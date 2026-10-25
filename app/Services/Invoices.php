<?php

namespace App\Services;

use App\Models\BdtEntry;
use App\Models\Campaign;
use App\Models\CampaignDay;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoicePayment;
use App\Models\Setting;
use App\Models\UsdEntry;
use App\Models\User;
use App\Notifications\AgencyAlert;
use App\Payments\PaymentMethod;
use App\Payments\PaySettings;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Invoices from first draft to paid. When money against an invoice is
 * confirmed (by the wallet's SMS or by hand) it becomes an ordinary client
 * payment in the BDT book, carrying the share of the invoice's ad budget it
 * covers as USD credit — so campaign funding and the P&L need nothing new.
 */
class Invoices
{
    public const PAID_TITLE = 'Invoice-এর টাকা পাওয়া গেছে'; // i18n

    public const REFUND_TITLE = 'Payment ফেরত দেওয়া হয়েছে — invoice আবার বাকি'; // i18n

    public const OVERDUE_TITLE = 'Invoice-এর মেয়াদ পার হয়ে গেছে'; // i18n

    public const CLAIM_TITLE = 'Client পেমেন্টের তথ্য দিয়েছে — যাচাই করুন'; // i18n

    public function __construct(private Postings $postings, private Notifier $notifier) {}

    /**
     * Creates or rewrites an invoice with its lines.
     *
     * @param  array{client_id: int, issue_date: string, due_date: ?string, notes: ?string, items: list<array{kind: string, description: string, usd: ?int, rate: ?float, amount: ?int, campaign_id: ?int, period_from: ?string, period_to: ?string, results: ?int, clicks: ?int}>}  $data
     */
    public function save(array $data, ?Invoice $invoice = null): Invoice
    {
        return DB::transaction(function () use ($data, $invoice) {
            if ($invoice === null) {
                $seq = Invoice::nextSeq();
                $invoice = new Invoice([
                    'seq' => $seq,
                    'number' => Invoice::numberFor($seq),
                    'status' => 'sent',
                    'public_token' => Str::random(40),
                ]);
            }

            $invoice->fill([
                'client_id' => $data['client_id'],
                'issue_date' => $data['issue_date'],
                'due_date' => $data['due_date'],
                'notes' => $data['notes'],
            ]);
            $invoice->save();
            $invoice->items()->delete();

            $total = 0;
            $usd = 0;

            foreach ($data['items'] as $position => $line) {
                $priced = in_array($line['kind'], InvoiceItem::USD_KINDS, true);
                // Spend lines bill a campaign. An advance line for a campaign buys
                // as many more days as its USD covers at the daily budget.
                $campaign = in_array($line['kind'], InvoiceItem::USD_KINDS, true) ? ($line['campaign_id'] ?? null) : null;
                $budget = $line['kind'] === 'ad_budget' && $campaign ? Campaign::find($campaign)?->dailyBudget() : null;
                $days = $budget ? intdiv((int) $line['usd'], $budget) ?: null : null;

                if ($priced) {
                    $cents = (int) $line['usd'];
                    $rate = (float) $line['rate'];
                    $amount = InvoiceItem::bdtFor($cents, $rate);
                    $usd += $cents;
                    $description = $line['description'] ?: ($line['kind'] === 'ad_spend' ? 'Campaign spend' : 'Advance ad budget');
                } else {
                    $cents = null;
                    $rate = null;
                    $amount = (int) $line['amount'];
                    $description = $line['description'];
                }

                $invoice->items()->create([
                    'position' => $position,
                    'kind' => $line['kind'],
                    'campaign_id' => $campaign,
                    'period_from' => $line['kind'] === 'ad_spend' ? $line['period_from'] : null,
                    'period_to' => $line['kind'] === 'ad_spend' ? $line['period_to'] : null,
                    'days' => $days,
                    'description' => $description,
                    'usd' => $cents,
                    'rate' => $rate,
                    'results' => $line['kind'] === 'ad_spend' ? $line['results'] : null,
                    'clicks' => $line['kind'] === 'ad_spend' ? $line['clicks'] : null,
                    'amount' => $amount,
                ]);
                $total += $amount;
            }

            $invoice->forceFill(['total' => $total, 'usd' => $usd])->save();

            return $invoice;
        });
    }

    /** The invoice is with the client now (shared by link, WhatsApp or email). */
    public function markSent(Invoice $invoice): void
    {
        if ($invoice->sent_at === null) {
            $invoice->forceFill(['sent_at' => now()])->save();

            // The client's own people hear of it too, with the link to pay.
            app(ClientNotifier::class)->send($invoice->client_id, new AgencyAlert('client_invoice', ClientNotifier::INVOICE_TITLE, '{amount}{date} — এই link-এ দেখুন ও pay করুন', [ // i18n
                'number' => $invoice->number,
                'amount' => Money::bdt($invoice->due()),
                'date' => $invoice->due_date ? ' · '.__('শেষ তারিখ :date', ['date' => $invoice->due_date->format('j M')]) : '',
            ], ['url' => route('invoice.public', $invoice->public_token, false)]));
        }
    }

    public function void(Invoice $invoice): void
    {
        DB::transaction(function () use ($invoice) {
            $invoice->payments()->where('status', 'pending')->update(['status' => 'expired', 'expires_at' => null]);
            $invoice->forceFill(['status' => 'void', 'voided_at' => now()])->save();
        });
    }

    /**
     * Confirms money against an invoice: the client payment in the BDT book
     * (with its share of the ad budget as USD credit), the Bangla QR fee,
     * and the invoice's paid amount and status.
     */
    public function verify(InvoicePayment $payment, int $received, ?User $by = null, ?string $date = null, ?string $wallet = null, ?string $note = null): bool
    {
        $done = DB::transaction(function () use ($payment, $received, $by, $date, $wallet, $note) {
            /** @var Invoice $invoice */
            $invoice = Invoice::with('client')->lockForUpdate()->findOrFail($payment->invoice_id);
            $fresh = InvoicePayment::lockForUpdate()->findOrFail($payment->id);

            if ($fresh->status === 'verified' || $invoice->status === 'void' || $received <= 0) {
                return false;
            }

            $method = $fresh->method;
            $day = $date ?? now()->toDateString();
            $place = $wallet ?? $method->wallet();
            // Bangla QR: the bank keeps its fee before the money lands. The client paid the QR's
            // amount (it is the invoice's), and the fee is ours: when what landed is a little short,
            // the gap is the fee; otherwise the bank's rule (free below the limit, else its %).
            $qrFee = 0;
            if ($method === PaymentMethod::BanglaQr) {
                $short = (int) $fresh->amount - $received;

                if ($short > 0 && $short <= PaySettings::qrShortBy((int) $fresh->amount)) {
                    $qrFee = $short;
                    $received = (int) $fresh->amount;
                } else {
                    $qrFee = PaySettings::qrFee($received);
                }
            }
            // The client's payment charge is only what came in beyond the invoice's due, so a
            // client who sent just the due still pays the invoice in full.
            $charge = min((int) $fresh->charge, max(0, $received - $invoice->due()));
            $arrived = $received;
            $received -= $charge;
            $paid = $invoice->paid + $received;
            $credit = $this->usdShare($invoice, $received, $paid);
            $reference = $fresh->reference ? ' · '.$fresh->reference : '';

            $entry = $this->postings->clientPayment([
                'entry_date' => $day,
                'description' => "{$invoice->client->name} — Invoice {$invoice->number} ({$method->label()})",
                'category' => 'client_payment',
                'client_id' => $invoice->client_id,
                'pay_via' => $method->payVia(),
                'wallet' => $place,
                'amount' => $received,
                'usd_credit' => $credit,
                'notes' => trim("Invoice {$invoice->number}{$reference}".($fresh->sender ? ' · '.$fresh->sender : '')),
            ]);

            $fee = null;

            if ($qrFee > 0) {
                // The fee comes off where the QR money landed.
                $fee = MoveFees::book($qrFee, $place, $day, "Bangla QR fee — Invoice {$invoice->number}");
            }

            // The charge is the agency's: other income, in the same place as the payment.
            $chargeEntry = $charge > 0 ? BdtEntry::create([
                'entry_date' => $day,
                'description' => "{$invoice->client->name} — payment charge, Invoice {$invoice->number} ({$method->label()})",
                'category' => 'other_income',
                'client_id' => $invoice->client_id,
                'pay_via' => $method->payVia(),
                'wallet' => $place,
                'amount' => $charge,
                'notes' => "Invoice {$invoice->number}",
            ]) : null;

            $fresh->forceFill([
                'status' => 'verified',
                'amount' => $arrived,
                'charge' => $charge,
                'charge_entry_id' => $chargeEntry?->id,
                'paid_on' => $day,
                'wallet' => $place,
                'usd_credit' => $credit ?: null,
                'bdt_entry_id' => $entry->id,
                'fee_entry_id' => $fee?->id,
                'expires_at' => null,
                'verified_at' => now(),
                'verified_by' => $by?->id,
                'note' => $note ?? $fresh->note,
            ])->save();

            $full = $paid >= $invoice->total;
            $invoice->forceFill([
                'paid' => $paid,
                'status' => $full ? 'paid' : 'partial',
                'paid_at' => $full ? now() : $invoice->paid_at,
                'sent_at' => $invoice->sent_at ?? now(),
            ])->save();

            if ($full) {
                // Nothing else is needed: close the pay popups and claims still open.
                $invoice->payments()->where('status', 'pending')->whereKeyNot($fresh->id)->update(['status' => 'expired', 'expires_at' => null]);
            }

            activity('Invoice')->performedOn($invoice)->causedBy($by)->withProperties([
                'number' => $invoice->number, 'method' => $method->value, 'amount' => $received, 'reference' => $fresh->reference,
                'bdt_entry_id' => $entry->id, 'auto' => $by === null,
            ])->log($by ? 'payment verified' : 'payment auto-verified');

            return [$invoice, $received, $qrFee];
        });

        if ($done === false) {
            return false;
        }

        [$invoice, $amount, $qrFee] = $done;
        $this->notifier->send($invoice->client->manager_id ?? null, new AgencyAlert('invoice_paid', self::PAID_TITLE, '{client} · {number} · {amount}', [
            'client' => $invoice->client->name, 'number' => $invoice->number,
            // Bangla QR: what the bank kept, and what landed with us.
            'amount' => Money::bdt($amount).($qrFee > 0 ? __(' · bank fee :fee কেটে জমা :landed', ['fee' => Money::bdt($qrFee), 'landed' => Money::bdt($amount - $qrFee)]) : ''),
        ], ['url' => '/invoices/'.$invoice->uid]));

        $full = $invoice->status === 'paid';
        app(ClientNotifier::class)->send($invoice->client_id, new AgencyAlert($full ? 'client_paid' : 'client_part_paid', $full ? ClientNotifier::PAID_TITLE : ClientNotifier::PART_TITLE, $full ? 'Invoice {number} · {amount} — পুরো পরিশোধ হয়েছে' : 'Invoice {number} · {amount} পাওয়া গেছে · বাকি {due}', [ // i18n
            'number' => $invoice->number, 'amount' => Money::bdt($amount), 'due' => Money::bdt($invoice->due()),
        ], ['url' => route('invoice.public', $invoice->public_token, false)]));

        if ($invoice->status === 'paid') {
            // A renewal's invoice: its campaign waits for the admin's OK.
            app(Renewals::class)->paid($invoice);
        }

        return true;
    }

    /**
     * A confirmed payment given back (the wallet sent the money back to the number that paid):
     * its rows leave the books — the payment, its charge and its QR fee — and the invoice is
     * due again by its share. The wallet's own fee for sending it back is a bank charge.
     */
    public function refund(InvoicePayment $payment, int $fee = 0, ?string $date = null, ?string $reference = null): bool
    {
        $done = DB::transaction(function () use ($payment, $fee, $date, $reference) {
            $fresh = InvoicePayment::lockForUpdate()->findOrFail($payment->id);
            /** @var Invoice $invoice */
            $invoice = Invoice::with('client')->lockForUpdate()->findOrFail($fresh->invoice_id);

            if ($fresh->status !== 'verified') {
                return false;
            }

            $share = (int) (BdtEntry::find($fresh->bdt_entry_id)->amount ?? 0);
            BdtEntry::whereKey(array_filter([$fresh->bdt_entry_id, $fresh->charge_entry_id, $fresh->fee_entry_id]))->get()->each->delete();

            $paid = max(0, $invoice->paid - $share);
            $invoice->forceFill([
                'paid' => $paid,
                'status' => $paid >= $invoice->total ? 'paid' : ($paid > 0 ? 'partial' : 'sent'),
                'paid_at' => $paid >= $invoice->total ? $invoice->paid_at : null,
            ])->save();

            $day = $date ?? now()->toDateString();
            MoveFees::book($fee, (string) ($fresh->wallet ?? $fresh->method->wallet()), $day, "{$fresh->method->label()} send money fee — refund, Invoice {$invoice->number}");

            $fresh->forceFill([
                'status' => 'refunded',
                'note' => trim(($fresh->note ? $fresh->note.' · ' : '').'Refund'.($reference ? ' '.$reference : '').' '.$day),
            ])->save();

            activity('Invoice')->performedOn($invoice)->withProperties([
                'number' => $invoice->number, 'payment' => $fresh->id, 'amount' => $fresh->amount, 'reference' => $reference,
            ])->log('payment refunded');

            return [$invoice, $fresh];
        });

        if ($done === false) {
            return false;
        }

        [$invoice, $fresh] = $done;
        $this->notifier->send($invoice->client->manager_id ?? null, new AgencyAlert('invoice_refund', self::REFUND_TITLE, '{client} · {number} · {amount}', [
            'client' => $invoice->client->name, 'number' => $invoice->number, 'amount' => Money::bdt($fresh->amount),
        ], ['url' => '/invoices/'.$invoice->uid]));
        app(ClientNotifier::class)->send($invoice->client_id, new AgencyAlert('client_refund', ClientNotifier::REFUND_TITLE, 'Invoice {number} · {amount} ফেরত গেছে', [ // i18n
            'number' => $invoice->number, 'amount' => Money::bdt($fresh->amount),
        ], ['url' => route('invoice.public', $invoice->public_token, false)]));

        return true;
    }

    /** A client sent a TrxID / bank reference nobody could confirm yet. */
    public function claimNeedsReview(InvoicePayment $payment): void
    {
        $invoice = $payment->invoice()->with('client')->firstOrFail();

        $this->notifier->send($invoice->client->manager_id ?? null, new AgencyAlert('invoice_claim', self::CLAIM_TITLE, '{client} · {number} · {method} {reference}', [
            'client' => $invoice->client->name, 'number' => $invoice->number, 'method' => $payment->method->label(), 'reference' => $payment->reference,
        ], ['url' => '/invoices/'.$invoice->uid]));
    }

    /**
     * The ad credit this payment buys: the invoice's USD in proportion to
     * the share of the invoice it pays, and whatever is left once it is paid.
     */
    private function usdShare(Invoice $invoice, int $received, int $paidAfter): int
    {
        if ($invoice->usd <= 0 || $invoice->total <= 0) {
            return 0;
        }

        $given = (int) $invoice->payments()->where('status', 'verified')->sum('usd_credit');
        $left = max(0, $invoice->usd - $given);

        if ($paidAfter >= $invoice->total) {
            return $left;
        }

        return min($left, (int) round($invoice->usd * $received / $invoice->total));
    }

    /**
     * Unpaid invoices past their due date: the Owner and the client's
     * manager hear about each one once (the status stays as it is).
     */
    public function markOverdue(): int
    {
        $count = 0;
        $late = Invoice::with('client')
            ->whereIn('status', Invoice::PAYABLE)
            ->whereNotNull('due_date')
            ->where('due_date', '<', now()->toDateString())
            ->whereNull('overdue_notified_at')
            ->limit(200)
            ->get();

        foreach ($late as $invoice) {
            $invoice->forceFill(['overdue_notified_at' => now()])->saveQuietly();

            $this->notifier->send($invoice->client->manager_id ?? null, new AgencyAlert('invoice_overdue', self::OVERDUE_TITLE, '{client} · {number} · {amount}', [
                'client' => $invoice->client->name, 'number' => $invoice->number, 'amount' => Money::bdt($invoice->due()),
            ], ['url' => '/invoices/'.$invoice->uid]));
            app(ClientNotifier::class)->send($invoice->client_id, new AgencyAlert('client_overdue', ClientNotifier::OVERDUE_TITLE, '{amount} বাকি — বিজ্ঞাপন না থামাতে এখনই pay করুন', [ // i18n
                'number' => $invoice->number, 'amount' => Money::bdt($invoice->due()),
            ], ['url' => route('invoice.public', $invoice->public_token, false)]));
            $count++;
        }

        return $count;
    }

    public static function commissionPercent(): float
    {
        return (float) (Setting::get('commission_percent') ?? '20');
    }

    /** BDT per USD of the FX batch now being spent (the first with USD left; else the latest). */
    public static function fxRate(): ?float
    {
        $fifo = app(Books::class)->fifo();
        $last = null;

        foreach ($fifo['segments'] as $segment) {
            $last = $segment['rate'];

            if (($fifo['batches'][$segment['id']]['left'] ?? 0) > 0) {
                return $segment['rate'];
            }
        }

        return $last;
    }

    /**
     * What a client pays per USD of spend already run: the rate of the FX
     * batch that paid for it (FIFO), plus the commission. Spend not costed
     * yet (no batch) takes today's client rate.
     *
     * @param  iterable<UsdEntry>  $rows  spend rows (negative amounts)
     */
    public static function rateFor(iterable $rows): ?float
    {
        $fifo = app(Books::class)->fifo();
        $usd = 0;
        $cost = 0;

        foreach ($rows as $row) {
            if (isset($fifo['entries'][$row->id]['cost'])) {
                $usd += -$row->amount;
                $cost += (int) $fifo['entries'][$row->id]['cost'];
            }
        }

        return $usd > 0 ? round($cost / $usd * (1 + self::commissionPercent() / 100), 2) : self::clientRate();
    }

    /** What a client pays per USD: the FX rate plus the agency's commission. */
    public static function clientRate(): ?float
    {
        $fx = self::fxRate();

        return $fx ? round($fx * (1 + self::commissionPercent() / 100), 2) : null;
    }

    /**
     * The client's campaigns (those linked to them on the Campaign page) with
     * their spend, results and clicks between two days — only days billed to
     * them, from bill_from on. Spend is read fresh from Pilot first, so a
     * campaign linked a minute ago shows too.
     *
     * @return list<array{id: int, name: string, rate: ?float, usd: int, results: int, clicks: int, days: int, from: string, to: string, live: bool, ended: bool, account: ?string}>
     */
    public static function campaignSpend(int $clientId, string $from, string $to): array
    {
        $rows = [];
        $campaigns = Campaign::with('account')->where('client_id', $clientId)->orderBy('name')->get();

        if (PilotClient::connected()) {
            $billing = app(CampaignBilling::class);
            $start = CarbonImmutable::parse($from);
            $end = CarbonImmutable::parse($to)->min(CarbonImmutable::now()->startOfDay());

            foreach ($campaigns->groupBy('pilot_account_id') as $group) {
                try {
                    $billing->readDays($group->first()->account, $group, $start, $end);
                } catch (\RuntimeException $e) {
                    report($e); // the days already stored still show
                }
            }
        }

        foreach ($campaigns as $campaign) {
            $start = $campaign->bill_from !== null && $campaign->bill_from->toDateString() > $from ? $campaign->bill_from->toDateString() : $from;
            $days = CampaignDay::where('campaign_id', $campaign->id)->whereBetween('day', [$start, $to])->get();

            // Priced at the batches that paid for these days' spend, as the books cost them.
            $billed = UsdEntry::where('source', 'pilot')->where('client_id', $clientId)
                ->whereBetween('entry_date', [$start, $to])->get();

            $rows[] = [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'rate' => self::rateFor($billed),
                'usd' => (int) $days->sum('spend'),
                'results' => (int) $days->sum('results'),
                'clicks' => (int) $days->sum('clicks'),
                'days' => $days->where('spend', '>', 0)->count(),
                'from' => $start,
                'to' => $to,
                'live' => $campaign->isLive(),
                'ended' => $campaign->hasEnded(),
                'account' => $campaign->account?->name,
            ];
        }

        return $rows;
    }
}

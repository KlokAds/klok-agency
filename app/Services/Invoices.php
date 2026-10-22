<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\CampaignDay;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoicePayment;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\AgencyAlert;
use App\Payments\PaymentMethod;
use App\Payments\PaySettings;
use App\Support\Money;
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
                    'status' => 'draft',
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
                $campaign = $line['kind'] === 'ad_spend' ? $line['campaign_id'] : null;

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

    /** The invoice is with the client now; an overdue one stays overdue. */
    public function markSent(Invoice $invoice): void
    {
        if ($invoice->status === 'draft') {
            $invoice->forceFill(['status' => 'sent', 'sent_at' => now()])->save();
        } elseif ($invoice->sent_at === null) {
            $invoice->forceFill(['sent_at' => now()])->save();
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

            if ($method === PaymentMethod::BanglaQr) {
                $fee = MoveFees::book((int) round($received * PaySettings::qrFeePercent() / 100), 'bank', $day, "Bangla QR fee — Invoice {$invoice->number}");
            }

            $fresh->forceFill([
                'status' => 'verified',
                'amount' => $received,
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
                'status' => $full ? 'paid' : ($invoice->due_date !== null && $invoice->due_date->lt(now()->startOfDay()) ? 'overdue' : 'partial'),
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

            return [$invoice, $received];
        });

        if ($done === false) {
            return false;
        }

        [$invoice, $amount] = $done;
        $this->notifier->send($invoice->client->manager_id ?? null, new AgencyAlert('invoice_paid', self::PAID_TITLE, '{client} · {number} · {amount}', [
            'client' => $invoice->client->name, 'number' => $invoice->number, 'amount' => Money::bdt($amount),
        ], ['url' => '/invoices/'.$invoice->id]));

        return true;
    }

    /** A client sent a TrxID / bank reference nobody could confirm yet. */
    public function claimNeedsReview(InvoicePayment $payment): void
    {
        $invoice = $payment->invoice()->with('client')->firstOrFail();

        $this->notifier->send($invoice->client->manager_id ?? null, new AgencyAlert('invoice_claim', self::CLAIM_TITLE, '{client} · {number} · {method} {reference}', [
            'client' => $invoice->client->name, 'number' => $invoice->number, 'method' => $payment->method->label(), 'reference' => $payment->reference,
        ], ['url' => '/invoices/'.$invoice->id]));
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
     * Sent invoices past their due date become overdue; the Owner and the
     * client's manager hear about each one once.
     */
    public function markOverdue(): int
    {
        $count = 0;
        $late = Invoice::with('client')
            ->whereIn('status', ['sent', 'partial', 'overdue'])
            ->whereNotNull('due_date')
            ->where('due_date', '<', now()->toDateString())
            ->whereNull('overdue_notified_at')
            ->limit(200)
            ->get();

        foreach ($late as $invoice) {
            $invoice->forceFill(['status' => 'overdue', 'overdue_notified_at' => now()])->saveQuietly();

            $this->notifier->send($invoice->client->manager_id ?? null, new AgencyAlert('invoice_overdue', self::OVERDUE_TITLE, '{client} · {number} · {amount}', [
                'client' => $invoice->client->name, 'number' => $invoice->number, 'amount' => Money::bdt($invoice->due()),
            ], ['url' => '/invoices/'.$invoice->id]));
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

    /** What a client pays per USD: the FX rate plus the agency's commission. */
    public static function clientRate(): ?float
    {
        $fx = self::fxRate();

        return $fx ? round($fx * (1 + self::commissionPercent() / 100), 2) : null;
    }

    /**
     * Each of the client's linked campaigns with its spend, results and
     * clicks between two days (only days it is billed for, from bill_from on).
     *
     * @return list<array{id: int, name: string, usd: int, results: int, clicks: int, days: int, from: string, to: string}>
     */
    public static function campaignSpend(int $clientId, string $from, string $to): array
    {
        $rows = [];

        foreach (Campaign::where('client_id', $clientId)->orderBy('name')->get() as $campaign) {
            $start = $campaign->bill_from !== null && $campaign->bill_from->toDateString() > $from ? $campaign->bill_from->toDateString() : $from;
            $days = CampaignDay::where('campaign_id', $campaign->id)->whereBetween('day', [$start, $to])->get();

            $rows[] = [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'usd' => (int) $days->sum('spend'),
                'results' => (int) $days->sum('results'),
                'clicks' => (int) $days->sum('clicks'),
                'days' => $days->where('spend', '>', 0)->count(),
                'from' => $from,
                'to' => $to,
            ];
        }

        return $rows;
    }
}

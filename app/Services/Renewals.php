<?php

namespace App\Services;

use App\Mail\InvoiceMail;
use App\Mail\RenewalMail;
use App\Models\Campaign;
use App\Models\Invoice;
use App\Models\Renewal;
use App\Models\Setting;
use App\Models\UsdEntry;
use App\Models\User;
use App\Notifications\AgencyAlert;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Renewing a prepaid campaign. Two days before it ends the client gets a
 * link (/r/{token}): pay for more days, or end the contract. Paying makes
 * an invoice for the days at the client's rate; once it is paid in full
 * the admin hears by email and in the app, and their OK moves the end on
 * Meta. Ending the contract is only told to the admin; nothing changes on
 * Meta, the campaign stops on its end date as planned.
 *
 * A client with "auto renewal" days (Client::renew_days) skips the choice:
 * the invoice for that many days is made at once and the team is told to
 * send it, and once it is paid in full the campaign is extended by itself.
 */
class Renewals
{
    /** How many days before the end the link is made. */
    public const LEAD_DAYS = 2;

    public const OPENED_TITLE = 'Campaign শেষ হচ্ছে — client-কে renewal link পাঠান'; // i18n

    public const DECLINED_TITLE = 'Client contract শেষ করেছে — extend লাগবে না'; // i18n

    public const PAID_TITLE = 'Renewal-এর টাকা এসেছে — approve করুন'; // i18n

    /** The renewal invoice's line for spend not paid yet; it buys no days. */
    public const DUE_LINE = 'Previous ad spend not yet paid';

    public const INVOICED_TITLE = 'Renewal invoice তৈরি — client-কে পাঠান'; // i18n

    public const EXTENDED_TITLE = 'Renewal-এর টাকা এসেছে — campaign বাড়ানো হয়েছে'; // i18n

    public function __construct(private Invoices $invoices, private CampaignLimits $limits, private Notifier $notifier, private Books $books) {}

    /**
     * Campaign spend the client has not paid for yet (cents): a renewal
     * invoice carries it first, so the new money clears it before the days.
     */
    public function dueOf(int $clientId): int
    {
        $figure = collect($this->books->clients())->firstWhere('id', $clientId);

        return max(0, -(int) ($figure['credit_left'] ?? 0));
    }

    /** The address admin emails go to. */
    public static function adminEmail(): string
    {
        return (string) (Setting::get('notify_email') ?: 'adsklok@gmail.com');
    }

    /**
     * Makes the link for every linked prepaid campaign that ends within the
     * next two days and has none for that end yet.
     */
    public function openDue(): int
    {
        $now = CarbonImmutable::now();
        $count = 0;

        $campaigns = Campaign::with(['account', 'client'])->whereNotNull('client_id')->whereNotNull('bill_from')
            ->whereNotNull('stop_at')->whereBetween('stop_at', [$now, $now->addDays(self::LEAD_DAYS)->endOfDay()])
            ->get()
            ->filter(fn (Campaign $c) => CampaignLimits::guarded($c) && $c->dailyBudget() !== null && ($c->isLive() || $c->paused_for_credit_at !== null));

        foreach ($campaigns as $campaign) {
            $endsOn = $campaign->lastDay()->toDateString();

            $existing = Renewal::where('campaign_id', $campaign->id)->whereDate('ends_on', $endsOn)->first();

            // A link made before the client was switched to auto renewal still gets its invoice.
            if ($existing !== null) {
                if ($existing->status === 'open' && $existing->invoice_id === null && $campaign->client?->renew_days) {
                    $count += $this->autoInvoice($existing) ? 1 : 0;
                }

                continue;
            }

            $renewal = Renewal::create([
                'campaign_id' => $campaign->id,
                'client_id' => $campaign->client_id,
                'token' => Str::random(40),
                'ends_on' => $endsOn,
                'budget' => $campaign->dailyBudget(),
                'status' => 'open',
            ]);
            $count++;

            if ($campaign->client?->renew_days && $this->autoInvoice($renewal)) {
                continue;
            }

            $this->notifier->send($campaign->client?->manager_id, new AgencyAlert('renewal_open', self::OPENED_TITLE, '{client} · {campaign} · {date}', [
                'client' => $campaign->client?->name, 'campaign' => $campaign->name, 'date' => CarbonImmutable::parse($endsOn)->format('j M'),
            ], ['url' => '/renewals/'.$renewal->id]));
        }

        return $count;
    }

    /**
     * Auto renewal: the invoice for the client's set days, made now, and the
     * team told to send it. False (the link stays, as without auto) when it
     * cannot be priced.
     */
    private function autoInvoice(Renewal $renewal): bool
    {
        $renewal->loadMissing(['client', 'campaign']);

        try {
            $invoice = $this->choose($renewal, (int) $renewal->client->renew_days);
        } catch (RuntimeException $e) {
            report($e);

            return false;
        }

        $this->notifier->send($renewal->client->manager_id, new AgencyAlert('renewal_invoiced', self::INVOICED_TITLE, '{client} · {campaign} · {days} দিন · {amount}', [ // i18n
            'client' => $renewal->client->name,
            'campaign' => $renewal->campaign?->name,
            'days' => $renewal->client->renew_days,
            'amount' => Money::bdt($invoice->total),
        ], ['url' => '/invoices/'.$invoice->uid]));

        return true;
    }

    /**
     * The client chose to go on for $days: an invoice for that many days of
     * the daily budget at their rate. Choosing again replaces an unpaid one.
     *
     * @throws RuntimeException when there is no rate to price it at
     */
    public function choose(Renewal $renewal, int $days): Invoice
    {
        $rate = Invoices::clientRate() ?? throw new RuntimeException(__('এখন কোনো dollar rate নেই — Klok Ads-এর সাথে যোগাযোগ করুন।'));

        $due = $this->dueOf($renewal->client_id);

        return DB::transaction(function () use ($renewal, $days, $rate, $due) {
            $renewal = Renewal::with(['campaign', 'invoice'])->lockForUpdate()->findOrFail($renewal->id);

            if (! in_array($renewal->status, ['open', 'invoiced', 'declined'], true) || ($renewal->invoice !== null && $renewal->invoice->paid > 0)) {
                throw new RuntimeException(__('এই renewal আর বদলানো যাবে না।'));
            }

            if ($renewal->invoice !== null && $renewal->invoice->status !== 'void') {
                $this->invoices->void($renewal->invoice);
            }

            $usd = (int) $renewal->budget * $days;
            $from = CarbonImmutable::parse($renewal->ends_on)->max(CarbonImmutable::now()->startOfDay())->addDay();
            $line = fn (string $description, int $cents, float $at) => [
                'kind' => 'ad_budget', 'description' => $description, 'usd' => $cents, 'rate' => $at, 'amount' => null,
                'campaign_id' => null, 'period_from' => null, 'period_to' => null, 'results' => null, 'clicks' => null,
            ];
            // Spend already run is owed at the batches that paid for it; the new days at today's rate.
            $dueRate = Invoices::rateFor(UsdEntry::where('source', 'pilot')->where('client_id', $renewal->client_id)->where('status', 'pending')->get()) ?? $rate;
            $items = $due > 0 ? [$line(self::DUE_LINE, $due, $dueRate)] : [];
            $invoice = $this->invoices->save([
                'client_id' => $renewal->client_id,
                'issue_date' => now()->toDateString(),
                'due_date' => CarbonImmutable::parse($renewal->ends_on)->toDateString(),
                'notes' => "Renewal: {$renewal->campaign->name}",
                'items' => [...$items, [
                    'kind' => 'ad_budget',
                    'description' => "{$renewal->campaign->name} — {$days} days ({$from->format('j M')} – {$from->addDays($days - 1)->format('j M Y')})",
                    'usd' => $usd,
                    'rate' => $rate,
                    'amount' => null,
                    'campaign_id' => null,
                    'period_from' => null,
                    'period_to' => null,
                    'results' => null,
                    'clicks' => null,
                ]],
            ]);
            $this->invoices->markSent($invoice);

            $renewal->forceFill(['days' => $days, 'usd' => $usd, 'invoice_id' => $invoice->id, 'status' => 'invoiced', 'decided_at' => now()])->save();

            return $invoice;
        });
    }

    /**
     * A renewal's invoice was edited before any payment: the days it buys are
     * what its advance lines now cover at the daily budget (the line for
     * earlier unpaid spend does not buy days).
     */
    public function invoiceEdited(Invoice $invoice): void
    {
        $renewal = Renewal::where('invoice_id', $invoice->id)->where('status', 'invoiced')->first();

        if ($renewal === null || ! $renewal->budget) {
            return;
        }

        $usd = (int) $invoice->items()->where('kind', 'ad_budget')->where('description', '!=', self::DUE_LINE)->sum('usd');
        $renewal->forceFill(['usd' => $usd, 'days' => intdiv($usd, (int) $renewal->budget)])->save();
    }

    /** The client ends the contract: the admin hears, Meta is left as it is. */
    public function decline(Renewal $renewal): void
    {
        if (! in_array($renewal->status, ['open', 'invoiced'], true) || ($renewal->invoice !== null && $renewal->invoice->paid > 0)) {
            return;
        }

        DB::transaction(function () use ($renewal) {
            if ($renewal->invoice !== null && $renewal->invoice->status !== 'void') {
                $this->invoices->void($renewal->invoice);
            }

            $renewal->forceFill(['status' => 'declined', 'decided_at' => now(), 'invoice_id' => null])->save();
        });

        $this->tell($renewal, 'declined', self::DECLINED_TITLE);
    }

    /**
     * An invoice was paid in full: its renewal waits for the admin's OK. An
     * ordinary invoice's advance lines for a campaign ("7 more days") wait
     * the same way, each as a paid renewal.
     */
    public function paid(Invoice $invoice): void
    {
        $renewal = Renewal::where('invoice_id', $invoice->id)->where('status', 'invoiced')->first();

        if ($renewal !== null) {
            $renewal->forceFill(['status' => 'paid', 'paid_at' => now()])->save();
            $this->paidNow($renewal);

            return;
        }

        $lines = $invoice->items()->where('kind', 'ad_budget')->whereNotNull('campaign_id')->whereNotNull('days')->get();

        foreach ($lines as $line) {
            $campaign = Campaign::find($line->campaign_id);

            if ($campaign === null || Renewal::where('invoice_id', $invoice->id)->where('campaign_id', $campaign->id)->exists()) {
                continue;
            }

            // The link made two days before the end, if any, becomes this one.
            $endsOn = ($campaign->lastDay() ?? CarbonImmutable::now()->startOfDay())->toDateString();
            $renewal = Renewal::where('campaign_id', $campaign->id)->whereDate('ends_on', $endsOn)->whereIn('status', ['open', 'declined', 'expired'])->first()
                ?? (Renewal::where('campaign_id', $campaign->id)->whereDate('ends_on', $endsOn)->exists() ? null : new Renewal([
                    'campaign_id' => $campaign->id, 'client_id' => $invoice->client_id, 'token' => Str::random(40), 'ends_on' => $endsOn,
                ]));

            if ($renewal === null) {
                continue;
            }

            $renewal->forceFill([
                'budget' => $campaign->dailyBudget(), 'days' => $line->days, 'usd' => $line->usd, 'invoice_id' => $invoice->id,
                'status' => 'paid', 'decided_at' => $renewal->decided_at ?? now(), 'paid_at' => now(),
            ])->save();
            $this->paidNow($renewal);
        }
    }

    /**
     * Paid in full: an auto-renewal client's campaign is extended at once;
     * otherwise, or when Meta refuses, it waits for the admin's OK.
     */
    private function paidNow(Renewal $renewal): void
    {
        $renewal->loadMissing(['client', 'campaign']);

        if ($renewal->client?->renew_days && $renewal->campaign !== null) {
            try {
                $end = $this->approve($renewal);
                $this->tell($renewal, 'extended', self::EXTENDED_TITLE, __(' · :date পর্যন্ত', ['date' => $end->format('j M')]));
                app(ClientNotifier::class)->send($renewal->client_id, new AgencyAlert('client_extended', ClientNotifier::EXTENDED_TITLE, '{campaign} — {days} দিন বাড়ল, এখন {time}', [ // i18n
                    'campaign' => $renewal->campaign->name, 'days' => $renewal->days, 'time' => $renewal->campaign->fresh()?->stopsText(),
                ], ['url' => '/portal/campaigns/'.$renewal->campaign_id]));

                return;
            } catch (RuntimeException) {
                // Kept on the renewal (error); the admin's OK tries again.
            }
        }

        $this->tell($renewal, 'paid', self::PAID_TITLE);
    }

    /**
     * The admin's OK: the campaign runs for the paid days more on Meta.
     *
     * @throws RuntimeException when Pilot or Meta refuses (kept on the renewal)
     */
    public function approve(Renewal $renewal, ?User $by = null): CarbonImmutable
    {
        if ($renewal->status !== 'paid') {
            throw new RuntimeException(__('শুধু টাকা আসা renewal approve করা যায়।'));
        }

        try {
            $end = $this->limits->extend($renewal->campaign, (int) $renewal->days, $by, 'Renewal #'.$renewal->id.($renewal->invoice ? ', invoice '.$renewal->invoice->number : ''));
        } catch (RuntimeException $e) {
            $renewal->forceFill(['error' => mb_substr($e->getMessage(), 0, 300)])->save();

            throw $e;
        }

        $renewal->forceFill(['status' => 'approved', 'new_end' => $end->toDateString(), 'approved_at' => now(), 'approved_by' => $by?->id, 'error' => null])->save();

        return $end;
    }

    /** The bell, and an email to the admin when mail is set up. */
    private function tell(Renewal $renewal, string $event, string $title, string $more = ''): void
    {
        $renewal->loadMissing(['campaign', 'client', 'invoice']);

        $this->notifier->send($renewal->client?->manager_id, new AgencyAlert('renewal_'.$event, $title, '{client} · {campaign}{amount}', [
            'client' => $renewal->client?->name,
            'campaign' => $renewal->campaign?->name,
            'amount' => (in_array($event, ['paid', 'extended'], true) && $renewal->invoice ? ' · '.Money::bdt($renewal->invoice->total) : '').$more,
        ], ['url' => '/renewals/'.$renewal->id]));

        if (InvoiceMail::configured()) {
            rescue(fn () => Mail::to(self::adminEmail())->send(new RenewalMail($renewal, $event)));
        }
    }
}

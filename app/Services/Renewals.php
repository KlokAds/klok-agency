<?php

namespace App\Services;

use App\Mail\InvoiceMail;
use App\Mail\RenewalMail;
use App\Models\Campaign;
use App\Models\Invoice;
use App\Models\Renewal;
use App\Models\Setting;
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
 */
class Renewals
{
    /** How many days before the end the link is made. */
    public const LEAD_DAYS = 2;

    public const OPENED_TITLE = 'Campaign শেষ হচ্ছে — client-কে renewal link পাঠান'; // i18n

    public const DECLINED_TITLE = 'Client contract শেষ করেছে — extend লাগবে না'; // i18n

    public const PAID_TITLE = 'Renewal-এর টাকা এসেছে — approve করুন'; // i18n

    public function __construct(private Invoices $invoices, private CampaignLimits $limits, private Notifier $notifier) {}

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
            $endsOn = CarbonImmutable::parse($campaign->stop_at)->toDateString();

            if (Renewal::where('campaign_id', $campaign->id)->whereDate('ends_on', $endsOn)->exists()) {
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

            $this->notifier->send($campaign->client?->manager_id, new AgencyAlert('renewal_open', self::OPENED_TITLE, '{client} · {campaign} · {date}', [
                'client' => $campaign->client?->name, 'campaign' => $campaign->name, 'date' => CarbonImmutable::parse($endsOn)->format('j M'),
            ], ['url' => '/renewals/'.$renewal->id]));
        }

        return $count;
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

        return DB::transaction(function () use ($renewal, $days, $rate) {
            $renewal = Renewal::with(['campaign', 'invoice'])->lockForUpdate()->findOrFail($renewal->id);

            if (! in_array($renewal->status, ['open', 'invoiced', 'declined'], true) || ($renewal->invoice !== null && $renewal->invoice->paid > 0)) {
                throw new RuntimeException(__('এই renewal আর বদলানো যাবে না।'));
            }

            if ($renewal->invoice !== null && $renewal->invoice->status !== 'void') {
                $this->invoices->void($renewal->invoice);
            }

            $usd = (int) $renewal->budget * $days;
            $from = CarbonImmutable::parse($renewal->ends_on)->max(CarbonImmutable::now()->startOfDay())->addDay();
            $invoice = $this->invoices->save([
                'client_id' => $renewal->client_id,
                'issue_date' => now()->toDateString(),
                'due_date' => CarbonImmutable::parse($renewal->ends_on)->toDateString(),
                'notes' => "Renewal: {$renewal->campaign->name}",
                'items' => [[
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

    /** An invoice was paid in full: its renewal waits for the admin's OK. */
    public function paid(Invoice $invoice): void
    {
        $renewal = Renewal::where('invoice_id', $invoice->id)->where('status', 'invoiced')->first();

        if ($renewal === null) {
            return;
        }

        $renewal->forceFill(['status' => 'paid', 'paid_at' => now()])->save();
        $this->tell($renewal, 'paid', self::PAID_TITLE);
    }

    /**
     * The admin's OK: the campaign runs for the paid days more on Meta.
     *
     * @throws RuntimeException when Pilot or Meta refuses (kept on the renewal)
     */
    public function approve(Renewal $renewal, User $by): CarbonImmutable
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

        $renewal->forceFill(['status' => 'approved', 'new_end' => $end->toDateString(), 'approved_at' => now(), 'approved_by' => $by->id, 'error' => null])->save();

        return $end;
    }

    /** The bell, and an email to the admin when mail is set up. */
    private function tell(Renewal $renewal, string $event, string $title): void
    {
        $renewal->loadMissing(['campaign', 'client', 'invoice']);

        $this->notifier->send($renewal->client?->manager_id, new AgencyAlert('renewal_'.$event, $title, '{client} · {campaign}{amount}', [
            'client' => $renewal->client?->name,
            'campaign' => $renewal->campaign?->name,
            'amount' => $event === 'paid' && $renewal->invoice ? ' · '.Money::bdt($renewal->invoice->total) : '',
        ], ['url' => '/renewals/'.$renewal->id]));

        if (InvoiceMail::configured()) {
            rescue(fn () => Mail::to(self::adminEmail())->send(new RenewalMail($renewal, $event)));
        }
    }
}

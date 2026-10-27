<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\BdtEntry;
use App\Models\Campaign;
use App\Models\CampaignDay;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Renewal;
use App\Models\UsdEntry;
use App\Models\User;
use App\Notifications\AgencyAlert;
use App\Services\AdAccountBalances;
use App\Services\Books;
use App\Services\CampaignLimits;
use App\Services\CampaignStats;
use App\Services\ClientNotifier;
use App\Services\Invoices;
use App\Services\Notifier;
use App\Services\Renewals;
use App\Services\TopUps;
use App\Support\Access;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;
use Spatie\Activitylog\Models\Activity;

/**
 * The client portal: a client's own people see their campaigns (spend and results over any
 * period), how much of their money is left and for how long, and their invoices to pay. Anyone
 * may sign up; nothing shows until the Owner links the account to a client. Only that client's
 * own figures ever leave here — never what the agency paid for the dollars, its margin or
 * another client.
 */
class PortalController extends Controller
{
    public const SIGNUP_TITLE = 'নতুন client portal-এ register করেছেন — client-এর সাথে যুক্ত করুন'; // i18n

    /** The periods a client can look at; "custom" takes from and to. */
    public const RANGES = CampaignStats::RANGES;

    /** Money from the client (and back to them) in the BDT book: what they paid, in full. */
    public const PAID = ['client_payment', 'service_income', 'client_refund'];

    public const REFUND_TITLE = 'Client বাকি টাকা ফেরত চেয়েছেন'; // i18n

    public function register(): Response
    {
        return Inertia::render('auth/ClientRegister');
    }

    public function signUp(Request $request, Notifier $notifier): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'company' => ['nullable', 'string', 'max:120'],
            'phone' => ['required', 'string', 'regex:/^(\+?88)?01[3-9]\d{8}$/'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ], ['phone.regex' => __('১১ সংখ্যার নম্বর দিন, যেমন 01712345678।')]);

        // Anyone can type a client's email here, so the address counts only once the link sent to
        // it is opened, and the Owner can link only such an account (ClientController). The check is
        // on in config/fortify.php; taking it out there brings back the old unchecked sign-up.
        $checks = Features::enabled(Features::emailVerification());

        $user = User::create([
            'name' => $data['name'],
            'company' => $data['company'] ?? null,
            'phone' => $data['phone'],
            'email' => Str::lower($data['email']),
            'password' => $data['password'],
            'is_active' => true,
            'email_verified_at' => $checks ? null : now(),
        ]);
        $user->assignRole(Access::CLIENT);

        if ($checks) {
            rescue(fn () => $user->sendEmailVerificationNotification());
        }

        $notifier->send(null, new AgencyAlert('portal_signup', self::SIGNUP_TITLE, '{name} · {company} · {phone}', [
            'name' => $user->name, 'company' => $user->company ?? '—', 'phone' => $user->phone,
        ], ['url' => '/clients']));

        // Their own copy: the account is made, and what happens next.
        rescue(fn () => $user->notify(new AgencyAlert('client_welcome', ClientNotifier::WELCOME_TITLE, 'আপনার account তৈরি হয়েছে। Klok Ads আপনার ব্যবসার সাথে account-টা যুক্ত করলেই আপনার বিজ্ঞাপন, invoice আর পেমেন্ট এখানে দেখতে পাবেন — হলে আপনাকে জানানো হবে।', [], ['url' => '/portal']))); // i18n

        Auth::login($user);
        $request->session()->regenerate();

        return redirect('/portal');
    }

    public function home(Request $request, Books $books): Response
    {
        $user = $request->user();
        $client = $user->client;

        if ($client === null) {
            return Inertia::render('portal/Pending', ['name' => $user->name]);
        }

        $figure = collect($books->clients())->firstWhere('id', $client->id) ?? [];
        $campaigns = Campaign::with('account:id,platform,currency,problem')->where('client_id', $client->id)->get();
        $ids = $campaigns->pluck('id');
        $today = CarbonImmutable::now()->startOfDay();
        $week = $this->theirDays(CampaignDay::whereIn('campaign_id', $ids)->whereDate('day', '>=', $today->subDays(6)->toDateString())->get(), $this->shownFrom($campaigns, $client))
            ->groupBy(fn (CampaignDay $d) => $d->day->toDateString());
        $due = Invoice::where('client_id', $client->id)->shared()->whereIn('status', Invoice::PAYABLE)->get();

        return Inertia::render('portal/Home', [
            'client' => $client->name,
            'credit' => [
                'paid' => (int) ($figure['credit_usd'] ?? 0),
                'spent' => (int) ($figure['campaign_usd'] ?? 0),
                'left' => (int) ($figure['credit_left'] ?? 0),
                // Final once Meta charged it; the rest is counted day by day and settles on the charge.
                'meta' => app(AdAccountBalances::class)->client($client->id),
                'daily' => (int) ($figure['daily_usd'] ?? 0),
                'days_left' => $figure['days_left'] ?? null,
                // Whether the unspent dollars can be asked back. Only the dollars are shown: any taka
                // figure worked out from our dollar rate would let the client read that rate (and so
                // our commission) back from it, so the agency confirms the taka amount itself.
                'refundable' => (int) ($figure['credit_left'] ?? 0) >= CampaignLimits::RUN_OUT_MIN,
                'round' => $this->creditRound($client, (int) ($figure['credit_left'] ?? 0)),
                // What a dollar costs them now, so the top-up form can say what their taka buys.
                'rate' => Invoices::clientRate(),
                'topup_min' => TopUps::MIN_BDT,
                // Owed for ads already run (credit below zero), in dollars and at their rate in taka.
                'owed' => max(0, (int) ($figure['usd_pending'] ?? 0)),
                'owed_bdt' => (int) ($figure['pending_bdt'] ?? 0),
            ],
            'week' => collect(range(6, 0))->map(function (int $back) use ($today, $week) {
                $rows = $week->get($today->subDays($back)->toDateString(), collect());

                return [
                    'day' => $today->subDays($back)->toDateString(),
                    'spend' => (int) $rows->sum('spend'),
                    'results' => (int) $rows->sum('results'),
                ];
            })->all(),
            // Every campaign not ended: running, paused, or held on our side.
            'running' => $campaigns->reject(fn (Campaign $c) => $c->hasEnded())->sortByDesc(fn (Campaign $c) => $c->isLive())->values()->map(fn (Campaign $c) => $this->campaignCard($c))->all(),
            'due' => ['count' => $due->count(), 'amount' => (int) $due->sum(fn (Invoice $i) => $i->due())],
            'next_invoice' => ($next = $due->sortBy('due_date')->first()) ? $this->invoiceRow($next) : null,
            // Everything so far, as the client sees it: what the ads spent, and every taka they
            // paid with its date — the whole amount they gave, never split into our share.
            'lifetime' => [
                'spent_usd' => -(int) UsdEntry::where('client_id', $client->id)->where('category', 'client_spend')->sum('amount'),
                // The same spend by platform (Meta, Google…), so the total adds up to what they see.
                'by_platform' => UsdEntry::with('account:id,code,name,kind')->where('client_id', $client->id)->where('category', 'client_spend')->get()
                    ->groupBy(fn (UsdEntry $e) => self::platformName($e->account))
                    ->map(fn ($rows, $name) => ['name' => $name, 'usd' => -(int) $rows->sum('amount')])
                    ->sortByDesc('usd')->values()->all(),
                'paid_bdt' => (int) BdtEntry::where('client_id', $client->id)->whereIn('category', self::PAID)->sum('amount'),
            ],
            'payments' => BdtEntry::where('client_id', $client->id)->whereIn('category', self::PAID)
                ->orderByDesc('entry_date')->orderByDesc('id')->limit(500)->get()
                ->map(fn (BdtEntry $e) => [
                    'date' => $e->entry_date?->format('j M Y'),
                    'amount' => $e->amount,
                    'via' => BdtEntry::PAY_VIA[$e->pay_via ?? ''] ?? null,
                    'invoice' => preg_match('/\bInvoice ([A-Z]+-(?:[A-Z0-9]+-)*\d+)/', (string) $e->notes.' '.$e->description, $m) ? $m[1] : null,
                    'refund' => $e->category === 'client_refund',
                ])->all(),
            // The KlokOne Android app, offered when the portal is open in a browser.
            'app' => AppReleaseController::downloadUrl('plain') !== null ? AppReleaseController::versionName('plain') : null,
        ]);
    }

    public function campaigns(Request $request): Response
    {
        $client = $this->client($request);
        $campaigns = Campaign::with('account:id,platform,currency,problem')->where('client_id', $client->id)->get();
        $ids = $campaigns->pluck('id')->map(fn ($id) => (int) $id)->all();
        $shown = $this->shownFrom($campaigns, $client);
        // "From the start": the earliest day they may see, so another client's days never set it.
        $first = collect([$this->firstDay(array_values(array_diff($ids, array_keys($shown)))), ...array_values($shown)])->filter()->min();
        // Today once something was spent today; the first hours of a day open on yesterday instead.
        [$from, $to, $range] = $this->range($request, $first, CampaignStats::openingDay($ids));
        $days = $this->theirDays(CampaignDay::whereIn('campaign_id', $ids)->whereDate('day', '>=', $from->toDateString())->whereDate('day', '<=', $to->toDateString())->get(), $shown);

        $rows = $campaigns->map(function (Campaign $c) use ($days) {
            $mine = $days->where('campaign_id', $c->id);

            return [...$this->campaignCard($c), ...$this->totals($mine), 'stop_at' => $c->stop_at?->getTimestamp() ?? PHP_INT_MAX];
        })
            // Every campaign of theirs: running ones first, then the rest, the latest to end first.
            ->sortBy([fn (array $a, array $b) => [$b['live'], $b['stop_at']] <=> [$a['live'], $a['stop_at']]])
            ->values()->all();

        return Inertia::render('portal/Campaigns', [
            'range' => ['key' => $range, 'from' => $from->toDateString(), 'to' => $to->toDateString()],
            'totals' => $this->totals($days),
            'series' => $this->series($days, $from, $to),
            'campaigns' => $rows,
            'rounds' => $this->paymentRounds($client),
            ...$this->beyondMeta($client),
        ]);
    }

    /**
     * Each payment that bought ad credit, and what its dollars did: the days' spend is laid on the
     * payments oldest first, so the client sees which payment is being spent now, what the earlier
     * ones bought, and what is left of the latest. Newest first.
     *
     * @return list<array{date: string, bdt: int, usd: int, spent: int, left: int, from: string|null, to: string|null, state: string}>
     */
    private function paymentRounds(Client $client): array
    {
        $payments = BdtEntry::where('client_id', $client->id)->where('usd_credit', '>', 0)->orderBy('entry_date')->orderBy('id')->get();
        if ($payments->isEmpty()) {
            return [];
        }
        $campaigns = Campaign::where('client_id', $client->id)->whereNotNull('bill_from')->get(['id', 'bill_from']);
        $days = CampaignDay::whereIn('campaign_id', $campaigns->pluck('id'))->where('spend', '>', 0)->orderBy('day')->get()
            ->filter(fn (CampaignDay $d) => $d->day->gte($campaigns->firstWhere('id', $d->campaign_id)?->bill_from))
            ->groupBy(fn (CampaignDay $d) => $d->day->toDateString())->map(fn ($g) => (int) $g->sum('spend'));

        /** @var list<array{date: string, bdt: int, usd: int, spent: int, left: int, from: string|null, to: string|null, state: string}> $rounds */
        $rounds = $payments->map(fn (BdtEntry $p) => [
            'date' => $p->entry_date->toDateString(), 'bdt' => (int) $p->amount, 'usd' => (int) $p->usd_credit,
            'spent' => 0, 'left' => (int) $p->usd_credit, 'from' => null, 'to' => null, 'state' => 'waiting',
        ])->values()->all();
        $last = count($rounds) - 1;
        $i = 0;

        foreach ($days as $day => $spend) {
            $day = (string) $day;
            while ($spend > 0) {
                $r = $rounds[$i];
                // The last payment takes whatever is left over, even past zero (owed).
                $take = $i === $last ? $spend : min($spend, $r['left']);
                $r['spent'] += $take;
                $r['left'] -= $take;
                $r['from'] ??= $day;
                $r['to'] = $day;
                $rounds[$i] = $r;
                $spend -= $take;
                if ($r['left'] <= 0 && $i < $last) {
                    $i++;
                }
            }
        }

        foreach ($rounds as $n => $r) {
            $rounds[$n]['state'] = $r['left'] < 0 ? 'owed' : ($r['left'] === 0 ? 'used' : ($r['spent'] > 0 ? 'running' : 'waiting'));
        }

        return array_reverse($rounds);
    }

    /**
     * The money running the ads now, counted from the latest payment that added ad credit: what it
     * added, what was still left from before, and what the ads spent since — so the card starts
     * full with each payment instead of adding every payment ever made.
     *
     * @return array{paid: int, carried: int, spent: int, since: string}|null
     */
    private function creditRound(Client $client, int $left): ?array
    {
        $last = BdtEntry::where('client_id', $client->id)->where('usd_credit', '>', 0)->latest('entry_date')->latest('id')->first();

        if ($last === null) {
            return null;
        }

        $campaigns = Campaign::where('client_id', $client->id)->whereNotNull('bill_from')->get(['id', 'bill_from']);
        $spent = (int) CampaignDay::whereIn('campaign_id', $campaigns->pluck('id'))
            ->whereDate('day', '>=', $last->entry_date->toDateString())->get()
            ->filter(fn (CampaignDay $d) => $d->day->gte($campaigns->firstWhere('id', $d->campaign_id)?->bill_from))
            ->sum('spend');

        return [
            'paid' => (int) $last->usd_credit,
            'carried' => max(0, $left + $spent - (int) $last->usd_credit),
            'spent' => $spent,
            'since' => $last->entry_date->format('j M'),
        ];
    }

    /**
     * What the client is billed for besides the Meta campaigns above: ad spend on other platforms
     * (Google…), with what is still due, and the services on their invoices.
     *
     * @return array{other_spend: array<int, array<string, mixed>>, services: array<int, array<string, mixed>>}
     */
    private function beyondMeta(Client $client): array
    {
        return [
            // Platforms other than Meta (Google…). Spend written on the card itself was Meta's.
            'other_spend' => UsdEntry::with('account:id,code,name,kind')->where('client_id', $client->id)->where('category', 'client_spend')
                ->whereHas('account', fn ($q) => $q->where('kind', 'platform')->where('code', '!=', 'meta'))
                ->orderByDesc('entry_date')->orderByDesc('id')->limit(200)->get()
                ->map(fn (UsdEntry $e) => [
                    'date' => $e->entry_date?->format('j M Y'),
                    'platform' => self::platformName($e->account),
                    'description' => $e->description,
                    'usd' => -$e->amount,
                    'due' => $e->status === 'pending',
                ])->values()->all(),
            'services' => InvoiceItem::with('invoice')->whereIn('kind', ['service', 'other'])
                ->whereHas('invoice', fn ($q) => $q->where('client_id', $client->id)->shared())
                ->latest('id')->limit(200)->get()
                ->map(fn (InvoiceItem $i) => [
                    'description' => $i->description,
                    'amount' => $i->amount,
                    'date' => $i->invoice->issue_date->format('j M Y'),
                    'invoice' => $i->invoice->number,
                    'paid' => $i->invoice->status === 'paid',
                    'url' => $i->invoice->publicUrl(),
                ])->values()->all(),
        ];
    }

    public function campaign(Request $request, Campaign $campaign): Response
    {
        $client = $this->client($request);
        abort_unless($campaign->client_id === $client->id, 404);
        $shown = $this->shownFrom(collect([$campaign]), $client);
        [$from, $to, $range] = $this->range($request, $shown[$campaign->id] ?? $this->firstDay([$campaign->id]));
        $days = $this->theirDays(CampaignDay::where('campaign_id', $campaign->id)->whereDate('day', '>=', $from->toDateString())->whereDate('day', '<=', $to->toDateString())->orderBy('day')->get(), $shown);

        return Inertia::render('portal/Campaign', [
            'campaign' => [...$this->campaignCard($campaign->load('account:id,platform,currency,problem')), 'started' => $campaign->start_at?->setTimezone(config('app.timezone'))->format('j M Y')],
            'range' => ['key' => $range, 'from' => $from->toDateString(), 'to' => $to->toDateString()],
            'totals' => $this->totals($days),
            'series' => $this->series($days, $from, $to),
            'days' => $days->sortByDesc('day')->values()->map(fn (CampaignDay $d) => [
                'day' => $d->day->toDateString(),
                'spend' => $d->spend,
                'results' => (int) $d->results,
                'clicks' => (int) $d->clicks,
                'cpr' => $d->results > 0 ? (int) round($d->spend / $d->results) : null,
            ])->all(),
            'renew' => $this->renewState($campaign),
            'control' => $this->controlState($campaign, $client),
            // Each time it was extended, and to when.
            'extensions' => Renewal::where('campaign_id', $campaign->id)->where('status', 'approved')->latest('approved_at')->get()
                ->map(fn (Renewal $r) => [
                    'days' => $r->days,
                    'until' => $r->new_end?->format('j M Y'),
                    'at' => $r->approved_at?->format('j M Y'),
                ])->all(),
        ]);
    }

    /** The client asks for the ad credit not spent yet back: the Owners act on it. */
    /** The client pays what they owe for ads already run: an invoice is made (or the open one reused) and they go to pay it. */
    public function payDue(Request $request, Invoices $invoices): \Symfony\Component\HttpFoundation\Response
    {
        $client = $this->client($request);
        $open = Invoice::where('client_id', $client->id)->where('notes', Invoices::DUE_NOTE)->where('status', 'sent')->where('paid', 0)->latest('id')->first();
        try {
            $invoice = $open ?? $invoices->invoiceDue($client);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['due' => $e->getMessage()]);
        }

        return Inertia::location($invoice->publicUrl());
    }

    /** The client adds credit on their own: so much taka, an invoice at their rate, paid like any other. */
    public function topUp(Request $request, TopUps $topUps): \Symfony\Component\HttpFoundation\Response
    {
        $client = $this->client($request);
        $data = $request->validate(['amount' => ['required', 'numeric', 'min:'.(TopUps::MIN_BDT / 100), 'max:'.(TopUps::MAX_BDT / 100)]], [], ['amount' => __('টাকার অঙ্ক')]);

        try {
            $invoice = $topUps->invoice($client, (int) round((float) $data['amount'] * 100));
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }

        return Inertia::location($invoice->publicUrl());
    }

    public function refundRequest(Request $request, Books $books, Notifier $notifier): RedirectResponse
    {
        $client = $this->client($request);
        $figure = collect($books->clients())->firstWhere('id', $client->id) ?? [];
        $left = (int) ($figure['credit_left'] ?? 0);
        $data = $request->validate(['to' => ['required', 'string', 'max:120'], 'note' => ['nullable', 'string', 'max:500']], [], ['to' => __('কোথায় পাঠাব')]);

        abort_if($left <= 0, 422);
        $estimate = ($fx = Invoices::fxRate()) ? (int) floor($left * $fx / 100) * 100 : null;

        $notifier->send(null, new AgencyAlert('refund_request', self::REFUND_TITLE, '{client} · {who} · {usd} (≈ {bdt}) → {to}{note}', [ // i18n
            'client' => $client->name, 'who' => $request->user()->name, 'usd' => '$'.number_format($left / 100, 2), 'bdt' => $estimate ? Money::bdt($estimate) : '—',
            'to' => $data['to'], 'note' => filled($data['note'] ?? null) ? ' · '.$data['note'] : '',
        ], ['url' => '/clients/'.$client->id, 'important' => true]));
        activity('Client')->performedOn($client)->causedBy($request->user())->withProperties(['usd' => $left, 'to' => $data['to']])->log('refund asked');

        rescue(fn () => $request->user()->notify(new AgencyAlert('client_refund_asked', ClientNotifier::REFUND_ASKED_TITLE, '{usd} → {to}। Klok Ads ২ কর্মদিবসের মধ্যে অঙ্ক নিশ্চিত করবে, ৭ কর্মদিবসের মধ্যে পাঠাবে।', [ // i18n
            'usd' => '$'.number_format($left / 100, 2), 'to' => $data['to'],
        ], ['url' => '/portal'])));

        $this->toast(__('ফেরতের অনুরোধ পাঠানো হয়েছে — Klok Ads জানাবে।'));

        return back();
    }

    /** The client pauses their campaign. */
    public function pause(Request $request, Campaign $campaign, CampaignLimits $limits): RedirectResponse
    {
        return $this->control($request, $campaign, fn () => $limits->clientPause($campaign, $request->user()), __('Campaign থামানো হয়েছে।'));
    }

    /** The client switches the campaign they paused back on. */
    public function resume(Request $request, Campaign $campaign, CampaignLimits $limits): RedirectResponse
    {
        return $this->control($request, $campaign, fn () => $limits->clientResume($campaign, $request->user()), __('Campaign আবার চালু হয়েছে।'));
    }

    /** The client sets the budget a day, in dollars. */
    public function budget(Request $request, Campaign $campaign, CampaignLimits $limits): RedirectResponse
    {
        $data = $request->validate([
            'budget' => ['required', 'numeric', 'min:'.(CampaignLimits::CLIENT_BUDGET_MIN / 100), 'max:'.(CampaignLimits::CLIENT_BUDGET_MAX / 100)],
        ], [], ['budget' => __('দৈনিক budget')]);

        return $this->control($request, $campaign, fn () => $limits->clientBudget($campaign, (int) round((float) $data['budget'] * 100), $request->user()), __('দৈনিক budget বদলানো হয়েছে।'));
    }

    /** One of the client's own changes on Meta: their campaign, a Meta one in USD. */
    private function control(Request $request, Campaign $campaign, \Closure $change, string $done): RedirectResponse
    {
        $client = $this->client($request);
        abort_unless($campaign->client_id === $client->id, 404);
        abort_unless(CampaignLimits::guarded($campaign->loadMissing('account')), 422);

        try {
            $change();
        } catch (\RuntimeException $e) {
            // CampaignLimits' own refusals are written for the client. Anything from deeper down
            // (Pilot's or Meta's reply, a missing token) can name our ad account or its billing, so
            // it goes to the error log and the client gets a plain line.
            if ($e->getFile() !== (new \ReflectionClass(CampaignLimits::class))->getFileName()) {
                report($e);
                throw ValidationException::withMessages(['control' => __('এখন বদলানো গেল না — একটু পরে আবার চেষ্টা করুন, না হলে Klok Ads-কে জানান।')]);
            }

            throw ValidationException::withMessages(['control' => $e->getMessage()]);
        }

        $this->toast($done);

        return back();
    }

    /**
     * What the client may do to it here: pause, switch on, set the budget a day; with the money
     * left and what the other running campaigns take a day, to show how long the money lasts.
     *
     * @return array<string, mixed>|null
     */
    private function controlState(Campaign $campaign, Client $client): ?array
    {
        if (! CampaignLimits::guarded($campaign) || $campaign->hasEnded()) {
            return null;
        }

        $figure = collect(app(Books::class)->clients())->firstWhere('id', $client->id) ?? [];
        $others = Campaign::where('client_id', $client->id)->whereKeyNot($campaign->id)->running()->get()
            ->sum(fn (Campaign $c) => (int) $c->dailyBudget());

        return [
            'state' => $campaign->clientState(),
            'budget' => $campaign->dailyBudget(),
            'min' => CampaignLimits::CLIENT_BUDGET_MIN,
            'max' => CampaignLimits::CLIENT_BUDGET_MAX,
            'credit_left' => (int) ($figure['credit_left'] ?? 0),
            'others_daily' => (int) $others,
            'prepaid' => (int) ($figure['credit_usd'] ?? 0) > 0,
            // The most a day the paid money carries to its last day (null: pays after the spend).
            'room' => app(CampaignLimits::class)->budgetRoom($campaign),
            'last_day' => $campaign->lastDay()?->format('j M'),
        ];
    }

    /** The client keeps it running for so many more days: the invoice to pay, made now. */
    public function renew(Request $request, Campaign $campaign, Renewals $renewals): \Symfony\Component\HttpFoundation\Response
    {
        $client = $this->client($request);
        abort_unless($campaign->client_id === $client->id, 404);
        $data = $request->validate(['days' => ['required', 'integer', 'min:1', 'max:90']]);

        $renewal = $renewals->forCampaign($campaign);
        abort_if($renewal === null, 422);
        $renewal->forceFill(['self_serve' => true])->save();

        try {
            $invoice = $renewals->choose($renewal, (int) $data['days']);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['days' => $e->getMessage()]);
        }

        return Inertia::location($invoice->publicUrl());
    }

    /** The client will not go on: the campaign stops at its end, and the agency hears. */
    public function stop(Request $request, Campaign $campaign, Renewals $renewals): RedirectResponse
    {
        $client = $this->client($request);
        abort_unless($campaign->client_id === $client->id, 404);

        $renewal = $renewals->forCampaign($campaign);
        abort_if($renewal === null, 422);
        $renewals->decline($renewal);

        return back();
    }

    public function invoices(Request $request): Response
    {
        $client = $this->client($request);

        return Inertia::render('portal/Invoices', [
            'invoices' => Invoice::where('client_id', $client->id)->shared()->latest('issue_date')->latest('id')->limit(100)->get()
                ->map(fn (Invoice $i) => $this->invoiceRow($i))->all(),
        ]);
    }

    /** What the client was told: invoices, payments, the day's result, endings. Opened is read. */
    public function notifications(Request $request): Response
    {
        $user = $request->user();
        $list = $user->notifications()->latest()->limit(100)->get()->map(fn ($n) => [
            'id' => $n->id,
            'kind' => $n->data['kind'] ?? 'info',
            'title' => $n->data['title'] ?? '',
            'body' => $n->data['body'] ?? null,
            'params' => $n->data['params'] ?? [],
            'url' => $n->data['url'] ?? null,
            'read' => $n->read_at !== null,
            'at' => $n->created_at->toIso8601String(),
        ]);
        $user->unreadNotifications()->update(['read_at' => now()]);

        return Inertia::render('portal/Notifications', ['notifications' => $list]);
    }

    /** The client this person belongs to; one still waiting is sent home to the waiting page. */
    private function client(Request $request): Client
    {
        $client = $request->user()->client;
        abort_if($client === null, 403);

        return $client;
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string} */
    private function range(Request $request, ?CarbonImmutable $first = null, string $default = 'all'): array
    {
        return CampaignStats::range($request, $first, $default);
    }

    /**
     * @param  Collection<int, CampaignDay>  $days
     * @return array{spend: int, results: int, clicks: int, cpr: int|null}
     */
    private function totals(Collection $days): array
    {
        return CampaignStats::totals($days);
    }

    /**
     * @param  Collection<int, CampaignDay>  $days
     * @return list<array{day: string, spend: int, results: int}>
     */
    private function series(Collection $days, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return CampaignStats::series($days, $from, $to);
    }

    /** @param  array<int, int>  $campaignIds */
    private function firstDay(array $campaignIds): ?CarbonImmutable
    {
        return CampaignStats::firstDay($campaignIds);
    }

    /**
     * A client sees each campaign's whole life (campaigns:read-history), except one that was once
     * another client's: its days before it was billed to this client were the other client's, so
     * those show only from bill_from (and none at all without one).
     *
     * @param  Collection<int, Campaign>  $campaigns
     * @return array<int, CarbonImmutable> campaign id => the first day shown, for those cut short
     */
    private function shownFrom(Collection $campaigns, Client $client): array
    {
        $before = Activity::where('log_name', 'Campaign')->where('subject_type', (new Campaign)->getMorphClass())
            ->whereIn('subject_id', $campaigns->pluck('id'))->get(['subject_id', 'attribute_changes'])
            ->filter(function (Activity $a) use ($client) {
                $old = $a->attribute_changes?->get('old')['client_id'] ?? null;

                return $old !== null && (int) $old !== $client->id;
            })
            ->pluck('subject_id')->map(fn ($id) => (int) $id)->all();

        return $campaigns->filter(fn (Campaign $c) => in_array($c->id, $before, true))
            ->mapWithKeys(fn (Campaign $c) => [$c->id => $c->bill_from?->startOfDay() ?? CarbonImmutable::now()->addDay()->startOfDay()])
            ->all();
    }

    /**
     * @param  Collection<int, CampaignDay>  $days
     * @param  array<int, CarbonImmutable>  $shown  from shownFrom()
     * @return Collection<int, CampaignDay>
     */
    private function theirDays(Collection $days, array $shown): Collection
    {
        return $days->filter(fn (CampaignDay $d) => ! isset($shown[$d->campaign_id]) || $d->day->gte($shown[$d->campaign_id]))->values();
    }

    /**
     * Whether the client can keep this campaign going from here, at what rate, and what they
     * already chose for its current end.
     *
     * @return array<string, mixed>|null
     */
    private function renewState(Campaign $campaign): ?array
    {
        $campaign->loadMissing('account');

        if (! CampaignLimits::guarded($campaign) || $campaign->dailyBudget() === null || $campaign->lastDay() === null || $campaign->hasEnded()) {
            return null;
        }

        $renewal = Renewal::with('invoice')->where('campaign_id', $campaign->id)->whereDate('ends_on', $campaign->lastDay()->toDateString())->latest('id')->first();

        return [
            'budget' => $campaign->dailyBudget(),
            'rate' => Invoices::clientRate(),
            'status' => $renewal?->status,
            'days' => $renewal?->days,
            'invoice_url' => $renewal?->invoice && $renewal->invoice->status !== 'void' ? $renewal->invoice->publicUrl() : null,
            'invoice_due' => $renewal?->invoice?->due(),
        ];
    }

    /**
     * Where a client's ad money went, as they know it: Meta is "Facebook Ads", and spend written on
     * our card itself (before the platform accounts existed) was Meta's too.
     */
    private static function platformName(?Account $account): string
    {
        return match (true) {
            $account === null, $account->kind !== 'platform', $account->code === 'meta' => 'Facebook Ads',
            default => $account->name,
        };
    }

    /** @return array<string, mixed> */
    private function campaignCard(Campaign $c): array
    {
        return [
            'id' => $c->id,
            'slug' => $c->slug,
            'name' => $c->name,
            'live' => $c->isLive(),
            // live, ended, paused_client, paused_credit, held (stopped on the agency's side)
            'state' => $c->clientState(),
            'paused' => ! $c->isLive() && ! $c->hasEnded(),
            'ended' => $c->hasEnded(),
            'stops' => $c->stopsText(),
            'days_left' => $c->daysLeft(),
            'budget' => $c->dailyBudget(),
            'currency' => strtoupper((string) ($c->account->currency ?? 'USD')),
            // "Purchase", "Message", "Lead"…: what its results are, by its goal.
            'result_name' => $c->resultName(),
        ];
    }

    /** @return array<string, mixed> */
    private function invoiceRow(Invoice $i): array
    {
        return [
            'number' => $i->number,
            'issued' => $i->issue_date->format('j M Y'),
            'due_date' => $i->due_date?->format('j M Y'),
            'total' => $i->total,
            'paid' => $i->paid,
            'due' => $i->due(),
            'status' => $i->status,
            'overdue' => $i->isOverdue(),
            'url' => $i->publicUrl(),
        ];
    }
}

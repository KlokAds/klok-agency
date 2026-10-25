<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\CampaignDay;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Renewal;
use App\Models\User;
use App\Notifications\AgencyAlert;
use App\Services\Books;
use App\Services\Notifier;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

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
    public const RANGES = ['today', 'yesterday', '7d', '15d', '30d', '90d', '180d', '365d', 'custom'];

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
            'password' => ['required', 'confirmed', Password::min(8)],
        ], ['phone.regex' => __('১১ সংখ্যার নম্বর দিন, যেমন 01712345678।')]);

        $user = User::create([
            'name' => $data['name'],
            'company' => $data['company'] ?? null,
            'phone' => $data['phone'],
            'email' => $data['email'],
            'password' => $data['password'],
            'is_active' => true,
            // Nothing is shown until the Owner links them, and that is the check of who they are.
            'email_verified_at' => now(),
        ]);
        $user->assignRole(Access::CLIENT);

        $notifier->send(null, new AgencyAlert('portal_signup', self::SIGNUP_TITLE, '{name} · {company} · {phone}', [
            'name' => $user->name, 'company' => $user->company ?? '—', 'phone' => $user->phone,
        ], ['url' => '/clients']));

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
        $campaigns = Campaign::with('account:id,currency')->where('client_id', $client->id)->get();
        $ids = $campaigns->pluck('id');
        $today = CarbonImmutable::now()->startOfDay();
        $week = CampaignDay::whereIn('campaign_id', $ids)->whereDate('day', '>=', $today->subDays(6)->toDateString())->get()
            ->groupBy(fn (CampaignDay $d) => $d->day->toDateString());
        $due = Invoice::where('client_id', $client->id)->whereIn('status', Invoice::PAYABLE)->get();

        return Inertia::render('portal/Home', [
            'client' => $client->name,
            'credit' => [
                'paid' => (int) ($figure['credit_usd'] ?? 0),
                'spent' => (int) ($figure['campaign_usd'] ?? 0),
                'left' => (int) ($figure['credit_left'] ?? 0),
                'daily' => (int) ($figure['daily_usd'] ?? 0),
                'days_left' => $figure['days_left'] ?? null,
            ],
            'week' => collect(range(6, 0))->map(function (int $back) use ($today, $week) {
                $rows = $week->get($today->subDays($back)->toDateString(), collect());

                return [
                    'day' => $today->subDays($back)->toDateString(),
                    'spend' => (int) $rows->sum('spend'),
                    'results' => (int) $rows->sum('results'),
                ];
            })->all(),
            'running' => $campaigns->filter(fn (Campaign $c) => $c->isLive())->values()->map(fn (Campaign $c) => $this->campaignCard($c))->all(),
            'due' => ['count' => $due->count(), 'amount' => (int) $due->sum(fn (Invoice $i) => $i->due())],
            'next_invoice' => ($next = $due->sortBy('due_date')->first()) ? $this->invoiceRow($next) : null,
        ]);
    }

    public function campaigns(Request $request): Response
    {
        $client = $this->client($request);
        [$from, $to, $range] = $this->range($request);
        $campaigns = Campaign::with('account:id,currency')->where('client_id', $client->id)->get();
        $days = CampaignDay::whereIn('campaign_id', $campaigns->pluck('id'))->whereDate('day', '>=', $from->toDateString())->whereDate('day', '<=', $to->toDateString())->get();

        $rows = $campaigns->map(function (Campaign $c) use ($days) {
            $mine = $days->where('campaign_id', $c->id);

            return [...$this->campaignCard($c), ...$this->totals($mine)];
        })->filter(fn (array $r) => $r['live'] || $r['spend'] > 0)
            ->sortByDesc(fn (array $r) => [$r['live'] ? 1 : 0, $r['spend']])->values()->all();

        return Inertia::render('portal/Campaigns', [
            'range' => ['key' => $range, 'from' => $from->toDateString(), 'to' => $to->toDateString()],
            'totals' => $this->totals($days),
            'series' => $this->series($days, $from, $to),
            'campaigns' => $rows,
        ]);
    }

    public function campaign(Request $request, Campaign $campaign): Response
    {
        $client = $this->client($request);
        abort_unless($campaign->client_id === $client->id, 404);
        [$from, $to, $range] = $this->range($request);
        $days = CampaignDay::where('campaign_id', $campaign->id)->whereDate('day', '>=', $from->toDateString())->whereDate('day', '<=', $to->toDateString())->orderBy('day')->get();

        return Inertia::render('portal/Campaign', [
            'campaign' => [...$this->campaignCard($campaign->load('account:id,currency')), 'started' => $campaign->start_at?->setTimezone(config('app.timezone'))->format('j M Y')],
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
            // Each time it was extended, and to when.
            'extensions' => Renewal::where('campaign_id', $campaign->id)->where('status', 'approved')->latest('approved_at')->get()
                ->map(fn (Renewal $r) => [
                    'days' => $r->days,
                    'until' => $r->new_end?->format('j M Y'),
                    'at' => $r->approved_at?->format('j M Y'),
                ])->all(),
        ]);
    }

    public function invoices(Request $request): Response
    {
        $client = $this->client($request);

        return Inertia::render('portal/Invoices', [
            'invoices' => Invoice::where('client_id', $client->id)->where('status', '!=', 'draft')->latest('issue_date')->latest('id')->limit(100)->get()
                ->map(fn (Invoice $i) => $this->invoiceRow($i))->all(),
        ]);
    }

    /** The client this person belongs to; one still waiting is sent home to the waiting page. */
    private function client(Request $request): Client
    {
        $client = $request->user()->client;
        abort_if($client === null, 403);

        return $client;
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string} */
    private function range(Request $request): array
    {
        $key = in_array($request->query('range'), self::RANGES, true) ? (string) $request->query('range') : '7d';
        $today = CarbonImmutable::now()->startOfDay();

        if ($key === 'custom') {
            $from = rescue(fn () => CarbonImmutable::parse((string) $request->query('from'))->startOfDay(), $today->subDays(6), false);
            $to = rescue(fn () => CarbonImmutable::parse((string) $request->query('to'))->startOfDay(), $today, false);
            [$from, $to] = $from->gt($to) ? [$to, $from] : [$from, $to];

            // At most two years in one view.
            return [$from->max($to->subDays(730)), $to->min($today), 'custom'];
        }

        return match ($key) {
            'today' => [$today, $today, $key],
            'yesterday' => [$today->subDay(), $today->subDay(), $key],
            default => [$today->subDays((int) rtrim($key, 'd') - 1), $today, $key],
        };
    }

    /**
     * @param  Collection<int, CampaignDay>  $days
     * @return array{spend: int, results: int, clicks: int, cpr: int|null}
     */
    private function totals(Collection $days): array
    {
        $spend = (int) $days->sum('spend');
        $results = (int) $days->sum('results');

        return ['spend' => $spend, 'results' => $results, 'clicks' => (int) $days->sum('clicks'), 'cpr' => $results > 0 ? (int) round($spend / $results) : null];
    }

    /**
     * Day by day, every day of the period (a quiet day is 0), for the chart.
     *
     * @param  Collection<int, CampaignDay>  $days
     * @return list<array{day: string, spend: int, results: int}>
     */
    private function series(Collection $days, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $byDay = $days->groupBy(fn (CampaignDay $d) => $d->day->toDateString());
        $out = [];

        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            $rows = $byDay->get($day->toDateString(), collect());
            $out[] = ['day' => $day->toDateString(), 'spend' => (int) $rows->sum('spend'), 'results' => (int) $rows->sum('results')];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function campaignCard(Campaign $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'live' => $c->isLive(),
            'paused' => ! $c->isLive() && ! $c->hasEnded(),
            'ended' => $c->hasEnded(),
            'stops' => $c->stopsText(),
            'days_left' => $c->daysLeft(),
            'budget' => $c->dailyBudget(),
            'currency' => strtoupper((string) ($c->account->currency ?? 'USD')),
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

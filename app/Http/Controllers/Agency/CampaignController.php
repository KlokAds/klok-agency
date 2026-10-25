<?php

namespace App\Http\Controllers\Agency;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CampaignDay;
use App\Models\Client;
use App\Models\PilotAccount;
use App\Models\Setting;
use App\Services\CampaignBilling;
use App\Services\CampaignLimits;
use App\Services\CampaignStats;
use App\Services\PilotAccounts;
use App\Services\PilotClient;
use App\Support\Lookups;
use App\Support\Paging;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Billing by campaign: pick an ad account, see its campaigns from Klok
 * Pilot, and link each to the client it is run for. A linked campaign's
 * spend from its "bill from" day on is the client's ad spend.
 */
class CampaignController extends Controller
{
    public function index(Request $request, PilotAccounts $accounts, CampaignBilling $billing): Response
    {
        $filters = $request->validate([
            'account' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['all', 'live', 'off', 'linked', 'unlinked'])],
        ]);
        $error = null;

        try {
            $accounts->sync();
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }

        $list = PilotAccount::withCount(['campaigns as linked_count' => fn ($q) => $q->whereNotNull('client_id')])
            ->orderByDesc('linked_count')->orderByRaw("case when currency = 'USD' then 0 else 1 end")->orderBy('name')->get();
        $account = $list->firstWhere('id', (int) ($filters['account'] ?? 0)) ?? $list->first();

        if ($account && PilotClient::connected() && Cache::add("pilot:campaigns:{$account->id}", true, 120)) {
            try {
                $billing->refreshCampaigns($account);
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
                Cache::forget("pilot:campaigns:{$account->id}");
            }
        }

        $status = $filters['status'] ?? 'all';
        $clients = Client::where('status', 'active')->orderBy('name')->get(['id', 'name']);

        // The period the figures cover: today once something was spent today, else yesterday.
        $ids = Campaign::where('pilot_account_id', $account->id ?? 0)->pluck('id')->all();
        [$from, $to, $range] = CampaignStats::range($request, CampaignStats::firstDay($ids), CampaignStats::openingDay($ids));

        $campaigns = Campaign::with(['client:id,name', 'account', 'renewals' => fn ($q) => $q->whereNotIn('status', ['approved', 'expired'])->latest('id')])
            ->where('pilot_account_id', $account->id ?? 0)
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where('name', 'like', '%'.$term.'%'))
            ->when($status === 'live', fn ($q) => $q->running())
            ->when($status === 'off', fn ($q) => $q->whereNot(fn ($w) => $w->running()))
            ->when($status === 'linked', fn ($q) => $q->whereNotNull('client_id'))
            ->when($status === 'unlinked', fn ($q) => $q->whereNull('client_id'))
            ->orderByRaw('case when last_spend_day is null then 1 else 0 end')
            ->orderByDesc('last_spend_day')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(Paging::size($request))
            ->withQueryString();
        $period = CampaignDay::whereIn('campaign_id', $campaigns->getCollection()->pluck('id'))
            ->whereDate('day', '>=', $from->toDateString())->whereDate('day', '<=', $to->toDateString())
            ->groupBy('campaign_id')->selectRaw('campaign_id, sum(spend) as spend, sum(results) as results')->get()->keyBy('campaign_id');
        $campaigns->through(fn (Campaign $c) => [
            'id' => $c->id,
            'slug' => $c->slug,
            'name' => $c->name,
            'status' => $c->status,
            'live' => $c->isLive(),
            'objective' => $c->objective,
            'daily_budget' => $c->dailyBudget(),
            'spend_cap' => $c->spend_cap,
            'paused_for_credit' => $c->paused_for_credit_at !== null,
            // Who paused it, as the client sees it: their own, the team, for money, on our side.
            'state' => $c->client_id !== null ? $c->clientState() : null,
            // What the team may change from here: Meta campaigns in USD, not ended.
            'controllable' => CampaignLimits::guarded($c) && ! $c->hasEnded(),
            'limits_error' => $c->limits_error,
            'guarded' => $c->client_id !== null && CampaignLimits::guarded($c),
            'renewal' => ($r = $c->renewals->first()) ? ['id' => $r->id, 'status' => $r->status] : null,
            'start_at' => $c->start_at?->toDateString(),
            'stop_at' => $c->lastDay()?->toDateString(),
            'ends_at' => $c->stop_at?->toIso8601String(),
            'ended' => $c->hasEnded(),
            'spend_total' => $c->spend_total,
            'spend_7d' => $c->spend_7d,
            'period' => ['spend' => (int) ($period[$c->id]->spend ?? 0), 'results' => (int) ($period[$c->id]->results ?? 0)],
            'last_spend_day' => $c->last_spend_day?->toDateString(),
            'client_id' => $c->client_id,
            'client' => $c->client?->name,
            'bill_from' => $c->bill_from?->toDateString(),
            'start' => $c->start_at?->toDateString(),
            'suggested' => $c->client_id ? null : CampaignBilling::suggestClient($c->name, $clients)?->only(['id', 'name']),
        ]);

        $counts = Campaign::where('pilot_account_id', $account->id ?? 0)
            ->selectRaw('count(*) as total, sum(case when client_id is null then 0 else 1 end) as linked')->first();

        return Inertia::render('agency/Campaigns', [
            'connected' => PilotClient::connected(),
            'error' => $error,
            'accounts' => $list->map(fn (PilotAccount $a) => [
                'value' => $a->id, 'label' => $a->name, 'hint' => trim(($a->currency ?? '').' · '.($a->shop ?? ''), ' ·'),
            ])->values(),
            'account' => $account ? [
                'id' => $account->id, 'name' => $account->name, 'external_id' => $account->external_id,
                'currency' => $account->currency, 'shop' => $account->shop,
            ] : null,
            'campaigns' => $campaigns,
            'counts' => ['total' => (int) ($counts->total ?? 0), 'linked' => (int) ($counts->linked ?? 0)],
            'filters' => ['q' => $filters['q'] ?? null, 'status' => $status],
            'range' => ['key' => $range, 'from' => $from->toDateString(), 'to' => $to->toDateString()],
            'synced_at' => Setting::get('pilot_spend_synced_at'),
            'options' => ['clients' => Lookups::clients(true)],
        ]);
    }

    /** One campaign's numbers for the team: spend, results and clicks over a period, day by day. */
    public function show(Request $request, Campaign $campaign): Response
    {
        $campaign->load(['account:id,name,platform,currency,problem', 'client:id,name']);
        [$from, $to, $range] = CampaignStats::range($request, CampaignStats::firstDay([$campaign->id]), '7d');
        $days = CampaignDay::where('campaign_id', $campaign->id)->whereDate('day', '>=', $from->toDateString())->whereDate('day', '<=', $to->toDateString())->orderBy('day')->get();

        return Inertia::render('agency/Campaign', [
            'campaign' => [
                'id' => $campaign->id,
                'slug' => $campaign->slug,
                'name' => $campaign->name,
                'account' => $campaign->account?->name,
                'client' => $campaign->client ? ['id' => $campaign->client->id, 'name' => $campaign->client->name] : null,
                'live' => $campaign->isLive(),
                'ended' => $campaign->hasEnded(),
                'state' => $campaign->client_id !== null ? $campaign->clientState() : null,
                'stops' => $campaign->stopsText(),
                'started' => $campaign->start_at?->setTimezone(config('app.timezone'))->format('j M Y'),
                'budget' => $campaign->dailyBudget(),
                'spend_cap' => $campaign->spend_cap,
                'currency' => strtoupper((string) ($campaign->account->currency ?? 'USD')),
                'result_name' => $campaign->resultName(),
                'bill_from' => $campaign->bill_from?->format('j M Y'),
                'spend_total' => $campaign->spend_total,
            ],
            'range' => ['key' => $range, 'from' => $from->toDateString(), 'to' => $to->toDateString()],
            'totals' => CampaignStats::totals($days),
            'series' => CampaignStats::series($days, $from, $to),
            'days' => CampaignStats::dayRows($days),
            'synced_at' => Setting::get('pilot_spend_synced_at'),
        ]);
    }

    /** The team switches a campaign off on Meta; its client sees it as paused by Klok Ads. */
    public function pause(Request $request, Campaign $campaign, CampaignLimits $limits): RedirectResponse
    {
        return $this->change($campaign, fn () => $limits->teamSetActive($campaign, false, $request->user()), __(':campaign থামানো হয়েছে।', ['campaign' => $campaign->name]));
    }

    /** The team switches it on again (whoever paused it); the money guard still caps it. */
    public function resume(Request $request, Campaign $campaign, CampaignLimits $limits): RedirectResponse
    {
        return $this->change($campaign, fn () => $limits->teamSetActive($campaign, true, $request->user()), __(':campaign আবার চালু হয়েছে।', ['campaign' => $campaign->name]));
    }

    /** The team sets the budget a day, in dollars. */
    public function budget(Request $request, Campaign $campaign, CampaignLimits $limits): RedirectResponse
    {
        $data = $request->validate(['budget' => ['required', 'numeric', 'min:1', 'max:100000']], [], ['budget' => __('দৈনিক budget')]);

        return $this->change($campaign, fn () => $limits->teamBudget($campaign, (int) round((float) $data['budget'] * 100), $request->user()), __(':campaign-এর দৈনিক budget বদলানো হয়েছে।', ['campaign' => $campaign->name]));
    }

    /** One change on Meta through Pilot; its refusal is shown as it is. */
    private function change(Campaign $campaign, \Closure $change, string $done): RedirectResponse
    {
        abort_unless(CampaignLimits::guarded($campaign->loadMissing('account')), 422);

        try {
            $change();
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['control' => $e->getMessage()]);
        }

        $this->toast($done);

        return back();
    }

    /** Which client a campaign is billed to, and from which day. */
    public function link(Request $request, Campaign $campaign, CampaignBilling $billing): RedirectResponse
    {
        $data = $request->validate([
            'client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')],
            'bill_from' => ['nullable', 'date'],
        ]);

        $before = $campaign->client_id;
        $campaign->update([
            'client_id' => $data['client_id'] ?? null,
            'bill_from' => isset($data['client_id'])
                ? ($data['bill_from'] ?? $billing->defaultBillFrom($campaign, (int) $data['client_id'])->toDateString())
                : null,
        ]);

        $billing->rebuild(array_values(array_unique(array_filter([(int) $before, (int) $campaign->client_id]))));
        $this->toast($campaign->client_id
            ? __(':campaign এখন :client-এর — :date থেকে খরচ তার হিসাবে যাবে।', ['campaign' => $campaign->name, 'client' => $campaign->client?->name, 'date' => $campaign->bill_from?->format('j M Y')])
            : __(':campaign কোনো client-এর সাথে যুক্ত নয়।', ['campaign' => $campaign->name]));

        return back();
    }

    /** Links every unlinked campaign of the account whose name names one client. */
    public function linkSuggested(Request $request, CampaignBilling $billing): RedirectResponse
    {
        $data = $request->validate([
            'account' => ['required', 'integer', Rule::exists('pilot_accounts', 'id')],
            'bill_from' => ['nullable', 'date'],
        ]);

        $clients = Client::where('status', 'active')->get(['id', 'name']);
        $touched = [];

        foreach (Campaign::where('pilot_account_id', $data['account'])->whereNull('client_id')->get() as $campaign) {
            $client = CampaignBilling::suggestClient($campaign->name, $clients);

            if ($client) {
                $campaign->update([
                    'client_id' => $client->id,
                    'bill_from' => $data['bill_from'] ?? $billing->defaultBillFrom($campaign, $client->id)->toDateString(),
                ]);
                $touched[] = $client->id;
            }
        }

        $billing->rebuild(array_values(array_unique($touched)));
        $this->toast(__(':countটা campaign নাম দেখে client-এর সাথে যুক্ত হলো।', ['count' => count($touched)]));

        return back();
    }

    /** Pulls the campaign list and the recent spend from Pilot now. */
    public function sync(Request $request, CampaignBilling $billing): RedirectResponse
    {
        try {
            if ($request->integer('account')) {
                $account = PilotAccount::findOrFail($request->integer('account'));
                $billing->refreshCampaigns($account);
                Cache::put("pilot:campaigns:{$account->id}", true, 120);
            }

            $result = $billing->sync();
            Cache::put('pilot:spend-sync', true, CampaignBilling::SYNC_MINUTES * 60);
            $this->toast(__('Klok Pilot থেকে আনা হলো — :countটা campaign-এর খরচ মিলানো হয়েছে।', ['count' => $result['campaigns']]));
        } catch (RuntimeException $e) {
            $this->toast($e->getMessage(), 'error');
        }

        return back();
    }
}

<?php

namespace App\Http\Controllers\Books;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PortalController;
use App\Models\BdtEntry;
use App\Models\Campaign;
use App\Models\CampaignDay;
use App\Models\Client;
use App\Models\Invoice;
use App\Payments\PaySettings;
use App\Services\Books;
use App\Services\CampaignStats;
use App\Support\InvoiceView;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One client's full report for a period, as a document the client may be handed: what they
 * paid, what their ads spent day by day and campaign by campaign, what it brought, and where
 * the account stands. No cost, rate or margin of the agency's own is on it.
 */
class ClientReportController extends Controller
{
    public function __invoke(Request $request, Client $client, Books $books): Response
    {
        $campaigns = Campaign::with('account:id,name')->where('client_id', $client->id)->whereNotNull('bill_from')->orderBy('name')->get();
        $first = CampaignStats::firstDay($campaigns->pluck('id')->all());
        $firstPaid = BdtEntry::where('client_id', $client->id)->whereIn('category', PortalController::PAID)->min('entry_date');
        $start = collect([$first, $firstPaid ? CarbonImmutable::parse($firstPaid) : null])->filter()->min();
        [$from, $to, $key] = CampaignStats::range($request, $start instanceof CarbonImmutable ? $start : null, 'all');

        // The ads: every billed day in the period, campaign by campaign.
        $days = CampaignDay::whereIn('campaign_id', $campaigns->pluck('id'))
            ->whereDate('day', '>=', $from->toDateString())->whereDate('day', '<=', $to->toDateString())
            ->orderBy('day')->get()
            ->filter(fn (CampaignDay $d) => $d->day->gte($campaigns->firstWhere('id', $d->campaign_id)?->bill_from));

        $campaignRows = $campaigns->map(function (Campaign $c) use ($days) {
            $own = $days->where('campaign_id', $c->id);

            return [
                'id' => $c->id,
                'name' => $c->name,
                'account' => $c->account?->name,
                'status' => $c->isLive() ? 'live' : ($c->hasEnded() ? 'ended' : 'off'),
                'days' => $own->where('spend', '>', 0)->count(),
                'first' => $own->min('day')?->toDateString(),
                'last' => $own->max('day')?->toDateString(),
                ...CampaignStats::totals($own),
            ];
        })->filter(fn ($row) => $row['spend'] > 0 || $row['status'] === 'live')->values()->all();

        // Day by day, grouped by month: the spend of all campaigns that day.
        $byDay = $days->groupBy(fn (CampaignDay $d) => $d->day->toDateString())->map(fn ($rows, $day) => [
            'day' => (string) $day,
            'spend' => (int) $rows->sum('spend'),
            'results' => (int) $rows->sum('results'),
            'clicks' => (int) $rows->sum('clicks'),
            'campaigns' => $rows->where('spend', '>', 0)->count(),
        ])->sortKeys()->values();
        $months = $byDay->groupBy(fn ($r) => substr($r['day'], 0, 7))->map(fn ($rows, $month) => [
            'month' => (string) $month,
            'spend' => (int) $rows->sum('spend'),
            'results' => (int) $rows->sum('results'),
            'days' => $rows->values()->all(),
        ])->values()->all();

        // Payments in the period, as the client made them.
        $payments = BdtEntry::where('client_id', $client->id)->whereIn('category', PortalController::PAID)
            ->whereDate('entry_date', '>=', $from->toDateString())->whereDate('entry_date', '<=', $to->toDateString())
            ->orderBy('entry_date')->orderBy('id')->get()
            ->map(fn (BdtEntry $e) => [
                'id' => $e->id,
                'date' => $e->entry_date?->toDateString(),
                'description' => $e->description,
                'via' => $e->pay_via ? (BdtEntry::PAY_VIA[$e->pay_via] ?? $e->pay_via) : null,
                'amount' => (int) $e->amount,
                'usd' => $e->usd_credit !== null ? (int) $e->usd_credit : null,
                'refund' => $e->category === 'client_refund',
            ])->values()->all();

        $invoices = $client->invoices()->with('client:id,name')
            ->whereDate('issue_date', '>=', $from->toDateString())->whereDate('issue_date', '<=', $to->toDateString())
            ->where('status', '!=', 'void')->orderBy('issue_date')->get()
            ->map(fn (Invoice $i) => InvoiceView::row($i))->values()->all();

        // Where the account stands today, the way the client's own portal says it.
        $figure = collect($books->clients())->firstWhere('id', $client->id) ?? [];

        return Inertia::render('clients/Report', [
            'client' => $client->only(['id', 'name', 'company', 'phone', 'email']),
            'business' => PaySettings::business(),
            'range' => ['key' => $key, 'from' => $from->toDateString(), 'to' => $to->toDateString()],
            'generated_at' => now()->toIso8601String(),
            'summary' => [
                'spend' => (int) $days->sum('spend'),
                'results' => (int) $days->sum('results'),
                'clicks' => (int) $days->sum('clicks'),
                'days' => $byDay->where('spend', '>', 0)->count(),
                'paid' => (int) collect($payments)->where('refund', false)->sum('amount'),
                'refunded' => (int) -collect($payments)->where('refund', true)->sum('amount'),
                'campaigns' => count($campaignRows),
            ],
            'standing' => [
                'credit_usd' => (int) ($figure['credit_usd'] ?? 0),
                'spent_usd' => (int) ($figure['campaign_usd'] ?? 0),
                'left_usd' => (int) ($figure['credit_left'] ?? 0),
                'owed_usd' => (int) ($figure['usd_pending'] ?? 0),
                'daily_usd' => (int) ($figure['daily_usd'] ?? 0),
                'days_left' => $figure['days_left'] ?? null,
            ],
            'campaigns' => $campaignRows,
            'months' => $months,
            'payments' => $payments,
            'invoices' => $invoices,
        ]);
    }
}

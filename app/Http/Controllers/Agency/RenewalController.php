<?php

namespace App\Http\Controllers\Agency;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Renewal;
use App\Services\CampaignLimits;
use App\Services\Renewals;
use App\Support\ClientMessage;
use App\Support\Money;
use App\Support\Paging;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Renewals of prepaid campaigns from the agency's side: every link with
 * where it stands, sending it to the client, and the OK that extends a
 * paid one on Meta. Admins can also extend a campaign by hand.
 */
class RenewalController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate(['status' => ['nullable', Rule::in([...array_keys(Renewal::STATUSES), 'all'])]]);
        $status = $filters['status'] ?? 'all';

        $renewals = Renewal::with(['campaign:id,name', 'client:id,name', 'invoice:id,number,total,paid,status'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderByRaw("case status when 'paid' then 0 when 'open' then 1 when 'invoiced' then 2 else 3 end")
            ->latest('id')
            ->paginate(Paging::size($request))
            ->withQueryString()
            ->through(fn (Renewal $r) => $this->row($r));

        return Inertia::render('agency/Renewals', [
            'renewals' => $renewals,
            'filters' => ['status' => $status],
            'counts' => Renewal::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'statuses' => Renewal::STATUSES,
        ]);
    }

    public function show(Renewal $renewal): Response
    {
        $renewal->load(['campaign.account', 'client', 'invoice']);

        return Inertia::render('agency/Renewal', [
            'renewal' => [
                ...$this->row($renewal),
                'url' => $renewal->publicUrl(),
                'message' => ClientMessage::links($renewal->client?->phone, ClientMessage::campaignRenewal($renewal)),
                'invoice_id' => $renewal->invoice_id,
                'error' => $renewal->error,
                'stop_at' => $renewal->campaign?->lastDay()?->toDateString(),
                'paused' => $renewal->campaign?->paused_for_credit_at !== null,
            ],
            'statuses' => Renewal::STATUSES,
        ]);
    }

    public function approve(Request $request, Renewal $renewal, Renewals $renewals): RedirectResponse
    {
        try {
            $end = $renewals->approve($renewal->load(['campaign.account', 'invoice']), $request->user());
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['renewal' => $e->getMessage()]);
        }

        $this->toast(__(':campaign এখন :date পর্যন্ত চলবে।', ['campaign' => $renewal->campaign->name, 'date' => $end->format('j M Y')]));

        return back();
    }

    /** An admin gives a campaign more days by hand (the client paid some other way). */
    public function extend(Request $request, Campaign $campaign, CampaignLimits $limits): RedirectResponse
    {
        $data = $request->validate(['days' => ['required', 'integer', 'min:1', 'max:90']]);
        abort_unless(CampaignLimits::guarded($campaign->load('account')), 422);

        try {
            $end = $limits->extend($campaign, (int) $data['days'], $request->user(), 'By hand in Klok Finance');
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['days' => $e->getMessage()]);
        }

        $this->toast(__(':campaign এখন :date পর্যন্ত চলবে।', ['campaign' => $campaign->name, 'date' => $end->format('j M Y')]));

        return back();
    }

    /** @return array<string, mixed> */
    private function row(Renewal $r): array
    {
        return [
            'id' => $r->id,
            'status' => $r->status,
            'campaign' => $r->campaign?->name,
            'campaign_id' => $r->campaign_id,
            'client' => $r->client?->name,
            'client_id' => $r->client_id,
            'ends_on' => $r->ends_on->toDateString(),
            'budget' => $r->budget,
            'days' => $r->days,
            'usd' => $r->usd,
            'invoice' => $r->invoice ? ['id' => $r->invoice->id, 'uid' => $r->invoice->uid, 'number' => $r->invoice->number, 'total' => Money::bdt($r->invoice->total), 'status' => $r->invoice->status] : null,
            'new_end' => $r->new_end?->toDateString(),
            'decided_at' => $r->decided_at?->toIso8601String(),
            'paid_at' => $r->paid_at?->toIso8601String(),
            'approved_at' => $r->approved_at?->toIso8601String(),
        ];
    }
}

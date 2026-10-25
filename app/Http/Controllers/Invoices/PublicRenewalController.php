<?php

namespace App\Http\Controllers\Invoices;

use App\Http\Controllers\Controller;
use App\Models\Renewal;
use App\Payments\PaySettings;
use App\Services\Invoices;
use App\Services\Renewals;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The client's renewal page, /r/{token}: no sign-in, the token is the key.
 * The client picks how many more days to run (and pays the invoice that
 * makes), or ends the contract. It can be printed or saved as a PDF.
 */
class PublicRenewalController extends Controller
{
    public function show(string $token, Renewals $renewals): Response
    {
        $renewal = $this->find($token);
        $campaign = $renewal->campaign;
        $rate = Invoices::clientRate();
        $results = $campaign->days()->where('day', '>=', $campaign->bill_from?->toDateString() ?? '1970-01-01')->get();

        return Inertia::render('public/Renewal', [
            'token' => $token,
            'business' => PaySettings::business(),
            'renewal' => [
                'status' => $renewal->status,
                'client' => $renewal->client?->name,
                'campaign' => $campaign->name,
                'ends_on' => $renewal->ends_on->toDateString(),
                'budget' => $renewal->budget,
                'rate' => $rate,
                'days' => $renewal->days,
                'usd' => $renewal->usd,
                'new_end' => $renewal->new_end?->toDateString(),
                'invoice_url' => $renewal->invoice && $renewal->invoice->status !== 'void' ? $renewal->invoice->publicUrl() : null,
                'invoice_total' => $renewal->invoice?->total,
                'since' => $campaign->bill_from?->toDateString(),
                'spent' => (int) $results->sum('spend'),
                'results' => (int) $results->sum('results'),
                'clicks' => (int) $results->sum('clicks'),
                'choices' => Renewal::DAY_CHOICES,
                'due' => $renewals->dueOf($renewal->client_id),
                'from' => CarbonImmutable::parse($renewal->ends_on)->max(CarbonImmutable::now()->startOfDay())->addDay()->toDateString(),
            ],
        ]);
    }

    /** More days: makes (or remakes) the invoice and sends the client to pay it. */
    public function choose(Request $request, string $token, Renewals $renewals): HttpResponse
    {
        $renewal = $this->find($token);
        $data = $request->validate(['days' => ['required', 'integer', 'min:1', 'max:90']]);

        // The client chose the days: once paid, the campaign runs on by itself, as on the portal.
        $renewal->forceFill(['self_serve' => true])->save();

        try {
            $invoice = $renewals->choose($renewal, (int) $data['days']);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['days' => $e->getMessage()]);
        }

        return Inertia::location($invoice->publicUrl());
    }

    public function decline(Request $request, string $token, Renewals $renewals): RedirectResponse
    {
        $request->validate(['confirm' => ['required', Rule::in(['1', 1, true])]]);
        $renewals->decline($this->find($token));

        return back();
    }

    private function find(string $token): Renewal
    {
        abort_unless(strlen($token) === 40, 404);

        $renewal = Renewal::with(['campaign', 'client', 'invoice'])->where('token', $token)->firstOrFail();
        abort_if($renewal->status === 'expired', 410);

        return $renewal;
    }
}

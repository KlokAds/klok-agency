<?php

namespace App\Http\Controllers\Agency;

use App\Http\Controllers\Controller;
use App\Models\PilotAccount;
use App\Services\PilotAccounts;
use App\Services\PilotClient;
use App\Support\Lookups;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Every ad account connected in Klok Pilot, with its campaigns, and which
 * client each one is run for.
 */
class PilotAccountController extends Controller
{
    public function index(Request $request, PilotAccounts $pilot): Response
    {
        $days = $request->integer('days', 7);
        $error = null;

        try {
            $pilot->sync();
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }

        $accounts = PilotAccount::with('client:id,name')
            ->orderByRaw('case when client_id is null then 1 else 0 end')
            ->orderBy('name')
            ->get()
            ->map(fn (PilotAccount $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'external_id' => $a->external_id,
                'platform' => $a->platform,
                'shop' => $a->shop,
                'client_id' => $a->client_id,
                'client' => $a->client?->name,
                ...$pilot->report($a, $days),
            ]);

        return Inertia::render('agency/AdAccounts', [
            'connected' => PilotClient::connected(),
            'error' => $error,
            'days' => in_array($days, PilotAccounts::DAYS, true) ? $days : 7,
            'accounts' => $accounts,
            'options' => ['clients' => Lookups::clients()],
        ]);
    }

    /** Which client an ad account is run for. */
    public function link(Request $request, PilotAccount $account): RedirectResponse
    {
        $data = $request->validate(['client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')]]);

        $account->update(['client_id' => $data['client_id'] ?? null]);
        $this->toast($account->client_id ? __(':account এখন :client-এর।', ['account' => $account->name, 'client' => $account->client?->name]) : __(':account কোনো client-এর সাথে যুক্ত নয়।', ['account' => $account->name]));

        return back();
    }

    /** Pull the latest list and numbers from Pilot now. */
    public function refresh(PilotAccounts $pilot): RedirectResponse
    {
        try {
            $pilot->sync(force: true);
            foreach (PilotAccount::pluck('pilot_id') as $id) {
                foreach (PilotAccounts::DAYS as $days) {
                    cache()->forget("pilot:report:{$id}:{$days}");
                }
            }
            $this->toast(__('Klok Pilot থেকে নতুন করে আনা হয়েছে।'));
        } catch (RuntimeException $e) {
            $this->toast($e->getMessage(), 'error');
        }

        return back();
    }
}

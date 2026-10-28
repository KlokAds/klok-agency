<?php

namespace App\Http\Controllers\Books;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\CardEvent;
use App\Models\FxBatch;
use App\Models\MoneyApp;
use App\Services\AdAccountBalances;
use App\Services\Books;
use App\Services\CampaignForecast;
use App\Services\CardEvents;
use App\Services\MetaReceipts;
use App\Services\PlatformDue;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where the dollars are: the card and other USD accounts, what each ad
 * platform has not charged yet, and how much bought USD is left in the FX
 * batches (and at what rate). The USD accounts are kept here too.
 */
class DollarController extends Controller
{
    public function index(Books $books, CampaignForecast $forecast, AdAccountBalances $balances): Response
    {
        $summary = $books->summary();
        $fifo = $books->fifo();

        // Each card's own balance (NSave main and its copy); together they are the card.
        $cards = collect($books->cardBalances())->keyBy('id');

        $accounts = Account::orderBy('sort')->get()->map(fn (Account $a) => [
            'id' => $a->id,
            'code' => $a->code,
            'name' => $a->name,
            'kind' => $a->kind,
            'last4' => $a->last4,
            'topup_from_id' => $a->topup_from_id,
            'is_active' => $a->is_active,
            // A card's balance counts its own rows and the platform charges it paid.
            'balance' => match ($a->kind) {
                'card' => $cards[$a->id]['balance'] ?? 0,
                'holding' => (int) $books->usdEntries()->where('account_id', $a->id)->sum('amount'),
                default => null,
            },
        ]);

        $batches = FxBatch::orderBy('number')->get()->map(fn (FxBatch $b) => [
            'id' => $b->id,
            'number' => $b->number,
            'date' => $b->purchased_on?->toDateString(),
            'usd' => $b->usd,
            'bdt' => $b->bdt,
            'rate' => round($b->rate(), 2),
            'left' => $fifo['batches'][$b->id]['left'] ?? 0,
        ]);

        return Inertia::render('books/Dollars', [
            'summary' => [
                'card' => $summary['card'],
                'holding' => $summary['holding'],
                'platform_due' => $summary['platform_due'],
                'card_after' => $summary['card_after'],
                'business_usd' => $summary['business_usd'],
                'usd_check' => $summary['usd_check'],
                'usd_at_cost' => $summary['usd_at_cost'],
                'platforms' => $summary['platforms'],
                'meta_live' => $summary['meta_live'],
            ],
            'platforms' => $summary['platforms'],
            'ad_accounts' => $balances->accounts(),
            'forecast' => $forecast->meta(),
            'accounts' => $accounts,
            'cards' => $cards->values(),
            'card_events' => CardEvents::recent(),
            'money_apps' => CardEvents::apps(),
            'batches' => $batches,
            'kinds' => collect(Account::KINDS)->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values(),
        ]);
    }

    /** What a new card charge on this platform would most likely pay for. */
    public function pending(Request $request, Account $account, PlatformDue $due): JsonResponse
    {
        abort_unless($account->kind === 'platform', 404);
        $cents = Money::toMinor((string) $request->query('amount', '0'));

        return response()->json(['rows' => $due->suggest($account, max(0, $cents))]);
    }

    public function storeAccount(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'kind' => ['required', Rule::in(['card', 'holding', 'platform'])],
            'last4' => ['nullable', 'digits:4', Rule::unique('accounts', 'last4')],
            'app_package' => ['nullable', Rule::exists('money_apps', 'package')],
            'topup_from_id' => ['nullable', Rule::exists('accounts', 'id')->whereIn('kind', ['card', 'holding'])],
        ]);

        if ($data['kind'] !== 'card') {
            $data['last4'] = null;
        }

        if ($data['kind'] === 'platform') {
            $data['app_package'] = $data['topup_from_id'] = null;
        }

        Account::create($data + [
            'code' => str($data['name'])->slug('_')->limit(24, '')->append('_'.now()->format('His'))->toString(),
            'sort' => (int) Account::max('sort') + 1,
        ]);
        $this->toast(__('নতুন account যোগ হয়েছে।'));
        $this->cardsChanged();

        return back();
    }

    public function updateAccount(Request $request, Account $account): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'is_active' => ['required', 'boolean'],
            'last4' => ['nullable', 'digits:4', Rule::unique('accounts', 'last4')->ignore($account->id)],
            'topup_from_id' => ['nullable', Rule::exists('accounts', 'id')->whereIn('kind', ['card', 'holding'])->whereNot('id', $account->id)],
        ]);

        // A card is what balances are measured against; it stays on.
        if ($account->kind === 'card') {
            $data['is_active'] = true;
        } else {
            unset($data['last4']);
        }

        if ($account->kind === 'platform') {
            unset($data['topup_from_id']);
        }

        $account->update($data);
        $this->toast(__('Account আপডেট হয়েছে।'));
        $this->cardsChanged();

        return back();
    }

    /** A new card or bank app is ours: a new account for it, or joined to one in the books. */
    public function approveApp(Request $request, MoneyApp $app, CardEvents $events): RedirectResponse
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(['new', 'link'])],
            'account_id' => ['nullable', 'required_if:mode,link', Rule::exists('accounts', 'id')->whereIn('kind', ['card', 'holding'])],
            'name' => ['nullable', 'string', 'max:80'],
            'kind' => ['nullable', Rule::in(['card', 'holding'])],
            'last4' => ['nullable', 'digits:4', Rule::unique('accounts', 'last4')->ignore($request->integer('account_id') ?: null)],
            'topup_from_id' => ['nullable', Rule::exists('accounts', 'id')->whereIn('kind', ['card', 'holding'])],
        ]);

        $account = $events->approve($app, $data);
        $this->toast(__(':app চালু — এখন থেকে :account-এ নিজে উঠবে।', ['app' => $app->name, 'account' => $account->name]));

        return back();
    }

    /** A card or bank app on or off. */
    public function turnApp(Request $request, MoneyApp $app, CardEvents $events): RedirectResponse
    {
        $on = $request->boolean('on');
        $events->turn($app, $on);
        $this->toast($on ? __(':app চালু হয়েছে।', ['app' => $app->name]) : __(':app বন্ধ — এর লেনদেন আর খাতায় উঠবে না।', ['app' => $app->name]));

        return back();
    }

    /** A held NSave line the Owner puts on a card (the notification did not say which). */
    public function placeEvent(Request $request, CardEvent $event, CardEvents $events): RedirectResponse
    {
        $data = $request->validate(['card_id' => ['required', Rule::exists('accounts', 'id')->where('kind', 'card')]]);
        abort_unless($event->status === 'held', 422);

        $events->place($event, Account::query()->findOrFail((int) $data['card_id']));
        $this->toast(__('খাতায় তোলা হয়েছে।'));

        return back();
    }

    /** A held NSave line the Owner sets aside (written by hand, or not ours). */
    public function dismissEvent(CardEvent $event): RedirectResponse
    {
        abort_unless($event->status === 'held', 422);
        $event->update(['status' => 'ignored', 'problem' => __('Owner সরিয়ে রেখেছেন')]);
        $this->toast(__('সরিয়ে রাখা হয়েছে।'));

        return back();
    }

    /** Cards or their digits changed: held lines and Meta's charges on them come in now. */
    private function cardsChanged(): void
    {
        rescue(fn () => app(CardEvents::class)->retryHeld());
        Cache::forget('meta-charges:pilot');
        rescue(fn () => app(MetaReceipts::class)->fromPilotIfDue());
    }
}

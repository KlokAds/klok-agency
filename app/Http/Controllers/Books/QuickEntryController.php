<?php

namespace App\Http\Controllers\Books;

use App\Http\Controllers\Controller;
use App\Models\AdRun;
use App\Models\BdtEntry;
use App\Models\Wallet;
use App\Services\Agency;
use App\Services\MoveFees;
use App\Services\Postings;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The "+ নতুন" menu: each everyday job posts to the right books at once.
 */
class QuickEntryController extends Controller
{
    private const PERMISSIONS = [
        'ad-spend' => 'usd.create',
        'own-spend' => 'usd.create',
        'card-charge' => 'usd.create',
        'transfer' => 'usd.create',
        'client-payment' => 'bdt.create',
        'expense' => 'bdt.create',
        'owner' => 'bdt.create',
        'buy-usd' => 'fx.create',
    ];

    public function __invoke(Request $request, string $type, Postings $postings, Agency $agency): RedirectResponse
    {
        abort_unless(isset(self::PERMISSIONS[$type]), 404);
        abort_unless($request->user()->can(self::PERMISSIONS[$type]), 403);

        $base = [
            'entry_date' => ['required', 'date', 'before_or_equal:'.now()->addDay()->toDateString()],
            'description' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
        $wallet = ['wallet' => ['required', Rule::in(Wallet::keys())]];

        $message = match ($type) {
            'ad-spend' => $this->run($request, $base + [
                'client_id' => ['required', 'exists:clients,id'],
                'account_id' => ['required', 'exists:accounts,id'],
                'amount' => ['required', Money::RULE],
                'status' => ['required', Rule::in(['pending', 'settled'])],
                'run_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            ], fn ($d) => $this->adSpendWithRun($d, $postings, $agency), __('Ad খরচ লেখা হয়েছে।')),

            'own-spend' => $this->run($request, [
                'description' => ['required', 'string', 'max:255'],
            ] + $base + [
                'account_id' => ['required', 'exists:accounts,id'],
                'amount' => ['required', Money::RULE],
            ], fn ($d) => $postings->ownSpend($d), __('নিজের খরচ লেখা হয়েছে।')),

            'card-charge' => $this->run($request, $base + [
                'account_id' => ['required', 'exists:accounts,id'],
                'amount' => ['required', Money::RULE],
                'allocations' => ['nullable', 'array', 'max:100'],
                'allocations.*' => ['nullable', Money::RULE],
            ], fn ($d) => $postings->cardCharge([...$d, 'allocations' => $this->allocations($d)]), __('Card charge লেখা হয়েছে।')),

            'transfer' => $this->run($request, $base + [
                'from_id' => ['required', 'exists:accounts,id'],
                'to_id' => ['required', 'exists:accounts,id'],
                'amount' => ['required', Money::RULE],
            ], fn ($d) => $postings->transfer($d), __('Transfer লেখা হয়েছে।')),

            'client-payment' => $this->run($request, $base + [
                'client_id' => ['required', 'exists:clients,id'],
                'category' => ['required', Rule::in(['client_payment', 'service_income'])],
                'pay_via' => ['required', Rule::in(array_keys(BdtEntry::PAY_VIA))],
                'usd_credit' => ['nullable', Money::RULE],
                ...$wallet,
                'amount' => ['required', Money::RULE],
                'settle_ids' => ['array'],
                'settle_ids.*' => ['integer'],
            ], fn ($d) => $postings->clientPayment($d, $d['settle_ids'] ?? []), __('Client-এর টাকা জমা হয়েছে।')),

            'expense' => $this->run($request, [
                'description' => ['required', 'string', 'max:255'],
            ] + $base + [
                'expense_head_id' => ['required', 'exists:expense_heads,id'],
                ...$wallet,
                'bank_fee' => ['boolean'],
                'amount' => ['required', Money::RULE],
            ], fn ($d) => $postings->expense($d), __('খরচ লেখা হয়েছে।')),

            'owner' => $this->run($request, $base + [
                'direction' => ['required', Rule::in(['in', 'out', 'loan', 'repay'])],
                ...$wallet,
                'bank_fee' => ['boolean'],
                'amount' => ['required', Money::RULE],
            ], fn ($d) => $postings->owner($d), __('Owner-এর হিসাব লেখা হয়েছে।')),

            'buy-usd' => $this->run($request, $base + [
                'usd' => ['required', Money::RULE],
                'bdt' => ['required', Money::RULE],
                ...$wallet,
            ], fn ($d) => $postings->buyUsd($d), __('USD কেনা লেখা হয়েছে — নতুন FX batch তৈরি।')),
        };

        $this->toast($message);

        return back();
    }

    /**
     * Ad spend; when "how many days" is given the ad is also tracked as an
     * ad run, so its renew reminder comes on time.
     *
     * @param  array<string, mixed>  $data
     */
    private function adSpendWithRun(array $data, Postings $postings, Agency $agency): void
    {
        DB::transaction(function () use ($data, $postings, $agency) {
            $entry = $postings->adSpend($data);

            if (empty($data['run_days'])) {
                return;
            }

            $days = (int) $data['run_days'];
            $start = CarbonImmutable::parse($data['entry_date']);

            $run = AdRun::create([
                'client_id' => $entry->client_id,
                'account_id' => $entry->account_id,
                'title' => $entry->description,
                'budget_usd' => -$entry->amount,
                'daily_usd' => (int) round(-$entry->amount / $days),
                'days' => $days,
                'start_on' => $start->toDateString(),
                'end_on' => $start->addDays($days - 1)->toDateString(),
                'usd_entry_id' => $entry->id,
                'status' => 'running',
            ]);

            $agency->plan($run);
        });
    }

    /**
     * What a card charge says it paid for: key => cents, never more than the charge.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, int>|null
     */
    private function allocations(array $data): ?array
    {
        $out = [];

        foreach ((array) ($data['allocations'] ?? []) as $key => $amount) {
            if (! preg_match('/^(c:\d+|l:\d+|o)$/', (string) $key)) {
                throw ValidationException::withMessages(['allocations' => __('ভাগ বোঝা গেল না — আবার চেষ্টা করুন।')]);
            }

            $cents = blank($amount) ? 0 : Money::toMinor((string) $amount);
            if ($cents > 0) {
                $out[(string) $key] = $cents;
            }
        }

        if (array_sum($out) > (int) $data['amount']) {
            throw ValidationException::withMessages(['allocations' => __('ভাগগুলোর যোগফল charge-এর চেয়ে বেশি।')]);
        }

        return $out ?: null;
    }

    /** @param  array<string, mixed>  $rules */
    private function run(Request $request, array $rules, callable $post, string $message): string
    {
        $data = $request->validate($rules, [], [
            'amount' => __('পরিমাণ'), 'entry_date' => __('তারিখ'), 'client_id' => 'client', 'description' => __('বিবরণ'),
            'account_id' => 'account', 'expense_head_id' => __('খরচের খাত'), 'usd' => 'USD', 'bdt' => 'BDT',
        ]);

        // Optional fields left empty are not in the validated data.
        $data += ['description' => null, 'notes' => null];

        if (! empty($data['usd_credit'])) {
            $data['usd_credit'] = Money::toMinor($data['usd_credit']);
        }

        foreach (['amount', 'usd', 'bdt'] as $key) {
            if (array_key_exists($key, $data)) {
                $data[$key] = Money::toMinor($data[$key]);

                if ($data[$key] <= 0) {
                    throw ValidationException::withMessages([$key => __('পরিমাণ ০-এর বেশি হতে হবে।')]);
                }
            }
        }

        // Written offline and sent later: booked once, however often it is resent.
        $offlineId = $request->header('X-Offline-Id');
        if ($offlineId !== null && ! Str::isUuid($offlineId)) {
            abort(422);
        }

        return DB::transaction(function () use ($request, $data, $post, $message, $offlineId) {
            if ($offlineId !== null) {
                $taken = ! DB::table('offline_entries')->insertOrIgnore([
                    'id' => $offlineId, 'user_id' => $request->user()->id,
                    'type' => (string) $request->route('type'), 'created_at' => now(),
                ]);
                if ($taken) {
                    return __('এই entry আগেই জমা হয়েছে।');
                }
            }

            $post($data);

            // Paid by bank transfer: the transfer's own fee is a Bank charge from the bank.
            if (! empty($data['bank_fee']) && ($data['wallet'] ?? null) === 'bank' && ($data['direction'] ?? 'out') !== 'in' && ($data['direction'] ?? null) !== 'repay') {
                MoveFees::book(MoveFees::bankFee(), 'bank', $data['entry_date'], 'Bank transfer fee — '.($data['description'] ?: __('টাকা পাঠানো')));
            }

            return $message;
        });
    }
}

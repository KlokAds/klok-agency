<?php

namespace App\Http\Controllers\Books;

use App\Http\Controllers\Controller;
use App\Services\Postings;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function __invoke(Request $request, string $type, Postings $postings): RedirectResponse
    {
        abort_unless(isset(self::PERMISSIONS[$type]), 404);
        abort_unless($request->user()->can(self::PERMISSIONS[$type]), 403);

        $base = [
            'entry_date' => ['required', 'date', 'before_or_equal:'.now()->addDay()->toDateString()],
            'description' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];

        $message = match ($type) {
            'ad-spend' => $this->run($request, $base + [
                'client_id' => ['required', 'exists:clients,id'],
                'account_id' => ['required', 'exists:accounts,id'],
                'amount' => ['required', Money::RULE],
                'status' => ['required', Rule::in(['pending', 'settled'])],
            ], fn ($d) => $postings->adSpend($d), 'Ad খরচ লেখা হয়েছে।'),

            'own-spend' => $this->run($request, [
                'description' => ['required', 'string', 'max:255'],
            ] + $base + [
                'account_id' => ['required', 'exists:accounts,id'],
                'amount' => ['required', Money::RULE],
            ], fn ($d) => $postings->ownSpend($d), 'নিজের খরচ লেখা হয়েছে।'),

            'card-charge' => $this->run($request, $base + [
                'account_id' => ['required', 'exists:accounts,id'],
                'amount' => ['required', Money::RULE],
            ], fn ($d) => $postings->cardCharge($d), 'Card charge লেখা হয়েছে।'),

            'transfer' => $this->run($request, $base + [
                'from_id' => ['required', 'exists:accounts,id'],
                'to_id' => ['required', 'exists:accounts,id'],
                'amount' => ['required', Money::RULE],
            ], fn ($d) => $postings->transfer($d), 'Transfer লেখা হয়েছে।'),

            'client-payment' => $this->run($request, $base + [
                'client_id' => ['required', 'exists:clients,id'],
                'category' => ['required', Rule::in(['client_payment', 'service_income'])],
                'amount' => ['required', Money::RULE],
                'settle_ids' => ['array'],
                'settle_ids.*' => ['integer'],
            ], fn ($d) => $postings->clientPayment($d, $d['settle_ids'] ?? []), 'Client-এর টাকা জমা হয়েছে।'),

            'expense' => $this->run($request, [
                'description' => ['required', 'string', 'max:255'],
            ] + $base + [
                'expense_head_id' => ['required', 'exists:expense_heads,id'],
                'amount' => ['required', Money::RULE],
            ], fn ($d) => $postings->expense($d), 'খরচ লেখা হয়েছে।'),

            'owner' => $this->run($request, $base + [
                'direction' => ['required', Rule::in(['in', 'out'])],
                'amount' => ['required', Money::RULE],
            ], fn ($d) => $postings->owner($d), 'Owner-এর হিসাব লেখা হয়েছে।'),

            'buy-usd' => $this->run($request, $base + [
                'usd' => ['required', Money::RULE],
                'bdt' => ['required', Money::RULE],
            ], fn ($d) => $postings->buyUsd($d), 'USD কেনা লেখা হয়েছে — নতুন FX batch তৈরি।'),
        };

        $this->toast($message);

        return back();
    }

    private function run(Request $request, array $rules, callable $post, string $message): string
    {
        $data = $request->validate($rules, [], [
            'amount' => 'পরিমাণ', 'entry_date' => 'তারিখ', 'client_id' => 'client', 'description' => 'বিবরণ',
            'account_id' => 'account', 'expense_head_id' => 'খরচের খাত', 'usd' => 'USD', 'bdt' => 'BDT',
        ]);

        // Optional fields left empty are not in the validated data.
        $data += ['description' => null, 'notes' => null];

        foreach (['amount', 'usd', 'bdt'] as $key) {
            if (array_key_exists($key, $data)) {
                $data[$key] = Money::toMinor($data[$key]);

                if ($data[$key] <= 0) {
                    throw ValidationException::withMessages([$key => 'পরিমাণ ০-এর বেশি হতে হবে।']);
                }
            }
        }

        $post($data);

        return $message;
    }
}

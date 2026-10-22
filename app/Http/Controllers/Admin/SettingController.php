<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\ExpenseHead;
use App\Models\Setting;
use App\Services\PaymentFees;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SettingController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/Settings', [
            'settings' => [
                'lock_date' => Setting::get('lock_date'),
                'require_2fa' => (bool) Setting::get('require_2fa', false),
                'idle_minutes' => (int) Setting::get('idle_minutes', 30),
                'payment_fee_percent' => (string) PaymentFees::percent(),
            ],
            'heads' => ExpenseHead::orderBy('name')->get(['id', 'name', 'is_active']),
            'accounts' => Account::orderBy('sort')->get(['id', 'code', 'name', 'kind', 'is_active']),
            'kinds' => collect(Account::KINDS)->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'lock_date' => ['nullable', 'date', 'before_or_equal:today'],
            'require_2fa' => ['required', 'boolean'],
            'idle_minutes' => ['required', 'integer', 'min:5', 'max:240'],
            'payment_fee_percent' => ['required', 'numeric', 'min:0', 'max:10'],
        ]);

        if ($data['require_2fa'] && ! $request->user()->two_factor_confirmed_at) {
            return back()->withErrors(['require_2fa' => __('আগে নিজের account-এ 2FA চালু করুন, তারপর সবার জন্য বাধ্যতামূলক করুন।')]);
        }

        Setting::put('lock_date', $data['lock_date']);
        Setting::put('require_2fa', $data['require_2fa'] ? '1' : '0');
        Setting::put('idle_minutes', (string) $data['idle_minutes']);
        // Applies to payments saved from now on; earlier fee rows keep the rate they were made with.
        Setting::put('payment_fee_percent', (string) round((float) $data['payment_fee_percent'], 3));

        $this->toast(__('Settings সংরক্ষণ হয়েছে।'));

        return back();
    }

    public function storeHead(Request $request): RedirectResponse
    {
        ExpenseHead::create($request->validate(['name' => ['required', 'string', 'max:80', 'unique:expense_heads,name']]));
        $this->toast(__('নতুন খরচের খাত যোগ হয়েছে।'));

        return back();
    }

    public function updateHead(Request $request, ExpenseHead $head): RedirectResponse
    {
        $head->update($request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('expense_heads', 'name')->ignore($head->id)],
            'is_active' => ['required', 'boolean'],
        ]));
        $this->toast(__('খরচের খাত আপডেট হয়েছে।'));

        return back();
    }

    public function storeAccount(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'kind' => ['required', Rule::in(['holding', 'platform'])],
        ]);

        Account::create($data + [
            'code' => str($data['name'])->slug('_')->limit(24, '')->append('_'.now()->format('His'))->toString(),
            'sort' => (int) Account::max('sort') + 1,
        ]);
        $this->toast(__('নতুন account যোগ হয়েছে।'));

        return back();
    }

    public function updateAccount(Request $request, Account $account): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'is_active' => ['required', 'boolean'],
        ]);

        // The card is what every balance is measured against; it stays on.
        if ($account->kind === 'card') {
            $data['is_active'] = true;
        }

        $account->update($data);
        $this->toast(__('Account আপডেট হয়েছে।'));

        return back();
    }
}

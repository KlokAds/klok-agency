<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\MoveFees;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
                'mfs_out_percent' => (string) MoveFees::mfsPercent(),
                'bank_transfer_fee' => (string) (MoveFees::bankFee() / 100),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'lock_date' => ['nullable', 'date', 'before_or_equal:today'],
            'require_2fa' => ['required', 'boolean'],
            'idle_minutes' => ['required', 'integer', 'min:5', 'max:240'],
            'mfs_out_percent' => ['required', 'numeric', 'min:0', 'max:10'],
            'bank_transfer_fee' => ['required', 'numeric', 'min:0', 'max:1000'],
        ]);

        if ($data['require_2fa'] && ! $request->user()->two_factor_confirmed_at) {
            return back()->withErrors(['require_2fa' => __('আগে নিজের account-এ 2FA চালু করুন, তারপর সবার জন্য বাধ্যতামূলক করুন।')]);
        }

        Setting::put('lock_date', $data['lock_date']);
        Setting::put('require_2fa', $data['require_2fa'] ? '1' : '0');
        Setting::put('idle_minutes', (string) $data['idle_minutes']);
        // What moving money costs: cashing out of MFS, and a bank transfer.
        Setting::put('mfs_out_percent', (string) round((float) $data['mfs_out_percent'], 3));
        Setting::put('bank_transfer_fee', (string) round((float) $data['bank_transfer_fee'], 2));

        $this->toast(__('Settings সংরক্ষণ হয়েছে।'));

        return back();
    }
}

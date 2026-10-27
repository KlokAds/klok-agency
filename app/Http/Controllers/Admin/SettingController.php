<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\MetaReceipts;
use App\Services\MoveFees;
use App\Support\MailSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
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
                'business_whatsapp' => (string) Setting::get('business_whatsapp', ''),
                'meta_cards' => implode(', ', MetaReceipts::ourCards()),
            ],
            'mail' => MailSettings::form(),
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
            'meta_cards' => ['nullable', 'string', 'max:60', 'regex:/^\s*\d{4}(\s*,\s*\d{4})*\s*$/'],
            'business_whatsapp' => ['nullable', 'string', 'regex:/^(\+?880|0)1[3-9]\d{8}$/'],
        ], ['business_whatsapp.regex' => __('১১ সংখ্যার নম্বর দিন, যেমন 01712345678।')]);

        if ($data['require_2fa'] && ! $request->user()->two_factor_confirmed_at) {
            return back()->withErrors(['require_2fa' => __('আগে নিজের account-এ 2FA চালু করুন, তারপর সবার জন্য বাধ্যতামূলক করুন।')]);
        }

        Setting::put('lock_date', $data['lock_date']);
        Setting::put('require_2fa', $data['require_2fa'] ? '1' : '0');
        Setting::put('idle_minutes', (string) $data['idle_minutes']);
        // What moving money costs: cashing out of MFS, and a bank transfer.
        Setting::put('mfs_out_percent', (string) round((float) $data['mfs_out_percent'], 3));
        Setting::put('bank_transfer_fee', (string) round((float) $data['bank_transfer_fee'], 2));
        // Where clients reach the agency on WhatsApp, from the portal.
        Setting::put('business_whatsapp', $data['business_whatsapp'] ?? null);
        // The cards Meta charges that are ours: their charges come off the card balance.
        Setting::put('meta_cards', filled($data['meta_cards'] ?? null) ? preg_replace('/\s+/', '', $data['meta_cards']) : null);

        $this->toast(__('Settings সংরক্ষণ হয়েছে।'));

        return back();
    }

    /** The mailbox the app sends from, and who hears about renewals. */
    public function mail(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'host' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9.-]+$/i'],
            'port' => ['required', 'integer', 'in:465,587'],
            'username' => ['required', 'email', 'max:190'],
            'password' => ['nullable', 'string', 'max:190'],
            'from_name' => ['required', 'string', 'max:80'],
            'notify_email' => ['required', 'email', 'max:190'],
            'receipts_host' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9.-]+$/i'],
            'receipts_username' => ['nullable', 'email', 'max:190'],
            'receipts_password' => ['nullable', 'string', 'max:190'],
        ]);

        // A saved password goes to whatever server is set here (the test mail, the receipts
        // check), so a new host or port drops it unless a new one is typed with it: whoever
        // changes the server cannot have the old password sent to theirs.
        $before = MailSettings::form();
        $receiptsHost = ($data['receipts_host'] ?? null) ?: 'imap.hostinger.com';
        $smtpMoved = $before['has_password'] && blank($data['password'] ?? null)
            && (strcasecmp($data['host'], $before['host']) !== 0 || (int) $data['port'] !== $before['port']);
        $receiptsMoved = $before['receipts_has_password'] && blank($data['receipts_password'] ?? null)
            && strcasecmp($receiptsHost, $before['receipts_host']) !== 0;

        Setting::put('smtp_host', $data['host']);
        Setting::put('smtp_port', (string) $data['port']);
        Setting::put('smtp_username', $data['username']);
        Setting::put('smtp_from_name', $data['from_name']);
        Setting::put('notify_email', $data['notify_email']);
        // Where Meta's receipts are forwarded: read every few minutes.
        Setting::put('receipts_host', $receiptsHost);
        Setting::put('receipts_username', $data['receipts_username'] ?? '');
        if (filled($data['receipts_password'] ?? null)) {
            Setting::put('receipts_password', Crypt::encryptString($data['receipts_password']));
        } elseif ($receiptsMoved) {
            Setting::put('receipts_password', null);
        }

        // A blank password keeps the saved one, on the same server.
        if (filled($data['password'] ?? null)) {
            Setting::put('smtp_password', Crypt::encryptString($data['password']));
        } elseif ($smtpMoved) {
            Setting::put('smtp_password', null);
        }

        $this->toast($smtpMoved || $receiptsMoved
            ? __('সংরক্ষণ হয়েছে। Server বদলেছে, তাই আগের password মুছে দেওয়া হয়েছে — নতুন server-এর password আবার দিন।')
            : __('Email-এর settings সংরক্ষণ হয়েছে।'));

        return back();
    }

    /** One short email to the notify address, to see the mailbox works. */
    public function testMail(): RedirectResponse
    {
        MailSettings::apply();
        $to = MailSettings::form()['notify_email'];

        try {
            Mail::raw(__('KlokOne থেকে পরীক্ষার email — এটা পেলে email ঠিক আছে।'), fn ($m) => $m->to($to)->subject('KlokOne test'));
        } catch (\Throwable $e) {
            return back()->withErrors(['mail' => __('Email যায়নি: :error', ['error' => mb_substr($e->getMessage(), 0, 200)])]);
        }

        $this->toast(__(':to-এ পরীক্ষার email পাঠানো হয়েছে।', ['to' => $to]));

        return back();
    }
}

<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\User;
use App\Notifications\AccountChanged;
use App\Notifications\AgencyAlert;
use App\Notifications\EmailChangeLink;
use App\Services\Notifier;
use App\Support\Access;
use App\Support\MailSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public const CLIENT_CHANGED_TITLE = 'Client নিজের তথ্য বদলেছেন'; // i18n

    public function edit(Request $request): Response
    {
        $user = $request->user();
        $owner = $user->hasRole(Access::OWNER);

        return Inertia::render('settings/Profile', [
            'status' => $request->session()->get('status'),
            'details' => $user->only(['email', 'phone', 'company']),
            // An email change waiting on its link: the new address, so the page can say so.
            'pending_email' => Cache::get(self::pendingKey($user->id)),
            // Each group: whether it reaches this person at all (their role's permission, or an
            // Owner's choice), and how — phone, email — as they set it.
            'notify' => [
                'owner' => $owner,
                'mail' => MailSettings::ready(),
                'groups' => collect(Notifier::GROUPS)->map(function (array $g, string $key) use ($user, $owner) {
                    $prefs = Notifier::prefs($user, $key);
                    $reaches = $owner || $g['permission'] === null || $user->can($g['permission']);

                    return ['key' => $key, 'label' => $g['label'], 'reaches' => $reaches, 'mandatory' => $key === 'tasks' && ! $owner] + $prefs;
                })->values(),
            ],
        ]);
    }

    /** How long a new address has to open its link. */
    public const EMAIL_LINK_MINUTES = 60;

    public static function pendingKey(int $userId): string
    {
        return 'email-change:'.$userId;
    }

    /**
     * A team member asks for a new sign-in email: a link goes to that address, and nothing
     * changes until it is opened. The old address is told at once, so a change the person
     * did not ask for never goes unnoticed.
     */
    public function requestEmail(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
        ]);
        $email = Str::lower($data['email']);

        if ($email === Str::lower((string) $user->email)) {
            return back()->withErrors(['email' => __('এটাই এখনকার email।')]);
        }

        if (($error = $this->sendEmailLink($user, $email)) !== null) {
            return back()->withErrors(['email' => $error]);
        }

        $this->toast(__(':email-এ link পাঠানো হয়েছে — ওটা খুলে confirm করুন।', ['email' => $email]));

        return to_route('profile.edit');
    }

    /**
     * The link to a new address, and a word to the person that it went. Opening it asks for the
     * password again (see routes/settings.php). Gives back the error to show when no mail can go.
     */
    private function sendEmailLink(User $user, string $email): ?string
    {
        if (! MailSettings::ready()) {
            return __('Email পাঠানোর mailbox দেওয়া নেই — Owner-কে বলুন, Users পাতা থেকে বদলে দেবেন।');
        }

        Cache::put(self::pendingKey($user->id), $email, now()->addMinutes(self::EMAIL_LINK_MINUTES));

        $url = URL::temporarySignedRoute('profile.email.verify', now()->addMinutes(self::EMAIL_LINK_MINUTES), [
            'hash' => sha1($email),
        ]);
        rescue(fn () => Notification::route('mail', $email)->notify(new EmailChangeLink($url, $user->name, (string) ($user->locale ?? 'bn'))));
        rescue(fn () => $user->notify(new AgencyAlert('account_changed', self::EMAIL_ASKED_TITLE, '{email}-এ একটা link গেছে। আপনি না চেয়ে থাকলে সাথে সাথে Klok Ads-কে জানান।', [ // i18n
            'email' => $email,
        ], ['url' => '/settings/profile', 'important' => true])));

        return null;
    }

    public const EMAIL_ASKED_TITLE = 'নতুন email-এর জন্য link পাঠানো হয়েছে'; // i18n

    /** The link from the new address, opened while signed in: the email changes now. */
    public function verifyEmail(Request $request, string $hash): RedirectResponse
    {
        $user = $request->user();
        $email = Cache::get(self::pendingKey($user->id));

        if (! is_string($email) || sha1($email) !== $hash) {
            $this->toast(__('Link-টা পুরোনো বা অন্য কারও — আবার email বদলের link চান।'), 'error');

            return to_route('profile.edit');
        }

        if (User::where('email', $email)->where('id', '!=', $user->id)->exists()) {
            Cache::forget(self::pendingKey($user->id));
            $this->toast(__('এই email এর মধ্যে অন্য কেউ নিয়ে নিয়েছে।'), 'error');

            return to_route('profile.edit');
        }

        $old = (string) $user->email;
        $user->forceFill(['email' => $email, 'email_verified_at' => now()])->save();
        Cache::forget(self::pendingKey($user->id));

        rescue(fn () => $user->notify(AccountChanged::alert($user, [__('Email')])));
        if (filled($old) && MailSettings::ready()) {
            rescue(fn () => Notification::route('mail', $old)->notify(new AccountChanged([__('Email')], (string) ($user->locale ?? 'bn'))));
        }

        // A client's new address: the agency hears, as with their other details.
        if ($user->isClient()) {
            app(Notifier::class)->send(null, new AgencyAlert('portal_profile', self::CLIENT_CHANGED_TITLE, '{name} · {client} · {what}', [
                'name' => $user->name, 'client' => $user->client->name ?? '—', 'what' => 'email',
            ], ['url' => $user->client_id ? '/clients/'.$user->client_id : '/clients']));
        }

        $this->toast(__('Email বদলে গেছে — এখন থেকে :email দিয়ে sign in করবেন।', ['email' => $email]));

        return to_route('profile.edit');
    }

    /** Which notification groups come, and how (phone, email). Your own tasks always come. */
    public function notifications(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'groups' => ['required', 'array'],
            'groups.*.key' => ['required', Rule::in(array_keys(Notifier::GROUPS))],
            'groups.*.on' => ['boolean'],
            'groups.*.push' => ['boolean'],
            'groups.*.email' => ['boolean'],
        ]);

        $prefs = [];
        foreach ($data['groups'] as $g) {
            $prefs[$g['key']] = ['on' => (bool) ($g['on'] ?? false), 'push' => (bool) ($g['push'] ?? false), 'email' => (bool) ($g['email'] ?? false)];
        }
        $request->user()->forceFill(['notify_prefs' => $prefs])->save();
        $this->toast(__('Notification-এর পছন্দ সংরক্ষণ হয়েছে।'));

        return back();
    }

    /** What each field is called in the notice. */
    public const FIELD_WORDS = [
        'name' => 'নাম', // i18n
        'email' => 'Email',
        'phone' => 'Phone নম্বর', // i18n
        'company' => 'প্রতিষ্ঠানের নাম', // i18n
    ];

    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validated();

        // A client's new email is their sign-in name, so it is never saved from here: it goes
        // through the same link as the team's, and opening it asks for the password. A session
        // left open on a desk or phone cannot move the account to someone else's inbox.
        $newEmail = isset($data['email']) ? Str::lower($data['email']) : null;
        unset($data['email']);
        $askEmail = $newEmail !== null && $newEmail !== Str::lower((string) $user->email);

        if ($askEmail && ($error = $this->sendEmailLink($user, $newEmail)) !== null) {
            return back()->withErrors(['email' => $error]);
        }

        $user->fill($data);
        $changed = array_keys($user->getDirty());
        $user->save();

        // The person hears of it themselves — bell, phone and email — so a change they did not
        // make never goes unnoticed.
        if ($changed !== []) {
            $words = array_map(fn (string $field) => self::FIELD_WORDS[$field] ?? $field, $changed);
            // A mail server hiccup must not undo the change or show an error.
            rescue(fn () => $user->notify(AccountChanged::alert($user, array_map(fn ($w) => __($w), $words))));
        }

        // A client changed their own details: the agency hears, so it knows who it is talking to.
        if ($user->isClient() && $changed !== []) {
            app(Notifier::class)->send(null, new AgencyAlert('portal_profile', self::CLIENT_CHANGED_TITLE, '{name} · {client} · {what}', [
                'name' => $user->name, 'client' => $user->client->name ?? '—', 'what' => implode(', ', $changed),
            ], ['url' => $user->client_id ? '/clients/'.$user->client_id : '/clients']));
        }

        $this->toast($askEmail
            ? __(':email-এ link পাঠানো হয়েছে — ওটা খুলে confirm করুন।', ['email' => $newEmail])
            : __('Profile আপডেট হয়েছে।'));

        return to_route('profile.edit');
    }
}

<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Notifications\AccountChanged;
use App\Notifications\AgencyAlert;
use App\Services\Notifier;
use App\Support\Access;
use App\Support\MailSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
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
        $user->fill($request->validated());
        $changed = array_keys($user->getDirty());
        $oldEmail = $user->getOriginal('email');
        $user->save();

        // The person hears of it themselves — bell, phone and email — and the old address too when
        // the email changed, so a change they did not make never goes unnoticed.
        if ($changed !== []) {
            $words = array_map(fn (string $field) => self::FIELD_WORDS[$field] ?? $field, $changed);
            // A mail server hiccup must not undo the change or show an error.
            rescue(fn () => $user->notify(AccountChanged::alert($user, array_map(fn ($w) => __($w), $words))));

            if (in_array('email', $changed, true) && filled($oldEmail) && MailSettings::ready()) {
                rescue(fn () => Notification::route('mail', $oldEmail)->notify(new AccountChanged([__('Email')], (string) ($user->locale ?? 'bn'))));
            }
        }

        // A client changed their own details: the agency hears, so it knows who it is talking to.
        if ($user->isClient() && $changed !== []) {
            app(Notifier::class)->send(null, new AgencyAlert('portal_profile', self::CLIENT_CHANGED_TITLE, '{name} · {client} · {what}', [
                'name' => $user->name, 'client' => $user->client->name ?? '—', 'what' => implode(', ', $changed),
            ], ['url' => $user->client_id ? '/clients/'.$user->client_id : '/clients']));
        }

        $this->toast(__('Profile আপডেট হয়েছে।'));

        return to_route('profile.edit');
    }
}

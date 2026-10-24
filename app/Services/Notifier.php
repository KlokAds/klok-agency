<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\AgencyAlert;
use App\Support\Access;
use Illuminate\Support\Collection;

/**
 * Who hears about what, and how.
 *
 * The work goes to the people who do it: everyone whose role has the
 * permission for that group (payments to whoever keeps the BDT book, ads to
 * whoever runs them…), plus the person it belongs to. An Owner hears about a
 * group only when they switched it on, when nobody else would (so nothing is
 * lost), or when it is marked important (a large expense, a deletion). Nobody
 * is told about their own work.
 *
 * Each person chooses per group in their profile: on or off, and whether it
 * also comes to the phone and by email. The bell always has it.
 */
class Notifier
{
    /** Each group, who acts on it, and how it comes by default. */
    public const GROUPS = [
        'money' => ['label' => 'টাকা: payment এসেছে, invoice বাকি, না-মেলা SMS', 'permission' => 'bdt.create', 'push' => true], // i18n
        'ads' => ['label' => 'Ad: budget বা তারিখ বদল, extend, credit শেষ, campaign শেষ, Pilot', 'permission' => 'runs.edit', 'push' => true], // i18n
        'dollars' => ['label' => 'Dollar: card-এ কম, Meta receipt', 'permission' => 'usd.create', 'push' => true], // i18n
        'activity' => ['label' => 'কে কী করল: invoice, হাতে payment, খরচ, মোছা', 'permission' => 'activity.view', 'push' => false], // i18n
        'summary' => ['label' => 'দিনের শেষের সারসংক্ষেপ', 'permission' => 'reports.view', 'push' => true], // i18n
        'tasks' => ['label' => 'কাজ ও reminder', 'permission' => null, 'push' => true], // i18n
    ];

    /** A campaign stopping, about to stop or its day's spend: every Owner hears of it, whatever they switched off. */
    public const OWNERS_ALWAYS = ['renewal_open', 'renewal_invoiced', 'renewal_extended', 'credit_out', 'credit_low', 'ended', 'pilot_down', 'campaign_ending', 'campaign_spend'];

    /**
     * How one person gets one group: on at all, on the phone, by email.
     *
     * @return array{on: bool, push: bool, email: bool}
     */
    public static function prefs(User $user, string $group): array
    {
        $all = is_array($user->notify_prefs) ? $user->notify_prefs : [];
        $saved = is_array($all[$group] ?? null) ? $all[$group] : [];
        $owner = $user->hasRole(Access::OWNER);

        // An Owner follows what the others do by default; the rest of the work is the doers'.
        return [
            'on' => (bool) ($saved['on'] ?? (! $owner || $group === 'activity')),
            'push' => (bool) ($saved['push'] ?? (self::GROUPS[$group]['push'] ?? true)),
            'email' => (bool) ($saved['email'] ?? false),
        ];
    }

    /**
     * @return Collection<int, User>
     */
    public function audience(?int $responsibleId, ?AgencyAlert $alert = null, ?int $actorId = null): Collection
    {
        $group = $alert?->group() ?? 'tasks';
        $permission = self::GROUPS[$group]['permission'] ?? null;
        $important = (bool) ($alert?->extra['important'] ?? false) || in_array($alert?->kind, self::OWNERS_ALWAYS, true);
        $active = User::query()->where('is_active', true)->with('roles')->get();
        $isOwner = fn (User $u) => $u->hasRole(Access::OWNER);

        // The people who do this work (Owners have every permission, so they are not counted here).
        $doers = $active->reject($isOwner)->filter(fn (User $u) => $permission !== null && $u->can($permission));
        $person = $responsibleId ? $active->firstWhere('id', $responsibleId) : null;

        $people = $doers->filter(fn (User $u) => self::prefs($u, $group)['on'])
            ->push($person)->filter()->unique('id');
        $nobody = $doers->push($person)->filter()->reject(fn (User $u) => $u->id === $actorId)->isEmpty();

        $owners = $active->filter($isOwner)->filter(
            fn (User $u) => $nobody || $important || $u->id === $person?->id || self::prefs($u, $group)['on'],
        );

        return $people->merge($owners)->unique('id')
            ->reject(fn (User $u) => $actorId !== null && $u->id === $actorId)
            ->values();
    }

    /** @param  int|null  $actorId  who did it (never told about their own work) */
    public function send(?int $responsibleId, AgencyAlert $alert, ?int $actorId = null): int
    {
        $people = $this->audience($responsibleId, $alert, $actorId);

        foreach ($people as $user) {
            $user->notify($alert);
        }

        return $people->count();
    }
}

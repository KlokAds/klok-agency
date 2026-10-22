<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\AgencyAlert;
use App\Support\Access;
use Illuminate\Support\Collection;

/**
 * Who hears about what: the person the work belongs to, plus every Owner.
 */
class Notifier
{
    /** @return Collection<int, User> */
    public function audience(?int $responsibleId): Collection
    {
        $owners = User::role(Access::OWNER)->where('is_active', true)->get();
        $person = $responsibleId ? User::where('is_active', true)->find($responsibleId) : null;

        return $owners->push($person)->filter()->unique('id')->values();
    }

    public function send(?int $responsibleId, AgencyAlert $alert): int
    {
        $people = $this->audience($responsibleId);

        foreach ($people as $user) {
            $user->notify($alert);
        }

        return $people->count();
    }
}

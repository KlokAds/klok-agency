<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Paging
{
    /**
     * The page a row lands on, so a link to one row (a reminder, say) opens
     * the page that shows it. Null when the row is not in the list.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    public static function pageOf(Builder $query, ?int $id, int $perPage): ?int
    {
        if (! $id) {
            return null;
        }

        $at = (clone $query)->pluck($query->getModel()->getQualifiedKeyName())->search($id);

        return $at === false ? null : intdiv((int) $at, $perPage) + 1;
    }
}

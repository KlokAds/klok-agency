<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class Paging
{
    public const SIZES = [10, 25, 50, 100];

    /** Rows per page the reader picked (?per_page=), 10 unless one of SIZES is asked for. */
    public static function size(Request $request): int
    {
        $size = $request->integer('per_page');

        return in_array($size, self::SIZES, true) ? $size : self::SIZES[0];
    }

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

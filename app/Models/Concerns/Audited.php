<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Every change to a financial record is written to the activity log, and
 * records that carry created_by / updated_by get the acting user stamped.
 */
trait Audited
{
    use LogsActivity;

    public static function bootAudited(): void
    {
        static::creating(function ($model) {
            if (Auth::id() && in_array('created_by', $model->getFillable(), true) && ! $model->created_by) {
                $model->created_by = Auth::id();
            }
        });

        static::saving(function ($model) {
            if (Auth::id() && in_array('updated_by', $model->getFillable(), true)) {
                $model->updated_by = Auth::id();
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logExcept(['created_at', 'updated_at', 'updated_by'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName(class_basename(static::class));
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use Audited, SoftDeletes;

    public const STATUSES = ['active' => 'Active', 'left' => 'Left'];

    protected $fillable = ['name', 'designation', 'phone', 'monthly_salary', 'joined_on', 'left_on', 'status', 'notes'];

    protected function casts(): array
    {
        return ['joined_on' => 'date', 'left_on' => 'date', 'monthly_salary' => 'integer'];
    }

    public function payments(): HasMany
    {
        return $this->hasMany(BdtEntry::class)->where('category', 'salary');
    }
}

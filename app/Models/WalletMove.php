<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * BDT moved from one of our places to another (bKash → bank, bank → cash).
 * It changes the two places' balances, never the BDT total.
 *
 * @property CarbonImmutable $moved_on
 * @property string $from_wallet
 * @property string $to_wallet
 * @property int $amount what arrived
 * @property int $fee what moving it cost (booked as a Bank charge)
 * @property int|null $fee_entry_id
 */
class WalletMove extends Model
{
    use Audited, SoftDeletes;

    protected $fillable = ['moved_on', 'from_wallet', 'to_wallet', 'amount', 'fee', 'fee_entry_id', 'note', 'created_by'];

    protected function casts(): array
    {
        return ['moved_on' => 'date', 'amount' => 'integer', 'fee' => 'integer', 'fee_entry_id' => 'integer'];
    }
}

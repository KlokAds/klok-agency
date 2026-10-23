<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of an invoice. A campaign line bills one campaign's USD spend
 * over a period (with that period's results), an advance line USD for ads
 * still to run; both are USD (cents) × the client's rate (BDT per USD).
 * Service and other lines are a plain BDT amount.
 *
 * @property int $id
 * @property int $invoice_id
 * @property int $position
 * @property string $kind ad_spend|ad_budget|service|other
 * @property int|null $campaign_id
 * @property CarbonImmutable|null $period_from
 * @property CarbonImmutable|null $period_to
 * @property int|null $days more days of the campaign an advance line pays for
 * @property string $description
 * @property int|null $usd
 * @property string|null $rate
 * @property int|null $results
 * @property int|null $clicks
 * @property int $amount poisha
 */
class InvoiceItem extends Model
{
    public const KINDS = [
        'ad_spend' => 'Campaign খরচ', // i18n
        'ad_budget' => 'Advance ad budget',
        'service' => 'Service',
        'other' => 'অন্যান্য', // i18n
    ];

    /** Lines priced in USD at the client's rate; their USD becomes the client's ad credit. */
    public const USD_KINDS = ['ad_spend', 'ad_budget'];

    protected $fillable = [
        'invoice_id', 'position', 'kind', 'campaign_id', 'period_from', 'period_to', 'days', 'description', 'usd', 'rate', 'results', 'clicks', 'amount',
    ];

    protected function casts(): array
    {
        return [
            'usd' => 'integer', 'amount' => 'integer', 'position' => 'integer', 'results' => 'integer', 'clicks' => 'integer', 'days' => 'integer',
            'period_from' => 'date', 'period_to' => 'date',
        ];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** Poisha for $usd cents at $rate taka per dollar. */
    public static function bdtFor(int $usd, float $rate): int
    {
        return (int) round($usd * $rate);
    }
}

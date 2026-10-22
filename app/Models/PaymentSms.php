<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An incoming-money SMS forwarded by the phone that holds the agency's
 * bKash / Nagad / Rocket SIM (or a bank's credit alert).
 *
 * @property int $id
 * @property string $provider bkash|nagad|rocket|bank|unknown
 * @property string $sender
 * @property string $body
 * @property int|null $amount poisha
 * @property string|null $trx_id
 * @property string|null $payer_number
 * @property string $status unmatched|matched|ignored
 * @property int|null $invoice_payment_id
 * @property CarbonImmutable $received_at
 */
class PaymentSms extends Model
{
    protected $table = 'payment_sms';

    protected $fillable = ['provider', 'sender', 'body', 'amount', 'trx_id', 'payer_number', 'status', 'invoice_payment_id', 'received_at'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'received_at' => 'datetime'];
    }

    /** @return BelongsTo<InvoicePayment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(InvoicePayment::class, 'invoice_payment_id');
    }
}

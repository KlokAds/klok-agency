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
 * @property string|null $service_centre the SMSC that delivered it, as the phone saw it
 * @property int|null $balance poisha: the wallet's balance the SMS shows
 * @property string $status unmatched|matched|ignored
 * @property bool|null $held did not look genuine: never settles by itself, only by the Owner
 * @property int|null $invoice_payment_id
 * @property CarbonImmutable $received_at
 */
class PaymentSms extends Model
{
    protected $table = 'payment_sms';

    protected $fillable = ['provider', 'sender', 'body', 'amount', 'trx_id', 'payer_number', 'service_centre', 'balance', 'status', 'held', 'invoice_payment_id', 'received_at'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'balance' => 'integer', 'held' => 'boolean', 'received_at' => 'datetime'];
    }

    /** @return BelongsTo<InvoicePayment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(InvoicePayment::class, 'invoice_payment_id');
    }
}

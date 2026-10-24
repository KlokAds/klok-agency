<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentSms;
use App\Models\Setting;
use App\Support\Access;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings › Payment SMS: every money SMS the phone sent that the server kept — which
 * invoice it paid, which it refunded, which no invoice took (gone after a day) — and
 * when the phone was last heard from. The phone's own list only has what it sent since
 * app 1.0.6; this one has all of it. Owner only: it is the wallet's own SMS.
 */
class PaymentSmsController extends Controller
{
    public const STATUSES = ['matched', 'unmatched', 'refund'];

    public function index(Request $request): Response
    {
        abort_unless($request->user()->hasRole(Access::OWNER), 403);

        $status = in_array($request->query('status'), self::STATUSES, true) ? (string) $request->query('status') : null;

        $sms = PaymentSms::with('payment.invoice:id,uid,number,client_id', 'payment.invoice.client:id,name')
            ->when($status, fn ($q) => $q->where('status', $status))
            ->latest('received_at')
            ->limit(200)
            ->get()
            ->map(fn (PaymentSms $s) => [
                'id' => $s->id,
                'provider' => $s->provider,
                'sender' => $s->sender,
                'body' => $s->body,
                'amount' => $s->amount,
                'payer' => $s->payer_number,
                'trx_id' => $s->trx_id,
                'status' => $s->status,
                'at' => $s->received_at->toIso8601String(),
                'invoice' => $s->payment?->invoice ? [
                    'uid' => $s->payment->invoice->uid,
                    'number' => $s->payment->invoice->number,
                    'client' => $s->payment->invoice->client?->name,
                ] : null,
            ]);

        return Inertia::render('admin/Sms', [
            'sms' => $sms,
            'status' => $status,
            'counts' => PaymentSms::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'last_seen' => Setting::get('sms_last_seen'),
        ]);
    }
}

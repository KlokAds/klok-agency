<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CardEvent;
use App\Models\PaymentSms;
use App\Models\Setting;
use App\Support\Access;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings › Payment SMS: everything the phone sent that the server kept — the money SMS (which
 * invoice each paid, which it refunded, which no invoice took) and the card and bank apps'
 * notifications (what went into the books, what waits, what changed nothing) — and when the
 * phone was last heard from. The Owner clears what they have seen; a cleared one only leaves
 * the list. Owner only: it is the wallet's own money.
 */
class PaymentSmsController extends Controller
{
    public const STATUSES = ['matched', 'unmatched', 'refund'];

    public const APP_STATUSES = ['booked', 'held', 'duplicate', 'ignored'];

    public function index(Request $request): Response
    {
        $this->owner($request);

        $kind = $request->query('kind') === 'app' ? 'app' : 'sms';
        $list = $kind === 'app' ? self::APP_STATUSES : self::STATUSES;
        $status = in_array($request->query('status'), $list, true) ? (string) $request->query('status') : null;

        $sms = $kind === 'sms' ? PaymentSms::with('payment.invoice:id,uid,number,client_id', 'payment.invoice.client:id,name')
            ->whereNull('cleared_at')
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
            ]) : [];

        $notes = $kind === 'app' ? CardEvent::with('card:id,name')
            ->whereNull('cleared_at')
            ->when($status, fn ($q) => $q->where('status', $status))
            ->latest('posted_at')
            ->limit(200)
            ->get()
            ->map(fn (CardEvent $e) => [
                'id' => $e->id,
                'app' => $e->app_name ?? $e->package,
                'body' => $e->body,
                'kind' => $e->kind,
                'amount' => $e->amount,
                'merchant' => $e->merchant,
                'card' => $e->card?->name,
                'status' => $e->status,
                'problem' => $e->problem,
                'at' => $e->posted_at?->toIso8601String(),
            ]) : [];

        return Inertia::render('admin/Sms', [
            'kind' => $kind,
            'sms' => $sms,
            'notes' => $notes,
            'status' => $status,
            'counts' => [
                'sms' => PaymentSms::whereNull('cleared_at')->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
                'app' => CardEvent::whereNull('cleared_at')->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            ],
            'last_seen' => Setting::get('sms_last_seen'),
        ]);
    }

    /** One line off the list. */
    public function clear(Request $request, string $kind, int $id): RedirectResponse
    {
        $this->owner($request);
        $this->query($kind)->whereKey($id)->update(['cleared_at' => now()]);

        return back();
    }

    /** Every line the list shows now (that kind, that status) off the list. */
    public function clearAll(Request $request): RedirectResponse
    {
        $this->owner($request);
        $data = $request->validate([
            'kind' => ['required', Rule::in(['sms', 'app'])],
            'status' => ['nullable', 'string', 'max:20'],
        ]);

        $n = $this->query($data['kind'])->whereNull('cleared_at')
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->update(['cleared_at' => now()]);
        $this->toast(__(':nটা list থেকে সরানো হয়েছে।', ['n' => $n]));

        return back();
    }

    /** @return Builder<PaymentSms>|Builder<CardEvent> */
    private function query(string $kind): Builder
    {
        return match ($kind) {
            'sms' => PaymentSms::query(),
            'app' => CardEvent::query(),
            default => abort(404),
        };
    }

    private function owner(Request $request): void
    {
        abort_unless($request->user()?->hasRole(Access::OWNER), 403);
    }
}

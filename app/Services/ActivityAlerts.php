<?php

namespace App\Services;

use App\Models\BdtEntry;
use App\Models\FxBatch;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\UsdEntry;
use App\Models\User;
use App\Models\WalletMove;
use App\Notifications\AgencyAlert;
use App\Support\Money;
use Spatie\Activitylog\Models\Activity;
use Throwable;

/**
 * "Who did what", from the activity log: when a person (not the system)
 * writes an invoice, sends or cancels one, enters money by hand, adds an
 * expense, buys dollars, moves money or deletes an entry, the people who
 * follow that (the "activity" group) are told quietly — the bell, and the
 * phone or email only if they chose so. Deleting, cancelling and large
 * expenses are marked important, so an Owner hears of them too.
 *
 * Any new kind of record that is audited comes in here by itself; only the
 * wording below needs a line for it.
 */
class ActivityAlerts
{
    /** One notification per request: a payment with its fee or USD credit is one action. */
    private static ?int $sentFor = null;

    /** Expenses at least this big (taka) are marked important; Settings › Phone app can change it. */
    public const BIG_DEFAULT = 5000;

    public function handle(Activity $activity): void
    {
        $request = spl_object_id(request());

        if (self::$sentFor === $request || $activity->causer_type !== (new User)->getMorphClass() || ! $activity->causer_id) {
            return;
        }

        try {
            $alert = $this->describe($activity);
        } catch (Throwable $e) {
            report($e);

            return;
        }

        if ($alert === null) {
            return;
        }

        self::$sentFor = $request;
        app(Notifier::class)->send(null, $alert, (int) $activity->causer_id);
    }

    /** A new action in the same request may notify again (tests). */
    public static function reset(): void
    {
        self::$sentFor = null;
    }

    private function describe(Activity $activity): ?AgencyAlert
    {
        $who = (string) (User::query()->whereKey((int) $activity->causer_id)->value('name') ?? '—');
        $event = (string) $activity->event;
        $subject = $activity->subject;
        // What changed (this version of the log keeps it in attribute_changes).
        $changes = $activity->attribute_changes ?? $activity->properties;
        $old = (array) ($changes['old'] ?? []);
        $new = (array) ($changes['attributes'] ?? []);

        if ($subject instanceof Invoice || $activity->subject_type === (new Invoice)->getMorphClass()) {
            $invoice = $subject instanceof Invoice ? $subject : null;
            $number = (string) ($new['number'] ?? $old['number'] ?? $invoice->number ?? '');
            $client = (string) ($invoice?->client->name ?? '');
            $total = Money::bdt((int) ($new['total'] ?? $invoice->total ?? 0));
            $url = $invoice !== null ? '/invoices/'.$invoice->uid : '/invoices';

            return match (true) {
                $event === 'created' => $this->alert('did_invoice', '{who} নতুন invoice বানিয়েছেন', '{number} · {client} · {amount}', compact('who', 'number', 'client') + ['amount' => $total], $url), // i18n
                $event === 'updated' && ($new['status'] ?? null) === 'sent' && ($old['status'] ?? null) === 'draft' => $this->alert('did_invoice_sent', '{who} invoice পাঠিয়েছেন', '{number} · {client} · {amount}', compact('who', 'number', 'client') + ['amount' => $total], $url), // i18n
                $event === 'updated' && ($new['status'] ?? null) === 'void' => $this->alert('did_invoice_void', '{who} invoice বাতিল করেছেন', '{number} · {client} · {amount}', compact('who', 'number', 'client') + ['amount' => $total], $url, important: true), // i18n
                // Its lines, dates or client changed (not a payment or its sending): an edit.
                $event === 'updated' && array_diff(array_keys($new), ['paid', 'status', 'paid_at', 'sent_at', 'viewed_at', 'updated_at']) !== [] => $this->alert('did_invoice_edit', '{who} invoice বদলেছেন', '{number} · {client}', compact('who', 'number', 'client'), $url), // i18n
                default => null,
            };
        }

        if ($activity->subject_type === (new BdtEntry)->getMorphClass()) {
            $row = $event === 'deleted' ? $old : $new + $old;
            if (! empty($row['fee_for_id'])) {
                return null; // a fee rides along with its own entry
            }
            $amount = (int) ($row['amount'] ?? 0);
            $what = trim((string) ($row['description'] ?? '')) ?: BdtEntry::categoryLabel((string) ($row['category'] ?? ''));
            $big = $amount <= -100 * (int) (Setting::get('notify_big_bdt') ?: self::BIG_DEFAULT);
            $params = ['who' => $who, 'what' => $what, 'amount' => Money::bdt($amount)];

            return match ($event) {
                'created' => $amount >= 0
                    ? $this->alert('did_money_in', '{who} টাকা জমা লিখেছেন', '{what} · {amount}', $params, '/bdt') // i18n
                    : $this->alert('did_money_out', $big ? '{who} বড় খরচ লিখেছেন' : '{who} খরচ লিখেছেন', '{what} · {amount}', $params, '/expenses', important: $big), // i18n
                'deleted' => $this->alert('did_delete', '{who} BDT খাতার একটা entry মুছেছেন', '{what} · {amount}', $params, '/trash', important: true), // i18n
                'updated' => array_key_exists('amount', $new) || array_key_exists('wallet', $new)
                    ? $this->alert('did_edit', '{who} BDT খাতার একটা entry বদলেছেন', '{what} · {amount}', $params, '/bdt') // i18n
                    : null,
                default => null,
            };
        }

        if ($activity->subject_type === (new UsdEntry)->getMorphClass()) {
            $row = $event === 'deleted' ? $old : $new + $old;
            $what = trim((string) ($row['description'] ?? '')) ?: 'USD';
            $params = ['who' => $who, 'what' => $what, 'amount' => '$'.number_format(((int) ($row['amount'] ?? 0)) / 100, 2)];

            return match ($event) {
                'created' => $this->alert('did_usd', '{who} USD খাতায় লিখেছেন', '{what} · {amount}', $params, '/usd'), // i18n
                'deleted' => $this->alert('did_delete', '{who} USD খাতার একটা entry মুছেছেন', '{what} · {amount}', $params, '/trash', important: true), // i18n
                default => null,
            };
        }

        if ($event === 'created' && $activity->subject_type === (new FxBatch)->getMorphClass()) {
            return $this->alert('did_fx', '{who} USD কিনেছেন', '{usd} · {bdt}', [ // i18n
                'who' => $who,
                'usd' => '$'.number_format(((int) ($new['usd'] ?? 0)) / 100, 2),
                'bdt' => Money::bdt((int) ($new['bdt'] ?? 0)),
            ], '/fx');
        }

        if ($event === 'created' && $activity->subject_type === (new WalletMove)->getMorphClass()) {
            return $this->alert('did_move', '{who} টাকা এক জায়গা থেকে আরেক জায়গায় নিয়েছেন', '{amount}', [ // i18n
                'who' => $who, 'amount' => Money::bdt((int) ($new['amount'] ?? 0)),
            ], '/wallets');
        }

        return null;
    }

    /** @param  array<string, string|int|float|null>  $params */
    private function alert(string $kind, string $title, string $body, array $params, string $url, bool $important = false): AgencyAlert
    {
        return new AgencyAlert($kind, $title, $body, $params, ['url' => $url] + ($important ? ['important' => true] : []));
    }
}

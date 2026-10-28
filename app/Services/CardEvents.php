<?php

namespace App\Services;

use App\Models\Account;
use App\Models\CardEvent;
use App\Models\MoneyApp;
use App\Models\UsdEntry;
use App\Notifications\AgencyAlert;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Card and bank apps' notifications (NSave, Fasset, PlasmaOne, Elevate Pay, …), forwarded by the
 * phone that forwards the payment SMS: every dollar move reaches the books by itself, on the card
 * or USD account it happened on. A new app is asked about once; the Owner approves it (a new
 * account, or joined to one already in the books) or turns it off, and can turn it on again.
 * - a charge at Meta, Google or TikTok pays that platform (joined with the same charge read
 *   from Meta, never booked twice); a charge anywhere else is our own spend from that account;
 * - a refund puts the dollars back, with their cost;
 * - money added moves it from the account it is set to come from (NSave's copy from its main);
 *   otherwise it is new dollars bought, which the Owner writes (with its taka) in Dollar ও Card;
 * - a declined charge, a code, an offer or anything without a dollar amount changes nothing.
 * What cannot be read for sure (no card digits, a card not in the books) waits for the Owner.
 */
class CardEvents
{
    public const HELD_TITLE = 'Card-এর একটা লেনদেন মেলাতে হবে'; // i18n

    public const NEW_APP_TITLE = 'নতুন card/bank app পাওয়া গেছে'; // i18n

    /** Shops that are an ad platform: their charges pay that platform's spend. */
    private const PLATFORMS = [
        'meta' => '/facebk|facebook|\bmeta\b|\bfb\b/i',
        'google' => '/google/i',
        'tiktok' => '/tiktok/i',
    ];

    public function __construct(private Postings $postings, private Notifier $notifier) {}

    /**
     * What a notification says. NSave's own words are not known for sure yet, so this reads
     * the usual ways a card app says it; anything it cannot place is held, not guessed.
     *
     * @return array{kind: string, amount: int|null, currency: string|null, merchant: string|null, last4: string|null}
     */
    public static function read(string $text): array
    {
        $flat = trim((string) preg_replace('/\s+/u', ' ', $text));
        $lower = mb_strtolower($flat);

        $amount = null;
        $currency = null;
        if (preg_match('/(?:US\$|\$|USD)\s?([\d,]+(?:\.\d{1,2})?)/i', $flat, $m) === 1
            || preg_match('/([\d,]+(?:\.\d{1,2})?)\s?(?:USD|US\$)/i', $flat, $m) === 1) {
            $amount = Money::toMinor(str_replace(',', '', $m[1]));
            $currency = 'USD';
        }

        $last4 = preg_match('/(?:card|ending(?:\s+in)?|[*•x…]{2,})\s*(?:ending\s*(?:in)?\s*)?[*•x…]*\s?(\d{4})\b/iu', $flat, $m) === 1 ? $m[1] : null;

        $kind = match (true) {
            // A one-time code for a payment is not the payment: the charge itself comes after.
            preg_match('/\botp\b|one.?time|verification code|passcode|security code|\b3ds\b|code is|\bcode\b\D{0,12}\d{4,8}/', $lower) === 1 => 'other',
            preg_match('/declin|\bfailed\b|unsuccessful|insufficient|rejected/', $lower) === 1 => 'declined',
            preg_match('/refund|revers|returned|credited back|chargeback/', $lower) === 1 => 'refund',
            preg_match('/\b(added|add money|deposit(?:ed)?|top.?up|topped up|loaded|funded|received)\b/', $lower) === 1 => 'topup',
            preg_match('/\b(spent|charged|paid|payment|purchase|debited|transaction|used)\b/', $lower) === 1 => 'charge',
            default => 'other',
        };

        $merchant = preg_match('/\b(?:at|to|from|with)\s+([A-Za-z0-9][\w*&\'.\- ]{1,60}?)(?=\s+(?:on|for|using|via|with|was|is|has|card|ending)\b|[.,!]|$)/u', $flat, $m) === 1
            ? trim($m[1]) : null;

        return ['kind' => $kind, 'amount' => $amount ?: null, 'currency' => $currency, 'merchant' => $merchant, 'last4' => $last4];
    }

    /**
     * One forwarded notification: kept once, read, and booked or held. The first dollar move seen
     * from an app makes it a new platform for the Owner to approve (or turn off).
     */
    public function take(string $body, CarbonImmutable $postedAt, ?string $package = null, ?string $appName = null): CardEvent
    {
        $body = mb_substr(trim($body), 0, 2000);
        $package = mb_substr($package ?: 'nsave', 0, 120);
        $read = self::read($body);

        $app = MoneyApp::where('package', $package)->first();
        $new = $app === null && $read['amount'] !== null;

        // Only a dollar move makes an app a platform; anything else from an unknown app is not kept.
        if ($new) {
            $app = MoneyApp::create(['package' => $package, 'name' => mb_substr($appName ?: $package, 0, 120), 'status' => 'pending', 'seen_at' => now()]);
        } elseif ($app !== null) {
            $app->update(['seen_at' => now()] + ($appName ? ['name' => mb_substr($appName, 0, 120)] : []));
        }

        // The same notification posted again (Android updates it): one row.
        $hash = hash('sha256', $package.'|'.$body.'|'.$postedAt->format('Y-m-d H:i'));
        $event = CardEvent::firstOrCreate(['hash' => $hash], [
            'body' => $body, 'posted_at' => $postedAt, 'package' => $package, 'app_name' => $app !== null ? $app->name : $appName,
        ] + $read);

        if ($event->wasRecentlyCreated) {
            $this->handle($event);

            if ($new) {
                $this->announce($app, $event);
            }
        }

        return $event->fresh() ?? $event;
    }

    /**
     * The Owner's answer to a new app: a new card or USD account for it, or joined to one already in
     * the books (NSave's main card). Its waiting lines go in at once.
     *
     * @param  array{mode: string, account_id?: int|null, name?: string|null, kind?: string|null, last4?: string|null, topup_from_id?: int|null}  $data
     */
    public function approve(MoneyApp $app, array $data): Account
    {
        $account = DB::transaction(function () use ($app, $data) {
            if ($data['mode'] === 'link') {
                $account = Account::whereIn('kind', ['card', 'holding'])->findOrFail((int) ($data['account_id'] ?? 0));
                $account->update(array_filter(['app_package' => $app->package, 'last4' => $data['last4'] ?? null]));
            } else {
                $kind = ($data['kind'] ?? 'card') === 'holding' ? 'holding' : 'card';
                $name = filled($data['name'] ?? null) ? (string) $data['name'] : $app->name;
                $account = Account::create([
                    'code' => str($name)->slug('_')->limit(24, '')->append('_'.now()->format('His'))->toString(),
                    'name' => $name, 'kind' => $kind,
                    'last4' => $kind === 'card' ? ($data['last4'] ?? null) : null,
                    'app_package' => $app->package, 'topup_from_id' => $data['topup_from_id'] ?? null,
                    'is_active' => true, 'sort' => (int) Account::max('sort') + 1,
                ]);
            }
            $app->update(['status' => 'on']);

            return $account;
        });

        $this->retryHeld($app->package);

        return $account;
    }

    /** On or off: off stops its lines going in; on again books what waited meanwhile. */
    public function turn(MoneyApp $app, bool $on): void
    {
        $app->update(['status' => $on ? ($app->accounts()->exists() ? 'on' : 'pending') : 'off']);

        if ($on) {
            $this->retryHeld($app->package);
        }
    }

    /** Held ones the books can now place (an app approved, a card added): tried again. */
    public function retryHeld(?string $package = null): int
    {
        $done = 0;

        foreach (CardEvent::where('status', 'held')->whereNull('usd_entry_id')->when($package, fn ($q) => $q->where('package', $package))->orderBy('posted_at')->get() as $event) {
            $this->handle($event, quiet: true);
            $done += $event->fresh()?->status === 'held' ? 0 : 1;
        }

        return $done;
    }

    /** A held line the Owner puts on a card or account: booked there. */
    public function place(CardEvent $event, Account $card): void
    {
        $this->handle($event, quiet: true, card: $card, force: true);
    }

    private function handle(CardEvent $event, bool $quiet = false, ?Account $card = null, bool $force = false): void
    {
        if ($event->amount === null || $event->currency !== 'USD' || in_array($event->kind, ['declined', 'other'], true)) {
            $event->update(['status' => 'ignored', 'problem' => $event->kind === 'declined' ? 'Declined — টাকা কাটেনি' : 'টাকার লেনদেন না']); // i18n

            return;
        }

        $app = MoneyApp::where('package', $event->package)->first();

        if (! $force && $app?->status === 'off') {
            $event->update(['status' => 'ignored', 'problem' => __(':app বন্ধ রাখা — খাতায় তোলা হয়নি।', ['app' => $app->name])]);

            return;
        }

        if (! $force && $app?->status === 'pending') {
            // The new-app notice already asked the Owner; each line waits for the answer quietly.
            $this->hold($event, __(':app নতুন — Dollar ও Card পাতায় approve করলে নিজে উঠবে।', ['app' => $app->name]), true);

            return;
        }

        $card ??= $this->accountFor($event);

        if ($card === null) {
            $this->hold($event, $event->last4 !== null
                ? __('Card :card খাতায় নেই — Dollar ও Card পাতায় card-টা যোগ করুন, তাহলে নিজে উঠবে।', ['card' => $event->last4])
                : __('কোন card থেকে, notification-এ লেখা নেই — নিজে মেলান।'), $quiet);

            return;
        }

        $event->update(['card_id' => $card->id]);
        $from = $card->topup_from_id !== null ? Account::find($card->topup_from_id) : null;

        if ($event->kind === 'topup' && $from === null) {
            $this->hold($event, __(':account-এ টাকা এসেছে — নতুন কেনা USD হলে Dollar ও Card-এ taka সহ লিখুন।', ['account' => $card->name]), $quiet);

            return;
        }

        $at = $event->posted_at !== null ? CarbonImmutable::parse($event->posted_at) : CarbonImmutable::now();
        $shop = $event->merchant ?? ($event->app_name ?? 'Card');
        $via = ($event->app_name ?? 'Card').' notification';

        DB::transaction(function () use ($event, $card, $from, $at, $shop, $via) {
            [$entry, $status] = match ($event->kind) {
                'charge' => $this->charge($event, $card, $at, $shop, $via),
                // Our spend coming back: the dollars and their cost return to the account.
                'refund' => [UsdEntry::create([
                    'entry_date' => $at->toDateString(), 'description' => 'Refund · '.$shop.($card->last4 ? ' · card '.$card->last4 : ''),
                    'category' => 'business_spend', 'account_id' => $card->id, 'amount' => (int) $event->amount, 'status' => 'na',
                    'notes' => $via,
                ]), 'booked'],
                // Money put here came from another of our accounts (NSave's copy from its main): the
                // total stays, each one is right.
                default => [UsdEntry::where('pair_key', $this->postings->transfer([
                    'entry_date' => $at->toDateString(), 'from_id' => $from?->id, 'to_id' => $card->id,
                    'amount' => (int) $event->amount, 'description' => ($event->app_name ?? 'Card').' · '.$from?->name.' → '.$card->name,
                    'notes' => $via,
                ]))->where('account_id', $card->id)->firstOrFail(), 'booked'],
            };

            $event->update(['usd_entry_id' => $entry->id, 'status' => $status, 'problem' => null]);
        });
    }

    /** The app's account the line belongs to: by its card digits, else the app's only account. */
    private function accountFor(CardEvent $event): ?Account
    {
        $mine = Account::where('app_package', $event->package)->where('is_active', true)->get();

        if ($event->last4 !== null) {
            return $mine->firstWhere('last4', $event->last4)
                ?? Account::cardByDigits($event->last4)
                // The app's one account has no digits written yet: it is that one.
                ?? ($mine->count() === 1 && $mine->first()?->last4 === null ? $mine->first() : null);
        }

        return $mine->count() === 1 ? $mine->first() : null;
    }

    /** A new app showed a dollar move: the Owner is asked once whether it is ours. */
    private function announce(MoneyApp $app, CardEvent $event): void
    {
        $this->notifier->send(null, new AgencyAlert('meta_receipt', self::NEW_APP_TITLE, '{app} · {amount} — {ask}', [ // i18n
            'app' => $app->name,
            'amount' => $event->amount !== null ? '$'.number_format($event->amount / 100, 2) : '—',
            'ask' => __('আমাদের হলে Dollar ও Card পাতায় approve করুন; না হলে বন্ধ করুন।'),
        ], ['url' => '/dollars', 'important' => true]));
    }

    /**
     * A charge: an ad platform's (joined with the same charge already read from Meta) or our own spend.
     *
     * @return array{0: UsdEntry, 1: string}
     */
    private function charge(CardEvent $event, Account $card, CarbonImmutable $at, string $shop, string $via): array
    {
        $code = collect(self::PLATFORMS)->search(fn (string $re) => preg_match($re, $shop.' '.$event->body) === 1);
        $platform = $code !== false ? Account::where('kind', 'platform')->where('code', $code)->first() : null;

        if ($platform === null) {
            return [$this->postings->ownSpend([
                'entry_date' => $at->toDateString(), 'description' => ($event->app_name ?? 'Card').' · '.$shop, 'account_id' => $card->id,
                'amount' => (int) $event->amount, 'notes' => $via.($card->last4 ? ' · card '.$card->last4 : ''),
            ]), 'booked'];
        }

        // The same charge may be in already (read from Meta, a receipt, or by hand): joined, never twice.
        $same = UsdEntry::where('category', 'card_charge')->where('account_id', $platform->id)
            ->where('amount', -(int) $event->amount)
            ->whereBetween('entry_date', [$at->subDays(2)->toDateString(), $at->addDays(2)->toDateString()])
            ->whereNotIn('id', CardEvent::whereNotNull('usd_entry_id')->pluck('usd_entry_id'))
            ->get()
            ->sortBy(fn (UsdEntry $e) => abs($e->entry_date->diffInDays($at)))
            ->first();

        if ($same !== null) {
            $same->forceFill(['card_id' => $card->id])->save();

            return [$same, 'duplicate'];
        }

        return [$this->postings->cardCharge([
            'entry_date' => $at->toDateString(), 'description' => $platform->name.' charge · card '.($card->last4 ?? ''),
            // A charge is paid by a card; from a USD account it counts on the main card's books.
            'account_id' => $platform->id, 'card_id' => $card->kind === 'card' ? $card->id : null, 'amount' => (int) $event->amount,
            'notes' => $via.' · '.$shop,
        ]), 'booked'];
    }

    private function hold(CardEvent $event, string $why, bool $quiet): void
    {
        $event->update(['status' => 'held', 'problem' => mb_substr($why, 0, 255)]);

        if ($quiet) {
            return;
        }

        $this->notifier->send(null, new AgencyAlert('meta_receipt', self::HELD_TITLE, '{amount} · {shop} — {why}', [ // i18n
            'amount' => $event->amount !== null ? '$'.number_format($event->amount / 100, 2) : '—',
            'shop' => $event->merchant ?? ($event->app_name ?? 'Card'), 'why' => $why,
        ], ['url' => '/dollars', 'important' => true]));
    }

    /**
     * Last four digits of every card of ours in the books.
     *
     * @return list<string>
     */
    public static function ourDigits(): array
    {
        return array_values(Account::where('kind', 'card')->whereNotNull('last4')->get()->map(fn (Account $a) => (string) $a->last4)->all());
    }

    /**
     * For the Dollar page: every app seen, new ones first, with its accounts and what waits.
     *
     * @return list<array<string, mixed>>
     */
    public static function apps(): array
    {
        return array_values(MoneyApp::with('accounts:id,name,last4,kind,app_package')->withCount(['events as waiting' => fn ($q) => $q->where('status', 'held')])
            ->orderByRaw("case status when 'pending' then 0 when 'on' then 1 else 2 end")->orderBy('name')->get()
            ->map(fn (MoneyApp $a) => [
                'id' => $a->id, 'name' => $a->name, 'package' => $a->package, 'status' => $a->status, 'seen' => $a->seen_at?->toIso8601String(),
                'waiting' => (int) $a->getAttribute('waiting'),
                'accounts' => $a->accounts->map(fn (Account $x) => ['id' => $x->id, 'name' => $x->name, 'last4' => $x->last4, 'kind' => $x->kind])->values()->all(),
            ])->all());
    }

    /**
     * For the Dollar page: what came in lately, held ones first.
     *
     * @return list<array<string, mixed>>
     */
    public static function recent(): array
    {
        return array_values(CardEvent::with('card:id,name,last4')
            ->orderByRaw("case when status = 'held' then 0 else 1 end")->latest('posted_at')->limit(30)->get()
            ->map(fn (CardEvent $e) => [
                'id' => $e->id, 'at' => $e->posted_at?->toIso8601String(), 'app' => $e->app_name, 'kind' => $e->kind, 'amount' => $e->amount,
                'merchant' => $e->merchant, 'last4' => $e->last4, 'card' => $e->card?->name, 'status' => $e->status,
                'problem' => $e->problem, 'body' => mb_substr($e->body, 0, 300),
            ])->all());
    }
}

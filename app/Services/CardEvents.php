<?php

namespace App\Services;

use App\Models\Account;
use App\Models\CardEvent;
use App\Models\UsdEntry;
use App\Notifications\AgencyAlert;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * NSave's app notifications, forwarded by the phone that forwards the payment SMS: every move
 * on our cards reaches the books by itself, on the card it happened on (main or copy).
 * - a charge at Meta, Google or TikTok pays that platform (joined with the same charge read
 *   from Meta, never booked twice); a charge anywhere else is our own spend from that card;
 * - a refund puts the dollars back on that card;
 * - money added to the copy card moves it from the main card; money added to the main card
 *   is new dollars bought, so the Owner writes that one (with its taka) in Dollar ও Card;
 * - a declined charge, an offer or anything without an amount changes nothing.
 * What cannot be read for sure (no card digits, a card not in the books) waits for the Owner.
 */
class CardEvents
{
    public const HELD_TITLE = 'Card-এর একটা লেনদেন মেলাতে হবে'; // i18n

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

    /** One forwarded notification: kept once, read, and booked or held. */
    public function take(string $body, CarbonImmutable $postedAt, ?string $package = null): CardEvent
    {
        $body = mb_substr(trim($body), 0, 2000);
        // The same notification posted again (Android updates it): one row.
        $hash = hash('sha256', 'nsave|'.$body.'|'.$postedAt->format('Y-m-d H:i'));

        $event = CardEvent::firstOrCreate(['hash' => $hash], ['body' => $body, 'posted_at' => $postedAt, 'package' => $package ? mb_substr($package, 0, 120) : null] + self::read($body));

        if ($event->wasRecentlyCreated) {
            $this->handle($event);
        }

        return $event->fresh() ?? $event;
    }

    /** Held ones the books can now place (a card was just added): tried again. */
    public function retryHeld(): int
    {
        $done = 0;

        foreach (CardEvent::where('status', 'held')->whereNull('usd_entry_id')->orderBy('posted_at')->get() as $event) {
            $this->handle($event, quiet: true);
            $done += $event->fresh()?->status === 'held' ? 0 : 1;
        }

        return $done;
    }

    /** A held line the Owner puts on a card: booked on that card. */
    public function place(CardEvent $event, Account $card): void
    {
        $this->handle($event, quiet: true, card: $card);
    }

    private function handle(CardEvent $event, bool $quiet = false, ?Account $card = null): void
    {
        if ($event->amount === null || $event->currency !== 'USD' || in_array($event->kind, ['declined', 'other'], true)) {
            $event->update(['status' => 'ignored', 'problem' => $event->kind === 'declined' ? 'Declined — টাকা কাটেনি' : 'টাকার লেনদেন না']); // i18n

            return;
        }

        $card ??= Account::cardByDigits($event->last4) ?? $this->onlyCard($event);

        if ($card === null) {
            $this->hold($event, $event->last4 !== null
                ? __('Card :card খাতায় নেই — Dollar ও Card পাতায় card-টা যোগ করুন, তাহলে নিজে উঠবে।', ['card' => $event->last4])
                : __('কোন card থেকে, notification-এ লেখা নেই — নিজে মেলান।'), $quiet);

            return;
        }

        $event->update(['card_id' => $card->id]);

        if ($event->kind === 'topup' && $card->id === Account::card()->id) {
            $this->hold($event, __('Main card-এ টাকা এসেছে — নতুন কেনা USD হলে Dollar ও Card-এ taka সহ লিখুন।'), $quiet);

            return;
        }

        $at = $event->posted_at !== null ? CarbonImmutable::parse($event->posted_at) : CarbonImmutable::now();
        $shop = $event->merchant ?? 'NSave';

        DB::transaction(function () use ($event, $card, $at, $shop) {
            [$entry, $status] = match ($event->kind) {
                'charge' => $this->charge($event, $card, $at, $shop),
                // Our spend coming back: the dollars and their cost return to the card.
                'refund' => [UsdEntry::create([
                    'entry_date' => $at->toDateString(), 'description' => 'Refund · '.$shop.' · card '.($card->last4 ?? ''),
                    'category' => 'business_spend', 'account_id' => $card->id, 'amount' => (int) $event->amount, 'status' => 'na',
                    'notes' => 'NSave notification',
                ]), 'booked'],
                // Money put on the copy card comes off the main card: the total stays, each card is right.
                default => [UsdEntry::where('pair_key', $this->postings->transfer([
                    'entry_date' => $at->toDateString(), 'from_id' => Account::card()->id, 'to_id' => $card->id,
                    'amount' => (int) $event->amount, 'description' => 'NSave · '.Account::card()->name.' → '.$card->name,
                    'notes' => 'NSave notification',
                ]))->where('account_id', $card->id)->firstOrFail(), 'booked'],
            };

            $event->update(['usd_entry_id' => $entry->id, 'status' => $status, 'problem' => null]);
        });
    }

    /**
     * A charge: an ad platform's (joined with the same charge already read from Meta) or our own spend.
     *
     * @return array{0: UsdEntry, 1: string}
     */
    private function charge(CardEvent $event, Account $card, CarbonImmutable $at, string $shop): array
    {
        $code = collect(self::PLATFORMS)->search(fn (string $re) => preg_match($re, $shop.' '.$event->body) === 1);
        $platform = $code !== false ? Account::where('kind', 'platform')->where('code', $code)->first() : null;

        if ($platform === null) {
            return [$this->postings->ownSpend([
                'entry_date' => $at->toDateString(), 'description' => 'NSave · '.$shop, 'account_id' => $card->id,
                'amount' => (int) $event->amount, 'notes' => 'NSave notification · card '.($card->last4 ?? ''),
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
            'account_id' => $platform->id, 'card_id' => $card->id, 'amount' => (int) $event->amount,
            'notes' => 'NSave notification · '.$shop,
        ]), 'booked'];
    }

    /** With one card in the books and no digits in the notification, it can only be that card. */
    private function onlyCard(CardEvent $event): ?Account
    {
        $cards = Account::where('kind', 'card')->get();

        return $event->last4 === null && $cards->count() === 1 ? $cards->first() : null;
    }

    private function hold(CardEvent $event, string $why, bool $quiet): void
    {
        $event->update(['status' => 'held', 'problem' => mb_substr($why, 0, 255)]);

        if ($quiet) {
            return;
        }

        $this->notifier->send(null, new AgencyAlert('meta_receipt', self::HELD_TITLE, '{amount} · {shop} — {why}', [ // i18n
            'amount' => $event->amount !== null ? '$'.number_format($event->amount / 100, 2) : '—',
            'shop' => $event->merchant ?? 'NSave', 'why' => $why,
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
     * For the Dollar page: what came in lately, held ones first.
     *
     * @return list<array<string, mixed>>
     */
    public static function recent(): array
    {
        return array_values(CardEvent::with('card:id,name,last4')
            ->orderByRaw("case when status = 'held' then 0 else 1 end")->latest('posted_at')->limit(30)->get()
            ->map(fn (CardEvent $e) => [
                'id' => $e->id, 'at' => $e->posted_at?->toIso8601String(), 'kind' => $e->kind, 'amount' => $e->amount,
                'merchant' => $e->merchant, 'last4' => $e->last4, 'card' => $e->card?->name, 'status' => $e->status,
                'problem' => $e->problem, 'body' => mb_substr($e->body, 0, 300),
            ])->all());
    }
}

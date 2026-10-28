<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Campaign;
use App\Models\MetaReceipt;
use App\Models\PilotAccount;
use App\Models\Setting;
use App\Models\UsdEntry;
use App\Notifications\AgencyAlert;
use App\Support\MailSettings;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Message;

/**
 * Meta's payment receipts, read from the receipts mailbox (Gmail forwards
 * them there), become card charges by themselves: the amount, the day, the
 * card and — as the receipt lists them — how much each campaign took. A
 * receipt is booked once, by its transaction id; one that cannot be read
 * is kept for a person, never guessed at.
 */
class MetaReceipts
{
    /** How often the mailbox is looked at. */
    public const EVERY_MINUTES = 5;

    /** Meta's card charges are read from Pilot this often. */
    // Every tick (5 minutes): a charge comes off the card's dollars soon after Meta takes it.
    public const CHARGES_EVERY_MINUTES = 5;

    /** Receipts in these states are safely in the books, so their mail is deleted. */
    public const DONE = ['booked', 'duplicate', 'ignored'];

    /** Other read mail is kept this many days, then cleared. */
    public const KEEP_DAYS = 30;

    public const BOOKED_TITLE = 'Meta receipt থেকে card charge উঠেছে'; // i18n

    public const UNREAD_TITLE = 'Meta receipt পড়া যায়নি — দেখে নিন'; // i18n

    public const OTHER_TITLE = 'অচেনা ad account-এর Meta receipt — খাতায় তোলা হয়নি'; // i18n

    /** The first line of a mailbox mail's stored text: who sent it. */
    public const SENDER_PREFIX = 'From: ';

    /** Meta's own mail domains (their subdomains count too). */
    public const META_DOMAINS = ['facebookmail.com', 'facebook.com', 'meta.com', 'metamail.com', 'fb.com'];

    public function __construct(private Postings $postings, private Notifier $notifier) {}

    public static function configured(): bool
    {
        return MailSettings::receipts() !== null;
    }

    /** Looks at the mailbox at most every few minutes (the tick calls this). */
    public function checkIfDue(): int
    {
        if (! self::configured() || ! Cache::add('meta-receipts:check', true, self::EVERY_MINUTES * 60)) {
            return 0;
        }

        return $this->check();
    }

    /**
     * Reads unseen mail in the receipts mailbox; each message is stored and,
     * when it is a Meta receipt, booked. Read mail is marked seen.
     *
     * @throws RuntimeException when the mailbox cannot be reached
     */
    public function check(): int
    {
        $box = MailSettings::receipts() ?? throw new RuntimeException(__('Receipt-এর mailbox দেওয়া নেই।'));
        $count = 0;

        try {
            $client = (new ClientManager)->make([
                'host' => $box['host'], 'port' => $box['port'], 'encryption' => 'ssl', 'validate_cert' => true,
                'username' => $box['username'], 'password' => $box['password'], 'protocol' => 'imap',
            ]);
            $client->connect();
            $inbox = $client->getFolder('INBOX');
            $messages = $inbox?->query()->whereUnseen()->limit(50)->get() ?? [];
        } catch (Throwable $e) {
            Setting::putQuietly('meta_receipts_error', mb_substr($e->getMessage(), 0, 300));

            throw new RuntimeException(__('Receipt-এর mailbox খোলা গেল না: :error', ['error' => mb_substr($e->getMessage(), 0, 200)]));
        }

        foreach ($messages as $message) {
            $html = (string) $message->getHTMLBody();
            $text = (string) $message->getTextBody();
            $id = (string) $message->getMessageId();
            $date = (string) $message->getDate();
            $receipt = $this->take(
                $id !== '' ? $id : 'uid-'.$message->getUid(),
                (string) $message->getSubject(),
                // Who sent it goes on the first line, so it is kept with the text and read
                // again on a retry. The HTML part keeps the campaign table; plain text is the fallback.
                self::senderLine($message).($html !== '' ? self::textOf($html) : $text),
                $date !== '' ? CarbonImmutable::parse($date) : CarbonImmutable::now(),
            );
            $count++;

            // The books keep the receipt's text, so the mailbox need not: a mail read
            // well is deleted (the mailbox never fills up); one that could not be read stays.
            if (in_array($receipt->status, self::DONE, true)) {
                $message->delete(false);
            } else {
                $message->setFlag('Seen');
            }
        }

        // Mail read earlier: a receipt already safe in the books goes now; anything
        // else (Gmail's forwarding codes and the like) after a month. Unreadable ones stay.
        $cutoff = now()->subDays(self::KEEP_DAYS);
        foreach ($inbox?->query()->whereSeen()->limit(100)->get() ?? [] as $old) {
            $stored = MetaReceipt::where('message_id', (string) $old->getMessageId())->first();
            $sent = (string) $old->getDate();
            $isOld = $sent !== '' && CarbonImmutable::parse($sent)->lt($cutoff);

            if ($stored !== null ? in_array($stored->status, self::DONE, true) : $isOld) {
                $old->delete(false);
            }
        }
        $client->expunge();

        Setting::putQuietly('meta_receipts_error', null);
        Setting::putQuietly('meta_receipts_checked_at', now()->toIso8601String());

        return $count;
    }

    /**
     * One message from the mailbox: kept, read, and booked when it is a
     * receipt not booked before.
     */
    public function take(string $messageId, string $subject, string $body, CarbonImmutable $sentAt): MetaReceipt
    {
        $existing = MetaReceipt::where('message_id', $messageId)->first();
        if ($existing !== null) {
            return $existing;
        }

        $receipt = new MetaReceipt(['message_id' => $messageId, 'subject' => mb_substr($subject, 0, 255), 'body' => mb_substr($body, 0, 60000)]);

        // Mail from the mailbox starts with its sender (check() writes that line); the rest is the mail.
        $sender = null;
        if (preg_match('/^'.preg_quote(self::SENDER_PREFIX, '/').'([^\n]*)\n/', $body, $line)) {
            $sender = $line[1];
            $body = substr($body, strlen($line[0]));
        }

        if (! self::looksLikeReceipt($subject, $body)) {
            $receipt->fill(['status' => 'ignored'])->save();

            return $receipt;
        }

        $account = null;
        $parsed = self::parse($subject."\n".$body, $sentAt, function (?string $ref) use (&$account) {
            $account = $ref ? PilotAccount::where('external_id', 'act_'.$ref)->orWhere('external_id', $ref)->first() : null;

            // Campaigns are looked for in the receipt's own ad account only.
            return $account === null ? [] : array_values(Campaign::where('pilot_account_id', $account->id)->get(['id', 'name', 'client_id'])->all());
        });

        $receipt->fill([
            'transaction_id' => $parsed['transaction_id'],
            'account_ref' => $parsed['account_ref'],
            'pilot_account_id' => $account?->id,
            'amount' => $parsed['amount'],
            'currency' => $parsed['currency'],
            'charged_at' => $parsed['charged_at'],
            'card_last4' => $parsed['card_last4'],
            'campaigns' => $parsed['campaigns'],
        ]);

        // Anyone can mail the mailbox: only mail from Meta itself is booked (or adds a split to a
        // charge). Anything else that looks like a receipt is kept for a person, never booked.
        if ($sender !== null && ! self::fromMeta($sender)) {
            $receipt->fill(['status' => 'unread', 'problem' => __('পাঠিয়েছে :from — Meta না, তাই খাতায় তোলা হয়নি। আসল receipt হলে হাতে লিখুন।', ['from' => $sender !== '' ? $sender : '—'])])->save();
            $this->tell($receipt, self::UNREAD_TITLE);

            return $receipt;
        }

        // Only our own ad accounts' receipts are ours: one from an account Pilot does
        // not know (someone else's, forwarded by the same filter) is kept, never booked.
        if ($account === null) {
            $receipt->fill(['status' => 'other_account', 'problem' => __('Ad account :ref আমাদের Pilot-এ নেই — খাতায় তোলা হয়নি।', ['ref' => $parsed['account_ref'] ?? '—'])])->save();
            $this->tell($receipt, self::OTHER_TITLE);

            return $receipt;
        }

        // A BDT ad account's receipts are not the card's dollars: kept, quietly.
        $currency = strtoupper((string) ($account->currency ?: ($parsed['currency'] ?? '')));
        if ($currency !== '' && $currency !== 'USD') {
            $receipt->fill(['status' => 'ignored', 'problem' => __(':currency account-এর receipt — card-এর USD খাতায় নেওয়া হয় না।', ['currency' => $currency])])->save();

            return $receipt;
        }

        if ($parsed['amount'] === null || $parsed['amount'] <= 0) {
            $receipt->fill(['status' => 'unread', 'problem' => __('টাকার অঙ্ক পাওয়া যায়নি।')])->save();
            $this->tell($receipt, self::UNREAD_TITLE);

            return $receipt;
        }

        // Every dollar must belong to a campaign of that account we know (a cent of rounding
        // aside); otherwise a person matches the names first and the mail stays.
        $unmatched = $parsed['amount'] - (int) array_sum(array_column($parsed['campaigns'], 'cents'));
        if ($parsed['campaigns'] === [] || abs($unmatched) > 1) {
            $receipt->fill(['status' => 'unread', 'problem' => __('Receipt-এর :amount কোনো campaign-এর নামের সাথে মেলেনি — Campaign পাতায় নাম/account মিলিয়ে "আবার পড়ুন" চাপুন।', ['amount' => '$'.number_format(abs($unmatched) / 100, 2)])])->save();
            $this->tell($receipt, self::UNREAD_TITLE);

            return $receipt;
        }

        if ($parsed['transaction_id'] !== null && ($first = MetaReceipt::where('transaction_id', $parsed['transaction_id'])->first()) !== null) {
            // Already booked from Pilot: the receipt adds what each campaign took.
            $entry = $first->usd_entry_id ? UsdEntry::find($first->usd_entry_id) : null;
            if ($entry !== null && ! $entry->allocations) {
                $entry->forceFill(['allocations' => self::allocations($parsed['campaigns'], $parsed['amount'])])->save();
            }
            $receipt->fill(['status' => 'duplicate', 'transaction_id' => null, 'usd_entry_id' => null])->save();

            return $receipt;
        }

        DB::transaction(function () use ($receipt, $parsed, $account) {
            $meta = Account::where('kind', 'platform')->where('code', 'meta')->firstOrFail();
            $allocations = self::allocations($parsed['campaigns'], $parsed['amount']);
            $sourceKey = 'meta:'.($parsed['transaction_id'] ?? $receipt->message_id);

            // The same charge already written by hand (same amount, within two days):
            // the receipt joins that row instead of booking the charge twice.
            // A charge Pilot already booked counts too (it has no receipt of its own yet).
            $byHand = UsdEntry::where('category', 'card_charge')->where('account_id', $meta->id)
                ->where(fn ($q) => $q->whereNull('source')->orWhere('source', 'meta_pilot'))
                ->where('amount', -$parsed['amount'])
                ->whereBetween('entry_date', [$parsed['charged_at']->subDays(2)->toDateString(), $parsed['charged_at']->addDays(2)->toDateString()])
                ->whereNotIn('id', MetaReceipt::whereNotNull('usd_entry_id')->where('message_id', 'not like', 'pilot:%')->pluck('usd_entry_id'))
                ->get()
                ->sortBy(fn (UsdEntry $e) => abs($e->entry_date->diffInDays($parsed['charged_at'])))
                ->first();

            if ($byHand !== null) {
                $byHand->forceFill([
                    'allocations' => $byHand->allocations ?: $allocations,
                    'source' => 'meta_receipt',
                    'source_key' => $sourceKey,
                    'notes' => trim(($byHand->notes ? $byHand->notes.' · ' : '').'Transaction '.($parsed['transaction_id'] ?? '—')),
                ])->save();
                $entry = $byHand;
            } else {
                $entry = $this->postings->cardCharge([
                    'entry_date' => $parsed['charged_at']->toDateString(),
                    'description' => 'Meta receipt'.($parsed['card_last4'] ? ' · card '.$parsed['card_last4'] : ''),
                    'account_id' => $meta->id,
                    'amount' => $parsed['amount'],
                    'notes' => $account->name.' · Transaction '.($parsed['transaction_id'] ?? '—'),
                    'allocations' => $allocations,
                ]);
                $entry->forceFill(['source' => 'meta_receipt', 'source_key' => $sourceKey])->save();
            }

            $receipt->fill(['usd_entry_id' => $entry->id, 'status' => 'booked', 'problem' => null])->save();
        });

        $this->tell($receipt, self::BOOKED_TITLE);

        return $receipt;
    }

    /**
     * Reads a receipt that could not be booked again from its stored text
     * (after a campaign or account was linked); its mail goes with the next
     * look at the mailbox once it is in the books.
     */
    public function retry(MetaReceipt $receipt): MetaReceipt
    {
        if (in_array($receipt->status, self::DONE, true)) {
            return $receipt;
        }

        $sentAt = CarbonImmutable::parse($receipt->created_at ?? now());
        [$messageId, $subject, $body] = [$receipt->message_id, (string) $receipt->subject, (string) $receipt->body];
        $receipt->delete();

        return $this->take($messageId, $subject, $body, $sentAt);
    }

    /**
     * The sender line for a mail: its From address, marked when the receiving
     * server found that From forged (DMARC fail). Meta's domains ask servers to
     * reject forged mail, so a made-up Meta sender is caught there.
     */
    private static function senderLine(Message $message): string
    {
        $from = (string) ($message->getFrom()->first()->mail ?? '');
        $from = mb_substr(strtolower(preg_replace('/\s+/', '', $from) ?? ''), 0, 150);
        $headers = str_replace("\r", '', (string) $message->getHeader()?->raw);
        $forged = (bool) preg_match('/^Authentication-Results:[^\n]*(?:\n[ \t][^\n]*)*\bdmarc=fail\b/mi', $headers);

        return self::SENDER_PREFIX.$from.($forged ? ' (DMARC fail)' : '')."\n";
    }

    /** Mail sent by Meta itself: a Meta address, not marked as forged. */
    public static function fromMeta(string $sender): bool
    {
        if (! preg_match('/^[^@\s]+@([a-z0-9.-]+)$/', $sender, $m)) {
            return false;
        }

        foreach (self::META_DOMAINS as $domain) {
            if ($m[1] === $domain || str_ends_with($m[1], '.'.$domain)) {
                return true;
            }
        }

        return false;
    }

    /** A Meta receipt, not some other mail that reached the mailbox. */
    public static function looksLikeReceipt(string $subject, string $body): bool
    {
        $text = $subject.' '.mb_substr($body, 0, 4000);

        return (bool) preg_match('/receipt|payment|billed|paid/i', $text)
            && (bool) preg_match('/meta|facebook/i', $text);
    }

    /**
     * What a receipt says, whatever its layout: labelled fields found by
     * their labels, and campaigns found by the names of campaigns we know,
     * each with the first amount written after it.
     *
     * @param  callable(?string): list<Campaign>  $campaignsFor  the campaigns of the receipt's ad account
     * @return array{transaction_id: ?string, account_ref: ?string, amount: ?int, currency: ?string, charged_at: CarbonImmutable, card_last4: ?string, campaigns: list<array{name: string, cents: int, campaign_id: ?int}>}
     */
    public static function parse(string $body, CarbonImmutable $sentAt, callable $campaignsFor): array
    {
        // Mail clients write odd spaces (Gmail: "11:47\u{202F}PM"): all become plain ones.
        $text = preg_replace('/[ \t\x{00A0}\x{2000}-\x{200B}\x{202F}\x{205F}\x{3000}]+/u', ' ', str_replace("\r", '', $body)) ?? $body;
        $money = '(?:US)?\$\s?([0-9][0-9,]*\.[0-9]{2})';

        $find = fn (string $pattern) => preg_match($pattern, $text, $m) ? trim($m[1]) : null;

        $amount = $find('/(?:amount\s+(?:billed|paid|charged)|total\s+(?:amount|billed|paid)|you\s+paid|paid)\s*:?\s*'.$money.'/i')
            ?? $find('/'.$money.'\s*USD/i')
            ?? $find('/'.$money.'/');
        $currency = $find('/\$\s?[0-9][0-9,]*\.[0-9]{2}\s*([A-Z]{3})\b/') ?? (str_contains($text, '$') ? 'USD' : null);
        $ref = $find('/(?:ad\s+)?account\s+id\s*[:.]?\s*([0-9]{6,})/i')
            // "Transaction for. Anowar Hossain (KA) (837…)": the last bracket holds the id.
            ?? $find('/transaction\s+for\.?\s*[^\n]{0,100}?\(([0-9]{6,})\)/i');
        // The charge is made when the billed period ends: "Date range Oct 10, 2026, 12:00 AM - Oct 15, 2026, 11:47 PM".
        $day = '([A-Za-z]{3,9}\.?\s+[0-9]{1,2},?\s+[0-9]{4}(?:,?\s+(?:at\s+)?[0-9]{1,2}:[0-9]{2}\s*[AP]M)?)';
        $date = $find('/date\s+range\s*:?\s*[^
]*?[0-9]{4}[^
]*?\s[-–]\s*'.$day.'/i')
            ?? $find('/(?:payment|billing|charge|receipt)\s+date\s*:?\s*'.$day.'/i')
            // A receipt forwarded by hand: the original's date in the forward header.
            ?? $find('/^Date:\s*(?:[A-Za-z]{3},\s*)?'.$day.'/mi');

        try {
            $chargedAt = $date ? CarbonImmutable::parse(str_replace(' at ', ' ', $date), config('app.timezone')) : $sentAt;
        } catch (Throwable) {
            $chargedAt = $sentAt;
        }

        // Campaigns: longest names first, so "GFFS - Sales 2" is not taken for "GFFS - Sales".
        $known = collect($campaignsFor($ref))->sortByDesc(fn (Campaign $c) => mb_strlen($c->name));
        $taken = [];
        $lines = [];
        foreach ($known as $campaign) {
            // The whole name only: "…Sales Campaign 2" is not found in "…Sales Campaign 25,938"
            // (that is the campaign's impressions after its name).
            $pattern = '/'.preg_quote($campaign->name, '/').'(?![\p{L}\p{N}])/iu';
            if (! preg_match_all($pattern, $text, $hits, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($hits[0] as [$found, $at]) {
                if (isset($taken[$at])) {
                    continue;
                }

                $after = substr($text, $at + strlen($found), 400);
                if (preg_match('/'.$money.'/', $after, $m)) {
                    $taken[$at] = true;
                    $lines[] = ['name' => $campaign->name, 'cents' => Money::toMinor($m[1]), 'campaign_id' => $campaign->id, 'at' => $at];
                }

                break;
            }
        }
        usort($lines, fn ($a, $b) => $a['at'] <=> $b['at']);

        return [
            'transaction_id' => $find('/transaction\s+id\s*[:.]?\s*([0-9][0-9\-]{5,}[0-9])/i'),
            'account_ref' => $ref,
            'amount' => $amount !== null ? Money::toMinor($amount) : null,
            'currency' => $currency,
            'charged_at' => $chargedAt,
            'card_last4' => $find('/(?:visa|mastercard|master\s*card|amex|american\s+express|card)[^0-9]{0,24}([0-9]{4})\b/i'),
            'campaigns' => array_map(fn ($l) => ['name' => $l['name'], 'cents' => $l['cents'], 'campaign_id' => $l['campaign_id']], $lines),
        ];
    }

    /**
     * The receipt's split as card-charge allocations: linked campaigns by
     * their key, the rest left to pay the oldest spend; never more than paid.
     *
     * @param  list<array{name: string, cents: int, campaign_id: ?int}>  $lines
     * @return array<string, int>|null
     */
    public static function allocations(array $lines, int $amount): ?array
    {
        $linked = Campaign::whereIn('id', array_filter(array_column($lines, 'campaign_id')))->whereNotNull('client_id')->pluck('id')->all();
        $out = [];
        $left = $amount;

        foreach ($lines as $line) {
            $key = $line['campaign_id'] !== null && in_array($line['campaign_id'], $linked, true) ? 'c:'.$line['campaign_id'] : 'o';
            $cents = min($line['cents'], $left);
            if ($cents > 0) {
                $out[$key] = ($out[$key] ?? 0) + $cents;
                $left -= $cents;
            }
        }

        return $out ?: null;
    }

    /** Plain text of an HTML mail, line by line. */
    public static function textOf(string $html): string
    {
        $html = preg_replace('/<(br|\/p|\/div|\/tr|\/h[1-6]|\/li)[^>]*>/i', "\n", $html) ?? $html;
        $html = preg_replace('/<\/t[dh]>/i', ' ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace("/\n\s*\n+/", "\n", $text) ?? $text);
    }

    /**
     * Meta's card charges read straight from Meta through Klok Pilot, so the card's dollars go
     * down even when no receipt email comes. Only charges from the first run on, only USD ad
     * accounts paying with one of our cards; each charge once (Meta's transaction id). A charge
     * already in the books (by hand, or from its receipt: same amount within two days) is linked,
     * never booked twice. Every five minutes; a charge set aside because its card was not ours
     * yet is booked once the card is added in Settings.
     *
     * @return int how many charges were booked
     */
    public function fromPilotIfDue(): int
    {
        if (! PilotClient::connected() || ! Cache::add('meta-charges:pilot', true, self::CHARGES_EVERY_MINUTES * 60)) {
            return 0;
        }

        $since = Setting::get('meta_charges_since');
        if ($since === null) {
            // From today on: what is already in the books stays as it is.
            Setting::putQuietly('meta_charges_since', $since = now()->toDateString());
        }
        $from = max((string) $since, now()->subDays(35)->toDateString());
        $cards = self::ourCards();
        $booked = 0;

        foreach (PilotAccount::where('platform', 'meta')->whereRaw("upper(coalesce(currency, 'USD')) = 'USD'")->get() as $account) {
            try {
                $read = app(PilotClient::class)->charges($account->pilot_id, $from);
            } catch (RuntimeException $e) {
                report($e);

                continue;
            }

            $card = preg_match('/(\d{4})\s*$/', (string) ($read['card'] ?? ''), $m) ? $m[1] : null;

            foreach ($read['charges'] ?? [] as $charge) {
                $booked += $this->bookPilotCharge($account, $charge, $card, $cards) ? 1 : 0;
            }
        }

        return $booked;
    }

    /**
     * The cards that are ours (the NSave card): set in Settings, else every card our booked
     * receipts were paid with.
     *
     * @return list<string> last four digits
     */
    public static function ourCards(): array
    {
        $set = array_filter(array_map('trim', explode(',', (string) Setting::get('meta_cards', ''))));

        return array_values($set ?: MetaReceipt::where('status', 'booked')->whereNotNull('card_last4')->distinct()->pluck('card_last4')->all());
    }

    /**
     * @param  array{id: string, time: string, amount: int, currency: string}  $charge
     * @param  list<string>  $cards
     */
    private function bookPilotCharge(PilotAccount $account, array $charge, ?string $card, array $cards): bool
    {
        $key = 'pilot:'.$charge['id'];
        $seen = MetaReceipt::where('message_id', $key)->first() ?? MetaReceipt::where('transaction_id', $charge['id'])->first();

        if ($seen !== null) {
            // Set aside only because its card was not ours then: now that it is, it is booked
            // like a new charge. Anything else already seen stays as it is.
            $nowOurs = $seen->message_id === $key && $seen->status === 'ignored' && $seen->usd_entry_id === null
                && $card !== null && in_array($card, $cards, true) && strtoupper((string) $charge['currency']) === 'USD';

            if (! $nowOurs) {
                return false;
            }

            $seen->delete();
        }

        $at = CarbonImmutable::parse($charge['time'])->setTimezone(config('app.timezone'));
        $receipt = new MetaReceipt([
            'message_id' => $key, 'transaction_id' => $charge['id'], 'subject' => 'Meta (Klok Pilot)',
            'account_ref' => ltrim((string) $account->external_id, 'act_'), 'pilot_account_id' => $account->id,
            'amount' => (int) $charge['amount'], 'currency' => strtoupper($charge['currency']), 'charged_at' => $at, 'card_last4' => $card,
        ]);

        if ($receipt->currency !== 'USD' || $receipt->amount <= 0) {
            $receipt->fill(['status' => 'ignored', 'problem' => __(':currency-এর charge — card-এর USD খাতায় নেওয়া হয় না।', ['currency' => $receipt->currency])])->save();

            return false;
        }

        // Someone else's card (a client paying Meta themselves): not our dollars.
        if ($card === null || ! in_array($card, $cards, true)) {
            $receipt->fill(['status' => 'ignored', 'problem' => __('Card :card আমাদের না — খাতায় তোলা হয়নি। Settings-এ card যোগ করলে উঠবে।', ['card' => $card ?? '—'])])->save();

            return false;
        }

        DB::transaction(function () use ($receipt, $account, $at) {
            $meta = Account::where('kind', 'platform')->where('code', 'meta')->firstOrFail();
            $same = UsdEntry::where('category', 'card_charge')->where('account_id', $meta->id)
                ->where('amount', -$receipt->amount)
                ->whereBetween('entry_date', [$at->subDays(2)->toDateString(), $at->addDays(2)->toDateString()])
                ->whereNotIn('id', MetaReceipt::whereNotNull('usd_entry_id')->where('message_id', 'like', 'pilot:%')->pluck('usd_entry_id'))
                ->get()
                ->sortBy(fn (UsdEntry $e) => abs($e->entry_date->diffInDays($at)))
                ->first();

            $entry = $same ?? $this->postings->cardCharge([
                'entry_date' => $at->toDateString(),
                'description' => 'Meta charge'.($receipt->card_last4 ? ' · card '.$receipt->card_last4 : ''),
                'account_id' => $meta->id,
                'amount' => $receipt->amount,
                'notes' => $account->name.' · Transaction '.$receipt->transaction_id.' · Klok Pilot',
            ]);

            if ($same === null) {
                $entry->forceFill(['source' => 'meta_pilot', 'source_key' => 'meta-pilot:'.$receipt->transaction_id])->save();
            }

            $receipt->fill(['usd_entry_id' => $entry->id, 'status' => $same === null ? 'booked' : 'duplicate'])->save();
        });

        if ($receipt->status === 'booked') {
            $this->tell($receipt, self::BOOKED_TITLE);
        }

        return $receipt->status === 'booked';
    }

    private function tell(MetaReceipt $receipt, string $title): void
    {
        $this->notifier->send(null, new AgencyAlert('meta_receipt', $title, '{amount} · {date}{problem}', [
            'amount' => $receipt->amount !== null ? '$'.number_format($receipt->amount / 100, 2) : '—',
            'date' => $receipt->charged_at?->format('j M') ?? '—',
            'problem' => $receipt->problem ? ' · '.$receipt->problem : '',
        ], ['url' => '/dollars/receipts']));
    }
}

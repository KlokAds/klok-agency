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

    public const BOOKED_TITLE = 'Meta receipt থেকে card charge উঠেছে'; // i18n

    public const UNREAD_TITLE = 'Meta receipt পড়া যায়নি — দেখে নিন'; // i18n

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
            $messages = $client->getFolder('INBOX')?->query()->whereUnseen()->limit(50)->get() ?? [];
        } catch (Throwable $e) {
            Setting::putQuietly('meta_receipts_error', mb_substr($e->getMessage(), 0, 300));

            throw new RuntimeException(__('Receipt-এর mailbox খোলা গেল না: :error', ['error' => mb_substr($e->getMessage(), 0, 200)]));
        }

        foreach ($messages as $message) {
            $html = (string) $message->getHTMLBody();
            $text = (string) $message->getTextBody();
            $id = (string) $message->getMessageId();
            $date = (string) $message->getDate();
            $this->take(
                $id !== '' ? $id : 'uid-'.$message->getUid(),
                (string) $message->getSubject(),
                // The HTML part keeps the campaign table; plain text is the fallback.
                $html !== '' ? self::textOf($html) : $text,
                $date !== '' ? CarbonImmutable::parse($date) : CarbonImmutable::now(),
            );
            $message->setFlag('Seen');
            $count++;
        }

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

        if (! self::looksLikeReceipt($subject, $body)) {
            $receipt->fill(['status' => 'ignored'])->save();

            return $receipt;
        }

        $account = null;
        $parsed = self::parse($subject."\n".$body, $sentAt, function (?string $ref) use (&$account) {
            $account = $ref ? PilotAccount::where('external_id', 'act_'.$ref)->orWhere('external_id', $ref)->first() : null;

            return array_values(Campaign::query()->when($account, fn ($q) => $q->where('pilot_account_id', $account->id))->get(['id', 'name', 'client_id'])->all());
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

        // A BDT ad account's receipts are not the card's dollars: kept, quietly.
        $currency = strtoupper((string) ($account->currency ?? $parsed['currency'] ?? ''));
        if ($currency !== '' && $currency !== 'USD') {
            $receipt->fill(['status' => 'ignored', 'problem' => __(':currency account-এর receipt — card-এর USD খাতায় নেওয়া হয় না।', ['currency' => $currency])])->save();

            return $receipt;
        }

        if ($parsed['amount'] === null || $parsed['amount'] <= 0) {
            $receipt->fill(['status' => 'unread', 'problem' => __('টাকার অঙ্ক পাওয়া যায়নি।')])->save();
            $this->tell($receipt, self::UNREAD_TITLE);

            return $receipt;
        }

        if ($parsed['transaction_id'] !== null && MetaReceipt::where('transaction_id', $parsed['transaction_id'])->exists()) {
            $receipt->fill(['status' => 'duplicate', 'transaction_id' => null])->save();

            return $receipt;
        }

        DB::transaction(function () use ($receipt, $parsed, $account) {
            $meta = Account::where('kind', 'platform')->where('code', 'meta')->firstOrFail();
            $allocations = self::allocations($parsed['campaigns'], $parsed['amount']);
            $sourceKey = 'meta:'.($parsed['transaction_id'] ?? $receipt->message_id);

            // The same charge already written by hand (same amount, within two days):
            // the receipt joins that row instead of booking the charge twice.
            $byHand = UsdEntry::where('category', 'card_charge')->where('account_id', $meta->id)->whereNull('source')
                ->where('amount', -$parsed['amount'])
                ->whereBetween('entry_date', [$parsed['charged_at']->subDays(2)->toDateString(), $parsed['charged_at']->addDays(2)->toDateString()])
                ->whereNotIn('id', MetaReceipt::whereNotNull('usd_entry_id')->pluck('usd_entry_id'))
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
                    'notes' => trim(($account ? $account->name.' · ' : '').'Transaction '.($parsed['transaction_id'] ?? '—')),
                    'allocations' => $allocations,
                ]);
                $entry->forceFill(['source' => 'meta_receipt', 'source_key' => $sourceKey])->save();
            }

            // Whatever the receipt paid that no campaign we know explains goes to the oldest spend.
            $unmatched = $parsed['amount'] - (int) array_sum(array_column($parsed['campaigns'], 'cents'));
            $receipt->fill([
                'usd_entry_id' => $entry->id,
                'status' => 'booked',
                'problem' => $unmatched > 0 ? __('Receipt-এর :amount কোনো জানা campaign-এ মেলেনি — পুরনো খরচে গেছে।', ['amount' => '$'.number_format($unmatched / 100, 2)]) : null,
            ])->save();
        });

        $this->tell($receipt, self::BOOKED_TITLE);

        return $receipt;
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
        $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', str_replace("\r", '', $body)) ?? $body;
        $money = '(?:US)?\$\s?([0-9][0-9,]*\.[0-9]{2})';

        $find = fn (string $pattern) => preg_match($pattern, $text, $m) ? trim($m[1]) : null;

        $amount = $find('/(?:amount\s+(?:billed|paid|charged)|total\s+(?:amount|billed|paid)|you\s+paid|paid)\s*:?\s*'.$money.'/i')
            ?? $find('/'.$money.'\s*USD/i')
            ?? $find('/'.$money.'/');
        $currency = $find('/\$\s?[0-9][0-9,]*\.[0-9]{2}\s*([A-Z]{3})\b/') ?? (str_contains($text, '$') ? 'USD' : null);
        $ref = $find('/(?:ad\s+)?account\s+id\s*[:.]?\s*([0-9]{6,})/i')
            ?? $find('/transaction\s+for\.?\s*[^()\n]{1,80}\(([0-9]{6,})\)/i');
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

    private function tell(MetaReceipt $receipt, string $title): void
    {
        $this->notifier->send(null, new AgencyAlert('meta_receipt', $title, '{amount} · {date}{problem}', [
            'amount' => $receipt->amount !== null ? '$'.number_format($receipt->amount / 100, 2) : '—',
            'date' => $receipt->charged_at?->format('j M') ?? '—',
            'problem' => $receipt->problem ? ' · '.$receipt->problem : '',
        ], ['url' => '/dollars/receipts']));
    }
}

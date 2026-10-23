<?php

namespace App\Payments;

use App\Support\Money;

/**
 * Reads the "money received" SMS that bKash, Nagad and Rocket send to the
 * receiving number, and a bank's credit alert.
 *
 * Only SMS from the wallets' official sender IDs (and the bank sender IDs
 * set in Settings → Payment) are trusted, so a client can't fake a payment
 * by texting a look-alike message from an ordinary phone number.
 */
class SmsParser
{
    /**
     * Official sender IDs per wallet (compared case-insensitively, ignoring spaces).
     *
     * @var array<string, list<string>>
     */
    public const SENDERS = [
        'bkash' => ['bkash'],
        'nagad' => ['nagad'],
        'rocket' => ['16216', 'rocket'],
    ];

    public function providerFor(string $sender): ?string
    {
        $normalised = PaySettings::sender($sender);

        if ($normalised === '') {
            return null;
        }

        foreach (self::SENDERS as $provider => $senders) {
            if (in_array($normalised, $senders, true)) {
                return $provider;
            }
        }

        return in_array($normalised, PaySettings::bankSenders(), true) ? 'bank' : null;
    }

    /**
     * @return array{provider: string, amount: int, trx_id: string, payer_number: ?string}|null
     */
    public function parse(string $sender, string $body): ?array
    {
        $provider = $this->providerFor($sender);

        if ($provider === null || ! $this->isIncoming($body, $provider)) {
            return null;
        }

        $amount = $this->amount($body);
        $trxId = $this->trxId($body, $provider === 'bank');

        // Bank alerts rarely carry a transaction ID; the text itself keeps a forwarded copy from counting twice.
        if ($provider === 'bank' && $trxId === null) {
            $trxId = 'SMS'.strtoupper(substr(sha1(preg_replace('/\s+/', ' ', trim($body)) ?? $body), 0, 17));
        }

        if ($amount === null || $amount <= 0 || $trxId === null) {
            return null;
        }

        return [
            'provider' => $provider,
            'amount' => $amount,
            'trx_id' => $trxId,
            'payer_number' => $this->payer($body),
        ];
    }

    /** Money coming in, not a Send Money / Cash Out / Payment the account itself made. */
    protected function isIncoming(string $body, string $provider): bool
    {
        if ($provider === 'bank') {
            return (bool) preg_match('/\b(credited|deposited|received|cr\.?)\b/i', $body)
                && ! preg_match('/\b(debited|withdrawn|dr\.?)\b/i', $body);
        }

        return (bool) preg_match('/\b(you have received|money received|received from|received payment|payment received|cash in)\b/i', $body)
            || (bool) preg_match('/Tk\s*[\d,]+(?:\.\d+)?\s+received/i', $body);
    }

    /** Poisha. */
    protected function amount(string $body): ?int
    {
        $number = '([\d,]+(?:\.\d{1,2})?)';
        $patterns = [
            "/Amount:\\s*(?:Tk|BDT)\\.?\\s*{$number}/i",
            "/received(?:\\s+payment)?\\s+(?:Tk|BDT)\\.?\\s*{$number}/i",
            // "BDT 1,015.00 Received" (Islami Bank CellFin), "Tk 500 received".
            "/(?:Tk|BDT)\\.?\\s*{$number}\\s+received/i",
            "/Cash In\\s+Tk\\.?\\s*{$number}/i",
            // "credited with Tk …", and UCB's "has been credited BDT 5,000.00".
            "/credited\\s+(?:(?:with|by|for)\\s+)?(?:Tk|BDT)\\.?\\s*{$number}/i",
            "/(?:Tk|BDT)\\.?\\s*{$number}\\s+(?:has been\\s+|is\\s+)?(?:credited|deposited)/i",
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $body, $match)) {
                return Money::toMinor($match[1]);
            }
        }

        return null;
    }

    protected function trxId(string $body, bool $bank = false): ?string
    {
        if (preg_match('/\b(?:TrxID|TxnID|Trx\s*ID|Txn\s*ID|TxnId|Transaction\s*ID)\s*[:.]?\s*([A-Z0-9]{6,20})\b/i', $body, $match)) {
            return strtoupper($match[1]);
        }

        // Only a bank alert's "Ref" is its transaction number; a wallet's Ref is what the sender typed.
        if ($bank && preg_match('/\bRef(?:erence)?\.?\s*(?:No\.?)?\s*[:.]?\s*([A-Z0-9]{6,20})\b/i', $body, $match)) {
            return strtoupper($match[1]);
        }

        return null;
    }

    protected function payer(string $body): ?string
    {
        if (preg_match('/(?:from|Sender:?)\s*(?:A\/C:?\s*)?(\+?8?8?01[3-9]\d{8}|01[3-9]\d{2}\*+\d{2,3})/i', $body, $match)) {
            return $match[1];
        }

        return null;
    }
}

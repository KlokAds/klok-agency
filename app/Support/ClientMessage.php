<?php

namespace App\Support;

use App\Models\AdRun;
use App\Models\Invoice;

/**
 * Ready-to-send text for a client, opened in WhatsApp or the phone's SMS app
 * with one tap — nothing is sent without a person pressing send.
 */
class ClientMessage
{
    /** Bangladeshi numbers in any common form → 8801XXXXXXXXX; null when unusable. */
    public static function phone(?string $raw): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $raw);

        if (str_starts_with($digits, '01') && strlen($digits) === 11) {
            return '88'.$digits;
        }

        if (str_starts_with($digits, '8801') && strlen($digits) === 13) {
            return $digits;
        }

        return strlen($digits) >= 10 ? $digits : null;
    }

    public static function renewal(AdRun $run): string
    {
        return __('আসসালামু আলাইকুম :name, আপনার :platform ad ":title" :date-এ শেষ হবে। চালু রাখতে চাইলে জানাবেন — আমরা renew করে দেব। — Klok Ads', [
            'name' => $run->client?->name,
            'platform' => $run->account?->name,
            'title' => $run->title,
            'date' => $run->end_on->format('d M'),
        ]);
    }

    /** Always in বাংলা: it goes to the client, not to whoever is signed in. */
    public static function invoice(Invoice $invoice): string
    {
        return __('আসসালামু আলাইকুম :name, Klok Ads-এর invoice :number — মোট :amount:due। দেখতে ও পেমেন্ট করতে: :url', [
            'name' => $invoice->client->name,
            'number' => $invoice->number,
            'amount' => Money::bdt($invoice->due()),
            'due' => $invoice->due_date ? __(', শেষ তারিখ :date', ['date' => $invoice->due_date->format('d M Y')], 'bn') : '',
            'url' => $invoice->publicUrl(),
        ], 'bn');
    }

    /** @return array{phone: ?string, whatsapp: ?string, sms: ?string, text: string} */
    public static function links(?string $phone, string $text): array
    {
        $number = self::phone($phone);

        return [
            'phone' => $number,
            'whatsapp' => $number ? 'https://wa.me/'.$number.'?text='.rawurlencode($text) : null,
            'sms' => $number ? 'sms:+'.$number.'?body='.rawurlencode($text) : null,
            'text' => $text,
        ];
    }
}

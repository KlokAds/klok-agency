<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\AgencyAlert;

/**
 * Tells a client's own people (the portal users linked to that client) — in the portal's bell,
 * on their phone if they use the app, and by email. Kinds start with "client_"; links go to the
 * portal or to the invoice's own page, never into the books.
 */
class ClientNotifier
{
    public const INVOICE_TITLE = 'নতুন invoice — {number}'; // i18n

    public const OVERDUE_TITLE = 'Invoice {number}-এর শেষ তারিখ পার হয়েছে'; // i18n

    public const PAID_TITLE = 'পেমেন্ট পাওয়া গেছে — ধন্যবাদ!'; // i18n

    public const PART_TITLE = 'পেমেন্টের একাংশ পাওয়া গেছে'; // i18n

    public const REFUND_TITLE = 'আপনার পেমেন্ট ফেরত দেওয়া হয়েছে'; // i18n

    public const SUMMARY_TITLE = 'আজকের বিজ্ঞাপনের ফল'; // i18n

    public const ENDING_TITLE = 'Campaign শেষ হচ্ছে'; // i18n

    public const LOW_TITLE = 'বিজ্ঞাপনের টাকা শেষের দিকে'; // i18n

    public const PAUSED_TITLE = 'টাকা শেষ — campaign থামানো হয়েছে'; // i18n

    public const EXTENDED_TITLE = 'Campaign বাড়ানো হয়েছে'; // i18n

    public const HELD_TITLE = 'Campaign সাময়িক বন্ধ — আমাদের দিকের সমস্যা'; // i18n

    public const BACK_TITLE = 'Campaign আবার চলছে'; // i18n

    public const WELCOME_TITLE = 'KlokOne-এ স্বাগতম'; // i18n

    public const LINKED_TITLE = 'আপনার account Klok Ads-এর সাথে যুক্ত হয়েছে'; // i18n

    public const DECLINED_TITLE = 'আপনি campaign চালু না রাখার কথা জানিয়েছেন'; // i18n

    public const REFUND_ASKED_TITLE = 'ফেরতের অনুরোধ পেয়েছি'; // i18n

    public const TEAM_PAUSED_TITLE = 'Klok Ads campaign থামিয়েছে'; // i18n

    /** @return int how many people heard */
    public function send(?int $clientId, AgencyAlert $alert): int
    {
        if ($clientId === null) {
            return 0;
        }

        $people = User::where('client_id', $clientId)->where('is_active', true)->get();

        foreach ($people as $user) {
            $user->notify($alert);
        }

        return $people->count();
    }
}

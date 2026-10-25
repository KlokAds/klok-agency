<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Your account's details were changed": sent to the person themselves (bell, phone and email,
 * as AgencyAlert does) and, when the email itself changed, by email to the old address too — so a
 * change they did not make never goes unnoticed.
 */
class AccountChanged extends Notification
{
    public const TITLE = 'আপনার account-এর তথ্য বদলানো হয়েছে'; // i18n

    /** @param  list<string>  $what  names of what changed, already in words */
    public function __construct(public array $what, public string $lang = 'bn') {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * The alert for the person's own bell, phone and email.
     *
     * @param  list<string>  $what
     */
    public static function alert(User $user, array $what): AgencyAlert
    {
        return new AgencyAlert('account_changed', self::TITLE, '{what} বদলানো হয়েছে। আপনি না করে থাকলে সাথে সাথে Klok Ads-কে জানান।', [ // i18n
            'what' => implode(', ', $what),
        ], ['url' => '/settings/profile', 'important' => true]);
    }

    /** To the old address, when the email changed: it may be the only one the owner still reads. */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__(self::TITLE, [], $this->lang))
            ->markdown('notifications::email', ['kicker' => __('নিরাপত্তা', [], $this->lang)])
            ->greeting(__(self::TITLE, [], $this->lang))
            ->line(__('নমস্কার,', [], $this->lang))
            ->line(__(':what বদলানো হয়েছে — এই email আর আপনার account-এর sign-in email নয়। আপনি না করে থাকলে সাথে সাথে Klok Ads-কে জানান।', ['what' => implode(', ', $this->what)], $this->lang))
            ->salutation(__('শুভেচ্ছান্তে,', [], $this->lang));
    }
}

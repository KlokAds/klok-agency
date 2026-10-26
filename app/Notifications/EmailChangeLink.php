<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To the address a person wants to sign in with from now on: one link, good for an hour,
 * that makes it their email. Nothing changes until the link is opened.
 */
class EmailChangeLink extends Notification
{
    public const TITLE = 'KlokOne-এ আপনার নতুন email confirm করুন'; // i18n

    public function __construct(public string $url, public string $name, public string $lang = 'bn') {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__(self::TITLE, [], $this->lang))
            ->markdown('notifications::email', ['kicker' => __('নিরাপত্তা', [], $this->lang)])
            ->greeting(__(self::TITLE, [], $this->lang))
            ->line(__(':name এই email-টা KlokOne-এর sign-in email করতে চেয়েছেন। নিচের button চাপলে এটাই হবে।', ['name' => $this->name], $this->lang))
            ->action(__('এই email-টা confirm করুন', [], $this->lang), $this->url)
            ->line(__('Link-টা ১ ঘণ্টা চলবে। আপনি না চেয়ে থাকলে কিছু করতে হবে না — কিছুই বদলাবে না।', [], $this->lang))
            ->salutation(__('শুভেচ্ছান্তে,', [], $this->lang));
    }
}

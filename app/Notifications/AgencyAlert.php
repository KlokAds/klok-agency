<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Channels\AppChannel;
use App\Services\MetaReceipts;
use App\Services\Notifier;
use App\Support\MailSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One notification: in the bell, and on the phones running the KlokOne
 * app. Text is kept in বাংলা source form with its parameters, and translated
 * when shown, so each reader sees their language. Every one rings on the
 * phone; urgent ones (money in, money out, something to approve or fix) pop
 * up over everything.
 */
class AgencyAlert extends Notification
{
    /** Kinds that need someone now. */
    public const URGENT = ['invoice_paid', 'invoice_claim', 'credit_out', 'credit_low', 'renewal_paid', 'card_low', 'pilot_down', 'account_problem'];

    /**
     * @param  array<string, string|int|float|null>  $params
     * @param  array<string, mixed>  $extra  e.g. ['url' => '/runs', 'phone' => '8801…', 'message' => '…']
     */
    public function __construct(
        public string $kind,
        public string $title,
        public ?string $body = null,
        public array $params = [],
        public array $extra = [],
    ) {}

    /**
     * The bell always; the phone and email as the reader chose for this group
     * (an important one rings the phone of whoever gets it). Who gets it at all
     * is Notifier's business.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if (! $notifiable instanceof User) {
            return [...$channels, AppChannel::class];
        }

        // A client's own person: their phone, and email too — that is how most clients read.
        if ($notifiable->isClient()) {
            return [...$channels, AppChannel::class, ...(filled($notifiable->email) && MailSettings::ready() ? ['mail'] : [])];
        }

        $prefs = Notifier::prefs($notifiable, $this->group());
        // no_push: the phone was already told another way (an app release).
        if (($prefs['push'] || ! empty($this->extra['important'])) && empty($this->extra['no_push'])) {
            $channels[] = AppChannel::class;
        }
        if ($prefs['email'] && filled($notifiable->email) && MailSettings::ready()) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    /** The same notification as a short email, in the reader's language, with a link to act on it. */
    public function toMail(object $notifiable): MailMessage
    {
        $app = $this->toApp($notifiable);
        $locale = (string) ($notifiable->locale ?? 'bn');
        $name = $notifiable instanceof User ? trim((string) $notifiable->name) : '';

        // A letter, not a log line: what it is about, the title, a greeting, the news, one button.
        return (new MailMessage)
            ->subject($app['title'])
            ->markdown('notifications::email', ['kicker' => $this->kicker($locale)])
            ->greeting($app['title'])
            ->line($name !== '' ? __('প্রিয় :name,', ['name' => $name], $locale) : __('নমস্কার,', [], $locale))
            ->line((string) ($app['body'] ?? ''))
            ->action(__('KlokOne-এ দেখুন', [], $locale), url($app['url'] ?? '/notifications'))
            ->salutation(__('শুভেচ্ছান্তে,', [], $locale));
    }

    /** The small line above an email's title: what the news is about. */
    private function kicker(string $locale): string
    {
        $label = match (true) {
            in_array($this->kind, ['client_invoice', 'client_paid', 'client_part_paid', 'client_overdue', 'client_refund'], true) => 'Invoice ও পেমেন্ট', // i18n
            str_starts_with($this->kind, 'client_') => 'আপনার বিজ্ঞাপন', // i18n
            default => match ($this->group()) {
                'money' => 'টাকা ও পেমেন্ট', // i18n
                'ads' => 'বিজ্ঞাপন', // i18n
                'dollars' => 'Dollar ও card', // i18n
                'activity' => 'কে কী করল', // i18n
                'summary' => 'দিনের সারসংক্ষেপ', // i18n
                default => 'KlokOne',
            },
        };

        return __($label, [], $locale);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->kind,
            'title' => $this->title,
            'body' => $this->body,
            'params' => $this->params,
        ] + $this->extra;
    }

    /**
     * The phone notification, in the reader's language.
     *
     * @return array{title: string, body: ?string, url: ?string, level: string, tag: string, kind: string}
     */
    public function toApp(object $notifiable): array
    {
        $locale = (string) ($notifiable->locale ?? 'bn');
        $fill = fn (string $text) => preg_replace_callback('/\{(\w+)\}/', fn ($m) => (string) ($this->params[$m[1]] ?? ''), $text) ?? $text;

        return [
            'title' => $fill(__($this->title, [], $locale)),
            'body' => $this->body !== null ? $fill(__($this->body, [], $locale)) : null,
            // Tapping it on the phone opens the page and marks this one read on the web too (?n=).
            'url' => self::withId(isset($this->extra['url']) ? (string) $this->extra['url'] : '/notifications', $this->id),
            'level' => $this->level(),
            'tag' => $this->kind.':'.md5($this->title.json_encode($this->params)),
            'kind' => $this->kind,
        ];
    }

    /** Which group of Notifier::GROUPS this kind belongs to (who acts on it). */
    public function group(): string
    {
        return match (true) {
            in_array($this->kind, ['invoice_paid', 'invoice_claim', 'invoice_overdue', 'invoice_refund', 'sms_silent'], true) => 'money',
            in_array($this->kind, ['credit_out', 'credit_low', 'ended', 'pilot_down', 'campaign_changed', 'campaign_ending', 'campaign_spend', 'account_problem', 'client_campaign'], true), str_starts_with($this->kind, 'renewal') => 'ads',
            str_starts_with($this->kind, 'did_') => 'activity',
            in_array($this->kind, ['card_low', 'meta_receipt'], true) => 'dollars',
            $this->kind === 'summary' => 'summary',
            default => 'tasks',
        };
    }

    public static function withId(string $url, string $id): string
    {
        return $url.(str_contains($url, '?') ? '&' : '?').'n='.$id;
    }

    public function level(): string
    {
        // A receipt that went into the books needs no one; one that did not, does.
        if ($this->kind === 'meta_receipt') {
            return $this->title === MetaReceipts::BOOKED_TITLE ? 'normal' : 'urgent';
        }

        return in_array($this->kind, self::URGENT, true) ? 'urgent' : 'normal';
    }
}

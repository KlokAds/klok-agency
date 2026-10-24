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
 * One notification: in the bell, and on the phones running the Klok Finance
 * app. Text is kept in বাংলা source form with its parameters, and translated
 * when shown, so each reader sees their language. Every one rings on the
 * phone; urgent ones (money in, money out, something to approve or fix) pop
 * up over everything.
 */
class AgencyAlert extends Notification
{
    /** Kinds that need someone now. */
    public const URGENT = ['invoice_paid', 'invoice_claim', 'credit_out', 'credit_low', 'renewal_paid', 'card_low', 'pilot_down'];

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

        $prefs = Notifier::prefs($notifiable, $this->group());
        if ($prefs['push'] || ! empty($this->extra['important'])) {
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

        return (new MailMessage)
            ->subject($app['title'])
            ->greeting($app['title'])
            ->line((string) ($app['body'] ?? ''))
            ->action(__('Klok Finance-এ দেখুন', [], (string) ($notifiable->locale ?? 'bn')), url($app['url'] ?? '/notifications'));
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
            in_array($this->kind, ['credit_out', 'credit_low', 'ended', 'pilot_down', 'campaign_changed', 'campaign_ending', 'campaign_spend'], true), str_starts_with($this->kind, 'renewal') => 'ads',
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

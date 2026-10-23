<?php

namespace App\Notifications;

use App\Notifications\Channels\AppChannel;
use App\Services\MetaReceipts;
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
    public const URGENT = ['invoice_paid', 'invoice_claim', 'credit_out', 'renewal_paid', 'sms_unmatched', 'card_low', 'pilot_down'];

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

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', AppChannel::class];
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
            'url' => isset($this->extra['url']) ? (string) $this->extra['url'] : null,
            'level' => $this->level(),
            'tag' => $this->kind.':'.md5($this->title.json_encode($this->params)),
            'kind' => $this->kind,
        ];
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

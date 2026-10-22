<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * One in-app notification (the bell). Text is kept in বাংলা source form with
 * its parameters, and translated when shown, so each reader sees their language.
 */
class AgencyAlert extends Notification
{
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
        return ['database'];
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
}

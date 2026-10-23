<?php

namespace App\Notifications\Channels;

use App\Models\AppDevice;
use App\Notifications\AgencyAlert;
use App\Services\AppPush;
use Illuminate\Notifications\Notification;

/**
 * Sends a notification to every phone where its reader uses the app. A
 * person without the app, or a server without Firebase set up, simply gets
 * the bell.
 */
class AppChannel
{
    public function __construct(private AppPush $push) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notification instanceof AgencyAlert || ! isset($notifiable->id) || ! $this->push->ready()) {
            return;
        }

        $devices = AppDevice::where('user_id', $notifiable->id)->get();

        if ($devices->isNotEmpty()) {
            $this->push->send($devices, $notification->toApp($notifiable));
        }
    }
}

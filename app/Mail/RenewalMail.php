<?php

namespace App\Mail;

use App\Models\Renewal;
use App\Support\Money;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * To the admin: a client ended a campaign's contract, or paid for more days
 * (with the link to approve the extension).
 */
class RenewalMail extends Mailable
{
    public function __construct(public Renewal $renewal, public string $event) {}

    public function envelope(): Envelope
    {
        $who = ($this->renewal->client->name ?? 'Client').' — '.($this->renewal->campaign->name ?? 'campaign');

        return new Envelope(subject: $this->event === 'paid'
            ? "Renewal paid, approve: {$who}"
            : "Contract ended, no extension: {$who}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.renewal', with: [
            'renewal' => $this->renewal,
            'event' => $this->event,
            'total' => $this->renewal->invoice ? Money::bdt($this->renewal->invoice->total) : null,
            'usd' => $this->renewal->usd !== null ? '$'.number_format($this->renewal->usd / 100, 2) : null,
            'url' => $this->renewal->adminUrl(),
        ]);
    }
}

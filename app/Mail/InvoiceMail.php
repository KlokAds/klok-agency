<?php

namespace App\Mail;

use App\Models\Invoice;
use App\Payments\PaySettings;
use App\Support\Money;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The invoice link by email, for clients who prefer it to WhatsApp.
 */
class InvoiceMail extends Mailable
{
    public function __construct(public Invoice $invoice) {}

    /** Mail is usable only when a real mailer is set up (not the log/array drivers). */
    public static function configured(): bool
    {
        app('mail.manager'); // applies the mailbox saved in Settings

        return ! in_array(config('mail.default'), ['log', 'array', null], true)
            && ! str_ends_with((string) config('mail.from.address'), '@example.com');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Invoice '.$this->invoice->number.' — '.PaySettings::business()['name']);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.invoice', with: [
            'business' => PaySettings::business(),
            'total' => Money::bdt($this->invoice->total),
            'due' => Money::bdt($this->invoice->due()),
            'url' => $this->invoice->publicUrl(),
        ]);
    }
}

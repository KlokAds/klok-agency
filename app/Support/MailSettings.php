<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;

/**
 * The mailbox the app sends from (a Hostinger mailbox such as
 * billing@klokads.com), set in Settings. The password is kept encrypted.
 * When nothing is set there, .env's MAIL_* settings stay in force.
 */
class MailSettings
{
    public const DEFAULTS = ['host' => 'smtp.hostinger.com', 'port' => 465];

    /** @return array{host: string, port: int, username: string, from_name: string, has_password: bool, notify_email: string, receipts_host: string, receipts_username: string, receipts_has_password: bool} */
    public static function form(): array
    {
        return [
            'host' => (string) (Setting::get('smtp_host') ?: self::DEFAULTS['host']),
            'port' => (int) (Setting::get('smtp_port') ?: self::DEFAULTS['port']),
            'username' => (string) Setting::get('smtp_username', ''),
            'from_name' => (string) (Setting::get('smtp_from_name') ?: 'Klok Ads'),
            'has_password' => filled(Setting::get('smtp_password')),
            'notify_email' => (string) (Setting::get('notify_email') ?: 'adsklok@gmail.com'),
            'receipts_host' => (string) (Setting::get('receipts_host') ?: 'imap.hostinger.com'),
            'receipts_username' => (string) Setting::get('receipts_username', ''),
            'receipts_has_password' => filled(Setting::get('receipts_password')),
        ];
    }

    /**
     * The mailbox Meta's receipts are forwarded to (read over IMAP), when set.
     *
     * @return array{host: string, port: int, username: string, password: string}|null
     */
    public static function receipts(): ?array
    {
        $username = (string) Setting::get('receipts_username', '');
        $stored = Setting::get('receipts_password');

        if ($username === '' || blank($stored)) {
            return null;
        }

        try {
            $password = Crypt::decryptString($stored);
        } catch (\Throwable) {
            return null;
        }

        return [
            'host' => (string) (Setting::get('receipts_host') ?: 'imap.hostinger.com'),
            'port' => (int) (Setting::get('receipts_port') ?: 993),
            'username' => $username,
            'password' => $password,
        ];
    }

    /** Whether a mailbox to send from is saved (email notifications need one). */
    public static function ready(): bool
    {
        return (string) Setting::get('smtp_username', '') !== '' && filled(Setting::get('smtp_password'));
    }

    /** Points Laravel's mailer at the saved mailbox, if one is saved. */
    public static function apply(): void
    {
        $username = (string) Setting::get('smtp_username', '');
        $stored = Setting::get('smtp_password');

        if ($username === '' || blank($stored)) {
            return;
        }

        try {
            $password = Crypt::decryptString($stored);
        } catch (\Throwable) {
            return;
        }

        $port = (int) (Setting::get('smtp_port') ?: self::DEFAULTS['port']);

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.scheme' => $port === 465 ? 'smtps' : 'smtp',
            'mail.mailers.smtp.host' => (string) (Setting::get('smtp_host') ?: self::DEFAULTS['host']),
            'mail.mailers.smtp.port' => $port,
            'mail.mailers.smtp.username' => $username,
            'mail.mailers.smtp.password' => $password,
            'mail.from.address' => $username,
            'mail.from.name' => (string) (Setting::get('smtp_from_name') ?: 'Klok Ads'),
        ]);
    }
}

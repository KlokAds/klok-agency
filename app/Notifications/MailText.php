<?php

namespace App\Notifications;

/**
 * Typed-in text (a person's, client's or campaign's name) put into a Markdown email.
 */
final class MailText
{
    /**
     * Text that Markdown shows as it is: no links, images, headings or emphasis. Like
     * AgencyAlert::plain(), but without < and >: the mail views print with {{ }}, which
     * already turns them into &lt; / &gt; (never a tag or an <https://…> link), and a
     * backslash before that would show "&lt;" in the email.
     */
    public static function plain(?string $text): string
    {
        return addcslashes((string) $text, '\\`*_[]()#!~|');
    }
}

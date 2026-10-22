/*
 * Money arrives from the server as integer minor units
 * (cents for USD, poisha for BDT).
 */
import { t } from '@/lib/i18n';

export type Currency = 'usd' | 'bdt';

const grouping = new Intl.NumberFormat('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

const whole = new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 });

export function money(
    minor: number | null | undefined,
    currency: Currency,
    opts: { sign?: boolean; round?: boolean } = {},
): string {
    if (minor === null || minor === undefined) {
        return '–';
    }

    const symbol = currency === 'usd' ? '$' : '\u09F3';
    const value = Math.abs(minor) / 100;
    const round = opts.round ?? currency === 'bdt';
    const shown = round ? Math.round(value) : Math.round(value * 100) / 100;
    const body = round ? whole.format(shown) : grouping.format(shown);
    // A value that rounds to zero is shown without a sign.
    const negative = minor < 0 && shown !== 0;
    const lead = negative ? '−' : opts.sign && minor > 0 && shown !== 0 ? '+' : '';

    return `${lead}${symbol}${body}`;
}

/** Minor units → text for an input field ("1250.50"). */
export function toInput(minor: number | null | undefined): string {
    if (!minor) {
        return '';
    }

    return (Math.abs(minor) / 100).toFixed(2).replace(/\.00$/, '');
}

export const BN_MONTHS = [
    'জানুয়ারি', // i18n
    'ফেব্রুয়ারি', // i18n
    'মার্চ', // i18n
    'এপ্রিল', // i18n
    'মে', // i18n
    'জুন', // i18n
    'জুলাই', // i18n
    'আগস্ট', // i18n
    'সেপ্টেম্বর', // i18n
    'অক্টোবর', // i18n
    'নভেম্বর', // i18n
    'ডিসেম্বর', // i18n
];

/** Month names in the current language (January… or জানুয়ারি…). */
export function monthNames(): string[] {
    return BN_MONTHS.map((name) => t(name));
}

const SHORT = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

export function parseDate(value: string | null | undefined): Date | null {
    if (!value) {
        return null;
    }

    const [y, m, d] = value.slice(0, 10).split('-').map(Number);

    return new Date(y, m - 1, d);
}

export function isoDate(date: Date): string {
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');

    return `${date.getFullYear()}-${m}-${d}`;
}

export function today(): string {
    return isoDate(new Date());
}

/** "15 Oct 2026" — the way the old sheet wrote dates. */
export function niceDate(value: string | null | undefined, withYear = true): string {
    const date = parseDate(value);

    if (!date) {
        return '—';
    }

    const base = `${date.getDate()} ${SHORT[date.getMonth()]}`;

    return withYear ? `${base} ${date.getFullYear()}` : base;
}

export function monthLabel(ym: string | null | undefined): string {
    if (!ym) {
        return '—';
    }

    const [y, m] = ym.split('-').map(Number);

    return `${monthNames()[m - 1]} ${y}`;
}

export function ago(iso: string | null | undefined): string {
    if (!iso) {
        return '—';
    }

    const seconds = Math.round((Date.now() - new Date(iso).getTime()) / 1000);

    if (seconds < 60) {
        return t('এইমাত্র');
    }

    const minutes = Math.round(seconds / 60);
    if (minutes < 60) {
        return t('{n} মিনিট আগে', { n: minutes });
    }

    const hours = Math.round(minutes / 60);
    if (hours < 24) {
        return hours === 1 ? t('1 ঘণ্টা আগে') : t('{n} ঘণ্টা আগে', { n: hours });
    }

    const days = Math.round(hours / 24);
    if (days < 30) {
        return days === 1 ? t('1 দিন আগে') : t('{n} দিন আগে', { n: days });
    }

    return niceDate(iso.slice(0, 10));
}

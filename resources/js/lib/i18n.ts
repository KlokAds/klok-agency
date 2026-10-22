import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

/*
 * বাংলা is the source text and the lookup key; English lives in
 * lang/en.json and lang/parts/<area>/en.json (the same files Laravel's __() reads).
 *
 *   t('আজকের ডেস্ক')                → "Today's desk" in English
 *   t('{n}টা row', { n: 3 })        → "3 rows"
 */
export type Locale = 'bn' | 'en';

const files = import.meta.glob<Record<string, string>>(
    ['../../../lang/en.json', '../../../lang/parts/*/en.json'],
    {
        eager: true,
        import: 'default',
    },
);

const english: Record<string, string> = Object.assign(
    {},
    ...Object.values(files),
);

export const locale = ref<Locale>(
    (document.documentElement.lang as Locale) === 'en' ? 'en' : 'bn',
);

export function setLocale(value: Locale): void {
    locale.value = value;
    document.documentElement.lang = value;
}

export function t(
    text: string,
    params: Record<string, string | number | null | undefined> = {},
): string {
    const out = locale.value === 'en' ? (english[text] ?? text) : text;

    return out.replace(/\{(\w+)\}/g, (_, key: string) =>
        (params[key] ?? '').toString(),
    );
}

export function isEnglish(): boolean {
    return locale.value === 'en';
}

/** Keeps the language in step with what the server says on every visit. */
export function initializeLocale(): void {
    router.on('navigate', (event) => {
        const value = (event as CustomEvent).detail?.page?.props?.locale as
            | Locale
            | undefined;

        if (value && value !== locale.value) {
            setLocale(value);
        }
    });
}

export function switchLocale(value: Locale): void {
    setLocale(value);
    router.post(
        '/locale',
        { locale: value },
        { preserveScroll: true, preserveState: false },
    );
}

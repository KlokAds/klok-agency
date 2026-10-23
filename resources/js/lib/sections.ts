// Pages that are views of one thing share one menu item and tabs: the
// dollars and the card, and the full ledgers kept for checking.
export const SECTIONS = {
    dollars: [
        {
            label: 'Overview',
            href: '/dollars',
            icon: 'dollar',
            perm: 'usd.view',
        },
        { label: 'FX Batch', href: '/fx', icon: 'swap', perm: 'fx.view' },
        {
            label: 'Card মেলানো',
            href: '/reconcile',
            icon: 'scale',
            perm: 'reconcile.view',
        }, // i18n
    ],
    campaigns: [
        {
            label: 'Campaign',
            href: '/campaigns',
            icon: 'pulse',
            perm: 'runs.view',
        },
        {
            label: 'Renewal',
            href: '/renewals',
            icon: 'renew',
            perm: 'runs.view',
        },
    ],
    ledgers: [
        { label: 'BDT খাতা', href: '/bdt', icon: 'taka', perm: 'bdt.view' }, // i18n
        { label: 'USD খাতা', href: '/usd', icon: 'dollar', perm: 'usd.view' }, // i18n
    ],
} as const;

/** The section a path belongs to, if any. */
export function sectionOf(path: string): keyof typeof SECTIONS | null {
    for (const [key, tabs] of Object.entries(SECTIONS)) {
        if (
            tabs.some(
                (tab) => path === tab.href || path.startsWith(tab.href + '/'),
            )
        ) {
            return key as keyof typeof SECTIONS;
        }
    }

    return null;
}

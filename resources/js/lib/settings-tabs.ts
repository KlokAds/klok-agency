// Settings is one place with tabs: rules of the books, the agency's
// reminders, people and their access, and the record of changes.
export const SETTINGS_TABS = [
    {
        label: 'সাধারণ',
        href: '/admin/settings',
        icon: 'gear',
        perm: 'settings.view',
    }, // i18n
    {
        label: 'Reminder ও Pilot',
        href: '/admin/agency',
        icon: 'bell',
        perm: 'settings.view',
    }, // i18n
    {
        label: 'Payment',
        href: '/admin/payment',
        icon: 'wallet',
        perm: 'settings.view',
    },
    {
        label: 'Phone app',
        href: '/admin/app',
        icon: 'bell',
        perm: 'settings.view',
    },
    { label: 'User', href: '/admin/users', icon: 'user', perm: 'users.view' },
    {
        label: 'Session',
        href: '/admin/sessions',
        icon: 'signal',
        perm: 'users.view',
    },
    {
        label: 'Role ও Permission',
        href: '/admin/roles',
        icon: 'key',
        perm: 'roles.view',
    }, // i18n
    {
        label: 'Activity log',
        href: '/activity',
        icon: 'pulse',
        perm: 'activity.view',
    },
    { label: 'Trash', href: '/trash', icon: 'bin', perm: 'trash.view' },
    // Owner only (checked on the server too).
    { label: 'সমস্যা', href: '/admin/errors', icon: 'warn', perm: 'owner' }, // i18n
] as const;

/** Whether a path is one of the Settings pages. */
export function isSettingsPath(path: string): boolean {
    return SETTINGS_TABS.some(
        (tab) => path === tab.href || path.startsWith(tab.href + '/'),
    );
}

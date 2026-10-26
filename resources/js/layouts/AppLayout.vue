<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import BrandMark from '@/components/BrandMark.vue';
import GlobalSearch from '@/components/GlobalSearch.vue';
import NotificationBell from '@/components/NotificationBell.vue';
import PhoneMenu from '@/components/PhoneMenu.vue';
import QuickEntry from '@/components/QuickEntry.vue';
import KButton from '@/components/k/KButton.vue';
import FeedbackModal from '@/components/FeedbackModal.vue';
import KFeedbackHost from '@/components/k/KFeedbackHost.vue';
import KIcon from '@/components/k/KIcon.vue';
import KModal from '@/components/k/KModal.vue';
import { useAppearance } from '@/composables/useAppearance';
import { useIdleLogout } from '@/composables/useIdleLogout';
import { useLiveRefresh } from '@/composables/useLiveRefresh';
import { usePopover } from '@/composables/usePopover';
import { locale, switchLocale, t } from '@/lib/i18n';
import { postJson } from '@/lib/http';
import { drop, forgetPages, offline, startOffline } from '@/lib/offline';
import { quickOpen } from '@/lib/quick-entry';
import { sectionOf } from '@/lib/sections';
import { SETTINGS_TABS, isSettingsPath } from '@/lib/settings-tabs';

const page = usePage();
const user = computed(() => page.props.auth.user);
// Beside the name: the Owner's app, or the team's (Accountant, Viewer…).
const roleBadge = computed(() =>
    user.value.role === 'Owner'
        ? 'Owner'
        : user.value.role === 'Client'
          ? 'Client'
          : 'Team',
);
const can = computed(() => page.props.auth.can ?? {});

type NavItem = { label: string; href: string; icon: string; perm: string };

const groups: { title?: string; items: NavItem[] }[] = [
    {
        items: [
            {
                label: 'ডেস্ক',
                href: '/dashboard',
                icon: 'desk',
                perm: 'dashboard.view',
            },
            {
                label: 'Report / P&L',
                href: '/reports',
                icon: 'chart',
                perm: 'reports.view',
            },
        ],
    }, // i18n
    {
        title: 'টাকা', // i18n
        items: [
            {
                label: 'টাকা কোথায়',
                href: '/wallets',
                icon: 'wallet',
                perm: 'bdt.view',
            }, // i18n
            {
                label: 'Payment (আয়)',
                href: '/payments',
                icon: 'down',
                perm: 'bdt.view',
            }, // i18n
            { label: 'খরচ', href: '/expenses', icon: 'bin', perm: 'bdt.view' }, // i18n
            {
                label: 'Dollar ও Card',
                href: '/dollars',
                icon: 'dollar',
                perm: 'usd.view',
            }, // i18n
            {
                label: 'খাতা (সব লেনদেন)',
                href: '/bdt',
                icon: 'list',
                perm: 'bdt.view',
            }, // i18n
        ],
    },
    {
        title: 'Client ও Ads', // i18n
        items: [
            {
                label: 'Client',
                href: '/clients',
                icon: 'people',
                perm: 'clients.view',
            },
            {
                label: 'Invoice',
                href: '/invoices',
                icon: 'doc',
                perm: 'clients.view',
            },
            {
                label: 'Campaign',
                href: '/campaigns',
                icon: 'pulse',
                perm: 'runs.view',
            },
        ],
    },
    {
        title: 'অফিস', // i18n
        items: [
            {
                label: 'কাজ ও reminder',
                href: '/tasks',
                icon: 'list',
                perm: 'tasks.view',
            }, // i18n
            {
                label: 'Payroll',
                href: '/payroll',
                icon: 'user',
                perm: 'payroll.view',
            },
        ],
    },
];

// Everything about running the app sits behind one Settings link.
const settingsHref = computed(
    () => SETTINGS_TABS.find((tab) => can.value[tab.perm])?.href ?? null,
);

// Pages the search box can jump to: the menu plus the Settings tabs.
const searchPages = computed(() => [
    ...visible.value.flatMap((g) => g.items),
    ...SETTINGS_TABS.filter((tab) => can.value[tab.perm]),
]);

const visible = computed(() =>
    groups
        .map((g) => ({ ...g, items: g.items.filter((i) => can.value[i.perm]) }))
        .filter((g) => g.items.length),
);

const canQuick = computed(() =>
    ['usd.create', 'bdt.create', 'fx.create'].some((p) => can.value[p]),
);

// The phone's bottom bar: desk, clients, (new entry), the book, and the full menu. A place
// this person may not see falls away; the slot in the middle is the new-entry button.
const tabs = computed(() => {
    const want = [
        {
            label: 'ডেস্ক',
            href: '/dashboard',
            icon: 'desk',
            perm: 'dashboard.view',
        }, // i18n
        {
            label: 'Client',
            href: '/clients',
            icon: 'people',
            perm: 'clients.view',
        },
        { label: 'নতুন', href: null, icon: 'plus', perm: '' }, // i18n
        { label: 'খাতা', href: '/bdt', icon: 'taka', perm: 'bdt.view' }, // i18n
        { label: 'মেনু', href: null, icon: 'menu', perm: '' }, // i18n
    ];

    return want.filter((tab, i) =>
        i === 2 ? canQuick.value : !tab.perm || can.value[tab.perm],
    );
});

const path = computed(() => page.url.split('?')[0]);
const isActive = (href: string) =>
    path.value === href ||
    path.value.startsWith(href + '/') ||
    (sectionOf(href) !== null && sectionOf(href) === sectionOf(path.value)) ||
    (href === settingsHref.value && isSettingsPath(path.value));

// The switch always names the other language, written in that language.
const otherLanguage = computed(() => (locale.value === 'en' ? 'বাং' : 'EN')); // i18n
const switchLabel = computed(
    () => (locale.value === 'en' ? 'বাংলায় দেখুন' : 'Switch to English'), // i18n
);

const drawer = ref(false);
// Phones get the sheet of tiles; the side drawer stays for a tablet in portrait.
const menuSheet = ref(false);
watch(path, () => ((drawer.value = false), (menuSheet.value = false)));

const quick = quickOpen;

const userAnchor = ref<HTMLElement | null>(null);
const userPanel = ref<HTMLElement | null>(null);
const userMenu = usePopover(userAnchor, userPanel, { align: 'end' });
const feedbackOpen = ref(false);

const { resolvedAppearance, updateAppearance } = useAppearance();
const { warning, secondsLeft, stay } = useIdleLogout();

async function logout(): Promise<void> {
    forgetPages();
    // The app first stops this phone getting this person's notifications.
    await bridge()
        ?.callHandler('signingOut')
        .catch(() => undefined);
    router.post('/logout');
}

// No internet: pages already opened still open; quick entries wait on the phone.
onMounted(() => {
    startOffline(
        () => user.value?.id ?? null,
        () => router.reload(),
    );
    markOpenedNotice();
});
// Figures keep up by themselves: fetched again every minute, and when the app comes back.
useLiveRefresh();
router.on('navigate', () => markOpenedNotice());

/**
 * Opened from a phone notification (…?n=<id>): that notification is read here
 * too, and the address loses the n= again.
 */
function markOpenedNotice(): void {
    const url = new URL(window.location.href);
    const id = url.searchParams.get('n');

    if (!id || !/^[0-9a-f-]{36}$/i.test(id)) {
        return;
    }

    url.searchParams.delete('n');
    window.history.replaceState(
        window.history.state,
        '',
        url.pathname + url.search + url.hash,
    );
    void postJson(`/notifications/${id}/read`)
        .then(() => window.dispatchEvent(new Event('notifications:changed')))
        .catch(() => undefined);
}
const mine = computed(() =>
    offline.waiting.filter((w) => w.user === user.value?.id),
);
const showWaiting = ref(false);

/** Inside the Android app: its own settings (lock, SMS forwarding). */
const inApp =
    typeof navigator !== 'undefined' &&
    /KlokFinanceApp/.test(navigator.userAgent);

function bridge() {
    return (
        window as unknown as {
            flutter_inappwebview?: {
                callHandler: (name: string) => Promise<unknown>;
            };
        }
    ).flutter_inappwebview;
}

function appSettings(): void {
    void bridge()?.callHandler('appSettings');
}
</script>

<template>
    <div
        class="app-shell min-h-dvh bg-paper text-ink lg:grid lg:grid-cols-[248px_1fr]"
    >
        <!-- Rail -->
        <aside
            class="no-print fixed inset-y-0 left-0 z-50 flex w-[264px] flex-col bg-rail text-rail-ink transition-transform lg:sticky lg:top-0 lg:h-dvh lg:w-auto lg:translate-x-0"
            :class="drawer ? 'translate-x-0' : '-translate-x-full'"
        >
            <div class="flex h-[68px] items-center justify-between px-5">
                <Link href="/dashboard"
                    ><BrandMark light :badge="roleBadge"
                /></Link>
                <button
                    type="button"
                    class="-mr-2 grid size-10 place-items-center rounded-lg text-rail-dim hover:bg-rail-2 hover:text-white lg:hidden"
                    :aria-label="t('মেনু বন্ধ করুন')"
                    @click="drawer = false"
                >
                    <KIcon name="close" :size="18" />
                </button>
            </div>

            <nav
                class="flex-1 [scrollbar-width:none] overflow-y-auto px-3 pb-6 hover:[scrollbar-width:thin]"
                :aria-label="t('মূল মেনু')"
            >
                <div
                    v-for="(group, gi) in visible"
                    :key="gi"
                    class="mt-4 first:mt-2"
                >
                    <p
                        v-if="group.title"
                        class="mb-1 px-3 text-[13px] font-medium text-rail-dim"
                    >
                        {{ t(group.title) }}
                    </p>
                    <Link
                        v-for="item in group.items"
                        :key="item.href"
                        :href="item.href"
                        class="group relative flex min-h-10 items-center gap-3 rounded-lg px-3 py-1.5 text-[15px] font-medium transition-colors lg:min-h-9"
                        :class="
                            isActive(item.href)
                                ? 'bg-rail-2 text-white'
                                : 'text-rail-ink/80 hover:bg-rail-2/60 hover:text-white'
                        "
                    >
                        <span
                            v-if="isActive(item.href)"
                            class="absolute top-2 bottom-2 -left-3 w-[3px] rounded-r bg-brand"
                        />
                        <KIcon
                            :name="item.icon"
                            :size="18"
                            :class="
                                isActive(item.href)
                                    ? 'text-brand'
                                    : 'text-rail-dim group-hover:text-rail-ink'
                            "
                        />
                        {{ t(item.label) }}
                    </Link>
                </div>
            </nav>

            <div v-if="settingsHref" class="px-3 pb-3">
                <Link
                    :href="settingsHref"
                    class="group relative flex min-h-10 items-center gap-3 rounded-lg px-3 py-1.5 text-[15px] font-medium transition-colors lg:min-h-9"
                    :class="
                        isActive(settingsHref)
                            ? 'bg-rail-2 text-white'
                            : 'text-rail-ink/80 hover:bg-rail-2/60 hover:text-white'
                    "
                >
                    <span
                        v-if="isActive(settingsHref)"
                        class="absolute top-2 bottom-2 -left-3 w-[3px] rounded-r bg-brand"
                    />
                    <KIcon
                        name="gear"
                        :size="18"
                        :class="
                            isActive(settingsHref)
                                ? 'text-brand'
                                : 'text-rail-dim group-hover:text-rail-ink'
                        "
                    />
                    Settings
                </Link>
            </div>

            <div
                class="border-t border-rail-2 px-5 py-4 text-[13px] text-rail-dim"
            >
                <p class="flex items-center gap-1.5">
                    <KIcon name="shield" :size="14" />
                    {{ t('সব পরিবর্তন log হয়') }}
                </p>
            </div>
        </aside>

        <div
            v-if="drawer"
            class="k-fade fixed inset-0 z-40 bg-black/40 lg:hidden"
            @click="drawer = false"
        />

        <!-- Main -->
        <div class="flex min-w-0 flex-col">
            <header
                class="no-print sticky top-0 z-30 flex h-[60px] min-w-0 items-center gap-1 border-b border-line bg-paper/90 px-3 backdrop-blur sm:gap-3 sm:px-6 lg:h-[68px] lg:px-8"
            >
                <button
                    type="button"
                    class="-ml-1 grid size-10 place-items-center rounded-lg hover:bg-sunken lg:hidden"
                    :aria-label="t('মেনু')"
                    @click="inApp ? (menuSheet = true) : (drawer = true)"
                >
                    <KIcon name="menu" :size="20" />
                </button>
                <!-- The brand gives way first, so the buttons at the right always fit. -->
                <Link href="/dashboard" class="shrink-0 lg:hidden"
                    ><BrandMark :size="28" compact :badge="roleBadge"
                /></Link>

                <span
                    class="ml-auto flex shrink-0 justify-end md:ml-0 md:flex-1 md:justify-start"
                >
                    <GlobalSearch :pages="searchPages" />
                </span>

                <span v-if="canQuick" class="hidden sm:block">
                    <KButton
                        variant="primary"
                        icon="plus"
                        @click="quick = true"
                        >{{ t('নতুন entry') }}</KButton
                    >
                </span>

                <NotificationBell />

                <!-- On a phone these two sit in the user menu, so the name in the bar has room. -->
                <button
                    type="button"
                    class="hidden h-10 rounded-lg px-2.5 text-[14px] font-bold text-ink-2 hover:bg-sunken hover:text-ink sm:block"
                    :aria-label="
                        locale === 'en' ? 'বাংলায় দেখুন' : 'Switch to English' // i18n
                    "
                    @click="switchLocale(locale === 'en' ? 'bn' : 'en')"
                >
                    {{ otherLanguage }}
                </button>

                <button
                    type="button"
                    class="hidden h-10 min-w-10 shrink-0 flex-col items-center justify-center gap-0.5 rounded-lg px-1.5 text-ink-2 hover:bg-sunken hover:text-ink sm:flex"
                    :aria-label="
                        resolvedAppearance === 'dark'
                            ? 'Light mode'
                            : 'Dark mode'
                    "
                    @click="
                        updateAppearance(
                            resolvedAppearance === 'dark' ? 'light' : 'dark',
                        )
                    "
                >
                    <KIcon
                        :name="resolvedAppearance === 'dark' ? 'sun' : 'moon'"
                        :size="16"
                    />
                    <span class="text-[13px] leading-none">{{
                        resolvedAppearance === 'dark' ? 'Light' : 'Dark'
                    }}</span>
                </button>

                <div ref="userAnchor">
                    <button
                        type="button"
                        class="flex h-10 items-center gap-2.5 rounded-[10px] pr-2 pl-1 hover:bg-sunken"
                        :aria-label="t('আমার account')"
                        :aria-expanded="userMenu.open.value"
                        @click="userMenu.toggle()"
                    >
                        <span
                            class="grid size-8 place-items-center rounded-lg bg-ink text-[14px] font-bold text-paper"
                            >{{ user.name.slice(0, 1).toUpperCase() }}</span
                        >
                        <span
                            class="hidden max-w-[160px] text-left leading-tight md:block"
                        >
                            <span
                                class="block truncate text-[14px] font-semibold"
                                >{{ user.name }}</span
                            >
                            <span
                                v-if="user.role"
                                class="block truncate text-[13px] text-ink-3"
                                >{{ user.role }}</span
                            >
                        </span>
                    </button>
                    <Teleport to="body">
                        <div
                            v-if="userMenu.open.value"
                            ref="userPanel"
                            :style="userMenu.style.value"
                            class="popover k-rise w-60 p-1.5"
                        >
                            <div class="px-3 py-2.5">
                                <p class="truncate text-[14.5px] font-semibold">
                                    {{ user.name }}
                                </p>
                                <p class="truncate text-[13px] text-ink-3">
                                    {{ user.email }}
                                </p>
                            </div>
                            <div class="my-1 h-px bg-line" />
                            <Link
                                href="/settings/profile"
                                class="flex min-h-10 items-center gap-2.5 rounded-lg px-3 py-2 text-[14.5px] hover:bg-sunken"
                                @click="userMenu.close()"
                                ><KIcon
                                    name="user"
                                    :size="16"
                                    class="text-ink-3"
                                />Profile</Link
                            >
                            <Link
                                href="/settings/security"
                                class="flex min-h-10 items-center gap-2.5 rounded-lg px-3 py-2 text-[14.5px] hover:bg-sunken"
                                @click="userMenu.close()"
                            >
                                <KIcon
                                    name="lock"
                                    :size="16"
                                    class="text-ink-3"
                                />{{ t('Password ও 2FA') }}
                                <span
                                    v-if="!user.two_factor_enabled"
                                    class="ml-auto size-2 rounded-full bg-wait"
                                    :title="t('2FA চালু নেই')"
                                />
                            </Link>
                            <button
                                v-if="inApp"
                                type="button"
                                class="flex min-h-10 w-full items-center gap-2.5 rounded-lg px-3 py-2 text-[14.5px] hover:bg-sunken"
                                @click="
                                    userMenu.close();
                                    appSettings();
                                "
                            >
                                <KIcon
                                    name="shield"
                                    :size="16"
                                    class="text-ink-3"
                                />{{ t('App-এর setting') }}
                            </button>
                            <button
                                type="button"
                                class="flex min-h-10 w-full items-center gap-2.5 rounded-lg px-3 py-2 text-[14.5px] hover:bg-sunken"
                                @click="
                                    userMenu.close();
                                    feedbackOpen = true;
                                "
                            >
                                <KIcon
                                    name="rocket"
                                    :size="16"
                                    class="text-ink-3"
                                />{{ t('Feature request ও পরামর্শ') }}
                            </button>
                            <button
                                type="button"
                                class="flex min-h-10 w-full items-center gap-2.5 rounded-lg px-3 py-2 text-[14.5px] hover:bg-sunken sm:hidden"
                                @click="
                                    userMenu.close();
                                    switchLocale(locale === 'en' ? 'bn' : 'en');
                                "
                            >
                                <span
                                    class="grid w-4 place-items-center text-[13px] font-bold text-ink-3"
                                    >{{ otherLanguage }}</span
                                >{{ switchLabel }}
                            </button>
                            <button
                                type="button"
                                class="flex min-h-10 w-full items-center gap-2.5 rounded-lg px-3 py-2 text-[14.5px] hover:bg-sunken sm:hidden"
                                @click="
                                    updateAppearance(
                                        resolvedAppearance === 'dark'
                                            ? 'light'
                                            : 'dark',
                                    )
                                "
                            >
                                <KIcon
                                    :name="
                                        resolvedAppearance === 'dark'
                                            ? 'sun'
                                            : 'moon'
                                    "
                                    :size="16"
                                    class="text-ink-3"
                                />{{
                                    resolvedAppearance === 'dark'
                                        ? 'Light mode'
                                        : 'Dark mode'
                                }}
                            </button>
                            <div class="my-1 h-px bg-line" />
                            <button
                                type="button"
                                class="flex min-h-10 w-full items-center gap-2.5 rounded-lg px-3 py-2 text-[14.5px] text-out hover:bg-out-soft"
                                @click="logout"
                            >
                                <KIcon name="out" :size="16" />Sign out
                            </button>
                        </div>
                    </Teleport>
                </div>
            </header>

            <main
                class="mx-auto w-full max-w-[1320px] flex-1 px-4 pt-6 pb-28 sm:px-6 lg:px-8 lg:pt-8"
            >
                <div
                    v-if="!offline.online || mine.length"
                    class="no-print mb-4 flex flex-wrap items-center gap-x-3 gap-y-2 rounded-xl border border-wait/40 bg-wait/10 px-4 py-2.5 text-[14.5px]"
                    role="status"
                >
                    <span
                        v-if="!offline.online"
                        class="flex min-w-0 items-center gap-2 font-semibold"
                        ><KIcon name="warn" :size="16" class="text-wait" />{{
                            t('নেট নেই — আগে খোলা পাতা দেখা যাচ্ছে')
                        }}</span
                    >
                    <KButton
                        v-if="mine.length"
                        size="sm"
                        variant="plain"
                        icon="clock"
                        @click="showWaiting = true"
                        >{{
                            t('{count} টা entry জমা হওয়ার অপেক্ষায়', {
                                count: mine.length,
                            })
                        }}</KButton
                    >
                </div>
                <slot />
            </main>
        </div>

        <!-- Phone: the four places a day's work happens, and the new entry raised in the middle -->
        <!-- Inside the app only: the browser on a phone keeps the web layout -->
        <nav
            v-if="inApp"
            class="no-print fixed inset-x-0 bottom-0 z-30 border-t border-line bg-paper/92 pb-[env(safe-area-inset-bottom)] backdrop-blur lg:hidden"
            :aria-label="t('মূল পাতা')"
        >
            <div
                class="mx-auto grid h-[64px] max-w-[560px] grid-cols-5 items-end"
            >
                <template v-for="(tab, i) in tabs" :key="tab.href ?? tab.label">
                    <button
                        v-if="i === 2 && canQuick"
                        type="button"
                        class="k-accent mx-auto mb-2.5 flex h-13 w-13 flex-col items-center justify-center rounded-2xl"
                        :aria-label="t('নতুন entry')"
                        @click="quick = true"
                    >
                        <KIcon name="plus" :size="22" />
                        <span class="text-[11.5px] leading-none font-bold">{{
                            t('নতুন')
                        }}</span>
                    </button>
                    <component
                        :is="tab.href ? Link : 'button'"
                        v-else
                        :href="tab.href"
                        type="button"
                        class="flex h-full flex-col items-center justify-center gap-1 pb-1.5 text-[12.5px] font-semibold"
                        :class="
                            tab.href && isActive(tab.href)
                                ? 'text-brand-ink'
                                : 'text-ink-3'
                        "
                        @click="tab.href ? undefined : (menuSheet = true)"
                    >
                        <span
                            class="grid h-7 w-11 place-items-center rounded-full transition-colors"
                            :class="
                                tab.href &&
                                isActive(tab.href) &&
                                'bg-brand-soft'
                            "
                            ><KIcon :name="tab.icon" :size="18"
                        /></span>
                        {{ t(tab.label) }}
                    </component>
                </template>
            </div>
        </nav>

        <!-- Phone browser: quick entry one thumb away, as on the web -->
        <button
            v-if="canQuick && !inApp"
            type="button"
            class="k-accent no-print fixed right-4 bottom-[calc(16px+env(safe-area-inset-bottom))] z-30 flex h-14 items-center gap-2 rounded-2xl px-5 text-[16px] font-bold sm:hidden"
            @click="quick = true"
        >
            <KIcon name="plus" :size="20" /> {{ t('নতুন entry') }}
        </button>

        <QuickEntry v-if="canQuick" v-model="quick" />
        <PhoneMenu
            v-model="menuSheet"
            :groups="visible"
            :settings-href="settingsHref"
            :is-active="isActive"
            :user="user"
        />

        <KModal
            v-model="showWaiting"
            width="md"
            :title="t('জমা হওয়ার অপেক্ষায়')"
            :subtitle="
                t('নেট এলে নিজে থেকে জমা হবে। ভুল থাকলে মুছে আবার লিখুন।')
            "
        >
            <ul class="flex flex-col divide-y divide-line">
                <li
                    v-for="item in mine"
                    :key="item.id"
                    class="flex items-start gap-3 py-2.5"
                >
                    <div class="min-w-0 flex-1">
                        <p class="text-[15px] font-medium">
                            {{ item.job }} ·
                            <span class="num">{{
                                item.data.amount ?? item.data.usd ?? ''
                            }}</span>
                        </p>
                        <p class="text-[13.5px] text-ink-3">
                            <span class="num">{{ item.data.entry_date }}</span>
                            {{ item.data.description ?? '' }}
                        </p>
                        <p v-if="item.error" class="text-[13.5px] text-out">
                            {{ item.error }}
                        </p>
                    </div>
                    <KButton variant="quiet" size="sm" @click="drop(item.id)">{{
                        t('মুছুন')
                    }}</KButton>
                </li>
            </ul>
        </KModal>
        <FeedbackModal v-model="feedbackOpen" />
        <KFeedbackHost />

        <KModal
            :model-value="warning"
            width="sm"
            :title="t('আপনি কি এখনো আছেন?')"
            @update:model-value="stay"
        >
            <p class="text-[15px] text-ink-2">
                {{ t('কিছুক্ষণ কোনো কাজ হয়নি। নিরাপত্তার জন্য') }}
                <span class="num font-semibold text-ink">{{
                    secondsLeft
                }}</span>
                {{ t('সেকেন্ড পর sign out হয়ে যাবে।') }}
            </p>
            <template #footer>
                <KButton variant="quiet" @click="logout">{{
                    t('এখনই sign out')
                }}</KButton>
                <KButton variant="primary" data-autofocus @click="stay">{{
                    t('চালিয়ে যান')
                }}</KButton>
            </template>
        </KModal>
    </div>
</template>

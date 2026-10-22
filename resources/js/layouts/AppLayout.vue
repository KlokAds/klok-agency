<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import BrandMark from '@/components/BrandMark.vue';
import NotificationBell from '@/components/NotificationBell.vue';
import QuickEntry from '@/components/QuickEntry.vue';
import KButton from '@/components/k/KButton.vue';
import KFeedbackHost from '@/components/k/KFeedbackHost.vue';
import KIcon from '@/components/k/KIcon.vue';
import KModal from '@/components/k/KModal.vue';
import { useAppearance } from '@/composables/useAppearance';
import { useIdleLogout } from '@/composables/useIdleLogout';
import { usePopover } from '@/composables/usePopover';
import { locale, switchLocale, t } from '@/lib/i18n';

const page = usePage();
const user = computed(() => page.props.auth.user);
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
        ],
    }, // i18n
    {
        title: 'খাতা', // i18n
        items: [
            {
                label: 'USD খাতা',
                href: '/usd',
                icon: 'dollar',
                perm: 'usd.view',
            }, // i18n
            { label: 'BDT খাতা', href: '/bdt', icon: 'taka', perm: 'bdt.view' }, // i18n
            { label: 'FX Batch', href: '/fx', icon: 'swap', perm: 'fx.view' },
            {
                label: 'Card মেলানো',
                href: '/reconcile',
                icon: 'scale',
                perm: 'reconcile.view',
            }, // i18n
        ],
    },
    {
        title: 'ব্যবসা', // i18n
        items: [
            {
                label: 'Client',
                href: '/clients',
                icon: 'people',
                perm: 'clients.view',
            },
            {
                label: 'Ad চালানো',
                href: '/runs',
                icon: 'rocket',
                perm: 'runs.view',
            }, // i18n
            {
                label: 'Ad account',
                href: '/ad-accounts',
                icon: 'signal',
                perm: 'runs.view',
            },
            {
                label: 'কাজ ও reminder',
                href: '/tasks',
                icon: 'list',
                perm: 'tasks.view',
            }, // i18n
            {
                label: 'Ad Platform',
                href: '/platforms',
                icon: 'signal',
                perm: 'platforms.view',
            },
            {
                label: 'Payroll',
                href: '/payroll',
                icon: 'wallet',
                perm: 'payroll.view',
            },
            {
                label: 'Report / P&L',
                href: '/reports',
                icon: 'chart',
                perm: 'reports.view',
            },
        ],
    },
    {
        title: 'নিয়ন্ত্রণ', // i18n
        items: [
            {
                label: 'User',
                href: '/admin/users',
                icon: 'user',
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
            {
                label: 'Reminder ও Pilot',
                href: '/admin/agency',
                icon: 'bell',
                perm: 'settings.view',
            }, // i18n
            {
                label: 'Settings',
                href: '/admin/settings',
                icon: 'gear',
                perm: 'settings.view',
            },
        ],
    },
];

const visible = computed(() =>
    groups
        .map((g) => ({ ...g, items: g.items.filter((i) => can.value[i.perm]) }))
        .filter((g) => g.items.length),
);

const canQuick = computed(() =>
    ['usd.create', 'bdt.create', 'fx.create'].some((p) => can.value[p]),
);

const path = computed(() => page.url.split('?')[0]);
const isActive = (href: string) =>
    path.value === href || path.value.startsWith(href + '/');

// The switch always names the other language, written in that language.
const otherLanguage = computed(() => (locale.value === 'en' ? 'বাং' : 'EN')); // i18n

const drawer = ref(false);
watch(path, () => (drawer.value = false));

const quick = ref(false);

const userAnchor = ref<HTMLElement | null>(null);
const userPanel = ref<HTMLElement | null>(null);
const userMenu = usePopover(userAnchor, userPanel, { align: 'end' });

const { resolvedAppearance, updateAppearance } = useAppearance();
const { warning, secondsLeft, stay } = useIdleLogout();

function logout(): void {
    router.post('/logout');
}
</script>

<template>
    <div class="min-h-dvh bg-paper text-ink lg:grid lg:grid-cols-[248px_1fr]">
        <!-- Rail -->
        <aside
            class="fixed inset-y-0 left-0 z-50 flex w-[264px] flex-col bg-rail text-rail-ink transition-transform lg:sticky lg:top-0 lg:h-dvh lg:w-auto lg:translate-x-0"
            :class="drawer ? 'translate-x-0' : '-translate-x-full'"
        >
            <div class="flex h-[68px] items-center px-5">
                <Link href="/dashboard"><BrandMark light /></Link>
            </div>

            <nav
                class="flex-1 overflow-y-auto px-3 pb-6"
                :aria-label="t('মূল মেনু')"
            >
                <div
                    v-for="(group, gi) in visible"
                    :key="gi"
                    class="mt-5 first:mt-2"
                >
                    <p
                        v-if="group.title"
                        class="mb-1.5 flex items-center gap-2 px-3 text-[10.5px] font-bold tracking-[0.18em] text-rail-dim uppercase"
                    >
                        <span class="num">0{{ gi }}</span>
                        <span class="h-px flex-1 bg-rail-2" />
                        {{ t(group.title) }}
                    </p>
                    <Link
                        v-for="item in group.items"
                        :key="item.href"
                        :href="item.href"
                        class="group relative flex items-center gap-3 rounded-[10px] px-3 py-2 text-[14px] font-medium transition-colors"
                        :class="
                            isActive(item.href)
                                ? 'bg-rail-2 text-white'
                                : 'text-rail-ink/80 hover:bg-rail-2/60 hover:text-white'
                        "
                    >
                        <span
                            v-if="isActive(item.href)"
                            class="absolute top-2 bottom-2 -left-3 w-[3px] rounded-r bg-[#2dd4bf]"
                        />
                        <KIcon
                            :name="item.icon"
                            :size="18"
                            :class="
                                isActive(item.href)
                                    ? 'text-[#2dd4bf]'
                                    : 'text-rail-dim group-hover:text-rail-ink'
                            "
                        />
                        {{ t(item.label) }}
                    </Link>
                </div>
            </nav>

            <div
                class="border-t border-rail-2 px-5 py-4 text-[11.5px] text-rail-dim"
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
                class="no-print sticky top-0 z-30 flex h-[60px] items-center gap-3 border-b border-line bg-paper/90 px-4 backdrop-blur sm:px-6 lg:h-[68px] lg:px-8"
            >
                <button
                    type="button"
                    class="-ml-1 grid size-10 place-items-center rounded-lg hover:bg-sunken lg:hidden"
                    :aria-label="t('মেনু')"
                    @click="drawer = true"
                >
                    <KIcon name="menu" :size="20" />
                </button>
                <Link href="/dashboard" class="lg:hidden"
                    ><BrandMark :size="28" compact
                /></Link>

                <span class="flex-1" />

                <span v-if="canQuick" class="hidden sm:block">
                    <KButton
                        variant="primary"
                        icon="plus"
                        @click="quick = true"
                        >{{ t('নতুন entry') }}</KButton
                    >
                </span>

                <NotificationBell />

                <button
                    type="button"
                    class="h-10 rounded-lg px-2.5 text-[13px] font-bold text-ink-2 hover:bg-sunken hover:text-ink"
                    :aria-label="
                        locale === 'en' ? 'বাংলায় দেখুন' : 'Switch to English' // i18n
                    "
                    @click="switchLocale(locale === 'en' ? 'bn' : 'en')"
                >
                    {{ otherLanguage }}
                </button>

                <button
                    type="button"
                    class="grid size-10 place-items-center rounded-lg text-ink-2 hover:bg-sunken hover:text-ink"
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
                    />
                </button>

                <div ref="userAnchor">
                    <button
                        type="button"
                        class="flex items-center gap-2.5 rounded-[10px] py-1 pr-2 pl-1 hover:bg-sunken"
                        @click="userMenu.toggle()"
                    >
                        <span
                            class="grid size-8 place-items-center rounded-lg bg-ink text-[13px] font-bold text-paper"
                            >{{ user.name.slice(0, 1).toUpperCase() }}</span
                        >
                        <span class="hidden text-left leading-tight md:block">
                            <span class="block text-[13px] font-semibold">{{
                                user.name
                            }}</span>
                            <span class="block text-[11px] text-ink-3">{{
                                user.role ?? '—'
                            }}</span>
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
                                <p class="text-[13.5px] font-semibold">
                                    {{ user.name }}
                                </p>
                                <p class="truncate text-[12px] text-ink-3">
                                    {{ user.email }}
                                </p>
                            </div>
                            <div class="my-1 h-px bg-line" />
                            <Link
                                href="/settings/profile"
                                class="flex items-center gap-2.5 rounded-lg px-3 py-2 text-[13.5px] hover:bg-sunken"
                                @click="userMenu.close()"
                                ><KIcon
                                    name="user"
                                    :size="16"
                                    class="text-ink-3"
                                />Profile</Link
                            >
                            <Link
                                href="/settings/security"
                                class="flex items-center gap-2.5 rounded-lg px-3 py-2 text-[13.5px] hover:bg-sunken"
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
                            <div class="my-1 h-px bg-line" />
                            <button
                                type="button"
                                class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-[13.5px] text-out hover:bg-out-soft"
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
                <slot />
            </main>
        </div>

        <!-- Phone: quick entry always one thumb away -->
        <button
            v-if="canQuick"
            type="button"
            class="no-print fixed right-4 bottom-[calc(16px+env(safe-area-inset-bottom))] z-30 flex h-14 items-center gap-2 rounded-2xl bg-brand px-5 text-[15px] font-bold text-white shadow-pop sm:hidden dark:text-rail"
            @click="quick = true"
        >
            <KIcon name="plus" :size="20" /> {{ t('নতুন entry') }}
        </button>

        <QuickEntry v-if="canQuick" v-model="quick" />
        <KFeedbackHost />

        <KModal
            :model-value="warning"
            width="sm"
            :title="t('আপনি কি এখনো আছেন?')"
            @update:model-value="stay"
        >
            <p class="text-[14px] text-ink-2">
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

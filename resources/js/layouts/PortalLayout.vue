<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import BrandMark from '@/components/BrandMark.vue';
import KFeedbackHost from '@/components/k/KFeedbackHost.vue';
import KIcon from '@/components/k/KIcon.vue';
import { useAppearance } from '@/composables/useAppearance';
import { locale, switchLocale, t } from '@/lib/i18n';

/**
 * The client portal's frame: the name with a "Client" tag, three places (home, campaigns,
 * invoices), and the person's own menu. Tabs sit on top on a computer and at the bottom on a
 * phone, where a thumb reaches them.
 */
const page = usePage();
const user = computed(() => page.props.auth.user);
const path = computed(() => page.url.split('?')[0]);
const linked = computed(() => Boolean(user.value?.client));
const unread = computed(() => Number(page.props.unread ?? 0));

const tabs = [
    { label: 'হোম', href: '/portal', icon: 'desk' }, // i18n
    { label: 'Campaign', href: '/portal/campaigns', icon: 'pulse' },
    { label: 'Invoice', href: '/portal/invoices', icon: 'doc' },
];
const active = (href: string) =>
    href === '/portal' ? path.value === '/portal' : path.value.startsWith(href);

const { resolvedAppearance, updateAppearance } = useAppearance();
const otherLanguage = computed(() => (locale.value === 'en' ? 'বাং' : 'EN')); // i18n

function bridge() {
    return (
        window as unknown as {
            flutter_inappwebview?: {
                callHandler: (name: string) => Promise<unknown>;
            };
        }
    ).flutter_inappwebview;
}

/** Inside the KlokOne app: its own settings (fingerprint / PIN lock). */
const inApp =
    typeof navigator !== 'undefined' &&
    /KlokFinanceApp/.test(navigator.userAgent);
function appSettings(): void {
    void bridge()?.callHandler('appSettings');
}

async function logout(): Promise<void> {
    await bridge()
        ?.callHandler('signingOut')
        .catch(() => undefined);
    router.post('/logout');
}
</script>

<template>
    <div class="min-h-dvh bg-paper pb-20 text-ink sm:pb-0">
        <header
            class="sticky top-0 z-30 border-b border-line bg-paper/90 backdrop-blur"
        >
            <div
                class="mx-auto flex h-[60px] max-w-5xl min-w-0 items-center gap-2 px-4 sm:h-[68px] sm:px-6"
            >
                <Link href="/portal" class="min-w-0 shrink"
                    ><BrandMark :size="30" compact badge="Client"
                /></Link>
                <nav
                    v-if="linked"
                    class="ml-6 hidden items-center gap-1 sm:flex"
                >
                    <Link
                        v-for="tab in tabs"
                        :key="tab.href"
                        :href="tab.href"
                        class="rounded-lg px-3 py-2 text-[14px] font-semibold transition-colors"
                        :class="
                            active(tab.href)
                                ? 'bg-brand-soft text-brand'
                                : 'text-ink-2 hover:bg-sunken'
                        "
                        >{{ t(tab.label) }}</Link
                    >
                </nav>
                <span class="flex-1" />
                <Link
                    v-if="linked"
                    href="/portal/notifications"
                    class="relative grid size-10 place-items-center rounded-lg text-ink-2 hover:bg-sunken"
                    :aria-label="t('Notification')"
                >
                    <KIcon name="bell" />
                    <span
                        v-if="unread > 0"
                        class="absolute top-1 right-1 grid min-w-4 place-items-center rounded-full bg-out px-1 text-[10px] font-bold text-white"
                        >{{ unread > 9 ? '9+' : unread }}</span
                    >
                </Link>
                <button
                    type="button"
                    class="hidden h-10 rounded-lg px-2.5 text-[13px] font-bold text-ink-2 hover:bg-sunken sm:block"
                    @click="switchLocale(locale === 'en' ? 'bn' : 'en')"
                >
                    {{ otherLanguage }}
                </button>
                <button
                    type="button"
                    class="hidden size-10 place-items-center rounded-lg text-ink-2 hover:bg-sunken sm:grid"
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
                <button
                    v-if="inApp"
                    type="button"
                    class="grid size-10 place-items-center rounded-lg text-ink-2 hover:bg-sunken"
                    :aria-label="t('App-এর setting')"
                    @click="appSettings"
                >
                    <KIcon name="shield" />
                </button>
                <Link
                    href="/settings/profile"
                    class="grid size-9 place-items-center rounded-lg bg-ink text-[13px] font-bold text-paper"
                    :title="user?.name"
                    >{{ user?.name?.slice(0, 1).toUpperCase() }}</Link
                >
                <button
                    type="button"
                    class="grid size-10 place-items-center rounded-lg text-ink-2 hover:bg-sunken"
                    aria-label="Sign out"
                    @click="logout"
                >
                    <KIcon name="out" />
                </button>
            </div>
        </header>

        <main class="mx-auto max-w-5xl px-4 py-6 sm:px-6 sm:py-8">
            <slot />
        </main>

        <!-- Phone: the three places at the bottom. -->
        <nav
            v-if="linked"
            class="fixed inset-x-0 bottom-0 z-30 grid grid-cols-3 border-t border-line bg-paper/95 pb-[env(safe-area-inset-bottom)] backdrop-blur sm:hidden"
        >
            <Link
                v-for="tab in tabs"
                :key="tab.href"
                :href="tab.href"
                class="flex flex-col items-center gap-0.5 py-2.5 text-[11.5px] font-semibold"
                :class="active(tab.href) ? 'text-brand' : 'text-ink-3'"
                ><KIcon :name="tab.icon" :size="21" />{{ t(tab.label) }}</Link
            >
        </nav>

        <KFeedbackHost />
    </div>
</template>

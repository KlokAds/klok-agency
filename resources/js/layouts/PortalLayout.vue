<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import BrandMark from '@/components/BrandMark.vue';
import FeedbackModal from '@/components/FeedbackModal.vue';
import OpenInApp from '@/components/OpenInApp.vue';
import KFeedbackHost from '@/components/k/KFeedbackHost.vue';
import KIcon from '@/components/k/KIcon.vue';
import { useAppearance } from '@/composables/useAppearance';
import { usePopover } from '@/composables/usePopover';
import { locale, switchLocale, t } from '@/lib/i18n';
import { forgetPages, offline, startOffline } from '@/lib/offline';

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
const switchLabel = computed(
    () => (locale.value === 'en' ? 'বাংলায় দেখুন' : 'Switch to English'), // i18n
);

// The person's menu: profile, sign-in safety, the app's own lock, sign out.
const menuAnchor = ref<HTMLElement | null>(null);
const menuPanel = ref<HTMLElement | null>(null);
const menu = usePopover(menuAnchor, menuPanel, { align: 'end' });
const twoFactorOff = computed(() => !user.value?.two_factor_enabled);
const feedback = ref(false);
const whatsapp = computed(
    () => (page.props.contact as { whatsapp?: string } | null)?.whatsapp,
);

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
    forgetPages();
    await bridge()
        ?.callHandler('signingOut')
        .catch(() => undefined);
    router.post('/logout');
}

// No internet: pages already opened still open, read from the phone.
onMounted(() =>
    startOffline(
        () => null,
        () => undefined,
    ),
);
</script>

<template>
    <!-- pb-24: the bottom tab bar never covers the last card on a phone. -->
    <div class="min-h-dvh bg-paper pb-24 text-ink sm:pb-0">
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
                <div ref="menuAnchor">
                    <button
                        type="button"
                        class="relative grid size-9 place-items-center rounded-lg bg-ink text-[13px] font-bold text-paper"
                        :aria-label="t('আমার account')"
                        @click="menu.toggle()"
                    >
                        {{ user?.name?.slice(0, 1).toUpperCase() }}
                        <span
                            v-if="twoFactorOff"
                            class="absolute -top-1 -right-1 size-3 rounded-full border-2 border-paper bg-wait"
                        />
                    </button>
                    <Teleport to="body">
                        <div
                            v-if="menu.open.value"
                            ref="menuPanel"
                            :style="menu.style.value"
                            class="popover k-rise w-64 p-1.5"
                        >
                            <div class="px-3 py-2.5">
                                <p class="text-[13.5px] font-semibold">
                                    {{ user?.name }}
                                </p>
                                <p class="truncate text-[12px] text-ink-3">
                                    {{ user?.email }}
                                </p>
                            </div>
                            <div class="my-1 h-px bg-line" />
                            <Link
                                href="/settings/profile"
                                class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-[13.5px] hover:bg-sunken"
                                @click="menu.close()"
                                ><KIcon
                                    name="user"
                                    :size="16"
                                    class="text-ink-3"
                                />Profile</Link
                            >
                            <p
                                class="px-3 pt-2.5 pb-1 text-[11px] font-bold tracking-wide text-ink-3 uppercase"
                            >
                                {{ t('নিরাপত্তা') }}
                            </p>
                            <Link
                                href="/settings/security"
                                class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-[13.5px] hover:bg-sunken"
                                @click="menu.close()"
                            >
                                <KIcon
                                    name="lock"
                                    :size="16"
                                    class="text-ink-3"
                                />{{ t('Password ও 2FA') }}
                                <span
                                    class="ml-auto rounded-full px-2 py-0.5 text-[11px] font-semibold"
                                    :class="
                                        twoFactorOff
                                            ? 'bg-wait-soft text-wait'
                                            : 'bg-in-soft text-in'
                                    "
                                    >{{
                                        twoFactorOff
                                            ? t('2FA বন্ধ')
                                            : t('2FA চালু')
                                    }}</span
                                >
                            </Link>
                            <button
                                v-if="inApp"
                                type="button"
                                class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-[13.5px] hover:bg-sunken"
                                @click="
                                    menu.close();
                                    appSettings();
                                "
                            >
                                <KIcon
                                    name="shield"
                                    :size="16"
                                    class="text-ink-3"
                                />{{ t('App lock — আঙুলের ছাপ / PIN') }}
                            </button>
                            <div class="my-1 h-px bg-line" />
                            <a
                                v-if="whatsapp"
                                :href="whatsapp"
                                target="_blank"
                                rel="noopener"
                                class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-[13.5px] hover:bg-sunken"
                                @click="menu.close()"
                                ><KIcon
                                    name="chat"
                                    :size="16"
                                    class="text-[#25D366]"
                                />{{ t('যোগাযোগ — WhatsApp') }}</a
                            >
                            <button
                                type="button"
                                class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-[13.5px] hover:bg-sunken"
                                @click="
                                    menu.close();
                                    feedback = true;
                                "
                            >
                                <KIcon
                                    name="rocket"
                                    :size="16"
                                    class="text-ink-3"
                                />{{ t('Feature request ও পরামর্শ') }}
                            </button>
                            <div class="my-1 h-px bg-line" />
                            <button
                                type="button"
                                class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-[13.5px] hover:bg-sunken sm:hidden"
                                @click="
                                    menu.close();
                                    switchLocale(locale === 'en' ? 'bn' : 'en');
                                "
                            >
                                <span
                                    class="grid w-4 place-items-center text-[11px] font-bold text-ink-3"
                                    >{{ otherLanguage }}</span
                                >{{ switchLabel }}
                            </button>
                            <button
                                type="button"
                                class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-[13.5px] hover:bg-sunken sm:hidden"
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
            </div>
        </header>

        <OpenInApp />
        <main class="mx-auto max-w-5xl px-4 py-6 sm:px-6 sm:py-8">
            <p
                v-if="!offline.online"
                class="mb-4 rounded-xl border border-wait/40 bg-wait/10 px-4 py-2.5 text-[13.5px] font-semibold"
                role="status"
            >
                {{ t('নেট নেই — আগে খোলা পাতা দেখা যাচ্ছে') }}
            </p>
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

        <FeedbackModal v-model="feedback" />
        <KFeedbackHost />
    </div>
</template>

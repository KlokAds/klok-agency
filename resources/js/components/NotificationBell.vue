<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import NotificationItem from '@/components/NotificationItem.vue';
import type { Notice } from '@/components/NotificationItem.vue';
import KButton from '@/components/k/KButton.vue';
import KIcon from '@/components/k/KIcon.vue';
import { usePopover } from '@/composables/usePopover';
import { toast } from '@/lib/feedback';
import { getJson } from '@/lib/http';
import { t } from '@/lib/i18n';

// The bell: unread count from the page, the latest few on open, and a
// quiet check every minute so new reminders show up without a reload.
const page = usePage();
const unread = ref(Number(page.props.unread ?? 0));
const items = ref<Notice[]>([]);
const loading = ref(false);

watch(
    () => page.props.unread,
    (value) => (unread.value = Number(value ?? 0)),
);

const anchor = ref<HTMLElement | null>(null);
const panel = ref<HTMLElement | null>(null);
const menu = usePopover(anchor, panel, { align: 'end' });

async function load(): Promise<void> {
    loading.value = true;
    try {
        const feed = await getJson<{ unread: number; items: Notice[] }>(
            '/notifications/feed',
        );
        const fresh = feed.items.filter(
            (n) => !n.read && !items.value.some((old) => old.id === n.id),
        );

        // Tell about new ones once, unless the list is open anyway.
        if (items.value.length && fresh.length && !menu.open.value) {
            toast(t(fresh[0].title, fresh[0].params), 'info');
        }

        items.value = feed.items;
        unread.value = feed.unread;
    } catch {
        // offline or signed out — try again on the next round
    } finally {
        loading.value = false;
    }
}

watch(menu.open, (open) => {
    if (open) {
        void load();
    }
});

function readAll(): void {
    router.post(
        '/notifications/read-all',
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                unread.value = 0;
                items.value = items.value.map((n) => ({ ...n, read: true }));
            },
        },
    );
}

function opened(item: Notice): void {
    menu.close();

    if (!item.read) {
        items.value = items.value.map((n) =>
            n.id === item.id ? { ...n, read: true } : n,
        );
        unread.value = Math.max(0, unread.value - 1);
        void fetch(`/notifications/${item.id}/read`, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-XSRF-TOKEN': decodeURIComponent(
                    document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
                ),
            },
            credentials: 'same-origin',
        });
    }
}

let timer: number | undefined;
// Read or removed elsewhere (the notifications page, a tap on the phone): count again now.
const refresh = () => void load();
onMounted(() => {
    void load();
    timer = window.setInterval(() => void load(), 60_000);
    window.addEventListener('notifications:changed', refresh);
});
onBeforeUnmount(() => {
    window.clearInterval(timer);
    window.removeEventListener('notifications:changed', refresh);
});

const badge = computed(() => (unread.value > 9 ? '9+' : String(unread.value)));

// Two tabs: what is new (unread), and everything recent. A read one leaves "new" at once.
const tab = ref<'new' | 'all'>('new');
const shown = computed(() =>
    tab.value === 'new' ? items.value.filter((n) => !n.read) : items.value,
);
</script>

<template>
    <div ref="anchor">
        <button
            type="button"
            class="flex h-10 shrink-0 flex-col items-center justify-center gap-0.5 rounded-lg px-1 text-ink-2 hover:bg-sunken hover:text-ink"
            @click="menu.toggle()"
        >
            <span class="relative">
                <KIcon name="bell" :size="16" />
                <span
                    v-if="unread > 0"
                    class="num absolute -top-1.5 -right-2.5 grid h-4 min-w-4 place-items-center rounded-full bg-out px-1 text-[12.5px] font-bold text-white"
                    >{{ badge }}</span
                >
            </span>
            <span class="text-[13px] leading-none max-sm:hidden">{{
                t('Notification')
            }}</span>
            <span class="text-[13px] leading-none sm:hidden">{{
                t('Alert')
            }}</span>
        </button>

        <Teleport to="body">
            <div
                v-if="menu.open.value"
                ref="panel"
                :style="menu.style.value"
                class="popover k-rise flex max-h-[70vh] w-[min(380px,calc(100vw-16px))] flex-col"
            >
                <div
                    class="flex min-h-[52px] items-center justify-between gap-3 border-b border-line py-2 pr-2 pl-4"
                >
                    <p class="text-[15px] font-bold">
                        {{ t('Notification') }}
                        <span
                            v-if="unread > 0"
                            class="num ml-1 rounded-md bg-out-soft px-1.5 py-0.5 text-[13px] font-semibold text-out"
                            >{{ unread }}</span
                        >
                    </p>
                    <KButton
                        v-if="unread > 0"
                        size="sm"
                        variant="plain"
                        icon="check"
                        @click="readAll"
                        >{{ t('সব পড়া হয়েছে') }}</KButton
                    >
                </div>
                <div class="flex gap-1 border-b border-line px-2 py-1.5">
                    <button
                        v-for="(label, key) in { new: t('নতুন'), all: t('সব') }"
                        :key="key"
                        type="button"
                        class="h-8 rounded-lg px-3 text-[13.5px] font-semibold transition-colors"
                        :class="
                            tab === key
                                ? 'bg-brand text-white'
                                : 'text-ink-2 hover:bg-sunken hover:text-ink'
                        "
                        @click="tab = key as 'new' | 'all'"
                    >
                        {{ label
                        }}<span
                            v-if="key === 'new' && unread > 0"
                            class="num ml-1 text-[13px] opacity-80"
                            >{{ unread }}</span
                        >
                    </button>
                </div>
                <div class="min-h-0 flex-1 overflow-y-auto p-1.5">
                    <p
                        v-if="!shown.length"
                        class="px-4 py-10 text-center text-[14px] text-ink-3"
                    >
                        {{
                            loading
                                ? t('লোড হচ্ছে…')
                                : tab === 'new'
                                  ? t('নতুন কিছু নেই — সব পড়া হয়েছে')
                                  : t('কোনো notification নেই')
                        }}
                    </p>
                    <NotificationItem
                        v-for="n in shown"
                        :key="n.id"
                        :item="n"
                        @open="opened"
                    />
                </div>
                <Link
                    href="/notifications"
                    class="flex min-h-11 items-center justify-center gap-1.5 border-t border-line px-4 text-[14px] font-semibold text-ink-2 hover:bg-sunken hover:text-ink"
                    @click="menu.close()"
                    >{{ t('সব দেখুন')
                    }}<KIcon name="arrow" :size="14" class="text-ink-3"
                /></Link>
            </div>
        </Teleport>
    </div>
</template>

<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import KIcon from '@/components/k/KIcon.vue';
import { t } from '@/lib/i18n';

/**
 * The app's menu: a sheet that rises from the bottom. The day's main pages sit on top as two
 * big cards; every other group is its own card with its own colour (money green, clients
 * purple, office amber), so a glance finds the right place. The open page is lit.
 */
type Item = { label: string; href: string; icon: string };

const props = defineProps<{
    groups: { title?: string; items: Item[] }[];
    settingsHref: string | null;
    isActive: (href: string) => boolean;
    user: { name: string; role?: string | null };
}>();
const open = defineModel<boolean>({ default: false });

const lead = computed(() => props.groups.find((g) => !g.title)?.items ?? []);
const titled = computed(() => props.groups.filter((g) => g.title));

// One colour a group, by its place in the menu: money, clients and ads, office, then the rest.
const TONES = [
    { bar: 'bg-in', badge: 'bg-in-soft text-in' },
    { bar: 'bg-brand', badge: 'bg-brand-soft text-brand-ink' },
    { bar: 'bg-wait', badge: 'bg-wait-soft text-wait' },
    { bar: 'bg-done', badge: 'bg-done-soft text-done' },
];
const tone = (i: number) => TONES[i % TONES.length];

// The sheet closes when a page is picked, and never scrolls the page under it.
watch(open, (on) => {
    document.body.style.overflow = on ? 'hidden' : '';
});
</script>

<template>
    <Teleport to="body">
        <div
            v-if="open"
            class="k-fade fixed inset-0 z-50 bg-black/55 backdrop-blur-[2px] lg:hidden"
            @click="open = false"
        />
        <section
            v-if="open"
            class="k-sheet fixed inset-x-0 bottom-0 z-50 flex max-h-[90dvh] flex-col rounded-t-[24px] border-t border-line-2 bg-paper pb-[env(safe-area-inset-bottom)] shadow-pop lg:hidden"
            role="dialog"
            :aria-label="t('মেনু')"
        >
            <div
                class="mx-auto mt-2.5 h-1 w-10 shrink-0 rounded-full bg-line-2"
            />
            <div class="flex items-center gap-2 px-5 pt-3 pb-1">
                <h2 class="min-w-0 flex-1 text-[19px] font-bold">
                    {{ t('মেনু') }}
                </h2>
                <Link
                    href="/settings/profile"
                    class="k-chip flex h-10 items-center gap-2 rounded-full pr-3.5 pl-1 text-[14px] font-semibold"
                    @click="open = false"
                >
                    <span
                        class="grid size-8 place-items-center rounded-full bg-brand text-[14px] font-bold text-white"
                        >{{ props.user.name.slice(0, 1).toUpperCase() }}</span
                    >
                    Profile
                </Link>
                <button
                    type="button"
                    class="k-chip grid size-10 place-items-center rounded-full"
                    :aria-label="t('মেনু বন্ধ করুন')"
                    @click="open = false"
                >
                    <KIcon name="close" :size="17" />
                </button>
            </div>

            <nav class="min-h-0 flex-1 overflow-y-auto px-4 pt-2 pb-5">
                <!-- The day's main pages: big cards, side by side -->
                <div v-if="lead.length" class="grid grid-cols-2 gap-2.5">
                    <Link
                        v-for="item in lead"
                        :key="item.href"
                        :href="item.href"
                        class="relative flex min-h-[96px] flex-col justify-between overflow-hidden rounded-2xl p-3.5"
                        :class="[
                            props.isActive(item.href)
                                ? 'k-accent'
                                : 'k-panel-bg border border-line-2',
                            lead.length === 1 ? 'col-span-2' : '',
                        ]"
                        @click="open = false"
                    >
                        <span class="flex items-start justify-between">
                            <span
                                class="grid size-10 place-items-center rounded-xl"
                                :class="
                                    props.isActive(item.href)
                                        ? 'bg-white/20 text-white'
                                        : 'bg-brand text-white'
                                "
                                ><KIcon :name="item.icon" :size="19"
                            /></span>
                            <KIcon
                                name="back"
                                :size="15"
                                class="rotate-180"
                                :class="
                                    props.isActive(item.href)
                                        ? 'text-white/80'
                                        : 'text-ink-3'
                                "
                            />
                        </span>
                        <span
                            class="mt-3 text-[15.5px] leading-tight font-bold"
                            >{{ t(item.label) }}</span
                        >
                    </Link>
                </div>

                <!-- Every other group: its own card, its own colour -->
                <section
                    v-for="(group, gi) in titled"
                    :key="group.title"
                    class="panel mt-3 p-3"
                >
                    <p class="flex items-center gap-2 px-1 pb-2.5">
                        <span
                            class="h-4 w-1 rounded-full"
                            :class="tone(gi).bar"
                        />
                        <span
                            class="flex-1 text-[13px] font-bold tracking-[0.08em] text-ink-2 uppercase"
                            >{{ t(group.title ?? '') }}</span
                        >
                        <span class="num text-[12.5px] text-ink-3">{{
                            group.items.length
                        }}</span>
                    </p>
                    <div class="grid grid-cols-2 gap-1.5">
                        <Link
                            v-for="item in group.items"
                            :key="item.href"
                            :href="item.href"
                            class="flex min-h-[52px] items-center gap-2.5 rounded-xl px-2.5 py-2 text-[14px] leading-tight font-semibold transition-colors"
                            :class="
                                props.isActive(item.href)
                                    ? 'k-accent'
                                    : 'bg-sunken text-ink active:bg-line'
                            "
                            @click="open = false"
                        >
                            <span
                                class="grid size-8 shrink-0 place-items-center rounded-lg"
                                :class="
                                    props.isActive(item.href)
                                        ? 'bg-white/20 text-white'
                                        : tone(gi).badge
                                "
                                ><KIcon :name="item.icon" :size="17"
                            /></span>
                            <span class="line-clamp-2 min-w-0">{{
                                t(item.label)
                            }}</span>
                        </Link>
                    </div>
                </section>

                <Link
                    v-if="props.settingsHref"
                    :href="props.settingsHref"
                    class="panel mt-3 flex min-h-14 items-center gap-3 px-3.5 text-[15px] font-semibold"
                    :class="
                        props.isActive(props.settingsHref)
                            ? 'text-brand-ink'
                            : 'text-ink'
                    "
                    @click="open = false"
                >
                    <span
                        class="grid size-9 place-items-center rounded-xl bg-sunken text-ink-2"
                        ><KIcon name="gear" :size="18"
                    /></span>
                    <span class="flex-1">Settings</span>
                    <KIcon
                        name="back"
                        :size="15"
                        class="rotate-180 text-ink-3"
                    />
                </Link>
            </nav>
        </section>
    </Teleport>
</template>

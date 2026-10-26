<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { watch } from 'vue';
import KIcon from '@/components/k/KIcon.vue';
import { t } from '@/lib/i18n';

/**
 * The phone's menu: a sheet that rises from the bottom with every page as a big icon tile,
 * grouped the way the side menu groups them. One thumb reaches everything; the page that is
 * open is lit. Desktop keeps its side rail.
 */
type Item = { label: string; href: string; icon: string };

const props = defineProps<{
    groups: { title?: string; items: Item[] }[];
    settingsHref: string | null;
    isActive: (href: string) => boolean;
    user: { name: string; role?: string | null };
}>();
const open = defineModel<boolean>({ default: false });

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
            class="k-sheet fixed inset-x-0 bottom-0 z-50 flex max-h-[88dvh] flex-col rounded-t-[22px] border-t border-line-2 bg-paper pb-[env(safe-area-inset-bottom)] shadow-pop lg:hidden"
            role="dialog"
            :aria-label="t('মেনু')"
        >
            <div
                class="mx-auto mt-2.5 h-1 w-10 shrink-0 rounded-full bg-line-2"
            />
            <div class="flex items-center gap-3 px-5 pt-3 pb-2">
                <span
                    class="grid size-10 shrink-0 place-items-center rounded-xl bg-ink text-[15px] font-bold text-paper"
                    >{{ props.user.name.slice(0, 1).toUpperCase() }}</span
                >
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-[15.5px] font-bold">{{
                        props.user.name
                    }}</span>
                    <span
                        v-if="props.user.role"
                        class="block truncate text-[13px] text-ink-3"
                        >{{ props.user.role }}</span
                    >
                </span>
                <button
                    type="button"
                    class="grid size-10 place-items-center rounded-lg text-ink-3 hover:bg-sunken hover:text-ink"
                    :aria-label="t('মেনু বন্ধ করুন')"
                    @click="open = false"
                >
                    <KIcon name="close" :size="18" />
                </button>
            </div>

            <nav class="min-h-0 flex-1 overflow-y-auto px-4 pb-4">
                <div v-for="(group, gi) in props.groups" :key="gi" class="mt-3">
                    <p
                        v-if="group.title"
                        class="mb-1.5 px-1 text-[12.5px] font-bold tracking-[0.1em] text-brand-ink uppercase"
                    >
                        {{ t(group.title) }}
                    </p>
                    <div class="grid grid-cols-3 gap-2">
                        <Link
                            v-for="item in group.items"
                            :key="item.href"
                            :href="item.href"
                            class="flex min-h-[84px] flex-col items-center justify-center gap-1.5 rounded-2xl border px-2 py-3 text-center text-[13px] leading-tight font-semibold transition-colors"
                            :class="
                                props.isActive(item.href)
                                    ? 'border-brand/50 bg-brand-soft text-brand-ink'
                                    : 'panel text-ink-2 active:bg-sunken'
                            "
                            @click="open = false"
                        >
                            <span
                                class="grid size-10 place-items-center rounded-xl"
                                :class="
                                    props.isActive(item.href)
                                        ? 'bg-brand text-white'
                                        : 'bg-sunken text-brand-ink'
                                "
                                ><KIcon :name="item.icon" :size="19"
                            /></span>
                            <span class="line-clamp-2">{{
                                t(item.label)
                            }}</span>
                        </Link>
                    </div>
                </div>

                <div class="mt-4 grid grid-cols-2 gap-2">
                    <Link
                        v-if="props.settingsHref"
                        :href="props.settingsHref"
                        class="flex h-12 items-center justify-center gap-2 rounded-xl text-[14px] font-semibold"
                        :class="
                            props.isActive(props.settingsHref)
                                ? 'border border-brand/50 bg-brand-soft text-brand-ink'
                                : 'panel text-ink-2'
                        "
                        @click="open = false"
                        ><KIcon name="gear" :size="17" />Settings</Link
                    >
                    <Link
                        href="/settings/profile"
                        class="panel flex h-12 items-center justify-center gap-2 rounded-xl text-[14px] font-semibold text-ink-2"
                        @click="open = false"
                        ><KIcon name="user" :size="17" />Profile</Link
                    >
                </div>
            </nav>
        </section>
    </Teleport>
</template>

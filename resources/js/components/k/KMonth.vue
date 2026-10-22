<script setup lang="ts">
import { ref, watch } from 'vue';
import { usePopover } from '@/composables/usePopover';
import { BN_MONTHS, monthLabel } from '@/lib/format';

defineProps<{ invalid?: boolean; id?: string }>();
const model = defineModel<string | null>();

const anchor = ref<HTMLElement | null>(null);
const panel = ref<HTMLElement | null>(null);
const { open, style, toggle, close } = usePopover(anchor, panel);
const year = ref(new Date().getFullYear());

watch(open, (value) => {
    if (value) {
        year.value = model.value ? Number(model.value.slice(0, 4)) : new Date().getFullYear();
    }
});

function pick(index: number): void {
    model.value = `${year.value}-${String(index + 1).padStart(2, '0')}`;
    close();
}
</script>

<template>
    <div ref="anchor">
        <button :id="id" type="button" class="field-box cursor-pointer text-left text-[14.5px]" :data-open="open" :data-invalid="invalid" @click="toggle">
            <svg viewBox="0 0 16 16" class="size-4 shrink-0 text-ink-3" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><rect x="2.5" y="3.5" width="11" height="10" rx="2" /><path d="M2.5 6.5h11M5.5 9h1M9.5 9h1M5.5 11h1" /></svg>
            <span class="flex-1 font-bn" :class="model ? 'text-ink' : 'text-ink-3'">{{ model ? monthLabel(model) : 'মাস বেছে নিন' }}</span>
        </button>

        <Teleport to="body">
            <div v-if="open" ref="panel" :style="style" class="popover k-rise w-[264px] p-3">
                <div class="mb-2 flex items-center justify-between">
                    <button type="button" class="grid size-8 place-items-center rounded-lg hover:bg-sunken" aria-label="আগের বছর" @click="year--">
                        <svg viewBox="0 0 16 16" class="size-4" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><path d="M10 3.5L5.5 8l4.5 4.5" /></svg>
                    </button>
                    <span class="num text-[15px] font-semibold">{{ year }}</span>
                    <button type="button" class="grid size-8 place-items-center rounded-lg hover:bg-sunken" aria-label="পরের বছর" @click="year++">
                        <svg viewBox="0 0 16 16" class="size-4" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><path d="M6 3.5L10.5 8 6 12.5" /></svg>
                    </button>
                </div>
                <div class="grid grid-cols-3 gap-1.5">
                    <button
                        v-for="(name, i) in BN_MONTHS"
                        :key="name"
                        type="button"
                        class="rounded-lg py-2.5 font-bn text-[13px] hover:bg-sunken"
                        :class="model === `${year}-${String(i + 1).padStart(2, '0')}` ? 'bg-brand font-semibold text-white hover:bg-brand' : ''"
                        @click="pick(i)"
                    >
                        {{ name }}
                    </button>
                </div>
            </div>
        </Teleport>
    </div>
</template>

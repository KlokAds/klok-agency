<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import KInput from '@/components/k/KInput.vue';
import KSwitch from '@/components/k/KSwitch.vue';
import { money } from '@/lib/format';
import { getJson } from '@/lib/http';
import { t } from '@/lib/i18n';

type Row = {
    key: string;
    label: string;
    client: string | null;
    pending: number;
    suggested: number;
};

// What a card charge paid for. By itself it pays the oldest spend first;
// "split by hand" lets it follow the platform's own invoice, campaign by
// campaign. Only a hand split is sent.
const props = defineProps<{
    accountId: number | null;
    amount: string | null;
}>();
const split = defineModel<Record<string, string>>({ required: true });

const rows = ref<Row[]>([]);
// What the inputs show; only a hand split goes out through the model.
const shown = ref<Record<string, string>>({});
const loading = ref(false);
const byHand = ref(false);

const cents = (text: string | null | undefined) =>
    Math.round(parseFloat((text || '0').replace(/,/g, '')) * 100) || 0;

let timer: ReturnType<typeof setTimeout> | undefined;
function load(): void {
    clearTimeout(timer);
    timer = setTimeout(async () => {
        if (!props.accountId) {
            rows.value = [];

            return;
        }

        loading.value = true;
        try {
            const result = await getJson<{ rows: Row[] }>(
                `/dollars/pending/${props.accountId}?amount=${encodeURIComponent(props.amount || '0')}`,
            );
            rows.value = result.rows;

            if (!byHand.value) {
                fill();
            }
        } finally {
            loading.value = false;
        }
    }, 300);
}

/** The oldest-first suggestion, as the books would do it. */
function fill(): void {
    shown.value = Object.fromEntries(
        rows.value.map((r) => [
            r.key,
            r.suggested ? (r.suggested / 100).toFixed(2) : '',
        ]),
    );
}

watch(() => [props.accountId, props.amount], load, { immediate: true });
watch(byHand, (on) => {
    if (!on) {
        fill();
    }
});
watch(
    [shown, byHand],
    () => (split.value = byHand.value ? { ...shown.value } : {}),
    { deep: true },
);

const given = computed(() =>
    Object.values(shown.value).reduce((sum, v) => sum + cents(v), 0),
);
const rest = computed(() => cents(props.amount) - given.value);
</script>

<template>
    <div v-if="rows.length" class="rounded-xl border border-line">
        <div
            class="flex flex-wrap items-center justify-between gap-2 border-b border-line px-3 py-2.5"
        >
            <p class="text-[13px] font-semibold">
                {{ t('কোনটার জন্য কাটল') }}
            </p>
            <KSwitch
                v-model="byHand"
                :label="t('Meta invoice দেখে নিজে ভাগ করুন')"
            />
        </div>
        <ul class="max-h-[260px] overflow-auto">
            <li
                v-for="r in rows"
                :key="r.key"
                class="flex items-center gap-3 border-b border-line px-3 py-2 text-[12.5px] last:border-0"
            >
                <span class="min-w-0 flex-1">
                    <span class="block truncate font-medium" :title="r.label">{{
                        r.label
                    }}</span>
                    <span class="text-ink-3"
                        >{{ r.client ? r.client + ' · ' : '' }}{{ t('বাকি') }}
                        <span class="num">{{
                            money(r.pending, 'usd')
                        }}</span></span
                    >
                </span>
                <KInput
                    v-model="shown[r.key]"
                    inputmode="decimal"
                    :disabled="!byHand"
                    placeholder="0"
                    class="w-[110px]"
                >
                    <template #trail
                        ><span class="text-[12px] text-ink-3"
                            >usd</span
                        ></template
                    >
                </KInput>
            </li>
        </ul>
        <p
            class="border-t border-line px-3 py-2 text-[12px]"
            :class="rest < 0 ? 'text-out' : 'text-ink-3'"
        >
            <template v-if="rest < 0">{{
                t('ভাগগুলো charge-এর চেয়ে {amount} বেশি।', {
                    amount: money(-rest, 'usd'),
                })
            }}</template>
            <template v-else-if="rest > 0">{{
                t('বাকি {amount} সবচেয়ে পুরনো খরচে যাবে।', {
                    amount: money(rest, 'usd'),
                })
            }}</template>
            <template v-else>{{ t('পুরো charge ভাগ করা হয়েছে।') }}</template>
            <span v-if="loading"> …</span>
        </p>
    </div>
</template>

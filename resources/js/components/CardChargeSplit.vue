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

// What a card charge paid for. By itself it pays the oldest spend first.
// "Split by hand" follows the platform's own invoice: tick the campaigns in
// the order the invoice lists them and the charge fills each up to what it
// still owes (GFFS A $8, GFFS B $6, Samir $6 of a $20 charge); a typed
// amount stays as typed. Only a hand split is sent.
const props = defineProps<{
    accountId: number | null;
    amount: string | null;
}>();
const split = defineModel<Record<string, string>>({ required: true });

const rows = ref<Row[]>([]);
const loading = ref(false);
const byHand = ref(false);
const order = ref<string[]>([]);
const typed = ref<Record<string, string>>({});

const cents = (text: string | null | undefined) =>
    Math.round(parseFloat((text || '0').replace(/,/g, '')) * 100) || 0;
const dollars = (c: number) => (c > 0 ? (c / 100).toFixed(2) : '');

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
        } finally {
            loading.value = false;
        }
    }, 300);
}
watch(() => [props.accountId, props.amount], load, { immediate: true });

/** What each row gets: typed amounts first, then the ticked rows in order. */
const shown = computed<Record<string, string>>(() => {
    if (!byHand.value) {
        return Object.fromEntries(
            rows.value.map((r) => [r.key, dollars(r.suggested)]),
        );
    }

    const out: Record<string, string> = {};
    let rest =
        cents(props.amount) -
        order.value.reduce(
            (sum, key) =>
                sum + (key in typed.value ? cents(typed.value[key]) : 0),
            0,
        );

    for (const key of order.value) {
        if (key in typed.value) {
            out[key] = typed.value[key];
            continue;
        }

        const pending = rows.value.find((r) => r.key === key)?.pending ?? 0;
        const take = Math.max(0, Math.min(pending, rest));
        out[key] = dollars(take);
        rest -= take;
    }

    return out;
});

watch(
    [shown, byHand],
    () => (split.value = byHand.value ? { ...shown.value } : {}),
    { deep: true, immediate: true },
);

watch(byHand, () => {
    order.value = [];
    typed.value = {};
});

function toggle(key: string): void {
    if (order.value.includes(key)) {
        order.value = order.value.filter((k) => k !== key);
        const { [key]: _gone, ...others } = typed.value;
        typed.value = others;
    } else {
        order.value = [...order.value, key];
    }
}

function type(key: string, value: string | null | undefined): void {
    if (!order.value.includes(key)) {
        order.value = [...order.value, key];
    }
    typed.value = { ...typed.value, [key]: value ?? '' };
}

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
        <p
            v-if="byHand"
            class="border-b border-line px-3 py-2 text-[12px] text-ink-3"
        >
            {{
                t(
                    'Meta invoice-এর ক্রমে campaign-গুলোতে চাপুন — charge প্রথমটা থেকে শুরু করে যতটা বাকি ততটা ভরবে। নিজে অঙ্ক লিখলে সেটাই থাকবে।',
                )
            }}
        </p>
        <ul class="max-h-[280px] overflow-auto">
            <li
                v-for="r in rows"
                :key="r.key"
                class="flex items-center gap-3 border-b border-line px-3 py-2 text-[12.5px] last:border-0"
                :class="byHand && 'cursor-pointer hover:bg-surface-2'"
                @click="byHand && toggle(r.key)"
            >
                <span
                    v-if="byHand"
                    class="grid size-6 shrink-0 place-items-center rounded-full border text-[11px] font-semibold"
                    :class="
                        order.includes(r.key)
                            ? 'border-brand bg-brand text-white'
                            : 'border-line-2 text-ink-3'
                    "
                    >{{
                        order.includes(r.key) ? order.indexOf(r.key) + 1 : ''
                    }}</span
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
                    :model-value="shown[r.key] ?? ''"
                    inputmode="decimal"
                    :disabled="!byHand"
                    placeholder="0"
                    class="w-[110px]"
                    @click.stop
                    @update:model-value="(v) => type(r.key, v)"
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

<script setup lang="ts">
import { computed } from 'vue';
import KField from '@/components/k/KField.vue';
import KInput from '@/components/k/KInput.vue';
import KSegment from '@/components/k/KSegment.vue';
import { t } from '@/lib/i18n';

// How a client's dollar is priced on every invoice made for them (renewal, top-up, spend due):
// the FX batch plus a commission (theirs, or the agency's when left empty), or a fixed rate.
const props = defineProps<{
    pricing: { commission: number; fx_rate: number | null };
    errors: Partial<Record<string, string>>;
}>();
const mode = defineModel<string>('mode', { required: true });
const fixed = defineModel<string>('fixed', { required: true });
const commission = defineModel<string>('commission', { required: true });

const modes = [
    { value: 'commission', label: 'Commission' },
    { value: 'fixed', label: t('Fixed rate') },
];

const taka = (n: number) =>
    '৳' + (Math.round(n * 100) / 100).toLocaleString('en-US');

// What a dollar costs them today with the choice as it stands.
const today = computed(() => {
    if (mode.value === 'fixed') {
        const rate = parseFloat(fixed.value);

        return rate > 0
            ? t('প্রতি USD {rate}, FX যা-ই হোক', { rate: taka(rate) })
            : '';
    }
    const pct =
        commission.value.trim() !== ''
            ? parseFloat(commission.value)
            : props.pricing.commission;
    if (!props.pricing.fx_rate || Number.isNaN(pct)) {
        return '';
    }

    return t('আজ: FX {fx} + {pct}% = প্রতি USD {rate}', {
        fx: taka(props.pricing.fx_rate),
        pct,
        rate: taka(props.pricing.fx_rate * (1 + pct / 100)),
    });
});
</script>

<template>
    <KField
        :label="t('Dollar-এর দাম')"
        :error="errors.rate_mode"
        class="sm:col-span-2"
        :hint="today"
    >
        <div class="flex flex-col gap-2 sm:flex-row sm:items-start">
            <KSegment v-model="mode" :options="modes" />
            <KInput
                v-if="mode === 'fixed'"
                v-model="fixed"
                inputmode="decimal"
                :placeholder="t('যেমন 150')"
                :invalid="!!errors.fixed_rate"
                class="sm:max-w-40"
            />
            <KInput
                v-else
                v-model="commission"
                inputmode="decimal"
                :placeholder="t('{n}% (সবার মতো)', { n: pricing.commission })"
                :invalid="!!errors.commission_percent"
                class="sm:max-w-40"
            />
        </div>
        <p
            v-if="errors.fixed_rate || errors.commission_percent"
            class="text-[13px] text-out"
        >
            {{ errors.fixed_rate || errors.commission_percent }}
        </p>
    </KField>
</template>

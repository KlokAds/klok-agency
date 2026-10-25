<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import KButton from '@/components/k/KButton.vue';
import KIcon from '@/components/k/KIcon.vue';
import KInput from '@/components/k/KInput.vue';
import type { CampaignStateKey } from '@/components/portal/CampaignState.vue';
import { confirmAction } from '@/lib/feedback';
import { money } from '@/lib/format';
import { t } from '@/lib/i18n';

/**
 * The client's own hand on their campaign: pause it, switch it back on, set the budget a day.
 * Every figure is in dollars (cents); a raise goes only as far as the money already paid carries
 * the campaign to its last day — beyond that the save is off and the page says to pay first.
 */
export type Control = {
    state: CampaignStateKey;
    budget: number | null;
    min: number;
    max: number;
    credit_left: number;
    others_daily: number;
    prepaid: boolean;
    room: number | null;
    last_day: string | null;
};

const props = defineProps<{ campaignId: number; control: Control }>();

const page = usePage();
const contact = computed(
    () => (page.props.contact as { whatsapp?: string } | null)?.whatsapp,
);
const error = computed(
    () => (page.props.errors as Record<string, string>)?.control,
);
const busy = ref(false);

const form = useForm({
    budget: props.control.budget ? (props.control.budget / 100).toFixed(2) : '',
});
const cents = computed(() => Math.round(parseFloat(form.budget || '0') * 100));
const raising = computed(() => cents.value > (props.control.budget ?? 0));
const tooMuch = computed(
    () =>
        props.control.prepaid &&
        props.control.room !== null &&
        raising.value &&
        cents.value > props.control.room,
);
const outOfRange = computed(
    () => cents.value < props.control.min || cents.value > props.control.max,
);
// How long the money left lasts with this budget and the client's other running campaigns.
const lasts = computed(() => {
    const daily = cents.value + props.control.others_daily;
    return props.control.prepaid && daily > 0
        ? Math.floor(props.control.credit_left / daily)
        : null;
});
const changed = computed(() => cents.value !== (props.control.budget ?? 0));

function saveBudget(): void {
    if (tooMuch.value || outOfRange.value || !changed.value) {
        return;
    }
    form.post(`/portal/campaigns/${props.campaignId}/budget`, {
        preserveScroll: true,
    });
}

async function pause(): Promise<void> {
    const ok = await confirmAction({
        title: t('Campaign থামাবেন?'),
        body: t(
            'থামানো থাকলে টাকা খরচ হবে না। পরে এখান থেকেই আবার চালু করতে পারবেন।',
        ),
        confirm: t('থামান'),
        danger: true,
    });
    if (ok) {
        post('pause');
    }
}

function post(what: 'pause' | 'resume'): void {
    busy.value = true;
    router.post(
        `/portal/campaigns/${props.campaignId}/${what}`,
        {},
        { preserveScroll: true, onFinish: () => (busy.value = false) },
    );
}
</script>

<template>
    <!-- Stopped on our side: said plainly, with a way to reach us. -->
    <section
        v-if="control.state === 'held'"
        class="mt-4 rounded-2xl border border-out/30 bg-out-soft p-5"
    >
        <p class="flex items-center gap-2 text-[15px] font-bold text-out">
            <KIcon name="warn" :size="18" />{{
                t('আমাদের দিকে সাময়িক সমস্যা')
            }}
        </p>
        <p class="mt-1.5 text-[13.5px] text-ink-2">
            {{
                t(
                    'Ad account বা Meta-র দিকের একটা সমস্যায় campaign এখন চলছে না। Klok Ads ঠিক করছে — এ সময় আপনার টাকা খরচ হচ্ছে না, আর ঠিক হলেই আপনাকে জানানো হবে।',
                )
            }}
        </p>
        <a
            v-if="contact"
            :href="contact"
            target="_blank"
            rel="noopener"
            class="mt-3 inline-flex h-10 items-center gap-2 rounded-[10px] bg-[#25D366] px-4 text-[13.5px] font-semibold text-white transition-colors hover:bg-[#1ebe5b] active:translate-y-px"
            ><KIcon name="chat" :size="16" />{{ t('WhatsApp-এ কথা বলুন') }}</a
        >
    </section>

    <!-- Paused by the agency: the client cannot switch it on; they can ask why. -->
    <section
        v-else-if="control.state === 'paused_team'"
        class="mt-4 rounded-2xl border border-wait/30 bg-wait-soft p-5"
    >
        <p class="text-[15px] font-bold text-wait">
            {{ t('Klok Ads campaign-টা থামিয়ে রেখেছে') }}
        </p>
        <p class="mt-1 text-[13.5px] text-ink-2">
            {{
                t(
                    'এ সময় আপনার টাকা খরচ হচ্ছে না। কেন থামানো বা কবে চালু হবে জানতে আমাদের লিখুন।',
                )
            }}
        </p>
        <a
            v-if="contact"
            :href="contact"
            target="_blank"
            rel="noopener"
            class="mt-3 inline-flex h-10 items-center gap-2 rounded-[10px] bg-[#25D366] px-4 text-[13.5px] font-semibold text-white transition-colors hover:bg-[#1ebe5b] active:translate-y-px"
            ><KIcon name="chat" :size="16" />{{ t('WhatsApp-এ কথা বলুন') }}</a
        >
    </section>

    <section
        v-else-if="control.state === 'paused_credit'"
        class="mt-4 rounded-2xl border border-out/30 bg-out-soft p-5"
    >
        <p class="text-[15px] font-bold text-out">
            {{ t('বিজ্ঞাপনের টাকা শেষ — campaign থামানো আছে') }}
        </p>
        <p class="mt-1 text-[13.5px] text-ink-2">
            {{ t('বাকি invoice pay করলেই নিজে থেকে আবার চালু হবে।') }}
        </p>
        <KButton variant="primary" href="/portal/invoices" class="mt-3">{{
            t('Invoice দেখুন')
        }}</KButton>
    </section>

    <section v-else class="panel mt-4 p-5">
        <h2 class="text-[16px] font-bold">{{ t('Campaign নিয়ন্ত্রণ') }}</h2>

        <!-- On / off -->
        <div
            class="mt-3 flex flex-wrap items-center justify-between gap-3 rounded-xl bg-sunken p-4"
        >
            <p class="text-[13.5px] text-ink-2">
                {{
                    control.state === 'live'
                        ? t('চলছে। থামালে আর টাকা খরচ হবে না।')
                        : t('আপনি থামিয়ে রেখেছেন — টাকা খরচ হচ্ছে না।')
                }}
            </p>
            <KButton
                v-if="control.state === 'live'"
                variant="plain"
                :loading="busy"
                @click="pause"
                >{{ t('থামান') }}</KButton
            >
            <KButton
                v-else
                variant="primary"
                :loading="busy"
                :disabled="control.prepaid && control.credit_left <= 0"
                @click="post('resume')"
                >{{ t('আবার চালু করুন') }}</KButton
            >
        </div>
        <p
            v-if="
                control.state === 'paused_client' &&
                control.prepaid &&
                control.credit_left <= 0
            "
            class="mt-2 text-[13px] font-medium text-out"
        >
            {{ t('টাকা শেষ — চালু করতে আগে pay করুন।') }}
        </p>

        <!-- Budget a day -->
        <div class="mt-5">
            <p class="text-[13.5px] font-semibold">{{ t('দিনের budget') }}</p>
            <div class="mt-2 flex flex-wrap items-start gap-2">
                <div class="w-40">
                    <KInput
                        v-model="form.budget"
                        inputmode="decimal"
                        :invalid="tooMuch || outOfRange || !!form.errors.budget"
                    >
                        <template #trail
                            ><span class="text-[13px] font-semibold text-ink-3"
                                >USD</span
                            ></template
                        >
                    </KInput>
                </div>
                <KButton
                    variant="primary"
                    :loading="form.processing"
                    :disabled="tooMuch || outOfRange || !changed"
                    @click="saveBudget"
                    >{{ t('Budget বদলান') }}</KButton
                >
            </div>
            <p
                v-if="tooMuch"
                class="mt-2 flex items-start gap-1.5 text-[13px] font-medium text-out"
            >
                <KIcon name="warn" :size="15" class="mt-0.5 shrink-0" />
                <span>{{
                    (control.room ?? 0) >= control.min
                        ? t(
                              'আপনার বাকি টাকায় {date} পর্যন্ত দিনে সর্বোচ্চ {max} চলবে। বেশি চাইলে আগে pay করুন।',
                              {
                                  date: control.last_day ?? '',
                                  max: money(control.room ?? 0, 'usd'),
                              },
                          )
                        : t(
                              'বাকি টাকায় budget বাড়ানো যাবে না — আগে pay করুন।',
                          )
                }}</span>
            </p>
            <p
                v-else-if="outOfRange && form.budget"
                class="mt-2 text-[13px] text-out"
            >
                {{
                    t('দিনে {min} থেকে {max}-এর মধ্যে দিন।', {
                        min: money(control.min, 'usd'),
                        max: money(control.max, 'usd'),
                    })
                }}
            </p>
            <p v-else-if="lasts !== null" class="mt-2 text-[13px] text-ink-3">
                {{
                    t('এই budget-এ বাকি টাকায় আনুমানিক {n} দিন চলবে।', {
                        n: lasts,
                    })
                }}
            </p>
            <p v-if="form.errors.budget" class="mt-2 text-[13px] text-out">
                {{ form.errors.budget }}
            </p>
        </div>
        <p v-if="error" class="mt-3 text-[13px] font-medium text-out">
            {{ error }}
        </p>
    </section>
</template>

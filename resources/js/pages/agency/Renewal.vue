<script setup lang="ts">
import SectionTabs from '@/components/SectionTabs.vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import KButton from '@/components/k/KButton.vue';
import KIcon from '@/components/k/KIcon.vue';
import KModal from '@/components/k/KModal.vue';
import KTag from '@/components/k/KTag.vue';
import { toast } from '@/lib/feedback';
import { ago, money, niceDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { RenewalRow } from './Renewals.vue';

// One renewal: the client's link to send (WhatsApp / SMS / copy), what the
// client chose, and — once the invoice is paid — the OK that extends the
// campaign on Meta.
const props = defineProps<{
    renewal: RenewalRow & {
        url: string;
        message: {
            phone: string | null;
            whatsapp: string | null;
            sms: string | null;
            text: string;
        };
        invoice_id: number | null;
        error: string | null;
        stop_at: string | null;
        paused: boolean;
    };
    statuses: Record<string, string>;
}>();

const r = computed(() => props.renewal);
const can = computed(() => usePage().props.auth.can);
const confirm = ref(false);
const busy = ref(false);
const errors = computed(() => usePage().props.errors as Record<string, string>);

function copy(text: string): void {
    void navigator.clipboard?.writeText(text);
    toast(t('Copy হয়েছে।'), 'info');
}

function approve(): void {
    router.post(
        `/renewals/${r.value.id}/approve`,
        {},
        {
            preserveScroll: true,
            onStart: () => (busy.value = true),
            onFinish: () => (busy.value = false),
            onSuccess: () => (confirm.value = false),
        },
    );
}

function addDays(iso: string, n: number): string {
    const d = new Date(`${iso}T00:00:00`);
    d.setDate(d.getDate() + n);

    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}
const todayIso = new Date().toISOString().slice(0, 10);
const willEnd = computed(() => {
    const from =
        (r.value.stop_at ?? r.value.ends_on) > todayIso
            ? (r.value.stop_at ?? r.value.ends_on)
            : todayIso;

    return addDays(from, r.value.days ?? 0);
});
</script>

<template>
    <SectionTabs section="campaigns" />
    <PageHeader
        eyebrow="Renewal"
        :title="r.campaign ?? 'Campaign'"
        :lead="r.client ?? ''"
    >
        <KTag
            :tone="
                r.status === 'paid'
                    ? 'wait'
                    : r.status === 'approved'
                      ? 'in'
                      : r.status === 'declined'
                        ? 'out'
                        : 'neutral'
            "
            >{{ t(statuses[r.status] ?? r.status) }}</KTag
        >
    </PageHeader>

    <div
        class="grid max-w-5xl grid-cols-1 gap-5 lg:grid-cols-[minmax(0,1fr)_320px]"
    >
        <div class="flex flex-col gap-5">
            <!-- Waiting for the OK -->
            <section
                v-if="r.status === 'paid'"
                class="panel border-wait/40 p-5 sm:p-6"
            >
                <h2 class="flex items-center gap-2 text-[16px] font-semibold">
                    <KIcon name="check" class="text-in" />{{
                        t('Client টাকা দিয়েছে')
                    }}
                </h2>
                <p class="mt-2 text-[14.5px] text-ink-2">
                    {{
                        t(
                            '{n} দিনের জন্য {amount} পাওয়া গেছে। Approve করলে Klok Pilot Meta-তে campaign-এর শেষ {date} পর্যন্ত বাড়াবে।',
                            {
                                n: r.days ?? 0,
                                amount: r.invoice?.total ?? '',
                                date: niceDate(willEnd),
                            },
                        )
                    }}
                </p>
                <p
                    v-if="r.error || errors.renewal"
                    class="mt-3 rounded-lg bg-out-soft px-3 py-2 text-[13.5px] text-out"
                >
                    {{ errors.renewal ?? r.error }}
                </p>
                <div
                    v-if="can['clients.edit']"
                    class="mt-4 flex sm:justify-end"
                >
                    <KButton
                        variant="primary"
                        icon="renew"
                        class="w-full sm:w-auto"
                        @click="confirm = true"
                        >{{ t('Approve করে extend করুন') }}</KButton
                    >
                </div>
            </section>

            <section v-else-if="r.status === 'approved'" class="panel p-5">
                <p class="text-[15px]">
                    {{
                        t('Extend হয়েছে — campaign {date} পর্যন্ত চলবে।', {
                            date: niceDate(r.new_end),
                        })
                    }}
                </p>
                <p v-if="r.approved_at" class="mt-1 text-[13.5px] text-ink-3">
                    {{ ago(r.approved_at) }}
                </p>
            </section>

            <section v-else-if="r.status === 'declined'" class="panel p-5">
                <p class="text-[15px]">
                    {{
                        t(
                            'Client contract এখানেই শেষ করেছে — campaign {date}-এ নিজে বন্ধ হবে, কিছু করতে হবে না।',
                            { date: niceDate(r.ends_on) },
                        )
                    }}
                </p>
            </section>

            <!-- The link -->
            <section class="panel p-5 sm:p-6">
                <h2 class="text-[16px] font-semibold">
                    {{ t('Client-এর link') }}
                </h2>
                <p class="mt-1 text-[14px] text-ink-3">
                    {{
                        t(
                            'Client এখানে দিন বেছে pay করে, অথবা contract শেষ করে। Print করে PDF-ও বানানো যায়।',
                        )
                    }}
                </p>
                <div class="mt-4 grid grid-cols-1 gap-2">
                    <code
                        class="num min-w-0 truncate rounded-lg bg-sunken px-3 py-2.5 text-[13.5px]"
                        >{{ r.url }}</code
                    >
                    <div class="grid grid-cols-2 gap-2 sm:flex">
                        <KButton size="sm" icon="list" @click="copy(r.url)"
                            >Copy</KButton
                        >
                        <a
                            :href="r.url"
                            target="_blank"
                            rel="noopener"
                            class="k-chip inline-flex h-8 items-center justify-center gap-2 rounded-[10px] px-3 text-[13.5px] font-semibold whitespace-nowrap"
                            ><KIcon name="eye" :size="15" />{{ t('খুলুন') }}</a
                        >
                    </div>
                </div>
                <div class="mt-4 grid grid-cols-1 gap-2 sm:flex sm:flex-wrap">
                    <a
                        v-if="r.message.whatsapp"
                        :href="r.message.whatsapp"
                        target="_blank"
                        rel="noopener"
                        class="inline-flex h-10 items-center justify-center gap-2 rounded-[10px] bg-brand px-4 text-[14.5px] font-semibold whitespace-nowrap text-white hover:bg-brand-ink dark:text-rail"
                        ><KIcon name="chat" :size="16" />WhatsApp</a
                    >
                    <a
                        v-if="r.message.sms"
                        :href="r.message.sms"
                        class="k-chip inline-flex h-10 items-center justify-center gap-2 rounded-[10px] px-4 text-[14.5px] font-semibold whitespace-nowrap"
                        ><KIcon name="mail" :size="16" />SMS</a
                    >
                    <KButton icon="list" @click="copy(r.message.text)">{{
                        t('বার্তা copy')
                    }}</KButton>
                </div>
                <p v-if="!r.message.phone" class="mt-3 text-[13.5px] text-wait">
                    {{
                        t(
                            'Client-এর phone নম্বর নেই — client-এর পাতায় দিলে WhatsApp/SMS বোতাম আসবে।',
                        )
                    }}
                </p>
            </section>
        </div>

        <aside class="panel h-fit p-5 text-[14px]">
            <dl class="grid grid-cols-1 gap-3">
                <div class="flex justify-between gap-3">
                    <dt class="text-ink-3">{{ t('এখন শেষ') }}</dt>
                    <dd class="num">
                        {{ niceDate(r.stop_at ?? r.ends_on, false) }}
                    </dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-ink-3">{{ t('দিনের budget') }}</dt>
                    <dd class="num">{{ money(r.budget, 'usd') }}</dd>
                </div>
                <div v-if="r.days" class="flex justify-between gap-3">
                    <dt class="text-ink-3">{{ t('বেছেছে') }}</dt>
                    <dd class="num">
                        {{ t('{n} দিন', { n: r.days }) }} ·
                        {{ money(r.usd, 'usd') }}
                    </dd>
                </div>
                <div v-if="r.invoice" class="flex justify-between gap-3">
                    <dt class="text-ink-3">Invoice</dt>
                    <dd>
                        <Link
                            :href="`/invoices/${r.invoice.uid}`"
                            class="font-medium text-brand"
                            >{{ r.invoice.number }}</Link
                        >
                        · {{ r.invoice.total }}
                    </dd>
                </div>
                <div v-if="r.paused" class="flex justify-between gap-3">
                    <dt class="text-ink-3">Campaign</dt>
                    <dd>
                        <KTag tone="out">{{ t('টাকা শেষ — বন্ধ') }}</KTag>
                    </dd>
                </div>
            </dl>
            <KButton
                size="sm"
                icon="user"
                block
                class="mt-4"
                :href="`/clients/${r.client_id}`"
                >{{ t('Client-এর হিসাব দেখুন') }}</KButton
            >
        </aside>
    </div>

    <KModal v-model="confirm" :title="t('Extend করবেন?')">
        <p class="text-[14.5px] text-ink-2">
            {{
                t(
                    '{campaign} Meta-তে {date} পর্যন্ত চলবে, বন্ধ থাকলে আবার চালু হবে।',
                    { campaign: r.campaign ?? '', date: niceDate(willEnd) },
                )
            }}
        </p>
        <template #footer>
            <KButton variant="quiet" @click="confirm = false">{{
                t('বাতিল')
            }}</KButton>
            <KButton
                variant="primary"
                icon="renew"
                :loading="busy"
                @click="approve"
                >Approve</KButton
            >
        </template>
    </KModal>
</template>

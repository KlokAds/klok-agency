<script setup lang="ts">
import SettingsNav from '@/components/SettingsNav.vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import KButton from '@/components/k/KButton.vue';
import KDate from '@/components/k/KDate.vue';
import KIcon from '@/components/k/KIcon.vue';
import KSelect from '@/components/k/KSelect.vue';
import KTag from '@/components/k/KTag.vue';
import { useLiveRefresh } from '@/composables/useLiveRefresh';
import { ago, today } from '@/lib/format';
import { t } from '@/lib/i18n';

// Every job that runs by itself, when it last ran, and a button to run it now.
const props = defineProps<{
    timer: {
        on: boolean;
        running: boolean;
        beat: string | null;
        tick: string | null;
        every_seconds: number;
        lifetime_minutes: number;
    };
    pilot: {
        connected: boolean;
        url: string;
        spend_synced: string | null;
        every_minutes: number;
        live_minutes: number;
        watching: boolean;
        failures: number;
        guard: boolean;
        guard_error: string | null;
    };
    receipts: {
        mailbox: boolean;
        checked: string | null;
        charges_since: string | null;
        cards: string[];
        every_minutes: number;
        charges_every_minutes: number;
    };
    sms: {
        phone: {
            version: number;
            seen: string | null;
            sent: string | null;
        } | null;
        last_sms: string | null;
        silent_minutes: number;
    };
    mail: { ready: boolean };
    app: { plain: string | null; sms: string | null };
    errors_today: number;
    now: string;
}>();

useLiveRefresh(30);

const busy = ref<string | null>(null);
function run(job: string): void {
    busy.value = job;
    router.post(
        `/admin/system/run/${job}`,
        {},
        { preserveScroll: true, onFinish: () => (busy.value = null) },
    );
}

// The two paces, picked from a list and saved at once.
const minuteOptions = [1, 2, 3, 5, 10, 15, 30, 60].map((n) => ({
    value: n,
    label: t('{n} মিনিট', { n }),
}));
const every = ref<number | string | null>(props.pilot.every_minutes);
const live = ref<number | string | null>(props.pilot.live_minutes);
watch([every, live], () =>
    router.post(
        '/admin/system/pace',
        { every: Number(every.value), live: Number(live.value) },
        { preserveScroll: true },
    ),
);

// From which day Meta's charges are matched to the campaigns' days.
const since = ref<string | null>(props.receipts.charges_since);
const sinceForm = useForm({ since: '' });
function saveSince(): void {
    sinceForm.since = since.value ?? '';
    sinceForm.post('/admin/system/charges-since', { preserveScroll: true });
}

// Minutes since an ISO time; null when never.
const minutesSince = (iso: string | null) =>
    iso ? Math.round((Date.parse(props.now) - Date.parse(iso)) / 60000) : null;
const fresh = (iso: string | null, within: number) => {
    const m = minutesSince(iso);
    return m !== null && m <= within;
};
const when = (iso: string | null) => (iso ? ago(iso) : '—');
</script>

<template>
    <PageHeader
        :title="t('System')"
        :eyebrow="t('Settings')"
        :lead="
            t('যা যা নিজে থেকে চলে — কখন শেষ চলেছে, আর এখনই চালানোর button।')
        "
    />
    <SettingsNav />

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <!-- The timer -->
        <section class="panel flex flex-col p-5 sm:p-6">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="flex items-center gap-2 text-[16px] font-bold">
                    <KIcon name="clock" :size="18" class="text-brand" />
                    {{ t('Timer (in-app cron)') }}
                </h2>
                <KTag
                    :tone="timer.running ? 'in' : 'out'"
                    class="text-[13px]"
                    >{{ timer.running ? t('চলছে') : t('বন্ধ') }}</KTag
                >
            </div>
            <p class="mt-3 text-[14px] leading-relaxed text-ink-2">
                {{
                    t(
                        'Server-এ cron নেই, তাই app-ই নিজের timer চালায়: প্রতি {s} second-এ reminder, খরচ, card charge আর notification-এর কাজ, আর Klok Pilot-কে জাগিয়ে রাখা; {m} মিনিট পর পর নতুন করে শুরু। কেউ site খুললেই থেমে থাকা timer আবার চালু হয়।',
                        { s: timer.every_seconds, m: timer.lifetime_minutes },
                    )
                }}
            </p>
            <dl class="mt-4 grid grid-cols-2 gap-3 text-[14px]">
                <div class="rounded-xl bg-sunken px-3.5 py-2.5">
                    <dt class="text-[13px] text-ink-3">
                        {{ t('শেষ beat') }}
                    </dt>
                    <dd class="num mt-0.5 font-semibold">
                        {{ when(timer.beat) }}
                    </dd>
                </div>
                <div class="rounded-xl bg-sunken px-3.5 py-2.5">
                    <dt class="text-[13px] text-ink-3">
                        {{ t('শেষ tick') }}
                    </dt>
                    <dd class="num mt-0.5 font-semibold">
                        {{ when(timer.tick) }}
                    </dd>
                </div>
            </dl>
            <p v-if="!timer.on" class="mt-3 text-[13.5px] text-wait">
                {{ t('KEEP_ALIVE বন্ধ — server-এর আসল cron চালাচ্ছে।') }}
            </p>
            <div class="mt-auto flex flex-wrap gap-2 pt-5">
                <KButton
                    variant="primary"
                    :loading="busy === 'tick'"
                    @click="run('tick')"
                    >{{ t('এখনই চালাও') }}</KButton
                >
                <KButton
                    variant="plain"
                    icon="renew"
                    :loading="busy === 'timer'"
                    @click="run('timer')"
                    >{{ t('Timer restart') }}</KButton
                >
            </div>
        </section>

        <!-- Klok Pilot -->
        <section class="panel flex flex-col p-5 sm:p-6">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="flex items-center gap-2 text-[16px] font-bold">
                    <KIcon name="pulse" :size="18" class="text-brand" />
                    {{ t('Klok Pilot — campaign-এর খরচ') }}
                </h2>
                <span class="flex flex-wrap gap-1.5">
                    <KTag
                        :tone="pilot.connected ? 'in' : 'out'"
                        class="text-[13px]"
                        >{{
                            pilot.connected ? t('যুক্ত') : t('যুক্ত নয়')
                        }}</KTag
                    >
                    <KTag
                        v-if="pilot.watching"
                        tone="brand"
                        class="text-[13px]"
                        >{{ t('এখন কেউ দেখছে — প্রতি মিনিটে') }}</KTag
                    >
                    <KTag
                        v-if="pilot.failures > 0"
                        tone="out"
                        class="text-[13px]"
                        >{{ t('{n} বার ব্যর্থ', { n: pilot.failures }) }}</KTag
                    >
                </span>
            </div>
            <p class="mt-3 text-[14px] leading-relaxed text-ink-2">
                {{
                    t(
                        'প্রতি {n} মিনিটে Meta → Pilot → এখানে: আজকের খরচ, result, campaign-এর অবস্থা; কেউ পাতা খুলে রাখলে প্রতি {m} মিনিটে, তখন Pilot আজকের হিসাব Meta থেকে আগে পড়ে নেয়। Credit guard {state}।',
                        {
                            n: pilot.every_minutes,
                            m: pilot.live_minutes,
                            state: pilot.guard ? t('চালু') : t('বন্ধ'),
                        },
                    )
                }}
            </p>
            <p v-if="pilot.guard_error" class="mt-2 text-[13.5px] text-out">
                {{ pilot.guard_error }}
            </p>
            <dl class="mt-4 grid grid-cols-2 gap-3 text-[14px]">
                <div
                    class="rounded-xl px-3.5 py-2.5"
                    :class="
                        fresh(pilot.spend_synced, pilot.every_minutes * 2)
                            ? 'bg-in-soft'
                            : 'bg-sunken'
                    "
                >
                    <dt class="text-[13px] text-ink-3">
                        {{ t('খরচ মেলানো') }}
                    </dt>
                    <dd class="num mt-0.5 font-semibold">
                        {{ when(pilot.spend_synced) }}
                    </dd>
                </div>
                <div class="rounded-xl bg-sunken px-3.5 py-2.5">
                    <dt class="text-[13px] text-ink-3">Pilot</dt>
                    <dd class="mt-0.5 truncate font-semibold">
                        {{ pilot.url || '—' }}
                    </dd>
                </div>
            </dl>
            <div class="mt-4 grid grid-cols-2 gap-3">
                <label class="grid gap-1 text-[13px] text-ink-3"
                    >{{ t('কেউ না দেখলে, প্রতি') }}
                    <KSelect
                        v-model="every"
                        :options="minuteOptions"
                        size="sm"
                    />
                </label>
                <label class="grid gap-1 text-[13px] text-ink-3"
                    >{{ t('কেউ দেখলে, প্রতি') }}
                    <KSelect
                        v-model="live"
                        :options="minuteOptions"
                        size="sm"
                    />
                </label>
            </div>
            <div class="mt-auto flex flex-wrap gap-2 pt-5">
                <KButton
                    variant="primary"
                    icon="renew"
                    :loading="busy === 'spend'"
                    @click="run('spend')"
                    >{{ t('এখনই খরচ আনো') }}</KButton
                >
                <KButton href="/admin/agency" variant="plain">{{
                    t('Pilot setting')
                }}</KButton>
            </div>
        </section>

        <!-- Meta receipts and card charges -->
        <section class="panel flex flex-col p-5 sm:p-6">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="flex items-center gap-2 text-[16px] font-bold">
                    <KIcon name="dollar" :size="18" class="text-brand" />
                    {{ t('Meta receipt ও card charge') }}
                </h2>
                <KTag
                    :tone="receipts.mailbox ? 'in' : 'wait'"
                    class="text-[13px]"
                    >{{
                        receipts.mailbox ? t('Mailbox যুক্ত') : t('Mailbox নেই')
                    }}</KTag
                >
            </div>
            <p class="mt-3 text-[14px] leading-relaxed text-ink-2">
                {{
                    t(
                        'প্রতি {a} মিনিটে receipt mailbox, প্রতি {b} মিনিটে Pilot থেকে Meta-র card charge — আমাদের card: {cards}।',
                        {
                            a: receipts.every_minutes,
                            b: receipts.charges_every_minutes,
                            cards: receipts.cards.length
                                ? receipts.cards.join(', ')
                                : '—',
                        },
                    )
                }}
            </p>
            <dl class="mt-4 grid grid-cols-2 gap-3 text-[14px]">
                <div
                    class="rounded-xl px-3.5 py-2.5"
                    :class="
                        fresh(receipts.checked, receipts.every_minutes * 3)
                            ? 'bg-in-soft'
                            : 'bg-sunken'
                    "
                >
                    <dt class="text-[13px] text-ink-3">
                        {{ t('শেষ দেখা') }}
                    </dt>
                    <dd class="num mt-0.5 font-semibold">
                        {{ when(receipts.checked) }}
                    </dd>
                </div>
                <div class="rounded-xl bg-sunken px-3.5 py-2.5">
                    <dt class="text-[13px] text-ink-3">
                        {{ t('Charge ধরা হচ্ছে') }}
                    </dt>
                    <dd class="num mt-0.5 font-semibold">
                        {{
                            receipts.charges_since
                                ? t('{date} থেকে', {
                                      date: receipts.charges_since,
                                  })
                                : '—'
                        }}
                    </dd>
                </div>
            </dl>
            <div class="mt-4 flex flex-wrap items-end gap-2">
                <label
                    class="grid min-w-[160px] flex-1 gap-1 text-[13px] text-ink-3"
                    >{{ t('Charge কোন দিন থেকে ধরব') }}
                    <KDate v-model="since" :max="today()" />
                </label>
                <KButton
                    variant="soft"
                    :disabled="!since || since === receipts.charges_since"
                    :loading="sinceForm.processing"
                    @click="saveSince"
                    >{{ t('এই দিন থেকে ধরো') }}</KButton
                >
            </div>
            <div class="mt-auto flex flex-wrap gap-2 pt-5">
                <KButton
                    variant="primary"
                    icon="renew"
                    :loading="busy === 'receipts'"
                    @click="run('receipts')"
                    >{{ t('এখনই দেখো') }}</KButton
                >
                <KButton href="/dollars/receipts" variant="plain">{{
                    t('Receipt-এর তালিকা')
                }}</KButton>
            </div>
        </section>

        <!-- The SMS phone -->
        <section class="panel flex flex-col p-5 sm:p-6">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="flex items-center gap-2 text-[16px] font-bold">
                    <KIcon name="chat" :size="18" class="text-brand" />
                    {{ t('Payment SMS-এর phone') }}
                </h2>
                <KTag
                    :tone="
                        sms.phone && fresh(sms.phone.seen, sms.silent_minutes)
                            ? 'in'
                            : 'out'
                    "
                    class="text-[13px]"
                    >{{
                        sms.phone
                            ? fresh(sms.phone.seen, sms.silent_minutes)
                                ? t('জীবিত')
                                : t('খবর নেই')
                            : t('কোনো phone নেই')
                    }}</KTag
                >
            </div>
            <p class="mt-3 text-[14px] leading-relaxed text-ink-2">
                {{
                    t(
                        'Owner-এর phone-এর KlokOne SMS app bKash/Nagad/Rocket-এর SMS এখানে পাঠায়; {n} মিনিট খবর না এলে Owner-কে জানানো হয়।',
                        { n: sms.silent_minutes },
                    )
                }}
            </p>
            <dl class="mt-4 grid grid-cols-2 gap-3 text-[14px]">
                <div class="rounded-xl bg-sunken px-3.5 py-2.5">
                    <dt class="text-[13px] text-ink-3">
                        {{ t('শেষ heartbeat') }}
                    </dt>
                    <dd class="num mt-0.5 font-semibold">
                        {{ sms.phone ? when(sms.phone.seen) : '—'
                        }}<span
                            v-if="sms.phone"
                            class="ml-1 text-[13px] font-normal text-ink-3"
                            >v{{ sms.phone.version }}</span
                        >
                    </dd>
                </div>
                <div class="rounded-xl bg-sunken px-3.5 py-2.5">
                    <dt class="text-[13px] text-ink-3">
                        {{ t('শেষ payment SMS') }}
                    </dt>
                    <dd class="num mt-0.5 font-semibold">
                        {{ when(sms.last_sms) }}
                    </dd>
                </div>
            </dl>
            <div class="mt-auto flex flex-wrap gap-2 pt-5">
                <KButton href="/admin/sms" variant="plain">{{
                    t('SMS Center')
                }}</KButton>
            </div>
        </section>

        <!-- Mail, app, errors -->
        <section class="panel flex flex-col p-5 sm:p-6 lg:col-span-2">
            <h2 class="flex items-center gap-2 text-[16px] font-bold">
                <KIcon name="warn" :size="18" class="text-brand" />
                {{ t('Email, app ও সমস্যা') }}
            </h2>
            <dl class="mt-4 grid grid-cols-1 gap-3 text-[14px] sm:grid-cols-3">
                <div
                    class="flex items-center justify-between rounded-xl bg-sunken px-3.5 py-3"
                >
                    <dt>{{ t('Email পাঠানো') }}</dt>
                    <dd>
                        <KTag
                            :tone="mail.ready ? 'in' : 'out'"
                            class="text-[13px]"
                            >{{ mail.ready ? t('চালু') : t('বন্ধ') }}</KTag
                        >
                    </dd>
                </div>
                <div
                    class="flex items-center justify-between rounded-xl bg-sunken px-3.5 py-3"
                >
                    <dt>{{ t('Phone app version') }}</dt>
                    <dd class="num font-semibold">
                        {{ app.plain ?? '—' }} · SMS {{ app.sms ?? '—' }}
                    </dd>
                </div>
                <Link
                    href="/admin/errors"
                    class="flex items-center justify-between rounded-xl px-3.5 py-3 transition-colors hover:brightness-110"
                    :class="
                        errors_today
                            ? 'bg-out-soft text-out'
                            : 'bg-in-soft text-in'
                    "
                >
                    <span>{{ t('আজকের server error') }}</span>
                    <b class="num inline-flex items-center gap-1.5"
                        ><KIcon
                            :name="errors_today ? 'warn' : 'check'"
                            :size="15"
                        />{{ errors_today }}</b
                    >
                </Link>
            </dl>
            <p class="mt-3 text-[13.5px] text-ink-3">
                {{
                    t(
                        'নতুন কোনো server error হলে সব Owner-এর কাছে notification যায়, একই error ঘণ্টায় একবার।',
                    )
                }}
            </p>
        </section>
    </div>
</template>

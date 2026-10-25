<script setup lang="ts">
import SettingsNav from '@/components/SettingsNav.vue';
import SettingsSection from '@/components/SettingsSection.vue';
import { Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import KButton from '@/components/k/KButton.vue';
import KIcon from '@/components/k/KIcon.vue';
import KTag from '@/components/k/KTag.vue';
import { useLiveRefresh } from '@/composables/useLiveRefresh';
import { ago } from '@/lib/format';
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

// Minutes since an ISO time; null when never.
const minutesSince = (iso: string | null) =>
    iso ? Math.round((Date.parse(props.now) - Date.parse(iso)) / 60000) : null;
const fresh = (iso: string | null, within: number) => {
    const m = minutesSince(iso);
    return m !== null && m <= within;
};
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

    <div class="mt-5 grid grid-cols-1 gap-4 lg:grid-cols-2">
        <!-- The timer -->
        <SettingsSection :title="t('Timer (in-app cron)')" icon="clock">
            <div class="flex flex-wrap items-center gap-2">
                <KTag :tone="timer.running ? 'in' : 'out'">{{
                    timer.running ? t('চলছে') : t('বন্ধ')
                }}</KTag>
                <span class="text-[14px] text-ink-3">{{
                    timer.beat
                        ? t('শেষ beat {ago}', { ago: ago(timer.beat) })
                        : t('এখনো চলেনি')
                }}</span>
            </div>
            <p class="mt-2 text-[14px] text-ink-2">
                {{
                    t(
                        'Server-এ cron নেই, তাই app-ই নিজের timer চালায়: প্রতি {s} second-এ reminder, খরচ, card charge আর notification-এর কাজ, আর Klok Pilot-কে জাগিয়ে রাখা; {m} মিনিট পর পর নতুন করে শুরু। কেউ site খুললেই থেমে থাকা timer আবার চালু হয়।',
                        { s: timer.every_seconds, m: timer.lifetime_minutes },
                    )
                }}
            </p>
            <p class="mt-2 text-[14px] text-ink-3">
                {{ t('শেষ tick') }}
                <b class="num text-ink">{{
                    timer.tick ? ago(timer.tick) : '—'
                }}</b>
            </p>
            <div class="mt-3 flex flex-wrap gap-2">
                <KButton
                    size="sm"
                    variant="primary"
                    :loading="busy === 'tick'"
                    @click="run('tick')"
                    >{{ t('এখনই চালাও') }}</KButton
                >
                <KButton
                    size="sm"
                    variant="plain"
                    icon="renew"
                    :loading="busy === 'timer'"
                    @click="run('timer')"
                    >{{ t('Timer restart') }}</KButton
                >
            </div>
            <p v-if="!timer.on" class="mt-2 text-[13.5px] text-wait">
                {{ t('KEEP_ALIVE বন্ধ — server-এর আসল cron চালাচ্ছে।') }}
            </p>
        </SettingsSection>

        <!-- Klok Pilot -->
        <SettingsSection
            :title="t('Klok Pilot — campaign-এর খরচ')"
            icon="pulse"
        >
            <div class="flex flex-wrap items-center gap-2">
                <KTag :tone="pilot.connected ? 'in' : 'out'">{{
                    pilot.connected ? t('যুক্ত') : t('যুক্ত নয়')
                }}</KTag>
                <KTag
                    v-if="pilot.spend_synced"
                    :tone="
                        fresh(pilot.spend_synced, pilot.every_minutes * 2)
                            ? 'in'
                            : 'wait'
                    "
                    >{{
                        t('খরচ মেলানো {ago}', { ago: ago(pilot.spend_synced) })
                    }}</KTag
                >
                <KTag v-if="pilot.watching" tone="brand">{{
                    t('এখন কেউ দেখছে — প্রতি মিনিটে')
                }}</KTag>
                <KTag v-if="pilot.failures > 0" tone="out">{{
                    t('{n} বার ব্যর্থ', { n: pilot.failures })
                }}</KTag>
            </div>
            <p class="mt-2 text-[14px] text-ink-2">
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
            <div class="mt-3 flex flex-wrap gap-2">
                <KButton
                    size="sm"
                    variant="primary"
                    icon="renew"
                    :loading="busy === 'spend'"
                    @click="run('spend')"
                    >{{ t('এখনই খরচ আনো') }}</KButton
                >
                <KButton href="/admin/agency" size="sm" variant="plain">{{
                    t('Pilot setting')
                }}</KButton>
            </div>
        </SettingsSection>

        <!-- Meta receipts and card charges -->
        <SettingsSection :title="t('Meta receipt ও card charge')" icon="dollar">
            <div class="flex flex-wrap items-center gap-2">
                <KTag :tone="receipts.mailbox ? 'in' : 'wait'">{{
                    receipts.mailbox ? t('Mailbox যুক্ত') : t('Mailbox নেই')
                }}</KTag>
                <KTag
                    v-if="receipts.checked"
                    :tone="
                        fresh(receipts.checked, receipts.every_minutes * 3)
                            ? 'in'
                            : 'wait'
                    "
                    >{{
                        t('শেষ দেখা {ago}', { ago: ago(receipts.checked) })
                    }}</KTag
                >
            </div>
            <p class="mt-2 text-[14px] text-ink-2">
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
            <div class="mt-3 flex flex-wrap gap-2">
                <KButton
                    size="sm"
                    variant="primary"
                    icon="renew"
                    :loading="busy === 'receipts'"
                    @click="run('receipts')"
                    >{{ t('এখনই দেখো') }}</KButton
                >
                <KButton href="/dollars/receipts" size="sm" variant="plain">{{
                    t('Receipt-এর তালিকা')
                }}</KButton>
            </div>
        </SettingsSection>

        <!-- The SMS phone -->
        <SettingsSection :title="t('Payment SMS-এর phone')" icon="chat">
            <div class="flex flex-wrap items-center gap-2">
                <KTag
                    :tone="
                        sms.phone && fresh(sms.phone.seen, sms.silent_minutes)
                            ? 'in'
                            : 'out'
                    "
                    >{{
                        sms.phone
                            ? fresh(sms.phone.seen, sms.silent_minutes)
                                ? t('জীবিত')
                                : t('খবর নেই')
                            : t('কোনো phone নেই')
                    }}</KTag
                >
                <span v-if="sms.phone" class="text-[14px] text-ink-3">
                    v{{ sms.phone.version }} ·
                    {{
                        t('শেষ heartbeat {ago}', {
                            ago: sms.phone.seen ? ago(sms.phone.seen) : '—',
                        })
                    }}
                </span>
            </div>
            <p class="mt-2 text-[14px] text-ink-3">
                {{ t('শেষ payment SMS') }}
                <b class="num text-ink">{{
                    sms.last_sms ? ago(sms.last_sms) : '—'
                }}</b>
            </p>
            <div class="mt-3">
                <KButton href="/admin/sms" size="sm" variant="plain">{{
                    t('SMS Center')
                }}</KButton>
            </div>
        </SettingsSection>

        <!-- Mail, app, errors -->
        <SettingsSection :title="t('Email, app ও সমস্যা')" icon="warn">
            <ul class="divide-y divide-line text-[14.5px]">
                <li class="flex items-center justify-between gap-3 py-2">
                    <span>{{ t('Email পাঠানো') }}</span>
                    <KTag :tone="mail.ready ? 'in' : 'out'">{{
                        mail.ready ? t('চালু') : t('বন্ধ')
                    }}</KTag>
                </li>
                <li class="flex items-center justify-between gap-3 py-2">
                    <span>{{ t('Phone app version') }}</span>
                    <span class="num text-ink-2"
                        >{{ app.plain ?? '—' }} · SMS {{ app.sms ?? '—' }}</span
                    >
                </li>
                <li class="flex items-center justify-between gap-3 py-2">
                    <span>{{ t('আজকের server error') }}</span>
                    <Link
                        href="/admin/errors"
                        class="inline-flex items-center gap-1.5 font-semibold"
                        :class="errors_today ? 'text-out' : 'text-in'"
                    >
                        <KIcon
                            :name="errors_today ? 'warn' : 'check'"
                            :size="15"
                        />{{ errors_today }}
                    </Link>
                </li>
            </ul>
            <p class="mt-2 text-[13.5px] text-ink-3">
                {{
                    t(
                        'নতুন কোনো server error হলে সব Owner-এর কাছে notification যায়, একই error ঘণ্টায় একবার।',
                    )
                }}
            </p>
        </SettingsSection>
    </div>
</template>

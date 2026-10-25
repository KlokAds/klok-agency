<script setup lang="ts">
import SettingsNav from '@/components/SettingsNav.vue';
import { router } from '@inertiajs/vue3';
import { reactive, watch } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import KEmpty from '@/components/k/KEmpty.vue';
import KIcon from '@/components/k/KIcon.vue';
import KPager from '@/components/k/KPager.vue';
import KSelect from '@/components/k/KSelect.vue';
import KTag from '@/components/k/KTag.vue';
import { ago } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { Option, Paginated } from '@/types';

type Activity = {
    id: number;
    log: string | null;
    event: string;
    subject_id: number | string | null;
    by: string;
    changes: {
        attributes?: Record<string, unknown>;
        old?: Record<string, unknown>;
    } | null;
    properties: Record<string, unknown> | null;
    at: string;
};

const props = defineProps<{
    activities: Paginated<Activity>;
    filters: Record<string, string | null>;
    users: Option[];
    logs: Option[];
}>();

const filters = reactive({
    user_id: props.filters.user_id ?? null,
    log: props.filters.log ?? null,
});

watch(filters, () => {
    const query = Object.fromEntries(
        Object.entries(filters).filter(([, v]) => v),
    );
    router.get('/activity', query, { preserveState: true, replace: true });
});

const events: Record<
    string,
    { label: string; tone: 'in' | 'out' | 'wait' | 'neutral' | 'brand' }
> = {
    created: { label: 'নতুন', tone: 'in' }, // i18n
    updated: { label: 'বদল', tone: 'wait' }, // i18n
    deleted: { label: 'মোছা', tone: 'out' }, // i18n
    restored: { label: 'ফেরত', tone: 'brand' }, // i18n
    login: { label: 'Login', tone: 'neutral' },
};

// Amount-like fields are stored in minor units.
const MONEY = [
    'amount',
    'usd',
    'bdt',
    'monthly_salary',
    'app_balance',
    'system_balance',
];

function show(key: string, value: unknown): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    if (MONEY.includes(key) && typeof value === 'number') {
        return (value / 100).toLocaleString('en-US', {
            minimumFractionDigits: 2,
        });
    }

    return String(value).slice(0, 80);
}

function diff(a: Activity): { key: string; from: string | null; to: string }[] {
    const now = a.changes?.attributes ?? {};
    const old = a.changes?.old ?? {};

    return Object.keys(now)
        .filter((k) => !['sequence', 'created_by', 'id'].includes(k))
        .map((k) => ({
            key: k,
            from: k in old ? show(k, old[k]) : null,
            to: show(k, now[k]),
        }));
}
</script>

<template>
    <SettingsNav />
    <PageHeader
        eyebrow="Settings"
        title="Activity log"
        :lead="
            t(
                'কে, কখন, কী বদলেছে — আগের আর পরের মান সহ। এই log কেউ বদলাতে বা মুছতে পারে না।',
            )
        "
    />

    <div class="mb-4 grid max-w-xl grid-cols-1 gap-3 sm:grid-cols-2">
        <KSelect
            v-model="filters.user_id"
            :options="users"
            :placeholder="t('সব user')"
            clearable
            size="sm"
        />
        <KSelect
            v-model="filters.log"
            :options="logs"
            :placeholder="t('সব ধরন')"
            clearable
            size="sm"
        />
    </div>

    <section class="panel overflow-hidden">
        <KEmpty v-if="!activities.data.length" :title="t('এখনো কিছু নেই')" />
        <ol v-else class="divide-y divide-dashed divide-line">
            <li
                v-for="a in activities.data"
                :key="a.id"
                class="grid grid-cols-1 gap-2 px-5 py-4 sm:grid-cols-[180px_1fr] sm:px-6"
            >
                <!-- Phone: who and when on one line; wider: their own column. -->
                <div
                    class="flex flex-wrap items-baseline gap-x-2 sm:flex-col sm:gap-0"
                >
                    <p class="text-[13.5px] font-semibold">{{ a.by }}</p>
                    <p
                        class="text-[12px] whitespace-nowrap text-ink-3"
                        :title="a.at"
                    >
                        {{ ago(a.at) }}
                    </p>
                </div>
                <div class="min-w-0">
                    <p class="flex flex-wrap items-center gap-2 text-[13.5px]">
                        <KTag :tone="events[a.event]?.tone ?? 'neutral'">{{
                            events[a.event] ? t(events[a.event].label) : a.event
                        }}</KTag>
                        <span class="font-medium">{{ a.log }}</span>
                        <span
                            v-if="a.subject_id"
                            class="num text-[12px] text-ink-3"
                            >#{{ a.subject_id }}</span
                        >
                        <span
                            v-if="a.properties?.ip"
                            class="num text-[12px] text-ink-3"
                            >{{ a.properties.ip }}</span
                        >
                    </p>
                    <dl
                        v-if="diff(a).length"
                        class="mt-2 grid grid-cols-1 gap-x-4 gap-y-1 text-[12.5px] sm:grid-cols-[140px_1fr]"
                    >
                        <template v-for="d in diff(a)" :key="d.key">
                            <dt class="num truncate text-ink-3">{{ d.key }}</dt>
                            <dd class="min-w-0 break-words">
                                <template v-if="d.from !== null"
                                    ><span class="text-out line-through">{{
                                        d.from
                                    }}</span>
                                    <KIcon
                                        name="arrow"
                                        :size="12"
                                        class="inline text-ink-3"
                                    />
                                </template>
                                <span
                                    :class="
                                        d.from !== null && 'font-medium text-in'
                                    "
                                    >{{ d.to }}</span
                                >
                            </dd>
                        </template>
                    </dl>
                </div>
            </li>
        </ol>
        <KPager :page="activities" :sizes="[10, 25, 50, 100]" />
    </section>
</template>

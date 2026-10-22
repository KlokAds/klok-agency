<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import KButton from '@/components/k/KButton.vue';
import KDate from '@/components/k/KDate.vue';
import KEmpty from '@/components/k/KEmpty.vue';
import KField from '@/components/k/KField.vue';
import KIcon from '@/components/k/KIcon.vue';
import KInput from '@/components/k/KInput.vue';
import KModal from '@/components/k/KModal.vue';
import KPager from '@/components/k/KPager.vue';
import KMoney from '@/components/k/KMoney.vue';
import KSegment from '@/components/k/KSegment.vue';
import KSelect from '@/components/k/KSelect.vue';
import KTag from '@/components/k/KTag.vue';
import KTextarea from '@/components/k/KTextarea.vue';
import { confirmAction } from '@/lib/feedback';
import { money, niceDate, toInput, today } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { Option, Paginated } from '@/types';

type Run = {
    id: number;
    title: string;
    client: string | null;
    client_id: number;
    platform: string | null;
    account_id: number;
    budget_usd: number;
    daily_usd: number | null;
    days: number;
    start_on: string;
    end_on: string;
    days_left: number;
    progress: number;
    remind_days_before: number;
    status: 'running' | 'ended' | 'renewed' | 'stopped';
    result: 'good' | 'ok' | 'bad' | null;
    results: number | null;
    cost_per_result: number | null;
    result_note: string | null;
    assigned_to: number | null;
    assignee: string | null;
    notes: string | null;
    open_tasks: number;
    whatsapp: string | null;
    sms: string | null;
    message: string;
};

const props = defineProps<{
    runs: Paginated<Run>;
    filters: { view: string; client_id: number | null; focus: number | null };
    counts: { running: number; ending: number };
    options: { clients: Option[]; platforms: Option[]; users: Option[] };
}>();

const can = computed(() => usePage().props.auth.can);

const view = ref(props.filters.view);
const clientId = ref<number | null>(props.filters.client_id);

function reload(): void {
    router.get(
        '/runs',
        { view: view.value, client_id: clientId.value ?? undefined },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

const views = computed<Option[]>(() => [
    { value: 'running', label: t('চলছে') + ` · ${props.counts.running}` },
    { value: 'ending', label: t('শেষ হচ্ছে') + ` · ${props.counts.ending}` },
    { value: 'ended', label: t('শেষ') },
    { value: 'all', label: t('সব') },
]);

const STATUS: Record<Run['status'], string> = {
    running: 'চলছে', // i18n
    ended: 'শেষ', // i18n
    renewed: 'Renew হয়েছে', // i18n
    stopped: 'বন্ধ করা', // i18n
};

const RESULT: Record<string, { label: string; tone: 'in' | 'wait' | 'out' }> = {
    good: { label: 'ভালো', tone: 'in' }, // i18n
    ok: { label: 'মাঝারি', tone: 'wait' }, // i18n
    bad: { label: 'খারাপ', tone: 'out' }, // i18n
};

function leftLabel(run: Run): string {
    if (run.status !== 'running') {
        return t(STATUS[run.status]);
    }

    if (run.days_left < 0) {
        return t('শেষ');
    }

    return run.days_left === 0
        ? t('আজ শেষ')
        : t('{n} দিন বাকি', { n: run.days_left });
}

// Add / edit
const editOpen = ref(false);
const editing = ref<Run | null>(null);
const form = useForm({
    client_id: null as number | null,
    account_id: null as number | null,
    title: '',
    budget_usd: '' as string | null,
    daily_usd: '' as string | null,
    days: '' as string | null,
    start_on: today() as string | null,
    remind_days_before: 1,
    assigned_to: null as number | null,
    notes: '' as string | null,
});

const remindOptions = computed<Option[]>(() => [
    { value: 0, label: t('শেষ দিনে') },
    { value: 1, label: t('১ দিন আগে') },
    { value: 2, label: t('২ দিন আগে') },
    { value: 3, label: t('৩ দিন আগে') },
]);

function startNew(): void {
    editing.value = null;
    form.reset();
    form.clearErrors();
    form.client_id = clientId.value;
    form.account_id = (props.options.platforms[0]?.value as number) ?? null;
    editOpen.value = true;
}

function startEdit(run: Run): void {
    editing.value = run;
    form.clearErrors();
    Object.assign(form, {
        client_id: run.client_id,
        account_id: run.account_id,
        title: run.title,
        budget_usd: toInput(run.budget_usd),
        daily_usd: toInput(run.daily_usd),
        days: String(run.days),
        start_on: run.start_on,
        remind_days_before: run.remind_days_before,
        assigned_to: run.assigned_to,
        notes: run.notes ?? '',
    });
    editOpen.value = true;
}

const endPreview = computed(() => {
    const days = Number(form.days) || 0;
    const budget = parseFloat(form.budget_usd || '0');
    const daily = parseFloat(form.daily_usd || '0');
    const n = days || (daily > 0 ? Math.ceil(budget / daily) : 0);

    if (!n || !form.start_on) {
        return null;
    }

    const end = new Date(form.start_on);
    end.setDate(end.getDate() + n - 1);

    return { days: n, end: end.toISOString().slice(0, 10) };
});

function save(): void {
    const opts = {
        preserveScroll: true,
        onSuccess: () => (editOpen.value = false),
    };

    if (editing.value) {
        form.put(`/runs/${editing.value.id}`, opts);
    } else {
        form.post('/runs', opts);
    }
}

// Result
const resultOpen = ref(false);
const resultRun = ref<Run | null>(null);
const resultForm = useForm({
    result: 'good',
    results: '' as string | null,
    cost_per_result: '' as string | null,
    result_note: '' as string | null,
});

const resultOptions = computed<Option[]>(() => [
    { value: 'good', label: t('ভালো') },
    { value: 'ok', label: t('মাঝারি') },
    { value: 'bad', label: t('খারাপ') },
]);

function startResult(run: Run): void {
    resultRun.value = run;
    Object.assign(resultForm, {
        result: run.result ?? 'good',
        results: run.results !== null ? String(run.results) : '',
        cost_per_result: toInput(run.cost_per_result),
        result_note: run.result_note ?? '',
    });
    resultOpen.value = true;
}

function saveResult(): void {
    resultForm.put(`/runs/${resultRun.value!.id}/result`, {
        preserveScroll: true,
        onSuccess: () => (resultOpen.value = false),
    });
}

// Renew
const renewOpen = ref(false);
const renewRun = ref<Run | null>(null);
const renewForm = useForm({
    budget_usd: '' as string | null,
    days: '' as string | null,
    start_on: null as string | null,
});

function startRenew(run: Run): void {
    renewRun.value = run;
    const next = new Date(run.end_on);
    next.setDate(next.getDate() + 1);
    Object.assign(renewForm, {
        budget_usd: toInput(run.budget_usd),
        days: String(run.days),
        start_on: next.toISOString().slice(0, 10),
    });
    renewOpen.value = true;
}

function saveRenew(): void {
    renewForm.post(`/runs/${renewRun.value!.id}/renew`, {
        preserveScroll: true,
        onSuccess: () => (renewOpen.value = false),
    });
}

async function stop(run: Run): Promise<void> {
    if (
        await confirmAction({
            title: t('Ad run বন্ধ করবেন?'),
            body: t('এর বাকি reminder আর আসবে না।'),
            confirm: t('বন্ধ করুন'),
            danger: true,
        })
    ) {
        router.post(`/runs/${run.id}/stop`, {}, { preserveScroll: true });
    }
}

async function remove(run: Run): Promise<void> {
    if (
        await confirmAction({
            title: t('Ad run মুছবেন?'),
            body: t('খাতার খরচ থেকে যাবে, শুধু এই হিসাব আর এর reminder মুছবে।'),
            confirm: t('মুছুন'),
            danger: true,
        })
    ) {
        router.delete(`/runs/${run.id}`, {
            preserveScroll: true,
            onSuccess: () => (editOpen.value = false),
        });
    }
}

function copyMessage(run: Run): void {
    void navigator.clipboard?.writeText(run.message);
}

onMounted(() => {
    if (props.filters.focus) {
        document
            .getElementById(`run-${props.filters.focus}`)
            ?.scrollIntoView({ block: 'center' });
    }
});
</script>

<template>
    <PageHeader
        :eyebrow="t('ব্যবসা')"
        :title="t('Ad চালানো')"
        :lead="
            t(
                'প্রতিটা client-এর ad কবে শুরু, কবে শেষ — শেষের আগে renew-এর SMS দেওয়ার reminder আসে, আর ফলাফল লিখলে scale করার কথা মনে করিয়ে দেয়।',
            )
        "
    >
        <KButton
            v-if="can['runs.create']"
            variant="ink"
            icon="plus"
            @click="startNew"
            >{{ t('নতুন ad run') }}</KButton
        >
    </PageHeader>

    <section class="no-print mb-5 flex flex-wrap items-center gap-3">
        <KSegment
            v-model="view"
            :options="views"
            size="sm"
            @update:model-value="reload"
        />
        <KSelect
            v-model="clientId"
            :options="options.clients"
            :placeholder="t('সব client')"
            clearable
            size="sm"
            class="w-56"
            @update:model-value="reload"
        />
    </section>

    <KEmpty
        v-if="!runs.data.length"
        :title="t('কোনো ad run নেই')"
        :body="
            t(
                'নতুন entry-তে ad খরচ লেখার সময় “কত দিন চলবে” দিলে এখানে নিজে চলে আসবে।',
            )
        "
    />

    <div v-else class="grid gap-4 lg:grid-cols-2">
        <article
            v-for="run in runs.data"
            :id="`run-${run.id}`"
            :key="run.id"
            class="panel flex flex-col p-5"
            :class="filters.focus === run.id && 'ring-2 ring-brand'"
        >
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="eyebrow">{{ run.client }} · {{ run.platform }}</p>
                    <p class="mt-1 truncate text-[16px] font-bold">
                        {{ run.title }}
                    </p>
                </div>
                <KTag
                    :tone="
                        run.status !== 'running'
                            ? 'neutral'
                            : run.days_left <= 1
                              ? 'wait'
                              : 'brand'
                    "
                    >{{ leftLabel(run) }}</KTag
                >
            </div>

            <dl class="mt-4 grid grid-cols-3 gap-3 text-[12.5px]">
                <div>
                    <dt class="text-ink-3">Budget</dt>
                    <dd class="num mt-0.5 text-[15px] font-medium">
                        {{ money(run.budget_usd, 'usd') }}
                    </dd>
                </div>
                <div>
                    <dt class="text-ink-3">{{ t('দৈনিক') }}</dt>
                    <dd class="num mt-0.5 text-[15px] font-medium">
                        {{ money(run.daily_usd, 'usd') }}
                    </dd>
                </div>
                <div>
                    <dt class="text-ink-3">{{ t('তারিখ') }}</dt>
                    <dd class="num mt-0.5 text-[13px]">
                        {{ niceDate(run.start_on, false) }} –
                        {{ niceDate(run.end_on, false) }}
                    </dd>
                </div>
            </dl>

            <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-sunken">
                <div
                    class="h-full rounded-full"
                    :class="run.status === 'running' ? 'bg-brand' : 'bg-line-2'"
                    :style="{ width: `${run.progress}%` }"
                />
            </div>

            <div class="mt-3 flex flex-wrap items-center gap-2 text-[12.5px]">
                <KTag v-if="run.result" :tone="RESULT[run.result].tone">{{
                    t(RESULT[run.result].label)
                }}</KTag>
                <span v-if="run.results !== null" class="num text-ink-2"
                    >{{ run.results }} {{ t('result') }}</span
                >
                <span v-if="run.cost_per_result !== null" class="num text-ink-2"
                    >CPR {{ money(run.cost_per_result, 'usd') }}</span
                >
                <span v-if="run.assignee" class="text-ink-3"
                    >· {{ run.assignee }}</span
                >
                <span v-if="run.open_tasks" class="text-ink-3"
                    >·
                    {{ t('{n}টা reminder বাকি', { n: run.open_tasks }) }}</span
                >
            </div>
            <p v-if="run.result_note" class="mt-2 text-[12.5px] text-ink-2">
                {{ run.result_note }}
            </p>

            <div
                class="mt-auto flex flex-wrap items-center gap-2 border-t border-dashed border-line pt-3"
                :class="run.result_note ? 'mt-3' : 'mt-4'"
            >
                <a
                    v-if="run.whatsapp && run.status === 'running'"
                    :href="run.whatsapp"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex h-8 items-center gap-1.5 rounded-[10px] bg-in-soft px-3 text-[12.5px] font-semibold text-in hover:bg-in hover:text-white"
                    ><KIcon name="chat" :size="15" />WhatsApp</a
                >
                <a
                    v-if="run.sms && run.status === 'running'"
                    :href="run.sms"
                    class="inline-flex h-8 items-center rounded-[10px] bg-sunken px-3 text-[12.5px] font-semibold text-ink-2 hover:bg-ink hover:text-paper"
                    >SMS</a
                >
                <KButton
                    v-if="!run.whatsapp && run.status === 'running'"
                    size="sm"
                    variant="quiet"
                    @click="copyMessage(run)"
                    >{{ t('লেখা copy') }}</KButton
                >
                <span class="flex-1" />
                <KButton
                    v-if="can['runs.edit']"
                    size="sm"
                    @click="startResult(run)"
                    >{{ t('ফলাফল') }}</KButton
                >
                <KButton
                    v-if="can['runs.create'] && run.status !== 'renewed'"
                    size="sm"
                    variant="primary"
                    @click="startRenew(run)"
                    >Renew</KButton
                >
                <button
                    v-if="can['runs.edit']"
                    type="button"
                    class="rounded-md p-1.5 text-ink-3 hover:bg-sunken hover:text-ink"
                    :aria-label="t('বদলান')"
                    @click="startEdit(run)"
                >
                    <KIcon name="pen" :size="16" />
                </button>
            </div>
        </article>
    </div>
    <KPager :page="runs" :sizes="[10, 25, 50, 100]" class="mt-4" />

    <!-- Add / edit -->
    <KModal
        v-model="editOpen"
        :title="editing ? editing.title : t('নতুন ad run')"
        :subtitle="
            t(
                'কত দিন চলবে দিন, অথবা দৈনিক budget দিন — শেষের তারিখ নিজে বের হবে।',
            )
        "
    >
        <form
            id="run-form"
            class="grid grid-cols-1 gap-4 sm:grid-cols-2"
            @submit.prevent="save"
        >
            <KField label="Client" :error="form.errors.client_id">
                <KSelect
                    v-model="form.client_id"
                    :options="options.clients"
                    :invalid="!!form.errors.client_id"
                />
            </KField>
            <KField label="Platform" :error="form.errors.account_id">
                <KSelect
                    v-model="form.account_id"
                    :options="options.platforms"
                />
            </KField>
            <KField
                :label="t('নাম')"
                :error="form.errors.title"
                class="sm:col-span-2"
            >
                <KInput
                    v-model="form.title"
                    :placeholder="t('যেমন Eid sale — Messages')"
                    :invalid="!!form.errors.title"
                />
            </KField>
            <KField label="Budget" :error="form.errors.budget_usd">
                <KMoney
                    v-model="form.budget_usd"
                    currency="usd"
                    :invalid="!!form.errors.budget_usd"
                />
            </KField>
            <KField :label="t('শুরুর তারিখ')" :error="form.errors.start_on">
                <KDate v-model="form.start_on" />
            </KField>
            <KField :label="t('কত দিন')" :error="form.errors.days">
                <KInput
                    v-model="form.days"
                    inputmode="numeric"
                    :placeholder="t('যেমন 7')"
                    :invalid="!!form.errors.days"
                />
            </KField>
            <KField
                :label="t('অথবা দৈনিক budget')"
                optional
                :error="form.errors.daily_usd"
            >
                <KMoney v-model="form.daily_usd" currency="usd" />
            </KField>
            <p
                v-if="endPreview"
                class="rounded-xl bg-brand-soft px-4 py-2.5 text-[13px] text-brand-ink sm:col-span-2"
            >
                {{
                    t('{n} দিন চলবে — শেষ {date}', {
                        n: endPreview.days,
                        date: niceDate(endPreview.end),
                    })
                }}
            </p>
            <KField :label="t('Renew-এর reminder কখন')" class="sm:col-span-2">
                <KSegment
                    v-model="form.remind_days_before"
                    :options="remindOptions"
                    size="sm"
                />
            </KField>
            <KField :label="t('দায়িত্বে')" optional>
                <KSelect
                    v-model="form.assigned_to"
                    :options="options.users"
                    :placeholder="t('Client-এর manager')"
                    clearable
                />
            </KField>
            <KField label="Note" optional class="sm:col-span-2">
                <KTextarea v-model="form.notes" />
            </KField>
        </form>
        <template #footer>
            <template v-if="editing">
                <KButton
                    v-if="can['runs.delete']"
                    variant="danger"
                    icon="bin"
                    @click="remove(editing)"
                    >{{ t('মুছুন') }}</KButton
                >
                <KButton
                    v-if="editing.status === 'running'"
                    variant="quiet"
                    class="mr-auto"
                    @click="stop(editing)"
                    >{{ t('বন্ধ করুন') }}</KButton
                >
            </template>
            <KButton variant="quiet" @click="editOpen = false">{{
                t('বাতিল')
            }}</KButton>
            <KButton
                type="submit"
                form="run-form"
                variant="primary"
                :loading="form.processing"
                >{{ t('সংরক্ষণ') }}</KButton
            >
        </template>
    </KModal>

    <!-- Result -->
    <KModal
        v-model="resultOpen"
        width="sm"
        :title="t('Ad কেমন চলছে?')"
        :subtitle="resultRun ? `${resultRun.client} · ${resultRun.title}` : ''"
    >
        <form
            id="result-form"
            class="flex flex-col gap-4"
            @submit.prevent="saveResult"
        >
            <KSegment v-model="resultForm.result" :options="resultOptions" />
            <div class="grid grid-cols-2 gap-3">
                <KField :label="t('কতগুলো result')" optional>
                    <KInput v-model="resultForm.results" inputmode="numeric" />
                </KField>
                <KField label="Cost per result" optional>
                    <KMoney
                        v-model="resultForm.cost_per_result"
                        currency="usd"
                    />
                </KField>
            </div>
            <KField label="Note" optional>
                <KTextarea
                    v-model="resultForm.result_note"
                    :placeholder="t('কী ভালো বা খারাপ — audience, creative…')"
                />
            </KField>
            <p class="text-[12.5px] text-ink-3">
                {{
                    t(
                        'ভালো হলে কাল budget বাড়ানোর, খারাপ হলে ঠিক করার reminder আসবে।',
                    )
                }}
            </p>
        </form>
        <template #footer>
            <KButton variant="quiet" @click="resultOpen = false">{{
                t('বাতিল')
            }}</KButton>
            <KButton
                type="submit"
                form="result-form"
                variant="primary"
                :loading="resultForm.processing"
                >{{ t('সংরক্ষণ') }}</KButton
            >
        </template>
    </KModal>

    <!-- Renew -->
    <KModal
        v-model="renewOpen"
        width="sm"
        title="Renew"
        :subtitle="renewRun ? `${renewRun.client} · ${renewRun.title}` : ''"
    >
        <form
            id="renew-form"
            class="flex flex-col gap-4"
            @submit.prevent="saveRenew"
        >
            <KField label="Budget" :error="renewForm.errors.budget_usd">
                <KMoney v-model="renewForm.budget_usd" currency="usd" />
            </KField>
            <div class="grid grid-cols-2 gap-3">
                <KField :label="t('কত দিন')" :error="renewForm.errors.days">
                    <KInput v-model="renewForm.days" inputmode="numeric" />
                </KField>
                <KField :label="t('শুরুর তারিখ')">
                    <KDate v-model="renewForm.start_on" />
                </KField>
            </div>
        </form>
        <template #footer>
            <KButton variant="quiet" @click="renewOpen = false">{{
                t('বাতিল')
            }}</KButton>
            <KButton
                type="submit"
                form="renew-form"
                variant="primary"
                :loading="renewForm.processing"
                >Renew</KButton
            >
        </template>
    </KModal>
</template>

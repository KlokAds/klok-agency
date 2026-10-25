<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import TaskForm from '@/components/TaskForm.vue';
import type { TaskRow } from '@/components/TaskForm.vue';
import KButton from '@/components/k/KButton.vue';
import KEmpty from '@/components/k/KEmpty.vue';
import KIcon from '@/components/k/KIcon.vue';
import KPager from '@/components/k/KPager.vue';
import KSegment from '@/components/k/KSegment.vue';
import KSelect from '@/components/k/KSelect.vue';
import KTag from '@/components/k/KTag.vue';
import { confirmAction } from '@/lib/feedback';
import { niceDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { Option, Paginated } from '@/types';

type Task = TaskRow & {
    client: string | null;
    ad_run: string | null;
    ad_run_id: number | null;
    assignee: string | null;
    done_at: string | null;
    overdue: boolean;
    auto: boolean;
};

const props = defineProps<{
    tasks: Paginated<Task>;
    filters: {
        view: string;
        who: string;
        client_id: number | null;
        focus: number | null;
    };
    counts: { open: number; today: number; mine: number };
    options: {
        clients: Option[];
        users: Option[];
        kinds: Option[];
        repeats: Option[];
    };
}>();

const can = computed(() => usePage().props.auth.can);

const view = ref(props.filters.view);
const who = ref(props.filters.who);
const clientId = ref<number | null>(props.filters.client_id);

function reload(): void {
    router.get(
        '/tasks',
        {
            view: view.value,
            who: who.value,
            client_id: clientId.value ?? undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

const views = computed<Option[]>(() => [
    { value: 'today', label: t('আজ ও আগের') + ` · ${props.counts.today}` },
    { value: 'open', label: t('সব বাকি') + ` · ${props.counts.open}` },
    { value: 'done', label: t('শেষ হয়েছে') },
]);
const whos = computed<Option[]>(() => [
    { value: 'all', label: t('সবার') },
    { value: 'mine', label: t('আমার') + ` · ${props.counts.mine}` },
]);

const KIND: Record<string, { label: string; icon: string }> = {
    task: { label: 'কাজ', icon: 'check' }, // i18n
    meeting: { label: 'Meeting', icon: 'calendar' },
    follow_up: { label: 'Follow-up', icon: 'chat' },
    renewal: { label: 'Renew-এর SMS', icon: 'chat' }, // i18n
    result: { label: 'ফলাফল লেখা', icon: 'list' }, // i18n
    scale: { label: 'Scale / optimize', icon: 'rocket' },
};

function when(task: Task): string {
    if (!task.due_at) {
        return t('তারিখ নেই');
    }

    return `${niceDate(task.due_at.slice(0, 10))} · ${task.due_at.slice(11, 16)}`;
}

const formOpen = ref(false);
const editing = ref<Task | null>(null);

function startNew(): void {
    editing.value = null;
    formOpen.value = true;
}

function startEdit(task: Task): void {
    editing.value = task;
    formOpen.value = true;
}

function toggle(task: Task): void {
    router.post(`/tasks/${task.id}/toggle`, {}, { preserveScroll: true });
}

async function remove(task: Task): Promise<void> {
    if (
        await confirmAction({
            title: t('কাজ মুছবেন?'),
            body: task.title,
            confirm: t('মুছুন'),
            danger: true,
        })
    ) {
        router.delete(`/tasks/${task.id}`, { preserveScroll: true });
    }
}

onMounted(() => {
    if (props.filters.focus) {
        document
            .getElementById(`task-${props.filters.focus}`)
            ?.scrollIntoView({ block: 'center' });
    }
});
</script>

<template>
    <PageHeader
        :eyebrow="t('ব্যবসা')"
        :title="t('কাজ ও reminder')"
        :lead="
            t(
                'Agency-র সব কাজ, client meeting আর follow-up এক জায়গায় — সময় হলে যাকে কাজ দেওয়া আর Owner-এর কাছে notification যায়।',
            )
        "
    >
        <KButton
            v-if="can['tasks.create']"
            variant="ink"
            icon="plus"
            @click="startNew"
            >{{ t('নতুন কাজ') }}</KButton
        >
    </PageHeader>

    <section class="no-print mb-5 flex flex-wrap items-center gap-3">
        <KSegment
            v-model="view"
            :options="views"
            size="sm"
            @update:model-value="reload"
        />
        <KSegment
            v-model="who"
            :options="whos"
            size="sm"
            @update:model-value="reload"
        />
        <KSelect
            v-model="clientId"
            :options="options.clients"
            :placeholder="t('সব client')"
            clearable
            size="sm"
            class="w-full sm:w-56"
            @update:model-value="reload"
        />
    </section>

    <section class="panel overflow-hidden">
        <KEmpty
            v-if="!tasks.data.length"
            :title="
                view === 'done'
                    ? t('এখনো কিছু শেষ হয়নি')
                    : t('কোনো কাজ বাকি নেই')
            "
        />
        <ul v-else class="divide-y divide-dashed divide-line">
            <li
                v-for="task in tasks.data"
                :id="`task-${task.id}`"
                :key="task.id"
                class="flex items-start gap-3 px-4 py-3.5 sm:px-5"
                :class="filters.focus === task.id && 'bg-brand-soft'"
            >
                <button
                    type="button"
                    class="mt-0.5 grid size-[22px] shrink-0 place-items-center rounded-[7px] border-[1.5px] transition-colors"
                    :class="
                        task.done_at
                            ? 'border-in bg-in text-white'
                            : 'border-line-2 hover:border-in'
                    "
                    :disabled="!can['tasks.edit']"
                    :aria-label="
                        task.done_at ? t('আবার খুলুন') : t('শেষ হয়েছে')
                    "
                    @click="toggle(task)"
                >
                    <KIcon v-if="task.done_at" name="check" :size="14" />
                </button>
                <div class="min-w-0 flex-1">
                    <p
                        class="text-[14px] font-semibold"
                        :class="task.done_at && 'text-ink-3 line-through'"
                    >
                        <span v-if="task.client" class="text-brand-ink"
                            >{{ task.client }}:
                        </span>
                        {{ t(task.title) }}
                    </p>
                    <p
                        class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-[12px] text-ink-3"
                    >
                        <span class="flex items-center gap-1">
                            <KIcon
                                :name="KIND[task.kind]?.icon ?? 'check'"
                                :size="13"
                            />{{ t(KIND[task.kind]?.label ?? task.kind) }}
                        </span>
                        <span
                            :class="task.overdue && 'font-semibold text-out'"
                            >{{ when(task) }}</span
                        >
                        <span v-if="task.assignee">· {{ task.assignee }}</span>
                        <span
                            v-if="task.repeat !== 'none'"
                            class="flex items-center gap-1"
                            ><KIcon name="undo" :size="12" />{{
                                t('বারবার')
                            }}</span
                        >
                        <Link
                            v-if="task.ad_run_id"
                            :href="`/runs?focus=${task.ad_run_id}&view=all`"
                            class="underline-offset-2 hover:underline"
                            >{{ task.ad_run }}</Link
                        >
                    </p>
                    <p v-if="task.notes" class="mt-1 text-[12.5px] text-ink-2">
                        {{ task.notes }}
                    </p>
                </div>
                <KTag v-if="task.priority === 'high'" tone="out">{{
                    t('জরুরি')
                }}</KTag>
                <!-- Always there: a phone has no hover to reveal them. -->
                <div class="-mt-1 -mr-2 flex shrink-0">
                    <button
                        v-if="can['tasks.edit'] && !task.auto"
                        type="button"
                        class="grid size-9 place-items-center rounded-lg text-ink-3 hover:bg-sunken hover:text-ink"
                        :aria-label="t('বদলান')"
                        @click="startEdit(task)"
                    >
                        <KIcon name="pen" :size="16" />
                    </button>
                    <button
                        v-if="can['tasks.delete']"
                        type="button"
                        class="grid size-9 place-items-center rounded-lg text-ink-3 hover:bg-out-soft hover:text-out"
                        :aria-label="t('মুছুন')"
                        @click="remove(task)"
                    >
                        <KIcon name="bin" :size="16" />
                    </button>
                </div>
            </li>
        </ul>
        <KPager :page="tasks" :sizes="[10, 25, 50, 100]" />
    </section>

    <TaskForm v-model="formOpen" :task="editing" :options="options" />
</template>

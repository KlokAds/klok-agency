<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import KButton from '@/components/k/KButton.vue';
import KDate from '@/components/k/KDate.vue';
import KField from '@/components/k/KField.vue';
import KInput from '@/components/k/KInput.vue';
import KModal from '@/components/k/KModal.vue';
import KSegment from '@/components/k/KSegment.vue';
import KSelect from '@/components/k/KSelect.vue';
import KTextarea from '@/components/k/KTextarea.vue';
import { today } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { Option } from '@/types';

export type TaskRow = {
    id: number;
    title: string;
    notes: string | null;
    kind: string;
    priority: string;
    client_id: number | null;
    assigned_to: number | null;
    due_at: string | null;
    remind_at: string | null;
    repeat: string;
};

// Add or change a task, client meeting or follow-up. Used on the tasks
// page and on a client's page (with the client already chosen).
const props = defineProps<{
    task: TaskRow | null;
    clientId?: number | null;
    options: {
        clients: Option[];
        users: Option[];
        kinds?: Option[];
        repeats?: Option[];
    };
}>();
const open = defineModel<boolean>({ default: false });

const form = useForm({
    title: '',
    notes: '' as string | null,
    kind: 'task',
    priority: 'normal',
    client_id: null as number | null,
    assigned_to: null as number | null,
    due_day: today() as string | null,
    due_time: '10:00',
    remind: 'due',
    repeat: 'none',
});

const kinds = computed<Option[]>(
    () =>
        props.options.kinds ?? [
            { value: 'task', label: t('কাজ') },
            { value: 'meeting', label: 'Meeting' },
            { value: 'follow_up', label: 'Follow-up' },
        ],
);
const repeats = computed<Option[]>(
    () =>
        props.options.repeats ?? [
            { value: 'none', label: t('একবার') },
            { value: 'daily', label: t('প্রতিদিন') },
            { value: 'weekly', label: t('প্রতি সপ্তাহে') },
            { value: 'monthly', label: t('প্রতি মাসে') },
        ],
);
const priorities = computed<Option[]>(() => [
    { value: 'normal', label: t('সাধারণ') },
    { value: 'high', label: t('জরুরি') },
]);
const remindOptions = computed<Option[]>(() => [
    { value: 'due', label: t('ঠিক সময়ে') },
    { value: '60', label: t('১ ঘণ্টা আগে') },
    { value: '1440', label: t('১ দিন আগে') },
    { value: 'none', label: t('Reminder না') },
]);
const times = computed<Option[]>(() =>
    Array.from({ length: 30 }, (_, i) => {
        const h = 8 + Math.floor(i / 2);
        const value = `${String(h).padStart(2, '0')}:${i % 2 ? '30' : '00'}`;

        return { value, label: value };
    }),
);

// The server checks due_at, which the form sends after joining day and time.
const dueError = computed(
    () => (form.errors as Record<string, string | undefined>).due_at,
);

watch(open, (value) => {
    if (!value) {
        return;
    }

    form.clearErrors();
    const task = props.task;
    const due = task?.due_at ?? null;

    Object.assign(form, {
        title: task?.title ?? '',
        notes: task?.notes ?? '',
        kind: task?.kind ?? 'task',
        priority: task?.priority ?? 'normal',
        client_id: task?.client_id ?? props.clientId ?? null,
        assigned_to: task?.assigned_to ?? null,
        due_day: due ? due.slice(0, 10) : today(),
        due_time: due ? due.slice(11, 16) : '10:00',
        remind: task && !task.remind_at ? 'none' : 'due',
        repeat: task?.repeat ?? 'none',
    });
});

function save(): void {
    form.transform((d) => {
        const due = d.due_day ? `${d.due_day} ${d.due_time}` : null;
        let remind: string | null = null;

        if (due && d.remind !== 'none') {
            const at = new Date(due.replace(' ', 'T'));
            at.setMinutes(
                at.getMinutes() - (d.remind === 'due' ? 0 : Number(d.remind)),
            );
            remind = `${at.getFullYear()}-${String(at.getMonth() + 1).padStart(2, '0')}-${String(at.getDate()).padStart(2, '0')} ${String(at.getHours()).padStart(2, '0')}:${String(at.getMinutes()).padStart(2, '0')}`;
        }

        return {
            title: d.title,
            notes: d.notes,
            kind: d.kind,
            priority: d.priority,
            client_id: d.client_id,
            assigned_to: d.assigned_to,
            due_at: due,
            remind_at: remind,
            repeat: d.repeat,
        };
    });

    const opts = {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    };

    if (props.task) {
        form.put(`/tasks/${props.task.id}`, opts);
    } else {
        form.post('/tasks', opts);
    }
}
</script>

<template>
    <KModal
        v-model="open"
        :title="task ? t('কাজ বদলান') : t('নতুন কাজ বা reminder')"
    >
        <form
            id="task-form"
            class="grid grid-cols-1 gap-4 sm:grid-cols-2"
            @submit.prevent="save"
        >
            <KField
                :label="t('কী করতে হবে')"
                :error="form.errors.title"
                class="sm:col-span-2"
            >
                <KInput
                    v-model="form.title"
                    :placeholder="t('যেমন GFFS-এর সাথে monthly meeting')"
                    :invalid="!!form.errors.title"
                />
            </KField>
            <KField :label="t('ধরন')">
                <KSegment v-model="form.kind" :options="kinds" size="sm" />
            </KField>
            <KField :label="t('গুরুত্ব')">
                <KSegment
                    v-model="form.priority"
                    :options="priorities"
                    size="sm"
                />
            </KField>
            <KField label="Client" optional>
                <KSelect
                    v-model="form.client_id"
                    :options="options.clients"
                    :placeholder="t('কোনো client না')"
                    clearable
                />
            </KField>
            <KField :label="t('কাকে দেওয়া')" optional>
                <KSelect
                    v-model="form.assigned_to"
                    :options="options.users"
                    :placeholder="t('Client-এর manager')"
                    clearable
                />
            </KField>
            <KField :label="t('কবে')" :error="dueError">
                <KDate
                    v-model="form.due_day"
                    clearable
                    :placeholder="t('তারিখ নেই')"
                />
            </KField>
            <KField :label="t('সময়')">
                <KSelect
                    v-model="form.due_time"
                    :options="times"
                    :searchable="false"
                />
            </KField>
            <KField label="Reminder" class="sm:col-span-2">
                <KSegment
                    v-model="form.remind"
                    :options="remindOptions"
                    size="sm"
                />
            </KField>
            <KField :label="t('বারবার')" class="sm:col-span-2">
                <KSegment v-model="form.repeat" :options="repeats" size="sm" />
            </KField>
            <KField label="Note" optional class="sm:col-span-2">
                <KTextarea v-model="form.notes" />
            </KField>
        </form>
        <template #footer>
            <KButton variant="quiet" @click="open = false">{{
                t('বাতিল')
            }}</KButton>
            <KButton
                type="submit"
                form="task-form"
                variant="primary"
                :loading="form.processing"
                >{{ t('সংরক্ষণ') }}</KButton
            >
        </template>
    </KModal>
</template>

<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import KButton from '@/components/k/KButton.vue';
import KDate from '@/components/k/KDate.vue';
import KField from '@/components/k/KField.vue';
import KIcon from '@/components/k/KIcon.vue';
import KInput from '@/components/k/KInput.vue';
import KModal from '@/components/k/KModal.vue';
import KSegment from '@/components/k/KSegment.vue';
import KSwitch from '@/components/k/KSwitch.vue';
import KTag from '@/components/k/KTag.vue';
import { niceDate, today } from '@/lib/format';
import { getJson } from '@/lib/http';
import { t } from '@/lib/i18n';
import type { Option } from '@/types';

type Head = { id: number; name: string; is_active: boolean };
type Account = { id: number; code: string; name: string; kind: string; is_active: boolean };

const props = defineProps<{
    settings: { lock_date: string | null; require_2fa: boolean; idle_minutes: number; payment_fee_percent: string };
    heads: Head[];
    accounts: Account[];
    kinds: Option[];
}>();

const can = computed(() => usePage().props.auth.can);
const editable = computed(() => !!can.value['settings.edit']);

const form = useForm({ ...props.settings });
const idleOptions = computed<Option[]>(() => [15, 30, 60, 120].map((m) => ({ value: m, label: t('{n} মিনিট', { n: m }) })));

const headForm = useForm({ name: '' });

function addHead(): void {
    headForm.post('/admin/settings/heads', { preserveScroll: true, onSuccess: () => headForm.reset() });
}

function toggleHead(h: Head): void {
    useForm({ name: h.name, is_active: !h.is_active }).put(`/admin/settings/heads/${h.id}`, { preserveScroll: true });
}

const accountOpen = ref(false);
const editingAccount = ref<Account | null>(null);
const accountForm = useForm({ name: '', kind: 'platform', is_active: true });

function startAccount(a?: Account): void {
    editingAccount.value = a ?? null;
    accountForm.clearErrors();
    Object.assign(accountForm, a ? { name: a.name, kind: a.kind, is_active: a.is_active } : { name: '', kind: 'platform', is_active: true });
    accountOpen.value = true;
}

function saveAccount(): void {
    const opts = { preserveScroll: true, onSuccess: () => (accountOpen.value = false) };
    editingAccount.value ? accountForm.put(`/admin/settings/accounts/${editingAccount.value.id}`, opts) : accountForm.post('/admin/settings/accounts', opts);
}

const newKinds = computed(() => props.kinds.filter((k) => k.value !== 'card'));

// App update (Owner only): what is running, what GitHub has, and the last run's log.
type UpdateStatus = {
    available: boolean;
    branch: string;
    current: { sha: string; subject: string; date: string } | null;
    latest: string | null;
    behind: boolean | null;
    last_run: { ok: boolean; from: string | null; to: string | null; log: string[]; at: string } | null;
};

const isOwner = computed(() => usePage().props.auth.user.role === 'Owner');
const update = ref<UpdateStatus | null>(null);
const checking = ref(false);
const updateOpen = ref(false);
const updateForm = useForm({ password: '' });

async function checkUpdate(): Promise<void> {
    checking.value = true;
    try {
        update.value = await getJson<UpdateStatus>('/admin/update/status');
    } finally {
        checking.value = false;
    }
}

function runUpdate(): void {
    updateForm.post('/admin/update', {
        preserveScroll: true,
        onSuccess: () => {
            updateOpen.value = false;
            updateForm.reset();
            void checkUpdate();
        },
        onFinish: () => updateForm.reset('password'),
    });
}

onMounted(() => {
    if (isOwner.value) {
        void checkUpdate();
    }
});
</script>

<template>
    <PageHeader :eyebrow="t('নিয়ন্ত্রণ')" title="Settings" />

    <div class="grid gap-6 lg:grid-cols-2">
        <!-- Security & books -->
        <form class="panel flex flex-col gap-6 p-6" @submit.prevent="form.put('/admin/settings', { preserveScroll: true })">
            <div>
                <h2 class="flex items-center gap-2 text-[17px] font-bold"><KIcon name="shield" class="text-brand" />{{ t('নিরাপত্তা ও খাতা') }}</h2>
                <p class="mt-1 text-[13px] text-ink-3">{{ t('সবার জন্য প্রযোজ্য।') }}</p>
            </div>

            <KSwitch v-model="form.require_2fa" :label="t('সবার জন্য 2FA বাধ্যতামূলক')" :hint="t('চালু করলে 2FA ছাড়া কেউ খাতা খুলতে পারবে না — আগে নিজে চালু করুন।')" />
            <p v-if="form.errors.require_2fa" class="-mt-4 text-[12.5px] font-medium text-out">{{ form.errors.require_2fa }}</p>

            <KField :label="t('কাজ না করলে নিজে sign out')" :hint="t('শেষ ১ মিনিটে সতর্ক করা হবে।')">
                <KSegment v-model="form.idle_minutes" :options="idleOptions" size="sm" />
            </KField>

            <KField :label="t('Client payment থেকে fee (%)')" :error="form.errors.payment_fee_percent" :hint="t('প্রতিটা client payment থেকে এই % কেটে আলাদা খরচ হিসেবে দেখানো হয়। নতুন হার শুধু এখন থেকে লেখা payment-এ লাগবে।')">
                <KInput v-model="form.payment_fee_percent" inputmode="decimal" size="sm" class="max-w-[140px]">
                    <template #trail><span class="text-[13px] font-semibold text-ink-3">%</span></template>
                </KInput>
            </KField>

            <KField :label="t('খাতা বন্ধ (lock) — এই তারিখ পর্যন্ত')" :error="form.errors.lock_date" :hint="t('মাস শেষে হিসাব মিলিয়ে lock দিন — ওই সময়ের কোনো entry আর যোগ, বদল বা মোছা যাবে না। তারিখ ছাড়া পুরনো row-ও lock হবে।')">
                <KDate v-model="form.lock_date" :max="today()" clearable :placeholder="t('Lock নেই')" />
            </KField>

            <div v-if="editable" class="flex justify-end">
                <KButton type="submit" variant="primary" :loading="form.processing">{{ t('সংরক্ষণ') }}</KButton>
            </div>
        </form>

        <div class="flex flex-col gap-6">
            <!-- Expense heads -->
            <section class="panel p-6">
                <h2 class="text-[17px] font-bold">{{ t('খরচের খাত') }}</h2>
                <p class="mt-1 text-[13px] text-ink-3">{{ t('BDT খরচ লেখার সময় এগুলো থেকে বাছা যায়। বন্ধ করলে নতুন entry-তে দেখাবে না।') }}</p>
                <div class="mt-4 flex flex-wrap gap-2">
                    <button
                        v-for="h in heads"
                        :key="h.id"
                        type="button"
                        :disabled="!editable"
                        class="rounded-lg border px-3 py-1.5 text-[13px] font-medium transition-colors"
                        :class="h.is_active ? 'border-line-2 bg-surface hover:border-ink-3' : 'border-dashed border-line-2 text-ink-3 line-through'"
                        :title="h.is_active ? t('বন্ধ করতে চাপুন') : t('চালু করতে চাপুন')"
                        @click="toggleHead(h)"
                    >
                        {{ h.name }}
                    </button>
                </div>
                <form v-if="editable" class="mt-4 flex gap-2" @submit.prevent="addHead">
                    <KInput v-model="headForm.name" :placeholder="t('নতুন খাত, যেমন Office rent')" size="sm" class="flex-1" :invalid="!!headForm.errors.name" />
                    <KButton type="submit" size="sm" icon="plus" :loading="headForm.processing">{{ t('যোগ') }}</KButton>
                </form>
                <p v-if="headForm.errors.name" class="mt-1.5 text-[12.5px] text-out">{{ headForm.errors.name }}</p>
            </section>

            <!-- Accounts -->
            <section class="panel p-6">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="text-[17px] font-bold">{{ t('USD account ও platform') }}</h2>
                        <p class="mt-1 text-[13px] text-ink-3">{{ t('নতুন ad platform বা USD রাখার জায়গা।') }}</p>
                    </div>
                    <KButton v-if="editable" size="sm" icon="plus" @click="startAccount()">{{ t('যোগ') }}</KButton>
                </div>
                <ul class="mt-3">
                    <li v-for="a in accounts" :key="a.id" class="flex items-center gap-3 border-b border-dashed border-line py-2.5 text-[14px] last:border-0">
                        <span class="flex-1 font-medium" :class="!a.is_active && 'text-ink-3 line-through'">{{ a.name }}</span>
                        <KTag>{{ kinds.find((k) => k.value === a.kind)?.label }}</KTag>
                        <button v-if="editable" type="button" class="rounded-md p-1.5 text-ink-3 hover:bg-sunken hover:text-ink" :aria-label="t('বদলান')" @click="startAccount(a)"><KIcon name="pen" :size="15" /></button>
                    </li>
                </ul>
            </section>
        </div>
    </div>

    <!-- App update -->
    <section v-if="isOwner" class="panel mt-6 p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="flex items-center gap-2 text-[17px] font-bold"><KIcon name="down" class="text-brand" />{{ t('App update') }}</h2>
                <p class="mt-1 max-w-xl text-[13px] text-ink-3">{{ t('GitHub-এ নতুন code push হলে এখান থেকে এক click-এ live server-এ নিয়ে আসুন। Server নিজে pull, migrate আর cache করে নেবে।') }}</p>
            </div>
            <div class="flex gap-2">
                <KButton size="sm" icon="undo" :loading="checking" @click="checkUpdate">{{ t('আবার দেখুন') }}</KButton>
                <KButton size="sm" variant="primary" icon="down" :disabled="!update?.available" @click="updateOpen = true">{{ t('এখনই update করুন') }}</KButton>
            </div>
        </div>

        <div v-if="update" class="mt-5 grid gap-4 sm:grid-cols-3">
            <div class="rounded-xl bg-surface-2 px-4 py-3">
                <p class="eyebrow">{{ t('এখন চলছে') }}</p>
                <p v-if="update.current" class="mt-1 text-[13.5px]">
                    <span class="num font-semibold">{{ update.current.sha.slice(0, 7) }}</span>
                    <span class="ml-2 text-ink-3">{{ niceDate(update.current.date) }}</span>
                </p>
                <p v-if="update.current" class="mt-0.5 truncate text-[12.5px] text-ink-2" :title="update.current.subject">{{ update.current.subject }}</p>
                <p v-if="!update.available" class="mt-1 text-[13px] text-ink-3">{{ t('এটা git checkout নয় (local)') }}</p>
            </div>
            <div class="rounded-xl bg-surface-2 px-4 py-3">
                <p class="eyebrow">GitHub · {{ update.branch }}</p>
                <p class="num mt-1 text-[13.5px] font-semibold">{{ update.latest ? update.latest.slice(0, 7) : '—' }}</p>
            </div>
            <div class="flex items-center rounded-xl px-4 py-3" :class="update.behind ? 'bg-wait-soft text-wait' : 'bg-in-soft text-in'">
                <KIcon :name="update.behind ? 'warn' : 'check'" class="mr-2" />
                <span class="text-[13.5px] font-semibold">{{ update.behind ? t('নতুন version আছে') : update.behind === false ? t('সর্বশেষ version চলছে') : t('GitHub-এর সাথে মেলানো যায়নি') }}</span>
            </div>
        </div>

        <details v-if="update?.last_run" class="mt-4 rounded-xl border border-line">
            <summary class="flex cursor-pointer items-center gap-2 px-4 py-2.5 text-[13px] font-semibold">
                <KTag :tone="update.last_run.ok ? 'in' : 'out'">{{ update.last_run.ok ? t('সফল') : t('ব্যর্থ') }}</KTag>
                {{ t('শেষ update') }} · {{ niceDate(update.last_run.at.slice(0, 10)) }}
            </summary>
            <pre class="num max-h-72 overflow-auto border-t border-line bg-sunken px-4 py-3 text-[11.5px] leading-relaxed whitespace-pre-wrap">{{ update.last_run.log.join('\n\n') }}</pre>
        </details>
    </section>

    <KModal v-model="updateOpen" width="sm" :title="t('App update করবেন?')" :subtitle="t('কয়েক মিনিট সময় লাগতে পারে; এর মধ্যে app কিছুক্ষণ বন্ধ থাকবে।')">
        <form id="update-form" @submit.prevent="runUpdate">
            <KField :label="t('নিশ্চিত করতে আপনার password দিন')" :error="updateForm.errors.password">
                <KInput v-model="updateForm.password" type="password" autocomplete="current-password" :invalid="!!updateForm.errors.password" />
            </KField>
        </form>
        <template #footer>
            <KButton variant="quiet" @click="updateOpen = false">{{ t('বাতিল') }}</KButton>
            <KButton type="submit" form="update-form" variant="primary" :loading="updateForm.processing">{{ t('Update শুরু করুন') }}</KButton>
        </template>
    </KModal>

    <KModal v-model="accountOpen" width="sm" :title="editingAccount ? editingAccount.name : t('নতুন account')">
        <form id="acct-form" class="flex flex-col gap-4" @submit.prevent="saveAccount">
            <KField :label="t('নাম')" :error="accountForm.errors.name"><KInput v-model="accountForm.name" :placeholder="t('যেমন LinkedIn Ads')" /></KField>
            <KField v-if="!editingAccount" :label="t('ধরন')"><KSegment v-model="accountForm.kind" :options="newKinds" /></KField>
            <KSwitch v-if="editingAccount && editingAccount.kind !== 'card'" v-model="accountForm.is_active" :label="t('চালু')" />
        </form>
        <template #footer>
            <KButton variant="quiet" @click="accountOpen = false">{{ t('বাতিল') }}</KButton>
            <KButton type="submit" form="acct-form" variant="primary" :loading="accountForm.processing">{{ t('সংরক্ষণ') }}</KButton>
        </template>
    </KModal>
</template>

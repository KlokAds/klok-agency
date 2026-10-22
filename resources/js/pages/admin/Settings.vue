<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
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
import { today } from '@/lib/format';
import type { Option } from '@/types';

type Head = { id: number; name: string; is_active: boolean };
type Account = { id: number; code: string; name: string; kind: string; is_active: boolean };

const props = defineProps<{
    settings: { lock_date: string | null; require_2fa: boolean; idle_minutes: number };
    heads: Head[];
    accounts: Account[];
    kinds: Option[];
}>();

const can = computed(() => usePage().props.auth.can);
const editable = computed(() => !!can.value['settings.edit']);

const form = useForm({ ...props.settings });
const idleOptions: Option[] = [15, 30, 60, 120].map((m) => ({ value: m, label: `${m} মিনিট` }));

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
</script>

<template>
    <PageHeader eyebrow="নিয়ন্ত্রণ" title="Settings" />

    <div class="grid gap-6 lg:grid-cols-2">
        <!-- Security & books -->
        <form class="panel flex flex-col gap-6 p-6" @submit.prevent="form.put('/admin/settings', { preserveScroll: true })">
            <div>
                <h2 class="flex items-center gap-2 text-[17px] font-bold"><KIcon name="shield" class="text-brand" />নিরাপত্তা ও খাতা</h2>
                <p class="mt-1 text-[13px] text-ink-3">সবার জন্য প্রযোজ্য।</p>
            </div>

            <KSwitch v-model="form.require_2fa" label="সবার জন্য 2FA বাধ্যতামূলক" hint="চালু করলে 2FA ছাড়া কেউ খাতা খুলতে পারবে না — আগে নিজে চালু করুন।" />
            <p v-if="form.errors.require_2fa" class="-mt-4 text-[12.5px] font-medium text-out">{{ form.errors.require_2fa }}</p>

            <KField label="কাজ না করলে নিজে sign out" hint="শেষ ১ মিনিটে সতর্ক করা হবে।">
                <KSegment v-model="form.idle_minutes" :options="idleOptions" size="sm" />
            </KField>

            <KField label="খাতা বন্ধ (lock) — এই তারিখ পর্যন্ত" :error="form.errors.lock_date" hint="মাস শেষে হিসাব মিলিয়ে lock দিন — ওই সময়ের কোনো entry আর যোগ, বদল বা মোছা যাবে না। তারিখ ছাড়া পুরনো row-ও lock হবে।">
                <KDate v-model="form.lock_date" :max="today()" clearable placeholder="Lock নেই" />
            </KField>

            <div v-if="editable" class="flex justify-end">
                <KButton type="submit" variant="primary" :loading="form.processing">সংরক্ষণ</KButton>
            </div>
        </form>

        <div class="flex flex-col gap-6">
            <!-- Expense heads -->
            <section class="panel p-6">
                <h2 class="text-[17px] font-bold">খরচের খাত</h2>
                <p class="mt-1 text-[13px] text-ink-3">BDT খরচ লেখার সময় এগুলো থেকে বাছা যায়। বন্ধ করলে নতুন entry-তে দেখাবে না।</p>
                <div class="mt-4 flex flex-wrap gap-2">
                    <button
                        v-for="h in heads"
                        :key="h.id"
                        type="button"
                        :disabled="!editable"
                        class="rounded-lg border px-3 py-1.5 text-[13px] font-medium transition-colors"
                        :class="h.is_active ? 'border-line-2 bg-surface hover:border-ink-3' : 'border-dashed border-line-2 text-ink-3 line-through'"
                        :title="h.is_active ? 'বন্ধ করতে চাপুন' : 'চালু করতে চাপুন'"
                        @click="toggleHead(h)"
                    >
                        {{ h.name }}
                    </button>
                </div>
                <form v-if="editable" class="mt-4 flex gap-2" @submit.prevent="addHead">
                    <KInput v-model="headForm.name" placeholder="নতুন খাত, যেমন Office rent" size="sm" class="flex-1" :invalid="!!headForm.errors.name" />
                    <KButton type="submit" size="sm" icon="plus" :loading="headForm.processing">যোগ</KButton>
                </form>
                <p v-if="headForm.errors.name" class="mt-1.5 text-[12.5px] text-out">{{ headForm.errors.name }}</p>
            </section>

            <!-- Accounts -->
            <section class="panel p-6">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="text-[17px] font-bold">USD account ও platform</h2>
                        <p class="mt-1 text-[13px] text-ink-3">নতুন ad platform বা USD রাখার জায়গা।</p>
                    </div>
                    <KButton v-if="editable" size="sm" icon="plus" @click="startAccount()">যোগ</KButton>
                </div>
                <ul class="mt-3">
                    <li v-for="a in accounts" :key="a.id" class="flex items-center gap-3 border-b border-dashed border-line py-2.5 text-[14px] last:border-0">
                        <span class="flex-1 font-medium" :class="!a.is_active && 'text-ink-3 line-through'">{{ a.name }}</span>
                        <KTag>{{ kinds.find((k) => k.value === a.kind)?.label }}</KTag>
                        <button v-if="editable" type="button" class="rounded-md p-1.5 text-ink-3 hover:bg-sunken hover:text-ink" aria-label="বদলান" @click="startAccount(a)"><KIcon name="pen" :size="15" /></button>
                    </li>
                </ul>
            </section>
        </div>
    </div>

    <KModal v-model="accountOpen" width="sm" :title="editingAccount ? editingAccount.name : 'নতুন account'">
        <form id="acct-form" class="flex flex-col gap-4" @submit.prevent="saveAccount">
            <KField label="নাম" :error="accountForm.errors.name"><KInput v-model="accountForm.name" placeholder="যেমন LinkedIn Ads" /></KField>
            <KField v-if="!editingAccount" label="ধরন"><KSegment v-model="accountForm.kind" :options="newKinds" /></KField>
            <KSwitch v-if="editingAccount && editingAccount.kind !== 'card'" v-model="accountForm.is_active" label="চালু" />
        </form>
        <template #footer>
            <KButton variant="quiet" @click="accountOpen = false">বাতিল</KButton>
            <KButton type="submit" form="acct-form" variant="primary" :loading="accountForm.processing">সংরক্ষণ</KButton>
        </template>
    </KModal>
</template>

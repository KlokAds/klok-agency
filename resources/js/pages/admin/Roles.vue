<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import KButton from '@/components/k/KButton.vue';
import KCheck from '@/components/k/KCheck.vue';
import KField from '@/components/k/KField.vue';
import KIcon from '@/components/k/KIcon.vue';
import KInput from '@/components/k/KInput.vue';
import KModal from '@/components/k/KModal.vue';
import KTag from '@/components/k/KTag.vue';
import { confirmAction } from '@/lib/feedback';

type Role = { id: number; name: string; is_owner: boolean; users: number; permissions: string[] };
type Module = { key: string; label: string; actions: string[] };

const props = defineProps<{ roles: Role[]; modules: Module[]; actions: Record<string, string> }>();

const can = computed(() => usePage().props.auth.can);
const allActions = computed(() => Object.keys(props.actions));

const open = ref(false);
const editing = ref<Role | null>(null);
const form = useForm({ name: '', permissions: [] as string[] });

function start(role?: Role): void {
    editing.value = role ?? null;
    form.clearErrors();
    form.name = role?.name ?? '';
    form.permissions = [...(role?.permissions ?? ['dashboard.view'])];
    open.value = true;
}

const has = (perm: string) => form.permissions.includes(perm);

function toggle(perm: string, on: boolean): void {
    const [module, action] = perm.split('.');
    let next = on ? [...new Set([...form.permissions, perm])] : form.permissions.filter((p) => p !== perm);

    // Doing anything in a module needs seeing it; taking "view" away takes the rest.
    if (on && action !== 'view') {
        next = [...new Set([...next, `${module}.view`])];
    }

    if (!on && action === 'view') {
        next = next.filter((p) => !p.startsWith(`${module}.`));
    }

    form.permissions = next;
}

function toggleModule(m: Module, on: boolean): void {
    const perms = m.actions.map((a) => `${m.key}.${a}`);
    form.permissions = on ? [...new Set([...form.permissions, ...perms])] : form.permissions.filter((p) => !perms.includes(p));
}

const moduleFull = (m: Module) => m.actions.every((a) => has(`${m.key}.${a}`));

function save(): void {
    const opts = { preserveScroll: true, onSuccess: () => (open.value = false) };
    editing.value ? form.put(`/admin/roles/${editing.value.id}`, opts) : form.post('/admin/roles', opts);
}

async function remove(role: Role): Promise<void> {
    if (await confirmAction({ title: `"${role.name}" role মুছবেন?`, body: 'যে role-এ কোনো user নেই, শুধু সেটাই মোছা যায়।', confirm: 'মুছে ফেলুন', danger: true })) {
        router.delete(`/admin/roles/${role.id}`, { preserveScroll: true, onSuccess: () => (open.value = false) });
    }
}

const count = (r: Role) => (r.is_owner ? 'সব' : r.permissions.length);
</script>

<template>
    <PageHeader eyebrow="নিয়ন্ত্রণ" title="Role ও Permission" lead="প্রতিটা পাতার জন্য আলাদা করে দেখা, নতুন, বদলানো, মোছা — নিজের মতো role বানান (যেমন Accountant, Viewer)।">
        <KButton v-if="can['roles.create']" variant="ink" icon="plus" @click="start()">নতুন role</KButton>
    </PageHeader>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <article v-for="r in roles" :key="r.id" class="panel flex flex-col p-5">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-[17px] font-bold">{{ r.name }}</p>
                    <p class="text-[12.5px] text-ink-3"><span class="num">{{ r.users }}</span> জন · <span class="num">{{ count(r) }}</span> permission</p>
                </div>
                <KTag v-if="r.is_owner" tone="brand"><KIcon name="key" :size="12" />সব ক্ষমতা</KTag>
            </div>
            <p v-if="r.is_owner" class="mt-4 text-[13px] text-ink-2">Owner সব কিছু করতে পারে, আর একমাত্র Owner-ই অন্যকে Owner বানাতে পারে। এই role বদলানো যায় না।</p>
            <div v-else class="mt-4 flex flex-wrap gap-1">
                <KTag v-for="m in modules.filter((m) => r.permissions.includes(`${m.key}.view`))" :key="m.key">{{ m.label }}</KTag>
                <span v-if="!r.permissions.length" class="text-[13px] text-ink-3">কোনো permission নেই</span>
            </div>
            <div v-if="!r.is_owner && can['roles.edit']" class="mt-auto flex justify-end pt-4">
                <KButton size="sm" icon="pen" @click="start(r)">Permission বদলান</KButton>
            </div>
        </article>
    </div>

    <KModal v-model="open" width="xl" :title="editing ? `${editing.name} — permission` : 'নতুন role'">
        <form id="role-form" class="flex flex-col gap-5" @submit.prevent="save">
            <KField label="Role-এর নাম" :error="form.errors.name" class="max-w-sm">
                <KInput v-model="form.name" placeholder="যেমন Junior Accountant" :invalid="!!form.errors.name" />
            </KField>

            <div class="overflow-x-auto rounded-xl border border-line">
                <table class="w-full text-[13.5px]">
                    <thead class="bg-surface-2">
                        <tr>
                            <th class="px-4 py-3 text-left text-[11px] font-bold tracking-[0.08em] text-ink-3 uppercase">পাতা</th>
                            <th v-for="a in allActions" :key="a" class="px-3 py-3 text-center text-[11px] font-bold tracking-[0.08em] text-ink-3 uppercase">{{ actions[a] }}</th>
                            <th class="px-3 py-3 text-center text-[11px] font-bold tracking-[0.08em] text-ink-3 uppercase">সব</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="m in modules" :key="m.key" class="border-t border-dashed border-line">
                            <td class="px-4 py-2.5 font-medium">{{ m.label }}</td>
                            <td v-for="a in allActions" :key="a" class="px-3 py-2.5 text-center">
                                <KCheck v-if="m.actions.includes(a)" :model-value="has(`${m.key}.${a}`)" @update:model-value="(v: boolean) => toggle(`${m.key}.${a}`, v)" />
                                <span v-else class="text-ink-3/40">·</span>
                            </td>
                            <td class="px-3 py-2.5 text-center">
                                <KCheck :model-value="moduleFull(m)" @update:model-value="(v: boolean) => toggleModule(m, v)" />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="text-[12.5px] text-ink-3">“দেখা” ছাড়া অন্য কিছু দিলে “দেখা” নিজে চালু হয়। Server-এ প্রতিটা কাজের আগে permission যাচাই হয় — পাতায় বোতাম লুকানো শুধু সুবিধার জন্য।</p>
        </form>
        <template #footer>
            <KButton v-if="editing && can['roles.delete']" variant="danger" icon="bin" class="mr-auto" @click="remove(editing)">মুছুন</KButton>
            <KButton variant="quiet" @click="open = false">বাতিল</KButton>
            <KButton type="submit" form="role-form" variant="primary" :loading="form.processing">সংরক্ষণ</KButton>
        </template>
    </KModal>
</template>

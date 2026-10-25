<script setup lang="ts">
import SettingsNav from '@/components/SettingsNav.vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import KButton from '@/components/k/KButton.vue';
import KField from '@/components/k/KField.vue';
import KIcon from '@/components/k/KIcon.vue';
import KInput from '@/components/k/KInput.vue';
import KModal from '@/components/k/KModal.vue';
import KSelect from '@/components/k/KSelect.vue';
import KSwitch from '@/components/k/KSwitch.vue';
import KTag from '@/components/k/KTag.vue';
import { confirmAction } from '@/lib/feedback';
import { ago } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { Option } from '@/types';

type User = {
    id: number;
    name: string;
    email: string;
    role: string | null;
    is_active: boolean;
    two_factor: boolean;
    must_change_password: boolean;
    last_login_at: string | null;
    last_login_ip: string | null;
};

defineProps<{ users: User[]; roles: Option[] }>();

const page = usePage();
const me = computed(() => page.props.auth.user);
const can = computed(() => page.props.auth.can);

const open = ref(false);
const editing = ref<User | null>(null);
const form = useForm({
    name: '',
    email: '',
    role: 'Accountant' as string | null,
    password: '',
    is_active: true,
});

function start(user?: User): void {
    editing.value = user ?? null;
    form.clearErrors();
    Object.assign(
        form,
        user
            ? {
                  name: user.name,
                  email: user.email,
                  role: user.role,
                  password: '',
                  is_active: user.is_active,
              }
            : {
                  name: '',
                  email: '',
                  role: 'Accountant',
                  password: '',
                  is_active: true,
              },
    );
    open.value = true;
}

function suggestPassword(): void {
    const chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    const bytes = crypto.getRandomValues(new Uint8Array(14));
    form.password =
        Array.from(bytes, (b) => chars[b % chars.length]).join('') + '7';
}

function save(): void {
    const opts = {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    };
    if (editing.value) {
        form.put(`/admin/users/${editing.value.id}`, opts);
    } else {
        form.post('/admin/users', opts);
    }
}

async function reset2fa(user: User): Promise<void> {
    if (
        await confirmAction({
            title: t('{name}-এর 2FA reset করবেন?', { name: user.name }),
            body: t(
                'Phone হারালে এটা করুন। পরের login-এ আবার 2FA চালু করতে হবে।',
            ),
            confirm: t('Reset করুন'),
            danger: true,
        })
    ) {
        router.post(
            `/admin/users/${user.id}/reset-2fa`,
            {},
            { preserveScroll: true },
        );
    }
}

async function remove(user: User): Promise<void> {
    if (
        await confirmAction({
            title: t('{name}-এর account মুছবেন?', { name: user.name }),
            body: t(
                'সাথে সাথে sign out হবে। তার লেখা entry-গুলো খাতায় থেকে যাবে। শুধু বন্ধ রাখতে চাইলে “Active” বন্ধ করুন।',
            ),
            confirm: t('মুছে ফেলুন'),
            danger: true,
        })
    ) {
        router.delete(`/admin/users/${user.id}`, {
            preserveScroll: true,
            onSuccess: () => (open.value = false),
        });
    }
}
</script>

<template>
    <SettingsNav />
    <PageHeader
        eyebrow="Settings"
        title="User"
        :lead="
            t(
                'কে খাতায় ঢুকতে পারবে। বাইরে থেকে কেউ নিজে account খুলতে পারে না — শুধু এখান থেকে।',
            )
        "
    >
        <KButton
            v-if="can['users.create']"
            variant="ink"
            icon="plus"
            @click="start()"
            >{{ t('নতুন user') }}</KButton
        >
    </PageHeader>

    <section class="panel overflow-x-auto">
        <table class="ledger">
            <thead>
                <tr>
                    <th>{{ t('নাম') }}</th>
                    <th>Role</th>
                    <th>{{ t('নিরাপত্তা') }}</th>
                    <th>{{ t('শেষ login') }}</th>
                    <th />
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="u in users"
                    :key="u.id"
                    :class="!u.is_active && 'opacity-55'"
                >
                    <td>
                        <p class="font-semibold">
                            {{ u.name }}
                            <span
                                v-if="u.id === me.id"
                                class="text-[12px] font-normal text-ink-3"
                                >{{ t('(আপনি)') }}</span
                            >
                        </p>
                        <p class="text-[12.5px] text-ink-3">{{ u.email }}</p>
                    </td>
                    <td>
                        <KTag
                            :tone="u.role === 'Owner' ? 'brand' : 'neutral'"
                            >{{ u.role ?? '—' }}</KTag
                        >
                    </td>
                    <td>
                        <div class="flex flex-wrap gap-1.5">
                            <KTag :tone="u.two_factor ? 'in' : 'wait'"
                                ><KIcon
                                    :name="u.two_factor ? 'shield' : 'warn'"
                                    :size="12"
                                />{{
                                    u.two_factor ? t('2FA চালু') : t('2FA নেই')
                                }}</KTag
                            >
                            <KTag v-if="u.must_change_password" tone="wait">{{
                                t('Password বদলাতে হবে')
                            }}</KTag>
                            <KTag v-if="!u.is_active" tone="out">{{
                                t('বন্ধ')
                            }}</KTag>
                        </div>
                    </td>
                    <td class="text-[13px] text-ink-2">
                        {{ ago(u.last_login_at) }}
                        <span
                            v-if="u.last_login_ip"
                            class="num block text-[11.5px] text-ink-3"
                            >{{ u.last_login_ip }}</span
                        >
                    </td>
                    <td class="r whitespace-nowrap">
                        <KButton
                            v-if="
                                can['users.edit'] &&
                                u.two_factor &&
                                u.id !== me.id
                            "
                            size="sm"
                            variant="quiet"
                            @click="reset2fa(u)"
                            >2FA reset</KButton
                        >
                        <KButton
                            v-if="can['users.edit']"
                            size="sm"
                            icon="pen"
                            @click="start(u)"
                            >{{ t('বদলান') }}</KButton
                        >
                    </td>
                </tr>
            </tbody>
        </table>
    </section>

    <KModal
        v-model="open"
        :title="editing ? editing.name : t('নতুন user')"
        :subtitle="
            editing
                ? undefined
                : t('প্রথম login-এ নিজের password দিতে বাধ্য হবে।')
        "
    >
        <form
            id="user-form"
            class="grid grid-cols-1 gap-4 sm:grid-cols-2"
            @submit.prevent="save"
        >
            <KField :label="t('নাম')" :error="form.errors.name"
                ><KInput v-model="form.name" :invalid="!!form.errors.name"
            /></KField>
            <KField label="Email (login)" :error="form.errors.email"
                ><KInput
                    v-model="form.email"
                    type="email"
                    autocomplete="off"
                    :invalid="!!form.errors.email"
            /></KField>
            <KField
                label="Role"
                :error="form.errors.role"
                class="sm:col-span-2"
            >
                <KSelect
                    v-model="form.role"
                    :options="roles"
                    :disabled="editing?.id === me.id"
                />
            </KField>
            <KField
                :label="
                    editing
                        ? t('নতুন অস্থায়ী password')
                        : t('অস্থায়ী password')
                "
                :optional="!!editing"
                :error="form.errors.password"
                class="sm:col-span-2"
                :hint="t('কমপক্ষে ৮ অক্ষর। user নিজে পরে বদলাবে।')"
            >
                <KInput
                    v-model="form.password"
                    type="password"
                    autocomplete="new-password"
                    :invalid="!!form.errors.password"
                >
                    <template #trail>
                        <button
                            type="button"
                            class="shrink-0 rounded-md px-2 py-1 text-[12px] font-semibold text-brand-ink hover:bg-brand-soft"
                            @click.stop="suggestPassword"
                        >
                            {{ t('বানিয়ে দাও') }}
                        </button>
                    </template>
                </KInput>
            </KField>
            <div v-if="editing && editing.id !== me.id" class="sm:col-span-2">
                <KSwitch
                    v-model="form.is_active"
                    label="Active"
                    :hint="
                        t(
                            'বন্ধ করলে সাথে সাথে sign out হবে, আর ঢুকতে পারবে না।',
                        )
                    "
                />
            </div>
        </form>
        <template #footer>
            <KButton
                v-if="editing && can['users.delete'] && editing.id !== me.id"
                variant="danger"
                icon="bin"
                class="mr-auto"
                @click="remove(editing)"
                >{{ t('মুছুন') }}</KButton
            >
            <KButton variant="quiet" @click="open = false">{{
                t('বাতিল')
            }}</KButton>
            <KButton
                type="submit"
                form="user-form"
                variant="primary"
                :loading="form.processing"
                >{{ editing ? t('সংরক্ষণ') : t('Account খুলুন') }}</KButton
            >
        </template>
    </KModal>
</template>

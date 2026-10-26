<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import { reactive } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import KButton from '@/components/k/KButton.vue';
import KField from '@/components/k/KField.vue';
import KInput from '@/components/k/KInput.vue';
import KSwitch from '@/components/k/KSwitch.vue';
import { t } from '@/lib/i18n';

type Group = {
    key: string;
    label: string;
    reaches: boolean;
    mandatory: boolean;
    on: boolean;
    push: boolean;
    email: boolean;
};

const props = defineProps<{
    notify: { owner: boolean; mail: boolean; groups: Group[] };
    details?: { email: string; phone: string | null; company: string | null };
}>();

const user = usePage().props.auth.user;
// The Owner changes any team email on the Users page — their own too.
const canEditUsers = !!usePage().props.auth.can['users.edit'];
const isClient = user.role === 'Client';
// A client keeps their own email, phone and company; the team's email is changed by an admin.
const form = useForm({
    name: user.name,
    email: props.details?.email ?? user.email,
    phone: props.details?.phone ?? '',
    company: props.details?.company ?? '',
});

// Which notifications come, and how; saved as soon as a switch moves.
const groups = reactive(props.notify.groups.map((g) => ({ ...g })));

function saveNotify(): void {
    router.put(
        '/settings/notifications',
        {
            groups: groups.map((g) => ({
                key: g.key,
                on: g.mandatory || g.on,
                push: g.push,
                email: g.email,
            })),
        },
        { preserveScroll: true, preserveState: true },
    );
}
</script>

<template>
    <PageHeader :eyebrow="t('আমার account')" title="Profile" />

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[1fr_320px]">
        <form
            class="panel flex flex-col gap-5 p-4 sm:p-6"
            @submit.prevent="
                form.patch('/settings/profile', { preserveScroll: true })
            "
        >
            <KField :label="t('নাম')" :error="form.errors.name">
                <KInput
                    v-model="form.name"
                    autocomplete="name"
                    :invalid="!!form.errors.name"
                />
            </KField>
            <template v-if="isClient">
                <KField
                    label="Email"
                    :hint="t('এটাই আপনার sign-in নাম, আর এখানেই email যায়।')"
                    :error="form.errors.email"
                >
                    <KInput
                        v-model="form.email"
                        type="email"
                        autocomplete="email"
                    />
                </KField>
                <KField
                    :label="t('Phone নম্বর')"
                    optional
                    :error="form.errors.phone"
                >
                    <KInput
                        v-model="form.phone"
                        type="tel"
                        inputmode="tel"
                        placeholder="01XXXXXXXXX"
                    />
                </KField>
                <KField :label="t('প্রতিষ্ঠানের নাম')" optional>
                    <KInput v-model="form.company" />
                </KField>
            </template>
            <KField
                v-else
                label="Email"
                :hint="
                    canEditUsers
                        ? t('এটাই sign-in নাম — Settings → User পাতায় বদলান।')
                        : t('Email বদলাতে Owner-কে বলুন — এটাই sign-in নাম।')
                "
            >
                <div class="flex items-center gap-2">
                    <div
                        class="field-box min-w-0 flex-1 bg-sunken! text-[15.5px] text-ink-2"
                    >
                        {{ user.email }}
                    </div>
                    <KButton
                        v-if="canEditUsers"
                        href="/admin/users"
                        variant="soft"
                        icon="pen"
                        >{{ t('বদলান') }}</KButton
                    >
                </div>
            </KField>
            <div class="flex justify-end">
                <KButton
                    type="submit"
                    variant="primary"
                    :loading="form.processing"
                    >{{ t('সংরক্ষণ') }}</KButton
                >
            </div>
        </form>

        <!-- A client gets their own news (invoices, payments, ads), always: no staff groups here. -->
        <section
            v-if="user.role !== 'Client'"
            class="panel p-4 sm:p-6 lg:col-start-1"
        >
            <h2 class="text-[16px] font-bold">{{ t('Notification') }}</h2>
            <p class="mt-1 text-[14px] text-ink-3">
                {{
                    notify.owner
                        ? t(
                              'কাজের খবর যায় যার কাজ তার কাছে (role-এর permission অনুযায়ী)। আপনি Owner — যে group চালু রাখবেন শুধু সেগুলো পাবেন; অন্য কেউ না দেখলে বা জরুরি হলে (মোছা, বড় খরচ) এমনিতেই পাবেন।',
                          )
                        : t(
                              'আপনার role-এর permission অনুযায়ী এই খবরগুলো আসে। কোনটা phone-এ বা email-এ চান, বেছে নিন। Bell-এ সব থাকে।',
                          )
                }}
            </p>
            <div class="mt-4 overflow-x-auto">
                <table v-stack class="stack w-full min-w-[440px] text-[14.5px]">
                    <thead>
                        <tr class="text-left text-[13px] text-ink-3">
                            <th class="py-2 pr-3 font-medium">
                                {{ t('কোন খবর') }}
                            </th>
                            <th class="px-2 py-2 text-center font-medium">
                                {{ t('চালু') }}
                            </th>
                            <th class="px-2 py-2 text-center font-medium">
                                Phone
                            </th>
                            <th class="px-2 py-2 text-center font-medium">
                                Email
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="g in groups"
                            :key="g.key"
                            class="border-t border-line"
                            :class="!g.reaches && 'opacity-50'"
                        >
                            <td class="py-3 pr-3">
                                {{ t(g.label) }}
                                <span
                                    v-if="!g.reaches"
                                    class="block text-[13px] text-ink-3"
                                    >{{
                                        t('আপনার role-এ এর permission নেই')
                                    }}</span
                                >
                            </td>
                            <td class="px-2 py-3">
                                <div class="flex justify-center">
                                    <KSwitch
                                        v-model="g.on"
                                        :class="
                                            (g.mandatory || !g.reaches) &&
                                            'pointer-events-none opacity-60'
                                        "
                                        @update:model-value="saveNotify"
                                    />
                                </div>
                            </td>
                            <td class="px-2 py-3">
                                <div class="flex justify-center">
                                    <KSwitch
                                        v-model="g.push"
                                        :class="
                                            !(g.on || g.mandatory) &&
                                            'pointer-events-none opacity-40'
                                        "
                                        @update:model-value="saveNotify"
                                    />
                                </div>
                            </td>
                            <td class="px-2 py-3">
                                <div class="flex justify-center">
                                    <KSwitch
                                        v-model="g.email"
                                        :class="
                                            (!(g.on || g.mandatory) ||
                                                !notify.mail) &&
                                            'pointer-events-none opacity-40'
                                        "
                                        @update:model-value="saveNotify"
                                    />
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="!notify.mail" class="mt-3 text-[13.5px] text-ink-3">
                {{
                    t(
                        'Email পাঠাতে আগে Settings › সাধারণ-এ mailbox (SMTP) দিতে হবে।',
                    )
                }}
            </p>
        </section>

        <aside class="panel p-4 sm:p-6 lg:col-start-2 lg:row-start-1">
            <p class="eyebrow">Role</p>
            <p class="mt-1 text-[18px] font-bold">
                {{ user.role ?? t('Role নেই') }}
            </p>
            <p class="mt-3 text-[14px] text-ink-3">
                {{
                    t(
                        'কোন পাতায় কী করতে পারবেন, তা Role ঠিক করে। বদলাতে Owner-এর সাথে কথা বলুন।',
                    )
                }}
            </p>
        </aside>
    </div>
</template>

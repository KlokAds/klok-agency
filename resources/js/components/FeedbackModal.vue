<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import KButton from '@/components/k/KButton.vue';
import KField from '@/components/k/KField.vue';
import KModal from '@/components/k/KModal.vue';
import KSegment from '@/components/k/KSegment.vue';
import KTextarea from '@/components/k/KTextarea.vue';
import { t } from '@/lib/i18n';

/** A feature request, an idea or a problem: it goes, whole, to every Owner. */
const open = defineModel<boolean>({ default: false });

const form = useForm({ kind: 'feature', text: '' });
const kinds = [
    { value: 'feature', label: t('নতুন feature') },
    { value: 'idea', label: t('পরামর্শ') },
    { value: 'problem', label: t('সমস্যা') },
];

function send(): void {
    form.post('/feedback', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            open.value = false;
        },
    });
}
</script>

<template>
    <KModal
        v-model="open"
        width="md"
        :title="t('Feature request ও পরামর্শ')"
        :subtitle="
            t('কী হলে আপনার কাজ সহজ হয়, বা কোথায় অসুবিধা — লিখুন, আমরা পড়ি।')
        "
    >
        <div class="flex flex-col gap-4">
            <KSegment v-model="form.kind" :options="kinds" />
            <KField :label="t('লিখুন')" :error="form.errors.text">
                <KTextarea
                    v-model="form.text"
                    :rows="5"
                    :invalid="!!form.errors.text"
                    :placeholder="
                        form.kind === 'problem'
                            ? t('কোন পাতায়, কী করতে গিয়ে, কী হলো…')
                            : t(
                                  'যেমন: invoice-এর PDF WhatsApp-এ পাঠানো যেত যদি…',
                              )
                    "
                />
            </KField>
        </div>
        <template #footer>
            <KButton variant="quiet" @click="open = false">{{
                t('বাতিল')
            }}</KButton>
            <KButton
                variant="primary"
                :loading="form.processing"
                :disabled="form.text.trim().length < 5"
                @click="send"
                >{{ t('পাঠান') }}</KButton
            >
        </template>
    </KModal>
</template>

<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import KIcon from '@/components/k/KIcon.vue';
import { t } from '@/lib/i18n';

defineProps<{ name: string }>();
</script>

<template>
    <Head :title="t('অপেক্ষায়')" />

    <section class="panel mx-auto mt-6 max-w-lg p-7 text-center">
        <span
            class="mx-auto grid size-14 place-items-center rounded-full bg-wait-soft text-wait"
            ><KIcon name="clock" :size="28"
        /></span>
        <h1 class="mt-4 text-[20px] font-bold">
            {{ t('স্বাগতম, {name}!', { name }) }}
        </h1>
        <p class="mt-2 text-[15.5px] text-ink-2">
            {{
                t(
                    'আপনার account খোলা হয়েছে। Klok Ads আপনাকে আপনার প্রতিষ্ঠানের সাথে যুক্ত করলেই এখানে campaign, খরচ, result আর invoice দেখা যাবে।',
                )
            }}
        </p>
        <p class="mt-4 text-[14px] text-ink-3">
            {{
                t(
                    'সাধারণত কয়েক ঘণ্টার মধ্যে হয়ে যায়। তাড়া থাকলে Klok Ads-কে জানান।',
                )
            }}
        </p>
    </section>
</template>

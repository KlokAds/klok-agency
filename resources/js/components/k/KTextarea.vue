<script setup lang="ts">
defineProps<{ placeholder?: string; rows?: number; invalid?: boolean; id?: string }>();
const model = defineModel<string | null>();
</script>

<template>
    <div class="field-box items-stretch! py-2" :data-invalid="invalid">
        <textarea :id="id" v-model="model" :rows="rows ?? 2" :placeholder="placeholder" class="w-full resize-none text-[14px] leading-relaxed" />
    </div>
</template>

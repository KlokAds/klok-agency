import { ref } from 'vue';

// The quick-entry panel lives in the layout; any page can open it, and
// may ask for one job (e.g. "client-payment") to be chosen already.
export const quickOpen = ref(false);
export const quickJob = ref<string | null>(null);

export function openQuickEntry(job: string | null = null): void {
    quickJob.value = job;
    quickOpen.value = true;
}

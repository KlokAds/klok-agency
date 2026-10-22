import { router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import type { Toast } from '@/types';

/* Toasts and confirm dialogs, rendered by <KFeedbackHost> in the shell. */

type LiveToast = Toast & { id: number };

type ConfirmRequest = {
    title: string;
    body?: string;
    confirm?: string;
    danger?: boolean;
    resolve: (ok: boolean) => void;
};

export const feedback = reactive<{ toasts: LiveToast[]; confirm: ConfirmRequest | null }>({
    toasts: [],
    confirm: null,
});

let seq = 0;

export function toast(message: string, type: Toast['type'] = 'success'): void {
    const id = ++seq;
    feedback.toasts.push({ id, message, type });
    setTimeout(() => dismiss(id), type === 'error' || type === 'warning' ? 7000 : 4200);
}

export function dismiss(id: number): void {
    feedback.toasts = feedback.toasts.filter((t) => t.id !== id);
}

export function confirmAction(opts: Omit<ConfirmRequest, 'resolve'>): Promise<boolean> {
    return new Promise((resolve) => {
        feedback.confirm = { ...opts, resolve };
    });
}

export function initializeFeedback(): void {
    router.on('flash', (event) => {
        const data = (event as CustomEvent).detail?.flash?.toast as Toast | undefined;

        if (data) {
            toast(data.message, data.type);
        }
    });

    // Validation errors that are not tied to a form field (e.g. "this row is locked").
    router.on('error', (event) => {
        const errors = (event as CustomEvent).detail?.errors as Record<string, string> | undefined;
        const loose = ['entry', 'batch', 'client', 'employee', 'user', 'role'];

        for (const key of loose) {
            if (errors?.[key]) {
                toast(errors[key], 'error');
            }
        }
    });

    router.on('httpException', (event) => {
        const status = (event as CustomEvent).detail?.response?.status;

        if (status === 403) {
            event.preventDefault();
            toast('এই কাজের permission আপনার নেই।', 'error');
        } else if (status === 419) {
            event.preventDefault();
            window.location.reload();
        }
    });
}

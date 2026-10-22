import { router } from '@inertiajs/vue3';
import { reactive, watch } from 'vue';

/**
 * Filter state that lives in the URL: any change reloads the page data
 * (search waits for typing to pause).
 */
export function useFilters<T extends Record<string, unknown>>(
    url: string,
    initial: T,
) {
    const filters = reactive({ ...initial }) as T;
    let timer: number | undefined;

    function apply(): void {
        const query = Object.fromEntries(
            Object.entries(filters)
                .filter(([, v]) => v !== null && v !== '' && v !== undefined)
                .map(([k, v]) => [k, String(v)]),
        ) as Record<string, string>;
        router.get(url, query, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }

    watch(
        () => ({ ...filters }),
        (now, before) => {
            window.clearTimeout(timer);
            timer = window.setTimeout(apply, now.q !== before.q ? 320 : 0);
        },
    );

    function clear(): void {
        for (const key of Object.keys(filters)) {
            (filters as Record<string, unknown>)[key] =
                key === 'order' ? 'new' : null;
        }
    }

    return { filters, clear };
}

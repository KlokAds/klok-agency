import { nextTick, onBeforeUnmount, ref, watch } from 'vue';
import type { Ref } from 'vue';

/**
 * Places a floating panel under (or above, when there is no room) its
 * anchor, keeps it there on scroll/resize, and closes it on outside click
 * or Escape. The panel is teleported to <body> so no parent can clip it.
 */
export function usePopover(
    anchor: Ref<HTMLElement | null>,
    panel: Ref<HTMLElement | null>,
    opts: {
        matchWidth?: boolean;
        minWidth?: number;
        align?: 'start' | 'end';
    } = {},
) {
    const open = ref(false);
    const style = ref<Record<string, string>>({});

    function place(): void {
        const a = anchor.value;
        const p = panel.value;

        if (!a || !p) {
            return;
        }

        const rect = a.getBoundingClientRect();
        const width = opts.matchWidth
            ? Math.max(rect.width, opts.minWidth ?? 0)
            : p.offsetWidth;
        const height = p.offsetHeight;
        const gap = 6;
        const below = window.innerHeight - rect.bottom;
        const up = below < height + gap + 8 && rect.top > below;

        let left = opts.align === 'end' ? rect.right - width : rect.left;
        left = Math.min(Math.max(8, left), window.innerWidth - width - 8);

        style.value = {
            position: 'fixed',
            left: `${left}px`,
            top: up
                ? `${Math.max(8, rect.top - height - gap)}px`
                : `${rect.bottom + gap}px`,
            ...(opts.matchWidth ? { width: `${width}px` } : {}),
            zIndex: '80',
        };
    }

    function onDocPointer(event: PointerEvent): void {
        const target = event.target as Node;

        if (anchor.value?.contains(target) || panel.value?.contains(target)) {
            return;
        }

        close();
    }

    function onKey(event: KeyboardEvent): void {
        if (event.key === 'Escape' && open.value) {
            event.stopPropagation();
            close();
            anchor.value?.querySelector<HTMLElement>('button, input')?.focus();
        }
    }

    function listen(on: boolean): void {
        const fn = on ? 'addEventListener' : 'removeEventListener';
        document[fn]('pointerdown', onDocPointer as EventListener, true);
        document[fn]('keydown', onKey as EventListener, true);
        window[fn]('resize', place);
        window[fn]('scroll', place, true);
    }

    watch(open, async (value) => {
        listen(value);

        if (value) {
            await nextTick();
            place();
        }
    });

    onBeforeUnmount(() => listen(false));

    function show(): void {
        open.value = true;
    }

    function close(): void {
        open.value = false;
    }

    function toggle(): void {
        open.value = !open.value;
    }

    return { open, style, show, close, toggle, place };
}

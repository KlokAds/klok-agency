import { router } from '@inertiajs/vue3';

/**
 * The pages opened in this tab, oldest first, so the back button knows whether the page before
 * is one of ours (history back) or the tab was opened right here (go up a level instead).
 */
const trail: string[] = [];

export function initializeBack(): void {
    router.on('navigate', (event) => {
        const url = event.detail.page.url;

        if (trail.at(-1) === url) {
            return;
        }

        // Going back (the browser's or ours) lands on the page before: the trail shortens.
        if (trail.at(-2) === url) {
            trail.pop();
        } else {
            trail.push(url);
        }
    });
}

export function canGoBack(): boolean {
    return trail.length > 1;
}

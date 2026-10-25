import type { Directive } from 'vue';

/**
 * v-stack on a <table class="stack">: each cell learns its column's name (data-label), so on a
 * phone the CSS can show every row as a card of "label — value" lines instead of a table to scroll
 * sideways. Read again whenever the table changes (a new page, a filter).
 */
function label(table: HTMLElement): void {
    const heads = Array.from(table.querySelectorAll('thead th')).map(
        (th) => th.textContent?.replace(/\s+/g, ' ').trim() ?? '',
    );

    table.querySelectorAll('tbody tr, tfoot tr').forEach((row) => {
        let column = 0;

        Array.from(row.children).forEach((cell) => {
            const el = cell as HTMLElement;
            el.dataset.label = heads[column] ?? '';
            column += Number(el.getAttribute('colspan') ?? 1);
        });
    });
}

export const vStack: Directive<HTMLElement> = {
    mounted: label,
    updated: label,
};

// Phone layout check, run in CI: a CSS grid that only gets its columns from sm:/md:/lg:/xl:
// up has no column width on a phone, so one long word or number makes it wider than the
// screen (and "truncate" stops working). Such a grid needs grid-cols-1 below the breakpoint.
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';

const root = new URL('../resources/js', import.meta.url).pathname.replace(
    /^\/([A-Za-z]:)/,
    '$1',
);

function walk(dir, out = []) {
    for (const name of readdirSync(dir)) {
        const path = join(dir, name);
        if (statSync(path).isDirectory()) walk(path, out);
        else if (path.endsWith('.vue')) out.push(path);
    }
    return out;
}

const bad = [];
for (const file of walk(root)) {
    readFileSync(file, 'utf8')
        .split('\n')
        .forEach((line, i) => {
            for (const [, classes] of line.matchAll(/class="([^"]*)"/g)) {
                const words = classes.split(/\s+/);
                const grid = words.includes('grid');
                const later = words.some((w) =>
                    /^(sm|md|lg|xl|2xl|@xl|@lg|@md):grid-cols-/.test(w),
                );
                const base = words.some((w) => w.startsWith('grid-cols-'));
                if (grid && later && !base)
                    bad.push(
                        `${relative(root, file)}:${i + 1}  ${classes.slice(0, 90)}`,
                    );
            }
        });
}

if (bad.length) {
    console.log(
        'Grids with no phone column (add grid-cols-1):\n' +
            bad.map((b) => '  ' + b).join('\n'),
    );
    process.exit(1);
}
console.log('layout: every grid has a phone column');

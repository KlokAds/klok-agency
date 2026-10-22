// Finds বাংলা text that is not translatable yet, and t()/__() keys with no English.
//   node scripts/i18n-check.mjs            → whole app
//   node scripts/i18n-check.mjs pages/books → only paths containing that text
import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(path.dirname(new URL(import.meta.url).pathname.replace(/^\/(\w:)/, '$1')), '..');
const filter = process.argv[2] ?? '';
const BN = /[ঀ-৿]/;

function walk(dir, ext, out = []) {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) walk(full, ext, out);
        else if (ext.some((e) => entry.name.endsWith(e))) out.push(full);
    }
    return out;
}

const english = {};
for (const file of [path.join(root, 'lang/en.json'), ...walk(path.join(root, 'lang/parts'), ['en.json'])]) {
    if (fs.existsSync(file)) Object.assign(english, JSON.parse(fs.readFileSync(file, 'utf8')));
}

// t('…') / __('…') with a plain string literal as the first argument.
const CALL = /\b(?:t|__|trans)\(\s*(['"`])((?:\\.|(?!\1).)*)\1/gs;

const unescape = (s, q) => s.replace(new RegExp('\\\\' + q, 'g'), q).replace(/\\\\/g, '\\');

let loose = 0;
let missing = 0;
const report = [];

const files = [...walk(path.join(root, 'resources/js'), ['.vue', '.ts']), ...walk(path.join(root, 'app'), ['.php'])]
    .filter((f) => !f.includes(`${path.sep}lib${path.sep}i18n.ts`))
    .filter((f) => f.replaceAll('\\', '/').includes(filter));

for (const file of files) {
    let src = fs.readFileSync(file, 'utf8');
    const rel = path.relative(root, file).replaceAll('\\', '/');
    const lines = [];

    for (const m of src.matchAll(CALL)) {
        const key = unescape(m[2], m[1]);
        if (BN.test(key) && !(key in english)) {
            missing++;
            lines.push(`  missing en: ${key}`);
        }
    }

    // A line marked `// i18n` holds source text that is translated where it is shown
    // (e.g. a label in a constant): it only needs its English to exist.
    src = src
        .split('\n')
        .map((line) => {
            if (!/\/\/\s*i18n\b/.test(line)) return line;
            for (const m of line.matchAll(/(['"`])((?:\\.|(?!\1).)*)\1/g)) {
                const key = unescape(m[2], m[1]);
                if (BN.test(key) && !(key in english)) {
                    missing++;
                    lines.push(`  missing en: ${key}`);
                }
            }
            return '';
        })
        .join('\n');

    // Drop translated calls, comments and the `<!-- -->` blocks, then look for বাংলা left over.
    src = src
        .replace(CALL, '""')
        .replace(/<!--[\s\S]*?-->/g, '')
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/(^|[^:'"`])\/\/.*$/gm, '$1');

    src.split('\n').forEach((line, i) => {
        if (BN.test(line)) {
            loose++;
            lines.push(`  ${i + 1}: ${line.trim().slice(0, 140)}`);
        }
    });

    if (lines.length) report.push(`${rel}\n${lines.join('\n')}`);
}

console.log(report.join('\n\n') || 'clean');
console.log(`\n${files.length} files · ${loose} lines with untranslated বাংলা · ${missing} keys without English`);
process.exit(loose || missing ? 1 : 0);

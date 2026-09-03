#!/usr/bin/env node
/* Extract i18n keys used in code and diff against en.json. */
const fs = require('fs');
const path = require('path');

const root = path.join(__dirname, '..');
const walk = (dir, acc = []) => {
    for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
        const p = path.join(dir, e.name);
        if (e.isDirectory()) {
            if (['node_modules', 'dist', 'build'].includes(e.name)) continue;
            walk(p, acc);
        } else if (/\.(vue|js|cjs|mjs|ts)$/.test(e.name)) acc.push(p);
    }
    return acc;
};

const files = walk(path.join(root, 'resources/js'));
const used = new Set();

// t('x') / $t('x') / i18n.t('x') — single-quoted and double-quoted, no concatenation
const re = /(?:\bt|\$t|i18n\.t)\(\s*'([^']+)'/g;
const re2 = /(?:\bt|\$t|i18n\.t)\(\s*"([^"]+)"/g;
// attribute-shorthand keys used by FormField & co: label="field.x" help="field.x" submitLabel="label.x"
const reAttr = /\b(label|help|submitLabel|fieldName|placeholder)=&quot;([a-z_0-9.]+)&quot;|\b(label|help|submitLabel|fieldName|placeholder)="([a-z_0-9.]+)"/g;

for (const f of files) {
    const src = fs.readFileSync(f, 'utf8');
    let m;
    while ((m = re.exec(src))) used.add(m[1]);
    while ((m = re2.exec(src))) used.add(m[1]);
    while ((m = reAttr.exec(src))) used.add(m[2] || m[4]);
}

// title.<routeName> from App.vue's t('title.' + to.name)
const routerSrc = fs.readFileSync(path.join(root, 'resources/js/router/index.js'), 'utf8');
const routeNames = [...routerSrc.matchAll(/name:\s*'([^']+)'/g)].map(m => m[1]);
for (const n of routeNames) used.add('title.' + n);

const en = JSON.parse(fs.readFileSync(path.join(root, 'resources/lang/en.json'), 'utf8'));
const missing = [...used].filter(k => !k.includes('${') && !k.endsWith('.') && !(k in en)).sort();
const dynamicPrefix = [...used].filter(k => k.includes('${')).sort();

console.log('route names found:', routeNames.length);
console.log('missing keys (' + missing.length + '):');
for (const k of missing) console.log('  ' + k);
console.log('dynamic (skipped):', dynamicPrefix.join(', '));

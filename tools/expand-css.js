/**
 * Expand packed single-line CSS rules to one declaration per line.
 *
 * Stylelint's `declaration-block-single-line-max-declarations` has no fixer,
 * so this does that one job via PostCSS (a real parser — a regex would split
 * on semicolons inside `url(data:…;base64,…)`).
 *
 * WHY the hard allowlist: an earlier run used a raw shell glob and reformatted
 * WooCommerce's vendor CSS. Vendor plugins are gitignored, so that was not
 * revertible. This script now refuses any path outside the ecosystem's own
 * plugins, regardless of what it is handed.
 */
const fs = require('fs');
const path = require('path');
const postcss = require('postcss');

const OURS = new Set(
  fs.readFileSync(path.join(__dirname, 'ecosystem-plugins.txt'), 'utf8')
    .split('\n').map(l => l.trim()).filter(l => l && !l.startsWith('#'))
);

function isOurs(file) {
  const parts = path.resolve(file).split(path.sep);
  const i = parts.lastIndexOf('plugins');
  return i !== -1 && OURS.has(parts[i + 1]);
}

function indentOf(node) {
  const m = (node.raws.before || '\n').match(/\n([ \t]*)$/);
  return m ? m[1] : '';
}

function expand(css) {
  const root = postcss.parse(css);
  let changed = 0;
  root.walkRules(rule => {
    if (!rule.nodes) return;
    const decls = rule.nodes.filter(n => n.type === 'decl');
    if (decls.length < 2) return;
    const packed = decls.slice(1).some(d => !(d.raws.before || '').includes('\n'));
    if (!packed) return;
    const ind = indentOf(rule);
    rule.nodes.forEach((n, i) => {
      // A comment with no newline before it trails the previous declaration —
      // moving it would detach it from what it annotates.
      if (n.type === 'comment' && i > 0 && !(n.raws.before || '').includes('\n')) return;
      n.raws.before = '\n' + ind + '    ';
    });
    rule.raws.after = '\n' + ind;
    rule.raws.between = ' ';
    changed++;
  });
  return { css: root.toString(), changed };
}

let total = 0, refused = 0;
for (const f of process.argv.slice(2)) {
  if (!isOurs(f)) { console.error(`  REFUSED (not an ecosystem plugin): ${f}`); refused++; continue; }
  const { css, changed } = expand(fs.readFileSync(f, 'utf8'));
  if (changed) { fs.writeFileSync(f, css); total += changed; console.log(`  ${String(changed).padStart(4)}  ${f}`); }
}
console.log(`rules expanded: ${total}${refused ? `, refused: ${refused}` : ''}`);

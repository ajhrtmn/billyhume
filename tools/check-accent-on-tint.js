#!/usr/bin/env node
/**
 * Flags "accent-coloured text on a tint of that same accent".
 *
 * WHY this exists: that one pattern produced three separate AA failures in a
 * single session -- .bhy-alert-* variants, the unread notification card, and
 * the course price badge at 3.23:1, which is the price. Each looked
 * reasonable in isolation: an accent foreground on a low-percentage mix of
 * the same accent. The two never separate far enough, because they are the
 * same hue at similar lightness.
 *
 * Nothing caught them. Stylelint cannot express "these two values are
 * related", and a human reviewer reads `color: var(--bh-accent)` next to
 * `background: color-mix(..., var(--bh-accent) 14%, ...)` as obviously
 * coherent -- which is exactly why it kept happening.
 *
 * Scope is deliberately narrow: the rule must set BOTH color and a
 * background, and both must reference the SAME accent custom property. That
 * is the shape all three real failures took, and it keeps this from becoming
 * a wall of noise nobody reads.
 */
const fs = require('fs');
const path = require('path');

// ecosystem-plugins.txt lists FOLDER NAMES, not paths -- reading them as
// paths silently scans nothing and the check passes for the wrong reason.
// Caught by deliberately reintroducing the pattern and watching it not fire.
const REPO = path.resolve(__dirname, '..');
const ROOTS = fs.readFileSync(path.join(__dirname, 'ecosystem-plugins.txt'), 'utf8')
  .split('\n').map(l => l.trim()).filter(l => l && !l.startsWith('#'))
  .map(name => path.join(REPO, 'wp-content/plugins', name))
  .concat([path.join(REPO, 'wp-content/themes/the-self-hosted-self-theme')])
  .filter(p => fs.existsSync(p));

if (!ROOTS.length) {
  console.error('  No ecosystem CSS roots resolved — refusing to report a pass.');
  process.exit(2);
}

const files = [];
const walk = dir => {
  let entries;
  try { entries = fs.readdirSync(dir, { withFileTypes: true }); } catch { return; }
  for (const e of entries) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) {
      if (e.name === 'node_modules' || e.name === 'vendor') continue;
      walk(p);
    } else if (e.name.endsWith('.css')) files.push(p);
  }
};
for (const r of ROOTS) walk(r);

// Any semantic hue token, not just accent.
const HUE = /var\(\s*(--[a-z0-9-]*(?:accent|success|warning|danger|error|info)[a-z0-9-]*)/gi;

// A purpose-built -bg sibling is NOT this bug. --bh-success on
// --bh-success-bg is a pair someone chose and it measures 6.23:1 in dark and
// 10.66:1 in light. Treating those as the same hue flagged six rules that
// are all fine -- measured before "fixing" them, which is the only reason
// they were not broken in the name of a checker.
//
// What actually failed, all five times, is narrower: a background deriving a
// TINT of the very token used for the text, via color-mix(). Nobody chose
// that contrast; it fell out of the mix percentage.
const baseHue = t => t.toLowerCase();
const findings = [];

for (const file of files) {
  const css = fs.readFileSync(file, 'utf8');
  // crude rule split is enough: we only need declarations grouped per block
  const ruleRe = /([^{}]+)\{([^{}]*)\}/g;
  let m;
  while ((m = ruleRe.exec(css)) !== null) {
    const [, selector, body] = m;
    const colorDecl = /(?:^|;)\s*color\s*:([^;]+)/i.exec(body);
    const bgDecl = /(?:^|;)\s*background(?:-color)?\s*:([^;]+)/i.exec(body);
    if (!colorDecl || !bgDecl) continue;

    const tokensIn = s => {
      const out = new Set();
      let t; HUE.lastIndex = 0;
      while ((t = HUE.exec(s)) !== null) out.add(baseHue(t[1].toLowerCase()));
      return out;
    };
    const fg = tokensIn(colorDecl[1]);
    const bg = tokensIn(bgDecl[1]);
    // Only when the background MIXES the same token the text uses.
    if (!/color-mix\s*\(/i.test(bgDecl[1])) continue;
    const shared = [...fg].filter(t => bg.has(t));
    if (!shared.length) continue;

    const line = css.slice(0, m.index).split('\n').length;
    findings.push({
      file: path.relative(process.cwd(), file),
      line,
      selector: selector.trim().split('\n').map(s => s.trim()).join(' ').slice(0, 70),
      token: shared[0],
    });
  }
}

if (!files.length) {
  console.error('  No CSS files found under the ecosystem roots — refusing to report a pass.');
  process.exit(2);
}
if (!findings.length) {
  console.log(`  ACCENT-ON-TINT OK (${files.length} stylesheets) — no rule paints text on a color-mix() tint of its own token`);
  process.exit(0);
}
console.error(`\n  ${findings.length} rule(s) paint text on a color-mix() tint of that SAME token.`);
console.error('  Measured precedent: this shape read 3.23:1 on the course price badge.');
console.error('  Fix by using the surface foreground (--bh-text / --bhy-ink) for the text,');
console.error('  keeping the accent as background and border.\n');
for (const f of findings) {
  console.error(`    ${f.file}:${f.line}  ${f.token}`);
  console.error(`      ${f.selector}`);
}
process.exit(1);

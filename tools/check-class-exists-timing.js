#!/usr/bin/env node
/**
 * Advisory scan for CLAUDE.md's own most-repeated hard rule: a peer plugin
 * checks another plugin's class via class_exists() AT HOOK-CALL TIME, never
 * at file-parse time. "Grep for class_exists( before assuming a class is
 * always loaded" is literally the instruction CLAUDE.md gives; this
 * automates exactly that grep, plus the timing half nothing was checking.
 *
 * WHY grep-based, not a real PHP parser: this ecosystem's own precedent
 * (check-accent-on-tint.js) is a narrow, purpose-built check over a full
 * static-analysis tool, and the same "a green checker you've never seen
 * fail proves nothing" + "calibrate against real data before trusting it"
 * discipline applies here. A full control-flow analysis of "is this
 * specific class_exists() call reachable before the class it's checking
 * could exist" is a much bigger tool than this rule needs.
 *
 * Scope, deliberately narrow: flags a `class_exists(` call that appears
 * with NO leading whitespace -- i.e. a bare top-level statement in the
 * file, not nested inside any function/method/closure/hook callback. Real
 * hook-call-time guards are, definitionally, indented inside something;
 * an unindented one is either running at file-parse/require time (the
 * anti-pattern) or is one of the narrow, legitimate exceptions below.
 *
 * KNOWN, DOCUMENTED EXCEPTION: class-qm-integration.php's two top-level
 * class_exists('QM_Collector')/('QM_Output_Html') guards are NOT the
 * anti-pattern -- they guard a `class X extends Y` DECLARATION, and PHP
 * requires the parent class to already exist at the point a class
 * extending it is declared. That is not deferrable to "hook-call time"
 * the way a normal cross-plugin method call is; it is the one correct way
 * to conditionally declare a class extending an optional dependency. Same
 * file phpstan.neon already carves out an exception for, same reason.
 *
 * This is advisory only (always exits 0) -- see tools/check-version-
 * bump.sh's own header for the same "new check on a mature codebase"
 * reasoning. A hit here is worth a human look, not an automatic bug.
 */
const fs = require('fs');
const path = require('path');

const REPO = path.resolve(__dirname, '..');
const ROOTS = fs.readFileSync(path.join(__dirname, 'ecosystem-plugins.txt'), 'utf8')
  .split('\n').map(l => l.trim()).filter(l => l && !l.startsWith('#'))
  .map(name => path.join(REPO, 'wp-content/plugins', name))
  .filter(p => fs.existsSync(p));

if (!ROOTS.length) {
  console.error('  No ecosystem plugin roots resolved — refusing to report a pass.');
  process.exit(2);
}

const files = [];
const walk = dir => {
  let entries;
  try { entries = fs.readdirSync(dir, { withFileTypes: true }); } catch { return; }
  for (const e of entries) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) {
      if (e.name === 'node_modules' || e.name === 'vendor' || e.name === 'tests') continue;
      walk(p);
    } else if (e.name.endsWith('.php')) files.push(p);
  }
};
for (const root of ROOTS) walk(root);

let total = 0;
const findings = [];
for (const file of files) {
  const rel = path.relative(REPO, file);
  const lines = fs.readFileSync(file, 'utf8').split('\n');
  lines.forEach((line, i) => {
    if (!/^class_exists\(/.test(line) && !/^if\s*\(\s*class_exists\(/.test(line)) return;
    const lineNo = i + 1;
    // The one documented exception: a top-level class_exists() whose
    // block declares a class (checked by looking at whether the very
    // next non-blank line starts a class declaration, or the guard
    // itself is a one-line `if (...) { class ... extends ... {`).
    const rest = lines.slice(i, i + 2).join(' ');
    if (/class\s+\w+\s+extends\s+\w+/.test(rest)) return;
    findings.push({ file: rel, line: lineNo, text: line.trim() });
    total++;
  });
}

if (!total) {
  console.log('class-exists-timing: no top-level class_exists() calls found outside the documented class-declaration exception.');
  process.exit(0);
}

console.log(`class-exists-timing: ${total} top-level class_exists() call(s) found — review, not necessarily a bug:\n`);
for (const f of findings) {
  console.log(`  ${f.file}:${f.line}  ${f.text}`);
}
console.log('\nA bare (unindented) class_exists() runs at file-parse/load time, not deferred to a hook callback — the anti-pattern CLAUDE.md warns against for CROSS-PLUGIN checks specifically. Worth a look at each: is this checking another peer plugin (should move inside a hook callback), or this same plugin\'s own sibling class (lower-risk, but still worth tightening for consistency)?');
process.exit(0); // advisory only

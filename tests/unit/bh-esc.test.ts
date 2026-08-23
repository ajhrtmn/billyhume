import { describe, it, expect, beforeAll } from 'vitest';
import fs from 'node:fs';
import vm from 'node:vm';

/**
 * bhEsc() — the shared HTML-escaping helper used wherever untrusted text
 * (submission titles, category names, both from public unauthenticated input)
 * is written into innerHTML. Security-relevant, pure, and therefore exactly
 * the kind of logic worth pinning down.
 *
 * Tests the COMPILED artifact (assets/js/bh-common.js) rather than the .ts,
 * because that file is what actually ships and runs in a browser — a bug
 * introduced by the compiler would be invisible to a test of the source.
 * It's a bare global (module: "none"), so it's evaluated in a VM context.
 */
let bhEsc: (s: unknown) => string;

beforeAll(() => {
  const js = fs.readFileSync('wp-content/plugins/bh-contest/assets/js/bh-common.js', 'utf8');
  const ctx: Record<string, unknown> = {};
  vm.createContext(ctx);
  vm.runInContext(js, ctx);
  bhEsc = ctx.bhEsc as (s: unknown) => string;
  expect(typeof bhEsc, 'bhEsc should be defined as a global by the compiled file').toBe('function');
});

describe('bhEsc — each dangerous character', () => {
  it.each([
    ['&', '&amp;'],
    ['<', '&lt;'],
    ['>', '&gt;'],
    ['"', '&quot;'],
    ["'", '&#39;'],
  ])('escapes %s', (input, expected) => {
    expect(bhEsc(input)).toBe(expected);
  });
});

describe('bhEsc — real attack shapes', () => {
  it('neutralises a script tag', () => {
    expect(bhEsc('<script>alert(1)</script>'))
      .toBe('&lt;script&gt;alert(1)&lt;/script&gt;');
  });

  it('neutralises an attribute break-out with double quotes', () => {
    expect(bhEsc('" onmouseover="alert(1)'))
      .toBe('&quot; onmouseover=&quot;alert(1)');
  });

  it('neutralises an attribute break-out with single quotes', () => {
    expect(bhEsc("' onfocus='alert(1)"))
      .toBe('&#39; onfocus=&#39;alert(1)');
  });

  it('neutralises an img onerror payload', () => {
    expect(bhEsc('<img src=x onerror=alert(1)>'))
      .toBe('&lt;img src=x onerror=alert(1)&gt;');
  });

  it('leaves no raw angle bracket or quote behind for a mixed payload', () => {
    const out = bhEsc(`<a href="x" onclick='y'>&</a>`);
    expect(out).not.toMatch(/[<>"']/);
  });
});

describe('bhEsc — coercion and empty-ish input', () => {
  it.each([
    [null, ''],
    [undefined, ''],
    ['', ''],
  ])('maps %s to an empty string', (input, expected) => {
    expect(bhEsc(input)).toBe(expected);
  });

  it.each([
    [0, '0'],
    [42, '42'],
    [false, 'false'],
    [true, 'true'],
  ])('coerces %s via String()', (input, expected) => {
    expect(bhEsc(input)).toBe(expected);
  });

  it('does not treat 0 as null (a classic falsy bug)', () => {
    expect(bhEsc(0)).toBe('0');
  });

  it('coerces arrays and objects without throwing', () => {
    expect(() => bhEsc(['a', 'b'])).not.toThrow();
    expect(bhEsc(['a', 'b'])).toBe('a,b');
    expect(bhEsc({})).toBe('[object Object]');
  });
});

describe('bhEsc — passthrough and idempotency', () => {
  it('leaves ordinary text untouched', () => {
    expect(bhEsc('A perfectly normal title')).toBe('A perfectly normal title');
  });

  it('leaves unicode and emoji untouched', () => {
    expect(bhEsc('Ünïcödé 日本語 🎵')).toBe('Ünïcödé 日本語 🎵');
  });

  it('double-escapes when applied twice — so it must only be applied once', () => {
    // Documents real behaviour rather than asserting idempotency it doesn't have:
    // the & of an existing entity is itself escaped.
    expect(bhEsc(bhEsc('<b>'))).toBe('&amp;lt;b&amp;gt;');
  });

  it('escapes every occurrence, not just the first', () => {
    expect(bhEsc('<<<')).toBe('&lt;&lt;&lt;');
    expect(bhEsc('a&b&c')).toBe('a&amp;b&amp;c');
  });

  it('handles a long string without truncating', () => {
    const long = '<'.repeat(5000);
    expect(bhEsc(long)).toBe('&lt;'.repeat(5000));
  });
});

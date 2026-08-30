// @vitest-environment jsdom
import { describe, it, expect, beforeAll } from 'vitest';
import fs from 'node:fs';
import vm from 'node:vm';

/**
 * bh-courses lesson step DOM helpers — the exact logic behind this
 * session's most expensive bug: on the live Etch site the theme's
 * front-end hydration blanks class="" off every step wrapper on load,
 * so courses.js could no longer find or style the step and the whole
 * lesson container went invisible with no chapter list.
 *
 * Tests the COMPILED artifact (assets/js/courses.js) — that's what
 * ships — loaded once into this jsdom global. The file exposes the pure
 * helpers as window.BHCLessonStepDom for exactly this.
 */
type StepDom = {
  SELECTOR: string;
  className: (type: string, done: boolean) => string;
  reassert: (el: HTMLElement) => void;
  setVisible: (root: ParentNode, index: number) => number;
};

let dom: StepDom;

beforeAll(() => {
  const js = fs.readFileSync(
    'wp-content/plugins/bh-courses/assets/js/courses.js',
    'utf8',
  );
  // jsdom env => this global already has window/document/MutationObserver.
  vm.runInThisContext(js);
  dom = (globalThis as unknown as { window: { BHCLessonStepDom: StepDom } }).window.BHCLessonStepDom;
  expect(typeof dom, 'courses.js should expose window.BHCLessonStepDom').toBe('object');
});

/* -------------------------------------------------------------------- */

describe('className(type, done) — the rebuild rule', () => {
  it.each([
    ['video', false, 'bhc-step bhc-step-video'],
    ['quiz', true, 'bhc-step bhc-step-quiz bhc-step-done'],
    ['text', false, 'bhc-step bhc-step-text'],
    ['audio-compare', true, 'bhc-step bhc-step-audio-compare bhc-step-done'],
  ])('%s / done=%s → "%s"', (type, done, expected) => {
    expect(dom.className(type as string, done as boolean)).toBe(expected);
  });

  it('still yields a usable base class when the type is unknown/empty', () => {
    expect(dom.className('', false)).toBe('bhc-step');
    expect(dom.className('', true)).toBe('bhc-step bhc-step-done');
  });
});

describe('reassert(el) — repair a wrapper Etch stripped', () => {
  function stripped(type = 'video', done = ''): HTMLElement {
    const el = document.createElement('div');
    el.setAttribute('data-step-index', '0');
    el.dataset.stepType = type;
    if (done) el.dataset.stepDone = done;
    el.className = ''; // the Etch strip
    return el;
  }

  it('rebuilds bhc-step + type + done from the data-step-* attrs', () => {
    const el = stripped('quiz', '1');
    dom.reassert(el);
    expect(el.className).toBe('bhc-step bhc-step-quiz bhc-step-done');
  });

  it('leaves a wrapper whose class survived completely alone', () => {
    const el = stripped('video');
    el.className = 'bhc-step bhc-step-video bhc-step-entering';
    dom.reassert(el);
    expect(el.className).toBe('bhc-step bhc-step-video bhc-step-entering'); // not clobbered
  });

  it('is safe to run repeatedly (the 5s MutationObserver re-fires it)', () => {
    const el = stripped('text');
    dom.reassert(el);
    dom.reassert(el);
    dom.reassert(el);
    expect(el.className).toBe('bhc-step bhc-step-text');
  });

  it('treats a missing data-step-done as "not done", not a crash', () => {
    const el = stripped('resource');
    dom.reassert(el);
    expect(el.className).toBe('bhc-step bhc-step-resource');
  });
});

describe('setVisible(root, index) — show one step, hide the rest', () => {
  function lesson(count: number, strip = false): HTMLElement {
    const root = document.createElement('div');
    root.className = 'bhc-lesson';
    for (let i = 0; i < count; i++) {
      const step = document.createElement('div');
      step.setAttribute('data-step-index', String(i));
      step.dataset.stepType = 'video';
      step.className = strip ? '' : 'bhc-step bhc-step-video';
      if (i !== 0) step.style.display = 'none';
      root.appendChild(step);
    }
    return root;
  }
  const displayOf = (root: HTMLElement, i: number) =>
    (root.querySelector<HTMLElement>(`[data-step-index="${i}"]`) as HTMLElement).style.display;

  it('shows exactly the target and hides every sibling', () => {
    const root = lesson(3);
    expect(dom.setVisible(root, 2)).toBe(2);
    expect(displayOf(root, 0)).toBe('none');
    expect(displayOf(root, 1)).toBe('none');
    expect(displayOf(root, 2)).toBe(''); // shown
  });

  it('works when Etch has blanked every wrapper class — the real regression', () => {
    const root = lesson(3, /* strip */ true);
    // selection must not depend on `.bhc-step` surviving
    expect(dom.setVisible(root, 1)).toBe(1);
    expect(displayOf(root, 0)).toBe('none');
    expect(displayOf(root, 1)).toBe('');
    expect(displayOf(root, 2)).toBe('none');
  });

  it('returns -1 and hides everything when the index does not exist', () => {
    const root = lesson(2);
    expect(dom.setVisible(root, 99)).toBe(-1);
    expect(displayOf(root, 0)).toBe('none');
    expect(displayOf(root, 1)).toBe('none');
  });

  it('SELECTOR matches a wrapper by class OR by data-step-index', () => {
    expect(dom.SELECTOR).toContain('[data-step-index]');
    const byClassOnly = document.createElement('div');
    byClassOnly.className = 'bhc-step';
    expect(byClassOnly.matches(dom.SELECTOR)).toBe(true);
    const byDataOnly = document.createElement('div');
    byDataOnly.setAttribute('data-step-index', '0');
    expect(byDataOnly.matches(dom.SELECTOR)).toBe(true);
  });
});

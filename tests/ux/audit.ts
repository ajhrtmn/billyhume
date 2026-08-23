import type { Page } from '@playwright/test';

export type Finding = {
  kind: 'contrast' | 'clipping' | 'overflow' | 'touch-target';
  selector: string;
  detail: string;
  ratio?: number;
  needed?: number;
};

export const WIDTHS = [1440, 1280, 1024, 961, 782, 375] as const;
export const THEMES = ['dark', 'light'] as const;
export type Theme = (typeof THEMES)[number];

/**
 * Set the admin skin's theme and RELOAD.
 *
 * WHY reload rather than toggle: setting data-shsas-theme and re-reading
 * getComputedStyle in the same task does NOT re-resolve `var()` references on
 * already-styled elements. Doing that once produced 39 contrast failures that
 * did not exist. Never toggle-and-measure.
 */
export async function setTheme(page: Page, theme: Theme): Promise<void> {
  await page.addInitScript((t) => {
    try { window.localStorage.setItem('shsas-theme', t as string); } catch { /* storage blocked */ }
  }, theme);
  await page.reload({ waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(250); // let WooCommerce React widgets finish hydrating
}

/** Runs the measured audit inside the page and returns findings. */
export async function audit(page: Page): Promise<Finding[]> {
  return page.evaluate(() => {
    const findings: any[] = [];

    const parse = (s: string) => {
      if (!s || s === 'transparent') return { r: 0, g: 0, b: 0, a: 0 };
      const m = s.match(/rgba?\(([^)]+)\)/);
      if (!m) return null;
      const p = m[1].split(/[,\s/]+/).filter(Boolean).map(parseFloat);
      return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 };
    };
    const over = (src: any, dst: any) => ({
      r: src.r * src.a + dst.r * (1 - src.a),
      g: src.g * src.a + dst.g * (1 - src.a),
      b: src.b * src.a + dst.b * (1 - src.a), a: 1,
    });
    const lum = (c: any) => {
      const ch = (v: number) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
      return 0.2126 * ch(c.r) + 0.7152 * ch(c.g) + 0.0722 * ch(c.b);
    };
    const contrast = (f: any, b: any) => {
      const l1 = lum(f), l2 = lum(b), hi = Math.max(l1, l2), lo = Math.min(l1, l2);
      return (hi + 0.05) / (lo + 0.05);
    };

    // Base is BODY, not white — :root is transparent in this skin, and a white
    // fallback invents failures on every dark screen.
    const BASE = (() => {
      const b = parse(getComputedStyle(document.body).backgroundColor);
      if (b && b.a >= 1) return b;
      const h = parse(getComputedStyle(document.documentElement).backgroundColor);
      return h && h.a >= 1 ? h : { r: 255, g: 255, b: 255, a: 1 };
    })();

    const effectiveBg = (el: Element): any => {
      const stack: any[] = [];
      let node: Element | null = el;
      while (node) {
        const cs = getComputedStyle(node);
        if (cs.backgroundImage && cs.backgroundImage !== 'none') return null; // can't evaluate
        const c = parse(cs.backgroundColor);
        if (c && c.a > 0) { stack.push(c); if (c.a >= 1) break; }
        node = node.parentElement;
      }
      let result = BASE;
      for (let i = stack.length - 1; i >= 0; i--) result = over(stack[i], result);
      return result;
    };

    const sel = (el: Element) => {
      let s = el.tagName.toLowerCase();
      if (el.id) s += '#' + el.id;
      const cn = typeof el.className === 'string' ? el.className.trim().split(/\s+/).slice(0, 3).join('.') : '';
      return cn ? s + '.' + cn : s;
    };

    // ---- exclusions, each earned by a real false positive ----
    const excluded = (el: Element) => {
      // Screen-reader-only text: deliberately clipped to ~1px.
      if (el.closest('.screen-reader-text, .screen-reader-shortcut')) return true;
      // Query Monitor is third-party chrome, not ours.
      if (el.closest('#query-monitor-main, #wp-admin-bar-query-monitor, #qm')) return true;
      const cs = getComputedStyle(el);
      if (cs.clipPath && /inset\(\s*50%/.test(cs.clipPath)) return true;
      if (cs.clip && cs.clip !== 'auto' && cs.clip !== 'rect(auto, auto, auto, auto)') return true;
      const r = el.getBoundingClientRect();
      if (r.width <= 1.5 && r.height <= 1.5) return true;
      if (r.left < -400 || r.top < -400) return true;
      return false;
    };
    const visible = (el: Element) => {
      const cs = getComputedStyle(el);
      if (cs.display === 'none' || cs.visibility === 'hidden' || parseFloat(cs.opacity) === 0) return false;
      const r = el.getBoundingClientRect();
      if (r.width < 1 || r.height < 1) return false;
      if ((el as HTMLElement).offsetParent === null && cs.position !== 'fixed') return false;
      return true;
    };

    // ---- horizontal overflow (page level) ----
    const de = document.documentElement;
    if (de.scrollWidth > window.innerWidth + 1) {
      findings.push({ kind: 'overflow', selector: 'html', detail: `scrollWidth ${de.scrollWidth} > innerWidth ${window.innerWidth}` });
    }

    const isMobile = window.innerWidth <= 782;
    for (const el of Array.from(document.querySelectorAll('body *'))) {
      if (!visible(el) || excluded(el)) continue;
      const cs = getComputedStyle(el);

      // ---- clipping ----
      // clientHeight 0 means deliberately collapsed (a closed nav/accordion),
      // not sheared content — the whole point of the collapse is to hide it.
      const collapsed = el.clientHeight === 0 || el.clientWidth === 0;
      const clipY = !collapsed && (cs.overflowY === 'hidden' || cs.overflowY === 'clip') && el.scrollHeight > el.clientHeight + 2;
      let clipX = !collapsed && (cs.overflowX === 'hidden' || cs.overflowX === 'clip') && el.scrollWidth > el.clientWidth + 2;
      // Deliberate truncation, not a clip.
      if (clipX && cs.textOverflow === 'ellipsis' && cs.whiteSpace === 'nowrap') clipX = false;
      // WP core's own icon-only mechanism at <=782 pushes label text out of the box.
      if (clipX && cs.textIndent === '100%') clipX = false;
      // Overflow caused only by out-of-flow decoration (an absolutely-positioned
      // starburst, glow, or bleed layer) is the whole point of `overflow: hidden`
      // on that container — it is not sheared content.
      let decorativeOnly = false;
      if (clipY || clipX) {
        const kids = Array.from(el.children) as HTMLElement[];
        const outOfBox = kids.filter(k => {
          const kr = k.getBoundingClientRect(), er = el.getBoundingClientRect();
          return kr.height > er.height + 2 || kr.top < er.top - 2 || kr.bottom > er.bottom + 2
              || kr.left < er.left - 2 || kr.right > er.right + 2;
        });
        decorativeOnly = outOfBox.length > 0 && outOfBox.every(k => {
          const pos = getComputedStyle(k).position;
          return pos === 'absolute' || pos === 'fixed';
        });
      }
      if ((clipY || clipX) && !decorativeOnly) {
        findings.push({
          kind: 'clipping', selector: sel(el),
          detail: clipY ? `scrollHeight ${el.scrollHeight} > clientHeight ${el.clientHeight}`
                        : `scrollWidth ${el.scrollWidth} > clientWidth ${el.clientWidth}`,
        });
      }

      // ---- touch targets ----
      if (isMobile && el.matches('a[href], button, input:not([type=hidden]), select, textarea, [role=button]')) {
        const r = el.getBoundingClientRect();
        // WCAG 2.5.8 exempts links rendered inline within a block of text —
        // enlarging them would break the paragraph. Only standalone controls
        // (block/flex/inline-block) carry the 44px minimum.
        const inlineInText = cs.display === 'inline' && !!el.closest('p, li, td, blockquote, figcaption');
        if (!inlineInText && r.width > 0 && r.height > 0 && (r.width < 44 || r.height < 44)) {
          findings.push({ kind: 'touch-target', selector: sel(el), detail: `${Math.round(r.width)}x${Math.round(r.height)} (min 44x44)` });
        }
      }

      // ---- contrast, on elements with their own text ----
      let direct = '';
      for (const n of Array.from(el.childNodes)) if (n.nodeType === 3) direct += n.nodeValue;
      direct = direct.trim();
      if (direct.length < 2) continue;
      let fg = parse(cs.color);
      if (!fg) continue;
      const bg = effectiveBg(el);
      if (!bg) continue;
      if (fg.a < 1) fg = over(fg, bg);
      const ratio = contrast(fg, bg);
      const fs = parseFloat(cs.fontSize), fw = parseInt(cs.fontWeight) || 400;
      const large = fs >= 24 || (fs >= 18.66 && fw >= 700);
      const needed = large ? 3 : 4.5;
      if (ratio < needed) {
        findings.push({
          kind: 'contrast', selector: sel(el),
          ratio: Math.round(ratio * 100) / 100, needed,
          detail: `${cs.color} on rgb(${Math.round(bg.r)},${Math.round(bg.g)},${Math.round(bg.b)}) — "${direct.slice(0, 40)}"`,
        });
      }
    }
    return findings;
  });
}

export function formatFindings(label: string, findings: Finding[]): string {
  if (!findings.length) return `${label}: clean`;
  const byKind = findings.reduce<Record<string, number>>((a, f) => (a[f.kind] = (a[f.kind] || 0) + 1, a), {});
  const lines = findings.slice(0, 25).map(f =>
    `    [${f.kind}] ${f.selector}${f.ratio ? ` ${f.ratio}:1 (need ${f.needed})` : ''} — ${f.detail}`);
  const more = findings.length > 25 ? `\n    ... +${findings.length - 25} more` : '';
  return `${label}: ${JSON.stringify(byKind)}\n${lines.join('\n')}${more}`;
}

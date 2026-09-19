/**
 * Browser mirror of BHY_Color + BHY_Contrast::resolve() (PHP:
 * includes/class-color.php, includes/class-contrast.php). Used ONLY by
 * the Design Suite live preview so it shows the same derived palette a
 * save would produce.
 *
 * The recipe table is NOT duplicated here — PHP localises
 * BHY_Contrast::spec() as `window.bhyContrastSpec` and this file
 * interprets it. Keep the maths in step with class-color.php; the
 * OUS_ContrastTestSuite calibration snapshot is the cross-check.
 *
 * Build: `npx tsc` in the plugin root (module: none, ES2019). Commit the
 * generated assets/js/contrast-core.js alongside this file.
 */
interface BhySpecEntry {
  label?: string;
  intent?: string;
  against?: string | string[] | null;
  derive: "mix" | "ink-on" | "solve-min" | "approach";
  from?: string;
  with?: string;
  weight?: number;
  emit?: "color-mix" | "hex";
  on?: string;
  inks?: string[];
  metric?: "apca" | "wcag";
  base?: string;
  target_lc?: number;
  chroma_mul?: number;
  target?: { apca?: number; wcag?: number };
  ceiling?: { apca?: number };
}
type BhySpec = Record<string, BhySpecEntry>;
type Rgb = [number, number, number];
type Oklch = { L: number; C: number; h: number };

(function () {
  "use strict";

  // ---- hex <-> rgb ------------------------------------------------

  function hexToRgb(hex: string): Rgb {
    let h = hex.trim().replace(/^#/, "");
    if (h.length === 3 || h.length === 4) {
      const r = h[0] ?? "0";
      const g = h[1] ?? "0";
      const b = h[2] ?? "0";
      h = r + r + g + g + b + b;
    }
    if (h.length < 6 || !/^[0-9a-fA-F]{6}/.test(h)) return [0, 0, 0];
    return [
      parseInt(h.slice(0, 2), 16),
      parseInt(h.slice(2, 4), 16),
      parseInt(h.slice(4, 6), 16),
    ];
  }

  function clamp255(v: number): number {
    return Math.max(0, Math.min(255, Math.round(v)));
  }

  function rgbToHex(rgb: Rgb): string {
    return (
      "#" +
      rgb
        .map((c) => clamp255(c).toString(16).padStart(2, "0"))
        .join("")
    );
  }

  // ---- WCAG 2.x ----------------------------------------------------

  function wcagLum(rgb: Rgb): number {
    const ch = rgb.map((c) => {
      const v = c / 255;
      return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
    });
    return 0.2126 * (ch[0] ?? 0) + 0.7152 * (ch[1] ?? 0) + 0.0722 * (ch[2] ?? 0);
  }

  function wcagRatio(a: string, b: string): number {
    const la = wcagLum(hexToRgb(a));
    const lb = wcagLum(hexToRgb(b));
    const hi = Math.max(la, lb);
    const lo = Math.min(la, lb);
    return (hi + 0.05) / (lo + 0.05);
  }

  // ---- APCA-style perceptual contrast ---------------------------

  const APCA = {
    R: 0.2126729,
    G: 0.7151522,
    B: 0.072175,
    TRC: 2.4,
    normBG: 0.56,
    normTXT: 0.57,
    revTXT: 0.62,
    revBG: 0.65,
    blkThrs: 0.022,
    blkClmp: 1.414,
    scale: 1.14,
    loOffset: 0.027,
    loClip: 0.1,
    deltaYmin: 0.0005,
  };

  function apcaY(rgb: Rgb): number {
    const r = Math.pow((rgb[0] ?? 0) / 255, APCA.TRC);
    const g = Math.pow((rgb[1] ?? 0) / 255, APCA.TRC);
    const b = Math.pow((rgb[2] ?? 0) / 255, APCA.TRC);
    return APCA.R * r + APCA.G * g + APCA.B * b;
  }

  function apcaLc(textHex: string, bgHex: string): number {
    let txt = apcaY(hexToRgb(textHex));
    let bg = apcaY(hexToRgb(bgHex));
    txt = txt > APCA.blkThrs ? txt : txt + Math.pow(APCA.blkThrs - txt, APCA.blkClmp);
    bg = bg > APCA.blkThrs ? bg : bg + Math.pow(APCA.blkThrs - bg, APCA.blkClmp);
    if (Math.abs(bg - txt) < APCA.deltaYmin) return 0;
    let out: number;
    if (bg > txt) {
      const sapc = (Math.pow(bg, APCA.normBG) - Math.pow(txt, APCA.normTXT)) * APCA.scale;
      out = sapc < APCA.loClip ? 0 : sapc - APCA.loOffset;
    } else {
      const sapc = (Math.pow(bg, APCA.revBG) - Math.pow(txt, APCA.revTXT)) * APCA.scale;
      out = sapc > -APCA.loClip ? 0 : sapc + APCA.loOffset;
    }
    return out * 100;
  }

  function apcaAbs(t: string, b: string): number {
    return Math.abs(apcaLc(t, b));
  }

  // ---- sRGB <-> OKLab / OKLCH ----------------------------------

  function toLinear(v: number): number {
    const x = v / 255;
    return x <= 0.04045 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4);
  }

  function toGamma(v: number): number {
    const x = v <= 0.0031308 ? v * 12.92 : 1.055 * Math.pow(v, 1 / 2.4) - 0.055;
    return x * 255;
  }

  function cbrt(v: number): number {
    return v < 0 ? -Math.pow(-v, 1 / 3) : Math.pow(v, 1 / 3);
  }

  function srgbToOklch(rgb: Rgb): Oklch {
    const r = toLinear(rgb[0] ?? 0);
    const g = toLinear(rgb[1] ?? 0);
    const b = toLinear(rgb[2] ?? 0);
    const l = 0.4122214708 * r + 0.5363325363 * g + 0.0514459929 * b;
    const m = 0.2119034982 * r + 0.6806995451 * g + 0.1073969566 * b;
    const s = 0.0883024619 * r + 0.2817188376 * g + 0.6299787005 * b;
    const l_ = cbrt(l);
    const m_ = cbrt(m);
    const s_ = cbrt(s);
    const L = 0.2104542553 * l_ + 0.793617785 * m_ - 0.0040720468 * s_;
    const A = 1.9779984951 * l_ - 2.428592205 * m_ + 0.4505937099 * s_;
    const B = 0.0259040371 * l_ + 0.7827717662 * m_ - 0.808675766 * s_;
    const C = Math.sqrt(A * A + B * B);
    let h = C < 1e-9 ? 0 : (Math.atan2(B, A) * 180) / Math.PI;
    if (h < 0) h += 360;
    return { L, C, h };
  }

  function oklabToSrgb(L: number, A: number, B: number): Rgb {
    const l_ = L + 0.3963377774 * A + 0.2158037573 * B;
    const m_ = L - 0.1055613458 * A - 0.0638541728 * B;
    const s_ = L - 0.0894841775 * A - 1.291485548 * B;
    const l = l_ * l_ * l_;
    const m = m_ * m_ * m_;
    const s = s_ * s_ * s_;
    const r = 4.0767416621 * l - 3.3077115913 * m + 0.2309699292 * s;
    const g = -1.2684380046 * l + 2.6097574011 * m - 0.3413193965 * s;
    const b = -0.0041960863 * l - 0.7034186147 * m + 1.707614701 * s;
    return [toGamma(r), toGamma(g), toGamma(b)];
  }

  function hexToOklch(hex: string): Oklch {
    return srgbToOklch(hexToRgb(hex));
  }

  function inGamut(L: number, C: number, h: number): boolean {
    const a = C * Math.cos((h * Math.PI) / 180);
    const b = C * Math.sin((h * Math.PI) / 180);
    const rgb = oklabToSrgb(L, a, b);
    return rgb.every((c) => c >= -0.5 && c <= 255.5);
  }

  function oklchToHex(L: number, C: number, h: number): string {
    L = Math.max(0, Math.min(1, L));
    if (!inGamut(L, C, h)) {
      let lo = 0;
      let hi = C;
      for (let i = 0; i < 24; i++) {
        const mid = (lo + hi) / 2;
        if (inGamut(L, mid, h)) lo = mid;
        else hi = mid;
      }
      C = lo;
    }
    const a = C * Math.cos((h * Math.PI) / 180);
    const b = C * Math.sin((h * Math.PI) / 180);
    return rgbToHex(oklabToSrgb(L, a, b));
  }

  // ---- derivations --------------------------------------------

  function solveLightnessForApca(
    baseHex: string,
    againstHex: string,
    targetLc: number
  ): string {
    const base = hexToOklch(baseHex);
    const against = hexToOklch(againstHex);
    const dir = against.L >= base.L ? -1 : 1;
    let lo = dir > 0 ? base.L : 0;
    let hi = dir > 0 ? 1 : base.L;
    const extreme = oklchToHex(dir > 0 ? hi : lo, base.C, base.h);
    if (apcaAbs(extreme, againstHex) < targetLc) return extreme;
    for (let i = 0; i < 22; i++) {
      const mid = (lo + hi) / 2;
      const enough = apcaAbs(oklchToHex(mid, base.C, base.h), againstHex) >= targetLc;
      if (dir > 0) {
        if (enough) hi = mid;
        else lo = mid;
      } else {
        if (enough) lo = mid;
        else hi = mid;
      }
    }
    return oklchToHex(dir > 0 ? hi : lo, base.C, base.h);
  }

  function approachApcaTarget(
    baseHex: string,
    againstHex: string,
    targetLc: number,
    chromaMul: number
  ): string {
    const base = hexToOklch(baseHex);
    const against = hexToOklch(againstHex);
    const c = Math.max(0, base.C * chromaMul);
    const at = (L: number) => apcaAbs(oklchToHex(L, c, base.h), againstHex);
    const cur = at(base.L);
    let lo: number;
    let hi: number;
    const towardBg = against.L >= base.L ? 1 : -1;
    if (cur > targetLc) {
      lo = base.L;
      hi = against.L;
    } else {
      lo = base.L;
      hi = towardBg > 0 ? 0 : 1;
    }
    for (let i = 0; i < 22; i++) {
      const mid = (lo + hi) / 2;
      const enough = at(mid) >= targetLc;
      if (cur > targetLc) {
        if (enough) lo = mid;
        else hi = mid;
      } else {
        if (enough) hi = mid;
        else lo = mid;
      }
    }
    return oklchToHex((lo + hi) / 2, c, base.h);
  }

  function bestInkOn(
    bgHex: string,
    inks: string[],
    metric: "apca" | "wcag",
    min: number
  ): string {
    let best = inks[0] ?? "#000000";
    let bestScore = -1;
    for (const ink of inks) {
      const score = metric === "wcag" ? wcagRatio(ink, bgHex) : apcaAbs(ink, bgHex);
      if (min > 0 && score >= min) return ink;
      if (score > bestScore) {
        bestScore = score;
        best = ink;
      }
    }
    return best;
  }

  function mixSrgb(aHex: string, bHex: string, weightA: number): string {
    const w = Math.max(0, Math.min(1, weightA));
    const a = hexToRgb(aHex);
    const b = hexToRgb(bHex);
    return rgbToHex([
      a[0] * w + b[0] * (1 - w),
      a[1] * w + b[1] * (1 - w),
      a[2] * w + b[2] * (1 - w),
    ]);
  }

  // ---- resolver ------------------------------------------------

  function normHex(val: string): string {
    const v = (val || "").trim();
    if (/^#[0-9a-fA-F]{6}$/.test(v)) return v.toLowerCase();
    if (/^#[0-9a-fA-F]{3}$/.test(v)) {
      return ("#" + v[1] + v[1] + v[2] + v[2] + v[3] + v[3]).toLowerCase();
    }
    if (/^#[0-9a-fA-F]{8}$/.test(v)) return v.slice(0, 7).toLowerCase();
    return "#000000";
  }

  function fmtPct(n: number): string {
    return String(Math.round(n * 100) / 100);
  }

  /**
   * @param seeds  { "--bh-bg": "#...", ... }
   * @param spec   window.bhyContrastSpec (BHY_Contrast::spec())
   * @returns      derived-only { "--bh-*": cssValue } — values may be color-mix() strings
   */
  function resolve(seeds: Record<string, string>, spec: BhySpec): Record<string, string> {
    const concrete: Record<string, string> = {};
    for (const k of Object.keys(seeds)) concrete[k] = normHex(seeds[k] ?? "");

    const ref = (key: string): string | null => {
      if (key === "black") return "#000000";
      if (key === "white") return "#ffffff";
      if (key && key[0] === "#") return key;
      return concrete[key] ?? null;
    };

    const css: Record<string, string> = {};

    for (const name of Object.keys(spec)) {
      const r = spec[name];
      if (!r) continue;
      let value: string | null = null;
      let hex: string | null = null;

      if (r.derive === "mix") {
        const a = ref(r.from ?? "");
        const b = ref(r.with ?? "");
        if (a !== null && b !== null) {
          const w = r.weight ?? 0;
          hex = mixSrgb(a, b, w);
          const bCss = r.with === "black" || r.with === "white" ? r.with : b;
          value =
            (r.emit ?? "hex") === "color-mix"
              ? `color-mix(in srgb, ${a} ${fmtPct(w * 100)}%, ${bCss})`
              : hex;
        }
      } else if (r.derive === "ink-on") {
        const bg = ref(r.on ?? "");
        if (bg !== null) {
          const inks: string[] = [];
          for (const ink of r.inks ?? []) {
            const v = ref(ink);
            if (v !== null) inks.push(v);
          }
          if (inks.length) {
            const metric = r.metric ?? "apca";
            const min = metric === "apca" ? r.target?.apca ?? 0 : 0;
            hex = bestInkOn(bg, inks, metric, min);
            value = hex;
          }
        }
      } else if (r.derive === "solve-min") {
        const base = ref(r.base ?? "");
        if (base !== null) {
          const againsts = (Array.isArray(r.against) ? r.against : [r.against])
            .map((k) => (k ? ref(k) : null))
            .filter((v): v is string => v !== null);
          const list = againsts.length ? againsts : ["#000000"];
          let bestCand: string | null = null;
          let bestWorst = -1;
          for (const ag of list) {
            const cand = solveLightnessForApca(base, ag, r.target_lc ?? 0);
            let worst = Infinity;
            for (const a2 of list) worst = Math.min(worst, apcaAbs(cand, a2));
            if (worst > bestWorst) {
              bestWorst = worst;
              bestCand = cand;
            }
          }
          hex = bestCand ?? base;
          value = hex;
        }
      } else if (r.derive === "approach") {
        const base = ref(r.base ?? "");
        const againstKey = Array.isArray(r.against) ? r.against[0] : r.against;
        const against = againstKey ? ref(againstKey) : null;
        if (base !== null && against !== null) {
          hex = approachApcaTarget(base, against, r.target_lc ?? 0, r.chroma_mul ?? 1);
          value = hex;
        }
      }

      if (value !== null && hex !== null) {
        css[name] = value;
        concrete[name] = hex;
      }
    }

    return css;
  }

  (window as unknown as { BhyContrast: unknown }).BhyContrast = {
    resolve,
    apcaLc,
    apcaAbs,
    wcagRatio,
    hexToOklch,
    oklchToHex,
    mixSrgb,
  };
})();

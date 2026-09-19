"use strict";
(function () {
    "use strict";
    // ---- hex <-> rgb ------------------------------------------------
    function hexToRgb(hex) {
        var _a, _b, _c;
        let h = hex.trim().replace(/^#/, "");
        if (h.length === 3 || h.length === 4) {
            const r = (_a = h[0]) !== null && _a !== void 0 ? _a : "0";
            const g = (_b = h[1]) !== null && _b !== void 0 ? _b : "0";
            const b = (_c = h[2]) !== null && _c !== void 0 ? _c : "0";
            h = r + r + g + g + b + b;
        }
        if (h.length < 6 || !/^[0-9a-fA-F]{6}/.test(h))
            return [0, 0, 0];
        return [
            parseInt(h.slice(0, 2), 16),
            parseInt(h.slice(2, 4), 16),
            parseInt(h.slice(4, 6), 16),
        ];
    }
    function clamp255(v) {
        return Math.max(0, Math.min(255, Math.round(v)));
    }
    function rgbToHex(rgb) {
        return ("#" +
            rgb
                .map((c) => clamp255(c).toString(16).padStart(2, "0"))
                .join(""));
    }
    // ---- WCAG 2.x ----------------------------------------------------
    function wcagLum(rgb) {
        var _a, _b, _c;
        const ch = rgb.map((c) => {
            const v = c / 255;
            return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
        });
        return 0.2126 * ((_a = ch[0]) !== null && _a !== void 0 ? _a : 0) + 0.7152 * ((_b = ch[1]) !== null && _b !== void 0 ? _b : 0) + 0.0722 * ((_c = ch[2]) !== null && _c !== void 0 ? _c : 0);
    }
    function wcagRatio(a, b) {
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
    function apcaY(rgb) {
        var _a, _b, _c;
        const r = Math.pow(((_a = rgb[0]) !== null && _a !== void 0 ? _a : 0) / 255, APCA.TRC);
        const g = Math.pow(((_b = rgb[1]) !== null && _b !== void 0 ? _b : 0) / 255, APCA.TRC);
        const b = Math.pow(((_c = rgb[2]) !== null && _c !== void 0 ? _c : 0) / 255, APCA.TRC);
        return APCA.R * r + APCA.G * g + APCA.B * b;
    }
    function apcaLc(textHex, bgHex) {
        let txt = apcaY(hexToRgb(textHex));
        let bg = apcaY(hexToRgb(bgHex));
        txt = txt > APCA.blkThrs ? txt : txt + Math.pow(APCA.blkThrs - txt, APCA.blkClmp);
        bg = bg > APCA.blkThrs ? bg : bg + Math.pow(APCA.blkThrs - bg, APCA.blkClmp);
        if (Math.abs(bg - txt) < APCA.deltaYmin)
            return 0;
        let out;
        if (bg > txt) {
            const sapc = (Math.pow(bg, APCA.normBG) - Math.pow(txt, APCA.normTXT)) * APCA.scale;
            out = sapc < APCA.loClip ? 0 : sapc - APCA.loOffset;
        }
        else {
            const sapc = (Math.pow(bg, APCA.revBG) - Math.pow(txt, APCA.revTXT)) * APCA.scale;
            out = sapc > -APCA.loClip ? 0 : sapc + APCA.loOffset;
        }
        return out * 100;
    }
    function apcaAbs(t, b) {
        return Math.abs(apcaLc(t, b));
    }
    // ---- sRGB <-> OKLab / OKLCH ----------------------------------
    function toLinear(v) {
        const x = v / 255;
        return x <= 0.04045 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4);
    }
    function toGamma(v) {
        const x = v <= 0.0031308 ? v * 12.92 : 1.055 * Math.pow(v, 1 / 2.4) - 0.055;
        return x * 255;
    }
    function cbrt(v) {
        return v < 0 ? -Math.pow(-v, 1 / 3) : Math.pow(v, 1 / 3);
    }
    function srgbToOklch(rgb) {
        var _a, _b, _c;
        const r = toLinear((_a = rgb[0]) !== null && _a !== void 0 ? _a : 0);
        const g = toLinear((_b = rgb[1]) !== null && _b !== void 0 ? _b : 0);
        const b = toLinear((_c = rgb[2]) !== null && _c !== void 0 ? _c : 0);
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
        if (h < 0)
            h += 360;
        return { L, C, h };
    }
    function oklabToSrgb(L, A, B) {
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
    function hexToOklch(hex) {
        return srgbToOklch(hexToRgb(hex));
    }
    function inGamut(L, C, h) {
        const a = C * Math.cos((h * Math.PI) / 180);
        const b = C * Math.sin((h * Math.PI) / 180);
        const rgb = oklabToSrgb(L, a, b);
        return rgb.every((c) => c >= -0.5 && c <= 255.5);
    }
    function oklchToHex(L, C, h) {
        L = Math.max(0, Math.min(1, L));
        if (!inGamut(L, C, h)) {
            let lo = 0;
            let hi = C;
            for (let i = 0; i < 24; i++) {
                const mid = (lo + hi) / 2;
                if (inGamut(L, mid, h))
                    lo = mid;
                else
                    hi = mid;
            }
            C = lo;
        }
        const a = C * Math.cos((h * Math.PI) / 180);
        const b = C * Math.sin((h * Math.PI) / 180);
        return rgbToHex(oklabToSrgb(L, a, b));
    }
    // ---- derivations --------------------------------------------
    function solveLightnessForApca(baseHex, againstHex, targetLc) {
        const base = hexToOklch(baseHex);
        const against = hexToOklch(againstHex);
        const dir = against.L >= base.L ? -1 : 1;
        let lo = dir > 0 ? base.L : 0;
        let hi = dir > 0 ? 1 : base.L;
        const extreme = oklchToHex(dir > 0 ? hi : lo, base.C, base.h);
        if (apcaAbs(extreme, againstHex) < targetLc)
            return extreme;
        for (let i = 0; i < 22; i++) {
            const mid = (lo + hi) / 2;
            const enough = apcaAbs(oklchToHex(mid, base.C, base.h), againstHex) >= targetLc;
            if (dir > 0) {
                if (enough)
                    hi = mid;
                else
                    lo = mid;
            }
            else {
                if (enough)
                    lo = mid;
                else
                    hi = mid;
            }
        }
        return oklchToHex(dir > 0 ? hi : lo, base.C, base.h);
    }
    function approachApcaTarget(baseHex, againstHex, targetLc, chromaMul) {
        const base = hexToOklch(baseHex);
        const against = hexToOklch(againstHex);
        const c = Math.max(0, base.C * chromaMul);
        const at = (L) => apcaAbs(oklchToHex(L, c, base.h), againstHex);
        const cur = at(base.L);
        let lo;
        let hi;
        const towardBg = against.L >= base.L ? 1 : -1;
        if (cur > targetLc) {
            lo = base.L;
            hi = against.L;
        }
        else {
            lo = base.L;
            hi = towardBg > 0 ? 0 : 1;
        }
        for (let i = 0; i < 22; i++) {
            const mid = (lo + hi) / 2;
            const enough = at(mid) >= targetLc;
            if (cur > targetLc) {
                if (enough)
                    lo = mid;
                else
                    hi = mid;
            }
            else {
                if (enough)
                    hi = mid;
                else
                    lo = mid;
            }
        }
        return oklchToHex((lo + hi) / 2, c, base.h);
    }
    function bestInkOn(bgHex, inks, metric, min) {
        var _a;
        let best = (_a = inks[0]) !== null && _a !== void 0 ? _a : "#000000";
        let bestScore = -1;
        for (const ink of inks) {
            const score = metric === "wcag" ? wcagRatio(ink, bgHex) : apcaAbs(ink, bgHex);
            if (min > 0 && score >= min)
                return ink;
            if (score > bestScore) {
                bestScore = score;
                best = ink;
            }
        }
        return best;
    }
    function mixSrgb(aHex, bHex, weightA) {
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
    function normHex(val) {
        const v = (val || "").trim();
        if (/^#[0-9a-fA-F]{6}$/.test(v))
            return v.toLowerCase();
        if (/^#[0-9a-fA-F]{3}$/.test(v)) {
            return ("#" + v[1] + v[1] + v[2] + v[2] + v[3] + v[3]).toLowerCase();
        }
        if (/^#[0-9a-fA-F]{8}$/.test(v))
            return v.slice(0, 7).toLowerCase();
        return "#000000";
    }
    function fmtPct(n) {
        return String(Math.round(n * 100) / 100);
    }
    /**
     * @param seeds  { "--bh-bg": "#...", ... }
     * @param spec   window.bhyContrastSpec (BHY_Contrast::spec())
     * @returns      derived-only { "--bh-*": cssValue } — values may be color-mix() strings
     */
    function resolve(seeds, spec) {
        var _a, _b, _c, _d, _e, _f, _g, _h, _j, _k, _l, _m, _o, _p, _q;
        const concrete = {};
        for (const k of Object.keys(seeds))
            concrete[k] = normHex((_a = seeds[k]) !== null && _a !== void 0 ? _a : "");
        const ref = (key) => {
            var _a;
            if (key === "black")
                return "#000000";
            if (key === "white")
                return "#ffffff";
            if (key && key[0] === "#")
                return key;
            return (_a = concrete[key]) !== null && _a !== void 0 ? _a : null;
        };
        const css = {};
        for (const name of Object.keys(spec)) {
            const r = spec[name];
            if (!r)
                continue;
            let value = null;
            let hex = null;
            if (r.derive === "mix") {
                const a = ref((_b = r.from) !== null && _b !== void 0 ? _b : "");
                const b = ref((_c = r.with) !== null && _c !== void 0 ? _c : "");
                if (a !== null && b !== null) {
                    const w = (_d = r.weight) !== null && _d !== void 0 ? _d : 0;
                    hex = mixSrgb(a, b, w);
                    const bCss = r.with === "black" || r.with === "white" ? r.with : b;
                    value =
                        ((_e = r.emit) !== null && _e !== void 0 ? _e : "hex") === "color-mix"
                            ? `color-mix(in srgb, ${a} ${fmtPct(w * 100)}%, ${bCss})`
                            : hex;
                }
            }
            else if (r.derive === "ink-on") {
                const bg = ref((_f = r.on) !== null && _f !== void 0 ? _f : "");
                if (bg !== null) {
                    const inks = [];
                    for (const ink of (_g = r.inks) !== null && _g !== void 0 ? _g : []) {
                        const v = ref(ink);
                        if (v !== null)
                            inks.push(v);
                    }
                    if (inks.length) {
                        const metric = (_h = r.metric) !== null && _h !== void 0 ? _h : "apca";
                        const min = metric === "apca" ? (_k = (_j = r.target) === null || _j === void 0 ? void 0 : _j.apca) !== null && _k !== void 0 ? _k : 0 : 0;
                        hex = bestInkOn(bg, inks, metric, min);
                        value = hex;
                    }
                }
            }
            else if (r.derive === "solve-min") {
                const base = ref((_l = r.base) !== null && _l !== void 0 ? _l : "");
                if (base !== null) {
                    const againsts = (Array.isArray(r.against) ? r.against : [r.against])
                        .map((k) => (k ? ref(k) : null))
                        .filter((v) => v !== null);
                    const list = againsts.length ? againsts : ["#000000"];
                    let bestCand = null;
                    let bestWorst = -1;
                    for (const ag of list) {
                        const cand = solveLightnessForApca(base, ag, (_m = r.target_lc) !== null && _m !== void 0 ? _m : 0);
                        let worst = Infinity;
                        for (const a2 of list)
                            worst = Math.min(worst, apcaAbs(cand, a2));
                        if (worst > bestWorst) {
                            bestWorst = worst;
                            bestCand = cand;
                        }
                    }
                    hex = bestCand !== null && bestCand !== void 0 ? bestCand : base;
                    value = hex;
                }
            }
            else if (r.derive === "approach") {
                const base = ref((_o = r.base) !== null && _o !== void 0 ? _o : "");
                const againstKey = Array.isArray(r.against) ? r.against[0] : r.against;
                const against = againstKey ? ref(againstKey) : null;
                if (base !== null && against !== null) {
                    hex = approachApcaTarget(base, against, (_p = r.target_lc) !== null && _p !== void 0 ? _p : 0, (_q = r.chroma_mul) !== null && _q !== void 0 ? _q : 1);
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
    window.BhyContrast = {
        resolve,
        apcaLc,
        apcaAbs,
        wcagRatio,
        hexToOklch,
        oklchToHex,
        mixSrgb,
    };
})();

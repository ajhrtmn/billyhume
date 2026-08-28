<?php
if (!defined('ABSPATH')) exit;

/**
 * Reusable form components for the gallery's controls panel — extracted
 * directly from bh-contest's original settings page. These were already
 * fully generic (no contest-specific naming or behavior baked in), so
 * this is a clean move, not a rewrite.
 */
class BHY_UI {
    // Audit fix (2026-07-25): moved out of BHY_Style, which had no other
    // front-end list/catalog UI components — this class (reusable UI
    // pieces) is the more cohesive home. BHY_Style::empty_state_html()
    // stays as a thin delegating wrapper so its ~12 existing call sites
    // across the ecosystem don't all need updating at once.
    const EMPTY_STATE_ICONS = [
        'search' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="10" cy="10" r="7"></circle><line x1="21" y1="21" x2="15.5" y2="15.5"></line></svg>',
        'info-outline' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"></circle><line x1="12" y1="11" x2="12" y2="16"></line><line x1="12" y1="8" x2="12" y2="8"></line></svg>',
    ];

    /**
     * The one shared "nothing to show" component for front-end list/
     * catalog surfaces — UX-AUDIT-2026-07.md's own top recommendation:
     * the exact same bare "No courses found yet." / "No tracks match."
     * pattern showed up independently in bh-courses' catalog and
     * bh-streaming's library, with no explanation and no next step,
     * while WooCommerce's own default empty state (one plugin away,
     * "try clearing any filters or head to our store's home") already
     * solves this correctly. One reusable piece here, retrofit onto
     * both existing call sites, rather than fixing the same gap twice
     * (and however many more times it would otherwise recur later).
     *
     * $args:
     *   'reason'      — 'zero' (nothing exists yet at all) or
     *                    'filtered' (a search/filter matched nothing) —
     *                    changes both the default message and whether a
     *                    "clear filters" affordance makes sense at all.
     *   'title'       — required, short. Own the specific cause instead
     *                    of a generic "No items found" ("No courses
     *                    published yet" beats "No results").
     *   'description' — optional, one more sentence of context.
     *   'cta_label'/'cta_url' — optional single next-step link/button.
     *   'clear_url'   — optional; only rendered when reason='filtered',
     *                    a plain "Clear filters" link.
     *   'icon'        — optional dashicon name (no 'dashicons-' prefix),
     *                    defaults to 'search' for filtered, 'info' for zero.
     *
     * Self-contained: ships its own <style> block, deliberately embedded
     * on EVERY call rather than guarded to print once per request —
     * confirmed live (bh-streaming's player.js) that a "print once" guard
     * breaks the moment a consumer swaps `element.innerHTML` between two
     * different rendered variants (e.g. the zero-data fragment, then the
     * filtered fragment on the same view): the second swap destroys the
     * first fragment's <style> tag along with everything else that was
     * inside that container, silently losing all the CSS with no error.
     */
    /** @param array<string, mixed> $args */
    public static function empty_state_html(array $args): string {
        $reason = $args['reason'] ?? 'zero';
        $title = (string) ($args['title'] ?? ($reason === 'filtered' ? 'Nothing matches your filters' : 'Nothing here yet'));
        $description = (string) ($args['description'] ?? '');
        $icon_key = (string) ($args['icon'] ?? ($reason === 'filtered' ? 'search' : 'info-outline'));
        $icon_svg = self::EMPTY_STATE_ICONS[$icon_key] ?? self::EMPTY_STATE_ICONS['info-outline'];

        // WHY --bhy-* not --bh-*: this is an ADMIN component (BHY_UI, 19 uses
        // across admin screens) but was reading the FRONT-END brand token
        // family instead of the admin design-system one -- --bhy-* is what
        // shsas_bridge_bhy_tokens() actually bridges to --shsas-* for this
        // skin's dark theme. Found live in Storybook's own a11y-audited
        // "Empty state" story: the title rendered at 1.16:1 (needs 4.5),
        // essentially invisible, because --bh-text was undefined in that
        // context and fell straight to its light-mode fallback #222 against
        // a dark background.
        $out = '<style>
            .bhy-empty-state { text-align: center; padding: 48px 20px; color: var(--bhy-ink-dim, #6b6b6b); }
            .bhy-empty-state .bhy-empty-icon { display: inline-block; width: 40px; height: 40px; margin: 0 auto; color: var(--bhy-border, #ccc); }
            .bhy-empty-state .bhy-empty-icon svg { width: 100%; height: 100%; }
            .bhy-empty-state h3 { margin: 12px 0 4px; font-size: 18px; color: var(--bhy-ink, #222); }
            .bhy-empty-state p { margin: 0 0 16px; font-size: 14px; }
            .bhy-empty-state .bhy-empty-actions { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }
            .bhy-empty-state .bhy-empty-cta { display: inline-block; padding: 8px 18px; border-radius: 6px; background: var(--bhy-accent, #2271b1); color: #fff; text-decoration: none; font-size: 14px; }
            .bhy-empty-state .bhy-empty-clear { display: inline-block; padding: 8px 18px; font-size: 14px; color: var(--bhy-ink-dim, #6b6b6b); text-decoration: underline; }
            @media (max-width: 480px) {
                .bhy-empty-state { padding: 32px 16px; }
                .bhy-empty-state .bhy-empty-icon { width: 28px; height: 28px; }
                .bhy-empty-state h3 { font-size: 16px; }
                .bhy-empty-state .bhy-empty-actions { flex-direction: column; align-items: center; }
            }
        </style>';

        $out .= '<div class="bhy-empty-state">';
        $out .= '<span class="bhy-empty-icon" aria-hidden="true">' . $icon_svg . '</span>';
        $out .= '<h3>' . esc_html($title) . '</h3>';
        if ($description !== '') $out .= '<p>' . esc_html($description) . '</p>';

        $has_cta = !empty($args['cta_label']) && !empty($args['cta_url']);
        $has_clear = $reason === 'filtered' && !empty($args['clear_url']);
        if ($has_cta || $has_clear) {
            $out .= '<div class="bhy-empty-actions">';
            if ($has_cta) $out .= '<a class="bhy-empty-cta" href="' . esc_url($args['cta_url']) . '">' . esc_html($args['cta_label']) . '</a>';
            if ($has_clear) $out .= '<a class="bhy-empty-clear" href="' . esc_url($args['clear_url']) . '">Clear filters</a>';
            $out .= '</div>';
        }
        $out .= '</div>';
        return $out;
    }

    public static function swatch_css(): string {
        return '
            .bhy-swatch-card {
                border: 1px solid var(--bhy-border, #dcdcde); border-radius: var(--bhy-radius-sm, 6px);
                padding: 8px; display: flex; gap: 10px; align-items: center;
                transition: border-color var(--bhy-transition, 150ms ease);
            }
            .bhy-swatch-card:hover { border-color: var(--bhy-accent, #2271b1); }
            .bhy-swatch {
                width: 32px; height: 32px; border-radius: 6px; flex: 0 0 auto; border: 1px solid #dcdcde;
                background-image: linear-gradient(45deg, #ccc 25%, transparent 25%), linear-gradient(-45deg, #ccc 25%, transparent 25%), linear-gradient(45deg, transparent 75%, #ccc 75%), linear-gradient(-45deg, transparent 75%, #ccc 75%);
                background-size: 10px 10px; background-position: 0 0, 0 5px, 5px -5px, -5px 0;
            }
            .bhy-swatch-body { flex: 1; min-width: 0; }
            .bhy-swatch-body label { display: block; font-weight: 600; font-size: 11px; margin-bottom: 3px; }
            .bhy-swatch-controls { display: flex; gap: 5px; align-items: center; }
            .bhy-swatch-controls input[type=text] { width: 100%; font-size: 12px; padding: 3px 6px; }
            .bhy-swatch-controls input[type=color] { width: 24px; height: 24px; padding: 0; border: 1px solid #dcdcde; cursor: pointer; }
            .bhy-hsl-toggle {
                background: none; border: none; padding: 0 0 0 6px; font-size: 10px; font-weight: 600; text-transform: uppercase;
                letter-spacing: .03em; color: var(--bhy-ink-dim, #787c82); cursor: pointer;
            }
            .bhy-hsl-toggle:hover, .bhy-hsl-toggle[aria-expanded="true"] { color: var(--bhy-accent, #2271b1); }
            /* :not([hidden]) rather than a bare .bhy-hsl-controls rule —
               a plain class selector ties in specificity with the
               browsers own [hidden]{display:none} UA rule and wins
               (author stylesheet beats UA default at equal specificity),
               which defeated the toggle button entirely: every panel
               rendered permanently expanded regardless of the hidden
               attribute JS was correctly setting. */
            .bhy-hsl-controls:not([hidden]) { display: flex; flex-direction: column; gap: 2px; margin-top: 6px; }
            .bhy-hsl-controls label { display: flex; align-items: center; gap: 6px; font-size: 10px; font-weight: 600; color: var(--bhy-ink-dim, #646970); }
            .bhy-hsl-controls input[type=range] { flex: 1; }
            .bhy-hsl-val { font-variant-numeric: tabular-nums; width: 32px; text-align: right; color: var(--bhy-ink, #1d2327); }
        ';
    }

    public static function swatch_field(string $id, string $name, string $label, string $value, string $placeholder = ''): void {
        $display = $value !== '' ? $value : $placeholder;
        ?>
        <div class="bhy-swatch-card">
            <div class="bhy-swatch" id="bhy-swatch-<?php echo esc_attr($id); ?>" style="background:<?php echo esc_attr($display ?: '#f6f7f7'); ?>"></div>
            <div class="bhy-swatch-body">
                <label for="<?php echo esc_attr($id); ?>"><?php echo esc_html($label); ?>
                    <button type="button" class="bhy-hsl-toggle" data-key="<?php echo esc_attr($id); ?>" aria-expanded="false" aria-controls="bhy-hsl-<?php echo esc_attr($id); ?>">HSL</button>
                </label>
                <div class="bhy-swatch-controls">
                    <input type="text" id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>"
                           value="<?php echo esc_attr($value); ?>" placeholder="<?php echo esc_attr($placeholder); ?>" data-key="<?php echo esc_attr($id); ?>">
                    <input type="color" id="bhy-picker-<?php echo esc_attr($id); ?>"
                           value="<?php echo esc_attr(strlen($display) === 7 && $display[0] === '#' ? $display : '#000000'); ?>" tabindex="-1">
                </div>
                <!-- Collapsed by default — 17 swatches (6 core + 3
                     advanced + 8 category) all showing three sliders at
                     once would be an overwhelming wall of controls; this
                     keeps the hex+eyedropper as the fast default path and
                     HSL as an opt-in for whoever actually wants to nudge
                     hue/saturation/lightness directly instead of hunting
                     for a hex value. -->
                <div class="bhy-hsl-controls" id="bhy-hsl-<?php echo esc_attr($id); ?>" hidden>
                    <label>H <input type="range" min="0" max="360" step="1" class="bhy-hsl-h" data-key="<?php echo esc_attr($id); ?>"> <span class="bhy-hsl-val bhy-hsl-h-val"></span></label>
                    <label>S <input type="range" min="0" max="100" step="1" class="bhy-hsl-s" data-key="<?php echo esc_attr($id); ?>"> <span class="bhy-hsl-val bhy-hsl-s-val"></span></label>
                    <label>L <input type="range" min="0" max="100" step="1" class="bhy-hsl-l" data-key="<?php echo esc_attr($id); ?>"> <span class="bhy-hsl-val bhy-hsl-l-val"></span></label>
                </div>
            </div>
        </div>
        <?php
    }

    // Wires up any .bhy-swatch-controls text input to its paired swatch
    // preview + color-picker dropper. $on_sync_js runs after every sync
    // (e.g. bh-style's gallery uses it to push the new value into every
    // registered surface's live preview, not just repaint the swatch).
    public static function swatch_js(string $on_sync_js = ''): string {
        return "
        (function () {
            function isValidCssColor(v) {
                var s = new Option().style;
                s.color = '';
                s.color = v;
                return s.color !== '';
            }
            document.querySelectorAll('.bhy-swatch-controls input[type=text]').forEach(function (input) {
                var key = input.dataset.key;
                var swatch = document.getElementById('bhy-swatch-' + key);
                var picker = document.getElementById('bhy-picker-' + key);
                function sync() {
                    var v = input.value.trim() || input.placeholder;
                    if (v && isValidCssColor(v)) swatch.style.background = v;
                    $on_sync_js
                }
                input.addEventListener('input', sync);
                if (picker) picker.addEventListener('input', function () { input.value = picker.value; sync(); });
            });

            // HSL sliders — an opt-in per swatch (behind the 'HSL' toggle
            // button, see swatch_field()) rather than always-visible,
            // since 17 swatches on this page all showing 3 sliders at
            // once would swamp the panel. The hex text field stays the
            // single source of truth: opening the panel reads FROM hex,
            // dragging a slider writes TO hex (dispatching a real 'input'
            // event so the existing swatch-preview/live-canvas sync above
            // fires exactly like a manual hex edit would).
            function hexToHsl(hex) {
                var m = /^#?([a-f\\d]{2})([a-f\\d]{2})([a-f\\d]{2})\$/i.exec(hex);
                if (!m) return null;
                var r = parseInt(m[1], 16) / 255, g = parseInt(m[2], 16) / 255, b = parseInt(m[3], 16) / 255;
                var max = Math.max(r, g, b), min = Math.min(r, g, b);
                var h, s, l = (max + min) / 2;
                if (max === min) { h = s = 0; }
                else {
                    var d = max - min;
                    s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
                    if (max === r) h = (g - b) / d + (g < b ? 6 : 0);
                    else if (max === g) h = (b - r) / d + 2;
                    else h = (r - g) / d + 4;
                    h *= 60;
                }
                return { h: Math.round(h), s: Math.round(s * 100), l: Math.round(l * 100) };
            }
            function hslToHex(h, s, l) {
                s /= 100; l /= 100;
                var c = (1 - Math.abs(2 * l - 1)) * s;
                var x = c * (1 - Math.abs((h / 60) % 2 - 1));
                var m = l - c / 2;
                var r = 0, g = 0, b = 0;
                if (h < 60) { r = c; g = x; b = 0; }
                else if (h < 120) { r = x; g = c; b = 0; }
                else if (h < 180) { r = 0; g = c; b = x; }
                else if (h < 240) { r = 0; g = x; b = c; }
                else if (h < 300) { r = x; g = 0; b = c; }
                else { r = c; g = 0; b = x; }
                function toHex(v) { var h2 = Math.round((v + m) * 255).toString(16); return h2.length === 1 ? '0' + h2 : h2; }
                return '#' + toHex(r) + toHex(g) + toHex(b);
            }

            document.querySelectorAll('.bhy-hsl-toggle').forEach(function (btn) {
                var key = btn.dataset.key;
                var panel = document.getElementById('bhy-hsl-' + key);
                var input = document.getElementById(key);
                if (!panel || !input) return;
                var hSlider = panel.querySelector('.bhy-hsl-h'), sSlider = panel.querySelector('.bhy-hsl-s'), lSlider = panel.querySelector('.bhy-hsl-l');
                var hVal = panel.querySelector('.bhy-hsl-h-val'), sVal = panel.querySelector('.bhy-hsl-s-val'), lVal = panel.querySelector('.bhy-hsl-l-val');

                function renderLabels() {
                    hVal.textContent = hSlider.value + '\\u00b0'; sVal.textContent = sSlider.value + '%'; lVal.textContent = lSlider.value + '%';
                }
                function initFromHex() {
                    var hsl = hexToHsl(input.value.trim() || input.placeholder);
                    if (!hsl) return;
                    hSlider.value = hsl.h; sSlider.value = hsl.s; lSlider.value = hsl.l;
                    renderLabels();
                }

                btn.addEventListener('click', function () {
                    var expanded = btn.getAttribute('aria-expanded') === 'true';
                    btn.setAttribute('aria-expanded', expanded ? 'false' : 'true');
                    panel.hidden = expanded;
                    if (!expanded) initFromHex();
                });

                [hSlider, sSlider, lSlider].forEach(function (slider) {
                    slider.addEventListener('input', function () {
                        renderLabels();
                        input.value = hslToHex(parseFloat(hSlider.value), parseFloat(sSlider.value), parseFloat(lSlider.value));
                        input.dispatchEvent(new Event('input', { bubbles: true }));
                    });
                });
            });
        })();
        ";
    }

    /** @param array<string, mixed> $s */
    public static function font_field(string $key, string $label, $s): void {
        $picked = $s[$key];
        $is_custom = !array_key_exists($picked, BHY_Style::FONT_OPTIONS);
        ?>
        <div class="bhy-font-field">
            <label for="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label>
            <select id="<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($key); ?>" data-custom-target="<?php echo esc_attr($key); ?>_custom">
                <?php foreach (BHY_Style::FONT_OPTIONS as $name => $param): ?>
                    <?php
                    // Font selectors preview their real typeface: an
                    // <option>'s font-family CAN be
                    // styled inline (unlike a color swatch, which most
                    // browsers ignore inside <option> — colors get a real
                    // custom dropdown in the ELEMENT inspector instead,
                    // see element-builder.js's renderStylePropertyField()
                    // — this select is the separate, site-level Global
                    // Styles font picker, a different control entirely).
                    // Only cosmetically useful because enqueue_media()
                    // (this file's own updated docblock) now also loads
                    // the real webfont stylesheet on this admin page, not
                    // just inside the canvas iframes as before.
                    ?>
                    <option value="<?php echo esc_attr($name); ?>" style="font-family:'<?php echo esc_attr($name); ?>', sans-serif;" <?php selected($picked, $name); ?>><?php echo esc_html($name); ?></option>
                <?php endforeach; ?>
                <option value="Custom" <?php selected($is_custom, true); ?>>Custom…</option>
            </select>
            <input type="text" id="<?php echo esc_attr($key); ?>_custom" name="<?php echo esc_attr($key); ?>_custom"
                   placeholder="e.g. Georgia, serif" value="<?php echo esc_attr($s[$key . '_custom']); ?>"
                   style="<?php echo $is_custom ? '' : 'display:none;'; ?>">
        </div>
        <?php
    }

    /**
     * @param array<string, mixed> $s
     * @param mixed $min
     * @param mixed $max
     * @param mixed $step
     */
    public static function slider_row(string $key, string $label, $s, $min, $max, $step, string $unit): void {
        ?>
        <div class="bhy-slider-row">
            <label for="<?php echo esc_attr($key); ?>">
                <span><?php echo esc_html($label); ?></span>
                <span class="bhy-slider-val" id="<?php echo esc_attr($key); ?>_val"><?php echo esc_html($s[$key] . $unit); ?></span>
            </label>
            <input type="range" id="<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($key); ?>"
                   min="<?php echo esc_attr($min); ?>" max="<?php echo esc_attr($max); ?>" step="<?php echo esc_attr($step); ?>"
                   value="<?php echo esc_attr($s[$key]); ?>" data-unit="<?php echo esc_attr($unit); ?>">
        </div>
        <?php
    }

    public static function admin_page_css(): string {
        return '
            * { box-sizing: border-box; }
            /* Controls column widened from 320px to 380px, and the swatch
               grid below now sizes itself off its OWN available width
               (auto-fit/minmax) instead of a hardcoded 2-column split —
               together these give a 32px swatch + hex text input + color
               picker enough room to not clip a 7-character hex value. */
            .bhy-layout { display: grid; grid-template-columns: 200px 1fr 380px; gap: 20px; margin-top: 16px; align-items: start; }
            .bhy-sidebar { background: var(--bhy-surface, #fff); border: 1px solid var(--bhy-border, #dcdcde); border-radius: var(--bhy-radius, 8px); padding: var(--bhy-space-3, 12px); animation: bhy-fade-in 300ms ease; }
            @keyframes bhy-fade-in { from { opacity: 0; } to { opacity: 1; } }
            @media (prefers-reduced-motion: reduce) { .bhy-sidebar { animation: none; } }
            .bhy-sidebar-group { font-size: var(--bhy-text-xs, 11px); text-transform: uppercase; letter-spacing: .04em; color: var(--bhy-ink-dim, #787c82); margin: var(--bhy-space-3, 12px) 0 4px; }
            .bhy-sidebar-group:first-child { margin-top: 0; }
            .bhy-story-btn {
                display: block; width: 100%; text-align: left; background: none;
                border: none; border-left: 3px solid transparent; padding: 7px 10px; border-radius: var(--bhy-radius-sm, 6px);
                cursor: pointer; font-size: 13px; color: var(--bhy-ink, #1d2327);
                transition: background var(--bhy-transition, 150ms ease), border-color var(--bhy-transition, 150ms ease);
            }
            .bhy-story-btn:hover { background: var(--bhy-hover-tint, #f0f0f1); }
            .bhy-story-btn:focus-visible { outline: none; box-shadow: var(--bhy-focus-ring, 0 0 0 2px rgba(34,113,177,.25)); }
            .bhy-story-btn.active { background: var(--bhy-selected-tint, #f0f6fc); border-left-color: var(--bhy-accent, #2271b1); color: var(--bhy-ink, #1d2327); font-weight: 600; }
            /* Canvas reads as a "stage" the placed content pops off of —
               kept deliberately dark/neutral (not white) so any surface
               being previewed has clear visual separation from the rail/
               inspector chrome around it. */
            .bhy-canvas {
                background: #1a1a1a; border: 1px solid var(--bhy-border, #dcdcde); border-radius: var(--bhy-radius, 8px);
                overflow: hidden; min-height: 320px; position: relative;
                box-shadow: inset 0 0 0 1px rgba(255,255,255,.03);
            }
            .bhy-story-frame {
                width: 100%; height: 600px; max-height: 75vh; border: 0; display: none;
                /* 3.4.61 — real, live-confirmed regression from the no-
                   iframes swap: "the Now Playing Bar is escaping the
                   styles of its container and displaying on the full
                   page." An iframe naturally contained position:fixed
                   descendants to ITS OWN viewport — a shadow root inside
                   a same-document div does NOT do this by default;
                   position:fixed still resolves against the real page
                   viewport unless some ancestor establishes a CSS
                   "containing block" for fixed-position descendants
                   (per spec: a transform, perspective, filter, or
                   contain:layout/paint/strict/content). overflow:hidden
                   ALONE (already set on .bhy-canvas above) does NOT do
                   this — that was the gap. contain:layout here gives
                   every shadow-hosted story\'s own position:fixed
                   elements (bh-contest\'s now-playing bar being the first
                   one that actually surfaced it) a real containing block
                   again, restoring the exact visual containment an
                   iframe used to give for free. */
                contain: layout;
            }
            /* Surface switches used to be an instant, unannounced snap
               (display:none -> block with zero transition) — everything
               else on this page now has some motion, this was the one
               remaining silent state change. animation (not transition)
               so it plays every time the class is freshly added, not
               just on a property change. */
            .bhy-story-frame.active { display: block; animation: bhy-frame-in 250ms ease; }
            @keyframes bhy-frame-in { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }
            /* A whole theme preset just repainted every color/font/scale
               token at once — this gives that "instant delight" moment a
               real, brief acknowledgment instead of everything just
               silently repainting with no event marking it happened. */
            .bhy-canvas.bhy-canvas-flash { animation: bhy-canvas-flash 600ms ease; }
            @keyframes bhy-canvas-flash {
                0% { box-shadow: inset 0 0 0 1px rgba(255,255,255,.03), 0 0 0 3px var(--bhy-accent, #2271b1); }
                100% { box-shadow: inset 0 0 0 1px rgba(255,255,255,.03), 0 0 0 0 transparent; }
            }
            @media (prefers-reduced-motion: reduce) {
                .bhy-story-frame.active, .bhy-canvas.bhy-canvas-flash { animation: none; }
            }
            .bhy-empty { color: #888; padding: 40px; text-align: center; font-size: var(--bhy-text-base, 13px); }
            .bhy-controls {
                background: var(--bhy-surface, #fff); border: 1px solid var(--bhy-border, #dcdcde); border-radius: var(--bhy-radius, 8px);
                padding: var(--bhy-space-4, 16px) var(--bhy-space-5, 20px);
                max-height: 80vh; overflow-y: auto; display: flex; flex-direction: column;
            }
            .bhy-controls h2 {
                font-size: var(--bhy-text-xs, 11px); font-weight: 700; text-transform: uppercase; letter-spacing: .06em;
                color: var(--bhy-ink-dim, #787c82); margin: var(--bhy-space-5, 20px) 0 var(--bhy-space-2, 8px);
                padding-bottom: var(--bhy-space-1, 4px); border-bottom: 1px solid var(--bhy-border, #dcdcde);
            }
            .bhy-controls h2:first-child { margin-top: 0; }
            /* Consistent control height across every text/select/color
               input in the inspector, so the many property rows line up
               instead of each control type sizing itself independently. */
            .bhy-controls input[type=text], .bhy-controls input[type=number],
            .bhy-controls select, .bhy-controls button.button {
                min-height: 30px; transition: border-color var(--bhy-transition, 150ms ease), box-shadow var(--bhy-transition, 150ms ease);
            }
            .bhy-controls input[type=text]:focus, .bhy-controls select:focus {
                border-color: var(--bhy-accent, #2271b1); box-shadow: var(--bhy-focus-ring, 0 0 0 2px rgba(34,113,177,.25)); outline: none;
            }

            /* Always-visible sample chips proving every scale/shape token
               (radius, radius_sm, bar_height, font_scale, space_scale) is
               actually applying — independent of which registered surface
               is currently selected in the canvas, since no single surface
               is guaranteed to visibly use every token. */
            .bhy-token-preview {
                display: flex; flex-wrap: wrap; align-items: center; gap: var(--bhy-space-2, 8px);
                background: var(--bhy-subtle, #f6f7f7); border: 1px solid var(--bhy-border, #dcdcde); border-radius: var(--bhy-radius, 8px);
                padding: var(--bhy-space-3, 12px); margin-bottom: var(--bhy-space-1, 4px);
            }
            .bhy-token-chip {
                background: var(--bh-surface, #2C120E); color: var(--bh-text, #EDDFCB);
                border: 1px solid var(--bh-border, #3D1B14); font-size: 11px; padding: 8px 12px;
                line-height: 1.3;
            }
            .bhy-token-chip span { display: block; opacity: .7; font-size: 10px; }
            .bhy-token-chip-radius { border-radius: var(--bh-radius, 12px); }
            .bhy-token-chip-radius-sm { border-radius: var(--bh-radius-sm, 8px); }
            .bhy-token-pill {
                border-radius: 999px; border: none; cursor: default;
                background: var(--bh-accent, #C1503A); color: #150705; font-size: 12px;
                font-weight: 600; padding: 8px 16px;
            }
            .bhy-token-bar {
                height: var(--bh-bar-height, 84px); width: 100%; flex-basis: 100%;
                background: var(--bh-surface-2, #220C0A); border: 1px solid var(--bh-border, #3D1B14);
                border-radius: var(--bh-radius-sm, 8px); display: flex; align-items: center; justify-content: center;
                color: var(--bh-text-dim, #B99584); font-size: 11px; transition: height .1s ease;
            }
            .bhy-token-text {
                background: var(--bh-surface, #2C120E); color: var(--bh-text, #EDDFCB);
                border: 1px solid var(--bh-border, #3D1B14); border-radius: var(--bh-radius-sm, 8px);
                font-size: calc(12px * var(--bh-font-scale, 1)); padding: calc(6px * var(--bh-space-scale, 1)) calc(10px * var(--bh-space-scale, 1));
            }
            .bhy-token-text strong { font-family: var(--bh-font-display, inherit); margin-right: 4px; }

            /* Theme preset picker — real color swatches instead of a
               plain <select><option> list, so a presets whole selling
               point ("apply this instantly") is visible before picking
               one, not hidden behind the most boring possible control. */
            .bhy-theme-swatch-groups { margin-top: 6px; }
            .bhy-theme-swatch-group-label { font-size: var(--bhy-text-xs, 11px); text-transform: uppercase; letter-spacing: .04em; color: var(--bhy-ink-dim, #787c82); margin: 10px 0 4px; }
            .bhy-theme-swatch-group-label:first-child { margin-top: 0; }
            .bhy-theme-swatch-row { display: flex; flex-wrap: wrap; gap: 8px; }
            .bhy-theme-swatch {
                display: flex; flex-direction: column; align-items: center; gap: 4px; width: 64px; padding: 6px;
                border: 1px solid var(--bhy-border, #dcdcde); border-radius: var(--bhy-radius-sm, 6px); background: none; cursor: pointer;
                transition: transform var(--bhy-transition, 150ms ease), border-color var(--bhy-transition, 150ms ease), box-shadow var(--bhy-transition, 150ms ease);
            }
            .bhy-theme-swatch:hover { transform: translateY(-2px); border-color: var(--bhy-accent, #2271b1); box-shadow: 0 2px 6px rgba(0,0,0,.08); }
            .bhy-theme-swatch:focus-visible { outline: none; box-shadow: var(--bhy-focus-ring, 0 0 0 2px rgba(34,113,177,.25)); }
            .bhy-theme-swatch.active { border-color: var(--bhy-accent, #2271b1); box-shadow: 0 0 0 2px var(--bhy-focus-ring, rgba(34,113,177,.25)); }
            .bhy-theme-swatch-preview { display: flex; align-items: center; justify-content: center; gap: 2px; width: 48px; height: 32px; border-radius: 4px; overflow: hidden; padding: 4px; box-sizing: border-box; }
            .bhy-theme-swatch-preview span { width: 8px; height: 8px; border-radius: 50%; flex: 0 0 auto; box-shadow: 0 0 0 1px rgba(0,0,0,.15); }
            .bhy-theme-swatch-name { font-size: 9px; text-align: center; line-height: 1.2; color: var(--bhy-ink-dim, #646970); max-width: 60px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

            .bhy-swatch-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: var(--bhy-space-2, 8px); }
            .bhy-font-field { margin-bottom: var(--bhy-space-3, 10px); }
            .bhy-font-field select, .bhy-font-field input { width: 100%; margin-top: 4px; }
            .bhy-slider-row { margin-bottom: var(--bhy-space-3, 10px); }
            .bhy-slider-row label { display: flex; justify-content: space-between; font-size: var(--bhy-text-sm, 12px); gap: var(--bhy-space-2, 8px); color: var(--bhy-ink, #1d2327); }
            .bhy-slider-row .bhy-slider-val { color: var(--bhy-ink-dim, #646970); font-variant-numeric: tabular-nums; }
            .bhy-slider-row input { width: 100%; }

            /* PAGE-BUILDER-DELETE-KEEP-AUDIT.md cleanup (2026-07-13) --
               ported from the now-deleted assets/css/element-builder.css,
               where these two rule sets used to live. Genuinely needed
               here: BHY_Gallery::render_controls() (the real Styles
               settings form, kept as-is through this cleanup) uses
               .bhel-field-row for its brand-wordmark row and
               .bhel-style-group for its Advanced-colors/Category-colors
               details disclosures -- moved into this shared file rather
               than re-duplicated inside class-style-gallery.php own
               render_script(), since this is now a core piece of the
               design system, not something specific to the deleted
               builder UI. */
            .bhel-field-row { margin-bottom: var(--bhy-space-3, 10px); }
            .bhel-field-row label { display: block; font-size: var(--bhy-text-sm, 12px); font-weight: 600; margin-bottom: 3px; color: var(--bhy-ink, #1d2327); }
            .bhel-field-row input[type=text], .bhel-field-row input[type=number], .bhel-field-row select, .bhel-field-row textarea {
                width: 100%; padding: 6px 8px; border: 1px solid var(--bhy-border, #dcdcde); border-radius: var(--bhy-radius-sm, 6px); font-size: var(--bhy-text-sm, 12px);
            }
            .bhel-field-row input[type=text]:focus, .bhel-field-row input[type=number]:focus,
            .bhel-field-row select:focus, .bhel-field-row textarea:focus { outline: none; box-shadow: var(--bhy-focus-ring, 0 0 0 2px rgba(34,113,177,.25)); border-color: var(--bhy-accent, #2271b1); }

            .bhel-style-group { border: 1px solid var(--bhy-border, #dcdcde); border-radius: var(--bhy-radius-sm, 6px); margin-bottom: var(--bhy-space-3, 10px); padding: 0 var(--bhy-space-3, 10px); }
            .bhel-style-group:hover { border-color: #c9ccd1; }
            .bhel-style-group-title {
                cursor: pointer; padding: var(--bhy-space-2, 8px) 0; font-size: var(--bhy-text-sm, 12px); font-weight: 600; color: var(--bhy-ink-dim, #646970);
                display: flex; align-items: center; gap: 6px; list-style: none;
            }
            .bhel-style-group-title:hover { color: var(--bhy-ink, #1d2327); }
            .bhel-style-group-title::-webkit-details-marker { display: none; }
            .bhel-style-group-title::before { content: \'▸\'; display: inline-block; transition: transform var(--bhy-transition, 150ms ease); }
            .bhel-style-group[open] > .bhel-style-group-title::before { transform: rotate(90deg); }
            .bhel-style-group-body { padding-top: var(--bhy-space-1, 4px); padding-bottom: var(--bhy-space-2, 6px); }

            /* Save was previously the last thing in a tall, internally
               scrolling column — easy to lose track of after adjusting a
               dozen controls. Sticking it to the bottom of the panel
               keeps it in view without needing its own scroll container. */
            .bhy-controls p.submit {
                position: sticky; bottom: -16px; margin: var(--bhy-space-5, 18px) -20px -16px; padding: var(--bhy-space-3, 12px) var(--bhy-space-5, 20px);
                background: var(--bhy-surface, #fff); border-top: 1px solid var(--bhy-border, #dcdcde); box-shadow: 0 -4px 8px rgba(0,0,0,.04);
            }
            .bhy-controls p.submit .button { width: 100%; text-align: center; }

            /* Below this, three fixed-width columns stop fitting a phone
               or a narrow window — stack sidebar, preview, and controls
               instead, and let the preview canvas set its own height
               rather than force a 600px iframe onto a small screen. */
            @media (max-width: 960px) {
                .bhy-layout { display: block; }
                .bhy-sidebar, .bhy-canvas, .bhy-controls { margin-bottom: 16px; }
                .bhy-sidebar { display: flex; flex-wrap: nowrap; overflow-x: auto; gap: 4px; padding: 8px; -webkit-overflow-scrolling: touch; }
                .bhy-sidebar-group { display: none; }
                .bhy-story-btn { width: auto; white-space: nowrap; flex: 0 0 auto; }
                .bhy-story-frame { height: 60vh; max-height: 480px; }
                .bhy-controls { max-height: none; }
                .bhy-controls p.submit { position: static; box-shadow: none; margin: 18px 0 0; }
            }
            @media (max-width: 480px) {
                .bhy-swatch-grid { grid-template-columns: 1fr; }
                .bhy-story-frame { height: 50vh; }
            }
        ' . self::swatch_css();
    }

    /* ---------------------------------------------------------------
     * Shared ecosystem admin design system.
     *
     * A real spacing scale (4px base unit), a real type scale, and a
     * small set of reusable component classes (card, alert, badge,
     * table wrapper, "detented" range slider) — drawing on Primer's
     * card/alert conventions, Material's spacing-scale discipline, and
     * HIG's preference for a single clear affordance per control.
     *
     * This is printed once, globally, on every ecosystem admin screen
     * (see BHY_UI::init_shared_admin_assets()) so Live Console, Results,
     * Debug Tools, and People/CRM can all opt in just by using these
     * class names — no per-plugin stylesheet to keep in sync.
     * --------------------------------------------------------------- */
    public static function init_shared_admin_assets(): void {
        add_action('admin_head', [self::class, 'print_design_system_css']);
        add_action('admin_footer', [self::class, 'print_design_system_js']);
        add_action('admin_head', [self::class, 'print_block_editor_metabox_fix']);
    }

    /**
     * Real bug, found live: the block editor's resizable Meta Boxes
     * panel (core, not this ecosystem's own code) has no minimum height
     * reserved for the actual canvas above it, only a min-width on the
     * same inline style. On ANY screen with metaboxes whose natural
     * stacked height reaches the available space (which is nearly every
     * real post-edit screen with more than one or two boxes — confirmed
     * on bh_course, bh_lesson, AND a stock WooCommerce Product with zero
     * of this ecosystem's own JS involved), the canvas gets squeezed to
     * a real, measured 0px height — not scrolled off-screen, not
     * covered by another panel, an actual 0-height iframe with fully
     * rendered content trapped inside it. The one-click workaround
     * (collapsing the "Meta Boxes" toggle at the top of the screen)
     * proves the canvas renders fine the instant it's given ANY room;
     * this just gives it a floor so a fresh page load never starts at
     * zero. Unscoped (every admin screen, not just this ecosystem's own
     * — the bug reproduces identically on core/third-party screens) via
     * the same "load everywhere, cheap, no gating" posture OUS_Toast's
     * assets already use.
     */
    public static function print_block_editor_metabox_fix(): void {
        echo '<style>.editor-resizable-editor{min-height:300px !important;}</style>';
    }

    /**
     * Three small, dependency-free behaviors any ecosystem admin screen
     * opts into just by using the right class/data-attribute — no
     * per-plugin JS file to write or enqueue:
     *
     *   - `input.bhy-table-search[data-target="#some-table-id"]` — typing
     *     filters that table's tbody rows by plain substring match
     *     against the row's own text.
     *   - `table.bhy-sortable` with `<th data-sort>` column headers —
     *     clicking a header sorts by that column (numeric-aware, toggles
     *     asc/desc on repeat clicks).
     *   - `button.bhy-copy-btn[data-copy-target="#some-id"]` — copies
     *     that element's value (inputs) or text content (everything
     *     else) to the clipboard, with brief visual confirmation.
     *
     * Plain vanilla JS, no jQuery/build step, matching this ecosystem's
     * existing convention (see OUS_Notifications' admin-bar bell for the
     * same "own script handle, no assumed dependency" shape).
     */
    public static function print_design_system_js(): void {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $id = $screen ? $screen->id : '';
        // Broadened from a literal 'bh-' substring match: real screen ids
        // in this ecosystem take several shapes WordPress itself derives
        // (edit-bh_course, edit-bhs_feed_source, bhs_track_page_bhm-
        // settings, the-self-hosted-self_page_ous-debug, etc.) — a strict 'bh-'
        // check missed most of them, silently never printing this CSS/JS
        // on exactly the screens that use it. Matching the bare 'bh'
        // prefix (every post type/slug in this ecosystem starts with it)
        // plus 'ous' (the-self-hosted-self's own non-'bh' pages) is safe: no core
        // WordPress screen id contains either as a substring.
        if ($id !== '' && strpos($id, 'bh') === false && strpos($id, 'ous') === false && strpos($id, 'the-self-hosted-self') === false) return;
        ?>
        <script>
        (function () {
            document.addEventListener('input', function (e) {
                if (!e.target.matches('input.bhy-table-search')) return;
                var target = document.querySelector(e.target.getAttribute('data-target'));
                if (!target) return;
                var q = e.target.value.trim().toLowerCase();
                target.querySelectorAll('tbody tr').forEach(function (row) {
                    row.style.display = (!q || row.textContent.toLowerCase().indexOf(q) !== -1) ? '' : 'none';
                });
            });

            document.addEventListener('click', function (e) {
                var th = e.target.closest('table.bhy-sortable thead th[data-sort]');
                if (th) {
                    var table = th.closest('table');
                    var tbody = table.querySelector('tbody');
                    var idx = Array.prototype.indexOf.call(th.parentNode.children, th);
                    var asc = !th.classList.contains('bhy-sort-asc');
                    th.parentNode.querySelectorAll('th').forEach(function (t) { t.classList.remove('bhy-sort-asc', 'bhy-sort-desc'); });
                    th.classList.add(asc ? 'bhy-sort-asc' : 'bhy-sort-desc');

                    var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
                    rows.sort(function (a, b) {
                        var av = (a.children[idx] ? a.children[idx].textContent : '').trim();
                        var bv = (b.children[idx] ? b.children[idx].textContent : '').trim();
                        var an = parseFloat(av.replace(/[^0-9.\-]/g, '')), bn = parseFloat(bv.replace(/[^0-9.\-]/g, ''));
                        var cmp = (!isNaN(an) && !isNaN(bn) && String(an) === av.replace(/[^0-9.\-]/g, ''))
                            ? (an - bn) : av.localeCompare(bv, undefined, {numeric: true, sensitivity: 'base'});
                        return asc ? cmp : -cmp;
                    });
                    rows.forEach(function (r) { tbody.appendChild(r); });
                    return;
                }

                var btn = e.target.closest('.bhy-copy-btn');
                if (btn) {
                    var target2 = document.querySelector(btn.getAttribute('data-copy-target'));
                    if (!target2) return;
                    var text = ('value' in target2) ? target2.value : target2.textContent;
                    var done = function () {
                        var original = btn.textContent;
                        btn.textContent = 'Copied!';
                        btn.classList.add('bhy-copied');
                        setTimeout(function () { btn.textContent = original; btn.classList.remove('bhy-copied'); }, 1500);
                    };
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(text).then(done);
                    } else {
                        // Fallback for non-HTTPS/older-browser contexts
                        // where the modern Clipboard API isn't available.
                        var tmp = document.createElement('textarea');
                        tmp.value = text; document.body.appendChild(tmp); tmp.select();
                        document.execCommand('copy'); document.body.removeChild(tmp);
                        done();
                    }
                }
            });

            // In-context help tooltips (.bhy-tip, see BHY_UI::tip()) — one
            // shared bubble element reused across every badge on the page
            // rather than one bubble per badge, positioned above the
            // badge (or below, if there is not enough room above the
            // viewport top) and re-clamped horizontally so it never runs
            // off either side of the screen.
            var bhyTipBubble = null;
            function bhyTipShow(el) {
                var text = el.getAttribute('data-tip');
                if (!text) return;
                if (!bhyTipBubble) {
                    bhyTipBubble = document.createElement('div');
                    bhyTipBubble.className = 'bhy-tip-bubble';
                    document.body.appendChild(bhyTipBubble);
                }
                bhyTipBubble.textContent = text;
                var r = el.getBoundingClientRect();
                bhyTipBubble.style.left = '0px';
                bhyTipBubble.style.top = '0px';
                bhyTipBubble.classList.add('is-visible');
                var bw = bhyTipBubble.offsetWidth, bh = bhyTipBubble.offsetHeight;
                var left = Math.min(Math.max(8, r.left + r.width / 2 - bw / 2), window.innerWidth - bw - 8);
                var top = r.top - bh - 8;
                if (top < 8) top = r.bottom + 8;
                bhyTipBubble.style.left = left + 'px';
                bhyTipBubble.style.top = top + 'px';
            }
            function bhyTipHide() {
                if (bhyTipBubble) bhyTipBubble.classList.remove('is-visible');
            }
            document.addEventListener('mouseover', function (e) {
                var tip = e.target.closest('.bhy-tip');
                if (tip) bhyTipShow(tip);
            });
            document.addEventListener('mouseout', function (e) {
                if (e.target.closest('.bhy-tip')) bhyTipHide();
            });
            document.addEventListener('focusin', function (e) {
                var tip = e.target.closest('.bhy-tip');
                if (tip) bhyTipShow(tip);
            });
            document.addEventListener('focusout', function (e) {
                if (e.target.closest('.bhy-tip')) bhyTipHide();
            });
        })();
        </script>
        <?php
    }

    // A small "?" badge that reveals `$text` on hover or keyboard focus
    // — see print_design_system_js()'s .bhy-tip handling and
    // design_system_css()'s .bhy-tip/.bhy-tip-bubble rules for the
    // actual show/hide + positioning behavior. `role="button"` +
    // `tabindex="0"` make it a real keyboard-reachable target (a
    // hover-only tooltip is unreachable without a mouse); `aria-label`
    // gives a screen reader the same text sighted users get from the
    // bubble, since the bubble itself is decorative (built via
    // textContent, not part of the accessibility tree by default).
    public static function tip(string $text): string {
        return '<span class="bhy-tip" tabindex="0" role="button" data-tip="' . esc_attr($text) . '" aria-label="' . esc_attr($text) . '">?</span>';
    }

    public static function print_design_system_css(): void {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $id = $screen ? $screen->id : '';
        // Only print on the ecosystem's own screens (the-self-hosted-self / bh-*
        // admin pages), identified by the "page_" hook suffix WordPress
        // gives submenu pages — never on core WP or unrelated plugin
        // screens, so this can't collide with theme/plugin admin CSS.
        // Broadened from a literal 'bh-' substring match: real screen ids
        // in this ecosystem take several shapes WordPress itself derives
        // (edit-bh_course, edit-bhs_feed_source, bhs_track_page_bhm-
        // settings, the-self-hosted-self_page_ous-debug, etc.) — a strict 'bh-'
        // check missed most of them, silently never printing this CSS/JS
        // on exactly the screens that use it. Matching the bare 'bh'
        // prefix (every post type/slug in this ecosystem starts with it)
        // plus 'ous' (the-self-hosted-self's own non-'bh' pages) is safe: no core
        // WordPress screen id contains either as a substring.
        if ($id !== '' && strpos($id, 'bh') === false && strpos($id, 'ous') === false && strpos($id, 'the-self-hosted-self') === false) return;
        echo '<style>' . self::design_system_css() . '</style>';
    }

    public static function design_system_css(): string {
        return '
            /* ============================================================
               LAYER 1 — TOKENS. Custom properties only, no selectors.
               New color/spacing/type value? It goes here, named, with a
               fallback the rest of this file can reference. See
               STYLE-SYSTEM.md at the plugins root for the full 4-layer
               model (tokens / utilities / components / plugin-local) this
               file and class-style.php both follow.
               ============================================================ */
            :root {
                --bhy-space-1: 4px; --bhy-space-2: 8px; --bhy-space-3: 12px; --bhy-space-4: 16px;
                --bhy-space-5: 20px; --bhy-space-6: 24px; --bhy-space-8: 32px;
                --bhy-text-xs: 11px; --bhy-text-sm: 12px; --bhy-text-base: 13px; --bhy-text-md: 14px;
                --bhy-text-lg: 16px; --bhy-text-xl: 20px; --bhy-text-2xl: 24px;
                /* How far a sticky element must sit below the WordPress admin
                   bar. Below 782px core switches #wpadminbar to
                   position:absolute so it scrolls away entirely, and anything
                   still reserving space for it leaves a dead band that page
                   content shows through. That exact bug shipped twice --
                   the front-end site header and this quicknav -- so the offset
                   is a token now rather than a literal repeated per surface. */
                --bhy-admin-bar-offset: 32px;
                --bhy-ink: #1d2327; --bhy-ink-dim: #646970; --bhy-border: #dcdcde; --bhy-surface: #fff;
                --bhy-subtle: #f6f7f7; --bhy-accent: #2271b1;
                --bhy-success: #1a7f37; --bhy-success-bg: #dafbe1;
                --bhy-warning: #8a5a00; --bhy-warning-bg: #fef3e2;
                --bhy-danger: #b3261e; --bhy-danger-bg: #fbe4e2;
                --bhy-radius: 8px; --bhy-radius-sm: 6px;
                /* 3.4.33 additions - additive only, nothing above changed.
                   A single shared micro-transition timing (hover/select/
                   expand states across the Design Suite shell) plus a
                   couple of named surface tints so "hovered row" and
                   "selected row" read the same way everywhere instead of
                   each screen picking its own ad hoc rgba value. */
                --bhy-transition: 150ms ease;
                --bhy-hover-tint: #f6f7f7;
                --bhy-selected-tint: #f0f6fc;
                --bhy-focus-ring: 0 0 0 2px rgba(34, 113, 177, .25);
            }
            .bhy-shell h1 { font-size: var(--bhy-text-2xl); margin-bottom: var(--bhy-space-2); }
            .bhy-shell .description { font-size: var(--bhy-text-base); color: var(--bhy-ink-dim); margin-bottom: var(--bhy-space-4); }

            /* ============================================================
               LAYER 3 — COMPONENTS. Named, reusable UI pieces that
               compose tokens + utilities (card/alert/badge/table below).
               New admin screen needs a status pill, a notice box, a
               surface panel, or a wide table? Check here BEFORE hand-
               rolling one inline — this is the whole reason those got
               reinvented per call site in the 2026-08 style audit.
               ============================================================ */

            /* Card — the one surface treatment every custom admin
               screen should reuse instead of inventing its own
               background/border/radius combination inline. */
            .bhy-card {
                background: var(--bhy-surface); border: 1px solid var(--bhy-border); border-radius: var(--bhy-radius);
                padding: var(--bhy-space-4) var(--bhy-space-5); margin-bottom: var(--bhy-space-5);
            }
            .bhy-card > h2, .bhy-card > h3 {
                font-size: var(--bhy-text-sm); text-transform: uppercase; letter-spacing: .04em;
                margin: 0 0 var(--bhy-space-3); color: var(--bhy-ink-dim);
            }

            /* Alert — left-border-accented, Primer-style; one shared
               shape for warning/success/danger/info instead of each
               admin screen picking its own ad hoc colors/padding. */
            @media screen and (max-width: 782px) {
                :root { --bhy-admin-bar-offset: 0px; }
            }
            /* Any admin surface that needs to stick below the bar should use
               this rather than hardcoding a pixel value. */
            .bhy-sticky-below-bar { position: sticky; top: var(--bhy-admin-bar-offset); }
            .bhy-alert {
                border: 1px solid var(--bhy-border); border-left-width: 4px; border-radius: var(--bhy-radius-sm);
                padding: var(--bhy-space-3) var(--bhy-space-4); margin-bottom: var(--bhy-space-4); font-size: var(--bhy-text-base);
            }
            .bhy-alert strong { display: inline-block; margin-right: var(--bhy-space-2); }
            /* Body text is ALWAYS the readable ink; the hue carries meaning
               through the left border and the background tint, which is where
               it can be saturated without costing legibility. Setting `color`
               to the hue itself put a saturated colour on a 16% tint of that
               same colour — measured 3.36:1 (success), 4.00:1 (warning) and
               4.15:1 (danger) in light mode, 4.38:1 (danger) in dark. All
               below AA. .bhy-alert-info was already correct and is the
               pattern the other three now follow.

               The TITLE gets ink too. Tinting it was tried and measured at
               3.36/4.00/4.15:1 — it fails for the same reason the body did.
               The WCAG 3:1 large-text allowance needs >=18.66px bold and this
               is ~13px, so there is no exception to lean on. The hue still
               carries the signal through the 4px left border and the
               background tint, which is where saturation costs nothing;
               `strong` keeps the emphasis through weight. Per the brief:
               usefulness beats the decorative reading.

               NOTE: no apostrophes in this comment, deliberately. It sits
               inside a single-quoted PHP string, and an unescaped one here
               silently terminates that string and fatals the whole site —
               the documented incident in CLAUDE.md, reproduced live while
               writing this very comment and caught by `php -l`. */
            .bhy-alert-warning { background: var(--bhy-warning-bg); border-left-color: var(--bhy-warning); color: var(--bhy-ink); }
            .bhy-alert-success { background: var(--bhy-success-bg); border-left-color: var(--bhy-success); color: var(--bhy-ink); }
            .bhy-alert-danger  { background: var(--bhy-danger-bg);  border-left-color: var(--bhy-danger);  color: var(--bhy-ink); }
            .bhy-alert-info    { background: var(--bhy-subtle);     border-left-color: var(--bhy-accent);  color: var(--bhy-ink); }

            .bhy-alert :is(ul, p:last-child) { margin-bottom: 0; }

            /* Badge/pill — status chips (Approved/Pending, live/off-air,
               vote counts) all reuse this instead of one-off inline
               background+radius+padding per call site. */
            .bhy-badge {
                display: inline-flex; align-items: center; gap: 4px; font-size: var(--bhy-text-xs); font-weight: 600;
                padding: 2px 10px; border-radius: 999px; line-height: 1.6; white-space: nowrap;
            }
            .bhy-badge-dot::before { content: ""; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
            .bhy-badge-neutral { background: #f0f0f1; color: var(--bhy-ink-dim); }
            .bhy-badge-success { background: var(--bhy-success-bg); color: var(--bhy-success); }
            .bhy-badge-warning { background: var(--bhy-warning-bg); color: var(--bhy-warning); }
            .bhy-badge-danger  { background: var(--bhy-danger-bg);  color: var(--bhy-danger); }
            /* The white-space:nowrap on .bhy-badge above is correct for a
               FIXED-vocabulary label ("up to date", "Approved") — no
               length risk to guard against. A badge wrapping dynamic/
               unbounded content (a user-entered tag, an artist-chosen
               category) needs bounding too, or nowrap alone just blows
               out the layout instead of wrapping ugly — add this
               alongside .bhy-badge for that case. Pair with a real
               title="..." attribute carrying the full text. */
            .bhy-badge-truncate { overflow: hidden; text-overflow: ellipsis; max-width: 160px; vertical-align: bottom; }

            /* ---- LAYER 2 (utilities, not a component) — kept here next
               to .bhy-badge since that\'s their most common pairing, but
               these are generic single-purpose helpers, not one named
               "thing." See STYLE-SYSTEM.md. ----
               Text-overflow utilities — the admin-side counterpart to
               the front-end .bh-truncate/.bh-clamp-* in class-style.php
               (see that own docblock for the full per-content-type
               reasoning: single-line content truncates with an
               ellipsis + title attr, multi-line content clamps at a
               fixed line count). These are for everything else in an
               admin table/list that is not a badge. */
            .bhy-truncate { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            .bhy-clamp-2 { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }

            /* Table wrapper — every wide admin table gets the same
               horizontal-scroll behavior, and (via container query) a
               denser padding once its own available width drops below
               a comfortable reading width, rather than only reacting to
               the whole browser window\'s size. Covers both real
               WP_List_Table output (.wp-list-table) and the plainer
               .widefat tables several plugins build by hand (BH
               Courses\' Student Progress — genuinely one column per
               lesson, the actual worst-case width in this whole
               ecosystem — and the Job Queue debug table) — the default
               posture everywhere in this ecosystem is "just use core
               WordPress admin styling as-is," this wrapper is the one
               deliberate deviation, and only because a wide data table
               with no horizontal-scroll affordance is a genuinely bad
               experience on anything narrower than a desktop, not
               because plain admin tables needed a makeover. */
            /* max-height caps every wrapped table at a reasonable number
               of visible rows before IT scrolls internally, rather than
               the table pushing the whole admin page taller and taller
               the more rows it happens to have. overflow-y: auto here
               (not just overflow-x) makes THIS wrapper the nearest
               scrolling ancestor, which is also what makes the sticky
               header below correctly stick to the top of the wrapper\'s
               own scroll — not the outer page\'s.

               Two sizes, not one, because "how much scroll room does
               this table deserve" genuinely depends on what else is on
               the same screen: the DEFAULT (~10-12 rows, 420px) is for
               a table that\'s one of several cards on the same page —
               bh-streaming\'s four stats tables, the Debug Tools page\'s
               several plugin sections, a CRM detail view\'s activity
               list — where giving any one of them a tall scroll area
               would just push its siblings further down for no reason.
               .bhy-table-wrap--tall (~20-24 rows, 760px) opts in for the
               opposite case: a page whose ENTIRE reason for existing is
               that one list — Reports/moderation queue, Registry
               Submissions review, a People directory — where the table
               IS the page, and cramming it into the same small window
               as a multi-card dashboard would waste most of the screen. */
            .bhy-table-wrap { container-type: inline-size; overflow-x: auto; overflow-y: auto; max-height: 420px; -webkit-overflow-scrolling: touch; border: 1px solid var(--bhy-border); border-radius: var(--bhy-radius); }
            .bhy-table-wrap.bhy-table-wrap--tall { max-height: 760px; }
            /* Safety net for wide tables that were never wrapped. An audit on
               2026-08-24 found 21 table.widefat instances rendered without
               .bhy-table-wrap against 18 with it, so more than half the wide
               tables in the ecosystem ignored the convention. The visible
               result was reported from a phone: Project Tracker overflowed
               horizontally, its table measuring 586px with overflow visible
               and no scroll parent.

               Making the table itself a scrolling block is the fix that does
               not require touching 21 call sites in 12 files, and it covers
               any table added later that forgets the wrapper. The wrapped
               case is restored to display:table immediately below, since a
               scroll container inside a scroll container is not wanted.

               Only below 782px: on a desktop the table has room and normal
               table layout is preferable. */
            @media (max-width: 782px) {
                .wrap table.widefat, .wrap table.wp-list-table {
                    display: block;
                    overflow-x: auto;
                    -webkit-overflow-scrolling: touch;
                }
                .bhy-table-wrap table.widefat, .bhy-table-wrap table.wp-list-table {
                    display: table;
                    overflow-x: visible;
                }
            }
            .bhy-table-wrap table.wp-list-table, .bhy-table-wrap table.widefat { border: none; margin: 0; }
            .bhy-table-wrap table.wp-list-table thead th, .bhy-table-wrap table.widefat thead th { position: sticky; top: 0; background: var(--bhy-subtle); z-index: 1; white-space: nowrap; }
            /* Hover highlight — makes a dense, striped table easier to
               scan/track across columns on a single row; doesn\'t fight
               .striped\'s own alternating background since this is just
               a slightly darker overlay on whichever row the pointer is
               actually over. */
            .bhy-table-wrap table.wp-list-table tbody tr:hover, .bhy-table-wrap table.widefat tbody tr:hover { background: var(--bhy-subtle); }
            /* Sortable column headers (see BHY_UI\'s shared JS) — a plain
               visual affordance (pointer cursor, a caret hinting "this
               is clickable") on any <th data-sort> inside a
               table.bhy-sortable, so a plugin opts in by adding one class
               and one data attribute per column, no separate JS to write. */
            table.bhy-sortable thead th[data-sort] { cursor: pointer; user-select: none; }
            table.bhy-sortable thead th[data-sort]::after { content: "\2195"; opacity: .35; margin-left: 4px; font-size: var(--bhy-text-xs); }
            table.bhy-sortable thead th[data-sort].bhy-sort-asc::after { content: "\2191"; opacity: 1; }
            table.bhy-sortable thead th[data-sort].bhy-sort-desc::after { content: "\2193"; opacity: 1; }
            /* Search box above a sortable/filterable table — same card-
               adjacent look as everything else, not a bare unstyled
               <input>. */
            input.bhy-table-search { width: 100%; max-width: 320px; margin-bottom: var(--bhy-space-3); padding: 6px 10px; border: 1px solid var(--bhy-border); border-radius: var(--bhy-radius-sm); font-size: var(--bhy-text-base); }
            /* Copy-to-clipboard button — a small icon-ish button that
               sits right next to a URL/code value instead of relying on
               "click the box, select all, ctrl+c" as the only way to
               grab it. */
            .bhy-copy-btn { font-size: var(--bhy-text-xs); padding: 2px 8px; margin-left: var(--bhy-space-2); cursor: pointer; }
            .bhy-copy-btn.bhy-copied { color: var(--bhy-success); border-color: var(--bhy-success); }
            @container (max-width: 640px) {
                .bhy-table-wrap table.wp-list-table th, .bhy-table-wrap table.wp-list-table td,
                .bhy-table-wrap table.widefat th, .bhy-table-wrap table.widefat td { padding: 6px 8px; font-size: var(--bhy-text-sm); white-space: nowrap; }
            }

            /* Range slider — same "detented" feel the quick-theme swatch
               picker and radius_sm slider already had: a visible filled
               track (not just a bare thumb on a flat line) plus tick
               marks at each step so discrete values read as discrete. */
            input.bhy-range {
                -webkit-appearance: none; appearance: none; width: 100%; height: 6px; border-radius: 999px;
                background: linear-gradient(to right, var(--bhy-accent) 0%, var(--bhy-accent) var(--bhy-range-pct, 50%), #e2e2e2 var(--bhy-range-pct, 50%), #e2e2e2 100%);
                cursor: pointer; margin: var(--bhy-space-2) 0;
            }
            input.bhy-range::-webkit-slider-thumb {
                -webkit-appearance: none; width: 16px; height: 16px; border-radius: 50%; background: #fff;
                border: 2px solid var(--bhy-accent); box-shadow: 0 1px 3px rgba(0,0,0,.25); cursor: pointer;
            }
            input.bhy-range::-moz-range-thumb {
                width: 16px; height: 16px; border-radius: 50%; background: #fff; border: 2px solid var(--bhy-accent);
                box-shadow: 0 1px 3px rgba(0,0,0,.25); cursor: pointer;
            }

            /* In-context help tooltip — a small "?" badge any admin
               screen can drop next to a label/control via BHY_UI::tip().
               Hover OR keyboard focus reveals the bubble (never
               hover-only — a field label\'s worth of context shouldn\'t
               be unreachable from the keyboard), positioned by JS in
               print_design_system_js() rather than pure CSS since a
               fixed ::after placement clips against real metabox edges
               on narrower screens. */
            .bhy-tip {
                display: inline-flex; align-items: center; justify-content: center;
                width: 15px; height: 15px; border-radius: 50%; margin-left: 4px;
                background: var(--bhy-surface-2, #f0f0f1); color: var(--bhy-text-dim, #6b7280);
                font-size: 10.5px; font-weight: 700; line-height: 1; cursor: help;
                border: 1px solid var(--bhy-border, #dcdcde); vertical-align: middle;
            }
            .bhy-tip:hover, .bhy-tip:focus-visible {
                background: var(--bhy-accent, #2271b1); color: #fff; border-color: var(--bhy-accent, #2271b1); outline: none;
            }
            .bhy-tip-bubble {
                /* Fixed dark chip regardless of the current admin color
                   scheme — QA fix: this originally read
                   background:var(--bhy-text, ...), but that token is
                   the admin design system\'s theme-relative FOREGROUND
                   text color, not a fixed dark chrome color (confirmed
                   live: the portal\'s identical mistake, --bh-text,
                   rendered as a washed-out light-cream box on the
                   site\'s real dark theme instead of a proper dark
                   tooltip). A transient overlay like this should look
                   the same regardless of theme. */
                position: fixed; z-index: 100000; max-width: 280px; padding: 8px 11px;
                background: #1d2327; color: #fff; font-size: var(--bhy-text-xs, 12px);
                font-weight: 400; line-height: 1.4; border-radius: var(--bhy-radius-sm, 6px);
                box-shadow: 0 2px 10px rgba(0,0,0,.3); pointer-events: none; opacity: 0;
                transform: translateY(2px); transition: opacity .12s ease, transform .12s ease;
            }
            .bhy-tip-bubble.is-visible { opacity: 1; transform: translateY(0); }
        ';
    }

    // Consistent open/close for the shared card+shell wrapper any custom
    // admin screen (Console, Debug Tools, People, etc.) can use instead
    // of its own one-off wrap markup.
    // $title may include small inline markup (e.g. a live-status dot
    // span) — callers pass plain text through esc_html themselves first
    // if they don't need that; wp_kses_post keeps this safe either way.
    public static function shell_open(string $title, string $description = ''): void {
        echo '<div class="wrap bhy-shell"><h1>' . wp_kses_post($title) . '</h1>';
        if ($description) echo '<p class="description">' . wp_kses_post($description) . '</p>';
    }

    public static function shell_close(): void {
        echo '</div>';
    }

    /* ---------------------------------------------------------------
     * Utility/"hidden" pages (Debug Tools, and anything similar a
     * future plugin adds) should always sort to the BOTTOM of whichever
     * parent menu they live under, regardless of what order plugins
     * happened to call add_submenu_page() in, or whether the core's own
     * menu-merge (which runs late, at priority 999 — see
     * class-menu-merge.php) appended something after them. This only
     * ever reorders entries within global $submenu — it never adds,
     * removes, or relocates a page across parents, so it's safe to run
     * regardless of which plugins are active.
     * --------------------------------------------------------------- */
    public static function pin_hidden_submenus_to_bottom(): void {
        add_action('admin_menu', [self::class, 'reorder_hidden_submenus'], 1000);
    }

    // Slugs any ecosystem plugin considers a "utility" page rather than
    // a primary destination — filterable so a future plugin (its own
    // debug/maintenance page) can opt in without touching this file.
    /** @return array<int, string> */
    public static function hidden_submenu_slugs(): array {
        // 'ous-debug' itself used to live here too, back when Debug Tools
        // was a submenu under the main "The Self-Hosted Self" hub — it's now its
        // own top-level "OUS Debug" menu (see class-debug.php), so
        // there's no longer a hub submenu entry for it to pin. API Docs
        // still hangs under THAT top-level menu (alongside Debug Tools'
        // own auto-relabeled first item) and stays pinned to the bottom
        // of it.
        return apply_filters('bhy_hidden_submenu_slugs', ['ous-api-docs']);
    }

    public static function reorder_hidden_submenus(): void {
        global $submenu;
        $hidden = self::hidden_submenu_slugs();
        if (!$hidden || !is_array($submenu)) return;

        foreach ($submenu as $parent => &$items) {
            $normal = [];
            $pinned = [];
            foreach ($items as $item) {
                // $item[2] is the slug WordPress stores each submenu
                // entry under.
                if (in_array($item[2], $hidden, true)) $pinned[] = $item;
                else $normal[] = $item;
            }
            if ($pinned) $items = array_merge($normal, $pinned);
        }
    }
    /* ---------------- component renderers ----------------
       Each renders through Twig when the template engine is available and
       falls back to the original PHP string build when it is not. The
       fallback is not dead code: the live host runs no composer install, so
       a plugin folder uploaded without its vendor/ must still render rather
       than fatal. Call sites are unaffected either way — that is the point
       of the seam.
       Single source for the shared component markup. Before these existed
       `.bhy-badge` was hand-written in 14 separate files, so nothing could
       enforce its structure and every copy was free to drift — the same
       failure shape as the eight hand-rolled pills that predated the class,
       and as the .bhm-paywall copy that diverged in bh-streaming.

       These also escape by construction, which removes a whole class of
       WordPress.Security.EscapeOutput findings at the source rather than
       asking every call site to remember. */

    /**
     * Is the Twig layer usable right now?
     *
     * class_exists() as well as is_available(): these renderers are called
     * from tools that run OUTSIDE WordPress (the Storybook fixture generator
     * loads this file directly), where BHY_View is not loaded at all and
     * Timber cannot boot anyway — Timber\Timber::compile() calls
     * get_template_directory(), which only exists inside WordPress. Without
     * this guard those tools fatal instead of taking the PHP fallback.
     */
    private static function view_engine_ready(): bool {
        return class_exists('BHY_View') && BHY_View::is_available();
    }

    public const BADGE_VARIANTS = ['neutral', 'success', 'warning', 'danger', 'info'];
    public const ALERT_VARIANTS = ['info', 'success', 'warning', 'danger'];

    /**
     * A status pill. Use for state ("Active", "Pending", "Failed") — never
     * as decoration on something that carries no state.
     *
     * @param array{variant?:string, dot?:bool, truncate?:bool, title?:string} $args
     */
    public static function badge(string $label, array $args = []): string {
        $variant = (string) ($args['variant'] ?? 'neutral');
        if (!in_array($variant, self::BADGE_VARIANTS, true)) $variant = 'neutral';

        $classes = ['bhy-badge', 'bhy-badge-' . $variant];
        if (!empty($args['dot']))      $classes[] = 'bhy-badge-dot';
        // WHY: only for unbounded labels (a user tag, an artist name). A
        // fixed-vocabulary label should never truncate — see STYLE-SYSTEM.md.
        if (!empty($args['truncate'])) $classes[] = 'bhy-badge-truncate';

        if (self::view_engine_ready()) {
            return trim(BHY_View::render('@ous/badge.twig', [
                'label'    => $label,
                'variant'  => $variant,
                'dot'      => !empty($args['dot']),
                'truncate' => !empty($args['truncate']),
                'title'    => $args['title'] ?? null,
            ]));
        }
        $title = isset($args['title']) ? ' title="' . esc_attr((string) $args['title']) . '"' : '';
        return '<span class="' . esc_attr(implode(' ', $classes)) . '"' . $title . '>' . esc_html($label) . '</span>';
    }

    /**
     * A bordered/tinted callout.
     *
     * @param array{variant?:string, title?:string, html?:string} $args
     *   'html' is pre-escaped markup for the body — callers passing it own
     *   their own escaping. Plain text should go in $message instead.
     */
    public static function alert(string $message, array $args = []): string {
        $variant = (string) ($args['variant'] ?? 'info');
        if (!in_array($variant, self::ALERT_VARIANTS, true)) $variant = 'info';

        if (self::view_engine_ready()) {
            return trim(BHY_View::render('@ous/alert.twig', [
                'variant'   => $variant,
                'title'     => $args['title'] ?? null,
                'message'   => $message,
                'body_html' => $args['html'] ?? null,
            ]));
        }
        $out = '<div class="bhy-alert bhy-alert-' . esc_attr($variant) . '">';
        if (!empty($args['title'])) {
            $out .= '<strong class="bhy-alert-title">' . esc_html((string) $args['title']) . '</strong> ';
        }
        $out .= $message !== '' ? esc_html($message) : '';
        if (!empty($args['html'])) $out .= $args['html'];
        return $out . '</div>';
    }

    /**
     * A surface panel. $body is pre-escaped markup — this owns the shell,
     * not the contents.
     *
     * @param array{title?:string, footer?:string, class?:string} $args
     */
    public static function card(string $body, array $args = []): string {
        if (self::view_engine_ready()) {
            return trim(BHY_View::render('@ous/card.twig', [
                'body_html'   => $body,
                'title'       => $args['title'] ?? null,
                'footer_html' => $args['footer'] ?? null,
                'extra_class' => $args['class'] ?? null,
            ]));
        }
        $extra = isset($args['class']) ? ' ' . (string) $args['class'] : '';
        $out = '<div class="bhy-card' . esc_attr($extra) . '">';
        if (!empty($args['title'])) {
            $out .= '<h3 class="bhy-card-title">' . esc_html((string) $args['title']) . '</h3>';
        }
        $out .= $body;
        if (!empty($args['footer'])) $out .= '<div class="bhy-card-footer">' . $args['footer'] . '</div>';
        return $out . '</div>';
    }

    /**
     * Wraps a wide/dense table so it scrolls instead of breaking the layout.
     * $table_html is pre-escaped markup.
     *
     * @param array{tall?:bool} $args
     */
    public static function table_wrap(string $table_html, array $args = []): string {
        if (self::view_engine_ready()) {
            return trim(BHY_View::render('@ous/table-wrap.twig', [
                'table_html' => $table_html,
                'tall'       => !empty($args['tall']),
            ]));
        }
        $class = 'bhy-table-wrap' . (!empty($args['tall']) ? ' bhy-table-wrap--tall' : '');
        return '<div class="' . esc_attr($class) . '">' . $table_html . '</div>';
    }

}

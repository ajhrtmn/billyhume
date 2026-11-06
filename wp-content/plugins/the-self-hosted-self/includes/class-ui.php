<?php
if (!defined('ABSPATH')) exit;

/**
 * Reusable UI components: admin form controls, the shared admin design
 * system, and front-end component renderers.
 */
class BHY_UI {
    const EMPTY_STATE_ICONS = [
        'search' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="10" cy="10" r="7"></circle><line x1="21" y1="21" x2="15.5" y2="15.5"></line></svg>',
        'info-outline' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"></circle><line x1="12" y1="11" x2="12" y2="16"></line><line x1="12" y1="8" x2="12" y2="8"></line></svg>',
    ];

    /**
     * Shared front-end "nothing to show" component for list/catalog surfaces.
     *
     * $args:
     *   'reason'      'zero' (nothing exists yet) or 'filtered' (no matches);
     *                 changes the default message and the "clear filters" link.
     *   'title'       required, short; name the specific cause.
     *   'description' optional, one more sentence.
     *   'cta_label'/'cta_url' optional single next-step link.
     *   'clear_url'   optional; rendered only when reason='filtered'.
     *   'icon'        optional (no 'dashicons-' prefix); defaults to
     *                 'search' for filtered, 'info' for zero.
     *
     * WHY: the <style> block is embedded on every call, not printed once, because
     * consumers swap innerHTML between variants and would lose a one-time style tag.
     */
    /** @param array<string, mixed> $args */
    public static function empty_state_html(array $args): string {
        $reason = $args['reason'] ?? 'zero';
        $title = (string) ($args['title'] ?? ($reason === 'filtered' ? 'Nothing matches your filters' : 'Nothing here yet'));
        $description = (string) ($args['description'] ?? '');
        $icon_key = (string) ($args['icon'] ?? ($reason === 'filtered' ? 'search' : 'info-outline'));
        $icon_svg = self::EMPTY_STATE_ICONS[$icon_key] ?? self::EMPTY_STATE_ICONS['info-outline'];

        // WHY --bhy-* not --bh-*: this renders in admin contexts, where only the
        // --bhy-* family is bridged to the dark admin skin.
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

    // Wires .bhy-swatch-controls text inputs to their swatch preview and color
    // picker. $on_sync_js runs after every sync (e.g. to refresh live previews).
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
        $is_custom = $picked !== BHY_Style::INHERIT_FONT && !array_key_exists($picked, BHY_Style::FONT_OPTIONS);
        ?>
        <div class="bhy-font-field">
            <label for="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label>
            <select id="<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($key); ?>" data-custom-target="<?php echo esc_attr($key); ?>_custom">
                <option value="<?php echo esc_attr(BHY_Style::INHERIT_FONT); ?>" <?php selected($picked, BHY_Style::INHERIT_FONT); ?>><?php echo esc_html(BHY_Style::INHERIT_FONT); ?> (match the site theme)</option>
                <?php foreach (BHY_Style::FONT_OPTIONS as $name => $param): ?>
                    <?php
                    // An <option>'s inline font-family previews the typeface; the
                    // gallery loads the webfonts on this page (enqueue_media()).
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
        return (string) file_get_contents(OUS_PATH . 'assets/css/admin-page.css') . self::swatch_css();
    }

    /* ---------------------------------------------------------------
     * Shared ecosystem admin design system: spacing/type scales and
     * reusable component classes (card, alert, badge, table wrapper, range
     * slider), printed globally on ecosystem admin screens.
     * --------------------------------------------------------------- */
    public static function init_shared_admin_assets(): void {
        add_action('admin_head', [self::class, 'print_design_system_css']);
        add_action('admin_footer', [self::class, 'print_design_system_js']);
        add_action('admin_head', [self::class, 'print_block_editor_metabox_fix']);
    }

    /**
     * WHY: core's resizable Meta Boxes panel can squeeze the block editor canvas
     * to 0px height on screens with several metaboxes. This gives the canvas a
     * minimum height. Unscoped, because it reproduces on every post-edit screen.
     */
    public static function print_block_editor_metabox_fix(): void {
        echo '<style>.editor-resizable-editor{min-height:300px !important;}</style>';
    }

    /**
     * Dependency-free behaviors any ecosystem admin screen opts into by markup:
     *   - `input.bhy-table-search[data-target="#table-id"]` filters tbody rows.
     *   - `table.bhy-sortable` with `<th data-sort>` sorts by column (numeric-aware).
     *   - `button.bhy-copy-btn[data-copy-target="#id"]` copies value/text.
     */
    public static function print_design_system_js(): void {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $id = $screen ? $screen->id : '';
        // Ecosystem screen ids take many shapes (edit-bh_course,
        // the-self-hosted-self_page_ous-debug); no core screen id contains 'bh' or 'ous'.
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

    // A "?" badge revealing $text on hover or focus. role="button" + tabindex
    // make it keyboard-reachable; aria-label carries the text for screen readers
    // since the bubble itself is decorative.
    public static function tip(string $text): string {
        return '<span class="bhy-tip" tabindex="0" role="button" data-tip="' . esc_attr($text) . '" aria-label="' . esc_attr($text) . '">?</span>';
    }

    public static function print_design_system_css(): void {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $id = $screen ? $screen->id : '';
        // Only on ecosystem screens. Ecosystem screen ids take many shapes
        // (edit-bh_course, the-self-hosted-self_page_ous-debug); no core screen id
        // contains 'bh' or 'ous'.
        if ($id !== '' && strpos($id, 'bh') === false && strpos($id, 'ous') === false && strpos($id, 'the-self-hosted-self') === false) return;
        echo '<style>' . self::design_system_css() . '</style>';
    }

    public static function design_system_css(): string {
        return (string) file_get_contents(OUS_PATH . 'assets/css/design-system.css');
    }

    // Shared card+shell wrapper for custom admin screens. $title may contain
    // small inline markup; wp_kses_post keeps it safe.
    public static function shell_open(string $title, string $description = ''): void {
        echo '<div class="wrap bhy-shell"><h1>' . wp_kses_post($title) . '</h1>';
        if ($description) echo '<p class="description">' . wp_kses_post($description) . '</p>';
    }

    public static function shell_close(): void {
        echo '</div>';
    }

    /* ---------------------------------------------------------------
     * Sorts utility pages (Debug Tools, API Docs, ...) to the bottom of their
     * parent menu. Only reorders within global $submenu; never moves pages.
     * --------------------------------------------------------------- */
    public static function pin_hidden_submenus_to_bottom(): void {
        add_action('admin_menu', [self::class, 'reorder_hidden_submenus'], 1000);
    }

    // Slugs considered utility pages; filterable via bhy_hidden_submenu_slugs.
    /** @return array<int, string> */
    public static function hidden_submenu_slugs(): array {
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
                // $item[2] is the submenu entry's slug.
                if (in_array($item[2], $hidden, true)) $pinned[] = $item;
                else $normal[] = $item;
            }
            if ($pinned) $items = array_merge($normal, $pinned);
        }
    }
    /* ---------------- component renderers ----------------
       Single source for shared component markup, escaped by construction. */

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
        $class = 'bhy-table-wrap' . (!empty($args['tall']) ? ' bhy-table-wrap--tall' : '');
        return '<div class="' . esc_attr($class) . '">' . $table_html . '</div>';
    }

}

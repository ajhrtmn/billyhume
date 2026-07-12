<?php
if (!defined('ABSPATH')) exit;

/**
 * Reusable form components for the gallery's controls panel — extracted
 * directly from bh-contest's original settings page. These were already
 * fully generic (no contest-specific naming or behavior baked in), so
 * this is a clean move, not a rewrite.
 */
class BHY_UI {
    public static function swatch_css() {
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
        ';
    }

    public static function swatch_field($id, $name, $label, $value, $placeholder = '') {
        $display = $value !== '' ? $value : $placeholder;
        ?>
        <div class="bhy-swatch-card">
            <div class="bhy-swatch" id="bhy-swatch-<?php echo esc_attr($id); ?>" style="background:<?php echo esc_attr($display ?: '#f6f7f7'); ?>"></div>
            <div class="bhy-swatch-body">
                <label for="<?php echo esc_attr($id); ?>"><?php echo esc_html($label); ?></label>
                <div class="bhy-swatch-controls">
                    <input type="text" id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>"
                           value="<?php echo esc_attr($value); ?>" placeholder="<?php echo esc_attr($placeholder); ?>" data-key="<?php echo esc_attr($id); ?>">
                    <input type="color" id="bhy-picker-<?php echo esc_attr($id); ?>"
                           value="<?php echo esc_attr(strlen($display) === 7 && $display[0] === '#' ? $display : '#000000'); ?>" tabindex="-1">
                </div>
            </div>
        </div>
        <?php
    }

    // Wires up any .bhy-swatch-controls text input to its paired swatch
    // preview + color-picker dropper. $on_sync_js runs after every sync
    // (e.g. bh-style's gallery uses it to push the new value into every
    // registered surface's live preview, not just repaint the swatch).
    public static function swatch_js($on_sync_js = '') {
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
        })();
        ";
    }

    public static function font_field($key, $label, $s) {
        $picked = $s[$key];
        $is_custom = !array_key_exists($picked, BHY_Style::FONT_OPTIONS);
        ?>
        <div class="bhy-font-field">
            <label for="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label>
            <select id="<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($key); ?>" data-custom-target="<?php echo esc_attr($key); ?>_custom">
                <?php foreach (BHY_Style::FONT_OPTIONS as $name => $param): ?>
                    <?php
                    // 3.4.49 follow-up — AJ's own ask: "would be cool if
                    // the color and font selectors could preview what
                    // they look like." An <option>'s font-family CAN be
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

    public static function slider_row($key, $label, $s, $min, $max, $step, $unit) {
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

    public static function admin_page_css() {
        return '
            * { box-sizing: border-box; }
            /* Controls column widened from 320px to 380px, and the swatch
               grid below now sizes itself off its OWN available width
               (auto-fit/minmax) instead of a hardcoded 2-column split —
               together these give a 32px swatch + hex text input + color
               picker enough room to not clip a 7-character hex value. */
            .bhy-layout { display: grid; grid-template-columns: 200px 1fr 380px; gap: 20px; margin-top: 16px; align-items: start; }
            .bhy-sidebar { background: var(--bhy-surface, #fff); border: 1px solid var(--bhy-border, #dcdcde); border-radius: var(--bhy-radius, 8px); padding: var(--bhy-space-3, 12px); }
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
            .bhy-story-frame.active { display: block; }
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

            .bhy-swatch-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: var(--bhy-space-2, 8px); }
            .bhy-font-field { margin-bottom: var(--bhy-space-3, 10px); }
            .bhy-font-field select, .bhy-font-field input { width: 100%; margin-top: 4px; }
            .bhy-slider-row { margin-bottom: var(--bhy-space-3, 10px); }
            .bhy-slider-row label { display: flex; justify-content: space-between; font-size: var(--bhy-text-sm, 12px); gap: var(--bhy-space-2, 8px); color: var(--bhy-ink, #1d2327); }
            .bhy-slider-row .bhy-slider-val { color: var(--bhy-ink-dim, #646970); font-variant-numeric: tabular-nums; }
            .bhy-slider-row input { width: 100%; }

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
    public static function init_shared_admin_assets() {
        add_action('admin_head', [self::class, 'print_design_system_css']);
        add_action('admin_footer', [self::class, 'print_design_system_js']);
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
    public static function print_design_system_js() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $id = $screen ? $screen->id : '';
        // Broadened from a literal 'bh-' substring match: real screen ids
        // in this ecosystem take several shapes WordPress itself derives
        // (edit-bh_course, edit-bhs_feed_source, bhs_track_page_bhm-
        // settings, own-ur-shit_page_ous-debug, etc.) — a strict 'bh-'
        // check missed most of them, silently never printing this CSS/JS
        // on exactly the screens that use it. Matching the bare 'bh'
        // prefix (every post type/slug in this ecosystem starts with it)
        // plus 'ous' (own-ur-shit's own non-'bh' pages) is safe: no core
        // WordPress screen id contains either as a substring.
        if ($id !== '' && strpos($id, 'bh') === false && strpos($id, 'ous') === false && strpos($id, 'own-ur-shit') === false) return;
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
        })();
        </script>
        <?php
    }

    public static function print_design_system_css() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $id = $screen ? $screen->id : '';
        // Only print on the ecosystem's own screens (own-ur-shit / bh-*
        // admin pages), identified by the "page_" hook suffix WordPress
        // gives submenu pages — never on core WP or unrelated plugin
        // screens, so this can't collide with theme/plugin admin CSS.
        // Broadened from a literal 'bh-' substring match: real screen ids
        // in this ecosystem take several shapes WordPress itself derives
        // (edit-bh_course, edit-bhs_feed_source, bhs_track_page_bhm-
        // settings, own-ur-shit_page_ous-debug, etc.) — a strict 'bh-'
        // check missed most of them, silently never printing this CSS/JS
        // on exactly the screens that use it. Matching the bare 'bh'
        // prefix (every post type/slug in this ecosystem starts with it)
        // plus 'ous' (own-ur-shit's own non-'bh' pages) is safe: no core
        // WordPress screen id contains either as a substring.
        if ($id !== '' && strpos($id, 'bh') === false && strpos($id, 'ous') === false && strpos($id, 'own-ur-shit') === false) return;
        echo '<style>' . self::design_system_css() . '</style>';
    }

    public static function design_system_css() {
        return '
            :root {
                --bhy-space-1: 4px; --bhy-space-2: 8px; --bhy-space-3: 12px; --bhy-space-4: 16px;
                --bhy-space-5: 20px; --bhy-space-6: 24px; --bhy-space-8: 32px;
                --bhy-text-xs: 11px; --bhy-text-sm: 12px; --bhy-text-base: 13px; --bhy-text-md: 14px;
                --bhy-text-lg: 16px; --bhy-text-xl: 20px; --bhy-text-2xl: 24px;
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
            .bhy-alert {
                border: 1px solid var(--bhy-border); border-left-width: 4px; border-radius: var(--bhy-radius-sm);
                padding: var(--bhy-space-3) var(--bhy-space-4); margin-bottom: var(--bhy-space-4); font-size: var(--bhy-text-base);
            }
            .bhy-alert strong { display: inline-block; margin-right: var(--bhy-space-2); }
            .bhy-alert-warning { background: var(--bhy-warning-bg); border-left-color: var(--bhy-warning); color: var(--bhy-warning); }
            .bhy-alert-success { background: var(--bhy-success-bg); border-left-color: var(--bhy-success); color: var(--bhy-success); }
            .bhy-alert-danger  { background: var(--bhy-danger-bg);  border-left-color: var(--bhy-danger);  color: var(--bhy-danger); }
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
        ';
    }

    // Tiny shared behavior for any input.bhy-range: keeps --bhy-range-pct
    // in sync with its value so the filled-track gradient above tracks
    // the thumb, and is safe to call multiple times on a page (each
    // range wires itself once via a data attribute guard).
    public static function range_fill_js() {
        return "
        document.querySelectorAll('input.bhy-range').forEach(function (input) {
            if (input.dataset.bhyRangeWired) return;
            input.dataset.bhyRangeWired = '1';
            function paint() {
                var min = parseFloat(input.min || 0), max = parseFloat(input.max || 100), val = parseFloat(input.value);
                var pct = max > min ? ((val - min) / (max - min)) * 100 : 0;
                input.style.setProperty('--bhy-range-pct', pct + '%');
            }
            input.addEventListener('input', paint);
            paint();
        });
        ";
    }

    // Consistent open/close for the shared card+shell wrapper any custom
    // admin screen (Console, Debug Tools, People, etc.) can use instead
    // of its own one-off wrap markup.
    // $title may include small inline markup (e.g. a live-status dot
    // span) — callers pass plain text through esc_html themselves first
    // if they don't need that; wp_kses_post keeps this safe either way.
    public static function shell_open($title, $description = '') {
        echo '<div class="wrap bhy-shell"><h1>' . wp_kses_post($title) . '</h1>';
        if ($description) echo '<p class="description">' . wp_kses_post($description) . '</p>';
    }

    public static function shell_close() {
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
    public static function pin_hidden_submenus_to_bottom() {
        add_action('admin_menu', [self::class, 'reorder_hidden_submenus'], 1000);
    }

    // Slugs any ecosystem plugin considers a "utility" page rather than
    // a primary destination — filterable so a future plugin (its own
    // debug/maintenance page) can opt in without touching this file.
    public static function hidden_submenu_slugs() {
        // 'ous-debug' itself used to live here too, back when Debug Tools
        // was a submenu under the main "Own Ur Shit" hub — it's now its
        // own top-level "OUS Debug" menu (see class-debug.php), so
        // there's no longer a hub submenu entry for it to pin. API Docs
        // still hangs under THAT top-level menu (alongside Debug Tools'
        // own auto-relabeled first item) and stays pinned to the bottom
        // of it.
        return apply_filters('bhy_hidden_submenu_slugs', ['ous-api-docs']);
    }

    public static function reorder_hidden_submenus() {
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
}

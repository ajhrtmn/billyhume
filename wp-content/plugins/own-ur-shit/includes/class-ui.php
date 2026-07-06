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
            .bhy-swatch-card { border: 1px solid #dcdcde; border-radius: 6px; padding: 8px; display: flex; gap: 10px; align-items: center; }
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
                    <option value="<?php echo esc_attr($name); ?>" <?php selected($picked, $name); ?>><?php echo esc_html($name); ?></option>
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
            .bhy-sidebar { background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 12px; }
            .bhy-sidebar-group { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: #787c82; margin: 12px 0 4px; }
            .bhy-sidebar-group:first-child { margin-top: 0; }
            .bhy-story-btn { display: block; width: 100%; text-align: left; background: none; border: none; padding: 8px 10px; border-radius: 6px; cursor: pointer; font-size: 13px; }
            .bhy-story-btn:hover { background: #f0f0f1; }
            .bhy-story-btn.active { background: #2271b1; color: #fff; }
            .bhy-canvas { background: #1a1a1a; border-radius: 8px; overflow: hidden; min-height: 320px; position: relative; }
            .bhy-story-frame { width: 100%; height: 600px; max-height: 75vh; border: 0; display: none; }
            .bhy-story-frame.active { display: block; }
            .bhy-empty { color: #888; padding: 40px; text-align: center; }
            .bhy-controls {
                background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 16px 20px;
                max-height: 80vh; overflow-y: auto; display: flex; flex-direction: column;
            }
            .bhy-controls h2 { font-size: 13px; text-transform: uppercase; letter-spacing: .04em; margin: 18px 0 8px; }
            .bhy-controls h2:first-child { margin-top: 0; }

            /* Always-visible sample chips proving every scale/shape token
               (radius, radius_sm, bar_height, font_scale, space_scale) is
               actually applying — independent of which registered surface
               is currently selected in the canvas, since no single surface
               is guaranteed to visibly use every token. */
            .bhy-token-preview {
                display: flex; flex-wrap: wrap; align-items: center; gap: 8px;
                background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 8px;
                padding: 12px; margin-bottom: 4px;
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

            .bhy-swatch-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 8px; }
            .bhy-font-field { margin-bottom: 10px; }
            .bhy-font-field select, .bhy-font-field input { width: 100%; margin-top: 4px; }
            .bhy-slider-row { margin-bottom: 10px; }
            .bhy-slider-row label { display: flex; justify-content: space-between; font-size: 12px; gap: 8px; }
            .bhy-slider-row input { width: 100%; }

            /* Save was previously the last thing in a tall, internally
               scrolling column — easy to lose track of after adjusting a
               dozen controls. Sticking it to the bottom of the panel
               keeps it in view without needing its own scroll container. */
            .bhy-controls p.submit {
                position: sticky; bottom: -16px; margin: 18px -20px -16px; padding: 12px 20px;
                background: #fff; border-top: 1px solid #dcdcde; box-shadow: 0 -4px 8px rgba(0,0,0,.04);
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
    }

    public static function print_design_system_css() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $id = $screen ? $screen->id : '';
        // Only print on the ecosystem's own screens (own-ur-shit / bh-*
        // admin pages), identified by the "page_" hook suffix WordPress
        // gives submenu pages — never on core WP or unrelated plugin
        // screens, so this can't collide with theme/plugin admin CSS.
        if ($id !== '' && strpos($id, 'bh-') === false && strpos($id, 'own-ur-shit') === false) return;
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
               the whole browser window\'s size. */
            .bhy-table-wrap { container-type: inline-size; overflow-x: auto; border: 1px solid var(--bhy-border); border-radius: var(--bhy-radius); }
            .bhy-table-wrap table.wp-list-table { border: none; margin: 0; }
            .bhy-table-wrap table.wp-list-table thead th { position: sticky; top: 0; background: var(--bhy-subtle); z-index: 1; }
            @container (max-width: 640px) {
                .bhy-table-wrap table.wp-list-table th, .bhy-table-wrap table.wp-list-table td { padding: 6px 8px; font-size: var(--bhy-text-sm); }
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
        return apply_filters('bhy_hidden_submenu_slugs', ['ous-debug']);
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

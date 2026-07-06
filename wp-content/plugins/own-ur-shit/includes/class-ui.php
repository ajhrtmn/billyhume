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
            .bhy-layout { display: grid; grid-template-columns: 200px 1fr 320px; gap: 20px; margin-top: 16px; align-items: start; }
            .bhy-sidebar { background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 12px; }
            .bhy-sidebar-group { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: #787c82; margin: 12px 0 4px; }
            .bhy-sidebar-group:first-child { margin-top: 0; }
            .bhy-story-btn { display: block; width: 100%; text-align: left; background: none; border: none; padding: 8px 10px; border-radius: 6px; cursor: pointer; font-size: 13px; }
            .bhy-story-btn:hover { background: #f0f0f1; }
            .bhy-story-btn.active { background: #2271b1; color: #fff; }
            .bhy-canvas { background: #1a1a1a; border-radius: 8px; overflow: hidden; min-height: 500px; position: relative; }
            .bhy-story-frame { width: 100%; height: 600px; border: 0; display: none; }
            .bhy-story-frame.active { display: block; }
            .bhy-empty { color: #888; padding: 40px; text-align: center; }
            .bhy-controls { background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 16px 20px; max-height: 80vh; overflow-y: auto; }
            .bhy-controls h2 { font-size: 13px; text-transform: uppercase; letter-spacing: .04em; margin: 18px 0 8px; }
            .bhy-controls h2:first-child { margin-top: 0; }
            .bhy-swatch-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
            .bhy-font-field { margin-bottom: 10px; }
            .bhy-font-field select, .bhy-font-field input { width: 100%; margin-top: 4px; }
            .bhy-slider-row { margin-bottom: 10px; }
            .bhy-slider-row label { display: flex; justify-content: space-between; font-size: 12px; }
            .bhy-slider-row input { width: 100%; }
        ' . self::swatch_css();
    }
}

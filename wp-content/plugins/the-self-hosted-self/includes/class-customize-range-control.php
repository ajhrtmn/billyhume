<?php
if (!defined('ABSPATH')) exit;

/**
 * A plain <input type="range"> Customizer control with a live number
 * readout. WP core ships no native range control in the classic
 * Customizer (Gutenberg's RangeControl component doesn't reach it), and
 * every component_tokens()/custom_sliders() size field needs one.
 * Deliberately NOT reusing BHY_UI::slider_row()'s markup/JS — that's
 * built for the Design Suite's own admin page, not the Customizer's
 * separate controls-pane script-loading model (customize_controls_
 * enqueue_scripts, not a normal admin_enqueue_scripts page load).
 *
 * WHY this file is required lazily (see BHY_Customizer::register()),
 * never from the plugin's own top-level require_once foreach: this class
 * extends WP_Customize_Control, which only exists once WP has decided
 * the current request is a Customizer request (customize_register only
 * fires from that same code path) — requiring this file unconditionally
 * at every page-parse time would fatal on any request where the
 * Customizer classes never loaded at all.
 */
class BHY_Customize_Range_Control extends WP_Customize_Control {
    public $type = 'bhy_range';
    public $unit = 'px';

    private static $script_added = false;

    public function render_content() {
        $min  = $this->input_attrs['min']  ?? 0;
        $max  = $this->input_attrs['max']  ?? 100;
        $step = $this->input_attrs['step'] ?? 1;
        ?>
        <label>
            <?php if ($this->label) : ?>
                <span class="customize-control-title"><?php echo esc_html($this->label); ?></span>
            <?php endif; ?>
            <span class="bhy-range-row" style="display:flex;align-items:center;gap:8px;">
                <input type="range" style="flex:1;"
                    min="<?php echo esc_attr($min); ?>"
                    max="<?php echo esc_attr($max); ?>"
                    step="<?php echo esc_attr($step); ?>"
                    value="<?php echo esc_attr($this->value()); ?>"
                    <?php $this->link(); ?> />
                <span class="bhy-range-value" data-unit="<?php echo esc_attr($this->unit); ?>"><?php echo esc_html($this->value() . $this->unit); ?></span>
            </span>
        </label>
        <?php
    }

    public function enqueue() {
        if (self::$script_added) return;
        self::$script_added = true;
        // Keeps the number readout in sync as the range thumb moves —
        // $this->link() above only updates the bound Customize setting
        // (and therefore the live preview), not this control's own text.
        wp_add_inline_script('customize-controls', "
            document.addEventListener('input', function (e) {
                if (!e.target || !e.target.matches('.bhy-range-row input[type=range]')) return;
                var out = e.target.parentElement.querySelector('.bhy-range-value');
                if (out) out.textContent = e.target.value + (out.dataset.unit || '');
            });
        ", 'after');
    }
}

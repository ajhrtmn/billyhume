<?php
if (!defined('ABSPATH')) exit;

/**
 * Range-input Customizer control with a live number readout (the classic
 * Customizer has no native range control).
 * WHY: required lazily from BHY_Customizer::register(); it extends
 * WP_Customize_Control, which only exists in Customizer requests.
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
        // link() updates the setting, not this control's own readout.
        wp_add_inline_script('customize-controls', "
            document.addEventListener('input', function (e) {
                if (!e.target || !e.target.matches('.bhy-range-row input[type=range]')) return;
                var out = e.target.parentElement.querySelector('.bhy-range-value');
                if (out) out.textContent = e.target.value + (out.dataset.unit || '');
            });
        ", 'after');
    }
}

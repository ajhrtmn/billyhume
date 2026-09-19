<?php
if (!defined('ABSPATH')) exit;

/**
 * BHY_Customize_Css_Rules_Control — the visual Custom CSS rule editor
 * (class-style-gallery.php's own copy, BHY_Style::css_property_registry()
 * /css_state_options()), rebuilt as a real Customizer control so AJ never
 * has to leave the Customizer to use it ("Can it stay all on the
 * customizer side instead of jumping to the backend. Its weird" — AJ,
 * 2026-09-19; "Both sides should be able to have acces to the rule
 * builder" — the admin page's own copy stays too, this is additive).
 *
 * Deliberately does NOT use WP_Customize_Control::link() (the standard
 * "bind one form field's value to this setting" helper) — this control's
 * value is a whole array of rule objects, not one string a plain input
 * can hold. Instead its enqueue()'d script talks to the Customize API
 * directly: reads the setting's current value with wp.customize(id).get(),
 * writes a new one with .set(realArray), and — since Customizer settings
 * transport arbitrary JS values structurally (via JSON over both the
 * postMessage preview channel and the save AJAX request, not a plain
 * form field's string .val()) — the array survives the round trip intact
 * on both sides: PHP's sanitize_callback receives a real decoded PHP
 * array, and the live preview's value.bind() receives a real JS array.
 *
 * Same lazy-require discipline as class-customize-range-control.php:
 * only ever required from inside BHY_Customizer::register(), never at
 * this plugin's top-level bootstrap, since WP_Customize_Control only
 * exists in a genuine Customizer request.
 */
class BHY_Customize_Css_Rules_Control extends WP_Customize_Control {
    public $type = 'bhy_css_rules';

    private static $script_enqueued = false;

    public function render_content() {
        ?>
        <style>
        /* The Customizer's own controls sidebar is even narrower than the
           Design Suite admin page's ~380px column (class-style-gallery.php
           has its own, near-identical copy of this) — everything here
           stacks vertically (flex-direction: column) rather than trying to
           fit a selector input + dropdown + button on one row at all. */
        .bhy-cz-rules-editor .bhy-css-rule { border: 1px solid #dcdcde; border-radius: 4px; padding: 10px; margin: 10px 0; background: #fff; }
        .bhy-cz-rules-editor .bhy-css-rule-header { display: flex; flex-direction: column; gap: 6px; margin-bottom: 8px; }
        .bhy-cz-rules-editor .bhy-css-rule-header input,
        .bhy-cz-rules-editor .bhy-css-rule-header select { width: 100%; max-width: 100%; box-sizing: border-box; }
        .bhy-cz-rules-editor .bhy-css-rule-props { display: flex; flex-direction: column; gap: 8px; margin-bottom: 8px; }
        .bhy-cz-rules-editor .bhy-css-prop-row { display: flex; flex-direction: column; gap: 4px; padding-bottom: 8px; border-bottom: 1px solid #f0f0f1; }
        .bhy-cz-rules-editor .bhy-css-prop-row:last-child { border-bottom: none; padding-bottom: 0; }
        .bhy-cz-rules-editor .bhy-prop-select,
        .bhy-cz-rules-editor .bhy-prop-control-slot,
        .bhy-cz-rules-editor .bhy-prop-control-slot input,
        .bhy-cz-rules-editor .bhy-prop-control-slot select { width: 100%; max-width: 100%; box-sizing: border-box; }
        .bhy-cz-rules-editor .bhy-remove-rule,
        .bhy-cz-rules-editor .bhy-remove-prop { align-self: flex-end; }
        </style>
        <div class="bhy-cz-rules-editor" data-setting-id="<?php echo esc_attr($this->id); ?>">
            <?php if ($this->label) : ?>
                <span class="customize-control-title"><?php echo esc_html($this->label); ?></span>
            <?php endif; ?>
            <?php if ($this->description) : ?>
                <span class="description customize-control-description"><?php echo wp_kses_post($this->description); ?></span>
            <?php endif; ?>
            <div class="bhy-cz-rules-list"></div>
            <button type="button" class="button bhy-cz-add-rule">+ Add rule</button>
        </div>
        <?php
    }

    public function enqueue() {
        if (self::$script_enqueued) return;
        self::$script_enqueued = true;
        wp_enqueue_script('bhy-customizer-css-rules', OUS_URL . 'assets/js/customizer-css-rules-control.js', ['customize-controls'], OUS_VER, true);
        wp_localize_script('bhy-customizer-css-rules', 'bhyCssPropertyRegistry', BHY_Style::css_property_registry());
        wp_localize_script('bhy-customizer-css-rules', 'bhyCssStateOptions', BHY_Style::css_state_options());
    }
}

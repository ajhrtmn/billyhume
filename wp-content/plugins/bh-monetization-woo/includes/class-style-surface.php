<?php
if (!defined('ABSPATH')) exit;

/**
 * Registers a representative preview of the tier picker into the core's
 * Style gallery — same mechanism every other plugin in this ecosystem
 * uses (bh-contest's player, bh-streaming's library, bh-registry's
 * browse page).
 */
class BHM_StyleSurface {
    public static function init() {
        add_filter('bhy_style_surfaces', [self::class, 'register']);
    }

    public static function register($surfaces) {
        $surfaces['bh-monetization-tiers'] = [
            'group' => 'Monetization', 'label' => 'Supporter Tiers',
            'render' => [self::class, 'preview'],
        ];
        return $surfaces;
    }

    public static function preview() {
        ob_start();
        ?>
<div class="bhm-tier-grid">
    <div class="bhm-tier-card">
        <h3>Fan</h3>
        <div class="bhm-tier-price">$3.00/mo</div>
        <p class="bhm-tier-benefits">Early access to new releases.</p>
        <a class="bhm-btn" href="#">Join</a>
    </div>
    <div class="bhm-tier-card bhm-tier-active">
        <h3>Supporter</h3>
        <div class="bhm-tier-price">$8.00/mo</div>
        <p class="bhm-tier-benefits">Everything in Fan, plus exclusive tracks and streaming-library access.</p>
        <span class="bhm-badge">Your current tier</span>
    </div>
</div>
<div class="bhm-paywall" style="margin-top:16px;">
    <p class="bhm-paywall-title">This content is for <strong>Supporter</strong> supporters and above.</p>
    <p class="bhm-paywall-benefits">Everything in Fan, plus exclusive tracks and streaming-library access.</p>
    <a class="bhm-btn" href="#">Become a supporter</a>
</div>
        <?php
        return ['css_url' => BHM_URL . 'assets/css/frontend.css', 'html' => ob_get_clean()];
    }
}

<?php
if (!defined('ABSPATH')) exit;

/**
 * Tier picker preview for the core Style gallery (bhy_style_surfaces).
 */
class BHM_StyleSurface {
    public static function init(): void {
        add_filter('bhy_style_surfaces', [self::class, 'register']);
    }

    /**
     * @param array<string, mixed> $surfaces
     * @return array<string, mixed>
     */
    public static function register($surfaces): array {
        $surfaces['bh-monetization-tiers'] = [
            'group' => 'Monetization', 'label' => BHM_Words::get('admin.frontend.supporter_tiers'),
            'render' => [self::class, 'preview'],
        ];
        return $surfaces;
    }

    /** @return array{css_url:string, html:string|false} */
    public static function preview(): array {
        ob_start();
        ?>
<div class="bhm-tier-grid">
    <div class="bhm-tier-card">
        <h3><?php echo BHM_Words::get('admin.style_surface.fan'); ?></h3>
        <div class="bhm-tier-price">$3.00/mo</div>
        <p class="bhm-tier-benefits"><?php echo BHM_Words::get('admin.style_surface.early_access_new_releases'); ?></p>
        <a class="bhm-btn" href="#"><?php echo BHM_Words::get('admin.style_surface.join'); ?></a>
    </div>
    <div class="bhm-tier-card bhm-tier-active">
        <h3><?php echo BHM_Words::get('admin.style_surface.supporter'); ?></h3>
        <div class="bhm-tier-price">$8.00/mo</div>
        <p class="bhm-tier-benefits"><?php echo BHM_Words::get('admin.style_surface.everything_fan_plus_exclusive_tracks'); ?></p>
        <span class="bh-badge bhm-badge"><?php echo BHM_Words::get('admin.style_surface.current_tier'); ?></span>
    </div>
</div>
<div class="bhm-paywall" style="margin-top:16px;">
    <p class="bhm-paywall-title"><?php echo BHM_Words::get('admin.style_surface.content_supporter_supporters_above'); ?></p>
    <p class="bhm-paywall-benefits"><?php echo BHM_Words::get('admin.style_surface.everything_fan_plus_exclusive_tracks'); ?></p>
    <a class="bhm-btn" href="#"><?php echo BHM_Words::get('admin.style_surface.become_supporter'); ?></a>
</div>
        <?php
        return ['css_url' => BHM_URL . 'assets/css/frontend.css', 'html' => ob_get_clean()];
    }
}

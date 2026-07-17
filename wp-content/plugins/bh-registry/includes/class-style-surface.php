<?php
if (!defined('ABSPATH')) exit;

/**
 * Registers a representative preview of the public browse/search UI
 * into the core's Style gallery — same mechanism bh-contest and
 * bh-streaming already use, proving the pattern holds for a third,
 * independently-installable plugin.
 */
class BHR_StyleSurface {
    public static function init() {
        add_filter('bhy_style_surfaces', [self::class, 'register']);
    }

    public static function register($surfaces) {
        $surfaces['bh-registry-browse'] = [
            'group'  => 'Registry',
            'label'  => 'Browse & Search',
            'render' => [self::class, 'preview'],
        ];
        return $surfaces;
    }

    public static function preview() {
        ob_start();
        ?>
<div class="bhr-app">
    <div class="bhr-search-row">
        <input type="text" class="bhr-search" placeholder="Search artists…" value="Nova">
        <select class="bhr-protocol-filter">
            <option>All protocols</option>
            <option>ActivityPub</option>
            <option>RSS / Podcasting 2.0</option>
        </select>
    </div>
    <div class="bhr-grid">
        <div class="bhr-card">
            <div class="bhr-card-avatar" style="background:var(--bh-accent);"></div>
            <div class="bhr-card-name">Nova Bloom</div>
            <div class="bhr-card-links">
                <span class="bhr-badge bhr-badge-verified">&#10003; ActivityPub</span>
            </div>
        </div>
        <div class="bhr-card">
            <div class="bhr-card-avatar" style="background:var(--bh-accent-soft);"></div>
            <div class="bhr-card-name">Echo Parade</div>
            <div class="bhr-card-links">
                <span class="bhr-badge bhr-badge-verified">&#10003; RSS Feed</span>
            </div>
        </div>
    </div>
</div>
        <?php
        return ['css_url' => BHR_URL . 'assets/css/registry.css', 'html' => ob_get_clean()];
    }
}

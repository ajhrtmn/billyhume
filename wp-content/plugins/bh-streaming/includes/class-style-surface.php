<?php
if (!defined('ABSPATH')) exit;

/**
 * Registers a representative preview of this plugin's own UI into
 * bh-style's gallery — same extension mechanism bh-contest uses, proving
 * the pattern actually works for more than one consuming plugin.
 */
class BHS_StyleSurface {
    public static function init() {
        add_filter('bhy_style_surfaces', [self::class, 'register']);
    }

    public static function register($surfaces) {
        $surfaces['bh-streaming-library'] = [
            'group' => 'Streaming', 'label' => 'Library & Now Playing',
            'render' => [self::class, 'preview'],
        ];
        return $surfaces;
    }

    public static function preview() {
        ob_start();
        ?>
<div class="bhs-app" style="min-height:0;padding-bottom:90px;">
    <div class="bhs-topbar">
        <div class="bhs-tabs">
            <button type="button" class="bhs-tab active">All Tracks</button>
            <button type="button" class="bhs-tab">Releases</button>
            <button type="button" class="bhs-tab">Liked Songs</button>
        </div>
    </div>
    <div class="bhs-main">
        <div class="bhs-library">
            <div class="bhs-grid">
                <div class="bhs-card">
                    <div class="bhs-card-art" style="background:var(--bh-accent);"></div>
                    <div class="bhs-card-title">Midnight Static</div>
                    <div class="bhs-card-artist">Nova Bloom</div>
                    <div class="bhs-card-meta">128 plays</div>
                </div>
                <div class="bhs-card">
                    <div class="bhs-card-art" style="background:var(--bh-accent-soft);"></div>
                    <div class="bhs-card-title">Glass Horizon</div>
                    <div class="bhs-card-artist">Echo Parade</div>
                    <div class="bhs-card-meta">96 plays</div>
                </div>
            </div>
        </div>
    </div>
    <div class="bhs-nowplaying">
        <div class="bhs-np-art" style="width:48px;height:48px;border-radius:4px;background:var(--bh-accent);"></div>
        <div class="bhs-np-info">
            <div class="bhs-np-title">Midnight Static</div>
            <div class="bhs-np-artist">Nova Bloom</div>
        </div>
        <div class="bhs-np-controls">
            <button type="button">&#9198;</button>
            <button type="button">&#10074;&#10074;</button>
            <button type="button">&#9197;</button>
            <button type="button" class="bhs-like-btn liked">&#9829;</button>
        </div>
    </div>
</div>
        <?php
        return ['css_url' => BHS_URL . 'assets/css/player.css', 'html' => ob_get_clean()];
    }
}

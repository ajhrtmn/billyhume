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
        // Design Suite gallery gap: the PRO Registration wizard
        // (BHS_PROWizard, built this session) is a real wp-admin
        // screen, not player.css's own custom classes — WP core's
        // common.min.css is the correct css_url here, same reasoning
        // as own-ur-shit's new OUS_StyleSurface for the media wizard.
        $surfaces['bh-streaming-pro-wizard'] = [
            'group' => 'Streaming', 'label' => 'PRO Registration wizard',
            'render' => [self::class, 'pro_wizard_preview'],
        ];
        return $surfaces;
    }

    public static function pro_wizard_preview() {
        ob_start();
        ?>
<div class="wrap" style="background:#f0f0f1;color:#1d2327;padding:16px;margin:0;">
    <h1>PRO Registration</h1>
    <div class="notice notice-success" style="padding:12px;"><p><strong>On file:</strong> BMI &mdash; Affiliated, membership confirmed (IPI/CAE: 123456789)</p></div>

    <h2>1. Pick a PRO</h2>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:20px;max-width:640px;">
        <div style="border:1px solid #dcdcde;border-radius:8px;padding:12px 14px;background:#fff;">
            <strong>ASCAP</strong> <span style="background:#1DB954;color:#fff;font-size:11px;padding:2px 8px;border-radius:999px;">Open signup</span>
            <p class="description" style="margin:6px 0;">Open direct signup for songwriters.</p>
            <p><a class="button" href="#">&rarr; ASCAP</a></p>
        </div>
        <div style="border:1px solid #dcdcde;border-radius:8px;padding:12px 14px;background:#fff;">
            <strong>SESAC</strong> <span style="background:#787c82;color:#fff;font-size:11px;padding:2px 8px;border-radius:999px;">Invitation-only</span>
            <p class="description" style="margin:6px 0;">Does not accept unsolicited applications.</p>
        </div>
    </div>

    <h2>2. Once you've registered, record it here</h2>
    <p><label style="display:block;font-weight:600;margin-bottom:4px;">IPI/CAE number<br><input type="text" value="123456789" style="width:100%;max-width:300px;"></label></p>
    <p><button class="button button-primary">Save</button></p>
</div>
        <?php
        return ['css_url' => admin_url('css/common.min.css'), 'html' => ob_get_clean()];
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

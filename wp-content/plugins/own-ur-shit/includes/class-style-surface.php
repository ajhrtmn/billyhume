<?php
if (!defined('ABSPATH')) exit;

/**
 * Design Suite gallery gap, caught in the same pass that fixed the
 * gallery's own character-decoding bug: own-ur-shit had registered
 * ZERO `bhy_style_surfaces` entries for its own admin surfaces —
 * every "it just works" wizard built this session (OUS_MediaWizard;
 * BH_ContestWizard and BHS_PROWizard register their own surfaces in
 * their own plugins) was completely invisible to the token editor,
 * meaning changing brand colors/fonts never let you see how they'd
 * actually look on a real guided-setup screen.
 *
 * These wizards are real wp-admin pages (`.wrap`, `.button`,
 * `.notice` — WordPress core's own admin chrome, not a custom
 * stylesheet this plugin ships), so the preview loads WP core's own
 * `common.min.css` as its css_url rather than pointing at a plugin
 * asset that doesn't exist — the same file every real wp-admin page
 * already loads, so the preview's buttons/notices look exactly like
 * the real screen, not an approximation.
 *
 * Real contrast bug: class-style-gallery.php's own
 * preview_doc() sets `:host{color:var(--bh-text)}` so every OTHER
 * surface (which uses the ecosystem's own dark brand theme) gets
 * readable text automatically — but this wizard fakes a real,
 * genuinely LIGHT wp-admin page, and inherited that same --bh-text
 * token unchanged. On the default dark theme, --bh-text is a light
 * color meant for a dark background, so unstyled text here rendered
 * light-on-light against this preview's own #f0f0f1 background —
 * fixed by setting a real, explicit wp-admin text color here rather
 * than relying on the inherited brand-theme token, since a genuinely
 * light-mode wp-admin page was never supposed to follow the brand
 * theme's dark-mode text color in the first place.
 *
 * Same reasoning applied to font-family: this preview inherited
 * `:host{font-family:var(--bh-font-body)}`
 * same as every real brand surface, so picking an exotic Display/Body
 * font in the Typography controls made this wp-admin-style preview
 * render in that font too — a real wp-admin screen never does that
 * regardless of the artist's brand font choice, it always uses
 * WordPress's own system font stack. Set explicitly here rather than
 * inherited, so the Typography controls correctly do nothing to this
 * surface's font — same "this is genuinely light-mode wp-admin, not a
 * themeable brand surface" posture as the color fix above.
 */
class OUS_StyleSurface {
    public static function init() {
        add_filter('bhy_style_surfaces', [self::class, 'register']);
    }

    public static function register($surfaces) {
        $surfaces['ous-media-wizard'] = [
            'group' => 'Own Ur Shit',
            'label' => 'Media & CDN Setup wizard',
            'render' => [self::class, 'media_wizard_preview'],
        ];
        return $surfaces;
    }

    private static function css_url() {
        return admin_url('css/common.min.css');
    }

    public static function media_wizard_preview() {
        ob_start();
        ?>
<div class="wrap" style="background:#f0f0f1;color:#1d2327;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Oxygen-Sans,Ubuntu,Cantarell,'Helvetica Neue',sans-serif;padding:16px;margin:0;">
    <h1>Media &amp; CDN Setup</h1>
    <div class="notice notice-success" style="padding:12px;"><p><strong>Currently connected:</strong> Cloudflare R2. New uploads offload automatically.</p></div>

    <h2>1. Choose a provider</h2>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:20px;max-width:640px;">
        <label style="display:block;border:2px solid var(--wp-admin-theme-color,#2271b1);border-radius:8px;padding:12px 14px;cursor:pointer;background:#fff;">
            <input type="radio" checked> <strong>Cloudflare R2</strong> <span style="background:#2271b1;color:#fff;font-size:11px;padding:2px 8px;border-radius:999px;">Recommended</span>
            <p class="description" style="margin:6px 0 0;">No egress fees at all — the standout choice for video specifically.</p>
        </label>
        <label style="display:block;border:2px solid #dcdcde;border-radius:8px;padding:12px 14px;cursor:pointer;background:#fff;">
            <input type="radio"> <strong>Amazon S3</strong>
            <p class="description" style="margin:6px 0 0;">The most widely documented option, pairs with CloudFront for a CDN.</p>
        </label>
    </div>

    <h2>2. Enter your credentials</h2>
    <div style="border:1px solid #dcdcde;border-radius:8px;padding:16px;margin-bottom:16px;background:#fff;max-width:480px;">
        <p><a class="button" href="#">&rarr; Get your Cloudflare R2 credentials</a></p>
        <p><label style="display:block;font-weight:600;margin-bottom:4px;">Access Key ID<br>
        <input type="text" value="••••••••••••" style="width:100%;"></label></p>
    </div>

    <p><button class="button button-primary button-hero">Save &amp; test connection</button></p>
</div>
        <?php
        return ['css_url' => self::css_url(), 'html' => ob_get_clean()];
    }
}

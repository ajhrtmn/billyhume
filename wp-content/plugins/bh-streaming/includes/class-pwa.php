<?php
if (!defined('ABSPATH')) exit;

/**
 * Everything needed to make this installable as a real PWA, and to
 * minimize the work left over when it's eventually wrapped for the App
 * Store/Play Store — icon generation especially, since normally that's
 * a tedious manual export of a dozen-plus specific sizes.
 *
 * Icon sizes here cover what the web manifest and iOS actually ask for
 * today. The FULL native icon set Xcode wants at build time (an
 * App Store 1024×1024 icon plus many more specific in-app sizes) is a
 * separate, later step once real Capacitor wrapping starts — generating
 * those now, before that pipeline exists to consume them, would be
 * guessing at a shape we don't need yet.
 */
class BHS_PWA {
    // The manual "upload a source image, generate every icon size" admin
    // page was removed (not worth the admin surface right now) — these
    // sizes still describe what the manifest/iOS ask for, but nothing
    // currently populates the bhs_icons option they'd be looked up from.
    // icon_url() below simply returns '' until something does, and
    // print_head_tags()/get_manifest() already handle that gracefully
    // (a PWA without a custom icon still installs fine, just with a
    // generic one).
    const ICON_SIZES = [512, 192, 180, 144, 96, 72, 48];

    public static function init() {
        // No admin_post/admin_menu hooks here anymore — see the note
        // above the icon generation section below.
    }

    public static function register_routes() {
        register_rest_route('bhs/v1', '/manifest.json', [
            'methods' => 'GET', 'callback' => [self::class, 'get_manifest'], 'permission_callback' => '__return_true',
        ]);
        register_rest_route('bhs/v1', '/sw.js', [
            'methods' => 'GET', 'callback' => [self::class, 'get_service_worker'], 'permission_callback' => '__return_true',
        ]);
    }

    /* ---------- manifest ---------- */

    public static function get_manifest() {
        $name = get_bloginfo('name') ?: 'Streaming';
        $icons = [];
        foreach (self::ICON_SIZES as $size) {
            $url = self::icon_url($size);
            if ($url) $icons[] = ['src' => $url, 'sizes' => "{$size}x{$size}", 'type' => 'image/png'];
        }

        $manifest = [
            'name'             => $name,
            'short_name'       => mb_substr($name, 0, 12),
            'start_url'        => home_url('/'),
            'display'          => 'standalone',
            'background_color' => '#000000',
            'theme_color'      => '#000000',
            'icons'            => $icons,
        ];

        $response = new WP_REST_Response($manifest, 200);
        $response->header('Content-Type', 'application/manifest+json');
        return $response;
    }

    /* ---------- service worker ---------- */

    // Deliberately minimal: caches the app shell (not audio files — those
    // are large and streamed, not something to blanket-precache) so the
    // player UI itself loads instantly and the manifest's installability
    // checks pass. Served through REST rather than a static file so
    // Service-Worker-Allowed can be set explicitly below — that header
    // is what lets a worker served from a plugin subpath still claim the
    // whole site as its scope, without needing a rewrite rule.
    public static function get_service_worker() {
        $js = "
const BHS_CACHE = 'bhs-shell-v" . BHS_VER . "';
self.addEventListener('install', function (e) { self.skipWaiting(); });
self.addEventListener('activate', function (e) { self.clients.claim(); });
self.addEventListener('fetch', function (e) {
    // Network-first for everything — this is a streaming app, staleness
    // is worse than an extra request. Caching here is about installability
    // and instant repeat loads, not offline playback.
    e.respondWith(fetch(e.request).catch(function () { return caches.match(e.request); }));
});
";
        $response = new WP_REST_Response($js, 200);
        $response->header('Content-Type', 'application/javascript');
        $response->header('Service-Worker-Allowed', '/');
        return $response;
    }

    /* ---------- icon generation ---------- */

    private static function icon_url($size) {
        $icons = get_option('bhs_icons', []);
        return $icons[$size] ?? '';
    }

    // A generated placeholder so a track without its own artwork never
    // shows a broken image — a plain, theme-neutral gradient square
    // rather than nothing.
    public static function placeholder_artwork_url() {
        return self::icon_url(512) ?: BHS_URL . 'assets/img/placeholder.svg';
    }

    /* ---------- head tags ---------- */

    public static function print_head_tags() {
        $manifest_url = rest_url('bhs/v1/manifest.json');
        $icon180 = self::icon_url(180);
        echo "\n<!-- BH Streaming PWA -->\n";
        echo '<link rel="manifest" href="' . esc_url($manifest_url) . '">' . "\n";
        echo '<meta name="theme-color" content="#000000">' . "\n";
        // iOS ignores the manifest for most of this — these three tags
        // are what actually make "Add to Home Screen" behave like an
        // app on iOS specifically, independent of manifest support.
        echo '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
        echo '<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">' . "\n";
        if ($icon180) echo '<link rel="apple-touch-icon" href="' . esc_url($icon180) . '">' . "\n";
        ?>
        <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function () {
                navigator.serviceWorker.register('<?php echo esc_url_raw(rest_url('bhs/v1/sw.js')); ?>').catch(function () { /* not fatal — the app still works without it, just without install-prompt eligibility */ });
            });
        }
        </script>
        <?php
    }
}

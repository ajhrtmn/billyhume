<?php
if (!defined('ABSPATH')) exit;

/**
 * PWA support: manifest, service worker, and iOS head tags. Icon sizes cover
 * the web manifest and iOS; the full native app icon set is out of scope.
 */
class BHS_PWA {
    // Nothing currently populates the bhs_icons option, so icon_url() returns
    // '' and the manifest/head tags fall back gracefully.
    const ICON_SIZES = [512, 192, 180, 144, 96, 72, 48];

    public static function init(): void {
    }

    public static function register_routes(): void {
        register_rest_route('bhs/v1', '/manifest.json', [
            'methods' => 'GET', 'callback' => [self::class, 'get_manifest'], 'permission_callback' => '__return_true',
        ]);
        register_rest_route('bhs/v1', '/sw.js', [
            'methods' => 'GET', 'callback' => [self::class, 'get_service_worker'], 'permission_callback' => '__return_true',
        ]);
    }

    /* ---------- manifest ---------- */

    public static function get_manifest(): \WP_REST_Response {
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

    // Caches the app shell only (never audio). Served through REST so the
    // Service-Worker-Allowed header can grant site-wide scope from a plugin path.
    public static function get_service_worker(): \WP_REST_Response {
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

    private static function icon_url(int $size): string {
        $icons = get_option('bhs_icons', []);
        return $icons[$size] ?? '';
    }

    // Gradient placeholder so a track without artwork never shows a broken image.
    public static function placeholder_artwork_url(): string {
        return self::icon_url(512) ?: BHS_URL . 'assets/img/placeholder.svg';
    }

    /* ---------- head tags ---------- */

    public static function print_head_tags(): void {
        $manifest_url = rest_url('bhs/v1/manifest.json');
        $icon180 = self::icon_url(180);
        echo "\n<!-- BH Streaming PWA -->\n";
        echo '<link rel="manifest" href="' . esc_url($manifest_url) . '">' . "\n";
        echo '<meta name="theme-color" content="#000000">' . "\n";
        // iOS ignores most of the manifest; these tags make Add to Home Screen work there.
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

<?php
/**
 * BHY_View — the ecosystem's template layer, wrapping Timber/Twig.
 *
 * Peers reach templates through here rather than touching Timber directly,
 * for the usual reason: the core is the only thing every plugin may depend
 * on, and this keeps the engine swappable behind one seam.
 *
 * Degrades rather than fatals. Timber ships in this plugin's vendor/, built
 * by the deploy workflow — but if that directory is missing (a partial
 * upload, a clone without the build), is_available() returns false and
 * callers fall back to their existing PHP rendering instead of white-screening.
 *
 * @package Own_Ur_Shit
 */
if (!defined('ABSPATH')) exit;

final class BHY_View {

    /** @var bool|null memoised so the filesystem is not re-checked per render */
    private static $available = null;

    /** @var array<string,string> plugin slug => absolute views/ path */
    private static $namespaces = [];

    public static function init(): void {
        if (!self::is_available()) return;

        // Register the core's own views, then let peers add theirs. Same
        // extension-point shape as ous_debug_tools / bhy_style_surfaces.
        self::add_namespace('ous', OUS_PATH . 'views');

        /**
         * Register a plugin's template directory.
         *
         * add_filter('bhy_view_namespaces', fn($ns) => $ns + ['bhc' => BHC_PATH . 'views']);
         *
         * @param array<string,string> $namespaces slug => absolute path
         */
        self::$namespaces = apply_filters('bhy_view_namespaces', self::$namespaces);

        // Filters registered before init(): Timber builds its Twig environment
        // during that call, so a filter added afterwards can miss it. Ordering
        // here is cheap insurance, not a fix for an observed bug.
        add_filter('timber/locations', [self::class, 'register_locations']);
        add_filter('timber/twig/environment/options', [self::class, 'twig_options']);
        Timber\Timber::init();
    }

    /**
     * Turn Twig auto-escaping ON explicitly.
     *
     * Timber ships `'autoescape' => false` in its own Twig environment
     * defaults (vendor/timber/timber/src/Loader.php). Twig 3 appears to
     * escape anyway in practice — verified live: rendering `{{ label }}`
     * with `<script>x</script>` through Timber produced escaped output
     * byte-identical to a directly-constructed Twig environment. So this
     * filter is belt-and-braces rather than the thing that makes escaping
     * work today.
     *
     * It stays because relying on that is relying on an undocumented
     * coincidence between two libraries' defaults, on the one behaviour
     * that matters most: auto-escaping is the main reason this engine was
     * adopted at all, and it protects output built from public,
     * unauthenticated input. Stating it explicitly costs one filter.
     *
     * Anything that genuinely needs raw markup must say so with Twig's
     * `|raw` filter, which is greppable in review.
     *
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public static function twig_options(array $options): array {
        $options['autoescape'] = 'html';
        return $options;
    }

    public static function add_namespace(string $slug, string $path): void {
        self::$namespaces[$slug] = untrailingslashit($path);
    }

    /**
     * @param array<string,mixed> $locations
     * @return array<string,mixed>
     */
    public static function register_locations(array $locations): array {
        foreach (self::$namespaces as $slug => $path) {
            if (is_dir($path)) $locations[$slug][] = $path;
        }
        return $locations;
    }

    /** Is the template engine usable? False when vendor/ was never built. */
    public static function is_available(): bool {
        if (self::$available !== null) return self::$available;
        if (!class_exists('Timber\Timber')) {
            $autoload = OUS_PATH . 'vendor/autoload.php';
            if (is_readable($autoload)) require_once $autoload;
        }
        return self::$available = class_exists('Timber\Timber');
    }

    /**
     * Render a template to a string.
     *
     * @param string               $template e.g. '@ous/badge.twig'
     * @param array<string,mixed>  $context
     * @return string Empty string when the engine is unavailable — callers
     *                that must not lose output should check is_available().
     */
    public static function render(string $template, array $context = []): string {
        if (!self::is_available()) return '';
        return (string) Timber\Timber::compile($template, $context);
    }

    /**
     * Render straight to output.
     *
     * @param array<string,mixed> $context
     */
    public static function display(string $template, array $context = []): void {
        echo self::render($template, $context); // phpcs:ignore WordPress.Security.EscapeOutput -- Twig auto-escapes.
    }
}

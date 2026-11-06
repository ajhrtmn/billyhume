<?php
/**
 * BHY_View: the ecosystem's template layer, wrapping Timber/Twig behind one
 * seam. Degrades rather than fatals: if vendor/ is missing, is_available()
 * is false and callers fall back to PHP rendering.
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

        // Registered before Timber builds its Twig environment.
        add_filter('timber/locations', [self::class, 'register_locations']);
        add_filter('timber/twig/environment/options', [self::class, 'twig_options']);
        Timber\Timber::init();
    }

    /**
     * Turn Twig auto-escaping ON explicitly. WHY: Timber's defaults say
     * 'autoescape' => false; escaping works today by coincidence, and it is the
     * main reason this engine is used. Raw output must use the greppable `|raw`.
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

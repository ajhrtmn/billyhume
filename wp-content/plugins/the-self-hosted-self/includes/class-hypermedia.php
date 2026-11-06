<?php
if (!defined('ABSPATH')) exit;

/**
 * Vendored Datastar (assets/js/vendor/datastar.js, MIT) for interactive admin UI, plus SSE
 * response helpers. Deliberately not a dependency on the HyperPress plugin.
 *
 *   if (class_exists('OUS_Hypermedia')) OUS_Hypermedia::enqueue();   // on admin_enqueue_scripts
 *   <button data-on:click="@get('/wp-json/my/v1/thing')">...</button>
 *
 *   OUS_Hypermedia::sse_headers();
 *   OUS_Hypermedia::patch_elements('<div id="result">Done</div>');
 *   exit;
 */
class OUS_Hypermedia {
    const ASSET_VERSION = '1.0.2'; // the vendored Datastar release tag — bump both together

    public static function init(): void {
        // WHY: Datastar is an ES module and needs type="module"; the filter works on WP versions
        // that lack wp_enqueue_script()'s newer 'strategy' argument.
        add_filter('script_loader_tag', [self::class, 'add_module_type'], 10, 2);
    }

    public static function enqueue(): void {
        wp_enqueue_script(
            'ous-datastar',
            OUS_URL . 'assets/js/vendor/datastar.js',
            [],
            self::ASSET_VERSION,
            true
        );
    }

    public static function add_module_type(string $tag, string $handle): string {
        if ($handle !== 'ous-datastar') return $tag;
        if (strpos($tag, 'type=') !== false) return $tag; // already has a type attribute, don't double up
        return str_replace(' src=', ' type="module" src=', $tag);
    }

    /**
     * Sends SSE headers and disables output buffering, which shared hosting often enables
     * by default and would turn the stream into one delayed response.
     */
    public static function sse_headers(): void {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no'); // nginx-specific, harmless elsewhere: disables proxy buffering
        while (ob_get_level() > 0) ob_end_flush();
        @ob_implicit_flush(true);
    }

    /**
     * Writes one 'datastar-patch-elements' SSE event; $html is split into one 'data: elements'
     * line per newline, since a raw newline inside a data: value is invalid SSE.
     *
     * @param string $html
     * @param string|null $selector CSS selector — omit to let Datastar infer it from the element's own id.
     * @param string|null $mode 'outer'|'inner'|'replace'|'prepend'|'append'|'before'|'after'|'remove' — omit for the default (outer).
     */
    public static function patch_elements(string $html, ?string $selector = null, ?string $mode = null): void {
        echo "event: datastar-patch-elements\n";
        foreach (explode("\n", (string) $html) as $line) {
            echo 'data: elements ' . $line . "\n";
        }
        if ($selector) echo 'data: selector ' . $selector . "\n";
        if ($mode) echo 'data: mode ' . $mode . "\n";
        echo "\n";
        flush();
    }

    /**
     * Writes one 'datastar-patch-signals' event with $signals JSON-encoded.
     *
     * @param array<string, mixed> $signals
     */
    public static function patch_signals(array $signals, bool $only_if_missing = false): void {
        echo "event: datastar-patch-signals\n";
        echo 'data: signals ' . wp_json_encode($signals) . "\n";
        if ($only_if_missing) echo "data: onlyIfMissing true\n";
        echo "\n";
        flush();
    }
}

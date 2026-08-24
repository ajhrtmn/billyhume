<?php
if (!defined('ABSPATH')) exit;

/**
 * OUS_Hypermedia — Phase 2 of the OSS-integration master plan. Vendors
 * Datastar (assets/js/vendor/datastar.js, v1.0.2, MIT, downloaded
 * directly from its official jsDelivr-hosted release bundle — the exact
 * bytes jsDelivr serves for that pinned tag, not hand-written or
 * paraphrased) as the new DEFAULT tool for interactive admin/editor UI
 * in this ecosystem, per CLAUDE.md's updated hard-conventions entry.
 *
 * Deliberately NOT a dependency on the separate HyperPress WordPress
 * plugin (github.com/EstebanForge/HyperPress) — confirmed by reading
 * its own repo structure that it's a full standalone plugin/Composer
 * library (its own admin settings page, HyperFields/HyperBlocks custom-
 * field and block-builder systems, a '/wp-html/v1/' REST namespace) —
 * taking that on whole would mean a genuinely new third-party PLUGIN
 * dependency for what this ecosystem actually needs, which is just
 * Datastar itself (a single ~34KB, zero-dependency JS file) plus a thin
 * PHP helper for the one universally-needed low-level primitive: writing
 * a correctly-formatted SSE response. HyperPress's own approach informed
 * this class's shape but isn't installed or required by it.
 *
 * "No build step, vanilla JS everywhere" (CLAUDE.md) is unchanged by
 * this — Datastar itself has no build step (a plain <script type=
 * "module"> tag), and wp.element.createElement stays valid for anything
 * simple enough not to need server-driven reactivity. See
 * ROADMAP-hyperpress-migration.md for the inventory of existing
 * wp.element-based screens and which ones are actually worth converting.
 *
 * USAGE, enqueuing on an admin screen:
 *
 *   add_action('admin_enqueue_scripts', function () {
 *       if (class_exists('OUS_Hypermedia')) OUS_Hypermedia::enqueue();
 *   });
 *
 *   Then in markup: <button data-on:click="@get('/wp-json/my/v1/thing')">...</button>
 *
 * USAGE, responding to a Datastar @get/@post fetch with a live patch:
 *
 *   OUS_Hypermedia::sse_headers();
 *   OUS_Hypermedia::patch_elements('<div id="result">Done</div>');
 *   exit;
 */
class OUS_Hypermedia {
    const ASSET_VERSION = '1.0.2'; // the exact Datastar release tag vendored below — bump both together

    public static function init(): void {
        // type="module" is REQUIRED for Datastar to execute (it's an ES
        // module, not a classic script) — WordPress's wp_enqueue_script()
        // has no built-in way to add that attribute pre-6.3, and this
        // ecosystem's minimum is PHP 7.4/no stated WP-version floor, so
        // the filter approach below works regardless of core version
        // rather than assuming the newer wp_enqueue_script() 'strategy'
        // array argument is available.
        add_filter('script_loader_tag', [self::class, 'add_module_type'], 10, 2);
    }

    public static function enqueue(): void {
        wp_enqueue_script(
            'ous-datastar',
            OUS_URL . 'assets/js/vendor/datastar.js',
            [], // zero dependencies — Datastar's own real selling point
            self::ASSET_VERSION,
            true
        );
    }

    public static function add_module_type(string $tag, string $handle): string {
        if ($handle !== 'ous-datastar') return $tag;
        if (strpos($tag, 'type=') !== false) return $tag; // already has a type attribute somehow, don't double up
        return str_replace(' src=', ' type="module" src=', $tag);
    }

    /**
     * Sets the headers a Datastar-consuming endpoint needs before
     * streaming any patch-elements/patch-signals events. Disables
     * output buffering too (a genuinely common gotcha — ordinary shared
     * hosting/PHP-FPM configs often buffer output by default, which
     * would silently turn a real SSE stream into one big delayed
     * response) since this ecosystem explicitly targets ordinary shared
     * hosting, not a curated server environment.
     */
    public static function sse_headers(): void {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no'); // nginx-specific, harmless elsewhere — prevents nginx's own proxy buffering from doing the same thing PHP's own buffering would
        while (ob_get_level() > 0) ob_end_flush();
        @ob_implicit_flush(true);
    }

    /**
     * Writes one 'datastar-patch-elements' SSE event — the exact wire
     * format Datastar's own reference documents (event: line, one or
     * more data: lines, blank-line terminator). $html is split on
     * newlines into repeated 'data: elements ...' lines per the
     * protocol's own multi-line convention — a single-line data: value
     * containing a raw newline is not valid SSE.
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

    /** Writes one 'datastar-patch-signals' event — $signals is encoded as JSON, matching the protocol's own example shape. */
    /** @param array<string, mixed> $signals */
    public static function patch_signals(array $signals, bool $only_if_missing = false): void {
        echo "event: datastar-patch-signals\n";
        echo 'data: signals ' . wp_json_encode($signals) . "\n";
        if ($only_if_missing) echo "data: onlyIfMissing true\n";
        echo "\n";
        flush();
    }
}

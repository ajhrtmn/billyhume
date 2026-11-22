<?php
if (!defined('ABSPATH')) exit;

/**
 * `wp bh …`: the command-line face of BH_Props. Every change goes through BH_Props commands,
 * so the CLI, REST and any future admin screen behave identically.
 */
class BH_Props_CLI {
    public static function register(): void {
        \WP_CLI::add_command('bh', self::class);
    }

    /**
     * Lists the property providers (one per words.php file, for now).
     *
     * ## EXAMPLES
     *     wp bh providers
     *
     * @param array<int, string> $args
     * @param array<string, string> $assoc
     */
    public function providers($args, $assoc): void {
        $rows = [];
        foreach (BH_Props::providers() as $id => $p) {
            $rows[] = ['provider' => $id, 'label' => $p['label'], 'properties' => count(BH_Props::defaults($id)), 'changed' => count(BH_Props::overrides($id))];
        }
        \WP_CLI\Utils\format_items($assoc['format'] ?? 'table', $rows, ['provider', 'label', 'properties', 'changed']);
    }

    /**
     * Lists properties and their current values.
     *
     * ## OPTIONS
     * [<provider>]
     * : Only this provider (e.g. words.courses).
     *
     * [--search=<text>]
     * : Only properties whose id, value or default contains this text.
     *
     * [--changed]
     * : Only properties that differ from the code file.
     *
     * [--format=<format>]
     * : table, json, csv or yaml. Default: table.
     *
     * ## EXAMPLES
     *     wp bh list words.courses --search="Next"
     *     wp bh list --changed
     *
     * @subcommand list
     * @param array<int, string> $args
     * @param array<string, string> $assoc
     */
    public function list_($args, $assoc): void {
        $rows = BH_Props::rows($args[0] ?? '', (string) ($assoc['search'] ?? ''), isset($assoc['changed']));
        $fields = isset($assoc['changed']) ? ['id', 'value', 'default'] : ['id', 'value', 'changed'];
        foreach ($rows as &$r) $r['changed'] = $r['changed'] ? 'yes' : '';
        \WP_CLI\Utils\format_items($assoc['format'] ?? 'table', $rows, $fields);
    }

    /**
     * Shows one property.
     *
     * ## OPTIONS
     * <id>
     * : provider:key, e.g. words.courses:lesson.next_lesson
     *
     * [--format=<format>]
     * : table or json.
     *
     * @param array<int, string> $args
     * @param array<string, string> $assoc
     */
    public function get($args, $assoc): void {
        $row = BH_Props::get($args[0]);
        if (is_wp_error($row)) \WP_CLI::error($row->get_error_message());
        $row['changed'] = $row['changed'] ? 'yes' : 'no';
        $row['at'] = $row['at'] ? gmdate('Y-m-d H:i', (int) $row['at']) . ' UTC' : '';
        \WP_CLI\Utils\format_items($assoc['format'] ?? 'yaml', [$row], ['id', 'value', 'default', 'changed', 'at']);
    }

    /**
     * Changes a property. Setting it back to the default removes the override.
     *
     * ## OPTIONS
     * <id>
     * : provider:key
     *
     * <value>
     * : The new text. Keep any {placeholders} the default has.
     *
     * [--force]
     * : Save even if the {placeholders} don't match the default.
     *
     * ## EXAMPLES
     *     wp bh set words.courses:lesson.next_lesson "Keep going →"
     *
     * @param array<int, string> $args
     * @param array<string, string> $assoc
     */
    public function set($args, $assoc): void {
        $res = BH_Props::set($args[0], $args[1], isset($assoc['force']));
        if (is_wp_error($res)) \WP_CLI::error($res->get_error_message());
        \WP_CLI::success($args[0] . ' = ' . BH_Props::get($args[0])['value']);
    }

    /**
     * Puts a property (or every property of a provider) back to the code-file default.
     *
     * ## OPTIONS
     * <id>
     * : provider:key, or just a provider with --all.
     *
     * [--all]
     * : Reset every changed property of the provider.
     *
     * @param array<int, string> $args
     * @param array<string, string> $assoc
     */
    public function reset($args, $assoc): void {
        if (isset($assoc['all'])) {
            $n = BH_Props::reset_provider($args[0]);
            \WP_CLI::success("Reset $n propert" . ($n === 1 ? 'y' : 'ies') . " in {$args[0]}.");
            return;
        }
        $res = BH_Props::reset($args[0]);
        if (is_wp_error($res)) \WP_CLI::error($res->get_error_message());
        \WP_CLI::success($args[0] . ' is back to: ' . BH_Props::get($args[0])['value']);
    }

    /**
     * Prints changed properties as JSON, to copy to another site with `wp bh import`.
     *
     * ## OPTIONS
     * [<provider>]
     * : Only this provider.
     *
     * @param array<int, string> $args
     * @param array<string, string> $assoc
     */
    public function export($args, $assoc): void {
        $out = [];
        foreach (BH_Props::rows($args[0] ?? '', '', true) as $r) $out[$r['id']] = $r['value'];
        \WP_CLI::line((string) wp_json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Sets properties from a JSON file made by `wp bh export`.
     *
     * ## OPTIONS
     * <file>
     * : Path to the JSON file.
     *
     * [--force]
     * : Skip the placeholder check.
     *
     * @param array<int, string> $args
     * @param array<string, string> $assoc
     */
    public function import($args, $assoc): void {
        $data = json_decode((string) @file_get_contents($args[0]), true);
        if (!is_array($data)) \WP_CLI::error('Could not read JSON from ' . $args[0]);
        $ok = 0;
        foreach ($data as $id => $value) {
            $res = BH_Props::set((string) $id, (string) $value, isset($assoc['force']));
            if (is_wp_error($res)) \WP_CLI::warning("$id: " . $res->get_error_message()); else $ok++;
        }
        \WP_CLI::success("Imported $ok of " . count($data) . '.');
    }

    /**
     * Writes a provider's changes into its code file (words.php) and clears them, so they
     * can be committed to git. Run it on a local copy, not the live site.
     *
     * ## OPTIONS
     * <provider>
     * : e.g. words.courses
     *
     * @param array<int, string> $args
     * @param array<string, string> $assoc
     */
    public function bake($args, $assoc): void {
        $res = BH_Props::bake($args[0]);
        if (is_wp_error($res)) \WP_CLI::error($res->get_error_message());
        foreach ($res['missing'] as $k) \WP_CLI::warning("Could not find '$k' in the file; left as an override.");
        \WP_CLI::success('Wrote ' . count($res['baked']) . ' change(s) into the code file.');
    }
}

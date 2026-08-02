<?php
if (!defined('ABSPATH')) exit;

/**
 * OUS_Integration — a light registry for CLAUDE.md's standing rule:
 * "critical infrastructure always ships with a minimal, self-hosted,
 * built-in default; a third-party integration is an enhancement layered
 * on top, never the only implementation." This class doesn't ENFORCE
 * that rule (no auto-failover, no implementation-switching UI) — it's a
 * visibility layer, so "what's the built-in default here, is anything
 * enhancing it right now, and what plugin do I install to get that"
 * is one real answer instead of something only readable by grepping
 * changelog comments.
 *
 * Deliberately thin, matching how BH_Content/BH_Commerce actually got
 * built in this codebase — extracted from real, already-working call
 * sites rather than designed in the abstract first. The first three
 * registrations below (see own-ur-shit.php, bh-crm.php, bh-mailpoet.php)
 * are retrofits of contracts that already existed and already proved
 * this shape works, not speculative new scope.
 *
 * USAGE, from any plugin's own bootstrap (typically right after the
 * class being registered as the built-in default has itself loaded):
 *
 *   add_action('init', function () {
 *       if (class_exists('OUS_Integration')) {
 *           OUS_Integration::register('email_broadcast', [
 *               'label' => 'Email broadcast / marketing',
 *               'description' => 'Reach a live-queried audience with a one-off or list-driven email.',
 *               'builtin_class' => 'OUS_Campaigns',
 *               'enhancer_class' => 'BHMP_Sync', // optional — omit or leave null if nothing enhances this yet
 *           ]);
 *       }
 *   }, 20); // after both classes involved have had a chance to load
 */
class OUS_Integration {
    /** @var array<string, array> */
    private static $contracts = [];

    public static function init() {
        add_filter('ous_debug_tools', [self::class, 'register_debug_section']);
    }

    /**
     * @param string $key Stable, namespaced identifier — e.g. 'email_broadcast', 'bh_mail'.
     * @param array $args {
     *   @type string $label           Required the FIRST time this key is registered. Short human name.
     *   @type string $description    Optional. One sentence, what this contract is for.
     *   @type string $builtin_class  Required the FIRST time this key is registered. The class that must always work, no third party needed.
     *   @type string $enhancer_class Optional. A class that, if present (class_exists()), provides a richer implementation of the same contract.
     * }
     *
     * Merges onto whatever's already registered under this key, rather
     * than overwriting wholesale — the point being zero-central-
     * registration, same shape as ous_registered_plugins/ous_debug_tools:
     * the plugin that OWNS the built-in default (typically own-ur-shit
     * itself, since most of today's defaults live in core) registers the
     * contract with label/description/builtin_class; a later, entirely
     * separate peer plugin that happens to enhance that same contract
     * only needs to call register($same_key, ['enhancer_class' => '...'])
     * — it doesn't need to know or repeat the builtin side's details.
     */
    public static function register($key, array $args) {
        $key = sanitize_key($key);
        $existing = self::$contracts[$key] ?? ['label' => $key, 'description' => '', 'builtin_class' => '', 'enhancer_class' => null];
        $merged = array_merge($existing, $args);
        if (empty($merged['builtin_class'])) return; // a contract with no working default isn't one of these, don't register it
        self::$contracts[$key] = $merged;
    }

    public static function all() {
        return self::$contracts;
    }

    /**
     * 'enhancer' if this contract has a registered enhancer class AND
     * it's actually loaded right now, 'builtin' otherwise, null if the
     * key was never registered at all. Call-time only — never cached
     * across requests, since which plugins are active can change.
     */
    public static function active_implementation($key) {
        $contract = self::$contracts[sanitize_key($key)] ?? null;
        if (!$contract) return null;
        if (!empty($contract['enhancer_class']) && class_exists($contract['enhancer_class'])) return 'enhancer';
        return 'builtin';
    }

    public static function register_debug_section($tools) {
        $tools['ous-integrations'] = [
            'label'  => 'Integration Contracts',
            'render' => [self::class, 'render_debug_section'],
            // Read-only report — nothing here writes anything, safe to
            // show even on a production install, same posture the
            // registry's own bundled-zip-freshness report takes.
            'safe_in_production' => true,
            'group'  => OUS_Debug::GROUP_MONITORING,
        ];
        return $tools;
    }

    public static function render_debug_section() {
        if (!self::$contracts) {
            echo '<p class="description">No integration contracts registered yet.</p>';
            return;
        }

        echo '<p class="description">Every registered "always-works built-in default, optional third-party enhancement" pair — see CLAUDE.md\'s standing rule. This is a status report, not a settings screen: which implementation is active is decided entirely by whether the enhancer\'s class happens to be loaded, same as every other class_exists()-guarded cross-plugin touch in this ecosystem.</p>';

        echo '<div class="bhy-table-wrap"><table class="widefat striped"><thead><tr>'
           . '<th>Contract</th><th>Built-in default</th><th>Enhancer (optional)</th><th>Active now</th>'
           . '</tr></thead><tbody>';

        foreach (self::$contracts as $key => $contract) {
            $active = self::active_implementation($key);
            $active_badge = $active === 'enhancer'
                ? '<span class="bhy-badge bhy-badge-success">' . esc_html($contract['enhancer_class']) . '</span>'
                : '<span class="bhy-badge bhy-badge-neutral">' . esc_html($contract['builtin_class']) . ' (built-in)</span>';

            $enhancer_cell = $contract['enhancer_class']
                ? esc_html($contract['enhancer_class']) . (class_exists($contract['enhancer_class']) ? '' : ' <span class="description">(not active)</span>')
                : '<span class="description">— none registered</span>';

            echo '<tr>';
            echo '<td><strong>' . esc_html($contract['label']) . '</strong>';
            if ($contract['description']) echo '<br><span class="description">' . esc_html($contract['description']) . '</span>';
            echo '</td>';
            echo '<td>' . esc_html($contract['builtin_class']) . '</td>';
            echo '<td>' . $enhancer_cell . '</td>';
            echo '<td>' . $active_badge . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }
}

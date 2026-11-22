<?php
if (!defined('ABSPATH')) exit;

/**
 * Reports which implementation is active for "built-in default + optional enhancer"
 * contracts (register on init priority 20). Reporting only; it switches nothing.
 */
class OUS_Integration {
    /** @var array<string, array<string, mixed>> */
    private static $contracts = [];

    public static function init(): void {
        add_filter('ous_debug_tools', [self::class, 'register_debug_section']);
    }

    /**
     * Merges onto any existing registration for $key, so an enhancer plugin only needs
     * register($key, ['enhancer_class' => '...']).
     *
     * @param string $key Stable, namespaced identifier — e.g. 'email_broadcast', 'bh_mail'.
     * @param array $args {
     *   @type string $label           Required the FIRST time this key is registered. Short human name.
     *   @type string $description    Optional. One sentence, what this contract is for.
     *   @type string $builtin_class  Required the FIRST time this key is registered. The class that must always work, no third party needed.
     *   @type string $enhancer_class Optional. A class that, if present (class_exists()), provides a richer implementation of the same contract.
     * }
     * @param array<string, mixed> $args
     */
    public static function register(string $key, array $args): void {
        $key = sanitize_key($key);
        $existing = self::$contracts[$key] ?? ['label' => $key, 'description' => '', 'builtin_class' => '', 'enhancer_class' => null];
        $merged = array_merge($existing, $args);
        if (empty($merged['builtin_class'])) return; // a contract with no working default isn't one of these, don't register it
        self::$contracts[$key] = $merged;
    }

    /** @return array<string, array<string, mixed>> */
    public static function all(): array {
        return self::$contracts;
    }

    /**
     * 'enhancer' if a registered enhancer class is loaded now, 'builtin' otherwise, null if
     * the key was never registered. Evaluated per call, never cached.
     */
    public static function active_implementation(string $key): ?string {
        $contract = self::$contracts[sanitize_key($key)] ?? null;
        if (!$contract) return null;
        if (!empty($contract['enhancer_class']) && class_exists($contract['enhancer_class'])) return 'enhancer';
        return 'builtin';
    }

    /**
     * @param array<string, mixed> $tools
     * @return array<string, mixed>
     */
    public static function register_debug_section($tools): array {
        $tools['ous-integrations'] = [
            'label'  => OUS_Words::get('admin.integration.integration_contracts'),
            'render' => [self::class, 'render_debug_section'],
            // Read-only report.
            'safe_in_production' => true,
            'group'  => OUS_Debug::GROUP_MONITORING,
        ];
        return $tools;
    }

    public static function render_debug_section(): void {
        if (!self::$contracts) {
            echo ('<p class="description">' . OUS_Words::get('admin.integration.no_integration_contracts_registered_yet') . '</p>');
            return;
        }

        echo '<p class="description">Every registered "always-works built-in default, optional third-party enhancement" pair — see CLAUDE.md\'s standing rule. This is a status report, not a settings screen: which implementation is active is decided entirely by whether the enhancer\'s class happens to be loaded, same as every other class_exists()-guarded cross-plugin touch in this ecosystem.</p>';

        echo '<div class="bhy-table-wrap"><table class="widefat striped"><thead><tr>'
           . ('<th>' . OUS_Words::get('admin.integration.contract') . '</th><th>' . OUS_Words::get('admin.integration.built_default') . '</th><th>' . OUS_Words::get('admin.integration.enhancer_optional') . '</th><th>Active now</th>')
           . '</tr></thead><tbody>';

        foreach (self::$contracts as $key => $contract) {
            $active = self::active_implementation($key);
            $active_badge = $active === 'enhancer'
                ? '<span class="bhy-badge bhy-badge-success">' . esc_html($contract['enhancer_class']) . '</span>'
                : '<span class="bhy-badge bhy-badge-neutral">' . esc_html($contract['builtin_class']) . ' (built-in)</span>';

            $enhancer_cell = $contract['enhancer_class']
                ? esc_html($contract['enhancer_class']) . (class_exists($contract['enhancer_class']) ? '' : (' <span class="description">' . OUS_Words::get('admin.integration.not_active') . '</span>'))
                : ('<span class="description">' . OUS_Words::get('admin.integration.none_registered') . '</span>');

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

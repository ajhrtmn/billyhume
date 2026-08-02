<?php
if (!defined('ABSPATH')) exit;

/**
 * Debug Tools section (own-ur-shit's OUS_Debug, ous_debug_tools filter)
 * rather than a standalone admin page — per CLAUDE.md's documented
 * WordPress page-hook resolution incident. Shows sync status and a
 * manual "Sync now" button.
 */
class BHMP_Debug {
    public static function init() {
        if (!class_exists('OUS_Debug')) return;
        OUS_Debug::register(
            'bh-mailpoet', 'BH MailPoet',
            [self::class, 'render_section'],
            [self::class, 'handle_action'],
            null,
            true, // safe_in_production — this section only reports status + triggers a real sync, never seeds/deletes fake data
            OUS_Debug::GROUP_MONITORING
        );
    }

    public static function render_section() {
        $mailpoet_active = class_exists('\MailPoet\API\API');
        $last_sync_at    = (int) get_option('bhmp_last_sync_at', 0);
        $last_counts     = get_option('bhmp_last_sync_counts', ['synced' => 0, 'failed' => 0]);

        echo '<p>';
        echo $mailpoet_active
            ? '<span class="bhy-badge bhy-badge-success">MailPoet detected</span>'
            : '<span class="bhy-badge bhy-badge-neutral">MailPoet not installed — sync is idle</span>';
        echo '</p>';

        if ($last_sync_at) {
            echo '<p>Last full sync: ' . esc_html(human_time_diff($last_sync_at) . ' ago')
                . ' — ' . (int) $last_counts['synced'] . ' synced, ' . (int) $last_counts['failed'] . ' failed.</p>';
        } else {
            echo '<p class="description">No full sync has run yet.</p>';
        }

        echo '<p class="description">Real-time sync also runs on registration, profile updates, WooCommerce order completion, entitlement grants, and the ecosystem\'s own event bus (votes, enrollments, wallet credits, etc.) — this button only covers the daily full-resync path manually.</p>';

        OUS_Debug::button('bh-mailpoet', 'sync_now', 'Sync now');
    }

    public static function handle_action($action, $post) {
        if ($action !== 'sync_now') return 'Unknown action.';
        $result = BHMP_Sync::sync_all();
        if ($result['skipped_no_mailpoet']) return 'MailPoet isn\'t installed — nothing to sync.';
        return "Synced {$result['synced']} contacts (" . $result['failed'] . ' failed).';
    }
}

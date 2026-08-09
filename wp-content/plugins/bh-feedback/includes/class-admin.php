<?php
if (!defined('ABSPATH')) exit;

/**
 * Audit fix (2026-07-25) — this plugin was the one peer plugin in the
 * ecosystem with no 'ous_registered_plugins' contribution/admin class at
 * all, unlike every sibling (see bh-registry/includes/class-admin.php for
 * the reference shape this mirrors), which meant it was invisible on the
 * core's own plugin-management dashboard even once active. Same pattern:
 * one admin_menus entry, relocated by the core's OUS_MenuMerge — this
 * class never calls add_submenu_page itself.
 */
class BHF_Admin {
    public static function init() {
        add_filter('ous_registered_plugins', [self::class, 'register']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
    }

    // The .bhf-badge-* classes used in render() below only get enqueued
    // on the front end (BHF_Requests::maybe_enqueue()) — this screen
    // needs the same stylesheet in wp-admin context.
    public static function enqueue($hook) {
        if ($hook !== 'own-ur-shit_page_bh-feedback-requests' && strpos((string) $hook, 'bh-feedback') === false) return;
        wp_enqueue_style('bhf-feedback', BHF_URL . 'assets/css/feedback.css', [], BHF_VER);
    }

    public static function register($plugins) {
        $plugins['bh-feedback'] = [
            'label'          => 'BH Feedback',
            'file'           => 'bh-feedback/bh-feedback.php',
            'depends_on'     => [],
            'check_class'    => 'BHF_Queue',
            'description'    => 'Paid song-feedback requests — a submitter pays via wallet credit, a reviewer with bhcore_review_submissions claims and completes it.',
            'dashboard_link' => 'admin.php?page=bh-feedback-requests',
            // Audit fix (2026-07-26): this array fully REPLACES (not
            // merges into) class-registry.php's own hardcoded DEFAULTS
            // entry for this key — self::all()'s array_merge() only
            // backfills MISSING keys with empty defaults afterward, so
            // omitting bundled_zip here silently zeroed out the bundled-
            // zip-freshness check for this plugin specifically (it read
            // as "not currently installed" instead of comparing versions).
            // bh-registry's own class-admin.php::register() is the
            // reference shape — always list bundled_zip here too, not
            // just in the hardcoded DEFAULTS this callback overwrites.
            'bundled_zip'    => 'bh-feedback.zip',
            'admin_menus'    => [
                ['slug' => 'bh-feedback-requests', 'label' => 'Feedback Requests', 'callback' => [self::class, 'render']],
            ],
        ];
        return $plugins;
    }

    // A plain overview so an admin has SOME ordinary way to see request
    // volume/status without opening the Reviewer Queue portal panel as
    // if they were a reviewer themselves — read-only, no claim/complete
    // actions here (those stay in the portal panel, reviewer-scoped).
    public static function render() {
        if (!current_user_can('manage_options')) wp_die('Not allowed.');

        echo '<div class="wrap"><h1>Feedback Requests</h1>';

        $requests = get_posts([
            'post_type' => 'bh_feedback_request', 'posts_per_page' => 50,
            'post_status' => 'publish', 'orderby' => 'date', 'order' => 'DESC',
        ]);

        if (!$requests) {
            echo '<p><em>No requests submitted yet.</em></p></div>';
            return;
        }

        echo '<div class="bhy-table-wrap"><table class="wp-list-table widefat striped"><thead><tr>'
           . '<th>Track</th><th>Submitter</th><th>Tier</th><th>Status</th><th>Submitted</th>'
           . '</tr></thead><tbody>';
        foreach ($requests as $r) {
            $author = get_userdata((int) $r->post_author);
            $status = get_post_meta($r->ID, '_bhf_status', true);
            $tier = get_post_meta($r->ID, '_bhf_tier', true);
            echo '<tr>'
               . '<td>' . esc_html($r->post_title) . '</td>'
               . '<td>' . esc_html($author ? $author->user_login : '#' . $r->post_author) . '</td>'
               . '<td>' . esc_html(class_exists('BHF_Pricing') ? BHF_Pricing::label_for($tier) : $tier) . '</td>'
               . '<td><span class="bh-badge bhf-badge bhf-badge-' . esc_attr($status) . '">' . esc_html(ucfirst($status)) . '</span></td>'
               . '<td>' . esc_html(get_the_date('M j, Y', $r)) . '</td>'
               . '</tr>';
        }
        echo '</tbody></table></div></div>';
    }
}

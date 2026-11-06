<?php
if (!defined('ABSPATH')) exit;

/**
 * Registers this plugin with the core's plugin dashboard
 * (ous_registered_plugins) and provides a read-only requests overview.
 */
class BHF_Admin {
    public static function init(): void {
        add_filter('ous_registered_plugins', [self::class, 'register']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
    }

    // The .bhf-badge-* styles are front-end only by default; enqueue here too.
    public static function enqueue(string $hook): void {
        if ($hook !== 'the-self-hosted-self_page_bh-feedback-requests' && strpos($hook, 'bh-feedback') === false) return;
        wp_enqueue_style('bhf-feedback', BHF_URL . 'assets/css/feedback.css', [], BHF_VER);
    }

    /**
     * @param array<string, mixed> $plugins
     * @return array<string, mixed>
     */
    public static function register(array $plugins): array {
        $plugins['bh-feedback'] = [
            'label'          => 'BH Feedback',
            'file'           => 'bh-feedback/bh-feedback.php',
            'depends_on'     => [],
            'check_class'    => 'BHF_Queue',
            'description'    => 'Paid song-feedback requests — a submitter pays via wallet credit, a reviewer with bhcore_review_submissions claims and completes it.',
            'dashboard_link' => 'admin.php?page=bh-feedback-requests',
            // WHY: this entry replaces the registry's DEFAULTS rather than
            // merging, so bundled_zip must be listed here too.
            'bundled_zip'    => 'bh-feedback.zip',
            'admin_menus'    => [
                ['slug' => 'bh-feedback-requests', 'label' => 'Feedback Requests', 'callback' => [self::class, 'render']],
            ],
        ];
        return $plugins;
    }

    // Read-only overview of request volume/status; claim/complete stay in the portal.
    public static function render(): void {
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

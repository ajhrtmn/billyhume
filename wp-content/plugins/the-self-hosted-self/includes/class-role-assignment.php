<?php
if (!defined('ABSPATH')) exit;

/**
 * Role-assignment admin UI framed as jobs ("Instructor", "Reviewer"), granting
 * per-user caps via add_cap()/remove_cap(). Administrators are excluded: their
 * role already has every job.
 */
class OUS_RoleAssignment {
    // Curated subset of OUS_Roles::DEFAULT_CAPS. The two restricted caps
    // (custom JS, sensitive CRM) are only in the Advanced section.
    const JOBS = [
        'instructor' => [
            'label' => 'Instructor',
            'description' => 'Manage student progress in courses.',
            'caps' => ['bhcore_manage_students'],
        ],
        'reviewer' => [
            'label' => 'Reviewer',
            'description' => 'Review submitted work (BH Feedback, instructor-graded steps).',
            'caps' => ['bhcore_review_submissions'],
        ],
        'designer' => [
            'label' => 'Designer',
            'description' => 'Use the Design Suite to edit site layout and styling.',
            'caps' => ['bhcore_design_site'],
        ],
        'crm_manager' => [
            'label' => 'CRM Manager',
            'description' => 'Manage contacts, notes, and CRM data.',
            'caps' => ['bhcore_manage_crm'],
        ],
    ];

    public static function init(): void {
        add_action('admin_menu', [self::class, 'add_menu']);
        add_filter('bhcore_test_suites', [self::class, 'register_test_suite']);
    }

    /**
     * @param array<string, mixed> $suites
     * @return array<string, mixed>
     */
    public static function register_test_suite($suites): array {
        $suites['the-self-hosted-self-roles'] = ['label' => 'The Self-Hosted Self (Roles)', 'callback' => [self::class, 'run_tests']];
        return $suites;
    }

    // Tests compute_job_diff() against a tagged fixture user, cleaned up after.
    /** @return array<int, mixed> */
    public static function run_tests(): array {
        if (!class_exists('OUS_TestRunner') || !class_exists('OUS_Debug')) return [];
        $rows = [];

        $uid = OUS_Debug::get_or_create_test_user('ous_roles_suite', false);
        $user = get_userdata($uid);
        foreach (self::JOBS['instructor']['caps'] as $cap) $user->remove_cap($cap);

        $diff = self::compute_job_diff($user, ['instructor']);
        $rows[] = OUS_TestRunner::assert_same(['instructor'], $diff['add'], 'compute_job_diff(): a job the user lacks is queued to add');
        $rows[] = OUS_TestRunner::assert_same([], $diff['remove'], 'compute_job_diff(): nothing queued to remove for a job the user does not have');

        $user->add_cap('bhcore_manage_students');
        $diff = self::compute_job_diff($user, ['instructor']);
        $rows[] = OUS_TestRunner::assert_same([], $diff['add'], 'compute_job_diff(): a job the user already fully has is not re-added (idempotent)');
        $rows[] = OUS_TestRunner::assert_same([], $diff['remove'], 'compute_job_diff(): a job still desired is not queued for removal');

        $diff = self::compute_job_diff($user, []);
        $rows[] = OUS_TestRunner::assert_same(['instructor'], $diff['remove'], 'compute_job_diff(): a job the user has but no longer wants is queued to remove');
        $rows[] = OUS_TestRunner::assert_same([], $diff['add'], 'compute_job_diff(): nothing queued to add when the desired set is empty');

        // Administrators always get an empty diff.
        $admin_id = OUS_Debug::get_or_create_test_user('ous_roles_suite_admin', false);
        $admin_user = get_userdata($admin_id);
        $admin_user->set_role('administrator');
        $diff = self::compute_job_diff($admin_user, ['instructor', 'reviewer', 'designer', 'crm_manager']);
        $rows[] = OUS_TestRunner::assert_same([], $diff['add'], 'compute_job_diff(): an administrator is never queued to gain anything');
        $rows[] = OUS_TestRunner::assert_same([], $diff['remove'], 'compute_job_diff(): an administrator is never queued to lose anything either');

        // Reset fixture users to plain subscribers.
        foreach (self::JOBS as $job) {
            foreach ($job['caps'] as $cap) $user->remove_cap($cap);
        }
        $admin_user->set_role('subscriber');

        return $rows;
    }

    public static function add_menu(): void {
        $hook = add_submenu_page('ous', 'Roles', 'Roles', 'manage_options', 'ous-roles', [self::class, 'render']);
        if (!$hook && class_exists('OUS_DebugLog')) {
            OUS_DebugLog::log('error', 'add_submenu_page() for Roles FAILED (returned false).', [
                'current_user_can(manage_options)' => current_user_can('manage_options') ? 'TRUE' : 'FALSE',
            ], 'OUS_RoleAssignment');
        }
    }

    public static function render(): void {
        if (!current_user_can('manage_options')) return;

        $message = '';
        if (isset($_POST['ous_roles_action']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ous_roles_nonce'] ?? '')), 'ous_roles_save')) {
            $message = self::handle_save();
        }
        $advanced_message = '';
        if (isset($_POST['ous_roles_advanced_action']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ous_roles_advanced_nonce'] ?? '')), 'ous_roles_advanced')) {
            $advanced_message = self::handle_advanced_save();
        }

        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';

        echo '<div class="wrap"><h1>Roles</h1>';
        if ($message) echo '<div class="notice notice-success"><p>' . esc_html($message) . '</p></div>';
        if ($advanced_message) echo '<div class="notice notice-success"><p>' . esc_html($advanced_message) . '</p></div>';
        echo '<p class="description">Grant a "job" to a specific person — this layers extra access on top of their existing account, it doesn\'t change their WordPress role. An Administrator already has every job below via their role, so admin accounts aren\'t listed here.</p>';

        self::render_search_form($search);
        self::render_jobs_table($search);
        self::render_advanced_section();

        echo '</div>';
    }

    private static function render_search_form(string $search): void {
        echo '<form method="get" style="margin:12px 0;">';
        echo '<input type="hidden" name="page" value="ous-roles">';
        echo '<input type="search" name="s" value="' . esc_attr($search) . '" placeholder="Search by name or email">';
        echo ' <button type="submit" class="button">Search</button>';
        if ($search) echo ' <a class="button" href="' . esc_url(admin_url('admin.php?page=ous-roles')) . '">Clear</a>';
        echo '</form>';
    }

    /** @return array<int, \WP_User> */
    private static function eligible_users(string $search): array {
        $args = [
            'role__not_in' => ['administrator'],
            'orderby' => 'display_name',
            'order' => 'ASC',
            'number' => 50,
        ];
        if ($search !== '') $args['search'] = '*' . $search . '*';
        return (new WP_User_Query($args))->get_results();
    }

    private static function render_jobs_table(string $search): void {
        $users = self::eligible_users($search);

        echo '<form method="post">';
        wp_nonce_field('ous_roles_save', 'ous_roles_nonce');
        echo '<input type="hidden" name="ous_roles_action" value="1">';
        echo '<input type="hidden" name="ous_rendered_user_ids" value="' . esc_attr(implode(',', wp_list_pluck($users, 'ID'))) . '">';

        echo '<table class="widefat striped"><thead><tr><th>Person</th>';
        foreach (self::JOBS as $job) {
            $tip = class_exists('BHY_UI') ? BHY_UI::tip($job['description']) : '';
            echo '<th>' . esc_html($job['label']) . $tip . '</th>';
        }
        echo '</tr></thead><tbody>';

        if (!$users) {
            echo '<tr><td colspan="' . (count(self::JOBS) + 1) . '">No matching non-admin accounts.</td></tr>';
        }
        foreach ($users as $user) {
            echo '<tr><td><strong>' . esc_html($user->display_name) . '</strong><br><span class="description">' . esc_html($user->user_email) . '</span></td>';
            foreach (self::JOBS as $key => $job) {
                $has_all = true;
                foreach ($job['caps'] as $cap) {
                    if (!$user->has_cap($cap)) { $has_all = false; break; }
                }
                echo '<td><input type="checkbox" name="ous_grant[' . (int) $user->ID . '][' . esc_attr($key) . ']" value="1"' . checked($has_all, true, false) . '></td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
        submit_button('Save');
        echo '</form>';
    }

    // Which job keys need add_cap() vs remove_cap() given the desired set.
    // Idempotent; always empty for administrators. Split out for unit testing.
    /**
     * @param \WP_User|false|null $user
     * @param array<int, string> $desired_job_keys
     * @return array{add: array<int, string>, remove: array<int, string>}
     */
    public static function compute_job_diff($user, array $desired_job_keys): array {
        $diff = ['add' => [], 'remove' => []];
        if (!$user || in_array('administrator', $user->roles, true)) return $diff;

        foreach (self::JOBS as $key => $job) {
            $should_have = in_array($key, $desired_job_keys, true);
            $has_all = true;
            foreach ($job['caps'] as $cap) {
                if (!$user->has_cap($cap)) { $has_all = false; break; }
            }
            if ($should_have && !$has_all) $diff['add'][] = $key;
            elseif (!$should_have && $has_all) $diff['remove'][] = $key;
        }
        return $diff;
    }

    private static function handle_save(): string {
        $user_ids = array_filter(array_map('intval', explode(',', (string) ($_POST['ous_rendered_user_ids'] ?? ''))));
        $grants = (array) ($_POST['ous_grant'] ?? []);
        $updated = 0;

        foreach ($user_ids as $uid) {
            $user = get_userdata($uid);
            if (!$user) continue;

            $desired = array_keys(array_filter((array) ($grants[$uid] ?? [])));
            $diff = self::compute_job_diff($user, $desired);
            foreach ($diff['add'] as $key) {
                foreach (self::JOBS[$key]['caps'] as $cap) { $user->add_cap($cap); $updated++; }
            }
            foreach ($diff['remove'] as $key) {
                foreach (self::JOBS[$key]['caps'] as $cap) { $user->remove_cap($cap); $updated++; }
            }
            if (($diff['add'] || $diff['remove']) && class_exists('OUS_Audit')) {
                OUS_Audit::log('roles_updated', 'user', $uid, ['jobs_added' => $diff['add'], 'jobs_removed' => $diff['remove']]);
            }
        }

        return $updated ? "Updated $updated capability grant(s)." : 'No changes.';
    }

    // Advanced: grant/revoke any registered capability, including the two
    // restricted ones and plugin-registered caps without a Job entry.
    /** @return array<int, string> */
    private static function all_registered_caps(): array {
        $caps = array_keys(apply_filters('bhcore_role_capabilities', class_exists('OUS_Roles') ? OUS_Roles::DEFAULT_CAPS : []));
        return array_unique($caps);
    }

    private static function render_advanced_section(): void {
        $caps = self::all_registered_caps();
        if (!$caps) return;

        echo '<h2>Advanced</h2>';
        echo '<p class="description">Grant or revoke any registered capability directly, by user ID — for the handful of capabilities deliberately left out of the Jobs table above (see each one\'s own tooltip in Debug Tools/code for why), or anything a plugin registers that has no curated job yet.</p>';
        echo '<form method="post">';
        wp_nonce_field('ous_roles_advanced', 'ous_roles_advanced_nonce');
        echo '<input type="hidden" name="ous_roles_advanced_action" value="1">';
        echo '<p><label>User ID <input type="number" name="ous_advanced_user_id" min="1" required></label></p>';
        echo '<p><label>Capability <select name="ous_advanced_cap">';
        foreach ($caps as $cap) echo '<option value="' . esc_attr($cap) . '">' . esc_html($cap) . '</option>';
        echo '</select></label></p>';
        echo '<p>';
        submit_button(__('Grant'), 'secondary', 'ous_advanced_grant', false);
        echo ' ';
        submit_button(__('Revoke'), 'secondary', 'ous_advanced_revoke', false);
        echo '</p></form>';
    }

    private static function handle_advanced_save(): string {
        $user_id = (int) ($_POST['ous_advanced_user_id'] ?? 0);
        $cap = sanitize_key($_POST['ous_advanced_cap'] ?? '');
        $user = $user_id ? get_userdata($user_id) : null;
        if (!$user || !$cap || !in_array($cap, self::all_registered_caps(), true)) return 'Invalid user or capability.';
        if (in_array('administrator', $user->roles, true)) return 'Administrators already have every registered capability.';

        $revoke = isset($_POST['ous_advanced_revoke']);
        if ($revoke) $user->remove_cap($cap);
        else $user->add_cap($cap);

        if (class_exists('OUS_Audit')) {
            OUS_Audit::log($revoke ? 'capability_revoked' : 'capability_granted', 'user', $user_id, ['cap' => $cap]);
        }

        return ($revoke ? 'Revoked ' : 'Granted ') . $cap . ' for ' . $user->display_name . '.';
    }
}

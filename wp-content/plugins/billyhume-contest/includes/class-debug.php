<?php
if (!defined('ABSPATH')) exit;

/**
 * Dev-only data seeding. Everything this class creates is tagged with
 * _bh_is_test = 1 (posts) or bh_is_test = 1 (users) so "Reset" can find
 * and remove exactly the fake data and nothing real.
 *
 * All seeding/close/publish/login-as-voter actions target ONE contest at a
 * time, picked via the dropdown at the top of the page — this lets you
 * build out several test contests side by side to exercise the
 * multi-contest flows (different windows, different shortcodes, etc).
 */
class BH_Debug {
    const FAKE_ARTISTS = ['The Night Owls', 'Static Bloom', 'Copper Wire', 'Low Tide Motel', 'Radio Silo', 'Paper Kites Union', 'Glass Harbor', 'Velvet Static', 'Dry County', 'The Understudies'];
    const FAKE_TITLES  = ['Midnight Frequency', 'Concrete Bloom', 'Slow Burn', 'Neon Ghosts', 'Last Call', 'Static & Gold', 'Borrowed Time', 'Empty Rooms', 'Feedback Loop', 'Paper Moon'];

    public static function init() {
        add_action('admin_menu', [self::class, 'add_menu']);
        add_action('admin_post_bh_debug_action', [self::class, 'handle']);
    }

    /**
     * True if this looks like a production install. wp_get_environment_type()
     * defaults to 'production' unless WP_ENVIRONMENT_TYPE is set (Local sets
     * it to 'local' automatically), so this fails safe: unknown = blocked.
     * Override with define('BH_DEBUG_TOOLS_FORCE', true) in wp-config.php
     * if you really need to seed data on a live site.
     */
    public static function is_locked() {
        if (defined('BH_DEBUG_TOOLS_FORCE') && BH_DEBUG_TOOLS_FORCE) return false;
        return !function_exists('wp_get_environment_type') || wp_get_environment_type() === 'production';
    }

    public static function add_menu() {
        add_submenu_page(
            BH_PostTypes::MENU_PARENT,
            'BH Debug Tools', '🛠 Debug Tools', 'manage_options', 'bh-debug',
            [self::class, 'render']
        );
    }

    /* ---------------- UI ---------------- */
    public static function render() {
        $notice   = isset($_GET['bh_msg']) ? sanitize_text_field(wp_unslash($_GET['bh_msg'])) : '';
        $contests = BH_Helpers::all_contests();
        $cid      = isset($_GET['contest_id']) ? (int) $_GET['contest_id'] : 0;
        if (!$cid || get_post_type($cid) !== 'bh_contest') $cid = $contests ? $contests[0]->ID : 0;

        echo '<div class="wrap"><h1>BH Debug Tools</h1>';
        $env = function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'unknown';
        if (self::is_locked()) {
            echo '<p style="color:#b3261e;"><strong>Locked.</strong> Detected environment: <code>' . esc_html($env) . '</code>. '
               . 'Seeding/reset actions are blocked because this looks like production. '
               . 'To unlock, add <code>define(\'BH_DEBUG_TOOLS_FORCE\', true);</code> to wp-config.php, or set <code>WP_ENVIRONMENT_TYPE</code> to <code>local</code>/<code>development</code>/<code>staging</code>.</p>';
        } else {
            echo '<p style="color:#1DB954;"><strong>Unlocked.</strong> Detected environment: <code>' . esc_html($env) . '</code>. Safe to seed test data.</p>';
        }
        if ($notice) echo '<div class="notice notice-success"><p>' . esc_html($notice) . '</p></div>';

        // Contest picker — every action below targets whichever contest is
        // selected here. Reloads the page with ?contest_id=X on change.
        echo '<form method="get" style="margin-bottom:16px;">'
           . '<input type="hidden" name="post_type" value="bh_contest">'
           . '<input type="hidden" name="page" value="bh-debug">'
           . '<label><strong>Target contest:</strong> <select name="contest_id" onchange="this.form.submit()">';
        if (!$contests) {
            echo '<option value="">— none yet —</option>';
        } else {
            foreach ($contests as $c) {
                $tag = get_post_meta($c->ID, '_bh_is_test', true) === '1' ? ' (test)' : '';
                echo '<option value="' . (int) $c->ID . '" ' . selected($cid, $c->ID, false) . '>'
                   . esc_html($c->post_title) . $tag . ' — ' . esc_html(ucfirst(BH_Helpers::contest_status($c->ID))) . '</option>';
            }
        }
        echo '</select></label> <noscript><button class="button">Go</button></noscript></form>';

        echo '<h2>Target contest state</h2><ul style="list-style:disc;margin-left:20px;">';
        if (!$cid) {
            echo '<li><em>No contest selected — create one below first.</em></li>';
        } else {
            echo '<li>' . esc_html(get_the_title($cid)) . ' — status: <strong>' . esc_html(ucfirst(BH_Helpers::contest_status($cid))) . '</strong></li>';
            echo '<li>Results published: ' . (get_post_meta($cid, '_bh_results_published', true) === '1' ? 'yes' : 'no') . '</li>';
            echo '<li>Submissions: ' . BH_Helpers::submission_count($cid) . ' published, ' . BH_Helpers::submission_count($cid, 'pending') . ' pending</li>';
            echo '<li>Votes cast: ' . BH_Helpers::vote_count($cid) . '</li>';
            echo '<li>Shortcode: <code>' . esc_html(BH_Helpers::shortcode_for($cid)) . '</code></li>';
        }
        echo '</ul>';

        $nonce = wp_create_nonce('bh_debug');
        $cidField = "<input type='hidden' name='contest_id' value='" . (int) $cid . "'>";
        $btn = function ($action, $label, $extra = '', $confirm = '', $needsContest = true) use ($nonce, $cidField, $cid) {
            $disabled = ($needsContest && !$cid) ? ' disabled' : '';
            $onclick = $confirm ? " onclick=\"return confirm('" . esc_js($confirm) . "')\"" : '';
            echo "<form method='post' action='" . esc_url(admin_url('admin-post.php')) . "' style='display:inline-block;margin:4px 8px 4px 0;'$onclick>";
            echo "<input type='hidden' name='action' value='bh_debug_action'>";
            echo "<input type='hidden' name='bh_action' value='" . esc_attr($action) . "'>";
            echo "<input type='hidden' name='_wpnonce' value='" . esc_attr($nonce) . "'>";
            if ($needsContest) echo $cidField;
            echo $extra;
            echo "<button class='button" . ($action === 'reset' ? ' button-secondary' : ' button-primary') . "'$disabled>" . esc_html($label) . "</button>";
            echo "</form>";
        };

        echo '<h2>1. Contest</h2><p>Always creates a brand-new test contest with voting open right now — build as many as you need to test multiple contests running side by side.</p>';
        $btn('seed_contest', 'Create a new open test contest', '', '', false);
        $btn('close_contest', 'Close voting on the target contest');

        echo '<h2>2. Submissions</h2><p>Adds fake published tracks to the <strong>target contest</strong> above, with fake artists/titles. No real audio file is attached — the player will list them and let you vote, but play will show "no audio file" (expected).</p>';
        $disabled = $cid ? '' : ' disabled';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin:4px 8px 4px 0;">';
        echo '<input type="hidden" name="action" value="bh_debug_action">';
        echo '<input type="hidden" name="bh_action" value="seed_submissions">';
        echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '">';
        echo $cidField;
        echo '<label>Count: <input type="number" name="count" value="8" min="1" max="30" style="width:60px;"' . $disabled . '></label> ';
        echo '<button class="button button-primary"' . $disabled . '>Add fake submissions</button>';
        echo '</form>';

        echo '<h2>3. Votes</h2><p>Creates fake voter accounts and casts randomized votes (respecting the 1/2-vote limit) on the target contest\'s submissions.</p>';
        $btn('seed_votes', 'Cast random test votes');

        echo '<h2>4. Results</h2><p>Toggles the "Publish Results" checkbox on the target contest.</p>';
        $btn('publish_results', 'Publish results (checkbox ON)');
        $btn('unpublish_results', 'Hide results (checkbox OFF)');

        echo '<h2>5. Test as another user</h2><p>You\'re currently viewing the player as whichever WordPress account is logged into this browser — normally your admin account. '
           . 'To see the voting UI as a fresh voter (no prior votes, no submission) <strong>on the target contest</strong>, use this. '
           . '<strong>It replaces your current login in this browser</strong> with a brand-new test account and sends you straight to that contest\'s page, so it will log you out of wp-admin too. '
           . 'Come back here afterward and log in again with your real account to keep testing admin features.</p>';
        $btn('login_as_voter', 'Log in as a new test voter →', '', 'This will log you out of your admin account in this browser and switch to a fresh test voter. Continue?');

        echo '<h2>Reset</h2><p>Deletes everything tagged as test data across <strong>all</strong> test contests: test contest(s), test submissions, test votes, and test users. Real data is untouched.</p>';
        $btn('reset', 'Wipe all test data', '', 'Delete all BH test data? This cannot be undone.', false);

        echo '</div>';
    }

    /* ---------------- actions ---------------- */
    public static function handle() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['_wpnonce'] ?? '', 'bh_debug')) {
            wp_die('Not allowed.');
        }
        $action = sanitize_key($_POST['bh_action'] ?? '');
        $cid    = (int) ($_POST['contest_id'] ?? 0);

        if (self::is_locked()) {
            $msg = 'Blocked: this looks like a production environment. Add define(\'BH_DEBUG_TOOLS_FORCE\', true) to wp-config.php to override.';
            self::redirect($cid, $msg);
        }

        // This one switches the browser's session, so it can't redirect back
        // to wp-admin like the others — the new session won't have access.
        if ($action === 'login_as_voter') {
            if (!$cid) self::redirect(0, 'Select a target contest first.');
            wp_safe_redirect(self::login_as_voter($cid));
            exit;
        }

        $msg = 'Done.';
        switch ($action) {
            case 'seed_contest':      $newId = self::seed_contest(); self::redirect($newId, 'Created a new test contest (ID ' . $newId . '), voting open now.'); break;
            case 'close_contest':     $msg = self::close_contest($cid); break;
            case 'seed_submissions':  $msg = self::seed_submissions($cid, max(1, min(30, (int) ($_POST['count'] ?? 8)))); break;
            case 'seed_votes':        $msg = self::seed_votes($cid); break;
            case 'publish_results':   $msg = self::set_results($cid, true); break;
            case 'unpublish_results': $msg = self::set_results($cid, false); break;
            case 'reset':             $msg = self::reset(); break;
        }

        self::redirect($cid, $msg);
    }

    private static function redirect($cid, $msg) {
        $url = admin_url(BH_PostTypes::MENU_PARENT . '&page=bh-debug');
        if ($cid) $url = add_query_arg('contest_id', (int) $cid, $url);
        wp_safe_redirect(add_query_arg('bh_msg', rawurlencode($msg), $url));
        exit;
    }

    private static function seed_contest() {
        $now = current_time('timestamp');
        $id = wp_insert_post([
            'post_title'  => 'Test Contest ' . gmdate('Y-m-d H:i:s'),
            'post_type'   => 'bh_contest',
            'post_status' => 'publish',
        ]);
        update_post_meta($id, '_bh_is_test', '1');
        update_post_meta($id, '_bh_start', gmdate('Y-m-d\TH:i', $now - DAY_IN_SECONDS));
        update_post_meta($id, '_bh_end', gmdate('Y-m-d\TH:i', $now + 7 * DAY_IN_SECONDS));
        wp_publish_post($id);
        return $id;
    }

    private static function close_contest($cid) {
        if (!$cid) return 'No target contest selected.';
        update_post_meta($cid, '_bh_end', gmdate('Y-m-d\TH:i', current_time('timestamp') - HOUR_IN_SECONDS));
        return 'Voting closed on "' . get_the_title($cid) . '".';
    }

    private static function seed_submissions($cid, $count) {
        if (!$cid) return 'No target contest selected.';

        for ($i = 0; $i < $count; $i++) {
            $artist = self::FAKE_ARTISTS[array_rand(self::FAKE_ARTISTS)];
            $title  = self::FAKE_TITLES[array_rand(self::FAKE_TITLES)] . ' ' . wp_rand(1, 99);

            // Reuse (or create) a lightweight test author so posts aren't all authored by the admin.
            $author_id = self::get_or_create_test_user('submitter');

            $pid = wp_insert_post([
                'post_title'  => $title,
                'post_type'   => 'bh_submission',
                'post_status' => 'publish',
                'post_author' => $author_id,
            ]);
            if (is_wp_error($pid)) continue;

            update_post_meta($pid, '_bh_is_test', '1');
            update_post_meta($pid, '_bh_contest_id', $cid);
            update_post_meta($pid, '_bh_artist_name', $artist);
            update_post_meta($pid, '_bh_play_count', wp_rand(0, 40));
            // No _bh_audio_id on purpose — no real file to attach in a seed script.
        }
        return "Added $count fake submissions to \"" . get_the_title($cid) . '".';
    }

    private static function seed_votes($cid) {
        if (!$cid) return 'No target contest selected.';

        $tracks = get_posts(['post_type' => 'bh_submission', 'post_status' => 'publish', 'meta_key' => '_bh_contest_id', 'meta_value' => $cid, 'posts_per_page' => -1, 'fields' => 'ids']);
        if (!$tracks) return 'No submissions to vote on yet in "' . get_the_title($cid) . '" — seed submissions first.';

        global $wpdb;
        $t = BH_Helpers::table();
        $voters = wp_rand(15, 30);
        $cast = 0;

        for ($i = 0; $i < $voters; $i++) {
            $uid = self::get_or_create_test_user('voter');
            $limit = BH_Helpers::vote_limit($uid, $cid); // some test users will "have submitted" if reused
            $picks = (array) array_rand(array_flip($tracks), min($limit, count($tracks)));
            foreach ($picks as $track_id) {
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM $t WHERE user_id=%d AND contest_id=%d AND submission_id=%d", $uid, $cid, $track_id
                ));
                if (!$exists) {
                    $wpdb->insert($t, ['user_id' => $uid, 'contest_id' => $cid, 'submission_id' => $track_id], ['%d', '%d', '%d']);
                    $cast++;
                }
            }
        }
        return "Cast $cast test votes from $voters fake voters on \"" . get_the_title($cid) . '".';
    }

    private static function set_results($cid, $on) {
        if (!$cid) return 'No target contest selected.';
        update_post_meta($cid, '_bh_results_published', $on ? '1' : '0');
        return ($on ? 'Results published' : 'Results hidden') . ' for "' . get_the_title($cid) . '".';
    }

    // Creates a brand-new, always-clean test voter (never reused, so it has
    // zero prior votes) and switches this browser's auth cookie to it —
    // effectively "log in as a spoofed voter" for interactive UI testing.
    // Returns the front-end URL for the TARGET contest specifically.
    private static function login_as_voter($cid) {
        $n = wp_rand(1000, 999999);
        $id = wp_insert_user([
            'user_login' => "bh_test_voter_{$n}",
            'user_email' => "bh_test_voter_{$n}@example.test",
            'user_pass'  => wp_generate_password(20),
            'role'       => 'subscriber',
        ]);
        if (is_wp_error($id)) {
            return add_query_arg(['bh_msg' => rawurlencode('Could not create a test voter.'), 'contest_id' => $cid], admin_url(BH_PostTypes::MENU_PARENT . '&page=bh-debug'));
        }
        update_user_meta($id, 'bh_is_test', 'voter');

        wp_set_current_user($id);
        wp_set_auth_cookie($id, true, is_ssl());

        return self::player_page_url($cid);
    }

    // Finds a published page/post whose [bh_contest_player] shortcode
    // explicitly targets this contest (by slug or ID). Falls back to the
    // first page with the shortcode at all, then to the site home.
    private static function player_page_url($cid) {
        $slug = get_post_field('post_name', $cid);
        $candidates = get_posts(['post_type' => ['page', 'post'], 'post_status' => 'publish', 'posts_per_page' => 100, 'fields' => 'ids']);

        $fallback = '';
        foreach ($candidates as $pid) {
            $content = get_post_field('post_content', $pid);
            if (!has_shortcode($content, 'bh_contest_player')) continue;
            if (!$fallback) $fallback = get_permalink($pid);

            $pattern = '/\[bh_contest_player[^\]]*contest=["\'](' . preg_quote($slug, '/') . '|' . (int) $cid . ')["\'][^\]]*\]/i';
            if (preg_match($pattern, $content)) return get_permalink($pid);
        }
        return $fallback ?: home_url('/');
    }

    private static function get_or_create_test_user($role_tag) {
        // Reuse an existing test user of this flavor about half the time,
        // so vote-limit logic (bonus vote for submitters) gets exercised.
        $pool = get_users(['meta_key' => 'bh_is_test', 'meta_value' => $role_tag, 'fields' => 'ID', 'number' => 20]);
        if ($pool && wp_rand(0, 1)) return $pool[array_rand($pool)];

        $n = wp_rand(1000, 999999);
        $id = wp_insert_user([
            'user_login' => "bh_test_{$role_tag}_{$n}",
            'user_email' => "bh_test_{$role_tag}_{$n}@example.test",
            'user_pass'  => wp_generate_password(20),
            'role'       => 'subscriber',
        ]);
        if (is_wp_error($id)) return get_current_user_id(); // fallback, shouldn't happen
        update_user_meta($id, 'bh_is_test', $role_tag);
        return $id;
    }

    private static function reset() {
        // Test posts (contests + submissions), across ALL test contests.
        $posts = get_posts(['post_type' => ['bh_contest', 'bh_submission'], 'meta_key' => '_bh_is_test', 'meta_value' => '1', 'posts_per_page' => -1, 'post_status' => 'any', 'fields' => 'ids']);
        foreach ($posts as $pid) wp_delete_post($pid, true);

        // Test users + their votes.
        $users = get_users(['meta_key' => 'bh_is_test', 'fields' => 'ID']);
        global $wpdb;
        $t = BH_Helpers::table();
        foreach ($users as $uid) {
            $wpdb->delete($t, ['user_id' => $uid], ['%d']);
            wp_delete_user($uid);
        }

        return 'Wiped ' . count($posts) . ' test posts and ' . count($users) . ' test users (and their votes).';
    }
}

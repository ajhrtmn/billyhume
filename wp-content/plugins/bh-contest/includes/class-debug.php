<?php
if (!defined('ABSPATH')) exit;

/**
 * Registers this plugin's dev-only data seeding into the shared Debug
 * Tools page (see OUS_Debug) instead of running its own separate menu
 * page — same seeding logic as before, just reached through one shared
 * page rather than a plugin-specific one.
 *
 * Everything this creates is tagged with _bh_is_test = 1 (posts) so
 * reset() can find and remove exactly the fake data and nothing real.
 * Fake user accounts are created via the shared
 * OUS_Debug::get_or_create_test_user() helper (tagged bhcore_is_test)
 * rather than this plugin creating its own separately-tagged users —
 * one shared convention means "Reset Everything" on the shared page
 * correctly cleans up test accounts regardless of which plugin created
 * them.
 *
 * All seeding/close/publish/login-as-voter actions target ONE contest at
 * a time, picked via the dropdown at the top of this section — this lets
 * you build out several test contests side by side to exercise the
 * multi-contest flows (different windows, different shortcodes, etc).
 */
class BH_Debug {
    const FAKE_ARTISTS = ['The Night Owls', 'Static Bloom', 'Copper Wire', 'Low Tide Motel', 'Radio Silo', 'Paper Kites Union', 'Glass Harbor', 'Velvet Static', 'Dry County', 'The Understudies'];
    const FAKE_TITLES  = ['Midnight Frequency', 'Concrete Bloom', 'Slow Burn', 'Neon Ghosts', 'Last Call', 'Static & Gold', 'Borrowed Time', 'Empty Rooms', 'Feedback Loop', 'Paper Moon'];

    public static function init() {
        add_filter('ous_debug_tools', [self::class, 'register']);
    }

    public static function register($tools) {
        $tools['bh-contest'] = [
            'label'  => 'BH Contest',
            'render' => [self::class, 'render_section'],
            'handle' => [self::class, 'handle_action'],
            'reset'  => [self::class, 'reset'],
        ];
        return $tools;
    }

    /* ---------------- UI ---------------- */

    public static function render_section($key) {
        $contests = BH_Helpers::all_contests();
        $cid      = isset($_GET['contest_id']) ? (int) $_GET['contest_id'] : 0;
        if (!$cid || get_post_type($cid) !== 'bh_contest') $cid = $contests ? $contests[0]->ID : 0;

        // Contest picker — every action below targets whichever contest is
        // selected here. Reloads the page with ?contest_id=X on change.
        echo '<form method="get" style="margin-bottom:16px;">'
           . '<input type="hidden" name="post_type" value="bh_contest">'
           . '<input type="hidden" name="page" value="ous-debug">'
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

        echo '<h3>Target contest state</h3><ul style="list-style:disc;margin-left:20px;">';
        if (!$cid) {
            echo '<li><em>No contest selected — create one below first.</em></li>';
        } else {
            echo '<li>' . esc_html(get_the_title($cid)) . ' — status: <strong>' . esc_html(ucfirst(BH_Helpers::contest_status($cid))) . '</strong></li>';
            echo '<li>Results published: ' . (get_post_meta($cid, '_bh_results_published', true) === '1' ? 'yes' : 'no') . '</li>';
            echo '<li>Submissions: ' . BH_Helpers::submission_count($cid) . ' published, ' . BH_Helpers::submission_count($cid, 'pending') . ' pending</li>';
            echo '<li>Votes cast: ' . BH_Helpers::vote_count($cid) . '</li>';
            echo '<li>Shortcode: <code>' . esc_html(BH_Helpers::shortcode_for($cid)) . '</code></li>';
            $cats = BH_Helpers::categories($cid);
            echo '<li>Categories: ' . ($cats ? esc_html(implode(', ', wp_list_pluck($cats, 'name'))) : '<em>none — single ordinary vote</em>') . '</li>';
        }
        echo '</ul>';

        $cidField = "<input type='hidden' name='contest_id' value='" . (int) $cid . "'>";

        echo '<h4>1. Contest</h4><p>Always creates a brand-new test contest with voting open right now — build as many as you need to test multiple contests running side by side.</p>';
        OUS_Debug::button($key, 'seed_contest', 'Create a new open test contest');
        self::button_with_contest($key, 'close_contest', 'Close voting on the target contest', $cidField, $cid);
        self::button_with_contest($key, 'seed_categories', 'Add 3 sample categories to target', $cidField, $cid);

        echo '<h4>2. Submissions</h4><p>Adds fake published tracks to the <strong>target contest</strong> above, with fake artists/titles. No real audio file is attached — the player will list them and let you vote, but play will show "no audio file" (expected).</p>';
        $disabled = $cid ? '' : ' disabled';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin:4px 8px 4px 0;">';
        echo '<input type="hidden" name="action" value="ous_debug_action">';
        echo '<input type="hidden" name="ous_plugin" value="' . esc_attr($key) . '">';
        echo '<input type="hidden" name="ous_debug_action" value="seed_submissions">';
        echo '<input type="hidden" name="_wpnonce" value="' . esc_attr(wp_create_nonce('ous_debug_' . $key)) . '">';
        echo $cidField;
        echo '<label>Count: <input type="number" name="count" value="8" min="1" max="30" style="width:60px;"' . $disabled . '></label> ';
        echo '<button class="button button-primary"' . $disabled . '>Add fake submissions</button>';
        echo '</form>';

        echo '<h4>3. Votes</h4><p>Creates fake voter accounts and casts randomized votes (respecting the 1/2-vote limit) on the target contest\'s submissions.</p>';
        self::button_with_contest($key, 'seed_votes', 'Cast random test votes', $cidField, $cid);

        echo '<h4>4. Results</h4><p>Toggles the "Publish Results" checkbox on the target contest.</p>';
        self::button_with_contest($key, 'publish_results', 'Publish results (checkbox ON)', $cidField, $cid);
        self::button_with_contest($key, 'unpublish_results', 'Hide results (checkbox OFF)', $cidField, $cid);

        echo '<h4>5. Test as another user</h4><p>You\'re currently viewing the player as whichever WordPress account is logged into this browser — normally your admin account. '
           . 'To see the voting UI as a fresh voter (no prior votes, no submission) <strong>on the target contest</strong>, use this. '
           . '<strong>It replaces your current login in this browser</strong> with a brand-new test account and sends you straight to that contest\'s page, so it will log you out of wp-admin too. '
           . 'Come back here afterward and log in again with your real account to keep testing admin features.</p>';
        self::button_with_contest($key, 'login_as_voter', 'Log in as a new test voter →', $cidField, $cid,
            'This will log you out of your admin account in this browser and switch to a fresh test voter. Continue?');
    }

    // OUS_Debug::button() covers the common case; this adds the
    // target-contest hidden field and disables the button when no
    // contest is selected, which every action here except seed_contest
    // itself needs.
    private static function button_with_contest($key, $action, $label, $cidField, $cid, $confirm = '') {
        if (!$cid) {
            echo '<button class="button" disabled title="Select a target contest first">' . esc_html($label) . '</button> ';
            return;
        }
        OUS_Debug::button($key, $action, $label, $cidField, $confirm);
    }

    /* ---------------- dispatch ---------------- */

    // See OUS_Debug's class docblock: login_as_voter is the one action
    // here that needs to do its own redirect+exit rather than return a
    // message, since it switches the browser's session.
    public static function handle_action($action, $post) {
        $cid = (int) ($post['contest_id'] ?? 0);

        if ($action === 'login_as_voter') {
            if (!$cid) self::redirect(0, 'Select a target contest first.');
            wp_safe_redirect(self::login_as_voter($cid));
            exit;
        }

        switch ($action) {
            case 'seed_contest':      $newId = self::seed_contest(); return 'Created a new test contest (ID ' . $newId . '), voting open now.';
            case 'close_contest':     return self::close_contest($cid);
            case 'seed_categories':   return self::seed_categories($cid);
            case 'seed_submissions':  return self::seed_submissions($cid, max(1, min(30, (int) ($post['count'] ?? 8))));
            case 'seed_votes':        return self::seed_votes($cid);
            case 'publish_results':   return self::set_results($cid, true);
            case 'unpublish_results': return self::set_results($cid, false);
        }
        return 'Unknown action.';
    }

    private static function redirect($cid, $msg) {
        $url = admin_url('admin.php?page=ous-debug');
        if ($cid) $url = add_query_arg('contest_id', (int) $cid, $url);
        wp_safe_redirect(add_query_arg('ous_msg', rawurlencode($msg), $url));
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

    private static function seed_categories($cid) {
        if (!$cid) return 'No target contest selected.';
        $cats = BH_Helpers::parse_categories_input("Best Vocals\nBest Original Song\nFan Favorite");
        update_post_meta($cid, '_bh_categories', wp_json_encode($cats));
        return 'Added 3 sample categories to "' . get_the_title($cid) . '". Existing votes on this contest count as "general" votes and won\'t show under the new categories.';
    }

    private static function seed_submissions($cid, $count) {
        if (!$cid) return 'No target contest selected.';

        for ($i = 0; $i < $count; $i++) {
            $artist = self::FAKE_ARTISTS[array_rand(self::FAKE_ARTISTS)];
            $title  = self::FAKE_TITLES[array_rand(self::FAKE_TITLES)] . ' ' . wp_rand(1, 99);
            $author_id = OUS_Debug::get_or_create_test_user('submitter');

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

        $cats = BH_Helpers::categories($cid);
        $cat_slugs = $cats ? wp_list_pluck($cats, 'slug') : [''];

        global $wpdb;
        $t = BH_Helpers::table();
        $voters = wp_rand(15, 30);
        $cast = 0;

        for ($i = 0; $i < $voters; $i++) {
            $uid = OUS_Debug::get_or_create_test_user('voter');
            $limit = BH_Helpers::vote_limit($uid, $cid); // some test users will "have submitted" if reused

            // Each category gets its own independent picks — a track can
            // win in more than one category, same as a real voter choosing
            // different favorites per category.
            foreach ($cat_slugs as $cat) {
                $picks = (array) array_rand(array_flip($tracks), min($limit, count($tracks)));
                foreach ($picks as $track_id) {
                    $exists = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM $t WHERE user_id=%d AND contest_id=%d AND category=%s AND submission_id=%d", $uid, $cid, $cat, $track_id
                    ));
                    if (!$exists) {
                        $wpdb->insert($t, ['user_id' => $uid, 'contest_id' => $cid, 'category' => $cat, 'submission_id' => $track_id], ['%d', '%d', '%s', '%d']);
                        $cast++;
                    }
                }
            }
        }
        $cat_note = $cats ? ' across ' . count($cats) . ' categories' : '';
        return "Cast $cast test votes from $voters fake voters$cat_note on \"" . get_the_title($cid) . '".';
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
            return add_query_arg(['ous_msg' => rawurlencode('Could not create a test voter.'), 'contest_id' => $cid], admin_url('admin.php?page=ous-debug'));
        }
        update_user_meta($id, 'bhcore_is_test', 'voter');

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

    // Wipes only THIS plugin's own tagged test data — posts tagged
    // _bh_is_test, and any user tagged bhcore_is_test with a role tag
    // this plugin uses ('submitter' or 'voter'). Shared "Reset
    // Everything" calls this alongside every other registered plugin's
    // own reset(); a person can also trigger just this one from this
    // section — see OUS_Debug's dispatcher for how a plugin-specific
    // reset would be wired if this plugin adds one later.
    public static function reset() {
        $posts = get_posts(['post_type' => ['bh_contest', 'bh_submission'], 'meta_key' => '_bh_is_test', 'meta_value' => '1', 'posts_per_page' => -1, 'post_status' => 'any', 'fields' => 'ids']);
        foreach ($posts as $pid) wp_delete_post($pid, true);

        $users = get_users(['meta_query' => [['key' => 'bhcore_is_test', 'value' => ['submitter', 'voter'], 'compare' => 'IN']], 'fields' => 'ID']);
        global $wpdb;
        $t = BH_Helpers::table();
        foreach ($users as $uid) {
            $wpdb->delete($t, ['user_id' => $uid], ['%d']);
            wp_delete_user($uid);
        }

        return 'Wiped ' . count($posts) . ' test posts and ' . count($users) . ' test users (and their votes).';
    }
}

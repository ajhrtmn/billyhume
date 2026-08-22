<?php
if (!defined('ABSPATH')) exit;

/**
 * Admin CRUD for gossip peers — a "peer" is another bh-registry install
 * an admin HERE has explicitly, mutually chosen to exchange
 * announcements with. Never auto-discovered, never a default relay
 * (this ecosystem's own standing rule: no third-party service as the
 * only implementation of anything critical, and no silent default
 * trust). Bootstrapping mirrors Mastodon/ActivityPub federation: two
 * admins each add the other and exchange a secret out-of-band — this
 * class only ever manages OUR OWN side of that relationship.
 *
 * Registered via BHR_Admin's own 'admin_menus' entry (class-admin.php)
 * — no raw add_submenu_page() call here, matching this ecosystem's
 * established page-hook-resolution-safe pattern.
 */
class BHR_Peers {
    const LIVENESS_FAIL_THRESHOLD = 5;
    const NEW_SECRET_TTL = 5 * MINUTE_IN_SECONDS;

    public static function init(): void {
        add_action('admin_post_bhr_peers_add', [self::class, 'handle_add']);
        add_action('admin_post_bhr_peers_action', [self::class, 'handle_action']);
        add_action('bhr_peer_liveness_check', [self::class, 'check_all_liveness']);
    }

    /* ---------- rendering ---------- */

    public static function render(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'bhr_peers';
        $peers = $wpdb->get_results("SELECT * FROM $table ORDER BY created_at DESC");

        echo '<div class="wrap"><h1>Registry Peers</h1>';
        echo '<p class="description">Peers exchange gossip announcements automatically once added — this is the only place discovery propagates outward from. Verification of anything a peer announces still always runs independently on this site; a peer relationship never grants trust by itself.</p>';

        self::render_new_secret_notice();

        echo '<div class="bhy-card" style="margin-bottom:16px;max-width:640px;"><h2 style="margin-top:0;">Add a Peer</h2>';
        echo '<p class="description">Enter the OTHER site\'s base URL (e.g. https://their-site.example). We\'ll confirm it\'s a live, reachable bh-registry install before saving. After saving, you\'ll see a one-time secret — send it to that site\'s admin out-of-band (not over email/chat in plain text if you can help it), and they add THIS site as a peer using it on their own end.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('bhr_peers_add');
        echo '<input type="hidden" name="action" value="bhr_peers_add">';
        echo '<p><input type="url" name="base_url" placeholder="https://their-site.example" required style="width:100%;max-width:420px;"></p>';
        echo '<p><input type="text" name="label" placeholder="Label (optional, e.g. their site name)" style="width:100%;max-width:420px;"></p>';
        echo '<p><button type="submit" class="button button-primary">Add Peer</button></p>';
        echo '</form></div>';

        echo '<div class="bhy-table-wrap"><table class="wp-list-table widefat striped"><thead><tr>';
        echo '<th>Peer</th><th>Status</th><th>Last announced</th><th>Last seen</th><th>Failures</th><th>Actions</th>';
        echo '</tr></thead><tbody>';
        if (!$peers) {
            echo '<tr><td colspan="6">No peers yet.</td></tr>';
        }
        foreach ($peers as $peer) {
            echo '<tr><td><strong>' . esc_html($peer->label ?: $peer->base_url) . '</strong>';
            if ($peer->label) echo '<br><span class="description">' . esc_html($peer->base_url) . '</span>';
            echo '</td>';
            echo '<td>' . self::status_badge($peer->status) . '</td>';
            echo '<td>' . esc_html($peer->last_announced_at ?: '—') . '</td>';
            echo '<td>' . esc_html($peer->last_seen_at ?: '—') . '</td>';
            echo '<td>' . (int) $peer->fail_count . '</td>';
            echo '<td>';
            if ($peer->status !== 'blocked') echo self::action_link($peer->id, 'block', 'Block', 'Block this peer? Their announces will be rejected immediately, and this site will stop announcing to them.') . ' ';
            if ($peer->status === 'blocked') echo self::action_link($peer->id, 'unblock', 'Unblock') . ' ';
            if ($peer->status === 'paused') echo self::action_link($peer->id, 'reactivate', 'Reactivate') . ' ';
            echo self::action_link($peer->id, 'regenerate_secret', 'Regenerate secret', 'Regenerate this peer\'s secret? They will need the new one before their announces are accepted again.') . ' ';
            echo self::action_link($peer->id, 'delete', 'Delete', 'Delete this peer permanently?');
            echo '</td></tr>';
        }
        echo '</tbody></table></div></div>';
    }

    private static function render_new_secret_notice(): void {
        $peer_id = (int) ($_GET['new_peer_id'] ?? 0);
        if (!$peer_id) return;
        $key = 'bhr_new_peer_secret_' . get_current_user_id() . '_' . $peer_id;
        $secret = get_transient($key);
        if (!$secret) return;
        delete_transient($key);
        echo '<div class="notice notice-success" style="padding:12px;"><p><strong>Peer added.</strong> Send this secret to the other site\'s admin now — it will not be shown again (you can regenerate it later if needed):</p>';
        echo '<p><input type="text" readonly value="' . esc_attr($secret) . '" style="width:100%;max-width:420px;font-family:monospace;" onclick="this.select();"></p></div>';
    }

    private static function status_badge(string $status): string {
        $variants = ['active' => 'success', 'paused' => 'warning', 'blocked' => 'danger'];
        $variant = $variants[$status] ?? 'neutral';
        return '<span class="bhy-badge bhy-badge-' . esc_attr($variant) . '">' . esc_html($status) . '</span>';
    }

    private static function action_link(int $peer_id, string $action, string $label, string $confirm = ''): string {
        $url = wp_nonce_url(
            admin_url('admin-post.php?action=bhr_peers_action&do=' . $action . '&peer_id=' . $peer_id),
            'bhr_peers_action'
        );
        $onclick = $confirm ? " onclick=\"return confirm('" . esc_js($confirm) . "')\"" : '';
        return '<a href="' . esc_url($url) . '"' . $onclick . '>' . esc_html($label) . '</a>';
    }

    /* ---------- actions ---------- */

    public static function handle_add(): void {
        if (!OUS_AdminGuard::verify_nonce_and_cap('manage_options', $_POST['_wpnonce'] ?? '', 'bhr_peers_add')) {
            wp_die('Not allowed.');
        }

        $base_url = esc_url_raw(rtrim(trim((string) ($_POST['base_url'] ?? '')), '/'));
        $label    = sanitize_text_field((string) ($_POST['label'] ?? ''));

        if (!$base_url || !wp_http_validate_url($base_url)) {
            wp_safe_redirect(admin_url('admin.php?page=bh-registry-peers&bhr_error=invalid_url'));
            exit;
        }

        // Fail closed — don't save a peer we can't confirm is actually a
        // live, reachable bh-registry install. This also means an admin
        // typo'd URL never silently sits there as a useless "active"
        // peer generating a secret nobody will ever use.
        $res = wp_remote_get(rtrim($base_url, '/') . '/wp-json/bhr/v1/peers/handshake', ['timeout' => 8, 'redirection' => 2]);
        if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) {
            if (class_exists('OUS_DebugLog')) {
                OUS_DebugLog::log('info', 'Peer add failed: handshake unreachable.', [
                    'base_url' => $base_url,
                    'wp_error' => is_wp_error($res) ? $res->get_error_message() : null,
                    'http_status' => is_wp_error($res) ? null : wp_remote_retrieve_response_code($res),
                ], 'BH Registry Gossip');
            }
            wp_safe_redirect(admin_url('admin.php?page=bh-registry-peers&bhr_error=unreachable'));
            exit;
        }

        global $wpdb;
        $secret = wp_generate_password(32, false, false);
        $inserted = $wpdb->insert($wpdb->prefix . 'bhr_peers', [
            'base_url'      => $base_url,
            'label'         => $label,
            'status'        => 'active',
            'shared_secret' => $secret,
        ]);

        if (!$inserted) {
            wp_safe_redirect(admin_url('admin.php?page=bh-registry-peers&bhr_error=duplicate_or_db'));
            exit;
        }

        $peer_id = (int) $wpdb->insert_id;
        set_transient('bhr_new_peer_secret_' . get_current_user_id() . '_' . $peer_id, $secret, self::NEW_SECRET_TTL);

        wp_safe_redirect(admin_url('admin.php?page=bh-registry-peers&new_peer_id=' . $peer_id));
        exit;
    }

    public static function handle_action(): void {
        if (!OUS_AdminGuard::verify_nonce_and_cap('manage_options', $_GET['_wpnonce'] ?? '', 'bhr_peers_action')) {
            wp_die('Not allowed.');
        }

        global $wpdb;
        $table = $wpdb->prefix . 'bhr_peers';
        $peer_id = (int) ($_GET['peer_id'] ?? 0);
        $do = sanitize_text_field($_GET['do'] ?? '');

        switch ($do) {
            case 'block':
                $wpdb->update($table, ['status' => 'blocked'], ['id' => $peer_id]);
                break;
            case 'unblock':
            case 'reactivate':
                $wpdb->update($table, ['status' => 'active', 'fail_count' => 0], ['id' => $peer_id]);
                break;
            case 'regenerate_secret':
                $new_secret = wp_generate_password(32, false, false);
                $wpdb->update($table, ['shared_secret' => $new_secret], ['id' => $peer_id]);
                set_transient('bhr_new_peer_secret_' . get_current_user_id() . '_' . $peer_id, $new_secret, self::NEW_SECRET_TTL);
                wp_safe_redirect(admin_url('admin.php?page=bh-registry-peers&new_peer_id=' . $peer_id));
                exit;
            case 'delete':
                $wpdb->delete($table, ['id' => $peer_id]);
                break;
        }

        wp_safe_redirect(admin_url('admin.php?page=bh-registry-peers'));
        exit;
    }

    /* ---------- liveness ---------- */

    // Daily cron target. Only checks reachability (the /handshake GET)
    // — never re-authenticates or re-trusts anything, this is purely
    // "is the other end still there," same spirit as
    // BHR_Verification::recheck_all()'s own re-checks of verified links.
    public static function check_all_liveness(): void {
        global $wpdb;
        $peers = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}bhr_peers WHERE status IN ('active', 'paused')");

        foreach ($peers as $peer) {
            $res = wp_remote_get(rtrim($peer->base_url, '/') . '/wp-json/bhr/v1/peers/handshake', ['timeout' => 8, 'redirection' => 2]);
            $ok = !is_wp_error($res) && wp_remote_retrieve_response_code($res) === 200;

            if ($ok) {
                $recovery = ['last_seen_at' => current_time('mysql'), 'fail_count' => 0];
                // Auto-recovery only from an auto-pause, never from a
                // 'blocked' status — 'blocked' isn't reachable by this
                // query at all (the WHERE clause above only selects
                // active/paused), so this is safe without an extra check.
                if ($peer->status === 'paused') $recovery['status'] = 'active';
                $wpdb->update($wpdb->prefix . 'bhr_peers', $recovery, ['id' => $peer->id]);
                continue;
            }

            $fails = (int) $peer->fail_count + 1;
            $update = ['fail_count' => $fails];
            // Auto-pause, never auto-block — blocking is an admin's own
            // explicit trust decision (see handle_action()'s 'block'
            // case), sticky the same way bhr_artists.status='rejected'
            // is sticky. Paused just means "unreachable enough times
            // that we've stopped counting on it," reversible by the
            // admin (or automatically once it answers again, below).
            if ($fails >= self::LIVENESS_FAIL_THRESHOLD && $peer->status === 'active') {
                $update['status'] = 'paused';
            }
            $wpdb->update($wpdb->prefix . 'bhr_peers', $update, ['id' => $peer->id]);

            if (class_exists('OUS_DebugLog')) {
                OUS_DebugLog::log('info', 'Peer liveness check failed.', [
                    'peer_id' => $peer->id, 'base_url' => $peer->base_url, 'fail_count' => $fails,
                ], 'BH Registry Gossip');
            }
        }
    }
}

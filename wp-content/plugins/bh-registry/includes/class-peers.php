<?php
if (!defined('ABSPATH')) exit;

/**
 * Admin CRUD for discovery peers — a "peer" is another bh-registry
 * install this site periodically pulls a manifest from (see
 * class-crawl.php). No secret, no authentication, no per-relationship
 * setup beyond knowing a base_url — the earlier push+shared-secret
 * design (briefly shipped, then redesigned) required two admins to
 * manually exchange a secret before anything worked, which was both
 * more friction than this feature should ever need and had a real bug
 * (no way for two independently-generated secrets to ever match).
 *
 * This screen also hosts the settings for the two OPTIONAL automatic-
 * discovery enhancements (ActivityPub relay, search-index lookup) —
 * both real, existing, internet-scale mechanisms capable of true
 * zero-prior-knowledge discovery, both off by default, both following
 * the same "toggle + credential, blank-preserves-existing" shape
 * own-ur-shit's own Media & CDN Setup wizard already establishes
 * (class-media-wizard.php's Tier B section) rather than inventing a
 * new settings convention. Whatever base_url either layer finds feeds
 * into the exact same bhr_peers table and the exact same crawl loop a
 * manually-added peer does — one shared pipeline, three ways in.
 *
 * Registered via BHR_Admin's own 'admin_menus' entry (class-admin.php)
 * — no raw add_submenu_page() call here, matching this ecosystem's
 * established page-hook-resolution-safe pattern.
 */
class BHR_Peers {
    public static function init(): void {
        add_action('admin_post_bhr_peers_add', [self::class, 'handle_add']);
        add_action('admin_post_bhr_peers_action', [self::class, 'handle_action']);
        add_action('admin_post_bhr_save_discovery_settings', [self::class, 'handle_save_discovery_settings']);
        add_action('bhr_peer_crawl', ['BHR_Crawl', 'crawl_all_peers']);
    }

    /* ---------- rendering ---------- */

    public static function render(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'bhr_peers';
        $peers = $wpdb->get_results("SELECT * FROM $table ORDER BY created_at DESC");

        echo '<div class="wrap"><h1>Registry Peers</h1>';
        echo '<p class="description">Peers are crawled automatically once added — this is the only place discovery propagates outward from. Verification of anything found via a peer still always runs independently on this site; a peer relationship never grants trust by itself.</p>';

        self::render_settings_notice();
        self::render_discovery_settings();

        echo '<div class="bhy-card" style="margin-bottom:16px;max-width:640px;"><h2 style="margin-top:0;">Add a Peer</h2>';
        echo '<p class="description">Enter the OTHER site\'s base URL (e.g. https://their-site.example). We\'ll confirm it\'s a live, reachable bh-registry install before saving — no secret or coordination with the other site needed, this side starts crawling immediately.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('bhr_peers_add');
        echo '<input type="hidden" name="action" value="bhr_peers_add">';
        echo '<p><input type="url" name="base_url" placeholder="https://their-site.example" required style="width:100%;max-width:420px;"></p>';
        echo '<p><input type="text" name="label" placeholder="Label (optional, e.g. their site name)" style="width:100%;max-width:420px;"></p>';
        echo '<p><button type="submit" class="button button-primary">Add Peer</button></p>';
        echo '</form></div>';

        echo '<div class="bhy-table-wrap"><table class="wp-list-table widefat striped"><thead><tr>';
        echo '<th>Peer</th><th>Status</th><th>Hop</th><th>Last crawled</th><th>Last seen</th><th>Failures</th><th>Actions</th>';
        echo '</tr></thead><tbody>';
        if (!$peers) {
            echo '<tr><td colspan="7">No peers yet.</td></tr>';
        }
        foreach ($peers as $peer) {
            echo '<tr><td><strong>' . esc_html($peer->label ?: $peer->base_url) . '</strong>';
            if ($peer->label) echo '<br><span class="description">' . esc_html($peer->base_url) . '</span>';
            echo '</td>';
            echo '<td>' . self::status_badge($peer->status) . '</td>';
            echo '<td>' . (int) $peer->discovered_hop . '</td>';
            echo '<td>' . esc_html($peer->last_crawled_at ?: '—') . '</td>';
            echo '<td>' . esc_html($peer->last_seen_at ?: '—') . '</td>';
            echo '<td>' . (int) $peer->fail_count . '</td>';
            echo '<td>';
            if ($peer->status !== 'blocked') echo self::action_link($peer->id, 'block', 'Block', 'Block this peer? This site will stop crawling it immediately.') . ' ';
            if ($peer->status === 'blocked') echo self::action_link($peer->id, 'unblock', 'Unblock') . ' ';
            if ($peer->status === 'paused') echo self::action_link($peer->id, 'reactivate', 'Reactivate') . ' ';
            echo self::action_link($peer->id, 'delete', 'Delete', 'Delete this peer permanently?');
            echo '</td></tr>';
        }
        echo '</tbody></table></div></div>';
    }

    // Tier B pattern (own-ur-shit's Media & CDN Setup wizard,
    // class-media-wizard.php): one toggle + one credential field per
    // optional layer, password/key field always rendered blank
    // (placeholder signals "already set," a blank submit preserves the
    // existing stored value rather than wiping it).
    private static function render_discovery_settings(): void {
        $relay_enabled = (bool) get_option('bhr_relay_enabled', false);
        $relay_url     = (string) get_option('bhr_relay_url', '');
        $search_enabled = (bool) get_option('bhr_search_enabled', false);
        $search_endpoint = (string) get_option('bhr_search_endpoint_url', '');
        $search_creds = get_option('bhr_search_credentials', ['api_key' => '']);

        echo '<div class="bhy-card" style="margin-bottom:16px;max-width:640px;"><h2 style="margin-top:0;">Automatic Discovery (optional)</h2>';
        echo '<p class="description">Both off by default — real outbound calls to infrastructure you configure, never a silent default. Either can find a peer this site has never heard of before; once found, that peer is crawled the same way any manually-added one is.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('bhr_save_discovery_settings');
        echo '<input type="hidden" name="action" value="bhr_save_discovery_settings">';

        echo '<h3>ActivityPub relay</h3>';
        echo '<p class="description">Connects to a real, self-hostable Fediverse relay — the same mechanism independent Mastodon-class instances already use to discover each other with no prior relationship. Once subscribed, any other registry on the same relay is discovered automatically, with no coordination between the two sites at all.</p>';
        echo '<p><label><input type="checkbox" name="relay_enabled" value="1" ' . checked($relay_enabled, true, false) . '> Enabled</label></p>';
        echo '<p><input type="url" name="relay_url" value="' . esc_attr($relay_url) . '" placeholder="https://relay.example" style="width:100%;max-width:420px;"></p>';
        self::render_relay_status();

        echo '<h3>Search-index lookup</h3>';
        echo '<p class="description">Queries a configured search endpoint for other public bh-registry installs. A self-hosted <a href="https://docs.searxng.org/" target="_blank" rel="noopener">SearXNG</a> instance is recommended over a paid vendor API — point at your own, no lock-in. A commercial API (Bing/Google/Brave) works too if you provide its endpoint and key.</p>';
        echo '<p><label><input type="checkbox" name="search_enabled" value="1" ' . checked($search_enabled, true, false) . '> Enabled</label></p>';
        echo '<p><input type="url" name="search_endpoint_url" value="' . esc_attr($search_endpoint) . '" placeholder="https://your-searxng.example/search" style="width:100%;max-width:420px;"></p>';
        echo '<p><input type="password" name="search_api_key" value="" placeholder="' . ($search_creds['api_key'] ? 'Already set — leave blank to keep it' : 'API key (optional, SearXNG usually needs none)') . '" style="width:100%;max-width:420px;"></p>';

        echo '<p><button type="submit" class="button button-primary">Save Discovery Settings</button></p>';
        echo '</form>';

        // Cron runs daily/weekly; an admin setting this up right now
        // shouldn't have to wait a day to find out whether it works.
        echo '<hr><p class="description"><strong>Run now</strong> — these normally run on their own schedule (peer crawl daily, search sweep weekly, relay sync daily).</p><p>';
        echo self::run_now_link('crawl', 'Crawl peers now') . ' ';
        echo self::run_now_link('search', 'Run search discovery now') . ' ';
        echo self::run_now_link('relay', 'Sync relay now');
        echo '</p></div>';
    }

    private static function run_now_link(string $what, string $label): string {
        $url = wp_nonce_url(
            admin_url('admin-post.php?action=bhr_peers_action&do=run_' . $what),
            'bhr_peers_action'
        );
        return '<a class="button" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
    }

    // Real, observable federation state — a relay subscription that
    // silently failed is otherwise invisible until you notice nothing
    // is being discovered, which is exactly the kind of quiet failure
    // this ecosystem's own debug conventions exist to prevent.
    private static function render_relay_status(): void {
        if (!class_exists('BHR_ActivityPub')) return;
        $state = BHR_ActivityPub::relay_state();
        $status = (string) ($state['status'] ?? '');

        echo '<p class="description"><strong>This site\'s actor:</strong> <code>' . esc_html(BHR_ActivityPub::actor_id()) . '</code></p>';

        if (!$status) {
            echo '<p class="description">Relay subscription: <span class="bhy-badge bhy-badge-neutral">not yet attempted</span> — runs on the next daily sync once enabled and a relay URL is saved.</p>';
            return;
        }

        $variants = ['accepted' => 'success', 'pending' => 'warning', 'failed' => 'danger', 'rejected' => 'danger'];
        $variant = $variants[$status] ?? 'neutral';
        $label = [
            'accepted' => 'subscribed (relay accepted our Follow)',
            'pending'  => 'Follow sent, awaiting the relay\'s Accept',
            'failed'   => 'Follow could not be delivered — check Console &amp; Logs',
            'rejected' => 'relay rejected our Follow',
        ][$status] ?? $status;

        echo '<p class="description">Relay subscription: <span class="bhy-badge bhy-badge-' . esc_attr($variant) . '">' . esc_html($status) . '</span> — ' . wp_kses_post($label);
        if (!empty($state['relay_url'])) echo ' (<code>' . esc_html($state['relay_url']) . '</code>)';
        echo '</p>';
    }

    private static function render_settings_notice(): void {
        if (isset($_GET['bhr_settings_saved'])) {
            echo '<div class="notice notice-success" style="padding:12px;"><p>Discovery settings saved.</p></div>';
        }

        $ran = isset($_GET['bhr_ran']) ? sanitize_text_field(wp_unslash($_GET['bhr_ran'])) : '';
        if ($ran) {
            $labels = ['crawl' => 'Peer crawl', 'search' => 'Search discovery', 'relay' => 'Relay sync'];
            $label = $labels[$ran] ?? 'Discovery task';
            echo '<div class="notice notice-info" style="padding:12px;"><p>' . esc_html($label) . ' ran. Any newly-discovered peers appear below; anything found is queued for this site\'s own independent verification before it ever shows publicly. Check <strong>Console &amp; Logs</strong> in Debug Tools for details of what happened.</p></div>';
        }

        $error = isset($_GET['bhr_error']) ? sanitize_text_field(wp_unslash($_GET['bhr_error'])) : '';
        if ($error) {
            $messages = [
                'invalid_url'      => 'That URL was rejected — it must be a real, public http(s) URL (private/loopback addresses are refused).',
                'unreachable'      => 'That site did not answer with a valid registry manifest, so it was not added.',
                'duplicate_or_db'  => 'That peer already exists, or the database write failed.',
            ];
            echo '<div class="notice notice-error" style="padding:12px;"><p>' . esc_html($messages[$error] ?? 'Something went wrong.') . '</p></div>';
        }
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

        $base_url = class_exists('BHR_Crawl') ? BHR_Crawl::normalize_base_url((string) ($_POST['base_url'] ?? '')) : '';
        $base_url = $base_url ? esc_url_raw($base_url) : '';
        $label    = sanitize_text_field((string) ($_POST['label'] ?? ''));

        if (!$base_url || !wp_http_validate_url($base_url) || (class_exists('BHR_Crawl') && !BHR_Crawl::is_safe_external_url($base_url))) {
            wp_safe_redirect(admin_url('admin.php?page=bh-registry-peers&bhr_error=invalid_url'));
            exit;
        }

        // Fail closed — don't save a peer we can't confirm is actually a
        // live, reachable bh-registry install. This also means an
        // admin's typo'd URL never silently sits there as a useless
        // "active" peer this site keeps trying to crawl forever.
        $res = wp_remote_get($base_url . '/wp-json/bhr/v1/peers/manifest', ['timeout' => 8, 'redirection' => 2]);
        if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) {
            if (class_exists('OUS_DebugLog')) {
                OUS_DebugLog::log('info', 'Peer add failed: manifest unreachable.', [
                    'base_url' => $base_url,
                    'wp_error' => is_wp_error($res) ? $res->get_error_message() : null,
                    'http_status' => is_wp_error($res) ? null : wp_remote_retrieve_response_code($res),
                ], 'BH Registry Crawl');
            }
            wp_safe_redirect(admin_url('admin.php?page=bh-registry-peers&bhr_error=unreachable'));
            exit;
        }

        global $wpdb;
        $inserted = $wpdb->insert($wpdb->prefix . 'bhr_peers', [
            'base_url'       => $base_url,
            'label'          => $label,
            'status'         => 'active',
            'discovered_hop' => 0, // manually-added = genesis
        ]);

        if (!$inserted) {
            wp_safe_redirect(admin_url('admin.php?page=bh-registry-peers&bhr_error=duplicate_or_db'));
            exit;
        }

        wp_safe_redirect(admin_url('admin.php?page=bh-registry-peers'));
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
            case 'delete':
                $wpdb->delete($table, ['id' => $peer_id]);
                break;
            case 'run_crawl':
                if (class_exists('BHR_Crawl')) BHR_Crawl::crawl_all_peers();
                wp_safe_redirect(admin_url('admin.php?page=bh-registry-peers&bhr_ran=crawl'));
                exit;
            case 'run_search':
                if (class_exists('BHR_SearchDiscovery')) BHR_SearchDiscovery::run();
                wp_safe_redirect(admin_url('admin.php?page=bh-registry-peers&bhr_ran=search'));
                exit;
            case 'run_relay':
                if (class_exists('BHR_ActivityPub')) BHR_ActivityPub::sync_relay();
                wp_safe_redirect(admin_url('admin.php?page=bh-registry-peers&bhr_ran=relay'));
                exit;
        }

        wp_safe_redirect(admin_url('admin.php?page=bh-registry-peers'));
        exit;
    }

    public static function handle_save_discovery_settings(): void {
        if (!OUS_AdminGuard::verify_nonce_and_cap('manage_options', $_POST['_wpnonce'] ?? '', 'bhr_save_discovery_settings')) {
            wp_die('Not allowed.');
        }

        $relay_enabled = !empty($_POST['relay_enabled']);
        $relay_url     = esc_url_raw((string) ($_POST['relay_url'] ?? ''));
        update_option('bhr_relay_enabled', $relay_enabled);
        update_option('bhr_relay_url', $relay_url);

        $search_enabled  = !empty($_POST['search_enabled']);
        $search_endpoint = esc_url_raw((string) ($_POST['search_endpoint_url'] ?? ''));
        $existing_creds  = get_option('bhr_search_credentials', ['api_key' => '']);
        $api_key         = wp_unslash((string) ($_POST['search_api_key'] ?? ''));
        $api_key         = $api_key !== '' ? $api_key : ($existing_creds['api_key'] ?? '');
        update_option('bhr_search_enabled', $search_enabled);
        update_option('bhr_search_endpoint_url', $search_endpoint);
        update_option('bhr_search_credentials', ['api_key' => $api_key]);

        wp_safe_redirect(admin_url('admin.php?page=bh-registry-peers&bhr_settings_saved=1'));
        exit;
    }
}

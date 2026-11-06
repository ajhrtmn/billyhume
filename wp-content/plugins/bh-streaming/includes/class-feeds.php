<?php
if (!defined('ABSPATH')) exit;

/**
 * Artist-curated feed aggregation over the open RSS/Atom podcast standard.
 *
 * Import: an admin adds a bhs_feed_source URL; items become bhs_track posts
 * flagged _bhs_source = 'external' (parsed via core fetch_feed()). Nothing is
 * pulled in unless an admin added the feed. Export: this site's catalog as a
 * standard RSS feed with <enclosure> audio, consumable by the same importer.
 */
class BHS_Feeds {
    const CRON_HOOK = 'bhs_sync_feeds';

    public static function init(): void {
        add_action('add_meta_boxes', [self::class, 'add_meta_box']);
        add_action('save_post_bhs_feed_source', [self::class, 'save_feed_source']);
        add_action('admin_post_bhs_sync_feed', [self::class, 'handle_manual_sync']);
        add_action(self::CRON_HOOK, [self::class, 'sync_all']);
        // "Share your feed" panel (export URL + QR) on the Feed Sources list screen.
        add_action('admin_notices', [self::class, 'render_share_panel']);

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), 'twicedaily', self::CRON_HOOK);
        }
    }

    /* ---------- open-standard enforcement ---------- */

    // Only accept open RSS/Atom media feeds: requires at least one item with
    // a real enclosure, not just any URL that returns a 200.
    /** @param mixed $feed */
    private static function validate_is_open_feed($feed): bool {
        if (is_wp_error($feed)) return false;
        $items = $feed->get_items(0, 5);
        foreach ($items as $item) {
            if ($item->get_enclosure() && $item->get_enclosure()->get_link()) return true;
        }
        return false;
    }

    // This site's own subscribable feed URL.
    public static function own_feed_url(): string {
        return esc_url_raw(rest_url('bhs/v1/feed.xml'));
    }

    public static function render_share_panel(): void {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->post_type !== 'bhs_feed_source') return;

        $url = self::own_feed_url();
    // Uses a public QR-image service (admin-only) rather than bundling a QR
    // library; swappable for a bundled encoder without other changes.
        $qr_src = 'https://api.qrserver.com/v1/create-qr-code/?size=160x160&data=' . rawurlencode($url);

        echo '<div class="notice notice-info" style="padding:16px;">';
        echo '<div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;">';
        echo '<img src="' . esc_url($qr_src) . '" width="120" height="120" alt="QR code for this site\'s feed" style="border:1px solid #dcdcde;border-radius:6px;">';
        echo '<div style="flex:1;min-width:240px;">';
        echo '<p style="margin-top:0;"><strong>Share your feed</strong> — this is the open-standard (RSS/Podcasting-2.0) link to your own catalog. '
           . 'Any Funkwhale channel, podcast app, or another bh-streaming site can subscribe to it directly, or scan the QR code from a phone.</p>';
        echo '<p><input type="text" id="bhs-own-feed-url" readonly value="' . esc_attr($url) . '" onclick="this.select();" style="width:100%;max-width:480px;font-family:monospace;font-size:12px;padding:6px;"> '
           . '<button type="button" class="button bhy-copy-btn" data-copy-target="#bhs-own-feed-url">Copy</button></p>';
        echo '<p class="description">To add SOMEONE ELSE\'S feed instead, use "Add New" below with their feed\'s RSS link — '
           . 'on Funkwhale that\'s the "RSS feed" link on the artist\'s channel page; most other open, artist-hosted platforms expose the same kind of link on their channel/show page.</p>';
        echo '</div></div></div>';
    }

    public static function register_routes(): void {
        register_rest_route('bhs/v1', '/feed.xml', [
            'methods' => 'GET', 'callback' => [self::class, 'export_feed'], 'permission_callback' => '__return_true',
        ]);
    }

    /* ---------- admin: feed source management ---------- */

    public static function add_meta_box(): void {
        add_meta_box('bhs_feed_source_url', 'Feed Details', [self::class, 'render_meta_box'], 'bhs_feed_source', 'normal', 'high');
    }

    public static function render_meta_box(\WP_Post $post): void {
        wp_nonce_field('bhs_save_feed_source', 'bhs_feed_source_nonce');
        $url = get_post_meta($post->ID, '_bhs_feed_url', true);
        $last = get_post_meta($post->ID, '_bhs_last_synced', true);

        $invalid = get_post_meta($post->ID, '_bhs_feed_invalid', true);
        $fetch_failed = get_post_meta($post->ID, '_bhs_feed_fetch_failed', true);

        echo '<p><label><strong>Feed URL</strong><br><input type="url" name="bhs_feed_url" value="' . esc_attr($url) . '" style="width:100%;" placeholder="https://example.com/feed.xml" required></label></p>';
        echo '<p class="description">Must be an open-standard RSS/Podcasting-2.0 feed with real audio enclosures — the same format Funkwhale publishes for every channel, and what this site\'s own export at <code>' . esc_html(self::own_feed_url()) . '</code> produces. '
           . 'On Funkwhale: open the artist\'s channel page → "RSS feed" link. Not a proprietary API URL or a plain web page link.</p>';
        if ($fetch_failed) {
            echo '<div class="notice notice-error inline"><p>Last sync couldn\'t reach this URL at all (network error, timeout, or the host was down) — this may just be temporary. The next scheduled sync will retry automatically.</p></div>';
        } elseif ($invalid) {
            echo '<div class="notice notice-error inline"><p>Last sync rejected this URL — it didn\'t look like an open podcast/audio feed (no items with a real audio enclosure). Double-check it\'s the channel\'s RSS link, not its regular profile page.</p></div>';
        }
        echo '<p>' . ($last ? 'Last synced: ' . esc_html($last) : '<em>Never synced — save this post to sync for the first time.</em>') . '</p>';

        if ($post->ID) {
            $sync_url = wp_nonce_url(admin_url('admin-post.php?action=bhs_sync_feed&feed_id=' . $post->ID), 'bhs_sync_feed');
            echo '<p><a href="' . esc_url($sync_url) . '" class="button">Sync now</a></p>';
        }
    }

    public static function save_feed_source(int $post_id): void {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!isset($_POST['bhs_feed_source_nonce']) || !wp_verify_nonce($_POST['bhs_feed_source_nonce'], 'bhs_save_feed_source')) return;
        if (!current_user_can('edit_post', $post_id)) return;
        if (!isset($_POST['bhs_feed_url'])) return;

        $url = esc_url_raw(trim($_POST['bhs_feed_url']));
        update_post_meta($post_id, '_bhs_feed_url', $url);
        if ($url) self::sync_one($post_id, $url);
    }

    public static function handle_manual_sync(): void {
        if (!OUS_AdminGuard::verify_nonce_and_cap('manage_options', $_GET['_wpnonce'] ?? '', 'bhs_sync_feed')) {
            wp_die('Not allowed.');
        }
        $feed_id = (int) ($_GET['feed_id'] ?? 0);
        $url = get_post_meta($feed_id, '_bhs_feed_url', true);
        if ($feed_id && $url) self::sync_one($feed_id, $url);
        wp_safe_redirect(get_edit_post_link($feed_id, ''));
        exit;
    }

    /* ---------- import ---------- */

    // Runs on twice-daily cron and the manual "Sync now" button (WP-Cron only
    // fires on page loads). When OUS_Jobs is available each feed is its own
    // job, so one slow remote feed doesn't block the rest; otherwise inline.
    public static function sync_all(): void {
        $sources = get_posts(['post_type' => 'bhs_feed_source', 'post_status' => 'publish', 'posts_per_page' => -1]);

        if (class_exists('OUS_Jobs')) {
            foreach ($sources as $source) {
                OUS_Jobs::enqueue('bhs_sync_one_feed', ['feed_id' => $source->ID]);
            }
        } else {
            foreach ($sources as $source) {
                $url = get_post_meta($source->ID, '_bhs_feed_url', true);
                if ($url) self::sync_one($source->ID, $url);
            }
        }

        // Health pass over already-imported tracks; always inline, not queued.
        self::check_external_track_health();
    }

    // Per-feed unit of work called by OUS_Jobs (registered in bh-streaming.php).
    /** @param array<string, mixed> $args */
    public static function sync_one_job(array $args): void {
        $feed_id = (int) ($args['feed_id'] ?? 0);
        if (!$feed_id || get_post_status($feed_id) !== 'publish') return;
        $url = get_post_meta($feed_id, '_bhs_feed_url', true);
        if ($url) self::sync_one($feed_id, $url);
    }

    /* ---------- source-down handling ---------- */
    // External audio is never re-hosted, so playability depends on the remote
    // host. Periodically check and surface unreliable sources in the catalog
    // and player. Applies only to streaming, never to purchased downloads.
    const HEALTH_DOWN_AFTER_FAILS = 3; // consecutive failures before a track is treated as unavailable

    public static function check_external_track_health(): void {
        $tracks = get_posts([
            'post_type' => 'bhs_track', 'post_status' => 'publish', 'posts_per_page' => -1,
            'meta_key' => '_bhs_source', 'meta_value' => 'external', 'fields' => 'ids',
        ]);
        foreach ($tracks as $track_id) {
            $url = get_post_meta($track_id, '_bhs_external_audio_url', true);
            if (!$url) continue;

            // HEAD first; some hosts mishandle HEAD for media, so fall back
            // to a tiny ranged GET.
            $resp = wp_remote_head($url, ['timeout' => 6, 'redirection' => 3]);
            $ok = !is_wp_error($resp) && (int) wp_remote_retrieve_response_code($resp) < 400;
            if (!$ok) {
                $resp = wp_remote_get($url, ['timeout' => 6, 'redirection' => 3, 'headers' => ['Range' => 'bytes=0-0']]);
                $ok = !is_wp_error($resp) && (int) wp_remote_retrieve_response_code($resp) < 400;
            }

            $fails = (int) get_post_meta($track_id, '_bhs_source_fail_count', true);
            $fails = $ok ? 0 : $fails + 1;
            $previous_health = get_post_meta($track_id, '_bhs_source_health', true) ?: 'ok';
            $new_health = $fails === 0 ? 'ok' : ($fails >= self::HEALTH_DOWN_AFTER_FAILS ? 'down' : 'degraded');
            update_post_meta($track_id, '_bhs_source_fail_count', $fails);
            update_post_meta($track_id, '_bhs_source_checked_at', current_time('mysql'));
            update_post_meta($track_id, '_bhs_source_health', $new_health);

            // Log only status transitions: ok -> down/degraded as a warning,
            // back to ok as info.
            if ($previous_health !== $new_health && class_exists('OUS_DebugLog')) {
                $level = ($new_health === 'ok') ? 'info' : 'warning';
                OUS_DebugLog::log($level, "External track source health changed: $previous_health -> $new_health", [
                    'track_id' => $track_id, 'url' => $url, 'consecutive_fails' => $fails,
                ], 'BH Streaming Feed Health');
            }
        }
    }

    // Read by BHS_API::track_payload(). 'ok' when never checked yet.
    public static function source_health(int $track_id): string {
        $health = get_post_meta($track_id, '_bhs_source_health', true);
        return $health ?: 'ok';
    }

    private static function sync_one(int $feed_source_id, string $url): void {
        require_once ABSPATH . WPINC . '/feed.php';
        $feed = fetch_feed($url);

        // Network failure is reported separately from "not an open feed", so
        // the admin isn't sent to check a URL that's fine.
        if (is_wp_error($feed)) {
            if (class_exists('OUS_DebugLog')) {
                // Log remote feed failures so an admin can see them in the console.
                OUS_DebugLog::log('warning', 'Feed fetch failed: ' . $feed->get_error_message(), ['feed_source_id' => $feed_source_id, 'url' => $url], 'BH Streaming');
            }
            update_post_meta($feed_source_id, '_bhs_feed_fetch_failed', '1');
            return;
        }
        delete_post_meta($feed_source_id, '_bhs_feed_fetch_failed');

        if (!self::validate_is_open_feed($feed)) {
            update_post_meta($feed_source_id, '_bhs_feed_invalid', '1');
            return;
        }
        delete_post_meta($feed_source_id, '_bhs_feed_invalid');

        $items = $feed->get_items(0, 30); // per-sync cap; not meant to backfill a whole archive
        $imported = 0;
        foreach ($items as $item) {
            try {
                $enclosure = $item->get_enclosure();
                if (!$enclosure || !$enclosure->get_link()) continue; // no audio, nothing to import

                $guid = $item->get_id();
                $existing = get_posts([
                    'post_type' => 'bhs_track', 'post_status' => 'any', 'posts_per_page' => 1,
                    'meta_key' => '_bhs_source_guid', 'meta_value' => $guid, 'fields' => 'ids',
                ]);
                if ($existing) continue; // already imported, don't duplicate on re-sync

                $artist = $item->get_author() ? $item->get_author()->get_name() : '';
                $pid = wp_insert_post([
                    'post_title' => $item->get_title() ?: 'Untitled',
                    'post_type' => 'bhs_track', 'post_status' => 'publish',
                ], true);
                if (is_wp_error($pid)) {
                    if (class_exists('OUS_DebugLog')) {
                        OUS_DebugLog::log('warning', 'Feed import: wp_insert_post failed — ' . $pid->get_error_message(), ['feed_source_id' => $feed_source_id, 'guid' => $guid], 'BH Streaming');
                    }
                    continue;
                }

                update_post_meta($pid, '_bhs_artist', sanitize_text_field($artist));
                update_post_meta($pid, '_bhs_source', 'external');
                update_post_meta($pid, '_bhs_source_feed_id', $feed_source_id);
                update_post_meta($pid, '_bhs_source_guid', $guid);
                // Audio stays a remote URL; the originating site is the source of truth.
                update_post_meta($pid, '_bhs_external_audio_url', esc_url_raw($enclosure->get_link()));
                $imported++;
            } catch (\Throwable $e) {
                // One malformed item must not abort the whole sync.
                if (class_exists('OUS_DebugLog')) {
                    OUS_DebugLog::log('error', 'Feed import: unexpected error on one item — ' . $e->getMessage(), ['feed_source_id' => $feed_source_id], 'BH Streaming');
                }
            }
        }

        update_post_meta($feed_source_id, '_bhs_last_synced', current_time('mysql'));
    }

    /* ---------- export ---------- */

    // WHY: a WP_REST_Response body is always JSON-serialized, so serving raw
    // RSS means leaving the REST cycle (status_header()/header()/echo/exit).
    public static function export_feed(): void {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $rss = $doc->createElement('rss');
        $rss->setAttribute('version', '2.0');
        $rss->setAttribute('xmlns:itunes', 'http://www.itunes.com/dtds/podcast-1.0.dtd');
        $doc->appendChild($rss);

        // WHY: DOMDocument escapes text nodes on saveXML(); don't pre-escape
        // with htmlspecialchars() or values get double-encoded.
        $channel = $doc->createElement('channel');
        $rss->appendChild($channel);
        $channel->appendChild($doc->createElement('title', get_bloginfo('name')));
        $channel->appendChild($doc->createElement('link', home_url('/')));
        $channel->appendChild($doc->createElement('description', get_bloginfo('description')));

        $tracks = get_posts(['post_type' => 'bhs_track', 'post_status' => 'publish', 'posts_per_page' => 50, 'orderby' => 'date', 'order' => 'DESC']);
        foreach ($tracks as $p) {
            $aid = (int) get_post_meta($p->ID, '_bhs_audio_id', true);
            $external_url = get_post_meta($p->ID, '_bhs_external_audio_url', true);
            $audio_url = $aid ? wp_get_attachment_url($aid) : $external_url;

            // WHY: this public feed must respect bhs_track_access_allowed (no
            // logged-in user here, so gated tracks are denied). A gated track
            // uses its _bhs_preview_audio_id clip if set, otherwise is omitted.
            $access_allowed = apply_filters('bhs_track_access_allowed', true, $p->ID);
            if (!$access_allowed) {
                $preview_id = (int) get_post_meta($p->ID, '_bhs_preview_audio_id', true);
                $audio_url = $preview_id ? wp_get_attachment_url($preview_id) : '';
                $aid = $preview_id; // so the mime-type lookup reads the preview file's type
            }
            if (!$audio_url) continue;

            $item = $doc->createElement('item');
            $item->appendChild($doc->createElement('title', $p->post_title));
            $item->appendChild($doc->createElement('guid', (string) $p->ID));
            $item->appendChild($doc->createElement('itunes:author', get_post_meta($p->ID, '_bhs_artist', true)));
            $item->appendChild($doc->createElement('pubDate', get_the_date(DATE_RSS, $p)));

            // Use the attachment's stored mime type; for external URLs guess
            // from the extension, defaulting to audio/mpeg.
            $mime = $aid ? get_post_mime_type($aid) : '';
            if (!$mime) {
                $filetype = wp_check_filetype($audio_url);
                $mime = $filetype['type'] ?: 'audio/mpeg';
            }
            $enclosure = $doc->createElement('enclosure');
            $enclosure->setAttribute('url', $audio_url);
            $enclosure->setAttribute('type', $mime);
            $item->appendChild($enclosure);

            $channel->appendChild($item);
        }

        $xml = $doc->saveXML();
        status_header(200);
        header('Content-Type: application/rss+xml; charset=UTF-8');
        echo $xml;
        exit;
    }
}

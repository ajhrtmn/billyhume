<?php
if (!defined('ABSPATH')) exit;

/**
 * The actual "aggregator, gatekept by the artist" mechanism.
 *
 * IMPORT: an admin adds a bhs_feed_source post with an external feed
 * URL — deliberately restricted to the open RSS/Atom podcast-feed
 * standard (RSS 2.0 + the iTunes/Podcasting-2.0 enclosure convention),
 * NOT any single platform's proprietary API. That standard happens to be
 * exactly what Funkwhale itself publishes for every channel, so a
 * Funkwhale channel's RSS link is a first-class citizen here — but so is
 * any other server that speaks the same open feed format (including
 * another bh-streaming site's own export, below). This plugin is not,
 * and should never become, Funkwhale-exclusive; Funkwhale is simply the
 * reference example of a server built on this open standard.
 * validate_is_open_feed() below is what actually enforces "open standard
 * feed, not an arbitrary URL" at save/sync time. Uses fetch_feed(),
 * WordPress core's own feed parser (built on SimplePie) — not custom XML
 * parsing, because WordPress already solved that problem. Imported
 * tracks become real bhs_track posts flagged _bhs_source = 'external', so
 * the rest of the catalog/player code never needs a separate code path
 * for "local" vs "aggregated" — it's all just tracks. Nothing gets
 * pulled in without an admin explicitly adding that feed URL first —
 * this is curation, not open following.
 *
 * EXPORT: this site's own catalog, re-served as a standard RSS feed
 * with real <enclosure> audio tags and iTunes-namespace metadata, so
 * any podcast app — or another bh-streaming site's importer — can
 * subscribe to it. Same format on both ends by design.
 */
class BHS_Feeds {
    const CRON_HOOK = 'bhs_sync_feeds';

    public static function init() {
        add_action('add_meta_boxes', [self::class, 'add_meta_box']);
        add_action('save_post_bhs_feed_source', [self::class, 'save_feed_source']);
        add_action('admin_post_bhs_sync_feed', [self::class, 'handle_manual_sync']);
        add_action(self::CRON_HOOK, [self::class, 'sync_all']);
        // "Share your feed" panel — this site's own export URL plus a
        // scannable QR code — shown on the Feed Sources list screen so
        // getting connected with another artist/instance is a copy-paste
        // or a phone-camera away, not a support ticket.
        add_action('admin_notices', [self::class, 'render_share_panel']);

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), 'twicedaily', self::CRON_HOOK);
        }
    }

    /* ---------- open-standard enforcement ---------- */

    // Restricts feed sources to the open RSS/Atom podcast-feed standard
    // (what fetch_feed() itself parses) rather than trusting any URL that
    // merely responds with SOME content — a 200 OK from an arbitrary API
    // isn't "an open feed," it's just a URL. Requires at least one item
    // with a real enclosure (the actual open-standard signal for "this is
    // a media feed"), which is exactly what Funkwhale channels, other
    // Podcasting-2.0 feeds, and this plugin's own export all provide.
    private static function validate_is_open_feed($feed) {
        if (is_wp_error($feed)) return false;
        $items = $feed->get_items(0, 5);
        foreach ($items as $item) {
            if ($item->get_enclosure() && $item->get_enclosure()->get_link()) return true;
        }
        return false;
    }

    // This site's own subscribable feed URL — the thing to hand another
    // artist, another bh-streaming instance, or a Funkwhale/podcast app.
    public static function own_feed_url() {
        return esc_url_raw(rest_url('bhs/v1/feed.xml'));
    }

    public static function render_share_panel() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->post_type !== 'bhs_feed_source') return;

        $url = self::own_feed_url();
        // A public QR-image service, not a bundled dependency — this
        // only ever runs in wp-admin (never on a visitor-facing page),
        // and avoids shipping/maintaining a QR-rendering library for one
        // small convenience. If offline/no-outbound-requests hosting
        // becomes a real requirement later, swap this for a bundled PHP
        // QR encoder without changing anything else here — the URL this
        // encodes is the only thing that matters.
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

    public static function register_routes() {
        register_rest_route('bhs/v1', '/feed.xml', [
            'methods' => 'GET', 'callback' => [self::class, 'export_feed'], 'permission_callback' => '__return_true',
        ]);
    }

    /* ---------- admin: feed source management ---------- */

    public static function add_meta_box() {
        add_meta_box('bhs_feed_source_url', 'Feed Details', [self::class, 'render_meta_box'], 'bhs_feed_source', 'normal', 'high');
    }

    public static function render_meta_box($post) {
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

    public static function save_feed_source($post_id) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!isset($_POST['bhs_feed_source_nonce']) || !wp_verify_nonce($_POST['bhs_feed_source_nonce'], 'bhs_save_feed_source')) return;
        if (!current_user_can('edit_post', $post_id)) return;
        if (!isset($_POST['bhs_feed_url'])) return;

        $url = esc_url_raw(trim($_POST['bhs_feed_url']));
        update_post_meta($post_id, '_bhs_feed_url', $url);
        if ($url) self::sync_one($post_id, $url);
    }

    public static function handle_manual_sync() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_GET['_wpnonce'] ?? '', 'bhs_sync_feed')) {
            wp_die('Not allowed.');
        }
        $feed_id = (int) ($_GET['feed_id'] ?? 0);
        $url = get_post_meta($feed_id, '_bhs_feed_url', true);
        if ($feed_id && $url) self::sync_one($feed_id, $url);
        wp_safe_redirect(get_edit_post_link($feed_id, ''));
        exit;
    }

    /* ---------- import ---------- */

    // Automatic (twice-daily cron) AND manual (the "Sync now" button) —
    // WP-Cron only actually fires on a page load that happens to land
    // after the scheduled time, it isn't a true background daemon, so
    // a manual option alongside it means an admin isn't purely at the
    // mercy of site traffic patterns to refresh a feed right now.
    //
    // If the core's job queue is active (own-ur-shit 3.2.0+ — see
    // OUS_Jobs, and bh-registry's BHR_Verification::recheck_all() for
    // the first place this exact pattern got proven out), each feed
    // source gets queued as its own job instead of all of them fetching
    // inline in one cron tick — a real, not cosmetic, improvement here
    // specifically: fetch_feed() is a network call to someone else's
    // server, and one slow or hanging feed currently delays every OTHER
    // feed source behind it in the loop. Falls back to the original
    // all-inline behavior if the job queue isn't there — same
    // class_exists()-guarded optional-integration shape every other
    // cross-plugin touch in this ecosystem uses.
    public static function sync_all() {
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

        // Not per-feed work — a lightweight pass over already-imported
        // external tracks, unrelated to any single feed's own fetch —
        // so it stays a plain inline call either way rather than being
        // queued itself.
        self::check_external_track_health();
    }

    // The per-feed-source unit of work OUS_Jobs actually calls — see
    // bh-streaming.php's bootstrap for the guarded registration.
    public static function sync_one_job($args) {
        $feed_id = (int) ($args['feed_id'] ?? 0);
        if (!$feed_id || get_post_status($feed_id) !== 'publish') return;
        $url = get_post_meta($feed_id, '_bhs_feed_url', true);
        if ($url) self::sync_one($feed_id, $url);
    }

    /* ---------- source-down handling (task 39) ---------- */
    // This ecosystem never re-hosts a featured artist's audio (see the
    // note on _bhs_external_audio_url above) — which means an external
    // track's playability depends entirely on THEIR host staying up,
    // something this site has zero control over. Silently 404ing mid-
    // playback with no explanation is a bad experience; the alternative
    // of downloading and re-hosting the file would break the whole
    // "your media stays yours" premise. This is the honest middle
    // ground: periodically check, and when a source is unreliable, SAY
    // SO — in the catalog and in the player — rather than pretending
    // everything's fine until a listener hits play and it just fails.
    //
    // Explicitly NOT this plugin's problem to solve: a track someone
    // actually bought/downloaded (bh-monetization-woo's purchase +
    // lossless/compressed delivery) is a real local file transferred to
    // the listener — that keeps working forever regardless of what
    // happens to any external host. This health check only ever applies
    // to STREAMING an externally-aggregated track, never to a completed
    // download already in someone's hands.
    const HEALTH_DOWN_AFTER_FAILS = 3; // consecutive failures before a track is treated as actually unavailable, not just a blip

    public static function check_external_track_health() {
        $tracks = get_posts([
            'post_type' => 'bhs_track', 'post_status' => 'publish', 'posts_per_page' => -1,
            'meta_key' => '_bhs_source', 'meta_value' => 'external', 'fields' => 'ids',
        ]);
        foreach ($tracks as $track_id) {
            $url = get_post_meta($track_id, '_bhs_external_audio_url', true);
            if (!$url) continue;

            // HEAD first (cheap); some hosts don't implement HEAD
            // correctly for media files, so fall back to a tiny ranged
            // GET rather than wrongly flagging a perfectly fine host.
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

            // Log only the TRANSITION (not every check, which runs on a
            // schedule and would otherwise flood the log with routine
            // "still fine" or "still down" restatements) — this was
            // previously invisible entirely; the only way to discover a
            // dead external feed was manually browsing track postmeta.
            // ok -> down/degraded logs a warning (a listener-facing
            // symptom is now live); down/degraded -> ok logs an info
            // (worth knowing recovery happened, e.g. after a host outage).
            if ($previous_health !== $new_health && class_exists('OUS_DebugLog')) {
                $level = ($new_health === 'ok') ? 'info' : 'warning';
                OUS_DebugLog::log($level, "External track source health changed: $previous_health -> $new_health", [
                    'track_id' => $track_id, 'url' => $url, 'consecutive_fails' => $fails,
                ], 'BH Streaming Feed Health');
            }
        }
    }

    // Read by BHS_API::track_payload() so the player can show a real
    // status rather than the listener just discovering it by a failed
    // play. 'ok' when never checked yet — a brand-new import isn't
    // treated as suspect before its first health pass ever runs.
    public static function source_health($track_id) {
        $health = get_post_meta($track_id, '_bhs_source_health', true);
        return $health ?: 'ok';
    }

    private static function sync_one($feed_source_id, $url) {
        require_once ABSPATH . WPINC . '/feed.php';
        $feed = fetch_feed($url);

        // QA fix: a transient network failure (timeout, DNS, host down)
        // used to fall straight through into validate_is_open_feed(),
        // which also returns false for a WP_Error — so the admin saw
        // "this didn't look like an open podcast/audio feed" for BOTH a
        // genuinely malformed feed AND a temporarily-unreachable host,
        // pointing them at the wrong problem ("check your feed URL")
        // when the real issue was "the remote host was unreachable, try
        // again." Now tracked as a distinct flag with its own message.
        if (is_wp_error($feed)) {
            if (class_exists('OUS_DebugLog')) {
                // A network call to someone else's server, failing — this is
                // exactly the class of "not this site's bug, but this site's
                // problem to notice" error the console exists for, so an
                // admin sees "this feed's been failing" without having to
                // manually re-check every feed source one by one.
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

        $items = $feed->get_items(0, 30); // a reasonable cap per sync — this isn't meant to backfill someone's entire archive on every run
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
                // Audio stays a remote URL rather than being downloaded and
                // re-hosted — this is a link to featured content, not a copy
                // of it. The originating site remains the source of truth.
                update_post_meta($pid, '_bhs_external_audio_url', esc_url_raw($enclosure->get_link()));
                $imported++;
            } catch (\Throwable $e) {
                // One malformed feed item (an unexpected shape from a
                // less-standard third-party feed) must not abort the
                // whole sync and lose every OTHER item in the same feed.
                if (class_exists('OUS_DebugLog')) {
                    OUS_DebugLog::log('error', 'Feed import: unexpected error on one item — ' . $e->getMessage(), ['feed_source_id' => $feed_source_id], 'BH Streaming');
                }
            }
        }

        update_post_meta($feed_source_id, '_bhs_last_synced', current_time('mysql'));
    }

    /* ---------- export ---------- */

    public static function export_feed() {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $rss = $doc->createElement('rss');
        $rss->setAttribute('version', '2.0');
        $rss->setAttribute('xmlns:itunes', 'http://www.itunes.com/dtds/podcast-1.0.dtd');
        $doc->appendChild($rss);

        // DOMDocument::createElement()'s $value becomes a text node, and
        // text nodes are already entity-escaped by DOMDocument itself on
        // saveXML() — calling htmlspecialchars() first double-encodes
        // (e.g. a title of "Rock & Roll" would serialize as
        // "Rock &amp;amp; Roll" instead of "Rock &amp; Roll"). Pass raw
        // values through and let DOMDocument do the one correct escape.
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
            if (!$audio_url) continue;

            $item = $doc->createElement('item');
            $item->appendChild($doc->createElement('title', $p->post_title));
            $item->appendChild($doc->createElement('guid', (string) $p->ID));
            $item->appendChild($doc->createElement('itunes:author', get_post_meta($p->ID, '_bhs_artist', true)));
            $item->appendChild($doc->createElement('pubDate', get_the_date(DATE_RSS, $p)));

            $enclosure = $doc->createElement('enclosure');
            $enclosure->setAttribute('url', $audio_url);
            $enclosure->setAttribute('type', 'audio/mpeg');
            $item->appendChild($enclosure);

            $channel->appendChild($item);
        }

        $xml = $doc->saveXML();
        $response = new WP_REST_Response($xml, 200);
        $response->header('Content-Type', 'application/rss+xml; charset=UTF-8');
        return $response;
    }
}

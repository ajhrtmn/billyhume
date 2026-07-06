<?php
if (!defined('ABSPATH')) exit;

/**
 * The actual "aggregator, gatekept by the artist" mechanism.
 *
 * IMPORT: an admin adds a bhs_feed_source post with an external feed
 * URL — any standard podcast/audio RSS feed, including another
 * bh-streaming site's own export (below), or a Funkwhale channel's RSS.
 * Uses fetch_feed(), WordPress core's own feed parser (built on
 * SimplePie) — not custom XML parsing, because WordPress already solved
 * that problem. Imported tracks become real bh_track posts flagged
 * _bhs_source = 'external', so the rest of the catalog/player code never
 * needs a separate code path for "local" vs "aggregated" — it's all
 * just tracks. Nothing gets pulled in without an admin explicitly
 * adding that feed URL first — this is curation, not open following.
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

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), 'twicedaily', self::CRON_HOOK);
        }
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

        echo '<p><label><strong>Feed URL</strong><br><input type="url" name="bhs_feed_url" value="' . esc_attr($url) . '" style="width:100%;" placeholder="https://example.com/feed.xml" required></label></p>';
        echo '<p class="description">Any standard podcast/audio RSS feed — including another bh-streaming site\'s own feed at <code>/wp-json/bhs/v1/feed.xml</code>.</p>';
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
    public static function sync_all() {
        $sources = get_posts(['post_type' => 'bhs_feed_source', 'post_status' => 'publish', 'posts_per_page' => -1]);
        foreach ($sources as $source) {
            $url = get_post_meta($source->ID, '_bhs_feed_url', true);
            if ($url) self::sync_one($source->ID, $url);
        }
    }

    private static function sync_one($feed_source_id, $url) {
        require_once ABSPATH . WPINC . '/feed.php';
        $feed = fetch_feed($url);
        if (is_wp_error($feed)) return;

        $items = $feed->get_items(0, 30); // a reasonable cap per sync — this isn't meant to backfill someone's entire archive on every run
        foreach ($items as $item) {
            $enclosure = $item->get_enclosure();
            if (!$enclosure || !$enclosure->get_link()) continue; // no audio, nothing to import

            $guid = $item->get_id();
            $existing = get_posts([
                'post_type' => 'bh_track', 'post_status' => 'any', 'posts_per_page' => 1,
                'meta_key' => '_bhs_source_guid', 'meta_value' => $guid, 'fields' => 'ids',
            ]);
            if ($existing) continue; // already imported, don't duplicate on re-sync

            $artist = $item->get_author() ? $item->get_author()->get_name() : '';
            $pid = wp_insert_post([
                'post_title' => $item->get_title() ?: 'Untitled',
                'post_type' => 'bh_track', 'post_status' => 'publish',
            ], true);
            if (is_wp_error($pid)) continue;

            update_post_meta($pid, '_bhs_artist', sanitize_text_field($artist));
            update_post_meta($pid, '_bhs_source', 'external');
            update_post_meta($pid, '_bhs_source_feed_id', $feed_source_id);
            update_post_meta($pid, '_bhs_source_guid', $guid);
            // Audio stays a remote URL rather than being downloaded and
            // re-hosted — this is a link to featured content, not a copy
            // of it. The originating site remains the source of truth.
            update_post_meta($pid, '_bhs_external_audio_url', esc_url_raw($enclosure->get_link()));
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

        $tracks = get_posts(['post_type' => 'bh_track', 'post_status' => 'publish', 'posts_per_page' => 50, 'orderby' => 'date', 'order' => 'DESC']);
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

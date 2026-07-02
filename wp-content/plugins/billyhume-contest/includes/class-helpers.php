<?php
if (!defined('ABSPATH')) exit;

class BH_Helpers {
    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'bh_votes';
    }

    // Legacy single-contest fallback: newest published contest. Used when a
    // shortcode or API call doesn't specify which contest it means — keeps
    // old embeds working exactly as before multi-contest support existed.
    public static function active_contest() {
        $q = new WP_Query([
            'post_type'      => 'bh_contest',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);
        return $q->posts[0] ?? 0;
    }

    // All published contests, newest first — used to populate admin
    // contest pickers (Results, Debug Tools) and the [bh_contest_player]
    // slug lookup.
    public static function all_contests() {
        $q = new WP_Query([
            'post_type'      => 'bh_contest',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);
        return $q->posts;
    }

    public static function find_by_slug($slug) {
        $q = new WP_Query([
            'post_type'      => 'bh_contest',
            'post_status'    => 'publish',
            'name'           => sanitize_title($slug),
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);
        return $q->posts[0] ?? 0;
    }

    // Turns a raw "contest" value from a shortcode attribute or REST param
    // (numeric ID, slug, or empty) into a validated, published contest ID.
    // Empty/invalid falls back to active_contest() so untargeted embeds and
    // older cached front-end code keep working.
    public static function resolve_contest($raw) {
        $raw = is_string($raw) ? trim($raw) : $raw;
        if ($raw === '' || $raw === null) return self::active_contest();

        if (is_numeric($raw)) {
            $id = (int) $raw;
            if (get_post_type($id) === 'bh_contest' && get_post_status($id) === 'publish') return $id;
            return 0;
        }
        return self::find_by_slug($raw);
    }

    // [bh_contest_player contest="..."] string an admin can copy-paste to
    // embed this specific contest anywhere.
    public static function shortcode_for($cid) {
        $post = get_post($cid);
        $ref  = ($post && $post->post_name) ? $post->post_name : $cid;
        return '[bh_contest_player contest="' . $ref . '"]';
    }

    // upcoming | open | closed | unscheduled — drives the admin status pill.
    public static function contest_status($cid) {
        $start = self::normalize_dt(get_post_meta($cid, '_bh_start', true));
        $end   = self::normalize_dt(get_post_meta($cid, '_bh_end', true));
        if (!$start || !$end) return 'unscheduled';
        $now = current_time('mysql');
        if ($now < $start) return 'upcoming';
        if ($now > $end)   return 'closed';
        return 'open';
    }

    // <input type="datetime-local"> submits "YYYY-MM-DDTHH:MM" (a literal T,
    // no seconds). current_time('mysql') returns "YYYY-MM-DD HH:MM:SS" (a
    // space, with seconds). Those two formats don't compare correctly as
    // plain strings — on any day matching the stored date, the T sorts
    // after the space regardless of actual time, which was making contests
    // read as permanently "Upcoming". Normalize to the same shape before
    // any comparison, here and wherever a raw value might have been saved
    // in the old format already.
    private static function normalize_dt($raw) {
        if (!$raw) return '';
        $raw = str_replace('T', ' ', trim($raw));
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $raw)) $raw .= ':00';
        return $raw;
    }

    public static function is_voting_open($cid) {
        return self::contest_status($cid) === 'open';
    }

    // Categories are stored on the contest as a simple JSON list — no new
    // post type/taxonomy needed. Empty/absent = single implicit "general"
    // category (slug ''), which is also what every vote cast before this
    // feature existed already uses, so nothing needs migrating.
    public static function categories($cid) {
        $raw = get_post_meta($cid, '_bh_categories', true);
        $list = $raw ? json_decode($raw, true) : [];
        return is_array($list) ? $list : [];
    }

    // Only worth showing a switcher for 2+ categories — one category is
    // the same as none, just with an unnecessary tab.
    public static function has_multiple_categories($cid) {
        return count(self::categories($cid)) >= 2;
    }

    // '' if the contest has no named categories, otherwise the first
    // defined category — used as the default when a request doesn't
    // specify one.
    public static function default_category($cid) {
        $cats = self::categories($cid);
        return $cats ? $cats[0]['slug'] : '';
    }

    // A category value is valid if it's '' on a contest with no categories,
    // or matches one of the contest's defined category slugs.
    public static function is_valid_category($cid, $slug) {
        $cats = self::categories($cid);
        if (!$cats) return $slug === '';
        foreach ($cats as $c) if ($c['slug'] === $slug) return true;
        return false;
    }

    // Turns free-text (one category name per line) into a slug+name list,
    // deduping slugs so two similarly-named categories don't collide.
    public static function parse_categories_input($text) {
        $out = [];
        $seen = [];
        foreach (preg_split('/[\r\n]+/', (string) $text) as $line) {
            $name = trim($line);
            if ($name === '') continue;
            $slug = sanitize_title($name);
            if (!$slug || isset($seen[$slug])) continue;
            $seen[$slug] = true;
            $out[] = ['slug' => $slug, 'name' => $name];
        }
        return $out;
    }

    // Counts pending submissions too: submitting earns the bonus vote and
    // blocks a second entry the moment it is sent, before admin approval.
    public static function has_submitted($uid, $cid) {
        $q = new WP_Query([
            'post_type'      => 'bh_submission',
            'author'         => $uid,
            'post_status'    => 'any',
            'meta_key'       => '_bh_contest_id',
            'meta_value'     => $cid,
            'fields'         => 'ids',
            'posts_per_page' => 1,
            'no_found_rows'  => true,
        ]);
        return !empty($q->posts);
    }

    // Vote limit applies per category independently — submitting a track
    // earns the bonus vote in every category, not just one. A contest can
    // override the global base/bonus (see the Contest Rules metabox);
    // leaving those fields blank falls back to the plugin-wide default.
    public static function vote_limit($uid, $cid) {
        $base  = get_post_meta($cid, '_bh_vote_base', true);
        $bonus = get_post_meta($cid, '_bh_vote_bonus', true);
        $base  = ($base === '' || $base === false) ? BH_VOTE_BASE : max(0, (int) $base);
        $bonus = ($bonus === '' || $bonus === false) ? BH_VOTE_BONUS : max(0, (int) $bonus);
        return $base + (self::has_submitted($uid, $cid) ? $bonus : 0);
    }

    public static function user_vote_count($uid, $cid, $category = '') {
        global $wpdb;
        $t = self::table();
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $t WHERE user_id = %d AND contest_id = %d AND category = %s", $uid, $cid, $category
        ));
    }

    public static function submission_count($cid, $status = 'publish') {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
             WHERE p.post_type = 'bh_submission' AND p.post_status = %s
               AND m.meta_key = '_bh_contest_id' AND m.meta_value = %d",
            $status, $cid
        ));
    }

    public static function vote_count($cid, $category = null) {
        global $wpdb;
        if ($category === null) {
            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM " . self::table() . " WHERE contest_id = %d", $cid
            ));
        }
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::table() . " WHERE contest_id = %d AND category = %s", $cid, $category
        ));
    }

    public static function user_total_votes($uid) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::table() . " WHERE user_id = %d", $uid
        ));
    }

    public static function allowed_audio() {
        return ['mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4'];
    }

    public static function artist_for($post) {
        return get_post_meta($post->ID, '_bh_artist_name', true)
            ?: get_the_author_meta('display_name', $post->post_author);
    }
}

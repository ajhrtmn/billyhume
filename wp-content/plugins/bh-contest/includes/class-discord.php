<?php
if (!defined('ABSPATH')) exit;

/**
 * Discord webhook notifications — the one integration in this batch that
 * doesn't need any external app registration, API key, or OAuth flow.
 * A Discord "incoming webhook" is just a URL a server admin generates
 * from inside their own Discord server's channel settings and pastes in
 * here; posting to it is a single unauthenticated HTTP request. No
 * credentials for this plugin to store or protect beyond that one URL.
 *
 * Deliberately per-contest (see the Discord Webhook URL field in the
 * Contest Rules & Results metabox) rather than site-wide, since
 * different contests may want to announce to different channels/servers
 * — and leaving it blank disables notifications for that contest
 * entirely, with zero behavior change from before this existed.
 *
 * Everything posts as a rich embed rather than plain text — Discord
 * renders these with a colored sidebar, structured fields, and an
 * optional link, which reads far better in a busy channel than a plain
 * message. All embed construction funnels through send() below, so
 * every trigger gets that treatment for free.
 *
 * Automatic triggers: a submission being approved — not submitted; this
 * webhook is public, so announcing before anyone's reviewed it would
 * mean the whole channel sees every submission including rejected ones
 * (class-admin.php maybe_notify_approval(), hooked to the actual
 * pending→publish transition so re-saving an already-approved
 * submission doesn't re-announce it) — and voting being explicitly
 * started via the "Start now" quick action (class-admin.php
 * quick_schedule() — note this does NOT fire if a pre-scheduled start
 * date just silently arrives with no admin action, since that would
 * need a cron-style check this plugin doesn't have).
 *
 * Deliberately NOT automatic: the results announcement. Publishing
 * results (the "Publish Results to Public" checkbox) only makes them
 * visible on the site — announcing them is a separate, explicit action
 * ("Send Winner Notifications" in the same metabox, see
 * class-admin.php send_winner_notifications()), so an admin can publish,
 * sanity-check the numbers, and announce whenever they're actually
 * ready rather than the moment they check a box.
 *
 * Manual trigger: admins can also send an arbitrary message on demand
 * (see the "Announce to Discord" box in the Contest Rules & Results
 * metabox) — useful for anything that doesn't map to a fixed lifecycle
 * moment, like "going live for the reveal in 5 minutes," which is an
 * inherently human-timed event rather than something the site itself
 * would ever know to announce automatically.
 */
class BH_Discord {
    const COLOR_SUBMISSION = 0x5865F2; // Discord blurple — neutral, informational
    const COLOR_VOTING     = 0x1DB954; // green — "something just opened"
    const COLOR_RESULTS    = 0xFFD700; // gold — the big moment
    const COLOR_ANNOUNCE   = 0x5865F2;

    public static function init() {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function register_routes() {
        register_rest_route('bh/v1', '/discord/announce', [
            'methods' => 'POST', 'callback' => [self::class, 'handle_announce'],
            'permission_callback' => fn() => current_user_can('manage_options'),
        ]);
    }

    public static function handle_announce($req) {
        $cid = (int) $req->get_param('contest');
        $message = sanitize_textarea_field((string) $req->get_param('message'));
        if (!$cid || $message === '') {
            return new WP_Error('bad_request', 'A contest and a message are both required.', ['status' => 400]);
        }
        $sent = self::send($cid, $message, '', [], self::COLOR_ANNOUNCE);
        return new WP_REST_Response(['success' => (bool) $sent, 'sent' => (bool) $sent], 200);
    }

    // Core embed sender — every trigger below funnels through this.
    // Fire-and-forget: a webhook failing (bad URL, Discord outage, rate
    // limit) should never block or fail the actual action that triggered
    // it — submitting a track still succeeds even if the notification
    // doesn't go through. wp_remote_post() with a short timeout and no
    // error propagation is the right shape for that.
    //
    // Returns true/false for whether a request was actually attempted
    // (not whether Discord accepted it, since 'blocking' => false means
    // this plugin deliberately never waits to find out) — used by the
    // manual announce endpoint above to tell an admin "no webhook is
    // configured for this contest" versus "sent."
    //
    // $fields is Discord's embed field format: [['name' => ..., 'value'
    // => ..., 'inline' => true|false], ...] — up to 25 per embed.
    public static function send($contest_id, $title, $description = '', $fields = [], $color = self::COLOR_ANNOUNCE, $url = '') {
        $webhook = trim((string) get_post_meta($contest_id, '_bh_discord_webhook', true));
        if ($webhook === '' || !wp_http_validate_url($webhook)) return false;

        $embed = ['title' => mb_substr($title, 0, 256), 'color' => $color, 'timestamp' => gmdate('c')];
        if ($description !== '') $embed['description'] = mb_substr($description, 0, 4096);
        if ($fields) $embed['fields'] = array_slice($fields, 0, 25);
        if ($url && wp_http_validate_url($url)) $embed['url'] = $url;

        wp_remote_post($webhook, [
            'timeout'  => 5,
            'blocking' => false,
            'headers'  => ['Content-Type' => 'application/json'],
            'body'     => wp_json_encode(['embeds' => [$embed]]),
        ]);
        return true;
    }

    /* ---------- the three automatic triggers ---------- */

    public static function notify_submission($contest_id, $title, $artist, $audio_url = '') {
        self::send(
            $contest_id,
            '🎵 New entry approved!',
            "**{$title}** by {$artist}",
            [],
            self::COLOR_SUBMISSION,
            $audio_url
        );
    }

    public static function notify_voting_open($contest_id) {
        $fields = [];
        self::send(
            $contest_id,
            '🗳️ Voting is now open!',
            '**' . get_the_title($contest_id) . '**',
            $fields,
            self::COLOR_VOTING
        );
    }

    // One embed for the whole announcement rather than one message per
    // category — keeps it to a single webhook request (avoiding any
    // rate-limit concern from firing several messages back to back) and
    // reads as one cohesive results post rather than a flood of separate
    // ones.
    public static function notify_results($contest_id) {
        $fields = [];

        foreach (BH_Helpers::categories($contest_id) as $cat) {
            $results = BH_API::category_results($contest_id, $cat['slug']);
            if (!$results) continue;
            $fields[] = ['name' => '🎵 ' . $cat['name'], 'value' => self::medal_lines($results), 'inline' => false];
        }

        $overall = BH_Reveal::overall_results($contest_id);
        if ($overall) {
            $fields[] = ['name' => '⭐ Overall', 'value' => self::medal_lines($overall), 'inline' => false];
        }

        self::send(
            $contest_id,
            '🏆 Results are in!',
            '**' . get_the_title($contest_id) . '**',
            $fields,
            self::COLOR_RESULTS
        );
    }

    // Top 3 of a ranked results array, medal-emoji style — shared by both
    // the per-category and overall sections of notify_results().
    private static function medal_lines($results) {
        $medals = ['🥇', '🥈', '🥉'];
        $lines = [];
        foreach (array_slice($results, 0, 3) as $i => $r) {
            $lines[] = ($medals[$i] ?? '#' . ($i + 1)) . ' **' . $r['title'] . '** — ' . $r['artist'] . ' (' . $r['votes'] . ' votes)';
        }
        return implode("\n", $lines);
    }
}

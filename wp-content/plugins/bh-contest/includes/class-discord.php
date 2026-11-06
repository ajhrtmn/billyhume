<?php
if (!defined('ABSPATH')) exit;

/**
 * Per-contest Discord webhook notifications, posted as rich embeds via send().
 * Automatic: submission approved, and voting started via "Start now" (not when a
 * scheduled start just arrives). Results and ad-hoc announcements are manual.
 */
class BH_Discord {
    const COLOR_SUBMISSION = 0x5865F2; // Discord blurple — neutral, informational
    const COLOR_VOTING     = 0x1DB954; // green — "something just opened"
    const COLOR_RESULTS    = 0xFFD700; // gold — the big moment
    const COLOR_ANNOUNCE   = 0x5865F2;

    public static function init(): void {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function register_routes(): void {
        register_rest_route('bh/v1', '/discord/announce', [
            'methods' => 'POST', 'callback' => [self::class, 'handle_announce'],
            'permission_callback' => fn() => current_user_can('manage_options'),
        ]);
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function handle_announce(\WP_REST_Request $req) {
        $cid = (int) $req->get_param('contest');
        $message = sanitize_textarea_field((string) $req->get_param('message'));
        if (!$cid || $message === '') {
            return new WP_Error('bad_request', 'A contest and a message are both required.', ['status' => 400]);
        }
        $sent = self::send($cid, $message, '', [], self::COLOR_ANNOUNCE);
        return new WP_REST_Response(['success' => (bool) $sent, 'sent' => (bool) $sent], 200);
    }

    // Fire-and-forget (non-blocking), so a webhook failure never fails the triggering
    // action. Returns whether a request was attempted, not whether Discord accepted it.
    // $fields: [['name' => ..., 'value' => ..., 'inline' => bool], ...], max 25.
    /** @param array<int, array<string, mixed>> $fields */
    public static function send(int $contest_id, string $title, string $description = '', array $fields = [], int $color = self::COLOR_ANNOUNCE, string $url = ''): bool {
        $webhook = trim((string) get_post_meta($contest_id, '_bh_discord_webhook', true));
        if ($webhook === '') return false; // no webhook configured — not an error, most contests don't have one
        if (!wp_http_validate_url($webhook)) {
            // Log a configured-but-invalid webhook, throttled per contest.
            if (class_exists('OUS_DebugLog')) {
                OUS_DebugLog::log_throttled('warning', 'bh_discord_bad_webhook_' . $contest_id, 3600,
                    'Discord webhook is configured for this contest but failed URL validation — notifications are silently not being sent.', ['contest_id' => $contest_id], 'BH Contest Discord'
                );
            }
            return false;
        }

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

    // $is_file_update rewords the post for an approved swap on an announced entry.
    public static function notify_submission(int $contest_id, string $title, string $artist, string $audio_url = '', bool $is_file_update = false): void {
        self::send(
            $contest_id,
            $is_file_update ? '🔄 Entry file updated' : '🎵 New entry approved!',
            "**{$title}** by {$artist}",
            [],
            self::COLOR_SUBMISSION,
            $audio_url
        );
    }

    public static function notify_voting_open(int $contest_id): void {
        $fields = [];
        self::send(
            $contest_id,
            '🗳️ Voting is now open!',
            '**' . get_the_title($contest_id) . '**',
            $fields,
            self::COLOR_VOTING
        );
    }

    // One embed for all categories: a single request, no rate-limit risk.
    public static function notify_results(int $contest_id): void {
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

    // Top 3 lines with medals, indexed by rank (ties share a medal, like the email path).
    /** @param array<int, array<string, mixed>> $results */
    private static function medal_lines($results): string {
        $medals = ['🥇', '🥈', '🥉'];
        $lines = [];
        foreach (array_slice($results, 0, 3) as $r) {
            $rank = (int) ($r['rank'] ?? 0);
            $lines[] = ($medals[$rank - 1] ?? '#' . $rank) . ' **' . $r['title'] . '** — ' . $r['artist'] . ' (' . $r['votes'] . ' votes)';
        }
        return implode("\n", $lines);
    }
}

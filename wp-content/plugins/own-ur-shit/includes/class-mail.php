<?php
if (!defined('ABSPATH')) exit;

/**
 * BH_Mail — the shared transactional-email interface ROADMAP-platform-
 * evolution.md Section 2.4 called for: one send() every plugin routes
 * through, instead of each one calling wp_mail() directly and hand-
 * rolling its own success/failure bookkeeping. Before this class, the
 * exact same seven-line pattern (send, emit bhcore/email_sent on
 * success, log a warning via OUS_DebugLog on failure) was copy-pasted
 * across OUS_Notifications, BH_Auth, OUS_Campaigns, bh-contest's
 * class-api.php, and bh-monetization-woo's class-gifts.php — this is
 * that pattern, written once.
 *
 * Real ESP swap point, not yet built: self::deliver() is the ONLY place
 * that touches wp_mail(). A real transactional provider (Postmark/
 * SendGrid/SES) — for actual delivery guarantees, bounce/complaint
 * handling, and not landing in spam, none of which wp_mail() alone ever
 * provides — replaces that one method's body later; every call site
 * above stays unchanged, which is the entire point of routing through
 * here instead of calling wp_mail() directly.
 *
 * USAGE, from any plugin:
 *
 *   if (class_exists('BH_Mail')) {
 *       BH_Mail::send([
 *           'user_id' => $user_id,        // OR 'to' => 'someone@example.com' (no account needed — gift/invite emails use this)
 *           'subject' => 'Confirm your email',
 *           'body'    => "Plain text or, with 'html' => true, HTML.",
 *           'source'  => 'BH Auth',       // debug-log label + BH_Event payload context on failure/success
 *       ]);
 *   }
 *
 * Returns the same bool wp_mail() itself returns (true = handed to the
 * configured transport, NOT "delivered" — wp_mail() never guarantees
 * that, which is exactly the gap the ESP swap point above exists to
 * eventually close).
 */
class BH_Mail {
    /**
     * @param array $args {
     *   @type string $to           Raw recipient email. Takes priority over user_id if both given.
     *   @type int    $user_id      Resolved to that user's email via get_userdata() when 'to' isn't set.
     *   @type string $subject      Required.
     *   @type string $body         Required.
     *   @type bool   $html         Adds the HTML content-type header. Default false (plain text).
     *   @type array  $headers      Extra wp_mail() headers, merged with the html header above if both given.
     *   @type string $source       Human-readable label for the OUS_DebugLog entry and BH_Event payload — e.g. 'BH Auth', 'BH Contest Submission'. Defaults to 'BH Mail'.
     *   @type array  $log_context  Extra key/value pairs merged into the failure log (e.g. ['submission_id' => $id]) — whatever detail helps trace WHICH send failed.
     * }
     * @param array<string, mixed> $args
     * @return bool Same as wp_mail()'s own return value.
     */
    public static function send(array $args): bool {
        $subject = (string) ($args['subject'] ?? '');
        $body = (string) ($args['body'] ?? '');
        $user_id = (int) ($args['user_id'] ?? 0);

        $to = $args['to'] ?? '';
        if (!$to && $user_id) {
            $user = get_userdata($user_id);
            $to = $user ? $user->user_email : '';
        }
        if (!$to || !$subject || !$body) return false;

        $headers = (array) ($args['headers'] ?? []);
        if (!empty($args['html'])) $headers[] = 'Content-Type: text/html; charset=UTF-8';

        $sent = self::deliver($to, $subject, $body, $headers);
        $source = (string) ($args['source'] ?? 'BH Mail');

        if ($sent && class_exists('BH_Event')) {
            // Same choke point OUS_Notifications' own send_email_now()
            // already fed — every plugin routing through here now
            // shows up in the CRM's unified per-person activity
            // timeline the same way, not just the queued-notification
            // path.
            BH_Event::emit('bhcore/email_sent', [
                'user_id' => $user_id, 'subject_type' => 'email', 'subject_id' => 0,
                'payload' => ['title' => $subject, 'source' => $source],
            ]);
        } elseif (!$sent && class_exists('OUS_DebugLog')) {
            OUS_DebugLog::log(
                'warning',
                $source . ' email failed to send (wp_mail() returned false).',
                array_merge(['user_id' => $user_id, 'to' => $to], (array) ($args['log_context'] ?? [])),
                $source
            );
        }

        return $sent;
    }

    /** The one seam a real ESP integration replaces later — see class docblock. */
    /** @param array<int, string> $headers */
    private static function deliver(string $to, string $subject, string $body, array $headers): bool {
        return wp_mail($to, $subject, $body, $headers);
    }
}

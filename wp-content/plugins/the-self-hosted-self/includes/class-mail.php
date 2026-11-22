<?php
if (!defined('ABSPATH')) exit;

/**
 * Transactional email: wraps wp_mail(), emits bhcore/email_sent or logs a warning.
 *   BH_Mail::send(['user_id' => $id (or 'to' => $email), 'subject' => ..., 'body' => ..., 'source' => ...]);
 * Returns true when handed to the transport, not when delivered.
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
     *   @type string $source       Label for the debug-log entry and BH_Event payload. Defaults to 'BH Mail'.
     *   @type array  $log_context  Extra key/value pairs merged into the failure log.
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
            // Feeds the CRM's per-person activity timeline.
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

    /**
     * The only place that calls wp_mail(): the swap point for a real ESP provider.
     *
     * @param array<int, string> $headers
     */
    private static function deliver(string $to, string $subject, string $body, array $headers): bool {
        return wp_mail($to, $subject, $body, $headers);
    }
}

<?php
if (!defined('ABSPATH')) exit;

/**
 * Abstracted SEPARATELY from BHL_StreamEngine, per wondrous-mixing-
 * forest.md's own "Decided" call: "chat is abstracted SEPARATELY from
 * the video engine (its own small interface) so Owncast's bundled chat
 * can later be replaced by a custom polling-based one (matching
 * class-jam.php's own proven pattern) independent of which video
 * engine is active underneath." Keeping this its own interface — not
 * a method on BHL_StreamEngine — is exactly what makes that later swap
 * possible without touching the video-engine choice at all.
 */
interface BHL_Chat {
    public function get_embed_html(); // -> string (an <iframe>-based embed), '' if not configured/not available
    public function is_configured();
}

/**
 * v1's only implementation — Owncast's own bundled chat, embedded via
 * its documented /embed/chat route (owncast.online/docs/embed/), the
 * same real, no-custom-build-needed feature get_embed_html() for video
 * already leans on. A future BHL_PollingChat (matching class-jam.php's
 * own 2-4s-interval polling pattern, this ecosystem's proven low-risk
 * real-time mechanism for ordinary shared hosting) would implement
 * this same interface without bh-live's video-embed code ever needing
 * to change.
 */
class BHL_OwncastChat implements BHL_Chat {
    public function is_configured() {
        $s = BHL_OwncastEngine::settings();
        return !empty($s['server_url']);
    }

    // The interactive route (not /embed/chat/readonly) — AJ's own call
    // on scope was explicitly "two-way interactive," and Owncast's own
    // chat iframe handles a viewer's display-name/access-token flow
    // itself, so nothing further is needed here to make posting work.
    public function get_embed_html() {
        $s = BHL_OwncastEngine::settings();
        if (empty($s['server_url'])) return '';
        $src = esc_url($s['server_url'] . '/embed/chat');
        return '<iframe src="' . $src . '" style="width:100%;height:100%;min-height:400px;border:0;"></iframe>';
    }
}

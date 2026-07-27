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
 *
 * render() takes the current stream's post ID rather than the
 * embed-only get_embed_html() this started as (audit pass 2026-07-26,
 * adding BHL_PollingChat/BHL_WorkersChat): a native (non-iframe) widget
 * needs to know WHICH stream's messages to poll/relay, and an iframe-
 * based implementation (BHL_OwncastChat) can simply ignore the
 * argument. 'type' tells the front end whether to just inject 'html'
 * as-is (an iframe is self-contained) or additionally call the native
 * chat widget's own JS init function against it.
 */
interface BHL_Chat {
    public function is_configured();
    public function render($stream_id); // -> ['type' => 'iframe'|'native', 'html' => string]
}

/**
 * v1's default for the Owncast engine — Owncast's own bundled chat,
 * embedded via its documented /embed/chat route
 * (owncast.online/docs/embed/), the same real, no-custom-build-needed
 * feature get_embed_html() for video already leans on.
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
    // $stream_id is unused — Owncast has exactly one chat room, tied to
    // the server itself, not per-bhl_stream-post.
    public function render($stream_id) {
        $s = BHL_OwncastEngine::settings();
        if (empty($s['server_url'])) return ['type' => 'iframe', 'html' => ''];
        $src = esc_url($s['server_url'] . '/embed/chat');
        return ['type' => 'iframe', 'html' => '<iframe src="' . $src . '" style="width:100%;height:100%;min-height:400px;border:0;"></iframe>'];
    }
}

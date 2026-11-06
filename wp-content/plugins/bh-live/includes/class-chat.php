<?php
if (!defined('ABSPATH')) exit;

/**
 * Chat contract, separate from BHL_StreamEngine so chat can be swapped
 * independently. render() takes the stream post ID; 'type' says whether the
 * front end injects 'html' as-is (iframe) or also mounts the native widget.
 */
interface BHL_Chat {
    public function is_configured(): bool;
    /** @return array{type:string, html:string} */
    public function render(int $stream_id): array;
}

/**
 * Owncast's bundled chat via its /embed/chat route.
 */
class BHL_OwncastChat implements BHL_Chat {
    public function is_configured(): bool {
        $s = BHL_OwncastEngine::settings();
        return !empty($s['server_url']);
    }

    // Interactive route (not /embed/chat/readonly); Owncast has one chat room per server, so $stream_id is unused.
    /** @return array{type:string, html:string} */
    public function render(int $stream_id): array {
        $s = BHL_OwncastEngine::settings();
        if (empty($s['server_url'])) return ['type' => 'iframe', 'html' => ''];
        $src = esc_url($s['server_url'] . '/embed/chat');
        return ['type' => 'iframe', 'html' => '<iframe src="' . $src . '" style="width:100%;height:100%;min-height:400px;border:0;"></iframe>'];
    }
}

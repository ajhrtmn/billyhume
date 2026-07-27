<?php
if (!defined('ABSPATH')) exit;

/**
 * Read-only public API — /bhl/v1/status (current live state) and
 * /bhl/v1/replays (past ended-with-replay streams that actually have a
 * replay file attached). Mirrors BHS_API/BHV_API's shape.
 */
class BHL_API {
    public static function register_routes() {
        register_rest_route('bhl/v1', '/status', [
            'methods' => 'GET', 'callback' => [self::class, 'get_status'], 'permission_callback' => '__return_true',
        ]);
        register_rest_route('bhl/v1', '/replays', [
            'methods' => 'GET', 'callback' => [self::class, 'get_replays'], 'permission_callback' => '__return_true',
        ]);
    }

    // The REST response reads the CURRENT bhl_stream record (fast, no
    // network call) rather than calling the active engine's
    // get_status() directly on every page load — BHL_Streams' own
    // polling job is what keeps that record in sync with the real
    // engine's status, same "poll on a schedule, read the cached
    // result on request" split bh-streaming's own feed-health checks
    // already use. Which engine is "active" (Owncast vs Cloudflare
    // Stream Live) only matters for get_embed_html()/chat below — the
    // bhl_stream record itself is engine-agnostic.
    public static function get_status() {
        $current = class_exists('BHL_Streams') ? BHL_Streams::current_live_stream() : null;
        if (!$current) {
            return new WP_REST_Response(['success' => true, 'online' => false, 'embed_html' => ''], 200);
        }
        $engine = class_exists('BHL_EngineRegistry') ? BHL_EngineRegistry::active() : null;
        $chat = class_exists('BHL_EngineRegistry') ? BHL_EngineRegistry::active_chat() : null;
        $chat_render = ($chat && $chat->is_configured()) ? $chat->render($current->ID) : ['type' => 'iframe', 'html' => ''];
        return new WP_REST_Response([
            'success'         => true,
            'online'          => true,
            'title'           => $current->post_title,
            'started_at'      => get_post_meta($current->ID, '_bhl_started_at', true),
            'stream_id'       => $current->ID,
            'embed_html'      => $engine ? $engine->get_embed_html() : '',
            // Chat is deliberately SEPARATE fields from embed_html, not
            // baked into it — the whole point of abstracting it
            // separately (class-chat.php's own docblock) is that a
            // future chat swap only ever touches these two fields.
            'chat_type'       => $chat_render['type'],
            'chat_embed_html' => $chat_render['html'],
        ], 200);
    }

    public static function get_replays() {
        $posts = get_posts([
            'post_type' => 'bhl_stream', 'post_status' => 'publish', 'posts_per_page' => -1,
            'orderby' => 'date', 'order' => 'DESC',
            'meta_query' => [['key' => '_bhl_status', 'value' => BHL_PostTypes::STATUS_ENDED]],
        ]);
        $out = [];
        foreach ($posts as $p) {
            $aid = (int) get_post_meta($p->ID, '_bhl_replay_attachment_id', true);
            if (!$aid) continue; // no replay ever attached — nothing to list
            $url = wp_get_attachment_url($aid);
            if (!$url) continue;
            $out[] = [
                'id'         => $p->ID,
                'title'      => $p->post_title,
                'url'        => $url,
                'started_at' => get_post_meta($p->ID, '_bhl_started_at', true),
                'ended_at'   => get_post_meta($p->ID, '_bhl_ended_at', true),
            ];
        }
        return new WP_REST_Response(['success' => true, 'replays' => $out], 200);
    }
}

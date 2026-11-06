<?php
if (!defined('ABSPATH')) exit;

/**
 * Read-only public API: /bhl/v1/status and /bhl/v1/replays.
 */
class BHL_API {
    public static function register_routes(): void {
        register_rest_route('bhl/v1', '/status', [
            'methods' => 'GET', 'callback' => [self::class, 'get_status'], 'permission_callback' => '__return_true',
        ]);
        register_rest_route('bhl/v1', '/replays', [
            'methods' => 'GET', 'callback' => [self::class, 'get_replays'], 'permission_callback' => '__return_true',
        ]);
    }

    // Reads the cached bhl_stream record (kept in sync by BHL_Streams' polling)
    // rather than calling the engine on every request.
    public static function get_status(): \WP_REST_Response {
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
            // Chat is kept in its own fields so a chat swap never touches embed_html.
            'chat_type'       => $chat_render['type'],
            'chat_embed_html' => $chat_render['html'],
        ], 200);
    }

    public static function get_replays(): \WP_REST_Response {
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

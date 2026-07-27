<?php
if (!defined('ABSPATH')) exit;

/**
 * Picks which BHL_StreamEngine implementation is active — this is the
 * whole payoff of the abstraction: BHL_Streams/BHL_API/the wizard all
 * go through active() rather than ever hardcoding BHL_OwncastEngine,
 * so adding a third engine later is a registry entry + a class, and
 * switching between Owncast and Cloudflare Stream Live today is a
 * radio button, not a code change.
 */
class BHL_EngineRegistry {
    const ENGINES = [
        'owncast' => ['label' => 'Owncast (self-hosted, free)', 'class' => 'BHL_OwncastEngine'],
        'cloudflare' => ['label' => 'Cloudflare Stream Live (managed, metered)', 'class' => 'BHL_CloudflareStreamEngine'],
    ];

    public static function active_key() {
        $key = get_option('bhl_active_engine', 'owncast');
        return isset(self::ENGINES[$key]) ? $key : 'owncast';
    }

    public static function save_active_key($key) {
        if (isset(self::ENGINES[$key])) update_option('bhl_active_engine', $key);
    }

    public static function active() {
        $key = self::active_key();
        $class = self::ENGINES[$key]['class'];
        return class_exists($class) ? new $class() : null;
    }

    // Chat isn't 1:1 with engine the way get_embed_html() is — Owncast
    // bundles its own, Cloudflare Stream Live has none at all (see
    // class-cloudflare-engine.php's own docblock), so there's no
    // BHL_Chat class to pair with it yet. null here means exactly
    // that: no chat class exists for the currently active engine,
    // not a configuration error.
    const CHATS = [
        'owncast' => 'BHL_OwncastChat',
    ];

    public static function active_chat() {
        $key = self::active_key();
        $class = self::CHATS[$key] ?? null;
        return ($class && class_exists($class)) ? new $class() : null;
    }
}

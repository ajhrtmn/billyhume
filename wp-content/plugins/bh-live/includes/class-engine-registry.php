<?php
if (!defined('ABSPATH')) exit;

/**
 * Picks which BHL_StreamEngine implementation is active, AND which
 * BHL_Chat implementation is active — this is the whole payoff of
 * both abstractions: BHL_Streams/BHL_API/the wizard all go through
 * these resolvers rather than ever hardcoding a class, so adding a
 * third option for either is a registry entry + a class, and
 * switching between them today is a radio button, not a code change.
 */
class BHL_EngineRegistry {
    const ENGINES = [
        'owncast' => ['label' => 'Owncast (self-hosted, free)', 'class' => 'BHL_OwncastEngine'],
        'cloudflare' => ['label' => 'Cloudflare Stream Live (managed, metered)', 'class' => 'BHL_CloudflareStreamEngine'],
    ];

    public static function active_key(): string {
        $key = get_option('bhl_active_engine', 'owncast');
        return isset(self::ENGINES[$key]) ? $key : 'owncast';
    }

    public static function save_active_key(string $key): void {
        if (isset(self::ENGINES[$key])) update_option('bhl_active_engine', $key);
    }

    public static function active(): ?\BHL_StreamEngine {
        $key = self::active_key();
        $class = self::ENGINES[$key]['class'];
        return class_exists($class) ? new $class() : null;
    }

    /**
     * Chat isn't 1:1 with engine the way get_embed_html() is — several
     * chat options can work under the SAME engine (e.g. Cloudflare
     * Stream Live pairs with either the free polling chat or the paid
     * real-time Workers chat), so this is its own small registry with
     * its own stored preference, constrained to whichever options list
     * the current engine as compatible. 'engines' => [] means "works
     * under any engine" (true of BHL_PollingChat).
     */
    const CHAT_OPTIONS = [
        'owncast_bundled' => ['label' => 'Owncast\'s own bundled chat (included, free)', 'class' => 'BHL_OwncastChat', 'engines' => ['owncast']],
        'polling' => ['label' => 'Polling chat (free, built-in, ~2-4s delay)', 'class' => 'BHL_PollingChat', 'engines' => []],
        'workers' => ['label' => 'Cloudflare Workers real-time chat (instant, requires Workers Paid plan)', 'class' => 'BHL_WorkersChat', 'engines' => ['cloudflare']],
    ];

    private static function chat_compatible(string $chat_key, string $engine_key): bool {
        $engines = self::CHAT_OPTIONS[$chat_key]['engines'] ?? [];
        return empty($engines) || in_array($engine_key, $engines, true);
    }

    // The default for each engine when nothing's been explicitly
    // chosen yet — Owncast's own bundled chat needs no setup at all,
    // so it stays the default there; polling is the free, zero-setup
    // default for every other engine.
    private static function default_chat_key_for(string $engine_key): string {
        return $engine_key === 'owncast' ? 'owncast_bundled' : 'polling';
    }

    public static function active_chat_key(): string {
        $engine_key = self::active_key();
        $stored = get_option('bhl_active_chat', '');
        if ($stored && isset(self::CHAT_OPTIONS[$stored]) && self::chat_compatible($stored, $engine_key)) {
            return $stored;
        }
        return self::default_chat_key_for($engine_key);
    }

    public static function save_active_chat_key(string $key): void {
        if (isset(self::CHAT_OPTIONS[$key])) update_option('bhl_active_chat', $key);
    }

    // Chat options compatible with whichever engine is CURRENTLY
    // active AND whose class is actually loaded — what the wizard
    // offers as radio choices. The class_exists() check matters
    // because CHAT_OPTIONS is the registry for ANY future chat
    // implementation, built or not yet — this keeps the wizard from
    // ever offering a choice that would silently resolve to nothing
    // if a future entry is added here before its class exists.
    /** @return array<string, array<string, mixed>> */
    public static function available_chat_options(): array {
        $engine_key = self::active_key();
        return array_filter(
            self::CHAT_OPTIONS,
            fn($opt, $key) => self::chat_compatible($key, $engine_key) && class_exists($opt['class']),
            ARRAY_FILTER_USE_BOTH
        );
    }

    public static function active_chat(): ?\BHL_Chat {
        $class = self::CHAT_OPTIONS[self::active_chat_key()]['class'] ?? null;
        return ($class && class_exists($class)) ? new $class() : null;
    }
}

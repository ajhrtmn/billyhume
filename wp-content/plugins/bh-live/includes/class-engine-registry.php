<?php
if (!defined('ABSPATH')) exit;

/**
 * Resolves the active BHL_StreamEngine and BHL_Chat; callers never hardcode a class.
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
     * Chat options, each listing compatible engines; 'engines' => [] means any engine.
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

    // Per-engine default chat when none is chosen; polling for any engine not listed.
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

    // Chat options compatible with the active engine whose class is loaded, so the
    // wizard never offers a choice that resolves to nothing.
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

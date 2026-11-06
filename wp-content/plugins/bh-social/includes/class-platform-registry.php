<?php
if (!defined('ABSPATH')) exit;

/**
 * Registry and factory for BH_SocialPlatform implementations.
 */
class BHSO_PlatformRegistry {
    const PLATFORMS = [
        'youtube' => ['label' => 'YouTube', 'class' => 'BHSO_YouTube'],
        'twitch'  => ['label' => 'Twitch', 'class' => 'BHSO_Twitch'],
        'meta'    => ['label' => 'Meta (Instagram)', 'class' => 'BHSO_Meta'],
        'tiktok'  => ['label' => 'TikTok', 'class' => 'BHSO_TikTok'],
    ];

    public static function get(string $key): ?\BH_SocialPlatform {
        if (!isset(self::PLATFORMS[$key])) return null;
        $class = self::PLATFORMS[$key]['class'];
        return class_exists($class) ? new $class() : null;
    }

    /** @return array<string, \BH_SocialPlatform> keyed by platform key */
    public static function all(): array {
        $out = [];
        foreach (self::PLATFORMS as $key => $meta) {
            $instance = self::get($key);
            if ($instance) $out[$key] = $instance;
        }
        return $out;
    }
}

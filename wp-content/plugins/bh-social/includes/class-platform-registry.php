<?php
if (!defined('ABSPATH')) exit;

/**
 * Same plain-array-registry-plus-factory shape as BHL_EngineRegistry —
 * only one real entry (YouTube) today, but Twitch/Meta/TikTok each land
 * here as one more PLATFORMS row + implementation class, no changes to
 * this file's own logic.
 */
class BHSO_PlatformRegistry {
    const PLATFORMS = [
        'youtube' => ['label' => 'YouTube', 'class' => 'BHSO_YouTube'],
        'twitch'  => ['label' => 'Twitch', 'class' => 'BHSO_Twitch'],
        'meta'    => ['label' => 'Meta (Instagram)', 'class' => 'BHSO_Meta'],
        'tiktok'  => ['label' => 'TikTok', 'class' => 'BHSO_TikTok'],
    ];

    /** @return BH_SocialPlatform|null */
    public static function get($key) {
        if (!isset(self::PLATFORMS[$key])) return null;
        $class = self::PLATFORMS[$key]['class'];
        return class_exists($class) ? new $class() : null;
    }

    /** @return BH_SocialPlatform[] keyed by platform key */
    public static function all() {
        $out = [];
        foreach (self::PLATFORMS as $key => $meta) {
            $instance = self::get($key);
            if ($instance) $out[$key] = $instance;
        }
        return $out;
    }
}

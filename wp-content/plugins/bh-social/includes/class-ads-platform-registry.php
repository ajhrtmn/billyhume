<?php
if (!defined('ABSPATH')) exit;

/**
 * Same plain-array-registry-plus-factory shape as BHSO_PlatformRegistry
 * (and BHL_EngineRegistry before it) — separate from that registry
 * because BH_AdsPlatform and BH_SocialPlatform are different
 * interfaces, not two flavors of the same one.
 */
class BHSO_AdsPlatformRegistry {
    const PLATFORMS = [
        'roku'       => ['label' => 'Roku Ads Manager', 'class' => 'BHSO_RokuAds'],
        'spotify'    => ['label' => 'Spotify Ad Studio', 'class' => 'BHSO_SpotifyAds'],
        'amazon_dsp' => ['label' => 'Amazon DSP', 'class' => 'BHSO_AmazonDSP'],
        'samsung'    => ['label' => 'Samsung Ads', 'class' => 'BHSO_SamsungAds'],
        'vizio'      => ['label' => 'Vizio Ads', 'class' => 'BHSO_VizioAds'],
    ];

    public static function get(string $key): ?\BH_AdsPlatform {
        if (!isset(self::PLATFORMS[$key])) return null;
        $class = self::PLATFORMS[$key]['class'];
        return class_exists($class) ? new $class() : null;
    }

    /** @return array<string, \BH_AdsPlatform> keyed by platform key */
    public static function all(): array {
        $out = [];
        foreach (self::PLATFORMS as $key => $meta) {
            $instance = self::get($key);
            if ($instance) $out[$key] = $instance;
        }
        return $out;
    }
}

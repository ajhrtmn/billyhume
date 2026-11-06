<?php
if (!defined('ABSPATH')) exit;

/**
 * Hooks core's Export/Erase Personal Data tools for this plugin's own table
 * (BHI_Profiles). Peer plugins register their own exporters/erasers.
 */
class OUS_Privacy {
    public static function init(): void {
        add_filter('wp_privacy_personal_data_exporters', [self::class, 'register_exporter']);
        add_filter('wp_privacy_personal_data_erasers', [self::class, 'register_eraser']);
    }

    /**
     * @param array<string, mixed> $exporters
     * @return array<string, mixed>
     */
    public static function register_exporter($exporters): array {
        $exporters['the-self-hosted-self-profile'] = [
            'exporter_friendly_name' => __('The Self-Hosted Self — Profile', 'the-self-hosted-self'),
            'callback' => [self::class, 'export_profile'],
        ];
        return $exporters;
    }

    /**
     * @param array<string, mixed> $erasers
     * @return array<string, mixed>
     */
    public static function register_eraser($erasers): array {
        $erasers['the-self-hosted-self-profile'] = [
            'eraser_friendly_name' => __('The Self-Hosted Self — Profile', 'the-self-hosted-self'),
            'callback' => [self::class, 'erase_profile'],
        ];
        return $erasers;
    }

    /** @return array<string, mixed> */
    public static function export_profile(string $email, int $page = 1): array {
        $user = get_user_by('email', $email);
        if (!$user) {
            return ['data' => [], 'done' => true];
        }

        $profile = BHI_Profiles::get($user->ID);
        $has_data = false;
        $props = [];
        foreach (BHI_Profiles::TEXT_COLS as $col) {
            if ($profile[$col] !== '') {
                $props[] = ['name' => $col, 'value' => $profile[$col]];
                $has_data = true;
            }
        }
        if (!empty($profile['bio'])) {
            $props[] = ['name' => 'bio', 'value' => $profile['bio']];
            $has_data = true;
        }
        if (!empty($profile['links'])) {
            $props[] = ['name' => 'links', 'value' => wp_json_encode($profile['links'])];
            $has_data = true;
        }

        $data = [];
        if ($has_data) {
            $data[] = [
                'group_id' => 'the-self-hosted-self-profile',
                'group_label' => __('The Self-Hosted Self Profile', 'the-self-hosted-self'),
                'item_id' => 'profile-' . $user->ID,
                'data' => $props,
            ];
        }

        return ['data' => $data, 'done' => true];
    }

    /** @return array<string, mixed> */
    public static function erase_profile(string $email, int $page = 1): array {
        $user = get_user_by('email', $email);
        if (!$user) {
            return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
        }

        $profile = BHI_Profiles::get($user->ID);
        $had_data = (bool) array_filter(array_intersect_key($profile, array_flip(BHI_Profiles::TEXT_COLS)));
        BHI_Profiles::delete_personal_data($user->ID);

        return [
            'items_removed' => $had_data,
            'items_retained' => false,
            'messages' => [],
            'done' => true,
        ];
    }
}

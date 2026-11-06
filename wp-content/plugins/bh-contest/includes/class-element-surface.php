<?php
if (!defined('ABSPATH')) exit;

/**
 * BH_Element surface for [bh_contest_player]: additive content slots around and
 * inside the player, plus contest data sources for bindings.
 * WHY: the player skeleton itself stays in player.ts, whose lookups depend on its
 * exact classes; slots only add new, empty zones.
 */
class BH_ElementSurface {
    const SURFACE = 'bh_contest_player';

    public static function init(): void {
        add_filter('bh_element_surfaces', [self::class, 'register_element_surface']);
        self::register_data_sources();
    }

    // Bindable data sources for slot placements; query logic stays in reviewed PHP here.
    private static function register_data_sources(): void {
        if (!class_exists('BH_Element_Data')) return;

        BH_Element_Data::register_source('bh_contest.vote_count', [
            'label'    => 'Contest vote count',
            'kind'     => 'scalar',
            'requires' => ['contest_id'],
            'resolve'  => function (array $args, array $ctx) {
                $cid = (int) ($ctx['contest_id'] ?? 0);
                if ($cid <= 0 || !class_exists('BH_Helpers')) return null;
                global $wpdb;
                $table = BH_Helpers::table();
                $count = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $table v INNER JOIN {$wpdb->posts} p ON p.ID = v.submission_id WHERE p.post_status = 'publish' AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = '_bh_contest_id' AND m.meta_value = %d)",
                    $cid
                ));
                return $count === null ? null : (int) $count;
            },
        ]);

        BH_Element_Data::register_source('bh_contest.track_count', [
            'label'    => 'Contest submitted track count',
            'kind'     => 'scalar',
            'requires' => ['contest_id'],
            'resolve'  => function (array $args, array $ctx) {
                $cid = (int) ($ctx['contest_id'] ?? 0);
                if ($cid <= 0) return null;
                $count = get_posts([
                    'post_type' => 'bh_submission', 'post_status' => 'publish',
                    'meta_key' => '_bh_contest_id', 'meta_value' => (string) $cid,
                    'posts_per_page' => -1, 'fields' => 'ids',
                ]);
                return count($count);
            },
        ]);

        BH_Element_Data::register_source('bh_contest.days_remaining', [
            'label'    => 'Days until voting closes',
            'kind'     => 'scalar',
            'requires' => ['contest_id'],
            'resolve'  => function (array $args, array $ctx) {
                $cid = (int) ($ctx['contest_id'] ?? 0);
                if ($cid <= 0) return null;
                $end = get_post_meta($cid, '_bh_end', true);
                if (!$end) return null; // no end date: no data, not an error
                $end_ts = strtotime($end);
                if ($end_ts === false) return null;
                $days = (int) ceil(($end_ts - current_time('timestamp')) / DAY_IN_SECONDS);
                return max(0, $days);
            },
        ]);
    }

    /**
     * @param array<string, mixed> $surfaces
     * @return array<string, mixed>
     */
    public static function register_element_surface($surfaces): array {
        if (!class_exists('BH_Element')) return $surfaces;
        $surfaces[self::SURFACE] = [
            'label' => 'Contest Player — custom content',
            'group' => 'Contest',
            // Per-contest context: each contest has its own slot content.
            'context' => ['type' => 'post', 'param' => 'contest_id'],
            'slots' => [
                'before_player' => ['label' => 'Before player (announcement/intro)'],
                'after_player'  => ['label' => 'After player (footer/CTA)'],
                // Zones inside the JS-built player: rendered here, inserted by
                // player.ts injectExtraZone() (see class-auth.php render()).
                'header_extra'  => ['label' => 'Header — extra content (next to brand/buttons)'],
                'tracklist_extra'     => ['label' => 'Above tracklist (below category tabs)'],
                'now_playing_extra'   => ['label' => 'Below the now-playing bar'],
                'results_modal_intro' => ['label' => 'Results modal — intro (above results list)'],
            ],
            // Preview against the newest published contest, or 0 if none.
            'preview_ctx' => function () {
                $latest = get_posts(['post_type' => 'bh_contest', 'post_status' => 'publish', 'posts_per_page' => 1, 'orderby' => 'date', 'order' => 'DESC', 'fields' => 'ids']);
                return ['contest_id' => $latest ? (int) $latest[0] : 0];
            },
        ];
        return $surfaces;
    }
}

<?php
if (!defined('ABSPATH')) exit;

/**
 * Renders the [bh_contest_player] shortcode. Auth itself lives in core (BHI_Auth).
 */
class BH_Auth {
    public static function init(): void {
        add_shortcode('bh_contest_player', [self::class, 'render']);
        add_action('template_redirect', [self::class, 'maybe_set_seo_data_early']);
    }

    /**
     * WHY: SEO data must be set before wp_head; render() runs in the_content(), too late.
     */
    public static function maybe_set_seo_data_early(): void {
        if (!class_exists('BH_SEO')) return;
        $atts = BH_SEO::shortcode_atts_on_current_page('bh_contest_player');
        if ($atts === null) return;
        self::set_seo_data($atts);
    }

    /**
     * Resolves the contest and sets BH_SEO page data.
     * @param mixed $atts
     */
    private static function set_seo_data($atts): void {
        if (!class_exists('BH_SEO')) return;
        $atts = shortcode_atts(['contest' => ''], $atts, 'bh_contest_player');
        $raw  = trim((string) $atts['contest']);
        $cid  = $raw !== '' ? BH_Helpers::resolve_contest($raw) : 0;
        if (!$cid) return;

        $start = get_post_meta($cid, '_bh_start', true);
        $end   = get_post_meta($cid, '_bh_end', true);
        $desc  = wp_strip_all_tags(get_post_field('post_content', $cid)) ?: (get_the_title($cid) . ' — vote now on ' . get_bloginfo('name'));

        BH_SEO::set_page_data([
            'title' => get_the_title($cid) . ' — ' . get_bloginfo('name'),
            'description' => $desc,
            'url' => get_permalink($cid),
            'type' => 'website',
            'schema' => [
                '@context' => 'https://schema.org',
                '@type' => 'Event',
                'name' => get_the_title($cid),
                'description' => $desc,
                'url' => get_permalink($cid),
                'eventAttendanceMode' => 'https://schema.org/OnlineEventAttendanceMode',
                'eventStatus' => 'https://schema.org/EventScheduled',
                'startDate' => $start ? mysql2date('c', $start) : null,
                'endDate' => $end ? mysql2date('c', $end) : null,
                'organizer' => [
                    '@type' => 'Organization',
                    'name' => get_bloginfo('name'),
                    'url' => home_url(),
                ],
            ],
        ]);
    }

    /** @param mixed $atts */
    public static function render($atts): string {
        $atts = shortcode_atts(['contest' => ''], $atts, 'bh_contest_player');
        $raw  = trim((string) $atts['contest']);
        $cid  = 0;

        if ($raw !== '') {
            $cid = BH_Helpers::resolve_contest($raw);
            if (!$cid && current_user_can('edit_posts')) {
                // Only shown to logged-in editors — visitors just see nothing
                // rather than a broken-looking box.
                return '<p style="padding:12px 16px;background:#3a2a00;color:#ffcf6b;border-radius:6px;'
                     . 'font-family:sans-serif;font-size:13px;">'
                     . '<strong>BH Contest:</strong> no contest matches <code>' . esc_html($raw) . '</code>. '
                     . 'Check Contests → the Shortcode column for the exact value. (Only editors see this notice.)'
                     . '</p>';
            }
        }

        // Contests are public unless marked members-only; the REST endpoints enforce the same check.
        if ($cid && !OUS_Visibility::can_view_open_by_default($cid)) {
            return OUS_Visibility::render_login_notice(__('Log in to view this contest.', 'bh-contest'));
        }

        // Repeats the early SEO call for embeds the early hook can't see; idempotent.
        self::set_seo_data($atts);

        static $i = 0; $i++;
        $attrs = 'class="bh-player-root" id="bh-player-root-' . $i . '" data-contest="' . esc_attr($cid ?: '') . '"';
        if ($cid) {
            $payload = BHY_Style::entity_style_payload($cid);
            if ($payload) $attrs .= ' data-style-overrides="' . esc_attr(wp_json_encode($payload)) . '"';
            if (get_post_meta($cid, '_bh_allow_audio_optional', true)) {
                $attrs .= ' data-allow-audio-optional="1"';
            }
        }

        // WHY: $before/$after render outside #bh-player-root, because player.js
        // rebuilds that div's innerHTML on load.
        $before = ''; $after = '';
        // "All contests" links to the Contest Library, else the archive, else nothing.
        $back_url = '';
        $lib_pid = (int) get_option('bh_contest_library_page_id', 0);
        if ($lib_pid && get_post_status($lib_pid) === 'publish') {
            $back_url = (string) get_permalink($lib_pid);
        } elseif (method_exists('OUS_Pages', 'url')) {
            $back_url = (string) (OUS_Pages::url('bh_archive', 'bh_archive_page_id') ?: '');
        }
        $back_link = '';
        if ($back_url) {
            // No inline margin: .ous-back-link (front-nav.css) owns the spacing.
            $back_link = '<p class="ous-back-link bh-back-to-archive">'
                . '<a href="' . esc_url($back_url) . '">'
                . '<span aria-hidden="true">&larr;</span> All contests</a></p>';
        }

        $submission_link = '';
        if ($cid && is_user_logged_in() && class_exists('BH_Helpers') && BH_Helpers::has_submitted(get_current_user_id(), $cid) && class_exists('BHI_Portal')) {
            $portal_url = home_url('/' . BHI_Portal::REWRITE_SLUG . '/submissions/');
            $submission_link = '<p style="margin:0 0 10px;font-size:13px;"><a href="' . esc_url($portal_url) . '">Manage my submission &rarr;</a></p>';
        }
        if ($cid && class_exists('BH_Element')) {
            $before = $back_link . $submission_link . BH_Element::render_slot('bh_contest_player', $cid, 'before_player', ['contest_id' => $cid]);
            $after  = BH_Element::render_slot('bh_contest_player', $cid, 'after_player', ['contest_id' => $cid]);

            // Zones inside the JS-built player go to player.js as base64 data
            // attributes, which it inserts into its own empty divs.
            self::attach_extra_zone($attrs, $cid, 'header_extra', 'header-extra');
            self::attach_extra_zone($attrs, $cid, 'tracklist_extra', 'tracklist-extra');
            self::attach_extra_zone($attrs, $cid, 'now_playing_extra', 'now-playing-extra');
            self::attach_extra_zone($attrs, $cid, 'results_modal_intro', 'results-modal-intro');
        } elseif ($submission_link) {
            // BH_Element not loaded — no slot content to merge with, but the submission link still stands on its own.
            $before = $back_link . $submission_link;
        }

        // .bh-player-aside matches the player's centred width; --before also carries
        // the header clearance (see player.css).
        if ($before !== '') $before = '<div class="bh-player-aside bh-player-aside--before">' . $before . '</div>';
        if ($after !== '')  $after  = '<div class="bh-player-aside bh-player-aside--after">' . $after . '</div>';

        return $before . '<div ' . $attrs . '></div>' . $after;
    }

    // Renders a slot and, if it has visible content, appends it as base64 data-{$attr_suffix}.
    private static function attach_extra_zone(string &$attrs, int $cid, string $slot, string $attr_suffix): void {
        if (!class_exists('BH_Element')) return;
        $html = BH_Element::render_slot('bh_contest_player', $cid, $slot, ['contest_id' => $cid]);
        if (self::slot_has_visible_content($html)) {
            $attrs .= ' data-' . $attr_suffix . '="' . esc_attr(base64_encode($html)) . '"';
        }
    }

    // render_slot() always emits a wrapper div, even when empty.
    private static function slot_has_visible_content(string $html): bool {
        return trim(wp_strip_all_tags($html)) !== '' || preg_match('/<(img|svg|iframe|video|audio)\b/i', $html) === 1;
    }
}

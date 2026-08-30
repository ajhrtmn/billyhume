<?php
if (!defined('ABSPATH')) exit;

/**
 * Narrowed scope as of the identity migration: this class now only
 * renders the [bh_contest_player] shortcode. Registration, login,
 * logout, session, profile, and email verification all live in the
 * The Self-Hosted Self core plugin now — see BHI_Auth. Kept the class name to
 * avoid a larger renaming cascade across every file that still
 * legitimately references "the thing that renders the player."
 */
class BH_Auth {
    public static function init(): void {
        add_shortcode('bh_contest_player', [self::class, 'render']);
        add_action('template_redirect', [self::class, 'maybe_set_seo_data_early']);
    }

    /**
     * Real bug, production-readiness sweep 2026-08-16: set_page_data()
     * below (inside render()) only ever runs during the_content(),
     * which is always after wp_head — where BH_SEO actually echoes its
     * tags — has already fired. Confirmed the exact same bug on
     * bh-courses first (a real course page had zero meta/OG tags
     * despite the code "setting" them every render) and fixed it with
     * this same template_redirect-early-lookup pattern there. Uses
     * BH_SEO::shortcode_atts_on_current_page() (the-self-hosted-self) instead of
     * re-parsing the shortcode regex here, since bh-streaming/
     * bh-registry need the identical lookup.
     */
    public static function maybe_set_seo_data_early(): void {
        if (!class_exists('BH_SEO')) return;
        $atts = BH_SEO::shortcode_atts_on_current_page('bh_contest_player');
        if ($atts === null) return;
        self::set_seo_data($atts);
    }

    /**
     * Extracted from render() below so the exact same resolve-and-set
     * logic can also run early, from maybe_set_seo_data_early() above,
     * before the_content() would otherwise run it too late. Private —
     * this plugin's own render() and early hook are the only callers;
     * no cross-class use, so no reason to widen the surface.
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

        // Real, deliberate product decision (not a default): unlike
        // bh-courses (closed unless marked public), a contest defaults
        // OPEN — that's how voting/engagement is meant to work, fans
        // need to see and share a contest without an account. This is
        // the explicit per-contest opt-IN for the ones an artist wants
        // restricted to logged-in members only — see OUS_Visibility's
        // own docblock. The same check also gates the REST endpoints
        // the player itself fetches from (class-api.php's tracks()/
        // results()), so this isn't just hiding the player markup —
        // the actual submission/vote data is refused server-side too.
        if ($cid && !OUS_Visibility::can_view_open_by_default($cid)) {
            return OUS_Visibility::render_login_notice(__('Log in to view this contest.', 'bh-contest'));
        }

        // Real duplicate work, left in deliberately (not a bug): the
        // template_redirect hook above already set this earlier in the
        // SAME request whenever it could — this second call only ever
        // does anything additional when render() is reached a way the
        // early hook couldn't predict (e.g. embedded via a block/
        // do_shortcode() call outside the queried post's own content).
        // set_page_data() is idempotent (last-write-wins on the same
        // request), so calling it twice with identical data is
        // harmless, not a correctness risk.
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

        // bh-contest's first real BH_Element surface (class-element-
        // surface.php's own docblock explains the scope/why). Deliberately
        // OUTSIDE the JS-owned #bh-player-root div, not inside it — player.js
        // rebuilds that div's entire innerHTML on load (renderSkeleton()),
        // so anything placed inside it would be wiped the instant the
        // script runs. These two zones are real server-rendered HTML,
        // siblings of the player, not something player.js knows about or
        // touches at all.
        $before = ''; $after = '';
        // A real way for a logged-in contestant to reach
        // their account portal (where they can now replace a wrong
        // file, see BH_PortalPanel) from the page they'd actually be
        // on when they realize the mistake — the contest player itself.
        // Server-rendered, a real sibling of the JS-owned player div
        // (never touched/rebuilt by player.js), so this survives
        // regardless of playback state. Only shown when the current
        // user actually has a submission for THIS contest — no reason
        // to show it to someone who hasn't entered.
        // A single contest was a dead end -- nothing on the page led back to
        // the archive it belongs to. OUS_Pages resolves which page hosts
        // [bh_archive] rather than hardcoding a slug, since that is the
        // author's choice; bh_archive_page_id is what this plugin's own
        // activator records when it creates the page. Omitted entirely when
        // core is absent or no archive page exists -- never a link to nowhere.
        // "All contests" belongs on the Contest Library (the lifecycle
        // browser of the contests themselves), not the Archive (the flat
        // every-entry catalog). Prefer the library page; fall back to
        // the archive only if the library page doesn't exist yet, then
        // to nothing — never a link to nowhere.
        $back_url = '';
        $lib_pid = (int) get_option('bh_contest_library_page_id', 0);
        if ($lib_pid && get_post_status($lib_pid) === 'publish') {
            $back_url = (string) get_permalink($lib_pid);
        } elseif (method_exists('OUS_Pages', 'url')) {
            $back_url = (string) (OUS_Pages::url('bh_archive', 'bh_archive_page_id') ?: '');
        }
        $back_link = '';
        if ($back_url) {
            // No inline margin — the shared .ous-back-link rule
            // (front-nav.css) owns the spacing, including the
            // header-clearance top margin an inline style would defeat.
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
            // QA fix: this used to unconditionally OVERWRITE $before
            // with render_slot()'s own output, silently discarding the
            // "Manage my submission" link assigned above every time.
            // Now appends instead.
            $before = $back_link . $submission_link . BH_Element::render_slot('bh_contest_player', $cid, 'before_player', ['contest_id' => $cid]);
            $after  = BH_Element::render_slot('bh_contest_player', $cid, 'after_player', ['contest_id' => $cid]);

            // Task #80's real, safe slice: this one DOES need to end up
            // INSIDE the header bar player.js builds — a sibling wouldn't
            // visually read as "part of the header" the way a real
            // header addition should. Since player.js owns and rebuilds
            // #bh-player-root's entire innerHTML on load, the only safe
            // way in is to hand player.js the already-rendered HTML as
            // data (base64, same reasoning class-style-gallery.php's own
            // shadow-DOM data-doc attribute uses — avoids any HTML-
            // attribute-escaping edge case a raw JSON/HTML string could
            // hit) and let IT insert it once, into a brand-new
            // '.bh-header-extra' div that does not exist in the header
            // today and does not replace anything that does.
            self::attach_extra_zone($attrs, $cid, 'header_extra', 'header-extra');

            // 3.2.2 — three more slots, same "genuinely new, empty div
            // player.js never reads from or requires" boundary header_extra
            // already proved out. Each corresponds to a brand-new,
            // renderSkeleton()-only div (see player.js's own comment at
            // each insertion point) — nothing here touches or replaces any
            // selector auth/vote/playback logic elsewhere in that file
            // depends on.
            self::attach_extra_zone($attrs, $cid, 'tracklist_extra', 'tracklist-extra');
            self::attach_extra_zone($attrs, $cid, 'now_playing_extra', 'now-playing-extra');
            self::attach_extra_zone($attrs, $cid, 'results_modal_intro', 'results-modal-intro');
        } elseif ($submission_link) {
            // BH_Element not loaded — no slot content to merge with, but the submission link still stands on its own.
            $before = $back_link . $submission_link;
        }

        // $before/$after render OUTSIDE the .bh-container that player.js
        // builds inside .bh-player-root, so without their own aligned
        // wrapper the "All contests" link and any slot content sat
        // flush against the viewport edge while the player below was
        // centred at 1200px. .bh-player-aside matches that width and
        // centring (see player.css); the --before variant also carries
        // the floating-header clearance so .bh-container can drop its
        // own (player.css neutralises it when the aside precedes it).
        if ($before !== '') $before = '<div class="bh-player-aside bh-player-aside--before">' . $before . '</div>';
        if ($after !== '')  $after  = '<div class="bh-player-aside bh-player-aside--after">' . $after . '</div>';

        return $before . '<div ' . $attrs . '></div>' . $after;
    }

    // Shared by header_extra and the three newer additive zones — renders
    // a real BH_Element slot for this contest, and (only if it actually
    // has visible content, per slot_has_visible_content() below) appends
    // it to $attrs as a base64-encoded data-{$attr_suffix} attribute for
    // player.js to read once and drop into its matching empty div. Same
    // base64-over-raw-HTML-attribute reasoning the original header_extra
    // wiring already used (avoids any HTML-attribute-escaping edge case a
    // raw JSON/HTML string in an attribute could hit) — extracted here
    // once four call sites needed the identical five lines rather than
    // left duplicated a fourth time.
    private static function attach_extra_zone(string &$attrs, int $cid, string $slot, string $attr_suffix): void {
        if (!class_exists('BH_Element')) return;
        $html = BH_Element::render_slot('bh_contest_player', $cid, $slot, ['contest_id' => $cid]);
        if (self::slot_has_visible_content($html)) {
            $attrs .= ' data-' . $attr_suffix . '="' . esc_attr(base64_encode($html)) . '"';
        }
    }

    // render_slot()'s wrapper div is always present, even empty — this
    // tells "genuinely empty" apart from "has real placement content"
    // without player.js needing to know anything about BH_Element's own
    // wrapper convention.
    private static function slot_has_visible_content(string $html): bool {
        return trim(wp_strip_all_tags($html)) !== '' || preg_match('/<(img|svg|iframe|video|audio)\b/i', $html) === 1;
    }
}

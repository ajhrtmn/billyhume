<?php
if (!defined('ABSPATH')) exit;

/**
 * bh-contest's first real BH_Element surface — AJ named this plugin the
 * litmus test for "content, component, and widget authoring on the site
 * itself," so this is the concrete first slice of that, not another
 * hand-authored style-only mockup (class-style-surfaces.php's player/
 * forms/results previews stay exactly as they are — see this class's own
 * docblock further down for why they're NOT converted here).
 *
 * Scope, deliberately narrow: [bh_contest_player]'s actual interactive
 * shell (header/tabs/tracklist/now-playing bar/auth+submit modals) is
 * built entirely client-side by assets/js/player.js — one big template
 * literal (renderSkeleton(), ~line 122) whose exact classes/structure
 * every OTHER method in that file depends on via this.q('.bh-results-btn')-
 * style lookups (auth-state show/hide, vote wiring, playback, the whole
 * thing). Making THAT skeleton itself a set of BH_Element placements
 * would mean guaranteeing every placement always emits those exact
 * required classes/attributes — a real, live-voting/auth/playback-
 * breaking risk to take on blind, with no browser available this session
 * to verify against. Not done here; flagged in WALKTHROUGH-GUIDE.md as
 * follow-up work that needs an actual click-through pass, not a repeat
 * of tonight's "reasoned through it, please verify live" pattern applied
 * to something this load-bearing.
 *
 * What IS safe, real, and additive: two brand-new content zones around
 * the player that player.js has never touched and never will — an
 * announcement/intro area before it, and a footer/CTA area after it.
 * These are genuinely new DOM, genuinely BH_Element-owned end to end,
 * and exercise the exact same real render_slot()-in-a-real-template
 * pattern CRM's profile page and LMS lessons already use — this is
 * that same conversion, just scoped to the part of bh-contest that's
 * actually safe to hand over today.
 */
class BH_ElementSurface {
    const SURFACE = 'bh_contest_player';

    public static function init() {
        add_filter('bh_element_surfaces', [self::class, 'register_element_surface']);
        self::register_data_sources();
    }

    // AJ's own direct follow-up, folded into this same conversion rather
    // than deferred as a separate pass: "is there a way to... litterally
    // do it all via the builder instead of as hard coded files?" for
    // server-side data specifically, the answer is this — real,
    // registered BH_Element_Data sources (own-ur-shit/includes/class-
    // element-data.php's own registration API, same one bhcore_events.count
    // already uses), NOT raw PHP typed into a text field. A placement in
    // the before_player/after_player slots can now bind to "this
    // contest's live vote count" or "days until voting closes" the same
    // way any other bound attribute works — no code, no file, no
    // deploy — while the actual query logic stays real, reviewed PHP
    // living in this file, not something typed into an admin textarea.
    private static function register_data_sources() {
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
                    'meta_key' => '_bh_contest_id', 'meta_value' => $cid,
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
                if (!$end) return null; // no end date set — genuinely "no data," not an error
                $end_ts = strtotime($end);
                if ($end_ts === false) return null;
                $days = (int) ceil(($end_ts - current_time('timestamp')) / DAY_IN_SECONDS);
                return max(0, $days);
            },
        ]);
    }

    // Named to match BHCRM_People::register_element_surface() / OUS_Dashboard's
    // own method of the same name — one consistent name for "this is a
    // real bh_element_surfaces registration" across every plugin, not a
    // bare register() that reads identically to the unrelated
    // bhy_style_surfaces mockup registrations (class-style-surfaces.php's
    // own register() a few files over) despite doing a completely
    // different job.
    public static function register_element_surface($surfaces) {
        if (!class_exists('BH_Element')) return $surfaces; // same guard BHCRM_People::register_element_surface() uses — harmless to keep even if own-ur-shit's element classes are ever absent
        $surfaces[self::SURFACE] = [
            'label' => 'Contest Player — custom content',
            'group' => 'Contest',
            // Per-contest context, same shape BH_Courses' lesson surface
            // and BH_CRM's profile surface already use — $context_id is
            // the bh_contest post ID, so each contest gets its own
            // independent announcement/footer content, not one shared
            // block site-wide.
            'context' => ['type' => 'post', 'param' => 'contest_id'],
            'slots' => [
                'before_player' => ['label' => 'Before player (announcement/intro)'],
                'after_player'  => ['label' => 'After player (footer/CTA)'],
                // Task #80's real, safe slice: a zone INSIDE the header
                // bar itself (next to the brand/Results/Submit/Login
                // buttons), not a replacement of it. Nothing required by
                // player.js's own this.q('.bh-results-btn')-style lookups
                // lives in this slot — it's a genuinely new, additive
                // mount point, same "extend, never replace, the load-
                // bearing skeleton" boundary this surface's own docblock
                // above already draws. See class-auth.php/player.js for
                // the two-sided wiring (server renders it, player.js
                // reads it off a data attribute and inserts it once,
                // rather than owning/rebuilding it itself).
                'header_extra'  => ['label' => 'Header — extra content (next to brand/buttons)'],
                // Same additive, non-load-bearing boundary as header_extra
                // above — each is a brand-new empty div renderSkeleton()
                // creates and player.js never reads from or requires for
                // its own auth/vote/playback logic, so these three are
                // just as safe to hand over as the first slot was. See
                // player.js's injectExtraZone() (generalized off
                // injectHeaderExtra()) and class-auth.php's render() for
                // the two-sided wiring.
                'tracklist_extra'     => ['label' => 'Above tracklist (below category tabs)'],
                'now_playing_extra'   => ['label' => 'Below the now-playing bar'],
                'results_modal_intro' => ['label' => 'Results modal — intro (above results list)'],
            ],
            // Same "no single real contest is THE one" reasoning
            // BHCRM_People's preview_ctx uses for its own current-viewer
            // stand-in: the most-recently-published contest, or 0 (no
            // real contest, still renders — an empty slot is valid) if
            // none exist yet.
            'preview_ctx' => function () {
                $latest = get_posts(['post_type' => 'bh_contest', 'post_status' => 'publish', 'posts_per_page' => 1, 'orderby' => 'date', 'order' => 'DESC', 'fields' => 'ids']);
                return ['contest_id' => $latest ? (int) $latest[0] : 0];
            },
        ];
        return $surfaces;
    }
}

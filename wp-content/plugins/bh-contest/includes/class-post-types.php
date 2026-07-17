<?php
if (!defined('ABSPATH')) exit;

class BH_PostTypes {
    // Both CPTs live under one top-level admin menu, anchored on bh_contest,
    // so the whole plugin reads as a single tab: Contests / Submissions /
    // Results / Debug Tools, instead of two separate top-level menu items.
    const MENU_PARENT = 'edit.php?post_type=bh_contest';

    public static function register() {
        register_post_type('bh_contest', [
            // menu_name carries the "OUS ·" prefix so this top-level menu
            // reads as part of the Own Ur Shit ecosystem even though it
            // has to stay its own top-level item (see class-registry.php's
            // docblock on why CPT list-tables aren't relocated) — name/
            // singular_name/all_items stay plain since those show up in
            // body text ("Add New Contest," "All Contests"), where a
            // prefix would read oddly.
            // QA fix, caught live: 'edit_item'/'add_new_item' were
            // never set, so every real edit screen showed the generic
            // WP core fallback "Edit Post"/"Add Post" instead of
            // "Edit Contest"/"Add Contest" — confirmed live via
            // screenshot.
            'labels'       => ['name' => 'Contests', 'menu_name' => 'OUS · Contest', 'singular_name' => 'Contest', 'all_items' => 'Contests', 'edit_item' => 'Edit Contest', 'add_new_item' => 'Add New Contest', 'new_item' => 'New Contest', 'view_item' => 'View Contest'],
            'public'       => false,
            'show_ui'      => true,
            'show_in_menu' => true, // this CPT IS the top-level anchor
            'menu_icon'    => 'dashicons-awards',
            // No 'editor' — a contest has no free-text body content of
            // its own; everything that actually matters (dates, contact
            // fields, categories, shortcode, branding) already lives in
            // this CPT's dedicated meta boxes (see BH_Admin::
            // add_meta_boxes()). Keeping the default block editor around
            // just added an empty, unused content area above those boxes.
            'supports'     => ['title'],
        ]);

        register_post_type('bh_submission', [
            'labels'       => ['name' => 'Submissions', 'singular_name' => 'Submission', 'menu_name' => 'Submissions', 'edit_item' => 'Review Submission', 'add_new_item' => 'Add New Submission', 'view_item' => 'View Submission'],
            'public'       => false,
            'show_ui'      => true,
            'show_in_menu' => self::MENU_PARENT, // nested under Contests, not its own top-level item
            'supports'     => ['title', 'author'],
        ]);

        // Real 'rejected' status, replacing the previous non-existent
        // one (an admin's only prior option was to leave a submission
        // stuck at 'pending' forever, or trash it, with zero
        // notification to the contestant either way — a real gap
        // surfaced by AJ's own permissions/QA pass this session).
        // 'publicly_queryable' => false / 'exclude_from_search' =>
        // true, same posture as 'pending' itself for this CPT (public
        // => false on the post type already blocks direct access, this
        // just keeps the status consistent with that).
        // QA fix, caught live: 'exclude_from_search' => true made
        // WordPress's post_status => 'any' query expansion (used in
        // SIX places across this plugin — has_submitted()'s duplicate
        // check, the portal's own submissions list, the CRM
        // integration's activity summary, etc.) silently EXCLUDE
        // rejected submissions entirely — confirmed live: a rejected
        // submission vanished from the contestant's own portal list.
        // WP_Query only respects exclude_from_search for CUSTOM
        // statuses during 'any' expansion (core statuses like
        // 'pending' are hardcoded exceptions), so this one flag was
        // silently breaking every 'any' query in the plugin. false is
        // still safe here — bh_submission itself is 'public' => false
        // on the post type, so this can never actually surface in a
        // real front-end search regardless of this flag.
        register_post_status('rejected', [
            'label'                     => 'Rejected',
            'internal'                  => false,
            'public'                    => false,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            // translators: %s: number of rejected submissions.
            'label_count'               => _n_noop('Rejected <span class="count">(%s)</span>', 'Rejected <span class="count">(%s)</span>'),
        ]);
    }
}

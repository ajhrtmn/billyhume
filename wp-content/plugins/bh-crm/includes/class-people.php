<?php
if (!defined('ABSPATH')) exit;

/**
 * The person list and detail view — built entirely on shared identity
 * (BHI_Profiles) plus whatever any OTHER active plugin optionally
 * contributes. This plugin never queries bh-contest's or bh-streaming's
 * own tables directly; it only knows about two filters, so it works
 * identically whether zero, one, or several other plugins are active.
 *
 * A plugin contributes in two places, entirely from its own bootstrap:
 *
 * 1. Who belongs on the list at all — anyone with profile data filled
 *    in already qualifies, but a plugin can add people who are active
 *    with it specifically even without profile data (e.g. someone who's
 *    voted but never filled in a real name):
 *
 *        add_filter('bh_crm_active_user_ids', function ($ids) {
 *            global $wpdb;
 *            return array_merge($ids, $wpdb->get_col(
 *                "SELECT DISTINCT user_id FROM {$wpdb->prefix}bh_votes"
 *            ));
 *        });
 *
 * 2. What shows up in that person's activity section on their detail
 *    page — a short summary line plus a callback that renders the full
 *    detail table when expanded:
 *
 *        add_filter('bh_crm_activity_summary', function ($sections, $user_id) {
 *            $votes = BH_Helpers::user_total_votes($user_id);
 *            if (!$votes) return $sections;
 *            $sections[] = [
 *                'plugin'  => 'BH Contest',
 *                'summary' => "$votes votes cast",
 *                'render'  => fn() => BH_Participants_Activity::render_detail($user_id),
 *            ];
 *            return $sections;
 *        }, 10, 2);
 *
 * Both are harmless to add even if this CRM plugin is never installed —
 * an add_filter() call on a filter nobody applies just sits unused,
 * which is what keeps this a genuine peer relationship rather than a
 * dependency in either direction.
 */
class BHCRM_People {
    /**
     * Registers the 'bh_crm_profile' surface for BH_Element (design doc
     * ELEMENT-BUILDER-DESIGN-PLAN.md §3.3/§5.2), mirroring OUS_Dashboard::
     * register_element_surface()'s (own-ur-shit/includes/class-dashboard.php)
     * exact shape. Unlike the dashboard's singleton surface, this one is
     * per-person — 'context' => ['type' => 'user', 'param' => 'user_id']
     * and every render_slot() call site in render_detail() below passes
     * $uid as the surface_context_id, matching the design doc's schema
     * note that surface_context_id is "the entity id (CRM = user_id...)".
     * The 'bh_element_surfaces' filter this hangs on is only ever applied
     * by BH_Element, so this is harmless to keep even if own-ur-shit's
     * element-builder classes are ever absent — same "peer relationship,
     * not a dependency" posture as bh_crm_activity_summary above.
     *
     * 1.3.3 — DESIGN-SUITE-UNIFICATION-PLAN.md "NO SPECIAL-CASED PAGES"
     * Step 1: the three separate 'header'/'main'/'sidebar' slots were
     * themselves a framework-level special case — three zones I chose in
     * advance, not something the user could restructure. Collapsed to
     * ONE slot, 'root'. There is nothing structurally distinguishing a
     * "header area" from anything else anymore: if AJ wants a header-
     * looking section, he adds a section node as the first root child —
     * same mechanism as adding a button, exactly the "a page and a
     * button are just differently-scoped nodes" framing this whole pass
     * is built around. Safe to collapse with zero data loss: confirmed
     * via live screenshot that all three of the old slots were empty on
     * this install (no placement rows to migrate or orphan).
     */
    public static function register_element_surface($surfaces) {
        $surfaces['bh_crm_profile'] = [
            'group'       => 'CRM',
            'label'       => 'CRM profile page',
            'slots'       => [
                'root' => ['label' => 'Page content'],
            ],
            'context'     => ['type' => 'user', 'param' => 'user_id'],
            // Preview context for a future builder GUI's canvas — the
            // currently-viewing admin's own user_id stands in as a
            // representative subject (there's no "the" profile being
            // edited outside a real $uid, same reasoning the dashboard's
            // preview_ctx uses for its own single-viewer context).
            'preview_ctx' => function () { return ['user_id' => get_current_user_id()]; },
        ];
        return $surfaces;
    }

    // No add_menu() here anymore — this page is registered as a submenu
    // of Own Ur Shit instead of its own top-level menu (see the 'bh-crm'
    // entry in the core's class-registry.php, applied by OUS_MenuMerge).
    // render() below is unchanged either way; only where WordPress hangs
    // it in the admin sidebar changed.

    /* ---------------- who's on the list ---------------- */

    // Anyone with profile data on file, plus anyone any active plugin
    // considers "active" via the filter — a person who's voted but
    // never filled in a real name still shows up if bh-contest is
    // active and contributes their ID, but a bare list of every WP
    // subscriber with zero activity anywhere would just be noise.
    public static function active_user_ids() {
        // Per QA-REPORT-code-quality.md's cross-plugin finding #2 — this
        // used to run raw SQL directly against core's bhi_profiles table
        // (a real encapsulation violation, doubled by class-export.php
        // running the byte-identical query independently). Both now go
        // through BHI_Profiles, the class that actually owns that table.
        $with_profile = class_exists('BHI_Profiles') ? BHI_Profiles::user_ids_with_profile_data() : [];
        $contributed = apply_filters('bh_crm_active_user_ids', []);
        return array_unique(array_map('intval', array_merge($with_profile, $contributed)));
    }

    /* ---------------- page ---------------- */

    public static function render() {
        $uid = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
        $project_id = isset($_GET['project_id']) ? (int) $_GET['project_id'] : 0;
        BHY_UI::shell_open('Studio &rsaquo; People');
        if (isset($_GET['bhcrm_msg'])) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sanitize_text_field(wp_unslash($_GET['bhcrm_msg']))) . '</p></div>';

        // 1.2.0 — project tracker board dispatch. Rides on THIS existing
        // single-page dispatch (admin.php?page=bh-crm) rather than a new
        // standalone page — see class-projects.php's docblock for why a
        // second page was deliberately avoided on this install.
        // QA fix: this used to require a truthy $uid to reach the board
        // at all — meaning a project with no linked person (created
        // straight from the Project Tracker index, not looped through a
        // person's page) could never be opened. $uid is now optional;
        // render_board() itself handles $uid === 0 (no "back to person"
        // link, falls back to "back to Project Tracker").
        if ($project_id && class_exists('BHCRM_Projects')) {
            BHCRM_Projects::render_board($project_id, $uid);
        } elseif ($uid) {
            self::render_detail($uid);
        } else {
            self::render_list();
        }
        BHY_UI::shell_close();
    }

    private static function render_list() {
        $ids = self::active_user_ids();
        if (!$ids) {
            echo '<p>No one has a profile on file or any recorded activity yet. This list fills in on its own — nothing to configure.</p>';
            return;
        }

        $tag_filter = sanitize_text_field($_GET['tag'] ?? '');
        if ($tag_filter) $ids = array_filter($ids, fn($id) => in_array($tag_filter, BHCRM_Tags::get($id), true));

        // ROADMAP-ux-polish-and-feature-parity-2026-07.md Section 3:
        // saved smart lists — AND-combined against whatever the tag
        // filter above already narrowed to, not a replacement for it,
        // so "tag=vip&segment=3" behaves exactly as a person reading
        // the URL would expect.
        $segment_id = (int) ($_GET['segment'] ?? 0);
        $active_segment = $segment_id ? BHCRM_Segments::get($segment_id) : null;
        if ($active_segment) $ids = BHCRM_Segments::apply(array_values($ids), $active_segment['conditions']);

        // QA fix: this whole toolbar (intro line, tag filter, smart
        // lists, export, search) was a loose sequence of <p>/<input>
        // elements relying on default browser paragraph margins for
        // spacing — inconsistent gaps between rows, and the export
        // button/search box had no visual relationship to the tag
        // filter above them. Wrapped in one flex column with a single
        // token-driven gap so the whole toolbar reads as one group.
        echo '<div style="display:flex;flex-direction:column;gap:var(--bhy-space-3,12px);margin-bottom:var(--bhy-space-4,16px);">';

        echo '<p style="margin:0;">Anyone with profile data on file or recorded activity. Click a name for their full detail.</p>';

        $all_tags = BHCRM_Tags::all_in_use();
        if ($all_tags) {
            echo '<p style="margin:0;"><strong>Filter by tag:</strong> ';
            echo '<a href="' . esc_url(remove_query_arg('tag')) . '">All</a> ';
            foreach ($all_tags as $t) {
                echo '&middot; <a href="' . esc_url(add_query_arg('tag', $t)) . '"' . ($tag_filter === $t ? ' style="font-weight:700;"' : '') . '>' . esc_html($t) . '</a> ';
            }
            echo '</p>';
        }

        self::render_segments_panel($active_segment);

        $export_url = wp_nonce_url(admin_url('admin-post.php?action=bhcrm_export' . ($tag_filter ? '&tag=' . urlencode($tag_filter) : '')), 'bhcrm_export');
        echo '<p style="margin:0;"><a class="button" href="' . esc_url($export_url) . '">Export CSV (all)</a></p>';

        // Search + sortable columns — see BHY_UI::print_design_system_js()
        // for the shared, dependency-free behavior. Genuinely useful here
        // specifically: a real directory is exactly the case where
        // "find one person by name" and "sort by most recently
        // registered" matter, unlike a small fixed-size stats table.
        echo '<input type="text" class="bhy-table-search" data-target="#bhcrm-people-table" placeholder="Filter by name, email, or tag&hellip;">';

        echo '</div>';

        // ROADMAP-ux-polish-and-feature-parity-2026-07.md Section 3:
        // bulk actions — one <form> wraps the whole table (checkboxes +
        // the bulk-action bar above it), posting to whichever of the two
        // admin-post actions the clicked button names via its own
        // formaction — same "one form, two possible submit targets"
        // shape a plain HTML form supports natively, no JS required for
        // the actions themselves (bhcrm-bulk-select-all.js below only
        // handles the header checkbox's select-all convenience).
        $bulk_tag_action = wp_nonce_url(admin_url('admin-post.php?action=bhcrm_bulk_tag'), 'bhcrm_bulk_action');
        $bulk_export_action = wp_nonce_url(admin_url('admin-post.php?action=bhcrm_export'), 'bhcrm_export');
        echo '<form method="post" id="bhcrm-bulk-form">';
        echo '<div class="bhcrm-bulk-bar" style="display:flex;align-items:center;gap:8px;margin:10px 0;flex-wrap:wrap;">';
        echo '<input type="text" name="bulk_tag" placeholder="Tag to apply…" style="max-width:200px;">';
        echo '<button type="submit" formaction="' . esc_url($bulk_tag_action) . '" class="button">Tag selected</button>';
        echo '<button type="submit" formaction="' . esc_url($bulk_export_action) . '" class="button">Export selected (CSV)</button>';
        echo '<span class="description bhcrm-bulk-count">0 selected</span>';
        echo '</div>';

        // --tall: this table IS the whole page (a directory, not one of
        // several colocated cards) — see BHY_UI's own docblock on why
        // that distinction gets it more scroll room than the default.
        echo '<div class="bhy-table-wrap bhy-table-wrap--tall">';
        echo '<table id="bhcrm-people-table" class="wp-list-table widefat striped bhy-sortable"><thead><tr>'
           . '<th style="width:24px;"><input type="checkbox" id="bhcrm-select-all"></th>'
           . '<th data-sort>Name</th><th data-sort>Email</th><th>Tags</th><th style="min-width:220px;">Activity</th><th data-sort>Registered</th>'
           . '</tr></thead><tbody>';
        foreach ($ids as $uid) {
            $user = get_userdata($uid);
            if (!$user) continue;
            $summary = array_map(fn($s) => $s['summary'], apply_filters('bh_crm_activity_summary', [], $uid));
            echo '<tr>'
               . '<td><input type="checkbox" name="bulk_ids[]" value="' . (int) $uid . '" class="bhcrm-row-select"></td>'
               . '<td><a href="' . esc_url(add_query_arg('user_id', $uid)) . '"><strong>' . esc_html($user->display_name) . '</strong></a></td>'
               . '<td>' . esc_html($user->user_email) . '</td>'
               . '<td>' . esc_html(implode(', ', BHCRM_Tags::get($uid))) . '</td>'
               . '<td>' . esc_html($summary ? implode(' &middot; ', $summary) : '—') . '</td>'
               . '<td>' . esc_html(mysql2date('M j, Y', $user->user_registered)) . '</td>'
               . '</tr>';
        }
        echo '</tbody></table></div>';
        echo '</form>';
        wp_enqueue_script('bhcrm-bulk-select', BHCRM_URL . 'assets/js/bulk-select.js', [], BHCRM_VER, true);
    }

    // ROADMAP-ux-polish-and-feature-parity-2026-07.md Section 3: "Saved
    // smart lists/segments." Saved lists as clickable pills (same visual
    // language the tag-filter row above already uses), a delete link
    // per pill, and a collapsible builder below for creating a new one.
    // Uses this ecosystem's shared design tokens (--bhy-space-*, see
    // own-ur-shit's BHY_UI::print_design_system_js()) rather than
    // hand-picked pixel values, unlike a few of this same pass's earlier
    // panels — worth matching going forward.
    private static function render_segments_panel($active_segment) {
        $segments = BHCRM_Segments::all();

        echo '<div style="margin:var(--bhy-space-4,16px) 0;padding:var(--bhy-space-4,16px);background:var(--bhy-surface,#fff);border:1px solid var(--bhy-border,#dcdcde);border-radius:var(--bhy-radius,8px);">';
        echo '<strong style="display:block;margin-bottom:var(--bhy-space-2,8px);">Smart lists</strong>';

        if ($segments) {
            echo '<div style="display:flex;flex-wrap:wrap;gap:var(--bhy-space-2,8px);margin-bottom:var(--bhy-space-3,12px);">';
            foreach ($segments as $s) {
                $is_active = $active_segment && (int) $active_segment['id'] === (int) $s['id'];
                $url = $is_active ? remove_query_arg('segment') : add_query_arg('segment', (int) $s['id']);
                $delete_url = wp_nonce_url(admin_url('admin-post.php?action=bhcrm_delete_segment&segment_id=' . (int) $s['id']), 'bhcrm_delete_segment');
                echo '<span style="display:inline-flex;align-items:center;gap:var(--bhy-space-1,4px);background:' . ($is_active ? 'var(--bhy-accent-soft,#e8b49a)' : 'var(--bhy-bg,#f6f7f7)') . ';border:1px solid var(--bhy-border,#dcdcde);border-radius:14px;padding:var(--bhy-space-1,4px) var(--bhy-space-1,4px) var(--bhy-space-1,4px) var(--bhy-space-3,12px);font-size:var(--bhy-text-sm,12px);">';
                echo '<a href="' . esc_url($url) . '" style="text-decoration:none;font-weight:' . ($is_active ? '700' : '400') . ';">' . esc_html($s['name']) . '</a>';
                echo ' <a href="' . esc_url($delete_url) . '" onclick="return confirm(\'Delete this saved list?\');" title="Delete" style="color:var(--bhy-ink-dim,#787c82);text-decoration:none;padding:0 var(--bhy-space-1,4px);">&times;</a>';
                echo '</span>';
            }
            echo '</div>';
        } else {
            echo '<p class="description" style="margin:0 0 var(--bhy-space-3,12px);">No saved lists yet — build one below (e.g. "tagged vip AND registered after a date AND has an active project").</p>';
        }

        // Collapsible builder — a <details> element needs zero JS for
        // the open/close behavior itself; segment-builder.js only
        // handles the repeatable condition rows inside it.
        echo '<details id="bhcrm-segment-builder">';
        echo '<summary style="cursor:pointer;color:var(--bhy-accent,#c1503a);font-size:var(--bhy-text-sm,12px);">+ Build a new list</summary>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:var(--bhy-space-3,12px);">';
        wp_nonce_field('bhcrm_save_segment');
        echo '<input type="hidden" name="action" value="bhcrm_save_segment">';
        echo '<p><input type="text" name="segment_name" placeholder="Name this list…" style="max-width:280px;"></p>';
        echo '<div id="bhcrm-segment-conditions"></div>';
        echo '<p><button type="button" class="button" id="bhcrm-add-condition">+ Add condition</button></p>';
        echo '<p><button type="submit" class="button button-primary">Save list</button></p>';
        echo '</form>';
        echo '</details>';
        echo '</div>';

        wp_enqueue_script('bhcrm-segment-builder', BHCRM_URL . 'assets/js/segment-builder.js', [], BHCRM_VER, true);
        wp_localize_script('bhcrm-segment-builder', 'bhcrmSegmentFields', BHCRM_Segments::FIELDS);
    }

    // Real name / platform handles / consent flags, admin-only. Never
    // exposed anywhere public — see BHI_Profiles for that guarantee.
    private static function render_profile($uid) {
        $p = BHI_Profiles::get($uid);
        $rows = array_filter([
            ['Real name', $p['real_name'], $p['real_name_public']],
            ['Discord',   $p['discord_name'], $p['discord_public']],
            ['Twitch',    $p['twitch_name'], $p['twitch_public']],
            ['YouTube',   $p['youtube_name'], $p['youtube_public']],
        ], fn($r) => $r[1] !== '');

        echo '<h3>Profile</h3>';
        if (!$rows && !$p['typical_platform'] && $p['phone'] === '') {
            echo '<p><em>No profile data collected yet.</em></p>';
            return;
        }
        if ($rows) {
            echo '<div class="bhy-table-wrap">';
            echo '<table class="wp-list-table widefat striped"><thead><tr><th>Field</th><th>Value</th><th>Consent to share</th></tr></thead><tbody>';
            foreach ($rows as [$label, $value, $public]) {
                echo '<tr><td>' . esc_html($label) . '</td><td>' . esc_html($value) . '</td>'
                   . '<td>' . ($public ? '&#10003; OK to share' : 'Keep private') . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
        if ($p['typical_platform']) echo '<p><strong>Usually watches on:</strong> ' . esc_html(ucfirst($p['typical_platform'])) . '</p>';
        // QA fix: bhcore_manage_crm (granted to editor + the new Studio
        // Manager role) previously gated the ENTIRE person page,
        // including this direct phone number — no split existed between
        // "can see the roster" and "can see private contact info." Now
        // requires the admin-only bhcore_view_crm_sensitive capability
        // on top of just reaching this page at all.
        if ($p['phone'] !== '' && current_user_can('bhcore_view_crm_sensitive')) {
            echo '<p><strong>Phone</strong> (direct contact only, never shared): ' . esc_html($p['phone']) . '</p>';
        } elseif ($p['phone'] !== '') {
            echo '<p><em>Phone on file — hidden (admin only).</em></p>';
        }
    }

    // Same avatar/banner/bio header the public profile page renders
    // (BHI_Profiles is the one shared source, own-ur-shit's
    // BHI_PublicProfile is the OTHER renderer of it) — admin-only
    // context here just adds the public/private state and a direct
    // link out to the live page when one exists, since staff often
    // need to check "what does this actually look like."
    private static function render_identity_header($uid) {
        if (!class_exists('BHI_Profiles')) return;
        $p = BHI_Profiles::get($uid);
        $avatar = $p['avatar_id'] ? wp_get_attachment_image_url((int) $p['avatar_id'], 'thumbnail') : '';
        if (!$avatar) $avatar = get_avatar_url($uid, ['size' => 96]);
        $banner = $p['banner_id'] ? wp_get_attachment_image_url((int) $p['banner_id'], 'medium_large') : '';

        echo '<div class="bhcrm-identity-header" style="position:relative;margin-bottom:16px;">';
        if ($banner) {
            echo '<div style="height:120px;background:url(' . esc_url($banner) . ') center/cover;border-radius:8px;margin-bottom:-40px;"></div>';
        }
        echo '<div style="display:flex;align-items:flex-end;gap:14px;padding:0 12px;">';
        echo '<img src="' . esc_url($avatar) . '" width="80" height="80" style="border-radius:50%;object-fit:cover;border:3px solid #fff;background:#eee;" />';
        echo '<div>';
        if ($p['profile_public'] && class_exists('BHI_PublicProfile')) {
            echo '<a href="' . esc_url(BHI_PublicProfile::profile_url($uid)) . '" target="_blank">View public profile page &rarr;</a>';
        } else {
            echo '<span style="color:#777;">Profile page not public</span>';
        }
        echo '</div></div>';
        if ($p['bio']) {
            echo '<p style="margin-top:10px;padding:0 12px;color:#555;">' . esc_html(wp_trim_words($p['bio'], 40)) . '</p>';
        }
        echo '</div>';
    }

    private static function render_detail($uid) {
        $user = get_userdata($uid);
        if (!$user) { echo '<p>User not found.</p>'; return; }

        // Element-builder context for this profile (design doc §5.2) —
        // every render_slot() call below shares this one $ctx, resolved
        // once per page load, since 'user_id' is the only context token
        // the 'bh_crm_profile' surface declares (register_element_surface()
        // above). Building $ctx unconditionally is cheap and harmless even
        // when BH_Element isn't loaded — render_slot() itself is the guard.
        $element_ctx = ['user_id' => $uid];

        echo '<p style="margin-bottom:var(--bhy-space-4,16px);"><a href="' . esc_url(remove_query_arg('user_id')) . '">&larr; All people</a></p>';

        // QA fix: this whole page used to be render_identity_header()
        // plus five sections (profile/tags/notes/projects/activity)
        // echoed back to back with only their own internal h2/h3/p
        // margins for spacing — no card grouping at all, so everything
        // ran together (confirmed live: name, email, "Profile", "Tags",
        // "Notes" all reading as one undifferentiated block). Wrapped
        // each section in .bhy-card, this design system's existing
        // shared card treatment (own-ur-shit's class-ui.php) — every
        // other custom admin screen already uses it, this page just
        // hadn't been brought in line with it yet.
        self::render_identity_header($uid);

        echo '<div class="bhy-card">';
        echo '<h2 style="text-transform:none;letter-spacing:normal;font-size:var(--bhy-text-xl,20px);color:var(--bhy-ink,inherit);">' . esc_html($user->display_name) . '</h2>';
        echo '<p>' . esc_html($user->user_email) . ' &middot; Registered ' . esc_html(mysql2date('M j, Y', $user->user_registered))
           . ' &middot; <a href="' . esc_url(get_edit_user_link($uid)) . '">Edit WordPress profile</a></p>';
        self::render_profile($uid);
        echo '</div>';

        echo '<div class="bhy-card">';
        BHCRM_Tags::render_editor($uid);
        echo '</div>';

        echo '<div class="bhy-card">';
        BHCRM_Notes::render_editor($uid);
        echo '</div>';

        // 1.2.0 — project tracker "Projects" section, additive, same
        // "appended after the existing fixed content" placement as the
        // tags/notes editors right above it.
        if (class_exists('BHCRM_Projects')) {
            echo '<div class="bhy-card">';
            BHCRM_Projects::render_projects_section($uid);
            echo '</div>';
        }

        $sections = apply_filters('bh_crm_activity_summary', [], $uid);
        if ($sections) {
            echo '<div class="bhy-card">';
            echo '<h3>Activity</h3>';
            foreach ($sections as $section) {
                echo '<h4>' . esc_html($section['plugin']) . '</h4><p>' . esc_html($section['summary']) . '</p>';
                if (!empty($section['render'])) call_user_func($section['render']);
            }
            echo '</div>';
        }

        // 1.3.3 — single 'root' slot, renders after the existing fixed
        // CRM data (fields table, tags, notes, activity sections) —
        // still additive/appended, never displacing existing regions,
        // same posture the old 'main'/'sidebar' calls had. The old
        // 'header'/'main'/'sidebar' split is gone (register_element_
        // surface()'s own updated docblock) — whatever layout this area
        // needs (a header-looking section, a two-column split, etc.) is
        // now something the user builds as real child nodes under this
        // one slot, not something this PHP template pre-decides.
        if (class_exists('BH_Element')) {
            echo BH_Element::render_slot('bh_crm_profile', $uid, 'root', $element_ctx);
        }
    }
}

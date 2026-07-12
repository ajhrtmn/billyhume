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
     */
    public static function register_element_surface($surfaces) {
        $surfaces['bh_crm_profile'] = [
            'group'       => 'CRM',
            'label'       => 'CRM profile page',
            'slots'       => [
                'header'  => ['label' => 'Header'],
                'main'    => ['label' => 'Main column'],
                'sidebar' => ['label' => 'Sidebar'],
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
    private static function active_user_ids() {
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
        BHY_UI::shell_open('People/CRM');
        if (isset($_GET['bhcrm_msg'])) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sanitize_text_field(wp_unslash($_GET['bhcrm_msg']))) . '</p></div>';

        // 1.2.0 — project tracker board dispatch. Rides on THIS existing
        // single-page dispatch (admin.php?page=bh-crm) rather than a new
        // standalone page — see class-projects.php's docblock for why a
        // second page was deliberately avoided on this install.
        if ($project_id && $uid && class_exists('BHCRM_Projects')) {
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

        echo '<p>Anyone with profile data on file or recorded activity. Click a name for their full detail.</p>';

        $all_tags = BHCRM_Tags::all_in_use();
        if ($all_tags) {
            echo '<p><strong>Filter by tag:</strong> ';
            echo '<a href="' . esc_url(remove_query_arg('tag')) . '">All</a> ';
            foreach ($all_tags as $t) {
                echo '&middot; <a href="' . esc_url(add_query_arg('tag', $t)) . '"' . ($tag_filter === $t ? ' style="font-weight:700;"' : '') . '>' . esc_html($t) . '</a> ';
            }
            echo '</p>';
        }

        $export_url = wp_nonce_url(admin_url('admin-post.php?action=bhcrm_export' . ($tag_filter ? '&tag=' . urlencode($tag_filter) : '')), 'bhcrm_export');
        echo '<p><a class="button" href="' . esc_url($export_url) . '">Export CSV</a></p>';

        // Search + sortable columns — see BHY_UI::print_design_system_js()
        // for the shared, dependency-free behavior. Genuinely useful here
        // specifically: a real directory is exactly the case where
        // "find one person by name" and "sort by most recently
        // registered" matter, unlike a small fixed-size stats table.
        echo '<input type="text" class="bhy-table-search" data-target="#bhcrm-people-table" placeholder="Filter by name, email, or tag&hellip;">';

        // --tall: this table IS the whole page (a directory, not one of
        // several colocated cards) — see BHY_UI's own docblock on why
        // that distinction gets it more scroll room than the default.
        echo '<div class="bhy-table-wrap bhy-table-wrap--tall">';
        echo '<table id="bhcrm-people-table" class="wp-list-table widefat striped bhy-sortable"><thead><tr>'
           . '<th data-sort>Name</th><th data-sort>Email</th><th>Tags</th><th>Activity</th><th data-sort>Registered</th>'
           . '</tr></thead><tbody>';
        foreach ($ids as $uid) {
            $user = get_userdata($uid);
            if (!$user) continue;
            $summary = array_map(fn($s) => $s['summary'], apply_filters('bh_crm_activity_summary', [], $uid));
            echo '<tr>'
               . '<td><a href="' . esc_url(add_query_arg('user_id', $uid)) . '"><strong>' . esc_html($user->display_name) . '</strong></a></td>'
               . '<td>' . esc_html($user->user_email) . '</td>'
               . '<td>' . esc_html(implode(', ', BHCRM_Tags::get($uid))) . '</td>'
               . '<td>' . esc_html($summary ? implode(' &middot; ', $summary) : '—') . '</td>'
               . '<td>' . esc_html(mysql2date('M j, Y', $user->user_registered)) . '</td>'
               . '</tr>';
        }
        echo '</tbody></table></div>';
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
        if ($p['phone'] !== '') echo '<p><strong>Phone</strong> (direct contact only, never shared): ' . esc_html($p['phone']) . '</p>';
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

        echo '<p><a href="' . esc_url(remove_query_arg('user_id')) . '">&larr; All people</a></p>';

        // 'header' slot renders directly above the identity header —
        // additive only, same "surrounds existing output, never replaces
        // it" posture as the dashboard's render_slot() call (§5.1).
        if (class_exists('BH_Element')) echo BH_Element::render_slot('bh_crm_profile', $uid, 'header', $element_ctx);

        self::render_identity_header($uid);
        echo '<h2>' . esc_html($user->display_name) . '</h2>';
        echo '<p>' . esc_html($user->user_email) . ' &middot; Registered ' . esc_html(mysql2date('M j, Y', $user->user_registered))
           . ' &middot; <a href="' . esc_url(get_edit_user_link($uid)) . '">Edit WordPress profile</a></p>';

        self::render_profile($uid);
        BHCRM_Tags::render_editor($uid);
        BHCRM_Notes::render_editor($uid);

        // 1.2.0 — project tracker "Projects" section, additive, same
        // "appended after the existing fixed content" placement as the
        // tags/notes editors right above it.
        if (class_exists('BHCRM_Projects')) {
            BHCRM_Projects::render_projects_section($uid);
        }

        $sections = apply_filters('bh_crm_activity_summary', [], $uid);
        if ($sections) {
            echo '<h3>Activity</h3>';
            foreach ($sections as $section) {
                echo '<h4>' . esc_html($section['plugin']) . '</h4><p>' . esc_html($section['summary']) . '</p>';
                if (!empty($section['render'])) call_user_func($section['render']);
            }
        }

        // 'main' slot renders after the existing fixed content (fields
        // table, tags, notes, activity sections) — the same "additive,
        // appended, never displacing existing regions" rule the dashboard
        // integration follows. 'sidebar' is registered on the surface
        // (§5.2's three named slots) but this page has no actual visual
        // sidebar column today, so it renders inline immediately after
        // 'main' rather than being silently dropped — a real two-column
        // layout is a CSS/markup change out of scope for this pass.
        if (class_exists('BH_Element')) {
            echo BH_Element::render_slot('bh_crm_profile', $uid, 'main', $element_ctx);
            echo BH_Element::render_slot('bh_crm_profile', $uid, 'sidebar', $element_ctx);
        }
    }
}

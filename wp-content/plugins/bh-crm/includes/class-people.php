<?php
if (!defined('ABSPATH')) exit;

/**
 * Person list and detail view, built on shared identity (BHI_Profiles) plus two
 * filters other plugins may hook; it never queries another plugin's tables.
 *
 * 1. Add people to the list (beyond those with profile data):
 *
 *        add_filter('bh_crm_active_user_ids', function ($ids) {
 *            global $wpdb;
 *            return array_merge($ids, $wpdb->get_col(
 *                "SELECT DISTINCT user_id FROM {$wpdb->prefix}bh_votes"
 *            ));
 *        });
 *
 * 2. Add an activity section (summary line + optional detail renderer) to a person's page:
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
 * Hooking these is harmless when bh-crm isn't installed.
 */
class BHCRM_People {
    /** Per-person 'bh_crm_profile' element surface (context id = user_id), one 'root' slot. */
    /**
     * @param array<string, mixed> $surfaces
     * @return array<string, mixed>
     */
    public static function register_element_surface($surfaces): array {
        $surfaces['bh_crm_profile'] = [
            'group'       => 'CRM',
            'label'       => 'CRM profile page',
            'slots'       => [
                'root' => ['label' => 'Page content'],
            ],
            'context'     => ['type' => 'user', 'param' => 'user_id'],
            // Previews use the viewing admin as a stand-in subject.
            'preview_ctx' => function () { return ['user_id' => get_current_user_id()]; },
        ];
        return $surfaces;
    }

    // The menu entry is registered by core's class-registry.php ('bh-crm'), not here.

    /* ---------------- who's on the list ---------------- */

    // Anyone with profile data, plus anyone another plugin reports as active; not every WP subscriber.
    /** @return array<int, int> */
    public static function active_user_ids(): array {
        $with_profile = class_exists('BHI_Profiles') ? BHI_Profiles::user_ids_with_profile_data() : [];
        $contributed = apply_filters('bh_crm_active_user_ids', []);
        return array_unique(array_map('intval', array_merge($with_profile, $contributed)));
    }

    /* ---------------- page ---------------- */

    public static function render(): void {
        $uid = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
        $project_id = isset($_GET['project_id']) ? (int) $_GET['project_id'] : 0;
        BHY_UI::shell_open('People');
        if (isset($_GET['bhcrm_msg'])) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sanitize_text_field(wp_unslash($_GET['bhcrm_msg']))) . '</p></div>';

        // WHY: boards ride this single page's dispatch, not a standalone page (see class-projects.php).
        // $uid may be 0 for a project with no linked person.
        $card_id = isset($_GET['card_id']) ? (int) $_GET['card_id'] : 0;
        if ($project_id && $card_id && class_exists('BHCRM_Subtasks')) {
            $path = isset($_GET['subtask_path']) ? array_values(array_filter(array_map('sanitize_text_field', explode(',', (string) $_GET['subtask_path'])))) : [];
            BHCRM_Subtasks::render($project_id, $uid, $card_id, $path);
        } elseif ($project_id && class_exists('BHCRM_Projects')) {
            BHCRM_Projects::render_board($project_id, $uid);
        } elseif ($uid) {
            self::render_detail($uid);
        } else {
            self::render_list();
        }
        BHY_UI::shell_close();
    }

    private static function render_list(): void {
        $ids = self::active_user_ids();
        if (!$ids) {
            echo '<p>No one has a profile on file or any recorded activity yet. This list fills in on its own — nothing to configure.</p>';
            return;
        }

        $tag_filter = sanitize_text_field($_GET['tag'] ?? '');
        if ($tag_filter) $ids = array_filter($ids, fn($id) => in_array($tag_filter, BHCRM_Tags::get($id), true));

        // A saved segment is AND-combined with the tag filter.
        $segment_id = (int) ($_GET['segment'] ?? 0);
        $active_segment = $segment_id ? BHCRM_Segments::get($segment_id) : null;
        if ($active_segment) $ids = BHCRM_Segments::apply(array_values($ids), $active_segment['conditions']);

        // Toolbar: one flex column with a single token-driven gap.
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

        // Search + sortable columns come from BHY_UI::print_design_system_js().
        echo '<input type="text" class="bhy-table-search" data-target="#bhcrm-people-table" placeholder="Filter by name, email, or tag&hellip;">';

        echo '</div>';

        // Bulk actions: one form around the table; each button picks its target via formaction.
        $bulk_tag_action = wp_nonce_url(admin_url('admin-post.php?action=bhcrm_bulk_tag'), 'bhcrm_bulk_action');
        $bulk_export_action = wp_nonce_url(admin_url('admin-post.php?action=bhcrm_export'), 'bhcrm_export');
        echo '<form method="post" id="bhcrm-bulk-form">';
        echo '<div class="bhcrm-bulk-bar" style="display:flex;align-items:center;gap:8px;margin:10px 0;flex-wrap:wrap;">';
        echo '<input type="text" name="bulk_tag" placeholder="Tag to apply…" style="max-width:200px;">';
        echo '<button type="submit" formaction="' . esc_url($bulk_tag_action) . '" class="button">Tag selected</button>';
        echo '<button type="submit" formaction="' . esc_url($bulk_export_action) . '" class="button">Export selected (CSV)</button>';
        echo '<span class="description bhcrm-bulk-count">0 selected</span>';
        echo '</div>';

        // --tall: this table is the whole page, so it gets more scroll room.
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

    // Saved smart lists as pills, plus a collapsible builder for a new one.
    /** @param array<string, mixed>|null $active_segment */
    private static function render_segments_panel($active_segment): void {
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

        // segment-builder.js manages condition rows. With Datastar, input/change events bubble to the
        // conditions container, which @post()s the enclosing form ({contentType:'form'}) for a live
        // match count; ajax_preview() only reads 'conditions' and 'nonce'.
        $has_datastar = class_exists('OUS_Hypermedia');
        $preview_nonce = wp_create_nonce('bhcrm_preview_segment');
        $preview_url = admin_url('admin-ajax.php?action=bhcrm_preview_segment');

        echo '<details id="bhcrm-segment-builder"' . ($has_datastar ? ' data-signals="{previewCount: null, previewTotal: null}"' : '') . '>';
        echo '<summary style="cursor:pointer;color:var(--bhy-accent,#c1503a);font-size:var(--bhy-text-sm,12px);">+ Build a new list</summary>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:var(--bhy-space-3,12px);">';
        wp_nonce_field('bhcrm_save_segment');
        echo '<input type="hidden" name="action" value="bhcrm_save_segment">';
        if ($has_datastar) echo '<input type="hidden" name="nonce" value="' . esc_attr($preview_nonce) . '">';
        echo '<p><input type="text" name="segment_name" placeholder="Name this list…" style="max-width:280px;"></p>';
        echo '<div id="bhcrm-segment-conditions"'
            . ($has_datastar ? ' data-on:input__debounce.350ms="@post(\'' . esc_js($preview_url) . '\', {contentType: \'form\'})" data-on:change__debounce.350ms="@post(\'' . esc_js($preview_url) . '\', {contentType: \'form\'})"' : '')
            . '></div>';
        echo '<p><button type="button" class="button" id="bhcrm-add-condition">+ Add condition</button> ';
        if ($has_datastar) {
            echo '<span class="description" style="margin-left:8px;" data-show="$previewCount !== null" data-text="$previewCount + \' of \' + $previewTotal + \' people match\'"></span>';
        } else {
            // No OUS_Hypermedia: segment-builder.js's fetch() path fills this span.
            echo '<span id="bhcrm-segment-preview" class="description" style="margin-left:8px;"></span>';
        }
        echo '</p>';
        echo '<p><button type="submit" class="button button-primary">Save list</button></p>';
        echo '</form>';
        echo '</details>';
        echo '</div>';

        wp_enqueue_script('bhcrm-segment-builder', BHCRM_URL . 'assets/js/segment-builder.js', [], BHCRM_VER, true);
        wp_localize_script('bhcrm-segment-builder', 'bhcrmSegmentFields', BHCRM_Segments::FIELDS);
        if ($has_datastar) {
            OUS_Hypermedia::enqueue();
        } else {
            wp_localize_script('bhcrm-segment-builder', 'bhcrmSegmentPreview', ['nonce' => wp_create_nonce('bhcrm_preview_segment')]);
        }
    }

    // Real name / platform handles / consent flags, admin-only. Never
    // exposed anywhere public — see BHI_Profiles for that guarantee.
    private static function render_profile(int $uid): void {
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
        // WHY: the phone number needs bhcore_view_crm_sensitive, beyond the page's bhcore_manage_crm.
        if ($p['phone'] !== '' && current_user_can('bhcore_view_crm_sensitive')) {
            echo '<p><strong>Phone</strong> (direct contact only, never shared): ' . esc_html($p['phone']) . '</p>';
        } elseif ($p['phone'] !== '') {
            echo '<p><em>Phone on file — hidden (admin only).</em></p>';
        }
    }

    // Avatar/banner/bio header, plus public/private state and a link to the live profile page.
    private static function render_identity_header(int $uid): void {
        if (!class_exists('BHI_Profiles')) return;
        $p = BHI_Profiles::get($uid);
        $avatar = $p['avatar_id'] ? wp_get_attachment_image_url((int) $p['avatar_id'], 'thumbnail') : '';
        if (!$avatar) $avatar = get_avatar_url($uid, ['size' => 96]);
        $banner = $p['banner_id'] ? wp_get_attachment_image_url((int) $p['banner_id'], 'medium_large') : '';

        echo '<div class="bhcrm-identity-header" style="position:relative;margin-bottom:var(--bhy-space-4,16px);">';
        if ($banner) {
            echo '<div style="height:120px;background:url(' . esc_url($banner) . ') center/cover;border-radius:var(--bhy-radius,8px);margin-bottom:-40px;"></div>';
        }
        echo '<div style="display:flex;align-items:flex-end;gap:var(--bhy-space-3,14px);padding:0 var(--bhy-space-3,12px);">';
        echo '<img src="' . esc_url($avatar) . '" width="80" height="80" style="border-radius:50%;object-fit:cover;border:3px solid var(--bhy-surface,#fff);background:var(--bhy-border,#eee);" />';
        echo '<div>';
        $public_profiles_live = class_exists('BHI_PublicProfile')
            && (!method_exists('BHI_PublicProfile', 'public_enabled') || BHI_PublicProfile::public_enabled());
        if ($p['profile_public'] && $public_profiles_live) {
            echo '<a href="' . esc_url(BHI_PublicProfile::profile_url($uid)) . '" target="_blank">View public profile page &rarr;</a>';
        } else {
            echo '<span style="color:var(--bhy-text-dim,#777);">Profile page not public</span>';
        }
        echo '</div></div>';
        if ($p['bio']) {
            echo '<p style="margin-top:var(--bhy-space-2,10px);padding:0 var(--bhy-space-3,12px);color:var(--bhy-text-dim,#555);">' . esc_html(wp_trim_words($p['bio'], 40)) . '</p>';
        }
        echo '</div>';
    }

    private static function render_detail(int $uid): void {
        $user = get_userdata($uid);
        if (!$user) { echo '<p>User not found.</p>'; return; }

        $element_ctx = ['user_id' => $uid];

        echo '<p style="margin-bottom:var(--bhy-space-4,16px);"><a href="' . esc_url(remove_query_arg('user_id')) . '">&larr; All people</a></p>';

        // Each section sits in its own .bhy-card.
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

        // User-built element content, appended after the fixed CRM sections.
        if (class_exists('BH_Element')) {
            echo BH_Element::render_slot('bh_crm_profile', $uid, 'root', $element_ctx);
        }
    }
}

// Metrics dashboard cards. Links use a monthly trend: they move too slowly for a daily sparkline.
add_filter('bhcore_metrics_widgets', function ($widgets) {
    if (!class_exists('OUS_Metrics')) return $widgets;

    $widgets[] = ['source' => 'BH CRM', 'render' => function () {
        $people = count(apply_filters('bh_crm_active_user_ids', []));
        OUS_Metrics::render_card('People tracked', $people, 'Total, all-time');
    }];
    $widgets[] = ['source' => 'BH CRM', 'render' => function () {
        $trend = OUS_Metrics::event_trend_monthly('bhcrm/link_created', 12);
        OUS_Metrics::render_card('Relationship links', array_sum($trend), 'Last 12 months', $trend);
    }];
    return $widgets;
});

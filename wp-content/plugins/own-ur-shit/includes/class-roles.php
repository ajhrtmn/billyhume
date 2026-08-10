<?php
if (!defined('ABSPATH')) exit;

/**
 * A first, deliberately modest step toward real role differentiation
 * across this ecosystem — today, anything gated by a capability check
 * at all just uses a plain WordPress capability like `edit_posts`,
 * meaning "any contributor or above" rather than anything specific to
 * a role like course instructor.
 *
 * This registers new, genuinely granular capabilities, granted to
 * `administrator` by default so nothing anyone already has access to
 * changes. A site that wants a non-admin "instructor" account can grant
 * `bhcore_manage_students` to any role via the `bhcore_role_capabilities`
 * filter below (or a one-off `$user->add_cap(...)` call) without waiting
 * on a dedicated UI.
 *
 * Correction (2026-07-25 audit): this docblock used to say "not new
 * WordPress ROLES" as a flat claim — that's no longer fully accurate.
 * ensure_manager_role() below DOES create one real baseline role
 * ('bhcore_studio_manager', editor's own capability set at registration
 * time) as a starting point for sites that want a non-admin manager
 * account without hand-building one. A full role-ASSIGNMENT admin UI
 * (letting an admin pick which user gets which role/capability through
 * a screen, rather than a filter or a one-off add_cap() call) is still
 * separate, roadmapped scope — that half of the original claim still holds.
 *
 * Any plugin can register its own new capability the same way, via the
 * same filter, with zero changes needed here — same "core provides the
 * mechanism, plugins opt in with zero central registration" shape as
 * OUS_Notifications/OUS_Jobs.
 */
class OUS_Roles {
    const MANAGER_ROLE = 'bhcore_studio_manager';

    const DEFAULT_CAPS = [
        // Course instructor: view/manage student progress (bh-courses'
        // BHC_ProgressAdmin). Named generically ("manage_students," not
        // "manage_courses") so a future reviewer queue, or any other
        // plugin with a "students/participants" concept, can reuse it
        // rather than minting its own near-identical capability.
        'bhcore_manage_students' => ['administrator'],
        // The reviewer capability bh-feedback (a real, shipped plugin)
        // gates its claim queue on — see class-queue.php/class-portal-
        // panel.php, actively checked in 5 places.
        'bhcore_review_submissions' => ['administrator'],
        // DESIGN-SUITE-UNIFICATION-PLAN.md §1.3, Phase 1 — gates the
        // two new top-level menus to "real employees, not just devs/
        // admins."
        //
        // Stopgap: this ecosystem has no dedicated non-admin "staff"
        // role, so per §1.3 both caps are granted to the built-in
        // 'editor' role as the closest existing fit for "trusted
        // non-admin staff." A site that wants a narrower "designer but
        // not editor" or "CRM but not editor" account still grants the
        // bare capability to any role via 'bhcore_role_capabilities' or
        // a one-off $user->add_cap() call.
        'bhcore_design_site' => ['administrator', 'editor', self::MANAGER_ROLE],
        'bhcore_manage_crm'  => ['administrator', 'editor', self::MANAGER_ROLE],
        // bh-tickets (Phase 3 of the OSS-integration master plan) — same
        // trusted-staff tier as bhcore_manage_crm/bhcore_design_site,
        // not administrator-only, since ticket triage is exactly the
        // kind of day-to-day work a non-admin manager account should be
        // able to do without full admin rights.
        'bhcore_manage_tickets' => ['administrator', 'editor', self::MANAGER_ROLE],
        // A placement's config.custom_js (class-element.php's
        // wrap_placement_html()) is raw JavaScript that runs on the
        // live site for every visitor — a materially different trust
        // level than a color token or custom_class/custom_css (which
        // can at worst make something ugly; arbitrary JS can exfiltrate
        // data, hijack forms, or break the page). Deliberately
        // administrator-only, not extended to editor like
        // bhcore_design_site/manage_crm — grant explicitly via
        // 'bhcore_role_capabilities' if a non-admin needs it.
        'bhcore_author_custom_js' => ['administrator'],
        // A "site manager" tier that can do CRM/contest/course work but
        // is more restricted than admin on sensitive person-level data
        // — phone numbers, wallet balance, purchase history, refund-
        // fraud flags. Previously bhcore_manage_crm gated the person
        // list and their sensitive data identically; this capability
        // gates only the sensitive fields (see BHCRM_People::
        // render_profile()'s phone line and bh-monetization-woo's
        // BHM_CRMIntegration) — administrator-only, not granted to
        // editor or MANAGER_ROLE, so a manager can do real CRM work
        // while private fields stay admin-eyes-only.
        'bhcore_view_crm_sensitive' => ['administrator'],
    ];

    // A plain register_activation_hook() alone would miss a file-replace
    // deploy of an already-active plugin, since that never fires
    // WordPress's real activation hook. add_cap() is idempotent, so
    // re-running this on every 'init' costs one cheap in-memory check,
    // not a real migration.
    public static function init(): void {
        self::activate();
    }

    // Also registered directly as this plugin's activation hook (see
    // own-ur-shit.php) for the immediate-effect case on a brand-new
    // install — both call sites are safe simultaneously since this is
    // fully idempotent.
    public static function activate(): void {
        self::ensure_manager_role();

        $caps = apply_filters('bhcore_role_capabilities', self::DEFAULT_CAPS);
        foreach ($caps as $cap => $role_names) {
            foreach ($role_names as $role_name) {
                $role = get_role($role_name);
                if ($role && !$role->has_cap($cap)) $role->add_cap($cap);
            }
        }
    }

    // The first real custom ROLE this ecosystem has ever registered
    // (every capability above rides on a built-in role instead) — a
    // "Studio Manager" a site owner can assign from Users -> Add New,
    // distinct from 'editor' so it can be deliberately withheld from
    // bhcore_view_crm_sensitive without stripping that access off
    // 'editor' itself.
    //
    // Base capability set is editor's own current capabilities at
    // registration time, not a hand-copied static list — the ecosystem's
    // CPTs (bh_contest/bh_course/bh_lesson) all use the default 'post'
    // capability_type, so the same edit_posts/edit_others_posts/
    // publish_posts/delete_posts capabilities editor relies on are what
    // this role needs too. add_role() is a silent no-op if the role
    // already exists, so this can't clobber capabilities an admin has
    // since customized on this role — it only creates it once.
    private static function ensure_manager_role(): void {
        if (get_role(self::MANAGER_ROLE)) return;
        $editor = get_role('editor');
        $caps = $editor ? $editor->capabilities : ['read' => true, 'edit_posts' => true, 'upload_files' => true];
        add_role(self::MANAGER_ROLE, 'Studio Manager', $caps);
    }
}

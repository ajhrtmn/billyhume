<?php
if (!defined('ABSPATH')) exit;

/**
 * A first, deliberately modest step toward real role differentiation
 * across this ecosystem — today, anything gated by a capability check
 * at all (bh-courses' Student Progress page, say) just uses a plain
 * WordPress capability like `edit_posts`, which means "any contributor
 * or above" rather than anything specific to being a course instructor.
 * That's fine solo-artist-only; it stops being fine the moment Billy
 * wants to hand off grading/teaching to someone who ISN'T also a full
 * admin.
 *
 * This registers two new, genuinely granular capabilities — not new
 * WordPress ROLES (a full role-assignment admin UI is real, separate
 * scope, flagged in the roadmap) — granted to `administrator` by
 * default so nothing anyone already has access to changes today. A
 * site that wants a non-admin "instructor" account can, right now,
 * create a plain `contributor`-or-similar account and grant it
 * `bhcore_manage_students` via the `bhcore_role_capabilities` filter
 * below (or a one-off `$user->add_cap(...)` call) without waiting on a
 * dedicated UI — the capability existing and being CHECKED consistently
 * is the actual unlock; a nice admin screen for assigning it is a
 * separate, later convenience layer.
 *
 * Any plugin can register its OWN new capability the same way, via the
 * same filter, with zero changes needed here — same "core provides the
 * mechanism, plugins opt in with zero central registration" shape as
 * OUS_Notifications/OUS_Jobs.
 */
class OUS_Roles {
    const DEFAULT_CAPS = [
        // Course instructor: view/manage student progress (bh-courses'
        // BHC_ProgressAdmin). Named generically ("manage_students," not
        // "manage_courses") since a future BH Feedback reviewer queue,
        // or any other plugin with a "students/participants" concept,
        // can reuse the exact same capability rather than each plugin
        // minting its own near-identical one.
        'bhcore_manage_students' => ['administrator'],
        // Scaffolded now, used by nothing yet — the reviewer capability
        // BH Feedback (roadmapped, not built) will check once it exists,
        // registered here now so that plugin's own activation doesn't
        // need to touch role capabilities itself.
        'bhcore_review_submissions' => ['administrator'],
        // DESIGN-SUITE-UNIFICATION-PLAN.md §1.3, Phase 1 (class-design-
        // suite.php, bh-crm/includes/class-hub.php) — "real employees,
        // not just devs/admins" gating the two new top-level menus.
        //
        // STOPGAP, flagged explicitly: this ecosystem has no dedicated
        // non-admin "staff/employee" role of its own (no OUS_Roles-
        // managed role concept exists to extend beyond the granular
        // capabilities listed in this const — see this class's own
        // docblock: "not new WordPress ROLES... a full role-assignment
        // admin UI is real, separate scope"). Per the design doc's own
        // §1.3 decision, both new caps are granted to the built-in
        // WordPress 'editor' role as the minimal viable "real employee"
        // default — editor already means "trusted non-admin staff" in
        // plain WordPress, and is the closest existing fit. A site that
        // wants a narrower "designer but not editor" or "CRM but not
        // editor" account still grants the bare capability to any role
        // via the 'bhcore_role_capabilities' filter or a one-off
        // $user->add_cap() call, same unlock this class's docblock
        // already documents for bhcore_manage_students — no change
        // needed here for that.
        'bhcore_design_site' => ['administrator', 'editor'],
        'bhcore_manage_crm'  => ['administrator', 'editor'],
        // AJ's own ask, folded into the bh-contest conversion: "attach
        // JS scripting... via the interface builder." A placement's
        // config.custom_js (class-element.php's wrap_placement_html())
        // is raw JavaScript that runs on the live site for every
        // visitor — a materially different trust level than a color
        // token or even custom_class/custom_css (which can at worst make
        // something ugly; arbitrary JS can exfiltrate data, hijack forms,
        // or break the page outright). Deliberately administrator-only,
        // NOT extended to editor the way bhcore_design_site/manage_crm
        // are — a site that wants a trusted non-admin to have this can
        // grant it explicitly via 'bhcore_role_capabilities' below, but
        // it does not ride along with general design/editing access by
        // default.
        'bhcore_author_custom_js' => ['administrator'],
    ];

    // Same reasoning this whole ecosystem repeats everywhere else a
    // migration exists (see BHI_Activator, BHS_Activator, BHC_Activator):
    // a plain register_activation_hook() ALONE would miss a file-replace
    // deploy of an already-active plugin, since that never fires
    // WordPress's real activation hook. add_cap() is idempotent (a
    // no-op if the role already has it), so re-running this on every
    // 'init' costs one cheap in-memory check, not a real migration —
    // cheap enough not to need its own separate versioned skip-check the
    // way a DB schema change does.
    public static function init() {
        self::activate();
    }

    // Also registered directly as this plugin's activation hook (see
    // own-ur-shit.php) for the immediate-effect case on a BRAND NEW
    // install, before init() would otherwise get a chance to run once —
    // both call sites are safe to have simultaneously since this is
    // fully idempotent.
    public static function activate() {
        $caps = apply_filters('bhcore_role_capabilities', self::DEFAULT_CAPS);
        foreach ($caps as $cap => $role_names) {
            foreach ($role_names as $role_name) {
                $role = get_role($role_name);
                if ($role && !$role->has_cap($cap)) $role->add_cap($cap);
            }
        }
    }
}

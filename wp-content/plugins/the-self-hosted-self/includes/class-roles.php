<?php
if (!defined('ABSPATH')) exit;

/**
 * Granular ecosystem capabilities (granted to administrator by default) plus
 * two roles: bh_member (fans) and bhcore_studio_manager. Any plugin can add or
 * re-grant capabilities via the `bhcore_role_capabilities` filter.
 */
class OUS_Roles {
    const MANAGER_ROLE = 'bhcore_studio_manager';

    // Role for self-registered fans/students: `read` only. WHY: its own role, not
    // `subscriber`, so third-party caps granted to subscriber never reach it.
    const MEMBER_ROLE = 'bh_member';

    const DEFAULT_CAPS = [
        // Course instructors (BHC_ProgressAdmin); named generically for reuse.
        'bhcore_manage_students' => ['administrator'],
        // bh-feedback's reviewer claim queue.
        'bhcore_review_submissions' => ['administrator'],
        // Design Suite access for trusted non-admin staff (editor is the closest
        // built-in fit).
        'bhcore_design_site' => ['administrator', 'editor', self::MANAGER_ROLE],
        'bhcore_manage_crm'  => ['administrator', 'editor', self::MANAGER_ROLE],
        // Ticket triage: trusted-staff tier, not admin-only.
        'bhcore_manage_tickets' => ['administrator', 'editor', self::MANAGER_ROLE],
        // WHY: administrator-only. A placement's config.custom_js runs for every
        // visitor and can exfiltrate data, unlike style tokens.
        'bhcore_author_custom_js' => ['administrator'],
        // Sensitive person-level CRM fields (phone, wallet, purchases, fraud
        // flags). Administrator-only, so managers can do CRM work without them.
        'bhcore_view_crm_sensitive' => ['administrator'],
    ];

    // WHY: runs on every init (add_cap() is idempotent) because a file-replace
    // deploy never fires the activation hook.
    public static function init(): void {
        self::activate();
    }

    // Also the activation hook, for immediate effect on a new install.
    public static function activate(): void {
        self::ensure_manager_role();
        self::ensure_member_role();

        $caps = apply_filters('bhcore_role_capabilities', self::DEFAULT_CAPS);
        foreach ($caps as $cap => $role_names) {
            foreach ($role_names as $role_name) {
                $role = get_role($role_name);
                if ($role && !$role->has_cap($cap)) $role->add_cap($cap);
            }
        }
    }

    // "Studio Manager": editor's capabilities at creation time, separate from
    // editor so bhcore_view_crm_sensitive can be withheld. add_role() is a no-op
    // if the role exists, so admin customizations are never clobbered.
    private static function ensure_manager_role(): void {
        if (get_role(self::MANAGER_ROLE)) return;
        $editor = get_role('editor');
        $caps = $editor ? $editor->capabilities : ['read' => true, 'edit_posts' => true, 'upload_files' => true];
        add_role(self::MANAGER_ROLE, 'Studio Manager', $caps);
    }

    // The fan/student role: just `read`. add_role() never clobbers later edits.
    // Filterable for extra caps on creation.
    private static function ensure_member_role(): void {
        if (get_role(self::MEMBER_ROLE)) return;
        $caps = apply_filters('bhi_member_role_caps', ['read' => true]);
        add_role(self::MEMBER_ROLE, apply_filters('bhi_member_role_label', 'Member'), $caps);
    }
}

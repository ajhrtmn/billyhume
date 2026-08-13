<?php
if (!defined('ABSPATH')) exit;

/**
 * OUS_Visibility — a shared "does this require a logged-in account to
 * view" contract, the same shared-service shape as Notifications/Jobs/
 * Roles/Events. Built in response to a real product decision: an
 * ungated (no required tier) bh-courses course was fully viewable by a
 * logged-OUT visitor, because BHM_Gate's tier check only ever asks "is
 * the tier requirement satisfied" — for a course with no tier set at
 * all, that's vacuously true regardless of login state. Login and tier
 * are two genuinely different questions this ecosystem was conflating:
 * "can anyone with an account see this" vs. "does seeing it cost
 * something."
 *
 * Deliberately NOT applied ecosystem-wide by default — bh-contest's
 * whole design depends on a contest being publicly viewable/shareable
 * (that's how voting/engagement actually works; login is required to
 * VOTE, not to see the contest), so this is opt-in per plugin/CPT, not
 * a global "everything requires login" switch. bh-courses is the first
 * (and, as of this pass, only) adopter — real content behind a login
 * wall by default, with an explicit per-post "Public" override for a
 * course an artist genuinely wants open to anyone.
 */
class OUS_Visibility {
    const META_KEY = '_ous_public_access';

    // A post marked explicitly public is viewable with no account at
    // all — the artist's own deliberate choice (a free/preview course,
    // for example), not a default.
    public static function is_public(int $post_id): bool {
        return (bool) get_post_meta($post_id, self::META_KEY, true);
    }

    // The actual gate: logged in, OR explicitly marked public.
    public static function can_view(int $post_id): bool {
        return is_user_logged_in() || self::is_public($post_id);
    }

    // Reusable "log in to view this" notice — routes through the
    // portal's own branded login screen when installed (BHI_Portal,
    // class-portal.php), falling back to core's own wp_login_url() so
    // this still works standalone if the portal isn't active.
    public static function render_login_notice(string $message = ''): string {
        $login_url = class_exists('BHI_Portal') ? home_url('/' . BHI_Portal::REWRITE_SLUG . '/') : wp_login_url(home_url($_SERVER['REQUEST_URI'] ?? '/'));
        $message = $message ?: __('Log in to view this.', 'own-ur-shit');
        return '<div class="ous-login-required"><p>' . esc_html($message) . '</p><p><a class="button ous-login-required-cta" href="' . esc_url($login_url) . '">' . esc_html__('Log In', 'own-ur-shit') . '</a></p></div>';
    }

    // Reusable admin checkbox markup — a contributing plugin's own
    // metabox echoes this and handles its own save (same META_KEY),
    // rather than this class owning a metabox on a CPT it knows
    // nothing about.
    public static function checkbox_field(int $post_id, string $label = ''): string {
        $checked = self::is_public($post_id) ? 'checked' : '';
        $label = $label ?: __('Public — no login required to view', 'own-ur-shit');
        return '<label><input type="checkbox" name="' . esc_attr(self::META_KEY) . '" value="1" ' . $checked . '> ' . esc_html($label) . '</label>';
    }

    // A contributing plugin's own save handler calls this after its own
    // nonce/capability checks — kept here only so the meta key itself
    // stays a single source of truth.
    public static function save_from_request(int $post_id): void {
        update_post_meta($post_id, self::META_KEY, !empty($_POST[self::META_KEY]) ? '1' : '');
    }

    /* ---------------- inverted polarity: default-open, opt-in-closed ---------------- */

    // bh-courses (above) defaults CLOSED — a course requires login
    // unless explicitly marked public. bh-contest is the opposite: a
    // contest defaults OPEN (its whole design depends on being publicly
    // viewable/shareable — that's how voting/engagement works), with an
    // explicit per-contest opt-IN for the ones an artist genuinely wants
    // restricted to logged-in members only. Deliberately a SEPARATE meta
    // key/method pair rather than reusing is_public()/can_view() with
    // inverted logic at each call site — "is this open" and "is this
    // closed" read backwards from each other if only one boolean flag
    // is doing double duty, and a future contest-side bug from flipped
    // polarity is exactly the kind of subtle mistake worth a few extra
    // lines to rule out entirely.
    const MEMBERS_ONLY_META_KEY = '_ous_members_only';

    public static function is_members_only(int $post_id): bool {
        return (bool) get_post_meta($post_id, self::MEMBERS_ONLY_META_KEY, true);
    }

    // The default-open gate: logged in, OR not marked members-only.
    public static function can_view_open_by_default(int $post_id): bool {
        return is_user_logged_in() || !self::is_members_only($post_id);
    }

    public static function members_only_checkbox_field(int $post_id, string $label = ''): string {
        $checked = self::is_members_only($post_id) ? 'checked' : '';
        $label = $label ?: __('Members only — requires a logged-in account to view', 'own-ur-shit');
        return '<label><input type="checkbox" name="' . esc_attr(self::MEMBERS_ONLY_META_KEY) . '" value="1" ' . $checked . '> ' . esc_html($label) . '</label>';
    }

    public static function save_members_only_from_request(int $post_id): void {
        update_post_meta($post_id, self::MEMBERS_ONLY_META_KEY, !empty($_POST[self::MEMBERS_ONLY_META_KEY]) ? '1' : '');
    }
}

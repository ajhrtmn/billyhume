<?php
if (!defined('ABSPATH')) exit;

/**
 * OUS_Visibility: shared "requires a logged-in account to view" contract,
 * separate from tier gating (login and paying are different questions).
 * Opt-in per plugin: bh-courses defaults closed, bh-contest defaults open.
 */
class OUS_Visibility {
    const META_KEY = '_ous_public_access';

    // An explicitly public post is viewable without an account.
    public static function is_public(int $post_id): bool {
        return (bool) get_post_meta($post_id, self::META_KEY, true);
    }

    // The actual gate: logged in, OR explicitly marked public.
    public static function can_view(int $post_id): bool {
        return is_user_logged_in() || self::is_public($post_id);
    }

    // "Log in to view this" notice, using the portal login when available,
    // else wp_login_url().
    public static function render_login_notice(string $message = ''): string {
        // The portal validates redirect_to against this site before using it (BHI_Portal::post_login_redirect()).
        // Built from home_url()'s own origin, never the client-supplied Host header.
        $home = (array) wp_parse_url(home_url());
        $here = ($home['scheme'] ?? 'https') . '://' . ($home['host'] ?? '') . (isset($home['port']) ? ':' . (int) $home['port'] : '')
            . wp_unslash($_SERVER['REQUEST_URI'] ?? '/');
        $login_url = class_exists('BHI_Portal')
            ? add_query_arg('redirect_to', rawurlencode($here), home_url('/' . BHI_Portal::REWRITE_SLUG . '/'))
            : wp_login_url($here);
        $message = $message ?: __('Log in to view this.', 'the-self-hosted-self');
        return '<div class="ous-login-required"><p>' . esc_html($message) . '</p><p><a class="button ous-login-required-cta" href="' . esc_url($login_url) . '">' . esc_html__('Log In', 'the-self-hosted-self') . '</a></p></div>';
    }

    // Checkbox markup for a contributing plugin's own metabox (same META_KEY).
    public static function checkbox_field(int $post_id, string $label = ''): string {
        $checked = self::is_public($post_id) ? 'checked' : '';
        $label = $label ?: __('Public — no login required to view', 'the-self-hosted-self');
        return '<label><input type="checkbox" name="' . esc_attr(self::META_KEY) . '" value="1" ' . $checked . '> ' . esc_html($label) . '</label>';
    }

    // Called by the plugin's save handler after its own nonce/capability checks.
    public static function save_from_request(int $post_id): void {
        update_post_meta($post_id, self::META_KEY, !empty($_POST[self::META_KEY]) ? '1' : '');
    }

    /* ---------------- inverted polarity: default-open, opt-in-closed ---------------- */

    // Default-open counterpart (bh-contest): opt-in members-only. WHY: a separate
    // meta key, not inverted is_public() logic, to rule out flipped-polarity bugs.
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
        $label = $label ?: __('Members only — requires a logged-in account to view', 'the-self-hosted-self');
        return '<label><input type="checkbox" name="' . esc_attr(self::MEMBERS_ONLY_META_KEY) . '" value="1" ' . $checked . '> ' . esc_html($label) . '</label>';
    }

    public static function save_members_only_from_request(int $post_id): void {
        update_post_meta($post_id, self::MEMBERS_ONLY_META_KEY, !empty($_POST[self::MEMBERS_ONLY_META_KEY]) ? '1' : '');
    }
}

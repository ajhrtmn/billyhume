<?php
if (!defined('ABSPATH')) exit;

/**
 * Design Suite live view of the real 'bh_crm_profile' surface, rendered via BH_Element::render_slot() (context id 0).
 * WHY: keyed by the surface's real slug, so the gallery's auto-story generator skips it and tree selection-sync matches.
 */
class BHCRM_StyleSurface {
    public static function init(): void {
        add_filter('bhy_style_surfaces', [self::class, 'register']);
    }

    /**
     * @param array<string, mixed> $surfaces
     * @return array<string, mixed>
     */
    public static function register($surfaces): array {
        if (!class_exists('BH_Element')) return $surfaces;
        $surfaces['bh_crm_profile'] = [
            'group'  => 'CRM',
            'label'  => 'CRM profile page (live)',
            'render' => [self::class, 'profile_preview'],
        ];
        return $surfaces;
    }

    private static function css_url(): string {
        return OUS_URL . 'assets/css/public-profile.css'; // front-end profile styling, so the preview looks like the public page
    }

    /** @return array{css_url:string, html:string|false} */
    public static function profile_preview(): array {
        $ctx = ['user_id' => 0];
        ob_start();
        ?>
<div class="bhi-profile">
    <div class="bhi-profile__header">
        <img class="bhi-profile__avatar" src="<?php echo esc_url(get_avatar_url(0, ['default' => 'mystery'])); ?>" alt="">
        <div class="bhi-profile__name">Sample person</div>
    </div>
    <div class="bhi-profile__bio">
        <p style="margin:0;color:var(--bh-text-dim);">This is the real, live "root" slot content for the <code>bh_crm_profile</code> surface at context 0 — add and style placements in the tree and they render here for real, not as a mockup. No more separate header/main/sidebar zones — build whatever layout you want directly.</p>
    </div>
    <?php echo BH_Element::render_slot('bh_crm_profile', 0, 'root', $ctx); ?>
</div>
        <?php
        return ['css_url' => self::css_url(), 'html' => ob_get_clean()];
    }
}

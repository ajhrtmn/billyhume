<?php
if (!defined('ABSPATH')) exit;

/**
 * Public, stream-safe page for listening through every approved
 * submission in a contest — meant for the "listen to entries" phase
 * between submissions closing and voting opening. Shows only public
 * info (song title, artist name) — never the real-identity data the
 * private Live Console shows, since this page is designed to be
 * screen-captured in OBS.
 *
 * Server-rendered rather than REST-driven: the track list doesn't need
 * to update live while someone's watching, so there's no reason to add
 * polling/fetch complexity here the way the Results Reveal genuinely
 * needs it for controller/display sync.
 *
 * Matches whatever theme the resolved contest actually uses, including
 * a per-contest override if one is enabled — the enqueued global
 * stylesheet only carries the site-wide palette, so this page embeds its
 * own <style> block with that specific contest's effective colors
 * directly in its output. That also sidesteps any risk of a late
 * wp_add_inline_style() call landing after styles have already printed
 * in wp_head — a plain <style> tag in the page body wins the cascade
 * for :root custom properties purely by coming later in document order,
 * regardless of where in the DOM it physically sits.
 */
class BH_Listening {
    public static function init() {
        add_shortcode('bh_listening_party', [self::class, 'render']);
    }

    public static function render($atts) {
        $atts = shortcode_atts(['contest' => ''], $atts, 'bh_listening_party');
        $cid = BH_Helpers::resolve_contest($atts['contest']);

        if (!$cid) {
            return current_user_can('edit_posts')
                ? '<p style="padding:12px 16px;background:#3a2a00;color:#ffcf6b;border-radius:6px;font-family:sans-serif;font-size:13px;">No contest found for the Listening Party — pass a contest attribute or publish one.</p>'
                : '';
        }

        $subs = get_posts([
            'post_type' => 'bh_submission', 'post_status' => 'publish',
            'meta_key' => '_bh_contest_id', 'meta_value' => $cid,
            'posts_per_page' => -1, 'orderby' => 'rand', // random order — no ranking has happened yet at this phase
        ]);

        ob_start();
        ?>
        <style><?php echo BH_Settings::inline_css($cid); ?></style>
        <div class="bh-container bh-listening">
            <div class="bh-header">
                <div class="bh-brand"><?php echo esc_html(get_the_title($cid)); ?></div>
            </div>
            <?php if (!$subs): ?>
                <p class="bh-empty">No approved submissions yet.</p>
            <?php else: ?>
                <div class="bh-listening-grid">
                    <?php foreach ($subs as $p):
                        $aid = (int) get_post_meta($p->ID, '_bh_audio_id', true);
                        $url = $aid ? wp_get_attachment_url($aid) : '';
                        ?>
                        <div class="bh-listening-card">
                            <div class="bh-listening-title"><?php echo esc_html($p->post_title); ?></div>
                            <div class="bh-listening-artist"><?php echo esc_html(BH_Helpers::artist_for($p)); ?></div>
                            <?php if ($url): ?>
                                <audio controls preload="none" src="<?php echo esc_url($url); ?>" class="bh-listening-audio"></audio>
                            <?php else: ?>
                                <p class="bh-empty" style="padding:8px;">No audio attached.</p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}

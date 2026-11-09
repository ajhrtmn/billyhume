<?php
if (!defined('ABSPATH')) exit;

/**
 * Course sales page: the bhc/course-landing block and [bh_course_landing id=""] shortcode.
 * Every section comes from the course's own fields (Course Details → Sales page), so the
 * same page works dropped into the block editor or an Etch layout.
 */
class BHC_Render_Landing {
    private static bool $rendering = false;

    /** @param mixed $atts */
    public static function render($atts): string {
        $course_id = (int) ((is_array($atts) ? ($atts['id'] ?? 0) : 0) ?: get_the_ID());
        if (!$course_id || get_post_type($course_id) !== 'bh_course' || get_post_status($course_id) !== 'publish') return '';
        // WHY: the About section runs the_content on the course description, which could hold this shortcode.
        if (self::$rendering) return '';
        self::$rendering = true;
        try {
            return self::render_page($course_id, get_current_user_id());
        } finally {
            self::$rendering = false;
        }
    }

    private static function render_page(int $course_id, int $uid): string {
        ob_start();
        echo '<div class="bhc-landing">';
        echo self::hero($course_id, $uid);

        $content = (string) get_post_field('post_content', $course_id);
        if (trim($content) !== '') {
            echo '<section class="bhc-landing-section bhc-landing-about"><h2>About the course</h2>' . apply_filters('the_content', $content) . '</section>';
        }
        echo self::contents($course_id);
        echo self::testimonials((string) get_post_meta($course_id, '_bhc_landing_testimonials', true));
        echo self::faq((string) get_post_meta($course_id, '_bhc_landing_faq', true));

        echo '<section class="bhc-landing-section bhc-landing-final">';
        echo '<h2>' . esc_html(get_the_title($course_id)) . '</h2>';
        echo self::cta($course_id, $uid);
        echo self::fine_print();
        echo '</section>';
        echo '</div>';
        return (string) ob_get_clean();
    }

    private static function hero(int $course_id, int $uid): string {
        $video = (string) get_post_meta($course_id, '_bhc_landing_video', true);
        $poster = has_post_thumbnail($course_id) ? (string) get_the_post_thumbnail_url($course_id, 'full') : '';
        $tagline = (string) get_post_meta($course_id, '_bhc_landing_tagline', true);
        $out = '<header class="bhc-landing-hero' . ($video || $poster ? ' bhc-landing-hero-media' : '') . '">';
        if ($video) {
            // WHY: muted + playsinline are what let browsers autoplay; courses.js pauses it for reduced motion.
            $out .= '<video class="bhc-landing-hero-video" data-bhc-landing-video autoplay muted loop playsinline preload="metadata" aria-hidden="true"'
                . ($poster ? ' poster="' . esc_url($poster) . '"' : '') . '><source src="' . esc_url($video) . '"></video>';
        } elseif ($poster) {
            $out .= '<img class="bhc-landing-hero-video" src="' . esc_url($poster) . '" alt="">';
        }
        $out .= '<div class="bhc-landing-hero-inner">';
        $out .= '<h1>' . esc_html(get_the_title($course_id)) . '</h1>';
        if ($tagline !== '') $out .= '<p class="bhc-landing-tagline">' . esc_html($tagline) . '</p>';
        $count = BHC_PostTypes::lesson_count($course_id);
        $duration = BHC_PostTypes::duration_note($course_id);
        $out .= '<p class="bhc-landing-meta">' . (int) $count . ' lesson' . ($count === 1 ? '' : 's') . ($duration ? ' &middot; ' . esc_html($duration) : '') . '</p>';
        $out .= self::cta($course_id, $uid);
        $out .= '</div></header>';
        return $out;
    }

    /** Start/Continue for students, Buy for everyone else, log-in for returning buyers. */
    private static function cta(int $course_id, int $uid): string {
        $has_access = $uid && BHC_Gate::user_can_access_course($uid, $course_id);
        if ($has_access) {
            $cta = BHC_Render_Course::render_continue_cta($uid, $course_id, BHC_Progress::course_percent($uid, $course_id));
            return '<div class="bhc-landing-cta">' . ($cta ?: '<a class="bhc-btn" href="' . esc_url((string) get_permalink($course_id)) . '">Go to the course &rarr;</a>') . '</div>';
        }
        $out = '<div class="bhc-landing-cta">';
        $product_id = (int) get_post_meta($course_id, '_bhm_purchase_wc_product_id', true);
        if (BHC_Gate::has_purchase_option($course_id) && $product_id && function_exists('wc_get_cart_url')) {
            $price = function_exists('wc_price') ? wc_price(BHC_Gate::purchase_price_cents($course_id) / 100) : '';
            $out .= '<a class="bhc-btn bhc-btn-buy-once" href="' . esc_url(add_query_arg('add-to-cart', $product_id, wc_get_cart_url())) . '">Get the course' . ($price ? ' &mdash; ' . $price : '') . '</a>';
        } else {
            $out .= '<a class="bhc-btn" href="' . esc_url((string) get_permalink($course_id)) . '">View the course &rarr;</a>';
        }
        if (!$uid) {
            $out .= '<p class="bhc-landing-login">Already bought it? <a href="' . esc_url(wp_login_url((string) get_permalink($course_id))) . '">Log in</a> to pick up where you left off.</p>';
        }
        return $out . '</div>';
    }

    private static function contents(int $course_id): string {
        $groups = BHC_PostTypes::grouped_lesson_order($course_id);
        $items = '';
        foreach ($groups as $group) {
            $lessons = '';
            foreach ($group['lesson_ids'] as $lesson_id) {
                if (get_post_status($lesson_id) !== 'publish') continue;
                $lessons .= '<li><strong>' . esc_html(get_the_title($lesson_id)) . '</strong>'
                    . (has_excerpt($lesson_id) ? '<p>' . esc_html(get_the_excerpt($lesson_id)) . '</p>' : '') . '</li>';
            }
            if ($lessons === '') continue;
            $items .= $group['title'] !== ''
                ? '<li class="bhc-landing-module"><h3>' . esc_html($group['title']) . '</h3><ol>' . $lessons . '</ol></li>'
                : $lessons;
        }
        return $items === '' ? '' : '<section class="bhc-landing-section bhc-landing-contents"><h2>What&rsquo;s inside</h2><ol class="bhc-landing-lessons">' . $items . '</ol></section>';
    }

    /** @return array<int, string[]> blank-line separated blocks, each as trimmed lines */
    private static function blocks(string $text): array {
        $blocks = preg_split('/\R\s*\R/', trim($text)) ?: [];
        $out = [];
        foreach ($blocks as $block) {
            $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $block) ?: []), 'strlen'));
            if ($lines) $out[] = $lines;
        }
        return $out;
    }

    private static function testimonials(string $text): string {
        $items = '';
        foreach (self::blocks($text) as $lines) {
            $who = '';
            if (count($lines) > 1 && preg_match('/^(?:—|–|-{1,2})\s*(.+)$/u', end($lines), $m)) {
                $who = $m[1];
                array_pop($lines);
            }
            $items .= '<figure class="bhc-landing-quote"><blockquote><p>' . implode('<br>', array_map('esc_html', $lines)) . '</p></blockquote>'
                . ($who !== '' ? '<figcaption>' . esc_html($who) . '</figcaption>' : '') . '</figure>';
        }
        return $items === '' ? '' : '<section class="bhc-landing-section bhc-landing-testimonials"><h2>What students say</h2><div class="bhc-landing-quotes">' . $items . '</div></section>';
    }

    private static function faq(string $text): string {
        $items = '';
        foreach (self::blocks($text) as $lines) {
            $question = array_shift($lines);
            $items .= '<details class="bhc-landing-faq-item"><summary>' . esc_html($question) . '</summary>'
                . ($lines ? '<p>' . implode('<br>', array_map('esc_html', $lines)) . '</p>' : '') . '</details>';
        }
        return $items === '' ? '' : '<section class="bhc-landing-section bhc-landing-faq"><h2>Questions</h2>' . $items . '</section>';
    }

    private static function fine_print(): string {
        $links = [];
        $terms_id = (int) get_option('woocommerce_terms_page_id', 0);
        if ($terms_id && get_post_status($terms_id) === 'publish') $links[] = '<a href="' . esc_url((string) get_permalink($terms_id)) . '">Terms of sale</a>';
        $privacy = get_privacy_policy_url();
        if ($privacy) $links[] = '<a href="' . esc_url($privacy) . '">Privacy</a>';
        $support = method_exists('OUS_Pages', 'url') ? OUS_Pages::url('bht_support') : null;
        if ($support) $links[] = '<a href="' . esc_url($support) . '">Questions? Contact us</a>';
        return $links ? '<p class="bhc-landing-fine-print">' . implode(' &middot; ', $links) . '</p>' : '';
    }
}

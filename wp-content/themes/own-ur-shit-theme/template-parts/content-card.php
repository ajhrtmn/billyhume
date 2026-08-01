<?php
if (!defined('ABSPATH')) exit;
/**
 * Post-summary card for archive/search grids — deliberately mirrors
 * bh-courses's .bhc-course-card shape (fixed 16:9 thumb slot with SVG
 * placeholder gradient when there's no featured image, depth+hover lift,
 * clamped excerpt, reserved footer space) so theme-rendered archives read
 * as the same card language as plugin catalog grids, not a second style.
 */
?>
<article <?php post_class('oust-card'); ?>>
    <a class="oust-card-thumb-link" href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true">
        <div class="oust-card-thumb">
            <?php if (has_post_thumbnail()) : ?>
                <?php the_post_thumbnail('medium_large'); ?>
            <?php else : ?>
                <div class="oust-card-thumb-placeholder" aria-hidden="true"></div>
            <?php endif; ?>
        </div>
    </a>
    <div class="oust-card-body">
        <?php if (get_the_category()) : ?>
            <span class="oust-card-kicker"><?php echo esc_html(get_the_category()[0]->name ?? ''); ?></span>
        <?php endif; ?>
        <h2 class="oust-card-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
        <div class="oust-card-excerpt"><?php echo wp_kses_post(get_the_excerpt()); ?></div>
        <div class="oust-card-footer">
            <span class="oust-card-date"><?php echo esc_html(get_the_date()); ?></span>
            <a class="oust-card-readmore" href="<?php the_permalink(); ?>"><?php esc_html_e('Read more', 'own-ur-shit-theme'); ?></a>
        </div>
    </div>
</article>

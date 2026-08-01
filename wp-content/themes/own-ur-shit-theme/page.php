<?php if (!defined('ABSPATH')) exit; get_header(); ?>

<div class="oust-container">
    <?php while (have_posts()) : the_post(); ?>
        <?php get_template_part('template-parts/page-header', null, ['title' => get_the_title()]); ?>

        <article <?php post_class('oust-page-content'); ?> id="post-<?php the_ID(); ?>">
            <?php if (has_post_thumbnail()) : ?>
                <div class="oust-page-featured-image"><?php the_post_thumbnail('large'); ?></div>
            <?php endif; ?>
            <div class="oust-prose">
                <?php the_content(); ?>
            </div>
            <?php
            wp_link_pages([
                'before' => '<nav class="oust-page-links">' . esc_html__('Pages:', 'own-ur-shit-theme'),
                'after' => '</nav>',
            ]);
            ?>
        </article>

        <?php if (comments_open() || get_comments_number()) : comments_template(); endif; ?>
    <?php endwhile; ?>
</div>

<?php get_footer(); ?>

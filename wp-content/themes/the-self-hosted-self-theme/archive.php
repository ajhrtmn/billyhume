<?php if (!defined('ABSPATH')) exit; get_header(); ?>

<div class="oust-container">
    <?php get_template_part('template-parts/page-header', null, ['kicker' => __('Archive', 'the-self-hosted-self-theme'), 'title' => get_the_archive_title(), 'lede' => get_the_archive_description()]); ?>

    <?php if (have_posts()) : ?>
        <div class="oust-card-grid">
            <?php while (have_posts()) : the_post(); ?>
                <?php get_template_part('template-parts/content-card'); ?>
            <?php endwhile; ?>
        </div>

        <div class="oust-pagination">
            <?php
            the_posts_pagination([
                'mid_size' => 2,
                'prev_text' => __('&larr; Previous', 'the-self-hosted-self-theme'),
                'next_text' => __('Next &rarr;', 'the-self-hosted-self-theme'),
            ]);
            ?>
        </div>
    <?php else : ?>
        <?php get_template_part('template-parts/content-none'); ?>
    <?php endif; ?>
</div>

<?php get_footer(); ?>

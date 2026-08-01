<?php if (!defined('ABSPATH')) exit; get_header(); ?>

<div class="oust-container oust-container-narrow">
    <?php while (have_posts()) : the_post(); ?>
        <?php
        $cats = get_the_category();
        get_template_part('template-parts/page-header', null, [
            'kicker' => $cats ? esc_html($cats[0]->name) : __('Post', 'own-ur-shit-theme'),
            'title' => get_the_title(),
            'lede' => get_the_date() . ' &middot; ' . esc_html(get_the_author()),
        ]);
        ?>

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
            <?php $tags = get_the_tags(); ?>
            <?php if ($tags) : ?>
                <div class="oust-post-tags">
                    <?php foreach ($tags as $tag) : ?>
                        <a class="oust-tag" href="<?php echo esc_url(get_tag_link($tag)); ?>"><?php echo esc_html($tag->name); ?></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </article>

        <nav class="oust-post-nav">
            <?php
            $prev = get_previous_post();
            $next = get_next_post();
            ?>
            <div class="oust-post-nav-prev">
                <?php if ($prev) : ?>
                    <a href="<?php echo esc_url(get_permalink($prev)); ?>">
                        <span class="oust-post-nav-label">&larr; <?php esc_html_e('Previous', 'own-ur-shit-theme'); ?></span>
                        <span class="oust-post-nav-title"><?php echo esc_html(get_the_title($prev)); ?></span>
                    </a>
                <?php endif; ?>
            </div>
            <div class="oust-post-nav-next">
                <?php if ($next) : ?>
                    <a href="<?php echo esc_url(get_permalink($next)); ?>">
                        <span class="oust-post-nav-label"><?php esc_html_e('Next', 'own-ur-shit-theme'); ?> &rarr;</span>
                        <span class="oust-post-nav-title"><?php echo esc_html(get_the_title($next)); ?></span>
                    </a>
                <?php endif; ?>
            </div>
        </nav>

        <?php if (comments_open() || get_comments_number()) : comments_template(); endif; ?>
    <?php endwhile; ?>
</div>

<?php get_footer(); ?>

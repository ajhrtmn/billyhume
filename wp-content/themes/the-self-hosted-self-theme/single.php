<?php if (!defined('ABSPATH')) exit; get_header(); ?>

<div class="oust-container oust-container-narrow">
    <?php while (have_posts()) : the_post(); ?>
        <?php
        // Fallback single view for every CPT too: get_the_category() only
        // works for 'post', so use the post type's singular label otherwise.
        // WHY: date/author and date-ordered prev/next are blog furniture; on a course or
        // lesson they would link into unrelated content.
        $is_blog_post = get_post_type() === 'post';
        $cats = get_the_category();
        if ($cats) {
            $kicker = esc_html($cats[0]->name);
        } else {
            $post_type_obj = get_post_type_object(get_post_type());
            $kicker = $post_type_obj ? esc_html($post_type_obj->labels->singular_name) : __('Post', 'the-self-hosted-self-theme');
        }
        if (apply_filters('oust_show_page_header', true, get_post())) get_template_part('template-parts/page-header', null, [
            'kicker' => $kicker,
            'title' => get_the_title(),
            'lede' => $is_blog_post ? get_the_date() . ' &middot; ' . esc_html(get_the_author()) : '',
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
                'before' => '<nav class="oust-page-links">' . esc_html__('Pages:', 'the-self-hosted-self-theme'),
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

        <?php if ($is_blog_post) : ?>
        <nav class="oust-post-nav">
            <?php
            $prev = get_previous_post();
            $next = get_next_post();
            ?>
            <div class="oust-post-nav-prev">
                <?php if ($prev) : ?>
                    <a href="<?php echo esc_url(get_permalink($prev)); ?>">
                        <span class="oust-post-nav-label">&larr; <?php esc_html_e('Previous', 'the-self-hosted-self-theme'); ?></span>
                        <span class="oust-post-nav-title"><?php echo esc_html(get_the_title($prev)); ?></span>
                    </a>
                <?php endif; ?>
            </div>
            <div class="oust-post-nav-next">
                <?php if ($next) : ?>
                    <a href="<?php echo esc_url(get_permalink($next)); ?>">
                        <span class="oust-post-nav-label"><?php esc_html_e('Next', 'the-self-hosted-self-theme'); ?> &rarr;</span>
                        <span class="oust-post-nav-title"><?php echo esc_html(get_the_title($next)); ?></span>
                    </a>
                <?php endif; ?>
            </div>
        </nav>
        <?php endif; ?>

        <?php if (comments_open() || get_comments_number()) : comments_template(); endif; ?>
    <?php endwhile; ?>
</div>

<?php get_footer(); ?>

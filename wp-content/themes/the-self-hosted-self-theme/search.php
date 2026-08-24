<?php if (!defined('ABSPATH')) exit; get_header(); ?>

<div class="oust-container">
    <?php
    $count = $wp_query->found_posts;
    $lede = sprintf(
        /* translators: %d: number of search results */
        _n('%d result found.', '%d results found.', $count, 'the-self-hosted-self-theme'),
        $count
    );
    get_template_part('template-parts/page-header', null, [
        'kicker' => __('Search Results', 'the-self-hosted-self-theme'),
        'title' => sprintf(__('&ldquo;%s&rdquo;', 'the-self-hosted-self-theme'), esc_html(get_search_query())),
        'lede' => $lede,
    ]);
    ?>

    <form role="search" method="get" class="oust-search-form" action="<?php echo esc_url(home_url('/')); ?>">
        <label class="screen-reader-text" for="oust-search-input"><?php esc_html_e('Search for:', 'the-self-hosted-self-theme'); ?></label>
        <input type="search" id="oust-search-input" class="oust-search-input" name="s" value="<?php echo esc_attr(get_search_query()); ?>" placeholder="<?php esc_attr_e('Search this site&hellip;', 'the-self-hosted-self-theme'); ?>">
        <button type="submit" class="oust-btn oust-btn-primary"><?php esc_html_e('Search', 'the-self-hosted-self-theme'); ?></button>
    </form>

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

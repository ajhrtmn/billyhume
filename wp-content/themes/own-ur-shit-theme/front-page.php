<?php if (!defined('ABSPATH')) exit; get_header(); ?>

<?php
$front_id = get_option('page_on_front');
$has_static_content = $front_id && get_post_field('post_content', $front_id) !== '';
?>

<?php if ($has_static_content) : ?>
    <div class="oust-container">
        <?php while (have_posts()) : the_post(); ?>
            <article <?php post_class('oust-page-content'); ?>>
                <?php if (has_post_thumbnail()) : ?>
                    <div class="oust-page-featured-image"><?php the_post_thumbnail('full'); ?></div>
                <?php endif; ?>
                <div class="oust-prose"><?php the_content(); ?></div>
            </article>
        <?php endwhile; ?>
    </div>
<?php else : ?>
    <section class="oust-hero">
        <div class="oust-hero-starburst" aria-hidden="true"></div>
        <div class="oust-hero-boomerang" aria-hidden="true"></div>
        <div class="oust-hero-boomerang oust-hero-boomerang-2" aria-hidden="true"></div>
        <div class="oust-hero-inner">
            <h1 class="oust-hero-title"><?php bloginfo('name'); ?></h1>
            <div class="oust-hero-rule"></div>
            <?php $desc = get_bloginfo('description'); ?>
            <?php if ($desc) : ?>
                <p class="oust-hero-lede"><?php echo esc_html($desc); ?></p>
            <?php endif; ?>
        </div>
    </section>

    <?php if (have_posts()) : ?>
        <div class="oust-container">
            <header class="oust-section-header">
                <span class="oust-section-kicker"><?php esc_html_e('Latest', 'own-ur-shit-theme'); ?></span>
                <h2 class="oust-section-title"><?php esc_html_e('From the Blog', 'own-ur-shit-theme'); ?></h2>
            </header>
            <div class="oust-card-grid">
                <?php while (have_posts()) : the_post(); ?>
                    <?php get_template_part('template-parts/content-card'); ?>
                <?php endwhile; ?>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php get_footer(); ?>

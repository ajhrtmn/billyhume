<?php
if (!defined('ABSPATH')) exit;
if (post_password_required()) return;
?>
<div class="oust-comments">
    <?php if (have_comments()) : ?>
        <h2 class="oust-comments-title">
            <?php
            $count = get_comments_number();
            printf(
                /* translators: %d: number of comments */
                esc_html(_n('%d Comment', '%d Comments', $count, 'own-ur-shit-theme')),
                (int) $count
            );
            ?>
        </h2>
        <ol class="oust-comment-list">
            <?php
            wp_list_comments([
                'style' => 'ol',
                'short_ping' => true,
                'avatar_size' => 44,
            ]);
            ?>
        </ol>
        <?php the_comments_pagination(['prev_text' => __('&larr; Older', 'own-ur-shit-theme'), 'next_text' => __('Newer &rarr;', 'own-ur-shit-theme')]); ?>
    <?php endif; ?>

    <?php if (!comments_open() && get_comments_number() !== 0 && post_type_supports(get_post_type(), 'comments')) : ?>
        <p class="oust-comments-closed"><?php esc_html_e('Comments are closed.', 'own-ur-shit-theme'); ?></p>
    <?php endif; ?>

    <?php
    comment_form([
        'class_submit' => 'oust-btn oust-btn-primary',
        'title_reply' => __('Leave a Comment', 'own-ur-shit-theme'),
    ]);
    ?>
</div>

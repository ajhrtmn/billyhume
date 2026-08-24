<?php

/**
 * PHPStan-only stub — merges extra @property docblock tags onto the
 * REAL WP_Post class (php-stubs/wordpress-stubs already does exactly
 * this for other dynamic WP_Post properties, e.g. its own `@property
 * string $page_template` tag on the same class) rather than adding a
 * suppression. Never loaded at runtime; PHPStan's `stubFiles` merges
 * this class's docblock onto the real WP_Post it already knows about.
 *
 * title/url/etc. are genuine, core-added properties — WP_Post is
 * declared `#[AllowDynamicProperties]` specifically because
 * wp_setup_nav_menu_item() (wp-includes/nav-menu.php) bolts these onto
 * every WP_Post object returned by wp_get_nav_menu_items() and every
 * item passed through the `wp_nav_menu_objects` filter. Only the
 * properties OUS_MenuSync::localize_account_link() actually reads or
 * writes are declared here — not a full re-documentation of every
 * field wp_setup_nav_menu_item() sets, which would just be duplicating
 * WordPress core's own source rather than typing this codebase's real
 * usage.
 *
 * @property string        $title   Nav menu item link text.
 * @property string        $url     Nav menu item link URL.
 * @property array<string> $classes CSS classes for the item's <li>. Core
 *           sets this in wp_setup_nav_menu_item() and reads it back when
 *           building the markup, which is what makes appending to it the
 *           supported way to add a class to one menu item.
 */
final class WP_Post
{
}

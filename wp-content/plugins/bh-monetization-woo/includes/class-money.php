<?php
if (!defined('ABSPATH')) exit;

/**
 * Cents formatting/parsing. Use these instead of number_format().
 * WHY: display() keeps the thousands separator; price() must not, or WooCommerce misparses it.
 */
class BHM_Money {
    /**
     * For on-screen "$12.34" display — keeps the thousands separator.
     * @param mixed $cents
     */
    public static function display($cents): string {
        return number_format(((int) $cents) / 100, 2);
    }

    /**
     * For WooCommerce's set_price()/set_regular_price() — plain decimal string, no thousands separator.
     * @param mixed $cents
     */
    public static function price($cents): string {
        return number_format(((int) $cents) / 100, 2, '.', '');
    }

    /**
     * Reverse: a raw dollars input (a $_POST/$_GET string, or a float) -> integer cents.
     * @param mixed $dollars
     */
    public static function parse($dollars): int {
        return (int) round(((float) $dollars) * 100);
    }
}

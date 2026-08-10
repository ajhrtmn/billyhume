<?php
if (!defined('ABSPATH')) exit;

/**
 * Audit fix (2026-07-25) — this plugin had ~34 hand-copied
 * number_format($x/100, 2)-style cents-formatting call sites and ~8
 * reverse-parse (dollars-string-to-cents) sites, using two easily
 * confusable idioms interleaved on real money paths:
 *   number_format($cents/100, 2)          — on-screen display, WITH the
 *                                            default thousands separator
 *   number_format($cents/100, 2, '.', '') — fed into WooCommerce's own
 *                                            set_price()/set_regular_price(),
 *                                            which requires a plain decimal
 *                                            string with NO thousands
 *                                            separator (a comma there
 *                                            silently breaks WC's own
 *                                            price parsing)
 * One helper, one place these two rules live — every cents-in-cents-out
 * call site should go through here instead of hand-rolling number_format()
 * again, so a future site can't accidentally use the wrong variant.
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

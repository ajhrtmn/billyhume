<?php
if (!defined('ABSPATH')) exit;

/**
 * Forwards WooCommerce's own log entries into OUS_DebugLog's Console &
 * Logs viewer, so a real WC-side failure (e.g. the download-directory
 * exception this ecosystem hit and fixed on 2026-07-26 — see
 * bh-monetization-woo/includes/class-downloads.php) shows up in the
 * SAME place every other plugin's own errors already do, instead of a
 * separate wp-content/uploads/wc-logs/*.log file nobody thinks to check
 * unless they already suspect WooCommerce specifically.
 *
 * ADDITIVE, not a replacement: registered via the real, current
 * 'woocommerce_register_log_handlers' filter (the deprecated
 * 'woocommerce_log_add' action — no longer fired since WC 3.0, per its
 * own wc_do_deprecated_action() call — was the wrong extension point
 * and would never have fired at all). WC's own file-based logging
 * keeps working completely unchanged; this is a second handler
 * alongside it, not instead of it.
 */
class OUS_WCLogBridge implements WC_Log_Handler_Interface {
    // PSR-3's 8 levels collapsed to this ecosystem's own 3-tier scheme
    // (see class-debug-log.php's own docblock: "kept to three, not a
    // full PSR-3 ladder, deliberately"), same mapping direction as
    // OUS_DebugLog's own handle_shutdown()/handle_error() already use.
    const LEVEL_MAP = [
        'emergency' => 'error', 'alert' => 'error', 'critical' => 'error', 'error' => 'error',
        'warning' => 'warning', 'notice' => 'warning',
        'info' => 'info', 'debug' => 'info',
    ];

    public static function init() {
        if (!class_exists('OUS_DebugLog')) return;
        add_filter('woocommerce_register_log_handlers', [self::class, 'register_handler']);
    }

    public static function register_handler($handlers) {
        $handlers[] = new self();
        return $handlers;
    }

    // WC_Log_Handler_Interface's one required method.
    public function handle($timestamp, $level, $message, $context) {
        OUS_DebugLog::log(
            self::LEVEL_MAP[$level] ?? 'info',
            (string) $message,
            is_array($context) ? $context : [],
            'WooCommerce'
        );
        return true; // "handled" — doesn't stop WC's own file handler from also running (each registered handler runs independently, see WC_Logger::log()'s own foreach).
    }
}

<?php
if (!defined('ABSPATH')) exit;

/**
 * Forwards WooCommerce log entries into OUS_DebugLog's Console & Logs.
 * Additive: registered via 'woocommerce_register_log_handlers' (the old
 * 'woocommerce_log_add' action no longer fires); WC's file logging is unchanged.
 */
class OUS_WCLogBridge implements WC_Log_Handler_Interface {
    // PSR-3's 8 levels collapsed to OUS_DebugLog's three.
    const LEVEL_MAP = [
        'emergency' => 'error', 'alert' => 'error', 'critical' => 'error', 'error' => 'error',
        'warning' => 'warning', 'notice' => 'warning',
        'info' => 'info', 'debug' => 'info',
    ];

    public static function init(): void {
        if (!class_exists('OUS_DebugLog')) return;
        add_filter('woocommerce_register_log_handlers', [self::class, 'register_handler']);
    }

    /**
     * @param array<int, mixed> $handlers
     * @return array<int, mixed>
     */
    public static function register_handler($handlers): array {
        $handlers[] = new self();
        return $handlers;
    }

    // WC_Log_Handler_Interface's one required method.
    /** @param array<string, mixed> $context */
    public function handle($timestamp, $level, $message, $context): bool {
        OUS_DebugLog::log(
            self::LEVEL_MAP[$level] ?? 'info',
            (string) $message,
            $context,
            'WooCommerce'
        );
        return true; // "handled" — doesn't stop WC's own file handler from also running (each registered handler runs independently, see WC_Logger::log()'s own foreach).
    }
}

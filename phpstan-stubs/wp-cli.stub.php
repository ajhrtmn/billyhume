<?php
// Minimal WP-CLI declarations for static analysis; WP-CLI itself is only loaded on the command line.

class WP_CLI {
    /** @param callable|class-string|object $callable @param array<string, mixed> $args */
    public static function add_command(string $name, $callable, array $args = []): bool { return true; }
    public static function success(string $message): void {}
    public static function warning(string $message): void {}
    public static function line(string $message = ''): void {}
    /** @param string|\WP_Error $message @return never */
    public static function error($message, bool $exit = true): void { exit(1); }
}

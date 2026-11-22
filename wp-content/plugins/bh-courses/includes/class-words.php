<?php
if (!defined('ABSPATH')) exit;

/**
 * Reads words.php, the one file holding every button label and message students see.
 * Text may contain {placeholders}; get() fills them in. A missing key shows the key
 * itself, so a typo is visible on the page (and the test suite checks every key).
 */
class BHC_Words {
    /** @var array<string, string>|null */
    private static ?array $words = null;

    /** @return array<string, string> */
    public static function all(): array {
        if (self::$words === null) {
            $words = include BHC_PATH . 'words.php';
            // Filter so a site can override wording without editing the file.
            self::$words = (array) apply_filters('bhc_words', is_array($words) ? $words : []);
        }
        return self::$words;
    }

    /** Plain text with {placeholders} filled in. Not escaped: use e() or html() to print. */
    /** @param array<string, string|int> $vars */
    public static function get(string $key, array $vars = []): string {
        $text = self::all()[$key] ?? $key;
        foreach ($vars as $name => $value) $text = str_replace('{' . $name . '}', (string) $value, $text);
        return $text;
    }

    /** Escaped text, ready to print. */
    /** @param array<string, string|int> $vars */
    public static function e(string $key, array $vars = []): string {
        return esc_html(self::get($key, $vars));
    }

    /** Escaped text with HTML pieces (links, spans) dropped into its {placeholders}. $html values must already be safe. */
    /** @param array<string, string> $html */
    public static function html(string $key, array $html): string {
        $text = esc_html(self::all()[$key] ?? $key);
        foreach ($html as $name => $value) $text = str_replace('{' . $name . '}', $value, $text);
        return $text;
    }
}

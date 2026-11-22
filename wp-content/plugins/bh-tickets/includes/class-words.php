<?php
if (!defined('ABSPATH')) exit;

/**
 * Reads words.php, the one file holding every label and message people see on the Support form and Support tab.
 * Text may contain {placeholders}; get() fills them in. A missing key shows the key
 * itself, so a typo is visible on the page (and the test suite checks every key).
 */
class BHT_Words {
    const PROPS_PROVIDER = 'words.support';

    /** @var array<string, string>|null */
    private static ?array $words = null;
    /** @var array<string, string>|null */
    private static ?array $defaults = null;

    /** @return array<string, string> words.php as written, before any saved changes */
    public static function defaults(): array {
        if (self::$defaults === null) {
            $words = include BHT_PATH . 'words.php';
            self::$defaults = is_array($words) ? $words : [];
        }
        return self::$defaults;
    }

    public static function register_props(): void {
        BH_Props::register_provider(self::PROPS_PROVIDER, ['label' => 'Support wording', 'defaults' => [self::class, 'defaults'], 'file' => BHT_PATH . 'words.php']);
    }

    public static function flush(): void {
        self::$words = null;
    }

    /** @return array<string, string> */
    public static function all(): array {
        if (self::$words === null) {
            $words = self::defaults();
            // Changes saved through BH_Props (wp bh set …) sit on top of the file.
            if (class_exists('BH_Props')) $words = BH_Props::apply(self::PROPS_PROVIDER, $words);
            self::$words = (array) apply_filters('bht_words', is_array($words) ? $words : []);
        }
        return self::$words;
    }

    /**
     * Everything except the admin-screen wording, for pages that pass the words to JavaScript.
     * @return array<string, string>
     */
    public static function for_browser(): array {
        return array_filter(self::all(), fn($key) => strpos($key, 'admin.') !== 0, ARRAY_FILTER_USE_KEY);
    }

    /** Plain text with {placeholders} filled in. Not escaped: use e() or html() to print. */
    /** @param array<string, string|int> $vars */
    public static function get(string $key, array $vars = []): string {
        $text = self::all()[$key] ?? $key;
        foreach ($vars as $name => $value) $text = str_replace('{' . $name . '}', (string) $value, $text);
        return $text;
    }

    /** A ticket status ("open") as the person sees it ("Open"). */
    public static function status(string $code): string {
        return self::all()['status.' . $code] ?? ucfirst($code);
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

add_action('bh_props_register', [BHT_Words::class, 'register_props']);
add_action('bh_props_changed', [BHT_Words::class, 'flush']);

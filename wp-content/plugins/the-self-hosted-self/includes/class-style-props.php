<?php
if (!defined('ABSPATH')) exit;

/**
 * The Design Suite as a BH_Props provider ("style"), so `wp bh`, REST and the Debug Tools
 * inspector change colors, sizes and fonts the same way they change wording. Values stay in
 * the bhy_style_settings option that the Design Suite page and the Customizer also write.
 *
 * Ids: style:color_accent, style:radius, style:components.<group>.<token>,
 * style:custom.<key>, style:custom_fonts.<key>.
 */
class BHY_Style_Props {
    const PROVIDER = 'style';

    /** Global numeric settings: [min, max, step, unit]. Same limits as BHY_Style::save_from_input(). */
    const NUMBERS = [
        'font_scale'  => [0.75, 1.6, 0.05, '×'],
        'space_scale' => [0.6, 1.8, 0.05, '×'],
        'radius'      => [0, 32, 1, 'px'],
        'radius_sm'   => [0, 24, 1, 'px'],
        'bar_height'  => [56, 140, 1, 'px'],
    ];

    public static function register(): void {
        BH_Props::register_provider(self::PROVIDER, [
            'label' => 'Design Suite styles',
            'defaults' => [self::class, 'defaults'],
            'current' => [self::class, 'current'],
            'write' => [self::class, 'write'],
            'validate' => [self::class, 'validate'],
            'meta' => [self::class, 'meta'],
        ]);
    }

    /** @return array<string, array<string, mixed>> every key with how to edit it (type, label, limits, default) */
    public static function definitions(): array {
        $defs = [];
        foreach (BHY_Style::DEFAULTS as $key => $default) {
            if ($key === 'brand_logo_id') continue; // a media-library id, set on the Design Suite page
            $d = ['type' => 'text', 'label' => ucfirst(str_replace('_', ' ', $key)), 'default' => (string) $default];
            if (strpos($key, 'color_') === 0 || strpos($key, 'cat_color_') === 0) {
                $d['type'] = 'color';
            } elseif ($key === 'font_display' || $key === 'font_body') {
                $d['type'] = 'select';
                $d['options'] = array_merge([BHY_Style::INHERIT_FONT], array_keys(BHY_Style::FONT_OPTIONS), ['Custom']);
            } elseif (isset(self::NUMBERS[$key])) {
                [$min, $max, $step, $unit] = self::NUMBERS[$key];
                $d = array_merge($d, ['type' => 'number', 'min' => $min, 'max' => $max, 'step' => $step, 'unit' => $unit]);
            }
            $defs[$key] = $d;
        }
        foreach (BHY_Style::custom_sliders() as $key => $def) {
            $defs['custom.' . $key] = self::token_def(['type' => 'size'] + (array) $def);
        }
        foreach (BHY_Style::custom_fonts() as $key => $def) {
            $defs['custom_fonts.' . $key] = self::token_def(['type' => 'font'] + (array) $def);
        }
        foreach (BHY_Style::component_tokens() as $group => $gdef) {
            foreach ((array) ($gdef['tokens'] ?? []) as $key => $tdef) {
                $d = self::token_def((array) $tdef);
                $d['label'] = ($gdef['label'] ?? $group) . ': ' . $d['label'];
                $defs['components.' . $group . '.' . $key] = $d;
            }
        }
        return $defs;
    }

    /**
     * @param array<string, mixed> $t a token definition from a bhy_style_* filter
     * @return array<string, mixed>
     */
    private static function token_def(array $t): array {
        $type = (string) ($t['type'] ?? 'size');
        $d = ['label' => (string) ($t['label'] ?? ''), 'default' => ''];
        if ($type === 'color') return array_merge($d, ['type' => 'color', 'default' => (string) ($t['default'] ?? '#000000')]);
        if ($type === 'font') return array_merge($d, ['type' => 'text', 'default' => (string) ($t['default'] ?? '')]);
        if ($type === 'toggle') return array_merge($d, ['type' => 'toggle', 'default' => empty($t['default']) ? '0' : '1']);
        return array_merge($d, [
            'type' => 'number', 'default' => (string) ($t['default'] ?? 0),
            'min' => $t['min'] ?? 0, 'max' => $t['max'] ?? 999999, 'step' => $t['step'] ?? 1, 'unit' => (string) ($t['unit'] ?? 'px'),
        ]);
    }

    /** @return array<string, string> */
    public static function defaults(): array {
        return array_map(fn($d) => (string) $d['default'], self::definitions());
    }

    /** @return array<string, string> the values in effect now (saved, or the default) */
    public static function current(): array {
        $saved = get_option(BHY_Style::OPTION, []);
        $saved = is_array($saved) ? $saved : [];
        $out = [];
        foreach (self::definitions() as $key => $d) {
            $v = self::dig($saved, $key);
            if ($v === null || ($d['type'] === 'text' && $v === '' && strpos($key, '.') !== false)) {
                $out[$key] = (string) $d['default'];
            } elseif ($d['type'] === 'toggle') {
                $out[$key] = empty($v) ? '0' : '1';
            } else {
                $out[$key] = is_float($v) || is_int($v) ? self::num((float) $v) : (string) $v;
            }
        }
        return $out;
    }

    /** @return array<string, mixed> */
    public static function meta(string $key): array {
        return self::definitions()[$key] ?? [];
    }

    /** @return string|\WP_Error the value as it will be stored */
    public static function validate(string $key, string $value) {
        $d = self::definitions()[$key] ?? null;
        if (!$d) return new WP_Error('bh_props_key', 'No style property "' . $key . '".');
        $value = trim($value);
        switch ($d['type']) {
            case 'color':
                $clean = BHY_Style::safe_color($value);
                if ($clean === '#000000' && !in_array(strtolower($value), ['#000', '#000000', '#000000ff'], true)) {
                    return new WP_Error('bh_props_color', '"' . $value . '" isn\'t a color. Use #hex, rgb(), rgba() or transparent.');
                }
                return $clean;
            case 'number':
                $num = preg_replace('/\s*(px|em|rem|%|×|x)$/i', '', $value);
                if (!is_numeric($num)) return new WP_Error('bh_props_number', '"' . $value . '" isn\'t a number.');
                if ((float) $num < (float) $d['min'] || (float) $num > (float) $d['max']) {
                    return new WP_Error('bh_props_range', 'Must be between ' . $d['min'] . ' and ' . $d['max'] . '.');
                }
                return self::num((float) $num);
            case 'toggle':
                $v = strtolower($value);
                if (in_array($v, ['1', 'on', 'yes', 'true'], true)) return '1';
                if (in_array($v, ['0', 'off', 'no', 'false', ''], true)) return '0';
                return new WP_Error('bh_props_toggle', 'Use on or off.');
            case 'select':
                if (!in_array($value, (array) $d['options'], true)) return new WP_Error('bh_props_option', 'Choose one of: ' . implode(', ', (array) $d['options']) . '.');
                return $value;
            default:
                return sanitize_text_field($value);
        }
    }

    /** Stores one value (null = back to the default) in bhy_style_settings. */
    public static function write(string $key, ?string $value): void {
        $saved = get_option(BHY_Style::OPTION, []);
        $saved = is_array($saved) ? $saved : [];
        $path = explode('.', $key);
        $last = (string) array_pop($path);
        $node = &$saved;
        foreach ($path as $part) {
            if (!isset($node[$part]) || !is_array($node[$part])) $node[$part] = [];
            $node = &$node[$part];
        }
        if ($value === null) {
            unset($node[$last]);
        } else {
            $type = self::definitions()[$key]['type'] ?? 'text';
            $node[$last] = $type === 'number' ? (float) $value : ($type === 'toggle' ? $value === '1' : $value);
        }
        unset($node);
        update_option(BHY_Style::OPTION, self::prune($saved));
    }

    /**
     * Drops groups left empty by a reset, so the option holds only what was changed.
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    private static function prune(array $a): array {
        foreach ($a as $k => $v) {
            if (is_array($v) && $k !== 'custom_css_rules') {
                $a[$k] = self::prune($v);
                if (!$a[$k]) unset($a[$k]);
            }
        }
        return $a;
    }

    /**
     * @param array<string, mixed> $data
     * @return mixed
     */
    private static function dig(array $data, string $key) {
        foreach (explode('.', $key) as $part) {
            if (!is_array($data) || !array_key_exists($part, $data)) return null;
            $data = $data[$part];
        }
        return $data;
    }

    /** 12.0 → "12", 0.85 → "0.85" */
    private static function num(float $n): string {
        return rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.');
    }
}

add_action('bh_props_register', [BHY_Style_Props::class, 'register']);

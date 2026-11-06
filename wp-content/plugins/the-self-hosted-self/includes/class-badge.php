<?php
if (!defined('ABSPATH')) exit;

/**
 * Maturity badge (alpha/beta/experimental) on the shared `.ous-badge` base.
 * A render helper only: call it next to the specific unverified feature.
 */
class OUS_Badge {
    const LEVELS = [
        'alpha'        => ['label' => 'Alpha', 'class' => 'ous-badge-alpha'],
        'beta'         => ['label' => 'Beta', 'class' => 'ous-badge-beta'],
        'experimental' => ['label' => 'Experimental', 'class' => 'ous-badge-experimental'],
    ];

    /**
     * @param string $level 'alpha'|'beta'|'experimental'
     * @param string $label optional override text (default: the level's own label)
     * @param string $title optional tooltip explaining why; worth always passing
     */
    public static function render(string $level, ?string $label = null, string $title = ''): string {
        if (!isset(self::LEVELS[$level])) return '';
        $meta = self::LEVELS[$level];
        $text = $label !== null ? $label : $meta['label'];
        $title_attr = $title !== '' ? ' title="' . esc_attr($title) . '"' : '';
        return '<span class="ous-badge ' . esc_attr($meta['class']) . '"' . $title_attr . '>' . esc_html($text) . '</span>';
    }
}

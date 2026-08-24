<?php
if (!defined('ABSPATH')) exit;

/**
 * A small, reusable status-badge renderer — added when bh-social needed
 * a way to visibly flag its untested-against-a-live-account platform
 * integrations as alpha/experimental, and a codebase-wide search
 * turned up no existing convention for this (only the ad-hoc
 * ous-badge-active/inactive/missing pair in class-dashboard.php, which
 * is install-status, not feature-maturity). Reuses that same
 * `.ous-badge` base class/shape rather than inventing a second visual
 * language, adding maturity-specific modifier classes alongside it.
 *
 * Deliberately just a render helper, not a registry/tracking system —
 * no plugin needs to "register" as alpha anywhere; a plugin's own admin
 * code calls OUS_Badge::render() next to whatever specific feature is
 * actually unverified, which is more honest than one blanket
 * plugin-level flag when only PART of a plugin is experimental (true
 * for bh-social: the OAuth/API code is real, but unverified against a
 * live third-party account).
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
     * @param string $title optional tooltip explaining WHY — always worth passing, since a bare badge with no reason is just an unexplained warning icon.
     */
    public static function render(string $level, ?string $label = null, string $title = ''): string {
        if (!isset(self::LEVELS[$level])) return '';
        $meta = self::LEVELS[$level];
        $text = $label !== null ? $label : $meta['label'];
        $title_attr = $title !== '' ? ' title="' . esc_attr($title) . '"' : '';
        return '<span class="ous-badge ' . esc_attr($meta['class']) . '"' . $title_attr . '>' . esc_html($text) . '</span>';
    }
}

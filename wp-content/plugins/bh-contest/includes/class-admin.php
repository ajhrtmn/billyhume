<?php
if (!defined('ABSPATH')) exit;

/**
 * DRY/SOLID audit Phase 3b: this file used to be a single 1,863-line
 * class mixing five distinct responsibilities (menu/navigation
 * plumbing, list-table columns, the live results dashboard + CSV
 * export, submission moderation, and every contest/submission metabox).
 * Split into five focused classes, same playbook as bh-monetization-woo's
 * class-products.php split:
 *   - BH_AdminMenus       (includes/class-admin-menus.php)
 *   - BH_AdminListTables  (includes/class-admin-list-tables.php)
 *   - BH_AdminReports     (includes/class-admin-reports.php)
 *   - BH_AdminModeration  (includes/class-admin-moderation.php)
 *   - BH_AdminMetaboxes   (includes/class-admin-metaboxes.php)
 *
 * BH_Admin itself stays only as a thin facade: its REJECTION_REASONS
 * constant is still the one place BH_AdminModeration/BH_AdminMetaboxes/
 * class-portal-panel.php reference (no reason to duplicate it), and
 * init() just wires each new class's own init().
 */
class BH_Admin {
    // Prefab rejection reasons, AJ's own ask: "some real reasoning
    // behind it" rather than a bare freeform box. 'other' always keeps
    // the freeform note meaningful even when nothing here fits.
    const REJECTION_REASONS = [
        'wrong_file'   => 'Wrong file attached (not the intended track)',
        'poor_quality' => 'Audio quality issue (clipping, low bitrate, corrupt file)',
        'ineligible'   => 'Doesn\'t meet contest eligibility rules',
        'duplicate'    => 'Duplicate of an existing submission',
        'copyright'    => 'Copyright/rights concern',
        'other'        => 'Other (see note)',
    ];

    public static function init() {
        BH_AdminMenus::init();
        BH_AdminListTables::init();
        BH_AdminReports::init();
        BH_AdminModeration::init();
        BH_AdminMetaboxes::init();
    }
}

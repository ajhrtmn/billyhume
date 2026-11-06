<?php
if (!defined('ABSPATH')) exit;

/**
 * Admin facade: owns REJECTION_REASONS and wires the BH_Admin* classes' init().
 */
class BH_Admin {
    // Prefab rejection reasons; 'other' relies on the freeform note.
    const REJECTION_REASONS = [
        'wrong_file'   => 'Wrong file attached (not the intended track)',
        'poor_quality' => 'Audio quality issue (clipping, low bitrate, corrupt file)',
        'ineligible'   => 'Doesn\'t meet contest eligibility rules',
        'duplicate'    => 'Duplicate of an existing submission',
        'copyright'    => 'Copyright/rights concern',
        'other'        => 'Other (see note)',
    ];

    public static function init(): void {
        BH_AdminMenus::init();
        BH_AdminListTables::init();
        BH_AdminReports::init();
        BH_AdminModeration::init();
        BH_AdminMetaboxes::init();
    }
}

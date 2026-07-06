<?php
if (!defined('ABSPATH')) exit;

class BHCRM_Export {
    public static function handle() {
        if (!current_user_can('manage_options') || !check_admin_referer('bhcrm_export')) wp_die('Not allowed.');

        $tag_filter = sanitize_text_field($_GET['tag'] ?? '');

        global $wpdb;
        $with_profile = $wpdb->get_col("SELECT user_id FROM {$wpdb->prefix}bhi_profiles WHERE real_name != '' OR discord_name != '' OR twitch_name != '' OR youtube_name != ''");
        $ids = array_unique(array_map('intval', array_merge($with_profile, apply_filters('bh_crm_active_user_ids', []))));
        if ($tag_filter) $ids = array_filter($ids, fn($id) => in_array($tag_filter, BHCRM_Tags::get($id), true));

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="people-' . gmdate('Y-m-d') . '.csv"');

        $out = fopen('php://output', 'w');
        fputcsv($out, ['Name', 'Email', 'Real name', 'Discord', 'Twitch', 'YouTube', 'Tags', 'Notes', 'Registered']);
        foreach ($ids as $uid) {
            $user = get_userdata($uid);
            if (!$user) continue;
            $p = BHI_Profiles::get($uid);
            fputcsv($out, array_map('BHCRM_Export::csv_safe', [
                $user->display_name, $user->user_email,
                $p['real_name'], $p['discord_name'], $p['twitch_name'], $p['youtube_name'],
                implode(', ', BHCRM_Tags::get($uid)), BHCRM_Notes::get($uid),
                mysql2date('Y-m-d', $user->user_registered),
            ]));
        }
        fclose($out);
        exit;
    }

    // Every one of these fields ultimately traces back to something a
    // registering/submitting user typed themselves (real name, handles,
    // tags, notes) — sanitize_text_field() never strips a leading
    // =/+/-/@, which Excel/Google Sheets/LibreOffice interpret as the
    // start of a formula when the cell is opened (the standard
    // "CSV injection" pattern: a real_name of something like
    // =HYPERLINK("http://evil","click") executes on open). The export
    // action itself is properly capability+nonce gated; this closes the
    // separate gap of the DATA inside it not being safe for a
    // spreadsheet to open. A leading apostrophe is the standard fix —
    // every major spreadsheet app treats it as "force this cell to be
    // text," and it's invisible in the rendered cell.
    public static function csv_safe($value) {
        $value = (string) $value;
        if ($value !== '' && strpbrk($value[0], "=+-@") !== false) {
            return "'" . $value;
        }
        return $value;
    }
}

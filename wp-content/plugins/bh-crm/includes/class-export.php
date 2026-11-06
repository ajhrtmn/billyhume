<?php
if (!defined('ABSPATH')) exit;

class BHCRM_Export {
    public static function handle(): void {
        if (!current_user_can('bhcore_manage_crm') || !check_admin_referer('bhcrm_export')) wp_die('Not allowed.');

        $tag_filter = sanitize_text_field($_GET['tag'] ?? '');

        $with_profile = class_exists('BHI_Profiles') ? BHI_Profiles::user_ids_with_profile_data() : [];
        $ids = array_unique(array_map('intval', array_merge($with_profile, apply_filters('bh_crm_active_user_ids', []))));
        if ($tag_filter) $ids = array_filter($ids, fn($id) => in_array($tag_filter, BHCRM_Tags::get($id), true));

        // WHY: posted bulk_ids are intersected with the allowed $ids, so a crafted value can't export non-CRM users.
        if (isset($_POST['bulk_ids'])) {
            $selected = array_map('intval', (array) $_POST['bulk_ids']);
            $ids = array_intersect($ids, $selected);
        }

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
                implode(', ', BHCRM_Tags::get($uid)), self::notes_summary($uid),
                mysql2date('Y-m-d', $user->user_registered),
            ]));
        }
        fclose($out);
        exit;
    }

    // All notes flattened into one cell, newest first, each stamped with author and date.
    private static function notes_summary(int $uid): string {
        $notes = BHCRM_Notes::list_for_person($uid);
        if (!$notes) return '';
        $lines = array_map(function ($n) {
            $author = $n['author_id'] ? get_userdata($n['author_id']) : null;
            $who = $author ? ($author->display_name ?: $author->user_login) : 'Unknown';
            $when = mysql2date('Y-m-d', $n['created_at']);
            return "[$when $who] " . str_replace(["\r", "\n"], ' ', $n['note']);
        }, $notes);
        return implode(' | ', $lines);
    }

    // WHY: CSV-injection guard; a leading =, +, - or @ runs as a formula in spreadsheets, so prefix an apostrophe.
    /** @param mixed $value */
    public static function csv_safe($value): string {
        $value = (string) $value;
        if ($value !== '' && strpbrk($value[0], "=+-@") !== false) {
            return "'" . $value;
        }
        return $value;
    }
}

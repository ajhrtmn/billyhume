<?php
if (!defined('ABSPATH')) exit;

class BHCRM_Export {
    public static function handle() {
        if (!current_user_can('bhcore_manage_crm') || !check_admin_referer('bhcrm_export')) wp_die('Not allowed.'); // QA fix: matches the CRM menu's own bhcore_manage_crm gate

        $tag_filter = sanitize_text_field($_GET['tag'] ?? '');

        // Per QA-REPORT-code-quality.md's cross-plugin finding #2 — same
        // fix as BHCRM_People::active_user_ids(): goes through
        // BHI_Profiles (the class that actually owns this table) instead
        // of running raw SQL against it directly.
        $with_profile = class_exists('BHI_Profiles') ? BHI_Profiles::user_ids_with_profile_data() : [];
        $ids = array_unique(array_map('intval', array_merge($with_profile, apply_filters('bh_crm_active_user_ids', []))));
        if ($tag_filter) $ids = array_filter($ids, fn($id) => in_array($tag_filter, BHCRM_Tags::get($id), true));

        // ROADMAP-ux-polish-and-feature-parity-2026-07.md Section 3:
        // "bulk export-selected." A real POSTed bulk_ids[] (the person
        // list's new checkbox selection, class-people.php) narrows the
        // export to exactly those people — intersected against, not
        // simply replacing, the existing active/tag-filtered $ids, so a
        // stale/crafted bulk_ids value can never export someone who
        // isn't a legitimate CRM entry in the first place.
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

    // QA fix: BHCRM_Notes::get() no longer exists — bh-crm 1.4.0
    // rewrote notes from a single freeform field into real timestamped
    // history (list_for_person()), and this call site was missed during
    // that rewrite, which would have fatal-errored the very next real
    // CSV export. Caught by a follow-up pass building bulk actions on
    // this same list, not by the original rewrite's own verification —
    // a reminder that "list the call sites" matters as much as
    // verifying the method itself. One CSV cell can't hold a real
    // table, so every note is flattened into one cell, newest first,
    // each stamped with its own author + date.
    private static function notes_summary($uid) {
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

<?php
if (!defined('ABSPATH')) exit;

/**
 * Certificate of completion — ROADMAP-ux-polish-and-feature-parity-
 * 2026-07.md 4a. The hook this listens for (`bhc_course_completed`,
 * class-progress.php) already fires exactly once per user/course; this
 * is simply the first real consumer of it. Studied LifterLMS's own
 * Achievements/Engagements module first, per that doc's own note
 * ("worth building only if... designed as a small extensible engine,
 * not a certificate-only hardcode") — LifterLMS's real shape is a
 * trigger→handler dispatch table (`lifterlms_engagement_award_
 * certificate` => `handle_certificate`, etc.). The honest conclusion
 * after that study: WordPress's own `do_action('bhc_course_completed',
 * ...)` already IS that same extension point — any future achievement
 * type (a badge, an email, a Discord-role grant) can add its OWN
 * independent listener to that same hook without this class needing to
 * grow into a registry/dispatcher of its own. No bespoke "engine"
 * class here — that would have been the abstraction-for-its-own-sake
 * this codebase's own conventions warn against, not genuine
 * extensibility.
 *
 * Off by default, per course — same explicit-opt-in posture as Lesson
 * Q&A (class-comments.php), not a blanket switch.
 *
 * Generated ON DEMAND (a "Download certificate" link once completed),
 * not pre-generated/stored at completion time — avoids regeneration
 * complexity (a course title edited after a student already has a
 * stale stored PDF, a storage-cleanup question that doesn't exist if
 * nothing is ever stored) at the cost of a few hundred ms of PDF
 * generation on click, a fine trade for something downloaded rarely.
 *
 * Uses FPDF (own-ur-shit/vendor/fpdf/fpdf.php — vendored, not
 * Composer, this ecosystem's own no-build-step convention applied to
 * PHP the same way SortableJS was vendored for JS this same session) —
 * pure PHP, zero dependencies, the right-sized tool for "draw a plain
 * one-page landscape document," not a full HTML-to-PDF renderer this
 * never needed.
 */
class BHC_Certificates {
    public static function init() {
        add_action('template_redirect', [self::class, 'maybe_serve_download']);
    }

    public static function course_offers_certificate($course_id) {
        return (bool) get_post_meta($course_id, '_bhc_certificate_enabled', true);
    }

    /** The actual download URL — a plain query arg on the course's own permalink, same "no rewrite rule needed" pattern this ecosystem's WooCommerce-facing links already use (wc_get_cart_url() . '?add-to-cart=X'). */
    public static function download_url($course_id) {
        return add_query_arg('bhc_certificate', (int) $course_id, get_permalink($course_id));
    }

    public static function maybe_serve_download() {
        if (!isset($_GET['bhc_certificate'])) return;
        $course_id = (int) $_GET['bhc_certificate'];
        $user_id = get_current_user_id();

        // Redirects to login with this exact URL as the return
        // destination, rather than dead-ending — a logged-out student
        // who followed a certificate link (e.g. from an old email, or a
        // bookmarked link) previously got a bare "log in" message with
        // no actual way to do so short of hunting down the login page
        // themselves and re-finding this URL from scratch.
        if (!$user_id) {
            wp_safe_redirect(wp_login_url(esc_url_raw($_SERVER['REQUEST_URI'] ?? home_url())));
            exit;
        }
        if (!self::course_offers_certificate($course_id)) wp_die('This course doesn\'t offer a certificate.', '', ['response' => 404, 'back_link' => true]);
        if (!class_exists('BHC_Progress') || !BHC_Progress::is_course_completed($user_id, $course_id)) {
            wp_die('Complete this course to unlock its certificate.', '', ['response' => 403, 'back_link' => true]);
        }

        self::stream_pdf($user_id, $course_id);
        exit;
    }

    private static function stream_pdf($user_id, $course_id) {
        // A raw require_once fatal (a white-screen PHP error, the least
        // graceful outcome possible for "student earned a certificate,
        // clicks download") if the vendored FPDF file is ever missing —
        // a partial deploy, an over-aggressive .gitignore, OUS_PATH
        // resolving wrong in some environment. A real 500 with a real
        // message costs one extra file_exists() check.
        if (!class_exists('FPDF')) {
            $fpdf_path = OUS_PATH . 'vendor/fpdf/fpdf.php';
            if (!file_exists($fpdf_path)) {
                wp_die('Certificate generation is temporarily unavailable — please contact support.', '', ['response' => 500, 'back_link' => true]);
            }
            require_once $fpdf_path;
        }

        $course_title = get_the_title($course_id);
        $user = get_userdata($user_id);
        $student_name = $user ? ($user->display_name ?: $user->user_login) : 'Student';
        $signature = (string) get_post_meta($course_id, '_bhc_certificate_signature', true);
        $site_name = get_bloginfo('name');
        $date = date_i18n(get_option('date_format'));

        // Distinction tier: a student who cleared a real quiz-mastery
        // bar (not just "finished," which every certificate already
        // proves) gets the same certificate with an added "WITH
        // DISTINCTION" line. course_quiz_average() returns null (not 0)
        // when no quiz was ever attempted in this course, which
        // correctly never qualifies — distinction is earned, not a
        // default. Filterable so a site can tune the bar without a
        // code change.
        $quiz_average = class_exists('BHC_Progress') ? BHC_Progress::course_quiz_average($user_id, $course_id) : null;
        $distinction_threshold = (int) apply_filters('bhc_certificate_distinction_threshold', 90);
        $with_distinction = $quiz_average !== null && $quiz_average >= $distinction_threshold;

        $pdf = new FPDF('L', 'mm', 'A4'); // landscape — the standard shape for this document type, not a preference
        $pdf->SetMargins(20, 20, 20);
        $pdf->AddPage();

        $pdf->SetDrawColor(180, 160, 120);
        $pdf->SetLineWidth(1.2);
        $pdf->Rect(10, 10, 277, 190);

        $pdf->SetY(45);
        $pdf->SetFont('Helvetica', '', 14);
        $pdf->Cell(0, 10, self::pdf_safe($site_name), 0, 1, 'C');

        $pdf->SetY(65);
        $pdf->SetFont('Helvetica', 'B', 28);
        $pdf->Cell(0, 16, 'Certificate of Completion', 0, 1, 'C');

        if ($with_distinction) {
            $pdf->SetY(83);
            $pdf->SetFont('Helvetica', 'BI', 12);
            $pdf->SetTextColor(180, 130, 30);
            $pdf->Cell(0, 8, 'WITH DISTINCTION', 0, 1, 'C');
            $pdf->SetTextColor(0, 0, 0);
        }

        $pdf->SetY(95);
        $pdf->SetFont('Helvetica', '', 13);
        $pdf->Cell(0, 8, 'This certifies that', 0, 1, 'C');

        $pdf->SetY(107);
        $pdf->SetFont('Helvetica', 'B', 22);
        $pdf->Cell(0, 12, self::pdf_safe($student_name), 0, 1, 'C');

        $pdf->SetY(125);
        $pdf->SetFont('Helvetica', '', 13);
        $pdf->Cell(0, 8, 'has successfully completed the course', 0, 1, 'C');

        $pdf->SetY(135);
        $pdf->SetFont('Helvetica', 'B', 18);
        $pdf->Cell(0, 10, self::pdf_safe($course_title), 0, 1, 'C');

        $pdf->SetY(155);
        $pdf->SetFont('Helvetica', '', 11);
        $pdf->Cell(0, 8, self::pdf_safe($date), 0, 1, 'C');

        if ($signature !== '') {
            $pdf->SetY(178);
            $pdf->SetFont('Helvetica', 'I', 12);
            $pdf->Cell(0, 8, self::pdf_safe($signature), 0, 1, 'C');
        }

        while (ob_get_level()) ob_end_clean(); // FPDF's own Output() writes raw bytes straight to the response — any buffered HTML/notices ahead of it would corrupt the file
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($course_title . ' - Certificate') . '.pdf"');
        $pdf->Output('D', sanitize_file_name($course_title) . '-certificate.pdf');
    }

    /** FPDF's default core fonts (Helvetica etc.) only support Windows-1252, not UTF-8 — course titles/names routinely aren't. Deliberately simple (strip anything outside Latin-1 rather than a full transliteration table) since this only affects a handful of characters in what's normally a short title/name string. */
    private static function pdf_safe($text) {
        $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', (string) $text);
        return $converted !== false ? $converted : preg_replace('/[^\x20-\x7E]/', '', (string) $text);
    }
}

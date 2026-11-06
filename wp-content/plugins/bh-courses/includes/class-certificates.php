<?php
if (!defined('ABSPATH')) exit;

/**
 * Certificate of completion, off by default per course. Generated on demand (never
 * stored) with the vendored FPDF, so later title edits never leave a stale PDF.
 * Other achievement types should add their own `bhc_course_completed` listener.
 */
class BHC_Certificates {
    public static function init(): void {
        add_action('template_redirect', [self::class, 'maybe_serve_download']);
    }

    public static function course_offers_certificate(int $course_id): bool {
        return (bool) get_post_meta($course_id, '_bhc_certificate_enabled', true);
    }

    /** Download URL: a plain query arg on the course permalink (no rewrite rule). */
    public static function download_url(int $course_id): string {
        return add_query_arg('bhc_certificate', (int) $course_id, get_permalink($course_id));
    }

    public static function maybe_serve_download(): void {
        if (!isset($_GET['bhc_certificate'])) return;
        $course_id = (int) $_GET['bhc_certificate'];
        $user_id = get_current_user_id();

        // Logged-out visitors go to login and return to this exact URL.
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

    private static function stream_pdf(int $user_id, int $course_id): void {
        // Fail with a real 500 message if the vendored FPDF file is missing, not a fatal.
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

        // "With distinction" when the quiz average clears the filterable bar; null (no quizzes)
        // never qualifies.
        $quiz_average = class_exists('BHC_Progress') ? BHC_Progress::course_quiz_average($user_id, $course_id) : null;
        $distinction_threshold = (int) apply_filters('bhc_certificate_distinction_threshold', 90);
        $with_distinction = $quiz_average !== null && $quiz_average >= $distinction_threshold;

        $pdf = new FPDF('L', 'mm', 'A4'); // landscape
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

        while (ob_get_level()) ob_end_clean(); // buffered output ahead of Output() would corrupt the PDF
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($course_title . ' - Certificate') . '.pdf"');
        $pdf->Output('D', sanitize_file_name($course_title) . '-certificate.pdf');
    }

    /** FPDF core fonts are Windows-1252 only, so strip characters outside Latin-1. */
    /** @param mixed $text */
    private static function pdf_safe($text): string {
        $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', (string) $text);
        return $converted !== false ? $converted : preg_replace('/[^\x20-\x7E]/', '', (string) $text);
    }
}

<?php
if (!defined('ABSPATH')) exit;

/**
 * "CD jacket" bonus content (liner notes, lyrics, artwork, credits) for a
 * track or release, rendered as a PDF via core's vendored FPDF. Cached as a
 * WP attachment (BHM_Downloads needs a stable file URL) and regenerated only
 * when the stored content hash changes.
 */
class BHS_Booklet {
    public static function init(): void {
        add_action('add_meta_boxes', [self::class, 'add_meta_box']);
        add_action('save_post_bhs_track', [self::class, 'save']);
        add_action('save_post_bhs_release', [self::class, 'save']);
    }

    public static function add_meta_box(): void {
        add_meta_box('bhs_bonus_content', 'Bonus Content (digital booklet)', [self::class, 'render_metabox'], 'bhs_track', 'normal', 'default');
        add_meta_box('bhs_bonus_content', 'Bonus Content (digital booklet)', [self::class, 'render_metabox'], 'bhs_release', 'normal', 'default');
    }

    public static function render_metabox(\WP_Post $post): void {
        wp_nonce_field('bhs_save_booklet', 'bhs_booklet_nonce');
        $is_track = $post->post_type === 'bhs_track';

        $notes = (string) get_post_meta($post->ID, '_bhs_liner_notes', true);
        $credits = (string) get_post_meta($post->ID, '_bhs_credits', true);
        $front_id = (int) get_post_meta($post->ID, '_bhs_artwork_front_id', true);
        $back_id = (int) get_post_meta($post->ID, '_bhs_artwork_back_id', true);
        $insert_id = (int) get_post_meta($post->ID, '_bhs_artwork_insert_id', true);
        $include_lyrics = (bool) get_post_meta($post->ID, '_bhs_include_lyrics_sheet', true);
        $has_lyrics = $is_track && (string) get_post_meta($post->ID, '_bhs_lyrics_text', true) !== '';

        echo '<p class="description">Each piece below is independently optional — only what you fill in gets included in the digital booklet a purchaser downloads alongside the audio. Nothing here is shown publicly; it\'s bundled with the purchase only.</p>';

        if ($is_track) {
            echo '<p><label><input type="checkbox" name="bhs_include_lyrics_sheet" value="1" ' . checked($include_lyrics, true, false) . ' ' . disabled($has_lyrics, false, false) . '> Include a printable lyrics sheet' . (!$has_lyrics ? ' <span class="description">(add lyrics in the Lyrics box below first)</span>' : '') . '</label></p>';
        }

        echo '<p><strong>Liner notes</strong> <span class="description">— artist commentary, the story behind ' . ($is_track ? 'the track' : 'the album') . '</span></p>';
        echo '<textarea name="bhs_liner_notes" rows="5" style="width:100%;">' . esc_textarea($notes) . '</textarea>';

        echo '<p style="margin-top:14px;"><strong>Credits</strong> <span class="description">— one per line, e.g. "Produced by Jane Doe"</span></p>';
        echo '<textarea name="bhs_credits" rows="4" style="width:100%;">' . esc_textarea($credits) . '</textarea>';

        echo '<p style="margin-top:14px;"><strong>Artwork package</strong></p>';
        echo '<div style="display:flex;gap:16px;flex-wrap:wrap;">';
        foreach (['front' => ['Front cover', $front_id], 'back' => ['Back cover', $back_id], 'insert' => ['Insert', $insert_id]] as $key => [$label, $aid]) {
            $url = $aid ? wp_get_attachment_image_url($aid, 'thumbnail') : '';
            echo '<div>';
            echo '<p style="margin-bottom:4px;">' . esc_html($label) . '</p>';
            echo '<input type="hidden" id="bhs_artwork_' . esc_attr($key) . '_id" name="bhs_artwork_' . esc_attr($key) . '_id" value="' . esc_attr((string) $aid) . '">';
            echo '<div id="bhs_artwork_' . esc_attr($key) . '_preview" style="width:100px;height:100px;background:#f0f0f0;border-radius:6px;overflow:hidden;">' . ($url ? '<img src="' . esc_url($url) . '" style="width:100%;height:100%;object-fit:cover;">' : '') . '</div>';
            echo '<button type="button" class="button" id="bhs_artwork_' . esc_attr($key) . '_upload" style="margin-top:6px;">Choose…</button>';
            echo '</div>';
        }
        echo '</div>';
        ?>
        <script>
        (function () {
            function pick(buttonId, hiddenId, previewId) {
                var btn = document.getElementById(buttonId);
                if (!btn || btn.dataset.bhsBound || !window.wp || !window.wp.media) return;
                btn.dataset.bhsBound = '1';
                var frame = null;
                btn.addEventListener('click', function () {
                    if (frame) { frame.open(); return; }
                    frame = wp.media({ title: 'Choose artwork', button: { text: 'Use this' }, multiple: false, library: { type: 'image' } });
                    frame.on('select', function () {
                        var att = frame.state().get('selection').first().toJSON();
                        document.getElementById(hiddenId).value = att.id;
                        document.getElementById(previewId).innerHTML = '<img src="' + (att.sizes && att.sizes.thumbnail ? att.sizes.thumbnail.url : att.url) + '" style="width:100%;height:100%;object-fit:cover;">';
                    });
                    frame.open();
                });
            }
            pick('bhs_artwork_front_upload', 'bhs_artwork_front_id', 'bhs_artwork_front_preview');
            pick('bhs_artwork_back_upload', 'bhs_artwork_back_id', 'bhs_artwork_back_preview');
            pick('bhs_artwork_insert_upload', 'bhs_artwork_insert_id', 'bhs_artwork_insert_preview');
        })();
        </script>
        <?php
    }

    public static function save(int $post_id): void {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!isset($_POST['bhs_booklet_nonce']) || !wp_verify_nonce($_POST['bhs_booklet_nonce'], 'bhs_save_booklet')) return;
        if (!current_user_can('edit_post', $post_id)) return;

        if (isset($_POST['bhs_include_lyrics_sheet'])) update_post_meta($post_id, '_bhs_include_lyrics_sheet', 1);
        else delete_post_meta($post_id, '_bhs_include_lyrics_sheet');

        if (isset($_POST['bhs_liner_notes'])) update_post_meta($post_id, '_bhs_liner_notes', sanitize_textarea_field($_POST['bhs_liner_notes']));
        if (isset($_POST['bhs_credits'])) update_post_meta($post_id, '_bhs_credits', sanitize_textarea_field($_POST['bhs_credits']));
        foreach (['front', 'back', 'insert'] as $key) {
            if (isset($_POST["bhs_artwork_{$key}_id"])) update_post_meta($post_id, "_bhs_artwork_{$key}_id", (int) $_POST["bhs_artwork_{$key}_id"]);
        }

        // Invalidate the cached PDF on any content change so a stale file is never served.
        delete_post_meta($post_id, '_bhs_booklet_content_hash');
    }

    public static function has_any_content(int $id): bool {
        if ((bool) get_post_meta($id, '_bhs_include_lyrics_sheet', true) && (string) get_post_meta($id, '_bhs_lyrics_text', true) !== '') return true;
        if ((string) get_post_meta($id, '_bhs_liner_notes', true) !== '') return true;
        if ((string) get_post_meta($id, '_bhs_credits', true) !== '') return true;
        foreach (['front', 'back', 'insert'] as $key) {
            if ((int) get_post_meta($id, "_bhs_artwork_{$key}_id", true)) return true;
        }
        return false;
    }

    // Hash of every field that ends up in the PDF.
    private static function content_hash(int $id): string {
        $parts = [
            get_post_meta($id, '_bhs_include_lyrics_sheet', true) ? get_post_meta($id, '_bhs_lyrics_text', true) : '',
            get_post_meta($id, '_bhs_liner_notes', true),
            get_post_meta($id, '_bhs_credits', true),
            get_post_meta($id, '_bhs_artwork_front_id', true),
            get_post_meta($id, '_bhs_artwork_back_id', true),
            get_post_meta($id, '_bhs_artwork_insert_id', true),
            get_the_title($id),
        ];
        return md5(implode('|', $parts));
    }

    /**
     * Returns the booklet's file URL, (re)generating it if needed; '' means
     * there is nothing to include, not an error.
     */
    public static function ensure_url(int $id): string {
        if (!self::has_any_content($id)) return '';

        $hash = self::content_hash($id);
        $cached_hash = get_post_meta($id, '_bhs_booklet_content_hash', true);
        $cached_attachment_id = (int) get_post_meta($id, '_bhs_booklet_attachment_id', true);

        if ($cached_attachment_id && $cached_hash === $hash) {
            $url = wp_get_attachment_url($cached_attachment_id);
            if ($url) return $url;
            // Cached attachment was deleted from Media; regenerate.
        }

        $pdf_bytes = self::generate_pdf_bytes($id);
        if ($pdf_bytes === '') return '';

        $filename = sanitize_file_name(get_the_title($id) . ' - Booklet.pdf');
        $upload = wp_upload_bits($filename, null, $pdf_bytes);
        if (!empty($upload['error'])) {
            if (class_exists('OUS_DebugLog')) OUS_DebugLog::log('error', 'Booklet upload failed: ' . $upload['error'], ['object_id' => $id], 'BH Streaming');
            return '';
        }

        $attachment_id = wp_insert_attachment([
            'post_mime_type' => 'application/pdf', 'post_title' => $filename, 'post_status' => 'inherit',
        ], $upload['file'], 0, true);
        if (is_wp_error($attachment_id)) return '';

        // WHY: delete the old attachment only after the new one exists, so an
        // in-progress download never 404s.
        if ($cached_attachment_id && $cached_attachment_id !== $attachment_id) {
            wp_delete_attachment($cached_attachment_id, true);
        }

        update_post_meta($id, '_bhs_booklet_attachment_id', $attachment_id);
        update_post_meta($id, '_bhs_booklet_content_hash', $hash);

        return (string) wp_get_attachment_url($attachment_id);
    }

    private static function generate_pdf_bytes(int $id): string {
        if (!class_exists('FPDF')) {
            $fpdf_path = OUS_PATH . 'vendor/fpdf/fpdf.php';
            if (!file_exists($fpdf_path)) return '';
            require_once $fpdf_path;
        }

        $title = get_the_title($id);
        $notes = (string) get_post_meta($id, '_bhs_liner_notes', true);
        $credits = (string) get_post_meta($id, '_bhs_credits', true);
        $include_lyrics = (bool) get_post_meta($id, '_bhs_include_lyrics_sheet', true);
        $lyrics = $include_lyrics ? (string) get_post_meta($id, '_bhs_lyrics_text', true) : '';

        $pdf = new FPDF('P', 'mm', 'A4');
        $pdf->SetMargins(20, 20, 20);
        $pdf->SetAutoPageBreak(true, 20);

        // Title page.
        $pdf->AddPage();
        $pdf->SetY(100);
        $pdf->SetFont('Helvetica', 'B', 24);
        $pdf->MultiCell(0, 12, self::pdf_safe($title), 0, 'C');
        $pdf->SetFont('Helvetica', '', 12);
        $pdf->Cell(0, 10, self::pdf_safe(get_bloginfo('name')), 0, 1, 'C');

        if ($notes !== '') self::add_text_section($pdf, 'Liner Notes', $notes);
        if ($lyrics !== '') self::add_text_section($pdf, 'Lyrics', $lyrics);
        if ($credits !== '') self::add_text_section($pdf, 'Credits', $credits);

        foreach (['front' => 'Front Cover', 'back' => 'Back Cover', 'insert' => 'Insert'] as $key => $label) {
            $aid = (int) get_post_meta($id, "_bhs_artwork_{$key}_id", true);
            if (!$aid) continue;
            $path = get_attached_file($aid);
            if (!$path || !file_exists($path)) continue;
            $type = wp_check_filetype($path)['type'] ?: '';
            // FPDF only decodes JPEG/PNG/GIF; other formats are skipped, not fatal.
            if (!in_array($type, ['image/jpeg', 'image/png', 'image/gif'], true)) continue;

            $pdf->AddPage();
            $pdf->SetFont('Helvetica', 'B', 14);
            $pdf->Cell(0, 10, self::pdf_safe($label), 0, 1, 'C');
            $size = @getimagesize($path);
            $page_width = 170; // A4 width (210mm) minus 20mm margins each side
            if ($size) {
                $ratio = $size[1] / $size[0];
                $pdf->Image($path, 20, 35, $page_width, $page_width * $ratio);
            }
        }

        return $pdf->Output('S'); // 'S' returns a string; never writes to the response
    }

    private static function add_text_section(\FPDF $pdf, string $heading, string $body): void {
        $pdf->AddPage();
        $pdf->SetFont('Helvetica', 'B', 16);
        $pdf->Cell(0, 12, self::pdf_safe($heading), 0, 1, 'L');
        $pdf->SetFont('Helvetica', '', 11);
        $pdf->MultiCell(0, 6, self::pdf_safe($body));
    }

    /** Same Latin-1 constraint as bh-courses' own class-certificates.php — FPDF's core fonts don't support UTF-8. */
    /** @param mixed $text */
    private static function pdf_safe($text): string {
        $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', (string) $text);
        return $converted !== false ? $converted : preg_replace('/[^\x20-\x7E\n]/', '', (string) $text);
    }
}

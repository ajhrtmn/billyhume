<?php
if (!defined('ABSPATH')) exit;

/**
 * Attaches a purchase's files (BHS_API::qualities_for() encodes, booklets, course videos) as
 * WooCommerce downloadable files, so delivery uses WooCommerce's own secure download URLs.
 */
class BHM_Downloads {
    public static function init(): void {
        if (!BH_Commerce::available()) return;
        // Files are read live at order time, not cached on the product.
        add_action('woocommerce_order_status_completed', [self::class, 'attach_download_files'], 5); // priority 5: before entitlements are granted, so files exist when WooCommerce emails the links
    }

    // WHY: WooCommerce wraps all callbacks of this hook in ONE try/catch, so an exception here
    // (e.g. save() rejecting an unapproved download directory) would abort entitlement, ledger
    // and referral callbacks too. Hence the approved-directory self-heal and per-item catch.
    public static function attach_download_files(int $order_id): void {
        self::maybe_register_approved_directory();

        $order = wc_get_order($order_id);
        if (!$order) return;

        // Base WC_Order_Item has no get_product_id(), hence the instanceof guard.
        foreach ($order->get_items() as $item) {
            if (!$item instanceof WC_Order_Item_Product) continue;
            try {
                self::attach_for_item($item);
            } catch (\Throwable $e) {
                if (class_exists('OUS_DebugLog')) {
                    OUS_DebugLog::log('error', 'attach_download_files() failed for order #' . $order_id . ': ' . $e->getMessage(), ['order_id' => $order_id, 'product_id' => $item->get_product_id()], 'BH Monetization');
                }
                // Swallowed on purpose (see above).
            }
        }
    }

    private static function attach_for_item(\WC_Order_Item_Product $item): void {
        $product_id = $item->get_product_id();
        $object_id = (int) get_post_meta($product_id, '_bhm_purchase_object_id', true);
        $object_type = get_post_meta($product_id, '_bhm_purchase_object_type', true);
        if (!$object_id) return;

        $product = wc_get_product($product_id);
        if (!$product) return;

        $files = self::gather_files($object_id, $object_type);
        if (!$files) return;

        $downloads = [];
        foreach ($files as $label => $url) {
            $download = new WC_Product_Download();
            $download->set_id(md5($url));
            $download->set_name($label);
            $download->set_file($url);
            $downloads[] = $download;
        }
        $product->set_downloads($downloads);

        // Course videos are large, so bound redistribution with WooCommerce's own limit/expiry
        // (filterable). Track/release downloads stay at WooCommerce's unlimited default.
        if ($object_type === 'bh_course') {
            $product->set_download_limit((int) apply_filters('bhm_course_download_limit', 8, $object_id));
            $product->set_download_expiry((int) apply_filters('bhm_course_download_expiry_days', 180, $object_id));
        }

        $product->save();
    }

    // WooCommerce only auto-approves woocommerce_uploads; purchasable files live in the general
    // uploads dir. Checked once per request, not just at activation.
    private static bool $checked_this_request = false;
    private static function maybe_register_approved_directory(): void {
        if (self::$checked_this_request) return;
        self::$checked_this_request = true;

        if (!class_exists('\Automattic\WooCommerce\Internal\ProductDownloads\ApprovedDirectories\Register')) return;
        try {
            $register = wc_get_container()->get(\Automattic\WooCommerce\Internal\ProductDownloads\ApprovedDirectories\Register::class);
            $url = wp_get_upload_dir()['baseurl'] . '/';
            if (!$register->approved_directory_exists($url)) {
                $register->add_approved_directory($url, true);
                if (class_exists('OUS_DebugLog')) {
                    OUS_DebugLog::log('info', 'Registered the site uploads directory as an approved WooCommerce download directory (was missing — every download would otherwise fail).', ['url' => $url], 'BH Monetization');
                }
            }
        } catch (\Throwable $e) {
            if (class_exists('OUS_DebugLog')) {
                OUS_DebugLog::log('warning', 'Could not verify/register the approved download directory: ' . $e->getMessage(), [], 'BH Monetization');
            }
        }
    }

    // label => url: a track's encodes, a release's tracks (title-prefixed), or a course's lesson videos.
    /** @return array<string, string> */
    private static function gather_files(int $object_id, string $object_type): array {
        if ($object_type === 'bh_course') {
            return self::course_lesson_video_files($object_id);
        }

        if (!class_exists('BHS_API')) return [];

        if ($object_type === 'bhs_release') {
            $track_ids = get_posts([
                'post_type' => 'bhs_track', 'post_status' => 'publish', 'posts_per_page' => -1,
                'meta_key' => '_bhs_release_id', 'meta_value' => (string) $object_id, 'fields' => 'ids',
            ]);
            $out = [];
            foreach ($track_ids as $tid) {
                $title = get_the_title($tid);
                foreach (self::track_files($tid) as $label => $url) {
                    $out[$title . ' — ' . $label] = $url;
                }
            }

            // The album's own booklet, in addition to per-track ones.
            if (class_exists('BHS_Booklet')) {
                $release_booklet_url = BHS_Booklet::ensure_url($object_id);
                if ($release_booklet_url) $out['Digital Booklet (Album)'] = $release_booklet_url;
            }

            return $out;
        }

        return self::track_files($object_id);
    }

    // Uploaded lesson videos only; embeds (Stream/YouTube/Vimeo) have no file to download.
    /** @return array<string, string> */
    private static function course_lesson_video_files(int $course_id): array {
        if (!class_exists('BHC_PostTypes') || !class_exists('BHC_Steps')) return [];

        $out = [];
        foreach (BHC_PostTypes::lesson_order($course_id) as $lesson_id) {
            $lesson_title = get_the_title($lesson_id);
            $steps = BHC_Steps::get($lesson_id);
            $upload_video_steps = array_values(array_filter($steps, function ($step) {
                return ($step['type'] ?? '') === 'video' && ($step['source'] ?? '') === 'upload' && !empty($step['attachment_id']);
            }));
            $multiple = count($upload_video_steps) > 1;

            foreach ($upload_video_steps as $i => $step) {
                $url = wp_get_attachment_url((int) $step['attachment_id']);
                if (!$url) continue;
                $label = $lesson_title . ($multiple ? ' — Video ' . ($i + 1) : '');
                $out[$label] = $url;
            }
        }
        return $out;
    }

    /** @return array<string, string> */
    private static function track_files(int $track_id): array {
        $qualities = BHS_API::qualities_for($track_id);
        $out = [];
        foreach ($qualities as $label => $info) {
            $out[$label] = $info['url'];
        }
        // No encodes: fall back to the plain audio file.
        if (!$out) {
            $default_url = BHS_API::audio_url_for($track_id);
            if ($default_url) $out['standard'] = $default_url;
        }

        // Bonus PDF booklet; ensure_url() returns '' when there's nothing to include.
        if (class_exists('BHS_Booklet')) {
            $booklet_url = BHS_Booklet::ensure_url($track_id);
            if ($booklet_url) $out['Digital Booklet'] = $booklet_url;
        }

        return $out;
    }
}

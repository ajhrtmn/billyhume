<?php
if (!defined('ABSPATH')) exit;

/**
 * Delivers whatever quality encodes a purchased track/release actually
 * has attached (see bh-streaming's class-api.php BHS_API::qualities_for()
 * — this plugin reuses that exact data, never its own copy) as real
 * WooCommerce downloadable-product files, so an entitled fan gets
 * WooCommerce's own secure, expiring, download-limited file URLs rather
 * than this plugin inventing its own file-serving mechanism.
 */
class BHM_Downloads {
    public static function init() {
        if (!BH_Commerce::available()) return;
        // Attach the real file list to a purchase product right before
        // checkout completion needs it — lazily, at order-processing
        // time, rather than trying to keep every purchase product's
        // file list eagerly in sync with whatever quality encodes get
        // added/removed on the track after the product was first
        // created. A track's audio rarely changes after publish, but
        // "rarely" isn't "never," so this reads live rather than caching.
        add_action('woocommerce_order_status_completed', [self::class, 'attach_download_files'], 5); // priority 5: runs BEFORE BHM_Products::on_order_completed() grants the entitlement, so the files exist by the time WooCommerce emails the download links
    }

    // Audit fix (2026-07-26): $product->save() below throws a real
    // \Exception when WooCommerce's "Approved Download Directories"
    // feature (enabled by default in modern WC) can't match the file's
    // URL against any registered directory — and this site had ZERO
    // directories registered at all, meaning every single download here
    // failed. That alone would just break downloads, but the actual
    // damage was worse: WC_Order::status_transition() wraps its ENTIRE
    // woocommerce_order_status_completed dispatch in one try/catch, not
    // one per callback — so this uncaught exception silently aborted
    // every callback registered AFTER this one on the same hook,
    // including BHM_Entitlements::on_order_completed() (grants access),
    // BHM_PurchaseLedger::on_order_completed() (the proof-of-purchase
    // ledger), and BHM_Referrals::on_order_completed() (referral
    // credit) — a fan could pay for a track and receive nothing, with
    // no error anywhere but WooCommerce's own separate wc-logs file.
    // Two fixes, both needed: (1) self-heal the missing approved-
    // directory rule below, so real downloads actually work; (2) never
    // again let a failure HERE take down every other callback on this
    // hook, regardless of the cause — catch and log instead.
    public static function attach_download_files($order_id) {
        self::maybe_register_approved_directory();

        $order = wc_get_order($order_id);
        if (!$order) return;

        // get_items() with no type filter returns WC's default
        // 'line_item' type only, which in practice is always
        // WC_Order_Item_Product — but the base WC_Order_Item type it's
        // typed to return has no get_product_id(). Guarding with
        // instanceof makes that guarantee explicit (PHPStan-caught).
        foreach ($order->get_items() as $item) {
            if (!$item instanceof WC_Order_Item_Product) continue;
            try {
                self::attach_for_item($item);
            } catch (\Throwable $e) {
                if (class_exists('OUS_DebugLog')) {
                    OUS_DebugLog::log('error', 'attach_download_files() failed for order #' . $order_id . ': ' . $e->getMessage(), ['order_id' => $order_id, 'product_id' => $item->get_product_id()], 'BH Monetization');
                }
                // Deliberately swallowed, not re-thrown: a download-file
                // problem on ONE line item must never be allowed to take
                // the rest of this hook's callbacks down with it — see
                // this method's own docblock for the exact failure mode
                // this closes.
            }
        }
    }

    private static function attach_for_item($item) {
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
        $product->save();
    }

    // Self-healing, same posture as BHY_RewriteHealer elsewhere in this
    // ecosystem: confirms the site's own uploads directory (where every
    // bh-streaming audio file and quality encode actually lives) is
    // registered as an approved WooCommerce download directory, adding
    // it if missing. WooCommerce's own default only auto-registers its
    // OWN `woocommerce_uploads` subfolder (see WC's Synchronize::
    // add_default_directories()) — nothing seeds the general uploads
    // path this ecosystem actually serves purchasable files from, so a
    // fresh WooCommerce install (or one where this feature got enabled
    // after activation) silently has no matching rule at all. Checked
    // once per request (a cheap in-memory + one DB read cost) rather
    // than only at plugin activation, so this can't be missed on an
    // install where the approved-directories feature was toggled on
    // after this plugin already activated.
    private static $checked_this_request = false;
    private static function maybe_register_approved_directory() {
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

    // Returns label => url for every quality encode a track has, or —
    // for a release — the union of every track's encodes, prefixed with
    // the track title so a multi-track album download list is legible.
    private static function gather_files($object_id, $object_type) {
        if (!class_exists('BHS_API')) return [];

        if ($object_type === 'bhs_release') {
            $track_ids = get_posts([
                'post_type' => 'bhs_track', 'post_status' => 'publish', 'posts_per_page' => -1,
                'meta_key' => '_bhs_release_id', 'meta_value' => $object_id, 'fields' => 'ids',
            ]);
            $out = [];
            foreach ($track_ids as $tid) {
                $title = get_the_title($tid);
                foreach (self::track_files($tid) as $label => $url) {
                    $out[$title . ' — ' . $label] = $url;
                }
            }
            return $out;
        }

        return self::track_files($object_id);
    }

    private static function track_files($track_id) {
        $qualities = BHS_API::qualities_for($track_id);
        $out = [];
        foreach ($qualities as $label => $info) {
            $out[$label] = $info['url'];
        }
        // A track with no quality encodes attached at all still has its
        // one plain _bhs_audio_id file — deliver that as 'standard'
        // rather than shipping a purchase with literally nothing in it.
        if (!$out) {
            $default_url = BHS_API::audio_url_for($track_id);
            if ($default_url) $out['standard'] = $default_url;
        }
        return $out;
    }
}

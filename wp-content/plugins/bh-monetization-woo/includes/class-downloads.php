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
        if (!class_exists('WooCommerce')) return;
        // Attach the real file list to a purchase product right before
        // checkout completion needs it — lazily, at order-processing
        // time, rather than trying to keep every purchase product's
        // file list eagerly in sync with whatever quality encodes get
        // added/removed on the track after the product was first
        // created. A track's audio rarely changes after publish, but
        // "rarely" isn't "never," so this reads live rather than caching.
        add_action('woocommerce_order_status_completed', [self::class, 'attach_download_files'], 5); // priority 5: runs BEFORE BHM_Products::on_order_completed() grants the entitlement, so the files exist by the time WooCommerce emails the download links
    }

    public static function attach_download_files($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;

        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            $object_id = (int) get_post_meta($product_id, '_bhm_purchase_object_id', true);
            $object_type = get_post_meta($product_id, '_bhm_purchase_object_type', true);
            if (!$object_id) continue;

            $product = wc_get_product($product_id);
            if (!$product) continue;

            $files = self::gather_files($object_id, $object_type);
            if (!$files) continue;

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

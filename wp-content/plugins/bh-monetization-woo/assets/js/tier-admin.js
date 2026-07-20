/**
 * Supporter Tiers admin metabox — cover image picker only. Everything
 * else on this screen (price, benefits, benefits list) is plain form
 * fields with no JS needed. Same wp.media() single-image-select shape
 * bh-courses/assets/js/admin.js already uses for step images.
 */
jQuery(function ($) {
    var $preview = $('#bhm-tier-cover-preview');
    var $input = $('#bhm_cover_image_id');
    var $choose = $('#bhm-tier-cover-choose');
    var $remove = $('#bhm-tier-cover-remove');
    var frame;

    $choose.on('click', function (e) {
        e.preventDefault();
        if (frame) { frame.open(); return; }
        frame = wp.media({ title: 'Select a cover image', library: { type: 'image' }, multiple: false });
        frame.on('select', function () {
            var attachment = frame.state().get('selection').first().toJSON();
            var url = (attachment.sizes && attachment.sizes.medium) ? attachment.sizes.medium.url : attachment.url;
            $input.val(attachment.id);
            $preview.attr('src', url).show();
            $remove.show();
            $choose.text('Change image');
        });
        frame.open();
    });

    $remove.on('click', function (e) {
        e.preventDefault();
        $input.val('');
        $preview.hide().attr('src', '');
        $remove.hide();
        $choose.text('Choose image');
    });

    // Live "no benefit checked" warning — class-tiers.php's own
    // render_metabox() already computes this once on page load; this
    // just keeps it in sync as an admin actually checks/unchecks boxes,
    // rather than only being accurate until the first click.
    var $priceInput = $('input[name="bhm_price"]');
    var $warning = $('#bhm-no-benefits-warning');
    function refreshBenefitWarning() {
        if (!$warning.length) return;
        var hasPrice = parseFloat($priceInput.val()) > 0;
        var hasBenefit = $('input[name="bhm_benefit_keys[]"]:checked').length > 0;
        $warning.toggle(hasPrice && !hasBenefit);
    }
    $(document).on('change', 'input[name="bhm_benefit_keys[]"]', refreshBenefitWarning);
    $priceInput.on('input', refreshBenefitWarning);
});

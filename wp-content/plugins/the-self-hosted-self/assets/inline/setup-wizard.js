(function () {
    var uploadBtn = document.getElementById('ous-wizard-logo-upload');
    var hidden = document.getElementById('ous_wizard_brand_logo_id');
    var preview = document.getElementById('ous-wizard-logo-preview');
    if (!uploadBtn) return;
    var frame = null;
    uploadBtn.addEventListener('click', function () {
        if (!window.wp || !window.wp.media) return; // WHY: checked lazily; wp.media loads in the footer, after this runs
        if (frame) { frame.open(); return; }
        frame = wp.media({ title: 'Choose a logo', button: { text: 'Use this' }, multiple: false, library: { type: 'image' } });
        frame.on('select', function () {
            var att = frame.state().get('selection').first().toJSON();
            hidden.value = att.id;
            var thumbUrl = (att.sizes && att.sizes.medium) ? att.sizes.medium.url : att.url;
            preview.innerHTML = '<img src="' + thumbUrl + '" style="width:100%;height:100%;object-fit:contain;">';
            uploadBtn.textContent = 'Change logo';
        });
        frame.open();
    });
})();

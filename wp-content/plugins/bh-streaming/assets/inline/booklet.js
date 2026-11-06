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

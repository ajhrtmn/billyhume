(function () {
    function pick(buttonId, hiddenId, previewId, isImage) {
        var btn = document.getElementById(buttonId);
        if (!btn || btn.dataset.bhsBound || !window.wp || !window.wp.media) return;
        btn.dataset.bhsBound = '1';
        var frame = null;
        btn.addEventListener('click', function () {
            if (frame) { frame.open(); return; }
            frame = wp.media({ title: 'Choose a file', button: { text: 'Use this' }, multiple: false, library: isImage ? { type: 'image' } : { type: 'audio' } });
            frame.on('select', function () {
                var att = frame.state().get('selection').first().toJSON();
                document.getElementById(hiddenId).value = att.id;
                var preview = document.getElementById(previewId);
                if (isImage) {
                    preview.innerHTML = '<img src="' + (att.sizes && att.sizes.medium ? att.sizes.medium.url : att.url) + '" style="width:100%;height:100%;object-fit:cover;">';
                } else {
                    preview.innerHTML = '<audio controls src="' + att.url + '" style="width:100%;"></audio>';
                }
            });
            frame.open();
        });
    }
    pick('bhs_audio_upload', 'bhs_audio_id', 'bhs_audio_preview', false);
    pick('bhs_preview_audio_upload', 'bhs_preview_audio_id', 'bhs_preview_audio_preview', false);
    pick('bhs_artwork_upload', 'bhs_artwork_id', 'bhs_artwork_preview', true);
})();

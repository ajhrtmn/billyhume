(function () {
    var btn = document.getElementById('bhv_video_upload');
    if (!btn || btn.dataset.bhvBound || !window.wp || !window.wp.media) return;
    btn.dataset.bhvBound = '1';
    var frame = null;
    btn.addEventListener('click', function () {
        if (frame) { frame.open(); return; }
        frame = wp.media({ title: 'Choose a video', button: { text: 'Use this video' }, multiple: false, library: { type: 'video' } });
        frame.on('select', function () {
            var att = frame.state().get('selection').first().toJSON();
            document.getElementById('bhv_video_id').value = att.id;
            document.getElementById('bhv_video_preview').innerHTML = '<video controls src="' + att.url + '" style="width:100%;"></video>';
            btn.textContent = 'Replace video';
        });
        frame.open();
    });
})();

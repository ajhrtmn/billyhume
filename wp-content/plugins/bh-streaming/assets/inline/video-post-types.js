(function () {
    // WHY: wp.media loads in a footer script, so wait for DOMContentLoaded before checking it.
    document.addEventListener('DOMContentLoaded', function () {
    if (!window.wp || !window.wp.media) return;
    var frame = null;
    var btn = document.getElementById('bhs-release-video-upload');
    if (btn) btn.addEventListener('click', function () {
        if (frame) { frame.open(); return; }
        frame = wp.media({ title: 'Choose a video', button: { text: 'Use this' }, multiple: false, library: { type: 'video' } });
        frame.on('select', function () {
            var att = frame.state().get('selection').first().toJSON();
            document.getElementById('bhs_release_video_attachment_id').value = att.id;
            document.getElementById('bhs_release_video_preview').innerHTML = '<video controls src="' + att.url + '" style="max-width:100%;"></video>';
            document.getElementById('bhs-release-video-clear').style.display = '';
        });
        frame.open();
    });
    var clearBtn = document.getElementById('bhs-release-video-clear');
    if (clearBtn) clearBtn.addEventListener('click', function (e) {
        e.preventDefault();
        document.getElementById('bhs_release_video_attachment_id').value = '';
        document.getElementById('bhs_release_video_preview').innerHTML = '<p><em>No video attached yet.</em></p>';
        clearBtn.style.display = 'none';
    });
    });
})();

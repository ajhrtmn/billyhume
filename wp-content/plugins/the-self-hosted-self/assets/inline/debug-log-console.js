if (typeof window.bhCopyToClipboard !== 'function') {
    window.bhCopyToClipboard = function (textareaId, btn) {
        var el = document.getElementById(textareaId);
        if (!el) return;
        var text = el.value;
        var done = function (ok) {
            if (!btn) return;
            var original = btn.textContent;
            btn.textContent = ok ? 'Copied!' : 'Copy failed';
            setTimeout(function () { btn.textContent = original; }, 1500);
        };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(function () { done(true); }, function () { done(false); });
        } else {
            el.style.position = 'static';
            el.select();
            var ok = false;
            try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
            el.style.position = 'absolute';
            el.style.left = '-9999px';
            done(ok);
        }
    };
}

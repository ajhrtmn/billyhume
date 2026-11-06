(function () {
    var btn = document.getElementById("bhl_replay_upload");
    if (!btn || btn.dataset.bhlBound || !window.wp || !window.wp.media) return;
    btn.dataset.bhlBound = "1";
    var frame = null;
    btn.addEventListener("click", function () {
        if (frame) { frame.open(); return; }
        frame = wp.media({ title: "Choose the replay video", button: { text: "Use this video" }, multiple: false, library: { type: "video" } });
        frame.on("select", function () {
            var att = frame.state().get("selection").first().toJSON();
            document.getElementById("bhl_replay_id").value = att.id;
            document.getElementById("bhl_replay_preview").innerHTML = "<video controls src=\"" + att.url + "\" style=\"width:100%;\"></video>";
            btn.textContent = "Replace replay";
        });
        frame.open();
    });
})();

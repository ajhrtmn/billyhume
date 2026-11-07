(function () {
    var PREFIX = "ous_debug_section_open_";
    // Separate namespace so a group id can never collide with a section id.
    var GROUP_PREFIX = "ous_debug_group_open_";

    document.querySelectorAll("details.ous-debug-section, #ous-section-reset-all").forEach(function (d) {
        try {
            if (localStorage.getItem(PREFIX + d.id) === "1") d.open = true;
        } catch (e) {}
        d.addEventListener("toggle", function () {
            try { localStorage.setItem(PREFIX + d.id, d.open ? "1" : "0"); } catch (e) {}
        });
    });

    // Groups render open server-side; only a remembered "0"/"1" overrides that.
    document.querySelectorAll("details.ous-debug-group").forEach(function (d) {
        try {
            var remembered = localStorage.getItem(GROUP_PREFIX + d.id);
            if (remembered === "0") d.open = false;
            else if (remembered === "1") d.open = true;
        } catch (e) {}
        d.addEventListener("toggle", function () {
            try { localStorage.setItem(GROUP_PREFIX + d.id, d.open ? "1" : "0"); } catch (e) {}
        });
    });

    if (!window.location.hash) return;
    var target = document.querySelector(window.location.hash);
    if (!target) return;
    if (target.tagName === "DETAILS") {
        target.open = true;
        try {
            var ns = target.classList.contains("ous-debug-group") ? GROUP_PREFIX : PREFIX;
            localStorage.setItem(ns + target.id, "1");
        } catch (e) {}
    }
    // A section stays hidden while any ancestor <details> is closed, so open them all.
    var ancestor = target.parentElement ? target.parentElement.closest("details") : null;
    while (ancestor) {
        ancestor.open = true;
        try {
            var ansNs = ancestor.classList.contains("ous-debug-group") ? GROUP_PREFIX : PREFIX;
            localStorage.setItem(ansNs + ancestor.id, "1");
        } catch (e) {}
        ancestor = ancestor.parentElement ? ancestor.parentElement.closest("details") : null;
    }

    requestAnimationFrame(function () {
        target.scrollIntoView({ block: "start" });
        target.style.transition = "box-shadow 0.3s ease";
        target.style.boxShadow = "0 0 0 3px #2271b1";
        setTimeout(function () { target.style.boxShadow = ""; }, 1800);
    });
})();

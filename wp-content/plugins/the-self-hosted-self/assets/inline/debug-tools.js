(function () {
    var PREFIX = "ous_debug_section_open_";
    // Separate localStorage namespace for GROUP-level state (added
    // in the 3.4.20 pass) so it can never collide with a section
    // id that happens to match a group id — see this file's own
    // 3.4.20 docblock.
    var GROUP_PREFIX = "ous_debug_group_open_";

    document.querySelectorAll("details.ous-debug-section, #ous-section-reset-all").forEach(function (d) {
        try {
            if (localStorage.getItem(PREFIX + d.id) === "1") d.open = true;
        } catch (e) {}
        d.addEventListener("toggle", function () {
            try { localStorage.setItem(PREFIX + d.id, d.open ? "1" : "0"); } catch (e) {}
        });
    });

    // Groups default open (the "open" attribute render() already
    // prints server-side) — this only OVERRIDES that default when
    // localStorage explicitly remembers "0" (closed) from a prior
    // visit; it never forces a group open here (the server-side
    // "open" attribute already covers first-visit), keeping this
    // symmetric with how the per-section loop above only ever
    // adds "open", never removes it.
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
    // Force-open every ancestor <details> too (an ancestor group,
    // or — for future-proofing if a group id itself is ever the
    // hash target — any nested <details> above it) since a
    // section can't visibly reveal itself while its parent group
    // is collapsed. Native <details>/<summary> nesting otherwise
    // leaves a closed ancestor collapsed even if the child's own
    // "open" attribute is set.
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

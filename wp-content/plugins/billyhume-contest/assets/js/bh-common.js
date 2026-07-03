// Tiny shared utility, loaded wherever untrusted text (submission titles,
// category names — both ultimately sourced from public, unauthenticated
// input) needs to go into innerHTML. Was previously copy-pasted
// separately into reveal.js and the admin Reveal Control page; kept here
// once so the two can't quietly drift out of sync with each other.
function bhEsc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
}

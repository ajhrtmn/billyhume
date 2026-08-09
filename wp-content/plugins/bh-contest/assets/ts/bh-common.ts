// Tiny shared utility, loaded wherever untrusted text (submission titles,
// category names — both ultimately sourced from public, unauthenticated
// input) needs to go into innerHTML. Was previously copy-pasted
// separately into reveal.js and the admin Reveal Control page; kept here
// once so the two can't quietly drift out of sync with each other.
//
// TypeScript pilot conversion — same posture as reveal.ts (plain `tsc`,
// no bundler, compiled assets/js/bh-common.js is committed). Declared
// as a plain global function (not exported) since module: "none" and
// every consumer references bhEsc as a bare global, same as before.
function bhEsc(s: unknown): string {
    const escapeMap: Record<string, string> = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
    return String(s == null ? '' : s).replace(/[&<>"']/g, (c) => escapeMap[c] ?? c);
}

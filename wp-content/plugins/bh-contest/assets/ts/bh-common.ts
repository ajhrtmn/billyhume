// HTML-escapes untrusted text (titles, category names) before innerHTML.
// A plain global (module "none"); consumers call bhEsc directly.
function bhEsc(s: unknown): string {
    const escapeMap: Record<string, string> = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
    return String(s == null ? '' : s).replace(/[&<>"']/g, (c) => escapeMap[c] ?? c);
}

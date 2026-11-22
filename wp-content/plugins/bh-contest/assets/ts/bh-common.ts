// Wording from bh-contest/words.php (printed as BHWords), with {placeholders} filled in.
declare const BHWords: Record<string, string> | undefined;
function bhWord(key: string, vars?: Record<string, string | number>): string {
    let text = (typeof BHWords !== 'undefined' && BHWords[key]) || key;
    if (vars) Object.keys(vars).forEach((name) => { text = text.split('{' + name + '}').join(String(vars[name])); });
    return text;
}

// bhWord(), escaped for innerHTML and template HTML.
function bhWordEsc(key: string, vars?: Record<string, string | number>): string {
    return bhEsc(bhWord(key, vars));
}

// HTML-escapes untrusted text (titles, category names) before innerHTML.
// A plain global (module "none"); consumers call bhEsc directly.
function bhEsc(s: unknown): string {
    const escapeMap: Record<string, string> = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
    return String(s == null ? '' : s).replace(/[&<>"']/g, (c) => escapeMap[c] ?? c);
}

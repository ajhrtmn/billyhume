"use strict";
function bhWord(key, vars) {
    let text = (typeof BHWords !== 'undefined' && BHWords[key]) || key;
    if (vars)
        Object.keys(vars).forEach((name) => { text = text.split('{' + name + '}').join(String(vars[name])); });
    return text;
}
// bhWord(), escaped for innerHTML and template HTML.
function bhWordEsc(key, vars) {
    return bhEsc(bhWord(key, vars));
}
// HTML-escapes untrusted text (titles, category names) before innerHTML.
// A plain global (module "none"); consumers call bhEsc directly.
function bhEsc(s) {
    const escapeMap = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
    return String(s == null ? '' : s).replace(/[&<>"']/g, (c) => { var _a; return (_a = escapeMap[c]) !== null && _a !== void 0 ? _a : c; });
}

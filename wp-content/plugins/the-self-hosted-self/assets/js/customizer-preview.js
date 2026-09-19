"use strict";
(function () {
    const wpApi = window.wp;
    if (!wpApi || !wpApi.customize)
        return;
    const schema = window.bhyCustomizerSchema;
    if (!schema)
        return;
    schema.forEach(function (entry) {
        wpApi.customize(entry.id, function (value) {
            value.bind(function (newVal) {
                const root = document.documentElement;
                if (entry.isToggle) {
                    const on = newVal === true || newVal === '1' || newVal === 'true';
                    root.style.setProperty(entry.var, on ? (entry.onVal || 'none') : (entry.offVal || 'flex'));
                }
                else if (entry.isFont) {
                    const name = String(newVal || '').trim();
                    if (!name)
                        return;
                    root.style.setProperty(entry.var, '"' + name.replace(/"/g, '') + '", ' + (entry.fallback || 'sans-serif'));
                }
                else {
                    root.style.setProperty(entry.var, newVal + (entry.unit || ''));
                }
            });
        });
    });
})();

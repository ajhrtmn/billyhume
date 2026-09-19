/**
 * Live-preview side of BHY_Customizer (class-customizer.php): for every
 * component_tokens()/custom_sliders()/custom_fonts() control registered
 * there, binds the matching wp.customize() setting and pushes its value
 * straight onto a CSS custom property on <html> — the same --bh-custom-*
 * / --bh-comp-<group>-<key> variables BHY_Style::inline_css() already
 * emits server-side, so a change here is visually identical to what
 * saving in the Design Suite admin page would produce, just instant and
 * unsaved until the Customizer's own Publish. bhyCustomizerSchema is
 * localized from PHP (BHY_Customizer::enqueue_preview_script()) rather
 * than hardcoded here, so a new token registration needs no JS change.
 * Run `npx tsc` from this plugin's root after editing; the compiled
 * assets/js/customizer-preview.js is what class-customizer.php enqueues.
 */
interface BhyCustomizerSchemaEntry {
    id: string;
    var: string;
    unit?: string;
    isFont?: boolean;
    fallback?: string;
    isToggle?: boolean;
    onVal?: string;
    offVal?: string;
}

(function () {
    const wpApi = window.wp;
    if (!wpApi || !wpApi.customize) return;
    const schema = (window as unknown as { bhyCustomizerSchema?: BhyCustomizerSchemaEntry[] }).bhyCustomizerSchema;
    if (!schema) return;

    schema.forEach(function (entry) {
        wpApi.customize!(entry.id, function (value) {
            value.bind(function (newVal: string | boolean) {
                const root = document.documentElement;
                if (entry.isToggle) {
                    const on = newVal === true || newVal === '1' || newVal === 'true';
                    root.style.setProperty(entry.var, on ? (entry.onVal || 'none') : (entry.offVal || 'flex'));
                } else if (entry.isFont) {
                    const name = String(newVal || '').trim();
                    if (!name) return;
                    root.style.setProperty(entry.var, '"' + name.replace(/"/g, '') + '", ' + (entry.fallback || 'sans-serif'));
                } else {
                    root.style.setProperty(entry.var, newVal + (entry.unit || ''));
                }
            });
        });
    });
})();

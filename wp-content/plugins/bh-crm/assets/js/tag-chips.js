"use strict";
/**
 * Tag chips + autocomplete over tags in use (BHCRM_Tags::render_editor()).
 * Progressive enhancement: the original hidden <input name="tags"> is kept in sync and is what submits.
 */
(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.bhcrm-tag-chips').forEach(initChipWidget);
    });
    function initChipWidget(container) {
        const hiddenInput = container.querySelector('.bhcrm-tag-chips-input');
        if (!hiddenInput)
            return;
        let suggestions = [];
        try {
            suggestions = JSON.parse(container.dataset.suggestions || '[]');
        }
        catch (e) {
            suggestions = [];
        }
        const tags = hiddenInput.value.split(',').map((t) => t.trim()).filter(Boolean);
        // Hidden but kept: it's still what gets submitted.
        hiddenInput.style.position = 'absolute';
        hiddenInput.style.left = '-9999px';
        hiddenInput.setAttribute('tabindex', '-1');
        hiddenInput.setAttribute('aria-hidden', 'true');
        const chipsList = document.createElement('div');
        chipsList.className = 'bhcrm-tag-chips-list';
        chipsList.style.cssText = 'display:flex;flex-wrap:wrap;gap:6px;margin-bottom:6px;';
        const typeahead = document.createElement('input');
        typeahead.type = 'text';
        typeahead.placeholder = tags.length ? 'Add another tag…' : 'Type a tag and press Enter…';
        typeahead.style.cssText = 'width:100%;max-width:400px;';
        typeahead.setAttribute('autocomplete', 'off');
        const dropdown = document.createElement('ul');
        dropdown.className = 'bhcrm-tag-suggestions';
        dropdown.style.cssText = 'list-style:none;margin:2px 0 0;padding:0;max-width:400px;border:1px solid #dcdcde;background:#fff;display:none;position:relative;z-index:10;max-height:160px;overflow-y:auto;';
        container.insertBefore(chipsList, hiddenInput);
        container.appendChild(typeahead);
        container.appendChild(dropdown);
        function sync() {
            hiddenInput.value = tags.join(', ');
        }
        function renderChips() {
            chipsList.innerHTML = '';
            tags.forEach((tag, i) => {
                const chip = document.createElement('span');
                chip.className = 'bhcrm-tag-chip';
                chip.style.cssText = 'display:inline-flex;align-items:center;gap:4px;background:#f0f0f1;border:1px solid #dcdcde;border-radius:12px;padding:2px 4px 2px 10px;font-size:12px;';
                chip.textContent = tag;
                const remove = document.createElement('button');
                remove.type = 'button';
                remove.setAttribute('aria-label', 'Remove tag ' + tag);
                remove.textContent = '×';
                remove.style.cssText = 'border:none;background:none;cursor:pointer;font-size:14px;line-height:1;padding:2px 4px;color:#646970;';
                remove.addEventListener('click', () => {
                    tags.splice(i, 1);
                    renderChips();
                    sync();
                });
                chip.appendChild(remove);
                chipsList.appendChild(chip);
            });
        }
        function addTag(raw) {
            const tag = raw.trim();
            if (!tag || tags.indexOf(tag) !== -1)
                return;
            tags.push(tag);
            renderChips();
            sync();
            typeahead.value = '';
            hideDropdown();
        }
        function hideDropdown() { dropdown.style.display = 'none'; dropdown.innerHTML = ''; }
        function showSuggestions(query) {
            const q = query.trim().toLowerCase();
            if (!q) {
                hideDropdown();
                return;
            }
            const matches = suggestions.filter((s) => s.toLowerCase().indexOf(q) !== -1 && tags.indexOf(s) === -1).slice(0, 8);
            if (!matches.length) {
                hideDropdown();
                return;
            }
            dropdown.innerHTML = '';
            matches.forEach((s) => {
                const li = document.createElement('li');
                li.textContent = s;
                li.style.cssText = 'padding:6px 10px;cursor:pointer;';
                li.addEventListener('mousedown', (e) => { e.preventDefault(); addTag(s); });
                li.addEventListener('mouseenter', () => { li.style.background = '#f0f0f1'; });
                li.addEventListener('mouseleave', () => { li.style.background = ''; });
                dropdown.appendChild(li);
            });
            dropdown.style.display = 'block';
        }
        typeahead.addEventListener('input', () => { showSuggestions(typeahead.value); });
        typeahead.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ',') {
                e.preventDefault();
                addTag(typeahead.value.replace(/,$/, ''));
            }
            else if (e.key === 'Backspace' && typeahead.value === '' && tags.length) {
                tags.pop();
                renderChips();
                sync();
            }
            else if (e.key === 'Escape') {
                hideDropdown();
            }
        });
        typeahead.addEventListener('blur', () => {
            // Delay so a suggestion's mousedown fires before the dropdown hides.
            setTimeout(hideDropdown, 150);
        });
        renderChips();
    }
})();

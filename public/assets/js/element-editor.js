/**
 * Element Editor — Slot builder UI and live preview for element catalogue.
 */
(function() {
    'use strict';

    var SLOT_TYPES = ['text', 'richtext', 'image', 'link', 'select', 'boolean', 'number', 'list'];

    var slots = [];
    var slotListEl = null;
    var noSlotsMsg = null;
    var jsonInput = null;
    var elementId = null;

    window.initElementEditor = function(initialSlots, elId) {
        slotListEl = document.getElementById('slot-list');
        noSlotsMsg = document.getElementById('no-slots-msg');
        jsonInput = document.getElementById('slots-json-input');
        elementId = elId;

        slots = Array.isArray(initialSlots) ? initialSlots : [];

        renderAllSlots();

        document.getElementById('add-slot-btn').addEventListener('click', function() {
            readSlotsFromDOM();
            slots.push({
                key: '',
                label: '',
                type: 'text',
                required: false,
                default: '',
                options: [],
                sub_slots: []
            });
            renderAllSlots();
        });

        // Serialize on form submit
        document.getElementById('element-form').addEventListener('submit', function() {
            serializeSlots();
        });

        // Auto-generate slug from name
        var nameEl = document.getElementById('el-name');
        var slugEl = document.getElementById('el-slug');
        var cssHint = document.getElementById('css-slug-hint');
        if (nameEl && slugEl) {
            nameEl.addEventListener('input', function() {
                if (slugEl.dataset.manual !== '1') {
                    var s = nameEl.value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
                    slugEl.value = s;
                    if (cssHint) cssHint.textContent = s || 'your-slug';
                }
            });
            slugEl.addEventListener('input', function() {
                slugEl.dataset.manual = '1';
                if (cssHint) cssHint.textContent = slugEl.value || 'your-slug';
            });
        }

        // Live preview
        var previewBtn = document.getElementById('refresh-preview-btn');
        if (previewBtn) {
            previewBtn.addEventListener('click', refreshPreview);
        }
    };

    function renderAllSlots() {
        slotListEl.innerHTML = '';
        noSlotsMsg.style.display = slots.length === 0 ? '' : 'none';

        slots.forEach(function(slot, index) {
            slotListEl.appendChild(createSlotRow(slot, index));
        });
    }

    function createSlotRow(slot, index) {
        var row = document.createElement('div');
        row.className = 'slot-row';
        row.setAttribute('data-index', index);

        var isSelect = slot.type === 'select';
        var isList = slot.type === 'list';
        var optionsStr = Array.isArray(slot.options) ? slot.options.join('\n') : '';

        row.innerHTML = ''
            + '<div class="slot-row-header">'
            + '  <span class="slot-drag-handle" title="Drag to reorder">&#9776;</span>'
            + '  <strong class="slot-title">' + escHtml(slot.label || slot.key || 'New Slot') + '</strong>'
            + '  <button type="button" class="btn btn-sm btn-danger slot-remove-btn">&times;</button>'
            + '</div>'
            + '<div class="slot-row-fields">'
            + '  <div class="slot-field">'
            + '    <label>Key</label>'
            + '    <input type="text" class="slot-key" value="' + escAttr(slot.key) + '" pattern="[a-z0-9_]+" placeholder="field_key">'
            + '  </div>'
            + '  <div class="slot-field">'
            + '    <label>Label</label>'
            + '    <input type="text" class="slot-label" value="' + escAttr(slot.label) + '" placeholder="Display Label">'
            + '  </div>'
            + '  <div class="slot-field">'
            + '    <label>Type</label>'
            + '    <select class="slot-type">'
            + SLOT_TYPES.map(function(t) {
                return '<option value="' + t + '"' + (slot.type === t ? ' selected' : '') + '>' + t + '</option>';
              }).join('')
            + '    </select>'
            + '  </div>'
            + '  <div class="slot-field">'
            + '    <label><input type="checkbox" class="slot-required"' + (slot.required ? ' checked' : '') + '> Required</label>'
            + '  </div>'
            + '</div>'
            + '<div class="slot-options-section" style="' + (isSelect ? '' : 'display:none;') + '">'
            + '  <label>Options (one per line)</label>'
            + '  <textarea class="slot-options" rows="3" placeholder="option1&#10;option2&#10;option3">' + escHtml(optionsStr) + '</textarea>'
            + '</div>'
            + '<div class="slot-subslots-section" style="' + (isList ? '' : 'display:none;') + '">'
            + '  <label>Sub-Slots (for list items)</label>'
            + '  <div class="sub-slot-list" data-parent="' + index + '"></div>'
            + '  <button type="button" class="btn btn-sm btn-secondary add-sub-slot-btn">+ Sub-Slot</button>'
            + '</div>';

        // Type change handler
        var typeSelect = row.querySelector('.slot-type');
        typeSelect.addEventListener('change', function() {
            row.querySelector('.slot-options-section').style.display = typeSelect.value === 'select' ? '' : 'none';
            row.querySelector('.slot-subslots-section').style.display = typeSelect.value === 'list' ? '' : 'none';
        });

        // Remove handler
        row.querySelector('.slot-remove-btn').addEventListener('click', function() {
            readSlotsFromDOM();
            slots.splice(index, 1);
            renderAllSlots();
        });

        // Sub-slots for list type
        if (isList && Array.isArray(slot.sub_slots)) {
            var subList = row.querySelector('.sub-slot-list');
            slot.sub_slots.forEach(function(sub, si) {
                subList.appendChild(createSubSlotRow(sub, si));
            });
        }

        row.querySelector('.add-sub-slot-btn').addEventListener('click', function() {
            var subList = row.querySelector('.sub-slot-list');
            var count = subList.children.length;
            subList.appendChild(createSubSlotRow({key: '', label: '', type: 'text'}, count));
        });

        return row;
    }

    function createSubSlotRow(sub, index) {
        var row = document.createElement('div');
        row.className = 'sub-slot-row';

        row.innerHTML = ''
            + '<input type="text" class="sub-slot-key" value="' + escAttr(sub.key) + '" placeholder="key" style="width:25%;">'
            + '<input type="text" class="sub-slot-label" value="' + escAttr(sub.label) + '" placeholder="Label" style="width:30%;">'
            + '<select class="sub-slot-type" style="width:25%;">'
            + ['text', 'richtext', 'image', 'number', 'boolean'].map(function(t) {
                return '<option value="' + t + '"' + (sub.type === t ? ' selected' : '') + '>' + t + '</option>';
              }).join('')
            + '</select>'
            + '<button type="button" class="btn btn-sm btn-danger sub-slot-remove">&times;</button>';

        row.querySelector('.sub-slot-remove').addEventListener('click', function() {
            row.remove();
        });

        return row;
    }

    function readSlotsFromDOM() {
        var rows = slotListEl.querySelectorAll('.slot-row');
        slots = [];
        rows.forEach(function(row) {
            var slot = {
                key: row.querySelector('.slot-key').value.trim(),
                label: row.querySelector('.slot-label').value.trim(),
                type: row.querySelector('.slot-type').value,
                required: row.querySelector('.slot-required').checked,
            };

            if (slot.type === 'select') {
                var optText = row.querySelector('.slot-options').value.trim();
                slot.options = optText ? optText.split('\n').map(function(o) { return o.trim(); }).filter(Boolean) : [];
            }

            if (slot.type === 'list') {
                slot.sub_slots = [];
                var subRows = row.querySelectorAll('.sub-slot-row');
                subRows.forEach(function(sr) {
                    slot.sub_slots.push({
                        key: sr.querySelector('.sub-slot-key').value.trim(),
                        label: sr.querySelector('.sub-slot-label').value.trim(),
                        type: sr.querySelector('.sub-slot-type').value,
                    });
                });
            }

            slots.push(slot);
        });
    }

    function serializeSlots() {
        readSlotsFromDOM();
        jsonInput.value = JSON.stringify(slots);
    }

    function refreshPreview() {
        if (!elementId) {
            // For new elements, do a client-side preview
            clientSidePreview();
            return;
        }

        fetch('/admin/elements/' + elementId + '/preview', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                renderPreview(data.html, data.css);
            }
        })
        .catch(function() {
            clientSidePreview();
        });
    }

    function clientSidePreview() {
        var template = document.getElementById('el-html-template').value;
        var css = document.getElementById('el-css').value;

        // Simple client-side rendering (basic replacements only)
        readSlotsFromDOM();
        var sampleData = {};
        slots.forEach(function(s) {
            if (s.type === 'text' || s.type === 'image' || s.type === 'select') {
                sampleData[s.key] = s.default || 'Sample ' + (s.label || s.key);
            } else if (s.type === 'richtext') {
                sampleData[s.key] = '<p>Sample ' + escHtml(s.label || s.key) + '</p>';
            } else if (s.type === 'boolean') {
                sampleData[s.key] = true;
            } else if (s.type === 'number') {
                sampleData[s.key] = 3;
            }
        });

        // Basic mustache replacement (text only, no sections)
        var html = template.replace(/\{\{\{([a-zA-Z0-9_.]+)\}\}\}/g, function(m, key) {
            return sampleData[key] || '';
        });
        html = html.replace(/\{\{([a-zA-Z0-9_.]+)\}\}/g, function(m, key) {
            return escHtml(sampleData[key] || '');
        });

        // Remove section tags for basic preview
        html = html.replace(/\{\{[#^\/][a-zA-Z0-9_.]+\}\}/g, '');

        var slug = document.getElementById('el-slug').value || 'preview';
        var wrapped = '<div class="lcms-el lcms-el-' + escAttr(slug) + '">' + html + '</div>';

        renderPreview(wrapped, css);
    }

    function renderPreview(html, css) {
        var container = document.getElementById('element-preview');
        container.innerHTML = '<style>' + css + '</style>' + html;
    }

    function escHtml(str) {
        if (typeof str !== 'string') return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function escAttr(str) {
        if (typeof str !== 'string') return '';
        return str.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }
})();

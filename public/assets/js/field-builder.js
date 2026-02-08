/**
 * Field Builder — Dynamic custom field management for content type editor.
 */
(function() {
    'use strict';

    var fields = [];
    var fieldListEl = null;
    var noFieldsMsg = null;
    var jsonInput = null;

    window.initFieldBuilder = function(initialFields) {
        fieldListEl = document.getElementById('field-list');
        noFieldsMsg = document.getElementById('no-fields-msg');
        jsonInput = document.getElementById('fields-json-input');

        fields = Array.isArray(initialFields) ? initialFields : [];

        renderAllFields();

        document.getElementById('add-field-btn').addEventListener('click', function() {
            readFieldsFromDOM();
            fields.push({
                key: '',
                label: '',
                type: 'text',
                required: false,
                options: []
            });
            renderAllFields();
        });

        // Serialize on form submit
        document.getElementById('content-type-form').addEventListener('submit', function() {
            serializeFields();
        });
    };

    function renderAllFields() {
        fieldListEl.innerHTML = '';
        noFieldsMsg.style.display = fields.length === 0 ? '' : 'none';

        fields.forEach(function(field, index) {
            fieldListEl.appendChild(createFieldRow(field, index));
        });
    }

    function createFieldRow(field, index) {
        var row = document.createElement('div');
        row.className = 'field-row';
        row.setAttribute('data-index', index);
        row.style.cssText = 'border:1px solid var(--border-color,#ddd);border-radius:6px;padding:1rem;margin-bottom:0.75rem;background:var(--bg-card,#fafafa);';

        var isSelect = field.type === 'select';
        var optionsStr = Array.isArray(field.options) ? field.options.join('\n') : '';

        row.innerHTML = ''
            + '<div style="display:flex;gap:0.75rem;flex-wrap:wrap;align-items:flex-start;">'
            + '  <div style="flex:1;min-width:120px;">'
            + '    <label style="font-size:0.8rem;font-weight:600;">Key</label>'
            + '    <input type="text" class="field-key" value="' + escAttr(field.key) + '"'
            + '           placeholder="field_key" pattern="[a-z0-9_]+" style="width:100%;">'
            + '  </div>'
            + '  <div style="flex:2;min-width:150px;">'
            + '    <label style="font-size:0.8rem;font-weight:600;">Label</label>'
            + '    <input type="text" class="field-label" value="' + escAttr(field.label) + '"'
            + '           placeholder="Display Label" style="width:100%;">'
            + '  </div>'
            + '  <div style="flex:1;min-width:120px;">'
            + '    <label style="font-size:0.8rem;font-weight:600;">Type</label>'
            + '    <select class="field-type" style="width:100%;">'
            + '      <option value="text"' + (field.type === 'text' ? ' selected' : '') + '>Text</option>'
            + '      <option value="textarea"' + (field.type === 'textarea' ? ' selected' : '') + '>Textarea</option>'
            + '      <option value="image"' + (field.type === 'image' ? ' selected' : '') + '>Image</option>'
            + '      <option value="select"' + (field.type === 'select' ? ' selected' : '') + '>Select</option>'
            + '      <option value="boolean"' + (field.type === 'boolean' ? ' selected' : '') + '>Boolean</option>'
            + '    </select>'
            + '  </div>'
            + '  <div style="display:flex;align-items:flex-end;gap:0.25rem;padding-bottom:2px;">'
            + '    <label style="font-size:0.8rem;cursor:pointer;">'
            + '      <input type="checkbox" class="field-required"' + (field.required ? ' checked' : '') + '> Req.'
            + '    </label>'
            + '  </div>'
            + '  <div style="display:flex;align-items:flex-end;gap:0.25rem;padding-bottom:2px;">'
            + '    <button type="button" class="btn btn-sm move-up-btn"' + (index === 0 ? ' disabled' : '') + ' title="Move up">&#9650;</button>'
            + '    <button type="button" class="btn btn-sm move-down-btn"' + (index === fields.length - 1 ? ' disabled' : '') + ' title="Move down">&#9660;</button>'
            + '    <button type="button" class="btn btn-sm btn-danger remove-field-btn" title="Remove field">&times;</button>'
            + '  </div>'
            + '</div>'
            + '<div class="field-options-wrap" style="margin-top:0.5rem;' + (isSelect ? '' : 'display:none;') + '">'
            + '  <label style="font-size:0.8rem;font-weight:600;">Options (one per line)</label>'
            + '  <textarea class="field-options" rows="3" placeholder="Option 1&#10;Option 2&#10;Option 3" style="width:100%;">' + escHtml(optionsStr) + '</textarea>'
            + '</div>';

        // Event: type change toggles options visibility
        var typeSelect = row.querySelector('.field-type');
        var optionsWrap = row.querySelector('.field-options-wrap');
        typeSelect.addEventListener('change', function() {
            optionsWrap.style.display = this.value === 'select' ? '' : 'none';
        });

        // Event: remove
        row.querySelector('.remove-field-btn').addEventListener('click', function() {
            readFieldsFromDOM();
            fields.splice(index, 1);
            renderAllFields();
        });

        // Event: move up
        row.querySelector('.move-up-btn').addEventListener('click', function() {
            if (index > 0) {
                readFieldsFromDOM();
                var temp = fields[index];
                fields[index] = fields[index - 1];
                fields[index - 1] = temp;
                renderAllFields();
            }
        });

        // Event: move down
        row.querySelector('.move-down-btn').addEventListener('click', function() {
            if (index < fields.length - 1) {
                readFieldsFromDOM();
                var temp = fields[index];
                fields[index] = fields[index + 1];
                fields[index + 1] = temp;
                renderAllFields();
            }
        });

        return row;
    }

    function readFieldsFromDOM() {
        var rows = fieldListEl.querySelectorAll('.field-row');
        fields = [];
        rows.forEach(function(row) {
            var optionsText = row.querySelector('.field-options').value.trim();
            var options = optionsText ? optionsText.split('\n').map(function(s) { return s.trim(); }).filter(Boolean) : [];
            fields.push({
                key: row.querySelector('.field-key').value.trim(),
                label: row.querySelector('.field-label').value.trim(),
                type: row.querySelector('.field-type').value,
                required: row.querySelector('.field-required').checked,
                options: options
            });
        });
    }

    function serializeFields() {
        readFieldsFromDOM();
        jsonInput.value = JSON.stringify(fields);
    }

    function escAttr(str) {
        return String(str || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function escHtml(str) {
        return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }
})();

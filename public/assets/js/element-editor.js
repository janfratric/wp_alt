/**
 * Element Editor — Tabbed sidebar (Settings / Slots / Content),
 * card-based slot builder, content-driven live preview, collapsible code.
 */
(function() {
    'use strict';

    /* ── constants ── */
    var SLOT_TYPES = ['text', 'richtext', 'image', 'link', 'select', 'boolean', 'number', 'list'];

    var TYPE_ICONS = {
        text:     { abbr: 'T',  label: 'Text' },
        richtext: { abbr: 'RT', label: 'Rich Text' },
        image:    { abbr: 'Im', label: 'Image' },
        link:     { abbr: 'Lk', label: 'Link' },
        select:   { abbr: 'Se', label: 'Select' },
        boolean:  { abbr: 'Bl', label: 'Boolean' },
        number:   { abbr: 'Nu', label: 'Number' },
        list:     { abbr: 'Li', label: 'List' }
    };

    /* ── state ── */
    var slots = [];
    var contentValues = {};      // persists across tab switches
    var slotListEl, noSlotsMsg, jsonInput, elementId;
    var previewDebounce = null;
    var currentTab = 'settings';
    var dragSrcCard = null;

    /* ================================================================
       Init
       ================================================================ */
    window.initElementEditor = function(initialSlots, elId) {
        slotListEl  = document.getElementById('slot-list');
        noSlotsMsg  = document.getElementById('no-slots-msg');
        jsonInput   = document.getElementById('slots-json-input');
        elementId   = elId;

        slots = Array.isArray(initialSlots) ? initialSlots : [];

        renderAllSlots();

        /* Tab bar */
        document.querySelectorAll('.el-tab').forEach(function(btn) {
            btn.addEventListener('click', function() {
                switchTab(btn.getAttribute('data-tab'));
            });
        });

        /* Add slot — show type picker */
        document.getElementById('add-slot-btn').addEventListener('click', function() {
            var picker = document.getElementById('slot-type-picker');
            if (picker) {
                picker.classList.toggle('open');
                return;
            }
            showTypePicker();
        });

        /* Form submit */
        document.getElementById('element-form').addEventListener('submit', function() {
            serializeSlots();
        });

        /* Auto-generate slug from name */
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

        /* Refresh preview button */
        var previewBtn = document.getElementById('refresh-preview-btn');
        if (previewBtn) previewBtn.addEventListener('click', function() { refreshPreviewWithContent(); });

        /* Code collapse toggle */
        var codeToggle = document.getElementById('code-toggle-btn');
        var codeBody   = document.getElementById('code-collapse-body');
        if (codeToggle && codeBody) {
            codeToggle.addEventListener('click', function() {
                var open = codeBody.style.display !== 'none';
                codeBody.style.display = open ? 'none' : '';
                codeToggle.setAttribute('aria-expanded', open ? 'false' : 'true');
                codeToggle.querySelector('.code-toggle-icon').textContent = open ? '\u25B6' : '\u25BC';
                codeToggle.childNodes[1].textContent = open ? ' Show Code' : ' Hide Code';
            });
        }

        /* Code sub-tabs (HTML / CSS) */
        document.querySelectorAll('.code-tab').forEach(function(btn) {
            btn.addEventListener('click', function() {
                document.querySelectorAll('.code-tab').forEach(function(b) { b.classList.remove('active'); });
                document.querySelectorAll('.code-tab-pane').forEach(function(p) { p.classList.remove('active'); });
                btn.classList.add('active');
                var pane = document.getElementById('code-tab-' + btn.getAttribute('data-code-tab'));
                if (pane) pane.classList.add('active');
            });
        });

        /* Initial preview */
        if (slots.length > 0) {
            setTimeout(function() { refreshPreviewWithContent(); }, 300);
        }
    };

    /* ================================================================
       Tab switching
       ================================================================ */
    function switchTab(tabName) {
        currentTab = tabName;
        document.querySelectorAll('.el-tab').forEach(function(b) {
            b.classList.toggle('active', b.getAttribute('data-tab') === tabName);
        });
        document.querySelectorAll('.el-tab-pane').forEach(function(p) {
            p.classList.toggle('active', p.id === 'el-tab-' + tabName);
        });
        if (tabName === 'content') {
            readSlotsFromDOM();
            renderContentFields();
        }
        if (tabName === 'slots') {
            // Ensure latest content values saved
            readContentValuesFromDOM();
        }
    }

    /* ================================================================
       Type picker (shown when + Add Slot is clicked)
       ================================================================ */
    function showTypePicker() {
        var wrapper = document.createElement('div');
        wrapper.id = 'slot-type-picker';
        wrapper.className = 'slot-type-picker open';

        SLOT_TYPES.forEach(function(t) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'slot-type-pick-btn';
            btn.innerHTML = '<span class="slot-card-type-icon type-' + t + '">' + TYPE_ICONS[t].abbr + '</span> ' + TYPE_ICONS[t].label;
            btn.addEventListener('click', function() {
                wrapper.classList.remove('open');
                addSlotOfType(t);
            });
            wrapper.appendChild(btn);
        });

        var addBar = document.querySelector('.slot-add-bar');
        addBar.appendChild(wrapper);
    }

    function addSlotOfType(type) {
        readSlotsFromDOM();
        slots.push({
            key: '',
            label: '',
            type: type,
            required: false,
            default: '',
            options: [],
            sub_slots: []
        });
        renderAllSlots();
        // Expand + scroll to the new card
        var cards = slotListEl.querySelectorAll('.slot-card');
        var last = cards[cards.length - 1];
        if (last) {
            last.classList.add('expanded');
            last.querySelector('.slot-card-body').style.display = '';
            last.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }

    /* ================================================================
       Slot rendering (card-based)
       ================================================================ */
    function renderAllSlots() {
        slotListEl.innerHTML = '';
        noSlotsMsg.style.display = slots.length === 0 ? '' : 'none';

        slots.forEach(function(slot, index) {
            slotListEl.appendChild(createSlotCard(slot, index));
        });
    }

    function createSlotCard(slot, index) {
        var card = document.createElement('div');
        card.className = 'slot-card';
        card.setAttribute('data-index', index);

        var isSelect = slot.type === 'select';
        var isList = slot.type === 'list';
        var optionsStr = Array.isArray(slot.options) ? slot.options.join('\n') : '';
        var icon = TYPE_ICONS[slot.type] || TYPE_ICONS.text;

        // Header
        var header = document.createElement('div');
        header.className = 'slot-card-header';
        header.innerHTML = ''
            + '<span class="slot-drag-handle" title="Drag to reorder">&#9776;</span>'
            + '<span class="slot-card-type-icon type-' + escAttr(slot.type) + '">' + icon.abbr + '</span>'
            + '<span class="slot-card-title">' + escHtml(slot.label || slot.key || 'New Slot') + '</span>'
            + '<span class="slot-card-type-badge">' + escHtml(slot.type) + '</span>'
            + (slot.required ? '<span class="slot-card-required">*</span>' : '')
            + '<span class="slot-card-expand">&#9660;</span>'
            + '<button type="button" class="btn btn-sm btn-danger slot-remove-btn" title="Remove">&times;</button>';

        // Body
        var body = document.createElement('div');
        body.className = 'slot-card-body';
        body.style.display = 'none';

        body.innerHTML = ''
            + '<div class="slot-field-grid">'
            + '  <div class="form-group">'
            + '    <label>Key</label>'
            + '    <input type="text" class="slot-key" value="' + escAttr(slot.key) + '" pattern="[a-z0-9_]+" placeholder="field_key">'
            + '  </div>'
            + '  <div class="form-group">'
            + '    <label>Label</label>'
            + '    <input type="text" class="slot-label" value="' + escAttr(slot.label) + '" placeholder="Display Label">'
            + '  </div>'
            + '  <div class="form-group">'
            + '    <label>Type</label>'
            + '    <select class="slot-type">'
            +      SLOT_TYPES.map(function(t) {
                       return '<option value="' + t + '"' + (slot.type === t ? ' selected' : '') + '>' + t + '</option>';
                   }).join('')
            + '    </select>'
            + '  </div>'
            + '  <div class="form-group">'
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

        card.appendChild(header);
        card.appendChild(body);

        /* ── event listeners ── */

        // Expand / collapse on header click
        header.addEventListener('click', function(e) {
            if (e.target.closest('.slot-remove-btn') || e.target.closest('.slot-drag-handle')) return;
            var expanded = card.classList.toggle('expanded');
            body.style.display = expanded ? '' : 'none';
        });

        // Remove
        header.querySelector('.slot-remove-btn').addEventListener('click', function(e) {
            e.stopPropagation();
            readSlotsFromDOM();
            slots.splice(index, 1);
            renderAllSlots();
        });

        // Type change — update icon and badge in header, toggle options/subslots
        var typeSelect = body.querySelector('.slot-type');
        typeSelect.addEventListener('change', function() {
            var newType = typeSelect.value;
            var ic = TYPE_ICONS[newType] || TYPE_ICONS.text;
            header.querySelector('.slot-card-type-icon').className = 'slot-card-type-icon type-' + newType;
            header.querySelector('.slot-card-type-icon').textContent = ic.abbr;
            header.querySelector('.slot-card-type-badge').textContent = newType;
            body.querySelector('.slot-options-section').style.display = newType === 'select' ? '' : 'none';
            body.querySelector('.slot-subslots-section').style.display = newType === 'list' ? '' : 'none';
        });

        // Label change — update title in header
        body.querySelector('.slot-label').addEventListener('input', function() {
            var val = this.value.trim() || body.querySelector('.slot-key').value.trim() || 'New Slot';
            header.querySelector('.slot-card-title').textContent = val;
        });

        // Required checkbox — update indicator
        body.querySelector('.slot-required').addEventListener('change', function() {
            var existing = header.querySelector('.slot-card-required');
            if (this.checked && !existing) {
                var span = document.createElement('span');
                span.className = 'slot-card-required';
                span.textContent = '*';
                header.querySelector('.slot-card-type-badge').after(span);
            } else if (!this.checked && existing) {
                existing.remove();
            }
        });

        // Sub-slots for list type
        if (isList && Array.isArray(slot.sub_slots)) {
            var subList = body.querySelector('.sub-slot-list');
            slot.sub_slots.forEach(function(sub, si) {
                subList.appendChild(createSubSlotRow(sub, si));
            });
        }

        body.querySelector('.add-sub-slot-btn').addEventListener('click', function() {
            var subList = body.querySelector('.sub-slot-list');
            var count = subList.children.length;
            subList.appendChild(createSubSlotRow({ key: '', label: '', type: 'text' }, count));
        });

        // Drag reorder
        setupSlotDrag(card, header.querySelector('.slot-drag-handle'));

        return card;
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

        row.querySelector('.sub-slot-remove').addEventListener('click', function() { row.remove(); });
        return row;
    }

    /* ================================================================
       Drag & drop reorder
       ================================================================ */
    function setupSlotDrag(card, handle) {
        handle.setAttribute('draggable', 'true');

        handle.addEventListener('dragstart', function(e) {
            dragSrcCard = card;
            card.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', card.getAttribute('data-index'));
        });

        card.addEventListener('dragover', function(e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            if (dragSrcCard && dragSrcCard !== card) {
                card.classList.add('drag-over');
            }
        });

        card.addEventListener('dragleave', function() {
            card.classList.remove('drag-over');
        });

        card.addEventListener('drop', function(e) {
            e.preventDefault();
            card.classList.remove('drag-over');
            if (!dragSrcCard || dragSrcCard === card) return;

            readSlotsFromDOM();
            var fromIdx = parseInt(dragSrcCard.getAttribute('data-index'), 10);
            var toIdx = parseInt(card.getAttribute('data-index'), 10);
            var moved = slots.splice(fromIdx, 1)[0];
            slots.splice(toIdx, 0, moved);
            renderAllSlots();
        });

        handle.addEventListener('dragend', function() {
            if (dragSrcCard) dragSrcCard.classList.remove('dragging');
            dragSrcCard = null;
            slotListEl.querySelectorAll('.drag-over').forEach(function(el) { el.classList.remove('drag-over'); });
        });
    }

    /* ================================================================
       Read slots from DOM
       ================================================================ */
    function readSlotsFromDOM() {
        var cards = slotListEl.querySelectorAll('.slot-card');
        slots = [];
        cards.forEach(function(card) {
            var slot = {
                key:      card.querySelector('.slot-key').value.trim(),
                label:    card.querySelector('.slot-label').value.trim(),
                type:     card.querySelector('.slot-type').value,
                required: card.querySelector('.slot-required').checked,
            };

            if (slot.type === 'select') {
                var optText = card.querySelector('.slot-options').value.trim();
                slot.options = optText ? optText.split('\n').map(function(o) { return o.trim(); }).filter(Boolean) : [];
            }

            if (slot.type === 'list') {
                slot.sub_slots = [];
                var subRows = card.querySelectorAll('.sub-slot-row');
                subRows.forEach(function(sr) {
                    slot.sub_slots.push({
                        key:   sr.querySelector('.sub-slot-key').value.trim(),
                        label: sr.querySelector('.sub-slot-label').value.trim(),
                        type:  sr.querySelector('.sub-slot-type').value,
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

    /* ================================================================
       Content tab — auto-generated fields from slot definitions
       ================================================================ */
    function renderContentFields() {
        var container = document.getElementById('content-fields');
        var noMsg     = document.getElementById('no-content-msg');
        if (!container) return;

        container.innerHTML = '';

        if (slots.length === 0) {
            if (noMsg) noMsg.style.display = '';
            return;
        }
        if (noMsg) noMsg.style.display = 'none';

        slots.forEach(function(slot) {
            if (!slot.key) return;
            var group = document.createElement('div');
            group.className = 'content-field-group';
            group.setAttribute('data-slot-key', slot.key);

            var label = document.createElement('label');
            label.textContent = slot.label || slot.key;
            if (slot.required) {
                var req = document.createElement('span');
                req.textContent = ' *';
                req.style.color = '#e74c3c';
                label.appendChild(req);
            }
            group.appendChild(label);

            var idPrefix = 'content-' + slot.key;
            var currentVal = contentValues[slot.key] !== undefined ? contentValues[slot.key] : undefined;
            group.appendChild(createContentField(slot, idPrefix, currentVal));
            container.appendChild(group);
        });

        // Bind change events
        container.addEventListener('input', onContentChange);
        container.addEventListener('change', onContentChange);
    }

    function createContentField(slot, idPrefix, currentValue) {
        var el;

        switch (slot.type) {
            case 'text':
                el = document.createElement('input');
                el.type = 'text';
                el.id = idPrefix;
                el.value = currentValue !== undefined ? currentValue : '';
                el.placeholder = 'Enter ' + (slot.label || slot.key);
                break;

            case 'richtext':
                el = document.createElement('textarea');
                el.id = idPrefix;
                el.rows = 4;
                el.value = currentValue !== undefined ? currentValue : '';
                el.placeholder = 'Enter rich text / HTML...';
                break;

            case 'image':
                el = document.createElement('div');
                el.className = 'content-image-field';
                var imgVal = currentValue !== undefined ? String(currentValue) : '';
                var hasImg = imgVal !== '';
                el.innerHTML = ''
                    + '<div class="content-image-preview" style="display:' + (hasImg ? 'block' : 'none') + ';">'
                    + '  <img src="' + escAttr(imgVal) + '" alt="Preview">'
                    + '</div>'
                    + '<div class="content-image-controls">'
                    + '  <input type="text" id="' + idPrefix + '" value="' + escAttr(imgVal) + '" placeholder="Image URL or path">'
                    + '  <button type="button" class="btn btn-sm btn-secondary content-browse-media">Browse Media</button>'
                    + '  <button type="button" class="btn btn-sm content-remove-media" style="display:' + (hasImg ? 'inline-block' : 'none') + ';">Remove</button>'
                    + '</div>';
                (function(wrapper) {
                    setTimeout(function() {
                        var urlInput   = wrapper.querySelector('input[type="text"]');
                        var browseBtn  = wrapper.querySelector('.content-browse-media');
                        var removeBtn  = wrapper.querySelector('.content-remove-media');
                        var previewDiv = wrapper.querySelector('.content-image-preview');
                        var previewImg = previewDiv ? previewDiv.querySelector('img') : null;

                        function updatePreview(url) {
                            if (url) {
                                previewImg.src = url;
                                previewDiv.style.display = 'block';
                                removeBtn.style.display = 'inline-block';
                            } else {
                                previewDiv.style.display = 'none';
                                removeBtn.style.display = 'none';
                            }
                        }

                        // URL input change updates preview
                        urlInput.addEventListener('change', function() {
                            updatePreview(urlInput.value);
                        });

                        // Browse Media button
                        if (browseBtn) {
                            browseBtn.addEventListener('click', function(e) {
                                e.preventDefault();
                                if (typeof window.openMediaBrowser === 'function') {
                                    window.openMediaBrowser(function(url) {
                                        urlInput.value = url;
                                        updatePreview(url);
                                        onContentChange();
                                    });
                                } else {
                                    alert('Media browser not available.');
                                }
                            });
                        }

                        // Remove button
                        if (removeBtn) {
                            removeBtn.addEventListener('click', function(e) {
                                e.preventDefault();
                                urlInput.value = '';
                                updatePreview('');
                                onContentChange();
                            });
                        }
                    }, 0);
                })(el);
                break;

            case 'boolean':
                var wrap = document.createElement('div');
                wrap.style.display = 'flex';
                wrap.style.alignItems = 'center';
                wrap.style.gap = '8px';

                var toggle = document.createElement('label');
                toggle.className = 'content-toggle';
                var cb = document.createElement('input');
                cb.type = 'checkbox';
                cb.id = idPrefix;
                if (currentValue !== undefined ? currentValue : false) cb.checked = true;
                var slider = document.createElement('span');
                slider.className = 'content-toggle-slider';
                toggle.appendChild(cb);
                toggle.appendChild(slider);

                var lbl = document.createElement('span');
                lbl.textContent = cb.checked ? 'Yes' : 'No';
                cb.addEventListener('change', function() { lbl.textContent = cb.checked ? 'Yes' : 'No'; });

                wrap.appendChild(toggle);
                wrap.appendChild(lbl);
                el = wrap;
                break;

            case 'number':
                el = document.createElement('input');
                el.type = 'number';
                el.id = idPrefix;
                el.value = currentValue !== undefined ? currentValue : '';
                el.placeholder = '0';
                break;

            case 'select':
                el = document.createElement('select');
                el.id = idPrefix;
                var opts = Array.isArray(slot.options) ? slot.options : [];
                var emptyOpt = document.createElement('option');
                emptyOpt.value = '';
                emptyOpt.textContent = '-- Select --';
                el.appendChild(emptyOpt);
                opts.forEach(function(o) {
                    var opt = document.createElement('option');
                    opt.value = o;
                    opt.textContent = o;
                    if (currentValue === o) opt.selected = true;
                    el.appendChild(opt);
                });
                break;

            case 'link':
                el = document.createElement('div');
                el.className = 'content-link-group';
                var stored = (typeof currentValue === 'object' && currentValue) ? currentValue : {};
                el.innerHTML = ''
                    + '<input type="url" class="link-url" id="' + idPrefix + '-url" placeholder="URL" value="' + escAttr(stored.url || '') + '">'
                    + '<input type="text" class="link-text" id="' + idPrefix + '-text" placeholder="Link text" value="' + escAttr(stored.text || '') + '">'
                    + '<select class="link-target" id="' + idPrefix + '-target">'
                    + '  <option value="_self"' + (stored.target === '_self' ? ' selected' : '') + '>Same tab</option>'
                    + '  <option value="_blank"' + (stored.target === '_blank' ? ' selected' : '') + '>New tab</option>'
                    + '</select>';
                break;

            case 'list':
                el = document.createElement('div');
                el.className = 'content-list-items';
                el.setAttribute('data-slot-key', slot.key);
                var items = (Array.isArray(currentValue) ? currentValue : []);
                if (items.length === 0) items = [{}]; // start with one empty item
                items.forEach(function(item, idx) {
                    el.appendChild(createListItem(slot, idx, item));
                });
                var addBtn = document.createElement('button');
                addBtn.type = 'button';
                addBtn.className = 'btn btn-sm btn-secondary';
                addBtn.textContent = '+ Add Item';
                addBtn.addEventListener('click', function() {
                    var count = el.querySelectorAll('.content-list-item').length;
                    el.insertBefore(createListItem(slot, count, {}), addBtn);
                    onContentChange();
                });
                el.appendChild(addBtn);
                break;

            default:
                el = document.createElement('input');
                el.type = 'text';
                el.value = currentValue !== undefined ? String(currentValue) : '';
        }

        return el;
    }

    function createListItem(slot, idx, values) {
        var item = document.createElement('div');
        item.className = 'content-list-item';

        var subSlots = Array.isArray(slot.sub_slots) ? slot.sub_slots : [];
        if (subSlots.length === 0) {
            // Single text field per item
            var inp = document.createElement('input');
            inp.type = 'text';
            inp.className = 'list-item-value';
            inp.value = typeof values === 'string' ? values : '';
            inp.placeholder = 'Item ' + (idx + 1);
            item.appendChild(inp);
        } else {
            subSlots.forEach(function(sub) {
                var wrap = document.createElement('div');
                wrap.className = 'form-group';
                wrap.style.marginBottom = '4px';
                var lbl = document.createElement('label');
                lbl.textContent = sub.label || sub.key;
                lbl.style.fontSize = '11px';
                wrap.appendChild(lbl);

                var inp = document.createElement('input');
                inp.type = sub.type === 'number' ? 'number' : 'text';
                inp.className = 'list-sub-field';
                inp.setAttribute('data-sub-key', sub.key);
                inp.value = (values && values[sub.key]) || '';
                inp.placeholder = sub.label || sub.key;
                wrap.appendChild(inp);
                item.appendChild(wrap);
            });
        }

        var removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'btn btn-sm btn-danger';
        removeBtn.textContent = '\u00D7';
        removeBtn.style.alignSelf = 'flex-start';
        removeBtn.addEventListener('click', function() {
            item.remove();
            onContentChange();
        });
        item.appendChild(removeBtn);

        return item;
    }

    /* ================================================================
       Read content values from DOM
       ================================================================ */
    function readContentValuesFromDOM() {
        var container = document.getElementById('content-fields');
        if (!container) return;

        slots.forEach(function(slot) {
            if (!slot.key) return;
            var prefix = 'content-' + slot.key;

            switch (slot.type) {
                case 'boolean':
                    var cb = container.querySelector('#' + prefix);
                    if (cb) contentValues[slot.key] = cb.checked;
                    break;

                case 'link':
                    var urlEl  = container.querySelector('#' + prefix + '-url');
                    var textEl = container.querySelector('#' + prefix + '-text');
                    var tgtEl  = container.querySelector('#' + prefix + '-target');
                    if (urlEl) {
                        contentValues[slot.key] = {
                            url:    urlEl.value,
                            text:   textEl ? textEl.value : '',
                            target: tgtEl ? tgtEl.value : '_self'
                        };
                    }
                    break;

                case 'list':
                    var listDiv = container.querySelector('.content-list-items[data-slot-key="' + slot.key + '"]');
                    if (listDiv) {
                        var items = [];
                        listDiv.querySelectorAll('.content-list-item').forEach(function(li) {
                            var subFields = li.querySelectorAll('.list-sub-field');
                            if (subFields.length > 0) {
                                var obj = {};
                                subFields.forEach(function(sf) {
                                    obj[sf.getAttribute('data-sub-key')] = sf.value;
                                });
                                items.push(obj);
                            } else {
                                var singleInput = li.querySelector('.list-item-value');
                                if (singleInput) items.push(singleInput.value);
                            }
                        });
                        contentValues[slot.key] = items;
                    }
                    break;

                default:
                    var field = container.querySelector('#' + prefix);
                    if (field) contentValues[slot.key] = field.value;
            }
        });
    }

    /* ================================================================
       Auto-preview on content change (debounced)
       ================================================================ */
    function onContentChange() {
        readContentValuesFromDOM();
        if (previewDebounce) clearTimeout(previewDebounce);
        previewDebounce = setTimeout(function() {
            refreshPreviewWithContent();
        }, 600);
    }

    /* ================================================================
       Preview
       ================================================================ */
    function refreshPreviewWithContent() {
        readSlotsFromDOM();
        readContentValuesFromDOM();

        var sampleData = buildSampleData();
        var csrfInput = document.querySelector('input[name="_csrf_token"]');
        var csrfToken = csrfInput ? csrfInput.value : '';

        if (!elementId) {
            clientSidePreview(sampleData);
            return;
        }

        fetch('/admin/elements/' + elementId + '/preview', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({
                html_template: document.getElementById('el-html-template').value,
                css: document.getElementById('el-css').value,
                slug: document.getElementById('el-slug').value,
                slots_json: slots,
                sample_data: sampleData
            })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                renderPreview(data.html, data.css);
            }
        })
        .catch(function() {
            clientSidePreview(sampleData);
        });
    }

    function buildSampleData() {
        var data = {};
        slots.forEach(function(s) {
            if (!s.key) return;
            var val = contentValues[s.key];
            if (val !== undefined && val !== '') {
                data[s.key] = val;
            } else {
                // Generate placeholder if no content value
                switch (s.type) {
                    case 'text':
                    case 'select':
                        data[s.key] = 'Sample ' + (s.label || s.key);
                        break;
                    case 'image':
                        var lbl = encodeURIComponent(s.label || s.key);
                        data[s.key] = 'https://placehold.co/400x300/e2e8f0/475569?text=' + lbl;
                        break;
                    case 'richtext':
                        data[s.key] = '<p>Sample ' + escHtml(s.label || s.key) + '</p>';
                        break;
                    case 'boolean':
                        data[s.key] = true;
                        break;
                    case 'number':
                        data[s.key] = 3;
                        break;
                    case 'link':
                        data[s.key] = { url: '#', text: 'Sample Link', target: '_self' };
                        break;
                    case 'list':
                        data[s.key] = [];
                        break;
                }
            }
        });
        return data;
    }

    function clientSidePreview(sampleData) {
        var template = document.getElementById('el-html-template').value;
        var css = document.getElementById('el-css').value;

        if (!sampleData) sampleData = buildSampleData();

        // Basic mustache replacement
        var html = template.replace(/\{\{\{([a-zA-Z0-9_.]+)\}\}\}/g, function(m, key) {
            return sampleData[key] || '';
        });
        html = html.replace(/\{\{([a-zA-Z0-9_.]+)\}\}/g, function(m, key) {
            var v = sampleData[key];
            if (typeof v === 'object') return escHtml(JSON.stringify(v));
            return escHtml(v != null ? String(v) : '');
        });
        html = html.replace(/\{\{[#^\/][a-zA-Z0-9_.]+\}\}/g, '');

        var slug = document.getElementById('el-slug').value || 'preview';
        var wrapped = '<div class="lcms-el lcms-el-' + escAttr(slug) + '">' + html + '</div>';
        renderPreview(wrapped, css);
    }

    function renderPreview(html, css) {
        var container = document.getElementById('element-preview');
        container.innerHTML = '<style>' + css + '</style>' + html;
    }

    /* ================================================================
       Helpers
       ================================================================ */
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

    /* ================================================================
       Public API — used by AI assistant to get/set slots and content
       ================================================================ */
    window.setElementSlots = function(newSlots) {
        if (!Array.isArray(newSlots) || !slotListEl) return;

        slots = newSlots.map(function(s) {
            return {
                key:       String(s.key || '').toLowerCase().replace(/[^a-z0-9_]/g, '_'),
                label:     String(s.label || s.key || ''),
                type:      SLOT_TYPES.indexOf(s.type) !== -1 ? s.type : 'text',
                required:  !!s.required,
                default:   s['default'] !== undefined ? s['default'] : '',
                options:   Array.isArray(s.options) ? s.options : [],
                sub_slots: Array.isArray(s.sub_slots) ? s.sub_slots.map(function(sub) {
                    return {
                        key:   String(sub.key || ''),
                        label: String(sub.label || sub.key || ''),
                        type:  sub.type || 'text'
                    };
                }) : []
            };
        });

        renderAllSlots();
        serializeSlots();

        if (currentTab === 'content') {
            renderContentFields();
        }
    };

    window.getElementSlots = function() {
        readSlotsFromDOM();
        return JSON.parse(JSON.stringify(slots));
    };

    window.setElementContent = function(sampleData) {
        if (!sampleData || typeof sampleData !== 'object') return;
        contentValues = sampleData;

        if (currentTab === 'content') {
            renderContentFields();
        }
        refreshPreviewWithContent();
    };

    window.getElementContent = function() {
        return JSON.parse(JSON.stringify(contentValues));
    };
})();

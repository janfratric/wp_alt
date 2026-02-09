/**
 * LiteCMS Page Builder
 * Element picker, slot forms, drag-drop reorder, JSON serialization.
 */
(function() {
    'use strict';

    // -----------------------------------------------------------------------
    // State
    // -----------------------------------------------------------------------
    var instances = [];       // Array of {elementId, elementSlug, elementName, elementCategory, slots, slotData, styleData}
    var catalogue = [];       // Cached element catalogue from API
    var pickerModal = null;   // DOM ref: picker modal
    var instanceList = null;  // DOM ref: instance list container
    var jsonInput = null;     // DOM ref: hidden elements_json input
    var csrfToken = '';       // CSRF token for API calls
    var dragSrcIndex = null;  // Index of element being dragged

    // -----------------------------------------------------------------------
    // Init
    // -----------------------------------------------------------------------
    function initPageBuilder(existingInstances, csrf) {
        csrfToken = csrf || '';

        instanceList = document.getElementById('pb-instance-list');
        jsonInput = document.getElementById('elements-json-input');
        pickerModal = document.getElementById('pb-picker-modal');

        // Parse existing instances
        if (Array.isArray(existingInstances)) {
            instances = existingInstances.map(function(inst) {
                return {
                    elementId: inst.elementId || 0,
                    elementSlug: inst.elementSlug || '',
                    elementName: inst.elementName || 'Unknown',
                    elementCategory: inst.elementCategory || 'general',
                    slots: Array.isArray(inst.slots) ? inst.slots : [],
                    slotData: (inst.slotData && typeof inst.slotData === 'object') ? inst.slotData : {},
                    styleData: (inst.styleData && typeof inst.styleData === 'object') ? inst.styleData : {}
                };
            });
        }

        renderAllInstances();

        // "Add Element" button
        var addBtn = document.getElementById('pb-add-element');
        if (addBtn) {
            addBtn.addEventListener('click', function(e) {
                e.preventDefault();
                openPicker();
            });
        }

        // Form submit handler — serialize instances to hidden input
        var form = instanceList ? instanceList.closest('form') : null;
        if (form) {
            form.addEventListener('submit', function() {
                serializeInstances();
            });
        }

        // Picker close handlers
        if (pickerModal) {
            var closeBtn = pickerModal.querySelector('.pb-picker-close');
            if (closeBtn) {
                closeBtn.addEventListener('click', function() {
                    closePicker();
                });
            }
            var overlay = pickerModal.querySelector('.pb-picker-overlay');
            if (overlay) {
                overlay.addEventListener('click', function() {
                    closePicker();
                });
            }
        }

        // Pre-fetch catalogue
        fetchCatalogue(function() {});
    }

    // -----------------------------------------------------------------------
    // Catalogue
    // -----------------------------------------------------------------------
    function fetchCatalogue(callback) {
        if (catalogue.length > 0) {
            if (callback) callback(catalogue);
            return;
        }

        var headers = { 'X-Requested-With': 'XMLHttpRequest' };
        if (csrfToken) {
            headers['X-CSRF-Token'] = csrfToken;
        }

        fetch('/admin/elements/api/list', { headers: headers })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data.success && Array.isArray(data.elements)) {
                    catalogue = data.elements;
                } else if (Array.isArray(data)) {
                    catalogue = data;
                }
                if (callback) callback(catalogue);
            })
            .catch(function() {
                catalogue = [];
                if (callback) callback(catalogue);
            });
    }

    // -----------------------------------------------------------------------
    // Picker Modal
    // -----------------------------------------------------------------------
    function openPicker() {
        fetchCatalogue(function() {
            renderPickerContent('', '');
            if (pickerModal) {
                pickerModal.classList.remove('hidden');
                var searchInput = document.getElementById('pb-picker-search');
                if (searchInput) {
                    searchInput.value = '';
                    searchInput.focus();
                }
            }
        });
    }

    function closePicker() {
        if (pickerModal) {
            pickerModal.classList.add('hidden');
        }
    }

    function renderPickerContent(searchTerm, categoryFilter) {
        var categoriesContainer = document.getElementById('pb-picker-categories');
        var gridContainer = document.getElementById('pb-picker-grid');

        if (!categoriesContainer || !gridContainer) return;

        // Collect unique categories
        var categories = [];
        catalogue.forEach(function(el) {
            var cat = el.category || 'general';
            if (categories.indexOf(cat) === -1) {
                categories.push(cat);
            }
        });
        categories.sort();

        // Render category tabs
        categoriesContainer.innerHTML = '';
        var allTab = document.createElement('button');
        allTab.type = 'button';
        allTab.className = 'pb-category-tab' + (!categoryFilter ? ' active' : '');
        allTab.textContent = 'All';
        allTab.addEventListener('click', function() {
            renderPickerContent(searchTerm, '');
        });
        categoriesContainer.appendChild(allTab);

        categories.forEach(function(cat) {
            var tab = document.createElement('button');
            tab.type = 'button';
            tab.className = 'pb-category-tab' + (categoryFilter === cat ? ' active' : '');
            tab.textContent = cat;
            tab.addEventListener('click', function() {
                renderPickerContent(searchTerm, cat);
            });
            categoriesContainer.appendChild(tab);
        });

        // Filter catalogue
        var filtered = catalogue.filter(function(el) {
            var matchesSearch = !searchTerm
                || (el.name || '').toLowerCase().indexOf(searchTerm.toLowerCase()) !== -1
                || (el.description || '').toLowerCase().indexOf(searchTerm.toLowerCase()) !== -1;
            var matchesCategory = !categoryFilter || (el.category || 'general') === categoryFilter;
            return matchesSearch && matchesCategory;
        });

        // Render grid
        gridContainer.innerHTML = '';

        if (filtered.length === 0) {
            var empty = document.createElement('div');
            empty.className = 'pb-picker-empty';
            empty.textContent = 'No elements found.';
            gridContainer.appendChild(empty);
            return;
        }

        filtered.forEach(function(el) {
            var card = document.createElement('div');
            card.className = 'pb-picker-card';
            card.innerHTML = '<div class="pb-picker-card-name">' + escHtml(el.name || '') + '</div>'
                + '<div class="pb-picker-card-desc">' + escHtml(el.description || '') + '</div>'
                + '<span class="pb-picker-card-category">' + escHtml(el.category || 'general') + '</span>';

            card.addEventListener('click', function() {
                addElement(el);
            });

            gridContainer.appendChild(card);
        });

        // Wire up search input
        var searchInput = document.getElementById('pb-picker-search');
        if (searchInput) {
            // Remove old listeners by replacing the node
            var newSearch = searchInput.cloneNode(true);
            searchInput.parentNode.replaceChild(newSearch, searchInput);
            newSearch.value = searchTerm || '';
            newSearch.addEventListener('input', function() {
                renderPickerContent(newSearch.value, categoryFilter);
            });
        }
    }

    // -----------------------------------------------------------------------
    // Add / Remove Elements
    // -----------------------------------------------------------------------
    function addElement(elementDef) {
        var slots = [];
        try {
            slots = typeof elementDef.slots_json === 'string'
                ? JSON.parse(elementDef.slots_json)
                : (Array.isArray(elementDef.slots_json) ? elementDef.slots_json : []);
        } catch (e) {
            slots = [];
        }

        var instance = {
            elementId: elementDef.id || 0,
            elementSlug: elementDef.slug || '',
            elementName: elementDef.name || 'Unknown',
            elementCategory: elementDef.category || 'general',
            slots: slots,
            slotData: buildDefaultSlotData(slots),
            styleData: {}
        };

        // Read current DOM state before adding (preserve any edits)
        if (instances.length > 0) {
            readInstancesFromDOM();
        }

        instances.push(instance);
        closePicker();
        renderAllInstances();

        // Scroll to new instance
        var cards = instanceList.querySelectorAll('.pb-instance-card');
        if (cards.length > 0) {
            var last = cards[cards.length - 1];
            last.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }

    function buildDefaultSlotData(slots) {
        var data = {};
        if (!Array.isArray(slots)) return data;

        slots.forEach(function(slot) {
            var key = slot.key || '';
            if (!key) return;

            switch (slot.type) {
                case 'text':
                    data[key] = '';
                    break;
                case 'richtext':
                    data[key] = '';
                    break;
                case 'image':
                    data[key] = '';
                    break;
                case 'link':
                    data[key] = { url: '', text: '', target: '_self' };
                    break;
                case 'select':
                    data[key] = (Array.isArray(slot.options) && slot.options.length > 0)
                        ? slot.options[0] : '';
                    break;
                case 'boolean':
                    data[key] = false;
                    break;
                case 'number':
                    data[key] = 0;
                    break;
                case 'list':
                    data[key] = [];
                    break;
                default:
                    data[key] = '';
            }
        });

        return data;
    }

    function removeInstance(index) {
        if (!confirm('Remove this element?')) return;

        readInstancesFromDOM();
        instances.splice(index, 1);
        renderAllInstances();
    }

    // -----------------------------------------------------------------------
    // Render Instances
    // -----------------------------------------------------------------------
    function renderAllInstances() {
        if (!instanceList) return;

        instanceList.innerHTML = '';

        if (instances.length === 0) {
            var empty = document.createElement('div');
            empty.className = 'pb-empty-state';
            empty.id = 'pb-empty-state';
            empty.innerHTML = '<div class="pb-empty-icon">&#9647;</div>'
                + '<p>No elements added yet.</p>'
                + '<p style="font-size:0.85rem;color:var(--color-text-muted);">'
                + 'Click "Add Element" to start building your page.</p>';
            instanceList.appendChild(empty);
        } else {
            instances.forEach(function(instance, index) {
                instanceList.appendChild(createInstanceCard(instance, index));
            });
        }

        // Update count badge
        var badge = document.getElementById('pb-element-count');
        if (badge) {
            var count = instances.length;
            badge.textContent = count + ' element' + (count !== 1 ? 's' : '');
        }
    }

    // -----------------------------------------------------------------------
    // Instance Card
    // -----------------------------------------------------------------------
    function createInstanceCard(instance, index) {
        var card = document.createElement('div');
        card.className = 'pb-instance-card';
        card.setAttribute('draggable', 'true');
        card.setAttribute('data-index', index);

        // Header
        var header = document.createElement('div');
        header.className = 'pb-instance-header';

        var dragHandle = document.createElement('span');
        dragHandle.className = 'pb-drag-handle';
        dragHandle.innerHTML = '&#9776;';
        dragHandle.title = 'Drag to reorder';

        var nameSpan = document.createElement('span');
        nameSpan.className = 'pb-instance-name';
        nameSpan.textContent = instance.elementName || 'Unknown';

        var categoryBadge = document.createElement('span');
        categoryBadge.className = 'pb-instance-category';
        categoryBadge.textContent = instance.elementCategory || 'general';

        var collapseBtn = document.createElement('button');
        collapseBtn.type = 'button';
        collapseBtn.className = 'pb-collapse-btn';
        collapseBtn.innerHTML = '&#9660;';
        collapseBtn.title = 'Toggle collapse';
        collapseBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            card.classList.toggle('pb-collapsed');
            collapseBtn.innerHTML = card.classList.contains('pb-collapsed') ? '&#9650;' : '&#9660;';
        });

        var removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'pb-remove-btn';
        removeBtn.innerHTML = '&times;';
        removeBtn.title = 'Remove element';
        removeBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            removeInstance(index);
        });

        header.appendChild(dragHandle);
        header.appendChild(nameSpan);
        header.appendChild(categoryBadge);
        header.appendChild(collapseBtn);
        header.appendChild(removeBtn);

        // Tab bar
        var tabBar = document.createElement('div');
        tabBar.className = 'pb-tab-bar';

        var contentTab = document.createElement('button');
        contentTab.type = 'button';
        contentTab.className = 'pb-tab active';
        contentTab.textContent = 'Content';

        var styleTab = document.createElement('button');
        styleTab.type = 'button';
        styleTab.className = 'pb-tab';
        styleTab.textContent = 'Style';

        tabBar.appendChild(contentTab);
        tabBar.appendChild(styleTab);

        // Content panel (slot fields)
        var contentPanel = document.createElement('div');
        contentPanel.className = 'pb-content-panel';

        if (Array.isArray(instance.slots) && instance.slots.length > 0) {
            instance.slots.forEach(function(slot) {
                contentPanel.appendChild(createSlotField(slot, instance.slotData, index));
            });
        } else {
            var noSlots = document.createElement('p');
            noSlots.style.cssText = 'color:var(--color-text-muted);font-size:0.85rem;margin:0;';
            noSlots.textContent = 'This element has no configurable slots.';
            contentPanel.appendChild(noSlots);
        }

        // Style panel
        var stylePanel = createStylePanel(instance, index);

        // Body wrapper
        var body = document.createElement('div');
        body.className = 'pb-instance-body';
        body.appendChild(contentPanel);
        body.appendChild(stylePanel);

        // Tab click handlers
        contentTab.addEventListener('click', function(e) {
            e.preventDefault();
            contentTab.classList.add('active');
            styleTab.classList.remove('active');
            contentPanel.classList.remove('hidden');
            stylePanel.classList.add('hidden');
        });

        styleTab.addEventListener('click', function(e) {
            e.preventDefault();
            styleTab.classList.add('active');
            contentTab.classList.remove('active');
            stylePanel.classList.remove('hidden');
            contentPanel.classList.add('hidden');
        });

        card.appendChild(header);
        card.appendChild(tabBar);
        card.appendChild(body);

        // Drag event listeners
        card.addEventListener('dragstart', function(e) {
            handleDragStart(e, index);
        });
        card.addEventListener('dragover', function(e) {
            handleDragOver(e, index);
        });
        card.addEventListener('drop', function(e) {
            handleDrop(e, index);
        });
        card.addEventListener('dragend', function(e) {
            handleDragEnd(e);
        });

        return card;
    }

    // -----------------------------------------------------------------------
    // Slot Field Factory
    // -----------------------------------------------------------------------
    function createSlotField(slot, slotData, instanceIndex) {
        var field = document.createElement('div');
        field.className = 'pb-slot-field';

        var key = slot.key || '';
        var label = slot.label || key;
        var value = slotData[key];
        var type = slot.type || 'text';

        switch (type) {
            case 'text':
                field.innerHTML = '<label>' + escHtml(label) + '</label>'
                    + '<input type="text" value="' + escAttr(value || '') + '"'
                    + ' data-instance="' + instanceIndex + '"'
                    + ' data-slot="' + escAttr(key) + '">';
                break;

            case 'richtext':
                field.innerHTML = '<label>' + escHtml(label) + '</label>'
                    + '<textarea rows="5"'
                    + ' data-instance="' + instanceIndex + '"'
                    + ' data-slot="' + escAttr(key) + '">'
                    + escHtml(value || '') + '</textarea>';
                break;

            case 'image':
                var imgVal = value || '';
                var hasImage = imgVal !== '';
                field.innerHTML = '<label>' + escHtml(label) + '</label>'
                    + '<div class="pb-image-field">'
                    + '<input type="hidden"'
                    + ' data-instance="' + instanceIndex + '"'
                    + ' data-slot="' + escAttr(key) + '"'
                    + ' value="' + escAttr(imgVal) + '">'
                    + '<img src="' + escAttr(imgVal) + '" class="pb-image-preview"'
                    + ' style="display:' + (hasImage ? '' : 'none') + ';">'
                    + '<button type="button" class="btn btn-sm pb-browse-media">Browse Media</button>'
                    + '<button type="button" class="btn btn-sm pb-remove-media"'
                    + ' style="display:' + (hasImage ? '' : 'none') + ';">Remove</button>'
                    + '</div>';

                // Bind media browse after creating the DOM
                (function(fieldEl) {
                    setTimeout(function() {
                        var browseBtn = fieldEl.querySelector('.pb-browse-media');
                        var removeMediaBtn = fieldEl.querySelector('.pb-remove-media');
                        var hiddenInput = fieldEl.querySelector('input[type="hidden"]');
                        var previewImg = fieldEl.querySelector('.pb-image-preview');

                        if (browseBtn) {
                            browseBtn.addEventListener('click', function(e) {
                                e.preventDefault();
                                if (typeof window.openMediaBrowser === 'function') {
                                    window.openMediaBrowser(function(url) {
                                        hiddenInput.value = url;
                                        previewImg.src = url;
                                        previewImg.style.display = '';
                                        removeMediaBtn.style.display = '';
                                    });
                                } else {
                                    var url = prompt('Enter image URL:');
                                    if (url) {
                                        hiddenInput.value = url;
                                        previewImg.src = url;
                                        previewImg.style.display = '';
                                        removeMediaBtn.style.display = '';
                                    }
                                }
                            });
                        }

                        if (removeMediaBtn) {
                            removeMediaBtn.addEventListener('click', function(e) {
                                e.preventDefault();
                                hiddenInput.value = '';
                                previewImg.style.display = 'none';
                                removeMediaBtn.style.display = 'none';
                            });
                        }
                    }, 0);
                })(field);
                break;

            case 'link':
                var linkData = (value && typeof value === 'object') ? value : { url: '', text: '', target: '_self' };
                field.innerHTML = '<label>' + escHtml(label) + '</label>'
                    + '<div class="pb-link-fields">'
                    + '<input type="text" placeholder="URL"'
                    + ' data-instance="' + instanceIndex + '"'
                    + ' data-slot="' + escAttr(key) + '"'
                    + ' data-subkey="url"'
                    + ' value="' + escAttr(linkData.url || '') + '">'
                    + '<input type="text" placeholder="Link Text"'
                    + ' data-instance="' + instanceIndex + '"'
                    + ' data-slot="' + escAttr(key) + '"'
                    + ' data-subkey="text"'
                    + ' value="' + escAttr(linkData.text || '') + '">'
                    + '<select'
                    + ' data-instance="' + instanceIndex + '"'
                    + ' data-slot="' + escAttr(key) + '"'
                    + ' data-subkey="target">'
                    + '<option value="_self"' + ((linkData.target || '_self') === '_self' ? ' selected' : '') + '>Same Tab</option>'
                    + '<option value="_blank"' + (linkData.target === '_blank' ? ' selected' : '') + '>New Tab</option>'
                    + '</select>'
                    + '</div>';
                break;

            case 'select':
                var options = Array.isArray(slot.options) ? slot.options : [];
                var selectHtml = '<label>' + escHtml(label) + '</label>'
                    + '<select data-instance="' + instanceIndex + '"'
                    + ' data-slot="' + escAttr(key) + '">';
                options.forEach(function(opt) {
                    selectHtml += '<option value="' + escAttr(opt) + '"'
                        + ((value || '') === opt ? ' selected' : '') + '>'
                        + escHtml(opt) + '</option>';
                });
                selectHtml += '</select>';
                field.innerHTML = selectHtml;
                break;

            case 'boolean':
                var checked = value === true || value === 'true' || value === '1' || value === 1;
                field.innerHTML = '<label class="pb-bool-label">'
                    + '<input type="checkbox"'
                    + ' data-instance="' + instanceIndex + '"'
                    + ' data-slot="' + escAttr(key) + '"'
                    + (checked ? ' checked' : '') + '> '
                    + escHtml(label) + '</label>';
                break;

            case 'number':
                var numVal = (value !== undefined && value !== null && value !== '') ? value : 0;
                field.innerHTML = '<label>' + escHtml(label) + '</label>'
                    + '<input type="number" value="' + escAttr(String(numVal)) + '"'
                    + ' data-instance="' + instanceIndex + '"'
                    + ' data-slot="' + escAttr(key) + '">';
                break;

            case 'list':
                var listItems = Array.isArray(value) ? value : [];
                var subSlots = Array.isArray(slot.sub_slots) ? slot.sub_slots : [];

                field.innerHTML = '<label>' + escHtml(label) + '</label>';

                var listContainer = document.createElement('div');
                listContainer.className = 'pb-list-field';
                listContainer.setAttribute('data-instance', instanceIndex);
                listContainer.setAttribute('data-slot', key);

                var itemsWrapper = document.createElement('div');
                itemsWrapper.className = 'pb-list-items';

                listItems.forEach(function(itemData, itemIndex) {
                    itemsWrapper.appendChild(createListItem(subSlots, itemData, itemIndex));
                });

                listContainer.appendChild(itemsWrapper);

                var addItemBtn = document.createElement('button');
                addItemBtn.type = 'button';
                addItemBtn.className = 'btn btn-sm pb-add-list-item';
                addItemBtn.textContent = '+ Add Item';
                addItemBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    // Build default item data from sub_slots
                    var newItemData = {};
                    subSlots.forEach(function(ss) {
                        switch (ss.type) {
                            case 'boolean': newItemData[ss.key] = false; break;
                            case 'number':  newItemData[ss.key] = 0; break;
                            case 'image':   newItemData[ss.key] = ''; break;
                            default:        newItemData[ss.key] = '';
                        }
                    });
                    var count = itemsWrapper.children.length;
                    itemsWrapper.appendChild(createListItem(subSlots, newItemData, count));
                });

                listContainer.appendChild(addItemBtn);
                field.appendChild(listContainer);
                break;

            default:
                field.innerHTML = '<label>' + escHtml(label) + '</label>'
                    + '<input type="text" value="' + escAttr(value || '') + '"'
                    + ' data-instance="' + instanceIndex + '"'
                    + ' data-slot="' + escAttr(key) + '">';
        }

        return field;
    }

    // -----------------------------------------------------------------------
    // List Item
    // -----------------------------------------------------------------------
    function createListItem(subSlots, itemData, itemIndex) {
        var item = document.createElement('div');
        item.className = 'pb-list-item';

        // Header with index and remove button
        var itemHeader = document.createElement('div');
        itemHeader.className = 'pb-list-item-header';
        itemHeader.innerHTML = '<span>Item ' + (itemIndex + 1) + '</span>';

        var removeItemBtn = document.createElement('button');
        removeItemBtn.type = 'button';
        removeItemBtn.className = 'pb-list-remove';
        removeItemBtn.innerHTML = '&times;';
        removeItemBtn.title = 'Remove item';
        removeItemBtn.addEventListener('click', function(e) {
            e.preventDefault();
            item.remove();
            // Re-number remaining items
            var parent = item.parentNode;
            if (parent) {
                var items = parent.querySelectorAll('.pb-list-item');
                items.forEach(function(it, idx) {
                    var hdr = it.querySelector('.pb-list-item-header span');
                    if (hdr) hdr.textContent = 'Item ' + (idx + 1);
                });
            }
        });
        itemHeader.appendChild(removeItemBtn);
        item.appendChild(itemHeader);

        // Sub-slot fields
        var safeData = (itemData && typeof itemData === 'object') ? itemData : {};

        if (Array.isArray(subSlots)) {
            subSlots.forEach(function(ss) {
                var subField = document.createElement('div');
                subField.className = 'pb-slot-field pb-sub-slot-field';

                var ssKey = ss.key || '';
                var ssLabel = ss.label || ssKey;
                var ssValue = safeData[ssKey];
                var ssType = ss.type || 'text';

                switch (ssType) {
                    case 'text':
                        subField.innerHTML = '<label>' + escHtml(ssLabel) + '</label>'
                            + '<input type="text" class="pb-list-input"'
                            + ' data-subslot="' + escAttr(ssKey) + '"'
                            + ' value="' + escAttr(ssValue || '') + '">';
                        break;

                    case 'richtext':
                        subField.innerHTML = '<label>' + escHtml(ssLabel) + '</label>'
                            + '<textarea rows="3" class="pb-list-input"'
                            + ' data-subslot="' + escAttr(ssKey) + '">'
                            + escHtml(ssValue || '') + '</textarea>';
                        break;

                    case 'image':
                        var subImgVal = ssValue || '';
                        var subHasImage = subImgVal !== '';
                        subField.innerHTML = '<label>' + escHtml(ssLabel) + '</label>'
                            + '<div class="pb-image-field">'
                            + '<input type="hidden" class="pb-list-input"'
                            + ' data-subslot="' + escAttr(ssKey) + '"'
                            + ' value="' + escAttr(subImgVal) + '">'
                            + '<img src="' + escAttr(subImgVal) + '" class="pb-image-preview"'
                            + ' style="display:' + (subHasImage ? '' : 'none') + ';">'
                            + '<button type="button" class="btn btn-sm pb-browse-media">Browse</button>'
                            + '<button type="button" class="btn btn-sm pb-remove-media"'
                            + ' style="display:' + (subHasImage ? '' : 'none') + ';">Remove</button>'
                            + '</div>';

                        (function(sf) {
                            setTimeout(function() {
                                var bBtn = sf.querySelector('.pb-browse-media');
                                var rBtn = sf.querySelector('.pb-remove-media');
                                var hInput = sf.querySelector('input[type="hidden"]');
                                var pImg = sf.querySelector('.pb-image-preview');

                                if (bBtn) {
                                    bBtn.addEventListener('click', function(ev) {
                                        ev.preventDefault();
                                        if (typeof window.openMediaBrowser === 'function') {
                                            window.openMediaBrowser(function(url) {
                                                hInput.value = url;
                                                pImg.src = url;
                                                pImg.style.display = '';
                                                rBtn.style.display = '';
                                            });
                                        } else {
                                            var u = prompt('Enter image URL:');
                                            if (u) {
                                                hInput.value = u;
                                                pImg.src = u;
                                                pImg.style.display = '';
                                                rBtn.style.display = '';
                                            }
                                        }
                                    });
                                }
                                if (rBtn) {
                                    rBtn.addEventListener('click', function(ev) {
                                        ev.preventDefault();
                                        hInput.value = '';
                                        pImg.style.display = 'none';
                                        rBtn.style.display = 'none';
                                    });
                                }
                            }, 0);
                        })(subField);
                        break;

                    case 'boolean':
                        var subChecked = ssValue === true || ssValue === 'true' || ssValue === '1' || ssValue === 1;
                        subField.innerHTML = '<label>'
                            + '<input type="checkbox" class="pb-list-input"'
                            + ' data-subslot="' + escAttr(ssKey) + '"'
                            + (subChecked ? ' checked' : '') + '> '
                            + escHtml(ssLabel) + '</label>';
                        break;

                    case 'number':
                        var subNumVal = (ssValue !== undefined && ssValue !== null && ssValue !== '') ? ssValue : 0;
                        subField.innerHTML = '<label>' + escHtml(ssLabel) + '</label>'
                            + '<input type="number" class="pb-list-input"'
                            + ' data-subslot="' + escAttr(ssKey) + '"'
                            + ' value="' + escAttr(String(subNumVal)) + '">';
                        break;

                    default:
                        subField.innerHTML = '<label>' + escHtml(ssLabel) + '</label>'
                            + '<input type="text" class="pb-list-input"'
                            + ' data-subslot="' + escAttr(ssKey) + '"'
                            + ' value="' + escAttr(ssValue || '') + '">';
                }

                item.appendChild(subField);
            });
        }

        return item;
    }

    // -----------------------------------------------------------------------
    // Style Panel
    // -----------------------------------------------------------------------
    function createStylePanel(instance, index) {
        var panel = document.createElement('div');
        panel.className = 'pb-style-panel hidden';

        var data = instance.styleData || {};

        // 1. Spacing
        panel.appendChild(createStyleGroup('Spacing', function(content) {
            var mLabel = document.createElement('div');
            mLabel.className = 'pb-style-label';
            mLabel.textContent = 'Margin';
            content.appendChild(mLabel);
            content.appendChild(createSpacingControl('margin', data, index));

            var pLabel = document.createElement('div');
            pLabel.className = 'pb-style-label';
            pLabel.textContent = 'Padding';
            content.appendChild(pLabel);
            content.appendChild(createSpacingControl('padding', data, index));
        }));

        // 2. Background
        panel.appendChild(createStyleGroup('Background', function(content) {
            content.appendChild(createColorRow('Color', 'bg_color', data, index));
            content.appendChild(createStyleSelectRow('Size', 'bg_size', ['', 'cover', 'contain', 'auto'], data, index));
            content.appendChild(createStyleSelectRow('Position', 'bg_position', ['', 'center center', 'top center', 'bottom center', 'left center', 'right center'], data, index));
            content.appendChild(createStyleSelectRow('Repeat', 'bg_repeat', ['', 'no-repeat', 'repeat', 'repeat-x', 'repeat-y'], data, index));
        }));

        // 3. Typography
        panel.appendChild(createStyleGroup('Typography', function(content) {
            content.appendChild(createColorRow('Color', 'text_color', data, index));
            content.appendChild(createNumberUnitRow('Size', 'text_size', 'text_size_unit', data, index));
            content.appendChild(createAlignRow(data, index));
            content.appendChild(createStyleSelectRow('Weight', 'text_weight', ['', '100','200','300','400','500','600','700','800','900'], data, index));
        }));

        // 4. Border
        panel.appendChild(createStyleGroup('Border', function(content) {
            content.appendChild(createNumberUnitRow('Width', 'border_width', 'border_unit', data, index));
            content.appendChild(createStyleSelectRow('Style', 'border_style', ['', 'none','solid','dashed','dotted','double'], data, index));
            content.appendChild(createColorRow('Color', 'border_color', data, index));
            content.appendChild(createNumberUnitRow('Radius', 'border_radius', 'border_radius_unit', data, index));
        }));

        // 5. Effects
        panel.appendChild(createStyleGroup('Effects', function(content) {
            var shadowLabel = document.createElement('div');
            shadowLabel.className = 'pb-style-label';
            shadowLabel.textContent = 'Box Shadow';
            content.appendChild(shadowLabel);

            var shadowRow = document.createElement('div');
            shadowRow.className = 'pb-spacing-control';
            ['shadow_x','shadow_y','shadow_blur','shadow_spread'].forEach(function(key) {
                var lbl = key.split('_').pop();
                var l = document.createElement('label');
                l.textContent = lbl.charAt(0).toUpperCase() + lbl.slice(1);
                shadowRow.appendChild(l);
                var inp = document.createElement('input');
                inp.type = 'number';
                inp.value = data[key] || '';
                inp.setAttribute('data-instance', index);
                inp.setAttribute('data-style', key);
                shadowRow.appendChild(inp);
            });
            content.appendChild(shadowRow);
            content.appendChild(createColorRow('Shadow Color', 'shadow_color', data, index));

            // Opacity
            var opRow = document.createElement('div');
            opRow.className = 'pb-range-field';
            var opLabel = document.createElement('label');
            opLabel.textContent = 'Opacity';
            opLabel.style.cssText = 'font-size:0.8rem;min-width:5rem;color:var(--color-text-muted);';
            opRow.appendChild(opLabel);
            var opRange = document.createElement('input');
            opRange.type = 'range';
            opRange.min = '0';
            opRange.max = '1';
            opRange.step = '0.05';
            opRange.value = data.opacity !== undefined && data.opacity !== '' ? data.opacity : '1';
            opRange.setAttribute('data-instance', index);
            opRange.setAttribute('data-style', 'opacity');
            opRow.appendChild(opRange);
            var opVal = document.createElement('span');
            opVal.className = 'pb-range-value';
            opVal.textContent = opRange.value;
            opRow.appendChild(opVal);
            opRange.addEventListener('input', function() {
                opVal.textContent = opRange.value;
            });
            content.appendChild(opRow);
        }));

        // 6. Layout
        panel.appendChild(createStyleGroup('Layout', function(content) {
            content.appendChild(createTextRow('Max Width', 'max_width', data, index, 'e.g. 1200px'));
            content.appendChild(createTextRow('Min Height', 'min_height', data, index, 'e.g. 400px'));
        }));

        // 7. Custom CSS
        panel.appendChild(createStyleGroup('Custom CSS', function(content) {
            var wrapper = document.createElement('div');
            wrapper.className = 'pb-custom-css';
            var ta = document.createElement('textarea');
            ta.placeholder = '/* CSS rules here apply to this element */';
            ta.value = data.custom_css || '';
            ta.setAttribute('data-instance', index);
            ta.setAttribute('data-style', 'custom_css');
            wrapper.appendChild(ta);
            var hint = document.createElement('div');
            hint.className = 'pb-css-hint';
            hint.textContent = 'Selectors are automatically scoped to this element instance.';
            wrapper.appendChild(hint);
            content.appendChild(wrapper);
        }));

        // 8. Advanced
        panel.appendChild(createStyleGroup('Advanced', function(content) {
            content.appendChild(createTextRow('CSS Class', 'custom_class', data, index, 'Extra CSS classes'));
        }));

        return panel;
    }

    function createStyleGroup(title, buildFn) {
        var details = document.createElement('details');
        details.className = 'pb-style-group';
        var summary = document.createElement('summary');
        summary.textContent = title;
        details.appendChild(summary);
        var content = document.createElement('div');
        content.className = 'pb-style-group-content';
        buildFn(content);
        details.appendChild(content);
        return details;
    }

    function createSpacingControl(prefix, data, index) {
        var row = document.createElement('div');
        row.className = 'pb-spacing-control';

        var linked = data[prefix + '_linked'] || false;
        var linkBtn = document.createElement('button');
        linkBtn.type = 'button';
        linkBtn.className = 'pb-spacing-link' + (linked ? ' active' : '');
        linkBtn.innerHTML = '&#128279;';
        linkBtn.title = 'Link all sides';
        linkBtn.setAttribute('data-instance', index);
        linkBtn.setAttribute('data-style', prefix + '_linked');
        row.appendChild(linkBtn);

        var sides = ['top', 'right', 'bottom', 'left'];
        var inputs = [];
        sides.forEach(function(side) {
            var lbl = document.createElement('label');
            lbl.textContent = side.charAt(0).toUpperCase();
            row.appendChild(lbl);
            var inp = document.createElement('input');
            inp.type = 'number';
            inp.value = data[prefix + '_' + side] || '';
            inp.setAttribute('data-instance', index);
            inp.setAttribute('data-style', prefix + '_' + side);
            inp.addEventListener('input', function() {
                if (linkBtn.classList.contains('active')) {
                    inputs.forEach(function(i) { i.value = inp.value; });
                }
            });
            row.appendChild(inp);
            inputs.push(inp);
        });

        var unitSel = document.createElement('select');
        unitSel.setAttribute('data-instance', index);
        unitSel.setAttribute('data-style', prefix + '_unit');
        ['px','rem','em','%','vh','vw'].forEach(function(u) {
            var opt = document.createElement('option');
            opt.value = u;
            opt.textContent = u;
            if ((data[prefix + '_unit'] || 'px') === u) opt.selected = true;
            unitSel.appendChild(opt);
        });
        row.appendChild(unitSel);

        linkBtn.addEventListener('click', function(e) {
            e.preventDefault();
            linkBtn.classList.toggle('active');
        });

        return row;
    }

    function createColorRow(label, key, data, index) {
        var row = document.createElement('div');
        row.className = 'pb-style-row';
        var lbl = document.createElement('label');
        lbl.textContent = label;
        row.appendChild(lbl);

        var wrapper = document.createElement('div');
        wrapper.className = 'pb-color-field';
        var colorInp = document.createElement('input');
        colorInp.type = 'color';
        colorInp.value = data[key] || '#000000';
        var textInp = document.createElement('input');
        textInp.type = 'text';
        textInp.value = data[key] || '';
        textInp.placeholder = '#000000';
        textInp.setAttribute('data-instance', index);
        textInp.setAttribute('data-style', key);

        colorInp.addEventListener('input', function() { textInp.value = colorInp.value; });
        textInp.addEventListener('input', function() {
            if (/^#[0-9a-fA-F]{6}$/.test(textInp.value)) colorInp.value = textInp.value;
        });

        wrapper.appendChild(colorInp);
        wrapper.appendChild(textInp);
        row.appendChild(wrapper);
        return row;
    }

    function createNumberUnitRow(label, numKey, unitKey, data, index) {
        var row = document.createElement('div');
        row.className = 'pb-style-row';
        var lbl = document.createElement('label');
        lbl.textContent = label;
        row.appendChild(lbl);
        var inp = document.createElement('input');
        inp.type = 'number';
        inp.value = data[numKey] || '';
        inp.setAttribute('data-instance', index);
        inp.setAttribute('data-style', numKey);
        row.appendChild(inp);

        var sel = document.createElement('select');
        sel.setAttribute('data-instance', index);
        sel.setAttribute('data-style', unitKey);
        ['px','rem','em','%','vh','vw'].forEach(function(u) {
            var opt = document.createElement('option');
            opt.value = u;
            opt.textContent = u;
            if ((data[unitKey] || 'px') === u) opt.selected = true;
            sel.appendChild(opt);
        });
        row.appendChild(sel);
        return row;
    }

    function createAlignRow(data, index) {
        var row = document.createElement('div');
        row.className = 'pb-style-row';
        var lbl = document.createElement('label');
        lbl.textContent = 'Align';
        row.appendChild(lbl);

        var group = document.createElement('div');
        group.className = 'pb-align-group';
        var aligns = [
            { val: 'left', label: 'L' },
            { val: 'center', label: 'C' },
            { val: 'right', label: 'R' },
            { val: 'justify', label: 'J' }
        ];
        // Hidden input for value
        var hiddenInp = document.createElement('input');
        hiddenInp.type = 'hidden';
        hiddenInp.value = data.text_align || '';
        hiddenInp.setAttribute('data-instance', index);
        hiddenInp.setAttribute('data-style', 'text_align');

        aligns.forEach(function(a) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.textContent = a.label;
            if ((data.text_align || '') === a.val) btn.classList.add('active');
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                group.querySelectorAll('button').forEach(function(b) { b.classList.remove('active'); });
                btn.classList.add('active');
                hiddenInp.value = a.val;
            });
            group.appendChild(btn);
        });

        row.appendChild(group);
        row.appendChild(hiddenInp);
        return row;
    }

    function createStyleSelectRow(label, key, options, data, index) {
        var row = document.createElement('div');
        row.className = 'pb-style-row';
        var lbl = document.createElement('label');
        lbl.textContent = label;
        row.appendChild(lbl);
        var sel = document.createElement('select');
        sel.setAttribute('data-instance', index);
        sel.setAttribute('data-style', key);
        options.forEach(function(o) {
            var opt = document.createElement('option');
            opt.value = o;
            opt.textContent = o || '— None —';
            if ((data[key] || '') === o) opt.selected = true;
            sel.appendChild(opt);
        });
        row.appendChild(sel);
        return row;
    }

    function createTextRow(label, key, data, index, placeholder) {
        var row = document.createElement('div');
        row.className = 'pb-style-row';
        var lbl = document.createElement('label');
        lbl.textContent = label;
        row.appendChild(lbl);
        var inp = document.createElement('input');
        inp.type = 'text';
        inp.value = data[key] || '';
        inp.placeholder = placeholder || '';
        inp.setAttribute('data-instance', index);
        inp.setAttribute('data-style', key);
        row.appendChild(inp);
        return row;
    }

    function readStyleDataFromDOM(card, index) {
        if (index < 0 || index >= instances.length) return;

        var styleData = {};
        var styleInputs = card.querySelectorAll('[data-style]');
        styleInputs.forEach(function(inp) {
            var instIdx = parseInt(inp.getAttribute('data-instance'), 10);
            if (instIdx !== index) return;

            var key = inp.getAttribute('data-style');
            if (!key) return;

            if (key.endsWith('_linked')) {
                styleData[key] = inp.classList.contains('active');
            } else if (inp.type === 'checkbox') {
                styleData[key] = inp.checked;
            } else if (inp.type === 'range') {
                styleData[key] = inp.value;
            } else {
                styleData[key] = inp.value;
            }
        });

        instances[index].styleData = styleData;
    }

    // -----------------------------------------------------------------------
    // Read from DOM
    // -----------------------------------------------------------------------
    function readInstancesFromDOM() {
        if (!instanceList) return;

        var cards = instanceList.querySelectorAll('.pb-instance-card');

        cards.forEach(function(card) {
            var idx = parseInt(card.getAttribute('data-index'), 10);
            if (isNaN(idx) || idx < 0 || idx >= instances.length) return;

            var instance = instances[idx];
            if (!instance.slotData || typeof instance.slotData !== 'object') {
                instance.slotData = {};
            }

            // Process each slot
            if (Array.isArray(instance.slots)) {
                instance.slots.forEach(function(slot) {
                    var key = slot.key || '';
                    if (!key) return;

                    switch (slot.type) {
                        case 'boolean':
                            var cb = card.querySelector('[data-instance="' + idx + '"][data-slot="' + key + '"]');
                            if (cb) {
                                instance.slotData[key] = cb.checked;
                            }
                            break;

                        case 'link':
                            var urlEl = card.querySelector('[data-instance="' + idx + '"][data-slot="' + key + '"][data-subkey="url"]');
                            var textEl = card.querySelector('[data-instance="' + idx + '"][data-slot="' + key + '"][data-subkey="text"]');
                            var targetEl = card.querySelector('[data-instance="' + idx + '"][data-slot="' + key + '"][data-subkey="target"]');
                            instance.slotData[key] = {
                                url: urlEl ? urlEl.value : '',
                                text: textEl ? textEl.value : '',
                                target: targetEl ? targetEl.value : '_self'
                            };
                            break;

                        case 'list':
                            var listField = card.querySelector('.pb-list-field[data-instance="' + idx + '"][data-slot="' + key + '"]');
                            if (listField) {
                                var listItems = listField.querySelectorAll('.pb-list-item');
                                var listData = [];
                                listItems.forEach(function(li) {
                                    var itemObj = {};
                                    var inputs = li.querySelectorAll('.pb-list-input');
                                    inputs.forEach(function(inp) {
                                        var ssKey = inp.getAttribute('data-subslot');
                                        if (!ssKey) return;
                                        if (inp.type === 'checkbox') {
                                            itemObj[ssKey] = inp.checked;
                                        } else {
                                            itemObj[ssKey] = inp.value;
                                        }
                                    });
                                    listData.push(itemObj);
                                });
                                instance.slotData[key] = listData;
                            }
                            break;

                        default:
                            // text, richtext, number, select, image
                            var el = card.querySelector('[data-instance="' + idx + '"][data-slot="' + key + '"]');
                            if (el) {
                                instance.slotData[key] = el.value;
                            }
                            break;
                    }
                });
            }

            // Read style data from DOM
            readStyleDataFromDOM(card, idx);
        });
    }

    // -----------------------------------------------------------------------
    // Serialize
    // -----------------------------------------------------------------------
    function serializeInstances() {
        readInstancesFromDOM();

        var output = instances.map(function(inst) {
            return {
                element_id: inst.elementId,
                slot_data: inst.slotData || {},
                style_data: inst.styleData || {}
            };
        });

        if (jsonInput) {
            jsonInput.value = JSON.stringify(output);
        }
    }

    // -----------------------------------------------------------------------
    // Drag and Drop
    // -----------------------------------------------------------------------
    function handleDragStart(e, index) {
        dragSrcIndex = index;
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', String(index));

        // Add dragging class after a short delay (so the ghost image is clean)
        var card = e.currentTarget;
        setTimeout(function() {
            card.classList.add('pb-dragging');
        }, 0);
    }

    function handleDragOver(e, index) {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';

        // Remove drag-over from all, add to this card
        var cards = instanceList.querySelectorAll('.pb-instance-card');
        cards.forEach(function(c) {
            c.classList.remove('pb-drag-over');
        });

        if (dragSrcIndex !== null && dragSrcIndex !== index) {
            e.currentTarget.classList.add('pb-drag-over');
        }
    }

    function handleDrop(e, index) {
        e.preventDefault();
        e.stopPropagation();

        if (dragSrcIndex === null || dragSrcIndex === index) return;

        // Read current state first to preserve edits
        readInstancesFromDOM();

        // Reorder: remove from old position, insert at new position
        var moved = instances.splice(dragSrcIndex, 1)[0];
        instances.splice(index, 0, moved);

        dragSrcIndex = null;
        renderAllInstances();
    }

    function handleDragEnd(e) {
        // Clean up drag classes
        if (instanceList) {
            var cards = instanceList.querySelectorAll('.pb-instance-card');
            cards.forEach(function(c) {
                c.classList.remove('pb-dragging');
                c.classList.remove('pb-drag-over');
            });
        }
        dragSrcIndex = null;
    }

    // -----------------------------------------------------------------------
    // Utilities
    // -----------------------------------------------------------------------
    function escHtml(str) {
        if (typeof str !== 'string') return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function escAttr(str) {
        if (typeof str !== 'string') return '';
        return str.replace(/&/g, '&amp;')
                  .replace(/"/g, '&quot;')
                  .replace(/</g, '&lt;')
                  .replace(/>/g, '&gt;');
    }

    // -----------------------------------------------------------------------
    // Global entry point
    // -----------------------------------------------------------------------
    window.initPageBuilder = initPageBuilder;

})();

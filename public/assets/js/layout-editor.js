/**
 * Layout Editor — header/footer toggles + block management
 */
(function () {
    'use strict';

    // ── Header / Footer visibility & mode toggles ───────────────────

    var headerVisible = document.getElementById('header_visible');
    var headerOptions = document.getElementById('header-options');
    if (headerVisible && headerOptions) {
        function toggleHeaderOptions() {
            headerOptions.style.display = headerVisible.checked ? '' : 'none';
        }
        headerVisible.addEventListener('change', toggleHeaderOptions);
        toggleHeaderOptions();
    }

    var footerVisible = document.getElementById('footer_visible');
    var footerOptions = document.getElementById('footer-options');
    if (footerVisible && footerOptions) {
        function toggleFooterOptions() {
            footerOptions.style.display = footerVisible.checked ? '' : 'none';
        }
        footerVisible.addEventListener('change', toggleFooterOptions);
        toggleFooterOptions();
    }

    var headerPicker = document.getElementById('header-element-picker');
    if (headerPicker) {
        document.querySelectorAll('input[name="header_mode"]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                headerPicker.style.display = this.value === 'block' ? '' : 'none';
            });
        });
    }

    var footerPicker = document.getElementById('footer-element-picker');
    if (footerPicker) {
        document.querySelectorAll('input[name="footer_mode"]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                footerPicker.style.display = this.value === 'block' ? '' : 'none';
            });
        });
    }

    // ── Block Management ────────────────────────────────────────────

    var blocksContainer = document.getElementById('blocks-container');
    var addBlockBtn = document.getElementById('add-block-btn');
    var blocksJsonInput = document.getElementById('blocks_json');

    if (!blocksContainer || !addBlockBtn || !blocksJsonInput) {
        return;
    }

    var blocks = [];
    var nextTempId = 1;

    // Column options (1-12)
    var columnOptions = [];
    for (var c = 1; c <= 12; c++) {
        columnOptions.push({ value: c, label: c + '' });
    }

    // Width percent options
    var widthOptions = [
        { value: 100, label: '100%' },
        { value: 90,  label: '90%' },
        { value: 80,  label: '80%' },
        { value: 70,  label: '70%' },
        { value: 60,  label: '60%' },
        { value: 50,  label: '50%' },
        { value: 40,  label: '40%' },
        { value: 30,  label: '30%' },
        { value: 20,  label: '20%' },
        { value: 10,  label: '10%' }
    ];

    var alignmentOptions = [
        { value: 'center', label: 'Center' },
        { value: 'left',   label: 'Left' },
        { value: 'right',  label: 'Right' }
    ];

    var displayOptions = [
        { value: 'flex',  label: 'Flex' },
        { value: 'block', label: 'Block' },
        { value: 'grid',  label: 'Grid' }
    ];

    function initBlocks() {
        var raw = blocksContainer.getAttribute('data-blocks') || '[]';
        try {
            var existing = JSON.parse(raw);
        } catch (e) {
            existing = [];
        }
        if (!Array.isArray(existing)) existing = [];

        blocks = [];
        for (var i = 0; i < existing.length; i++) {
            var b = existing[i];
            blocks.push({
                tempId: nextTempId++,
                name: b.name || ('Block ' + (i + 1)),
                columns: parseInt(b.columns, 10) || 1,
                width_percent: parseInt(b.width_percent, 10) || 100,
                alignment: b.alignment || 'center',
                display_mode: b.display_mode || 'flex',
                collapsed: false
            });
        }
        renderBlocks();
    }

    function addBlock() {
        blocks.push({
            tempId: nextTempId++,
            name: 'Block ' + (blocks.length + 1),
            columns: 1,
            width_percent: 100,
            alignment: 'center',
            display_mode: 'flex',
            collapsed: false
        });
        renderBlocks();
    }

    function removeBlock(tempId) {
        if (!confirm('Remove this block?')) return;
        blocks = blocks.filter(function (b) { return b.tempId !== tempId; });
        renderBlocks();
    }

    function toggleCollapse(tempId) {
        for (var i = 0; i < blocks.length; i++) {
            if (blocks[i].tempId === tempId) {
                blocks[i].collapsed = !blocks[i].collapsed;
                break;
            }
        }
        renderBlocks();
    }

    function renderBlocks() {
        readBlocksFromDOM();
        blocksContainer.innerHTML = '';

        for (var i = 0; i < blocks.length; i++) {
            var block = blocks[i];
            var item = document.createElement('div');
            item.className = 'block-item';
            item.setAttribute('data-temp-id', block.tempId);
            item.setAttribute('draggable', 'true');

            // Header row
            var header = document.createElement('div');
            header.className = 'block-item-header';

            var dragHandle = document.createElement('span');
            dragHandle.className = 'block-drag-handle';
            dragHandle.textContent = '\u2261';
            dragHandle.title = 'Drag to reorder';
            header.appendChild(dragHandle);

            var nameInput = document.createElement('input');
            nameInput.type = 'text';
            nameInput.className = 'block-name-input';
            nameInput.value = block.name;
            nameInput.placeholder = 'Block name';
            nameInput.maxLength = 200;
            header.appendChild(nameInput);

            var collapseBtn = document.createElement('button');
            collapseBtn.type = 'button';
            collapseBtn.className = 'block-collapse-btn';
            collapseBtn.textContent = block.collapsed ? '\u25B6' : '\u25BC';
            collapseBtn.title = 'Toggle details';
            collapseBtn.setAttribute('data-temp-id', block.tempId);
            header.appendChild(collapseBtn);

            var deleteBtn = document.createElement('button');
            deleteBtn.type = 'button';
            deleteBtn.className = 'block-delete-btn';
            deleteBtn.textContent = '\u00D7';
            deleteBtn.title = 'Remove block';
            deleteBtn.setAttribute('data-temp-id', block.tempId);
            header.appendChild(deleteBtn);

            item.appendChild(header);

            // Body row (controls)
            var body = document.createElement('div');
            body.className = 'block-item-body' + (block.collapsed ? ' collapsed' : '');

            body.appendChild(createSelectField('Columns', 'block-columns', block.columns, columnOptions));
            body.appendChild(createSelectField('Width', 'block-width', block.width_percent, widthOptions));
            body.appendChild(createSelectField('Align', 'block-alignment', block.alignment, alignmentOptions));
            body.appendChild(createSelectField('Display', 'block-display', block.display_mode, displayOptions));

            item.appendChild(body);
            blocksContainer.appendChild(item);
        }

        serializeBlocks();
    }

    function createSelectField(labelText, className, currentValue, options) {
        var wrap = document.createElement('div');

        var label = document.createElement('label');
        label.textContent = labelText;
        wrap.appendChild(label);

        var select = document.createElement('select');
        select.className = className;
        for (var i = 0; i < options.length; i++) {
            var opt = document.createElement('option');
            opt.value = options[i].value;
            opt.textContent = options[i].label;
            if (String(options[i].value) === String(currentValue)) {
                opt.selected = true;
            }
            select.appendChild(opt);
        }
        wrap.appendChild(select);
        return wrap;
    }

    function readBlocksFromDOM() {
        var items = blocksContainer.querySelectorAll('.block-item');
        if (items.length === 0) return;

        var tempIdToBlock = {};
        for (var i = 0; i < blocks.length; i++) {
            tempIdToBlock[blocks[i].tempId] = blocks[i];
        }

        var newOrder = [];
        for (var j = 0; j < items.length; j++) {
            var tid = parseInt(items[j].getAttribute('data-temp-id'), 10);
            var b = tempIdToBlock[tid];
            if (!b) continue;

            var nameEl = items[j].querySelector('.block-name-input');
            if (nameEl) b.name = nameEl.value;

            var colEl = items[j].querySelector('.block-columns');
            if (colEl) b.columns = parseInt(colEl.value, 10);

            var widEl = items[j].querySelector('.block-width');
            if (widEl) b.width_percent = parseInt(widEl.value, 10);

            var aliEl = items[j].querySelector('.block-alignment');
            if (aliEl) b.alignment = aliEl.value;

            var disEl = items[j].querySelector('.block-display');
            if (disEl) b.display_mode = disEl.value;

            newOrder.push(b);
        }
        blocks = newOrder;
    }

    function serializeBlocks() {
        readBlocksFromDOM();
        var out = [];
        for (var i = 0; i < blocks.length; i++) {
            out.push({
                name: blocks[i].name || ('Block ' + (i + 1)),
                sort_order: i,
                columns: blocks[i].columns,
                width_percent: blocks[i].width_percent,
                alignment: blocks[i].alignment,
                display_mode: blocks[i].display_mode
            });
        }
        blocksJsonInput.value = JSON.stringify(out);
    }

    // ── Event Delegation ────────────────────────────────────────────

    blocksContainer.addEventListener('click', function (e) {
        var btn = e.target.closest('.block-collapse-btn');
        if (btn) {
            toggleCollapse(parseInt(btn.getAttribute('data-temp-id'), 10));
            return;
        }
        var del = e.target.closest('.block-delete-btn');
        if (del) {
            removeBlock(parseInt(del.getAttribute('data-temp-id'), 10));
            return;
        }
    });

    addBlockBtn.addEventListener('click', addBlock);

    // Serialize before form submit
    var form = blocksContainer.closest('form');
    if (form) {
        form.addEventListener('submit', function () {
            serializeBlocks();
        });
    }

    // ── Drag-and-Drop Reorder ───────────────────────────────────────

    var dragItem = null;

    blocksContainer.addEventListener('dragstart', function (e) {
        var item = e.target.closest('.block-item');
        if (!item) return;
        dragItem = item;
        item.style.opacity = '0.5';
        e.dataTransfer.effectAllowed = 'move';
    });

    blocksContainer.addEventListener('dragend', function (e) {
        if (dragItem) {
            dragItem.style.opacity = '';
            dragItem = null;
        }
        blocksContainer.querySelectorAll('.block-item').forEach(function (el) {
            el.classList.remove('block-drag-over');
        });
    });

    blocksContainer.addEventListener('dragover', function (e) {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        var target = e.target.closest('.block-item');
        if (target && target !== dragItem) {
            blocksContainer.querySelectorAll('.block-item').forEach(function (el) {
                el.classList.remove('block-drag-over');
            });
            target.classList.add('block-drag-over');
        }
    });

    blocksContainer.addEventListener('drop', function (e) {
        e.preventDefault();
        var target = e.target.closest('.block-item');
        if (!target || !dragItem || target === dragItem) return;

        // Read current DOM order before rearranging
        readBlocksFromDOM();

        // Move in DOM
        var items = Array.from(blocksContainer.querySelectorAll('.block-item'));
        var dragIdx = items.indexOf(dragItem);
        var dropIdx = items.indexOf(target);
        if (dragIdx < dropIdx) {
            target.parentNode.insertBefore(dragItem, target.nextSibling);
        } else {
            target.parentNode.insertBefore(dragItem, target);
        }

        // Re-read order from new DOM
        readBlocksFromDOM();
        serializeBlocks();

        target.classList.remove('block-drag-over');
    });

    // ── Initialize ──────────────────────────────────────────────────

    initBlocks();
})();

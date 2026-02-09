/**
 * LiteCMS Page Layout Styles
 * Handles page-level style controls (page-body, container, site-main) in the sidebar card.
 */
(function() {
    'use strict';

    var pageStylesData = {};
    var currentTarget = 'page_body';
    var controlsContainer = null;
    var hiddenInput = null;

    function initPageStyles() {
        controlsContainer = document.getElementById('page-style-controls');
        hiddenInput = document.getElementById('page-styles-json');
        var targetSelect = document.getElementById('page-style-target');

        if (!controlsContainer || !hiddenInput) return;

        // Parse existing data
        try {
            pageStylesData = JSON.parse(hiddenInput.value || '{}');
        } catch (e) {
            pageStylesData = {};
        }

        // Build controls for default target
        buildControls(currentTarget);

        // Target selector change
        if (targetSelect) {
            targetSelect.addEventListener('change', function() {
                readCurrentTargetFromDOM();
                currentTarget = targetSelect.value;
                buildControls(currentTarget);
            });
        }

        // Serialize on form submit
        var form = hiddenInput.closest('form');
        if (form) {
            form.addEventListener('submit', function() {
                readCurrentTargetFromDOM();
                hiddenInput.value = JSON.stringify(pageStylesData);
            });
        }
    }

    function buildControls(target) {
        if (!controlsContainer) return;
        controlsContainer.innerHTML = '';

        var data = pageStylesData[target] || {};

        // Background
        controlsContainer.appendChild(makeGroup('Background', function(content) {
            content.appendChild(makeColorRow('Color', 'bg_color', data));
        }));

        // Spacing
        controlsContainer.appendChild(makeGroup('Spacing', function(content) {
            content.appendChild(makeNumRow('Padding Top', 'padding_top', data));
            content.appendChild(makeNumRow('Padding Bottom', 'padding_bottom', data));
            content.appendChild(makeNumRow('Margin Top', 'margin_top', data));
            content.appendChild(makeNumRow('Margin Bottom', 'margin_bottom', data));
        }));

        // Typography
        controlsContainer.appendChild(makeGroup('Typography', function(content) {
            content.appendChild(makeColorRow('Text Color', 'text_color', data));
        }));

        // Layout
        controlsContainer.appendChild(makeGroup('Layout', function(content) {
            content.appendChild(makeTextRow('Max Width', 'max_width', data, 'e.g. 1200px'));
        }));

        // Custom CSS
        controlsContainer.appendChild(makeGroup('Custom CSS', function(content) {
            var wrapper = document.createElement('div');
            wrapper.className = 'pb-custom-css';
            var ta = document.createElement('textarea');
            ta.className = 'ps-input';
            ta.setAttribute('data-ps-key', 'custom_css');
            ta.placeholder = '/* CSS for ' + target.replace('_', '-') + ' */';
            ta.value = data.custom_css || '';
            wrapper.appendChild(ta);
            var hint = document.createElement('div');
            hint.className = 'pb-css-hint';
            hint.textContent = 'Selectors scoped to .' + target.replace('_', '-');
            wrapper.appendChild(hint);
            content.appendChild(wrapper);
        }));
    }

    function makeGroup(title, buildFn) {
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

    function makeColorRow(label, key, data) {
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
        textInp.className = 'ps-input';
        textInp.setAttribute('data-ps-key', key);
        textInp.value = data[key] || '';
        textInp.placeholder = '#000000';
        colorInp.addEventListener('input', function() { textInp.value = colorInp.value; });
        textInp.addEventListener('input', function() {
            if (/^#[0-9a-fA-F]{6}$/.test(textInp.value)) colorInp.value = textInp.value;
        });
        wrapper.appendChild(colorInp);
        wrapper.appendChild(textInp);
        row.appendChild(wrapper);
        return row;
    }

    function makeNumRow(label, key, data) {
        var row = document.createElement('div');
        row.className = 'pb-style-row';
        var lbl = document.createElement('label');
        lbl.textContent = label;
        row.appendChild(lbl);
        var inp = document.createElement('input');
        inp.type = 'number';
        inp.className = 'ps-input';
        inp.setAttribute('data-ps-key', key);
        inp.value = data[key] || '';
        row.appendChild(inp);
        return row;
    }

    function makeTextRow(label, key, data, placeholder) {
        var row = document.createElement('div');
        row.className = 'pb-style-row';
        var lbl = document.createElement('label');
        lbl.textContent = label;
        row.appendChild(lbl);
        var inp = document.createElement('input');
        inp.type = 'text';
        inp.className = 'ps-input';
        inp.setAttribute('data-ps-key', key);
        inp.value = data[key] || '';
        inp.placeholder = placeholder || '';
        row.appendChild(inp);
        return row;
    }

    function readCurrentTargetFromDOM() {
        if (!controlsContainer) return;
        var inputs = controlsContainer.querySelectorAll('.ps-input');
        var data = {};
        inputs.forEach(function(inp) {
            var key = inp.getAttribute('data-ps-key');
            if (!key) return;
            data[key] = inp.value;
        });
        pageStylesData[currentTarget] = data;
    }

    // Init when DOM is ready
    document.addEventListener('DOMContentLoaded', initPageStyles);
})();

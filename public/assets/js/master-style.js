/**
 * Master Style — Live Preview Controller
 * Updates the preview panel in real-time as style controls change.
 */
(function() {
    'use strict';

    var preview   = document.getElementById('stylePreview');
    var spHeader  = document.getElementById('spHeader');
    var spContent = document.getElementById('spContent');
    var spFooter  = document.getElementById('spFooter');
    var spCard    = document.getElementById('spCard');
    var spButton  = document.getElementById('spButton');
    var form      = document.getElementById('styleForm');

    if (!preview || !form) return;

    var fontStacks    = window.STYLE_FONT_STACKS || {};
    var shadowPresets = window.STYLE_SHADOW_PRESETS || {};
    var googleFonts   = window.STYLE_GOOGLE_FONTS || {};

    // Track loaded Google Fonts to avoid duplicate <link> tags
    var loadedGoogleFonts = {};

    // --- Color hex display updater ---
    function updateColorHex(input) {
        var hex = input.parentElement.querySelector('.color-hex');
        if (hex) hex.textContent = input.value;
    }

    // --- Darken a hex color by a percentage ---
    function darkenColor(hex, percent) {
        hex = hex.replace('#', '');
        var r = Math.max(0, Math.round(parseInt(hex.substring(0, 2), 16) * (1 - percent / 100)));
        var g = Math.max(0, Math.round(parseInt(hex.substring(2, 4), 16) * (1 - percent / 100)));
        var b = Math.max(0, Math.round(parseInt(hex.substring(4, 6), 16) * (1 - percent / 100)));
        return '#' + ((1 << 24) + (r << 16) + (g << 8) + b).toString(16).slice(1);
    }

    // --- Load a Google Font dynamically ---
    function loadGoogleFont(fontKey) {
        if (!googleFonts[fontKey] || loadedGoogleFonts[fontKey]) return;
        var family = googleFonts[fontKey];
        var link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = 'https://fonts.googleapis.com/css2?family=' + family + ':wght@400;500;600;700;800;900&display=swap';
        document.head.appendChild(link);
        loadedGoogleFonts[fontKey] = true;
    }

    // --- Apply all current values to the preview ---
    function updatePreview() {
        // Colors with direct CSS variable mapping
        var colorInputs = form.querySelectorAll('input[type="color"][data-css-var]');
        for (var i = 0; i < colorInputs.length; i++) {
            var input = colorInputs[i];
            var cssVar = input.getAttribute('data-css-var');
            if (cssVar) {
                preview.style.setProperty(cssVar, input.value);
            }
        }

        // Apply CSS variables to preview elements
        var colorPrimary    = getVal('style_color_primary');
        var colorText       = getVal('style_color_text');
        var colorTextMuted  = getVal('style_color_text_muted');
        var colorBg         = getVal('style_color_bg');
        var colorBgAlt      = getVal('style_color_bg_alt');
        var colorBorder     = getVal('style_color_border');
        var colorLink       = getVal('style_color_link');

        // Preview background
        preview.style.background = colorBg;
        preview.style.color = colorText;

        // Content area
        spContent.style.color = colorText;

        // Links
        var links = preview.querySelectorAll('.sp-link');
        for (var j = 0; j < links.length; j++) {
            links[j].style.color = colorLink;
        }

        // Muted text
        var muted = preview.querySelectorAll('.sp-muted');
        for (var k = 0; k < muted.length; k++) {
            muted[k].style.color = colorTextMuted;
        }

        // Card
        spCard.style.background = colorBgAlt;
        spCard.style.borderColor = colorBorder;

        // Button
        spButton.style.background = colorPrimary;

        // Borders
        spHeader.style.borderBottomColor = colorBorder;
        spFooter.style.borderTopColor = colorBorder;

        // Header & Footer (component-level colors)
        var headerBg   = getVal('style_color_header_bg');
        var headerText = getVal('style_color_header_text');
        var footerBg   = getVal('style_color_footer_bg');
        var footerText = getVal('style_color_footer_text');

        spHeader.style.background = headerBg;
        spHeader.style.color = headerText;
        var navLinks = spHeader.querySelectorAll('.sp-nav a');
        for (var n = 0; n < navLinks.length; n++) {
            navLinks[n].style.color = headerText;
        }
        spHeader.querySelector('.sp-logo').style.color = headerText;

        spFooter.style.background = footerBg;
        spFooter.style.color = footerText;

        // Typography
        var fontFamilyKey = getSelectVal('style_font_family');
        var fontHeadingKey = getSelectVal('style_font_heading');
        var fontSizeBase = getSelectVal('style_font_size_base');
        var lineHeight = getVal('style_line_height');
        var headingWeight = getSelectVal('style_heading_weight');

        // Load Google Fonts if needed
        if (fontFamilyKey && fontFamilyKey.indexOf('google_') === 0) {
            loadGoogleFont(fontFamilyKey);
        }
        if (fontHeadingKey && fontHeadingKey.indexOf('google_') === 0) {
            loadGoogleFont(fontHeadingKey);
        }

        // Apply body font
        var bodyStack = fontStacks[fontFamilyKey] || fontStacks['system_ui'];
        preview.style.fontFamily = bodyStack;
        preview.style.fontSize = fontSizeBase;
        preview.style.lineHeight = lineHeight;

        // Apply heading font
        var headingStack = fontStacks[fontHeadingKey] || bodyStack;
        var headings = preview.querySelectorAll('.sp-h1, .sp-h2, .sp-h3');
        for (var h = 0; h < headings.length; h++) {
            headings[h].style.fontFamily = headingStack;
            headings[h].style.fontWeight = headingWeight;
        }

        // Shadows
        var shadowKey = getSelectVal('style_shadow');
        var shadowValues = shadowPresets[shadowKey] || shadowPresets['subtle'];
        spCard.style.boxShadow = shadowValues[0];

        // Auto-derive hover
        var autoDerive = document.getElementById('style_auto_derive_hover');
        var hoverInput = document.getElementById('style_color_primary_hover');
        if (autoDerive && autoDerive.checked && hoverInput) {
            var derived = darkenColor(colorPrimary, 12);
            hoverInput.value = derived;
            updateColorHex(hoverInput);
        }
    }

    // Helper: get value of an input by name
    function getVal(name) {
        var el = form.querySelector('[name="' + name + '"]');
        return el ? el.value : '';
    }

    // Helper: get value of a select by name
    function getSelectVal(name) {
        var el = form.querySelector('select[name="' + name + '"]');
        return el ? el.value : '';
    }

    // --- Bind events ---
    // Color inputs: update on input (real-time as picker moves)
    var allColorInputs = form.querySelectorAll('input[type="color"]');
    for (var c = 0; c < allColorInputs.length; c++) {
        allColorInputs[c].addEventListener('input', function() {
            updateColorHex(this);
            updatePreview();
        });
    }

    // Select/number inputs: update on change
    var allSelects = form.querySelectorAll('select, input[type="number"]');
    for (var s = 0; s < allSelects.length; s++) {
        allSelects[s].addEventListener('change', function() {
            updatePreview();
        });
        allSelects[s].addEventListener('input', function() {
            updatePreview();
        });
    }

    // Auto-derive checkbox
    var autoDeriveCheckbox = document.getElementById('style_auto_derive_hover');
    var hoverColorInput = document.getElementById('style_color_primary_hover');
    if (autoDeriveCheckbox) {
        autoDeriveCheckbox.addEventListener('change', function() {
            if (hoverColorInput) {
                hoverColorInput.disabled = this.checked;
            }
            updatePreview();
        });
    }

    // Reset button
    var resetBtn = document.getElementById('resetBtn');
    if (resetBtn) {
        resetBtn.addEventListener('click', function() {
            if (confirm('Reset all styles to defaults? This will discard any saved customizations.')) {
                document.getElementById('resetForm').submit();
            }
        });
    }

    // Initial preview update
    updatePreview();

})();

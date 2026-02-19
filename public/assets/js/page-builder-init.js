/**
 * LiteCMS Page Builder Initialization
 * Reads config from data attributes and calls initPageBuilder.
 * Mode toggle is handled by design-mode-init.js (supports html/elements/design).
 */
document.addEventListener('DOMContentLoaded', function() {
    var builderPanel = document.getElementById('page-builder-panel');

    // Read config from data attributes on the builder panel
    if (builderPanel && typeof initPageBuilder === 'function') {
        var raw = builderPanel.getAttribute('data-instances') || '[]';
        var csrf = builderPanel.getAttribute('data-csrf') || '';
        var existingInstances = [];
        try {
            existingInstances = JSON.parse(raw);
        } catch (e) {
            existingInstances = [];
        }

        var templateBlocks = [];
        var blocksRaw = builderPanel.getAttribute('data-template-blocks') || '[]';
        try {
            templateBlocks = JSON.parse(blocksRaw);
        } catch (e) {
            templateBlocks = [];
        }

        initPageBuilder(existingInstances, csrf, templateBlocks);
    }
});

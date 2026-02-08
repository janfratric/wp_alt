/**
 * LiteCMS Page Builder Initialization
 * Reads config from data attributes and wires up mode toggle + initPageBuilder.
 */
document.addEventListener('DOMContentLoaded', function() {
    var htmlPanel = document.getElementById('html-editor-panel');
    var builderPanel = document.getElementById('page-builder-panel');
    var modeRadios = document.querySelectorAll('input[name="editor_mode"]');

    // Editor mode toggle
    modeRadios.forEach(function(radio) {
        radio.addEventListener('change', function() {
            if (this.value === 'html') {
                htmlPanel.classList.remove('hidden');
                builderPanel.classList.add('hidden');
            } else {
                htmlPanel.classList.add('hidden');
                builderPanel.classList.remove('hidden');
            }
        });
    });

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
        initPageBuilder(existingInstances, csrf);
    }
});

/**
 * Content Type Editor — slug auto-generation, field builder init, delete handling.
 */
(function() {
    'use strict';

    // Initialize field builder from hidden input value
    var jsonInput = document.getElementById('fields-json-input');
    var initialFields = [];
    try {
        initialFields = JSON.parse(jsonInput.value || '[]');
    } catch (e) {
        initialFields = [];
    }
    if (typeof initFieldBuilder === 'function') {
        initFieldBuilder(initialFields);
    }

    // Auto-generate slug from name
    var nameInput = document.getElementById('ct-name');
    var slugInput = document.getElementById('ct-slug');
    var slugManuallyEdited = slugInput.value !== '';
    nameInput.addEventListener('input', function() {
        if (!slugManuallyEdited) {
            slugInput.value = nameInput.value.toLowerCase()
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/-+/g, '-')
                .replace(/^-|-$/g, '');
        }
    });
    slugInput.addEventListener('input', function() {
        slugManuallyEdited = slugInput.value !== '';
    });

    // Delete type button
    var deleteBtn = document.getElementById('delete-type-btn');
    if (deleteBtn) {
        deleteBtn.addEventListener('click', function() {
            if (confirm('Delete this content type? This cannot be undone.')) {
                document.getElementById('delete-type-form').submit();
            }
        });
    }
})();

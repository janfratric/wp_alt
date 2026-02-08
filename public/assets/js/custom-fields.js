/**
 * Custom Fields — image browse/remove handlers and type-change reload for content editor.
 */
(function() {
    'use strict';

    // Custom field image browse buttons — reuse the media modal from editor.js
    document.querySelectorAll('.cf-image-browse').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var fieldKey = this.getAttribute('data-field');
            var input = document.getElementById('cf_' + fieldKey);
            var preview = document.getElementById('cf_preview_' + fieldKey);
            var removeBtn = this.parentNode.querySelector('.cf-image-remove');

            if (typeof window.openMediaBrowser === 'function') {
                window.openMediaBrowser(function(url) {
                    input.value = url;
                    preview.src = url;
                    preview.style.display = '';
                    removeBtn.style.display = '';
                });
            }
        });
    });

    // Custom field image remove buttons
    document.querySelectorAll('.cf-image-remove').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var fieldKey = this.getAttribute('data-field');
            var input = document.getElementById('cf_' + fieldKey);
            var preview = document.getElementById('cf_preview_' + fieldKey);
            input.value = '';
            preview.style.display = 'none';
            this.style.display = 'none';
        });
    });

    // Reload page with correct type when type dropdown changes (create mode only)
    var typeSelect = document.getElementById('type');
    if (typeSelect && window.location.pathname === '/admin/content/create') {
        typeSelect.addEventListener('change', function() {
            window.location.href = '/admin/content/create?type=' + encodeURIComponent(this.value);
        });
    }
})();

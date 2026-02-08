/**
 * Content Type List — delete button handling.
 */
(function() {
    'use strict';

    document.querySelectorAll('.delete-ct-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var name = this.getAttribute('data-name');
            if (confirm('Delete content type "' + name + '"? This cannot be undone.')) {
                var form = document.getElementById('delete-ct-form');
                form.action = '/admin/content-types/' + this.getAttribute('data-id');
                form.submit();
            }
        });
    });
})();

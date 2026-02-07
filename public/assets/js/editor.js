/**
 * LiteCMS Content Editor
 * Handles: TinyMCE WYSIWYG, slug auto-generation, select-all checkbox
 */
document.addEventListener('DOMContentLoaded', function() {

    // --- TinyMCE Initialization ---
    var bodyField = document.getElementById('body');
    if (bodyField) {
        var script = document.createElement('script');
        script.src = 'https://cdn.jsdelivr.net/npm/tinymce@6/tinymce.min.js';
        script.onload = function() {
            tinymce.init({
                selector: '#body',
                base_url: 'https://cdn.jsdelivr.net/npm/tinymce@6',
                suffix: '.min',
                height: 500,
                menubar: true,
                plugins: [
                    'advlist', 'autolink', 'lists', 'link', 'image', 'charmap',
                    'preview', 'anchor', 'searchreplace', 'visualblocks', 'code',
                    'fullscreen', 'insertdatetime', 'media', 'table', 'help', 'wordcount'
                ],
                toolbar: 'undo redo | blocks | bold italic forecolor | '
                    + 'alignleft aligncenter alignright alignjust | '
                    + 'bullist numlist outdent indent | removeformat | code | help',
                content_style: 'body { font-family: -apple-system, BlinkMacSystemFont, '
                    + '"Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; '
                    + 'font-size: 16px; line-height: 1.6; max-width: 100%; }',
                promotion: false,
                branding: false,
                setup: function(editor) {
                    editor.on('change', function() {
                        editor.save();
                    });
                }
            });
        };
        document.head.appendChild(script);

        // Sync TinyMCE to textarea on form submit
        var form = document.getElementById('content-form');
        if (form) {
            form.addEventListener('submit', function() {
                if (typeof tinymce !== 'undefined') {
                    tinymce.triggerSave();
                }
            });
        }
    }

    // --- Slug Auto-Generation ---
    var titleField = document.getElementById('title');
    var slugField = document.getElementById('slug');
    var slugManuallyEdited = false;

    if (slugField && slugField.value.trim() !== '') {
        slugManuallyEdited = true;
    }

    if (slugField) {
        slugField.addEventListener('input', function() {
            slugManuallyEdited = true;
        });
    }

    if (titleField && slugField) {
        titleField.addEventListener('input', function() {
            if (!slugManuallyEdited) {
                slugField.value = generateSlug(titleField.value);
            }
        });
    }

    function generateSlug(text) {
        return text
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '')
            .replace(/-{2,}/g, '-');
    }

    // --- Select All Checkbox ---
    var selectAll = document.getElementById('select-all');
    if (selectAll) {
        selectAll.addEventListener('change', function() {
            var checkboxes = document.querySelectorAll('input[name="ids[]"]');
            for (var i = 0; i < checkboxes.length; i++) {
                checkboxes[i].checked = selectAll.checked;
            }
        });
    }

    // --- Delete Buttons (standalone form to avoid nested-form bug) ---
    // Note: admin.js handles data-confirm for all other buttons (e.g. bulk Apply).
    // Delete buttons don't use data-confirm — this handler manages its own confirm().
    var deleteForm = document.getElementById('delete-form');
    var deleteButtons = document.querySelectorAll('.delete-btn');
    for (var i = 0; i < deleteButtons.length; i++) {
        deleteButtons[i].addEventListener('click', function() {
            if (confirm('Are you sure you want to delete this content?')) {
                deleteForm.action = '/admin/content/' + this.getAttribute('data-id');
                deleteForm.submit();
            }
        });
    }
});

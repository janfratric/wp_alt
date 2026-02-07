/**
 * LiteCMS Content Editor
 * Handles: TinyMCE WYSIWYG, slug auto-generation, select-all checkbox, media browser
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
                    + 'bullist numlist outdent indent | mediabrowser image link | removeformat code help',
                content_style: 'body { font-family: -apple-system, BlinkMacSystemFont, '
                    + '"Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; '
                    + 'font-size: 16px; line-height: 1.6; max-width: 100%; }',
                promotion: false,
                branding: false,
                toolbar_mode: 'wrap',
                file_picker_types: 'image',
                file_picker_callback: function(callback, value, meta) {
                    if (meta.filetype === 'image') {
                        openMediaBrowser(function(url) {
                            callback(url, { alt: '' });
                        });
                    }
                },
                images_upload_handler: function(blobInfo) {
                    return new Promise(function(resolve, reject) {
                        var csrfInput = document.querySelector('input[name="_csrf_token"]');
                        var formData = new FormData();
                        formData.append('file', blobInfo.blob(), blobInfo.filename());
                        if (csrfInput) {
                            formData.append('_csrf_token', csrfInput.value);
                        }

                        fetch('/admin/media/upload', {
                            method: 'POST',
                            headers: { 'X-Requested-With': 'XMLHttpRequest' },
                            body: formData
                        })
                        .then(function(res) { return res.json(); })
                        .then(function(data) {
                            if (data.success) {
                                resolve(data.url);
                            } else {
                                reject('Upload failed: ' + (data.error || 'Unknown error'));
                            }
                        })
                        .catch(function() { reject('Upload failed: Network error'); });
                    });
                },
                setup: function(editor) {
                    editor.on('change', function() {
                        editor.save();
                    });
                    // Media browser toolbar button
                    editor.ui.registry.addButton('mediabrowser', {
                        icon: 'image',
                        tooltip: 'Insert from Media Library',
                        onAction: function() {
                            openMediaBrowser(function(url) {
                                editor.insertContent('<img src="' + url + '" alt="" />');
                            });
                        }
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
    var deleteForm = document.getElementById('delete-form');
    var deleteButtons = document.querySelectorAll('.delete-btn');
    for (var i = 0; i < deleteButtons.length; i++) {
        deleteButtons[i].addEventListener('click', function() {
            var msg = this.getAttribute('data-confirm');
            if (!msg || confirm(msg)) {
                deleteForm.action = '/admin/content/' + this.getAttribute('data-id');
                deleteForm.submit();
            }
        });
    }

    // --- Confirm Dialogs (for bulk apply, etc.) ---
    var confirmButtons = document.querySelectorAll('button[data-confirm]:not(.delete-btn)');
    for (var i = 0; i < confirmButtons.length; i++) {
        confirmButtons[i].addEventListener('click', function(e) {
            if (!confirm(this.getAttribute('data-confirm'))) {
                e.preventDefault();
            }
        });
    }

    // --- Media Browser Modal ---
    function openMediaBrowser(callback) {
        var overlay = document.getElementById('media-modal-overlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.id = 'media-modal-overlay';
            overlay.className = 'media-modal-overlay';
            overlay.innerHTML =
                '<div class="media-modal">'
                + '<div class="media-modal-header">'
                + '<span>Select Media</span>'
                + '<button class="media-modal-close">&times;</button>'
                + '</div>'
                + '<div class="media-modal-body">'
                + '<div class="media-modal-grid" id="media-modal-grid"></div>'
                + '<div id="media-modal-loading" class="text-center text-muted" style="padding:2rem;">Loading...</div>'
                + '</div>'
                + '<div class="media-modal-footer">'
                + '<button class="btn" id="media-modal-cancel">Cancel</button>'
                + '<button class="btn btn-primary" id="media-modal-select" disabled>Select</button>'
                + '</div>'
                + '</div>';
            document.body.appendChild(overlay);

            overlay.querySelector('.media-modal-close').addEventListener('click', closeMediaBrowser);
            document.getElementById('media-modal-cancel').addEventListener('click', closeMediaBrowser);
            overlay.addEventListener('click', function(e) {
                if (e.target === overlay) closeMediaBrowser();
            });
        }

        overlay.classList.add('active');
        var grid = document.getElementById('media-modal-grid');
        var loading = document.getElementById('media-modal-loading');
        var selectBtn = document.getElementById('media-modal-select');
        var selectedUrl = null;

        grid.innerHTML = '';
        loading.style.display = 'block';
        selectBtn.disabled = true;

        fetch('/admin/media/browse?type=image&page=1', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            loading.style.display = 'none';
            if (!data.items || data.items.length === 0) {
                grid.innerHTML = '<p class="text-muted" style="grid-column:1/-1;text-align:center;padding:2rem;">No images found. Upload some first.</p>';
                return;
            }
            data.items.forEach(function(item) {
                var el = document.createElement('div');
                el.className = 'media-modal-item';
                el.innerHTML = '<img src="' + item.url + '" alt="" loading="lazy">';
                el.addEventListener('click', function() {
                    grid.querySelectorAll('.media-modal-item.selected').forEach(function(s) {
                        s.classList.remove('selected');
                    });
                    el.classList.add('selected');
                    selectedUrl = item.url;
                    selectBtn.disabled = false;
                });
                grid.appendChild(el);
            });
        })
        .catch(function() {
            loading.style.display = 'none';
            grid.innerHTML = '<p class="text-muted" style="grid-column:1/-1;text-align:center;">Failed to load media.</p>';
        });

        selectBtn.onclick = function() {
            if (selectedUrl && callback) {
                callback(selectedUrl);
            }
            closeMediaBrowser();
        };
    }

    function closeMediaBrowser() {
        var overlay = document.getElementById('media-modal-overlay');
        if (overlay) overlay.classList.remove('active');
    }

    // --- Featured Image Picker ---
    var browseBtn = document.getElementById('featured-image-browse');
    var removeBtn = document.getElementById('featured-image-remove');
    var featuredInput = document.getElementById('featured_image');
    var featuredPreview = document.getElementById('featured-image-preview-img');

    if (browseBtn) {
        browseBtn.addEventListener('click', function() {
            openMediaBrowser(function(url) {
                featuredInput.value = url;
                if (featuredPreview) {
                    featuredPreview.src = url;
                    featuredPreview.parentElement.style.display = 'block';
                }
                if (removeBtn) {
                    removeBtn.style.display = '';
                }
            });
        });
    }

    if (removeBtn) {
        removeBtn.addEventListener('click', function() {
            featuredInput.value = '';
            if (featuredPreview) {
                featuredPreview.parentElement.style.display = 'none';
            }
            removeBtn.style.display = 'none';
        });
    }
});

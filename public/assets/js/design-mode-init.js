/**
 * LiteCMS Design Mode Initialization
 * Handles 3-way mode toggle (html/elements/design), embedded Pencil editor iframe,
 * design file selection, preview panel, and reconvert workflow.
 */
document.addEventListener('DOMContentLoaded', function() {
    var htmlPanel = document.getElementById('html-editor-panel');
    var builderPanel = document.getElementById('page-builder-panel');
    var designPanel = document.getElementById('design-editor-panel');
    var modeRadios = document.querySelectorAll('input[name="editor_mode"]');
    var pageStylesCard = document.getElementById('page-styles-card');

    // Design mode elements
    var designFileInput = document.getElementById('design-file-input');
    var designFileSelect = document.getElementById('design-file-select');
    var designIframe = document.getElementById('design-editor-iframe');
    var designPreview = document.getElementById('design-preview-frame');
    var reconvertBtn = document.getElementById('design-reconvert-btn');
    var designStatus = document.getElementById('design-status');
    var designNewName = document.getElementById('design-new-name');

    var editorReady = false;
    var currentDesignFile = designFileInput ? designFileInput.value : '';

    // --- Mode toggle (3-way: html, elements, design) ---
    modeRadios.forEach(function(radio) {
        radio.addEventListener('change', function() {
            if (htmlPanel) htmlPanel.classList.toggle('hidden', this.value !== 'html');
            if (builderPanel) builderPanel.classList.toggle('hidden', this.value !== 'elements');
            if (designPanel) designPanel.classList.toggle('hidden', this.value !== 'design');
            if (pageStylesCard) {
                pageStylesCard.style.display = this.value === 'elements' ? '' : 'none';
            }
            // Load editor when switching to design mode
            if (this.value === 'design' && designIframe && !designIframe.src) {
                loadDesignEditor(currentDesignFile);
            }
        });
    });

    // --- Build iframe URL ---
    function buildEditorUrl(filePath) {
        var base = '/assets/pencil-editor/index.html';
        var params = new URLSearchParams();
        params.set('baseUrl', '');
        var csrfInput = document.querySelector('input[name="csrf_token"]');
        params.set('csrf', csrfInput ? csrfInput.value : '');
        if (filePath) params.set('filePath', filePath);
        return base + '?' + params.toString();
    }

    // --- Send message to bridge ---
    function sendToBridge(action, payload) {
        if (!designIframe || !designIframe.contentWindow) return;
        designIframe.contentWindow.postMessage({
            source: 'litecms-admin',
            action: action,
            payload: payload || {}
        }, '*');
    }

    // --- Load design editor iframe ---
    function loadDesignEditor(filePath) {
        if (!designIframe) return;
        editorReady = false;
        designIframe.src = buildEditorUrl(filePath);
        if (designStatus) designStatus.textContent = 'Loading...';
    }

    // --- File select change ---
    if (designFileSelect) {
        designFileSelect.addEventListener('change', function() {
            var selected = this.value;
            var isNew = selected === '__new__';
            if (designNewName) {
                designNewName.style.display = isNew ? 'inline-block' : 'none';
            }
            if (isNew) {
                currentDesignFile = '';
                if (designFileInput) designFileInput.value = '';
            } else {
                currentDesignFile = selected;
                if (designFileInput) designFileInput.value = selected;
                loadDesignEditor(selected);
            }
        });
    }

    // --- New file name input ---
    if (designNewName) {
        designNewName.addEventListener('input', function() {
            var name = this.value.trim();
            if (name && !name.endsWith('.pen')) name += '.pen';
            name = name.replace(/[^a-zA-Z0-9_\-\.]/g, '');
            currentDesignFile = name;
            if (designFileInput) designFileInput.value = name;
            if (editorReady && name) {
                sendToBridge('set-file-path', { path: name });
            }
        });
    }

    // --- Save design file (before form submit) ---
    function saveDesignFile(callback) {
        if (!currentDesignFile || !editorReady) {
            if (callback) callback();
            return;
        }
        sendToBridge('set-file-path', { path: currentDesignFile });
        sendToBridge('save');
        var onSaved = function(event) {
            if (event.data && event.data.source === 'pencil-bridge'
                && event.data.event === 'file-saved') {
                window.removeEventListener('message', onSaved);
                if (callback) callback();
            }
        };
        window.addEventListener('message', onSaved);
        setTimeout(function() {
            window.removeEventListener('message', onSaved);
            if (callback) callback();
        }, 5000);
    }

    // --- Re-convert button ---
    if (reconvertBtn) {
        reconvertBtn.addEventListener('click', function() {
            if (!currentDesignFile) return;
            reconvertBtn.disabled = true;
            reconvertBtn.textContent = 'Converting...';

            saveDesignFile(function() {
                fetch('/admin/content/' + getContentId() + '/reconvert', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({
                        csrf_token: getCsrfToken(),
                        design_file: currentDesignFile
                    })
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    reconvertBtn.disabled = false;
                    reconvertBtn.textContent = 'Re-convert to HTML';
                    if (data.success) {
                        if (designStatus) {
                            designStatus.textContent = 'Converted!';
                            designStatus.className = 'design-status status-saved';
                        }
                        refreshPreview();
                        var bodyEl = document.getElementById('body');
                        if (bodyEl && data.html) bodyEl.value = data.html;
                    } else {
                        if (designStatus) {
                            designStatus.textContent = 'Error: ' + (data.error || 'Unknown');
                            designStatus.className = 'design-status status-error';
                        }
                    }
                })
                .catch(function() {
                    reconvertBtn.disabled = false;
                    reconvertBtn.textContent = 'Re-convert to HTML';
                });
            });
        });
    }

    // --- Preview refresh ---
    function refreshPreview() {
        if (!designPreview || !currentDesignFile) return;
        designPreview.src = '/admin/design/preview?path=' +
            encodeURIComponent(currentDesignFile) + '&t=' + Date.now();
    }

    // --- Listen for bridge messages ---
    window.addEventListener('message', function(event) {
        if (!event.data || event.data.source !== 'pencil-bridge') return;
        switch (event.data.event) {
            case 'editor-ready':
                editorReady = true;
                if (designStatus) {
                    designStatus.textContent = 'Ready';
                    designStatus.className = 'design-status status-ready';
                }
                if (currentDesignFile) {
                    sendToBridge('set-file-path', { path: currentDesignFile });
                }
                break;
            case 'file-saved':
                if (designStatus) {
                    designStatus.textContent = 'Saved';
                    designStatus.className = 'design-status status-saved';
                    setTimeout(function() {
                        designStatus.textContent = 'Ready';
                        designStatus.className = 'design-status status-ready';
                    }, 2000);
                }
                break;
        }
    });

    // --- Helpers ---
    function getContentId() {
        var form = document.getElementById('content-form');
        return form ? form.dataset.contentId : '';
    }
    function getCsrfToken() {
        var el = document.querySelector('input[name="csrf_token"]');
        return el ? el.value : '';
    }

    // --- Intercept form submit to save design file first ---
    var contentForm = document.getElementById('content-form');
    if (contentForm) {
        contentForm.addEventListener('submit', function(e) {
            var mode = document.querySelector('input[name="editor_mode"]:checked');
            if (mode && mode.value === 'design' && currentDesignFile && editorReady) {
                e.preventDefault();
                saveDesignFile(function() {
                    contentForm.submit();
                });
            }
        });
    }

    // --- Initial load if already in design mode ---
    var checkedMode = document.querySelector('input[name="editor_mode"]:checked');
    if (checkedMode && checkedMode.value === 'design' && designIframe) {
        loadDesignEditor(currentDesignFile);
    }
});

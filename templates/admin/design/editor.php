<?php $this->layout('admin/layout'); ?>

<div class="design-editor-page">
    <div class="design-editor-toolbar">
        <h1 class="design-editor-title">Design Editor</h1>

        <div class="design-editor-controls">
            <!-- File selector -->
            <div class="design-file-select">
                <label for="design-file-path">File:</label>
                <select id="design-file-path" class="form-control form-control-sm">
                    <option value="">— New Design —</option>
                    <?php foreach ($designFiles as $df): ?>
                    <option value="<?= $this->e($df['path']) ?>"
                        <?= ($currentFile ?? '') === $df['path'] ? 'selected' : '' ?>>
                        <?= $this->e($df['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- New file name input (shown when "New Design" selected) -->
            <div class="design-new-file" id="new-file-group">
                <input type="text" id="new-file-name"
                       class="form-control form-control-sm"
                       placeholder="my-design.pen"
                       pattern="[a-zA-Z0-9_\-]+\.pen">
            </div>

            <!-- Save button -->
            <button type="button" class="btn btn-sm btn-primary" id="design-save-btn"
                    title="Save (Ctrl+S)">Save</button>

            <!-- Status indicator -->
            <span class="design-status" id="design-status">Ready</span>
        </div>
    </div>

    <!-- Editor iframe -->
    <div class="design-editor-frame-wrapper" id="editor-wrapper">
        <iframe
            id="pencil-editor-iframe"
            class="design-editor-iframe"
            data-base-url=""
            data-csrf-token="<?= $this->e($csrfToken) ?>"
            sandbox="allow-scripts allow-same-origin allow-popups allow-forms allow-modals allow-downloads"
            allowfullscreen
        ></iframe>
        <div class="design-editor-loading" id="editor-loading">
            <div class="loading-spinner"></div>
            <p>Loading Design Editor...</p>
        </div>
    </div>
</div>

<script>
(function() {
    'use strict';

    var iframe = document.getElementById('pencil-editor-iframe');
    var fileSelect = document.getElementById('design-file-path');
    var newFileGroup = document.getElementById('new-file-group');
    var newFileInput = document.getElementById('new-file-name');
    var saveBtn = document.getElementById('design-save-btn');
    var statusEl = document.getElementById('design-status');
    var loadingEl = document.getElementById('editor-loading');

    var csrfToken = iframe.dataset.csrfToken || '';
    var currentFile = <?= json_encode($currentFile ?? '') ?>;
    var editorReady = false;

    // Build iframe URL with parameters
    function buildEditorUrl(filePath) {
        var base = '/assets/pencil-editor/index.html';
        var params = new URLSearchParams();
        params.set('baseUrl', '');
        params.set('csrf', csrfToken);
        if (filePath) {
            params.set('filePath', filePath);
        }
        return base + '?' + params.toString();
    }

    // Send message to bridge inside iframe
    function sendToBridge(action, payload) {
        if (!iframe.contentWindow) return;
        iframe.contentWindow.postMessage({
            source: 'litecms-admin',
            action: action,
            payload: payload || {}
        }, '*');
    }

    // Load editor with file (reloads iframe)
    function loadEditor(filePath) {
        currentFile = filePath || '';
        editorReady = false;
        loadingEl.style.display = 'flex';
        iframe.src = buildEditorUrl(filePath);
    }

    // Get current file path (from selector or new file input)
    function getCurrentFilePath() {
        if (fileSelect.value) return fileSelect.value;
        var name = newFileInput.value.trim();
        if (!name) return '';
        if (!name.endsWith('.pen')) name += '.pen';
        return name.replace(/[^a-zA-Z0-9_\-\.]/g, '');
    }

    // Trigger save via bridge
    function triggerSave() {
        var path = getCurrentFilePath();
        if (!path) {
            newFileInput.focus();
            statusEl.textContent = 'Enter a filename first';
            statusEl.className = 'design-status status-error';
            return;
        }
        // Ensure bridge knows the current file path
        sendToBridge('set-file-path', { path: path });
        // Then request save
        sendToBridge('save');
        currentFile = path;
        statusEl.textContent = 'Saving...';
        statusEl.className = 'design-status status-saving';
    }

    // Toggle new file input visibility
    function toggleNewFile() {
        var isNew = fileSelect.value === '';
        newFileGroup.style.display = isNew ? 'inline-block' : 'none';
    }

    // Listen for bridge messages from iframe
    window.addEventListener('message', function(event) {
        if (!event.data || event.data.source !== 'pencil-bridge') return;

        switch (event.data.event) {
            case 'editor-ready':
                loadingEl.style.display = 'none';
                editorReady = true;
                statusEl.textContent = 'Ready';
                statusEl.className = 'design-status status-ready';
                // If we have a file path (e.g. from new-file input), tell bridge
                var path = getCurrentFilePath();
                if (path && !fileSelect.value) {
                    sendToBridge('set-file-path', { path: path });
                }
                break;

            case 'file-saved':
                statusEl.textContent = 'Saved';
                statusEl.className = 'design-status status-saved';
                setTimeout(function() {
                    statusEl.textContent = 'Ready';
                    statusEl.className = 'design-status status-ready';
                }, 2000);
                break;
        }
    });

    // Save button click
    saveBtn.addEventListener('click', triggerSave);

    // Ctrl+S on the parent page — forward to editor
    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 's' && !e.shiftKey && !e.altKey) {
            e.preventDefault();
            if (editorReady) triggerSave();
        }
    });

    // File selector change — load existing file
    fileSelect.addEventListener('change', function() {
        toggleNewFile();
        if (this.value) {
            loadEditor(this.value);
        }
    });

    // New file name — update bridge path without reloading iframe
    newFileInput.addEventListener('input', function() {
        var path = getCurrentFilePath();
        if (path && editorReady) {
            sendToBridge('set-file-path', { path: path });
        }
    });

    // Initial load
    toggleNewFile();
    loadEditor(currentFile);
})();
</script>

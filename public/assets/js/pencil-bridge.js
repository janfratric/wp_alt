// public/assets/js/pencil-bridge.js
(function() {
    'use strict';

    // Read configuration from query params
    var params = new URLSearchParams(window.location.search);
    var baseUrl = params.get('baseUrl') || '';
    var filePath = params.get('filePath') || '';
    var csrfToken = params.get('csrf') || '';

    // ---- Intercept activation verification ----
    // The editor verifies license tokens against api.pencil.dev.
    // Intercept these calls and return a mock success response so the
    // embedded editor works without an external license server.
    var _origFetch = window.fetch;
    window.fetch = function(url, opts) {
        if (typeof url === 'string' && url.indexOf('api.pencil.dev/public/activation') !== -1) {
            return Promise.resolve(new Response('{"ok":true}', {
                status: 200,
                headers: { 'Content-Type': 'application/json' }
            }));
        }
        return _origFetch.apply(this, arguments);
    };

    // ---- Request/Response infrastructure ----

    var _nextRequestId = 1;
    var _pendingRequests = {};

    /**
     * Send a request to the editor and return a Promise for the response.
     * The editor's IPC calls handle() for the method and sends back a response
     * via window.vscodeapi.postMessage().
     */
    function requestFromEditor(method, payload) {
        return new Promise(function(resolve, reject) {
            var id = 'bridge-req-' + (_nextRequestId++);
            _pendingRequests[id] = { resolve: resolve, reject: reject };
            window.postMessage({
                id: id,
                type: 'request',
                method: method,
                payload: payload || {},
                source: 'vscode-extension'
            }, '*');
            // Timeout after 10 seconds
            setTimeout(function() {
                if (_pendingRequests[id]) {
                    delete _pendingRequests[id];
                    reject(new Error('Request ' + method + ' timed out'));
                }
            }, 10000);
        });
    }

    // ---- Mock vscodeapi ----

    /**
     * The editor calls window.vscodeapi.postMessage(msg) to communicate.
     * Messages have shape: { id?, type, method, payload, source }
     */
    window.vscodeapi = {
        postMessage: function(msg) {
            handleEditorMessage(msg);
        }
    };

    // ---- Message Handler ----

    function handleEditorMessage(msg) {
        if (!msg || !msg.method) return;

        // Handle responses to our requests (e.g. save response)
        if (msg.type === 'response' && msg.id && _pendingRequests[msg.id]) {
            var pending = _pendingRequests[msg.id];
            delete _pendingRequests[msg.id];
            if (msg.error) {
                pending.reject(new Error(msg.error.message || 'Request failed'));
            } else {
                pending.resolve(msg.payload);
            }
            return;
        }

        var method = msg.method;
        var payload = msg.payload || {};
        var id = msg.id;
        var type = msg.type; // "request" or "notification"

        switch (method) {
            case 'initialized':
                onEditorInitialized();
                break;

            case 'load-file':
                loadFile(payload.path || filePath);
                break;

            case 'file-changed':
                // Editor notifies that the document was modified (dirty).
                // If content is included (rare), save immediately.
                // Otherwise, schedule an auto-save via request/response.
                if (payload && payload.content) {
                    persistToServer(payload.path || filePath, payload.content);
                } else {
                    scheduleAutoSave();
                }
                break;

            case 'import-uri':
                handleImportUri(id, payload);
                break;

            case 'import-file':
                handleImportFile(id, payload);
                break;

            case 'get-recent-files':
                respondToEditor(id, method, []);
                break;

            case 'get-license':
                respondToEditor(id, method, {
                    email: 'admin@litecms.local',
                    licenseToken: 'litecms-embedded'
                });
                break;

            case 'get-fullscreen':
                respondToEditor(id, method, false);
                break;

            case 'toggle-design-mode':
            case 'toggle-theme':
            case 'clear-recent-files':
            case 'telemetry':
            case 'claude-disconnect':
            case 'claude-status-help-triggered':
            case 'set-license':
            case 'set-left-sidebar-visible':
            case 'send-prompt':
            case 'agent-stop':
            case 'desktop-update-install':
                // Silently ignore — not needed in LiteCMS context
                break;

            default:
                console.log('[pencil-bridge] Unhandled message:', method, payload);
                // For unknown requests, respond with null to avoid hanging
                if (type === 'request' && id) {
                    respondToEditor(id, method, null);
                }
                break;
        }
    }

    // ---- Editor Communication Helpers ----

    /**
     * Send a message to the editor.
     */
    function sendToEditor(method, payload, id) {
        var msg = {
            method: method,
            payload: payload,
            source: 'vscode-extension'
        };
        if (id !== undefined) {
            msg.id = id;
            msg.type = 'response';
        } else {
            // Editor IPC filter requires id on ALL messages (id && type && method).
            // Generate an id for notifications too.
            msg.id = 'bridge-msg-' + (_nextRequestId++);
            msg.type = 'notification';
        }
        window.postMessage(msg, '*');
    }

    /**
     * Respond to a request from the editor (by id).
     * The method name MUST be included — the editor's IPC filter
     * requires id, type, AND method on all messages.
     */
    function respondToEditor(id, method, data) {
        if (id === undefined || id === null) return;
        sendToEditor(method, data, id);
    }

    // ---- File Operations ----

    function onEditorInitialized() {
        console.log('[pencil-bridge] Editor initialized');

        // Send theme to match the admin panel (dark)
        sendToEditor('color-theme-changed', { theme: 'dark' });

        // If a file path was specified, trigger a load
        if (filePath) {
            loadFile(filePath);
        }

        // Notify parent page that editor is ready
        if (window.parent !== window) {
            window.parent.postMessage({
                source: 'pencil-bridge',
                event: 'editor-ready'
            }, '*');
        }
    }

    function loadFile(path) {
        if (!path) return;

        fetch(baseUrl + '/admin/design/load?path=' + encodeURIComponent(path), {
            method: 'GET',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': csrfToken
            },
            credentials: 'same-origin'
        })
        .then(function(response) {
            if (!response.ok) throw new Error('Load failed: ' + response.status);
            return response.json();
        })
        .then(function(data) {
            if (data.success && data.content) {
                sendToEditor('file-update', {
                    filePath: path,
                    content: data.content,
                    zoomToFit: true
                });
            } else {
                sendToEditor('file-error', {
                    filePath: path,
                    error: data.error || 'Failed to load file'
                });
            }
        })
        .catch(function(err) {
            console.error('[pencil-bridge] Load error:', err);
            sendToEditor('file-error', {
                path: path,
                error: err.message
            });
        });
    }

    // ---- Save Operations ----

    var _autoSaveTimer = null;
    var _saving = false;

    /**
     * Schedule an auto-save. Debounced: resets on each call,
     * fires 3 seconds after the last document change.
     */
    function scheduleAutoSave() {
        if (_autoSaveTimer) clearTimeout(_autoSaveTimer);
        _autoSaveTimer = setTimeout(function() {
            _autoSaveTimer = null;
            requestSaveAndPersist();
        }, 3000);
    }

    /**
     * Ask the editor to serialize the document, then persist to server.
     * The editor handles the 'save' request via its IPC and returns
     * the full .pen JSON string from fileManager.export().
     */
    function requestSaveAndPersist() {
        if (_saving || !filePath) return;
        _saving = true;

        requestFromEditor('save', filePath)
            .then(function(content) {
                _saving = false;
                if (content) {
                    persistToServer(filePath, content);
                }
            })
            .catch(function(err) {
                _saving = false;
                console.error('[pencil-bridge] Save request failed:', err);
            });
    }

    /**
     * POST the serialized content to the PHP backend.
     */
    function persistToServer(path, content) {
        if (!path || content === undefined) return;

        fetch(baseUrl + '/admin/design/save', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': csrfToken
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                path: path,
                content: content,
                _csrf_token: csrfToken
            })
        })
        .then(function(response) {
            if (!response.ok) throw new Error('Save failed: ' + response.status);
            return response.json();
        })
        .then(function(data) {
            if (data.success) {
                console.log('[pencil-bridge] Saved:', path);
                // Notify parent page of successful save
                if (window.parent !== window) {
                    window.parent.postMessage({
                        source: 'pencil-bridge',
                        event: 'file-saved',
                        path: path
                    }, '*');
                }
            } else {
                console.error('[pencil-bridge] Save error:', data.error);
            }
        })
        .catch(function(err) {
            console.error('[pencil-bridge] Save error:', err);
        });
    }

    // ---- Ctrl+S Intercept ----
    // In VS Code, the extension handles Ctrl+S and sends the 'save' request.
    // In the embedded iframe, the browser would show "Save Page As..." instead.
    // Capture Ctrl+S in the capture phase to prevent browser default.
    window.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 's' && !e.shiftKey && !e.altKey) {
            e.preventDefault();
            e.stopPropagation();
            // Cancel any pending auto-save and save immediately
            if (_autoSaveTimer) {
                clearTimeout(_autoSaveTimer);
                _autoSaveTimer = null;
            }
            requestSaveAndPersist();
        }
    }, true);

    // ---- Import Operations ----

    /**
     * import-uri: Editor sends a URI (data:, http:, file://).
     * Expected response: { filePath: "name.ext", fileContents: ArrayBuffer }
     */
    function handleImportUri(requestId, payload) {
        var uri = payload.uri || payload.url;
        if (!uri) {
            respondToEditor(requestId, 'import-uri', { error: 'No URI provided' });
            return;
        }

        if (uri.indexOf('data:') === 0) {
            // Decode data URI in browser → return binary + filename
            var match = uri.match(/^data:([^;,]+);base64,(.+)$/);
            if (!match) {
                respondToEditor(requestId, 'import-uri', { error: 'Invalid data URI' });
                return;
            }
            var mime = match[1];
            var ext = mime.split('/')[1] || 'bin';
            if (ext === 'jpeg') ext = 'jpg';
            if (ext === 'svg+xml') ext = 'svg';
            var raw = atob(match[2]);
            var bytes = new Uint8Array(raw.length);
            for (var i = 0; i < raw.length; i++) bytes[i] = raw.charCodeAt(i);
            respondToEditor(requestId, 'import-uri', {
                filePath: 'import.' + ext,
                fileContents: bytes.buffer
            });
        } else if (uri.indexOf('http') === 0) {
            // Fetch remote URL → return binary + filename
            _origFetch(uri)
                .then(function(resp) { return resp.arrayBuffer(); })
                .then(function(buffer) {
                    var name = uri.split('/').pop().split('?')[0] || 'import.png';
                    respondToEditor(requestId, 'import-uri', {
                        filePath: name,
                        fileContents: buffer
                    });
                })
                .catch(function(err) {
                    respondToEditor(requestId, 'import-uri', { error: err.message });
                });
        } else {
            respondToEditor(requestId, 'import-uri', { error: 'Unsupported URI scheme' });
        }
    }

    /**
     * import-file: Editor sends binary data to be stored on server.
     * Input:  { fileName: string, fileContents: ArrayBuffer }
     * Expected response: { filePath: "/url/to/stored/file.ext" }
     */
    function handleImportFile(requestId, payload) {
        var fileName = payload.fileName || 'upload.png';
        var fileContents = payload.fileContents;

        if (!fileContents) {
            respondToEditor(requestId, 'import-file', { filePath: '' });
            return;
        }

        // Convert ArrayBuffer → base64 data URI for our PHP endpoint
        var bytes = new Uint8Array(fileContents);
        var binary = '';
        var chunkSize = 8192;
        for (var i = 0; i < bytes.length; i += chunkSize) {
            binary += String.fromCharCode.apply(null, bytes.subarray(i, i + chunkSize));
        }
        var ext = fileName.split('.').pop().toLowerCase();
        var mimeMap = {
            png: 'image/png', jpg: 'image/jpeg', jpeg: 'image/jpeg',
            gif: 'image/gif', webp: 'image/webp', svg: 'image/svg+xml'
        };
        var mime = mimeMap[ext] || 'image/png';
        var dataUri = 'data:' + mime + ';base64,' + btoa(binary);

        fetch(baseUrl + '/admin/design/import-file', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': csrfToken
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                uri: dataUri,
                _csrf_token: csrfToken
            })
        })
        .then(function(response) {
            if (!response.ok) throw new Error('Upload failed: ' + response.status);
            return response.json();
        })
        .then(function(data) {
            if (data.success) {
                respondToEditor(requestId, 'import-file', { filePath: data.url });
            } else {
                respondToEditor(requestId, 'import-file', { error: data.error });
            }
        })
        .catch(function(err) {
            console.error('[pencil-bridge] Import-file error:', err);
            respondToEditor(requestId, 'import-file', { error: err.message });
        });
    }

    // ---- Parent Page Communication ----

    // Listen for messages from the parent admin page (outside the iframe)
    window.addEventListener('message', function(event) {
        if (!event.data || event.data.source !== 'litecms-admin') return;

        var action = event.data.action;
        var payload = event.data.payload || {};

        switch (action) {
            case 'load-file':
                loadFile(payload.path);
                break;

            case 'set-file-path':
                if (payload.path) {
                    filePath = payload.path;
                    console.log('[pencil-bridge] File path set to:', filePath);
                }
                break;

            case 'set-theme':
                sendToEditor('color-theme-changed', {
                    theme: payload.theme || 'dark'
                });
                break;

            case 'save':
                requestSaveAndPersist();
                break;
        }
    });

})();

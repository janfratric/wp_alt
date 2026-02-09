/**
 * LiteCMS AI Chat Core
 * Shared module for AI chat functionality — used by both editor assistant and page generator.
 * Exposes window.AIChatCore constructor.
 */
(function() {
    'use strict';

    var MODEL_CONTEXT_WINDOWS = {
        'claude-sonnet-4-20250514': 200000,
        'claude-haiku-4-5-20251001': 200000,
        'claude-opus-4-6': 200000,
        '_default': 200000
    };

    /**
     * @param {Object} config
     * @param {HTMLElement} config.messagesEl       - Container for chat messages
     * @param {HTMLElement} config.inputEl           - Textarea for user input
     * @param {HTMLElement} config.sendBtnEl         - Send button
     * @param {HTMLElement} [config.headerEl]        - Header element for controls
     * @param {HTMLElement} [config.attachPreviewEl] - Container for attachment previews
     * @param {HTMLElement} [config.attachBtnEl]     - Attach file button
     * @param {string}      config.chatEndpoint      - POST endpoint for messages
     * @param {string}      [config.compactEndpoint]  - POST endpoint for compaction
     * @param {string}      [config.modelsEndpoint]   - GET endpoint for model list
     * @param {string}      [config.conversationsEndpoint] - GET endpoint for history
     * @param {string}      config.csrfToken
     * @param {number|null} [config.contentId]
     * @param {boolean}     [config.enableAttachments]
     * @param {boolean}     [config.enableModelSelector]
     * @param {boolean}     [config.enableContextMeter]
     * @param {boolean}     [config.enableCompact]
     * @param {boolean}     [config.enableConversationHistory]
     * @param {boolean}     [config.enableMarkdown]
     * @param {boolean}     [config.enableResizable]
     * @param {HTMLElement} [config.resizableEl]
     * @param {Function}    [config.messageActions]   - Returns array of {label, action} for assistant msgs
     * @param {Function}    [config.extraPayload]     - Returns extra fields for API calls
     * @param {Function}    [config.onAssistantMessage] - Called after assistant response
     * @param {Function}    [config.onBeforeSend]     - Called before sending
     */
    function AIChatCore(config) {
        this.config = config;
        this.conversationId = null;
        this.isLoading = false;
        this.loadingEl = null;
        this.attachments = [];
        this.currentModel = null;
        this.messageCount = 0;
        this.dropOverlay = null;

        this._init();
    }

    AIChatCore.prototype._init = function() {
        var self = this;
        var c = this.config;

        // Send button
        if (c.sendBtnEl) {
            c.sendBtnEl.addEventListener('click', function() { self.sendMessage(); });
        }

        // Input: Enter to send, Shift+Enter for newline
        if (c.inputEl) {
            c.inputEl.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    self.sendMessage();
                }
            });

            // Auto-grow textarea up to CSS max-height, then scroll
            c.inputEl.addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = this.scrollHeight + 'px';
            });

            // Clipboard paste for images
            if (c.enableAttachments !== false) {
                c.inputEl.addEventListener('paste', function(e) {
                    self._handlePaste(e);
                });
            }
        }

        // Attach button
        if (c.attachBtnEl && c.enableAttachments !== false) {
            this._fileInput = document.createElement('input');
            this._fileInput.type = 'file';
            this._fileInput.accept = 'image/*';
            this._fileInput.style.display = 'none';
            this._fileInput.multiple = true;
            document.body.appendChild(this._fileInput);

            c.attachBtnEl.addEventListener('click', function() {
                self._fileInput.click();
            });

            this._fileInput.addEventListener('change', function() {
                if (this.files && this.files.length > 0) {
                    for (var i = 0; i < this.files.length; i++) {
                        self._uploadFile(this.files[i]);
                    }
                    this.value = '';
                }
            });
        }

        // Drag & drop on messages area
        if (c.messagesEl && c.enableAttachments !== false) {
            var container = c.messagesEl.parentElement || c.messagesEl;
            container.addEventListener('dragover', function(e) {
                e.preventDefault();
                e.stopPropagation();
                self._showDropOverlay(container);
            });
            container.addEventListener('dragleave', function(e) {
                e.preventDefault();
                e.stopPropagation();
                if (!container.contains(e.relatedTarget)) {
                    self._hideDropOverlay();
                }
            });
            container.addEventListener('drop', function(e) {
                e.preventDefault();
                e.stopPropagation();
                self._hideDropOverlay();
                if (e.dataTransfer && e.dataTransfer.files.length > 0) {
                    for (var i = 0; i < e.dataTransfer.files.length; i++) {
                        var file = e.dataTransfer.files[i];
                        if (file.type.startsWith('image/')) {
                            self._uploadFile(file);
                        }
                    }
                }
            });
        }

        // Model selector
        if (c.enableModelSelector !== false && c.modelsEndpoint) {
            this._initModelSelector();
        }

        // Context meter (initialized but starts at 0)
        if (c.enableContextMeter !== false) {
            this._initContextMeter();
        }

        // Compact button
        if (c.enableCompact !== false && c.headerEl) {
            this._initCompactButton();
        }

        // Conversation history
        if (c.enableConversationHistory !== false && c.headerEl) {
            this._initHistoryButton();
        }

        // Resizable
        if (c.enableResizable && c.resizableEl) {
            this._initResize();
        }
    };

    // ===================== Public Methods =====================

    AIChatCore.prototype.sendMessage = function(text) {
        var self = this;
        var c = this.config;

        if (this.isLoading) return;
        var message = text || (c.inputEl ? c.inputEl.value.trim() : '');
        if (message === '' && this.attachments.length === 0) return;

        if (c.inputEl) {
            c.inputEl.value = '';
            c.inputEl.style.height = 'auto';
        }

        if (c.onBeforeSend) {
            c.onBeforeSend(message, this.attachments);
        }

        this.appendMessage('user', message, { attachments: this.attachments.slice() });
        this._showLoading();
        this._scrollToBottom();

        var payload = {
            message: message,
            conversation_id: this.conversationId
        };

        if (this.attachments.length > 0) {
            payload.attachments = this.attachments.map(function(a) {
                return { url: a.url, media_id: a.media_id, mime_type: a.mime_type, type: 'image' };
            });
        }

        if (this.currentModel) {
            payload.model = this.currentModel;
        }

        if (c.extraPayload) {
            var extra = c.extraPayload();
            for (var key in extra) {
                if (extra.hasOwnProperty(key)) {
                    payload[key] = extra[key];
                }
            }
        }

        // Clear attachments after building payload
        this.attachments = [];
        this._renderAttachmentPreviews();

        this._apiCall(c.chatEndpoint, payload)
            .then(function(data) {
                self._hideLoading();
                if (data.success) {
                    self.conversationId = data.conversation_id;
                    self.appendMessage('assistant', data.response);
                    self.messageCount += 2;
                    self._updateCompactVisibility();

                    if (data.usage) {
                        self._updateContextMeter(data.usage);
                    }
                    if (c.onAssistantMessage) {
                        c.onAssistantMessage(data.response, data);
                    }
                } else {
                    self._appendError(data.error || 'An unknown error occurred.');
                }
            })
            .catch(function(err) {
                self._hideLoading();
                var msg = (err && err.message) ? err.message : 'Network error';
                self._appendError(msg);
            });
    };

    AIChatCore.prototype.loadConversation = function(conversationId) {
        var self = this;
        var c = this.config;
        if (!c.conversationsEndpoint) return;

        var url = c.conversationsEndpoint;
        if (c.contentId !== null && c.contentId !== undefined) {
            url += '?content_id=' + c.contentId;
        }

        fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.success && data.conversations && data.conversations.length > 0) {
                var conv;
                if (conversationId) {
                    conv = data.conversations.find(function(co) { return co.id === conversationId; });
                }
                if (!conv) conv = data.conversations[0];

                self.conversationId = conv.id;
                self.messageCount = conv.messages ? conv.messages.length : 0;

                // Clear and re-render
                self._clearMessages();
                if (conv.messages) {
                    conv.messages.forEach(function(msg) {
                        self.appendMessage(msg.role, msg.content, {
                            attachments: msg.attachments,
                            isSummary: msg.is_summary
                        });
                    });
                }

                if (conv.usage) {
                    self._updateContextMeter(conv.usage);
                }
                self._updateCompactVisibility();
            }
        })
        .catch(function() {
            // Silently ignore
        });
    };

    AIChatCore.prototype.newConversation = function() {
        this.conversationId = null;
        this.messageCount = 0;
        this.attachments = [];
        this._clearMessages();
        this._renderAttachmentPreviews();
        this._updateCompactVisibility();
        this._resetContextMeter();
        this._appendSystemMessage('New conversation started.');
        if (this.config.inputEl) this.config.inputEl.focus();
    };

    AIChatCore.prototype.getConversationId = function() {
        return this.conversationId;
    };

    AIChatCore.prototype.appendMessage = function(role, content, opts) {
        opts = opts || {};
        var c = this.config;
        if (!c.messagesEl) return;

        // Remove empty-state placeholder
        var placeholder = c.messagesEl.querySelector('.ai-empty-state');
        if (placeholder) placeholder.remove();

        var bubble = document.createElement('div');
        bubble.className = 'ai-message ai-message-' + role;

        if (opts.isSummary) {
            bubble.classList.add('ai-message-summary');
        }

        // Attachment thumbnails (for user messages with images)
        if (opts.attachments && opts.attachments.length > 0) {
            var attDiv = document.createElement('div');
            attDiv.className = 'ai-message-attachments';
            opts.attachments.forEach(function(att) {
                if (att.url) {
                    var img = document.createElement('img');
                    img.src = att.url;
                    img.alt = 'Attachment';
                    img.loading = 'lazy';
                    attDiv.appendChild(img);
                }
            });
            bubble.appendChild(attDiv);
        }

        var contentDiv = document.createElement('div');
        contentDiv.className = 'ai-message-content';

        if (opts.isSummary) {
            var badge = document.createElement('div');
            badge.className = 'ai-summary-badge';
            badge.textContent = 'Summary';
            contentDiv.appendChild(badge);
        }

        if (role === 'user') {
            contentDiv.appendChild(document.createTextNode(content));
        } else if (role === 'assistant') {
            if (c.enableMarkdown !== false && typeof marked !== 'undefined') {
                contentDiv.innerHTML = this._renderMarkdown(content);
            } else {
                contentDiv.innerHTML = this._formatBasic(content);
            }
            this._addCodeCopyButtons(contentDiv);
        }

        bubble.appendChild(contentDiv);

        // Action buttons for assistant messages
        if (role === 'assistant' && c.messageActions) {
            var actions = c.messageActions(content);
            if (actions && actions.length > 0) {
                var actionsDiv = document.createElement('div');
                actionsDiv.className = 'ai-message-actions';
                actions.forEach(function(act) {
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'btn btn-sm ai-action-btn';
                    btn.textContent = act.label;
                    if (act.title) btn.title = act.title;
                    btn.addEventListener('click', function() {
                        act.action();
                        if (act.feedback) {
                            var orig = btn.textContent;
                            btn.textContent = act.feedback;
                            btn.disabled = true;
                            setTimeout(function() {
                                btn.textContent = orig;
                                btn.disabled = false;
                            }, 1500);
                        }
                    });
                    actionsDiv.appendChild(btn);
                });
                bubble.appendChild(actionsDiv);
            }
        }

        c.messagesEl.appendChild(bubble);
        this._scrollToBottom();
    };

    AIChatCore.prototype.destroy = function() {
        if (this._fileInput && this._fileInput.parentNode) {
            this._fileInput.parentNode.removeChild(this._fileInput);
        }
    };

    // ===================== Private: Messaging =====================

    AIChatCore.prototype._appendError = function(errorText) {
        var c = this.config;
        if (!c.messagesEl) return;

        var bubble = document.createElement('div');
        bubble.className = 'ai-message ai-message-error';

        var contentDiv = document.createElement('div');
        contentDiv.className = 'ai-message-content';

        if (errorText.indexOf('API key') !== -1 || errorText.indexOf('Settings') !== -1) {
            contentDiv.innerHTML = this._escapeHtml(errorText) + ' <a href="/admin/settings">Go to Settings</a>';
        } else {
            contentDiv.textContent = errorText;
        }

        bubble.appendChild(contentDiv);
        c.messagesEl.appendChild(bubble);
        this._scrollToBottom();
    };

    AIChatCore.prototype._appendSystemMessage = function(text) {
        var c = this.config;
        if (!c.messagesEl) return;

        var el = document.createElement('div');
        el.className = 'ai-message ai-message-system';
        el.textContent = text;
        c.messagesEl.appendChild(el);
        this._scrollToBottom();
    };

    AIChatCore.prototype._clearMessages = function() {
        if (this.config.messagesEl) {
            this.config.messagesEl.innerHTML = '';
        }
    };

    AIChatCore.prototype._showLoading = function() {
        this.isLoading = true;
        var c = this.config;
        if (!c.messagesEl) return;

        this.loadingEl = document.createElement('div');
        this.loadingEl.className = 'ai-message ai-message-loading';
        this.loadingEl.innerHTML = '<div class="ai-typing-indicator"><span></span><span></span><span></span></div>';
        c.messagesEl.appendChild(this.loadingEl);
        this._scrollToBottom();

        if (c.sendBtnEl) c.sendBtnEl.disabled = true;
    };

    AIChatCore.prototype._hideLoading = function() {
        this.isLoading = false;
        if (this.loadingEl && this.loadingEl.parentNode) {
            this.loadingEl.parentNode.removeChild(this.loadingEl);
            this.loadingEl = null;
        }
        if (this.config.sendBtnEl) this.config.sendBtnEl.disabled = false;
        if (this.config.inputEl) this.config.inputEl.focus();
    };

    AIChatCore.prototype._scrollToBottom = function() {
        var el = this.config.messagesEl;
        if (el) el.scrollTop = el.scrollHeight;
    };

    // ===================== Private: Markdown Rendering =====================

    AIChatCore.prototype._renderMarkdown = function(text) {
        if (typeof marked === 'undefined') return this._formatBasic(text);

        try {
            if (typeof marked.parse === 'function') {
                var opts = {};
                if (typeof hljs !== 'undefined') {
                    opts.highlight = function(code, lang) {
                        if (lang && hljs.getLanguage(lang)) {
                            return hljs.highlight(code, { language: lang }).value;
                        }
                        return hljs.highlightAuto(code).value;
                    };
                }
                return marked.parse(text, opts);
            }
            return marked(text);
        } catch (e) {
            return this._formatBasic(text);
        }
    };

    AIChatCore.prototype._formatBasic = function(text) {
        text = text.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
        text = text.replace(/\*(.*?)\*/g, '<em>$1</em>');
        text = text.replace(/\n/g, '<br>');
        return text;
    };

    AIChatCore.prototype._addCodeCopyButtons = function(container) {
        var COLLAPSE_HEIGHT = 150; // px threshold for collapsing
        var pres = container.querySelectorAll('pre');
        for (var i = 0; i < pres.length; i++) {
            var pre = pres[i];
            var wrapper = document.createElement('div');
            wrapper.className = 'ai-code-block-wrapper';
            pre.parentNode.insertBefore(wrapper, pre);
            wrapper.appendChild(pre);

            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'ai-code-copy-btn';
            btn.textContent = 'Copy';
            btn.addEventListener('click', (function(codeEl) {
                return function() {
                    var code = codeEl.querySelector('code');
                    var text = code ? code.textContent : codeEl.textContent;
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(text);
                    }
                    this.textContent = 'Copied!';
                    var b = this;
                    setTimeout(function() { b.textContent = 'Copy'; }, 1500);
                };
            })(pre));
            wrapper.appendChild(btn);

            // Collapsible: defer measurement until DOM is rendered
            (function(preEl, wrapperEl) {
                setTimeout(function() {
                    if (preEl.scrollHeight > COLLAPSE_HEIGHT) {
                        preEl.classList.add('ai-code-collapsed');
                        var toggle = document.createElement('button');
                        toggle.type = 'button';
                        toggle.className = 'ai-code-toggle-btn';
                        toggle.textContent = 'Show more';
                        toggle.addEventListener('click', function() {
                            var collapsed = preEl.classList.toggle('ai-code-collapsed');
                            toggle.textContent = collapsed ? 'Show more' : 'Show less';
                        });
                        wrapperEl.appendChild(toggle);
                    }
                }, 0);
            })(pre, wrapper);
        }
    };

    // ===================== Private: File Attachments =====================

    AIChatCore.prototype._handlePaste = function(e) {
        var items = e.clipboardData && e.clipboardData.items;
        if (!items) return;

        for (var i = 0; i < items.length; i++) {
            if (items[i].type.indexOf('image') !== -1) {
                e.preventDefault();
                var file = items[i].getAsFile();
                if (file) this._uploadFile(file);
                return;
            }
        }
    };

    // Image settings — read from data-image-settings on <body> (set by layout.php from DB config)
    var imgCfg = {};
    try { imgCfg = JSON.parse(document.body.getAttribute('data-image-settings') || '{}'); } catch (e) {}
    var AUTO_RESIZE_THRESHOLD = imgCfg.resize_threshold || (1.5 * 1024 * 1024);
    var RESIZE_MAX_DIMENSION  = imgCfg.max_dimension || 2048;
    var RESIZE_QUALITY        = (imgCfg.jpeg_quality || 85) / 100; // convert 0-100 to 0.0-1.0

    AIChatCore.prototype._uploadFile = function(file) {
        var self = this;

        // Only attempt resize for images above threshold
        if (file.type.startsWith('image/') && file.size > AUTO_RESIZE_THRESHOLD) {
            this._showUploadingIndicator(file.name + ' (resizing...)');
            this._resizeImage(file).then(function(resizedFile) {
                self._doUpload(resizedFile);
            }).catch(function() {
                // Resize failed — try uploading original
                self._doUpload(file);
            });
        } else {
            this._showUploadingIndicator(file.name);
            this._doUpload(file);
        }
    };

    /**
     * Resize an image file using Canvas API.
     * Returns a Promise that resolves to a resized File/Blob.
     */
    AIChatCore.prototype._resizeImage = function(file) {
        return new Promise(function(resolve, reject) {
            var img = new Image();
            var url = URL.createObjectURL(file);

            img.onload = function() {
                URL.revokeObjectURL(url);

                var w = img.naturalWidth;
                var h = img.naturalHeight;

                // Calculate new dimensions maintaining aspect ratio
                if (w > RESIZE_MAX_DIMENSION || h > RESIZE_MAX_DIMENSION) {
                    if (w > h) {
                        h = Math.round(h * (RESIZE_MAX_DIMENSION / w));
                        w = RESIZE_MAX_DIMENSION;
                    } else {
                        w = Math.round(w * (RESIZE_MAX_DIMENSION / h));
                        h = RESIZE_MAX_DIMENSION;
                    }
                }

                var canvas = document.createElement('canvas');
                canvas.width = w;
                canvas.height = h;

                var ctx = canvas.getContext('2d');
                ctx.drawImage(img, 0, 0, w, h);

                // Convert to blob — use JPEG for photos (smaller), keep PNG for transparent
                var outputType = (file.type === 'image/png') ? 'image/png' : 'image/jpeg';
                var quality = (outputType === 'image/jpeg') ? RESIZE_QUALITY : undefined;

                canvas.toBlob(function(blob) {
                    if (!blob) { reject(new Error('Canvas toBlob failed')); return; }

                    // If resized is still too large, try lower quality
                    if (blob.size > AUTO_RESIZE_THRESHOLD && outputType === 'image/jpeg') {
                        canvas.toBlob(function(blob2) {
                            if (!blob2) { resolve(blob); return; }
                            var ext = outputType === 'image/png' ? '.png' : '.jpg';
                            var resized = new File([blob2], file.name.replace(/\.[^.]+$/, ext), { type: outputType });
                            resolve(resized);
                        }, outputType, 0.6);
                    } else {
                        var ext = outputType === 'image/png' ? '.png' : '.jpg';
                        var resized = new File([blob], file.name.replace(/\.[^.]+$/, ext), { type: outputType });
                        resolve(resized);
                    }
                }, outputType, quality);
            };

            img.onerror = function() {
                URL.revokeObjectURL(url);
                reject(new Error('Failed to load image'));
            };

            img.src = url;
        });
    };

    AIChatCore.prototype._doUpload = function(file) {
        var self = this;
        var c = this.config;

        var formData = new FormData();
        formData.append('file', file);
        formData.append('_csrf_token', c.csrfToken);

        fetch('/admin/media/upload', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        })
        .then(function(res) {
            if (!res.ok) {
                return res.text().then(function(text) {
                    var msg;
                    try {
                        var j = JSON.parse(text);
                        msg = j.error || 'Upload failed';
                    } catch (e) {
                        msg = 'Upload failed (HTTP ' + res.status + ')';
                    }
                    throw new Error(msg);
                });
            }
            return res.json();
        })
        .then(function(data) {
            self._hideUploadingIndicator();
            if (data.success) {
                self.attachments.push({
                    url: data.url,
                    media_id: data.id,
                    mime_type: data.mime_type || file.type
                });
                self._renderAttachmentPreviews();
            } else {
                self._appendError('Upload failed: ' + (data.error || 'Unknown error'));
            }
        })
        .catch(function(err) {
            self._hideUploadingIndicator();
            self._appendError('File upload error: ' + (err.message || 'Network error'));
        });
    };

    AIChatCore.prototype._showUploadingIndicator = function(filename) {
        var el = this.config.attachPreviewEl;
        if (!el) return;

        var indicator = document.createElement('div');
        indicator.className = 'ai-attachment-thumb ai-uploading';
        indicator.id = 'ai-upload-indicator';
        indicator.innerHTML = '<div class="ai-upload-spinner"></div>';
        indicator.title = 'Uploading ' + filename + '...';
        el.appendChild(indicator);
    };

    AIChatCore.prototype._hideUploadingIndicator = function() {
        var indicator = document.getElementById('ai-upload-indicator');
        if (indicator) indicator.remove();
    };

    AIChatCore.prototype._renderAttachmentPreviews = function() {
        var el = this.config.attachPreviewEl;
        if (!el) return;

        el.innerHTML = '';
        var self = this;

        this.attachments.forEach(function(att, idx) {
            var thumb = document.createElement('div');
            thumb.className = 'ai-attachment-thumb';

            var img = document.createElement('img');
            img.src = att.url;
            img.alt = 'Attachment';
            thumb.appendChild(img);

            var removeBtn = document.createElement('button');
            removeBtn.className = 'ai-attachment-remove';
            removeBtn.innerHTML = '&times;';
            removeBtn.type = 'button';
            removeBtn.addEventListener('click', function() {
                self.attachments.splice(idx, 1);
                self._renderAttachmentPreviews();
            });
            thumb.appendChild(removeBtn);

            el.appendChild(thumb);
        });
    };

    AIChatCore.prototype._showDropOverlay = function(container) {
        if (this.dropOverlay) return;
        this.dropOverlay = document.createElement('div');
        this.dropOverlay.className = 'ai-file-drop-overlay';
        this.dropOverlay.textContent = 'Drop image to attach';
        container.style.position = 'relative';
        container.appendChild(this.dropOverlay);
    };

    AIChatCore.prototype._hideDropOverlay = function() {
        if (this.dropOverlay && this.dropOverlay.parentNode) {
            this.dropOverlay.parentNode.removeChild(this.dropOverlay);
        }
        this.dropOverlay = null;
    };

    // ===================== Private: Model Selector =====================

    AIChatCore.prototype._initModelSelector = function() {
        var c = this.config;
        var selectEl = c.headerEl ? c.headerEl.querySelector('.ai-model-select') : null;
        if (!selectEl) return;

        var self = this;

        // Restore from localStorage
        var saved = localStorage.getItem('litecms_ai_model');

        fetch(c.modelsEndpoint, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (!data.success) return;

            selectEl.innerHTML = '';
            (data.models || []).forEach(function(m) {
                var opt = document.createElement('option');
                opt.value = m.id;
                opt.textContent = m.display_name || m.id;
                if (m.id === (saved || data.current_model)) {
                    opt.selected = true;
                    self.currentModel = m.id;
                }
                selectEl.appendChild(opt);
            });

            selectEl.addEventListener('change', function() {
                self.currentModel = this.value;
                localStorage.setItem('litecms_ai_model', this.value);
            });
        })
        .catch(function() {
            // Model fetch failed — use default
        });
    };

    // ===================== Private: Context Meter =====================

    AIChatCore.prototype._initContextMeter = function() {
        var c = this.config;
        this._meterBarEl = c.headerEl ? c.headerEl.querySelector('.context-bar') : null;
        this._meterTextEl = c.headerEl ? c.headerEl.querySelector('.context-text') : null;
    };

    AIChatCore.prototype._updateContextMeter = function(usage) {
        if (!this._meterBarEl || !this._meterTextEl) return;

        var total = (usage.total_input_tokens || 0) + (usage.total_output_tokens || 0);
        var modelId = this.currentModel || '';
        var limit = usage.context_window || MODEL_CONTEXT_WINDOWS[modelId] || MODEL_CONTEXT_WINDOWS['_default'];
        var pct = Math.min(100, (total / limit) * 100);

        this._meterBarEl.style.width = pct + '%';
        this._meterTextEl.textContent = this._formatTokens(total) + ' / ' + this._formatTokens(limit);

        this._meterBarEl.className = 'context-bar';
        if (pct > 80) this._meterBarEl.classList.add('context-bar-danger');
        else if (pct > 60) this._meterBarEl.classList.add('context-bar-warning');
        else this._meterBarEl.classList.add('context-bar-ok');
    };

    AIChatCore.prototype._resetContextMeter = function() {
        if (this._meterBarEl) {
            this._meterBarEl.style.width = '0';
            this._meterBarEl.className = 'context-bar context-bar-ok';
        }
        if (this._meterTextEl) {
            var limit = MODEL_CONTEXT_WINDOWS[this.currentModel] || MODEL_CONTEXT_WINDOWS['_default'];
            this._meterTextEl.textContent = '0 / ' + this._formatTokens(limit);
        }
    };

    AIChatCore.prototype._formatTokens = function(n) {
        if (n >= 1000000) return (n / 1000000).toFixed(1) + 'M';
        if (n >= 1000) return (n / 1000).toFixed(1) + 'k';
        return n.toString();
    };

    // ===================== Private: Compact =====================

    AIChatCore.prototype._initCompactButton = function() {
        var c = this.config;
        this._compactBtn = c.headerEl ? c.headerEl.querySelector('[id$="compact-btn"]') : null;
        if (!this._compactBtn) return;

        var self = this;
        this._compactBtn.addEventListener('click', function() {
            self._doCompact();
        });
    };

    AIChatCore.prototype._updateCompactVisibility = function() {
        if (!this._compactBtn) return;
        this._compactBtn.style.display = (this.messageCount >= 6 && this.conversationId) ? '' : 'none';
    };

    AIChatCore.prototype._doCompact = function() {
        var self = this;
        var c = this.config;
        if (!c.compactEndpoint || !this.conversationId || this.isLoading) return;

        if (!confirm('Compact this conversation? Older messages will be summarized to reduce token usage.')) {
            return;
        }

        this._showLoading();

        var payload = { conversation_id: this.conversationId };
        if (this.currentModel) payload.model = this.currentModel;

        this._apiCall(c.compactEndpoint, payload)
            .then(function(data) {
                self._hideLoading();
                if (data.success && data.messages) {
                    self._clearMessages();
                    self.messageCount = data.messages.length;

                    data.messages.forEach(function(msg) {
                        self.appendMessage(msg.role, msg.content, {
                            attachments: msg.attachments,
                            isSummary: msg.is_summary
                        });
                    });

                    if (data.usage) {
                        self._updateContextMeter(data.usage);
                    }

                    self._appendSystemMessage(
                        'Conversation compacted. Reduced from ' +
                        self._formatTokens(data.tokens_before) + ' to ' +
                        self._formatTokens(data.tokens_after) + ' tokens.'
                    );
                    self._updateCompactVisibility();
                } else {
                    self._appendError(data.error || 'Compaction failed.');
                }
            })
            .catch(function() {
                self._hideLoading();
                self._appendError('Failed to compact conversation.');
            });
    };

    // ===================== Private: Conversation History =====================

    AIChatCore.prototype._initHistoryButton = function() {
        var c = this.config;
        this._historyBtn = c.headerEl ? c.headerEl.querySelector('[id$="history-btn"]') : null;
        this._historyDropdown = c.headerEl ?
            (c.headerEl.parentElement || c.headerEl).querySelector('[id$="history-dropdown"]') : null;

        if (!this._historyBtn) return;

        var self = this;
        this._historyBtn.addEventListener('click', function() {
            self._toggleHistory();
        });
    };

    AIChatCore.prototype._toggleHistory = function() {
        if (!this._historyDropdown) return;

        var isVisible = this._historyDropdown.style.display !== 'none';
        if (isVisible) {
            this._historyDropdown.style.display = 'none';
            return;
        }

        this._loadHistory();
    };

    AIChatCore.prototype._loadHistory = function() {
        var self = this;
        var c = this.config;
        if (!c.conversationsEndpoint || !this._historyDropdown) return;

        var url = c.conversationsEndpoint;
        if (c.contentId !== null && c.contentId !== undefined) {
            url += '?content_id=' + c.contentId;
        }

        fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (!data.success) return;

            self._historyDropdown.innerHTML = '';
            var convs = data.conversations || [];

            if (convs.length === 0) {
                var empty = document.createElement('div');
                empty.className = 'ai-history-item';
                empty.textContent = 'No conversations yet.';
                empty.style.color = 'var(--color-text-muted)';
                self._historyDropdown.appendChild(empty);
            } else {
                convs.forEach(function(conv) {
                    var item = document.createElement('div');
                    item.className = 'ai-history-item';
                    if (conv.id === self.conversationId) item.classList.add('active');

                    var title = document.createElement('span');
                    title.className = 'ai-history-title';
                    title.textContent = conv.title || 'Conversation #' + conv.id;
                    item.appendChild(title);

                    var date = document.createElement('span');
                    date.className = 'ai-history-date';
                    date.textContent = self._formatDate(conv.updated_at);
                    item.appendChild(date);

                    item.addEventListener('click', function() {
                        self._clearMessages();
                        self.conversationId = conv.id;
                        self.messageCount = conv.messages ? conv.messages.length : 0;

                        if (conv.messages) {
                            conv.messages.forEach(function(msg) {
                                self.appendMessage(msg.role, msg.content, {
                                    attachments: msg.attachments,
                                    isSummary: msg.is_summary
                                });
                            });
                        }

                        if (conv.usage) {
                            self._updateContextMeter(conv.usage);
                        }

                        self._updateCompactVisibility();
                        self._historyDropdown.style.display = 'none';
                    });

                    self._historyDropdown.appendChild(item);
                });
            }

            self._historyDropdown.style.display = '';
        })
        .catch(function() {});
    };

    // ===================== Private: Resizable Panel =====================

    AIChatCore.prototype._initResize = function() {
        var c = this.config;
        var panel = c.resizableEl;
        var handle = panel.querySelector('.ai-resize-handle');
        if (!handle) return;

        var startX, startWidth;
        var minWidth = 300, maxWidth = 800;

        function onMouseDown(e) {
            e.preventDefault();
            startX = e.clientX;
            startWidth = panel.offsetWidth;
            document.addEventListener('mousemove', onMouseMove);
            document.addEventListener('mouseup', onMouseUp);
            document.body.style.cursor = 'col-resize';
            document.body.style.userSelect = 'none';
        }

        function onMouseMove(e) {
            var diff = startX - e.clientX;
            var newWidth = Math.min(maxWidth, Math.max(minWidth, startWidth + diff));
            panel.style.width = newWidth + 'px';
            var layout = document.querySelector('.editor-layout');
            if (layout) {
                layout.style.gridTemplateColumns = '1fr 320px ' + newWidth + 'px';
            }
        }

        function onMouseUp() {
            document.removeEventListener('mousemove', onMouseMove);
            document.removeEventListener('mouseup', onMouseUp);
            document.body.style.cursor = '';
            document.body.style.userSelect = '';
            localStorage.setItem('litecms_ai_panel_width', panel.offsetWidth.toString());
            if (typeof tinymce !== 'undefined' && tinymce.activeEditor) {
                tinymce.activeEditor.execCommand('mceAutoResize');
            }
        }

        handle.addEventListener('mousedown', onMouseDown);

        // Restore saved width
        var saved = localStorage.getItem('litecms_ai_panel_width');
        if (saved) {
            var w = parseInt(saved, 10);
            if (w >= minWidth && w <= maxWidth) {
                panel.style.width = w + 'px';
            }
        }
    };

    // ===================== Private: Utilities =====================

    AIChatCore.prototype._apiCall = function(url, data) {
        return fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': this.config.csrfToken
            },
            body: JSON.stringify(data)
        }).then(function(res) {
            var ct = res.headers.get('content-type') || '';
            if (!res.ok || ct.indexOf('application/json') === -1) {
                return res.text().then(function(text) {
                    var clean = text.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
                    if (clean.length > 200) clean = clean.substring(0, 200) + '...';
                    throw new Error(clean || ('Server error (HTTP ' + res.status + ')'));
                });
            }
            return res.json();
        });
    };

    AIChatCore.prototype._escapeHtml = function(str) {
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    };

    AIChatCore.prototype._formatDate = function(dateStr) {
        if (!dateStr) return '';
        var d = new Date(dateStr);
        var now = new Date();
        var diff = now - d;
        if (diff < 86400000) {
            return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        }
        return d.toLocaleDateString([], { month: 'short', day: 'numeric' });
    };

    // Expose globally
    window.AIChatCore = AIChatCore;
})();

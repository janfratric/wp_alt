(function() {
    'use strict';

    // --- State ---
    var csrfToken = '';
    var contentType = '';
    var editorMode = 'html';
    var currentStep = 'setup';
    var generatedData = null;
    var isLoading = false;
    var core = null;

    // --- DOM refs ---
    var appEl;

    // --- Init ---
    function init() {
        appEl = document.getElementById('generator-app');
        if (!appEl) return;

        csrfToken = appEl.getAttribute('data-csrf') || '';

        // Type selection
        var typeButtons = appEl.querySelectorAll('.type-option');
        for (var i = 0; i < typeButtons.length; i++) {
            typeButtons[i].addEventListener('click', function() {
                onTypeSelected(this.getAttribute('data-type'));
            });
        }

        // Editor mode selection
        var modeButtons = appEl.querySelectorAll('.mode-option');
        for (var j = 0; j < modeButtons.length; j++) {
            modeButtons[j].addEventListener('click', function() {
                editorMode = this.getAttribute('data-mode');
                var allModes = appEl.querySelectorAll('.mode-option');
                for (var k = 0; k < allModes.length; k++) { allModes[k].classList.remove('active'); }
                this.classList.add('active');
            });
        }

        // Preview buttons
        var backBtn = document.getElementById('btn-back-to-chat');
        if (backBtn) backBtn.addEventListener('click', function() { goToStep('gathering'); });

        var draftBtn = document.getElementById('btn-create-draft');
        if (draftBtn) draftBtn.addEventListener('click', function() { createContent('draft'); });

        var publishBtn = document.getElementById('btn-create-publish');
        if (publishBtn) publishBtn.addEventListener('click', function() { createContent('published'); });
    }

    // --- AIChatCore instance (created on first type selection) ---
    function initChatCore() {
        if (core) return;

        core = new window.AIChatCore({
            messagesEl:      document.getElementById('generator-messages'),
            inputEl:         document.getElementById('generator-input'),
            sendBtnEl:       document.getElementById('generator-send'),
            headerEl:        document.getElementById('generator-chat-header'),
            attachPreviewEl: document.getElementById('generator-attach-preview'),
            attachBtnEl:     document.getElementById('generator-attach-btn'),
            chatEndpoint:    '/admin/generator/chat',
            compactEndpoint: '/admin/ai/compact',
            modelsEndpoint:  '/admin/ai/models/enabled',
            conversationsEndpoint: null,
            csrfToken:       csrfToken,
            contentId:       null,
            enableAttachments: true,
            enableModelSelector: true,
            enableContextMeter: true,
            enableCompact: true,
            enableConversationHistory: false,
            enableMarkdown: true,
            enableResizable: false,
            messageActions: null,
            extraPayload: function() {
                return {
                    content_type: contentType,
                    step: currentStep,
                    editor_mode: editorMode
                };
            },
            onAssistantMessage: function(content, data) {
                checkReadyState(data);
            }
        });
    }

    // --- Step Management ---
    function goToStep(step) {
        currentStep = step;
        var steps = ['setup', 'gathering', 'preview', 'created'];
        var currentIndex = steps.indexOf(step);

        for (var i = 0; i < steps.length; i++) {
            var panel = document.getElementById('step-' + steps[i]);
            var indicator = document.getElementById('step-ind-' + steps[i]);

            if (panel) {
                if (steps[i] === step) {
                    panel.classList.remove('hidden');
                } else {
                    panel.classList.add('hidden');
                }
            }

            if (indicator) {
                indicator.classList.remove('active', 'completed');
                if (i < currentIndex) {
                    indicator.classList.add('completed');
                } else if (i === currentIndex) {
                    indicator.classList.add('active');
                }
            }
        }

        if (step === 'gathering') {
            var inputEl = document.getElementById('generator-input');
            if (inputEl) inputEl.focus();
        }
    }

    // --- Setup ---
    function onTypeSelected(typeSlug) {
        contentType = typeSlug;
        generatedData = null;

        initChatCore();

        // Start fresh conversation
        core.newConversation();

        goToStep('gathering');

        // Send initial message
        var msg = 'I want to create a new ' + typeSlug + '. Please help me plan it.';
        core.sendMessage(msg);
    }

    function checkReadyState(data) {
        if (data.step === 'ready') {
            var messagesEl = document.getElementById('generator-messages');
            if (!messagesEl) return;

            var btnDiv = document.createElement('div');
            btnDiv.style.textAlign = 'center';
            btnDiv.style.margin = '1rem 0';
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-primary btn-generate';
            btn.textContent = 'Generate Page';
            btn.addEventListener('click', requestGeneration);
            btnDiv.appendChild(btn);
            messagesEl.appendChild(btnDiv);
            messagesEl.scrollTop = messagesEl.scrollHeight;
        }
    }

    function requestGeneration() {
        if (isLoading || !core) return;
        isLoading = true;

        var messagesEl = document.getElementById('generator-messages');
        var sendBtn = document.getElementById('generator-send');

        // Disable generate buttons
        if (messagesEl) {
            var genBtns = messagesEl.querySelectorAll('.btn-generate');
            for (var i = 0; i < genBtns.length; i++) {
                genBtns[i].disabled = true;
            }
        }
        if (sendBtn) sendBtn.disabled = true;

        // Show loading indicator
        core.appendMessage('user', 'Generate the page now based on everything we discussed.');

        apiCall('/admin/generator/chat', {
            message: 'Generate the page now based on everything we discussed.',
            conversation_id: core.getConversationId(),
            content_type: contentType,
            step: 'generating',
            editor_mode: editorMode,
            model: core.currentModel || undefined
        }).then(function(data) {
            isLoading = false;
            if (sendBtn) sendBtn.disabled = false;

            if (data.success && data.step === 'generated' && data.generated) {
                generatedData = data.generated;
                populatePreview(data.generated);
                goToStep('preview');
            } else if (data.success && data.step === 'generation_failed') {
                core.appendMessage('assistant', 'I had trouble generating structured output. Let me try again. ' + (data.response || ''));
                if (messagesEl) {
                    var btns = messagesEl.querySelectorAll('.btn-generate');
                    for (var j = 0; j < btns.length; j++) { btns[j].disabled = false; }
                }
            } else {
                core.appendMessage('assistant', 'Error: ' + (data.error || 'Generation failed.'));
            }
        }).catch(function() {
            isLoading = false;
            if (sendBtn) sendBtn.disabled = false;
            core.appendMessage('assistant', 'Error: Could not reach the server.');
        });
    }

    // --- Preview ---
    function populatePreview(data) {
        setText('preview-title', data.title || '');
        setText('preview-slug', data.slug || '');
        setText('preview-excerpt', data.excerpt || '');
        setText('preview-meta-title', data.meta_title || '');
        setText('preview-meta-desc', data.meta_description || '');

        var bodyEl = document.getElementById('preview-body');
        if (bodyEl) {
            if (data.editor_mode === 'elements' && data.elements && data.elements.length > 0) {
                var elHtml = '<h3>Elements</h3>';
                for (var i = 0; i < data.elements.length; i++) {
                    var el = data.elements[i];
                    var isNew = el.element_slug === '__new__';
                    elHtml += '<div class="card" style="margin-bottom:0.5rem;padding:0.75rem;">';
                    elHtml += '<strong>' + escapeHtml(el.element_slug) + '</strong>';
                    if (isNew && el.new_element) {
                        elHtml += ' <span class="badge" style="background:#f59e0b;color:#fff;">Proposed</span>';
                        elHtml += '<div style="font-size:0.85rem;margin-top:0.25rem;">' + escapeHtml(el.new_element.name || '') + '</div>';
                    }
                    if (el.slot_data) {
                        elHtml += '<div style="font-size:0.8rem;color:#666;margin-top:0.25rem;">';
                        for (var sk in el.slot_data) {
                            if (el.slot_data.hasOwnProperty(sk)) {
                                var sv = String(el.slot_data[sk]);
                                if (sv.length > 80) sv = sv.substring(0, 80) + '...';
                                elHtml += escapeHtml(sk) + ': ' + escapeHtml(sv) + '<br>';
                            }
                        }
                        elHtml += '</div>';
                    }
                    elHtml += '</div>';
                }
                bodyEl.innerHTML = elHtml;
            } else {
                bodyEl.innerHTML = data.body || '';
            }
        }

        var cfEl = document.getElementById('preview-custom-fields');
        if (cfEl && data.custom_fields && Object.keys(data.custom_fields).length > 0) {
            var html = '<h3>Custom Fields</h3><div class="preview-meta">';
            for (var key in data.custom_fields) {
                if (data.custom_fields.hasOwnProperty(key)) {
                    html += '<div><strong>' + escapeHtml(key) + ':</strong> ' +
                            escapeHtml(String(data.custom_fields[key])) + '</div>';
                }
            }
            html += '</div>';
            cfEl.innerHTML = html;
        } else if (cfEl) {
            cfEl.innerHTML = '';
        }
    }

    function setText(id, text) {
        var el = document.getElementById(id);
        if (el) el.textContent = text;
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // --- Create ---
    function createContent(status) {
        if (!generatedData || isLoading) return;

        isLoading = true;
        var draftBtn = document.getElementById('btn-create-draft');
        var publishBtn = document.getElementById('btn-create-publish');
        if (draftBtn) draftBtn.disabled = true;
        if (publishBtn) publishBtn.disabled = true;

        var createPayload = {
            title:            generatedData.title,
            slug:             generatedData.slug,
            body:             generatedData.body || '',
            excerpt:          generatedData.excerpt || '',
            meta_title:       generatedData.meta_title || '',
            meta_description: generatedData.meta_description || '',
            content_type:     contentType,
            status:           status,
            custom_fields:    generatedData.custom_fields || {},
            editor_mode:      editorMode
        };
        if (editorMode === 'elements' && generatedData.elements) {
            createPayload.elements = generatedData.elements;
        }

        apiCall('/admin/generator/create', createPayload).then(function(data) {
            isLoading = false;
            if (data.success) {
                var editLink = document.getElementById('btn-edit-content');
                if (editLink) editLink.href = data.edit_url;
                goToStep('created');
            } else {
                alert('Error creating content: ' + (data.error || 'Unknown error'));
                if (draftBtn) draftBtn.disabled = false;
                if (publishBtn) publishBtn.disabled = false;
            }
        }).catch(function() {
            isLoading = false;
            alert('Error: Could not reach the server.');
            if (draftBtn) draftBtn.disabled = false;
            if (publishBtn) publishBtn.disabled = false;
        });
    }

    // --- API Helper ---
    function apiCall(url, data) {
        return fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify(data)
        }).then(function(res) {
            return res.json();
        });
    }

    // --- Boot ---
    document.addEventListener('DOMContentLoaded', init);
})();

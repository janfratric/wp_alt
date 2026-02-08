(function() {
    'use strict';

    // --- State ---
    var csrfToken = '';
    var conversationId = null;
    var contentType = '';
    var currentStep = 'setup';
    var generatedData = null;
    var isLoading = false;

    // --- DOM refs ---
    var appEl;
    var messagesEl, inputEl, sendBtn;

    // --- Init ---
    function init() {
        appEl = document.getElementById('generator-app');
        if (!appEl) return;

        csrfToken = appEl.getAttribute('data-csrf') || '';
        messagesEl = document.getElementById('generator-messages');
        inputEl = document.getElementById('generator-input');
        sendBtn = document.getElementById('generator-send');

        // Type selection
        var typeButtons = appEl.querySelectorAll('.type-option');
        for (var i = 0; i < typeButtons.length; i++) {
            typeButtons[i].addEventListener('click', function() {
                onTypeSelected(this.getAttribute('data-type'));
            });
        }

        // Chat input
        if (sendBtn) {
            sendBtn.addEventListener('click', sendMessage);
        }
        if (inputEl) {
            inputEl.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    sendMessage();
                }
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

        if (step === 'gathering' && inputEl) {
            inputEl.focus();
        }
    }

    // --- Setup ---
    function onTypeSelected(typeSlug) {
        contentType = typeSlug;
        conversationId = null;
        generatedData = null;

        // Clear any previous chat
        if (messagesEl) messagesEl.innerHTML = '';

        goToStep('gathering');

        // Send initial message to start the conversation
        appendMessage('user', 'I want to create a new ' + typeSlug + '.');
        showLoading();

        apiCall('/admin/generator/chat', {
            message: 'I want to create a new ' + typeSlug + '. Please help me plan it.',
            conversation_id: null,
            content_type: contentType,
            step: 'gathering'
        }).then(function(data) {
            hideLoading();
            if (data.success) {
                conversationId = data.conversation_id;
                appendMessage('assistant', data.response);
                checkReadyState(data);
            } else {
                appendMessage('assistant', 'Error: ' + (data.error || 'Something went wrong.'));
            }
        }).catch(function(err) {
            hideLoading();
            appendMessage('assistant', 'Error: Could not reach the server.');
        });
    }

    // --- Chat ---
    function sendMessage() {
        if (isLoading) return;
        var message = inputEl ? inputEl.value.trim() : '';
        if (message === '') return;

        inputEl.value = '';
        appendMessage('user', message);
        showLoading();

        apiCall('/admin/generator/chat', {
            message: message,
            conversation_id: conversationId,
            content_type: contentType,
            step: 'gathering'
        }).then(function(data) {
            hideLoading();
            if (data.success) {
                conversationId = data.conversation_id;
                appendMessage('assistant', data.response);
                checkReadyState(data);
            } else {
                appendMessage('assistant', 'Error: ' + (data.error || 'Something went wrong.'));
            }
        }).catch(function(err) {
            hideLoading();
            appendMessage('assistant', 'Error: Could not reach the server.');
        });
    }

    function checkReadyState(data) {
        if (data.step === 'ready') {
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
            scrollMessages();
        }
    }

    function requestGeneration() {
        if (isLoading) return;
        showLoading();

        // Disable the generate button if it exists
        var genBtns = messagesEl.querySelectorAll('.btn-generate');
        for (var i = 0; i < genBtns.length; i++) {
            genBtns[i].disabled = true;
        }

        apiCall('/admin/generator/chat', {
            message: 'Generate the page now based on everything we discussed.',
            conversation_id: conversationId,
            content_type: contentType,
            step: 'generating'
        }).then(function(data) {
            hideLoading();
            if (data.success && data.step === 'generated' && data.generated) {
                generatedData = data.generated;
                populatePreview(data.generated);
                goToStep('preview');
            } else if (data.success && data.step === 'generation_failed') {
                appendMessage('assistant', 'I had trouble generating structured output. Let me try again. ' + (data.response || ''));
                var btns = messagesEl.querySelectorAll('.btn-generate');
                for (var j = 0; j < btns.length; j++) { btns[j].disabled = false; }
            } else {
                appendMessage('assistant', 'Error: ' + (data.error || 'Generation failed.'));
            }
        }).catch(function(err) {
            hideLoading();
            appendMessage('assistant', 'Error: Could not reach the server.');
        });
    }

    // --- Messages UI ---
    function appendMessage(role, content) {
        if (!messagesEl) return;

        var wrapper = document.createElement('div');
        wrapper.className = 'chat-message chat-message-' + role;

        var bubble = document.createElement('div');
        bubble.className = 'chat-bubble';

        if (role === 'assistant') {
            bubble.innerHTML = formatResponse(content);
        } else {
            bubble.textContent = content;
        }

        wrapper.appendChild(bubble);
        messagesEl.appendChild(wrapper);
        scrollMessages();
    }

    function formatResponse(text) {
        // Basic markdown-like formatting
        text = text.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
        text = text.replace(/\*(.*?)\*/g, '<em>$1</em>');
        text = text.replace(/\n/g, '<br>');
        return text;
    }

    function showLoading() {
        isLoading = true;
        var el = document.createElement('div');
        el.className = 'chat-loading';
        el.id = 'generator-loading';
        el.textContent = 'AI is thinking...';
        if (messagesEl) {
            messagesEl.appendChild(el);
            scrollMessages();
        }
        if (sendBtn) sendBtn.disabled = true;
    }

    function hideLoading() {
        isLoading = false;
        var el = document.getElementById('generator-loading');
        if (el) el.remove();
        if (sendBtn) sendBtn.disabled = false;
    }

    function scrollMessages() {
        if (messagesEl) {
            messagesEl.scrollTop = messagesEl.scrollHeight;
        }
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
            bodyEl.innerHTML = data.body || '';
        }

        // Custom fields
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

        apiCall('/admin/generator/create', {
            title:            generatedData.title,
            slug:             generatedData.slug,
            body:             generatedData.body,
            excerpt:          generatedData.excerpt || '',
            meta_title:       generatedData.meta_title || '',
            meta_description: generatedData.meta_description || '',
            content_type:     contentType,
            status:           status,
            custom_fields:    generatedData.custom_fields || {}
        }).then(function(data) {
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
        }).catch(function(err) {
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

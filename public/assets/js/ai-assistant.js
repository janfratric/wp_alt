/**
 * LiteCMS AI Assistant Panel
 * Chat interface integrated into the content editor.
 */
(function() {
    'use strict';

    var panelOpen = false;
    var conversationId = null;
    var contentId = null;
    var isLoading = false;
    var csrfToken = '';
    var loadingEl = null;

    document.addEventListener('DOMContentLoaded', function() {
        initAIPanel();
    });

    function initAIPanel() {
        var panel = document.getElementById('ai-panel');
        if (!panel) return;

        var form = document.getElementById('content-form');
        if (form && form.dataset.contentId) {
            contentId = parseInt(form.dataset.contentId, 10) || null;
        }

        var csrfInput = document.querySelector('input[name="_csrf_token"]');
        if (csrfInput) {
            csrfToken = csrfInput.value;
        }

        // Toggle button
        var toggleBtn = document.getElementById('ai-toggle-btn');
        if (toggleBtn) {
            toggleBtn.addEventListener('click', togglePanel);
        }

        // Close button (inside panel header)
        var closeBtn = document.getElementById('ai-close-btn');
        if (closeBtn) {
            closeBtn.addEventListener('click', function() {
                if (toggleBtn) toggleBtn.click();
            });
        }

        // Send button
        var sendBtn = document.getElementById('ai-send-btn');
        if (sendBtn) {
            sendBtn.addEventListener('click', sendMessage);
        }

        // Input: Enter to send, Shift+Enter for newline
        var input = document.getElementById('ai-input');
        if (input) {
            input.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    sendMessage();
                }
            });
        }

        // New conversation button
        var newConvBtn = document.getElementById('ai-new-conversation');
        if (newConvBtn) {
            newConvBtn.addEventListener('click', function() {
                conversationId = null;
                var messages = document.getElementById('ai-messages');
                if (messages) messages.innerHTML = '';
                appendSystemMessage('New conversation started.');
                var inp = document.getElementById('ai-input');
                if (inp) inp.focus();
            });
        }

        // Load existing conversation for this content
        if (contentId !== null) {
            loadConversation();
        }
    }

    function togglePanel() {
        var layout = document.querySelector('.editor-layout');
        var panel = document.getElementById('ai-panel');
        var toggleBtn = document.getElementById('ai-toggle-btn');

        if (!layout || !panel) return;

        panelOpen = !panelOpen;

        if (panelOpen) {
            layout.classList.add('ai-panel-open');
            panel.classList.add('active');
            if (toggleBtn) toggleBtn.setAttribute('aria-expanded', 'true');
            // Reflow TinyMCE after panel animation
            setTimeout(function() {
                if (typeof tinymce !== 'undefined' && tinymce.activeEditor) {
                    tinymce.activeEditor.execCommand('mceAutoResize');
                }
            }, 300);
            var input = document.getElementById('ai-input');
            if (input) input.focus();
        } else {
            layout.classList.remove('ai-panel-open');
            panel.classList.remove('active');
            if (toggleBtn) toggleBtn.setAttribute('aria-expanded', 'false');
        }
    }

    function sendMessage() {
        var input = document.getElementById('ai-input');
        if (!input) return;

        var message = input.value.trim();
        if (message === '' || isLoading) return;

        input.value = '';
        input.style.height = 'auto';

        appendMessage('user', message);
        showLoading();
        scrollToBottom();

        isLoading = true;
        var sendBtn = document.getElementById('ai-send-btn');
        if (sendBtn) sendBtn.disabled = true;

        var body = {
            message: message,
            content_id: contentId,
            conversation_id: conversationId
        };

        fetch('/admin/ai/chat', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify(body)
        })
        .then(function(res) {
            var contentType = res.headers.get('content-type') || '';
            if (!res.ok || contentType.indexOf('application/json') === -1) {
                return res.text().then(function(text) {
                    // Try to extract a useful message from non-JSON responses (e.g. PHP fatal errors)
                    var clean = text.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
                    if (clean.length > 200) clean = clean.substring(0, 200) + '...';
                    throw new Error(clean || ('Server error (HTTP ' + res.status + ')'));
                });
            }
            return res.json();
        })
        .then(function(data) {
            hideLoading();
            if (data.success) {
                conversationId = data.conversation_id;
                appendMessage('assistant', data.response);
            } else {
                appendError(data.error || 'An unknown error occurred.');
            }
        })
        .catch(function(err) {
            hideLoading();
            var msg = (err && err.message) ? err.message : 'Network error';
            appendError(msg);
        })
        .finally(function() {
            isLoading = false;
            if (sendBtn) sendBtn.disabled = false;
            scrollToBottom();
            if (input) input.focus();
        });
    }

    function appendMessage(role, content) {
        var messages = document.getElementById('ai-messages');
        if (!messages) return;

        // Remove empty-state placeholder if present
        var placeholder = messages.querySelector('.ai-empty-state');
        if (placeholder) placeholder.remove();

        var bubble = document.createElement('div');
        bubble.className = 'ai-message ai-message-' + role;

        var contentDiv = document.createElement('div');
        contentDiv.className = 'ai-message-content';

        if (role === 'user') {
            contentDiv.textContent = content;
            bubble.appendChild(contentDiv);
        } else if (role === 'assistant') {
            contentDiv.innerHTML = content;

            var actions = document.createElement('div');
            actions.className = 'ai-message-actions';

            var insertBtn = document.createElement('button');
            insertBtn.type = 'button';
            insertBtn.className = 'btn btn-sm ai-action-btn';
            insertBtn.textContent = 'Insert';
            insertBtn.title = 'Insert at cursor position in editor';
            insertBtn.addEventListener('click', function() {
                insertToEditor(content);
                showBtnFeedback(insertBtn, 'Inserted!');
            });

            var replaceBtn = document.createElement('button');
            replaceBtn.type = 'button';
            replaceBtn.className = 'btn btn-sm ai-action-btn';
            replaceBtn.textContent = 'Replace';
            replaceBtn.title = 'Replace all editor content';
            replaceBtn.addEventListener('click', function() {
                replaceEditorContent(content);
            });

            var copyBtn = document.createElement('button');
            copyBtn.type = 'button';
            copyBtn.className = 'btn btn-sm ai-action-btn';
            copyBtn.textContent = 'Copy';
            copyBtn.title = 'Copy to clipboard';
            copyBtn.addEventListener('click', function() {
                copyToClipboard(content);
                showBtnFeedback(copyBtn, 'Copied!');
            });

            actions.appendChild(insertBtn);
            actions.appendChild(replaceBtn);
            actions.appendChild(copyBtn);

            bubble.appendChild(contentDiv);
            bubble.appendChild(actions);
        }

        messages.appendChild(bubble);
        scrollToBottom();
    }

    function appendError(errorText) {
        var messages = document.getElementById('ai-messages');
        if (!messages) return;

        var bubble = document.createElement('div');
        bubble.className = 'ai-message ai-message-error';

        var contentDiv = document.createElement('div');
        contentDiv.className = 'ai-message-content';

        if (errorText.indexOf('API key') !== -1 || errorText.indexOf('Settings') !== -1) {
            contentDiv.innerHTML = escapeHtml(errorText)
                + ' <a href="/admin/settings">Go to Settings</a>';
        } else {
            contentDiv.textContent = errorText;
        }

        bubble.appendChild(contentDiv);
        messages.appendChild(bubble);
        scrollToBottom();
    }

    function appendSystemMessage(text) {
        var messages = document.getElementById('ai-messages');
        if (!messages) return;

        var el = document.createElement('div');
        el.className = 'ai-message ai-message-system';
        el.textContent = text;
        messages.appendChild(el);
        scrollToBottom();
    }

    function showLoading() {
        var messages = document.getElementById('ai-messages');
        if (!messages) return;

        loadingEl = document.createElement('div');
        loadingEl.className = 'ai-message ai-message-loading';
        loadingEl.innerHTML = '<div class="ai-typing-indicator">'
            + '<span></span><span></span><span></span>'
            + '</div>';
        messages.appendChild(loadingEl);
        scrollToBottom();
    }

    function hideLoading() {
        if (loadingEl && loadingEl.parentNode) {
            loadingEl.parentNode.removeChild(loadingEl);
            loadingEl = null;
        }
    }

    function loadConversation() {
        if (contentId === null) return;

        fetch('/admin/ai/conversations?content_id=' + contentId, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.success && data.conversations && data.conversations.length > 0) {
                var conv = data.conversations[0]; // most recent
                conversationId = conv.id;
                conv.messages.forEach(function(msg) {
                    appendMessage(msg.role, msg.content);
                });
            }
        })
        .catch(function() {
            // Silently ignore — panel starts empty
        });
    }

    function insertToEditor(htmlContent) {
        if (typeof tinymce !== 'undefined' && tinymce.activeEditor) {
            tinymce.activeEditor.focus();
            tinymce.activeEditor.insertContent(htmlContent);
        }
    }

    function replaceEditorContent(htmlContent) {
        if (!confirm('Replace all editor content with this response? This cannot be undone.')) {
            return;
        }
        if (typeof tinymce !== 'undefined' && tinymce.activeEditor) {
            tinymce.activeEditor.setContent(htmlContent);
        }
    }

    function copyToClipboard(text) {
        // Strip HTML for clipboard
        var tmp = document.createElement('div');
        tmp.innerHTML = text;
        var plainText = tmp.textContent || tmp.innerText || '';

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(plainText);
        } else {
            // Fallback
            var textarea = document.createElement('textarea');
            textarea.value = plainText;
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            document.body.removeChild(textarea);
        }
    }

    function showBtnFeedback(btn, text) {
        var original = btn.textContent;
        btn.textContent = text;
        btn.disabled = true;
        setTimeout(function() {
            btn.textContent = original;
            btn.disabled = false;
        }, 1500);
    }

    function scrollToBottom() {
        var container = document.getElementById('ai-messages');
        if (container) {
            container.scrollTop = container.scrollHeight;
        }
    }

    function escapeHtml(str) {
        var tmp = document.createElement('div');
        tmp.textContent = str;
        return tmp.innerHTML;
    }

})();

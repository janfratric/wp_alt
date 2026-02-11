/**
 * LiteCMS AI Assistant Panel
 * Thin wrapper around AIChatCore for the content editor side panel.
 */
(function() {
    'use strict';

    var panelOpen = false;
    var contentId = null;
    var csrfToken = '';
    var core = null;

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

        // Initialize AIChatCore
        core = new window.AIChatCore({
            messagesEl:     document.getElementById('ai-messages'),
            inputEl:        document.getElementById('ai-input'),
            sendBtnEl:      document.getElementById('ai-send-btn'),
            headerEl:       document.getElementById('ai-panel-header'),
            attachPreviewEl: document.getElementById('ai-attach-preview'),
            attachBtnEl:    document.getElementById('ai-attach-btn'),
            chatEndpoint:   '/admin/ai/chat',
            compactEndpoint: '/admin/ai/compact',
            modelsEndpoint: '/admin/ai/models/enabled',
            conversationsEndpoint: '/admin/ai/conversations',
            csrfToken:      csrfToken,
            contentId:      contentId,
            enableAttachments: true,
            enableModelSelector: true,
            enableContextMeter: true,
            enableCompact: true,
            enableConversationHistory: true,
            enableMarkdown: true,
            enableSpeechToText: true,
            transcribeEndpoint: '/admin/ai/transcribe',
            enableResizable: true,
            resizableEl:    panel,
            messageActions: function(content) {
                return [
                    {
                        label: 'Insert',
                        title: 'Insert at cursor position in editor',
                        feedback: 'Inserted!',
                        action: function() { insertToEditor(content); }
                    },
                    {
                        label: 'Replace',
                        title: 'Replace all editor content',
                        action: function() { replaceEditorContent(content); }
                    },
                    {
                        label: 'Copy',
                        title: 'Copy to clipboard',
                        feedback: 'Copied!',
                        action: function() { copyToClipboard(content); }
                    }
                ];
            },
            extraPayload: function() {
                return { content_id: contentId };
            }
        });

        // Toggle button
        var toggleBtn = document.getElementById('ai-toggle-btn');
        if (toggleBtn) {
            toggleBtn.addEventListener('click', togglePanel);
        }

        // Close button
        var closeBtn = document.getElementById('ai-close-btn');
        if (closeBtn) {
            closeBtn.addEventListener('click', function() {
                if (panelOpen) togglePanel();
            });
        }

        // New conversation button
        var newConvBtn = document.getElementById('ai-new-conversation');
        if (newConvBtn) {
            newConvBtn.addEventListener('click', function() {
                core.newConversation();
            });
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

            // Restore saved width
            var saved = localStorage.getItem('litecms_ai_panel_width');
            if (saved) {
                var w = parseInt(saved, 10);
                if (w >= 300 && w <= 800) {
                    panel.style.width = w + 'px';
                    layout.style.gridTemplateColumns = '1fr 320px ' + w + 'px';
                }
            }

            // Reflow TinyMCE after panel animation
            setTimeout(function() {
                if (typeof tinymce !== 'undefined' && tinymce.activeEditor) {
                    tinymce.activeEditor.execCommand('mceAutoResize');
                }
            }, 300);

            // Load conversation if not yet loaded
            if (contentId !== null && !core.getConversationId()) {
                core.loadConversation();
            }

            var input = document.getElementById('ai-input');
            if (input) input.focus();
        } else {
            layout.classList.remove('ai-panel-open');
            panel.classList.remove('active');
            layout.style.gridTemplateColumns = '';
            if (toggleBtn) toggleBtn.setAttribute('aria-expanded', 'false');
        }
    }

    // --- TinyMCE Integration ---

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
        var tmp = document.createElement('div');
        tmp.innerHTML = text;
        var plainText = tmp.textContent || tmp.innerText || '';

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(plainText);
        } else {
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

})();

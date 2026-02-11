(function() {
    'use strict';

    var panelOpen = false;
    var elementId = null;
    var csrfToken = '';
    var core = null;
    var lastSnapshot = null;

    document.addEventListener('DOMContentLoaded', function() {
        initElementAIPanel();
    });

    function initElementAIPanel() {
        var panel = document.getElementById('element-ai-panel');
        if (!panel) return;

        // Read element ID from form data attribute
        var form = document.getElementById('element-form');
        if (form && form.dataset.elementId) {
            elementId = parseInt(form.dataset.elementId, 10) || null;
        }

        var csrfInput = document.querySelector('input[name="_csrf_token"]');
        if (csrfInput) csrfToken = csrfInput.value;

        // Initialize AIChatCore
        core = new window.AIChatCore({
            messagesEl:      document.getElementById('element-ai-messages'),
            inputEl:         document.getElementById('element-ai-input'),
            sendBtnEl:       document.getElementById('element-ai-send'),
            headerEl:        document.getElementById('element-ai-header'),
            attachPreviewEl: document.getElementById('element-ai-attach-preview'),
            attachBtnEl:     document.getElementById('element-ai-attach-btn'),
            chatEndpoint:    '/admin/ai/element/chat',
            compactEndpoint: '/admin/ai/compact',
            modelsEndpoint:  '/admin/ai/models/enabled',
            conversationsEndpoint: '/admin/ai/element/conversations',
            csrfToken:        csrfToken,
            contentId:        null,
            enableAttachments: true,
            enableModelSelector: true,
            enableContextMeter: true,
            enableCompact: true,
            enableConversationHistory: true,
            enableMarkdown: true,
            enableSpeechToText: true,
            transcribeEndpoint: '/admin/ai/transcribe',
            enableResizable: true,
            resizableEl: panel,
            messageActions: function(content) {
                return [
                    { label: 'Copy', title: 'Copy to clipboard', feedback: 'Copied!',
                      action: function() { copyToClipboard(content); } }
                ];
            },
            onAssistantMessage: function(response) {
                autoApplyFromResponse(response);
            },
            extraPayload: function() {
                return {
                    element_id: elementId,
                    current_html: getCurrentHtml(),
                    current_css: getCurrentCss()
                };
            }
        });

        // Toggle button
        var toggleBtn = document.getElementById('element-ai-toggle');
        if (toggleBtn) toggleBtn.addEventListener('click', togglePanel);

        // Close button
        var closeBtn = document.getElementById('element-ai-close');
        if (closeBtn) closeBtn.addEventListener('click', function() {
            if (panelOpen) togglePanel();
        });

        // New conversation
        var newBtn = document.getElementById('element-ai-new');
        if (newBtn) newBtn.addEventListener('click', function() {
            core.newConversation();
        });
    }

    function togglePanel() {
        var grid = document.querySelector('.element-editor-grid');
        var panel = document.getElementById('element-ai-panel');
        var toggleBtn = document.getElementById('element-ai-toggle');

        if (!grid || !panel) return;

        panelOpen = !panelOpen;

        if (panelOpen) {
            grid.classList.add('ai-panel-open');
            panel.style.display = 'flex';
            if (toggleBtn) toggleBtn.setAttribute('aria-expanded', 'true');
        } else {
            grid.classList.remove('ai-panel-open');
            panel.style.display = 'none';
            if (toggleBtn) toggleBtn.setAttribute('aria-expanded', 'false');
        }
    }

    function getCurrentHtml() {
        var el = document.getElementById('el-html-template');
        return el ? el.value : '';
    }

    function getCurrentCss() {
        var el = document.getElementById('el-css');
        return el ? el.value : '';
    }

    /* ================================================================
       Code block extraction
       ================================================================ */
    function extractCodeBlock(content, lang) {
        var regex = new RegExp('```' + lang + '\\s*\\n([\\s\\S]*?)```', 'i');
        var match = content.match(regex);
        return match ? match[1].trim() : null;
    }

    function extractSlugFromCss(css) {
        var match = css.match(/\.lcms-el-([a-z0-9]+(?:-[a-z0-9]+)*)/);
        return match ? match[1] : null;
    }

    function extractElementJson(content) {
        var regex = /```element-json\s*\n([\s\S]*?)```/i;
        var match = content.match(regex);
        if (!match) return null;
        try {
            var parsed = JSON.parse(match[1].trim());
            if (parsed && typeof parsed === 'object') {
                return {
                    slots: Array.isArray(parsed.slots) ? parsed.slots : null,
                    sample_data: (parsed.sample_data && typeof parsed.sample_data === 'object') ? parsed.sample_data : null
                };
            }
        } catch (e) {
            // Invalid JSON
        }
        return null;
    }

    /* ================================================================
       Auto-apply AI response
       ================================================================ */
    function autoApplyFromResponse(content) {
        var htmlCode = extractCodeBlock(content, 'html');
        var cssCode = extractCodeBlock(content, 'css');
        var elementJson = extractElementJson(content);

        // Nothing to apply — AI was just discussing
        if (!htmlCode && !cssCode && !elementJson) return;

        var nameEl = document.getElementById('el-name');
        var slugEl = document.getElementById('el-slug');

        // Take snapshot before applying (include name/slug for revert)
        lastSnapshot = {
            html: getCurrentHtml(),
            css: getCurrentCss(),
            name: nameEl ? nameEl.value : '',
            slug: slugEl ? slugEl.value : '',
            slots: (typeof window.getElementSlots === 'function') ? window.getElementSlots() : [],
            content: (typeof window.getElementContent === 'function') ? window.getElementContent() : {}
        };

        // Apply HTML
        if (htmlCode) {
            var htmlEl = document.getElementById('el-html-template');
            if (htmlEl) htmlEl.value = htmlCode;
        }

        // Apply CSS
        if (cssCode) {
            var cssEl = document.getElementById('el-css');
            if (cssEl) cssEl.value = cssCode;

            // Auto-fill slug from CSS scoping class (needed for preview to match)
            var cssSlug = extractSlugFromCss(cssCode);
            if (cssSlug && slugEl && !slugEl.value) {
                slugEl.value = cssSlug;
                // Trigger input event so element-editor.js updates the CSS hint
                slugEl.dispatchEvent(new Event('input', { bubbles: true }));
            }
            // Auto-fill name from slug if empty
            if (cssSlug && nameEl && !nameEl.value) {
                nameEl.value = cssSlug.replace(/-/g, ' ').replace(/\b\w/g, function(c) { return c.toUpperCase(); });
            }
        }

        // Apply slots and sample content
        if (elementJson) {
            if (elementJson.slots && typeof window.setElementSlots === 'function') {
                window.setElementSlots(elementJson.slots);
            }
            if (elementJson.sample_data && typeof window.setElementContent === 'function') {
                window.setElementContent(elementJson.sample_data);
            }
        }

        // Refresh preview
        var previewBtn = document.getElementById('refresh-preview-btn');
        if (previewBtn) previewBtn.click();

        // Show save/revert bar
        showSaveRevertBar(lastSnapshot);
    }

    /* ================================================================
       Save / Revert bar
       ================================================================ */
    function showSaveRevertBar(snapshot) {
        // Remove any existing bar
        var existing = document.querySelector('.ai-apply-bar');
        if (existing) existing.remove();

        var bar = document.createElement('div');
        bar.className = 'ai-apply-bar';

        var label = document.createElement('span');
        label.className = 'ai-apply-label';
        label.textContent = 'AI changes applied';

        var saveBtn = document.createElement('button');
        saveBtn.type = 'button';
        saveBtn.className = 'btn btn-primary btn-sm';
        saveBtn.textContent = 'Save';
        saveBtn.addEventListener('click', function() {
            bar.remove();
            var form = document.getElementById('element-form');
            if (form) form.submit();
        });

        var revertBtn = document.createElement('button');
        revertBtn.type = 'button';
        revertBtn.className = 'btn btn-secondary btn-sm';
        revertBtn.textContent = 'Revert';
        revertBtn.addEventListener('click', function() {
            revertToSnapshot(snapshot);
            bar.remove();
        });

        bar.appendChild(label);
        bar.appendChild(saveBtn);
        bar.appendChild(revertBtn);

        // Insert above the preview section
        var previewHeader = document.querySelector('.preview-header');
        if (previewHeader) {
            previewHeader.parentNode.insertBefore(bar, previewHeader);
        } else {
            var codePanel = document.querySelector('.element-code-panel');
            if (codePanel) codePanel.insertBefore(bar, codePanel.firstChild);
        }
    }

    function revertToSnapshot(snapshot) {
        if (!snapshot) return;

        // Restore name and slug
        var nameEl = document.getElementById('el-name');
        if (nameEl) nameEl.value = snapshot.name;
        var slugEl = document.getElementById('el-slug');
        if (slugEl) {
            slugEl.value = snapshot.slug;
            slugEl.dispatchEvent(new Event('input', { bubbles: true }));
        }

        // Restore HTML
        var htmlEl = document.getElementById('el-html-template');
        if (htmlEl) htmlEl.value = snapshot.html;

        // Restore CSS
        var cssEl = document.getElementById('el-css');
        if (cssEl) cssEl.value = snapshot.css;

        // Restore slots
        if (typeof window.setElementSlots === 'function') {
            window.setElementSlots(snapshot.slots);
        }

        // Restore content
        if (typeof window.setElementContent === 'function') {
            window.setElementContent(snapshot.content);
        }

        // Refresh preview
        var previewBtn = document.getElementById('refresh-preview-btn');
        if (previewBtn) previewBtn.click();
    }

    /* ================================================================
       Utilities
       ================================================================ */
    function copyToClipboard(text) {
        var tmp = document.createElement('div');
        tmp.innerHTML = text;
        var plain = tmp.textContent || tmp.innerText || '';
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(plain);
        }
    }
})();

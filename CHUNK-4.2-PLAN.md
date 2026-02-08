# Chunk 4.2 — AI Chat Panel Frontend
## Detailed Implementation Plan

---

## Overview

This chunk builds the frontend AI chat panel integrated into the content editor. It adds a collapsible side panel with a chat interface, message rendering, "Insert into editor" / "Replace" functionality, conversation history loading, and proper error handling. The panel communicates with the existing backend endpoints built in Chunk 4.1 (`POST /admin/ai/chat`, `GET /admin/ai/conversations`).

No backend code changes are needed — all routes and controllers already exist.

---

## File Modification/Creation Order

Files are listed in dependency order. Modifications to existing files are marked with `[MODIFY]`.

| # | File | Action | Purpose |
|---|------|--------|---------|
| 1 | `public/assets/js/ai-assistant.js` | CREATE | Chat panel logic (fetch API calls, message rendering, insert-to-editor, conversation loading) |
| 2 | `public/assets/css/admin.css` | MODIFY | Add AI panel styles (split view, chat bubbles, responsive) |
| 3 | `templates/admin/content/edit.php` | MODIFY | Add collapsible AI assistant side panel HTML and toggle button; load ai-assistant.js |
| 4 | `app/Admin/ContentController.php` | MODIFY | Pass `contentId` to the edit template for the AI panel's use |

---

## Dependencies on Existing Code

### Backend API Endpoints (from Chunk 4.1 — no changes needed)

**POST `/admin/ai/chat`** — `AIController::chat()`
- Request: JSON `{"message": "...", "content_id": 123, "conversation_id": 456}`
- Success response: `{"success": true, "response": "...", "conversation_id": 456, "usage": {...}}`
- Error response: `{"success": false, "error": "..."}`
- Status codes: 200 (success), 400 (missing key / bad input), 502 (API error)

**GET `/admin/ai/conversations?content_id=123`** — `AIController::conversations()`
- Response: `{"success": true, "conversations": [{"id": 1, "content_id": 123, "messages": [{role, content, timestamp}], ...}]}`

### Existing Editor Integration Points

- TinyMCE instance: `tinymce.get('body')` or `tinymce.activeEditor`
  - Get content: `tinymce.activeEditor.getContent()`
  - Set content: `tinymce.activeEditor.setContent(html)`
  - Insert at cursor: `tinymce.activeEditor.insertContent(html)`
- CSRF token: `document.querySelector('input[name="_csrf_token"]').value`
- Content form: `#content-form`
- Editor layout: `.editor-layout` (currently 2-column grid: `1fr 320px`)
- Content ID: passed as `data-content-id` attribute on form (new addition)

---

## Detailed Specifications

### 1. `public/assets/js/ai-assistant.js` — CREATE

**Purpose**: Complete AI chat panel frontend logic.

**Architecture**: Single IIFE (Immediately Invoked Function Expression) that initializes when the DOM is ready. Uses vanilla JS following the same patterns as `admin.js` and `editor.js`.

**State Variables**:
```
- panelOpen: boolean — whether the AI panel is currently visible
- conversationId: int|null — current conversation ID from backend
- contentId: int|null — content being edited (from data attribute)
- isLoading: boolean — whether an API request is in flight
- csrfToken: string — CSRF token from form
```

**Functions**:

```
initAIPanel()
    Purpose: Entry point. Checks for AI panel DOM elements, sets up event listeners.
    Steps:
      1. Find #ai-panel element; if not present, abort (not on editor page).
      2. Read contentId from document.getElementById('content-form').dataset.contentId.
      3. Read csrfToken from document.querySelector('input[name="_csrf_token"]').value.
      4. Attach click handler to #ai-toggle-btn to call togglePanel().
      5. Attach click handler to #ai-send-btn to call sendMessage().
      6. Attach keydown handler to #ai-input for Enter (send) / Shift+Enter (newline).
      7. If contentId is not null (editing existing content), call loadConversation().

togglePanel()
    Purpose: Show/hide the AI panel and adjust editor layout.
    Steps:
      1. Toggle .ai-panel-open class on .editor-layout.
      2. Toggle .active class on #ai-panel.
      3. Toggle aria-expanded on #ai-toggle-btn.
      4. Update panelOpen state.
      5. If opening and TinyMCE is loaded, trigger resize to reflow editor.

sendMessage()
    Purpose: Send a user message to the AI backend.
    Steps:
      1. Read message from #ai-input textarea; trim. If empty, return.
      2. If isLoading, return (prevent double-send).
      3. Clear #ai-input.
      4. Append user message bubble to #ai-messages via appendMessage('user', message).
      5. Show loading indicator via showLoading().
      6. Scroll #ai-messages to bottom.
      7. Set isLoading = true.
      8. Build request body:
         {
           message: userMessage,
           content_id: contentId,
           conversation_id: conversationId
         }
         Also include current editor content as context in the message if the user's message
         doesn't already contain substantial text:
         — The backend's buildSystemPrompt() already includes a body excerpt from the database,
           but the user may have unsaved edits. So we append the current TinyMCE content
           to the message only on the first message of a conversation (or when the user
           explicitly references "my content" / "the page").
         — Simplified approach: always send current editor content in a separate field:
         {
           message: userMessage,
           content_id: contentId,
           conversation_id: conversationId
         }
         (The backend already handles content context via buildSystemPrompt, using
         the database content. For unsaved edits, the user can paste content into chat.)
      9. POST to /admin/ai/chat with JSON body and CSRF header.
      10. On success:
          - Hide loading indicator.
          - Set conversationId from response.
          - Append assistant message bubble via appendMessage('assistant', response.response).
          - Scroll to bottom.
      11. On error:
          - Hide loading indicator.
          - Append error message bubble via appendError(error text).
          - Scroll to bottom.
      12. Set isLoading = false.
      13. Re-focus #ai-input.

appendMessage(role, content)
    Purpose: Add a message bubble to the chat display.
    Steps:
      1. Create div.ai-message.ai-message-{role}.
      2. If role === 'assistant':
         a. Create div.ai-message-content, set innerHTML to rendered content.
            — AI responses may contain HTML. Render it as HTML.
         b. Create div.ai-message-actions with:
            - "Insert" button (class="btn btn-sm ai-insert-btn") — calls insertToEditor(content).
            - "Replace" button (class="btn btn-sm ai-replace-btn") — calls replaceEditorContent(content).
            - "Copy" button (class="btn btn-sm ai-copy-btn") — copies content to clipboard.
      3. If role === 'user':
         a. Create div.ai-message-content, set textContent (NOT innerHTML — user messages are plain text).
      4. Append to #ai-messages.
      5. Scroll to bottom.

appendError(errorText)
    Purpose: Show an error message in the chat area.
    Steps:
      1. Create div.ai-message.ai-message-error.
      2. Set textContent to errorText.
      3. If error mentions "API key" or "Settings", add a link to /admin/settings.
      4. Append to #ai-messages.

showLoading() / hideLoading()
    Purpose: Show/hide a typing indicator.
    Steps:
      1. showLoading(): Create div.ai-message.ai-message-loading with animated dots,
         append to #ai-messages, store reference.
      2. hideLoading(): Remove the loading element if it exists.

loadConversation()
    Purpose: Load existing conversation history for the current content item.
    Steps:
      1. If contentId is null, return.
      2. GET /admin/ai/conversations?content_id={contentId} with CSRF header.
      3. On success:
         - If conversations array is non-empty, take the first (most recent).
         - Set conversationId = conversation.id.
         - For each message in conversation.messages, call appendMessage(msg.role, msg.content).
         - Scroll to bottom.
      4. On error: silently ignore (panel starts empty).

insertToEditor(htmlContent)
    Purpose: Insert AI-generated HTML into TinyMCE at cursor position.
    Steps:
      1. If tinymce.activeEditor exists:
         tinymce.activeEditor.insertContent(htmlContent)
      2. Show brief "Inserted!" feedback on the button.

replaceEditorContent(htmlContent)
    Purpose: Replace the entire TinyMCE editor content with AI-generated HTML.
    Steps:
      1. If tinymce.activeEditor exists:
         - Confirm with user: "Replace all editor content? This cannot be undone."
         - If confirmed: tinymce.activeEditor.setContent(htmlContent)
      2. Show brief "Replaced!" feedback on the button.

copyToClipboard(text)
    Purpose: Copy text to clipboard.
    Steps:
      1. navigator.clipboard.writeText(text).
      2. Show brief "Copied!" feedback on button.

scrollToBottom()
    Purpose: Scroll the messages container to the bottom.
    Steps:
      1. var container = document.getElementById('ai-messages');
         container.scrollTop = container.scrollHeight;

escapeHtml(str)
    Purpose: Escape HTML entities for safe display in text contexts.
    Steps:
      1. Use a temporary div element to escape text:
         var tmp = document.createElement('div');
         tmp.textContent = str;
         return tmp.innerHTML;
```

**Full Code Template**:

```javascript
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
        .then(function(res) { return res.json(); })
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
            appendError('Network error. Please check your connection and try again.');
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

        if (role === 'assistant') {
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
        } else {
            contentDiv.textContent = content;
            bubble.appendChild(contentDiv);
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
```

---

### 2. `public/assets/css/admin.css` — MODIFY

**Purpose**: Add AI panel styles. Insert before the responsive media query section (before line 910).

**New CSS to add** (append before `/* --- Responsive: Tablet & Mobile --- */`):

```css
/* --- AI Assistant Panel --- */
.ai-toggle-wrapper {
    display: flex;
    justify-content: flex-end;
    margin-bottom: 0.75rem;
}

#ai-toggle-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.4rem 0.85rem;
    background: var(--color-primary);
    color: var(--color-white);
    border: none;
    border-radius: 6px;
    font-size: 0.85rem;
    font-weight: 500;
    cursor: pointer;
    transition: background var(--transition-fast);
}

#ai-toggle-btn:hover {
    background: var(--color-primary-hover);
}

#ai-toggle-btn .ai-icon {
    font-size: 1rem;
}

/* Panel itself — hidden by default */
#ai-panel {
    display: none;
    flex-direction: column;
    background: var(--color-white);
    border-radius: var(--card-radius);
    box-shadow: var(--card-shadow);
    overflow: hidden;
    min-height: 0;
}

#ai-panel.active {
    display: flex;
}

/* Editor layout expands to 3 columns when AI panel is open */
.editor-layout.ai-panel-open {
    grid-template-columns: 1fr 320px 380px;
}

/* AI Panel Header */
.ai-panel-header {
    padding: 0.75rem 1rem;
    border-bottom: 1px solid var(--color-border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-weight: 600;
    font-size: 0.95rem;
    flex-shrink: 0;
}

.ai-panel-header-actions {
    display: flex;
    gap: 0.4rem;
}

.ai-panel-header-actions button {
    background: none;
    border: 1px solid var(--color-border);
    border-radius: 4px;
    padding: 0.2rem 0.5rem;
    font-size: 0.75rem;
    cursor: pointer;
    color: var(--color-text-muted);
    transition: all var(--transition-fast);
}

.ai-panel-header-actions button:hover {
    background: var(--color-border-light);
    color: var(--color-text);
}

/* Messages Container */
#ai-messages {
    flex: 1;
    overflow-y: auto;
    padding: 1rem;
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    min-height: 300px;
    max-height: calc(100vh - 300px);
}

/* Empty state */
.ai-empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    height: 100%;
    min-height: 200px;
    text-align: center;
    color: var(--color-text-muted);
    font-size: 0.9rem;
    padding: 2rem 1rem;
}

.ai-empty-state .ai-empty-icon {
    font-size: 2rem;
    margin-bottom: 0.75rem;
    opacity: 0.5;
}

/* Message Bubbles */
.ai-message {
    max-width: 95%;
    animation: aiFadeIn 0.2s ease;
}

@keyframes aiFadeIn {
    from { opacity: 0; transform: translateY(4px); }
    to { opacity: 1; transform: translateY(0); }
}

.ai-message-user {
    align-self: flex-end;
}

.ai-message-user .ai-message-content {
    background: var(--color-primary);
    color: var(--color-white);
    border-radius: 12px 12px 2px 12px;
    padding: 0.6rem 0.85rem;
    font-size: 0.9rem;
    line-height: 1.5;
    word-wrap: break-word;
    white-space: pre-wrap;
}

.ai-message-assistant {
    align-self: flex-start;
}

.ai-message-assistant .ai-message-content {
    background: var(--color-border-light);
    color: var(--color-text);
    border-radius: 12px 12px 12px 2px;
    padding: 0.75rem 0.85rem;
    font-size: 0.9rem;
    line-height: 1.6;
    word-wrap: break-word;
}

/* Style HTML content inside assistant messages */
.ai-message-assistant .ai-message-content h1,
.ai-message-assistant .ai-message-content h2,
.ai-message-assistant .ai-message-content h3 {
    margin: 0.5rem 0 0.3rem;
    font-size: 1.05rem;
}

.ai-message-assistant .ai-message-content p {
    margin: 0.3rem 0;
}

.ai-message-assistant .ai-message-content ul,
.ai-message-assistant .ai-message-content ol {
    padding-left: 1.25rem;
    margin: 0.3rem 0;
}

.ai-message-assistant .ai-message-content code {
    background: rgba(0,0,0,0.06);
    padding: 0.1rem 0.35rem;
    border-radius: 3px;
    font-family: var(--font-mono);
    font-size: 0.85em;
}

/* Message Action Buttons */
.ai-message-actions {
    display: flex;
    gap: 0.4rem;
    margin-top: 0.4rem;
    padding-top: 0.35rem;
}

.ai-action-btn {
    padding: 0.2rem 0.5rem;
    font-size: 0.75rem;
    background: var(--color-white);
    border: 1px solid var(--color-border);
    border-radius: 4px;
    color: var(--color-text-muted);
    cursor: pointer;
    transition: all var(--transition-fast);
}

.ai-action-btn:hover {
    background: var(--color-primary-light);
    color: var(--color-primary);
    border-color: var(--color-primary);
}

/* Error Messages */
.ai-message-error .ai-message-content {
    background: var(--color-error-bg);
    color: #991b1b;
    border: 1px solid var(--color-error-border);
    border-radius: 8px;
    padding: 0.6rem 0.85rem;
    font-size: 0.85rem;
}

.ai-message-error a {
    color: var(--color-primary);
    text-decoration: underline;
}

/* System Messages */
.ai-message-system {
    align-self: center;
    font-size: 0.8rem;
    color: var(--color-text-muted);
    padding: 0.25rem 0.75rem;
    background: var(--color-border-light);
    border-radius: 12px;
}

/* Loading / Typing Indicator */
.ai-message-loading {
    align-self: flex-start;
}

.ai-typing-indicator {
    display: flex;
    gap: 4px;
    padding: 0.75rem 1rem;
    background: var(--color-border-light);
    border-radius: 12px 12px 12px 2px;
    width: fit-content;
}

.ai-typing-indicator span {
    width: 7px;
    height: 7px;
    background: var(--color-text-muted);
    border-radius: 50%;
    animation: aiTypingBounce 1.4s ease-in-out infinite;
}

.ai-typing-indicator span:nth-child(2) {
    animation-delay: 0.2s;
}

.ai-typing-indicator span:nth-child(3) {
    animation-delay: 0.4s;
}

@keyframes aiTypingBounce {
    0%, 60%, 100% { transform: translateY(0); opacity: 0.4; }
    30% { transform: translateY(-4px); opacity: 1; }
}

/* Input Area */
.ai-panel-input {
    border-top: 1px solid var(--color-border);
    padding: 0.75rem;
    display: flex;
    gap: 0.5rem;
    align-items: flex-end;
    flex-shrink: 0;
}

.ai-panel-input textarea {
    flex: 1;
    resize: none;
    border: 1px solid var(--color-border);
    border-radius: 8px;
    padding: 0.5rem 0.75rem;
    font-size: 0.9rem;
    font-family: inherit;
    line-height: 1.4;
    max-height: 120px;
    min-height: 38px;
    transition: border-color var(--transition-fast);
}

.ai-panel-input textarea:focus {
    outline: none;
    border-color: var(--color-primary);
    box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.1);
}

#ai-send-btn {
    flex-shrink: 0;
    width: 38px;
    height: 38px;
    border-radius: 8px;
    background: var(--color-primary);
    color: var(--color-white);
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.1rem;
    transition: background var(--transition-fast);
}

#ai-send-btn:hover {
    background: var(--color-primary-hover);
}

#ai-send-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
```

**Responsive additions** (add inside the existing `@media (max-width: 768px)` block):

```css
    /* AI panel goes full-width on mobile */
    .editor-layout.ai-panel-open {
        grid-template-columns: 1fr;
    }

    #ai-panel.active {
        position: fixed;
        inset: 0;
        z-index: 1000;
        border-radius: 0;
        max-height: 100vh;
    }

    #ai-messages {
        max-height: calc(100vh - 140px);
    }
```

---

### 3. `templates/admin/content/edit.php` — MODIFY

**Changes**:
1. Add `data-content-id` attribute to the form element.
2. Add the AI toggle button above the editor layout.
3. Add the AI panel as a third column inside `.editor-layout`.
4. Add `<script src="/assets/js/ai-assistant.js"></script>` after the editor.js script.

**Updated template** (full file):

```php
<?php $this->layout('admin/layout'); ?>

<div class="page-header">
    <h1><?= $isNew ? 'Create Content' : 'Edit: ' . $this->e($content['title']) ?></h1>
    <div style="display:flex;gap:0.5rem;align-items:center;">
        <a href="/admin/content" class="btn">← Back to Content</a>
        <button type="button" id="ai-toggle-btn" aria-expanded="false"
                title="Toggle AI Assistant">
            <span class="ai-icon">&#9733;</span> AI Assistant
        </button>
    </div>
</div>

<form method="POST"
      action="<?= $isNew ? '/admin/content' : '/admin/content/' . (int)$content['id'] ?>"
      id="content-form"
      data-content-id="<?= $isNew ? '' : (int)$content['id'] ?>">
    <?= $this->csrfField() ?>
    <?php if (!$isNew): ?>
        <input type="hidden" name="_method" value="PUT">
    <?php endif; ?>

    <div class="editor-layout">
        <!-- Main Column -->
        <div class="editor-main">
            <div class="form-group">
                <label for="title">Title <span style="color:var(--color-error);">*</span></label>
                <input type="text" id="title" name="title"
                       value="<?= $this->e($content['title']) ?>"
                       required maxlength="255"
                       placeholder="Enter title...">
            </div>

            <div class="form-group">
                <label for="slug">Slug</label>
                <div class="slug-field">
                    <span class="slug-prefix">/</span>
                    <input type="text" id="slug" name="slug"
                           value="<?= $this->e($content['slug']) ?>"
                           placeholder="auto-generated-from-title">
                </div>
            </div>

            <div class="form-group">
                <label for="body">Body</label>
                <textarea id="body" name="body" rows="20"><?= $content['body'] ?></textarea>
            </div>

            <div class="form-group">
                <label for="excerpt">Excerpt</label>
                <textarea id="excerpt" name="excerpt" rows="3"
                          placeholder="Brief summary for listings..."><?= $this->e($content['excerpt'] ?? '') ?></textarea>
            </div>
        </div>

        <!-- Sidebar Column -->
        <div class="editor-sidebar">
            <!-- Publish Card -->
            <div class="card">
                <div class="card-header">Publish</div>
                <div class="card-body">
                    <div class="form-group">
                        <label for="type">Type</label>
                        <select id="type" name="type">
                            <option value="page" <?= ($content['type'] ?? 'page') === 'page' ? 'selected' : '' ?>>Page</option>
                            <option value="post" <?= ($content['type'] ?? '') === 'post' ? 'selected' : '' ?>>Post</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="status">Status</label>
                        <select id="status" name="status">
                            <option value="draft" <?= ($content['status'] ?? 'draft') === 'draft' ? 'selected' : '' ?>>Draft</option>
                            <option value="published" <?= ($content['status'] ?? '') === 'published' ? 'selected' : '' ?>>Published</option>
                            <option value="archived" <?= ($content['status'] ?? '') === 'archived' ? 'selected' : '' ?>>Archived</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="published_at">Publish Date</label>
                        <?php
                        $pubAt = $content['published_at'] ?? '';
                        $pubAtValue = '';
                        if ($pubAt !== '' && $pubAt !== null) {
                            $ts = strtotime($pubAt);
                            if ($ts !== false) {
                                $pubAtValue = date('Y-m-d\TH:i', $ts);
                            }
                        }
                        ?>
                        <input type="datetime-local" id="published_at" name="published_at"
                               value="<?= $this->e($pubAtValue) ?>">
                    </div>

                    <div class="form-group">
                        <label for="sort_order">Sort Order</label>
                        <input type="number" id="sort_order" name="sort_order"
                               value="<?= (int)($content['sort_order'] ?? 0) ?>" min="0">
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary" style="width:100%;">
                            <?= $isNew ? 'Create' : 'Update' ?>
                        </button>
                    </div>
                </div>
            </div>

            <!-- SEO Card -->
            <div class="card">
                <div class="card-header">SEO</div>
                <div class="card-body">
                    <div class="form-group">
                        <label for="meta_title">Meta Title</label>
                        <input type="text" id="meta_title" name="meta_title"
                               value="<?= $this->e($content['meta_title'] ?? '') ?>"
                               maxlength="255"
                               placeholder="Custom title for search engines">
                    </div>
                    <div class="form-group mb-0">
                        <label for="meta_description">Meta Description</label>
                        <textarea id="meta_description" name="meta_description"
                                  rows="3" maxlength="320"
                                  placeholder="Brief description for search results..."><?= $this->e($content['meta_description'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Featured Image Card -->
            <div class="card">
                <div class="card-header">Featured Image</div>
                <div class="card-body">
                    <div class="form-group mb-0">
                        <?php
                        $featuredImg = $content['featured_image'] ?? '';
                        $hasImage = ($featuredImg !== '' && $featuredImg !== null);
                        ?>
                        <div class="featured-image-preview"
                             style="<?= $hasImage ? '' : 'display:none;' ?>">
                            <img id="featured-image-preview-img"
                                 src="<?= $this->e((string)$featuredImg) ?>"
                                 alt="Featured image preview">
                        </div>
                        <input type="hidden" id="featured_image" name="featured_image"
                               value="<?= $this->e((string)$featuredImg) ?>">
                        <div class="featured-image-actions">
                            <button type="button" class="btn btn-sm" id="featured-image-browse">
                                Browse Media
                            </button>
                            <button type="button" class="btn btn-sm"
                                    id="featured-image-remove"
                                    style="<?= $hasImage ? '' : 'display:none;' ?>">
                                Remove
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- AI Assistant Panel (hidden by default, shown when toggled) -->
        <div id="ai-panel">
            <div class="ai-panel-header">
                <span>AI Assistant</span>
                <div class="ai-panel-header-actions">
                    <button type="button" id="ai-new-conversation" title="New conversation">New</button>
                    <button type="button" onclick="document.getElementById('ai-toggle-btn').click()" title="Close panel">&times;</button>
                </div>
            </div>
            <div id="ai-messages">
                <div class="ai-empty-state">
                    <div class="ai-empty-icon">&#9733;</div>
                    <p>Ask the AI assistant to help write, edit, or improve your content.</p>
                    <p style="font-size:0.8rem;margin-top:0.25rem;">Try: "Write an introduction for this page" or "Make this text more concise"</p>
                </div>
            </div>
            <div class="ai-panel-input">
                <textarea id="ai-input" rows="1"
                          placeholder="Ask the AI assistant..."
                          autocomplete="off"></textarea>
                <button type="button" id="ai-send-btn" title="Send message">&#10148;</button>
            </div>
        </div>
    </div>
</form>

<script src="/assets/js/editor.js"></script>
<script src="/assets/js/ai-assistant.js"></script>
```

**Key differences from current template**:
- Line 5-9: Page header now wraps the "Back" link and "AI Assistant" toggle button together.
- Line 12: `<form>` tag gains `data-content-id` attribute (empty for new content, integer for editing).
- Lines 131-148: New `#ai-panel` div added as the third child of `.editor-layout`.
- Line 155: New `<script>` tag for `ai-assistant.js`.

---

### 4. `app/Admin/ContentController.php` — MODIFY

**Change**: No data changes needed. The content `id` is already available in `$content['id']` in the template. The template reads it directly. The `data-content-id` attribute on the form is populated from `$content['id']` which is already passed to the template.

For new content (`create` action), `$content['id']` is `null`, and the template outputs an empty `data-content-id=""`. The AI panel JS checks for this and skips conversation loading when empty.

**No PHP code changes required for this file.** The existing template data includes everything the AI panel needs.

---

## CSRF Handling for JSON Requests

The AI chat panel sends JSON via `fetch()` with `Content-Type: application/json`. In this case, `$_POST` is not populated — the backend reads from `php://input` instead.

**Confirmed: No changes needed.** The existing `CsrfMiddleware` (lines 22-24 of `app/Auth/CsrfMiddleware.php`) already checks the `X-CSRF-Token` header as a fallback when `$_POST['_csrf_token']` is empty:

```php
// Also accept token from header (for AJAX/fetch requests)
if ($token === '') {
    $token = (string) ($request->server('HTTP_X_CSRF_TOKEN') ?? '');
}
```

The `ai-assistant.js` sends the CSRF token via the `X-CSRF-Token` header in all fetch requests, which is validated by this existing code path.

---

## Acceptance Test Procedures

### Test 1: Toggle AI panel — panel opens/closes; editor width adjusts
```
1. Navigate to /admin/content/create (or edit existing content).
2. Verify the "AI Assistant" toggle button is visible in the page header.
3. Click "AI Assistant" button.
4. Verify: AI panel slides in as a third column to the right of the sidebar.
5. Verify: Editor layout adjusts (main column narrows to accommodate panel).
6. Verify: Panel shows the empty state message.
7. Click the X button in the panel header or the toggle button again.
8. Verify: Panel closes, editor layout returns to normal 2-column.
```

### Test 2: Send a message — AI response appears in chat bubbles with loading indicator
```
1. Open the AI panel (toggle).
2. Type "Write a short welcome message for a bakery website" in the input.
3. Press Enter (or click the send arrow).
4. Verify: User message appears as a blue bubble on the right.
5. Verify: Typing indicator (animated dots) appears on the left.
6. Verify: Send button is disabled while loading.
7. Wait for response.
8. Verify: Typing indicator disappears.
9. Verify: AI response appears as a gray bubble on the left.
10. Verify: "Insert", "Replace", "Copy" buttons appear below the AI response.
```

### Test 3: Click "Insert" on an AI response — HTML is inserted into TinyMCE editor
```
1. Create or edit content. Add some text in the TinyMCE editor.
2. Open AI panel, send a message asking for content.
3. When AI responds, click the "Insert" button.
4. Verify: AI-generated HTML is inserted into TinyMCE at the cursor position.
5. Verify: Existing editor content is preserved (not replaced).
6. Verify: "Insert" button briefly shows "Inserted!" feedback.
```

### Test 4: Navigate away and back to same content — conversation history is restored
```
1. Edit an existing content item (e.g., /admin/content/5/edit).
2. Open AI panel, send 2-3 messages.
3. Navigate to /admin/content (content list).
4. Navigate back to /admin/content/5/edit.
5. Open AI panel.
6. Verify: Previous messages are loaded from the server.
7. Verify: Conversation continues with context (send another message that references earlier).
```

### Test 5: With no API key configured — panel shows a message with link to settings
```
1. Ensure no Claude API key is saved in Settings.
2. Open the AI panel on any content editor.
3. Type a message and send.
4. Verify: Error message appears: "Claude API key is not configured..."
5. Verify: Error includes a clickable link to /admin/settings.
```

### Test 6: Send multiple messages — conversation context is maintained
```
1. Open AI panel.
2. Send: "I'm building a page about our team."
3. Wait for response.
4. Send: "Now add a section about our mission."
5. Wait for response.
6. Verify: The second response references or builds on the first (conversation context works).
7. Verify: Both user and assistant messages are visible in the chat history.
```

### Test 7: "Replace" button replaces editor content
```
1. Add text in TinyMCE editor.
2. Get an AI response.
3. Click "Replace" button.
4. Verify: Confirmation dialog appears.
5. Click OK.
6. Verify: TinyMCE editor content is completely replaced with AI response.
```

### Test 8: "New conversation" starts a fresh chat
```
1. Have an ongoing conversation with messages.
2. Click "New" button in AI panel header.
3. Verify: Chat messages are cleared.
4. Verify: System message "New conversation started." appears.
5. Send a new message.
6. Verify: New conversation begins (no prior context).
```

### Test 9: Responsive behavior on mobile
```
1. Resize browser to <768px width.
2. Verify: Editor layout is single-column.
3. Toggle AI panel.
4. Verify: Panel opens as a full-screen overlay.
5. Verify: Close button works to dismiss the overlay.
```

### Test 10: Enter to send, Shift+Enter for newline
```
1. Open AI panel, focus the input.
2. Type text and press Shift+Enter.
3. Verify: A newline is inserted in the input (message NOT sent).
4. Press Enter (without Shift).
5. Verify: Message is sent.
```

---

## Implementation Notes

### Coding Patterns
- Follow existing vanilla JS patterns from `editor.js` and `admin.js`:
  - No jQuery, no modules — single IIFE wrapping all code.
  - Fetch API for HTTP requests.
  - `document.createElement()` for dynamic DOM construction.
  - Event listeners attached after DOMContentLoaded.
- CSS follows existing admin.css conventions:
  - Use existing CSS custom properties for colors, spacing, transitions.
  - BEM-lite naming: `.ai-message`, `.ai-message-content`, `.ai-message-actions`.
  - Animations via `@keyframes`.

### Edge Cases
1. **New content (no ID)**: The AI panel works but has no content context. `contentId` is null, so conversation is not content-specific. No conversation pre-loading.
2. **TinyMCE not yet loaded**: The `insertToEditor()` and `replaceEditorContent()` functions check for `tinymce.activeEditor` before acting. If TinyMCE hasn't loaded yet (CDN slow), the buttons do nothing silently.
3. **Long AI responses**: The messages container scrolls. HTML content from AI may include headings, lists, code — all styled within the bubble.
4. **Network failures**: Catch block shows user-friendly error in the chat area.
5. **Concurrent requests**: `isLoading` flag prevents double-sends. Send button is disabled during requests.
6. **CSRF token expiry**: If the session expires, the CSRF token becomes invalid. The backend returns a 403 which the JS catches and shows as an error.

### CSP Compliance
The existing CSP policy on admin pages allows:
- `script-src 'self' https://cdn.jsdelivr.net` — `ai-assistant.js` is served from `'self'`
- `style-src 'self' 'unsafe-inline'` — inline styles in JS-created elements are allowed
- `connect-src 'self'` — fetch to `/admin/ai/chat` is same-origin, allowed
- No changes needed to CSP headers.

### What Is NOT Changed
- No backend routes added (all exist from Chunk 4.1).
- No database schema changes.
- No modifications to `ClaudeClient.php`, `ConversationManager.php`, or `AIController.php`.
- No changes to `admin.js` or `editor.js` (AI panel is fully self-contained).
- The admin layout (`templates/admin/layout.php`) is not modified.

---

## File Checklist

| # | File | Action | Type |
|---|------|--------|------|
| 1 | `public/assets/js/ai-assistant.js` | CREATE | JavaScript |
| 2 | `public/assets/css/admin.css` | MODIFY | CSS (add ~220 lines) |
| 3 | `templates/admin/content/edit.php` | MODIFY | Template (restructure header, add AI panel HTML, add script tag) |

**Note**: `app/Auth/CsrfMiddleware.php` already supports `X-CSRF-Token` headers — no changes needed.

---

## Estimated Scope

- **New JavaScript**: ~280 lines (`ai-assistant.js`)
- **New CSS**: ~220 lines (AI panel styles)
- **Template changes**: ~25 new lines in `edit.php` (toggle button + AI panel markup)
- **Total new code**: ~525 lines

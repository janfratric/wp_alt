<?php $this->layout('admin/layout'); ?>

<div class="page-header">
    <h1><?= $this->e($title) ?></h1>
    <p style="color:var(--text-muted,#888);margin-top:0.25rem;">
        Use AI to create a new page through a guided conversation.
    </p>
</div>

<div class="generator-container" id="generator-app"
     data-csrf="<?= $this->e($csrfToken) ?>">

    <!-- Step indicator -->
    <div class="generator-steps">
        <div class="step active" id="step-ind-setup">1. Setup</div>
        <div class="step" id="step-ind-gathering">2. Describe</div>
        <div class="step" id="step-ind-preview">3. Preview</div>
        <div class="step" id="step-ind-created">4. Done</div>
    </div>

    <!-- Step 1: Setup -->
    <div class="generator-panel" id="step-setup">
        <h2>What would you like to create?</h2>
        <div class="type-selector">
            <?php foreach ($contentTypes as $slug => $name): ?>
                <button type="button" class="type-option" data-type="<?= $this->e($slug) ?>">
                    <?= $this->e($name) ?>
                </button>
            <?php endforeach; ?>
        </div>
        <div class="editor-mode-selector" style="margin-top:1rem;">
            <label style="font-weight:600;">Editor Mode:</label>
            <div class="mode-options" style="display:flex;gap:0.5rem;margin-top:0.5rem;">
                <button type="button" class="mode-option active" data-mode="html">HTML</button>
                <button type="button" class="mode-option" data-mode="elements">Elements</button>
            </div>
        </div>
    </div>

    <!-- Step 2: Gathering -->
    <div class="generator-panel hidden" id="step-gathering">
        <div class="generator-chat">
            <div class="ai-panel-header" id="generator-chat-header">
                <div class="ai-panel-header-left">
                    <select class="ai-model-select" title="Select model"></select>
                </div>
                <div class="ai-panel-header-actions">
                    <button type="button" id="generator-compact-btn" title="Compact conversation" style="display:none;">Compact</button>
                </div>
            </div>
            <div class="ai-context-meter">
                <div class="context-bar-track">
                    <div class="context-bar context-bar-ok" style="width:0"></div>
                </div>
                <span class="context-text">0 / 200.0k</span>
            </div>
            <div id="generator-messages" class="chat-messages"></div>
            <div id="generator-attach-preview" class="ai-attachments-preview"></div>
            <div class="chat-input-area">
                <button type="button" id="generator-attach-btn" class="ai-attach-btn" title="Attach image">&#128206;</button>
                <textarea id="generator-input" placeholder="Describe what you need..." rows="2"></textarea>
                <button id="generator-send" type="button" class="btn btn-primary">Send</button>
            </div>
        </div>
    </div>

    <!-- Step 3: Preview -->
    <div class="generator-panel hidden" id="step-preview">
        <div class="preview-header">
            <h2>Preview Generated Content</h2>
            <div class="preview-meta">
                <div><strong>Title:</strong> <span id="preview-title"></span></div>
                <div><strong>Slug:</strong> /<span id="preview-slug"></span></div>
                <div><strong>Excerpt:</strong> <span id="preview-excerpt"></span></div>
                <div><strong>Meta Title:</strong> <span id="preview-meta-title"></span></div>
                <div><strong>Meta Desc:</strong> <span id="preview-meta-desc"></span></div>
            </div>
        </div>
        <div id="preview-body" class="preview-content"></div>
        <div id="preview-custom-fields"></div>
        <div class="preview-actions">
            <button id="btn-back-to-chat" type="button" class="btn btn-secondary">Back to Chat</button>
            <button id="btn-create-draft" type="button" class="btn btn-secondary">Create as Draft</button>
            <button id="btn-create-publish" type="button" class="btn btn-primary">Create &amp; Publish</button>
        </div>
    </div>

    <!-- Step 4: Success -->
    <div class="generator-panel hidden" id="step-created">
        <div class="success-message">
            <h2>Page Created Successfully!</h2>
            <p>Your content has been created and is ready for review.</p>
            <div class="success-actions">
                <a id="btn-edit-content" href="#" class="btn btn-primary">Open in Editor</a>
                <a href="/admin/generator" class="btn btn-secondary">Generate Another</a>
                <a href="/admin/content" class="btn btn-secondary">View All Content</a>
            </div>
        </div>
    </div>

</div>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/highlightjs/cdn-release@11/build/styles/github-dark.min.css">
<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<script src="https://cdn.jsdelivr.net/gh/highlightjs/cdn-release@11/build/highlight.min.js"></script>
<script src="/assets/js/ai-chat-core.js"></script>
<script src="/assets/js/page-generator.js"></script>

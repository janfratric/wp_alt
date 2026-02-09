<?php $this->layout('admin/layout'); ?>

<div class="page-header">
    <h1>Master Style</h1>
    <p style="color: var(--text-muted, #6c757d); margin: 0;">Configure your site's visual identity. Changes preview in real-time.</p>
</div>

<div class="style-editor">
    <!-- Left: Controls -->
    <div class="style-controls">
        <form method="POST" action="/admin/style" id="styleForm">
            <?= $this->csrfField() ?>
            <input type="hidden" name="_method" value="PUT">

            <!-- Colors Section -->
            <div class="style-section">
                <h2>Colors</h2>
                <p class="section-desc">Core color palette used across your site.</p>

                <div class="color-grid">
                    <div class="color-field">
                        <label for="style_color_primary">Primary Color</label>
                        <div class="color-input-wrap">
                            <input type="color"
                                   id="style_color_primary"
                                   name="style_color_primary"
                                   value="<?= $this->e($styles['style_color_primary']) ?>"
                                   data-default="<?= $this->e($defaults['style_color_primary']) ?>"
                                   data-css-var="--color-primary">
                            <span class="color-hex"><?= $this->e($styles['style_color_primary']) ?></span>
                        </div>
                        <small>Brand color for buttons, accents, and highlights.</small>
                    </div>

                    <div class="color-field">
                        <label for="style_color_primary_hover">Primary Hover</label>
                        <div class="color-input-wrap">
                            <input type="color"
                                   id="style_color_primary_hover"
                                   name="style_color_primary_hover"
                                   value="<?= $this->e($styles['style_color_primary_hover']) ?>"
                                   data-default="<?= $this->e($defaults['style_color_primary_hover']) ?>"
                                   data-css-var="--color-primary-hover"
                                   <?= $styles['style_auto_derive_hover'] === '1' ? 'disabled' : '' ?>>
                            <span class="color-hex"><?= $this->e($styles['style_color_primary_hover']) ?></span>
                        </div>
                        <label class="checkbox-label" style="margin-top: 0.25rem;">
                            <input type="hidden" name="style_auto_derive_hover" value="0">
                            <input type="checkbox"
                                   name="style_auto_derive_hover"
                                   id="style_auto_derive_hover"
                                   value="1"
                                   <?= $styles['style_auto_derive_hover'] === '1' ? 'checked' : '' ?>>
                            Auto-derive from primary
                        </label>
                    </div>

                    <div class="color-field">
                        <label for="style_color_text">Text Color</label>
                        <div class="color-input-wrap">
                            <input type="color"
                                   id="style_color_text"
                                   name="style_color_text"
                                   value="<?= $this->e($styles['style_color_text']) ?>"
                                   data-default="<?= $this->e($defaults['style_color_text']) ?>"
                                   data-css-var="--color-text">
                            <span class="color-hex"><?= $this->e($styles['style_color_text']) ?></span>
                        </div>
                        <small>Main body text color.</small>
                    </div>

                    <div class="color-field">
                        <label for="style_color_text_muted">Muted Text</label>
                        <div class="color-input-wrap">
                            <input type="color"
                                   id="style_color_text_muted"
                                   name="style_color_text_muted"
                                   value="<?= $this->e($styles['style_color_text_muted']) ?>"
                                   data-default="<?= $this->e($defaults['style_color_text_muted']) ?>"
                                   data-css-var="--color-text-muted">
                            <span class="color-hex"><?= $this->e($styles['style_color_text_muted']) ?></span>
                        </div>
                        <small>Secondary text, captions, metadata.</small>
                    </div>

                    <div class="color-field">
                        <label for="style_color_bg">Background</label>
                        <div class="color-input-wrap">
                            <input type="color"
                                   id="style_color_bg"
                                   name="style_color_bg"
                                   value="<?= $this->e($styles['style_color_bg']) ?>"
                                   data-default="<?= $this->e($defaults['style_color_bg']) ?>"
                                   data-css-var="--color-bg">
                            <span class="color-hex"><?= $this->e($styles['style_color_bg']) ?></span>
                        </div>
                        <small>Page background color.</small>
                    </div>

                    <div class="color-field">
                        <label for="style_color_bg_alt">Alt Background</label>
                        <div class="color-input-wrap">
                            <input type="color"
                                   id="style_color_bg_alt"
                                   name="style_color_bg_alt"
                                   value="<?= $this->e($styles['style_color_bg_alt']) ?>"
                                   data-default="<?= $this->e($defaults['style_color_bg_alt']) ?>"
                                   data-css-var="--color-bg-alt">
                            <span class="color-hex"><?= $this->e($styles['style_color_bg_alt']) ?></span>
                        </div>
                        <small>Cards, alternating sections.</small>
                    </div>

                    <div class="color-field">
                        <label for="style_color_border">Border Color</label>
                        <div class="color-input-wrap">
                            <input type="color"
                                   id="style_color_border"
                                   name="style_color_border"
                                   value="<?= $this->e($styles['style_color_border']) ?>"
                                   data-default="<?= $this->e($defaults['style_color_border']) ?>"
                                   data-css-var="--color-border">
                            <span class="color-hex"><?= $this->e($styles['style_color_border']) ?></span>
                        </div>
                        <small>Borders and dividers.</small>
                    </div>

                    <div class="color-field">
                        <label for="style_color_link">Link Color</label>
                        <div class="color-input-wrap">
                            <input type="color"
                                   id="style_color_link"
                                   name="style_color_link"
                                   value="<?= $this->e($styles['style_color_link']) ?>"
                                   data-default="<?= $this->e($defaults['style_color_link']) ?>"
                                   data-css-var="--color-link">
                            <span class="color-hex"><?= $this->e($styles['style_color_link']) ?></span>
                        </div>
                        <small>Hyperlink color.</small>
                    </div>

                    <div class="color-field">
                        <label for="style_color_link_hover">Link Hover</label>
                        <div class="color-input-wrap">
                            <input type="color"
                                   id="style_color_link_hover"
                                   name="style_color_link_hover"
                                   value="<?= $this->e($styles['style_color_link_hover']) ?>"
                                   data-default="<?= $this->e($defaults['style_color_link_hover']) ?>"
                                   data-css-var="--color-link-hover">
                            <span class="color-hex"><?= $this->e($styles['style_color_link_hover']) ?></span>
                        </div>
                        <small>Link hover state.</small>
                    </div>
                </div>
            </div>

            <!-- Header & Footer Section -->
            <div class="style-section">
                <h2>Header &amp; Footer</h2>
                <p class="section-desc">Customize the look of your site header and footer.</p>

                <div class="color-grid">
                    <div class="color-field">
                        <label for="style_color_header_bg">Header Background</label>
                        <div class="color-input-wrap">
                            <input type="color"
                                   id="style_color_header_bg"
                                   name="style_color_header_bg"
                                   value="<?= $this->e($styles['style_color_header_bg']) ?>"
                                   data-default="<?= $this->e($defaults['style_color_header_bg']) ?>"
                                   data-target="header-bg">
                            <span class="color-hex"><?= $this->e($styles['style_color_header_bg']) ?></span>
                        </div>
                    </div>

                    <div class="color-field">
                        <label for="style_color_header_text">Header Text</label>
                        <div class="color-input-wrap">
                            <input type="color"
                                   id="style_color_header_text"
                                   name="style_color_header_text"
                                   value="<?= $this->e($styles['style_color_header_text']) ?>"
                                   data-default="<?= $this->e($defaults['style_color_header_text']) ?>"
                                   data-target="header-text">
                            <span class="color-hex"><?= $this->e($styles['style_color_header_text']) ?></span>
                        </div>
                    </div>

                    <div class="color-field">
                        <label for="style_color_footer_bg">Footer Background</label>
                        <div class="color-input-wrap">
                            <input type="color"
                                   id="style_color_footer_bg"
                                   name="style_color_footer_bg"
                                   value="<?= $this->e($styles['style_color_footer_bg']) ?>"
                                   data-default="<?= $this->e($defaults['style_color_footer_bg']) ?>"
                                   data-target="footer-bg">
                            <span class="color-hex"><?= $this->e($styles['style_color_footer_bg']) ?></span>
                        </div>
                    </div>

                    <div class="color-field">
                        <label for="style_color_footer_text">Footer Text</label>
                        <div class="color-input-wrap">
                            <input type="color"
                                   id="style_color_footer_text"
                                   name="style_color_footer_text"
                                   value="<?= $this->e($styles['style_color_footer_text']) ?>"
                                   data-default="<?= $this->e($defaults['style_color_footer_text']) ?>"
                                   data-target="footer-text">
                            <span class="color-hex"><?= $this->e($styles['style_color_footer_text']) ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Typography Section -->
            <div class="style-section">
                <h2>Typography</h2>
                <p class="section-desc">Fonts and text appearance across your site.</p>

                <div class="form-group">
                    <label for="style_font_family">Body Font</label>
                    <select id="style_font_family"
                            name="style_font_family"
                            data-default="<?= $this->e($defaults['style_font_family']) ?>">
                        <optgroup label="Web-Safe Fonts">
                            <?php foreach ($fontLabels as $key => $label): ?>
                                <?php if (!str_starts_with($key, 'google_')): ?>
                                    <option value="<?= $this->e($key) ?>"
                                        <?= ($styles['style_font_family'] === $key) ? 'selected' : '' ?>
                                        data-stack="<?= $this->e($fontStacks[$key]) ?>">
                                        <?= $this->e($label) ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </optgroup>
                        <optgroup label="Google Fonts">
                            <?php foreach ($fontLabels as $key => $label): ?>
                                <?php if (str_starts_with($key, 'google_')): ?>
                                    <option value="<?= $this->e($key) ?>"
                                        <?= ($styles['style_font_family'] === $key) ? 'selected' : '' ?>
                                        data-stack="<?= $this->e($fontStacks[$key]) ?>"
                                        data-google="<?= $this->e($googleFontFamilies[$key] ?? '') ?>">
                                        <?= $this->e($label) ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </optgroup>
                    </select>
                </div>

                <div class="form-group">
                    <label for="style_font_heading">Heading Font</label>
                    <select id="style_font_heading"
                            name="style_font_heading"
                            data-default="<?= $this->e($defaults['style_font_heading']) ?>">
                        <optgroup label="Web-Safe Fonts">
                            <?php foreach ($fontLabels as $key => $label): ?>
                                <?php if (!str_starts_with($key, 'google_')): ?>
                                    <option value="<?= $this->e($key) ?>"
                                        <?= ($styles['style_font_heading'] === $key) ? 'selected' : '' ?>
                                        data-stack="<?= $this->e($fontStacks[$key]) ?>">
                                        <?= $this->e($label) ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </optgroup>
                        <optgroup label="Google Fonts">
                            <?php foreach ($fontLabels as $key => $label): ?>
                                <?php if (str_starts_with($key, 'google_')): ?>
                                    <option value="<?= $this->e($key) ?>"
                                        <?= ($styles['style_font_heading'] === $key) ? 'selected' : '' ?>
                                        data-stack="<?= $this->e($fontStacks[$key]) ?>"
                                        data-google="<?= $this->e($googleFontFamilies[$key] ?? '') ?>">
                                        <?= $this->e($label) ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </optgroup>
                    </select>
                </div>

                <div class="typo-grid">
                    <div class="form-group">
                        <label for="style_font_size_base">Base Font Size</label>
                        <select id="style_font_size_base"
                                name="style_font_size_base"
                                data-default="<?= $this->e($defaults['style_font_size_base']) ?>">
                            <option value="0.875rem" <?= $styles['style_font_size_base'] === '0.875rem' ? 'selected' : '' ?>>Small (14px)</option>
                            <option value="1rem" <?= $styles['style_font_size_base'] === '1rem' ? 'selected' : '' ?>>Medium (16px)</option>
                            <option value="1.125rem" <?= $styles['style_font_size_base'] === '1.125rem' ? 'selected' : '' ?>>Large (18px)</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="style_line_height">Line Height</label>
                        <input type="number"
                               id="style_line_height"
                               name="style_line_height"
                               value="<?= $this->e($styles['style_line_height']) ?>"
                               data-default="<?= $this->e($defaults['style_line_height']) ?>"
                               min="1.2" max="2.2" step="0.1">
                    </div>

                    <div class="form-group">
                        <label for="style_heading_weight">Heading Weight</label>
                        <select id="style_heading_weight"
                                name="style_heading_weight"
                                data-default="<?= $this->e($defaults['style_heading_weight']) ?>">
                            <option value="400" <?= $styles['style_heading_weight'] === '400' ? 'selected' : '' ?>>Light (400)</option>
                            <option value="500" <?= $styles['style_heading_weight'] === '500' ? 'selected' : '' ?>>Medium (500)</option>
                            <option value="600" <?= $styles['style_heading_weight'] === '600' ? 'selected' : '' ?>>Semi-Bold (600)</option>
                            <option value="700" <?= $styles['style_heading_weight'] === '700' ? 'selected' : '' ?>>Bold (700)</option>
                            <option value="800" <?= $styles['style_heading_weight'] === '800' ? 'selected' : '' ?>>Extra-Bold (800)</option>
                            <option value="900" <?= $styles['style_heading_weight'] === '900' ? 'selected' : '' ?>>Black (900)</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Shadows Section -->
            <div class="style-section">
                <h2>Shadows</h2>
                <p class="section-desc">Box shadow intensity for cards and elevated elements.</p>

                <div class="form-group">
                    <label for="style_shadow">Shadow Style</label>
                    <select id="style_shadow"
                            name="style_shadow"
                            data-default="<?= $this->e($defaults['style_shadow']) ?>">
                        <option value="none" <?= $styles['style_shadow'] === 'none' ? 'selected' : '' ?>>None</option>
                        <option value="subtle" <?= $styles['style_shadow'] === 'subtle' ? 'selected' : '' ?>>Subtle (default)</option>
                        <option value="medium" <?= $styles['style_shadow'] === 'medium' ? 'selected' : '' ?>>Medium</option>
                        <option value="strong" <?= $styles['style_shadow'] === 'strong' ? 'selected' : '' ?>>Strong</option>
                    </select>
                </div>
            </div>

            <!-- Actions -->
            <div class="style-actions">
                <button type="submit" class="btn btn-primary">Save Styles</button>
                <button type="button" id="resetBtn" class="btn">Reset to Defaults</button>
            </div>
        </form>

        <!-- Hidden reset form -->
        <form method="POST" action="/admin/style" id="resetForm" style="display:none;">
            <?= $this->csrfField() ?>
            <input type="hidden" name="_method" value="PUT">
            <input type="hidden" name="_reset" value="1">
        </form>
    </div>

    <!-- Right: Live Preview -->
    <div class="style-preview-wrap">
        <div class="style-preview-label">Live Preview</div>
        <div class="style-preview" id="stylePreview">
            <!-- Preview Header -->
            <div class="sp-header" id="spHeader">
                <span class="sp-logo">Site Name</span>
                <nav class="sp-nav">
                    <a href="#">Home</a>
                    <a href="#">About</a>
                    <a href="#">Blog</a>
                    <a href="#">Contact</a>
                </nav>
            </div>

            <!-- Preview Content -->
            <div class="sp-content" id="spContent">
                <h1 class="sp-h1">Heading Level 1</h1>
                <h2 class="sp-h2">Heading Level 2</h2>
                <p class="sp-body">This is a paragraph of body text demonstrating the chosen fonts, colors, and line height. Here is a <a href="#" class="sp-link">hyperlink example</a> and some <span class="sp-muted">muted secondary text</span> for reference.</p>

                <!-- Card -->
                <div class="sp-card" id="spCard">
                    <h3 class="sp-h3">Card Component</h3>
                    <p class="sp-body">This card demonstrates the alt background, border, and shadow styles applied to elevated elements.</p>
                    <a href="#" class="sp-button" id="spButton">Button</a>
                </div>

                <!-- Second paragraph -->
                <p class="sp-body sp-muted" style="font-size: 0.9em; margin-top: 1rem;">
                    This muted text shows how secondary content appears with the current palette.
                </p>
            </div>

            <!-- Preview Footer -->
            <div class="sp-footer" id="spFooter">
                &copy; 2026 Site Name. All rights reserved.
            </div>
        </div>
    </div>
</div>

<script>
// Pass font stacks and shadow presets to JS
window.STYLE_FONT_STACKS = <?= json_encode($fontStacks, JSON_UNESCAPED_SLASHES) ?>;
window.STYLE_SHADOW_PRESETS = <?= json_encode($shadowPresets, JSON_UNESCAPED_SLASHES) ?>;
window.STYLE_GOOGLE_FONTS = <?= json_encode($googleFontFamilies, JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="/assets/js/master-style.js"></script>

<style>
/* Master Style Editor Layout */
.style-editor {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.5rem;
    align-items: start;
}
.style-controls {
    min-width: 0;
}
.style-preview-wrap {
    position: sticky;
    top: 80px;
}
.style-preview-label {
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-muted, #6c757d);
    margin-bottom: 0.5rem;
    font-weight: 600;
}
.style-section {
    background: var(--card-bg, #fff);
    border: 1px solid var(--border-color, #dee2e6);
    border-radius: 8px;
    padding: 1.25rem;
    margin-bottom: 1rem;
}
.style-section h2 {
    margin-top: 0;
    font-size: 1.1rem;
    padding-bottom: 0.4rem;
    border-bottom: 1px solid var(--border-color, #dee2e6);
}
.section-desc {
    color: var(--text-muted, #6c757d);
    font-size: 0.85rem;
    margin-bottom: 1rem;
}
.color-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.75rem;
}
.color-field label {
    display: block;
    font-size: 0.85rem;
    font-weight: 500;
    margin-bottom: 0.25rem;
}
.color-field small {
    display: block;
    font-size: 0.75rem;
    color: var(--text-muted, #6c757d);
    margin-top: 0.15rem;
}
.color-input-wrap {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.color-input-wrap input[type="color"] {
    width: 36px;
    height: 36px;
    padding: 2px;
    border: 1px solid var(--border-color, #dee2e6);
    border-radius: 6px;
    cursor: pointer;
    background: none;
}
.color-input-wrap input[type="color"]:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
.color-hex {
    font-family: monospace;
    font-size: 0.8rem;
    color: var(--text-muted, #6c757d);
}
.typo-grid {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 0.75rem;
}
.style-actions {
    display: flex;
    gap: 0.75rem;
    margin-top: 1rem;
}
.checkbox-label {
    display: flex;
    align-items: center;
    gap: 0.35rem;
    font-size: 0.8rem;
    cursor: pointer;
}
.checkbox-label input[type="checkbox"] {
    width: auto;
    margin: 0;
}

/* Live Preview */
.style-preview {
    border: 1px solid var(--border-color, #dee2e6);
    border-radius: 8px;
    overflow: hidden;
    background: #ffffff;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    font-size: 1rem;
    line-height: 1.7;
    color: #1e293b;
}
.sp-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.75rem 1rem;
    border-bottom: 1px solid #e2e8f0;
    background: #ffffff;
}
.sp-logo {
    font-weight: 700;
    font-size: 1.1rem;
}
.sp-nav {
    display: flex;
    gap: 0.75rem;
    font-size: 0.85rem;
}
.sp-nav a {
    text-decoration: none;
    color: inherit;
}
.sp-nav a:hover {
    text-decoration: underline;
}
.sp-content {
    padding: 1.25rem 1rem;
}
.sp-h1 {
    font-size: 1.8rem;
    font-weight: 700;
    margin: 0 0 0.5rem;
    line-height: 1.3;
}
.sp-h2 {
    font-size: 1.4rem;
    font-weight: 700;
    margin: 0 0 0.5rem;
    line-height: 1.3;
}
.sp-h3 {
    font-size: 1.1rem;
    font-weight: 700;
    margin: 0 0 0.5rem;
    line-height: 1.3;
}
.sp-body {
    margin: 0 0 0.75rem;
}
.sp-link {
    color: #2563eb;
    text-decoration: underline;
}
.sp-muted {
    color: #64748b;
}
.sp-card {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 1rem;
    margin: 1rem 0;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08), 0 1px 2px rgba(0,0,0,0.06);
}
.sp-button {
    display: inline-block;
    padding: 0.5rem 1.25rem;
    background: #2563eb;
    color: #fff;
    border-radius: 6px;
    text-decoration: none;
    font-size: 0.9rem;
    font-weight: 500;
}
.sp-button:hover {
    text-decoration: none;
}
.sp-footer {
    padding: 0.75rem 1rem;
    border-top: 1px solid #e2e8f0;
    font-size: 0.8rem;
    background: #f8fafc;
    color: #64748b;
}

/* Responsive */
@media (max-width: 900px) {
    .style-editor {
        grid-template-columns: 1fr;
    }
    .style-preview-wrap {
        position: static;
        order: -1;
    }
    .color-grid {
        grid-template-columns: 1fr;
    }
    .typo-grid {
        grid-template-columns: 1fr;
    }
}
</style>

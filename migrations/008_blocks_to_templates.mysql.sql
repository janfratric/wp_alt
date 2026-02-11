-- LiteCMS — MySQL
-- Migration: 008_blocks_to_templates
-- Moves page_blocks ownership from content to layout_templates

-- Add layout_template_id column
ALTER TABLE page_blocks ADD COLUMN layout_template_id BIGINT UNSIGNED AFTER content_id,
    ADD FOREIGN KEY (layout_template_id) REFERENCES layout_templates(id) ON DELETE CASCADE;

-- Make content_id nullable
ALTER TABLE page_blocks MODIFY content_id BIGINT UNSIGNED NULL;

-- Add index for template-based lookups
CREATE INDEX idx_page_blocks_template ON page_blocks(layout_template_id, sort_order);

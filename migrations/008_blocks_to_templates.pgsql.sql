-- LiteCMS — PostgreSQL
-- Migration: 008_blocks_to_templates
-- Moves page_blocks ownership from content to layout_templates

-- Add layout_template_id column
ALTER TABLE page_blocks ADD COLUMN layout_template_id INTEGER REFERENCES layout_templates(id) ON DELETE CASCADE;

-- Make content_id nullable
ALTER TABLE page_blocks ALTER COLUMN content_id DROP NOT NULL;

-- Add index for template-based lookups
CREATE INDEX IF NOT EXISTS idx_page_blocks_template ON page_blocks(layout_template_id, sort_order);

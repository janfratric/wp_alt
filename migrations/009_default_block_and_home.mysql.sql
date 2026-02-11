-- LiteCMS — MySQL
-- Migration: 009_default_block_and_home
-- Adds a default block to the default layout template.
-- Home page seeding is done in PHP bootstrap (requires author_id from users table).

-- Insert a "Main Content" block for the default layout template
INSERT INTO page_blocks (layout_template_id, content_id, name, sort_order, columns, width_percent, alignment, display_mode, style_data_json)
SELECT id, NULL, 'Main Content', 0, 1, 100, 'center', 'block', '{}'
FROM layout_templates WHERE slug = 'default-layout';

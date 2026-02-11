-- LiteCMS — SQLite
-- Migration: 008_blocks_to_templates
-- Moves page_blocks ownership from content to layout_templates

-- SQLite cannot ALTER COLUMN, so recreate the table
CREATE TABLE IF NOT EXISTS page_blocks_new (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    content_id INTEGER,
    layout_template_id INTEGER,
    name VARCHAR(200) NOT NULL DEFAULT '',
    sort_order INTEGER NOT NULL DEFAULT 0,
    columns INTEGER NOT NULL DEFAULT 1 CHECK (columns BETWEEN 1 AND 12),
    width_percent INTEGER NOT NULL DEFAULT 100 CHECK (width_percent BETWEEN 10 AND 100),
    alignment VARCHAR(20) NOT NULL DEFAULT 'center' CHECK (alignment IN ('left', 'center', 'right')),
    display_mode VARCHAR(20) NOT NULL DEFAULT 'flex' CHECK (display_mode IN ('flex', 'block', 'grid')),
    style_data_json TEXT NOT NULL DEFAULT '{}',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (content_id) REFERENCES content(id) ON DELETE CASCADE,
    FOREIGN KEY (layout_template_id) REFERENCES layout_templates(id) ON DELETE CASCADE
);

-- Copy existing data (if any)
INSERT INTO page_blocks_new (id, content_id, name, sort_order, columns, width_percent, alignment, display_mode, style_data_json, created_at, updated_at)
    SELECT id, content_id, name, sort_order, columns, width_percent, alignment, display_mode, style_data_json, created_at, updated_at
    FROM page_blocks;

DROP TABLE IF EXISTS page_blocks;
ALTER TABLE page_blocks_new RENAME TO page_blocks;

CREATE INDEX IF NOT EXISTS idx_page_blocks_content ON page_blocks(content_id, sort_order);
CREATE INDEX IF NOT EXISTS idx_page_blocks_template ON page_blocks(layout_template_id, sort_order);

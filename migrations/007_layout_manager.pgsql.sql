-- LiteCMS Layout Manager — PostgreSQL
-- Migration: 007_layout_manager
-- Creates layout templates, page blocks, and links content to layouts

-- Layout templates (reusable page structure definitions)
CREATE TABLE IF NOT EXISTS layout_templates (
    id SERIAL PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    slug VARCHAR(100) NOT NULL UNIQUE,
    is_default INTEGER NOT NULL DEFAULT 0,
    header_visible INTEGER NOT NULL DEFAULT 1,
    header_height VARCHAR(50) NOT NULL DEFAULT 'auto',
    header_mode VARCHAR(20) NOT NULL DEFAULT 'standard' CHECK (header_mode IN ('standard', 'block')),
    header_element_id INTEGER REFERENCES elements(id) ON DELETE SET NULL,
    footer_visible INTEGER NOT NULL DEFAULT 1,
    footer_height VARCHAR(50) NOT NULL DEFAULT 'auto',
    footer_mode VARCHAR(20) NOT NULL DEFAULT 'standard' CHECK (footer_mode IN ('standard', 'block')),
    footer_element_id INTEGER REFERENCES elements(id) ON DELETE SET NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_layout_templates_default ON layout_templates(is_default);

-- Seed default layout template
INSERT INTO layout_templates (name, slug, is_default) VALUES ('Default Layout', 'default-layout', 1);

-- Page blocks (per-page element containers with layout properties)
CREATE TABLE IF NOT EXISTS page_blocks (
    id SERIAL PRIMARY KEY,
    content_id INTEGER NOT NULL REFERENCES content(id) ON DELETE CASCADE,
    name VARCHAR(200) NOT NULL DEFAULT '',
    sort_order INTEGER NOT NULL DEFAULT 0,
    columns INTEGER NOT NULL DEFAULT 1 CHECK (columns BETWEEN 1 AND 12),
    width_percent INTEGER NOT NULL DEFAULT 100 CHECK (width_percent BETWEEN 10 AND 100),
    alignment VARCHAR(20) NOT NULL DEFAULT 'center' CHECK (alignment IN ('left', 'center', 'right')),
    display_mode VARCHAR(20) NOT NULL DEFAULT 'flex' CHECK (display_mode IN ('flex', 'block', 'grid')),
    style_data_json TEXT NOT NULL DEFAULT '{}',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_page_blocks_content ON page_blocks(content_id, sort_order);

-- Link content to layout templates
ALTER TABLE content ADD COLUMN layout_template_id INTEGER REFERENCES layout_templates(id) ON DELETE SET NULL;

-- Link page elements to blocks
ALTER TABLE page_elements ADD COLUMN block_id INTEGER REFERENCES page_blocks(id) ON DELETE SET NULL;

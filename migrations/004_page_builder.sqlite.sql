-- LiteCMS Page Builder — SQLite
-- Migration: 004_page_builder
-- Creates element catalogue, page composition, and AI proposal tables

-- Elements catalogue (reusable UI components)
CREATE TABLE IF NOT EXISTS elements (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    slug VARCHAR(100) NOT NULL UNIQUE,
    name VARCHAR(200) NOT NULL,
    description TEXT,
    category VARCHAR(100) DEFAULT 'general',
    html_template TEXT NOT NULL DEFAULT '',
    css TEXT NOT NULL DEFAULT '',
    slots_json TEXT NOT NULL DEFAULT '[]',
    preview_html TEXT,
    version INTEGER NOT NULL DEFAULT 1,
    is_ai_generated INTEGER NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'draft', 'archived')),
    author_id INTEGER,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (author_id) REFERENCES users(id)
);

CREATE INDEX IF NOT EXISTS idx_elements_slug ON elements(slug);
CREATE INDEX IF NOT EXISTS idx_elements_category ON elements(category);
CREATE INDEX IF NOT EXISTS idx_elements_status ON elements(status);

-- Page elements (element instances on content pages)
CREATE TABLE IF NOT EXISTS page_elements (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    content_id INTEGER NOT NULL,
    element_id INTEGER NOT NULL,
    sort_order INTEGER NOT NULL DEFAULT 0,
    slot_data_json TEXT NOT NULL DEFAULT '{}',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (content_id) REFERENCES content(id) ON DELETE CASCADE,
    FOREIGN KEY (element_id) REFERENCES elements(id) ON DELETE RESTRICT
);

CREATE INDEX IF NOT EXISTS idx_page_elements_content ON page_elements(content_id);
CREATE INDEX IF NOT EXISTS idx_page_elements_element ON page_elements(element_id);
CREATE INDEX IF NOT EXISTS idx_page_elements_sort ON page_elements(content_id, sort_order);

-- Element proposals (AI-generated elements awaiting approval)
CREATE TABLE IF NOT EXISTS element_proposals (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(200) NOT NULL,
    slug VARCHAR(100) NOT NULL,
    description TEXT,
    category VARCHAR(100) DEFAULT 'general',
    html_template TEXT NOT NULL,
    css TEXT NOT NULL DEFAULT '',
    slots_json TEXT NOT NULL DEFAULT '[]',
    conversation_id INTEGER,
    proposed_by INTEGER NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'rejected')),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (conversation_id) REFERENCES ai_conversations(id),
    FOREIGN KEY (proposed_by) REFERENCES users(id)
);

CREATE INDEX IF NOT EXISTS idx_element_proposals_status ON element_proposals(status);

-- Add editor mode to content table
ALTER TABLE content ADD COLUMN editor_mode VARCHAR(20) NOT NULL DEFAULT 'html';

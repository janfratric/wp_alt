-- LiteCMS Page Builder — MySQL
-- Migration: 004_page_builder
-- Creates element catalogue, page composition, and AI proposal tables

-- Elements catalogue (reusable UI components)
CREATE TABLE IF NOT EXISTS elements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(100) NOT NULL UNIQUE,
    name VARCHAR(200) NOT NULL,
    description TEXT,
    category VARCHAR(100) DEFAULT 'general',
    html_template TEXT NOT NULL,
    css TEXT NOT NULL,
    slots_json TEXT NOT NULL,
    preview_html TEXT,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    is_ai_generated TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('active', 'draft', 'archived') NOT NULL DEFAULT 'active',
    author_id BIGINT UNSIGNED,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_elements_category (category),
    INDEX idx_elements_status (status),
    FOREIGN KEY (author_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Page elements (element instances on content pages)
CREATE TABLE IF NOT EXISTS page_elements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    content_id BIGINT UNSIGNED NOT NULL,
    element_id BIGINT UNSIGNED NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    slot_data_json TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_page_elements_content (content_id),
    INDEX idx_page_elements_element (element_id),
    INDEX idx_page_elements_sort (content_id, sort_order),
    FOREIGN KEY (content_id) REFERENCES content(id) ON DELETE CASCADE,
    FOREIGN KEY (element_id) REFERENCES elements(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Element proposals (AI-generated elements awaiting approval)
CREATE TABLE IF NOT EXISTS element_proposals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    slug VARCHAR(100) NOT NULL,
    description TEXT,
    category VARCHAR(100) DEFAULT 'general',
    html_template TEXT NOT NULL,
    css TEXT NOT NULL,
    slots_json TEXT NOT NULL,
    conversation_id BIGINT UNSIGNED,
    proposed_by BIGINT UNSIGNED NOT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_element_proposals_status (status),
    FOREIGN KEY (conversation_id) REFERENCES ai_conversations(id),
    FOREIGN KEY (proposed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add editor mode to content table
ALTER TABLE content ADD COLUMN editor_mode VARCHAR(20) NOT NULL DEFAULT 'html';

-- LiteCMS Layout Manager — MySQL
-- Migration: 007_layout_manager
-- Creates layout templates, page blocks, and links content to layouts

-- Layout templates (reusable page structure definitions)
CREATE TABLE IF NOT EXISTS layout_templates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    slug VARCHAR(100) NOT NULL UNIQUE,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    header_visible TINYINT(1) NOT NULL DEFAULT 1,
    header_height VARCHAR(50) NOT NULL DEFAULT 'auto',
    header_mode ENUM('standard', 'block') NOT NULL DEFAULT 'standard',
    header_element_id BIGINT UNSIGNED,
    footer_visible TINYINT(1) NOT NULL DEFAULT 1,
    footer_height VARCHAR(50) NOT NULL DEFAULT 'auto',
    footer_mode ENUM('standard', 'block') NOT NULL DEFAULT 'standard',
    footer_element_id BIGINT UNSIGNED,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_layout_templates_default (is_default),
    FOREIGN KEY (header_element_id) REFERENCES elements(id) ON DELETE SET NULL,
    FOREIGN KEY (footer_element_id) REFERENCES elements(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default layout template
INSERT INTO layout_templates (name, slug, is_default) VALUES ('Default Layout', 'default-layout', 1);

-- Page blocks (per-page element containers with layout properties)
CREATE TABLE IF NOT EXISTS page_blocks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    content_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(200) NOT NULL DEFAULT '',
    sort_order INT NOT NULL DEFAULT 0,
    columns TINYINT UNSIGNED NOT NULL DEFAULT 1,
    width_percent TINYINT UNSIGNED NOT NULL DEFAULT 100,
    alignment ENUM('left', 'center', 'right') NOT NULL DEFAULT 'center',
    display_mode ENUM('flex', 'block', 'grid') NOT NULL DEFAULT 'flex',
    style_data_json TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_page_blocks_content (content_id, sort_order),
    FOREIGN KEY (content_id) REFERENCES content(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Link content to layout templates
ALTER TABLE content ADD COLUMN layout_template_id BIGINT UNSIGNED,
    ADD FOREIGN KEY (layout_template_id) REFERENCES layout_templates(id) ON DELETE SET NULL;

-- Link page elements to blocks
ALTER TABLE page_elements ADD COLUMN block_id BIGINT UNSIGNED,
    ADD FOREIGN KEY (block_id) REFERENCES page_blocks(id) ON DELETE SET NULL;

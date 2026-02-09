-- LiteCMS Element Styles — MySQL
-- Migration: 005_element_styles
-- Adds per-instance styling and page-level layout styling

-- Per-instance style overrides (spacing, colors, borders, etc.)
ALTER TABLE page_elements ADD COLUMN style_data_json TEXT NOT NULL DEFAULT '{}';

-- Page-level wrapper styling (page-body, container, site-main)
CREATE TABLE IF NOT EXISTS page_styles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    content_id INT NOT NULL UNIQUE,
    style_data_json TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (content_id) REFERENCES content(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_page_styles_content ON page_styles(content_id);

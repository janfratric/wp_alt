-- Chunk 7.6: Add theme_override column to content table and pen_file to layout_templates
ALTER TABLE content ADD COLUMN theme_override VARCHAR(50) DEFAULT NULL;
ALTER TABLE layout_templates ADD COLUMN pen_file VARCHAR(500) DEFAULT NULL;

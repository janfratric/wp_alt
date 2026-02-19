-- Migration 010: Add design_file column to content table
ALTER TABLE content ADD COLUMN design_file VARCHAR(500) DEFAULT NULL;

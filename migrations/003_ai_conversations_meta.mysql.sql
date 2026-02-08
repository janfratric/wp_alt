-- LiteCMS Migration 003 — AI Conversations Metadata (MariaDB/MySQL)

ALTER TABLE ai_conversations ADD COLUMN usage_json TEXT NOT NULL DEFAULT '{}';
ALTER TABLE ai_conversations ADD COLUMN title VARCHAR(255) DEFAULT NULL;

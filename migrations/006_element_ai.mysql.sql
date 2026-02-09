-- LiteCMS Element AI — MySQL
-- Migration: 006_element_ai
-- Adds element_id to ai_conversations for per-element AI chats

ALTER TABLE ai_conversations ADD COLUMN element_id INT DEFAULT NULL;
CREATE INDEX idx_ai_conversations_element ON ai_conversations(element_id);

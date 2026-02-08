<?php declare(strict_types=1);

namespace App\AIAssistant;

use App\Database\QueryBuilder;

class ConversationManager
{
    /**
     * Find an existing conversation for a user+content pair, or create a new one.
     */
    public function findOrCreate(int $userId, ?int $contentId): array
    {
        $qb = QueryBuilder::query('ai_conversations')
            ->select()
            ->where('user_id', $userId);

        if ($contentId !== null) {
            $qb->where('content_id', $contentId);
        } else {
            $qb->whereRaw('content_id IS NULL');
        }

        $conversation = $qb->orderBy('updated_at', 'DESC')->first();

        if ($conversation !== null) {
            return $conversation;
        }

        $id = QueryBuilder::query('ai_conversations')->insert([
            'user_id'       => $userId,
            'content_id'    => $contentId,
            'messages_json' => '[]',
        ]);

        return $this->findById((int) $id);
    }

    /**
     * Find a conversation by ID.
     */
    public function findById(int $id): ?array
    {
        return QueryBuilder::query('ai_conversations')
            ->select()
            ->where('id', $id)
            ->first();
    }

    /**
     * Get the messages array from a conversation record.
     */
    public function getMessages(array $conversation): array
    {
        $json = $conversation['messages_json'] ?? '[]';
        $messages = @json_decode($json, true);

        return is_array($messages) ? $messages : [];
    }

    /**
     * Append a message to a conversation and update the database.
     */
    public function appendMessage(int $conversationId, string $role, string $content): array
    {
        $conversation = $this->findById($conversationId);
        if ($conversation === null) {
            return [];
        }

        $messages = $this->getMessages($conversation);
        $messages[] = [
            'role'      => $role,
            'content'   => $content,
            'timestamp' => date('c'),
        ];

        QueryBuilder::query('ai_conversations')
            ->where('id', $conversationId)
            ->update([
                'messages_json' => json_encode($messages, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'updated_at'    => date('Y-m-d H:i:s'),
            ]);

        return $messages;
    }

    /**
     * Get conversation history for a specific content item.
     */
    public function getHistory(int $userId, ?int $contentId): array
    {
        $qb = QueryBuilder::query('ai_conversations')
            ->select()
            ->where('user_id', $userId);

        if ($contentId !== null) {
            $qb->where('content_id', $contentId);
        } else {
            $qb->whereRaw('content_id IS NULL');
        }

        return $qb->orderBy('updated_at', 'DESC')->get();
    }

    /**
     * Delete a conversation by ID.
     */
    public function delete(int $id): void
    {
        QueryBuilder::query('ai_conversations')
            ->where('id', $id)
            ->delete();
    }

    /**
     * Append a message with optional attachments and usage metadata.
     */
    public function appendMessageWithUsage(
        int $conversationId,
        string $role,
        string $content,
        array $attachments = [],
        array $usage = []
    ): array {
        $conversation = $this->findById($conversationId);
        if ($conversation === null) {
            return [];
        }

        $messages = $this->getMessages($conversation);
        $msg = [
            'role'      => $role,
            'content'   => $content,
            'timestamp' => date('c'),
        ];

        if (!empty($attachments)) {
            $msg['attachments'] = $attachments;
        }
        if (!empty($usage)) {
            $msg['usage'] = $usage;
        }

        $messages[] = $msg;

        QueryBuilder::query('ai_conversations')
            ->where('id', $conversationId)
            ->update([
                'messages_json' => json_encode($messages, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'updated_at'    => date('Y-m-d H:i:s'),
            ]);

        return $messages;
    }

    /**
     * Update cumulative token usage for a conversation.
     */
    public function updateUsage(int $conversationId, array $usage): void
    {
        $conversation = $this->findById($conversationId);
        if ($conversation === null) {
            return;
        }

        $existing = @json_decode($conversation['usage_json'] ?? '{}', true);
        if (!is_array($existing)) {
            $existing = [];
        }

        $inputTokens = $usage['input_tokens'] ?? 0;
        $outputTokens = $usage['output_tokens'] ?? 0;

        $existing['total_input_tokens'] = ($existing['total_input_tokens'] ?? 0) + $inputTokens;
        $existing['total_output_tokens'] = ($existing['total_output_tokens'] ?? 0) + $outputTokens;

        QueryBuilder::query('ai_conversations')
            ->where('id', $conversationId)
            ->update([
                'usage_json' => json_encode($existing, JSON_THROW_ON_ERROR),
            ]);
    }

    /**
     * Get parsed usage data for a conversation.
     */
    public function getUsage(int $conversationId): array
    {
        $conversation = $this->findById($conversationId);
        if ($conversation === null) {
            return [];
        }

        $usage = @json_decode($conversation['usage_json'] ?? '{}', true);
        return is_array($usage) ? $usage : [];
    }

    /**
     * Compact a conversation by replacing older messages with a summary.
     */
    public function compact(int $conversationId, string $summary, int $keepLastN = 4): array
    {
        $conversation = $this->findById($conversationId);
        if ($conversation === null) {
            return [];
        }

        $messages = $this->getMessages($conversation);
        $kept = array_slice($messages, -$keepLastN);

        $newMessages = array_merge(
            [[
                'role'       => 'assistant',
                'content'    => '[Conversation Summary] ' . $summary,
                'timestamp'  => date('c'),
                'is_summary' => true,
            ]],
            $kept
        );

        QueryBuilder::query('ai_conversations')
            ->where('id', $conversationId)
            ->update([
                'messages_json' => json_encode($newMessages, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'usage_json'    => '{}',
                'updated_at'    => date('Y-m-d H:i:s'),
            ]);

        return $newMessages;
    }

    /**
     * Set a human-readable title for the conversation.
     */
    public function setTitle(int $conversationId, string $title): void
    {
        $title = mb_substr($title, 0, 80);
        // Truncate at last word boundary if we hit the limit
        if (mb_strlen($title) === 80) {
            $lastSpace = mb_strrpos($title, ' ');
            if ($lastSpace !== false && $lastSpace > 40) {
                $title = mb_substr($title, 0, $lastSpace);
            }
        }

        QueryBuilder::query('ai_conversations')
            ->where('id', $conversationId)
            ->update(['title' => $title]);
    }
}

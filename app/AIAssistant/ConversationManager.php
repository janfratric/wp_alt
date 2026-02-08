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
}

<?php
/**
 * Chat messages.
 */
declare(strict_types=1);

final class Message extends Model
{
    public static function forConversation(int $convId, int $limit = 500): array
    {
        return self::rows(
            'SELECT * FROM messages WHERE conversation_id = ? ORDER BY id ASC LIMIT ' . max(1, $limit),
            [$convId]
        );
    }

    /** Recent transcript used as LLM context (oldest first). */
    public static function transcript(int $convId, int $limit = 30): array
    {
        $all = self::rows(
            'SELECT role, content FROM messages WHERE conversation_id = ? ORDER BY id DESC LIMIT ' . max(1, $limit),
            [$convId]
        );
        return array_reverse($all);
    }

    public static function find(int $id): ?array
    {
        return self::row('SELECT * FROM messages WHERE id = ?', [$id]);
    }

    public static function create(int $convId, string $role, string $content, ?string $model = null, array $meta = []): int
    {
        self::write(
            'INSERT INTO messages (conversation_id, role, content, model, tokens, meta, created_at)
             VALUES (?,?,?,?,?,?,?)',
            [
                $convId,
                $role,
                $content,
                $model,
                (int) ($meta['tokens'] ?? 0),
                self::json(array_diff_key($meta, ['tokens' => 1])) ?: null,
                self::now(),
            ]
        );
        Conversation::touch($convId);
        return self::newId();
    }

    public static function destroy(int $id): void
    {
        self::write('DELETE FROM messages WHERE id = ?', [$id]);
    }

    public static function stats(): array
    {
        return ['messages' => (int) self::scalar('SELECT COUNT(*) FROM messages')];
    }
}

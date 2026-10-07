<?php
/**
 * Chat threads.
 */
declare(strict_types=1);

final class Conversation extends Model
{
    public static function all(): array
    {
        return self::rows(
            'SELECT c.*, COUNT(m.id) AS message_count
               FROM conversations c LEFT JOIN messages m ON m.conversation_id = c.id
              GROUP BY c.id ORDER BY c.updated_at DESC'
        );
    }

    public static function find(int $id): ?array
    {
        return self::row('SELECT * FROM conversations WHERE id = ?', [$id]);
    }

    public static function create(string $title, ?string $model = null, ?string $system = null): int
    {
        self::write(
            'INSERT INTO conversations (title, model, system_prompt, created_at, updated_at) VALUES (?,?,?,?,?)',
            [mb_substr($title, 0, 120), $model, $system, self::now(), self::now()]
        );
        return self::newId();
    }

    public static function rename(int $id, string $title): void
    {
        self::write('UPDATE conversations SET title = ?, updated_at = ? WHERE id = ?', [mb_substr($title, 0, 120), self::now(), $id]);
    }

    public static function touch(int $id): void
    {
        self::write('UPDATE conversations SET updated_at = ? WHERE id = ?', [self::now(), $id]);
    }

    public static function destroy(int $id): void
    {
        self::write('DELETE FROM conversations WHERE id = ?', [$id]);
    }

    public static function clear(int $id): void
    {
        self::write('DELETE FROM messages WHERE conversation_id = ?', [$id]);
        self::touch($id);
    }

    /** @return array{conversations:int,messages:int} */
    public static function stats(): array
    {
        return [
            'conversations' => (int) self::scalar('SELECT COUNT(*) FROM conversations'),
            'messages'      => (int) self::scalar('SELECT COUNT(*) FROM messages'),
        ];
    }
}

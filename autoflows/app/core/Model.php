<?php
/**
 * Thin base model with prepared-statement helpers.
 * Prefixed method names avoid collisions with concrete models (e.g. Setting::all()).
 */
declare(strict_types=1);

abstract class Model
{
    protected static function db(): PDO
    {
        return Database::pdo();
    }

    protected static function rows(string $sql, array $params = []): array
    {
        $st = static::db()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    protected static function row(string $sql, array $params = []): ?array
    {
        $st = static::db()->prepare($sql);
        $st->execute($params);
        $r = $st->fetch();
        return $r === false ? null : $r;
    }

    protected static function scalar(string $sql, array $params = []): mixed
    {
        $st = static::db()->prepare($sql);
        $st->execute($params);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    protected static function write(string $sql, array $params = []): int
    {
        $st = static::db()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    protected static function newId(): int
    {
        return (int) static::db()->lastInsertId();
    }

    protected static function json(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    protected static function unjson(?string $value, mixed $fallback = []): mixed
    {
        if ($value === null || $value === '') {
            return $fallback;
        }
        $out = json_decode($value, true);
        return $out === null && $value !== 'null' ? $fallback : $out;
    }

    protected static function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}

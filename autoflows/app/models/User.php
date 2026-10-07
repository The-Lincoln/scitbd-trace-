<?php
/**
 * Users — social login identities (Google / Facebook / dev).
 */
declare(strict_types=1);

final class User extends Model
{
    public static function find(int $id): ?array
    {
        return self::row('SELECT * FROM users WHERE id = ?', [$id]);
    }

    public static function findByEmail(string $email): ?array
    {
        $email = mb_strtolower(trim($email));
        if ($email === '') {
            return null;
        }
        return self::row('SELECT * FROM users WHERE lower(email) = ?', [$email]);
    }

    public static function findByProvider(string $provider, string $providerId): ?array
    {
        if ($providerId === '') {
            return null;
        }
        return self::row(
            'SELECT * FROM users WHERE provider = ? AND provider_id = ?',
            [$provider, $providerId]
        );
    }

    public static function count(): int
    {
        return (int) self::scalar('SELECT COUNT(*) FROM users');
    }

    /**
     * Find-or-create from an OAuth profile.
     * @param array{provider:string,id:string,email?:string,name?:string,avatar?:string} $p
     */
    public static function upsertSocial(array $p): array
    {
        $provider = (string) ($p['provider'] ?? 'google');
        $pid = (string) ($p['id'] ?? '');
        $email = mb_strtolower(trim((string) ($p['email'] ?? '')));
        $name = trim((string) ($p['name'] ?? ''));
        $avatar = trim((string) ($p['avatar'] ?? ''));

        $user = ($pid !== '') ? self::findByProvider($provider, $pid) : null;
        if ($user === null && $email !== '') {
            $user = self::findByEmail($email);
        }

        $now = self::now();
        if ($user === null) {
            self::write(
                'INSERT INTO users (name, email, avatar, provider, provider_id, last_login_at, created_at, updated_at)
                 VALUES (?,?,?,?,?,?,?,?)',
                [$name !== '' ? $name : ($email !== '' ? $email : 'AutoFlows user'),
                 $email, $avatar ?: null, $provider, $pid ?: null, $now, $now, $now]
            );
            return self::find(self::newId());
        }

        self::write(
            'UPDATE users SET name = COALESCE(NULLIF(?, \'\'), name),
                              email = CASE WHEN ? <> \'\' THEN ? ELSE email END,
                              avatar = COALESCE(NULLIF(?, \'\'), avatar),
                              provider = ?, provider_id = COALESCE(NULLIF(?, \'\'), provider_id),
                              last_login_at = ?, updated_at = ? WHERE id = ?',
            [$name, $email, $email, $avatar, $provider, $pid, $now, $now, (int) $user['id']]
        );
        return self::find((int) $user['id']);
    }

    /** Offline dev account (no OAuth round-trip). */
    public static function dev(string $which): array
    {
        $email = $which === 'facebook' ? 'fb-dev@autoflows.local' : 'gmail-dev@autoflows.local';
        $name = $which === 'facebook' ? 'Facebook Dev' : 'Gmail Dev';
        $user = self::findByEmail($email);
        $now = self::now();
        if ($user === null) {
            self::write(
                'INSERT INTO users (name, email, provider, provider_id, last_login_at, created_at, updated_at)
                 VALUES (?,?,?,?,?,?,?)',
                [$name, $email, $which . '-dev', 'dev-' . $which, $now, $now, $now]
            );
            $user = self::find(self::newId());
        } else {
            self::write(
                'UPDATE users SET last_login_at = ?, updated_at = ? WHERE id = ?',
                [$now, $now, (int) $user['id']]
            );
            $user = self::find((int) $user['id']);
        }
        return $user;
    }

    public static function touchLogin(int $id): void
    {
        self::write(
            'UPDATE users SET last_login_at = ?, updated_at = ? WHERE id = ?',
            [self::now(), self::now(), $id]
        );
    }
}

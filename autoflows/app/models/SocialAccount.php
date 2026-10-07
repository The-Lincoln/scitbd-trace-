<?php
/**
 * SocialAccount — connected Gmail / Facebook channel credentials.
 *
 * One row per (user, provider). user_id = 0 (shared) is used when nobody is
 * signed in so a single-operator install can connect channels globally.
 */
declare(strict_types=1);

final class SocialAccount extends Model
{
    public static function forUser(int $userId, string $provider): ?array
    {
        $row = self::row(
            'SELECT * FROM social_accounts WHERE user_id = ? AND provider = ? ORDER BY id DESC LIMIT 1',
            [$userId, $provider]
        );
        if ($row === null && $userId !== 0) {
            // Fall back to the shared (global) connection.
            $row = self::row(
                'SELECT * FROM social_accounts WHERE user_id = 0 AND provider = ? ORDER BY id DESC LIMIT 1',
                [$provider]
            );
        }
        return $row === null ? null : self::decorate($row);
    }

    /** @return array<string,array|null> provider => row */
    public static function allForUser(int $userId): array
    {
        $out = ['google' => null, 'facebook' => null];
        foreach (array_keys($out) as $p) {
            $out[$p] = self::forUser($userId, $p);
        }
        return $out;
    }

    public static function statusForUser(int $userId): array
    {
        $out = [];
        foreach (['google', 'facebook'] as $p) {
            $acc = self::forUser($userId, $p);
            $out[$p] = [
                'connected' => $acc !== null && trim((string) ($acc['access_token_plain'] ?? '')) !== '',
                'email'     => $acc['email'] ?? null,
                'name'      => $acc['name'] ?? null,
                'avatar'    => $acc['avatar'] ?? null,
                'page_name' => $acc['page_name'] ?? null,
                'page_id'   => $acc['page_id'] ?? null,
                'expires_at'=> $acc['expires_at'] ?? null,
                'expired'   => $acc !== null ? self::isExpired($acc) : false,
                'simulated' => $acc !== null && (bool) ($acc['meta_arr']['simulated'] ?? false),
                'updated_at'=> $acc['updated_at'] ?? null,
            ];
        }
        return $out;
    }

    public static function isExpired(array $acc): bool
    {
        $exp = trim((string) ($acc['expires_at'] ?? ''));
        if ($exp === '') {
            return false; // no expiry info → treat as usable
        }
        return strtotime($exp) !== false && strtotime($exp) < (time() + 60);
    }

    public static function decorate(array $row): array
    {
        $row['meta_arr'] = self::unjson($row['meta'] ?? null, []);
        if (class_exists('SocialAuth', false)) {
            $row['access_token_plain'] = SocialAuth::unseal((string) ($row['access_token'] ?? ''));
            $row['refresh_token_plain'] = SocialAuth::unseal((string) ($row['refresh_token'] ?? ''));
            $row['page_token_plain'] = SocialAuth::unseal((string) ($row['page_token'] ?? ''));
        } else {
            $row['access_token_plain'] = (string) ($row['access_token'] ?? '');
            $row['refresh_token_plain'] = (string) ($row['refresh_token'] ?? '');
            $row['page_token_plain'] = (string) ($row['page_token'] ?? '');
        }
        return $row;
    }

    /**
     * Upsert a connection. Tokens are sealed before storage.
     * @param array $d provider,user_id,provider_user_id,email,name,avatar,access_token,refresh_token,expires_in,scopes,page_*
     */
    public static function saveConnection(array $d): array
    {
        $provider = (string) ($d['provider'] ?? '');
        $userId = (int) ($d['user_id'] ?? 0);
        $existing = self::row(
            'SELECT * FROM social_accounts WHERE user_id = ? AND provider = ?',
            [$userId, $provider]
        );

        $seal = class_exists('SocialAuth', false)
            ? fn (string $v): string => SocialAuth::seal($v)
            : fn (string $v): string => $v;

        $access = $seal(trim((string) ($d['access_token'] ?? ($existing['access_token'] ?? ''))));
        // Keep the old refresh token when the provider does not re-issue one.
        $refreshRaw = trim((string) ($d['refresh_token'] ?? ''));
        $refresh = $refreshRaw !== '' ? $seal($refreshRaw)
            : (string) ($existing['refresh_token'] ?? '');
        $pageTokenRaw = trim((string) ($d['page_token'] ?? ''));
        $pageToken = $pageTokenRaw !== '' ? $seal($pageTokenRaw)
            : (string) ($existing['page_token'] ?? '');

        $expiresAt = null;
        if (!empty($d['expires_at'])) {
            $expiresAt = (string) $d['expires_at'];
        } elseif (!empty($d['expires_in'])) {
            $expiresAt = date('Y-m-d H:i:s', time() + max(60, (int) $d['expires_in']));
        } elseif ($existing) {
            $expiresAt = $existing['expires_at'];
        }

        $meta = $existing ? (self::unjson($existing['meta'] ?? null, [])) : [];
        if (!empty($d['meta']) && is_array($d['meta'])) {
            $meta = array_merge($meta, $d['meta']);
        }

        $now = self::now();
        $scopes = $d['scopes'] ?? ($existing['scopes'] ?? null);
        if (is_array($scopes)) {
            $scopes = implode(' ', $scopes);
        }

        if ($existing === null) {
            self::write(
                'INSERT INTO social_accounts (user_id, provider, provider_user_id, email, name, avatar,
                                              access_token, refresh_token, expires_at, scopes,
                                              page_id, page_name, page_token, meta, created_at, updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$userId, $provider, $d['provider_user_id'] ?? null, $d['email'] ?? null,
                 $d['name'] ?? null, $d['avatar'] ?? null, $access ?: null, $refresh ?: null,
                 $expiresAt, $scopes, $d['page_id'] ?? null, $d['page_name'] ?? null,
                 $pageToken ?: null, self::json($meta), $now, $now]
            );
            return self::decorate(self::row('SELECT * FROM social_accounts WHERE id = ?', [self::newId()]));
        }

        self::write(
            'UPDATE social_accounts SET provider_user_id = COALESCE(?, provider_user_id),
                                        email = COALESCE(?, email), name = COALESCE(?, name),
                                        avatar = COALESCE(?, avatar), access_token = ?,
                                        refresh_token = ?, expires_at = ?, scopes = ?,
                                        page_id = COALESCE(?, page_id), page_name = COALESCE(?, page_name),
                                        page_token = ?, meta = ?, updated_at = ? WHERE id = ?',
            [$d['provider_user_id'] ?? null, $d['email'] ?? null, $d['name'] ?? null,
             $d['avatar'] ?? null, $access ?: null, $refresh ?: null, $expiresAt, $scopes,
             $d['page_id'] ?? null, $d['page_name'] ?? null, $pageToken ?: null,
             self::json($meta), $now, (int) $existing['id']]
        );
        return self::decorate(self::row('SELECT * FROM social_accounts WHERE id = ?', [(int) $existing['id']]));
    }

    public static function saveSimulated(int $userId, string $provider, array $profile): array
    {
        return self::saveConnection([
            'user_id' => $userId,
            'provider' => $provider,
            'provider_user_id' => (string) ($profile['id'] ?? ('dev-' . $provider)),
            'email' => (string) ($profile['email'] ?? ''),
            'name' => (string) ($profile['name'] ?? ''),
            'avatar' => (string) ($profile['avatar'] ?? ''),
            'access_token' => 'simulated',
            'scopes' => $provider === 'google' ? 'openid email profile gmail.send (simulated)' : 'public_profile email (simulated)',
            'meta' => ['simulated' => true, 'note' => 'Dev connection — no real API calls. Add OAuth keys in Settings for live publishing.'],
        ]);
    }

    public static function disconnect(int $userId, string $provider): void
    {
        self::write(
            'DELETE FROM social_accounts WHERE provider = ? AND (user_id = ? OR user_id = 0)',
            [$provider, $userId]
        );
    }
}

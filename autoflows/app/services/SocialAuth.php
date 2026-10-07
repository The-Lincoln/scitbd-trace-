<?php
/**
 * SocialAuth — Google (Gmail) + Facebook OAuth2 for AutoFlows.
 *
 * Plain-cURL, no Composer. Handles:
 *   login URL building, code↔token exchange, profile fetch,
 *   Facebook long-lived token + Pages lookup, Google refresh,
 *   and light token obfuscation at rest (openssl or base64).
 */
declare(strict_types=1);

final class SocialAuth
{
    public const PROVIDERS = ['google', 'facebook'];

    public static function isConfigured(string $provider): bool
    {
        if ($provider === 'google') {
            return trim((string) config('oauth.google.client_id')) !== ''
                && trim((string) config('oauth.google.client_secret')) !== '';
        }
        if ($provider === 'facebook') {
            return trim((string) config('oauth.facebook.app_id')) !== ''
                && trim((string) config('oauth.facebook.app_secret')) !== '';
        }
        return false;
    }

    /** @return array<string,bool> */
    public static function configuredMap(): array
    {
        return [
            'google' => self::isConfigured('google'),
            'facebook' => self::isConfigured('facebook'),
        ];
    }

    public static function authUrl(string $provider): string
    {
        $state = bin2hex(random_bytes(16));
        $_SESSION['oauth_state_' . $provider] = $state;
        $redirect = oauth_redirect_uri($provider);

        if ($provider === 'google') {
            $q = http_build_query([
                'client_id' => trim((string) config('oauth.google.client_id')),
                'redirect_uri' => $redirect,
                'response_type' => 'code',
                'scope' => implode(' ', (array) config('oauth.google.scopes')),
                'access_type' => 'offline',
                'prompt' => 'consent',
                'include_granted_scopes' => 'true',
                'state' => $state,
            ]);
            return ((string) config('oauth.google.auth_url')) . '?' . $q;
        }

        $q = http_build_query([
            'client_id' => trim((string) config('oauth.facebook.app_id')),
            'redirect_uri' => $redirect,
            'response_type' => 'code',
            'scope' => implode(',', (array) config('oauth.facebook.scopes')),
            'state' => $state,
        ]);
        return ((string) config('oauth.facebook.auth_url')) . '?' . $q;
    }

    public static function checkState(string $provider, ?string $got): bool
    {
        $want = $_SESSION['oauth_state_' . $provider] ?? null;
        unset($_SESSION['oauth_state_' . $provider]);
        if (!is_string($want) || $want === '' || !is_string($got) || $got === '') {
            return false;
        }
        return hash_equals($want, $got);
    }

    /**
     * Exchange ?code for tokens. Returns ['tokens'=>[…],'profile'=>[…]].
     * @throws RuntimeException on any OAuth failure
     */
    public static function exchange(string $provider, string $code): array
    {
        $redirect = oauth_redirect_uri($provider);

        if ($provider === 'google') {
            $tokens = self::postForm(
                (string) config('oauth.google.token_url'),
                [
                    'code' => $code,
                    'client_id' => trim((string) config('oauth.google.client_id')),
                    'client_secret' => trim((string) config('oauth.google.client_secret')),
                    'redirect_uri' => $redirect,
                    'grant_type' => 'authorization_code',
                ]
            );
            if (empty($tokens['access_token'])) {
                throw new RuntimeException('Google token error: ' . substr(json_encode($tokens), 0, 220));
            }
            $profile = self::getJson(
                (string) config('oauth.google.userinfo_url'),
                ['Authorization: Bearer ' . $tokens['access_token']]
            );
            if (empty($profile['sub'])) {
                throw new RuntimeException('Google profile error: ' . substr(json_encode($profile), 0, 220));
            }
            return [
                'tokens' => $tokens,
                'profile' => [
                    'provider' => 'google',
                    'id' => (string) $profile['sub'],
                    'email' => (string) ($profile['email'] ?? ''),
                    'name' => (string) ($profile['name'] ?? ''),
                    'avatar' => (string) ($profile['picture'] ?? ''),
                ],
            ];
        }

        // ---- facebook ----
        $ver = (string) config('oauth.facebook.graph_version', 'v18.0');
        $tokens = self::getJson(
            (string) config('oauth.facebook.token_url') . '?' . http_build_query([
                'client_id' => trim((string) config('oauth.facebook.app_id')),
                'client_secret' => trim((string) config('oauth.facebook.app_secret')),
                'redirect_uri' => $redirect,
                'code' => $code,
            ])
        );
        if (empty($tokens['access_token'])) {
            throw new RuntimeException('Facebook token error: ' . substr(json_encode($tokens), 0, 220));
        }
        $short = (string) $tokens['access_token'];
        // Upgrade to a 60-day token (best-effort; short token still works).
        $long = self::getJson(
            "https://graph.facebook.com/{$ver}/oauth/access_token?" . http_build_query([
                'grant_type' => 'fb_exchange_token',
                'client_id' => trim((string) config('oauth.facebook.app_id')),
                'client_secret' => trim((string) config('oauth.facebook.app_secret')),
                'fb_exchange_token' => $short,
            ])
        );
        $access = (string) ($long['access_token'] ?? $short);
        $expiresIn = (int) ($long['expires_in'] ?? $tokens['expires_in'] ?? 0);

        $me = self::getJson(
            "https://graph.facebook.com/{$ver}/me?" . http_build_query([
                'fields' => 'id,name,email,picture.type(large)',
                'access_token' => $access,
            ])
        );
        if (empty($me['id'])) {
            throw new RuntimeException('Facebook profile error: ' . substr(json_encode($me), 0, 220));
        }
        $avatar = '';
        if (isset($me['picture']['data']['url'])) {
            $avatar = (string) $me['picture']['data']['url'];
        }

        // First manageable Page (for auto-posting as the Page).
        $pageId = $pageName = $pageToken = null;
        $pages = self::getJson(
            "https://graph.facebook.com/{$ver}/me/accounts?" . http_build_query(['access_token' => $access, 'limit' => 25])
        );
        $first = $pages['data'][0] ?? null;
        if (is_array($first) && !empty($first['id'])) {
            $pageId = (string) $first['id'];
            $pageName = (string) ($first['name'] ?? '');
            $pageToken = (string) ($first['access_token'] ?? '');
        }

        return [
            'tokens' => [
                'access_token' => $access,
                'expires_in' => $expiresIn ?: 5184000,
                'page_id' => $pageId,
                'page_name' => $pageName,
                'page_token' => $pageToken,
            ],
            'profile' => [
                'provider' => 'facebook',
                'id' => (string) $me['id'],
                'email' => (string) ($me['email'] ?? ''),
                'name' => (string) ($me['name'] ?? ''),
                'avatar' => $avatar,
            ],
        ];
    }

    /** Refresh an expired Google access token. Returns new tokens or []. */
    public static function refreshGoogle(array $account): array
    {
        $refresh = (string) ($account['refresh_token_plain'] ?? '');
        if ($refresh === '' || $refresh === 'simulated') {
            return [];
        }
        $tokens = self::postForm((string) config('oauth.google.token_url'), [
            'client_id' => trim((string) config('oauth.google.client_id')),
            'client_secret' => trim((string) config('oauth.google.client_secret')),
            'refresh_token' => $refresh,
            'grant_type' => 'refresh_token',
        ]);
        return !empty($tokens['access_token']) ? $tokens : [];
    }

    // ------------------------------------------------------------- tokens ---

    /** Obfuscate a token for DB storage. OpenSSL when a seal key exists. */
    public static function seal(string $plain): string
    {
        if ($plain === '') {
            return '';
        }
        $key = (string) config('oauth.seal_key', '');
        if ($key !== '' && function_exists('openssl_encrypt')) {
            $iv = random_bytes(12);
            $ct = openssl_encrypt($plain, 'aes-256-gcm', hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv, $tag);
            if ($ct !== false) {
                return 'gcm1.' . base64_encode($iv . ($tag ?? '') . $ct);
            }
        }
        return 'b64.' . base64_encode($plain);
    }

    public static function unseal(string $sealed): string
    {
        if ($sealed === '' || $sealed === 'simulated') {
            return $sealed;
        }
        if (str_starts_with($sealed, 'gcm1.')) {
            $key = (string) config('oauth.seal_key', '');
            $raw = base64_decode(substr($sealed, 5), true);
            if ($raw === false || $key === '' || !function_exists('openssl_decrypt')) {
                return '';
            }
            $iv = substr($raw, 0, 12);
            $tag = substr($raw, 12, 16);
            $ct = substr($raw, 28);
            $pt = openssl_decrypt($ct, 'aes-256-gcm', hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv, $tag);
            return $pt === false ? '' : $pt;
        }
        if (str_starts_with($sealed, 'b64.')) {
            $pt = base64_decode(substr($sealed, 4), true);
            return $pt === false ? '' : $pt;
        }
        // Legacy rows stored the raw token.
        return $sealed;
    }

    // ---------------------------------------------------------------- http ---

    /** @return array<string,mixed> */
    public static function postForm(string $url, array $fields): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new RuntimeException('HTTP error: ' . $err);
        }
        $data = json_decode((string) $body, true);
        if (!is_array($data)) {
            throw new RuntimeException('Bad JSON (HTTP ' . $code . '): ' . substr((string) $body, 0, 200));
        }
        if ($code >= 400 && empty($data['access_token'])) {
            throw new RuntimeException('OAuth HTTP ' . $code . ': ' . substr((string) $body, 0, 220));
        }
        return $data;
    }

    /** @param string[] $headers @return array<string,mixed> */
    public static function getJson(string $url, array $headers = []): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
        ]);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new RuntimeException('HTTP error: ' . $err);
        }
        $data = json_decode((string) $body, true);
        return is_array($data) ? $data : [];
    }
}

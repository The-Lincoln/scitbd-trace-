<?php
/**
 * Shared helper functions. Pure functions only — no classes, safe to include
 * from the web front controller and from CLI tools alike.
 */
declare(strict_types=1);

/**
 * Read a dotted config key, e.g. `chat.defaults.system`. DB settings win.
 *
 * The file config is latched once; the `settings` overlay is applied lazily
 * and only latched once the table is actually readable — first boot creates
 * the schema *after* the first config() call. Call config_refresh() after
 * writing settings so the current request re-reads them.
 */
function &config_state(): array
{
    // `file` is the pristine require()'d config and must never be mutated;
    // `cfg` is the overlay result. Keeping them apart is what makes a re-merge
    // idempotent — see apply_setting_overrides() below.
    static $state = ['file' => null, 'cfg' => null, 'merged' => false, 'loading' => false];
    return $state;
}

function config_refresh(): void
{
    $state = &config_state();
    $state['merged'] = false;
}

function config(string $key, mixed $default = null): mixed
{
    $state = &config_state();

    if ($state['file'] === null) {
        $state['file'] = require BASE_PATH . '/app/config.php';
        $state['cfg']  = $state['file'];
    }

    // Re-entrancy guard: merging reads the DB, and Database::pdo() itself
    // calls config('db_path'). Without it the nested call would start a fresh
    // merge that reads the DB that is still opening — an endless loop.
    //
    // Always merge onto `file`, never onto the previous result: merging a
    // previously-merged config would leave a removed setting baked in forever.
    if (!$state['merged'] && !$state['loading']) {
        $state['loading'] = true;
        try {
            $applied = apply_setting_overrides($state['file']);
        } finally {
            $state['loading'] = false;
        }
        if ($applied !== null) {
            $state['cfg']   = $applied;
            $state['merged'] = true;
        }
    }

    $value = $state['cfg'];
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

/**
 * Overlay rows from `settings` onto the file config.
 * Keys are dot-paths written without dots, e.g. `chat_provider` -> chat.provider.
 *
 * Returns null when the settings table is not readable yet, so the caller
 * retries instead of latching a config that is missing its overrides.
 */
function apply_setting_overrides(array $cfg): ?array
{
    try {
        if (!class_exists('Setting', false)) {
            $file = BASE_PATH . '/app/models/Setting.php';
            if (!is_file($file)) {
                return null;
            }
            require_once $file;
        }
        $saved = Setting::all();
    } catch (Throwable) {
        return null; // DB not ready yet — do not latch; retry on the next call.
    }

    // Explicit mapping keeps intent readable and prevents stray keys.
    $map = [
        'chat_provider'    => 'chat.provider',
        'chat_endpoint'    => 'chat.providers.ollama.endpoint',
        'chat_model'       => 'chat.providers.ollama.model',
        'chat_temperature' => 'chat.defaults.temperature',
        'chat_num_predict' => 'chat.defaults.num_predict',
        'chat_system'      => 'chat.defaults.system',
        'brand_name'       => 'brand.name',
        'brand_voice'      => 'brand.voice',
        'brand_audience'   => 'brand.audience',
        'brand_products'   => 'brand.products',
        'brand_links'      => 'brand.links',
        'brand_cta'        => 'brand.cta',
        'brand_emoji'      => 'brand.emoji',
        'brand_hashtags'   => 'brand.hashtags',
        // OAuth credentials (stored via Settings; never logged).
        'oauth_google_client_id'     => 'oauth.google.client_id',
        'oauth_google_client_secret' => 'oauth.google.client_secret',
        'oauth_facebook_app_id'      => 'oauth.facebook.app_id',
        'oauth_facebook_app_secret'  => 'oauth.facebook.app_secret',
        'publish_gmail_to'           => 'publish.default_gmail_to',
        // Slack workspace T0AGURY3K1D (sealed tokens; never logged).
        'slack_bot_token'      => 'slack.bot_token',
        'slack_app_token'      => 'slack.app_token',
        'slack_signing_secret' => 'slack.signing_secret',
        'slack_default_channel'=> 'slack.default_channel',
    ];

    $cast = [
        'chat.temperature' => 'float',
        'chat.num_predict' => 'int',
        'brand.emoji'      => 'int',
        'brand.hashtags'   => 'int',
    ];

    foreach ($map as $settingKey => $path) {
        if (!isset($saved[$settingKey]) || $saved[$settingKey] === '') {
            continue;
        }
        $raw  = $saved[$settingKey];
        $type = $cast[str_replace('providers.ollama.', '', $path)] ?? $cast[$path] ?? null;

        $value = match ($type) {
            'float' => (float) $raw,
            'int'   => (int) $raw,
            default => (string) $raw,
        };

        if ($path === 'chat.providers.ollama.endpoint') {
            $value = rtrim((string) $value, '/');
        }

        $ref = &$cfg;
        foreach (explode('.', $path) as $i => $part) {
            $last = $i === substr_count($path, '.');
            if ($last) {
                $ref[$part] = $value;
                break;
            }
            if (!isset($ref[$part]) || !is_array($ref[$part])) {
                $ref[$part] = [];
            }
            $ref = &$ref[$part];
        }
        unset($ref);
    }
    return $cfg;
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $route = ''): string
{
    return 'index.php?r=' . trim($route, '/');
}

function redirect(string $route): never
{
    header('Location: ' . url($route));
    exit;
}

// ------------------------------------------------------------------ CSRF ---

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
}

function csrf_verify(): bool
{
    $token = $_POST['_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $sess = $_SESSION['csrf'] ?? '';
    if (!is_string($token) || $token === '' || !is_string($sess) || $sess === '') {
        return false;
    }
    return hash_equals($sess, $token);
}

// ----------------------------------------------------------------- flash ---

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flash(): array
{
    $all = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $all;
}

// -------------------------------------------------------------- responses ---

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function is_ajax(): bool
{
    return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
        || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
}

// ------------------------------------------------------------- formatting ---

function human_size(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    $n = (float) $bytes;
    while ($n >= 1024 && $i < count($units) - 1) {
        $n /= 1024;
        $i++;
    }
    return round($n, 1) . ' ' . $units[$i];
}

function time_ago(string $datetime): string
{
    $ts = strtotime($datetime);
    if ($ts === false) {
        return '—';
    }
    $diff = time() - $ts;
    if ($diff < 0) {
        return 'in ' . time_ago_after(-$diff);
    }
    if ($diff < 60) {
        return 'just now';
    }
    return time_ago_after($diff);
}

function time_ago_after(int $diff): string
{
    if ($diff < 60) return $diff . 's';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    return floor($diff / 86400) . 'd ago';
}

function excerpt(?string $text, int $n = 90): string
{
    $t = trim(preg_replace('/\s+/', ' ', (string) $text));
    return mb_strlen($t) <= $n ? $t : mb_substr($t, 0, $n - 1) . '…';
}

/** URL-safe slug from a title. */
function slugify(string $text): string
{
    $text = mb_strtolower(trim($text));
    $text = preg_replace('/[^\pL\pN]+/u', '-', $text) ?? '';
    $text = trim($text, '-');
    return $text !== '' ? $text : 'untitled';
}

function token(int $len = 8): string
{
    return substr(bin2hex(random_bytes((int) ceil($len / 2))), 0, $len);
}

function ensure_dir(string $path): string
{
    if (!is_dir($path)) {
        mkdir($path, 0775, true);
    }
    return $path;
}

function today_str(string $format = 'Y-m-d'): string
{
    return date($format);
}

/** Words-per-minute reading estimate for prose. */
function reading_minutes(string $text): int
{
    $words = str_word_count(strip_tags($text));
    return max(1, (int) ceil($words / 200));
}

/** Clamp an int into a range. */
function clamp(int $v, int $min, int $max): int
{
    return max($min, min($max, $v));
}

function clampf(float $v, float $min, float $max): float
{
    return max($min, min($max, $v));
}

// ----------------------------------------------------------------- auth ----

/**
 * Absolute base URL of the current request (scheme + host + dir of index.php).
 * Used to build OAuth redirect URIs that match the console configuration.
 */
function base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1:8020';
    // dirname of /index.php or /public/index.php → keep the public dir.
    $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php');
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
    $dir = $dir === '/' || $dir === '.' ? '' : $dir;
    return $scheme . '://' . $host . $dir;
}

/** Redirect URI registered in Google / Facebook consoles for a provider. */
function oauth_redirect_uri(string $provider): string
{
    return base_url() . '/index.php?r=auth/' . $provider . '/callback';
}

/** Currently signed-in user row, or null for guests. */
function current_user(): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return null;
    }
    $uid = (int) ($_SESSION[(string) config('auth.session_key', 'af_uid')] ?? 0);
    if ($uid <= 0) {
        return null;
    }
    if (!class_exists('User', false)) {
        $f = BASE_PATH . '/app/models/User.php';
        if (is_file($f)) {
            require_once $f;
        } else {
            return null;
        }
    }
    try {
        return User::find($uid);
    } catch (Throwable) {
        return null;
    }
}

function auth_login(array $user): void
{
    $_SESSION[(string) config('auth.session_key', 'af_uid')] = (int) $user['id'];
    if (function_exists('session_regenerate_id') && !headers_sent()) {
        @session_regenerate_id(true);
    }
}

function auth_logout(): void
{
    unset($_SESSION[(string) config('auth.session_key', 'af_uid')]);
}

function auth_user_id(): int
{
    return (int) ($_SESSION[(string) config('auth.session_key', 'af_uid')] ?? 0);
}

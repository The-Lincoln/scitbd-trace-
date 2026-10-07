<?php
/**
 * Base controller — input parsing, CSRF and JSON helpers.
 */
declare(strict_types=1);

abstract class Controller
{
    protected View $view;

    public function __construct()
    {
        $this->view = new View();
    }

    protected function requireCsrf(): void
    {
        if (!csrf_verify()) {
            if (is_ajax()) {
                json_response(['ok' => false, 'error' => 'Invalid CSRF token'], 419);
            }
            http_response_code(419);
            exit('Invalid CSRF token. Please go back and retry.');
        }
    }

    protected function str(string $key, string $default = ''): string
    {
        $v = $_POST[$key] ?? $default;
        return is_string($v) ? trim($v) : $default;
    }

    protected function input(string $key, string $default = ''): string
    {
        $v = $_POST[$key] ?? $_GET[$key] ?? $default;
        return is_string($v) ? trim($v) : $default;
    }

    protected function int(string $key, int $default = 0): int
    {
        $v = $_POST[$key] ?? $_GET[$key] ?? null;
        return is_numeric($v) ? (int) $v : $default;
    }

    protected function bool(string $key, bool $default = false): bool
    {
        $v = $_POST[$key] ?? $_GET[$key] ?? null;
        if ($v === null) {
            return $default;
        }
        return in_array((string) $v, ['1', 'true', 'on', 'yes'], true);
    }

    /** Parse a JSON request body (fetch() posts) and merge it into $_POST. */
    protected function jsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === '' || $raw === false) {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [];
        }
        foreach ($data as $k => $v) {
            if (is_scalar($v) || $v === null) {
                $_POST[$k] = $v;
            }
        }
        return $data;
    }

    /** Required non-empty string from JSON body or POST. */
    protected function requireStr(string $key, array $body, int $max = 4000): string
    {
        $v = trim((string) ($body[$key] ?? $_POST[$key] ?? ''));
        if ($v === '') {
            json_response(['ok' => false, 'error' => ucfirst($key) . ' is required'], 422);
        }
        return mb_substr($v, 0, $max);
    }
}

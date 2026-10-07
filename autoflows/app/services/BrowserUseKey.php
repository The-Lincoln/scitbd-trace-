<?php
/**
 * BrowserUseKey — single source of truth for BROWSER_USE_API_KEY (bu_…).
 *
 * 1) CEO agents (Python: ceo/browseruse_key.py)
 * 2) SCCRM agents (PHP: this file via sccrm/services/BrowserSkillService.php,
 *    AgentBrowserService.php, sccrm/ai/*)
 * 3) Application (autoflows/app/config.php 'browser' section + AgentBrowser /
 *    BrowserSkill exec env passthrough + trace/* tracers)
 *
 * Resolution order: real env → trace/.env → ceo/.env → '' (never throws).
 * Never logs the full key — use ::masked() for diagnostics.
 */
declare(strict_types=1);

final class BrowserUseKey
{
    public const ENV = 'BROWSER_USE_API_KEY';
    public const HEADER = 'X-Browser-Use-API-Key';

    public static function get(): string
    {
        $k = trim((string)(getenv(self::ENV) ?: ''));
        if ($k !== '') {
            return $k;
        }
        foreach (self::envFiles() as $f) {
            if (is_file($f)) {
                foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                    $line = trim($line);
                    if ($line === '' || $line[0] === '#') {
                        continue;
                    }
                    if (str_starts_with($line, self::ENV . '=')) {
                        $v = trim(substr($line, strlen(self::ENV . '=')));
                        $v = trim($v, "\"' \t");
                        if ($v !== '') {
                            @putenv(self::ENV . '=' . $v);
                            return $v;
                        }
                    }
                }
            }
        }
        return '';
    }

    /** @return string[] candidate .env paths (trace root first). */
    public static function envFiles(): array
    {
        $traceRoot = dirname(__DIR__, 2);
        if (basename($traceRoot) !== 'trace' && is_dir($traceRoot . '/trace')) {
            $traceRoot .= '/trace';
        }
        return [$traceRoot . '/.env', dirname($traceRoot) . '/.env'];
    }

    public static function has(): bool
    {
        return self::get() !== '';
    }

    /** bu_ab12…WXYZ — safe for logs / status pages. */
    public static function masked(): string
    {
        $k = self::get();
        if ($k === '') {
            return '(missing)';
        }
        if (strlen($k) <= 10) {
            return substr($k, 0, 3) . '****';
        }
        return substr($k, 0, 6) . '****' . substr($k, -4);
    }

    /** Auth header for direct Cloud REST calls. */
    public static function headers(): array
    {
        $k = self::get();
        return $k !== '' ? [self::HEADER . ': ' . $k] : [];
    }

    /** Ensure child-process env (agent-browser -p browseruse, bsk, SDKs) carries the key. */
    public static function ensureEnv(array $env = []): array
    {
        $k = self::get();
        if ($k !== '' && empty($env[self::ENV])) {
            $env[self::ENV] = $k;
        }
        return $env;
    }
}

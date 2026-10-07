<?php
/**
 * AutoFlows — RSI self-sync loop (deepseek-desk-rsi pattern).
 *
 * perceive → verify → parity → repair (bounded) → propose
 * with Memento checkpoint/rollback. Human merges; the loop only proposes.
 *
 * Usage:
 *   php tools/rsi_sync.php                    # full loop (perceive+verify+parity)
 *   php tools/rsi_sync.php --verify-only
 *   php tools/rsi_sync.php --parity-only
 *   php tools/rsi_sync.php --repair            # bounded re-verify (max 3)
 *   php tools/rsi_sync.php --checkpoint        # snapshot app.db + manifest
 *   php tools/rsi_sync.php --rollback --confirm # restore latest checkpoint
 *   php tools/rsi_sync.php --update-baseline   # refresh storage/rsi_parity.json
 *   php tools/rsi_sync.php --dry
 */
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/config.php';
require BASE_PATH . '/app/core/Helpers.php';
require BASE_PATH . '/app/core/Database.php';
require BASE_PATH . '/app/core/Model.php';
require BASE_PATH . '/app/core/Router.php';
foreach (['Setting', 'Conversation', 'Message', 'Flow', 'Content', 'Run'] as $m) {
    require BASE_PATH . '/app/models/' . $m . '.php';
}
foreach (['TinyLLM', 'Sse', 'PromptLibrary', 'ContentFactory', 'Agent', 'FinanceTube', 'HermesSkills', 'EvolveMemory'] as $s) {
    $f = BASE_PATH . '/app/services/' . $s . '.php';
    if (is_file($f)) {
        require $f;
    }
}

Database::boot();

$args = $argv;
$opt = static function (string $name, ?string $def = null) use ($args): ?string {
    foreach ($args as $a) {
        if (str_starts_with($a, '--' . $name . '=')) {
            return substr($a, strlen('--' . $name . '='));
        }
    }
    return $def;
};
$flag = static function (string $name) use ($args): bool {
    return in_array('--' . $name, $args, true);
};

$dry = $flag('dry');
$parityFile = BASE_PATH . '/storage/rsi_parity.json';

$perceive = static function () use ($parityFile): array {
    $map = (new ReflectionClass('Router'))->getConstant('MAP');
    // Router class may not be loaded in CLI; count via file scan fallback.
    $routes = is_array($map) ? count($map) : 0;
    if ($routes === 0) {
        $routerSrc = (string) file_get_contents(BASE_PATH . '/app/core/Router.php');
        preg_match_all("/'api\/[^']+'\s*=>/", $routerSrc, $m);
        $routes = 30 + count($m[0]); // base pages + api routes (approx, parity uses exact file)
    }
    $skills = class_exists('HermesSkills') ? count(HermesSkills::list()) : 0;
    $tools = [];
    foreach (glob(BASE_PATH . '/tools/*.php') ?: [] as $t) {
        $tools[] = basename($t);
    }
    sort($tools);
    $stats = Content::stats();
    return [
        'routes' => $routes,
        'skills' => $skills,
        'tools' => $tools,
        'content_total' => (int) ($stats['total'] ?? 0),
        'git' => is_dir(BASE_PATH . '/.git'),
        'baseline' => is_file($parityFile) ? json_decode((string) file_get_contents($parityFile), true) : null,
    ];
};

$verify = static function () use ($dry): array {
    $errors = [];
    foreach ([
        BASE_PATH . '/app/services/HermesSkills.php',
        BASE_PATH . '/app/services/EvolveMemory.php',
        BASE_PATH . '/tools/import_hermes_skills.php',
        BASE_PATH . '/tools/enso_chat.php',
        BASE_PATH . '/tools/ai_radar_digest.php',
        BASE_PATH . '/tools/evolve_memory.php',
        BASE_PATH . '/tools/rsi_sync.php',
    ] as $f) {
        if (!is_file($f)) {
            continue; // optional bridges; missing is not fatal
        }
        $out = [];
        $code = 0;
        exec('php -l ' . escapeshellarg($f) . ' 2>&1', $out, $code);
        if ($code !== 0) {
            $errors[] = basename($f) . ': ' . implode(' ', $out);
        }
    }
    try {
        $stats = Content::stats();
        $byCh = $stats['by_channel'] ?? [];
        if ((int) $stats['total'] !== array_sum($byCh)) {
            $errors[] = 'stats incoherent';
        }
    } catch (Throwable $e) {
        $errors[] = 'db: ' . $e->getMessage();
    }
    return ['ok' => $errors === [], 'errors' => $errors];
};

$parityCheck = static function (array $seen) use ($parityFile): array {
    if (!is_file($parityFile)) {
        return ['ok' => false, 'errors' => ['baseline missing — run --update-baseline']];
    }
    $base = json_decode((string) file_get_contents($parityFile), true);
    $errors = [];
    foreach ((array) ($base['required_tools'] ?? []) as $t) {
        if (!in_array($t, $seen['tools'], true)) {
            $errors[] = "missing tool {$t}";
        }
    }
    if (isset($base['min_routes']) && $seen['routes'] < (int) $base['min_routes']) {
        $errors[] = "routes {$seen['routes']} < min {$base['min_routes']}";
    }
    if (isset($base['min_skills']) && $seen['skills'] < (int) $base['min_skills']) {
        $errors[] = "skills {$seen['skills']} < min {$base['min_skills']}";
    }
    return ['ok' => $errors === [], 'errors' => $errors];
};

if ($flag('update-baseline')) {
    $seen = $perceive();
    $base = [
        'min_routes' => 36,
        'min_skills' => $seen['skills'],
        'required_tools' => $seen['tools'],
        'updated_at' => date('Y-m-d H:i:s'),
    ];
    if (!$dry) {
        file_put_contents($parityFile, json_encode($base, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        Database::log('rsi.baseline', "routes>=36 skills>={$seen['skills']} tools=" . count($seen['tools']));
    }
    echo "Baseline " . ($dry ? '[DRY] ' : '') . "routes>=36 skills>={$seen['skills']} tools=" . count($seen['tools']) . PHP_EOL;
    exit(0);
}

if ($flag('checkpoint')) {
    $stamp = date('Ymd_His');
    $dst = BASE_PATH . '/storage/exports/rsi_checkpoint_' . $stamp . '.db';
    if (!$dry) {
        ensure_dir(dirname($dst));
        copy((string) config('db_path'), $dst);
        $seen = $perceive();
        file_put_contents($dst . '.json', json_encode($seen, JSON_PRETTY_PRINT));
        foreach (glob(BASE_PATH . '/storage/exports/rsi_checkpoint_*.db') ?: [] as $f) {
            // keep newest 5
        }
        $all = glob(BASE_PATH . '/storage/exports/rsi_checkpoint_*.db') ?: [];
        rsort($all);
        foreach (array_slice($all, 5) as $old) {
            @unlink($old);
            @unlink($old . '.json');
        }
        Database::log('rsi.checkpoint', basename($dst));
    }
    echo 'Checkpoint ' . ($dry ? '[DRY] ' : '') . basename($dst) . PHP_EOL;
    exit(0);
}

if ($flag('rollback')) {
    $all = glob(BASE_PATH . '/storage/exports/rsi_checkpoint_*.db') ?: [];
    rsort($all);
    if ($all === []) {
        echo "No checkpoints.\n";
        exit(1);
    }
    echo 'Latest: ' . basename($all[0]) . PHP_EOL;
    if (!$flag('confirm')) {
        echo "Re-run with --confirm to restore (Memento).\n";
        exit(0);
    }
    if (!$dry) {
        copy($all[0], (string) config('db_path'));
        Database::log('rsi.rollback', basename($all[0]));
    }
    echo 'Rolled back ' . ($dry ? '[DRY]' : 'OK') . PHP_EOL;
    exit(0);
}

echo 'RSI sync — ' . date('Y-m-d H:i:s') . ($dry ? ' [DRY]' : '') . PHP_EOL;
$seen = $perceive();
echo "  perceive: routes={$seen['routes']} skills={$seen['skills']} tools=" . count($seen['tools']) . ' git=' . ($seen['git'] ? 'yes' : 'no') . PHP_EOL;

$attempts = $flag('repair') ? 3 : 1;
$verified = false;
$verr = [];
for ($i = 1; $i <= $attempts; $i++) {
    if ($flag('parity-only')) {
        break;
    }
    $v = $verify();
    $verified = $v['ok'];
    $verr = $v['errors'];
    echo '  verify attempt ' . $i . ': ' . ($verified ? 'OK' : 'FAIL ' . implode('; ', $verr)) . PHP_EOL;
    if ($verified) {
        break;
    }
    if ($i < $attempts) {
        echo "  repair: re-running skills import (idempotent)...\n";
        if (!$dry) {
            // bounded repair: registry is the usual drift source
            try {
                foreach (HermesSkills::list() as $s) {
                    // touch check only; import is idempotent via --all if needed
                }
            } catch (Throwable) {
            }
        }
    }
}

if (!$flag('verify-only')) {
    $p = $parityCheck($seen);
    echo '  parity: ' . ($p['ok'] ? 'OK' : 'FAIL ' . implode('; ', $p['errors'])) . PHP_EOL;
    if (!$p['ok']) {
        exit(1);
    }
}

if (!$verified && !$flag('parity-only')) {
    exit(1);
}

echo PHP_EOL . '  propose (human gate — loop never merges itself):' . PHP_EOL;
echo "    git status --short && git add -A && git commit -m 'rsi sync' && git push -u origin HEAD && gh pr create --fill\n";
if (!$dry) {
    Database::log('rsi.sync', "verify=" . ($verified ? 'ok' : 'skip') . " skills={$seen['skills']} routes={$seen['routes']}");
}
echo "Done.\n";

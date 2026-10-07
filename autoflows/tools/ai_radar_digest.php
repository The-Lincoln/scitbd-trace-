<?php
/**
 * AutoFlows — AI Radar digest bridge (ai-radar-wiki).
 *
 * Reads a local ai-radar-wiki checkout (9230-node trend graph) and prints
 * the daily summary + weekly pillar trends as an AutoFlows content brief.
 * Read-only: never writes to the radar checkout.
 *
 * Usage:
 *   php tools/ai_radar_digest.php
 *   php tools/ai_radar_digest.php --limit=5 --brief
 *   php tools/ai_radar_digest.php --source="C:\path\to\ai-radar-wiki" --dry
 *
 * Env:
 *   AI_RADAR_PATH  default C:\Users\onlin\AppData\Local\Temp\opencode\ai-radar-wiki
 */
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/config.php';
require BASE_PATH . '/app/core/Helpers.php';
require BASE_PATH . '/app/core/Database.php';

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

$defaultSrc = "C:\\Users\\onlin\\AppData\\Local\\Temp\\opencode\\ai-radar-wiki";
$source = (string) ($opt('source', getenv('AI_RADAR_PATH') ?: $defaultSrc) ?? $defaultSrc);
$limit = max(1, min(20, (int) ($opt('limit', '5') ?? '5')));
$dry = $flag('dry');
$wantBrief = $flag('brief');

echo 'AI Radar digest — ' . date('Y-m-d H:i:s') . PHP_EOL;
echo "  source: {$source}" . ($dry ? ' [DRY]' : '') . PHP_EOL;

if (!is_dir($source)) {
    echo "Source missing: {$source}\n";
    exit(1);
}

$dailyFile = $source . DIRECTORY_SEPARATOR . 'daily_summary.json';
$weeklyFile = $source . DIRECTORY_SEPARATOR . 'weekly_trends.json';
if (!is_file($dailyFile)) {
    echo "daily_summary.json not found in source.\n";
    exit(1);
}

$daily = json_decode((string) file_get_contents($dailyFile), true);
$summary = $daily['daily_summary'] ?? $daily;
$date = (string) ($summary['date'] ?? $summary['date_bj'] ?? 'unknown');
$headline = (string) ($summary['headline'] ?? '');
$overview = (string) ($summary['overview'] ?? ($summary['stats'] ?? ''));
$insights = is_array($summary['insights'] ?? null) ? $summary['insights'] : [];
$items = is_array($summary['all_today_ids'] ?? null) ? $summary['all_today_ids'] : [];

echo "  date: {$date}\n";
if ($headline !== '') {
    echo "  headline: " . excerpt($headline, 160) . "\n";
}
if ($overview !== '') {
    echo "  overview: " . excerpt($overview, 200) . "\n";
}

$n = 0;
foreach ($insights as $ins) {
    if ($n >= $limit) {
        break;
    }
    $n++;
    $pillar = (string) ($ins['pillar'] ?? $ins['pillar_key'] ?? "insight {$n}");
    echo PHP_EOL . "  [{$n}] {$pillar}: " . excerpt((string) ($ins['insight'] ?? ''), 220) . PHP_EOL;
    foreach ((array) ($ins['evidence'] ?? []) as $ev) {
        $t = (string) ($ev['title'] ?? $ev['id'] ?? '');
        $u = (string) ($ev['url'] ?? '');
        $s = (string) ($ev['score'] ?? '');
        echo '      - ' . excerpt($t, 120) . ($s !== '' ? " (score {$s})" : '') . ($u !== '' ? " <{$u}>" : '') . PHP_EOL;
    }
}
if ($items !== []) {
    echo PHP_EOL . '  today_ids (' . count($items) . '):' . PHP_EOL;
    foreach (array_slice($items, 0, $limit) as $it) {
        echo '    - ' . excerpt((string) ($it['label'] ?? $it['id'] ?? ''), 120) . PHP_EOL;
    }
}

if (is_file($weeklyFile)) {
    $weekly = json_decode((string) file_get_contents($weeklyFile), true);
    $pillars = $weekly['pillar_trends'] ?? [];
    if (is_array($pillars) && $pillars !== []) {
        echo PHP_EOL . '  weekly pillar trends:' . PHP_EOL;
        foreach ($pillars as $k => $v) {
            $t = is_array($v) ? (string) ($v['trend'] ?? '') : (string) $v;
            echo "    - {$k}: {$t}\n";
        }
    }
}

if ($wantBrief) {
    $titles = [];
    foreach ($insights as $ins) {
        foreach ((array) ($ins['evidence'] ?? []) as $ev) {
            $t = trim((string) ($ev['title'] ?? ''));
            if ($t !== '') {
                $titles[] = $t;
            }
        }
    }
    foreach ($items as $it) {
        $t = trim((string) ($it['label'] ?? ''));
        if ($t !== '' && !in_array($t, $titles, true)) {
            $titles[] = $t;
        }
    }
    $brief = 'AI Radar ' . $date . ': ' . implode(' | ', array_slice($titles, 0, $limit));
    echo PHP_EOL . '  brief: ' . excerpt($brief, 300) . PHP_EOL;
}

if (!$dry) {
    Database::log('ai_radar.digest', "date={$date} insights=" . count($insights) . " limit={$limit}");
}
echo PHP_EOL . "Done.\n";

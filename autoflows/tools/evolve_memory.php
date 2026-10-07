<?php
/**
 * AutoFlows — Evolve memory CLI (dsh-evolve pattern).
 *
 * Durable cross-session memory: remember/recall/approve/prune with tiered
 * approval and zero-token deterministic recall (bigram + FTS5 RRF).
 *
 * Usage:
 *   php tools/evolve_memory.php remember --kind=fact --scope=user --importance=1 --text="Brand voice is warm"
 *   php tools/evolve_memory.php recall --query="brand voice" --limit=5
 *   php tools/evolve_memory.php list --status=pending
 *   php tools/evolve_memory.php approve --id=3
 *   php tools/evolve_memory.php prune --id=4
 *   php tools/evolve_memory.php stats
 */
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/config.php';
require BASE_PATH . '/app/core/Helpers.php';
require BASE_PATH . '/app/core/Database.php';
require BASE_PATH . '/app/services/EvolveMemory.php';

Database::boot();
$pdo = Database::pdo();
EvolveMemory::ensureTable($pdo);

$args = $argv;
$cmd = $args[1] ?? 'stats';
$opt = static function (string $name, ?string $def = null) use ($args): ?string {
    foreach ($args as $a) {
        if (str_starts_with($a, '--' . $name . '=')) {
            return substr($a, strlen('--' . $name . '='));
        }
    }
    return $def;
};

switch ($cmd) {
    case 'remember':
        $text = trim((string) ($opt('text', '') ?? ''));
        if ($text === '') {
            echo "Missing --text=\"...\"\n";
            exit(1);
        }
        $res = EvolveMemory::remember(
            $pdo,
            (string) ($opt('kind', 'note') ?? 'note'),
            (string) ($opt('scope', 'user') ?? 'user'),
            (int) ($opt('importance', '1') ?? '1'),
            $text
        );
        Database::log('evolve.remember', "#{$res['id']} {$res['status']}: " . excerpt($text, 80));
        echo "id={$res['id']} status={$res['status']} — {$res['reason']}\n";
        break;

    case 'recall':
        $q = trim((string) ($opt('query', '') ?? ''));
        if ($q === '') {
            echo "Missing --query=\"...\"\n";
            exit(1);
        }
        $rows = EvolveMemory::recall($pdo, $q, (int) ($opt('limit', '5') ?? '5'));
        if ($rows === []) {
            echo "No memories match.\n";
            break;
        }
        foreach ($rows as $r) {
            echo "#{$r['id']} [{$r['kind']}/{$r['scope']}/i{$r['importance']}] score={$r['score']}\n";
            echo '  ' . excerpt((string) $r['text'], 200) . "\n";
        }
        break;

    case 'list':
        foreach (EvolveMemory::list($pdo, (string) ($opt('status', 'all') ?? 'all'), 30) as $r) {
            echo "#{$r['id']} [{$r['kind']}/{$r['scope']}/i{$r['importance']}/{$r['status']}] " . excerpt((string) $r['text'], 120) . "\n";
        }
        break;

    case 'approve':
        $id = (int) ($opt('id', '0') ?? '0');
        echo EvolveMemory::approve($pdo, $id) ? "Approved #{$id}\n" : "Nothing approved (missing or not pending).\n";
        Database::log('evolve.approve', "#{$id}");
        break;

    case 'prune':
        $id = (int) ($opt('id', '0') ?? '0');
        echo EvolveMemory::prune($pdo, $id) ? "Pruned #{$id}\n" : "Nothing pruned.\n";
        Database::log('evolve.prune', "#{$id}");
        break;

    case 'stats':
    default:
        echo json_encode(EvolveMemory::stats($pdo)) . PHP_EOL;
        break;
}

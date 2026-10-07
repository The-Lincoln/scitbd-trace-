<?php
/**
 * AutoFlows — Hermes-House skill importer.
 *
 * Copies verbatim SKILL.md + skill.json packs from a Hermes-House checkout
 * into storage/skills/<skill-name>/ for runtime use via HermesSkills.
 *
 * Usage:
 *   php tools/import_hermes_skills.php --list
 *   php tools/import_hermes_skills.php --only=youtube-auto,content-generator
 *   php tools/import_hermes_skills.php --all --dry
 *   php tools/import_hermes_skills.php --source="C:\path\to\Hermes-House\skills"
 *
 * Defaults to the curated Block 3 set from HermesSkills::defaultSet().
 */
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/config.php';
require BASE_PATH . '/app/core/Helpers.php';
require BASE_PATH . '/app/core/Database.php';
require BASE_PATH . '/app/services/HermesSkills.php';

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

$defaultSrc = "C:\\Users\\onlin\\AppData\\Local\\Temp\\opencode\\Hermes-House\\skills";
$source = (string) ($opt('source', getenv('HERMES_SKILLS_SRC') ?: $defaultSrc) ?? $defaultSrc);
$destBase = HermesSkills::baseDir();

if ($flag('list')) {
    $items = HermesSkills::list();
    if ($items === []) {
        echo "No skills imported yet in {$destBase}\n";
        exit(0);
    }
    printf("%-38s %-6s %s\n", 'NAME', 'FILES', 'DESCRIPTION');
    foreach ($items as $it) {
        $files = ($it['has_md'] ? 'md' : '--') . '+' . ($it['has_json'] ? 'json' : '--');
        printf("%-38s %-6s %s\n", $it['name'], $files, mb_substr($it['description'], 0, 90));
    }
    echo count($items) . " skill(s).\n";
    exit(0);
}

$onlyRaw = (string) ($opt('only', '') ?? '');
if ($flag('all')) {
    if (!is_dir($source)) {
        echo "Source not found: {$source}\n";
        exit(1);
    }
    $names = [];
    foreach (scandir($source) ?: [] as $e) {
        if ($e === '.' || $e === '..') {
            continue;
        }
        if (is_dir($source . DIRECTORY_SEPARATOR . $e)) {
            $names[] = $e;
        }
    }
    sort($names);
} elseif ($onlyRaw !== '') {
    $names = array_values(array_filter(array_map('trim', explode(',', $onlyRaw))));
} else {
    $names = HermesSkills::defaultSet();
}

$dry = $flag('dry');
echo 'Hermes import — ' . date('Y-m-d H:i:s') . PHP_EOL;
echo "  source: {$source}\n";
echo '  dest: ' . $destBase . PHP_EOL;
echo '  skills: ' . implode(',', $names) . ($dry ? ' [DRY]' : '') . PHP_EOL;

if (!is_dir($source)) {
    echo "Source missing: {$source}\n";
    exit(1);
}

if (!$dry) {
    ensure_dir($destBase);
}

$ok = 0;
$skip = 0;
foreach ($names as $name) {
    $safe = strtolower((string) preg_replace('/[^a-z0-9\-_]/', '', $name));
    $srcDir = $source . DIRECTORY_SEPARATOR . $name;
    if (!is_dir($srcDir)) {
        echo "  [skip] {$name} — not in source\n";
        $skip++;
        continue;
    }
    $dstDir = $destBase . DIRECTORY_SEPARATOR . $safe;
    $files = ['SKILL.md', 'skill.json'];
    $copied = [];
    foreach ($files as $f) {
        $src = $srcDir . DIRECTORY_SEPARATOR . $f;
        if (!is_file($src)) {
            continue;
        }
        if ($dry) {
            $copied[] = $f . '(dry)';
            continue;
        }
        ensure_dir($dstDir);
        copy($src, $dstDir . DIRECTORY_SEPARATOR . $f);
        $copied[] = $f;
    }
    if ($copied === []) {
        echo "  [skip] {$name} — no SKILL.md/skill.json\n";
        $skip++;
        continue;
    }
    $ok++;
    echo '  [ok] ' . $name . ' -> ' . implode(',', $copied) . PHP_EOL;
}

if (!$dry) {
    Database::log('hermes.import', "ok={$ok} skip={$skip} names=" . implode(',', $names));
}
echo "Done — ok={$ok} skip={$skip}" . ($dry ? ' (dry, no writes)' : '') . PHP_EOL;

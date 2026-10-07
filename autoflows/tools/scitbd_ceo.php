<?php
/**
 * AutoFlows — SCITBD CEO agent CLI (Master Directive bridge).
 *
 * Read-only by default; only push-tasks --confirm writes to the CEO DB.
 * Never writes to AutoFlows storage (suggests enso_chat commands instead).
 *
 * Usage:
 *   php tools/scitbd_ceo.php status
 *   php tools/scitbd_ceo.php prompts [--category=marketing]
 *   php tools/scitbd_ceo.php escalations
 *   php tools/scitbd_ceo.php push-tasks [--confirm]
 *   php tools/scitbd_ceo.php digest
 */
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/config.php';
require BASE_PATH . '/app/core/Helpers.php';
require BASE_PATH . '/app/core/Database.php';
require BASE_PATH . '/app/services/ScitbdCeo.php';

$args = $argv;
$cmd = $args[1] ?? 'status';
if (str_starts_with($cmd, '--')) {
    $cmd = 'status';
}
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

try {
    $profile = ScitbdCeo::profile();
} catch (Throwable $e) {
    echo 'CEO DB unavailable (' . ScitbdCeo::dbPath() . '): ' . $e->getMessage() . PHP_EOL;
    exit(1);
}

switch ($cmd) {
    case 'prompts': {
        $cat = (string) ($opt('category', '') ?? '');
        $rows = ScitbdCeo::prompts($cat !== '' ? $cat : null);
        if ($rows === []) {
            echo "No prompts" . ($cat !== '' ? " in category {$cat}" : '') . ".\n";
            break;
        }
        foreach ($rows as $r) {
            echo "[{$r['category']} #{$r['seq']}] " . excerpt((string) $r['prompt_text'], 160) . PHP_EOL;
        }
        echo count($rows) . " prompt(s). Run one via:\n";
        $first = $rows[0];
        echo '  php tools/enso_chat.php --message="' . excerpt((string) $first['prompt_text'], 90) . "\" --new\n";
        break;
    }

    case 'escalations': {
        $rows = ScitbdCeo::checkEscalations();
        if ($rows === []) {
            echo "No escalations firing (leads empty, no NPS<40 tickets). Greenfield CRM.\n";
            break;
        }
        foreach ($rows as $e) {
            echo "! [{$e['sla']}] {$e['trigger']}\n  {$e['detail']}\n";
        }
        echo count($rows) . " escalation(s).\n";
        break;
    }

    case 'push-tasks': {
        $confirm = $flag('confirm');
        $res = ScitbdCeo::pushTasks(!$confirm);
        if ($confirm) {
            echo 'Pushed ' . count($res['created']) . ' directive task(s)' . ($res['created'] ? ' -> daily #' . implode(',#', $res['created']) : '') . ", skipped {$res['skipped']} (already pushed).\n";
        } else {
            $pending = 0;
            foreach (ScitbdCeo::directiveTasks() as $t) {
                if (empty($t['daily_task_id'])) {
                    $pending++;
                }
            }
            echo "DRY: {$pending} directive task(s) would push (22 total, " . (22 - $pending) . " already have daily_task_id). Re-run with --confirm to write.\n";
        }
        break;
    }

    case 'digest': {
        $b = ScitbdCeo::briefing();
        echo 'SCITBD CEO digest — ' . date('Y-m-d H:i:s') . PHP_EOL;
        echo "  {$b['company']} | {$b['block']}\n";
        echo "  pending daily_tasks: {$b['pending_tasks']} | escalations: {$b['escalations']} | leads: {$b['leads']} | tickets: {$b['tickets']}\n";
        echo "  divisions:\n";
        foreach (ScitbdCeo::divisions() as $d) {
            echo '    - ' . $d['division_code'] . ' ' . $d['division_name'] . " [{$d['priority']}]\n";
        }
        echo "  KPIs (non-negotiable marked *):\n";
        foreach (ScitbdCeo::kpis() as $k) {
            echo '    ' . (!empty($k['is_non_negotiable']) ? '*' : ' ') . $k['metric'] . ": {$k['baseline']} -> 6m {$k['target_6m']} / 12m {$k['target_12m']}\n";
        }
        break;
    }

    case 'status':
    default: {
        $b = ScitbdCeo::briefing();
        echo 'SCITBD CEO — ' . date('Y-m-d H:i:s') . PHP_EOL;
        echo "  company: {$b['company']} (" . ($profile['company_short'] ?? '') . ")\n";
        echo "  block: {$b['block']}\n";
        echo "  services: " . count(ScitbdCeo::services()) . ' | divisions: ' . count(ScitbdCeo::divisions()) . ' | directive tasks: ' . count(ScitbdCeo::directiveTasks()) . PHP_EOL;
        echo "  pending: {$b['pending_tasks']} | escalations: {$b['escalations']} | leads: {$b['leads']} | tickets: {$b['tickets']}\n";
        break;
    }
}

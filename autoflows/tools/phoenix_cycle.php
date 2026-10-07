<?php
/**
 * AutoFlows — Phoenix cycle bridge (dsh-phoenix pattern).
 *
 * Graceful, resumable lifecycle for the localhost server + long runs:
 *   checkpoint (atomic JSON, pendingResume) -> idle-aware restart signal
 *   -> boot-token rotation (heartbeat) -> goal re-arm plan -> resume.
 * The loop proposes; a human (or scheduler) performs the actual reboot.
 * State: storage/phoenix_checkpoint.json (tmp+rename), storage/phoenix_boot.json.
 *
 * Usage:
 *   php tools/phoenix_cycle.php status
 *   php tools/phoenix_cycle.php checkpoint --goal="Ship finance drafts" --run-id=27
 *   php tools/phoenix_cycle.php checkpoint --clear
 *   php tools/phoenix_cycle.php restart [--cap=300] [--exec] [--port=8020]
 *   php tools/phoenix_cycle.php resume [--confirm]
 *   php tools/phoenix_cycle.php --dry status
 */
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/config.php';
require BASE_PATH . '/app/core/Helpers.php';
require BASE_PATH . '/app/core/Database.php';
require BASE_PATH . '/app/core/Model.php';
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
$dry = $flag('dry');

$ckptFile = BASE_PATH . '/storage/phoenix_checkpoint.json';
$bootFile = BASE_PATH . '/storage/phoenix_boot.json';
$flagFile = BASE_PATH . '/storage/phoenix_restart.flag';

$readJson = static function (string $f): ?array {
    if (!is_file($f)) {
        return null;
    }
    $d = json_decode((string) file_get_contents($f), true);
    return is_array($d) ? $d : null;
};
$atomicWrite = static function (string $f, array $data): void {
    $tmp = $f . '.tmp-' . getmypid() . '-' . time() . '-' . bin2hex(random_bytes(4));
    file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    rename($tmp, $f);
};
$runningRuns = static function (): int {
    try {
        return (int) Run::stats()['running'];
    } catch (Throwable) {
        return 0;
    }
};

switch ($cmd) {
    case 'checkpoint':
        if ($flag('clear')) {
            $cur = $readJson($ckptFile) ?? [];
            $cur['pendingResume'] = false;
            $cur['updated_at'] = date('Y-m-d H:i:s');
            if (!$dry) {
                $atomicWrite($ckptFile, $cur);
                Database::log('phoenix.checkpoint', 'cleared pendingResume');
            }
            echo "Checkpoint cleared" . ($dry ? ' [DRY]' : '') . PHP_EOL;
            break;
        }
        $goal = trim((string) ($opt('goal', '') ?? ''));
        if ($goal === '') {
            echo "Missing --goal=\"...\" (or use --clear).\n";
            exit(1);
        }
        $data = [
            'pendingResume' => true,
            'goal' => [
                'brief' => mb_substr($goal, 0, 500),
                'run_id' => (int) ($opt('run-id', '0') ?? '0'),
                'flow_id' => (int) ($opt('flow-id', '0') ?? '0'),
            ],
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if (!$dry) {
            $atomicWrite($ckptFile, $data);
            Database::log('phoenix.checkpoint', 'pendingResume: ' . excerpt($goal, 80));
        }
        echo 'Checkpoint written' . ($dry ? ' [DRY]' : '') . ": pendingResume=true goal=\"" . excerpt($goal, 80) . "\"\n";
        break;

    case 'restart': {
        $cap = max(0, min(3600, (int) ($opt('cap', '300') ?? '300')));
        $port = (string) ($opt('port', '8020') ?? '8020');
        $waited = 0;
        $running = $runningRuns();
        while ($running > 0 && $waited < $cap) {
            echo "  {$running} run(s) active — deferring restart ({$waited}s/{$cap}s)...\n";
            if ($dry) {
                break;
            }
            sleep(5);
            $waited += 5;
            $running = $runningRuns();
        }
        if ($running > 0 && $waited >= $cap) {
            echo "Cap reached with {$running} run(s) active — NOT restarting (5-min safety cap).\n";
            exit(1);
        }
        $token = bin2hex(random_bytes(16));
        if (!$dry) {
            $atomicWrite($bootFile, ['boot_token' => $token, 'rotated_at' => date('Y-m-d H:i:s')]);
            file_put_contents($flagFile, "restart requested " . date('Y-m-d H:i:s') . " port={$port}\n");
            Database::log('phoenix.restart', "idle, boot rotated, port={$port}" . ($flag('exec') ? ' exec-flag' : ''));
        }
        echo 'Idle boundary reached' . ($dry ? ' [DRY]' : '') . ". Boot token rotated.\n";
        echo "To complete the reboot (operator step):\n";
        echo "  Stop-Process -Name php -ErrorAction SilentlyContinue; php -S 127.0.0.1:{$port} -t public public/router.php\n";
        if ($flag('exec')) {
            echo "NOTE: --exec in this bridge writes the restart flag only; the operator performs the reboot (never-interrupt discipline).\n";
        }
        break;
    }

    case 'resume': {
        $ckpt = $readJson($ckptFile);
        if ($ckpt === null || empty($ckpt['pendingResume'])) {
            echo "No pending resume.\n";
            break;
        }
        $g = $ckpt['goal'] ?? [];
        echo "Re-arm plan for: " . excerpt((string) ($g['brief'] ?? ''), 160) . PHP_EOL;
        if (!empty($g['run_id'])) {
            echo "  prior run #{$g['run_id']} — review trace: php tools/nova_task_plan.php --intent=\"" . excerpt((string) ($g['brief'] ?? ''), 60) . "\"\n";
        }
        echo "  resume via: php tools/enso_chat.php --message=\"" . excerpt((string) ($g['brief'] ?? ''), 80) . "\" --preset=video --new\n";
        if ($flag('confirm')) {
            if (!$dry) {
                $ckpt['pendingResume'] = false;
                $ckpt['resumed_at'] = date('Y-m-d H:i:s');
                $atomicWrite($ckptFile, $ckpt);
                @unlink($flagFile);
                Database::log('phoenix.resume', 'confirmed: ' . excerpt((string) ($g['brief'] ?? ''), 80));
            }
            echo 'Resumed + checkpoint cleared' . ($dry ? ' [DRY]' : '') . PHP_EOL;
        } else {
            echo "Re-run with --confirm to clear pendingResume.\n";
        }
        break;
    }

    case 'status':
    default: {
        $ckpt = $readJson($ckptFile);
        $boot = $readJson($bootFile);
        echo 'Phoenix status — ' . date('Y-m-d H:i:s') . ($dry ? ' [DRY]' : '') . PHP_EOL;
        echo '  running runs: ' . $runningRuns() . PHP_EOL;
        echo '  checkpoint: ' . ($ckpt === null ? 'none' : 'pendingResume=' . var_export(!empty($ckpt['pendingResume']), true) . ' updated=' . ($ckpt['updated_at'] ?? '?')) . PHP_EOL;
        echo '  boot token: ' . ($boot === null ? 'none' : substr((string) ($boot['boot_token'] ?? ''), 0, 8) . '… rotated=' . ($boot['rotated_at'] ?? '?')) . PHP_EOL;
        echo '  restart flag: ' . (is_file($flagFile) ? 'PRESENT' : 'absent') . PHP_EOL;
        echo '  skills: ' . (class_exists('HermesSkills') ? count(HermesSkills::list()) : 0) . PHP_EOL;
        break;
    }
}

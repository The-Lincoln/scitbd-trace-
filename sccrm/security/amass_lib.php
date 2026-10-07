<?php
/**
 * Amass integration — attack-surface subdomain enumeration.
 * Uses the real `amass` binary (owasp-amass/amass, cloned under external/amass)
 * when installed; otherwise falls back to passive crt.sh enumeration so the
 * feature works out of the box, with an install guide for the full engine.
 */

function amassRepoPresent() {
    return is_dir(dirname(__DIR__, 2) . '/external/amass');
}

function amassRepoVersion() {
    $mod = dirname(__DIR__, 2) . '/external/amass/go.mod';
    if (!is_file($mod)) return null;
    foreach (file($mod, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('#^module\s+(\S+)#', trim($line), $m)) return $m[1]; // e.g. github.com/owasp-amass/amass/v5
    }
    return null;
}

/** Locate an installed amass binary (Windows `where` + unix `which`). */
function amassBinary() {
    static $bin = null;
    if ($bin !== null) return $bin;
    $bin = false;
    $candidates = [];
    foreach (['where amass 2>nul', 'which amass 2>/dev/null'] as $cmd) {
        $out = [];
        @exec($cmd, $out, $code);
        foreach ($out as $line) {
            $line = trim($line);
            if ($line !== '' && is_file($line)) { $candidates[] = $line; break; }
        }
        if ($candidates) break;
    }
    // Common install spots
    foreach (['C:\\Program Files\\amass\\amass.exe', getenv('USERPROFILE') . '\\go\\bin\\amass.exe'] as $p) {
        if ($p && is_file($p)) { $candidates[] = $p; break; }
    }
    if ($candidates) $bin = $candidates[0];
    return $bin;
}

/** Run passive enum via the Amass binary. Returns ['source','subs'=>[],'raw','error']. */
function amassEnumBinary($domain, $timeoutSec = 120) {
    $bin = amassBinary();
    if (!$bin) return ['source' => 'amass', 'subs' => [], 'raw' => '', 'error' => 'not-installed'];
    $domain = strtolower(trim($domain));
    if (!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain)) {
        return ['source' => 'amass', 'subs' => [], 'raw' => '', 'error' => 'Invalid domain.'];
    }
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open('"' . $bin . '" enum -passive -norecursive -timeout ' . (int)min(max($timeoutSec, 30), 600) . ' -d ' . escapeshellarg($domain), $descriptors, $pipes);
    if (!is_resource($proc)) return ['source' => 'amass', 'subs' => [], 'raw' => '', 'error' => 'Could not start amass.'];
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    $out = '';
    $start = time();
    $limit = (int)min(max($timeoutSec, 30), 600) + 10;
    while (time() - $start < $limit) {
        $st = proc_get_status($proc);
        $chunk = stream_get_contents($pipes[1]);
        if ($chunk !== false) $out .= $chunk;
        if (!$st['running']) break;
        usleep(200000);
    }
    @proc_terminate($proc);
    foreach ($pipes as $p) { if (is_resource($p)) fclose($p); }
    @proc_close($proc);
    $subs = [];
    foreach (preg_split('/\R/', $out) as $line) {
        $line = strtolower(trim($line));
        if (preg_match('/^[a-z0-9_.-]+\.' . preg_quote($domain, '/') . '$/', $line)) $subs[] = $line;
    }
    $subs = array_values(array_unique($subs));
    sort($subs);
    return ['source' => 'amass', 'subs' => $subs, 'raw' => mb_substr($out, 0, 4000), 'error' => null];
}

/** Passive enum via crt.sh certificate transparency (no binary needed). */
function amassEnumCrtsh($domain, $timeoutSec = 20) {
    $domain = strtolower(trim($domain));
    if (!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain)) {
        return ['source' => 'crt.sh', 'subs' => [], 'raw' => '', 'error' => 'Invalid domain.'];
    }
    $ctx = stream_context_create(['http' => ['timeout' => $timeoutSec, 'header' => 'User-Agent: SCITBD-OSINT/1.0']]);
    $json = @file_get_contents('https://crt.sh/?q=%25.' . urlencode($domain) . '&output=json', false, $ctx);
    if ($json === false) return ['source' => 'crt.sh', 'subs' => [], 'raw' => '', 'error' => 'crt.sh unreachable.'];
    $rows = json_decode($json, true);
    if (!is_array($rows)) return ['source' => 'crt.sh', 'subs' => [], 'raw' => '', 'error' => 'Bad crt.sh response.'];
    $subs = [];
    foreach ($rows as $r) {
        foreach (preg_split('/\R/', $r['name_value'] ?? '') as $n) {
            $n = strtolower(trim($n, " \t*."));
            // Strict hostname check — crt.sh rows occasionally carry free text.
            if ($n === '' || substr($n, -strlen($domain)) !== $domain) continue;
            if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $n)) continue;
            $subs[] = $n;
        }
    }
    $subs = array_values(array_unique($subs));
    sort($subs);
    return ['source' => 'crt.sh', 'subs' => $subs, 'raw' => '', 'error' => null];
}

/** Best available enum: Amass binary first, crt.sh fallback. */
function amassEnum($domain, $prefer = 'auto') {
    if ($prefer === 'amass' || ($prefer === 'auto' && amassBinary())) {
        $r = amassEnumBinary($domain);
        if ($r['error'] !== 'not-installed') return $r;
    }
    return amassEnumCrtsh($domain);
}

function amassEnsureTables($db) {
    $db->exec("CREATE TABLE IF NOT EXISTS subdomain_enums (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        domain TEXT NOT NULL,
        source TEXT DEFAULT 'crt.sh',
        sub_count INTEGER DEFAULT 0,
        data_json TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
}

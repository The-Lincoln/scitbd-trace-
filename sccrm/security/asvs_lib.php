<?php
/**
 * ASVS library — browses the cloned OWASP ASVS standard (external/asvs)
 * and maps URL-tracer security findings to ASVS 5.0 requirement IDs.
 */

function asvsRoot() {
    return dirname(__DIR__, 2) . '/external/asvs';
}

function asvsVersion() {
    $root = asvsRoot();
    foreach (['5.0', '4.0'] as $v) {
        if (is_dir("$root/$v/en")) return $v;
    }
    return null;
}

function asvsLangDir() {
    $v = asvsVersion();
    return $v ? (asvsRoot() . "/$v/en") : null;
}

/** Chapters: [{file, code, title}] e.g. code=V3, title="Web Frontend Security". */
function asvsChapters() {
    $dir = asvsLangDir();
    if (!$dir) return [];
    $out = [];
    foreach (glob("$dir/0x*-V*.md") ?: [] as $f) {
        $base = basename($f, '.md');
        if (preg_match('/^0x[0-9]+-(V[0-9]+)-(.+)$/', $base, $m)) {
            $out[] = ['file' => $base . '.md', 'code' => $m[1], 'title' => str_replace('-', ' ', $m[2])];
        }
    }
    return $out;
}

/** Parse `| **ID** | text | level |` rows from a chapter file. */
function asvsRequirements($file) {
    $dir = asvsLangDir();
    $path = realpath($dir . '/' . basename($file));
    if (!$path || strpos($path, realpath($dir)) !== 0 || !is_file($path)) return [];
    $rows = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^\|\s*\*\*([0-9]+\.[0-9]+\.[0-9]+)\*\*\s*\|\s*(.+?)\s*\|\s*([123])\s*\|?\s*$/', $line, $m)) {
            $rows[] = ['id' => $m[1], 'text' => $m[2], 'level' => (int)$m[3]];
        }
    }
    return $rows;
}

/** Full-text search across chapters. Returns [{file, code, title, hits: [req rows or lines]}]. */
function asvsSearch($q, $limit = 40) {
    $q = trim($q);
    if ($q === '') return [];
    $out = [];
    foreach (asvsChapters() as $ch) {
        foreach (asvsRequirements($ch['file']) as $r) {
            if (stripos($r['id'] . ' ' . $r['text'], $q) !== false) {
                $out[] = ['code' => $ch['code'], 'title' => $ch['title'], 'file' => $ch['file']] + $r;
                if (count($out) >= $limit) return $out;
            }
        }
    }
    return $out;
}

/** Minimal safe Markdown → HTML (headings, tables, bold, code, lists). */
function asvsMarkdown($md) {
    $lines = preg_split('/\R/', (string)$md);
    $html = '';
    $inTable = false;
    $inList = false;
    foreach ($lines as $line) {
        if (preg_match('/^\|.*\|$/', trim($line))) {
            if (!$inTable) { $html .= '<table class="table table-sm table-bordered" style="font-size:12px;"><tbody>'; $inTable = true; }
            $cells = array_map('trim', explode('|', trim($line, '| ')));
            if (preg_grep('/^-+$/', $cells) === $cells || (count($cells) && preg_match('/^:?-{3,}:?$/', $cells[0]))) { continue; }
            $html .= '<tr>';
            foreach ($cells as $c) $html .= '<td>' . asvsInline($c) . '</td>';
            $html .= '</tr>';
            continue;
        }
        if ($inTable) { $html .= '</tbody></table>'; $inTable = false; }
        if (preg_match('/^#{1,4}\s+(.+)/', $line, $m)) {
            if ($inList) { $html .= '</ul>'; $inList = false; }
            $html .= '<h6 class="mt-3">' . asvsInline($m[1]) . '</h6>';
        } elseif (preg_match('/^[-*]\s+(.+)/', $line, $m)) {
            if (!$inList) { $html .= '<ul style="font-size:13px;">'; $inList = true; }
            $html .= '<li>' . asvsInline($m[1]) . '</li>';
        } elseif (trim($line) === '') {
            if ($inList) { $html .= '</ul>'; $inList = false; }
        } else {
            if ($inList) { $html .= '</ul>'; $inList = false; }
            $html .= '<p style="font-size:13px;">' . asvsInline($line) . '</p>';
        }
    }
    if ($inTable) $html .= '</tbody></table>';
    if ($inList) $html .= '</ul>';
    return $html;
}

function asvsInline($s) {
    $s = htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $s = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $s);
    $s = preg_replace('/`(.+?)`/', '<code>$1</code>', $s);
    return $s;
}

/**
 * Map of tracer security checks → ASVS 5.0 controls.
 * key: [label, asvs_id, level, how-to-read from $trace]
 */
function asvsTracerMap() {
    return [
        'hsts' => ['Strict-Transport-Security', '3.4.1', 1],
        'csp' => ['Content-Security-Policy', '3.4.3', 2],
        'frame' => ['Clickjacking defence (frame-ancestors / X-Frame-Options)', '3.4.6', 2],
        'referrer' => ['Referrer-Policy', '3.4.5', 2],
        'nosniff' => ['X-Content-Type-Options: nosniff', '3.2.1', 1],
        'cookie_secure' => ['Cookie Secure flag', '3.3.1', 1],
        'cookie_samesite' => ['Cookie SameSite', '3.3.2', 2],
        'cookie_httponly' => ['Cookie HttpOnly', '3.3.4', 2],
        'tls' => ['Valid public TLS certificate (HTTPS)', '12.2.2', 1],
        'https_only' => ['HTTPS-only external service', '12.2.1', 1],
    ];
}

/** Evaluate a URLTracer $traceResult against the ASVS map. */
function asvsEvaluateTrace($trace) {
    $map = asvsTracerMap();
    $secHeaders = $trace['security']['headers'] ?? [];
    $rawHeaders = $trace['headers']['headers'] ?? [];
    $isHttps = stripos($trace['basic']['url'] ?? '', 'https://') === 0;
    $present = function ($name) use ($secHeaders, $rawHeaders) {
        if (isset($secHeaders[$name])) return !empty($secHeaders[$name]['present']);
        foreach ($rawHeaders as $k => $v) { if (strcasecmp($k, $name) === 0 && trim((string)$v) !== '') return true; }
        return false;
    };
    $findings = [];
    $add = function ($key, $pass, $evidence) use (&$findings, $map) {
        $findings[] = ['key' => $key, 'label' => $map[$key][0], 'asvs' => $map[$key][1], 'level' => $map[$key][2],
            'status' => $pass ? 'pass' : 'fail', 'evidence' => $evidence];
    };
    $add('hsts', $present('Strict-Transport-Security'), $rawHeaders['Strict-Transport-Security'] ?? ($secHeaders['Strict-Transport-Security']['value'] ?? 'missing'));
    $cspVal = $rawHeaders['Content-Security-Policy'] ?? ($secHeaders['Content-Security-Policy']['value'] ?? '');
    $add('csp', $present('Content-Security-Policy'), $cspVal !== '' ? mb_substr($cspVal, 0, 120) : 'missing');
    $frameOk = $present('X-Frame-Options') || stripos((string)$cspVal, 'frame-ancestors') !== false;
    $add('frame', $frameOk, $rawHeaders['X-Frame-Options'] ?? (stripos((string)$cspVal, 'frame-ancestors') !== false ? 'via CSP frame-ancestors' : 'missing'));
    $add('referrer', $present('Referrer-Policy'), $rawHeaders['Referrer-Policy'] ?? 'missing');
    $ct = $rawHeaders['X-Content-Type-Options'] ?? '';
    $add('nosniff', strcasecmp(trim($ct), 'nosniff') === 0, $ct !== '' ? $ct : 'missing');
    // Cookies (best-effort parse of possibly-joined Set-Cookie)
    $cookieRaw = '';
    foreach ($rawHeaders as $k => $v) { if (strcasecmp($k, 'Set-Cookie') === 0) $cookieRaw .= ' ' . $v; }
    if (trim($cookieRaw) === '') {
        $add('cookie_secure', false, 'no Set-Cookie observed');
        $add('cookie_samesite', false, 'no Set-Cookie observed');
        $add('cookie_httponly', false, 'no Set-Cookie observed');
    } else {
        $add('cookie_secure', stripos($cookieRaw, 'secure') !== false, mb_substr(trim($cookieRaw), 0, 100));
        $add('cookie_samesite', stripos($cookieRaw, 'samesite') !== false, stripos($cookieRaw, 'samesite') !== false ? 'present' : 'missing');
        $add('cookie_httponly', stripos($cookieRaw, 'httponly') !== false, stripos($cookieRaw, 'httponly') !== false ? 'present' : 'missing');
    }
    $cert = $trace['ssl']['certificate'] ?? [];
    $add('tls', !empty($cert['is_valid']), isset($cert['valid_to']) ? ('valid to ' . $cert['valid_to']) : ($isHttps ? 'https, cert unread' : 'not https'));
    $add('https_only', $isHttps, $trace['basic']['url'] ?? '');
    return $findings;
}

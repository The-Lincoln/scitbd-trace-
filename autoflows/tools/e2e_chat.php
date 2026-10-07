<?php
/**
 * Manual offline E2E: chat `/social <topic>` -> streamed agent events ->
 * rows in the content library -> renders in the editor.
 * Run against an already-started server: php tools/e2e_chat.php <port>
 */
declare(strict_types=1);

$port = (int) ($argv[1] ?? 8024);
$base = "http://127.0.0.1:{$port}";
$cookie = tempnam(sys_get_temp_dir(), 'afck');
$pass = 0; $fail = 0;

function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo '  ' . ($ok ? '✓' : '✗') . ' ' . $label . ($detail !== '' ? " — {$detail}" : '') . PHP_EOL;
}

function req(string $url, array $opts = []): array
{
    global $cookie;
    $ctx = stream_context_create(['http' => [
        'method'         => $opts['method'] ?? 'GET',
        'header'         => $opts['headers'] ?? '',
        'content'        => $opts['body'] ?? '',
        'timeout'        => $opts['timeout'] ?? 60,
        'ignore_errors'  => true,
        'follow_location'=> 0,
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    $status = 0; $sc = [];
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#HTTP/\S+\s+(\d{3})#', $h, $m)) { $status = (int) $m[1]; $sc[] = $m[1]; }
        if (stripos($h, 'set-cookie:') === 0) {
            $c = trim(substr($h, 11));
            file_put_contents($cookie, explode(';', $c)[0] . "\n", FILE_APPEND);
        }
    }
    return ['status' => $status, 'body' => $body === false ? null : $body];
}

$jar = function (): string {
    global $cookie;
    $lines = is_file($cookie) ? array_filter(array_map('trim', file($cookie))) : [];
    return $lines ? 'Cookie: ' . implode('; ', array_unique($lines)) . "\r\n" : '';
};

echo "E2E — chat slash command through to the editor (port {$port})\n\n";

// 1. boot + CSRF -----------------------------------------------------------
$home = req($base . '/index.php?r=chat');
chk('chat page loads', $home['status'] === 200 && $home['body'] !== null, 'HTTP ' . $home['status']);

$csrf = '';
if (preg_match('/<meta name="csrf" content="([^"]+)"/', (string) $home['body'], $m)) {
    $csrf = $m[1];
}
chk('csrf meta present', $csrf !== '', substr($csrf, 0, 12) . '…');

// baseline row count
$statsBefore = json_decode((string) req($base . '/index.php?r=api/stats', [
    'headers' => "Accept: application/json\r\n" . $jar(),
])['body'], true);
$before = (int) ($statsBefore['content']['total'] ?? 0);
echo "  · content rows before: {$before}\n";

// 2. send /social over SSE -------------------------------------------------
$payload = json_encode([
    'conversation_id' => 0,
    'content'         => '/social why small teams should publish weekly',
    'model'           => '',
    'temperature'     => 0.75,
    'num_predict'     => 768,
    'system'          => '',
], JSON_UNESCAPED_SLASHES);

$send = req($base . '/index.php?r=api/chat/send', [
    'method'  => 'POST',
    'headers' => "Accept: text/event-stream\r\nContent-Type: application/json\r\n"
               . "X-Requested-With: XMLHttpRequest\r\nX-CSRF-Token: {$csrf}\r\n" . $jar(),
    'body'    => $payload,
    'timeout' => 90,
]);

chk('send returns 200', $send['status'] === 200, 'HTTP ' . $send['status']);
chk('response is a stream', str_contains((string) $send['body'], 'data:'), strlen((string) $send['body']) . ' bytes');

// parse SSE frames
$events = [];
foreach (explode("\n\n", (string) $send['body']) as $frame) {
    foreach (explode("\n", $frame) as $line) {
        if (strpos($line, 'data:') !== 0) continue;
        $j = json_decode(trim(substr($line, 5)), true);
        if (is_array($j) && isset($j['type'])) $events[] = $j;
    }
}
$types = array_column($events, 'type');
chk('command event fired', in_array('command', $types, true),
    json_encode(array_values(array_intersect(['command','run_start','step','step_done','meta','done'], $types))));

$meta = null;
foreach ($events as $e) { if ($e['type'] === 'meta') $meta = $e; }
chk('meta payload present', $meta !== null);

$done = end($events);
chk('stream terminates with done', is_array($done) && $done['type'] === 'done');

$gen = $meta['generated'] ?? [];
chk('generated items reported', is_array($gen) && count($gen) >= 1, count($gen) . ' id(s)');
chk('reply text is non-empty', isset($meta['text']) && trim((string) $meta['text']) !== '',
    substr((string) ($meta['text'] ?? ''), 0, 50) . '…');
chk('run_id returned', isset($meta['run_id']) && $meta['run_id'] > 0, '#' . ($meta['run_id'] ?? 0));

// 3. rows landed in the library -------------------------------------------
$statsAfter = json_decode((string) req($base . '/index.php?r=api/stats', [
    'headers' => "Accept: application/json\r\n" . $jar(),
])['body'], true);
$after = (int) ($statsAfter['content']['total'] ?? 0);
chk('content rows increased', $after > $before, "{$before} → {$after}");

if ($gen) {
    $id = (int) $gen[0];
    $edit = req($base . "/index.php?r=item&id={$id}");
    chk('editor renders the new item', $edit['status'] === 200
        && !str_contains((string) $edit['body'], 'Fatal error')
        && !str_contains((string) $edit['body'], 'View not found'),
        "item #{$id}, " . strlen((string) $edit['body']) . ' bytes');

    $hasWindow = str_contains((string) $edit['body'], 'window.__ITEM__');
    chk('editor exposes window.__ITEM__', $hasWindow);

    $hasBody = preg_match('/"body"\s*:\s*"[^"]{20,}"/', (string) $edit['body']) === 1;
    chk('editor carries a real body', $hasBody);
}

// 4. thread persisted ------------------------------------------------------
$convs = json_decode((string) req($base . '/index.php?r=api/chat/conversations', [
    'headers' => "Accept: application/json\r\n" . $jar(),
])['body'], true);
$n = count($convs['items'] ?? []);
chk('conversation persisted', $n >= 1, $n . ' thread(s)');

@unlink($cookie);
echo "\n" . str_repeat('─', 54) . "\n  pass: {$pass}   fail: {$fail}\n" . str_repeat('─', 54) . "\n";
echo $fail === 0 ? "  ✅ E2E GREEN\n" : "  ❌ {$fail} FAILURE(S)\n";
exit($fail === 0 ? 0 : 1);

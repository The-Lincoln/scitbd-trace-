<?php
/**
 * TinyLLM client.
 *
 * Primary provider is a local Ollama server (`/api/chat`, NDJSON streaming).
 * Every network failure degrades to a deterministic template engine so both
 * the chat and the generator never dead-end — offline, no key, no waiting.
 */
declare(strict_types=1);

final class TinyLLM
{
    public static function provider(): string
    {
        $p = (string) config('chat.provider', 'ollama');
        return $p === 'local' ? 'local' : 'ollama';
    }

    public static function endpoint(): string
    {
        return rtrim((string) config('chat.providers.ollama.endpoint'), '/');
    }

    public static function model(): string
    {
        return (string) config('chat.providers.ollama.model', 'tinyllama');
    }

    public static function isLocal(): bool
    {
        return self::provider() === 'local';
    }

    // ------------------------------------------------------------ status ---

    /** @return array{ok:bool,provider:string,model:string,label:string,latency_ms:int,error:?string,models:array} */
    public static function status(int $timeout = 3): array
    {
        $provider = self::provider();
        $out = [
            'ok'         => false,
            'provider'   => $provider,
            'model'      => self::model(),
            'label'      => (string) config('chat.providers.' . $provider . '.label', $provider),
            'latency_ms' => 0,
            'error'      => null,
            'models'     => [],
        ];

        if ($provider === 'local') {
            $out['ok'] = true;
            return $out;
        }

        $t0  = microtime(true);
        $res = self::request('/api/version', null, $timeout);
        $out['latency_ms'] = (int) round((microtime(true) - $t0) * 1000);

        if ($res['code'] !== 0 && $res['code'] !== 200) {
            $out['error'] = $res['error'] !== '' ? $res['error'] : 'Ollama is not reachable at ' . self::endpoint();
            return $out;
        }

        $out['ok'] = true;
        $tags = self::request('/api/tags', null, $timeout);
        if ($tags['code'] === 200) {
            $data = json_decode($tags['body'], true);
            foreach (($data['models'] ?? []) as $m) {
                $out['models'][] = [
                    'name'  => (string) $m['name'],
                    'bytes' => (int) ($m['size'] ?? 0),
                ];
            }
        }
        return $out;
    }

    /** Model list for the settings dropdown (always includes TinyLLM). */
    public static function models(): array
    {
        $out = [];
        foreach ((array) config('chat.providers.ollama.models', []) as $name => $desc) {
            $out[] = ['name' => (string) $name, 'desc' => (string) $desc];
        }
        foreach (self::status(2)['models'] as $m) {
            $found = false;
            foreach ($out as $i => $row) {
                if ($row['name'] === $m['name']) {
                    $out[$i]['desc'] .= ' · installed (' . human_size($m['bytes']) . ')';
                    $found = true;
                }
            }
            if (!$found) {
                $out[] = ['name' => $m['name'], 'desc' => 'installed (' . human_size($m['bytes']) . ')'];
            }
        }
        return $out;
    }

    // -------------------------------------------------------------- chat ---

    /**
     * Blocking completion.
     *
     * @param array $messages [['role'=>..,'content'=>..], …]
     * @return array{content:string,model:string,provider:string,tokens:int,ms:int,error:?string,fallback:bool}
     */
    public static function chat(array $messages, array $opts = []): array
    {
        $t0     = microtime(true);
        $model  = (string) ($opts['model'] ?? self::model());
        $system = trim((string) ($opts['system'] ?? config('chat.defaults.system', '')));

        if (self::isLocal()) {
            return self::fallback($messages, $t0, 'local');
        }

        $payload = self::payload($messages, $opts, $model, $system, false);
        $res     = self::request('/api/chat', $payload, (int) config('chat.providers.ollama.timeout', 180));

        if ($res['code'] !== 200) {
            return self::fallback($messages, $t0, $res['error'] !== '' ? $res['error'] : 'HTTP ' . $res['code']);
        }

        $data = json_decode($res['body'], true);
        if (!is_array($data) || !isset($data['message']['content'])) {
            return self::fallback($messages, $t0, 'Malformed response from Ollama');
        }

        return [
            'content'  => trim((string) $data['message']['content']),
            'model'    => (string) ($data['model'] ?? $model),
            'provider' => 'ollama',
            'tokens'   => (int) ($data['eval_count'] ?? 0),
            'ms'       => (int) round((microtime(true) - $t0) * 1000),
            'error'    => null,
            'fallback' => false,
        ];
    }

    /**
     * Token-by-token completion. $onChunk receives each text delta.
     *
     * @param callable $onChunk fn(string $delta): void
     * @return array{content:string,model:string,provider:string,tokens:int,ms:int,error:?string,fallback:bool}
     */
    public static function stream(array $messages, array $opts, callable $onChunk): array
    {
        $t0     = microtime(true);
        $model  = (string) ($opts['model'] ?? self::model());
        $system = trim((string) ($opts['system'] ?? config('chat.defaults.system', '')));

        if (self::isLocal()) {
            $r = self::fallback($messages, $t0, 'local');
            self::emitChunks($r['content'], $onChunk);
            return $r;
        }

        $payload = self::payload($messages, $opts, $model, $system, true);
        $full    = '';
        $tokens  = 0;

        $ch = curl_init(self::endpoint() . '/api/chat');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT        => (int) config('chat.providers.ollama.timeout', 180),
            CURLOPT_NOSIGNAL       => true,
            CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$full, &$tokens, $onChunk) {
                static $buf = '';
                $buf .= $chunk;
                while (($pos = strpos($buf, "\n")) !== false) {
                    $line = trim(substr($buf, 0, $pos));
                    $buf  = substr($buf, $pos + 1);
                    if ($line === '') {
                        continue;
                    }
                    $j = json_decode($line, true);
                    if (!is_array($j)) {
                        continue;
                    }
                    $delta = (string) ($j['message']['content'] ?? '');
                    if ($delta !== '') {
                        $full .= $delta;
                        $onChunk($delta);
                    }
                    if (isset($j['done']) && $j['done'] === true) {
                        $tokens = (int) ($j['eval_count'] ?? $tokens);
                    }
                }
                return strlen($chunk);
            },
        ]);

        $ok   = curl_exec($ch);
        $cErr = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($ok === false || $code !== 200) {
            $reason = $ok === false
                ? ($cErr !== '' ? $cErr : 'connection failed')
                : 'HTTP ' . $code;
            $r = self::fallback($messages, $t0, $reason);
            self::emitChunks($r['content'], $onChunk);
            return $r;
        }

        if (trim($full) === '') {
            $r = self::fallback($messages, $t0, 'Empty response');
            self::emitChunks($r['content'], $onChunk);
            return $r;
        }

        return [
            'content'  => $full,
            'model'    => $model,
            'provider' => 'ollama',
            'tokens'   => $tokens,
            'ms'       => (int) round((microtime(true) - $t0) * 1000),
            'error'    => null,
            'fallback' => false,
        ];
    }

    // ------------------------------------------------------------ fallback ---

    private static function fallback(array $messages, float $t0, ?string $reason): array
    {
        return [
            'content'  => self::localReply($messages),
            'model'    => (string) config('chat.providers.local.model', 'template-engine-v1'),
            'provider' => 'local',
            'tokens'   => 0,
            'ms'       => (int) round((microtime(true) - $t0) * 1000),
            'error'    => $reason,
            'fallback' => true,
        ];
    }

    /** Stream text to a callback in word-sized chunks. */
    private static function emitChunks(string $text, callable $onChunk): void
    {
        foreach (preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $piece) {
            if ($piece !== '') {
                $onChunk($piece);
                usleep(5000);
            }
        }
    }

    private static function payload(array $messages, array $opts, string $model, string $system, bool $stream): array
    {
        $msgs = [];
        if ($system !== '') {
            $msgs[] = ['role' => 'system', 'content' => $system];
        }
        foreach ($messages as $m) {
            $role = (string) ($m['role'] ?? 'user');
            $text = (string) ($m['content'] ?? '');
            if ($text === '' || $role === 'system') {
                continue;
            }
            $msgs[] = ['role' => $role, 'content' => $text];
        }

        return [
            'model'    => $model,
            'messages' => $msgs,
            'stream'   => $stream,
            'options'  => [
                'temperature'    => (float) ($opts['temperature'] ?? config('chat.defaults.temperature')),
                'num_predict'    => (int)   ($opts['num_predict'] ?? config('chat.defaults.num_predict')),
                'top_p'          => (float) config('chat.defaults.top_p'),
                'top_k'          => (int)   config('chat.defaults.top_k'),
                'repeat_penalty' => (float) config('chat.defaults.repeat_pen'),
                'num_ctx'        => (int)   config('chat.providers.ollama.num_ctx'),
                'num_thread'     => 4,
            ],
        ];
    }

    /**
     * Raw HTTP helper.
     *
     * @return array{code:int,body:string,error:string}
     */
    private static function request(string $path, ?array $payload, int $timeout = 10): array
    {
        $ch   = curl_init(self::endpoint() . $path);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => max(2, min($timeout, 6)),
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_NOSIGNAL       => true,
        ];
        if ($payload !== null) {
            $opts[CURLOPT_POST]       = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE);
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($body === false) {
            return ['code' => 0, 'body' => '', 'error' => $err !== '' ? $err : 'connection failed'];
        }
        $j = json_decode((string) $body, true);
        if (is_array($j) && isset($j['error'])) {
            return ['code' => $code, 'body' => (string) $body, 'error' => (string) $j['error']];
        }
        return ['code' => $code, 'body' => (string) $body, 'error' => ''];
    }

    // -------------------------------------------------------- rule engine ---

    /**
     * Offline assistant. Deliberately useful rather than a stub: it answers the
     * kinds of questions a content-automation studio actually receives, and
     * exposes the same slash commands the online model understands.
     */
    public static function localReply(array $messages): string
    {
        $last = '';
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['role'] ?? '') === 'user') {
                $last = trim((string) $messages[$i]['content']);
                break;
            }
        }
        if ($last === '' && $messages !== []) {
            $last = trim((string) ($messages[array_key_last($messages)]['content'] ?? ''));
        }

        $q   = mb_strtolower($last);
        $has = fn (string ...$need): bool => (bool) array_filter($need, fn ($k) => str_contains($q, $k));

        if ($has('hello', 'hi ', 'hey', 'good morning', 'good evening') && mb_strlen($q) < 40) {
            return "Hey — I'm the **built-in template engine**.\n\n"
                 . "TinyLLM isn't reachable right now, but I can still help:\n"
                 . "- `/social <topic>` — draft platform posts\n"
                 . "- `/blog <topic>` — outline + article\n"
                 . "- `/email <topic>` — subject, body, CTA\n"
                 . "- `/daily <topic>` — run the full daily pack\n"
                 . "- `flows`, `content`, `agent` — how AutoFlows works";
        }

        if (str_starts_with($q, '/') || $has('slash command', 'command list')) {
            return "**Commands**\n\n"
                 . "| Command | What it does |\n|---|---|\n"
                 . "| `/help` | this table |\n"
                 . "| `/social <topic>` | one post per connected platform |\n"
                 . "| `/blog <topic>` | title, slug, excerpt, article, tags |\n"
                 . "| `/email <topic>` | subject, preheader, body, CTA |\n"
                 . "| `/daily <topic>` | the whole daily pack via FlowAgent |\n"
                 . "| `/status` | model + database status |\n\n"
                 . "Everything you generate lands in **Content** as a draft you can edit, "
                 . "approve and schedule.";
        }

        if ($has('flow', 'autoflow', 'schedule', 'daily', 'cron', 'every day')) {
            return "**AutoFlows** are standing recipes.\n\n"
                 . "1. Open **Flows → New flow**.\n"
                 . "2. Pick a channel: `social`, `blog`, `email` or `pack` (all three).\n"
                 . "3. Write the standing brief, choose tone, audience and how many items.\n"
                 . "4. Set a schedule — `daily`, `weekly` or `manual` — and a run time.\n"
                 . "5. Press **Run now**, or let `php tools/run_daily.php` pick it up.\n\n"
                 . "Each run writes a trace you can replay on the **Agent** page.";
        }

        if ($has('agent', 'flowagent', 'multi-step', 'plan')) {
            return "**FlowAgent** turns one goal into a six-step run:\n\n"
                 . "1. `brief` — pull topic, audience, tone and angle out of the goal\n"
                 . "2. `outline` — angles, hooks and the article skeleton\n"
                 . "3. `social` — platform-tuned posts\n"
                 . "4. `blog` — title, slug, excerpt, body, tags\n"
                 . "5. `email` — subject, preheader, body, single CTA\n"
                 . "6. `review` — score out of 100 plus three concrete fixes\n\n"
                 . "Open the **Agent** page, type a goal and watch the steps stream live.";
        }

        if ($has('social', 'twitter', 'linkedin', 'instagram', 'hashtag', 'post idea')) {
            return "**Social post formula**\n\n"
                 . "> Hook in line one — a claim, number or sharp opinion.\n"
                 . "> One idea only. Short lines. A pause before the CTA.\n"
                 . "> 3–5 hashtags, niche not broad.\n\n"
                 . "Type `/social <topic>` and I'll write one per connected platform, "
                 . "each inside its own character limit.";
        }

        if ($has('blog', 'article', 'seo', 'outline', 'long form')) {
            return "**Blog structure I use**\n\n"
                 . "1. Title — under 60 characters, benefit-led\n"
                 . "2. Excerpt — 140–160 characters for the SERP snippet\n"
                 . "3. H2 sections answering one question each\n"
                 . "4. Short paragraphs, one example per section\n"
                 . "5. A closing CTA back to the product\n\n"
                 . "`/blog <topic>` generates all of it, plus a slug and tags.";
        }

        if ($has('email', 'subject line', 'newsletter', 'campaign', 'cta', 'open rate')) {
            return "**Email recipe**\n\n"
                 . "- **Subject** — ≤ 45 characters, specific, no spam words\n"
                 . "- **Preheader** — 60–90 characters that complement, not repeat, the subject\n"
                 . "- **Body** — one idea, short paragraphs, one CTA repeated at most twice\n"
                 . "- **CTA** — a verb: *Start your free week*, not *Learn more*\n\n"
                 . "`/email <topic>` writes the whole thing.";
        }

        if ($has('who are you', 'what are you', 'your name', 'tinyllama', 'tiny llm')) {
            return "I'm **AutoFlows**, normally served by **TinyLLM** through a local Ollama "
                 . "endpoint — no data leaves this machine.\n\n"
                 . "Right now the model isn't answering, so this reply came from the built-in "
                 . "template engine. Start it with `ollama run tinyllama` and I'll switch back "
                 . "automatically.";
        }

        if ($has('help', 'how do i', 'how to', '?')) {
            return "**Quick start**\n\n"
                 . "1. **Flows → New flow** — describe what to generate every day.\n"
                 . "2. **Run now** (or `/daily <topic>`) — watch FlowAgent work.\n"
                 . "3. **Content** — edit, approve, schedule, publish.\n"
                 . "4. **Chat** — brainstorm with the model, `/`-commands included.\n"
                 . "5. **Settings** — point at your Ollama host and set the brand voice.\n\n"
                 . "Everything lives in one SQLite file: `storage/app.db`.";
        }

        // Keyword echo — keeps the conversation going while offline.
        $words = array_values(array_filter(preg_split('/\W+/u', $last) ?: [], fn ($w) => mb_strlen($w) > 3));
        $topic = $words === [] ? 'that' : '**' . implode('**, **', array_slice($words, 0, 4)) . '**';

        return "I can't reach the local TinyLLM model right now, so here's the offline answer "
             . "about {$topic}.\n\n"
             . "**What I'd do with it:**\n"
             . "- `/social {$topic}` for a platform-tuned post\n"
             . "- `/blog {$topic}` for a full article\n"
             . "- `/email {$topic}` for a campaign email\n\n"
             . "> Run `ollama serve` and reload — I'll switch back to the full LLM automatically.";
    }
}

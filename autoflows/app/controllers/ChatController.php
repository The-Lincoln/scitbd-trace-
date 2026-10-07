<?php
/**
 * TinyLLM chat — conversation CRUD, SSE streaming, and slash commands that
 * fire real AutoFlows generations so chat and the content library stay in
 * sync.
 */
declare(strict_types=1);

final class ChatController extends Controller
{
    private const COMMANDS = ['help', 'social', 'blog', 'email', 'daily', 'status', 'slack', 'agent'];

    // ------------------------------------------------------------- pages ---

    public function index(): void
    {
        $convs = Conversation::all();

        $convId  = $this->int('c', 0);
        $current = $convId > 0 ? Conversation::find($convId) : null;
        if ($current === null && $convs !== []) {
            $convId  = (int) $convs[0]['id'];
            $current = Conversation::find($convId);
        }
        if ($current !== null) {
            Conversation::touch($convId);
            $convs = Conversation::all();
        }

        $this->view->render('chat/index', [
            'title'         => 'Chat',
            'conversations' => $convs,
            'current'       => $current,
            'messages'      => $convId > 0 ? Message::forConversation($convId) : [],
            'llm'           => TinyLLM::status(3),
            'models'        => TinyLLM::models(),
            'defaults'      => config('chat.defaults'),
            'flows'         => Flow::all(),
            'recentContent' => Content::decorateAll(Content::recent(5)),
            'flashes'       => take_flash(),
        ]);
    }

    // --------------------------------------------------------- endpoints ---

    public function conversations(): void
    {
        json_response(['ok' => true, 'items' => Conversation::all()]);
    }

    public function create(): void
    {
        $this->requireCsrf();
        $body   = $this->jsonBody();
        $title  = (string) ($body['title'] ?? 'New chat') ?: 'New chat';
        $system = (string) ($body['system'] ?? '') ?: null;
        $id     = Conversation::create(excerpt($title, 60), null, $system);
        Database::log('chat.create', "#{$id} {$title}");
        json_response(['ok' => true, 'id' => $id, 'item' => Conversation::find($id)]);
    }

    public function rename(): void
    {
        $this->requireCsrf();
        $body  = $this->jsonBody();
        $id    = (int) ($body['id'] ?? 0);
        $title = trim((string) ($body['title'] ?? ''));
        if (Conversation::find($id) === null) {
            json_response(['ok' => false, 'error' => 'Conversation not found'], 404);
        }
        if ($title === '') {
            json_response(['ok' => false, 'error' => 'Title cannot be empty'], 422);
        }
        Conversation::rename($id, $title);
        json_response(['ok' => true, 'id' => $id, 'title' => $title]);
    }

    public function delete(): void
    {
        $this->requireCsrf();
        $body = $this->jsonBody();
        $id   = (int) ($body['id'] ?? 0);
        if (Conversation::find($id) === null) {
            json_response(['ok' => false, 'error' => 'Conversation not found'], 404);
        }
        Conversation::destroy($id);
        Database::log('chat.delete', "#{$id}");
        json_response(['ok' => true]);
    }

    public function messages(): void
    {
        $id = $this->int('id', 0);
        if (Conversation::find($id) === null) {
            json_response(['ok' => false, 'error' => 'Conversation not found'], 404);
        }
        json_response(['ok' => true, 'items' => Message::forConversation($id)]);
    }

    public function status(): void
    {
        json_response(['ok' => true, 'llm' => TinyLLM::status(3), 'models' => TinyLLM::models()]);
    }

    /** Remove one message from a thread. */
    public function deleteMessage(): void
    {
        $this->requireCsrf();
        $body = $this->jsonBody();
        $id   = (int) ($body['id'] ?? 0);
        if (Message::find($id) === null) {
            json_response(['ok' => false, 'error' => 'Message not found'], 404);
        }
        Message::destroy($id);
        Database::log('chat.delete_message', "#{$id}");
        json_response(['ok' => true]);
    }

    /** Drop every message but keep the thread. */
    public function clear(): void
    {
        $this->requireCsrf();
        $body = $this->jsonBody();
        $id   = (int) ($body['id'] ?? 0);
        if (Conversation::find($id) === null) {
            json_response(['ok' => false, 'error' => 'Conversation not found'], 404);
        }
        Conversation::clear($id);
        Database::log('chat.clear', "conv #{$id}");
        json_response(['ok' => true]);
    }

    public function export(): void
    {
        $id   = $this->int('id', 0);
        $conv = Conversation::find($id);
        if ($conv === null) {
            json_response(['ok' => false, 'error' => 'Not found'], 404);
        }
        $out = '# ' . $conv['title'] . "\n\n";
        foreach (Message::forConversation($id) as $m) {
            $out .= '## ' . ucfirst($m['role']) . ' · ' . $m['created_at'] . "\n\n" . $m['content'] . "\n\n---\n\n";
        }
        header('Content-Type: text/markdown; charset=utf-8');
        header('Content-Disposition: attachment; filename="chat_' . $id . '.md"');
        echo $out;
        exit;
    }

    /**
     * SSE streaming completion.
     *
     * Events: start · delta · command · meta · error · done
     */
    public function send(): void
    {
        $this->requireCsrf();
        $body    = $this->jsonBody();
        $content = trim((string) ($body['content'] ?? ''));
        $convId  = (int) ($body['conversation_id'] ?? 0);

        if ($content === '') {
            json_response(['ok' => false, 'error' => 'Message is empty'], 422);
        }

        $system = (string) ($body['system'] ?? '') ?: (string) config('chat.defaults.system', '');
        if ($convId > 0 && Conversation::find($convId) === null) {
            $convId = 0;
        }
        if ($convId === 0) {
            $convId = Conversation::create(excerpt($content, 60), null, $system);
        }

        Sse::open();
        $send = static function (array $p): void { Sse::send($p); };
        $send(['type' => 'start', 'conversation_id' => $convId]);

        Message::create($convId, 'user', $content, null, []);

        $opts = [
            'model'       => (string) ($body['model'] ?? '') ?: TinyLLM::model(),
            'temperature' => clampf((float) ($body['temperature'] ?? config('chat.defaults.temperature')), 0.0, 2.0),
            'num_predict' => clamp((int) ($body['num_predict'] ?? config('chat.defaults.num_predict')), 16, 4096),
            'system'      => $system,
        ];

        // ---- slash commands run the real generators ------------------------
        $cmd = self::parseCommand($content);
        if ($cmd !== null && $cmd['name'] !== 'help') {
            $gen = self::runCommand($cmd, $convId, $send);
            if ($gen !== null) {
                self::finishMessage($convId, $gen['text'], $gen['meta'], $send, $content);
                return;
            }
        }

        // ---- normal chat ---------------------------------------------------
        $history = Message::transcript($convId, 30);
        $buffered = '';

        try {
            $result = TinyLLM::stream($history, $opts, function (string $delta) use ($send, &$buffered): void {
                $buffered .= $delta;
                $send(['type' => 'delta', 't' => $delta]);
            });
        } catch (Throwable $e) {
            $send(['type' => 'error', 'error' => $e->getMessage()]);
            $result = [
                'content'  => TinyLLM::localReply($history),
                'model'    => (string) config('chat.providers.local.model'),
                'provider' => 'local',
                'tokens'   => 0,
                'ms'       => 0,
                'error'    => $e->getMessage(),
                'fallback' => true,
            ];
        }

        $text = trim((string) $result['content']) ?: ($buffered !== '' ? $buffered : '_The model returned nothing. Is Ollama running?_');

        self::finishMessage($convId, $text, [
            'tokens'   => (int) $result['tokens'],
            'ms'       => (int) $result['ms'],
            'provider' => $result['provider'],
            'model'    => $result['model'],
            'fallback' => (bool) $result['fallback'],
            'warning'  => $result['error'] ?? null,
        ], $send, $content);
    }

    /** Persist the assistant turn and close the stream. */
    private static function finishMessage(int $convId, string $text, array $meta, callable $send, string $prompt): void
    {
        $msgId = Message::create($convId, 'assistant', $text, (string) ($meta['model'] ?? null), [
            'tokens'   => (int) ($meta['tokens'] ?? 0),
            'ms'       => (int) ($meta['ms'] ?? 0),
            'provider' => (string) ($meta['provider'] ?? ''),
            'fallback' => (bool) ($meta['fallback'] ?? false),
        ]);

        $conv = Conversation::find($convId);
        if ($conv !== null && in_array($conv['title'], ['New chat', ''], true)) {
            Conversation::rename($convId, excerpt($prompt, 60));
        }

        $send([
            'type'           => 'meta',
            'message_id'     => $msgId,
            'text'           => $text,          // authoritative — replaces the live buffer
            'model'          => (string) ($meta['model'] ?? ''),
            'provider'       => (string) ($meta['provider'] ?? ''),
            'tokens'         => (int) ($meta['tokens'] ?? 0),
            'ms'             => (int) ($meta['ms'] ?? 0),
            'fallback'       => (bool) ($meta['fallback'] ?? false),
            'warning'        => $meta['warning'] ?? null,
            'command'        => $meta['command'] ?? null,
            'generated'      => $meta['generated'] ?? [],
            'run_id'         => $meta['run_id'] ?? null,
            'title'          => (string) (Conversation::find($convId)['title'] ?? ''),
        ]);
        $send(['type' => 'done', 'ok' => true, 'conversation_id' => $convId]);

        Database::log('chat.send', "conv #{$convId} via " . ($meta['provider'] ?? '?') . ' cmd=' . ($meta['command'] ?? '-'));
        exit;
    }

    // ----------------------------------------------------------- commands ---

    /** @return array{name:string,arg:string}|null */
    private static function parseCommand(string $content): ?array
    {
        if (!preg_match('/^\/([a-z]+)\s*(.*)$/is', $content, $m)) {
            return null;
        }
        $name = mb_strtolower($m[1]);
        if (!in_array($name, self::COMMANDS, true)) {
            return null;
        }
        return ['name' => $name, 'arg' => trim($m[2])];
    }

    /**
     * Execute a slash command. Returns null when the command should fall
     * through to the plain chat path (e.g. /help with no model available).
     *
     * @return array{text:string,meta:array}|null
     */
    private static function runCommand(array $cmd, int $convId, callable $send): ?array
    {
        $name = $cmd['name'];
        $arg  = $cmd['arg'];

        if ($name === 'status') {
            $st = TinyLLM::status(3);
            $cs = Content::stats();
            return [
                'text' => "**Status**\n\n"
                    . "| | |\n|---|---|\n"
                    . "| Model | `" . e($st['model']) . "` |\n"
                    . "| Provider | {$st['label']} |\n"
                    . "| Latency | " . ($st['latency_ms'] ? $st['latency_ms'] . ' ms' : '—') . " |\n"
                    . "| Reachable | " . ($st['ok'] ? '✅ yes' : '❌ no — template engine active') . " |\n"
                    . "| Content items | {$cs['total']} ({$cs['draft']} drafts, {$cs['queue']} scheduled) |\n"
                    . "| Flows | " . Flow::stats()['active'] . " active |\n",
                'meta' => ['provider' => $st['provider'], 'model' => $st['model'], 'command' => 'status', 'generated' => []],
            ];
        }

        if ($name === 'help') {
            return [
                'text' => TinyLLM::localReply([['role' => 'user', 'content' => '/help']]),
                'meta' => ['provider' => 'local', 'model' => 'command', 'command' => 'help', 'generated' => []],
            ];
        }

        // SCIT Slack tools (#102): /slack posts straight to workspace T0AGURY3K1D.
        if ($name === 'slack') {
            if ($arg === '') {
                return [
                    'text' => "`/slack` needs a message.\n\nTry `/slack shipping Block 3 US campaign now`.",
                    'meta' => ['command' => 'slack', 'generated' => []],
                ];
            }
            $posted = class_exists('SlackApp') ? SlackApp::postMessage(SlackApp::defaultChannel(), $arg) : ['ok' => false, 'simulated' => true];
            $sim = !empty($posted['simulated']) ? ' (simulated — add xoxb for live)' : '';
            return [
                'text' => "Posted to Slack " . SlackApp::defaultChannel() . "{$sim} — " . mb_substr($arg, 0, 140) . "\n\n" . SlackApp::workspaceUrl(),
                'meta' => ['provider' => 'slack', 'model' => 'slack-tools-v1', 'command' => 'slack', 'generated' => []],
            ];
        }

        if ($arg === '') {
            $send(['type' => 'delta', 't' => "Give me a topic — try `/" . $name . " email subject lines`."]);
            return [
                'text' => "`/{$name}` needs a topic.\n\nTry `/{$name} " . ($name === 'email' ? 're-engaging dormant trial users' : 'writing better hooks') . "`.",
                'meta' => ['command' => $name, 'generated' => []],
            ];
        }

        $channels = match ($name) {
            'social' => ['social'],
            'blog'   => ['blog'],
            'email'  => ['email'],
            'slack'  => ['slack'],
            'agent'  => ['social', 'blog', 'email', 'slack'],
            'daily'  => ['social', 'blog', 'email', 'slack'],
            default  => ['social'],
        };

        $send(['type' => 'command', 'name' => $name, 'channels' => $channels, 'label' => 'Running ' . $name]);

        // The agent emits its own terminal `done`; inside a chat stream that
        // would close the connection before the summary message is written.
        $agentSend = static function (array $p) use ($send): void {
            if (($p['type'] ?? '') === 'done') {
                return;
            }
            $send($p);
        };

        $result = Agent::run([
            'goal'       => $arg,
            'channels'   => $channels,
            'trigger_by' => 'chat',
            'count'      => $name === 'social' ? 3 : 1,
        ], $agentSend);

        // SCIT Slack tools (#102 #103): mirror /agent + /daily runs to Slack.
        if (in_array($name, ['agent', 'daily'], true) && class_exists('SlackApp')) {
            SlackApp::publishFlowResult(SlackApp::defaultChannel(), "Chat /{$name}: {$arg}", $result);
        }

        $items = [];
        foreach ($result['content_ids'] as $id) {
            $c = Content::find($id);
            if ($c === null) {
                continue;
            }
            $icon = (string) config('channels.' . $c['channel'] . '.icon', 'bi-pencil');
            $items[] = "- **[{$c['id']}] {$c['title']}**"
                     . ($c['platform'] ? ' · ' . $c['platform'] : '')
                     . " · {$c['chars']} chars"
                     . " · [open](index.php?r=item&id={$c['id']})";
        }

        $score = $result['score'] > 0 ? "\n\n**Editor score:** {$result['score']}/100" : '';
        $text  = "Done — **" . count($result['content_ids']) . " item(s)** generated as drafts.\n\n"
               . ($items !== [] ? implode("\n", $items) : "_Nothing was produced._")
               . $score
               . "\n\n> [Replay the run trace](index.php?r=agent&run={$result['run_id']}) · "
               . "[open the library](index.php?r=content)";

        return [
            'text' => $text,
            'meta' => [
                'command'   => $name,
                'generated' => $result['content_ids'],
                'run_id'    => $result['run_id'],
                'provider'  => $result['provider'],
                'model'     => $result['provider'] === 'local' ? (string) config('chat.providers.local.model') : TinyLLM::model(),
                'fallback'  => $result['provider'] === 'local',
            ],
        ];
    }
}

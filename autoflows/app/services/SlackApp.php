<?php
/**
 * SlackApp — Slack workspace bridge for AutoFlows.
 *
 * Workspace: T0AGURY3K1D
 *   https://app.slack.com/client/T0AGURY3K1D
 *   AI agent DM: D0BDHAC8RJL (task #95)
 *
 * Covers task #98 (Chat bridge), #99 (FlowAgent trigger), #100 (AutoFlows publish).
 * Plain-cURL, no Composer. Simulated mode when no bot token — mirrors
 * Publisher::recordResult behaviour so autoflows complete end-to-end.
 *
 * CEO tasks: #98 in_progress, #99 pending, #100 pending, #96 Gmail/FB workflow.
 */
declare(strict_types=1);

final class SlackApp
{
    public const WORKSPACE_ID = 'T0AGURY3K1D';
    public const AGENT_DM_ID = 'D0BDHAC8RJL';
    public const WORKSPACE_URL = 'https://app.slack.com/client/T0AGURY3K1D';
    public const AGENT_URL = 'https://app.slack.com/client/T0AGURY3K1D/D0BDHAC8RJL';

    // ------------------------------------------------------------ config ---

    public static function isConfigured(): bool
    {
        $tok = self::botToken();
        return $tok !== '' && $tok !== 'simulated';
    }

    public static function botToken(): string
    {
        $raw = (string) config('slack.bot_token', getenv('SLACK_BOT_TOKEN') ?: '');
        if ($raw === '') {
            return '';
        }
        // Reuse SocialAuth seal format when present.
        if (class_exists('SocialAuth') && (str_starts_with($raw, 'gcm1.') || str_starts_with($raw, 'b64.'))) {
            return SocialAuth::unseal($raw);
        }
        return $raw;
    }

    public static function signingSecret(): string
    {
        return (string) config('slack.signing_secret', getenv('SLACK_SIGNING_SECRET') ?: '');
    }

    /** App-level token (xapp, Socket Mode / Events). Sealed at rest. */
    public static function appToken(): string
    {
        $raw = (string) config('slack.app_token', getenv('SLACK_APP_TOKEN') ?: '');
        if ($raw === '') {
            return '';
        }
        if (class_exists('SocialAuth') && (str_starts_with($raw, 'gcm1.') || str_starts_with($raw, 'b64.'))) {
            return SocialAuth::unseal($raw);
        }
        return $raw;
    }

    public static function isAppConfigured(): bool
    {
        $tok = self::appToken();
        return $tok !== '' && $tok !== 'simulated';
    }

    public static function defaultChannel(): string
    {
        return (string) config('slack.default_channel', getenv('SLACK_DEFAULT_CHANNEL') ?: '#general');
    }

    public static function workspaceUrl(?string $channelId = null): string
    {
        if ($channelId === null || $channelId === '') {
            return self::WORKSPACE_URL;
        }
        return 'https://app.slack.com/client/' . self::WORKSPACE_ID . '/' . $channelId;
    }

    // ------------------------------------------------------------ security ---

    /** Verify X-Slack-Signature per Slack docs. Empty secret = skip (dev). */
    public static function verifySignature(string $body, string $timestamp, string $signature): bool
    {
        $secret = self::signingSecret();
        if ($secret === '') {
            return true; // dev / simulated — accept and log
        }
        if ($timestamp === '' || $signature === '') {
            return false;
        }
        // Reject replays older than 5 min.
        if (abs(time() - (int) $timestamp) > 300) {
            return false;
        }
        $base = 'v0:' . $timestamp . ':' . $body;
        $want = 'v0=' . hash_hmac('sha256', $base, $secret);
        return hash_equals($want, $signature);
    }

    // ------------------------------------------------------------ posting ---

    /**
     * Post text to a Slack channel. Simulated when offline.
     * @param array<string,mixed> $blocks Optional Block Kit blocks
     */
    public static function postMessage(string $channel, string $text, array $blocks = []): array
    {
        $token = self::botToken();
        $at = date('Y-m-d H:i:s');

        if ($token === '' || $token === 'simulated') {
            Database::log('slack.simulated', ($channel ?: '#general') . ' :: ' . mb_substr($text, 0, 120));
            return [
                'ok' => true, 'simulated' => true, 'channel' => $channel,
                'text_chars' => mb_strlen($text),
                'url' => self::workspaceUrl(),
                'note' => 'No Slack bot token — simulated post. Add SLACK_BOT_TOKEN in Settings/env for live delivery.',
                'at' => $at,
            ];
        }

        $payload = ['channel' => $channel, 'text' => $text];
        if ($blocks !== []) {
            $payload['blocks'] = $blocks;
        }
        try {
            $res = self::api('chat.postMessage', $payload, $token);
        } catch (Throwable $e) {
            return ['ok' => false, 'simulated' => false, 'channel' => $channel, 'error' => $e->getMessage(), 'at' => $at];
        }
        if (empty($res['ok'])) {
            return ['ok' => false, 'simulated' => false, 'channel' => $channel, 'error' => (string) ($res['error'] ?? substr((string) json_encode($res), 0, 200)), 'response' => $res, 'at' => $at];
        }
        Database::log('slack.post', ($channel) . ' ts=' . ($res['ts'] ?? '?'));
        return ['ok' => true, 'simulated' => false, 'channel' => $channel, 'ts' => (string) ($res['ts'] ?? ''), 'url' => self::workspaceUrl($channel), 'at' => $at];
    }

    public static function updateMessage(string $channel, string $ts, string $text, array $blocks = []): array
    {
        $token = self::botToken();
        if ($token === '' || $token === 'simulated') {
            return ['ok' => true, 'simulated' => true, 'channel' => $channel, 'ts' => $ts, 'at' => date('Y-m-d H:i:s')];
        }
        $payload = ['channel' => $channel, 'ts' => $ts, 'text' => $text];
        if ($blocks !== []) {
            $payload['blocks'] = $blocks;
        }
        try {
            $res = self::api('chat.update', $payload, $token);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        return ['ok' => !empty($res['ok']), 'response' => $res];
    }

    /** Publish a FlowAgent run result to Slack (#100). */
    public static function publishFlowResult(string $channel, string $flowName, array $result, array $opts = []): array
    {
        $runId = (int) ($result['run_id'] ?? 0);
        $count = count((array) ($result['content_ids'] ?? []));
        $score = (int) ($result['score'] ?? 0);
        $text = "Flow *{$flowName}* done — {$count} item(s)" . ($score > 0 ? " · score {$score}/100" : '')
            . ($runId > 0 ? " · <" . url('agent') . '&run=' . $runId . "|Run #{$runId}>" : '');
        $blocks = [
            ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $text]],
            ['type' => 'actions', 'elements' => [
                ['type' => 'button', 'text' => ['type' => 'plain_text', 'text' => 'Open library'], 'url' => url('content')],
                ['type' => 'button', 'text' => ['type' => 'plain_text', 'text' => $runId > 0 ? "Replay #{$runId}" : 'Open FlowAgent'], 'url' => url('agent') . ($runId > 0 ? '&run=' . $runId : '')],
            ]],
        ];
        return self::postMessage($channel !== '' ? $channel : self::defaultChannel(), $text, $blocks);
    }

    // ------------------------------------------------------------ inbound ---

    /**
     * Parse a slash-command payload (/slack, /agent).
     * @return array{command:string,text:string,user_id:string,channel_id:string,response_url:string}
     */
    public static function parseSlash(array $post): array
    {
        return [
            'command' => (string) ($post['command'] ?? ''),
            'text' => trim((string) ($post['text'] ?? '')),
            'user_id' => (string) ($post['user_id'] ?? ''),
            'channel_id' => (string) ($post['channel_id'] ?? ''),
            'response_url' => (string) ($post['response_url'] ?? ''),
        ];
    }

    /**
     * Bridge Slack text into local Chat (#98).
     * Creates/finds a conversation and stores both sides.
     */
    public static function bridgeToChat(string $slackText, string $slackUser = '', int $convId = 0): array
    {
        $title = excerpt(($slackUser !== '' ? $slackUser . ': ' : '') . $slackText, 60) ?: 'Slack import';
        if ($convId <= 0 || Conversation::find($convId) === null) {
            $convId = Conversation::create($title, null, (string) config('chat.defaults.system', ''));
        }
        Message::create($convId, 'user', $slackText, 'slack', ['slack_user' => $slackUser]);
        Database::log('slack.chat_bridge', "conv #{$convId} :: " . mb_substr($slackText, 0, 120));
        return ['conversation_id' => $convId];
    }

    /**
     * Trigger a FlowAgent run from Slack text (#99).
     * Caller streams progress back via response_url / chat.update.
     */
    public static function bridgeToAgent(string $goal, array $channels = ['social', 'blog', 'email'], string $triggerBy = 'slack'): array
    {
        $goal = trim($goal) !== '' ? trim($goal) : 'Slack quick brief';
        $res = Agent::run([
            'goal' => $goal,
            'channels' => array_values(array_intersect(['social', 'blog', 'email', 'slack'], $channels)) ?: ['social'],
            'trigger_by' => $triggerBy,
            'count' => 3,
        ]);
        Database::log('slack.agent_bridge', "run #{$res['run_id']} :: " . mb_substr($goal, 0, 120));
        return $res;
    }

    /** POST a delayed payload to a Slack response_url (slash commands). Simulated when empty. */
    public static function postToResponseUrl(string $responseUrl, array $payload): array
    {
        if ($responseUrl === '') {
            Database::log('slack.simulated', 'response_url empty :: ' . mb_substr((string) ($payload['text'] ?? json_encode($payload)), 0, 120));
            return ['ok' => true, 'simulated' => true, 'note' => 'No response_url — returned inline.'];
        }
        $ch = curl_init($responseUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload),
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            Database::log('slack.response_url_failed', $err, 'error');
            return ['ok' => false, 'error' => $err];
        }
        curl_close($ch);
        Database::log('slack.response_url', mb_substr((string) $body, 0, 120));
        return ['ok' => true, 'simulated' => false, 'response' => mb_substr((string) $body, 0, 300)];
    }

    /**
     * Run Agent with Slack streaming (#99).
     * - Posts run_start + each step + done to $channel and/or $responseUrl.
     * - Deltas are buffered (not posted per-token) to respect rate limits.
     * @return array{run_id:int,status:string,outputs:int,content_ids:int[],score:int,provider:string,streamed:int}
     */
    public static function bridgeToAgentStreaming(string $goal, array $channels = ['social', 'blog', 'email'], ?string $responseUrl = null, ?string $channel = null, string $triggerBy = 'slack'): array
    {
        $goal = trim($goal) !== '' ? trim($goal) : 'Slack quick brief';
        $channels = array_values(array_intersect(['social', 'blog', 'email', 'slack'], $channels)) ?: ['social'];
        $responseUrl = (string) ($responseUrl ?? '');
        $channel = $channel !== null && $channel !== '' ? $channel : self::defaultChannel();
        $streamed = 0;

        $notify = static function (string $text, bool $final = false) use ($responseUrl, $channel, &$streamed): void {
            $payload = ['response_type' => $final ? 'in_channel' : 'ephemeral', 'text' => $text];
            if ($responseUrl !== '') {
                self::postToResponseUrl($responseUrl, $payload);
                $streamed++;
            }
            // Mirror important milestones to the channel (rate-safe: steps + done only).
            if ($final || str_starts_with($text, ':')) {
                self::postMessage($channel, $text);
                $streamed++;
            }
        };

        $notify(":rocket: Starting FlowAgent run for: {$goal} [" . implode(',', $channels) . "]", false);

        $buffer = '';
        $emit = static function (array $p) use ($notify, &$buffer): void {
            $t = (string) ($p['type'] ?? '');
            if ($t === 'step') {
                $notify(":gear: Step {$p['index']}/{$p['total']} — {$p['label']} ({$p['id']})", false);
            } elseif ($t === 'delta') {
                $buffer .= (string) ($p['t'] ?? '');
            } elseif ($t === 'step_done') {
                $notify(":white_check_mark: {$p['id']} done — {$p['chars']} chars, {$p['outputs']} outputs", false);
            } elseif ($t === 'step_failed') {
                $notify(":x: {$p['id']} failed — {$p['error']}", true);
            }
            // run_start / done handled by caller return.
        };

        $res = Agent::run([
            'goal' => $goal,
            'channels' => $channels,
            'trigger_by' => $triggerBy,
            'count' => 3,
        ], $emit);

        $runId = (int) $res['run_id'];
        $final = ":tada: FlowAgent Run #{$runId} done — {$res['outputs']} outputs, score {$res['score']}/100 via {$res['provider']} (<" . url('agent') . '&run=' . $runId . "|open run>)";
        $notify($final, true);
        Database::log('slack.agent_stream', "run #{$runId} streamed {$streamed} msgs :: " . mb_substr($goal, 0, 120));
        $res['streamed'] = $streamed;
        return $res;
    }

    // ------------------------------------------------------------ tools ---

    /**
     * All SCIT Slack tools registry for AutoFlow + FlowAgent (#102 #103).
     * @return array<int,array{id:string,label:string,desc:string}>
     */
    public static function tools(): array
    {
        return [
            ['id' => 'postMessage', 'label' => 'Post message to channel', 'desc' => 'chat.postMessage — simulated without xoxb'],
            ['id' => 'updateMessage', 'label' => 'Update message', 'desc' => 'chat.update by channel+ts'],
            ['id' => 'publishFlowResult', 'label' => 'Publish flow result', 'desc' => 'Flow done card + Open library / Replay buttons'],
            ['id' => 'bridgeToChat', 'label' => 'Bridge Slack text to Chat', 'desc' => 'Conversation + Message mirror'],
            ['id' => 'bridgeToAgent', 'label' => 'Trigger FlowAgent run', 'desc' => 'Agent::run from Slack goal'],
            ['id' => 'bridgeToAgentStreaming', 'label' => 'Streaming FlowAgent run', 'desc' => 'Steps + done to response_url/channel'],
            ['id' => 'postToResponseUrl', 'label' => 'Delayed slash response', 'desc' => 'POST to response_url'],
            ['id' => 'status', 'label' => 'Workspace status', 'desc' => 'workspace_id, URLs, configured, default_channel'],
            ['id' => 'ceoList', 'label' => 'CEO pending tasks', 'desc' => 'ceo list — current BST block queue'],
            ['id' => 'ceoSummary', 'label' => 'CEO daily summary', 'desc' => 'ceo summary — totals + progress %'],
            ['id' => 'ceoBlock', 'label' => 'CEO current block', 'desc' => 'ceo block — active BST window'],
            ['id' => 'ceoCreate', 'label' => 'CEO create task', 'desc' => 'ceo create <title> — new daily_task'],
            ['id' => 'ceoSetStatus', 'label' => 'CEO start/done task', 'desc' => 'ceo start|done <id> — status change + task_logs'],
        ];
    }

    // --------------------------------------------- CEO chatops (Slack parity) ---
    // Same `ceo list|summary|block|create|done|start` language as
    // slack-php-integration (src/Ceo/CeoTaskManager + webhook handlers),
    // but backed by ScitbdCeo/DailyTask so no Composer is required here.

    public static function isCeoCommand(string $text): bool
    {
        if (!class_exists('ScitbdCeo')) {
            return (bool) preg_match('/\bceo\s+(list|tasks|summary|block|create|done|complete|start|help)\b/i', $text);
        }
        return ScitbdCeo::parseCeoCommand($text) !== null;
    }

    public static function ceoHelp(): string
    {
        return "CEO commands: `ceo list` · `ceo summary` · `ceo block` · `ceo create <title>` · `ceo done <id>` · `ceo start <id>`";
    }

    /** @return array{text:string,blocks:array} */
    public static function ceoAnswer(string $text, string $triggerBy = 'slack'): array
    {
        $parsed = class_exists('ScitbdCeo') ? ScitbdCeo::parseCeoCommand($text) : null;
        if ($parsed === null) {
            return ['text' => self::ceoHelp(), 'blocks' => []];
        }
        $verb = $parsed['verb'];
        $arg = $parsed['arg'];
        try {
            switch ($verb) {
                case 'list':
                case 'tasks': {
                    $block = ScitbdCeo::currentBlock();
                    $tasks = ScitbdCeo::pendingForBlock(isset($block['id']) ? (int) $block['id'] : null, 10);
                    $msg = ScitbdCeo::formatTasksText($tasks, $block ?: null);
                    return ['text' => strip_tags($msg), 'blocks' => [['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $msg]]]];
                }
                case 'summary': {
                    $block = ScitbdCeo::currentBlock();
                    $msg = ScitbdCeo::formatSummaryText(ScitbdCeo::summary(), $block ?: null);
                    return ['text' => strip_tags($msg), 'blocks' => [['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $msg]]]];
                }
                case 'block': {
                    $block = ScitbdCeo::currentBlock();
                    if (empty($block)) {
                        return ['text' => 'No active BST block right now.', 'blocks' => []];
                    }
                    $msg = "🕐 *{$block['block_name']}*\n*BST:* {$block['bst_start']}–{$block['bst_end']} · *UTC:* {$block['utc_start']}–{$block['utc_end']}\n*Focus:* {$block['regional_focus']} — {$block['core_execution_focus']}";
                    return ['text' => strip_tags("Active block: {$block['block_name']}"), 'blocks' => [['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $msg]]]];
                }
                case 'create': {
                    $title = trim($arg !== '' ? $arg : (string) preg_replace('/^.*?create\s+/i', '', $parsed['clean'] ?? ''));
                    if ($title === '') {
                        return ['text' => 'Usage: `ceo create <task title>`', 'blocks' => []];
                    }
                    $priority = 'medium';
                    if (preg_match('/\b(critical|high|medium|low)\b/i', $title, $m)) {
                        $priority = strtolower($m[1]);
                    }
                    $block = ScitbdCeo::currentBlock();
                    $id = DailyTask::create([
                        'task_title' => $title, 'priority' => $priority,
                        'bst_block_id' => isset($block['id']) ? (int) $block['id'] : 3,
                        'assignee' => 'ceo', 'created_by' => $triggerBy,
                    ]);
                    $task = DailyTask::find($id);
                    $msg = "✅ *Task #{$id} created*\n" . ($task['task_title'] ?? $title) . "\nPriority: `{$priority}` · Block: `" . ($task['bst_block_id'] ?? '?') . '`';
                    Database::log('slack.ceo_create', "#{$id} :: " . mb_substr($title, 0, 120));
                    return ['text' => strip_tags("Task #{$id} created: {$title}"), 'blocks' => [['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $msg]]]];
                }
                case 'done':
                case 'complete': {
                    $id = (int) preg_replace('/\D+/', '', $arg);
                    if ($id <= 0) {
                        return ['text' => 'Usage: `ceo done <task_id>`', 'blocks' => []];
                    }
                    if (!DailyTask::setStatus($id, 'completed')) {
                        return ['text' => "⚠️ Task #{$id} not found.", 'blocks' => []];
                    }
                    Database::log('slack.ceo_done', "#{$id} by {$triggerBy}");
                    return ['text' => "✅ Task #{$id} marked completed.", 'blocks' => []];
                }
                case 'start': {
                    $id = (int) preg_replace('/\D+/', '', $arg);
                    if ($id <= 0) {
                        return ['text' => 'Usage: `ceo start <task_id>`', 'blocks' => []];
                    }
                    if (!DailyTask::setStatus($id, 'in_progress')) {
                        return ['text' => "⚠️ Task #{$id} not found.", 'blocks' => []];
                    }
                    Database::log('slack.ceo_start', "#{$id} by {$triggerBy}");
                    return ['text' => "🔄 Task #{$id} started.", 'blocks' => []];
                }
                default:
                    return ['text' => self::ceoHelp(), 'blocks' => []];
            }
        } catch (Throwable $e) {
            Database::log('slack.ceo_failed', $e->getMessage(), 'error');
            return ['text' => '⚠️ CEO command failed: ' . $e->getMessage(), 'blocks' => []];
        }
    }

    // ------------------------------------------------------------ http ---

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private static function api(string $method, array $payload, string $token): array
    {
        $ch = curl_init('https://slack.com/api/' . $method);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8', 'Authorization: Bearer ' . $token],
            CURLOPT_POSTFIELDS => json_encode($payload),
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Slack HTTP error: ' . $err);
        }
        curl_close($ch);
        $data = json_decode((string) $body, true);
        return is_array($data) ? $data : ['raw' => substr((string) $body, 0, 300)];
    }
}

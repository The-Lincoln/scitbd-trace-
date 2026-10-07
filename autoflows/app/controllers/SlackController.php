<?php
/**
 * SlackController — Events API + Slash commands for workspace T0AGURY3K1D.
 * Task #98 (Chat bridge). Uses SlackApp service.
 *
 * Routes (Router::MAP):
 *   slack/events -> events()  (Event Subscriptions Request URL)
 *   slack/slash  -> slash()   (Slash commands /slack, /agent)
 *   api/slack/status -> status()
 *
 * Configure in api.slack.com:
 *   Request URL: {base}/index.php?r=slack/events
 *   Slash: /slack -> {base}/index.php?r=slack/slash
 *          /agent -> {base}/index.php?r=slack/slash
 */
declare(strict_types=1);

final class SlackController extends Controller
{
    /** Slack Events API entrypoint. */
    public function events(): void
    {
        $body = file_get_contents('php://input') ?: '';
        $data = json_decode($body, true);
        if (!is_array($data)) {
            json_response(['ok' => false, 'error' => 'Bad JSON'], 400);
        }

        // URL verification handshake.
        if (($data['type'] ?? '') === 'url_verification') {
            header('Content-Type: text/plain');
            echo (string) ($data['challenge'] ?? '');
            exit;
        }

        // Verify signature (skipped when no secret = dev).
        $ts = (string) ($_SERVER['HTTP_X_SLACK_REQUEST_TIMESTAMP'] ?? '');
        $sig = (string) ($_SERVER['HTTP_X_SLACK_SIGNATURE'] ?? '');
        if (!SlackApp::verifySignature($body, $ts, $sig)) {
            json_response(['ok' => false, 'error' => 'Bad signature'], 401);
        }

        $event = $data['event'] ?? [];
        $type = (string) ($event['type'] ?? '');
        $subtype = (string) ($event['subtype'] ?? '');

        // Ignore bot echoes / edits to avoid loops.
        if ($subtype === 'bot_message' || $subtype === 'message_changed' || isset($event['bot_id'])) {
            json_response(['ok' => true, 'ignored' => $subtype ?: 'bot']);
        }

        if (in_array($type, ['message', 'app_mention'], true)) {
            $text = trim((string) ($event['text'] ?? ''));
            $user = (string) ($event['user'] ?? '');
            $channel = (string) ($event['channel'] ?? '');
            if ($text !== '') {
                // CEO chatops first — same `ceo …` language as slack-php-integration.
                if (SlackApp::isCeoCommand($text)) {
                    $ans = SlackApp::ceoAnswer($text, 'slack_event:' . ($user !== '' ? $user : 'unknown'));
                    if ($channel !== '') {
                        SlackApp::postMessage($channel, $ans['text'], $ans['blocks']);
                    }
                    Database::log('slack.event_ceo', mb_substr($text, 0, 120));
                    json_response(['ok' => true, 'ceo' => true]);
                }
                // #98: mirror into local Chat.
                $bridge = SlackApp::bridgeToChat($text, $user);
                // #99 hook: /agent prefix inside Slack triggers a streaming run.
                if (str_starts_with(strtolower($text), '/agent') || str_starts_with($text, '!agent')) {
                    $goal = trim(preg_replace('/^(\/agent|!agent)\s*/i', '', $text));
                    try {
                        $run = SlackApp::bridgeToAgentStreaming(
                            $goal !== '' ? $goal : $text,
                            ['social', 'blog', 'email'],
                            null,
                            $channel !== '' ? $channel : SlackApp::defaultChannel(),
                            'slack_event'
                        );
                        Database::log('slack.event_agent', "run #{$run['run_id']} streamed {$run['streamed']}");
                    } catch (Throwable $e) {
                        Database::log('slack.agent_failed', $e->getMessage(), 'error');
                    }
                } else {
                    // Light ack so Slack shows the bridge is live (full LLM reply = #99 streaming).
                    if ($channel !== '' && SlackApp::isConfigured()) {
                        SlackApp::postMessage($channel, "Got it — logged to Chat #{$bridge['conversation_id']}. Ask me with `/agent <goal>` for a full run.");
                    }
                }
                Database::log('slack.event', "{$type} conv #{$bridge['conversation_id']} :: " . mb_substr($text, 0, 120));
            }
        }

        json_response(['ok' => true]);
    }

    /** Slash commands: /slack <msg> and /agent <goal>. */
    public function slash(): void
    {
        $body = file_get_contents('php://input') ?: '';
        $ts = (string) ($_SERVER['HTTP_X_SLACK_REQUEST_TIMESTAMP'] ?? '');
        $sig = (string) ($_SERVER['HTTP_X_SLACK_SIGNATURE'] ?? '');
        if (!SlackApp::verifySignature($body, $ts, $sig)) {
            json_response(['ok' => false, 'error' => 'Bad signature'], 401);
        }

        $cmd = SlackApp::parseSlash($_POST);
        $name = strtolower((string) $cmd['command']); // /slack or /agent
        $text = $cmd['text'];
        $channel = $cmd['channel_id'] !== '' ? $cmd['channel_id'] : SlackApp::defaultChannel();

        if ($text === '' || in_array($text, ['help', '--help'], true)) {
            json_response([
                'response_type' => 'ephemeral',
                'text' => "SlackApp — workspace " . SlackApp::WORKSPACE_ID . "\n"
                    . "`/slack <message>` log to Chat\n"
                    . "`/slack-status` bridge status\n"
                    . "`/agent <goal>` start a FlowAgent run (social+blog+email)\n"
                    . "`ceo list|summary|block|create|done|start` CEO tasks (current BST block)",
            ]);
        }

        // CEO chatops via slash: `/slack ceo summary` or `/agent ceo list`.
        if (SlackApp::isCeoCommand($text)) {
            $ans = SlackApp::ceoAnswer($text, 'slack_slash:' . ($cmd['user_id'] !== '' ? $cmd['user_id'] : 'unknown'));
            if ($channel !== '') {
                SlackApp::postMessage($channel, $ans['text'], $ans['blocks']);
            }
            json_response(['response_type' => 'in_channel', 'text' => $ans['text'], 'blocks' => $ans['blocks']]);
        }

        if ($name === '/agent' || str_starts_with(strtolower($text), 'agent ')) {
            $goal = preg_replace('/^agent\s+/i', '', $text);
            $responseUrl = (string) $cmd['response_url'];
            try {
                // Streaming: posts run_start + each step + done to response_url + channel.
                $run = SlackApp::bridgeToAgentStreaming(
                    $goal,
                    ['social', 'blog', 'email'],
                    $responseUrl,
                    $channel,
                    'slack_slash'
                );
                // Immediate HTTP response (Slack already got progress via response_url).
                json_response([
                    'response_type' => 'in_channel',
                    'text' => ":tada: FlowAgent Run #{$run['run_id']} done for: {$goal} — {$run['outputs']} outputs, score {$run['score']}/100 via {$run['provider']} (streamed {$run['streamed']})",
                ]);
            } catch (Throwable $e) {
                if ($responseUrl !== '') {
                    SlackApp::postToResponseUrl($responseUrl, ['response_type' => 'ephemeral', 'text' => 'Agent failed: ' . $e->getMessage()]);
                }
                json_response(['response_type' => 'ephemeral', 'text' => 'Agent failed: ' . $e->getMessage()]);
            }
        }

        // Default /slack: bridge to Chat (+ /slack-status shortcut).
        if (strtolower($text) === 'status' || $text === 'slack-status') {
            json_response([
                'response_type' => 'ephemeral',
                'text' => 'SlackApp ' . (SlackApp::isConfigured() ? 'live' : 'simulated (no SLACK_BOT_TOKEN)')
                    . ' · workspace ' . SlackApp::WORKSPACE_ID . ' · ' . SlackApp::workspaceUrl(),
            ]);
        }

        $bridge = SlackApp::bridgeToChat($text, (string) $cmd['user_id']);
        // Also visible in channel so the team sees Chat ID.
        SlackApp::postMessage($channel, "Logged to Chat #{$bridge['conversation_id']}: {$text}");
        json_response([
            'response_type' => 'in_channel',
            'text' => "Logged to Chat #{$bridge['conversation_id']}",
        ]);
    }

    /** Health for top-bar / debugging. */
    public function status(): void
    {
        json_response([
            'ok' => true,
            'workspace_id' => SlackApp::WORKSPACE_ID,
            'workspace_url' => SlackApp::workspaceUrl(),
            'agent_url' => SlackApp::AGENT_URL,
            'configured' => SlackApp::isConfigured(),
            'default_channel' => SlackApp::defaultChannel(),
            'routes' => ['events' => url('slack/events'), 'slash' => url('slack/slash')],
        ]);
    }
}

<?php
/**
 * AutoFlows — the standing "generate this on a schedule" recipes.
 */
declare(strict_types=1);

final class FlowController extends Controller
{
    private const CHANNELS = ['social', 'blog', 'email', 'pack', 'slack'];
    private const SCHEDULES = ['daily', 'weekly', 'manual'];

    public function index(): void
    {
        $this->view->render('flows/index', [
            'title'     => 'AutoFlows',
            'flows'     => Flow::all(),
            'due'       => Flow::dueToday(),
            'channels'  => (array) config('channels'),
            'runs'      => Run::all(8),
            'llm'       => TinyLLM::status(3),
            'flashes'   => take_flash(),
        ]);
    }

    public function edit(): void
    {
        $id   = $this->int('id', 0);
        $flow = $id > 0 ? Flow::find($id) : null;
        if ($id > 0 && $flow === null) {
            flash('warning', 'Flow not found.');
            redirect('flows');
        }

        $this->view->render('flows/edit', [
            'title'     => $flow !== null ? 'Edit flow' : 'New flow',
            'flow'      => $flow,
            'channels'  => (array) config('channels'),
            'platforms' => (array) config('channels.social.platforms'),
            'runs'      => $flow !== null ? Run::forFlow((int) $flow['id'], 10) : [],
            'llm'       => TinyLLM::status(3),
            'flashes'   => take_flash(),
        ]);
    }

    // ---------------------------------------------------------- endpoints ---

    public function create(): void
    {
        $this->requireCsrf();
        $d    = $this->payload();
        $id   = Flow::create($d);
        Database::log('flow.create', "#{$id} {$d['name']} [{$d['channel']}]");
        json_response(['ok' => true, 'id' => $id, 'redirect' => url('flow') . '&id=' . $id]);
    }

    public function update(): void
    {
        $this->requireCsrf();
        $d  = $this->payload();
        $id = $this->int('id', 0);
        if (Flow::find($id) === null) {
            json_response(['ok' => false, 'error' => 'Flow not found'], 404);
        }
        Flow::update($id, $d);
        Database::log('flow.update', "#{$id} {$d['name']}");
        json_response(['ok' => true, 'id' => $id]);
    }

    public function delete(): void
    {
        $this->requireCsrf();
        $body = $this->jsonBody();
        $id   = (int) ($body['id'] ?? $this->int('id', 0));
        if (Flow::find($id) === null) {
            json_response(['ok' => false, 'error' => 'Flow not found'], 404);
        }
        Flow::destroy($id);
        Database::log('flow.delete', "#{$id}");
        json_response(['ok' => true]);
    }

    public function toggle(): void
    {
        $this->requireCsrf();
        $body = $this->jsonBody();
        $id   = (int) ($body['id'] ?? $this->int('id', 0));
        if (Flow::find($id) === null) {
            json_response(['ok' => false, 'error' => 'Flow not found'], 404);
        }
        $active = Flow::toggle($id);
        Database::log('flow.toggle', "#{$id} " . ($active ? 'on' : 'off'));
        json_response(['ok' => true, 'active' => $active]);
    }

    /**
     * Run a flow now, streaming the agent trace over SSE.
     * Used by "Run now" on the flows page and the dashboard.
     */
    public function run(): void
    {
        $this->requireCsrf();
        $body = $this->jsonBody();
        $id   = (int) ($body['id'] ?? $this->int('id', 0));

        $flow = $id > 0 ? Flow::find($id) : null;
        if ($flow === null) {
            Sse::open();
            Sse::send(['type' => 'error', 'error' => 'Flow not found']);
            Sse::done(['ok' => false]);
        }

        Sse::open();
        Sse::send(['type' => 'flow', 'id' => (int) $flow['id'], 'name' => $flow['name']]);

        // The agent's terminal event IS ours here — forward it untouched.
        try {
            $result = Agent::run([
                'goal'       => trim((string) ($flow['brief'] ?? '')) ?: (string) $flow['name'],
                'channels'   => Flow::channelsFor($flow),
                'flow_id'    => (int) $flow['id'],
                'tone'       => (string) $flow['tone'],
                'audience'   => (string) $flow['audience'],
                'platforms'  => ContentFactory::context(['platforms' => $flow['platforms']])['platforms'],
                'count'      => (int) $flow['count'],
                'trigger_by' => 'manual',
            ], static function (array $p): void {
                Sse::send($p);
            });
            Database::log('flow.run', "{$flow['name']} -> {$result['outputs']} outputs");
            // #100: publish flow outputs to Slack workspace T0AGURY3K1D (simulated when offline).
            if (class_exists('SlackApp')) {
                try {
                    $slack = SlackApp::publishFlowResult(SlackApp::defaultChannel(), (string) $flow['name'], $result);
                    Sse::send(['type' => 'slack', 'ok' => (bool) ($slack['ok'] ?? false), 'simulated' => (bool) ($slack['simulated'] ?? true), 'channel' => SlackApp::defaultChannel(), 'url' => $slack['url'] ?? SlackApp::workspaceUrl()]);
                } catch (Throwable $e) {
                    Sse::send(['type' => 'slack', 'ok' => false, 'error' => $e->getMessage()]);
                    Database::log('slack.publish_failed', $e->getMessage(), 'error');
                }
            }
            exit;
        } catch (Throwable $e) {
            Sse::send(['type' => 'error', 'error' => $e->getMessage()]);
            Sse::done(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    // ------------------------------------------------------------- helpers ---

    private function payload(): array
    {
        $body     = $this->jsonBody();
        $get      = fn (string $k, string $d = '') => (string) ($body[$k] ?? $_POST[$k] ?? $d);
        $channel  = $get('channel', 'social');

        if (!in_array($channel, self::CHANNELS, true)) {
            json_response(['ok' => false, 'error' => 'Unknown channel'], 422);
        }
        $name = trim($get('name'));
        if ($name === '') {
            json_response(['ok' => false, 'error' => 'Name is required'], 422);
        }

        $platforms = $body['platforms'] ?? $_POST['platforms'] ?? ['twitter', 'linkedin'];
        if (is_string($platforms)) {
            $platforms = array_values(array_filter(array_map('trim', explode(',', $platforms))));
        }
        $allowed = array_keys((array) config('channels.social.platforms', []));
        $platforms = array_values(array_intersect($allowed, (array) $platforms));
        if ($platforms === []) {
            $platforms = ['twitter'];
        }

        $schedule = $get('schedule', 'daily');
        if (!in_array($schedule, self::SCHEDULES, true)) {
            $schedule = 'manual';
        }

        $runAt = $get('run_at', '09:00');
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $runAt)) {
            $runAt = '09:00';
        }

        $brief = (string) ($body['brief'] ?? $_POST['brief'] ?? '');

        return [
            'name'      => $name,
            'channel'   => $channel,
            'brief'     => mb_substr($brief, 0, 2000),
            'platforms' => $platforms,
            'count'     => clamp((int) ($body['count'] ?? $_POST['count'] ?? 3), 1, 10),
            'tone'      => mb_substr($get('tone', 'warm'), 0, 60),
            'audience'  => mb_substr($get('audience', ''), 0, 300),
            'schedule'  => $schedule,
            'run_at'    => $runAt,
            'weekday'   => clamp((int) ($body['weekday'] ?? $_POST['weekday'] ?? 1), 1, 7),
            'active'    => !empty($body['active']) || !empty($_POST['active']),
        ];
    }
}

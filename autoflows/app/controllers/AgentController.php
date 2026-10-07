<?php
/**
 * FlowAgent console — start a multi-step run, watch it stream, replay a trace.
 */
declare(strict_types=1);

final class AgentController extends Controller
{
    public function index(): void
    {
        $runId = $this->int('run', 0);
        $run   = $runId > 0 ? Run::find($runId) : null;

        $this->view->render('agent/index', [
            'title'     => $run !== null ? 'Run #' . $run['id'] : 'FlowAgent',
            'run'       => $run,
            'outputs'   => $run !== null && $run['outputs'] > 0
                ? Content::decorateAll(Content::forRun((int) $run['id']))
                : [],
            'runs'      => Run::all(20),
            'plan'      => array_map(fn ($id) => ['id' => $id] + Agent::defs()[$id], Agent::plan(['social', 'blog', 'email', 'slack'])),
            'channels'  => (array) config('channels'),
            'platforms' => (array) config('channels.social.platforms'),
            'llm'       => TinyLLM::status(3),
            'flashes'   => take_flash(),
        ]);
    }

    /** SSE endpoint — executes the plan. */
    public function run(): void
    {
        $this->requireCsrf();
        $body = $this->jsonBody();

        $goal = trim((string) ($body['goal'] ?? ''));
        if ($goal === '') {
            json_response(['ok' => false, 'error' => 'A goal is required'], 422);
        }

        $channels = (array) ($body['channels'] ?? ['social', 'blog', 'email']);
        $channels = array_values(array_intersect(['social', 'blog', 'email', 'slack'], $channels));
        if ($channels === []) {
            $channels = ['social'];
        }

        $platforms = (array) ($body['platforms'] ?? ['twitter', 'linkedin']);
        $platforms = array_values(array_intersect(
            array_keys((array) config('channels.social.platforms', [])),
            is_string($platforms) ? explode(',', $platforms) : $platforms
        ));

        Sse::open();

        try {
            Agent::run([
                'goal'       => $goal,
                'channels'   => $channels,
                'tone'       => (string) ($body['tone'] ?? ''),
                'audience'   => (string) ($body['audience'] ?? ''),
                'platforms'  => $platforms !== [] ? $platforms : ['twitter'],
                'count'      => clamp((int) ($body['count'] ?? 3), 1, 10),
                'trigger_by' => 'agent',
            ], static function (array $p): void {
                Sse::send($p);
            });
            exit;
        } catch (Throwable $e) {
            Sse::send(['type' => 'error', 'error' => $e->getMessage()]);
            Sse::done(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    public function runs(): void
    {
        json_response(['ok' => true, 'items' => Run::all($this->int('limit', 20))]);
    }

    /** Replay a stored trace (used when reopening an old run). */
    public function trace(): void
    {
        $id   = $this->int('id', 0);
        $run  = Run::find($id);
        if ($run === null) {
            json_response(['ok' => false, 'error' => 'Run not found'], 404);
        }
        json_response([
            'ok'      => true,
            'run'     => $run,
            'trace'   => Run::trace($run),
            'outputs' => Content::decorateAll(Content::forRun($id)),
        ]);
    }
}

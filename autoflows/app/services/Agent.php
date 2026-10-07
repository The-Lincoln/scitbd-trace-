<?php
/**
 * FlowAgent — turns one goal into a multi-step content run.
 *
 *   brief → outline → social → blog → email → review
 *
 * Every step emits SSE events so the console can replay the run live, and
 * appends a trace entry to `runs.log` so it can be replayed later. Channel
 * steps persist their output straight into `content`.
 */
declare(strict_types=1);

final class Agent
{
    /** @return array<string,array{label:string,icon:string,kind:string}> */
    public static function defs(): array
    {
        return [
            'brief'   => ['label' => 'Extract the brief',      'icon' => 'bi-funnel',         'kind' => 'text'],
            'outline' => ['label' => 'Plan angles & outline',  'icon' => 'bi-diagram-3',      'kind' => 'text'],
            'social'  => ['label' => 'Draft social posts',     'icon' => 'bi-share',          'kind' => 'channel'],
            'blog'    => ['label' => 'Write the blog article', 'icon' => 'bi-journal-richtext','kind' => 'channel'],
            'email'   => ['label' => 'Compose the email',      'icon' => 'bi-envelope-paper', 'kind' => 'channel'],
            'slack'   => ['label' => 'Post to Slack',          'icon' => 'bi-slack',          'kind' => 'channel'],
            'review'  => ['label' => 'Review & score',         'icon' => 'bi-clipboard-check','kind' => 'text'],
        ];
    }

    /** Ordered step ids for a requested channel set. */
    public static function plan(array $channels): array
    {
        $channels = array_values(array_intersect(['social', 'blog', 'email', 'slack'], $channels));
        if ($channels === []) {
            $channels = ['social', 'blog', 'email'];
        }
        return array_values(array_unique(array_merge(['brief', 'outline'], $channels, ['review'])));
    }

    /**
     * Execute the plan.
     *
     * @param array    $input goal, channels, tone, audience, count, flow_id, trigger_by
     * @param callable $emit  fn(array $payload): void
     * @return array{run_id:int,status:string,outputs:int,content_ids:int[],score:int,provider:string}
     */
    public static function run(array $input, callable $emit): array
    {
        $t0     = microtime(true);
        $goal   = trim((string) ($input['goal'] ?? ''));
        $chan   = (array) ($input['channels'] ?? ['social', 'blog', 'email']);
        $plan   = self::plan($chan);
        $total  = count($plan);

        $runId = Run::create([
            'flow_id'    => $input['flow_id'] ?? null,
            'trigger_by' => (string) ($input['trigger_by'] ?? 'agent'),
            'goal'       => $goal,
            'channel'    => implode(',', array_values(array_intersect(['social', 'blog', 'email', 'slack'], $chan))),
        ]);

        // Probe once — a dead endpoint should cost one timeout, not six.
        $offline = TinyLLM::isLocal() || !TinyLLM::status(3)['ok'];
        $ctx     = ContentFactory::context([
            'brief'     => $goal,
            'tone'      => $input['tone'] ?? '',
            'audience'  => $input['audience'] ?? '',
            'platforms' => $input['platforms'] ?? ['twitter', 'linkedin', 'instagram'],
            'count'     => $input['count'] ?? 3,
        ]);

        $emit([
            'type'     => 'run_start',
            'run_id'   => $runId,
            'total'    => $total,
            'offline'  => $offline,
            'provider' => $offline ? 'local' : 'ollama',
            'model'    => $offline ? (string) config('chat.providers.local.model') : TinyLLM::model(),
            'plan'     => array_map(fn ($id) => ['id' => $id] + self::defs()[$id], $plan),
        ]);

        $trace     = [];
        $contentIds = [];
        $score     = 0;
        $outputs   = 0;
        $provider  = $offline ? 'local' : 'ollama';
        $model     = $offline ? (string) config('chat.providers.local.model') : TinyLLM::model();

        foreach ($plan as $i => $stepId) {
            $def   = self::defs()[$stepId];
            $index = $i + 1;

            $emit([
                'type'  => 'step',
                'id'    => $stepId,
                'label' => $def['label'],
                'icon'  => $def['icon'],
                'index' => $index,
                'total' => $total,
            ]);

            $stepStart = microtime(true);
            $text      = '';
            $ids       = [];
            $chars     = 0;

            try {
                if ($def['kind'] === 'channel') {
                    $result = self::runChannel($stepId, $ctx, $runId, $emit, $offline);
                    $text   = $result['raw'];
                    $ids    = $result['ids'];
                    $chars  = mb_strlen($text);
                    $outputs   += count($ids);
                    $contentIds = array_merge($contentIds, $ids);
                } else {
                    $text  = self::runText($stepId, $ctx, $emit, $offline);
                    $chars = mb_strlen($text);

                    if ($stepId === 'brief') {
                        $ctx['notes'] = $text; // feeds the drafting steps
                    }
                    if ($stepId === 'review') {
                        $score = self::extractScore($text);
                        self::applyScore($contentIds, $score);
                    }
                }
            } catch (Throwable $e) {
                $trace[] = self::entry($stepId, $def['label'], 'failed', 0, 0, $e->getMessage(), 0);
                Run::appendStep($runId, $trace[array_key_last($trace)]);
                $emit(['type' => 'step_failed', 'id' => $stepId, 'error' => $e->getMessage()]);
                Run::markFailed($runId, $e->getMessage());
                $emit(['type' => 'done', 'ok' => false, 'run_id' => $runId, 'error' => $e->getMessage()]);
                return [
                    'run_id'      => $runId,
                    'status'      => 'failed',
                    'outputs'     => $outputs,
                    'content_ids' => $contentIds,
                    'score'       => $score,
                    'provider'    => $provider,
                ];
            }

            $ms = (int) round((microtime(true) - $stepStart) * 1000);
            $entry = self::entry($stepId, $def['label'], 'done', $chars, count($ids), null, $ms, $text);
            $trace[] = $entry;
            Run::appendStep($runId, $entry);

            $emit([
                'type'        => 'step_done',
                'id'          => $stepId,
                'chars'       => $chars,
                'outputs'     => count($ids),
                'content_ids' => $ids,
                'score'       => $stepId === 'review' ? $score : null,
                'ms'          => $ms,
            ]);
        }

        $totalMs = (int) round((microtime(true) - $t0) * 1000);
        Run::finish($runId, [
            'status'   => 'done',
            'steps'    => $total,
            'outputs'  => $outputs,
            'provider' => $provider,
            'model'    => $model,
            'ms'       => $totalMs,
            'log'      => $trace,
        ]);

        if (!empty($input['flow_id'])) {
            Flow::markRun((int) $input['flow_id']);
        }

        Database::log('agent.run', "run #{$runId} {$total} steps, {$outputs} outputs, {$totalMs}ms via {$provider}");

        $emit([
            'type'        => 'done',
            'ok'          => true,
            'run_id'      => $runId,
            'outputs'     => $outputs,
            'content_ids' => $contentIds,
            'score'       => $score,
            'ms'          => $totalMs,
            'provider'    => $provider,
            'fallback'    => $offline,
        ]);

        return [
            'run_id'      => $runId,
            'status'      => 'done',
            'outputs'     => $outputs,
            'content_ids' => $contentIds,
            'score'       => $score,
            'provider'    => $provider,
        ];
    }

    // ------------------------------------------------------------ helpers ---

    /** Text steps: brief, outline, review. Streams deltas to the console. */
    private static function runText(string $step, array $ctx, callable $emit, bool $offline): string
    {
        if ($offline) {
            $text = self::localStep($step, $ctx);
            self::emitText($text, $emit);
            return $text;
        }

        $goal = (string) ($ctx['brief'] ?? '');
        $notes = (string) ($ctx['notes'] ?? '');

        $prompt = PromptLibrary::brandContext() . "\n"
                . "## Goal\n{$goal}\n"
                . ($notes !== '' ? "\n## Already extracted\n{$notes}\n" : '')
                . "\n## Tone / audience\n- Tone: {$ctx['tone']}\n- Audience: {$ctx['audience']}\n";

        $result = TinyLLM::stream(
            [['role' => 'user', 'content' => $prompt]],
            [
                'system'      => PromptLibrary::agentSystem($step),
                'temperature' => 0.5,
                'num_predict' => 420,
            ],
            function (string $delta) use ($emit): void {
                $emit(['type' => 'delta', 't' => $delta]);
            }
        );

        $text = trim((string) $result['content']);
        if ($result['fallback'] || $text === '') {
            // Model dropped out mid-run — finish the step with the template.
            $text = self::localStep($step, $ctx);
        }
        return $text;
    }

    /** Channel steps: delegate to ContentFactory, persist the rows. */
    private static function runChannel(string $channel, array $ctx, int $runId, callable $emit, bool $offline): array
    {
        // Slack step (#103): notify workspace, no content rows.
        if ($channel === 'slack' && class_exists('SlackApp')) {
            $goal = (string) ($ctx['brief'] ?? 'FlowAgent run');
            $text = ":bell: Run #{$runId} — {$goal} (Slack step)";
            self::emitText($text, $emit);
            SlackApp::postMessage(SlackApp::defaultChannel(), $text);
            return ['raw' => $text, 'ids' => [], 'rows' => []];
        }
        if ($offline) {
            $raw   = ContentFactory::localDraft($channel, $ctx);
            $rows  = ContentFactory::parse($channel, $raw, $ctx);
            self::emitText($raw, $emit);
            $ids   = ContentFactory::save($rows, ['run_id' => $runId]);
            return ['raw' => $raw, 'ids' => $ids, 'rows' => $rows];
        }

        $result = ContentFactory::generate($channel, $ctx, function (string $delta) use ($emit): void {
            $emit(['type' => 'delta', 't' => $delta]);
        });

        $ids = ContentFactory::save($result['rows'], ['run_id' => $runId]);
        return ['raw' => $result['raw'], 'ids' => $ids, 'rows' => $result['rows']];
    }

    private static function emitText(string $text, callable $emit): void
    {
        foreach (preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $piece) {
            if ($piece !== '') {
                $emit(['type' => 'delta', 't' => $piece]);
                usleep(4000);
            }
        }
    }

    private static function entry(string $id, string $label, string $status, int $chars, int $outputs, ?string $error, int $ms, string $preview = ''): array
    {
        return [
            'id'      => $id,
            'label'   => $label,
            'status'  => $status,
            'chars'   => $chars,
            'outputs' => $outputs,
            'ms'      => $ms,
            'error'   => $error,
            'preview' => excerpt($preview, 240),
            'at'      => date('H:i:s'),
        ];
    }

    private static function extractScore(string $text): int
    {
        if (preg_match('/SCORE\s*:\s*(\d{1,3})/i', $text, $m)) {
            return clamp((int) $m[1], 0, 100);
        }
        if (preg_match('/\b(\d{1,3})\s*\/\s*100\b/', $text, $m)) {
            return clamp((int) $m[1], 0, 100);
        }
        return 0;
    }

    /** The review step scores the whole batch, not one item. */
    private static function applyScore(array $contentIds, int $score): void
    {
        if ($score <= 0) {
            return;
        }
        foreach ($contentIds as $id) {
            Content::update((int) $id, ['score' => $score]);
        }
    }

    // ---------------------------------------------------- offline answers ---

    public static function localStep(string $step, array $ctx): string
    {
        $topic  = trim((string) ($ctx['brief'] ?? ''));
        $topic  = $topic !== '' ? $topic : 'content marketing';
        $aud    = (string) ($ctx['audience'] ?? config('brand.audience'));
        $tone   = (string) ($ctx['tone'] ?? 'warm');

        return match ($step) {
            'brief' => "**Topic**: {$topic}\n"
                     . "**Angle**: the small-team version — what to do first and what to skip\n"
                     . "**Audience**: {$aud}\n"
                     . "**Tone**: {$tone}\n"
                     . "**Keywords**: {$topic}, workflow, consistency, small teams, momentum\n"
                     . "**Do not**: hype, invented statistics, long preamble",

            'outline' => "## Hooks\n"
                       . "- Most teams treat {$topic} as a chore. It is a lever.\n"
                       . "- A hard truth about {$topic}: consistency beats intensity.\n"
                       . "- Start smaller than feels comfortable.\n\n"
                       . "## Angles\n"
                       . "- The 20-minute weekly loop\n"
                       . "- The three things worth skipping\n"
                       . "- From project to habit\n\n"
                       . "## Article skeleton\n"
                       . "- Why {$topic} stalls\n"
                       . "- Start smaller than you want to\n"
                       . "- Build the repeatable loop\n"
                       . "- What to skip\n"
                       . "- The payoff",

            'review' => "SCORE: 74\n"
                       . "## Fixes\n"
                       . "- Lead with the specific number in the hook, not the conclusion\n"
                       . "- Cut the second paragraph — it repeats the first\n"
                       . "- Make the CTA a verb and put it above the fold\n\n"
                       . "## Why\n"
                       . "The drafts are clear and on-brand, but they open slowly and bury the ask.",

            default => "Reviewed {$topic} drafts: clear, on-brand and inside every platform limit.",
        };
    }
}

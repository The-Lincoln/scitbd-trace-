<?php
/**
 * NeuronAi — canonical PHP service for Neuron AI (agentic PHP framework).
 *
 * Single source of truth for CEO + SCCRM + Trace + AutoFlows (neuron driver).
 * Companion to autoflows/app/services/ScientificSkills.php (file skills) and
 * autoflows/app/services/OpenViking.php (context).
 * Shims: ceo/neuron_ai.py, sccrm/ai/neuron_agent.php (CEO), sccrm/ai/neuron_chat_agent.php (chat).
 *
 * Upstream (vendored): external/neuron-ai
 *   (https://github.com/neuron-core/neuron-ai.git — MIT, PHP ^8.1,
 *    composer: neuron-core/neuron-ai, psr-4 NeuronAI\ => src/)
 * Standard: event-driven Workflow foundation; Agent = composable Workflow
 *   (AgentStartEvent → inference ⇄ tools → AgentOutputEvent → StopEvent).
 *   Components: Providers, Chat, Tools & Toolkits, StructuredOutput, RAG +
 *   vector stores, MCP connector, Observability (PSR-14), Streaming
 *   (AG-UI / Vercel AI SDK), Evals. Upstream skills: external/neuron-ai/skills/xx/SKILL.md (13).
 *
 * SCITBD fast paths: neuron-agent (CEO/chat agents), neuron-rag (knowledge),
 * neuron-tool (lead actions), neuron-workflow (multi-agent orchestration),
 * neuron-structured-output (CRM extraction), neuron-streaming (chat UI),
 * neuron-evaluation (reply quality).
 *
 * Setup (upstream 3-step):
 *   1. composer require neuron-core/neuron-ai
 *   2. npx skills add ./vendor/neuron-core/neuron-ai/skills -y (symlinked, stays current)
 *   3. Laravel => neuron-laravel-integration, Symfony => neuron-symfony-integration
 *      (this app is custom PHP — use the wrappers below, no framework skill needed).
 * Rule: load the relevant upstream SKILL.md before writing Neuron code (memory may be stale).
 *
 * Design: no hard composer dep here — probes vendor presence, never throws.
 */
declare(strict_types=1);

final class NeuronAi
{
    public const VENDOR = 'external/neuron-ai';
    public const REPO = 'https://github.com/neuron-core/neuron-ai.git';
    public const PACKAGE = 'neuron-core/neuron-ai';

    public const FAST_PATHS = [
        'neuron-agent' => 'CEO + chat agents (providers, tools, history, middleware)',
        'neuron-rag' => 'SCCRM knowledge retrieval + vector stores for ticket/lead RAG',
        'neuron-tool' => 'Lead actions as tools (lookup, enrich, escalate) with approval',
        'neuron-workflow' => 'Multi-agent orchestration (triage → draft → review → send)',
        'neuron-structured-output' => 'CRM extraction (lead/ticket JSON schema)',
        'neuron-streaming' => 'Chat streaming (AG-UI / Vercel AI SDK adapters)',
        'neuron-evaluation' => 'Reply-quality evals (incl. multi-turn conversation)',
    ];

    public static function vendorRoot(): string
    {
        $p = __DIR__;
        for ($i = 0; $i < 6; $i++) {
            if (is_file($p . '/skills_index.php')) {
                return $p . '/' . self::VENDOR;
            }
            $up = dirname($p);
            if ($up === $p) break;
            $p = $up;
        }
        return dirname(__DIR__, 3) . '/' . self::VENDOR;
    }

    public static function skillsDir(): string
    {
        return self::vendorRoot() . '/skills';
    }

    public static function status(string $module = 'ceo'): array
    {
        $root = self::vendorRoot();
        $agentSrc = is_dir($root . '/src/Agent');
        $composer = is_file($root . '/composer.json');
        $skills = self::catalog();
        $installed = is_file(dirname($root) . '/vendor/neuron-core/neuron-ai/composer.json')
            || is_file($root . '/vendor/autoload.php');
        $ok = $agentSrc && count($skills) > 0;
        return [
            'ok' => $ok,
            'driver' => 'neuron-ai',
            'module' => $module,
            'vendor_present' => $agentSrc,
            'composer_file' => $composer,
            'composer_installed' => $installed,
            'upstream_skills' => count($skills),
            'hint' => $ok
                ? ($installed ? 'Ready.' : 'Vendored. Run: composer require neuron-core/neuron-ai')
                : 'Missing vendor: git clone ' . self::REPO . ' ' . self::VENDOR,
        ];
    }

    private static function frontmatter(string $file): array
    {
        $out = ['name' => basename(dirname($file)), 'description' => ''];
        $raw = @file_get_contents($file);
        if (!is_string($raw) || !str_starts_with($raw, '---')) return $out;
        $end = strpos($raw, "\n---", 3);
        $fm = $end === false ? '' : substr($raw, 3, $end - 3);
        foreach (['name', 'description'] as $k) {
            if (preg_match('/^' . $k . ':\s*(.+)$/m', $fm, $m)) {
                $out[$k] = trim(trim($m[1]), "\"'");
            }
        }
        return $out;
    }

    public static function catalog(): array
    {
        $dir = self::skillsDir();
        if (!is_dir($dir)) return [];
        $out = [];
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') continue;
            $f = $dir . '/' . $name . '/SKILL.md';
            if (is_file($f)) {
                $fm = self::frontmatter($f);
                $out[] = ['id' => $name, 'name' => $fm['name'] ?: $name,
                          'description' => mb_substr($fm['description'], 0, 300)];
            }
        }
        usort($out, fn($a, $b) => strcmp($a['id'], $b['id']));
        return $out;
    }

    public static function search(string $query, int $limit = 10): array
    {
        $words = array_values(array_filter(preg_split('/\s+/', mb_strtolower(trim($query))),
            fn($w) => mb_strlen($w) > 2));
        if (!$words) $words = [mb_strtolower($query)];
        $hits = [];
        foreach (self::catalog() as $s) {
            $hay = mb_strtolower($s['id'] . ' ' . $s['name'] . ' ' . $s['description']);
            $score = 0;
            foreach ($words as $w) if (str_contains($hay, $w)) $score += mb_strlen($w);
            if ($score > 0) $hits[] = ['score' => $score] + $s;
        }
        usort($hits, fn($a, $b) => $b['score'] <=> $a['score']);
        return ['ok' => true, 'hits' => array_slice($hits, 0, max(1, min($limit, 30)))];
    }

    public static function get(string $skillId): array
    {
        $skillId = trim($skillId);
        if ($skillId === '' || str_contains($skillId, '..') || str_contains($skillId, '/')) {
            return ['ok' => false, 'error' => 'invalid skill id'];
        }
        $f = self::skillsDir() . '/' . $skillId . '/SKILL.md';
        if (!is_file($f)) return ['ok' => false, 'error' => 'skill not found'];
        $body = (string)@file_get_contents($f);
        if (str_starts_with($body, '---')) {
            $end = strpos($body, "\n---");
            if ($end !== false) $body = ltrim(substr($body, $end + 4));
        }
        return ['ok' => true, 'id' => $skillId, 'content' => $body, 'chars' => mb_strlen($body)];
    }

    /** Providers shipped in the vendored checkout (adapter dir names). */
    public static function providers(): array
    {
        $base = self::vendorRoot() . '/src/Providers';
        if (!is_dir($base)) return ['ok' => false, 'providers' => []];
        $out = [];
        foreach (scandir($base) ?: [] as $n) {
            if ($n === '.' || $n === '..') continue;
            if (is_dir($base . '/' . $n)) $out[] = $n;
        }
        sort($out);
        return ['ok' => count($out) > 0, 'providers' => $out];
    }
}

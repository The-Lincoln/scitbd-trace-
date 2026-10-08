<?php
/**
 * SCCRM Neuron CEO AI agent — NeuronAI-powered CEO reasoning for PHP.
 *
 * Upstream: external/neuron-ai (https://github.com/neuron-core/neuron-ai.git)
 * Canonical service: autoflows/app/services/NeuronAi.php
 * Python mirror: ceo/neuron_ai.py
 * Skill: ceo/.agents/skills/neuron-ai/SKILL.md (canonical)
 *
 *   $st  = neuronAgentStatus();                 // vendor + composer readiness
 *   $res = neuronAgentAsk($db, 'Summarise Block 2 risks'); // ['ok','text','driver']
 *   $ex  = neuronAgentExtract($db, $notes, $schema);       // structured JSON
 *
 * Strategy (offline-safe, never throws):
 *   1. If vendor/autoload + NeuronAI\Agent\Agent exists AND a provider key is
 *      configured (ANTHROPIC_/OPENAI_API_KEY), run a minimal Neuron agent.
 *   2. Otherwise fall back to local TinyLLM (aiChatReply) so CEO workflows
 *      keep working without keys or composer install.
 * Human-in-the-loop: tool-approval / deferred results map to CEO review —
 * never auto-execute lead/CRM side effects; return proposals for approval.
 */
require_once __DIR__ . '/ai_bootstrap.php';

function neuronAgentStatus(): array
{
    try {
        require_once dirname(__DIR__, 2) . '/autoflows/app/services/NeuronAi.php';
        if (class_exists('NeuronAi')) {
            $s = NeuronAi::status('sccrm-ceo');
            $s['bridge'] = 'sccrm/ai/neuron_agent.php';
            return $s;
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'driver' => 'neuron-ai', 'error' => $e->getMessage()];
    }
    // Fallback probe when canonical service is missing.
    $root = dirname(__DIR__, 2) . '/external/neuron-ai';
    $ok = is_dir($root . '/src/Agent');
    return ['ok' => $ok, 'driver' => 'neuron-ai',
            'hint' => $ok ? 'Vendored (service missing).' : 'Missing vendor checkout.'];
}

function neuronAgentHasProvider(): bool
{
    foreach (['ANTHROPIC_API_KEY', 'OPENAI_API_KEY', 'NEURON_API_KEY'] as $k) {
        if (getenv($k) !== false && getenv($k) !== '') return true;
    }
    return false;
}

/**
 * Ask the CEO agent a question. Returns ['ok','text','driver','provider'].
 * Never throws; falls back to TinyLLM when Neuron is not runnable.
 */
function neuronAgentAsk($db, string $question, string $system = ''): array
{
    $question = trim($question);
    if ($question === '') return ['ok' => false, 'driver' => 'neuron-ai', 'error' => 'empty question'];
    // Attempt native Neuron path only when autoloadable + key present.
    try {
        $autoloads = [
            dirname(__DIR__, 2) . '/vendor/autoload.php',
            dirname(__DIR__, 2) . '/external/neuron-ai/vendor/autoload.php',
        ];
        foreach ($autoloads as $al) {
            if (is_file($al)) { require_once $al; break; }
        }
        if (class_exists('NeuronAI\\Agent\\Agent') && neuronAgentHasProvider()) {
            // Minimal inline agent: subclasses must implement provider()+instructions().
            // We use eval-free anonymous class via a temp file-free approach:
            // if the host app defines ScitbdNeuronCeoAgent, prefer it.
            if (class_exists('ScitbdNeuronCeoAgent')) {
                $agent = new ScitbdNeuronCeoAgent();
                $resp = $agent->chat($question);
                $hasContent = (is_object($resp) && method_exists($resp, 'getContent'));
                $text = $hasContent ? (string)$resp->getContent() : (string)$resp;
                if (trim($text) !== '') {
                    return ['ok' => true, 'text' => trim($text), 'driver' => 'neuron-ai', 'provider' => 'neuron'];
                }
            }
            // Provider present but no concrete agent class yet — report actionable next step.
            return ['ok' => false, 'driver' => 'neuron-ai', 'error' => 'Define ScitbdNeuronCeoAgent (see SKILL.md neuron-agent)'];
        }
    } catch (Throwable $e) {
        // fall through to TinyLLM
    }
    try {
        $sys = $system !== '' ? $system : 'You are the SCITBD CEO AI agent. Be concise, decisive, BST-aware (Asia/Dhaka). ';
        $res = aiChatReply([
            ['role' => 'system', 'content' => $sys . aiAlignedContext($db)],
            ['role' => 'user', 'content' => $question],
        ]);
        $text = trim($res['content'] ?? '');
        if ($text === '') return ['ok' => false, 'driver' => 'tinyllm-fallback', 'error' => 'Empty model reply'];
        return ['ok' => true, 'text' => $text, 'driver' => 'tinyllm-fallback', 'provider' => $res['provider'] ?? 'tinyllm'];
    } catch (Throwable $e) {
        return ['ok' => false, 'driver' => 'tinyllm-fallback', 'error' => $e->getMessage()];
    }
}

/**
 * Structured extraction (lead/ticket JSON). Falls back to free-text when
 * neuron-structured-output is not runnable. $schema = JSON-schema array.
 */
function neuronAgentExtract($db, string $notes, array $schema = []): array
{
    $r = neuronAgentAsk($db, "Extract the following into JSON per this schema:\n" . json_encode($schema) . "\n\nNotes:\n" . $notes,
        'You are a precise CRM extractor. Output valid JSON only. No invented fields.');
    if (empty($r['ok'])) return $r;
    $txt = $r['text'];
    // Pull first {...} block if the model added prose.
    $found = preg_match('/\{.*\}/s', $txt, $m);
    if ($found) {
        $j = json_decode($m[0], true);
        if ($j !== null) {
            return ['ok' => true, 'data' => $j, 'driver' => $r['driver']];
        }
    }
    $j2 = json_decode($txt, true);
    if ($j2 !== null) {
        return ['ok' => true, 'data' => $j2, 'driver' => $r['driver']];
    }
    return ['ok' => false, 'driver' => $r['driver'], 'error' => 'Non-JSON reply', 'text' => mb_substr($txt, 0, 500)];
}

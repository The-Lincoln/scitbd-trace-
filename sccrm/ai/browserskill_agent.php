<?php
/**
 * SCCRM BrowserSkill AI agent — lead-website intel via Tencent `bsk`.
 * Companion to sccrm/ai/browser_skill.php (vercel agent-browser driver).
 *
 *   $res = browserskillAgentResearch($db, $company, $website, $goal);
 * Never throws: failures return ['ok'=>false,'error'=>...].
 */
require_once __DIR__ . '/ai_bootstrap.php';
require_once dirname(__DIR__) . '/services/BrowserSkillService.php';

function browserskillAgentAvailable(): bool
{
    try {
        return class_exists('BrowserSkill') && BrowserSkill::isAvailable();
    } catch (Throwable $e) {
        return false;
    }
}

/** Research a lead site; logs to interactions when $db provided. */
function browserskillAgentResearch($db, string $company, string $website, string $goal = ''): array
{
    $t0 = microtime(true);
    try {
        if (!browserskillAgentAvailable()) {
            return ['ok' => false, 'driver' => 'bsk', 'error' => 'bsk unavailable (install CLI + extension, run bsk doctor)', 'ms' => 0];
        }
        $res = BrowserSkillService::researchLeadWebsite($company, $website, $goal);
        $ms = (int)round((microtime(true) - $t0) * 1000);
        $res['ms'] = $ms;
        if (!empty($res['ok']) && $db instanceof PDO) {
            try {
                BrowserSkillService::ensureTables($db);
                BrowserSkillService::logRun($db, 'research', $website, true, $ms, mb_substr($res['report'] ?? '', 0, 800));
            } catch (Throwable $e) {
            }
        }
        return $res;
    } catch (Throwable $e) {
        return ['ok' => false, 'driver' => 'bsk', 'error' => $e->getMessage(), 'ms' => (int)round((microtime(true) - $t0) * 1000)];
    }
}

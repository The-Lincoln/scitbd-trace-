<?php
/**
 * SCCRM OpenMontage AI agent — video production intake for campaigns/leads.
 * Companion to sccrm/ai/tooljet_agent.php (dashboards) and
 * sccrm/ai/evolution_agent.php (WhatsApp).
 *
 *   $st  = openmontageAgentStatus();
 *   $res = openmontageAgentRequest($db, 'Acme launch teaser', $brief, 'cinematic');
 * Never throws: failures return ['ok'=>false,'error'=>...].
 */
require_once __DIR__ . '/ai_bootstrap.php';
require_once dirname(__DIR__) . '/services/OpenMontageService.php';

function openmontageAgentStatus(): array
{
    try {
        return OpenMontageService::status() + ['driver' => 'openmontage'];
    } catch (Throwable $e) {
        return ['ok' => false, 'driver' => 'openmontage', 'error' => $e->getMessage()];
    }
}

function openmontageAgentRequest($db, string $title, string $brief, string $pipeline = 'animated-explainer', array $opts = []): array
{
    try {
        $r = OpenMontageService::requestVideo($title, $brief, $pipeline, $opts);
        return $r + ['driver' => 'openmontage'];
    } catch (Throwable $e) {
        return ['ok' => false, 'driver' => 'openmontage', 'error' => $e->getMessage()];
    }
}

function openmontageAgentPreflight(): array
{
    try {
        return OpenMontageService::preflight() + ['driver' => 'openmontage'];
    } catch (Throwable $e) {
        return ['ok' => false, 'driver' => 'openmontage', 'error' => $e->getMessage()];
    }
}

function openmontageAgentView(string $slug): array
{
    try {
        $r = OpenMontageService::view($slug);
        return $r === null ? ['ok' => false, 'driver' => 'openmontage', 'error' => 'Unknown production'] : $r + ['ok' => true, 'driver' => 'openmontage'];
    } catch (Throwable $e) {
        return ['ok' => false, 'driver' => 'openmontage', 'error' => $e->getMessage()];
    }
}

function openmontageAgentEdit(string $slug, array $fields): array
{
    try {
        return OpenMontageService::edit($slug, $fields) + ['driver' => 'openmontage'];
    } catch (Throwable $e) {
        return ['ok' => false, 'driver' => 'openmontage', 'error' => $e->getMessage()];
    }
}

function openmontageAgentDelete(string $slug): array
{
    try {
        return OpenMontageService::remove($slug) + ['driver' => 'openmontage'];
    } catch (Throwable $e) {
        return ['ok' => false, 'driver' => 'openmontage', 'error' => $e->getMessage()];
    }
}

<?php
/**
 * SCCRM — ScientificSkills service shim (layer 2 of 3: SCCRM agents).
 * Canonical logic: autoflows/app/services/ScientificSkills.php
 * CEO mirror: ceo/scientific_skills.py
 */
if (!class_exists('ScientificSkills')) {
    $canon = dirname(__DIR__, 2) . '/autoflows/app/services/ScientificSkills.php';
    if (is_file($canon)) {
        require_once $canon;
    }
}

final class ScientificSkillsService
{
    public static function status(): array
    {
        return ScientificSkills::status();
    }

    /** Recommend skills for a research/marketing task. */
    public static function recommend(string $task, int $limit = 5): array
    {
        return ScientificSkills::search($task, $limit);
    }

    /** Load a skill body for prompt injection (+ usage log when $db given). */
    public static function load(string $id, $db = null): array
    {
        $t0 = microtime(true);
        $r = ScientificSkills::get($id);
        if ($db instanceof PDO) {
            ScientificSkills::logRun($db, 'sccrm', 'get', $id, !empty($r['ok']), (int)round((microtime(true) - $t0) * 1000), mb_substr($r['name'] ?? '', 0, 200));
        }
        return $r;
    }

    public static function ensureTables(PDO $db): void
    {
        ScientificSkills::ensureTables($db);
    }
}

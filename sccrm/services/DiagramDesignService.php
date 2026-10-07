<?php
/**
 * SCCRM — DiagramDesign service shim (layer 2 of 3: SCCRM agents).
 * Canonical logic: autoflows/app/services/DiagramDesign.php
 * CEO mirror: ceo/diagram_design.py
 */
if (!class_exists('DiagramDesign')) {
    $canon = dirname(__DIR__, 2) . '/autoflows/app/services/DiagramDesign.php';
    if (is_file($canon)) {
        require_once $canon;
    }
}

final class DiagramDesignService
{
    public static function status(): array
    {
        return DiagramDesign::status();
    }

    public static function types(): array
    {
        return DiagramDesign::listTypes();
    }

    /** Scaffold a proposal/report visual (agent draws per type reference). */
    public static function requestDiagram(string $title, string $type): array
    {
        return DiagramDesign::scaffold($title, $type, 'template', 'sccrm');
    }

    public static function ensureTables(PDO $db): void
    {
        DiagramDesign::ensureTables($db);
    }
}

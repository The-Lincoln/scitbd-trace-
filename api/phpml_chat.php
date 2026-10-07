<?php
/**
 * phpML Chat API Endpoint
 * Provides ML-powered chat responses using the phpML library
 */

header("Content-Type: application/json");
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../includes/db.php";
require_once __DIR__ . "/../includes/functions.php";
require_once __DIR__ . "/../vendor/autoload.php";
require_once __DIR__ . "/../phpML/ChatEngine.php";

use PHPML\ChatEngine;

$input = json_decode(file_get_contents("php://input"), true) ?? [];
$message = trim($input["message"] ?? $_GET["message"] ?? "");

if (empty($message)) {
    echo json_encode(["success" => false, "error" => "Message is required"]);
    exit;
}

try {
    $engine = new ChatEngine();
    $response = $engine->chat($message);
    
    echo json_encode([
        "success" => true,
        "response" => $response,
        "timestamp" => date("Y-m-d H:i:s"),
        "engine" => "phpML Chat Engine",
        "engine_version" => "1.0.0",
        "models_trained" => true,
        "models" => $engine->getModelInfo(),
        "skills" => $engine->getSkillsInfo(),
        "skills_summary" => ($engine->getSkillsInfo()['loaded'] ?? false) ? "Skills engine active with {$engine->getSkillsInfo()['total_skills']} skills across " . count($engine->getSkillsInfo()['groups'] ?? []) . " groups" : "Skills engine not loaded"
    ]);
} catch (\Throwable $e) {
    echo json_encode([
        "success" => false,
        "error" => "phpML Chat Error: " . $e->getMessage(),
        "timestamp" => date("Y-m-d H:i:s"),
        "skills_loaded" => false
    ]);
}
exit;

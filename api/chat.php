<?php
header("Content-Type: application/json");
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../includes/db.php";
require_once __DIR__ . "/../includes/functions.php";
require_once __DIR__ . "/../includes/ai_agent.php";

$input = json_decode(file_get_contents("php://input"), true) ?? [];
$message = trim($input["message"] ?? $_GET["message"] ?? "");

if (empty($message)) {
    echo json_encode(["success" => false, "error" => "Message is required"]);
    exit;
}

$agent = new OSINTAgent();
$response = $agent->chat($message);

echo json_encode(["success" => true, "response" => $response, "timestamp" => date("Y-m-d H:i:s"), "agent_version" => "1.0.0"]);
exit;
?>

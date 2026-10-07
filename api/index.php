<?php
header("Content-Type: application/json");
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../includes/db.php";
require_once __DIR__ . "/../includes/functions.php";
require_once __DIR__ . "/../includes/ai_agent.php";

$action = $_GET["action"] ?? "";
$agent = new OSINTAgent();

switch ($action) {
    case "chat":
        $message = trim($_GET["message"] ?? "");
        if (empty($message)) { echo json_encode(["success" => false, "error" => "Message required"]); exit; }
        echo json_encode(["success" => true, "response" => $agent->chat($message)]);
        break;
    case "tools":
        echo json_encode(["success" => true, "data" => $agent->getAllTools(), "count" => count($agent->getAllTools())]);
        break;
    case "categories":
        echo json_encode(["success" => true, "data" => $agent->getAllCategories()]);
        break;
    case "category_tree":
        echo json_encode(["success" => true, "data" => $agent->getCategoryTree()]);
        break;
    case "search":
        $q = trim($_GET["q"] ?? "");
        $res = $agent->searchToolsPublic($q);
        echo json_encode(["success" => true, "data" => $res, "count" => substr_count($res, "\n")]);
        break;
    case "stats":
        echo json_encode(["success" => true, "data" => ["total_tools" => count($agent->getAllTools()), "total_categories" => count($agent->getAllCategories())]]);
        break;
    default:
        echo json_encode(["success" => false, "error" => "Valid action required"]);
}
exit;
?>

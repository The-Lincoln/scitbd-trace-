<?php
// SCITBD AI CEO Agent Backend Engine - BST 24-Hour Autonomous Framework
// Updated with Full Task Management Routes
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    $db = new PDO('sqlite:' . __DIR__ . '/scitbd_ceo.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(["status" => "error", "message" => "Database Connection Failed"]);
    exit();
}

$action = $_GET['action'] ?? '';
$requestMethod = $_SERVER['REQUEST_METHOD'];
$requestUri = $_SERVER['REQUEST_URI'];
$requestPath = parse_url($requestUri, PHP_URL_PATH);

function getCurrentBSTBlock($db) {
    $tz = new DateTimeZone('Asia/Dhaka');
    $now = new DateTime('now', $tz);
    $currentHour = (int)$now->format('H');
    $currentMinute = (int)$now->format('i');
    $currentTime = str_pad($currentHour, 2, '0', STR_PAD_LEFT) . ':' . str_pad($currentMinute, 2, '0', STR_PAD_LEFT);

    $blocks = $db->query("SELECT * FROM operational_blocks ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($blocks as $block) {
        $start = $block['bst_start'];
        $end = $block['bst_end'];
        if ($start < $end) {
            if ($currentTime >= $start && $currentTime < $end) {
                return $block;
            }
        } else {
            if ($currentTime >= $start || $currentTime < $end) {
                return $block;
            }
        }
    }
    return null;
}

function markBlockActive($db, $blockId) {
    $db->exec("UPDATE operational_blocks SET status = 'INACTIVE'");
    $stmt = $db->prepare("UPDATE operational_blocks SET status = 'ACTIVE' WHERE id = ?");
    $stmt->execute([$blockId]);
}

// ============================================================
// TASK MANAGEMENT ROUTES
// ============================================================

// GET /tasks - List all tasks
if ($requestPath === '/tasks' && $requestMethod === 'GET') {
    $status = $_GET['status'] ?? '';
    $priority = $_GET['priority'] ?? '';
    $category = $_GET['category'] ?? '';
    $bst_block_id = $_GET['bst_block_id'] ?? '';
    $query = "SELECT * FROM daily_tasks WHERE 1=1";
    $params = [];
    if ($status) { $query .= " AND status = ?"; $params[] = $status; }
    if ($priority) { $query .= " AND priority = ?"; $params[] = $priority; }
    if ($category) { $query .= " AND category = ?"; $params[] = $category; }
    if ($bst_block_id) { $query .= " AND bst_block_id = ?"; $params[] = $bst_block_id; }
    $query .= " ORDER BY CASE priority WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END, due_date ASC";
    try {
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        echo json_encode(["status" => "success", "data" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (PDOException $e) {
        echo json_encode(["status" => "error", "message" => "Failed to fetch tasks"]);
    }
    exit();
}

// GET /tasks/{id} - Get specific task
if (preg_match('#^/tasks/(\d+)$#', $requestPath, $matches) && $requestMethod === 'GET') {
    $taskId = (int)$matches[1];
    try {
        $stmt = $db->prepare("SELECT * FROM daily_tasks WHERE id = ?");
        $stmt->execute([$taskId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            echo json_encode(["status" => "success", "data" => $row]);
        } else {
            echo json_encode(["status" => "error", "message" => "Task not found"]);
        }
    } catch (PDOException $e) {
        echo json_encode(["status" => "error", "message" => "Failed to fetch task"]);
    }
    exit();
}

// POST /tasks - Create new task
if ($requestPath === '/tasks' && $requestMethod === 'POST') {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);
    if (!$data || !isset($data['task_title'])) {
        echo json_encode(["status" => "error", "message" => "Task title is required"]);
        exit();
    }
    $validCategories = ['sales','marketing','development','operations','client','admin','ai','general'];
    $category = in_array($data['category'] ?? '', $validCategories) ? $data['category'] : 'general';
    $validPriorities = ['low','medium','high','critical'];
    $priority = in_array($data['priority'] ?? '', $validPriorities) ? $data['priority'] : 'medium';
    try {
        $now = date('Y-m-d H:i:s');
        $stmt = $db->prepare("INSERT INTO daily_tasks (task_title, task_description, priority, category, assignee, due_date, estimated_hours, bst_block_id, related_lead_id, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $data['task_title'],
            $data['task_description'] ?? '',
            $priority,
            $category,
            $data['assignee'] ?? 'ceo',
            $data['due_date'] ?? null,
            $data['estimated_hours'] ?? 1.0,
            $data['bst_block_id'] ?? null,
            $data['related_lead_id'] ?? null,
            'toolbar_agent',
            $now,
            $now
        ]);
        $taskId = $db->lastInsertId();
        $stmt2 = $db->prepare("INSERT INTO task_logs (task_id, action, performed_by, details) VALUES (?, 'created', 'ai_agent', 'Task created via toolbar')");
        $stmt2->execute([$taskId]);
        $stmt3 = $db->prepare("SELECT * FROM daily_tasks WHERE id = ?");
        $stmt3->execute([$taskId]);
        echo json_encode(["status" => "success", "data" => $stmt3->fetch(PDO::FETCH_ASSOC), "task_id" => $taskId]);
    } catch (PDOException $e) {
        echo json_encode(["status" => "error", "message" => "Failed to create task: " . $e->getMessage()]);
    }
    exit();
}

// PUT /tasks/{id} - Update task
if (preg_match('#^/tasks/(\d+)$#', $requestPath, $matches) && $requestMethod === 'PUT') {
    $taskId = (int)$matches[1];
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);
    if (!$data) {
        echo json_encode(["status" => "error", "message" => "No data provided"]);
        exit();
    }
    try {
        $updates = [];
        $params = [];
        $allowed = ['status', 'task_title', 'task_description', 'priority', 'category', 'assignee', 'due_date', 'estimated_hours', 'bst_block_id'];
        foreach ($allowed as $field) {
            if (isset($data[$field])) {
                $updates[] = "$field = ?";
                $params[] = $data[$field];
            }
        }
        if (empty($updates)) {
            echo json_encode(["status" => "error", "message" => "No updates provided"]);
            exit();
        }
        $updates[] = "updated_at = ?";
        $params[] = date('Y-m-d H:i:s');
        if ($data['status'] === 'completed') {
            $updates[] = "completed_at = ?";
            $params[] = date('Y-m-d H:i:s');
        }
        $params[] = $taskId;
        $sql = "UPDATE daily_tasks SET " . implode(', ', $updates) . " WHERE id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $stmt2 = $db->prepare("INSERT INTO task_logs (task_id, action, performed_by, details) VALUES (?, 'updated', 'ai_agent', 'Task updated via toolbar')");
        $stmt2->execute([$taskId]);
        $stmt3 = $db->prepare("SELECT * FROM daily_tasks WHERE id = ?");
        $stmt3->execute([$taskId]);
        echo json_encode(["status" => "success", "data" => $stmt3->fetch(PDO::FETCH_ASSOC)]);
    } catch (PDOException $e) {
        echo json_encode(["status" => "error", "message" => "Failed to update task"]);
    }
    exit();
}

// DELETE /tasks/{id} - Delete task
if (preg_match('#^/tasks/(\d+)$#', $requestPath, $matches) && $requestMethod === 'DELETE') {
    $taskId = (int)$matches[1];
    try {
        $db->prepare("DELETE FROM task_logs WHERE task_id = ?")->execute([$taskId]);
        $db->prepare("DELETE FROM daily_tasks WHERE id = ?")->execute([$taskId]);
        echo json_encode(["status" => "success", "message" => "Task deleted"]);
    } catch (PDOException $e) {
        echo json_encode(["status" => "error", "message" => "Failed to delete task"]);
    }
    exit();
}

// POST /tasks/{id}/complete - Complete task
if (preg_match('#^/tasks/(\d+)/complete$#', $requestPath, $matches) && $requestMethod === 'POST') {
    $taskId = (int)$matches[1];
    try {
        $db->prepare("UPDATE daily_tasks SET status = 'completed', completed_at = ?, updated_at = ? WHERE id = ?")->execute([date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $taskId]);
        echo json_encode(["status" => "success", "message" => "Task completed"]);
    } catch (PDOException $e) {
        echo json_encode(["status" => "error", "message" => "Failed to complete task"]);
    }
    exit();
}

// POST /tasks/{id}/start - Start task
if (preg_match('#^/tasks/(\d+)/start$#', $requestPath, $matches) && $requestMethod === 'POST') {
    $taskId = (int)$matches[1];
    try {
        $db->prepare("UPDATE daily_tasks SET status = 'in_progress', updated_at = ? WHERE id = ?")->execute([date('Y-m-d H:i:s'), $taskId]);
        echo json_encode(["status" => "success", "message" => "Task started"]);
    } catch (PDOException $e) {
        echo json_encode(["status" => "error", "message" => "Failed to start task"]);
    }
    exit();
}

// ============================================================
// TOOLBAR ENDPOINTS
// ============================================================

// GET /toolbar/data
if ($requestPath === '/toolbar/data' && $requestMethod === 'GET') {
    try {
        $now = new DateTime('now', new DateTimeZone('Asia/Dhaka'));
        $bstHour = (int)$now->format('H') + 6;
        if ($bstHour >= 24) $bstHour -= 24;
        $bstTime = $now->format('H:i');
        $activeBlock = getCurrentBSTBlock($db);

        $stmt = $db->query("SELECT * FROM daily_tasks ORDER BY CASE priority WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END, due_date ASC");
        $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt2 = $db->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) as completed, SUM(CASE WHEN status='in_progress' THEN 1 ELSE 0 END) as in_progress, SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) as pending, SUM(CASE WHEN status='blocked' THEN 1 ELSE 0 END) as blocked, COALESCE(SUM(actual_hours), 0) as total_hours FROM daily_tasks WHERE DATE(created_at) = ?");
        $stmt2->execute([date('Y-m-d')]);
        $summary = $stmt2->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            "status" => "success",
            "data" => [
                "active_bst_block" => $activeBlock ? array_merge($activeBlock, ["current_bst_time" => $bstTime, "active" => true]) : ["block_id" => null, "current_bst_time" => $bstTime, "active" => false],
                "tasks" => $tasks,
                "summary" => [
                    "date" => date('Y-m-d'),
                    "total_tasks" => $summary['total'] ?? 0,
                    "completed_tasks" => $summary['completed'] ?? 0,
                    "in_progress_tasks" => $summary['in_progress'] ?? 0,
                    "pending_tasks" => $summary['pending'] ?? 0,
                    "blocked_tasks" => $summary['blocked'] ?? 0,
                    "total_hours_worked" => $summary['total_hours'] ?? 0.0,
                    "progress_percent" => round(($summary['completed'] ?? 0) / max(($summary['total'] ?? 1), 1) * 100, 1)
                ],
                "timestamp" => date('c')
            ]
        ]);
    } catch (PDOException $e) {
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
    exit();
}

// POST /toolbar/chat
if ($requestPath === '/toolbar/chat' && $requestMethod === 'POST') {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);
    $prompt = $data['prompt'] ?? '';
    if (empty($prompt)) {
        echo json_encode(["status" => "error", "response" => "Prompt is required"]);
        exit();
    }
    $activeBlock = getCurrentBSTBlock($db);
    $blockInfo = $activeBlock ? "Current BST Block: {$activeBlock['block_name']} ({$activeBlock['bst_start']}-{$activeBlock['bst_end']} BST)" : "No active BST block";
    $response = "🤖 **SCITBD Task Management Agent**\n\n{$blockInfo}\n\nYou asked: *{$prompt}*\n\n";
    if (stripos($prompt, 'create') !== false || stripos($prompt, 'add task') !== false) {
        $response .= "To create a task, use the **Create** tab or send a message like:\n`Create a high priority task for Block 2`\n";
    } elseif (stripos($prompt, 'list') !== false || stripos($prompt, 'what are my') !== false || stripos($prompt, 'show') !== false) {
        $stmt = $db->query("SELECT COUNT(*) as total FROM daily_tasks");
        $total = $stmt->fetchColumn();
        $stmt2 = $db->query("SELECT COUNT(*) as completed FROM daily_tasks WHERE status='completed'");
        $completed = $stmt2->fetchColumn();
        $response .= "You have **{$total} tasks** total, **{$completed} completed**.\n\nUse the **Tasks** tab to view all tasks.\n";
    } elseif (stripos($prompt, 'summary') !== false) {
        $stmt = $db->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) as completed, SUM(CASE WHEN status='in_progress' THEN 1 ELSE 0 END) as in_progress, SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) as pending, COALESCE(SUM(actual_hours), 0) as total_hours FROM daily_tasks WHERE DATE(created_at) = ?");
        $stmt->execute([date('Y-m-d')]);
        $s = $stmt->fetch(PDO::FETCH_ASSOC);
        $response .= "**Daily Summary ({$s['total']} tasks):**\n- ✅ Completed: {$s['completed']}\n- 🔄 In Progress: {$s['in_progress']}\n- ⏳ Pending: {$s['pending']}\n- 🕐 Hours Worked: {$s['total_hours']}h\n";
    } elseif (stripos($prompt, 'block') !== false) {
        $response .= "{$blockInfo}\n\nUse the **Dashboard** tab to see the full BST timeline.\n";
    } else {
        $response .= "I can help you with:\n- **Create tasks** - Use the Create tab or ask me\n- **View tasks** - Check the Tasks tab\n- **Complete tasks** - Use the task detail view\n- **Daily summaries** - Check the Summary tab\n- **BST blocks** - See the Dashboard tab\n";
    }
    echo json_encode(["status" => "success", "data" => ["response" => $response]]);
    exit();
}

// GET /toolbar/chat
if ($requestPath === '/toolbar/chat' && $requestMethod === 'GET') {
    $prompt = $_GET['prompt'] ?? '';
    if (empty($prompt)) {
        echo json_encode(["status" => "success", "data" => ["response" => "Hello! I'm the SCITBD AI Task Manager. Ask me anything about tasks."]]);
        exit();
    }
    $activeBlock = getCurrentBSTBlock($db);
    $blockInfo = $activeBlock ? "Current BST Block: {$activeBlock['block_name']} ({$activeBlock['bst_start']}-{$activeBlock['bst_end']} BST)" : "No active BST block";
    $response = "🤖 **SCITBD Task Management Agent**\n\n{$blockInfo}\n\nYou asked: *{$prompt}*\n\n";
    if (stripos($prompt, 'create') !== false || stripos($prompt, 'add task') !== false) {
        $response .= "To create a task, use the **Create** tab or send a message like:\n`Create a high priority task for Block 2`\n";
    } elseif (stripos($prompt, 'list') !== false || stripos($prompt, 'what are my') !== false || stripos($prompt, 'show') !== false) {
        $stmt = $db->query("SELECT COUNT(*) as total FROM daily_tasks");
        $total = $stmt->fetchColumn();
        $stmt2 = $db->query("SELECT COUNT(*) as completed FROM daily_tasks WHERE status='completed'");
        $completed = $stmt2->fetchColumn();
        $response .= "You have **{$total} tasks** total, **{$completed} completed**.\n\nUse the **Tasks** tab to view all tasks.\n";
    } elseif (stripos($prompt, 'summary') !== false) {
        $stmt = $db->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) as completed, SUM(CASE WHEN status='in_progress' THEN 1 ELSE 0 END) as in_progress, SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) as pending, COALESCE(SUM(actual_hours), 0) as total_hours FROM daily_tasks WHERE DATE(created_at) = ?");
        $stmt->execute([date('Y-m-d')]);
        $s = $stmt->fetch(PDO::FETCH_ASSOC);
        $response .= "**Daily Summary ({$s['total']} tasks):**\n- ✅ Completed: {$s['completed']}\n- 🔄 In Progress: {$s['in_progress']}\n- ⏳ Pending: {$s['pending']}\n- 🕐 Hours Worked: {$s['total_hours']}h\n";
    } elseif (stripos($prompt, 'block') !== false) {
        $response .= "{$blockInfo}\n\nUse the **Dashboard** tab to see the full BST timeline.\n";
    } else {
        $response .= "I can help you with:\n- **Create tasks** - Use the Create tab or ask me\n- **View tasks** - Check the Tasks tab\n- **Complete tasks** - Use the task detail view\n- **Daily summaries** - Check the Summary tab\n- **BST blocks** - See the Dashboard tab\n";
    }
    echo json_encode(["status" => "success", "data" => ["response" => $response]]);
    exit();
}

// POST /toolbar/task
if ($requestPath === '/toolbar/task' && $requestMethod === 'POST') {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);
    $title = $data['title'] ?? '';
    if (empty($title)) {
        echo json_encode(["status" => "error", "message" => "Task title is required"]);
        exit();
    }
    try {
        $now = date('Y-m-d H:i:s');
        $stmt = $db->prepare("INSERT INTO daily_tasks (task_title, task_description, priority, category, assignee, due_date, estimated_hours, bst_block_id, related_lead_id, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $title,
            $data['description'] ?? '',
            $data['priority'] ?? 'medium',
            $data['category'] ?? 'general',
            'ceo',
            $data['due_date'] ?? null,
            $data['estimated_hours'] ?? 1.0,
            $data['bst_block_id'] ?? null,
            $data['related_lead_id'] ?? null,
            'toolbar_agent',
            $now,
            $now
        ]);
        $taskId = $db->lastInsertId();
        echo json_encode(["status" => "success", "data" => ["task_id" => $taskId, "message" => "Task created"]]);
    } catch (PDOException $e) {
        echo json_encode(["status" => "error", "message" => "Failed to create task"]);
    }
    exit();
}

// GET /toolbar/summary
if ($requestPath === '/toolbar/summary' && $requestMethod === 'GET') {
    $date = $_GET['date'] ?? date('Y-m-d');
    try {
        $stmt = $db->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) as completed, SUM(CASE WHEN status='in_progress' THEN 1 ELSE 0 END) as in_progress, SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) as pending, SUM(CASE WHEN status='blocked' THEN 1 ELSE 0 END) as blocked, COALESCE(SUM(actual_hours), 0) as total_hours FROM daily_tasks WHERE DATE(created_at) = ?");
        $stmt->execute([$date]);
        $s = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode([
            "status" => "success",
            "data" => [
                "date" => $date,
                "total_tasks" => $s['total'] ?? 0,
                "completed_tasks" => $s['completed'] ?? 0,
                "in_progress_tasks" => $s['in_progress'] ?? 0,
                "pending_tasks" => $s['pending'] ?? 0,
                "blocked_tasks" => $s['blocked'] ?? 0,
                "total_hours_worked" => $s['total_hours'] ?? 0.0,
                "progress_percent" => round(($s['completed'] ?? 0) / max(($s['total'] ?? 1), 1) * 100, 1)
            ]
        ]);
    } catch (PDOException $e) {
        echo json_encode(["status" => "error", "message" => "Failed to get summary"]);
    }
    exit();
}

// GET /toolbar/blocks
if ($requestPath === '/toolbar/blocks' && $requestMethod === 'GET') {
    try {
        $now = new DateTime('now', new DateTimeZone('Asia/Dhaka'));
        $bstHour = (int)$now->format('H') + 6;
        if ($bstHour >= 24) $bstHour -= 24;
        $bstTime = $now->format('H:i');
        $activeBlock = getCurrentBSTBlock($db);
        echo json_encode([
            "status" => "success",
            "data" => [
                "current_bst_time" => $bstTime,
                "active_block" => $activeBlock,
                "blocks" => [
                    ['id' => 1, 'name' => 'Block 1', 'start' => '06:00', 'end' => '12:00', 'focus' => 'BD/South Asia'],
                    ['id' => 2, 'name' => 'Block 2', 'start' => '12:00', 'end' => '18:00', 'focus' => 'Middle East/EU'],
                    ['id' => 3, 'name' => 'Block 3', 'start' => '18:00', 'end' => '00:00', 'focus' => 'UK/US East Coast'],
                    ['id' => 4, 'name' => 'Block 4', 'start' => '00:00', 'end' => '06:00', 'focus' => 'US West/Oceania']
                ]
            ]
        ]);
    } catch (PDOException $e) {
        echo json_encode(["status" => "error", "message" => "Failed to get blocks"]);
    }
    exit();
}

// ============================================================
// ORIGINAL ENDPOINTS (Preserved)
// ============================================================

// API Route: Submit New Lead & Enforce SLA Rules
if ($action === 'submit_lead' && $requestMethod === 'POST') {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        echo json_encode(["status" => "error", "message" => "Invalid JSON input"]);
        exit();
    }
    $requiredFields = ['client_name', 'email', 'country', 'deal_value', 'service_line'];
    foreach ($requiredFields as $field) {
        if (!isset($data[$field]) || $data[$field] === '') {
            echo json_encode(["status" => "error", "message" => "Missing required field: {$field}"]);
            exit();
        }
    }
    $clientName = trim($data['client_name']);
    $email = trim($data['email']);
    $country = trim($data['country']);
    $dealValue = (float)$data['deal_value'];
    $serviceLine = trim($data['service_line']);
    $currentBlock = getCurrentBSTBlock($db);
    $blockName = $currentBlock ? $currentBlock['block_name'] : 'Unknown Block';
    $blockId = $currentBlock ? $currentBlock['id'] : null;
    try {
        $stmt = $db->prepare("INSERT INTO leads (client_name, email, country, deal_value, service_line) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$clientName, $email, $country, $dealValue, $serviceLine]);
    } catch (PDOException $e) {
        echo json_encode(["status" => "error", "message" => "Failed to insert lead: " . $e->getMessage()]);
        exit();
    }
    $leadId = $db->lastInsertId();
    $escalationTriggered = false;
    if ($dealValue >= 10000) {
        $escalationTriggered = true;
        markBlockActive($db, $blockId);
        $logStmt = $db->prepare("INSERT INTO operational_logs (block_id, block_name, action_taken, status) VALUES (?, ?, ?, ?)");
        $logStmt->execute([$blockId, $blockName, "CEO Video Escalation Protocol triggered for Lead #{$leadId} ({$dealValue} USD)", 'SUCCESS']);
    } else {
        $logStmt = $db->prepare("INSERT INTO operational_logs (block_id, block_name, action_taken, status) VALUES (?, ?, ?, ?)");
        $logStmt->execute([$blockId, $blockName, "Lead #{$leadId} processed in " . ($currentBlock ? $currentBlock['regional_focus'] : 'Unknown') . " window", 'SUCCESS']);
    }
    echo json_encode(["status" => "success", "message" => "Lead processed. Proposal engine dispatched within 2-hour SLA.", "ceo_video_escalation" => $escalationTriggered, "current_block" => $blockName]);
    exit();
}

// API Route: Get Real-Time CEO Intelligence Dashboard Metrics
if ($action === 'get_dashboard') {
    try {
        $leadsCount = $db->query("SELECT COUNT(*) FROM leads")->fetchColumn();
        $totalPipeline = $db->query("SELECT SUM(deal_value) FROM leads")->fetchColumn() ?: 0;
        $recentLogs = $db->query("SELECT * FROM operational_logs ORDER BY executed_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
        $currentBlock = getCurrentBSTBlock($db);
        $blocks = $db->query("SELECT * FROM operational_blocks ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        if ($currentBlock) { markBlockActive($db, $currentBlock['id']); }
    } catch (PDOException $e) {
        echo json_encode(["status" => "error", "message" => "Failed to fetch dashboard data"]);
        exit();
    }
    echo json_encode(["year_end_target" => 1500000, "current_pipeline" => (float)$totalPipeline, "total_leads" => (int)$leadsCount, "current_block" => $currentBlock, "blocks" => $blocks, "logs" => $recentLogs]);
    exit();
}

// API Route: Get All 24-Hour BST Operational Blocks
if ($action === 'get_blocks') {
    try {
        $blocks = $db->query("SELECT * FROM operational_blocks ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $currentBlock = getCurrentBSTBlock($db);
    } catch (PDOException $e) {
        echo json_encode(["status" => "error", "message" => "Failed to fetch blocks"]);
        exit();
    }
    echo json_encode(["status" => "success", "current_block" => $currentBlock, "blocks" => $blocks]);
    exit();
}

// API Route: Get Current BST Block & Time
if ($action === 'get_current_block') {
    try {
        $currentBlock = getCurrentBSTBlock($db);
    } catch (PDOException $e) {
        echo json_encode(["status" => "error", "message" => "Failed to fetch current block"]);
        exit();
    }
    $tz = new DateTimeZone('Asia/Dhaka');
    $now = new DateTime('now', $tz);
    echo json_encode(["status" => "success", "current_time_bst" => $now->format('Y-m-d H:i:s'), "current_block" => $currentBlock]);
    exit();
}

// Default: Invalid action
echo json_encode(["status" => "error", "message" => "Invalid action"]);
?>

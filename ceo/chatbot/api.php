<?php
header('Content-Type: application/json');
require_once 'db.php';

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? $_GET['action'] ?? '';

if ($action === 'get_knowledge_bank') {
    $category = $_GET['category'] ?? '';
    $search = $_GET['search'] ?? '';
    $sql = "SELECT * FROM knowledge_bank";
    $where = [];
    if ($category) $where[] = "category = :category";
    if ($search) {
        $where[] = "(question LIKE :search OR answer LIKE :search)";
    }
    if ($where) $sql .= " WHERE " . implode(' AND ', $where);
    $sql .= " ORDER BY category, question";
    try {
        $stmt = $db->prepare($sql);
        if ($category) $stmt->bindValue(':category', $category);
        if ($search) $stmt->bindValue(':search', '%' . $search . '%');
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'data' => $rows]);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => 'Failed to fetch knowledge bank']);
    }
    exit;
}

if ($action === 'search_knowledge') {
    exit;
}

if ($action === 'add_knowledge') {
    $question = trim($input['question'] ?? '');
    $answer = trim($input['answer'] ?? '');
    $category = trim($input['category'] ?? 'General');
    if (empty($question) || empty($answer)) {
        echo json_encode(['status' => 'error', 'message' => 'Question and answer are required']);
        exit;
    }
    try {
        $stmt = $db->prepare("INSERT INTO knowledge_bank (question, answer, category) VALUES (?, ?, ?)");
        $stmt->execute([$question, $answer, $category]);
        echo json_encode(['status' => 'success', 'id' => $db->lastInsertId()]);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => 'Failed to add entry']);
    }
    exit;
}

if ($action === 'update_knowledge') {
    $id = (int)($input['id'] ?? 0);
    $question = trim($input['question'] ?? '');
    $answer = trim($input['answer'] ?? '');
    $category = trim($input['category'] ?? 'General');
    if (empty($id) || empty($question) || empty($answer)) {
        echo json_encode(['status' => 'error', 'message' => 'ID, question, and answer are required']);
        exit;
    }
    try {
        $stmt = $db->prepare("UPDATE knowledge_bank SET question = ?, answer = ?, category = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$question, $answer, $category, $id]);
        echo json_encode(['status' => 'success', 'updated' => $stmt->rowCount()]);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => 'Failed to update entry']);
    }
    exit;
}

if ($action === 'delete_knowledge') {
    $id = (int)($input['id'] ?? 0);
    if (empty($id)) {
        echo json_encode(['status' => 'error', 'message' => 'ID is required']);
        exit;
    }
    try {
        $stmt = $db->prepare("DELETE FROM knowledge_bank WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['status' => 'success', 'deleted' => $stmt->rowCount()]);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => 'Failed to delete entry']);
    }
    exit;
}

// === CHAT ENGINE ===
$user_msg = trim($input['message'] ?? '');
$session_id = $input['session_id'] ?? session_id();

if (empty($user_msg)) {
    echo json_encode(['status' => 'error', 'response' => 'Message cannot be empty.']);
    exit;
}

$lower_msg = strtolower($user_msg);
$response = "";
$kb_matched = false;

try {
    $stmt = $db->query("SELECT question, answer FROM knowledge_bank");
    $kbEntries = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($kbEntries as $entry) {
        $kbQuestion = strtolower($entry['question']);
        if (stripos($lower_msg, $kbQuestion) !== false || stripos($kbQuestion, substr($lower_msg, 0, 20)) !== false || levenshtein($lower_msg, $kbQuestion) < 5) {
            $response = "<strong>📚 Knowledge Bank:</strong><br><br>" . $entry['answer'];
            $kb_matched = true;
            break;
        }
    }
} catch (PDOException $e) {
    // Continue without KB
}

if (!$kb_matched) {
    if (strpos($lower_msg, 'service') !== false || strpos($lower_msg, 'capabilities') !== false) {
        $response = "SCITBD offers <strong>17 Strategic Capabilities</strong> across 4 core divisions:<br>" .
                    "&bull; <strong>Development:</strong> Websites, Custom ERP/HRM, Mobile Apps (Native/Cross-Platform)<br>" .
                    "&bull; <strong>AI &amp; Data:</strong> GPT-4 Chatbots, Machine Learning, AI Agents, Intelligent Automation<br>" .
                    "&bull; <strong>Security &amp; Strategy:</strong> ICT Consultancy, Cybersecurity WAF, ISO 27001/GDPR Audits<br>" .
                    "&bull; <strong>Growth &amp; Enterprise:</strong> E-Learning LMS, Digital Commerce, SEO Domination, Smart City Tech.<br><br>" .
                    "&uarr; <a href='https://scit.zya.me/?i=1' target='_blank' class='text-success fw-bold'>Explore All 17 Services</a>";
    } elseif (strpos($lower_msg, 'price') !== false || strpos($lower_msg, 'cost') !== false || strpos($lower_msg, 'rate') !== false) {
        $response = "Our Silver Strategic Capability tier starts at <strong>10,000 BDT</strong>. We accept payments in <strong>USD, BDT, and EUR</strong>.<br>" .
                    "We guarantee a custom technical proposal in your inbox within our <strong>sub-2-hour SLA</strong>.<br><br>" .
                    "&uarr; <a href='https://scit.zya.me/booking.php?service=1&amp;mode=proposal' target='_blank' class='text-success fw-bold'>Request a Fast Proposal</a>";
    } elseif (strpos($lower_msg, 'consult') !== false || strpos($lower_msg, 'book') !== false || strpos($lower_msg, 'demo') !== false) {
        $response = "You can schedule a <strong>Free 10-Minute Zoom Consultation</strong> with our technical directors, or submit a project request instantly.<br><br>" .
                    "&uarr; <a href='https://scit.zya.me/consultation.php' target='_blank' class='btn btn-sm btn-outline-success me-1 mt-1'>Book Free Consultation</a> " .
                    "<a href='https://scit.zya.me/booking.php' target='_blank' class='btn btn-sm btn-success text-dark fw-bold mt-1'>Book Service Now</a>";
    } elseif (strpos($lower_msg, 'ai') !== false || strpos($lower_msg, 'bot') !== false || strpos($lower_msg, 'automation') !== false) {
        $response = "SCITBD specializes in <strong>Autonomous AI Agents, GPT-4 Chatbots, and Machine Learning</strong> to unlock 'dark data' and automate up to 70% of manual enterprise workflows.<br>" .
                    "We offer enterprise clients a <strong>Free 30-Day AI Chatbot Trial</strong>.<br><br>" .
                    "&uarr; <a href='https://scit.zya.me/consultation.php' target='_blank' class='text-success fw-bold'>Claim Your 30-Day AI Trial</a>";
    } elseif (strpos($lower_msg, 'contact') !== false || strpos($lower_msg, 'phone') !== false || strpos($lower_msg, 'email') !== false || strpos($lower_msg, 'ceo') !== false) {
        $response = "<strong>SCITBD Headquarters:</strong><br>" .
                    "&nbsp;1st Floor, House-13, Block-E, Green Road, Tangail 1900, Dhaka, Bangladesh<br>" .
                    "&nbsp;<strong>Founder &amp; CEO:</strong> Md Shoeb Lincoln<br>" .
                    "&nbsp;<strong>Phone:</strong> +880 1559-575338<br>" .
                    "&nbsp;<strong>Email:</strong> socialcommunicationit@gmail.com";
    } else {
        $response = "Thank you for reaching out to <strong>SCITBD</strong> (Social Communication IT Bangladesh). We operate 24/7 across 30+ countries delivering enterprise solutions with Silicon Valley precision and Dhaka agility.<br><br>" .
                    "How can I assist you today? You can ask about our <strong>17 Services</strong>, <strong>Pricing</strong>, <strong>AI Solutions</strong>, or book a consultation below:<br><br>" .
                    "<a href='https://scit.zya.me/consultation.php' target='_blank' class='btn btn-sm btn-success text-dark fw-bold'>Free Zoom Consultation</a>";
    }
}

try {
    $stmt = $db->prepare("INSERT INTO chat_logs (session_id, user_message, bot_response) VALUES (:sid, :umsg, :bresp)");
    $stmt->execute([':sid' => $session_id, ':umsg' => $user_msg, ':bresp' => $response]);
} catch (Exception $e) {}

echo json_encode(['status' => 'success', 'response' => $response]);
?>
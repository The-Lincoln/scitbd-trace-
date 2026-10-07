<?php
/**
 * Helper functions for OSINT Framework
 */
require_once __DIR__ . '/../config.php';

/** Get theme from cookie/localStorage; default to DEFAULT_THEME */
function current_theme() {
    return $_COOKIE['theme'] ?? DEFAULT_THEME;
}

/** Get category tree (root + nested children) */
function get_category_tree($include_tool_counts = true) {
    $pdo = db();
    $stmt = $pdo->query("SELECT * FROM categories ORDER BY sort_order, name");
    $rows = $stmt->fetchAll();

    $by_parent = [];
    foreach ($rows as $r) {
        $by_parent[$r['parent_id'] ?? 0][] = $r;
    }

    $counts = [];
    if ($include_tool_counts) {
        $cstmt = $pdo->query("SELECT category_id, COUNT(*) AS n FROM tools GROUP BY category_id");
        foreach ($cstmt->fetchAll() as $c) $counts[$c['category_id']] = $c['n'];
    }

    $build = function($parent_id) use (&$build, $by_parent, $counts, $include_tool_counts) {
        $out = [];
        foreach ($by_parent[$parent_id] ?? [] as $node) {
            $node['children'] = $build($node['id']);
            $node['tool_count'] = $counts[$node['id']] ?? 0;
            if ($include_tool_counts && $node['children']) {
                foreach ($node['children'] as $child) {
                    $node['tool_count'] += $child['tool_count'];
                }
            }
            $out[] = $node;
        }
        return $out;
    };

    return $build(0);
}

/** Get tools by category (including child categories) */
function get_tools_by_category($category_id) {
    $pdo = db();

    // Gather all child category IDs
    $ids = [(int)$category_id];
    $stack = [(int)$category_id];
    while ($stack) {
        $cur = array_shift($stack);
        $stmt = $pdo->prepare("SELECT id FROM categories WHERE parent_id = ?");
        $stmt->execute([$cur]);
        foreach ($stmt->fetchAll() as $row) {
            $ids[] = (int)$row['id'];
            $stack[] = (int)$row['id'];
        }
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT * FROM tools WHERE category_id IN ($placeholders) ORDER BY name");
    $stmt->execute($ids);
    return $stmt->fetchAll();
}

/** Search tools by query */
function search_tools($q, $limit = 100) {
    $pdo = db();
    $q = "%$q%";
    $stmt = $pdo->prepare("SELECT t.*, c.name AS category_name, c.slug AS category_slug
                          FROM tools t
                          JOIN categories c ON c.id = t.category_id
                          WHERE t.name LIKE ? OR t.description LIKE ? OR t.tags LIKE ?
                          ORDER BY t.name LIMIT $limit");
    $stmt->execute([$q, $q, $q]);
    return $stmt->fetchAll();
}

/** Get a single tool */
function get_tool($id) {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT t.*, c.name AS category_name, c.slug AS category_slug
                          FROM tools t JOIN categories c ON c.id = t.category_id
                          WHERE t.id = ?");
    $stmt->execute([(int)$id]);
    return $stmt->fetch();
}

/** Get category by slug */
function get_category($slug) {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM categories WHERE slug = ?");
    $stmt->execute([$slug]);
    return $stmt->fetch();
}

/** Recompute tool rating aggregate */
function recompute_rating($tool_id) {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT AVG(rating) AS avg, COUNT(*) AS cnt FROM ratings WHERE tool_id = ?");
    $stmt->execute([(int)$tool_id]);
    $row = $stmt->fetch();
    $pdo->prepare("UPDATE tools SET rating_avg = ?, rating_count = ?, updated_at = datetime('now') WHERE id = ?")
        ->execute([$row['avg'] ?? 0, $row['cnt'] ?? 0, (int)$tool_id]);
}

/** Is user logged in? */
function is_logged_in() {
    return !empty($_SESSION['user_id']);
}

/** Is admin? */
function is_admin() {
    return is_logged_in() && ($_SESSION['role'] ?? '') === 'admin';
}

/** Get current user data */
function current_user() {
    if (!is_logged_in()) return null;
    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([(int)$_SESSION['user_id']]);
    return $stmt->fetch();
}

/** Has user favorited this tool? */
function is_favorited($tool_id) {
    if (!is_logged_in()) return false;
    $pdo = db();
    $stmt = $pdo->prepare("SELECT 1 FROM favorites WHERE user_id = ? AND tool_id = ?");
    $stmt->execute([(int)$_SESSION['user_id'], (int)$tool_id]);
    return (bool)$stmt->fetchColumn();
}

/** Get user favorites */
function get_user_favorites($user_id) {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT t.*, c.name AS category_name, c.slug AS category_slug
                          FROM favorites f JOIN tools t ON t.id = f.tool_id
                          JOIN categories c ON c.id = t.category_id
                          WHERE f.user_id = ? ORDER BY f.created_at DESC");
    $stmt->execute([(int)$user_id]);
    return $stmt->fetchAll();
}

/** Sanitize for HTML output */
function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/** Render a star rating HTML */
function render_stars($avg) {
    $avg = (float)$avg;
    $html = '<span class="stars" data-rating="' . round($avg, 1) . '">';
    for ($i = 1; $i <= 5; $i++) {
        if ($avg >= $i) $html .= '<i class="fas fa-star"></i>';
        elseif ($avg >= $i - 0.5) $html .= '<i class="fas fa-star-half-alt"></i>';
        else $html .= '<i class="far fa-star"></i>';
    }
    $html .= '</span>';
    return $html;
}

/** Cost type badge HTML */
function cost_badge($type) {
    $map = [
        'free' => ['success', 'Free'],
        'freemium' => ['info', 'Freemium'],
        'paid' => ['danger', 'Paid'],
    ];
    [$color, $label] = $map[$type] ?? ['secondary', ucfirst($type)];
    return "<span class=\"badge bg-$color\">$label</span>";
}

/** Access type badge HTML */
function access_badge($type) {
    $map = [
        'web' => ['primary', 'Web'],
        'api' => ['warning', 'API'],
        'software' => ['dark', 'Software'],
        'browser-ext' => ['info', 'Extension'],
    ];
    [$color, $label] = $map[$type] ?? ['secondary', ucfirst($type)];
    return "<span class=\"badge bg-$color\">$label</span>";
}

/** Compute favicon URL with caching logic */
function favicon_url($tool) {
    if (!empty($tool['favicon'])) {
        return $tool['favicon'];
    }
    $host = parse_url($tool['url'], PHP_URL_HOST);
    return $host ? FAVICON_SERVICE . $host : '';
}

/** JSON response helper */
function json_response($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/** Require login, redirect if not */
function require_login() {
    if (!is_logged_in()) {
        header('Location: auth/login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
        exit;
    }
}

/** Require admin */
function require_admin() {
    if (!is_admin()) {
        header('Location: admin/login.php');
        exit;
    }
}


/**
 * AI Agent Chat - Returns AI response about public data
 */
function ai_chat($message) {
    require_once __DIR__ . "/ai_agent.php";
    $agent = new OSINTAgent();
    return $agent->chat($message);
}

/**
 * Get all tools via AI agent
 */
function ai_get_all_tools() {
    require_once __DIR__ . "/ai_agent.php";
    $agent = new OSINTAgent();
    return $agent->getAllTools();
}

/**
 * Get all categories via AI agent
 */
function ai_get_all_categories() {
    require_once __DIR__ . "/ai_agent.php";
    $agent = new OSINTAgent();
    return $agent->getAllCategories();
}

/**
 * Get category tree via AI agent
 */
function ai_get_category_tree() {
    require_once __DIR__ . "/ai_agent.php";
    $agent = new OSINTAgent();
    return $agent->getCategoryTree();
}

/**
 * Search tools via AI agent
 */
function ai_search_tools($query) {
    require_once __DIR__ . "/ai_agent.php";
    $agent = new OSINTAgent();
    $results = $agent->searchTools($query);
    return $results;
}



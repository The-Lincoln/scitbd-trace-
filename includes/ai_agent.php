<?php
/**
 * OSINT Framework - AI Agent Model
 * Provides intelligent responses about all public data using PHP + SQLite
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../skills_index.php';

use LLPhant\Chat\OllamaChat;
use LLPhant\Chat\LmStudioChat;
use LLPhant\Chat\OpenAIChat;
use LLPhant\OpenAIConfig;
use LLPhant\Chat\Message;
use LLPhant\Chat\Enums\ChatRole;
use Psr\Log\NullLogger;

class OSINTAgent {
    private $pdo;
    private $knowledge;
    private $llphantLoaded = false;
    private $chatEngine = null;

    public function __construct() {
        $this->pdo = db();
        $this->loadKnowledge();
        $this->initLLPhant();
    }

    private function initLLPhant() {
        if (!class_exists('LLPhant\Chat\OllamaChat')) {
            return;
        }
        $this->llphantLoaded = true;
        try {
            $config = new \LLPhant\OllamaConfig();
            $config->model = 'llama3';
            $config->url = 'http://localhost:11434/api/';
            $this->chatEngine = new \LLPhant\Chat\OllamaChat($config, new NullLogger());
        } catch (\Throwable $e) {
            $this->chatEngine = null;
            $this->llphantLoaded = false;
        }
    }

    private function loadKnowledge() {
        $tools = $this->pdo->query("SELECT t.*, c.name AS category_name, c.slug AS category_slug FROM tools t JOIN categories c ON c.id = t.category_id ORDER BY t.name")->fetchAll();
        $categories = $this->pdo->query("SELECT * FROM categories ORDER BY sort_order, name")->fetchAll();
        $users = $this->pdo->query("SELECT id, username, email, role, created_at FROM users")->fetchAll();
        $ratings = $this->pdo->query("SELECT COUNT(*) as total, AVG(rating) as avg_rating FROM ratings")->fetch();
        $favs = $this->pdo->query("SELECT COUNT(*) as total FROM favorites")->fetch();

        $by_parent = [];
        foreach ($categories as $cat) {
            $by_parent[$cat['parent_id'] ?? 0][] = $cat;
        }
        $tree = $this->buildTree(0, $by_parent);

        $catMap = [];
        foreach ($categories as $cat) {
            $catMap[$cat['slug']] = $cat;
        }

        $this->knowledge = [
            'tools' => $tools,
            'categories' => $categories,
            'users' => $users,
            'ratings' => $ratings,
            'favorites' => $favs,
            'category_tree' => $tree,
            'category_map' => $catMap,
        ];
    }

    private function buildTree($parentId, $byParent) {
        $out = [];
        foreach ($byParent[$parentId] ?? [] as $node) {
            $children = $this->buildTree($node['id'], $byParent);
            $out[] = array_merge($node, ['children' => $children]);
        }
        return $out;
    }

    public function chat($message) {
        $msg = strtolower(trim($message));
        $totalTools = count($this->knowledge['tools']);
        $totalCats = count($this->knowledge['categories']);

        // Use LLPhant ML engine if available and configured
        if ($this->llphantLoaded && $this->chatEngine !== null) {
            try {
                return $this->llPhantChat($message);
            } catch (\Throwable $e) {
                // Fallback to rule-based if LLPhant fails
            }
        }

        if (in_array($msg, ['hello', 'hi', 'hey'])) {
            return "Hello! I'm the OSINT Framework AI Agent powered by phpML + LLPhant. I can help with: stats, categories, tool search, and more.";
        }
        if (in_array($msg, ['help', 'commands'])) {
            return "Available commands:\n- stats - Show framework statistics\n- categories - List all categories\n- search [query] - Search tools\n- tools in [category] - Tools in category\n- top [N] tools - Top rated tools\n- free tools / paid tools\n- database - Database info\n- llm [question] - Ask the LLM (if configured)\n- quit - End chat";
        }
        if (preg_match('/about|what is/i', $msg)) {
            return "OSINT Framework is a comprehensive directory of OSINT tools built with PHP + SQLite + Bootstrap + phpML + LLPhant. It has $totalTools tools across $totalCats categories.";
        }
        if (preg_match('/stats|statistics|how many/i', $msg)) {
            return "Stats: $totalTools tools, $totalCats categories. Use 'categories' for full list.";
        }
        if (preg_match('/categories|all categories/i', $msg)) {
            return $this->formatCategories();
        }
        if (preg_match('/tools in (.+)|category.*(.+)/i', $msg, $m)) {
            return $this->toolsInCategory($m[1] ?? $m[2]);
        }
        if (preg_match('/search|find|look for/i', $msg)) {
            $q = trim(str_ireplace(['search', 'find', 'look for'], '', $msg));
            if (!empty($q)) return $this->searchTools($q);
        }
        if (preg_match('/top (\d+)/i', $msg, $m)) {
            return $this->topTools((int)$m[1]);
        }
        if (preg_match('/free.*tools/i', $msg)) {
            return $this->filterByCost('free');
        }
        if (preg_match('/paid.*tools/i', $msg)) {
            return $this->filterByCost('paid');
        }
        if (preg_match('/database|sqlite|tables/i', $msg)) {
            return $this->dbInfo();
        }
        if (preg_match('/^llm\s+/i', $msg)) {
            $question = trim(substr($msg, 4));
            if (!empty($question) && $this->llphantLoaded) {
                return $this->llPhantChat($question);
            }
            return "LLPhant engine not configured. Set Ollama/LMStudio connection to use LLM chat.";
        }
        return "I don't understand \"$message\". Try 'help' for commands.";
    }

    private function llPhantChat($message) {
        if (!$this->llphantLoaded || $this->chatEngine === null) {
            return "LLPhant chat engine not available.";
        }
        try {
            $response = $this->chatEngine->generateChat([
                Message::user($message)
            ]);
            return $response;
        } catch (\Throwable $e) {
            throw $e;
        }
    }

    private function formatCategories() {
        $tree = $this->knowledge['category_tree'];
        return $this->formatTree($tree);
    }

    private function formatTree($nodes, $indent = 0) {
        $out = '';
        $pad = str_repeat('  ', $indent);
        foreach ($nodes as $node) {
            $name = htmlspecialchars_decode($node['name']);
            $count = $node['tool_count'] ?? 0;
            $tc = (int)$count;
            $out .= $pad . $name . ' (' . $tc . ' tools)' . "\n";
            if (!empty($node['children'])) {
                $out .= $this->formatTree($node['children'], $indent + 1);
            }
        }
        return $out;
    }

    private function toolsInCategory($slug) {
        $cat = $this->knowledge['category_map'][$slug] ?? null;
        if (!$cat) {
            foreach ($this->knowledge['category_map'] as $s => $c) {
                if (stripos($c['name'], $slug) !== false || stripos($s, $slug) !== false) {
                    $cat = $c; break;
                }
            }
        }
        if (!$cat) return "Category not found: $slug";
        $tools = array_filter($this->knowledge['tools'], fn($t) => $t['category_id'] == $cat['id']);
        $out = $cat['name'] . ' (' . count($tools) . " tools):\n";
        foreach ($tools as $t) {
            $out .= '- ' . $t['name'] . ' (' . $t['cost_type'] . '): ' . $t['description'] . "\n";
        }
        return $out;
    }

    private function searchTools($query) {
        $results = [];
        foreach ($this->knowledge['tools'] as $t) {
            if (stripos($t['name'], $query) !== false || stripos($t['description'], $query) !== false || stripos($t['tags'], $query) !== false) {
                $results[] = $t;
            }
        }
        if (empty($results)) return "No tools found for \"$query\"";
        $out = count($results) . " results for \"$query\":\n";
        foreach ($results as $t) {
            $out .= '- ' . $t['name'] . ' (' . $t['category_name'] . ', ' . $t['cost_type'] . '): ' . $t['description'] . "\n";
        }
        return $out;
    }

    private function topTools($n = 5) {
        $sorted = $this->knowledge['tools'];
        usort($sorted, fn($a, $b) => ($b['rating_avg'] * $b['rating_count']) <=> ($a['rating_avg'] * $a['rating_count']));
        $top = array_slice($sorted, 0, $n);
        $out = "Top $n tools:\n";
        foreach ($top as $i => $t) {
            $stars = str_repeat('*', (int)$t['rating_avg']) . str_repeat('o', 5 - (int)$t['rating_avg']);
            $out .= ($i+1) . '. ' . $t['name'] . ' [' . $stars . '] ' . $t['rating_avg'] . '/5 (' . $t['rating_count'] . " ratings)\n";
        }
        return $out;
    }

    private function filterByCost($type) {
        $tools = array_filter($this->knowledge['tools'], fn($t) => $t['cost_type'] == $type);
        return count($tools) . " " . $type . " tools:\n" . implode("\n", array_map(fn($t) => '- ' . $t['name'], $tools));
    }

    private function dbInfo() {
        $tables = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
        $out = "Database (SQLite):\n";
        foreach ($tables as $t) {
            $cnt = $this->pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
            $out .= "- $t: $cnt rows\n";
        }
        return $out;
    }

    public function getAllTools() { return $this->knowledge['tools']; }
    public function getAllCategories() { return $this->knowledge['categories']; }
    public function getCategoryTree() { return $this->knowledge['category_tree']; }
    public function searchToolsPublic($q) { return $this->searchTools($q); }
}

function getAgent() { return new OSINTAgent(); }

function handleChat($message) {
    $agent = new OSINTAgent();
    return json_encode(['success' => true, 'response' => $agent->chat($message), 'timestamp' => date('Y-m-d H:i:s')]);
}
?>

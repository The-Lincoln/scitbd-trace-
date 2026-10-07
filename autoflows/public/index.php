<?php
/**
 * AutoFlows — Front Controller (public/ docroot copy).
 *
 * Canonical entry when the document root points at public/ (Apache +
 * `php -S -t public`). Mirrors ../index.php. BASE_PATH resolves by location
 * so this file also works if copied/moved.
 */
declare(strict_types=1);

if (!defined('BASE_PATH')) {
    $rootCandidate = is_file(__DIR__ . '/../app/config.php') ? dirname(__DIR__) : __DIR__;
    // Root layout fallback: if app/ sits next to this file, use __DIR__.
    if (is_file(__DIR__ . '/app/config.php')) {
        $rootCandidate = __DIR__;
    }
    define('BASE_PATH', $rootCandidate);
}

require BASE_PATH . '/app/config.php';
require BASE_PATH . '/app/core/Helpers.php';
require BASE_PATH . '/app/core/Database.php';
require BASE_PATH . '/app/core/Model.php';
require BASE_PATH . '/app/core/Controller.php';
require BASE_PATH . '/app/core/View.php';
require BASE_PATH . '/app/core/Router.php';

// Models (Setting first — Helpers::config() may consult it while booting).
foreach (['Setting', 'User', 'SocialAccount', 'Conversation', 'Message', 'Flow', 'Content', 'Run', 'DailyTask'] as $m) {
    require BASE_PATH . '/app/models/' . $m . '.php';
}

// Services — order matters: PromptLibrary is used by ContentFactory, which
// Agent builds on; TinyLLM and Sse are leaves. FinanceTube, HermesSkills,
// EvolveMemory and ScitbdCeo extend prompts/chat/memory/CEO bridges — they
// must load here too, otherwise class_exists() guards silently disable them
// on web while CLI tools use them. Guarded so a missing pack never 500s boot.
foreach (['TinyLLM', 'Sse', 'PromptLibrary', 'ContentFactory', 'Agent', 'SocialAuth', 'Publisher', 'SlackApp', 'FinanceTube', 'HermesSkills', 'EvolveMemory', 'ScitbdCeo', 'AgentBrowser', 'BrowserAgent'] as $s) {
    $f = BASE_PATH . '/app/services/' . $s . '.php';
    if (is_file($f)) {
        require $f;
    }
}

// Controllers
foreach ([
    'HomeController', 'ChatController', 'FlowController',
    'ContentController', 'AgentController', 'SettingsController', 'AuthController', 'SlackController', 'TasksController', 'BrowserController',
] as $c) {
    $cf = BASE_PATH . '/app/controllers/' . $c . '.php';
    if (is_file($cf)) {
        require $cf;
    }
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Storage + schema (creates storage/app.db on first boot)
Database::boot();

(new Router())->dispatch();

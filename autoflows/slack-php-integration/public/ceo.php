<?php
/**
 * CEO API bridge — exposes ceo/index.php backend from the public docroot.
 *
 * Why: `composer start` serves `public/` (php -S -t public/), so ceo/index.php
 * is NOT web-reachable by default. This file forwards all /ceo.php requests
 * (REST /tasks, /toolbar/*, ?action=*) to the canonical ceo/ backend.
 *
 * Usage:
 *   GET  /ceo.php/tasks
 *   GET  /ceo.php/toolbar/data
 *   GET  /ceo.php?action=get_dashboard
 *   POST /ceo.php/tasks  {"task_title": "...", "priority": "high"}
 *
 * The root index.php router also handles /tasks and /toolbar/* when the
 * server docroot is the project root. Both paths share the same backend.
 */

// Make ceo/index.php path-routing work when accessed as /ceo.php/tasks:
// ceo/index.php uses $_SERVER['REQUEST_URI'] path (/ceo.php/tasks). Strip the
// /ceo.php prefix so its internal checks ($requestPath === '/tasks') match.
if (isset($_SERVER['REQUEST_URI'])) {
    $_SERVER['REQUEST_URI'] = preg_replace('#^/ceo\.php#', '', $_SERVER['REQUEST_URI']);
    if ($_SERVER['REQUEST_URI'] === '' || $_SERVER['REQUEST_URI'][0] !== '/') {
        $_SERVER['REQUEST_URI'] = '/' . ($_SERVER['REQUEST_URI'] ?? '');
    }
    // Ensure QUERY_STRING stays intact for ?action= routes
    if (str_contains($_SERVER['REQUEST_URI'], '?') === false && !empty($_SERVER['QUERY_STRING'])) {
        $_SERVER['REQUEST_URI'] .= '?' . $_SERVER['QUERY_STRING'];
    }
}

require __DIR__ . '/../ceo/index.php';

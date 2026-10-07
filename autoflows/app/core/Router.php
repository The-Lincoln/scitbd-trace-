<?php
/**
 * Minimal router: ?r=controller/action → [Controller, method].
 * Falls back to HomeController@index for the empty route.
 */
declare(strict_types=1);

final class Router
{
    private const MAP = [
        ''                      => ['HomeController', 'index'],
        'home'                  => ['HomeController', 'index'],

        // TinyLLM chat
        'chat'                  => ['ChatController', 'index'],
        'api/chat/conversations'=> ['ChatController', 'conversations'],
        'api/chat/create'       => ['ChatController', 'create'],
        'api/chat/rename'       => ['ChatController', 'rename'],
        'api/chat/delete'       => ['ChatController', 'delete'],
        'api/chat/messages'     => ['ChatController', 'messages'],
        'api/chat/send'         => ['ChatController', 'send'],
        'api/chat/delete_message'=> ['ChatController', 'deleteMessage'],
        'api/chat/clear'        => ['ChatController', 'clear'],
        'api/chat/status'       => ['ChatController', 'status'],
        'api/chat/export'       => ['ChatController', 'export'],

        // AutoFlows
        'flows'                 => ['FlowController', 'index'],
        'flow'                  => ['FlowController', 'edit'],
        'api/flow/create'       => ['FlowController', 'create'],
        'api/flow/update'       => ['FlowController', 'update'],
        'api/flow/delete'       => ['FlowController', 'delete'],
        'api/flow/toggle'       => ['FlowController', 'toggle'],
        'api/flow/run'          => ['FlowController', 'run'],

        // Content library
        'content'               => ['ContentController', 'index'],
        'item'                  => ['ContentController', 'edit'],
        'api/content/save'      => ['ContentController', 'save'],
        'api/content/delete'    => ['ContentController', 'delete'],
        'api/content/status'    => ['ContentController', 'status'],
        'api/content/regenerate'=> ['ContentController', 'regenerate'],
        'api/content/export'    => ['ContentController', 'export'],
        'api/content/publish'   => ['ContentController', 'publish'],

        // AI agent console
        'agent'                 => ['AgentController', 'index'],
        'api/agent/run'         => ['AgentController', 'run'],
        'api/agent/runs'        => ['AgentController', 'runs'],
        'api/agent/trace'       => ['AgentController', 'trace'],

        // Browser automation (agent-browser: vercel-labs/agent-browser)
        'browser'               => ['BrowserController', 'index'],
        'api/browser/status'    => ['BrowserController', 'status'],
        'api/browser/open'      => ['BrowserController', 'open'],
        'api/browser/snapshot'  => ['BrowserController', 'snapshot'],
        'api/browser/read'      => ['BrowserController', 'read'],
        'api/browser/act'       => ['BrowserController', 'act'],
        'api/browser/batch'     => ['BrowserController', 'batch'],
        'api/browser/shot'      => ['BrowserController', 'shot'],
        'api/browser/run'       => ['BrowserController', 'run'],
        'api/browser/close'     => ['BrowserController', 'close'],
        'api/browser/file'      => ['BrowserController', 'file'],

        // Daily Task Management dashboard (CEO daily_tasks) — Task #105
        'tasks'                 => ['TasksController', 'index'],
        'task'                  => ['TasksController', 'edit'],
        'api/tasks/list'        => ['TasksController', 'list'],
        'api/tasks/create'      => ['TasksController', 'create'],
        'api/tasks/update'      => ['TasksController', 'update'],
        'api/tasks/delete'      => ['TasksController', 'delete'],
        'api/tasks/status'      => ['TasksController', 'status'],
        'api/tasks/assign'      => ['TasksController', 'assign'],

        // Auth — Gmail (Google) + Facebook social login & channel connect
        'login'                 => ['AuthController', 'login'],
        'logout'                => ['AuthController', 'logout'],
        'auth/google'           => ['AuthController', 'google'],
        'auth/google/callback'  => ['AuthController', 'googleCallback'],
        'auth/facebook'         => ['AuthController', 'facebook'],
        'auth/facebook/callback'=> ['AuthController', 'facebookCallback'],
        'auth/dev'              => ['AuthController', 'dev'],
        'auth/disconnect'       => ['AuthController', 'disconnect'],
        'api/auth/status'       => ['AuthController', 'status'],

        // Slack — workspace T0AGURY3K1D Events + Slash (#98)
        'slack/events'           => ['SlackController', 'events'],
        'slack/slash'            => ['SlackController', 'slash'],
        'api/slack/status'       => ['SlackController', 'status'],

        // Settings + dashboard data
        'settings'              => ['SettingsController', 'index'],
        'settings/save'         => ['SettingsController', 'save'],
        'settings/reset'        => ['SettingsController', 'reset'],
        'api/settings/test'     => ['SettingsController', 'test'],
        'api/stats'             => ['HomeController', 'stats'],
    ];

    public function dispatch(): void
    {
        $route  = trim((string) ($_GET['r'] ?? ''), '/');
        $target = self::MAP[$route] ?? null;

        if ($target === null) {
            $this->notFound($route);
            return;
        }

        [$controller, $action] = $target;
        $instance = new $controller();
        if (!method_exists($instance, $action)) {
            $this->notFound($route);
            return;
        }
        $instance->$action();
    }

    private function notFound(string $route): void
    {
        if (is_ajax() || str_starts_with($route, 'api/')) {
            json_response(['ok' => false, 'error' => 'Unknown route: ' . $route], 404);
        }
        http_response_code(404);
        (new View())->render('errors/404', ['route' => $route, 'title' => 'Not found', 'flashes' => []]);
    }
}

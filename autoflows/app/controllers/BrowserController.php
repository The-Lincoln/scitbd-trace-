<?php
/**
 * Browser — agent-browser console for AutoFlows.
 * Page: ?r=browser  ·  JSON: ?r=api/browser/*
 */
declare(strict_types=1);

final class BrowserController extends Controller
{
    public function index(): void
    {
        $status = AgentBrowser::status('autoflows');
        $this->view->render('browser/index', [
            'title' => 'Browser Automation',
            'status' => $status,
            'plans' => BrowserAgent::PLANS,
            'flashes' => take_flash(),
        ]);
    }

    public function status(): void
    {
        json_response(['ok' => true, 'browser' => AgentBrowser::status('autoflows'), 'tinyllm' => TinyLLM::status(2)]);
    }

    public function open(): void
    {
        $this->requireCsrf();
        $b = $this->jsonBody();
        $url = trim((string)($b['url'] ?? $_POST['url'] ?? ''));
        if ($url === '') {
            json_response(['ok' => false, 'error' => 'URL is required'], 422);
        }
        $res = AgentBrowser::open($url, ['module' => 'autoflows', 'timeout' => 75]);
        Database::log('browser.open', mb_substr($url, 0, 200));
        json_response(['ok' => $res['ok'], 'result' => $res]);
    }

    public function snapshot(): void
    {
        $res = AgentBrowser::snapshot(['module' => 'autoflows']);
        json_response(['ok' => $res['ok'], 'result' => $res]);
    }

    public function read(): void
    {
        $b = $this->jsonBody();
        $url = trim((string)($b['url'] ?? $_GET['url'] ?? ''));
        $res = AgentBrowser::read($url !== '' ? $url : null, ['module' => 'autoflows']);
        json_response(['ok' => $res['ok'], 'result' => $res]);
    }

    public function act(): void
    {
        $this->requireCsrf();
        $b = $this->jsonBody();
        $action = (array)($b['action'] ?? $b);
        $res = BrowserAgent::runAction($action, ['module' => 'autoflows', 'session' => AgentBrowser::sessionFor('autoflows')]);
        Database::log('browser.act', BrowserAgent::describeAction($action));
        json_response(['ok' => $res['ok'], 'result' => $res]);
    }

    public function batch(): void
    {
        $this->requireCsrf();
        $b = $this->jsonBody();
        $cmds = (array)($b['commands'] ?? []);
        if ($cmds === []) {
            json_response(['ok' => false, 'error' => 'commands[] is required'], 422);
        }
        $res = AgentBrowser::batch($cmds, ['module' => 'autoflows', 'bail' => !empty($b['bail'])]);
        Database::log('browser.batch', count($cmds) . ' cmds');
        json_response(['ok' => $res['ok'], 'result' => $res]);
    }

    public function shot(): void
    {
        $this->requireCsrf();
        $res = AgentBrowser::screenshot(null, ['module' => 'autoflows']);
        // Return web-accessible path when under storage/exports (served by router.php).
        $web = null;
        if (!empty($res['path']) && str_contains($res['path'], 'storage')) {
            $web = '?r=api/browser/file&f=' . urlencode(basename($res['path']));
        }
        json_response(['ok' => $res['ok'], 'result' => $res, 'web' => $web]);
    }

    /** SSE run of a BrowserAgent plan. */
    public function run(): void
    {
        $this->requireCsrf();
        $b = $this->jsonBody();
        $url = trim((string)($b['url'] ?? ''));
        if ($url === '') {
            json_response(['ok' => false, 'error' => 'URL is required'], 422);
        }
        Sse::open();
        $out = BrowserAgent::run([
            'plan' => (string)($b['plan'] ?? 'research'),
            'url' => $url,
            'goal' => (string)($b['goal'] ?? ''),
            'actions' => (array)($b['actions'] ?? []),
            'module' => 'autoflows',
            'screenshot' => true,
        ], static function (array $p): void {
            Sse::send($p);
        });
        Sse::done(['ok' => $out['ok'], 'report' => $out['report'], 'shot' => $out['shot'], 'steps' => $out['steps'], 'ms' => $out['ms']]);
    }

    public function close(): void
    {
        $this->requireCsrf();
        $res = AgentBrowser::close(false, ['module' => 'autoflows']);
        json_response(['ok' => $res['ok'], 'result' => $res]);
    }

    /** Serve a screenshot file from the shot dir (same-origin evidence). */
    public function file(): void
    {
        $f = basename((string)($_GET['f'] ?? ''));
        if (!preg_match('/^[\w\-.]+\.(png|jpg|jpeg|webp)$/i', $f)) {
            json_response(['ok' => false, 'error' => 'Bad file'], 400);
        }
        $p = AgentBrowser::shotDir() . '/' . $f;
        if (!is_file($p)) {
            json_response(['ok' => false, 'error' => 'Not found'], 404);
        }
        $mime = str_ends_with(strtolower($f), '.png') ? 'image/png' : 'image/jpeg';
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($p));
        readfile($p);
        exit;
    }
}

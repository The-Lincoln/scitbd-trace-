<?php
/**
 * Tasks — Daily Task Management dashboard (CEO daily_tasks).
 * View / create / edit / delete / status / assign. Task #105.
 */
declare(strict_types=1);

final class TasksController extends Controller
{
    public function index(): void
    {
        $status = (string) ($this->input('status', '') ?? '');
        $block = $this->int('block', 0);
        $q = trim((string) ($this->input('q', '') ?? ''));
        if (!in_array($status, DailyTask::STATUSES, true)) {
            $status = '';
        }
        $this->view->render('tasks/index', [
            'title' => 'Daily Tasks',
            'tasks' => DailyTask::all($status, $block, $q),
            'stats' => DailyTask::stats(),
            'filter' => ['status' => $status, 'block' => $block, 'q' => $q],
            'statuses' => DailyTask::STATUSES,
            'priorities' => DailyTask::PRIORITIES,
            'categories' => DailyTask::CATEGORIES,
            'flashes' => take_flash(),
        ]);
    }

    public function edit(): void
    {
        $id = $this->int('id', 0);
        $task = $id > 0 ? DailyTask::find($id) : null;
        if ($id > 0 && $task === null) {
            flash('warning', 'Task not found.');
            redirect('tasks');
        }
        $this->view->render('tasks/edit', [
            'title' => $task !== null ? 'Edit task #' . $task['id'] : 'New task',
            'task' => $task,
            'logs' => $task !== null ? DailyTask::logs((int) $task['id']) : [],
            'statuses' => DailyTask::STATUSES,
            'priorities' => DailyTask::PRIORITIES,
            'categories' => DailyTask::CATEGORIES,
            'flashes' => take_flash(),
        ]);
    }

    // ---------------------------------------------------------- endpoints ---

    public function create(): void
    {
        $this->requireCsrf();
        $d = $this->taskPayload();
        if ($d['task_title'] === '') {
            json_response(['ok' => false, 'error' => 'Title is required'], 422);
        }
        $id = DailyTask::create($d + ['created_by' => 'ceo']);
        Database::log('tasks.create', "#{$id}");
        json_response(['ok' => true, 'id' => $id, 'redirect' => url('task') . '&id=' . $id]);
    }

    public function update(): void
    {
        $this->requireCsrf();
        $id = $this->int('id', 0);
        if (DailyTask::find($id) === null) {
            json_response(['ok' => false, 'error' => 'Task not found'], 404);
        }
        $d = $this->taskPayload();
        if ($d['task_title'] === '') {
            json_response(['ok' => false, 'error' => 'Title is required'], 422);
        }
        DailyTask::update($id, $d);
        Database::log('tasks.update', "#{$id}");
        json_response(['ok' => true, 'id' => $id]);
    }

    public function delete(): void
    {
        $this->requireCsrf();
        $body = $this->jsonBody();
        $id = (int) ($body['id'] ?? $this->int('id', 0));
        if (DailyTask::find($id) === null) {
            json_response(['ok' => false, 'error' => 'Task not found'], 404);
        }
        DailyTask::destroy($id);
        Database::log('tasks.delete', "#{$id}");
        json_response(['ok' => true]);
    }

    public function status(): void
    {
        $this->requireCsrf();
        $body = $this->jsonBody();
        $id = (int) ($body['id'] ?? $this->int('id', 0));
        $status = (string) ($body['status'] ?? $_POST['status'] ?? '');
        if (!DailyTask::setStatus($id, $status)) {
            json_response(['ok' => false, 'error' => 'Unknown task or status'], 422);
        }
        Database::log('tasks.status', "#{$id} {$status}");
        json_response(['ok' => true, 'id' => $id, 'status' => $status]);
    }

    public function assign(): void
    {
        $this->requireCsrf();
        $body = $this->jsonBody();
        $id = (int) ($body['id'] ?? $this->int('id', 0));
        $assignee = (string) ($body['assignee'] ?? $_POST['assignee'] ?? '');
        if (!DailyTask::assign($id, $assignee)) {
            json_response(['ok' => false, 'error' => 'Unknown task'], 404);
        }
        Database::log('tasks.assign', "#{$id} -> {$assignee}");
        json_response(['ok' => true, 'id' => $id, 'assignee' => $assignee]);
    }

    public function list(): void
    {
        $status = (string) ($this->input('status', '') ?? '');
        $block = $this->int('block', 0);
        $q = trim((string) ($this->input('q', '') ?? ''));
        if (!in_array($status, DailyTask::STATUSES, true)) {
            $status = '';
        }
        json_response(['ok' => true, 'items' => DailyTask::all($status, $block, $q), 'stats' => DailyTask::stats()]);
    }

    // ------------------------------------------------------------- helpers ---

    private function taskPayload(): array
    {
        $body = $this->jsonBody();
        $get = fn (string $k, string $d = '') => trim((string) ($body[$k] ?? $_POST[$k] ?? $d));
        $due = $get('due_date', date('Y-m-d H:i:s'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $due)) {
            $due = date('Y-m-d H:i:s');
        }
        if (strlen($due) === 16) {
            $due .= ':00';
        }
        if (strlen($due) === 10) {
            $due .= ' 09:00:00';
        }
        return [
            'task_title' => mb_substr($get('task_title'), 0, 200),
            'task_description' => (string) ($body['task_description'] ?? $_POST['task_description'] ?? ''),
            'priority' => $get('priority', 'medium'),
            'status' => $get('status', 'pending'),
            'category' => $get('category', 'operations'),
            'assignee' => $get('assignee', 'ceo') ?: 'ceo',
            'due_date' => $due,
            'estimated_hours' => (float) ($body['estimated_hours'] ?? $_POST['estimated_hours'] ?? 1.0),
            'bst_block_id' => clamp($this->int('bst_block_id', (int) ($body['bst_block_id'] ?? 3)), 1, 4),
        ];
    }
}

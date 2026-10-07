<?php
/**
 * Daily Tasks dashboard — view / filter / status / assign / delete.
 * Expects: $tasks, $stats, $filter, $statuses, $priorities, $categories
 */
$tasks = (array) $tasks;
$stats = (array) $stats;
$filter = (array) $filter;
$byStatus = (array) ($stats['by_status'] ?? []);
$prioBadge = ['low' => 'text-bg-secondary', 'medium' => 'text-bg-info', 'high' => 'text-bg-warning', 'critical' => 'text-bg-danger'];
$statusBadge = ['pending' => 'text-bg-secondary', 'in_progress' => 'text-bg-primary', 'completed' => 'text-bg-success', 'cancelled' => 'text-bg-dark', 'blocked' => 'text-bg-danger'];
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div>
    <h1 class="h4 mb-1">Daily Tasks</h1>
    <p class="text-secondary small mb-0">CEO <code>daily_tasks</code> — view, edit, delete, update status &amp; assign. <?= (int)($stats['open'] ?? 0) ?> open · <?= (int)($stats['due_today'] ?? 0) ?> due today.</p>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-accent btn-sm" href="<?= url('task') ?>#new"><i class="bi bi-plus-lg me-1"></i>New task</a>
  </div>
</div>

<div class="d-flex flex-wrap gap-1 mb-3">
  <?php foreach ((array)$statuses as $s): ?>
    <a class="btn btn-sm <?= ($filter['status'] ?? '') === $s ? 'btn-light' : 'btn-outline-light' ?>" href="<?= url('tasks') . ($s !== '' ? '&status=' . $s : '') ?>"><?= e($s) ?> <span class="badge text-bg-dark"><?= (int)($byStatus[$s] ?? 0) ?></span></a>
  <?php endforeach; ?>
  <?php if (($filter['status'] ?? '') !== ''): ?><a class="btn btn-sm btn-outline-secondary" href="<?= url('tasks') ?>">clear</a><?php endif; ?>
</div>

<form class="row g-2 mb-3" method="get" action="index.php">
  <input type="hidden" name="r" value="tasks">
  <div class="col-md-2">
    <select class="form-select form-select-sm" name="status">
      <option value="">All statuses</option>
      <?php foreach ((array)$statuses as $s): ?><option value="<?= e($s) ?>" <?= ($filter['status'] ?? '') === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-2">
    <select class="form-select form-select-sm" name="block">
      <option value="0">All blocks</option>
      <?php for ($b = 1; $b <= 4; $b++): ?><option value="<?= $b ?>" <?= (int)($filter['block'] ?? 0) === $b ? 'selected' : '' ?>>Block <?= $b ?></option><?php endfor; ?>
    </select>
  </div>
  <div class="col-md-6"><input class="form-control form-control-sm" name="q" value="<?= e($filter['q'] ?? '') ?>" placeholder="Search title, description, assignee…"></div>
  <div class="col-md-2"><button class="btn btn-sm btn-outline-light w-100">Filter</button></div>
</form>

<div class="card">
  <div class="table-responsive">
    <table class="table table-dark table-hover table-sm align-middle mb-0" id="tasksTable">
      <thead><tr><th>ID</th><th>Title</th><th>Pri</th><th>Status</th><th>Cat</th><th>Assignee</th><th>Due</th><th>Blk</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php if (empty($tasks)): ?>
        <tr><td colspan="9" class="text-center text-secondary py-4">No tasks match. <a href="<?= url('task') ?>#new">Create one</a>.</td></tr>
      <?php else: foreach ($tasks as $t): $id = (int)$t['id']; ?>
        <tr data-task-row="<?= $id ?>">
          <td class="text-secondary">#<?= $id ?></td>
          <td><a class="text-decoration-none" href="<?= url('task') ?>&id=<?= $id ?>"><?= e(mb_substr((string)$t['task_title'], 0, 70)) ?></a><div class="small text-secondary"><?= e(excerpt((string)($t['task_description'] ?? ''), 80)) ?></div></td>
          <td><span class="badge <?= $prioBadge[$t['priority']] ?? 'text-bg-secondary' ?>"><?= e($t['priority']) ?></span></td>
          <td>
            <select class="form-select form-select-sm task-status" data-id="<?= $id ?>">
              <?php foreach ((array)$statuses as $s): ?><option value="<?= e($s) ?>" <?= $t['status'] === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?>
            </select>
          </td>
          <td class="small"><?= e($t['category']) ?></td>
          <td>
            <div class="input-group input-group-sm" style="min-width:140px">
              <input class="form-control form-control-sm task-assignee" data-id="<?= $id ?>" value="<?= e($t['assignee']) ?>">
              <button class="btn btn-outline-light btn-assign" data-id="<?= $id ?>" title="Assign"><i class="bi bi-person-check"></i></button>
            </div>
          </td>
          <td class="small text-nowrap"><?= e($t['due_date']) ?></td>
          <td class="text-center">B<?= (int)$t['bst_block_id'] ?></td>
          <td class="text-end text-nowrap">
            <a class="btn btn-sm btn-outline-light" href="<?= url('task') ?>&id=<?= $id ?>" title="View / edit"><i class="bi bi-pencil"></i></a>
            <button class="btn btn-sm btn-outline-danger btn-delete" data-id="<?= $id ?>" title="Delete"><i class="bi bi-trash"></i></button>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<span class="small text-secondary" id="tasksOut"></span>
<?php View::share('extraScripts', ['tasks.js']); ?>

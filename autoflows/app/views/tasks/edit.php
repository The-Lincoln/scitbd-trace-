<?php
/**
 * Task editor — view + edit + delete + status + assign. Task #105.
 * Expects: $task (null = new), $logs, $statuses, $priorities, $categories
 */
$task = is_array($task ?? null) ? $task : null;
$isNew = $task === null;
$v = fn (string $k, string $d = '') => e((string) ($task[$k] ?? $d));
?>
<div class="d-flex justify-content-between align-items-center gap-2 mb-3">
  <div>
    <h1 class="h4 mb-1"><?= $isNew ? 'New task' : 'Task #' . (int)$task['id'] ?></h1>
    <p class="text-secondary small mb-0"><a href="<?= url('tasks') ?>">&larr; Back to Daily Tasks</a><?php if (!$isNew): ?> · status <strong><?= e($task['status']) ?></strong> · assignee <strong><?= e($task['assignee']) ?></strong><?php endif; ?></p>
  </div>
  <?php if (!$isNew): ?><button class="btn btn-sm btn-outline-danger" id="btnDeleteTask" data-id="<?= (int)$task['id'] ?>"><i class="bi bi-trash me-1"></i>Delete</button><?php endif; ?>
</div>

<form id="taskForm" data-id="<?= $isNew ? 0 : (int)$task['id'] ?>">
  <div class="row g-3">
    <div class="col-lg-8">
      <div class="card mb-3"><div class="card-body">
        <div class="mb-3"><label class="form-label" for="fTitle">Title</label><input class="form-control" id="fTitle" value="<?= $v('task_title') ?>" required maxlength="200"></div>
        <div class="mb-3"><label class="form-label" for="fDesc">Description</label><textarea class="form-control" id="fDesc" rows="8"><?= $v('task_description') ?></textarea></div>
        <div class="row g-3">
          <div class="col-sm-4"><label class="form-label" for="fPriority">Priority</label><select class="form-select" id="fPriority"><?php foreach ((array)$priorities as $p): ?><option value="<?= e($p) ?>" <?= ($task['priority'] ?? 'medium') === $p ? 'selected' : '' ?>><?= e($p) ?></option><?php endforeach; ?></select></div>
          <div class="col-sm-4"><label class="form-label" for="fCategory">Category</label><select class="form-select" id="fCategory"><?php foreach ((array)$categories as $c): ?><option value="<?= e($c) ?>" <?= ($task['category'] ?? 'operations') === $c ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select></div>
          <div class="col-sm-4"><label class="form-label" for="fStatus">Status</label><select class="form-select" id="fStatus"><?php foreach ((array)$statuses as $s): ?><option value="<?= e($s) ?>" <?= ($task['status'] ?? 'pending') === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select></div>
        </div>
      </div></div>
    </div>
    <div class="col-lg-4">
      <div class="card mb-3"><div class="card-body">
        <div class="mb-3"><label class="form-label" for="fAssignee">Assignee (daily assign)</label><input class="form-control" id="fAssignee" value="<?= $v('assignee', 'ceo') ?>" placeholder="ceo"></div>
        <div class="mb-3"><label class="form-label" for="fDue">Due date</label><input class="form-control" id="fDue" value="<?= $v('due_date', date('Y-m-d H:i:s')) ?>" placeholder="YYYY-MM-DD HH:MM:SS"></div>
        <div class="row g-3 mb-3">
          <div class="col-6"><label class="form-label" for="fBlock">BST block</label><select class="form-select" id="fBlock"><?php for ($b = 1; $b <= 4; $b++): ?><option value="<?= $b ?>" <?= (int)($task['bst_block_id'] ?? 3) === $b ? 'selected' : '' ?>>Block <?= $b ?></option><?php endfor; ?></select></div>
          <div class="col-6"><label class="form-label" for="fEst">Est. hours</label><input class="form-control" id="fEst" type="number" step="0.5" min="0" value="<?= e((string)($task['estimated_hours'] ?? '1')) ?>"></div>
        </div>
        <div class="d-grid gap-2">
          <button class="btn btn-accent" type="submit"><i class="bi bi-check2 me-1"></i><?= $isNew ? 'Create task' : 'Save changes' ?></button>
          <span class="small text-secondary" id="taskSaveOut"></span>
        </div>
      </div></div>
      <?php if (!$isNew): ?>
      <div class="card"><div class="card-header py-2 small">History (<?= count((array)$logs) ?>)</div>
        <ul class="list-group list-group-flush small"><?php foreach ((array)$logs as $lg): ?><li class="list-group-item bg-transparent"><span class="badge text-bg-dark"><?= e($lg['action']) ?></span> <?= e($lg['performed_by']) ?> <span class="text-secondary"><?= e($lg['created_at']) ?></span><div><?= e(mb_substr((string)$lg['details'], 0, 160)) ?></div></li><?php endforeach; ?></ul>
      </div>
      <?php endif; ?>
    </div>
  </div>
</form>
<?php View::share('extraScripts', ['tasks.js']); ?>

<?php /** 404 */ ?>
<div class="text-center py-5">
  <div class="display-3 text-primary mb-3"><i class="bi bi-signpost-2"></i></div>
  <h1 class="h3">Route not found</h1>
  <p class="text-secondary">
    Nothing is registered at <code><?= e($route ?? '') ?></code>.
  </p>
  <a class="btn btn-accent" href="<?= url('home') ?>"><i class="bi bi-house me-1"></i>Back to the dashboard</a>
</div>

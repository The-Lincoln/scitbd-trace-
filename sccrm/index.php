<?php
session_start();
require_once __DIR__ . '/includes/header.php';

$total_contacts = $db->query("SELECT COUNT(*) FROM contacts")->fetchColumn();
$total_companies = $db->query("SELECT COUNT(*) FROM companies")->fetchColumn();
$total_interactions = $db->query("SELECT COUNT(*) FROM interactions")->fetchColumn();
$total_tasks = $db->query("SELECT COUNT(*) FROM tasks")->fetchColumn();
$pending_tasks = $db->query("SELECT COUNT(*) FROM tasks WHERE status != 'completed' AND status != 'cancelled'")->fetchColumn();
$upcoming_interactions = $db->query("SELECT COUNT(*) FROM interactions WHERE follow_up_date IS NOT NULL AND follow_up_date >= date('now')")->fetchColumn();

$total_leads = $db->query("SELECT COUNT(*) FROM leads")->fetchColumn();
$new_leads = $db->query("SELECT COUNT(*) FROM leads WHERE status = 'new'")->fetchColumn();
$won_leads = $db->query("SELECT COUNT(*) FROM leads WHERE status = 'won'")->fetchColumn();
$lead_pipeline = $db->query("SELECT status, COUNT(*) as count FROM leads GROUP BY status")->fetchAll();

$total_kb = $db->query("SELECT COUNT(*) FROM knowledge_articles WHERE status='published'")->fetchColumn();
$total_faq = $db->query("SELECT COUNT(*) FROM faqs WHERE is_published=1")->fetchColumn();
$open_tickets = $db->query("SELECT COUNT(*) FROM support_tickets WHERE status NOT IN ('resolved','closed')")->fetchColumn();

$recent_interactions = $db->query("
    SELECT i.*, c.first_name, c.last_name, co.name as company_name
    FROM interactions i
    LEFT JOIN contacts c ON i.contact_id = c.id
    LEFT JOIN companies co ON i.company_id = co.id
    ORDER BY i.created_at DESC LIMIT 6
")->fetchAll();

$recent_tasks = $db->query("
    SELECT t.*, c.first_name, c.last_name, co.name as company_name
    FROM tasks t
    LEFT JOIN contacts c ON t.contact_id = c.id
    LEFT JOIN companies co ON t.company_id = co.id
    ORDER BY t.created_at DESC LIMIT 6
")->fetchAll();

$task_status_counts = $db->query("
    SELECT status, COUNT(*) as count FROM tasks GROUP BY status
")->fetchAll();

$interaction_type_counts = $db->query("
    SELECT type, COUNT(*) as count FROM interactions GROUP BY type
")->fetchAll();

$recent_contacts = $db->query("
    SELECT c.*, co.name as company_name FROM contacts c
    LEFT JOIN companies co ON c.company_id = co.id
    ORDER BY c.created_at DESC LIMIT 5
")->fetchAll();

$recent_leads = $db->query("
    SELECT l.*, m.name as market_name, s.name as service_name
    FROM leads l
    LEFT JOIN markets m ON l.market_id = m.id
    LEFT JOIN services s ON l.service_id = s.id
    ORDER BY l.created_at DESC LIMIT 6
")->fetchAll();
?>
<div class="dashboard-welcome">
    <h4><i class="fas fa-hand-wave me-2"></i> Welcome to SCIT CRM</h4>
    <p>Manage your social communication, track interactions, and stay on top of your relationships.</p>
    <form method="GET" action="<?= htmlspecialchars($SCCRM_BASE ?? '/sccrm') ?>/ai/" class="input-group mt-2" style="max-width:560px;">
        <span class="input-group-text bg-transparent"><i class="fas fa-robot text-primary"></i></span>
        <input type="text" name="q" class="form-control" placeholder="Ask TinyLLM — try /briefing, /ceo list, /leads 5…" aria-label="Ask TinyLLM">
        <button class="btn btn-primary">Ask AI</button>
    </form>
</div>

<div class="row g-3 mb-4">
    <div class="col-xl-2 col-lg-4 col-md-6">
        <div class="stats-card">
            <div class="icon blue"><i class="fas fa-users"></i></div>
            <div class="number"><?= $total_contacts ?></div>
            <div class="label">Total Contacts</div>
        </div>
    </div>
    <div class="col-xl-2 col-lg-4 col-md-6">
        <div class="stats-card">
            <div class="icon green"><i class="fas fa-building"></i></div>
            <div class="number"><?= $total_companies ?></div>
            <div class="label">Companies</div>
        </div>
    </div>
    <div class="col-xl-2 col-lg-4 col-md-6">
        <div class="stats-card">
            <div class="icon orange"><i class="fas fa-comments"></i></div>
            <div class="number"><?= $total_interactions ?></div>
            <div class="label">Interactions</div>
        </div>
    </div>
    <div class="col-xl-2 col-lg-4 col-md-6">
        <div class="stats-card">
            <div class="icon purple"><i class="fas fa-tasks"></i></div>
            <div class="number"><?= $total_tasks ?></div>
            <div class="label">Total Tasks</div>
        </div>
    </div>
    <div class="col-xl-2 col-lg-4 col-md-6">
        <div class="stats-card">
            <div class="icon red"><i class="fas fa-flag-checkered"></i></div>
            <div class="number"><?= $total_leads ?></div>
            <div class="label">Total Leads</div>
        </div>
    </div>
    <div class="col-xl-2 col-lg-4 col-md-6">
        <div class="stats-card">
            <div class="icon purple"><i class="fas fa-book"></i></div>
            <div class="number"><?= $total_kb ?></div>
            <div class="label">Articles</div>
        </div>
    </div>
    <div class="col-xl-2 col-lg-4 col-md-6">
        <div class="stats-card">
            <div class="icon blue"><i class="fas fa-question-circle"></i></div>
            <div class="number"><?= $total_faq ?></div>
            <div class="label">FAQs</div>
        </div>
    </div>
    <div class="col-xl-2 col-lg-4 col-md-6">
        <div class="stats-card">
            <div class="icon green"><i class="fas fa-headset"></i></div>
            <div class="number"><?= $open_tickets ?></div>
            <div class="label">Open Tickets</div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-xl-8">
        <div class="card-crm">
            <div class="card-header">
                <h6><i class="fas fa-clock me-2 text-primary"></i>Recent Interactions</h6>
                <a href="<?= $SCCRM_BASE ?>/interactions/" class="btn btn-sm btn-outline-secondary rounded-pill">View All</a>
            </div>
            <div class="card-body p-0">
                <?php if (count($recent_interactions) > 0): ?>
                <div class="activity-timeline p-4">
                    <?php foreach ($recent_interactions as $item): ?>
                    <div class="timeline-item">
                        <div class="time"><?= timeAgo($item['created_at']) ?></div>
                        <div class="title">
                            <span class="badge-type <?= $item['type'] ?> me-1"><?= ucfirst($item['type']) ?></span>
                            <?= htmlspecialchars($item['subject']) ?>
                        </div>
                        <div class="desc">
                            with <strong><?= htmlspecialchars($item['first_name'] . ' ' . $item['last_name']) ?></strong>
                            <?php if ($item['company_name']): ?> &middot; <?= htmlspecialchars($item['company_name']) ?><?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="empty-state"><i class="fas fa-inbox"></i><h6>No interactions yet</h6></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="card-crm mb-3">
            <div class="card-header">
                <h6><i class="fas fa-chart-donut me-2 text-success"></i>Task Status</h6>
            </div>
            <div class="card-body">
                <canvas id="taskChart" height="160"></canvas>
            </div>
        </div>
        <div class="card-crm mb-3">
            <div class="card-header">
                <h6><i class="fas fa-flag-checkered me-2 text-warning"></i>Lead Pipeline</h6>
                <a href="<?= $SCCRM_BASE ?>/leads/" class="btn btn-sm btn-outline-secondary rounded-pill">View All</a>
            </div>
            <div class="card-body">
                <canvas id="leadChart" height="160"></canvas>
            </div>
        </div>
        <div class="card-crm">
            <div class="card-header">
                <h6><i class="fas fa-chart-bar me-2 text-info"></i>Interaction Types</h6>
            </div>
            <div class="card-body">
                <canvas id="interactionChart" height="160"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-xl-4">
        <div class="card-crm">
            <div class="card-header">
                <h6><i class="fas fa-tasks me-2 text-purple"></i>Recent Tasks</h6>
                <a href="<?= $SCCRM_BASE ?>/tasks/" class="btn btn-sm btn-outline-secondary rounded-pill">View All</a>
            </div>
            <div class="card-body p-0">
                <?php if (count($recent_tasks) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-crm">
                        <thead><tr><th>Task</th><th>Priority</th><th>Status</th></tr></thead>
                        <tbody>
                            <?php foreach ($recent_tasks as $t): ?>
                            <tr>
                                <td><div class="fw-semibold" style="font-size:13px;"><?= htmlspecialchars($t['title']) ?></div></td>
                                <td><span class="badge-priority <?= $t['priority'] ?>"><?= ucfirst($t['priority']) ?></span></td>
                                <td><span class="badge-status <?= $t['status'] ?>"><?= str_replace('_', ' ', ucfirst($t['status'])) ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="empty-state"><i class="fas fa-clipboard-list"></i><h6>No tasks yet</h6></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="card-crm">
            <div class="card-header">
                <h6><i class="fas fa-flag-checkered me-2 text-warning"></i>Recent Leads</h6>
                <a href="<?= $SCCRM_BASE ?>/leads/" class="btn btn-sm btn-outline-secondary rounded-pill">View All</a>
            </div>
            <div class="card-body p-0">
                <?php if (count($recent_leads) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-crm">
                        <thead><tr><th>Lead</th><th>Market</th><th>Status</th></tr></thead>
                        <tbody>
                            <?php foreach ($recent_leads as $l): ?>
                            <tr>
                                <td><div class="fw-semibold" style="font-size:13px;"><?= htmlspecialchars(($l['first_name'] ?? '') . ' ' . ($l['last_name'] ?? '')) ?></div></td>
                                <td><span class="badge bg-light text-dark" style="font-size:11px;"><?= htmlspecialchars($l['market_name'] ?? '-') ?></span></td>
                                <td><span class="badge-status <?= $l['status'] ?>" style="font-size:11px;"><?= ucfirst($l['status']) ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="empty-state"><i class="fas fa-flag-checkered"></i><h6>No leads yet</h6><a href="<?= $SCCRM_BASE ?>/leads/generate.php" class="btn btn-success btn-sm mt-2"><i class="fas fa-bolt me-1"></i> Generate Leads</a></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="card-crm">
            <div class="card-header">
                <h6><i class="fas fa-user-plus me-2 text-info"></i>Recent Contacts</h6>
                <a href="<?= $SCCRM_BASE ?>/contacts/" class="btn btn-sm btn-outline-secondary rounded-pill">View All</a>
            </div>
            <div class="card-body p-0">
                <?php if (count($recent_contacts) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-crm">
                        <thead><tr><th>Name</th><th>Position</th><th>Company</th></tr></thead>
                        <tbody>
                            <?php foreach ($recent_contacts as $contact): ?>
                            <tr>
                                <td>
                                    <div class="contact-row">
                                        <div class="avatar-circle avatar-sm" style="background:<?= avatarColor($contact['id']) ?>"><?= getInitials($contact['first_name'] . ' ' . $contact['last_name']) ?></div>
                                        <div class="info"><div class="name" style="font-size:13px;"><?= htmlspecialchars($contact['first_name'] . ' ' . $contact['last_name']) ?></div></div>
                                    </div>
                                </td>
                                <td style="font-size:12px;"><?= htmlspecialchars($contact['position'] ?? '-') ?></td>
                                <td style="font-size:12px;"><?= htmlspecialchars($contact['company_name'] ?? '-') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="empty-state"><i class="fas fa-users"></i><h6>No contacts yet</h6></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var taskCtx = document.getElementById('taskChart');
    if (taskCtx) {
        new Chart(taskCtx, {
            type: 'doughnut',
            data: {
                labels: [<?php foreach($task_status_counts as $s): ?>'<?= str_replace('_',' ',ucfirst($s['status'])) ?>',<?php endforeach; ?>],
                datasets: [{
                    data: [<?php foreach($task_status_counts as $s): ?><?= $s['count'] ?>,<?php endforeach; ?>],
                    backgroundColor: ['#f39c12','#3498db','#2ecc71','#e74c3c'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, padding: 8, font: { size: 10 } } } },
                cutout: '65%'
            }
        });
    }
    var leadCtx = document.getElementById('leadChart');
    if (leadCtx) {
        new Chart(leadCtx, {
            type: 'doughnut',
            data: {
                labels: [<?php foreach($lead_pipeline as $p): ?>'<?= str_replace('_',' ',ucfirst($p['status'])) ?>',<?php endforeach; ?>],
                datasets: [{
                    data: [<?php foreach($lead_pipeline as $p): ?><?= $p['count'] ?>,<?php endforeach; ?>],
                    backgroundColor: ['#3498db','#f39c12','#9b59b6','#e67e22','#1abc9c','#2ecc71','#e74c3c'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, padding: 8, font: { size: 10 } } } },
                cutout: '65%'
            }
        });
    }
    var intCtx = document.getElementById('interactionChart');
    if (intCtx) {
        new Chart(intCtx, {
            type: 'doughnut',
            data: {
                labels: [<?php foreach($interaction_type_counts as $s): ?>'<?= ucfirst($s['type']) ?>',<?php endforeach; ?>],
                datasets: [{
                    data: [<?php foreach($interaction_type_counts as $s): ?><?= $s['count'] ?>,<?php endforeach; ?>],
                    backgroundColor: ['#2ecc71','#3498db','#9b59b6','#95a5a6','#2c3e50','#f39c12'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, padding: 8, font: { size: 10 } } } },
                cutout: '65%'
            }
        });
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

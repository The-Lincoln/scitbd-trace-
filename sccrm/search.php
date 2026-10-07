<?php
session_start();
require_once __DIR__ . '/includes/header.php';
$q = trim($_GET['q'] ?? '');

$contacts = $companies = $interactions = $tasks = [];
if ($q) {
    $like = "%$q%";
    $contacts = $db->prepare("SELECT c.*, co.name as company_name FROM contacts c LEFT JOIN companies co ON c.company_id = co.id WHERE c.first_name LIKE ? OR c.last_name LIKE ? OR c.email LIKE ? OR c.position LIKE ? OR c.department LIKE ? ORDER BY c.first_name");
    $contacts->execute([$like,$like,$like,$like,$like]);
    $contacts = $contacts->fetchAll();

    $companies = $db->prepare("SELECT * FROM companies WHERE name LIKE ? OR industry LIKE ? OR email LIKE ? OR city LIKE ? ORDER BY name");
    $companies->execute([$like,$like,$like,$like]);
    $companies = $companies->fetchAll();

    $interactions = $db->prepare("SELECT i.*, c.first_name, c.last_name, co.name as company_name FROM interactions i LEFT JOIN contacts c ON i.contact_id = c.id LEFT JOIN companies co ON i.company_id = co.id WHERE i.subject LIKE ? OR i.content LIKE ? ORDER BY i.date DESC LIMIT 10");
    $interactions->execute([$like,$like]);
    $interactions = $interactions->fetchAll();

    $tasks = $db->prepare("SELECT t.*, c.first_name, c.last_name, co.name as company_name FROM tasks t LEFT JOIN contacts c ON t.contact_id = c.id LEFT JOIN companies co ON t.company_id = co.id WHERE t.title LIKE ? OR t.description LIKE ? ORDER BY t.created_at DESC LIMIT 10");
    $tasks->execute([$like,$like]);
    $tasks = $tasks->fetchAll();
}
?>
<div class="page-title-area">
    <div>
        <h4>Search</h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= $SCCRM_BASE ?>/index.php" class="text-decoration-none">Dashboard</a></li>
                <li class="breadcrumb-item active">Search</li>
            </ol>
        </nav>
    </div>
</div>

<div class="card-crm mb-4">
    <div class="card-body">
        <form method="GET" class="row g-2">
            <div class="col-md-10">
                <div class="input-group">
                    <span class="input-group-text bg-transparent"><i class="fas fa-search text-muted"></i></span>
                    <input type="text" name="q" class="form-control form-control-lg" placeholder="Search contacts, companies, interactions, tasks..." value="<?= htmlspecialchars($q) ?>">
                </div>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100 h-100"><i class="fas fa-search me-1"></i> Search</button>
            </div>
        </form>
    </div>
</div>

<?php if ($q): ?>
<div class="row g-3">
    <div class="col-md-6">
        <div class="card-crm">
            <div class="card-header"><h6><i class="fas fa-users me-2 text-primary"></i> Contacts (<?= count($contacts) ?>)</h6></div>
            <div class="card-body p-0">
                <?php if (count($contacts) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-crm">
                        <thead><tr><th>Name</th><th>Position</th><th>Company</th><th>Email</th></tr></thead>
                        <tbody>
                            <?php foreach ($contacts as $c): ?>
                            <tr>
                                <td>
                                    <div class="contact-row">
                                        <div class="avatar-circle avatar-sm" style="background:<?= avatarColor($c['id']) ?>"><?= getInitials($c['first_name'].' '.$c['last_name']) ?></div>
                                        <div class="info"><a href="<?= $SCCRM_BASE ?>/contacts/view.php?id=<?= $c['id'] ?>" class="name text-decoration-none"><?= htmlspecialchars($c['first_name'].' '.$c['last_name']) ?></a></div>
                                    </div>
                                </td>
                                <td style="font-size:13px;"><?= htmlspecialchars($c['position'] ?? '-') ?></td>
                                <td style="font-size:13px;"><?= htmlspecialchars($c['company_name'] ?? '-') ?></td>
                                <td style="font-size:13px;"><?= htmlspecialchars($c['email'] ?? '-') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="empty-state py-3"><i class="fas fa-users"></i><h6>No contacts found</h6></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card-crm">
            <div class="card-header"><h6><i class="fas fa-building me-2 text-success"></i> Companies (<?= count($companies) ?>)</h6></div>
            <div class="card-body p-0">
                <?php if (count($companies) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-crm">
                        <thead><tr><th>Name</th><th>Industry</th><th>Email</th><th>City</th></tr></thead>
                        <tbody>
                            <?php foreach ($companies as $comp): ?>
                            <tr>
                                <td>
                                    <div class="contact-row">
                                        <div class="avatar-circle avatar-sm" style="background:<?= avatarColor($comp['id']) ?>"><i class="fas fa-building"></i></div>
                                        <div class="info"><a href="<?= $SCCRM_BASE ?>/companies/view.php?id=<?= $comp['id'] ?>" class="name text-decoration-none"><?= htmlspecialchars($comp['name']) ?></a></div>
                                    </div>
                                </td>
                                <td style="font-size:13px;"><?= htmlspecialchars($comp['industry'] ?? '-') ?></td>
                                <td style="font-size:13px;"><?= htmlspecialchars($comp['email'] ?? '-') ?></td>
                                <td style="font-size:13px;"><?= htmlspecialchars($comp['city'] ?? '-') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="empty-state py-3"><i class="fas fa-building"></i><h6>No companies found</h6></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card-crm">
            <div class="card-header"><h6><i class="fas fa-comments me-2 text-warning"></i> Interactions (<?= count($interactions) ?>)</h6></div>
            <div class="card-body p-0">
                <?php if (count($interactions) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-crm">
                        <thead><tr><th>Date</th><th>Type</th><th>Subject</th><th>Contact</th></tr></thead>
                        <tbody>
                            <?php foreach ($interactions as $i): ?>
                            <tr>
                                <td style="font-size:13px;"><?= formatDate($i['date']) ?></td>
                                <td><span class="badge-type <?= $i['type'] ?>"><?= ucfirst($i['type']) ?></span></td>
                                <td style="font-size:13px;"><?= htmlspecialchars($i['subject']) ?></td>
                                <td style="font-size:13px;"><?= htmlspecialchars($i['first_name'].' '.$i['last_name'] ?? '-') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="empty-state py-3"><i class="fas fa-comments"></i><h6>No interactions found</h6></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card-crm">
            <div class="card-header"><h6><i class="fas fa-tasks me-2 text-info"></i> Tasks (<?= count($tasks) ?>)</h6></div>
            <div class="card-body p-0">
                <?php if (count($tasks) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-crm">
                        <thead><tr><th>Title</th><th>Priority</th><th>Status</th><th>Due</th></tr></thead>
                        <tbody>
                            <?php foreach ($tasks as $t): ?>
                            <tr>
                                <td style="font-size:13px;"><?= htmlspecialchars($t['title']) ?></td>
                                <td><span class="badge-priority <?= $t['priority'] ?>"><?= ucfirst($t['priority']) ?></span></td>
                                <td><span class="badge-status <?= $t['status'] ?>"><?= str_replace('_',' ',ucfirst($t['status'])) ?></span></td>
                                <td style="font-size:13px;"><?= $t['due_date'] ? formatDate($t['due_date']) : '-' ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="empty-state py-3"><i class="fas fa-tasks"></i><h6>No tasks found</h6></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if (empty($contacts) && empty($companies) && empty($interactions) && empty($tasks)): ?>
<div class="card-crm">
    <div class="empty-state">
        <i class="fas fa-search"></i>
        <h6>No results found for "<?= htmlspecialchars($q) ?>"</h6>
        <p class="text-muted">Try different keywords or check your spelling.</p>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

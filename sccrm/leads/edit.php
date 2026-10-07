<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$_sccrm_db_boot = require_once __DIR__ . '/../config/database.php';
if (($_sccrm_db_boot instanceof PDO) && (!isset($db) || !($db instanceof PDO))) { $db = $_sccrm_db_boot; }
$id = $_GET['id'] ?? 0;
$lead = $db->prepare("SELECT * FROM leads WHERE id = ?");
$lead->execute([$id]); $lead = $lead->fetch();
if (!$lead) { header('Location: index.php'); exit; }
$markets = $db->query("SELECT id, name FROM markets WHERE is_active = 1 ORDER BY name")->fetchAll();
$services = $db->query("SELECT id, name, category FROM services WHERE is_active = 1 ORDER BY category, name")->fetchAll();
$contacts = $db->query("SELECT id, first_name, last_name FROM contacts ORDER BY first_name")->fetchAll();
$companies = $db->query("SELECT id, name FROM companies ORDER BY name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $score = 0;
    if (!empty($_POST['email'])) $score += 15;
    if (!empty($_POST['phone'])) $score += 10;
    if (!empty($_POST['company_name'])) $score += 15;
    if (!empty($_POST['budget'])) $score += 20;
    if (!empty($_POST['position'])) $score += 10;
    if ($_POST['priority'] === 'high' || $_POST['priority'] === 'urgent') $score += 15;
    if ($_POST['status'] === 'won') $_POST['converted_at'] = date('Y-m-d H:i:s');
    if ($_POST['status'] === 'contacted' && !$lead['contacted_at']) $_POST['contacted_at'] = date('Y-m-d H:i:s');
    $score = min($score, 100);
    $stmt = $db->prepare("UPDATE leads SET contact_id=?, company_id=?, market_id=?, service_id=?, first_name=?, last_name=?, email=?, phone=?, company_name=?, position=?, source=?, status=?, priority=?, notes=?, budget=?, score=?, contacted_at=COALESCE(?,contacted_at), converted_at=COALESCE(?,converted_at), updated_at=CURRENT_TIMESTAMP WHERE id=?");
    $stmt->execute([
        $_POST['contact_id'] ?: null, $_POST['company_id'] ?: null,
        $_POST['market_id'] ?: null, $_POST['service_id'] ?: null,
        $_POST['first_name'], $_POST['last_name'], $_POST['email'], $_POST['phone'],
        $_POST['company_name'], $_POST['position'], $_POST['source'] ?: 'manual',
        $_POST['status'] ?: 'new', $_POST['priority'] ?: 'medium',
        $_POST['notes'], $_POST['budget'] ?: null, $score,
        $_POST['contacted_at'] ?? null, $_POST['converted_at'] ?? null, $id
    ]);
    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Lead updated!'];
    header('Location: index.php');
    exit;
}
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-title-area">
    <div>
        <h4>Edit Lead</h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= $SCCRM_BASE ?>/index.php" class="text-decoration-none">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= $SCCRM_BASE ?>/leads/" class="text-decoration-none">Leads</a></li>
                <li class="breadcrumb-item active">Edit</li>
            </ol>
        </nav>
    </div>
</div>
<div class="card-crm">
    <div class="card-body">
        <form method="POST" class="row g-3">
            <div class="col-12"><hr class="my-1"><h6 class="text-primary">Lead Information</h6></div>
            <div class="col-md-4">
                <label class="form-label">First Name <span class="text-danger">*</span></label>
                <input type="text" name="first_name" class="form-control" required value="<?= htmlspecialchars($lead['first_name'] ?? '') ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Last Name <span class="text-danger">*</span></label>
                <input type="text" name="last_name" class="form-control" required value="<?= htmlspecialchars($lead['last_name'] ?? '') ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($lead['email'] ?? '') ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Phone</label>
                <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($lead['phone'] ?? '') ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Company</label>
                <input type="text" name="company_name" class="form-control" value="<?= htmlspecialchars($lead['company_name'] ?? '') ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Position</label>
                <input type="text" name="position" class="form-control" value="<?= htmlspecialchars($lead['position'] ?? '') ?>">
            </div>
            <div class="col-12"><hr class="my-1"><h6 class="text-primary">Lead Details</h6></div>
            <div class="col-md-3">
                <label class="form-label">Target Market</label>
                <select name="market_id" class="form-select">
                    <option value="">-- Select --</option>
                    <?php foreach ($markets as $m): ?>
                    <option value="<?= $m['id'] ?>" <?= $lead['market_id'] == $m['id'] ? 'selected' : '' ?>><?= htmlspecialchars($m['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Service</label>
                <select name="service_id" class="form-select">
                    <option value="">-- Select --</option>
                    <?php foreach ($services as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= $lead['service_id'] == $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <?php foreach (['new','contacted','qualified','proposal','negotiation','won','lost'] as $st): ?>
                    <option value="<?= $st ?>" <?= $lead['status'] === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Priority</label>
                <select name="priority" class="form-select">
                    <?php foreach (['low','medium','high','urgent'] as $pr): ?>
                    <option value="<?= $pr ?>" <?= $lead['priority'] === $pr ? 'selected' : '' ?>><?= ucfirst($pr) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Budget ($)</label>
                <input type="number" name="budget" class="form-control" step="0.01" value="<?= htmlspecialchars($lead['budget'] ?? '') ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Source</label>
                <select name="source" class="form-select">
                    <?php foreach (['manual','hourly_generator','web','referral','campaign'] as $val): ?>
                    <option value="<?= $val ?>" <?= $lead['source'] === $val ? 'selected' : '' ?>><?= str_replace('_',' ',ucfirst($val)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Link Contact</label>
                <select name="contact_id" class="form-select">
                    <option value="">-- None --</option>
                    <?php foreach ($contacts as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $lead['contact_id'] == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['first_name'].' '.$c['last_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">Link Company</label>
                <select name="company_id" class="form-select">
                    <option value="">-- None --</option>
                    <?php foreach ($companies as $comp): ?>
                    <option value="<?= $comp['id'] ?>" <?= $lead['company_id'] == $comp['id'] ? 'selected' : '' ?>><?= htmlspecialchars($comp['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-control" rows="3"><?= htmlspecialchars($lead['notes'] ?? '') ?></textarea>
            </div>
            <div class="col-12 d-flex gap-2">
                <button type="submit" class="btn btn-primary rounded-pill px-4"><i class="fas fa-save me-1"></i> Update Lead</button>
                <a href="<?= $SCCRM_BASE ?>/leads/" class="btn btn-outline-secondary rounded-pill px-4">Cancel</a>
            </div>
        </form>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>

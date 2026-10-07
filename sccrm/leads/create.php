<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$_sccrm_db_boot = require_once __DIR__ . '/../config/database.php';
if (($_sccrm_db_boot instanceof PDO) && (!isset($db) || !($db instanceof PDO))) { $db = $_sccrm_db_boot; }
$markets = $db->query("SELECT id, name FROM markets WHERE is_active = 1 ORDER BY name")->fetchAll();
$services = $db->query("SELECT id, name, category FROM services WHERE is_active = 1 ORDER BY category, name")->fetchAll();
$contacts = $db->query("SELECT id, first_name, last_name FROM contacts ORDER BY first_name")->fetchAll();
$companies = $db->query("SELECT id, name FROM companies ORDER BY name")->fetchAll();
$preselected_market = $_GET['market_id'] ?? '';
$preselected_service = $_GET['service_id'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $score = 0;
    if (!empty($_POST['email'])) $score += 15;
    if (!empty($_POST['phone'])) $score += 10;
    if (!empty($_POST['company_name'])) $score += 15;
    if (!empty($_POST['budget'])) $score += 20;
    if (!empty($_POST['position'])) $score += 10;
    if ($_POST['priority'] === 'high' || $_POST['priority'] === 'urgent') $score += 15;
    $score = min($score, 100);
    $stmt = $db->prepare("INSERT INTO leads (contact_id, company_id, market_id, service_id, first_name, last_name, email, phone, company_name, position, source, status, priority, notes, budget, score) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
        $_POST['contact_id'] ?: null, $_POST['company_id'] ?: null,
        $_POST['market_id'] ?: null, $_POST['service_id'] ?: null,
        $_POST['first_name'], $_POST['last_name'], $_POST['email'], $_POST['phone'],
        $_POST['company_name'], $_POST['position'], $_POST['source'] ?: 'manual',
        $_POST['status'] ?: 'new', $_POST['priority'] ?: 'medium',
        $_POST['notes'], $_POST['budget'] ?: null, $score
    ]);
    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Lead created successfully!'];
    header('Location: index.php');
    exit;
}
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-title-area">
    <div>
        <h4>Add Lead</h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= $SCCRM_BASE ?>/index.php" class="text-decoration-none">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= $SCCRM_BASE ?>/leads/" class="text-decoration-none">Leads</a></li>
                <li class="breadcrumb-item active">Add Lead</li>
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
                <input type="text" name="first_name" class="form-control" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Last Name <span class="text-danger">*</span></label>
                <input type="text" name="last_name" class="form-control" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control">
            </div>
            <div class="col-md-4">
                <label class="form-label">Phone</label>
                <input type="text" name="phone" class="form-control">
            </div>
            <div class="col-md-4">
                <label class="form-label">Company</label>
                <input type="text" name="company_name" class="form-control" placeholder="Company name">
            </div>
            <div class="col-md-4">
                <label class="form-label">Position</label>
                <input type="text" name="position" class="form-control" placeholder="e.g. CEO, Manager">
            </div>
            <div class="col-12"><hr class="my-1"><h6 class="text-primary">Lead Details</h6></div>
            <div class="col-md-4">
                <label class="form-label">Target Market</label>
                <select name="market_id" class="form-select">
                    <option value="">-- Select Market --</option>
                    <?php foreach ($markets as $m): ?>
                    <option value="<?= $m['id'] ?>" <?= $preselected_market == $m['id'] ? 'selected' : '' ?>><?= htmlspecialchars($m['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Service/Product</label>
                <select name="service_id" class="form-select">
                    <option value="">-- Select Service --</option>
                    <?php foreach ($services as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= $preselected_service == $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <?php foreach (['new','contacted','qualified','proposal','negotiation','won','lost'] as $st): ?>
                    <option value="<?= $st ?>" <?= $st === 'new' ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Priority</label>
                <select name="priority" class="form-select">
                    <?php foreach (['low','medium','high','urgent'] as $pr): ?>
                    <option value="<?= $pr ?>" <?= $pr === 'medium' ? 'selected' : '' ?>><?= ucfirst($pr) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Budget ($)</label>
                <input type="number" name="budget" class="form-control" step="0.01" min="0">
            </div>
            <div class="col-md-3">
                <label class="form-label">Source</label>
                <select name="source" class="form-select">
                    <?php foreach (['manual'=>'Manual Entry','hourly_generator'=>'Hourly Generator','web'=>'Website','referral'=>'Referral','campaign'=>'Campaign'] as $val=>$label): ?>
                    <option value="<?= $val ?>" <?= $val === 'manual' ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">Link to Contact (optional)</label>
                <select name="contact_id" class="form-select">
                    <option value="">-- Select Existing Contact --</option>
                    <?php foreach ($contacts as $c): ?>
                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['first_name'].' '.$c['last_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-control" rows="3"></textarea>
            </div>
            <div class="col-12 d-flex gap-2">
                <button type="submit" class="btn btn-primary rounded-pill px-4"><i class="fas fa-save me-1"></i> Create Lead</button>
                <a href="<?= $SCCRM_BASE ?>/leads/" class="btn btn-outline-secondary rounded-pill px-4">Cancel</a>
            </div>
        </form>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>

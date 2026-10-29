<?php
require_once __DIR__ . '/../../php_scripts/auth.php';
require_once __DIR__ . '/../../php_scripts/team_auth.php';
requirePermission('manage_settings');

$msg = '';
$msg_type = '';
$tab = $_GET['tab'] ?? 'general';

// ─── Handle form saves ───
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $msg = 'Invalid session token';
        $msg_type = 'danger';
    } else {
        $action = $_POST['settings_action'] ?? '';

        // Save general settings
        if ($action === 'save_settings') {
            $keys = ['app_name','company_name','default_lead_status','default_lead_stage','pagination_size','session_timeout','timezone'];
            $stmt = $link->prepare("INSERT INTO " . tn('TBL_APP_SETTINGS') . " (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = ?, updated_at = NOW()");
            $uid = USER_ID;
            foreach ($keys as $k) {
                $v = $_POST[$k] ?? '';
                $stmt->bind_param('ssi', $k, $v, $uid);
                $stmt->execute();
            }
            $msg = 'Settings saved successfully!';
            $msg_type = 'success';
            logActivity($link, USER_ID, 'UPDATE', 'Updated general settings');
        }

        // Upload / remove application logo
        if ($action === 'upload_logo') {
            $uploadDir = __DIR__ . '/../../uploads/logo/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

            if (isset($_POST['remove_logo']) && $_POST['remove_logo'] === '1') {
                $link->query("DELETE FROM " . tn('TBL_APP_SETTINGS') . " WHERE setting_key = 'logo_path'");
                $msg = 'Logo removed. Default branding restored.';
                $msg_type = 'success';
                logActivity($link, USER_ID, 'UPDATE', 'Removed application logo');
            } elseif (!empty($_FILES['logo_file']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
                $allowed = ['image/png', 'image/jpeg', 'image/jpg', 'image/svg+xml', 'image/gif', 'image/webp'];
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($finfo, $_FILES['logo_file']['tmp_name']);
                finfo_close($finfo);

                if (!in_array($mime, $allowed, true)) {
                    $msg = 'Invalid file type. Use PNG, JPG, SVG, GIF or WebP.';
                    $msg_type = 'danger';
                } elseif ($_FILES['logo_file']['size'] > 2 * 1024 * 1024) {
                    $msg = 'File too large. Max size is 2 MB.';
                    $msg_type = 'danger';
                } else {
                    $ext = array_search($mime, [
                        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
                        'svg' => 'image/svg+xml', 'gif' => 'image/gif', 'webp' => 'image/webp'
                    ], true);
                    $target = $uploadDir . 'app_logo.' . $ext;
                    foreach (glob($uploadDir . 'app_logo.*') as $old) @unlink($old);
                    $saved = move_uploaded_file($_FILES['logo_file']['tmp_name'], $target)
                        || copy($_FILES['logo_file']['tmp_name'], $target);
                    if ($saved) {
                        $rel = 'uploads/logo/app_logo.' . $ext;
                        $stmt = $link->prepare("INSERT INTO " . tn('TBL_APP_SETTINGS') . " (setting_key, setting_value) VALUES ('logo_path', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = ?, updated_at = NOW()");
                        $stmt->bind_param('si', $rel, USER_ID);
                        $stmt->execute();
                        $msg = 'Logo uploaded successfully!';
                        $msg_type = 'success';
                        logActivity($link, USER_ID, 'UPDATE', 'Uploaded application logo');
                    } else {
                        $msg = 'Failed to save the uploaded file.';
                        $msg_type = 'danger';
                    }
                }
            } else {
                $msg = 'No file selected.';
                $msg_type = 'danger';
            }
        }
    }
}

// ─── Fetch settings ───
$settings = [];
$sr = mysqli_query($link, "SELECT setting_key, setting_value FROM " . tn('TBL_APP_SETTINGS'));
if ($sr) while ($s = mysqli_fetch_assoc($sr)) $settings[$s['setting_key']] = $s['setting_value'];

// ─── Fetch users summary ───
$tu = mysqli_query($link, "SELECT COUNT(*) FROM " . tn('TBL_USERS') . " WHERE ROLE != 'Super Admin'");
$total_users = $tu ? (int)mysqli_fetch_row($tu)[0] : 0;
$au = mysqli_query($link, "SELECT COUNT(*) FROM " . tn('TBL_USERS') . " WHERE STATUS='Active' AND ROLE != 'Super Admin'");
$active_users = $au ? (int)mysqli_fetch_row($au)[0] : 0;
$role_counts = [];
$rc = mysqli_query($link, "SELECT ROLE, COUNT(*) as cnt FROM " . tn('TBL_USERS') . " WHERE ROLE != 'Super Admin' GROUP BY ROLE");
if ($rc) while ($r = mysqli_fetch_assoc($rc)) $role_counts[$r['ROLE']] = $r['cnt'];
?>
<?php $pageTitle = 'Settings - CallNow CRM'; include __DIR__ . '/../../php_scripts/header.php'; ?>

<div class="container-fluid px-3 py-3" style="max-width: 1100px;">

    <!-- Zoho CRM Module Header -->
    <div class="module-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Home</a></li>
                    <li class="breadcrumb-item"><a href="<?= url('modules/settings/settings.php') ?>">Administration</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Application Settings</li>
                </ol>
            </nav>
            <div class="d-flex align-items-center gap-2">
                <h1 class="page-title mb-0">System Configuration</h1>
                <span class="badge bg-secondary-subtle text-secondary border">Global</span>
            </div>
            <p class="text-muted small mb-0">General preferences, branding assets, telecalling defaults, and session parameters</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a href="<?= url('modules/settings/permissions_manager.php') ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-shield-lock me-1"></i> Access Control Hub
            </a>
            <a href="<?= url('modules/settings/api_settings.php') ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-key me-1"></i> API Settings
            </a>
        </div>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-<?= htmlspecialchars($msg_type ?: 'info'); ?> alert-dismissible fade show mb-3" role="alert">
            <i class="bi <?= $msg_type === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' ?> me-2"></i>
            <?= htmlspecialchars($msg); ?>
            <button class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Zoho CRM Navigation Tabs -->
    <ul class="nav nav-tabs mb-3 border-bottom">
        <li class="nav-item">
            <a class="nav-link <?= $tab==='general'?'active fw-semibold text-primary':'' ?>" href="<?= url('modules/settings/settings.php') ?>?tab=general">
                <i class="bi bi-sliders me-1"></i> General Preferences
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $tab==='branding'?'active fw-semibold text-primary':'' ?>" href="<?= url('modules/settings/settings.php') ?>?tab=branding">
                <i class="bi bi-palette me-1"></i> Branding &amp; Logo
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $tab==='users'?'active fw-semibold text-primary':'' ?>" href="<?= url('modules/settings/settings.php') ?>?tab=users">
                <i class="bi bi-people me-1"></i> User Statistics
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link text-muted" href="<?= url('modules/settings/api_settings.php') ?>">
                <i class="bi bi-phone me-1"></i> Mobile &amp; API Tokens
            </a>
        </li>
    </ul>

    <?php if ($tab === 'general'): ?>
    <!-- General Settings Form -->
    <div class="crm-card">
        <div class="card-header bg-white py-2 px-3 border-bottom">
            <span class="fw-semibold text-dark">Platform Parameters</span>
        </div>
        <div class="card-body p-4">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                <input type="hidden" name="settings_action" value="save_settings">

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label small text-muted mb-1">Application Name</label>
                        <input type="text" name="app_name" class="form-control form-control-sm" value="<?= htmlspecialchars($settings['app_name'] ?? 'CallNow CRM') ?>" required>
                        <div class="text-muted small mt-1">Displayed in browser title, navbar, and headers</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small text-muted mb-1">Company / Organization Name</label>
                        <input type="text" name="company_name" class="form-control form-control-sm" value="<?= htmlspecialchars($settings['company_name'] ?? 'CallNow') ?>" required>
                        <div class="text-muted small mt-1">Default organization entity for new accounts</div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label small text-muted mb-1">Default Lead Pipeline Status</label>
                        <select name="default_lead_status" class="form-select form-select-sm">
                            <?php foreach (['LEAD','FOLLOWUP','INTERNAL_UNDERWRITING','LOGIN','BANK_UNDERWRITING','SANCTIONED','DISBURSED','REJECT'] as $opt): ?>
                                <option value="<?= $opt ?>" <?= ($settings['default_lead_status'] ?? 'LEAD') === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small text-muted mb-1">Default Lead Temperature Stage</label>
                        <select name="default_lead_stage" class="form-select form-select-sm">
                            <?php foreach (['Cold','Warm','Hot'] as $opt): ?>
                                <option value="<?= $opt ?>" <?= ($settings['default_lead_stage'] ?? 'Cold') === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small text-muted mb-1">Default System Timezone</label>
                        <select name="timezone" class="form-select form-select-sm">
                            <?php foreach (['Asia/Kolkata','Asia/Dubai','Asia/Singapore','UTC'] as $opt): ?>
                                <option value="<?= $opt ?>" <?= ($settings['timezone'] ?? 'Asia/Kolkata') === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label small text-muted mb-1">Default Records Per Page</label>
                        <input type="number" name="pagination_size" class="form-control form-control-sm" value="<?= htmlspecialchars($settings['pagination_size'] ?? '50') ?>" min="10" max="200">
                        <div class="text-muted small mt-1">Default page length for DataTables and lead lists</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small text-muted mb-1">Session Inactivity Timeout (minutes)</label>
                        <input type="number" name="session_timeout" class="form-control form-control-sm" value="<?= htmlspecialchars($settings['session_timeout'] ?? '30') ?>" min="5" max="480">
                        <div class="text-muted small mt-1">Automatically logs out inactive telecallers</div>
                    </div>
                </div>

                <div class="d-flex justify-content-end gap-2 pt-3 mt-3 border-top">
                    <button type="submit" class="btn btn-primary btn-sm px-4">
                        <i class="bi bi-check-lg me-1"></i> Save Configuration
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php elseif ($tab === 'branding'): ?>
    <!-- Branding & Logo Form -->
    <div class="crm-card">
        <div class="card-header bg-white py-2 px-3 border-bottom">
            <span class="fw-semibold text-dark">Brand Assets &amp; Logo</span>
        </div>
        <div class="card-body p-4">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                <input type="hidden" name="settings_action" value="upload_logo">

                <div class="row g-4 align-items-center">
                    <div class="col-md-3 text-center">
                        <div class="p-3 border rounded bg-light d-inline-block">
                            <?php if (!empty($settings['logo_path'])): ?>
                                <img src="<?= htmlspecialchars((defined('APP_BASE') ? APP_BASE : '') . '/' . ltrim($settings['logo_path'], '/')) ?>" alt="App Logo" style="max-height: 80px; max-width: 160px; object-fit: contain;">
                            <?php else: ?>
                                <div style="width: 80px; height: 80px;" class="d-flex align-items-center justify-content-center text-muted border border-dashed rounded bg-white mx-auto">
                                    <i class="bi bi-image fs-1 text-secondary"></i>
                                </div>
                                <div class="text-muted small mt-1">Default CallNow Logo</div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-9">
                        <label class="form-label small text-muted mb-1">Upload Brand Logo</label>
                        <div class="input-group input-group-sm mb-2">
                            <input type="file" name="logo_file" class="form-control" accept="image/png,image/jpeg,image/svg+xml,image/gif,image/webp">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-upload me-1"></i> Upload Logo
                            </button>
                            <?php if (!empty($settings['logo_path'])): ?>
                                <button type="submit" name="remove_logo" value="1" class="btn btn-outline-danger" onclick="return confirm('Remove custom logo and restore default branding?');" title="Remove Logo">
                                    <i class="bi bi-trash"></i>
                                </button>
                            <?php endif; ?>
                        </div>
                        <div class="text-muted small">
                            Supported file formats: PNG, SVG, JPG, WebP. Maximum size: 2 MB. Transparent PNG or SVG recommended.
                        </div>
                    </div>
                </div>

                <div class="alert alert-info py-2 px-3 mt-4 mb-0 small">
                    <i class="bi bi-info-circle-fill me-2"></i>
                    Browsers aggressively cache application favicons and logos. After uploading, press <code>Ctrl + F5</code> to clear client cache.
                </div>
            </form>
        </div>
    </div>

    <?php elseif ($tab === 'users'): ?>
    <!-- User Overview Tab -->
    <div class="crm-card">
        <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
            <span class="fw-semibold text-dark">Staff &amp; Role Breakdown</span>
            <a href="<?= url('modules/users/users_add.php') ?>" class="btn btn-primary btn-sm">
                <i class="bi bi-person-plus me-1"></i> Add User
            </a>
        </div>
        <div class="card-body p-4">
            <div class="row g-2 mb-4">
                <div class="col-6 col-md-3">
                    <div class="metric-card">
                        <div class="metric-label">Total Users</div>
                        <div class="metric-value text-dark"><?= $total_users ?></div>
                        <div class="text-muted small">Registered agents</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="metric-card">
                        <div class="metric-label">Active Users</div>
                        <div class="metric-value text-success"><?= $active_users ?></div>
                        <div class="text-muted small">Ready to call</div>
                    </div>
                </div>
                <?php foreach ($role_counts as $roleName => $cnt): ?>
                    <div class="col-6 col-md-3">
                        <div class="metric-card">
                            <div class="metric-label"><?= htmlspecialchars($roleName) ?></div>
                            <div class="metric-value text-primary"><?= $cnt ?></div>
                            <div class="text-muted small">Allocated seats</div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="p-3 border rounded bg-light d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h6 class="fw-bold mb-1 text-dark small"><i class="bi bi-people text-primary me-1"></i> Full Staff Management</h6>
                    <p class="text-muted small mb-0">Navigate to the User Directory to manage logins, passwords, roles, and status.</p>
                </div>
                <a href="<?= url('modules/users/users_view.php') ?>" class="btn btn-outline-primary btn-sm">
                    Open Directory <i class="bi bi-arrow-right ms-1"></i>
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div>

<?php include __DIR__ . '/../../php_scripts/footer.php'; ?>

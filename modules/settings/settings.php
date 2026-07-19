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
            $stmt = $link->prepare("INSERT INTO TBL_APP_SETTINGS (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = ?, updated_at = NOW()");
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
                $link->query("DELETE FROM TBL_APP_SETTINGS WHERE setting_key = 'logo_path'");
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
                        $stmt = $link->prepare("INSERT INTO TBL_APP_SETTINGS (setting_key, setting_value) VALUES ('logo_path', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = ?, updated_at = NOW()");
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
$sr = mysqli_query($link, "SELECT setting_key, setting_value FROM TBL_APP_SETTINGS");
if ($sr) while ($s = mysqli_fetch_assoc($sr)) $settings[$s['setting_key']] = $s['setting_value'];

// ─── Fetch TBL_USERS summary ───
$tu = mysqli_query($link, "SELECT COUNT(*) FROM TBL_USERS");
$total_TBL_USERS = $tu ? (int)mysqli_fetch_row($tu)[0] : 0;
$au = mysqli_query($link, "SELECT COUNT(*) FROM TBL_USERS WHERE STATUS='Active'");
$active_TBL_USERS = $au ? (int)mysqli_fetch_row($au)[0] : 0;
$role_counts = [];
$rc = mysqli_query($link, "SELECT ROLE, COUNT(*) as cnt FROM TBL_USERS GROUP BY ROLE");
if ($rc) while ($r = mysqli_fetch_assoc($rc)) $role_counts[$r['ROLE']] = $r['cnt'];
?>
<?php $pageTitle = 'Settings - CallNow Admin'; include __DIR__ . '/../../php_scripts/header.php'; ?>

<style>
:root {
    --st-accent: var(--accent);
    --st-accent-dark: var(--accent-hover, #4f46e5);
    --st-ink: #1e1b4b;
    --st-ink-soft: #6b6890;
    --st-soft: #f0f2ff;
    --st-border: #e2e4f0;
}

.st-header {
    background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 50%, #a855f7 100%);
    border-radius: 1rem;
    padding: 1.5rem 2rem;
    margin-bottom: 1.5rem;
    position: relative;
    overflow: hidden;
}
.st-header::before {
    content: '';
    position: absolute;
    inset: 0;
    background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.06'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
    opacity: 0.3;
}
.st-header-content {
    position: relative; z-index: 1;
    display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;
}
.st-header-left h1 {
    font-size: 1.35rem; font-weight: 700; color: #fff;
    margin: 0 0 0.2rem 0; letter-spacing: -0.02em;
    display: flex; align-items: center; gap: 0.5rem;
}
.st-header-left h1 i { font-size: 1.4rem; }
.st-header-left p { color: rgba(255,255,255,0.7); font-size: 0.8125rem; margin: 0; }

/* ── Tabs ── */
.st-tabs {
    display: flex; gap: 0.25rem; background: var(--st-soft);
    border: 1px solid var(--st-border); border-radius: 0.75rem;
    padding: 0.25rem; margin-bottom: 1.25rem;
}
.st-tab {
    flex: 1; text-align: center; padding: 0.5rem 1rem;
    border-radius: 0.625rem; font-size: 0.8125rem; font-weight: 600;
    color: var(--st-ink-soft); text-decoration: none; transition: all 0.12s ease;
}
.st-tab:hover { color: var(--st-ink); background: rgba(255,255,255,0.6); }
.st-tab.active {
    background: #fff; color: var(--st-accent); box-shadow: 0 1px 4px rgba(0,0,0,0.06);
}
.st-tab i { margin-right: 0.35rem; }

/* ── Card ── */
.st-card {
    background: #fff; border: 1px solid var(--st-border);
    border-radius: 0.875rem; overflow: hidden;
    box-shadow: 0 1px 3px rgba(0,0,0,0.03);
}
.st-card-body { padding: 1.75rem 2rem; }

/* ── Alert ── */
.st-alert {
    border-radius: 0.625rem; font-size: 0.8125rem;
    padding: 0.75rem 1rem; margin-bottom: 1.25rem;
    display: flex; align-items: center; gap: 0.5rem;
}

/* ── Form ── */
.st-label {
    font-size: 0.75rem; font-weight: 600; color: var(--st-ink);
    margin-bottom: 0.3rem;
}
.st-hint {
    font-size: 0.6875rem; color: var(--st-ink-soft); margin-top: 0.15rem;
}
.st-input, .st-select {
    border: 1px solid var(--st-border) !important;
    border-radius: 0.5rem !important; font-size: 0.8125rem !important;
    color: var(--st-ink) !important; padding: 0.4rem 0.75rem !important;
    background: #fff !important;
}
.st-input:focus, .st-select:focus {
    border-color: var(--st-accent) !important;
    box-shadow: 0 0 0 3px rgba(99,102,241,0.12) !important;
    outline: none;
}
.st-btn-primary {
    background: linear-gradient(135deg, var(--st-accent), var(--st-accent-dark)) !important;
    border: none !important; color: #fff !important; border-radius: 0.5rem !important;
    font-size: 0.8125rem !important; font-weight: 600 !important;
    padding: 0.5rem 1.5rem !important;
    transition: all 0.15s ease !important;
    box-shadow: 0 2px 8px rgba(99,102,241,0.25) !important;
}
.st-btn-primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 14px rgba(99,102,241,0.35) !important;
}
.st-btn-danger {
    background: linear-gradient(135deg, #ef4444, #dc2626) !important;
    border: none !important; color: #fff !important; border-radius: 0.5rem !important;
    font-size: 0.75rem !important; font-weight: 600 !important;
    padding: 0.35rem 1rem !important;
    transition: all 0.15s ease !important;
}
.st-btn-outline {
    border: 1px solid var(--st-border) !important;
    background: #fff !important; color: var(--st-ink-soft) !important;
    border-radius: 0.5rem !important; font-size: 0.75rem !important;
    padding: 0.35rem 1rem !important;
}

/* ── Permission grid ── */
.st-perm-grid {
    width: 100%; border-collapse: separate; border-spacing: 0;
    font-size: 0.8125rem;
}
.st-perm-grid thead th {
    background: #fafbff; color: var(--st-ink-soft);
    font-weight: 600; font-size: 0.6875rem; text-transform: uppercase;
    letter-spacing: 0.04em; padding: 0.625rem 0.875rem;
    border-bottom: 2px solid var(--st-border);
    text-align: center; white-space: nowrap;
}
.st-perm-grid thead th:first-child { text-align: left; }
.st-perm-grid tbody td {
    padding: 0.5rem 0.875rem; border-bottom: 1px solid #f0f1f8;
    text-align: center; vertical-align: middle;
}
.st-perm-grid tbody td:first-child {
    text-align: left; font-weight: 600; color: var(--st-ink);
}
.st-perm-grid tbody tr:hover { background: #f8f9ff; }
.st-perm-grid tbody tr:last-child td { border-bottom: none; }
.st-perm-switch {
    width: 38px; height: 22px; position: relative; display: inline-block;
}
.st-perm-switch input { opacity: 0; width: 0; height: 0; }
.st-perm-slider {
    position: absolute; cursor: pointer; inset: 0;
    background: #d4d6e8; border-radius: 22px; transition: 0.2s;
}
.st-perm-slider::before {
    content: ''; position: absolute; height: 16px; width: 16px;
    left: 3px; bottom: 3px; background: #fff; border-radius: 50%; transition: 0.2s;
}
.st-perm-switch input:checked + .st-perm-slider {
    background: var(--st-accent);
}
.st-perm-switch input:checked + .st-perm-slider::before {
    transform: translateX(16px);
}

/* ── Stat cards ── */
.st-stat-grid {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 1rem; margin-bottom: 1.5rem;
}
.st-stat-card {
    background: var(--st-soft); border: 1px solid var(--st-border);
    border-radius: 0.75rem; padding: 1rem 1.25rem;
}
.st-stat-card .num {
    font-size: 1.5rem; font-weight: 700; color: var(--st-ink);
    line-height: 1.2;
}
.st-stat-card .lbl {
    font-size: 0.75rem; color: var(--st-ink-soft); margin: 0;
}

/* ── Role cards ── */
.st-role-grid {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: 1rem;
}
.st-role-card {
    background: #fff; border: 1px solid var(--st-border);
    border-radius: 0.75rem; padding: 1.25rem;
    transition: box-shadow 0.15s;
}
.st-role-card:hover { box-shadow: 0 2px 12px rgba(0,0,0,0.05); }
.st-role-card h4 {
    font-size: 0.95rem; font-weight: 700; color: var(--st-ink);
    margin: 0 0 0.2rem 0; display: flex; align-items: center; gap: 0.4rem;
}
.st-role-card .desc {
    font-size: 0.75rem; color: var(--st-ink-soft); margin-bottom: 0.5rem;
}
.st-role-badge {
    display: inline-block; font-size: 0.6rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.04em;
    padding: 0.15rem 0.4rem; border-radius: 0.25rem;
}
.st-role-badge.system {
    background: #6366f1; color: #fff;
}
.st-role-badge.custom {
    background: #f0f2ff; color: #6366f1;
}

@media (max-width: 767px) {
    .st-card-body { padding: 1.25rem; }
    .st-perm-grid { font-size: 0.75rem; }
    .st-perm-grid thead th, .st-perm-grid tbody td { padding: 0.4rem 0.5rem; }
}
</style>

<div class="container page-wrapper">

    <!-- ─── Header ─── -->
    <div class="st-header">
        <div class="st-header-content">
            <div class="st-header-left">
                <h1><i class="bi bi-gear-fill"></i> Settings</h1>
                <p>Manage application settings and TBL_USERS</p>
            </div>
            <div class="st-header-right">
                <a href="<?= url('modules/settings/TBL_PERMISSIONS_manager.php') ?>" class="st-btn-primary" style="text-decoration:none;font-size:0.8125rem!important;padding:0.5rem 1.1rem!important;display:inline-flex;align-items:center;gap:0.4rem;">
                    <i class="bi bi-shield-lock"></i> Manage TBL_ROLES &amp; TBL_PERMISSIONS
                </a>
            </div>
        </div>
    </div>

    <?php if ($msg): ?>
        <div class="st-alert" style="background:<?= $msg_type==='success'?'#d1fae5':'#fee2e2' ?>;border:1px solid <?= $msg_type==='success'?'#6ee7b7':'#fca5a5' ?>;color:<?= $msg_type==='success'?'#065f46':'#991b1b' ?>;">
            <i class="bi <?= $msg_type==='success'?'bi-check-circle-fill':'bi-x-circle-fill' ?>"></i>
            <?= htmlspecialchars($msg) ?>
            <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" style="font-size:0.65rem;"></button>
        </div>
    <?php endif; ?>

    <!-- ─── Tabs ─── -->
    <div class="st-tabs">
        <a href="<?= url('modules/settings/settings.php') ?>?tab=general" class="st-tab <?= $tab==='general'?'active':'' ?>">
            <i class="bi bi-sliders"></i> General
        </a>
        <a href="<?= url('modules/settings/settings.php') ?>?tab=TBL_USERS" class="st-tab <?= $tab==='TBL_USERS'?'active':'' ?>">
            <i class="bi bi-people"></i> TBL_USERS
        </a>
        <a href="<?= url('modules/settings/TBL_API_SETTINGS.php') ?>" class="st-tab <?= $tab==='api'?'active':'' ?>">
            <i class="bi bi-phone"></i> API Access
        </a>
    </div>

    <?php if ($tab === 'general'): ?>
    <!-- ═══ General Settings ═══ -->
    <div class="st-card">
        <div class="st-card-body">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                <input type="hidden" name="settings_action" value="save_settings">

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="st-label">Application Name</label>
                        <input type="text" name="app_name" class="form-control st-input" value="<?= htmlspecialchars($settings['app_name'] ?? 'CallNow CRM') ?>">
                        <div class="st-hint">Displayed in the browser title bar and header</div>
                    </div>
                    <div class="col-md-6">
                        <label class="st-label">Company Name</label>
                        <input type="text" name="company_name" class="form-control st-input" value="<?= htmlspecialchars($settings['company_name'] ?? 'CallNow') ?>">
                        <div class="st-hint">Default company for new TBL_USERS</div>
                    </div>
                    <div class="col-md-4">
                        <label class="st-label">Default Lead Status</label>
                        <select name="default_lead_status" class="form-select st-select">
                            <?php foreach (['LEAD','FOLLOWUP','INTERNAL_UNDERWRITING','LOGIN','BANK_UNDERWRITING','SANCTIONED','DISBURSED','REJECT'] as $opt): ?>
                                <option value="<?= $opt ?>" <?= ($settings['default_lead_status'] ?? 'LEAD') === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="st-label">Default Lead Stage</label>
                        <select name="default_lead_stage" class="form-select st-select">
                            <?php foreach (['Cold','Warm','Hot'] as $opt): ?>
                                <option value="<?= $opt ?>" <?= ($settings['default_lead_stage'] ?? 'Cold') === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="st-label">Timezone</label>
                        <select name="timezone" class="form-select st-select">
                            <?php foreach (['Asia/Kolkata','Asia/Dubai','Asia/Singapore','UTC'] as $opt): ?>
                                <option value="<?= $opt ?>" <?= ($settings['timezone'] ?? 'Asia/Kolkata') === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="st-label">Records Per Page</label>
                        <input type="number" name="pagination_size" class="form-control st-input" value="<?= htmlspecialchars($settings['pagination_size'] ?? '50') ?>" min="10" max="200">
                    </div>
                    <div class="col-md-4">
                        <label class="st-label">Session Timeout (minutes)</label>
                        <input type="number" name="session_timeout" class="form-control st-input" value="<?= htmlspecialchars($settings['session_timeout'] ?? '30') ?>" min="5" max="480">
                    </div>
                </div>

                <hr style="border-color:var(--st-border);margin:1.25rem 0;">

                <!-- ══ Application Logo ══ -->
                <form method="POST" enctype="multipart/form-data" class="mb-0">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                    <input type="hidden" name="settings_action" value="upload_logo">
                    <div class="row g-3 align-items-center">
                        <div class="col-md-2 text-center">
                            <?php if (!empty($settings['logo_path'])): ?>
                                <img src="<?= htmlspecialchars((defined('APP_BASE') ? APP_BASE : '') . '/' . ltrim($settings['logo_path'], '/')) ?>" alt="App Logo" style="max-height:64px;max-width:140px;object-fit:contain;border:1px solid var(--st-border);border-radius:0.5rem;padding:0.25rem;background:#fff;">
                            <?php else: ?>
                                <div style="width:64px;height:64px;border:1px dashed var(--st-border);border-radius:0.5rem;display:flex;align-items:center;justify-content:center;color:var(--st-ink-soft);margin:0 auto;"><i class="bi bi-image" style="font-size:1.5rem;"></i></div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-7">
                            <label class="st-label">Application Logo</label>
                            <input type="file" name="logo_file" class="form-control st-input" accept="image/png,image/jpeg,image/svg+xml,image/gif,image/webp">
                            <div class="st-hint">Shown in the sidebar and browser tab. PNG/SVG/JPG/WebP, max 2 MB. Recommended: square, transparent background.</div>
                        </div>
                        <div class="col-md-3 d-flex gap-2">
                            <button type="submit" class="st-btn-primary" style="font-size:0.75rem!important;padding:0.4rem 1rem!important;"><i class="bi bi-upload"></i> Upload</button>
                            <?php if (!empty($settings['logo_path'])): ?>
                                <button type="submit" name="remove_logo" value="1" class="st-btn-danger" onclick="return confirm('Remove the current logo and restore the default branding?');"><i class="bi bi-trash"></i></button>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>

                <hr style="border-color:var(--st-border);margin:1.25rem 0;">
                <div class="text-end">
                    <button type="submit" class="st-btn-primary"><i class="bi bi-check-lg"></i> Save Settings</button>
                </div>
            </form>
        </div>
    </div>

    <?php elseif ($tab === 'TBL_USERS'): ?>
    <!-- ═══ User Management ═══ -->
    <div class="st-card">
        <div class="st-card-body">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <div>
                    <h5 style="font-size:1rem;font-weight:700;color:var(--st-ink);margin:0;">User Overview</h5>
                    <p style="font-size:0.75rem;color:var(--st-ink-soft);margin:0;">Summary of all registered TBL_USERS</p>
                </div>
                <a href="<?= url('modules/TBL_USERS/TBL_USERS_add.php') ?>" class="st-btn-primary" style="font-size:0.75rem!important;padding:0.4rem 1rem!important;">
                    <i class="bi bi-person-plus"></i> Add User
                </a>
            </div>

            <div class="st-stat-grid">
                <div class="st-stat-card">
                    <div class="num"><?= $total_TBL_USERS ?></div>
                    <p class="lbl">Total TBL_USERS</p>
                </div>
                <div class="st-stat-card">
                    <div class="num"><?= $active_TBL_USERS ?></div>
                    <p class="lbl">Active</p>
                </div>
                <?php foreach ($role_counts as $role => $cnt): ?>
                    <div class="st-stat-card">
                        <div class="num"><?= $cnt ?></div>
                        <p class="lbl"><?= htmlspecialchars($role) ?></p>
                    </div>
                <?php endforeach; ?>
            </div>

            <hr style="border-color:var(--st-border);margin:1rem 0;">
            <p style="font-size:0.8125rem;color:var(--st-ink-soft);margin-bottom:0;">
                <i class="bi bi-arrow-right-circle"></i>
                <a href="<?= url('modules/TBL_USERS/TBL_USERS_view.php') ?>" style="color:var(--st-accent);font-weight:600;">Go to full User Management</a> to edit, filter, and manage all TBL_USERS.
            </p>
        </div>
    </div>

    <?php endif; ?>
</div>

<?php include __DIR__ . '/../../php_scripts/footer.php'; ?>

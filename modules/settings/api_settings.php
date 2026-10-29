<?php
require_once __DIR__ . '/../../php_scripts/auth.php';
requirePermission('manage_api');

global $link;
ensureApiSchema($link);

// Ensure manage_api permission exists for RBAC checks
require_once __DIR__ . '/../../php_scripts/permissions.php';
if (function_exists('seedApiPermission')) {
    seedApiPermission($link);
}

set_exception_handler(function ($e) {
    http_response_code(500);
    echo '<div class="alert alert-danger">API page error: ' . htmlspecialchars($e->getMessage()) . '</div>';
    exit;
});

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        http_response_code(500);
        echo '<div class="alert alert-danger">API page error: ' . htmlspecialchars($err['message']) . ' in ' . htmlspecialchars($err['file']) . ':' . $err['line'] . '</div>';
        exit;
    }
});

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Invalid CSRF token'];
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_settings') {
        $settings = [
            'api_enabled' => isset($_POST['api_enabled']) ? '1' : '0',
            'rate_limit_per_minute' => (int)($_POST['rate_limit_per_minute'] ?? 60),
            'rate_limit_per_hour' => (int)($_POST['rate_limit_per_hour'] ?? 1000),
            'token_expiry_days' => (int)($_POST['token_expiry_days'] ?? 90),
            'allowed_databases' => json_encode(array_filter([
                'temporary' => isset($_POST['db_temporary']) ? 'temporary' : null,
                'main' => isset($_POST['db_main']) ? 'main' : null,
                'leads' => isset($_POST['db_leads']) ? 'leads' : null,
            ])),
            'require_team_assignment' => isset($_POST['require_team_assignment']) ? '1' : '0',
            'log_requests' => isset($_POST['log_requests']) ? '1' : '0',
        ];
        
        foreach ($settings as $key => $value) {
            $stmt = @$link->prepare("INSERT INTO " . tn('TBL_API_SETTINGS') . " (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            if (!$stmt) continue;
            $stmt->bind_param('sss', $key, $value, $value);
            $stmt->execute();
        }
        
        logActivity($link, USER_ID, 'API_SETTINGS_UPDATED', 'Updated mobile API settings');
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'API settings saved successfully'];
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }
    
    if ($action === 'create_token') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $name = trim($_POST['token_name'] ?? 'Mobile App');
        $expiryDays = (int)($_POST['expiry_days'] ?? 90);
        
        if ($userId <= 0) {
            $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'User selection is required'];
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;
        }

        require_once __DIR__ . '/../../php_scripts/super_admin.php';
        $tokenLimitMsg = checkApiTokenLimit($link, USER_ID);
        if ($tokenLimitMsg) {
            $_SESSION['flash'] = ['type' => 'danger', 'msg' => $tokenLimitMsg];
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;
        }
        
        $token = bin2hex(random_bytes(32));
        $expiry = $expiryDays > 0 ? date('Y-m-d H:i:s', strtotime("+$expiryDays days")) : null;
        
        $stmt = @$link->prepare("INSERT INTO " . tn('TBL_API_TOKENS') . " (user_id, token, name, expires_at) VALUES (?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param('isss', $userId, $token, $name, $expiry);
            $stmt->execute();
        }

        // Auto-grant default DB access so the API is usable immediately
        require_once __DIR__ . '/../../php_scripts/api_auth.php';
        if (function_exists('grantDefaultApiAccess')) {
            grantDefaultApiAccess($userId);
        }
        
        logActivity($link, USER_ID, 'API_TOKEN_CREATED', "Created API token for user #$userId", '', 'TBL_API_TOKENS');
        $_SESSION['flash'] = ['type' => 'success', 'msg' => "API token created: $token (shown once only - copy it now)"];
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }
    
    if ($action === 'revoke_token') {
        $tokenId = (int)$_POST['token_id'] ?? 0;
        if ($tokenId) {
            $stmt = $link->prepare("UPDATE " . tn('TBL_API_TOKENS') . " SET is_active = 0 WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('i', $tokenId);
                $stmt->execute();
            }
            logActivity($link, USER_ID, 'API_TOKEN_REVOKED', "Revoked token #$tokenId", '', 'TBL_API_TOKENS');
        }
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Token access revoked successfully'];
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }
    
    if ($action === 'delete_token') {
        $tokenId = (int)$_POST['token_id'] ?? 0;
        if ($tokenId) {
            $stmt = $link->prepare("DELETE FROM " . tn('TBL_API_TOKENS') . " WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('i', $tokenId);
                $stmt->execute();
            }
            logActivity($link, USER_ID, 'API_TOKEN_DELETED', "Deleted token #$tokenId", '', 'TBL_API_TOKENS');
        }
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Token permanently deleted'];
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }
    
    if ($action === 'assign_database') {
        $userId = (int)($_POST['assign_user_id'] ?? 0);
        $databaseType = $_POST['assign_db_type'] ?? '';
        $teamId = isset($_POST['assign_team_id']) && $_POST['assign_team_id'] ? (int)$_POST['assign_team_id'] : null;
        
        if (!$userId || !$databaseType) {
            $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'User and database type required'];
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;
        }
        
        $stmt = @$link->prepare("INSERT INTO " . tn('TBL_API_DB_ASSIGNMENTS') . " (user_id, database_type, team_id, assigned_by) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE is_active = 1");
        if ($stmt) {
            $stmt->bind_param('isii', $userId, $databaseType, $teamId, USER_ID);
            $stmt->execute();
        }
        
        logActivity($link, USER_ID, 'API_ASSIGNMENT_CREATED', "Assigned $databaseType to user #$userId" . ($teamId ? " team #$teamId" : ''), '', 'TBL_API_DB_ASSIGNMENTS');
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Database assignment created'];
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }
    
    if ($action === 'remove_assignment') {
        $assignId = (int)($_POST['assign_id'] ?? 0);
        if ($assignId) {
            $stmt = $link->prepare("DELETE FROM " . tn('TBL_API_DB_ASSIGNMENTS') . " WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('i', $assignId);
                $stmt->execute();
            }
            logActivity($link, USER_ID, 'API_ASSIGNMENT_REMOVED', "Removed assignment #$assignId", '', 'TBL_API_DB_ASSIGNMENTS');
        }
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Assignment removed'];
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }
}

// Pagination setup
$perPage = 10;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

// Fetch settings
$settings = [];
$res = @$link->query("SELECT setting_key, setting_value FROM " . tn('TBL_API_SETTINGS'));
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $val = $row['setting_value'];
        $decoded = json_decode($val, true);
        if (json_last_error() === JSON_ERROR_NONE) $val = $decoded;
        $settings[$row['setting_key']] = $val;
    }
}

$tokens = [];
$totalTokens = 0;
$res = @$link->query("SELECT COUNT(*) FROM " . tn('TBL_API_TOKENS'));
if ($res) {
    $totalTokens = (int)$res->fetch_row()[0];
}
$res = @$link->query("
    SELECT t.*, u.NAME as user_name, u.EMAIL as user_email, u.ROLE as user_role
    FROM " . tn('TBL_API_TOKENS') . " t
    JOIN " . tn('TBL_USERS') . " u ON t.user_id = u.ID
    ORDER BY t.created_at DESC
    LIMIT $perPage OFFSET $offset
");
if ($res) {
    while ($row = $res->fetch_assoc()) $tokens[] = $row;
}

$activeUsers = [];
$res = @$link->query("SELECT ID, NAME, EMAIL, ROLE, TEAM_ID FROM " . tn('TBL_USERS') . " WHERE STATUS = 'Active' AND ROLE != 'Super Admin' ORDER BY ROLE, NAME");
if ($res) {
    while ($row = $res->fetch_assoc()) $activeUsers[] = $row;
}

$assignments = [];
$totalAssignments = 0;
$res = @$link->query("SELECT COUNT(*) FROM " . tn('TBL_API_DB_ASSIGNMENTS'));
if ($res) {
    $totalAssignments = (int)$res->fetch_row()[0];
}
$res = @$link->query("
    SELECT a.*, u.NAME as user_name, u.ROLE as user_role, u.TEAM_ID as user_team_id, 
           t.NAME as team_name, sup.NAME as assigned_by_name
    FROM " . tn('TBL_API_DB_ASSIGNMENTS') . " a
    JOIN " . tn('TBL_USERS') . " u ON a.user_id = u.ID
    LEFT JOIN " . tn('TBL_TEAMS') . " t ON a.team_id = t.ID
    LEFT JOIN " . tn('TBL_USERS') . " sup ON a.assigned_by = sup.ID
    ORDER BY a.assigned_at DESC
    LIMIT $perPage OFFSET $offset
");
if ($res) {
    while ($row = $res->fetch_assoc()) $assignments[] = $row;
}

$teamsList = [];
$res = @$link->query("SELECT ID, NAME FROM " . tn('TBL_TEAMS') . " ORDER BY NAME");
if ($res) {
    while ($row = $res->fetch_assoc()) $teamsList[] = $row;
}

$allowedDbs = $settings['allowed_databases'] ?? '["temporary","leads"]';
if (is_string($allowedDbs)) {
    $allowedDbs = json_decode($allowedDbs, true) ?: [];
}
$allowedDbs = is_array($allowedDbs) ? $allowedDbs : [];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Activity logs
$logPerPage = 20;
$logPage = max(1, (int)($_GET['log_page'] ?? 1));
$logOffset = ($logPage - 1) * $logPerPage;
$totalLogs = 0;
$resCount = @$link->query("SELECT COUNT(*) FROM " . tn('TBL_API_ACCESS_LOGS'));
if ($resCount) {
    $totalLogs = (int)$resCount->fetch_row()[0];
}
$totalLogPages = max(1, (int)ceil($totalLogs / $logPerPage));

$pageTitle = 'Mobile API Management - CallNow CRM';
include __DIR__ . '/../../php_scripts/header.php';
?>

<div class="container-fluid px-3 py-3">

    <!-- Zoho CRM Module Header -->
    <div class="module-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Home</a></li>
                    <li class="breadcrumb-item"><a href="<?= url('modules/settings/settings.php') ?>">Administration</a></li>
                    <li class="breadcrumb-item active" aria-current="page">API Management</li>
                </ol>
            </nav>
            <div class="d-flex align-items-center gap-2">
                <h1 class="page-title mb-0">Mobile &amp; Telecalling API</h1>
                <span class="badge <?= ($settings['api_enabled'] ?? '1') ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger' ?> border">
                    <?= ($settings['api_enabled'] ?? '1') ? 'Service Active' : 'Service Disabled' ?>
                </span>
            </div>
            <p class="text-muted small mb-0">Generate tokens for Android app, restrict database scope, and review endpoint consumption</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#apiTourGuide">
                <i class="bi bi-book me-1"></i> Documentation Guide
            </button>
            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createTokenModal">
                <i class="bi bi-plus-circle me-1"></i> Create API Token
            </button>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= htmlspecialchars($flash['type']) ?> alert-dismissible fade show mb-3" role="alert">
            <i class="bi <?= $flash['type'] === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' ?> me-2"></i>
            <?= htmlspecialchars($flash['msg']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- KPI Metric Summary -->
    <div class="row g-2 mb-3">
        <div class="col-6 col-md-3">
            <div class="metric-card">
                <div class="metric-label">Active API Tokens</div>
                <div class="metric-value text-primary"><?= number_format($totalTokens) ?></div>
                <div class="text-muted small">Generated app keys</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="metric-card">
                <div class="metric-label">Database Assignments</div>
                <div class="metric-value text-success"><?= number_format($totalAssignments) ?></div>
                <div class="text-muted small">Mapped permissions</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="metric-card">
                <div class="metric-label">Total Requests Logged</div>
                <div class="metric-value text-dark"><?= number_format($totalLogs) ?></div>
                <div class="text-muted small">Audit history records</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="metric-card">
                <div class="metric-label">Rate Limiting</div>
                <div class="metric-value text-secondary"><?= (int)($settings['rate_limit_per_minute'] ?? 60) ?>/min</div>
                <div class="text-muted small">Per client cap</div>
            </div>
        </div>
    </div>

    <!-- Collapsible API Integration Tour -->
    <div class="collapse mb-3" id="apiTourGuide">
        <div class="crm-card p-3">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-book-half text-primary me-2"></i>Mobile App Developer Quickstart</h6>
                <button type="button" class="btn-close btn-sm" data-bs-toggle="collapse" data-bs-target="#apiTourGuide"></button>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <div class="p-3 border rounded bg-light h-100">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="badge bg-primary rounded-circle" style="width: 24px; height: 24px; display: inline-flex; align-items: center; justify-content: center;">1</span>
                            <span class="fw-bold text-dark small">Create API Token</span>
                        </div>
                        <p class="text-muted small mb-0">Generate a unique authentication token for the mobile agent with designated expiration.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="p-3 border rounded bg-light h-100">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="badge bg-primary rounded-circle" style="width: 24px; height: 24px; display: inline-flex; align-items: center; justify-content: center;">2</span>
                            <span class="fw-bold text-dark small">Assign Database Access</span>
                        </div>
                        <p class="text-muted small mb-0">Authorize the user for Temporary, Main, or Leads database pools with optional team constraints.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="p-3 border rounded bg-light h-100">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="badge bg-primary rounded-circle" style="width: 24px; height: 24px; display: inline-flex; align-items: center; justify-content: center;">3</span>
                            <span class="fw-bold text-dark small">Authenticate Requests</span>
                        </div>
                        <p class="text-muted small mb-0">Supply header <code>Authorization: Bearer &lt;token&gt;</code> to base URL <code><?= htmlspecialchars((APP_BASE ?? '') . '/api/v1') ?></code>.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- API Configuration Card -->
    <div class="crm-card mb-3">
        <div class="card-header bg-white py-2 px-3 border-bottom">
            <span class="fw-semibold text-dark">Service &amp; Throttle Parameters</span>
        </div>
        <div class="card-body p-3">
            <form method="POST">
                <input type="hidden" name="action" value="save_settings">
                <?= csrfField() ?>
                
                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="api_enabled" id="api_enabled" <?= ($settings['api_enabled'] ?? '1') ? 'checked' : '' ?>>
                            <label class="form-check-label small fw-medium" for="api_enabled">Enable Mobile API Gateway</label>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="log_requests" id="log_requests" <?= ($settings['log_requests'] ?? '1') ? 'checked' : '' ?>>
                            <label class="form-check-label small fw-medium" for="log_requests">Record Endpoint Request Audit Logs</label>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="require_team_assignment" id="require_team_assignment" <?= ($settings['require_team_assignment'] ?? '1') ? 'checked' : '' ?>>
                            <label class="form-check-label small fw-medium" for="require_team_assignment">Require Team Scope for Access</label>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label class="form-label small text-muted mb-1">Rate Limit (Requests / minute)</label>
                        <input type="number" class="form-control form-control-sm" name="rate_limit_per_minute" value="<?= htmlspecialchars($settings['rate_limit_per_minute'] ?? 60) ?>" min="0" max="1000">
                        <div class="text-muted small mt-1">Set 0 for unlimited calls</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small text-muted mb-1">Rate Limit (Requests / hour)</label>
                        <input type="number" class="form-control form-control-sm" name="rate_limit_per_hour" value="<?= htmlspecialchars($settings['rate_limit_per_hour'] ?? 1000) ?>" min="0" max="10000">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small text-muted mb-1">Default Token Lifetime (days)</label>
                        <input type="number" class="form-control form-control-sm" name="token_expiry_days" value="<?= htmlspecialchars($settings['token_expiry_days'] ?? 90) ?>" min="0" max="365">
                        <div class="text-muted small mt-1">Set 0 for non-expiring keys</div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label small text-muted mb-1">Permitted Databases via Mobile API</label>
                    <div class="d-flex gap-3">
                        <?php foreach (['temporary' => 'Temporary Database', 'main' => 'Main Database', 'leads' => 'Leads Pool'] as $key => $label): ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="db_<?= $key ?>" id="db_<?= $key ?>" value="1" <?= in_array($key, $allowedDbs) ? 'checked' : '' ?>>
                                <label class="form-check-label small" for="db_<?= $key ?>"><?= $label ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="d-flex justify-content-end pt-2 border-top">
                    <button type="submit" class="btn btn-primary btn-sm px-4">
                        <i class="bi bi-save me-1"></i> Save Configuration
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- API Tokens Card -->
    <div class="crm-card mb-3">
        <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-2">
                <span class="fw-semibold text-dark">Active Client Tokens</span>
                <span class="badge bg-secondary-subtle text-secondary border"><?= $totalTokens ?> Total</span>
            </div>
            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createTokenModal">
                <i class="bi bi-plus-circle me-1"></i> New Token
            </button>
        </div>
        <div class="card-body p-0">
            <?php if (empty($tokens)): ?>
                <div class="text-center text-muted py-4">
                    <i class="bi bi-key fs-3 d-block mb-1 text-secondary"></i>
                    No API tokens registered. Create a token for mobile client sign-in.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table crm-table align-middle mb-0">
                        <thead>
                            <tr>
                                <th style="min-width: 170px;">Token Key</th>
                                <th style="min-width: 180px;">User Account</th>
                                <th style="width: 120px;">Client Label</th>
                                <th style="width: 130px;">Created</th>
                                <th style="width: 110px;">Expires</th>
                                <th style="width: 130px;">Last Activity</th>
                                <th style="width: 90px;">Status</th>
                                <th style="width: 130px;" class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tokens as $t): ?>
                                <tr>
                                    <td>
                                        <code class="text-dark bg-light px-2 py-1 rounded border font-monospace small" style="cursor: pointer;" title="Click to copy full token" onclick="navigator.clipboard.writeText('<?= $t['token'] ?>'); alert('Token copied to clipboard!');">
                                            <?= substr($t['token'], 0, 8) ?>...<?= substr($t['token'], -8) ?> <i class="bi bi-clipboard ms-1 text-muted"></i>
                                        </code>
                                    </td>
                                    <td>
                                        <div class="fw-medium text-dark small"><?= htmlspecialchars($t['user_name']) ?></div>
                                        <div class="text-muted font-monospace" style="font-size: 0.7rem;"><?= htmlspecialchars($t['user_email']) ?></div>
                                    </td>
                                    <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($t['name']) ?></span></td>
                                    <td class="small text-muted"><?= date('d M Y H:i', strtotime($t['created_at'])) ?></td>
                                    <td class="small text-muted"><?= $t['expires_at'] ? date('d M Y', strtotime($t['expires_at'])) : 'Never' ?></td>
                                    <td class="small text-muted"><?= $t['last_used_at'] ? date('d M Y H:i', strtotime($t['last_used_at'])) : 'Never' ?></td>
                                    <td>
                                        <?php if ($t['is_active']): ?>
                                            <span class="badge bg-success-subtle text-success border">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger-subtle text-danger border">Revoked</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($t['is_active']): ?>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Revoke this API token?');">
                                                <input type="hidden" name="action" value="revoke_token">
                                                <input type="hidden" name="token_id" value="<?= $t['id'] ?>">
                                                <?= csrfField() ?>
                                                <button type="submit" class="btn btn-sm btn-outline-warning px-2 py-0.5" title="Revoke Access"><i class="bi bi-x-circle"></i></button>
                                            </form>
                                            <form method="POST" class="d-inline ms-1" onsubmit="return confirm('Permanently delete this token?');">
                                                <input type="hidden" name="action" value="delete_token">
                                                <input type="hidden" name="token_id" value="<?= $t['id'] ?>">
                                                <?= csrfField() ?>
                                                <button type="submit" class="btn btn-sm btn-outline-danger px-2 py-0.5" title="Delete Token"><i class="bi bi-trash"></i></button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Database Assignments Card -->
    <div class="crm-card mb-3">
        <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-2">
                <span class="fw-semibold text-dark">Database Pool Assignments</span>
                <span class="badge bg-secondary-subtle text-secondary border"><?= $totalAssignments ?> Configured</span>
            </div>
            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#assignDbModal">
                <i class="bi bi-plus-circle me-1"></i> Add Assignment
            </button>
        </div>
        <div class="card-body p-0">
            <?php if (empty($assignments)): ?>
                <div class="text-center text-muted py-4">
                    <i class="bi bi-database-gear fs-3 d-block mb-1 text-secondary"></i>
                    No database pool assignments configured.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table crm-table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>User Account</th>
                                <th style="width: 120px;">Role</th>
                                <th style="width: 130px;">Database</th>
                                <th style="width: 140px;">Team Scope</th>
                                <th style="width: 140px;">Assigned By</th>
                                <th style="width: 120px;">Date</th>
                                <th style="width: 80px;" class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($assignments as $a): ?>
                                <tr>
                                    <td>
                                        <div class="fw-medium text-dark small"><?= htmlspecialchars($a['user_name']) ?></div>
                                    </td>
                                    <td><span class="badge bg-info-subtle text-primary border"><?= htmlspecialchars($a['user_role']) ?></span></td>
                                    <td>
                                        <span class="badge <?= $a['database_type'] === 'temporary' ? 'bg-warning-subtle text-warning border-warning' : ($a['database_type'] === 'main' ? 'bg-primary-subtle text-primary' : 'bg-success-subtle text-success') ?> border">
                                            <?= ucfirst($a['database_type']) ?>
                                        </span>
                                    </td>
                                    <td><?= $a['team_name'] ? htmlspecialchars($a['team_name']) : '<span class="text-muted small">All Teams</span>' ?></td>
                                    <td class="small text-muted"><?= htmlspecialchars($a['assigned_by_name'] ?? '—') ?></td>
                                    <td class="small text-muted"><?= date('d M Y', strtotime($a['assigned_at'])) ?></td>
                                    <td class="text-end">
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Remove this database assignment?');">
                                            <input type="hidden" name="action" value="remove_assignment">
                                            <input type="hidden" name="assign_id" value="<?= $a['id'] ?>">
                                            <?= csrfField() ?>
                                            <button type="submit" class="btn btn-sm btn-outline-danger px-2 py-0.5"><i class="bi bi-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent API Activity Logs Card -->
    <div class="crm-card mb-3">
        <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
            <span class="fw-semibold text-dark">Recent Endpoint Traffic</span>
            <span class="badge bg-secondary-subtle text-secondary border"><?= number_format($totalLogs) ?> Events</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table crm-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="width: 140px;">Timestamp</th>
                            <th>Caller</th>
                            <th>Endpoint</th>
                            <th style="width: 80px;">Method</th>
                            <th style="width: 80px;">HTTP</th>
                            <th style="width: 90px;">Latency</th>
                            <th style="width: 120px;">IP Address</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $logs = $link->query("
                            SELECT al.*, u.NAME as user_name
                            FROM " . tn('TBL_API_ACCESS_LOGS') . " al
                            LEFT JOIN " . tn('TBL_USERS') . " u ON al.user_id = u.ID
                            ORDER BY al.created_at DESC
                            LIMIT $logPerPage OFFSET $logOffset
                        ");
                        if ($logs && $logs->num_rows > 0):
                            while ($log = $logs->fetch_assoc()):
                        ?>
                            <tr>
                                <td class="small text-muted font-monospace"><?= date('d M H:i:s', strtotime($log['created_at'])) ?></td>
                                <td class="small fw-medium text-dark"><?= htmlspecialchars($log['user_name'] ?? 'Guest/Anonymous') ?></td>
                                <td><code class="text-dark bg-light px-1.5 py-0.5 rounded border small font-monospace"><?= htmlspecialchars($log['endpoint']) ?></code></td>
                                <td>
                                    <span class="badge bg-<?= $log['method'] === 'GET' ? 'info' : ($log['method'] === 'POST' ? 'success' : 'warning') ?>-subtle text-<?= $log['method'] === 'GET' ? 'primary' : ($log['method'] === 'POST' ? 'success' : 'dark') ?> border">
                                        <?= $log['method'] ?>
                                    </span>
                                </td>
                                <td>
                                    <?php 
                                        $code = (int)$log['response_code'];
                                        $cls = $code >= 500 ? 'bg-danger-subtle text-danger' : ($code >= 400 ? 'bg-warning-subtle text-warning' : 'bg-success-subtle text-success');
                                    ?>
                                    <span class="badge <?= $cls ?> border font-monospace"><?= $code ?></span>
                                </td>
                                <td class="small font-monospace"><?= $log['execution_time_ms'] ?>ms</td>
                                <td class="small text-muted font-monospace"><?= htmlspecialchars($log['ip_address'] ?? '—') ?></td>
                            </tr>
                        <?php 
                            endwhile;
                        else:
                        ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">No recent API requests logged.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Create Token Modal -->
<div class="modal fade" id="createTokenModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="create_token">
                <?= csrfField() ?>
                <div class="modal-header">
                    <h6 class="modal-title fw-bold"><i class="bi bi-key text-primary me-2"></i> Generate Mobile API Token</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-3">
                    <div class="mb-3">
                        <label class="form-label small text-muted mb-1">Target Agent Account <span class="text-danger">*</span></label>
                        <select class="form-select form-select-sm" name="user_id" required>
                            <option value="">Select agent...</option>
                            <?php foreach ($activeUsers as $u): ?>
                                <option value="<?= $u['ID'] ?>"><?= htmlspecialchars($u['NAME']) ?> (<?= htmlspecialchars($u['ROLE']) ?>) - <?= htmlspecialchars($u['EMAIL']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small text-muted mb-1">Device / Integration Label</label>
                        <input type="text" class="form-control form-control-sm" name="token_name" value="Mobile App" placeholder="e.g. Android Handset, Field Tablet">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small text-muted mb-1">Validity Period (days)</label>
                        <input type="number" class="form-control form-control-sm" name="expiry_days" value="90" min="0" max="365">
                        <div class="text-muted small mt-1">Set 0 for perpetual validity</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-key me-1"></i> Issue Token</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Assign Database Modal -->
<div class="modal fade" id="assignDbModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="assign_database">
                <?= csrfField() ?>
                <div class="modal-header">
                    <h6 class="modal-title fw-bold"><i class="bi bi-database-plus text-primary me-2"></i> Assign Database Scope</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-3">
                    <div class="mb-3">
                        <label class="form-label small text-muted mb-1">Agent Account <span class="text-danger">*</span></label>
                        <select class="form-select form-select-sm" name="assign_user_id" required>
                            <option value="">Select agent...</option>
                            <?php foreach ($activeUsers as $u): ?>
                                <option value="<?= $u['ID'] ?>"><?= htmlspecialchars($u['NAME']) ?> (<?= htmlspecialchars($u['ROLE']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small text-muted mb-1">Database Pool <span class="text-danger">*</span></label>
                        <select class="form-select form-select-sm" name="assign_db_type" required>
                            <option value="temporary">Temporary Database</option>
                            <option value="main">Main Database</option>
                            <option value="leads">Leads Pipeline</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small text-muted mb-1">Team Filter Scope (Optional)</label>
                        <select class="form-select form-select-sm" name="assign_team_id">
                            <option value="">All Operational Teams</option>
                            <?php foreach ($teamsList as $t): ?>
                                <option value="<?= $t['ID'] ?>"><?= htmlspecialchars($t['NAME']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="text-muted small mt-1">Optionally confine agent querying to a specific team</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i> Apply Scope</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../php_scripts/footer.php'; ?>

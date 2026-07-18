<?php
require_once __DIR__ . '/../../php_scripts/auth.php';
requirePermission('manage_api');

global $link;

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
            $stmt = $link->prepare("INSERT INTO api_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->bind_param('sss', $key, $value, $value);
            $stmt->execute();
        }
        
        logActivity($link, USER_ID, 'API_SETTINGS_UPDATED', 'Updated mobile API settings');
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'API settings saved'];
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }
    
    if ($action === 'create_token') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $name = trim($_POST['token_name'] ?? 'Mobile App');
        $expiryDays = (int)($_POST['expiry_days'] ?? 90);
        
        if ($userId <= 0) {
            $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'User required'];
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;
        }
        
        $token = bin2hex(random_bytes(32));
        $expiry = $expiryDays > 0 ? date('Y-m-d H:i:s', strtotime("+$expiryDays days")) : null;
        
        $stmt = $link->prepare("INSERT INTO api_tokens (user_id, token, name, expires_at) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('isss', $userId, $token, $name, $expiry);
        $stmt->execute();

        // Auto-grant default DB access so the API is usable immediately
        require_once __DIR__ . '/../../php_scripts/api_auth.php';
        grantDefaultApiAccess($userId);
        
        logActivity($link, USER_ID, 'API_TOKEN_CREATED', "Created API token for user #$userId", '', 'api_tokens');
        $_SESSION['flash'] = ['type' => 'success', 'msg' => "API token created: $token (shown once only)"];
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }
    
    if ($action === 'revoke_token') {
        $tokenId = (int)($_POST['token_id'] ?? 0);
        if ($tokenId) {
            $link->query("UPDATE api_tokens SET is_active = 0 WHERE id = $tokenId");
            logActivity($link, USER_ID, 'API_TOKEN_REVOKED', "Revoked token #$tokenId", '', 'api_tokens');
        }
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Token revoked'];
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
        
        $stmt = $link->prepare("INSERT INTO api_database_assignments (user_id, database_type, team_id, assigned_by) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE is_active = 1");
        $stmt->bind_param('isii', $userId, $databaseType, $teamId, USER_ID);
        $stmt->execute();
        
        logActivity($link, USER_ID, 'API_ASSIGNMENT_CREATED', "Assigned $databaseType to user #$userId" . ($teamId ? " team #$teamId" : ''), '', 'api_database_assignments');
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Database assignment created'];
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }
    
    if ($action === 'remove_assignment') {
        $assignId = (int)($_POST['assign_id'] ?? 0);
        if ($assignId) {
            $link->query("DELETE FROM api_database_assignments WHERE id = $assignId");
            logActivity($link, USER_ID, 'API_ASSIGNMENT_REMOVED', "Removed assignment #$assignId", '', 'api_database_assignments');
        }
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Assignment removed'];
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }
}

// Fetch data
$settings = [];
$res = $link->query("SELECT setting_key, setting_value FROM api_settings");
while ($row = $res->fetch_assoc()) {
    $val = $row['setting_value'];
    $decoded = json_decode($val, true);
    if (json_last_error() === JSON_ERROR_NONE) $val = $decoded;
    $settings[$row['setting_key']] = $val;
}

$tokens = [];
$res = $link->query("
    SELECT t.*, u.NAME as user_name, u.EMAIL as user_email, u.ROLE as user_role
    FROM api_tokens t
    JOIN users u ON t.user_id = u.ID
    ORDER BY t.created_at DESC
");
while ($row = $res->fetch_assoc()) $tokens[] = $row;

$users = [];
$res = $link->query("SELECT ID, NAME, EMAIL, ROLE, TEAM_ID FROM users WHERE STATUS = 'Active' ORDER BY ROLE, NAME");
while ($row = $res->fetch_assoc()) $users[] = $row;

$assignments = [];
$res = $link->query("
    SELECT a.*, u.NAME as user_name, u.ROLE as user_role, u.TEAM_ID as user_team_id, 
           t.NAME as team_name, sup.NAME as assigned_by_name
    FROM api_database_assignments a
    JOIN users u ON a.user_id = u.ID
    LEFT JOIN teams t ON a.team_id = t.ID
    LEFT JOIN users sup ON a.assigned_by = sup.ID
    ORDER BY a.assigned_at DESC
");
while ($row = $res->fetch_assoc()) $assignments[] = $row;

$teams = [];
$res = $link->query("SELECT ID, NAME FROM teams ORDER BY NAME");
while ($row = $res->fetch_assoc()) $teams[] = $row;

$allowedDbs = $settings['allowed_databases'] ?? '["temporary","leads"]';
if (is_string($allowedDbs)) {
    $allowedDbs = json_decode($allowedDbs, true) ?: [];
}
$allowedDbs = is_array($allowedDbs) ? $allowedDbs : [];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$pageTitle = 'API Access Management';
include __DIR__ . '/../../php_scripts/header.php';
?>

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0"><i class="bi bi-key me-2"></i>API Access Management</h1>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createTokenModal">
            <i class="bi bi-plus-circle me-1"></i> Create API Token
        </button>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show">
            <?= htmlspecialchars($flash['msg']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Settings Card -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-sliders me-2"></i>API Configuration</h5>
        </div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="action" value="save_settings">
                <?= csrfField() ?>
                
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="api_enabled" id="api_enabled" <?= ($settings['api_enabled'] ?? '1') ? 'checked' : '' ?>>
                            <label class="form-check-label" for="api_enabled">Enable Mobile API</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="log_requests" id="log_requests" <?= ($settings['log_requests'] ?? '1') ? 'checked' : '' ?>>
                            <label class="form-check-label" for="log_requests">Log All API Requests</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="require_team_assignment" id="require_team_assignment" <?= ($settings['require_team_assignment'] ?? '1') ? 'checked' : '' ?>>
                            <label class="form-check-label" for="require_team_assignment">Require Team Assignment for DB Access</label>
                        </div>
                    </div>
                </div>

                <hr>

                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label class="form-label">Rate Limit (per minute)</label>
                        <input type="number" class="form-control" name="rate_limit_per_minute" value="<?= htmlspecialchars($settings['rate_limit_per_minute'] ?? 60) ?>" min="0" max="1000">
                        <div class="form-text">0 = unlimited</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Rate Limit (per hour)</label>
                        <input type="number" class="form-control" name="rate_limit_per_hour" value="<?= htmlspecialchars($settings['rate_limit_per_hour'] ?? 1000) ?>" min="0" max="10000">
                        <div class="form-text">0 = unlimited</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Token Expiry (days)</label>
                        <input type="number" class="form-control" name="token_expiry_days" value="<?= htmlspecialchars($settings['token_expiry_days'] ?? 90) ?>" min="0" max="365">
                        <div class="form-text">0 = never expires</div>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-12">
                        <label class="form-label">Allowed Databases for Mobile API</label>
                        <div class="row g-2">
                            <?php foreach (['temporary' => 'Temporary Database', 'main' => 'Main Database', 'leads' => 'Leads Table'] as $key => $label): ?>
                                <div class="col-md-4">
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="checkbox" name="db_<?= $key ?>" id="db_<?= $key ?>" value="1" <?= in_array($key, $allowedDbs) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="db_<?= $key ?>"><?= $label ?></label>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i> Save Settings</button>
            </form>
        </div>
    </div>

    <!-- API Tokens -->
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="bi bi-key me-2"></i>API Tokens</h5>
            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createTokenModal">
                <i class="bi bi-plus-circle me-1"></i> Create API Token
            </button>
        </div>
        <div class="card-body">
            <?php if (empty($tokens)): ?>
                <p class="text-muted text-center py-3">No API tokens created yet</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Token (click to copy)</th>
                                <th>User</th>
                                <th>Name</th>
                                <th>Created</th>
                                <th>Expires</th>
                                <th>Last Used</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tokens as $t): ?>
                                <tr>
                                    <td>
                                        <code class="text-truncate d-block" style="max-width: 200px; cursor: pointer;" title="Click to copy" onclick="navigator.clipboard.writeText('<?= $t['token'] ?>')">
                                            <?= substr($t['token'], 0, 8) ?>...<?= substr($t['token'], -8) ?>
                                        </code>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars($t['user_name']) ?></strong><br>
                                        <small class="text-muted"><?= htmlspecialchars($t['user_email']) ?></small>
                                    </td>
                                    <td><?= htmlspecialchars($t['name']) ?></td>
                                    <td><?= date('M j, Y H:i', strtotime($t['created_at'])) ?></td>
                                    <td><?= $t['expires_at'] ? date('M j, Y', strtotime($t['expires_at'])) : '<span class="text-muted">Never</span>' ?></td>
                                    <td><?= $t['last_used_at'] ? date('M j, Y H:i', strtotime($t['last_used_at'])) : '<span class="text-muted">Never</span>' ?></td>
                                    <td>
                                        <?php if ($t['is_active']): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">Revoked</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($t['is_active']): ?>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Revoke this API token?');">
                                                <input type="hidden" name="action" value="revoke_token">
                                                <input type="hidden" name="token_id" value="<?= $t['id'] ?>">
                                                <?= csrfField() ?>
                                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-x-circle"></i> Revoke</button>
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

    <!-- Database Assignments -->
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="bi bi-database-gear me-2"></i>Database Assignments</h5>
            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#assignDbModal">
                <i class="bi bi-plus-circle me-1"></i> Add Assignment
            </button>
        </div>
        <div class="card-body">
            <?php if (empty($assignments)): ?>
                <p class="text-muted text-center py-3">No database assignments configured</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Role</th>
                                <th>Database</th>
                                <th>Team Filter</th>
                                <th>Assigned By</th>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($assignments as $a): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($a['user_name']) ?></strong><br>
                                        <small class="text-muted"><?= htmlspecialchars($a['user_email'] ?? '') ?></small>
                                    </td>
                                    <td><span class="badge bg-info"><?= htmlspecialchars($a['user_role']) ?></span></td>
                                    <td>
                                        <span class="badge bg-<?= $a['database_type'] === 'temporary' ? 'success' : ($a['database_type'] === 'main' ? 'primary' : 'warning') ?>">
                                            <?= ucfirst($a['database_type']) ?>
                                        </span>
                                    </td>
                                    <td><?= $a['team_name'] ? htmlspecialchars($a['team_name']) : '<span class="text-muted">All Teams</span>' ?></td>
                                    <td><?= htmlspecialchars($a['assigned_by_name'] ?? '—') ?></td>
                                    <td><?= date('M j, Y', strtotime($a['assigned_at'])) ?></td>
                                    <td>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Remove this assignment?');">
                                            <input type="hidden" name="action" value="remove_assignment">
                                            <input type="hidden" name="assign_id" value="<?= $a['id'] ?>">
                                            <?= csrfField() ?>
                                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
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

    <!-- Access Logs -->
    <div class="card">
        <div class="card-header"><h5 class="mb-0"><i class="bi bi-activity me-2"></i>Recent API Activity</h5></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>User</th>
                            <th>Endpoint</th>
                            <th>Method</th>
                            <th>Status</th>
                            <th>Duration</th>
                            <th>IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $logs = $link->query("
                            SELECT al.*, u.NAME as user_name
                            FROM api_access_logs al
                            LEFT JOIN users u ON al.user_id = u.ID
                            ORDER BY al.created_at DESC
                            LIMIT 50
                        ");
                        while ($log = $logs->fetch_assoc()):
                        ?>
                            <tr>
                                <td class="small"><?= date('M j, Y H:i:s', strtotime($log['created_at'])) ?></td>
                                <td><?= htmlspecialchars($log['user_name'] ?? 'Unknown') ?></td>
                                <td><code class="small"><?= htmlspecialchars($log['endpoint']) ?></code></td>
                                <td><span class="badge bg-<?= $log['method'] === 'GET' ? 'info' : ($log['method'] === 'POST' ? 'success' : 'warning') ?>"><?= $log['method'] ?></span></td>
                                <td>
                                    <?php 
                                        $code = (int)$log['response_code'];
                                        $cls = $code >= 500 ? 'danger' : ($code >= 400 ? 'warning' : 'success');
                                    ?>
                                    <span class="badge bg-<?= $cls ?>"><?= $code ?></span>
                                </td>
                                <td class="small"><?= $log['execution_time_ms'] ?>ms</td>
                                <td class="small text-muted"><?= htmlspecialchars($log['ip_address'] ?? '—') ?></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Create Token Modal -->
<div class="modal fade" id="createTokenModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="create_token">
                <?= csrfField() ?>
                <div class="modal-header">
                    <h5 class="modal-title">Create API Token</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">User <span class="text-danger">*</span></label>
                        <select class="form-select" name="user_id" required>
                            <option value="">Select user...</option>
                            <?php foreach ($users as $u): ?>
                                <option value="<?= $u['ID'] ?>"><?= htmlspecialchars($u['NAME']) ?> (<?= htmlspecialchars($u['ROLE']) ?>) - <?= htmlspecialchars($u['EMAIL']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Token Name</label>
                        <input type="text" class="form-control" name="token_name" value="Mobile App" placeholder="e.g. Mobile App, Partner Integration">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Expiry (days)</label>
                        <input type="number" class="form-control" name="expiry_days" value="90" min="0" max="365">
                        <div class="form-text">0 = never expires</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-key me-1"></i> Generate Token</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Assign Database Modal -->
    <div class="modal fade" id="assignDbModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="assign_database">
                    <?= csrfField() ?>
                    <div class="modal-header">
                        <h5 class="modal-title">Assign Database Access</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">User <span class="text-danger">*</span></label>
                            <select class="form-select" name="assign_user_id" required>
                                <option value="">Select user...</option>
                                <?php foreach ($users as $u): ?>
                                    <option value="<?= $u['ID'] ?>"><?= htmlspecialchars($u['NAME']) ?> (<?= htmlspecialchars($u['ROLE']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Database Type <span class="text-danger">*</span></label>
                            <select class="form-select" name="assign_db_type" required>
                                <option value="temporary">Temporary Database</option>
                                <option value="main">Main Database</option>
                                <option value="leads">Leads Table</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Team Filter (optional)</label>
                            <select class="form-select" name="assign_team_id">
                                <option value="">All Teams</option>
                                <?php foreach ($teams as $t): ?>
                                    <option value="<?= $t['ID'] ?>"><?= htmlspecialchars($t['NAME']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Limit access to specific team's data</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-database-plus me-1"></i> Assign</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

<?php include __DIR__ . '/../../php_scripts/footer.php'; ?>
<?php
/**
 * One-time setup: Creates app_settings and role_permissions tables
 * with default seed data. Run once after deploying the Settings module.
 *
 * Usage: Open in browser (Admin only) or run from CLI:
 *   php create_settings_tables.php
 */

require_once __DIR__ . '/config.php';

if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/php_scripts/auth.php';
    if (!isAdmin()) {
        http_response_code(403);
        exit('Admin access required');
    }
}

echo "Creating app_settings table...\n";
$link->query("CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT,
    updated_by INT DEFAULT NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

echo "Creating role_permissions table...\n";
$link->query("CREATE TABLE IF NOT EXISTS role_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role VARCHAR(50) NOT NULL,
    permission_key VARCHAR(50) NOT NULL,
    permission_value TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY uk_role_perm (role, permission_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Seed default settings
echo "Seeding default settings...\n";
$defaults = [
    'app_name' => 'CallNow CRM',
    'company_name' => '',
    'default_lead_status' => 'New',
    'default_lead_stage' => '1',
    'pagination_size' => '100',
    'session_timeout' => '30',
    'timezone' => 'Asia/Kolkata',
];
$stmt = $link->prepare("INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES (?, ?)");
foreach ($defaults as $k => $v) {
    $stmt->bind_param('ss', $k, $v);
    $stmt->execute();
}

// Seed default permissions (Admin = full access, others selective)
echo "Seeding default permissions...\n";
$roles = ['Admin','Manager','Supervisor','Officer'];
$permKeys = ['manage_users','manage_teams','manage_leads','manage_settings','export_data','delete_leads','view_reports','upload_data','manage_database','assign_leads'];

// Default matrix: 1 = allowed, 0 = denied
$defaultPerms = [
    'Admin'      => [1,1,1,1,1,1,1,1,1,1],
    'Manager'    => [1,1,1,0,1,0,1,1,1,1],
    'Supervisor' => [0,0,1,0,0,0,1,0,0,1],
    'Officer'    => [0,0,1,0,0,0,0,0,0,0],
];

$stmt = $link->prepare("INSERT IGNORE INTO role_permissions (role, permission_key, permission_value) VALUES (?, ?, ?)");
foreach ($defaultPerms as $role => $vals) {
    foreach ($permKeys as $i => $pk) {
        $v = $vals[$i];
        $stmt->bind_param('ssi', $role, $pk, $v);
        $stmt->execute();
    }
}

echo "Done! Tables created and seeded successfully.\n";

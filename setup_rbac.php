<?php
/**
 * Run this script ONCE to set up the RBAC system.
 * 1. Creates `roles` table
 * 2. Seeds system roles (Admin, Manager, Supervisor, Officer)
 * 3. Alters `users.ROLE` from ENUM to VARCHAR(50)
 * 4. Alters `role_permissions.role` from ENUM to VARCHAR(50)
 * 5. Adds `role_id` FK to users table
 *
 * Usage: php setup_rbac.php  OR  open in browser (Admin only)
 */
require_once __DIR__ . '/config.php';

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    require_once __DIR__ . '/php_scripts/auth.php';
    if (!isAdmin()) { http_response_code(403); exit('Admin only'); }
}

function msg($s) { global $isCli; echo $isCli ? "$s\n" : "<div>$s</div>"; }

// 1. Create roles table
msg("Creating `roles` table...");
$link->query("CREATE TABLE IF NOT EXISTS roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_name VARCHAR(50) NOT NULL UNIQUE,
    description VARCHAR(255) DEFAULT '',
    is_system TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// 2. Seed system roles
msg("Seeding system roles...");
$systemRoles = [
    ['Admin',      'Full system access — all permissions.'],
    ['Manager',    'Manages teams, users, leads, and data operations.'],
    ['Supervisor', 'Oversees team leads and reports.'],
    ['Officer',    'Basic lead management only.'],
];
$stmt = $link->prepare("INSERT IGNORE INTO roles (role_name, description, is_system) VALUES (?, ?, 1)");
foreach ($systemRoles as $r) {
    $stmt->bind_param('ss', $r[0], $r[1]);
    $stmt->execute();
}
msg("System roles seeded: " . $stmt->affected_rows . " inserted.");

// 3. Alter users.ROLE from ENUM to VARCHAR
msg("Altering `users.ROLE` to VARCHAR(50)...");
$chk = mysqli_query($link, "SHOW COLUMNS FROM users LIKE 'ROLE'");
$col = mysqli_fetch_assoc($chk);
if ($col && str_starts_with($col['Type'], 'enum')) {
    $link->query("ALTER TABLE users MODIFY COLUMN ROLE VARCHAR(50) DEFAULT 'Officer'");
    msg("  Done.");
} else {
    msg("  Already VARCHAR or not found, skipping.");
}

// 4. Add role_id column to users
msg("Adding `role_id` to users table...");
$chk2 = mysqli_query($link, "SHOW COLUMNS FROM users LIKE 'role_id'");
if (mysqli_num_rows($chk2) === 0) {
    $link->query("ALTER TABLE users ADD COLUMN role_id INT DEFAULT NULL AFTER ROLE,
                  ADD CONSTRAINT fk_user_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE SET NULL");
    // Populate role_id from existing ROLE names
    $link->query("UPDATE users u JOIN roles r ON u.ROLE = r.role_name SET u.role_id = r.id");
    msg("  Done with data migration.");
} else {
    msg("  Already exists, skipping.");
}

// 5. Alter role_permissions.role from ENUM to VARCHAR
msg("Altering `role_permissions.role` to VARCHAR(50)...");
$chk3 = mysqli_query($link, "SHOW COLUMNS FROM role_permissions LIKE 'role'");
$col3 = mysqli_fetch_assoc($chk3);
if ($col3 && str_starts_with($col3['Type'], 'enum')) {
    $link->query("ALTER TABLE role_permissions MODIFY COLUMN role VARCHAR(50) NOT NULL");
    msg("  Done.");
} else {
    msg("  Already VARCHAR, skipping.");
}

// 6. Ensure role_permissions has a UNIQUE key
msg("Ensuring unique constraint on role_permissions...");
$link->query("ALTER TABLE role_permissions ADD UNIQUE KEY IF NOT EXISTS uq_role_perm (role, permission_key)");

msg("\n=== RBAC setup complete! ===");
msg("System roles: Admin, Manager, Supervisor, Officer");
msg("You can now add custom roles in Settings > Roles.");
msg("Permission enforcement via can('key') is ready in auth.php.");

<?php
/**
 * Central permission seed + helpers for the DB-backed RBAC system.
 *
 * The `permissions` DB table is the runtime source of truth (DB-backed, addable via UI).
 * This file only defines the INITIAL seed data and helper functions. Include it once.
 */

if (!function_exists('getPermissionSeed')) {

    // Seed definition of the core permission keys.
    // is_system = 1 => protected from delete/rename in the UI.
    function getPermissionSeed(): array {
        return [
            'manage_users'    => ['label' => 'Manage Users',     'category' => 'Administration', 'description' => 'Create, edit and delete user accounts', 'is_system' => 1],
            'manage_teams'    => ['label' => 'Manage Teams',      'category' => 'Administration', 'description' => 'Create teams and assign members', 'is_system' => 1],
            'manage_settings' => ['label' => 'Manage Settings',   'category' => 'Administration', 'description' => 'Access the Settings hub and permission manager', 'is_system' => 1],
            'view_activity'   => ['label' => 'View Activity Log', 'category' => 'Administration', 'description' => 'Access the audit / activity log', 'is_system' => 1],
            'manage_database' => ['label' => 'Manage Database',   'category' => 'Data', 'description' => 'Edit, delete and transfer records in temporary & main databases', 'is_system' => 1],
            'upload_data'     => ['label' => 'Upload Data',       'category' => 'Data', 'description' => 'Import CSV files into the databases', 'is_system' => 1],
            'export_data'     => ['label' => 'Export Data',       'category' => 'Data', 'description' => 'Export database lists to CSV', 'is_system' => 1],
            'assign_leads'    => ['label' => 'Assign Leads',      'category' => 'Leads', 'description' => 'Assign leads / numbers to telecallers', 'is_system' => 1],
            'manage_leads'    => ['label' => 'Manage Leads',      'category' => 'Leads', 'description' => 'Create and edit leads', 'is_system' => 1],
            'delete_leads'    => ['label' => 'Delete Leads',      'category' => 'Leads', 'description' => 'Delete lead records', 'is_system' => 1],
            'view_reports'    => ['label' => 'View Reports',      'category' => 'Reporting', 'description' => 'Access call-performance reports', 'is_system' => 1],
        ];
    }

    // Default permission matrix for SYSTEM roles. Custom roles default to ALL OFF (secure by default).
    // Admin/Manager are treated as superusers in can(); listed here for seed completeness.
    function getSystemRoleDefaults(): array {
        return [
            'Admin'      => ['*' => 1],
            'Manager'    => ['*' => 1],
            'Supervisor' => [
                'manage_teams' => 1, 'assign_leads' => 1, 'manage_leads' => 1,
                'export_data' => 1, 'view_reports' => 1, 'view_activity' => 1,
            ],
            'Officer'    => [
                'manage_leads' => 1, 'view_reports' => 1,
            ],
        ];
    }

    // Seed the permissions table (idempotent). Call after the table exists.
    function seedPermissions(mysqli $link): int {
        $seed = getPermissionSeed();
        $stmt = mysqli_prepare($link, "INSERT IGNORE INTO permissions (permission_key, label, category, description, is_system) VALUES (?, ?, ?, ?, ?)");
        $count = 0;
        foreach ($seed as $key => $d) {
            mysqli_stmt_bind_param($stmt, 'ssssi', $key, $d['label'], $d['category'], $d['description'], $d['is_system']);
            if (mysqli_stmt_execute($stmt)) $count += mysqli_stmt_affected_rows($stmt);
        }
        return $count;
    }

    // Fetch all permissions from DB (runtime source of truth).
    function getAllPermissions(mysqli $link): array {
        $out = [];
        $res = mysqli_query($link, "SELECT id, permission_key, label, category, description, is_system FROM permissions ORDER BY category, label");
        if ($res) while ($r = mysqli_fetch_assoc($res)) $out[] = $r;
        return $out;
    }

    // Group permissions by category.
    function getPermissionsByCategory(mysqli $link): array {
        $grouped = [];
        foreach (getAllPermissions($link) as $p) {
            $grouped[$p['category']][] = $p;
        }
        return $grouped;
    }

    function permissionExists(mysqli $link, string $key): bool {
        $stmt = mysqli_prepare($link, "SELECT 1 FROM permissions WHERE permission_key = ?");
        mysqli_stmt_bind_param($stmt, 's', $key);
        mysqli_stmt_execute($stmt);
        return (bool) mysqli_stmt_num_rows($stmt);
    }

}

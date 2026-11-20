<?php
/**
 * Central permission seed + helpers for the DB-backed RBAC system.
 *
 * The `TBL_PERMISSIONS` DB table is the runtime source of truth (DB-backed, addable via UI).
 * This file only defines the INITIAL seed data and helper functions. Include it once.
 */

if (!function_exists('getTBL_PERMISSIONSeed')) {

    // Seed definition of the core permission keys.
    // is_system = 1 => protected from delete/rename in the UI.
    function getTBL_PERMISSIONSeed(): array {
        return [
            'manage_users'    => ['label' => 'Manage Users',     'category' => 'Administration', 'description' => 'Create, edit and delete user accounts', 'is_system' => 1],
            'manage_teams'    => ['label' => 'Manage Teams',      'category' => 'Administration', 'description' => 'Create teams and assign members', 'is_system' => 1],
            'manage_settings'     => ['label' => 'Manage Settings',   'category' => 'Administration', 'description' => 'Access the Settings hub and permission manager', 'is_system' => 1],
            'manage_api'          => ['label' => 'Manage API Access',  'category' => 'Integration',    'description' => 'Create and manage API tokens and database assignments', 'is_system' => 1],
            'view_activity'       => ['label' => 'View Activity Log', 'category' => 'Administration', 'description' => 'Access the audit / activity log', 'is_system' => 1],
            'manage_database'     => ['label' => 'Manage Database',   'category' => 'Data', 'description' => 'Edit, delete and transfer records in temporary & main databases', 'is_system' => 1],
            'upload_data'         => ['label' => 'Upload Data',       'category' => 'Data', 'description' => 'Import CSV files into the databases', 'is_system' => 1],
            'export_data'         => ['label' => 'Export Data',       'category' => 'Data', 'description' => 'Export database lists to CSV', 'is_system' => 1],
            'export_db'           => ['label' => 'Export Database',   'category' => 'Data', 'description' => 'Export and download complete database records (Main & Temporary DB)', 'is_system' => 1],
            'assign_leads'        => ['label' => 'Assign Leads',      'category' => 'Leads', 'description' => 'Assign leads / numbers to telecallers', 'is_system' => 1],
            'manage_leads'        => ['label' => 'Manage Leads',      'category' => 'Leads', 'description' => 'Create and edit leads', 'is_system' => 1],
            'delete_leads'        => ['label' => 'Delete Leads',      'category' => 'Leads', 'description' => 'Delete lead records', 'is_system' => 1],
            'view_reports'        => ['label' => 'View Reports',      'category' => 'Reporting', 'description' => 'Access call-performance reports', 'is_system' => 1],
            'manage_super_admin'  => ['label' => 'Manage Super Admin Panel', 'category' => 'Administration', 'description' => 'Access the super admin panel for limits, plans, and payment settings', 'is_system' => 1],
        ];
    }

    // Default permission matrix for SYSTEM TBL_ROLES. Custom TBL_ROLES default to ALL OFF (secure by default).
    // Admin/Manager/Super Admin are treated as superTBL_USERS in can(); listed here for seed completeness.
    function getSystemRoleDefaults(): array {
        // Existing defaults
        return [
            'Super Admin' => ['*' => 1],
            'Admin' => ['*' => 1],
            'Manager' => ['*' => 1, 'export_db' => 0],
            'Supervisor' => [
                'manage_users' => 1, 'manage_teams' => 1, 'assign_leads' => 1, 'manage_leads' => 1,
                'export_data' => 1, 'export_db' => 0, 'view_reports' => 1, 'view_activity' => 1,
            ],
            'Officer' => [
                'manage_leads' => 1, 'view_reports' => 1, 'view_activity' => 1, 'export_db' => 0,
            ],
        ];
    }

    function getPermissionSeed(): array {
        return getTBL_PERMISSIONSeed();
    }

    function seedExportDbPermission(mysqli $link): void {
        $perm = 'export_db';
        $label = 'Export Database';
        $category = 'Data';
        $description = 'Export and download complete database records (Main & Temporary DB)';
        $is_system = 1;
        if (!permissionExists($link, $perm)) {
            $stmt = mysqli_prepare($link, "INSERT INTO " . tn('TBL_PERMISSIONS') . " (permission_key, label, category, description, is_system) VALUES (?, ?, ?, ?, ?)");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'ssssi', $perm, $label, $category, $description, $is_system);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
        }

        $rolesRes = mysqli_query($link, "SELECT role_name FROM " . tn('TBL_ROLES'));
        if ($rolesRes) {
            $checkStmt = mysqli_prepare($link, "SELECT 1 FROM " . tn('TBL_ROLE_PERMISSIONS') . " WHERE role = ? AND permission_key = ?");
            $insertStmt = mysqli_prepare($link, "INSERT INTO " . tn('TBL_ROLE_PERMISSIONS') . " (role, permission_key, permission_value) VALUES (?, ?, ?)");
            while ($r = mysqli_fetch_assoc($rolesRes)) {
                $roleName = $r['role_name'];
                mysqli_stmt_bind_param($checkStmt, 'ss', $roleName, $perm);
                mysqli_stmt_execute($checkStmt);
                mysqli_stmt_store_result($checkStmt);
                if (mysqli_stmt_num_rows($checkStmt) === 0) {
                    $val = ($roleName === 'Admin' || $roleName === 'Super Admin') ? 1 : 0;
                    mysqli_stmt_bind_param($insertStmt, 'ssi', $roleName, $perm, $val);
                    mysqli_stmt_execute($insertStmt);
                }
            }
            mysqli_stmt_close($checkStmt);
            mysqli_stmt_close($insertStmt);
        }
    }

    function seedApiPermission(mysqli $link): void {
        $perm = 'manage_api';
        $label = 'Manage API Access';
        $category = 'Integration';
        $description = 'Create and manage API tokens and database assignments';
        $is_system = 1;
        if (!permissionExists($link, $perm)) {
            $stmt = mysqli_prepare($link, "INSERT INTO " . tn('TBL_PERMISSIONS') . " (permission_key, label, category, description, is_system) VALUES (?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, 'ssssi', $perm, $label, $category, $description, $is_system);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }

    function seedTBL_PERMISSIONS(mysqli $link): int {
        $seed = getTBL_PERMISSIONSeed();
        $stmt = mysqli_prepare($link, "INSERT IGNORE INTO " . tn('TBL_PERMISSIONS') . " (permission_key, label, category, description, is_system) VALUES (?, ?, ?, ?, ?)");
        $count = 0;
        foreach ($seed as $key => $d) {
            $label = $d['label'];
            $category = $d['category'];
            $description = $d['description'];
            $is_system = $d['is_system'];
            mysqli_stmt_bind_param($stmt, 'ssssi', $key, $label, $category, $description, $is_system);
            if (mysqli_stmt_execute($stmt)) $count += mysqli_stmt_affected_rows($stmt);
        }
        return $count;
    }

    function getAllTBL_PERMISSIONS(mysqli $link): array {
        seedExportDbPermission($link);
        $out = [];
        $res = mysqli_query($link, "SELECT id, permission_key, label, category, description, is_system FROM " . tn('TBL_PERMISSIONS') . " ORDER BY category, label");
        if ($res) while ($r = mysqli_fetch_assoc($res)) $out[] = $r;
        return $out;
    }

    function getAllPermissions(mysqli $link): array {
        return getAllTBL_PERMISSIONS($link);
    }

    function getPermissionsByCategory(mysqli $link): array {
        return getTBL_PERMISSIONSByCategory($link);
    }

    function getTBL_PERMISSIONSByCategory(mysqli $link): array {
        $grouped = [];
        foreach (getAllTBL_PERMISSIONS($link) as $p) {
            $grouped[$p['category']][] = $p;
        }
        return $grouped;
    }

    function permissionExists(mysqli $link, string $key): bool {
        $stmt = mysqli_prepare($link, "SELECT 1 FROM " . tn('TBL_PERMISSIONS') . " WHERE permission_key = ?");
        if (!$stmt) return false;
        mysqli_stmt_bind_param($stmt, 's', $key);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_store_result($stmt);
        $exists = (mysqli_stmt_num_rows($stmt) > 0);
        mysqli_stmt_close($stmt);
        return $exists;
    }

}

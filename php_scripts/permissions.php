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
            'manage_TBL_USERS'    => ['label' => 'Manage TBL_USERS',     'category' => 'Administration', 'description' => 'Create, edit and delete user accounts', 'is_system' => 1],
            'manage_TBL_TEAMS'    => ['label' => 'Manage TBL_TEAMS',      'category' => 'Administration', 'description' => 'Create TBL_TEAMS and assign members', 'is_system' => 1],
            'manage_settings'     => ['label' => 'Manage Settings',   'category' => 'Administration', 'description' => 'Access the Settings hub and permission manager', 'is_system' => 1],
            'manage_api'          => ['label' => 'Manage API Access',  'category' => 'Integration',    'description' => 'Create and manage API tokens and database assignments', 'is_system' => 1],
            'view_activity'       => ['label' => 'View Activity Log', 'category' => 'Administration', 'description' => 'Access the audit / activity log', 'is_system' => 1],
            'manage_database'     => ['label' => 'Manage Database',   'category' => 'Data', 'description' => 'Edit, delete and transfer records in temporary & main databases', 'is_system' => 1],
            'upload_data'         => ['label' => 'Upload Data',       'category' => 'Data', 'description' => 'Import CSV files into the databases', 'is_system' => 1],
            'export_data'         => ['label' => 'Export Data',       'category' => 'Data', 'description' => 'Export database lists to CSV', 'is_system' => 1],
            'assign_leads'        => ['label' => 'Assign Leads',      'category' => 'Leads', 'description' => 'Assign leads / numbers to telecallers', 'is_system' => 1],
            'manage_leads'        => ['label' => 'Manage Leads',      'category' => 'Leads', 'description' => 'Create and edit leads', 'is_system' => 1],
            'delete_leads'        => ['label' => 'Delete Leads',      'category' => 'Leads', 'description' => 'Delete lead records', 'is_system' => 1],
            'view_reports'        => ['label' => 'View Reports',      'category' => 'Reporting', 'description' => 'Access call-performance reports', 'is_system' => 1],
        ];
    }

    // Default permission matrix for SYSTEM TBL_ROLES. Custom TBL_ROLES default to ALL OFF (secure by default).
    // Admin/Manager are treated as superTBL_USERS in can(); listed here for seed completeness.
    function getSystemRoleDefaults(): array {
        return [
            'Admin'      => ['*' => 1],
            'Manager'    => ['*' => 1],
            'Supervisor' => [
                'manage_TBL_TEAMS' => 1, 'assign_leads' => 1, 'manage_leads' => 1,
                'export_data' => 1, 'view_reports' => 1, 'view_activity' => 1,
            ],
            'Officer'    => [
                'manage_leads' => 1, 'view_reports' => 1,
            ],
        ];
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

    // Seed the TBL_PERMISSIONS table (idempotent). Call after the table exists.
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

    // Fetch all TBL_PERMISSIONS from DB (runtime source of truth).
    function getAllTBL_PERMISSIONS(mysqli $link): array {
        $out = [];
        $res = mysqli_query($link, "SELECT id, permission_key, label, category, description, is_system FROM " . tn('TBL_PERMISSIONS') . " ORDER BY category, label");
        if ($res) while ($r = mysqli_fetch_assoc($res)) $out[] = $r;
        return $out;
    }

    // Group TBL_PERMISSIONS by category.
    function getTBL_PERMISSIONSByCategory(mysqli $link): array {
        $grouped = [];
        foreach (getAllTBL_PERMISSIONS($link) as $p) {
            $grouped[$p['category']][] = $p;
        }
        return $grouped;
    }

    function permissionExists(mysqli $link, string $key): bool {
        $stmt = mysqli_prepare($link, "SELECT 1 FROM " . tn('TBL_PERMISSIONS') . " WHERE permission_key = ?");
        mysqli_stmt_bind_param($stmt, 's', $key);
        mysqli_stmt_execute($stmt);
        return (bool) mysqli_stmt_num_rows($stmt);
    }

}

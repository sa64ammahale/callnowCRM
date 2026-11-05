<?php
/**
 * Organizational Hierarchy Engine - CallNow CRM V5.00
 * Enforces 4-tier User Role Hierarchy:
 *   Super Admin -> Admin -> Supervisor -> Sales Officer
 */

require_once __DIR__ . '/config.php';

/**
 * Self-healing schema for direct organizational relationships
 */
function ensureHierarchySchema(mysqli $link): void {
    static $done = false;
    if ($done) return;

    try {
        $uCols = [];
        $res = $link->query("SHOW COLUMNS FROM " . tn('TBL_USERS'));
        if ($res) {
            while ($c = $res->fetch_assoc()) $uCols[$c['Field']] = true;
        }

        if (!isset($uCols['supervisor_id'])) {
            @$link->query("ALTER TABLE " . tn('TBL_USERS') . " ADD COLUMN supervisor_id INT NULL DEFAULT NULL AFTER TEAM_ID, ADD INDEX idx_user_supervisor (supervisor_id)");
        }
        if (!isset($uCols['admin_id'])) {
            @$link->query("ALTER TABLE " . tn('TBL_USERS') . " ADD COLUMN admin_id INT NULL DEFAULT NULL AFTER supervisor_id, ADD INDEX idx_user_admin (admin_id)");
        }

        $tCols = [];
        $tRes = $link->query("SHOW COLUMNS FROM " . tn('TBL_TEAMS'));
        if ($tRes) {
            while ($tc = $tRes->fetch_assoc()) $tCols[$tc['Field']] = true;
        }

        if (!isset($tCols['MANAGER_ID']) && !isset($tCols['manager_id'])) {
            @$link->query("ALTER TABLE " . tn('TBL_TEAMS') . " ADD COLUMN MANAGER_ID INT NULL DEFAULT NULL AFTER SUPERVISOR_ID, ADD INDEX idx_team_manager (MANAGER_ID)");
        }
    } catch (\Throwable $e) {}

    $done = true;
}

ensureHierarchySchema($GLOBALS['link']);

/**
 * Normalize role to one of the 4 primary hierarchy roles
 */
function getNormalizedRole(string $role): string {
    $r = trim($role);
    if (strcasecmp($r, 'Super Admin') === 0 || strcasecmp($r, 'superadmin') === 0) return 'Super Admin';
    if (strcasecmp($r, 'Admin') === 0 || strcasecmp($r, 'Manager') === 0) return 'Admin';
    if (strcasecmp($r, 'Supervisor') === 0) return 'Supervisor';
    return 'Officer';
}

/**
 * Hierarchy numeric level for permission comparison
 */
function getRoleHierarchyLevel(string $role): int {
    return match (getNormalizedRole($role)) {
        'Super Admin' => 4,
        'Admin'       => 3,
        'Supervisor'  => 2,
        'Officer'     => 1,
        default       => 0,
    };
}

/**
 * Super Admin check
 */
function isUserSuperAdmin(int $userId, string $role = ''): bool {
    if ($userId === 1) return true;
    return getNormalizedRole($role) === 'Super Admin';
}

/**
 * Get all Super Admin user IDs to guarantee strict isolation
 */
function getSuperAdminUserIds(mysqli $link): array {
    static $ids = null;
    if ($ids !== null) return $ids;

    $ids = [1];
    $res = $link->query("SELECT ID FROM " . tn('TBL_USERS') . " WHERE ROLE = 'Super Admin' OR ID = 1");
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $ids[] = (int)$r['ID'];
        }
    }
    $ids = array_values(array_unique($ids));
    return $ids;
}

/**
 * Get team IDs managed by an Admin
 */
function getAdminManagedTeamIds(mysqli $link, int $adminId): array {
    $teamIds = [];
    $stmt = $link->prepare("
        SELECT ID FROM " . tn('TBL_TEAMS') . " 
        WHERE MANAGER_ID = ? 
           OR SUPERVISOR_ID IN (SELECT ID FROM " . tn('TBL_USERS') . " WHERE admin_id = ?)
    ");
    if ($stmt) {
        $stmt->bind_param('ii', $adminId, $adminId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $teamIds[] = (int)$r['ID'];
        }
        $stmt->close();
    }
    return array_values(array_unique($teamIds));
}

/**
 * Get Supervisor IDs assigned to an Admin
 */
function getAdminAssignedSupervisorIds(mysqli $link, int $adminId): array {
    $superAdminIds = getSuperAdminUserIds($link);
    $supIds = [];

    $stmt = $link->prepare("
        SELECT DISTINCT u.ID 
        FROM " . tn('TBL_USERS') . " u
        LEFT JOIN " . tn('TBL_TEAMS') . " t ON (t.SUPERVISOR_ID = u.ID)
        WHERE u.ROLE IN ('Supervisor', 'Manager')
          AND u.ROLE != 'Super Admin'
          AND (t.MANAGER_ID = ? OR u.admin_id = ?)
    ");
    if ($stmt) {
        $stmt->bind_param('ii', $adminId, $adminId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $id = (int)$r['ID'];
            if (!in_array($id, $superAdminIds, true)) {
                $supIds[] = $id;
            }
        }
        $stmt->close();
    }

    return array_values(array_unique($supIds));
}

/**
 * Get Sales Officer IDs supervised by a Supervisor
 */
function getSupervisorAssignedOfficerIds(mysqli $link, int $supervisorId, ?int $supervisorTeamId = null): array {
    $superAdminIds = getSuperAdminUserIds($link);
    $officerIds = [];

    $teamIds = [];
    if ($supervisorTeamId && $supervisorTeamId > 0) $teamIds[] = $supervisorTeamId;
    $stmtT = $link->prepare("SELECT ID FROM " . tn('TBL_TEAMS') . " WHERE SUPERVISOR_ID = ?");
    if ($stmtT) {
        $stmtT->bind_param('i', $supervisorId);
        $stmtT->execute();
        $resT = $stmtT->get_result();
        while ($tr = $resT->fetch_assoc()) $teamIds[] = (int)$tr['ID'];
        $stmtT->close();
    }
    $teamIds = array_values(array_unique(array_filter($teamIds)));

    $whereParts = ["u.ROLE NOT IN ('Super Admin', 'Admin')"];
    $params = [];
    $types = '';

    if (!empty($teamIds)) {
        $in = implode(',', array_map('intval', $teamIds));
        $whereParts[] = "(u.TEAM_ID IN ($in) OR u.supervisor_id = ?)";
        $params[] = $supervisorId;
        $types .= 'i';
    } else {
        $whereParts[] = "u.supervisor_id = ?";
        $params[] = $supervisorId;
        $types .= 'i';
    }

    $sql = "SELECT u.ID FROM " . tn('TBL_USERS') . " u WHERE " . implode(' AND ', $whereParts);
    $stmt = $link->prepare($sql);
    if ($stmt) {
        if (!empty($types)) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $id = (int)$r['ID'];
            if (!in_array($id, $superAdminIds, true) && $id !== $supervisorId) {
                $officerIds[] = $id;
            }
        }
        $stmt->close();
    }

    return array_values(array_unique($officerIds));
}

/**
 * Get all Sales Officer IDs working under an Admin
 */
function getAdminAssignedOfficerIds(mysqli $link, int $adminId): array {
    $superAdminIds = getSuperAdminUserIds($link);
    $teamIds = getAdminManagedTeamIds($link, $adminId);
    $supIds  = getAdminAssignedSupervisorIds($link, $adminId);

    $officerIds = [];
    $whereOr = [];

    if (!empty($teamIds)) {
        $inT = implode(',', array_map('intval', $teamIds));
        $whereOr[] = "u.TEAM_ID IN ($inT)";
    }
    if (!empty($supIds)) {
        $inS = implode(',', array_map('intval', $supIds));
        $whereOr[] = "u.supervisor_id IN ($inS)";
    }
    $whereOr[] = "u.admin_id = " . (int)$adminId;

    $sql = "
        SELECT u.ID FROM " . tn('TBL_USERS') . " u 
        WHERE u.ROLE NOT IN ('Super Admin', 'Admin')
          AND (" . implode(' OR ', $whereOr) . ")
    ";
    $res = $link->query($sql);
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $id = (int)$r['ID'];
            if (!in_array($id, $superAdminIds, true) && $id !== $adminId) {
                $officerIds[] = $id;
            }
        }
    }

    return array_values(array_unique($officerIds));
}

/**
 * Get all team IDs authorized for a user based on role and assignments
 */
function getAuthorizedTeamIds(mysqli $link, int $userId, string $role, ?int $userTeamId = null): array {
    $normRole = getNormalizedRole($role);
    if ($normRole === 'Super Admin' || $userId === 1) {
        $res = $link->query("SELECT ID FROM " . tn('TBL_TEAMS') . " ORDER BY NAME");
        $tIds = [];
        if ($res) while ($r = $res->fetch_assoc()) $tIds[] = (int)$r['ID'];
        return $tIds;
    }

    if ($normRole === 'Admin') {
        return getAdminManagedTeamIds($link, $userId);
    }

    if ($normRole === 'Supervisor') {
        $tIds = [];
        if ($userTeamId && $userTeamId > 0) $tIds[] = $userTeamId;
        $stmt = $link->prepare("SELECT ID FROM " . tn('TBL_TEAMS') . " WHERE SUPERVISOR_ID = ?");
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) $tIds[] = (int)$r['ID'];
            $stmt->close();
        }
        return array_values(array_unique(array_filter($tIds)));
    }

    // Officer
    return ($userTeamId && $userTeamId > 0) ? [$userTeamId] : [];
}

/**
 * Authoritative list of user IDs accessible to the actor.
 * Strict rules:
 * - Super Admin: has global access, but Super Admin accounts are excluded when querying subordinate staff.
 * - Admin: sees self + assigned Supervisors + Sales Officers under those Supervisors. (NO other Admins, NO Super Admin).
 * - Supervisor: sees self + assigned Sales Officers. (NO other Supervisors, NO Admins, NO Super Admin).
 * - Sales Officer: sees strictly self-only.
 */
function getAuthorizedUserIds(mysqli $link, int $userId, string $role, ?int $userTeamId = null): array {
    $normRole = getNormalizedRole($role);
    $superAdminIds = getSuperAdminUserIds($link);

    if ($normRole === 'Super Admin' || $userId === 1) {
        $all = [];
        $res = $link->query("SELECT ID FROM " . tn('TBL_USERS') . " WHERE STATUS = 'Active'");
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $all[] = (int)$r['ID'];
            }
        }
        return $all;
    }

    if ($normRole === 'Admin') {
        $supIds = getAdminAssignedSupervisorIds($link, $adminId = $userId);
        $offIds = getAdminAssignedOfficerIds($link, $userId);
        $combined = array_merge([$userId], $supIds, $offIds);
        // Exclude Super Admins
        $accessible = array_diff($combined, $superAdminIds);
        return array_values(array_unique($accessible));
    }

    if ($normRole === 'Supervisor') {
        $offIds = getSupervisorAssignedOfficerIds($link, $userId, $userTeamId);
        $combined = array_merge([$userId], $offIds);
        $accessible = array_diff($combined, $superAdminIds);
        return array_values(array_unique($accessible));
    }

    // Officer: strictly self-only
    return [$userId];
}

/**
 * Access check for a specific target user ID.
 * Returns true if actor is authorized to view or manage target.
 */
function canUserAccessTargetUser(mysqli $link, int $actorId, string $actorRole, int $targetUserId): bool {
    $normRole = getNormalizedRole($actorRole);
    $superAdminIds = getSuperAdminUserIds($link);

    // If target is Super Admin, ONLY Super Admin can access
    if (in_array($targetUserId, $superAdminIds, true)) {
        return ($normRole === 'Super Admin' || $actorId === 1);
    }

    // Super Admin has global access to non-superadmin targets
    if ($normRole === 'Super Admin' || $actorId === 1) {
        return true;
    }

    // Self access is always allowed
    if ($actorId === $targetUserId) {
        return true;
    }

    // Officers cannot view any other user
    if ($normRole === 'Officer') {
        return false;
    }

    $accessible = getAuthorizedUserIds($link, $actorId, $actorRole);
    return in_array($targetUserId, $accessible, true);
}

/**
 * SQL WHERE clause generator to scope queries to authorized users.
 */
function getHierarchyUserFilterSQL(mysqli $link, int $actorId, string $actorRole, string $colOrAlias = 'user_id', string|int|null $colOrTeamId = null, ?int $userTeamId = null): string {
    $normRole = getNormalizedRole($actorRole);

    // Support both signatures:
    // Signature A: (link, actorId, actorRole, col, userTeamId)
    // Signature B: (link, actorId, actorRole, alias, col, userTeamId)
    if (is_string($colOrTeamId)) {
        $col = "{$colOrAlias}.{$colOrTeamId}";
        $teamId = $userTeamId;
    } else {
        $col = $colOrAlias;
        $teamId = is_int($colOrTeamId) ? $colOrTeamId : $userTeamId;
    }

    $superAdminExclusion = " AND $col NOT IN (" . implode(',', getSuperAdminUserIds($link)) . ")";

    if ($normRole === 'Super Admin' || $actorId === 1) {
        return "1=1";
    }

    if ($normRole === 'Officer') {
        return "$col = " . (int)$actorId;
    }

    $accessible = getAuthorizedUserIds($link, $actorId, $actorRole, $teamId);
    if (empty($accessible)) {
        return "$col = " . (int)$actorId;
    }

    return "$col IN (" . implode(',', array_map('intval', $accessible)) . ")" . $superAdminExclusion;
}

/**
 * SQL WHERE clause generator for Client Contacts table
 */
function getHierarchyContactFilterSQL(mysqli $link, int $actorId, string $actorRole, string $alias = 'm', ?int $userTeamId = null): string {
    $col = "{$alias}.MAINDATABASE_CALL_DIALED_USER";
    $normRole = getNormalizedRole($actorRole);

    if ($normRole === 'Super Admin' || $actorId === 1) {
        return "1=1";
    }

    if ($normRole === 'Officer') {
        return "$col = " . (int)$actorId;
    }

    $accessible = getAuthorizedUserIds($link, $actorId, $actorRole, $userTeamId);
    if (empty($accessible)) {
        return "$col = " . (int)$actorId;
    }

    return "$col IN (" . implode(',', array_map('intval', $accessible)) . ")";
}

/**
 * SQL WHERE clause generator for Leads table
 */
function getHierarchyLeadFilterSQL(mysqli $link, int $actorId, string $actorRole, string $alias = 'l', ?int $userTeamId = null): string {
    $col = "{$alias}.assigned_to";
    $normRole = getNormalizedRole($actorRole);

    if ($normRole === 'Super Admin' || $actorId === 1) {
        return "1=1";
    }

    if ($normRole === 'Officer') {
        return "$col = " . (int)$actorId;
    }

    $accessible = getAuthorizedUserIds($link, $actorId, $actorRole, $userTeamId);
    if (empty($accessible)) {
        return "$col = " . (int)$actorId;
    }

    return "$col IN (" . implode(',', array_map('intval', $accessible)) . ")";
}

/**
 * Role promotion and assignment permission validator
 */
function canActorAssignRole(string $actorRole, string $targetRole): bool {
    $actorNorm  = getNormalizedRole($actorRole);
    $targetNorm = getNormalizedRole($targetRole);

    if ($actorNorm === 'Super Admin') return true;
    if ($actorNorm === 'Admin') {
        // Admin can only assign Supervisor or Officer
        return in_array($targetNorm, ['Supervisor', 'Officer'], true);
    }
    return false;
}

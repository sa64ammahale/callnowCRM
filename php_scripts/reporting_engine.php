<?php
if (!defined('APP_BASE')) {
    require_once __DIR__ . '/../config.php';
}
require_once __DIR__ . '/reporting_schema.php';
require_once __DIR__ . '/hierarchy_engine.php';

ensureReportingAndConversionSchema($GLOBALS['link']);

/**
 * Fetch configured reporting targets for a given role (defaults to Officer)
 */
function getReportingTargets(mysqli $link, string $role = 'Officer'): array {
    $defaults = [
        'role' => $role,
        'daily_call_target' => 100,
        'daily_connected_target' => 30,
        'daily_talk_time_minutes' => 120,
        'daily_lead_target' => 3,
        'min_connect_rate_pct' => 25.00,
        'max_overdue_followups' => 5,
    ];

    $stmt = $link->prepare("SELECT * FROM " . tn('TBL_REPORTING_TARGETS') . " WHERE role = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $role);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            return array_merge($defaults, $row);
        }
    }
    return $defaults;
}

/**
 * Save / Update reporting targets (Super Admin only)
 */
function saveReportingTargets(mysqli $link, array $targets, string $role = 'Officer'): bool {
    $stmt = $link->prepare("
        INSERT INTO " . tn('TBL_REPORTING_TARGETS') . "
            (role, daily_call_target, daily_connected_target, daily_talk_time_minutes, daily_lead_target, min_connect_rate_pct, max_overdue_followups)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            daily_call_target = VALUES(daily_call_target),
            daily_connected_target = VALUES(daily_connected_target),
            daily_talk_time_minutes = VALUES(daily_talk_time_minutes),
            daily_lead_target = VALUES(daily_lead_target),
            min_connect_rate_pct = VALUES(min_connect_rate_pct),
            max_overdue_followups = VALUES(max_overdue_followups)
    ");
    if (!$stmt) return false;
    $c = (int)($targets['daily_call_target'] ?? 100);
    $conn = (int)($targets['daily_connected_target'] ?? 30);
    $talk = (int)($targets['daily_talk_time_minutes'] ?? 120);
    $lead = (int)($targets['daily_lead_target'] ?? 3);
    $rate = (float)($targets['min_connect_rate_pct'] ?? 25.00);
    $od = (int)($targets['max_overdue_followups'] ?? 5);

    $stmt->bind_param('siiiidi', $role, $c, $conn, $talk, $lead, $rate, $od);
    $res = $stmt->execute();
    $stmt->close();
    return $res;
}

/**
 * Determine effective authorized scope for any actor
 */
function getEffectiveReportingScope(mysqli $link, ?int $userId, ?int $teamId, ?int $supervisorId, string $userRole): array {
    $normRole = getNormalizedRole($userRole);
    $actorId = defined('USER_ID') ? (int)USER_ID : ($userId ?? 0);
    $uTeam = defined('USER_TEAM_ID') ? (int)USER_TEAM_ID : null;

    if ($normRole === 'Officer') {
        return [
            'mode' => 'officer',
            'user_ids' => [$actorId],
            'team_ids' => $uTeam ? [$uTeam] : [],
            'supervisor_ids' => [],
        ];
    }

    if ($normRole === 'Supervisor') {
        $assignedOfficers = getSupervisorAssignedOfficerIds($link, $actorId, $uTeam);
        $allowedUserIds = array_merge([$actorId], $assignedOfficers);
        $allowedTeamIds = getAuthorizedTeamIds($link, $actorId, 'Supervisor', $uTeam);

        $filteredUserIds = ($userId && in_array($userId, $allowedUserIds, true)) ? [$userId] : $allowedUserIds;
        $filteredTeamIds = ($teamId && in_array($teamId, $allowedTeamIds, true)) ? [$teamId] : $allowedTeamIds;

        return [
            'mode' => 'supervisor',
            'user_ids' => $filteredUserIds,
            'team_ids' => $filteredTeamIds,
            'supervisor_ids' => [$actorId],
        ];
    }

    if ($normRole === 'Admin') {
        $assignedSups = getAdminAssignedSupervisorIds($link, $actorId);
        $assignedOfficers = getAdminAssignedOfficerIds($link, $actorId);
        $allowedUserIds = array_merge([$actorId], $assignedSups, $assignedOfficers);
        $allowedTeamIds = getAdminManagedTeamIds($link, $actorId);

        $filteredUserIds = ($userId && in_array($userId, $allowedUserIds, true)) ? [$userId] : $allowedUserIds;
        $filteredTeamIds = ($teamId && in_array($teamId, $allowedTeamIds, true)) ? [$teamId] : $allowedTeamIds;
        $filteredSupIds  = ($supervisorId && in_array($supervisorId, $assignedSups, true)) ? [$supervisorId] : $assignedSups;

        return [
            'mode' => 'admin',
            'user_ids' => $filteredUserIds,
            'team_ids' => $filteredTeamIds,
            'supervisor_ids' => $filteredSupIds,
        ];
    }

    // Super Admin: global access
    $filteredUserIds = $userId ? [$userId] : [];
    $filteredTeamIds = $teamId ? [$teamId] : [];
    $filteredSupIds  = $supervisorId ? [$supervisorId] : [];

    return [
        'mode' => 'super_admin',
        'user_ids' => $filteredUserIds,
        'team_ids' => $filteredTeamIds,
        'supervisor_ids' => $filteredSupIds,
    ];
}

/**
 * Get Client Contact metrics according to role and team scoping
 */
function getClientContactMetrics(mysqli $link, ?int $userId, ?int $teamId, ?int $supervisorId, string $userRole): array {
    $scope = getEffectiveReportingScope($link, $userId, $teamId, $supervisorId, $userRole);
    $superAdminIds = getSuperAdminUserIds($link);
    $where = [];

    if (!empty($scope['user_ids'])) {
        $inU = implode(',', array_map('intval', $scope['user_ids']));
        $where[] = "m.MAINDATABASE_CALL_DIALED_USER IN ($inU)";
    } elseif ($supervisorId) {
        $where[] = "m.MAINDATABASE_CALL_DIALED_USER IN (SELECT u.ID FROM " . tn('TBL_USERS') . " u JOIN " . tn('TBL_TEAMS') . " t ON u.TEAM_ID = t.ID WHERE t.SUPERVISOR_ID = " . (int)$supervisorId . ")";
    } elseif ($teamId) {
        $where[] = "m.MAINDATABASE_CALL_DIALED_USER IN (SELECT ID FROM " . tn('TBL_USERS') . " WHERE TEAM_ID = " . (int)$teamId . ")";
    }

    $whereSql = !empty($where) ? "WHERE " . implode(' AND ', $where) : "";

    $sql = "
        SELECT 
            COUNT(m.ID) AS total_assigned,
            SUM(CASE WHEN m.CALL_COUNT > 0 OR m.LAST_DIALED_DATE_TIME IS NOT NULL THEN 1 ELSE 0 END) AS attempted,
            SUM(CASE WHEN (m.CALL_COUNT IS NULL OR m.CALL_COUNT = 0) AND m.LAST_DIALED_DATE_TIME IS NULL THEN 1 ELSE 0 END) AS not_yet_called,
            SUM(CASE WHEN LOWER(m.MAINDATABASE_CALL_DIALED_STATUS) NOT LIKE '%not%' AND (LOWER(m.MAINDATABASE_CALL_DIALED_STATUS) LIKE '%interest%' OR LOWER(m.MAINDATABASE_CALL_DIALED_STATUS) LIKE '%enquiry%' OR LOWER(m.MAINDATABASE_CALL_DIALED_STATUS) LIKE '%lead%') THEN 1 ELSE 0 END) AS interested,
            SUM(CASE WHEN LOWER(m.MAINDATABASE_CALL_DIALED_STATUS) LIKE '%not interest%' THEN 1 ELSE 0 END) AS not_interested,
            SUM(CASE WHEN LOWER(m.MAINDATABASE_CALL_DIALED_STATUS) LIKE '%callback%' OR LOWER(m.MAINDATABASE_CALL_DIALED_STATUS) LIKE '%call back%' OR LOWER(m.MAINDATABASE_CALL_DIALED_STATUS) LIKE '%busy%' OR LOWER(m.MAINDATABASE_CALL_DIALED_STATUS) LIKE '%follow%' THEN 1 ELSE 0 END) AS callback_busy,
            SUM(CASE WHEN LOWER(m.MAINDATABASE_CALL_DIALED_STATUS) IN ('do not call', 'dnd', 'dnc', 'do not disturb') THEN 1 ELSE 0 END) AS dnd,
            SUM(CASE WHEN LOWER(m.MAINDATABASE_CALL_DIALED_STATUS) IN ('invalid', 'delete', 'wrong number') THEN 1 ELSE 0 END) AS invalid,
            SUM(CASE WHEN LOWER(m.MAINDATABASE_CALL_DIALED_STATUS) IN ('switch off', 'switch_off') THEN 1 ELSE 0 END) AS switch_off,
            SUM(CASE WHEN LOWER(m.MAINDATABASE_CALL_DIALED_STATUS) IN ('not reachable', 'not rechable') THEN 1 ELSE 0 END) AS not_reachable,
            SUM(CASE WHEN LOWER(m.MAINDATABASE_CALL_DIALED_STATUS) IN ('ringing') THEN 1 ELSE 0 END) AS ringing
        FROM " . tn('TBL_MAIN') . " m
        $whereSql
    ";

    $res = $link->query($sql)->fetch_assoc();

    // Query pending followups on contacts within scope
    $foSql = "SELECT COUNT(DISTINCT f.client_contact_id) as pending_followups FROM " . tn('TBL_LEAD_FOLLOWUPS') . " f WHERE f.status = 'OPEN' AND f.client_contact_id IS NOT NULL";
    if (!empty($scope['user_ids'])) {
        $foSql .= " AND f.user_id IN (" . implode(',', array_map('intval', $scope['user_ids'])) . ")";
    }
    $foRes = $link->query($foSql);
    $foRow = $foRes ? $foRes->fetch_assoc() : ['pending_followups' => 0];

    return [
        'total_assigned' => (int)($res['total_assigned'] ?? 0),
        'attempted' => (int)($res['attempted'] ?? 0),
        'not_yet_called' => (int)($res['not_yet_called'] ?? 0),
        'interested' => (int)($res['interested'] ?? 0),
        'not_interested' => (int)($res['not_interested'] ?? 0),
        'callback_busy' => (int)($res['callback_busy'] ?? 0),
        'dnd' => (int)($res['dnd'] ?? 0),
        'invalid' => (int)($res['invalid'] ?? 0),
        'switch_off' => (int)($res['switch_off'] ?? 0),
        'not_reachable' => (int)($res['not_reachable'] ?? 0),
        'ringing' => (int)($res['ringing'] ?? 0),
        'pending_followups' => (int)($foRow['pending_followups'] ?? 0),
    ];
}

/**
 * Get Calling Activity metrics (dials, durations, connect rates) in date range
 */
function getCallingMetrics(mysqli $link, string $from, string $to, ?int $userId, ?int $teamId, ?int $supervisorId, string $userRole): array {
    $scope = getEffectiveReportingScope($link, $userId, $teamId, $supervisorId, $userRole);
    $superAdminIds = getSuperAdminUserIds($link);

    $where = [
        "COALESCE(cl.call_time, cl.created_at) >= '$from'",
        "COALESCE(cl.call_time, cl.created_at) < '$to' + INTERVAL 1 DAY",
        "cl.user_id NOT IN (" . implode(',', $superAdminIds) . ")"
    ];

    if (!empty($scope['user_ids'])) {
        $inU = implode(',', array_map('intval', $scope['user_ids']));
        $where[] = "cl.user_id IN ($inU)";
    } elseif ($supervisorId) {
        $where[] = "cl.user_id IN (SELECT u.ID FROM " . tn('TBL_USERS') . " u JOIN " . tn('TBL_TEAMS') . " t ON u.TEAM_ID = t.ID WHERE t.SUPERVISOR_ID = " . (int)$supervisorId . ")";
    } elseif ($teamId) {
        $where[] = "cl.user_id IN (SELECT ID FROM " . tn('TBL_USERS') . " WHERE TEAM_ID = " . (int)$teamId . ")";
    }

    $whereSql = "WHERE " . implode(' AND ', $where);

    $sql = "
        SELECT 
            COUNT(cl.id) AS total_dials,
            COUNT(DISTINCT cl.client_contact_id) AS unique_called,
            SUM(CASE WHEN cl.call_duration > 0 OR LOWER(cl.call_status) IN ('connected', 'interested', 'lead', 'enquiry', 'callback / busy', 'follow-up') THEN 1 ELSE 0 END) AS connected,
            SUM(CASE WHEN (cl.call_duration = 0 OR cl.call_duration IS NULL) AND LOWER(cl.call_status) NOT IN ('connected', 'interested', 'lead', 'enquiry', 'callback / busy', 'follow-up') THEN 1 ELSE 0 END) AS not_connected,
            SUM(CASE WHEN cl.call_duration > 0 AND cl.call_duration <= 20 THEN 1 ELSE 0 END) AS short_calls,
            SUM(CASE WHEN cl.call_duration > 20 THEN 1 ELSE 0 END) AS conversations,
            SUM(CASE WHEN LOWER(cl.call_status) NOT LIKE '%not%' AND (LOWER(cl.call_status) LIKE '%interest%' OR LOWER(cl.call_status) LIKE '%enquiry%' OR LOWER(cl.call_status) LIKE '%lead%') THEN 1 ELSE 0 END) AS interested,
            SUM(CASE WHEN LOWER(cl.call_status) LIKE '%not interest%' THEN 1 ELSE 0 END) AS not_interested,
            SUM(CASE WHEN LOWER(cl.call_status) LIKE '%callback%' OR LOWER(cl.call_status) LIKE '%call back%' OR LOWER(cl.call_status) LIKE '%busy%' OR LOWER(cl.call_status) LIKE '%follow%' THEN 1 ELSE 0 END) AS callback_busy,
            SUM(CASE WHEN LOWER(cl.call_status) IN ('do not call', 'dnd', 'dnc', 'do not disturb') THEN 1 ELSE 0 END) AS dnd,
            SUM(CASE WHEN LOWER(cl.call_status) IN ('invalid', 'delete', 'wrong number') THEN 1 ELSE 0 END) AS invalid,
            SUM(CASE WHEN LOWER(cl.call_status) IN ('switch off', 'switch_off') THEN 1 ELSE 0 END) AS switch_off,
            SUM(CASE WHEN LOWER(cl.call_status) IN ('not reachable', 'not rechable') THEN 1 ELSE 0 END) AS not_reachable,
            SUM(CASE WHEN LOWER(cl.call_status) IN ('ringing') THEN 1 ELSE 0 END) AS ringing,
            COALESCE(SUM(cl.call_duration), 0) AS total_duration_seconds
        FROM " . tn('TBL_CALL_LOGS') . " cl
        $whereSql
    ";

    $res = $link->query($sql)->fetch_assoc();

    $totDials = (int)($res['total_dials'] ?? 0);
    $connected = (int)($res['connected'] ?? 0);
    $connectRate = $totDials > 0 ? round(($connected / $totDials) * 100, 1) : 0.0;
    $durSec = (int)($res['total_duration_seconds'] ?? 0);
    $avgDur = $connected > 0 ? round($durSec / $connected) : 0;

    $durHours = floor($durSec / 3600);
    $durMins = floor(($durSec % 3600) / 60);

    return [
        'total_dials' => $totDials,
        'unique_called' => (int)($res['unique_called'] ?? 0),
        'connected' => $connected,
        'not_connected' => (int)($res['not_connected'] ?? 0),
        'connect_rate' => $connectRate,
        'short_calls' => (int)($res['short_calls'] ?? 0),
        'conversations' => (int)($res['conversations'] ?? 0),
        'interested' => (int)($res['interested'] ?? 0),
        'not_interested' => (int)($res['not_interested'] ?? 0),
        'callback_busy' => (int)($res['callback_busy'] ?? 0),
        'dnd' => (int)($res['dnd'] ?? 0),
        'invalid' => (int)($res['invalid'] ?? 0),
        'switch_off' => (int)($res['switch_off'] ?? 0),
        'not_reachable' => (int)($res['not_reachable'] ?? 0),
        'ringing' => (int)($res['ringing'] ?? 0),
        'total_duration_seconds' => $durSec,
        'talk_time_formatted' => sprintf('%02dh %02dm', $durHours, $durMins),
        'avg_call_duration_seconds' => $avgDur,
    ];
}

/**
 * Get Converted Leads & Loan Pipeline metrics in date range
 */
function getLeadConversionMetrics(mysqli $link, string $from, string $to, ?int $userId, ?int $teamId, ?int $supervisorId, string $userRole): array {
    $scope = getEffectiveReportingScope($link, $userId, $teamId, $supervisorId, $userRole);
    $superAdminIds = getSuperAdminUserIds($link);

    $where = [
        "l.created_at >= '$from'",
        "l.created_at < '$to' + INTERVAL 1 DAY",
        "l.assigned_to NOT IN (" . implode(',', $superAdminIds) . ")"
    ];

    if (!empty($scope['user_ids'])) {
        $inU = implode(',', array_map('intval', $scope['user_ids']));
        $where[] = "l.assigned_to IN ($inU)";
    } elseif ($supervisorId) {
        $where[] = "l.assigned_to IN (SELECT u.ID FROM " . tn('TBL_USERS') . " u JOIN " . tn('TBL_TEAMS') . " t ON u.TEAM_ID = t.ID WHERE t.SUPERVISOR_ID = " . (int)$supervisorId . ")";
    } elseif ($teamId) {
        $where[] = "l.assigned_to IN (SELECT ID FROM " . tn('TBL_USERS') . " WHERE TEAM_ID = " . (int)$teamId . ")";
    }

    $whereSql = "WHERE " . implode(' AND ', $where);

    $sql = "
        SELECT 
            COUNT(l.lead_id) AS total_converted_leads,
            SUM(CASE WHEN l.lead_status_new = 'DISBURSED' THEN 1 ELSE 0 END) AS disbursed_count,
            SUM(CASE WHEN l.lead_status_new = 'DISBURSED' THEN CAST(REPLACE(REPLACE(l.loan_amount, ',', ''), ' ', '') AS DECIMAL(15,2)) ELSE 0 END) AS disbursed_volume,
            SUM(CASE WHEN l.lead_status_new = 'SANCTIONED' THEN 1 ELSE 0 END) AS sanctioned_count,
            SUM(CASE WHEN l.lead_status_new = 'LOGIN' THEN 1 ELSE 0 END) AS in_login_count,
            SUM(CASE WHEN l.lead_status_new = 'INTERNAL_UNDERWRITING' THEN 1 ELSE 0 END) AS in_underwriting_count,
            SUM(CASE WHEN l.lead_status_new = 'REJECT' THEN 1 ELSE 0 END) AS rejected_count,
            SUM(CASE WHEN l.lead_status_new IN ('LEAD', 'FOLLOWUP') OR l.lead_status_new IS NULL THEN 1 ELSE 0 END) AS open_leads_count,
            COUNT(DISTINCT l.cust_id) AS unique_contacts_converted
        FROM " . tn('TBL_LEADS') . " l
        $whereSql
    ";

    $res = $link->query($sql)->fetch_assoc();

    return [
        'total_converted_leads' => (int)($res['total_converted_leads'] ?? 0),
        'unique_contacts_converted' => (int)($res['unique_contacts_converted'] ?? 0),
        'disbursed_count' => (int)($res['disbursed_count'] ?? 0),
        'disbursed_volume' => (float)($res['disbursed_volume'] ?? 0),
        'sanctioned_count' => (int)($res['sanctioned_count'] ?? 0),
        'in_login_count' => (int)($res['in_login_count'] ?? 0),
        'in_underwriting_count' => (int)($res['in_underwriting_count'] ?? 0),
        'rejected_count' => (int)($res['rejected_count'] ?? 0),
        'open_leads_count' => (int)($res['open_leads_count'] ?? 0),
    ];
}

/**
 * Get Hourly Calling Analytics (09:00 to 18:00) with volume, connect ratios and talk time
 */
function getHourlyCallingAnalytics(mysqli $link, string $from, string $to, ?int $userId, ?int $teamId, ?int $supervisorId, string $userRole): array {
    $scope = getEffectiveReportingScope($link, $userId, $teamId, $supervisorId, $userRole);
    $superAdminIds = getSuperAdminUserIds($link);

    $where = [
        "COALESCE(cl.call_time, cl.created_at) >= '$from'",
        "COALESCE(cl.call_time, cl.created_at) < '$to' + INTERVAL 1 DAY",
        "cl.user_id NOT IN (" . implode(',', $superAdminIds) . ")"
    ];

    if (!empty($scope['user_ids'])) {
        $inU = implode(',', array_map('intval', $scope['user_ids']));
        $where[] = "cl.user_id IN ($inU)";
    } elseif ($supervisorId) {
        $where[] = "cl.user_id IN (SELECT u.ID FROM " . tn('TBL_USERS') . " u JOIN " . tn('TBL_TEAMS') . " t ON u.TEAM_ID = t.ID WHERE t.SUPERVISOR_ID = " . (int)$supervisorId . ")";
    } elseif ($teamId) {
        $where[] = "cl.user_id IN (SELECT ID FROM " . tn('TBL_USERS') . " WHERE TEAM_ID = " . (int)$teamId . ")";
    }

    $whereSql = "WHERE " . implode(' AND ', $where);

    $sql = "
        SELECT 
            HOUR(COALESCE(cl.call_time, cl.created_at)) AS call_hour,
            COUNT(cl.id) AS total_calls,
            SUM(CASE WHEN cl.call_duration > 0 OR LOWER(cl.call_status) IN ('connected', 'interested', 'lead', 'enquiry', 'callback / busy', 'follow-up') THEN 1 ELSE 0 END) AS connected_calls,
            SUM(CASE WHEN LOWER(cl.call_status) NOT LIKE '%not%' AND (LOWER(cl.call_status) LIKE '%interest%' OR LOWER(cl.call_status) LIKE '%enquiry%' OR LOWER(cl.call_status) LIKE '%lead%') THEN 1 ELSE 0 END) AS interested_calls,
            COUNT(DISTINCT cl.client_contact_id) AS unique_contacts,
            COALESCE(SUM(cl.call_duration), 0) AS total_duration
        FROM " . tn('TBL_CALL_LOGS') . " cl
        $whereSql
        GROUP BY HOUR(COALESCE(cl.call_time, cl.created_at))
        ORDER BY call_hour ASC
    ";

    $rows = $link->query($sql)->fetch_all(MYSQLI_ASSOC);

    $hourlyMap = [];
    foreach ($rows as $r) {
        $hourlyMap[(int)$r['call_hour']] = $r;
    }

    $analytics = [];
    for ($h = 9; $h <= 18; $h++) {
        $startLabel = sprintf('%02d:00', $h);
        $endLabel = sprintf('%02d:00', $h + 1);
        $data = $hourlyMap[$h] ?? [
            'call_hour' => $h,
            'total_calls' => 0,
            'connected_calls' => 0,
            'interested_calls' => 0,
            'unique_contacts' => 0,
            'total_duration' => 0,
        ];

        $tot = (int)$data['total_calls'];
        $conn = (int)$data['connected_calls'];
        $rate = $tot > 0 ? round(($conn / $tot) * 100, 1) : 0.0;
        $durSec = (int)$data['total_duration'];

        $analytics[] = [
            'hour' => $h,
            'window' => "$startLabel - $endLabel",
            'total_calls' => $tot,
            'connected_calls' => $conn,
            'interested_calls' => (int)$data['interested_calls'],
            'unique_contacts' => (int)$data['unique_contacts'],
            'total_duration_seconds' => $durSec,
            'talk_time_formatted' => sprintf('%02dm %02ds', floor($durSec / 60), $durSec % 60),
            'connect_rate' => $rate,
        ];
    }

    return $analytics;
}

/**
 * Get Employee-wise calling performance, targets and conversion metrics
 */
function getEmployeePerformanceRows(mysqli $link, string $from, string $to, ?int $teamId, ?int $supervisorId, ?int $userId, string $userRole, array $targets): array {
    $scope = getEffectiveReportingScope($link, $userId, $teamId, $supervisorId, $userRole);
    $superAdminIds = getSuperAdminUserIds($link);

    $where = [
        "u.STATUS = 'Active'",
        "u.ROLE NOT IN ('Super Admin')",
        "u.ID NOT IN (" . implode(',', $superAdminIds) . ")"
    ];

    if (!empty($scope['user_ids'])) {
        $inU = implode(',', array_map('intval', $scope['user_ids']));
        $where[] = "u.ID IN ($inU)";
    } elseif ($supervisorId) {
        $where[] = "t.SUPERVISOR_ID = " . (int)$supervisorId;
    } elseif ($teamId) {
        $where[] = "u.TEAM_ID = " . (int)$teamId;
    }

    $whereSql = "WHERE " . implode(' AND ', $where);

    $sql = "
        SELECT 
            u.ID AS user_id,
            u.NAME AS employee_name,
            u.ROLE AS user_role,
            t.ID AS team_id,
            t.NAME AS team_name,
            s.ID AS supervisor_id,
            s.NAME AS supervisor_name,
            COALESCE(c.assigned_contacts, 0) AS assigned_contacts,
            COALESCE(cl.total_dials, 0) AS total_dials,
            COALESCE(cl.unique_called, 0) AS unique_called,
            COALESCE(cl.connected, 0) AS connected,
            COALESCE(cl.not_connected, 0) AS not_connected,
            COALESCE(cl.interested, 0) AS interested,
            COALESCE(cl.not_interested, 0) AS not_interested,
            COALESCE(cl.callback_busy, 0) AS callback_busy,
            COALESCE(cl.dnd, 0) AS dnd,
            COALESCE(cl.invalid, 0) AS invalid,
            COALESCE(cl.total_duration, 0) AS total_duration,
            COALESCE(cl.last_call_time, NULL) AS last_call_time,
            COALESCE(ld.converted_leads, 0) AS converted_leads,
            COALESCE(ld.disbursed_loans, 0) AS disbursed_loans,
            COALESCE(ld.disbursed_amount, 0) AS disbursed_amount,
            COALESCE(fo.fo_created, 0) AS fo_created,
            COALESCE(fo.fo_completed, 0) AS fo_completed,
            COALESCE(fo.fo_overdue, 0) AS fo_overdue
        FROM " . tn('TBL_USERS') . " u
        LEFT JOIN " . tn('TBL_TEAMS') . " t ON u.TEAM_ID = t.ID
        LEFT JOIN " . tn('TBL_USERS') . " s ON t.SUPERVISOR_ID = s.ID
        LEFT JOIN (
            SELECT MAINDATABASE_CALL_DIALED_USER, COUNT(ID) AS assigned_contacts 
            FROM " . tn('TBL_MAIN') . " 
            GROUP BY MAINDATABASE_CALL_DIALED_USER
        ) c ON u.ID = c.MAINDATABASE_CALL_DIALED_USER
        LEFT JOIN (
            SELECT 
                user_id,
                COUNT(id) AS total_dials,
                COUNT(DISTINCT client_contact_id) AS unique_called,
                SUM(CASE WHEN call_duration > 0 OR LOWER(call_status) IN ('connected', 'interested', 'lead', 'enquiry', 'callback / busy', 'follow-up') THEN 1 ELSE 0 END) AS connected,
                SUM(CASE WHEN (call_duration = 0 OR call_duration IS NULL) AND LOWER(call_status) NOT IN ('connected', 'interested', 'lead') THEN 1 ELSE 0 END) AS not_connected,
                SUM(CASE WHEN LOWER(call_status) NOT LIKE '%not%' AND (LOWER(call_status) LIKE '%interest%' OR LOWER(call_status) LIKE '%enquiry%' OR LOWER(call_status) LIKE '%lead%') THEN 1 ELSE 0 END) AS interested,
                SUM(CASE WHEN LOWER(call_status) LIKE '%not interest%' THEN 1 ELSE 0 END) AS not_interested,
                SUM(CASE WHEN LOWER(call_status) LIKE '%callback%' OR LOWER(call_status) LIKE '%busy%' OR LOWER(call_status) LIKE '%follow%' THEN 1 ELSE 0 END) AS callback_busy,
                SUM(CASE WHEN LOWER(call_status) IN ('do not call', 'dnd', 'dnc') THEN 1 ELSE 0 END) AS dnd,
                SUM(CASE WHEN LOWER(call_status) IN ('invalid', 'delete') THEN 1 ELSE 0 END) AS invalid,
                SUM(COALESCE(call_duration, 0)) AS total_duration,
                MAX(COALESCE(call_time, created_at)) AS last_call_time
            FROM " . tn('TBL_CALL_LOGS') . "
            WHERE COALESCE(call_time, created_at) >= '$from' AND COALESCE(call_time, created_at) < '$to' + INTERVAL 1 DAY
            GROUP BY user_id
        ) cl ON u.ID = cl.user_id
        LEFT JOIN (
            SELECT 
                assigned_to,
                COUNT(lead_id) AS converted_leads,
                SUM(CASE WHEN lead_status_new = 'DISBURSED' THEN 1 ELSE 0 END) AS disbursed_loans,
                SUM(CASE WHEN lead_status_new = 'DISBURSED' THEN CAST(REPLACE(REPLACE(loan_amount, ',', ''), ' ', '') AS DECIMAL(15,2)) ELSE 0 END) AS disbursed_amount
            FROM " . tn('TBL_LEADS') . "
            WHERE created_at >= '$from' AND created_at < '$to' + INTERVAL 1 DAY
            GROUP BY assigned_to
        ) ld ON u.ID = ld.assigned_to
        LEFT JOIN (
            SELECT 
                user_id,
                SUM(CASE WHEN created_at >= '$from' AND created_at < '$to' + INTERVAL 1 DAY THEN 1 ELSE 0 END) AS fo_created,
                SUM(CASE WHEN status = 'DONE' AND completed_at >= '$from' AND completed_at < '$to' + INTERVAL 1 DAY THEN 1 ELSE 0 END) AS fo_completed,
                SUM(CASE WHEN status = 'OPEN' AND followup_at < NOW() THEN 1 ELSE 0 END) AS fo_overdue
            FROM " . tn('TBL_LEAD_FOLLOWUPS') . "
            GROUP BY user_id
        ) fo ON u.ID = fo.user_id
        $whereSql
        ORDER BY total_dials DESC
    ";

    $rows = $link->query($sql)->fetch_all(MYSQLI_ASSOC);

    $dailyTarget = (int)($targets['daily_call_target'] ?? 100);

    foreach ($rows as &$r) {
        $tot = (int)$r['total_dials'];
        $conn = (int)$r['connected'];
        $r['connect_rate'] = $tot > 0 ? round(($conn / $tot) * 100, 1) : 0.0;
        $r['target'] = $dailyTarget;
        $r['achievement_pct'] = $dailyTarget > 0 ? min(200, round(($tot / $dailyTarget) * 100, 1)) : 0.0;
        $durSec = (int)$r['total_duration'];
        $r['talk_time_formatted'] = sprintf('%02dh %02dm', floor($durSec / 3600), floor(($durSec % 3600) / 60));
        $r['avg_call_duration_seconds'] = $conn > 0 ? round($durSec / $conn) : 0;
    }
    unset($r);

    return $rows;
}

/**
 * Get Supervisor-wise performance rollup
 */
function getSupervisorPerformanceRows(mysqli $link, string $from, string $to, ?int $teamId, array $targets, ?int $actorUserId = null, string $actorRole = 'Super Admin'): array {
    $normRole = getNormalizedRole($actorRole);
    $superAdminIds = getSuperAdminUserIds($link);

    // Officers cannot view supervisor rollup
    if ($normRole === 'Officer') {
        return [];
    }

    $where = [
        "t.SUPERVISOR_ID IS NOT NULL",
        "s.ID NOT IN (" . implode(',', $superAdminIds) . ")"
    ];

    if ($normRole === 'Supervisor') {
        $supActor = $actorUserId ?: (defined('USER_ID') ? (int)USER_ID : 0);
        $where[] = "s.ID = $supActor";
    } elseif ($normRole === 'Admin') {
        $adminActor = $actorUserId ?: (defined('USER_ID') ? (int)USER_ID : 0);
        $adminSups = getAdminAssignedSupervisorIds($link, $adminActor);
        $adminTeams = getAdminManagedTeamIds($link, $adminActor);
        if (!empty($adminTeams) || !empty($adminSups)) {
            $tIn = !empty($adminTeams) ? implode(',', array_map('intval', $adminTeams)) : '0';
            $sIn = !empty($adminSups) ? implode(',', array_map('intval', $adminSups)) : '0';
            $where[] = "(t.ID IN ($tIn) OR s.ID IN ($sIn))";
        } else {
            $where[] = "1=0";
        }
    }

    if ($teamId) {
        $where[] = "t.ID = " . (int)$teamId;
    }

    $whereSql = "WHERE " . implode(' AND ', $where);

    $sql = "
        SELECT 
            s.ID AS supervisor_id,
            s.NAME AS supervisor_name,
            t.ID AS team_id,
            t.NAME AS team_name,
            COUNT(DISTINCT u.ID) AS total_officers,
            COALESCE(SUM(c.assigned_contacts), 0) AS assigned_contacts,
            COALESCE(SUM(cl.total_dials), 0) AS total_dials,
            COALESCE(SUM(cl.unique_called), 0) AS unique_called,
            COALESCE(SUM(cl.connected), 0) AS connected,
            COALESCE(SUM(cl.interested), 0) AS interested,
            COALESCE(SUM(ld.converted_leads), 0) AS converted_leads,
            COALESCE(SUM(ld.disbursed_loans), 0) AS disbursed_loans,
            COALESCE(SUM(ld.disbursed_amount), 0) AS disbursed_amount,
            COALESCE(SUM(fo.fo_completed), 0) AS fo_completed,
            COALESCE(SUM(fo.fo_created), 0) AS fo_created
        FROM " . tn('TBL_TEAMS') . " t
        JOIN " . tn('TBL_USERS') . " s ON t.SUPERVISOR_ID = s.ID
        LEFT JOIN " . tn('TBL_USERS') . " u ON (u.TEAM_ID = t.ID AND u.STATUS = 'Active' AND u.ROLE != 'Super Admin')
        LEFT JOIN (
            SELECT MAINDATABASE_CALL_DIALED_USER, COUNT(ID) AS assigned_contacts 
            FROM " . tn('TBL_MAIN') . " 
            GROUP BY MAINDATABASE_CALL_DIALED_USER
        ) c ON u.ID = c.MAINDATABASE_CALL_DIALED_USER
        LEFT JOIN (
            SELECT 
                user_id,
                COUNT(id) AS total_dials,
                COUNT(DISTINCT client_contact_id) AS unique_called,
                SUM(CASE WHEN call_duration > 0 OR LOWER(call_status) IN ('connected', 'interested', 'lead', 'enquiry', 'callback / busy', 'follow-up') THEN 1 ELSE 0 END) AS connected,
                SUM(CASE WHEN LOWER(call_status) NOT LIKE '%not%' AND (LOWER(call_status) LIKE '%interest%' OR LOWER(call_status) LIKE '%enquiry%' OR LOWER(call_status) LIKE '%lead%') THEN 1 ELSE 0 END) AS interested
            FROM " . tn('TBL_CALL_LOGS') . "
            WHERE COALESCE(call_time, created_at) >= '$from' AND COALESCE(call_time, created_at) < '$to' + INTERVAL 1 DAY
            GROUP BY user_id
        ) cl ON u.ID = cl.user_id
        LEFT JOIN (
            SELECT 
                assigned_to,
                COUNT(lead_id) AS converted_leads,
                SUM(CASE WHEN lead_status_new = 'DISBURSED' THEN 1 ELSE 0 END) AS disbursed_loans,
                SUM(CASE WHEN lead_status_new = 'DISBURSED' THEN CAST(REPLACE(REPLACE(loan_amount, ',', ''), ' ', '') AS DECIMAL(15,2)) ELSE 0 END) AS disbursed_amount
            FROM " . tn('TBL_LEADS') . "
            WHERE created_at >= '$from' AND created_at < '$to' + INTERVAL 1 DAY
            GROUP BY assigned_to
        ) ld ON u.ID = ld.assigned_to
        LEFT JOIN (
            SELECT 
                user_id,
                SUM(CASE WHEN created_at >= '$from' AND created_at < '$to' + INTERVAL 1 DAY THEN 1 ELSE 0 END) AS fo_created,
                SUM(CASE WHEN status = 'DONE' AND completed_at >= '$from' AND completed_at < '$to' + INTERVAL 1 DAY THEN 1 ELSE 0 END) AS fo_completed
            FROM " . tn('TBL_LEAD_FOLLOWUPS') . "
            GROUP BY user_id
        ) fo ON u.ID = fo.user_id
        $whereSql
        GROUP BY t.ID, s.ID
        ORDER BY total_dials DESC
    ";

    $rows = $link->query($sql)->fetch_all(MYSQLI_ASSOC);

    $dailyTarget = (int)($targets['daily_call_target'] ?? 100);

    foreach ($rows as &$r) {
        $tot = (int)$r['total_dials'];
        $conn = (int)$r['connected'];
        $officers = max(1, (int)$r['total_officers']);
        $r['connect_rate'] = $tot > 0 ? round(($conn / $tot) * 100, 1) : 0.0;
        $teamTarget = $dailyTarget * $officers;
        $r['team_target'] = $teamTarget;
        $r['achievement_pct'] = $teamTarget > 0 ? min(200, round(($tot / $teamTarget) * 100, 1)) : 0.0;
        $foCreated = (int)$r['fo_created'];
        $r['fo_completion_rate'] = $foCreated > 0 ? round(((int)$r['fo_completed'] / $foCreated) * 100, 1) : 100.0;
    }
    unset($r);

    return $rows;
}

/**
 * Get Admin-wise performance rollup across authorized organizational structure
 */
function getAdminPerformanceRows(mysqli $link, string $from, string $to, ?int $scopedAdminId, array $targets, string $actorRole = 'Super Admin'): array {
    $normRole = getNormalizedRole($actorRole);
    $superAdminIds = getSuperAdminUserIds($link);

    // Officers and Supervisors cannot view Admin-level reports
    if ($normRole === 'Officer' || $normRole === 'Supervisor') {
        return [];
    }

    $whereAdmin = [
        "u.ROLE IN ('Admin', 'Manager')",
        "u.ID NOT IN (" . implode(',', $superAdminIds) . ")",
        "u.STATUS = 'Active'"
    ];

    if ($normRole === 'Admin') {
        $actorAdminId = $scopedAdminId ?: (defined('USER_ID') ? (int)USER_ID : 0);
        $whereAdmin[] = "u.ID = $actorAdminId";
    } elseif ($scopedAdminId && !in_array($scopedAdminId, $superAdminIds, true)) {
        $whereAdmin[] = "u.ID = " . (int)$scopedAdminId;
    }

    $whereSql = "WHERE " . implode(' AND ', $whereAdmin);
    $sql = "SELECT u.ID, u.NAME, u.MOBILE, u.EMAIL, u.ROLE FROM " . tn('TBL_USERS') . " u $whereSql ORDER BY u.NAME";
    $res = $link->query($sql);
    $admins = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) $admins[] = $r;
    }

    $dailyTarget = (int)($targets['daily_call_target'] ?? 100);
    $rows = [];

    foreach ($admins as $adm) {
        $aId = (int)$adm['ID'];
        $teamIds = getAdminManagedTeamIds($link, $aId);
        $supIds  = getAdminAssignedSupervisorIds($link, $aId);
        $offIds  = getAdminAssignedOfficerIds($link, $aId);
        $allStaffIds = array_unique(array_merge($supIds, $offIds));

        if (empty($allStaffIds)) {
            $rows[] = [
                'admin_id' => $aId,
                'admin_name' => $adm['NAME'],
                'mobile' => $adm['MOBILE'],
                'email' => $adm['EMAIL'],
                'total_teams' => count($teamIds),
                'total_supervisors' => count($supIds),
                'total_officers' => count($offIds),
                'assigned_contacts' => 0,
                'total_dials' => 0,
                'unique_called' => 0,
                'connected' => 0,
                'connect_rate' => 0.0,
                'total_duration' => 0,
                'talk_time_formatted' => '00h 00m',
                'interested' => 0,
                'converted_leads' => 0,
                'conversion_rate' => 0.0,
                'disbursed_loans' => 0,
                'disbursed_amount' => 0.0,
                'achievement_pct' => 0.0,
            ];
            continue;
        }

        $inStaff = implode(',', array_map('intval', $allStaffIds));

        $clSql = "
            SELECT 
                COUNT(id) AS total_dials,
                COUNT(DISTINCT client_contact_id) AS unique_called,
                SUM(CASE WHEN call_duration > 0 OR LOWER(call_status) IN ('connected', 'interested', 'lead', 'enquiry', 'callback / busy', 'follow-up') THEN 1 ELSE 0 END) AS connected,
                SUM(CASE WHEN LOWER(call_status) NOT LIKE '%not%' AND (LOWER(call_status) LIKE '%interest%' OR LOWER(call_status) LIKE '%enquiry%' OR LOWER(call_status) LIKE '%lead%') THEN 1 ELSE 0 END) AS interested,
                SUM(COALESCE(call_duration, 0)) AS total_duration
            FROM " . tn('TBL_CALL_LOGS') . "
            WHERE user_id IN ($inStaff)
              AND COALESCE(call_time, created_at) >= '$from' AND COALESCE(call_time, created_at) < '$to' + INTERVAL 1 DAY
        ";
        $cl = $link->query($clSql)->fetch_assoc();

        $ldSql = "
            SELECT 
                COUNT(lead_id) AS converted_leads,
                SUM(CASE WHEN lead_status_new = 'DISBURSED' THEN 1 ELSE 0 END) AS disbursed_loans,
                SUM(CASE WHEN lead_status_new = 'DISBURSED' THEN CAST(REPLACE(REPLACE(loan_amount, ',', ''), ' ', '') AS DECIMAL(15,2)) ELSE 0 END) AS disbursed_amount
            FROM " . tn('TBL_LEADS') . "
            WHERE assigned_to IN ($inStaff)
              AND created_at >= '$from' AND created_at < '$to' + INTERVAL 1 DAY
        ";
        $ld = $link->query($ldSql)->fetch_assoc();

        $cSql = "SELECT COUNT(ID) AS assigned_contacts FROM " . tn('TBL_MAIN') . " WHERE MAINDATABASE_CALL_DIALED_USER IN ($inStaff)";
        $c = $link->query($cSql)->fetch_assoc();

        $totDials = (int)($cl['total_dials'] ?? 0);
        $conn = (int)($cl['connected'] ?? 0);
        $durSec = (int)($cl['total_duration'] ?? 0);
        $leads = (int)($ld['converted_leads'] ?? 0);
        $officersCount = max(1, count($offIds));
        $adminTarget = $dailyTarget * $officersCount;

        $rows[] = [
            'admin_id' => $aId,
            'admin_name' => $adm['NAME'],
            'mobile' => $adm['MOBILE'],
            'email' => $adm['EMAIL'],
            'total_teams' => count($teamIds),
            'total_supervisors' => count($supIds),
            'total_officers' => count($offIds),
            'assigned_contacts' => (int)($c['assigned_contacts'] ?? 0),
            'total_dials' => $totDials,
            'unique_called' => (int)($cl['unique_called'] ?? 0),
            'connected' => $conn,
            'connect_rate' => $totDials > 0 ? round(($conn / $totDials) * 100, 1) : 0.0,
            'total_duration' => $durSec,
            'talk_time_formatted' => sprintf('%02dh %02dm', floor($durSec / 3600), floor(($durSec % 3600) / 60)),
            'interested' => (int)($cl['interested'] ?? 0),
            'converted_leads' => $leads,
            'conversion_rate' => $totDials > 0 ? round(($leads / $totDials) * 100, 2) : 0.0,
            'disbursed_loans' => (int)($ld['disbursed_loans'] ?? 0),
            'disbursed_amount' => (float)($ld['disbursed_amount'] ?? 0),
            'achievement_pct' => $adminTarget > 0 ? min(200, round(($totDials / $adminTarget) * 100, 1)) : 0.0,
        ];
    }

    return $rows;
}

/**
 * Get follow-up list with customer and officer details, strictly scoped by role
 */
function getFollowupList(mysqli $link, ?int $userId, ?int $teamId, string $userRole, string $filter = 'all', int $limit = 100): array {
    $scope = getEffectiveReportingScope($link, $userId, $teamId, null, $userRole);
    $superAdminIds = getSuperAdminUserIds($link);

    $where = [
        "f.user_id NOT IN (" . implode(',', $superAdminIds) . ")"
    ];

    if (!empty($scope['user_ids'])) {
        $inU = implode(',', array_map('intval', $scope['user_ids']));
        $where[] = "f.user_id IN ($inU)";
    } elseif ($teamId) {
        $where[] = "f.user_id IN (SELECT ID FROM " . tn('TBL_USERS') . " WHERE TEAM_ID = " . (int)$teamId . ")";
    }

    if ($filter === 'today') {
        $where[] = "f.followup_at >= CURDATE() AND f.followup_at < CURDATE() + INTERVAL 1 DAY";
    } elseif ($filter === 'overdue') {
        $where[] = "f.followup_at < NOW() AND f.status = 'OPEN'";
    } elseif ($filter === 'completed') {
        $where[] = "f.status = 'DONE'";
    } elseif ($filter === 'open') {
        $where[] = "f.status = 'OPEN'";
    }

    $whereSql = "WHERE " . implode(' AND ', $where);

    $sql = "
        SELECT 
            f.id, f.lead_id, f.client_contact_id, f.contact_source, f.source_call_id, f.user_id, f.followup_at, f.note, f.status, f.completed_at, f.created_at,
            u.NAME AS officer_name,
            COALESCE(l.NAME, m.MAINDATABASE_NAME, t.CUST_NAME, '') AS customer_name,
            COALESCE(l.MOBILE, m.MAINDATABASE_MOBILE, t.CUST_MOBILE, '') AS customer_mobile,
            COALESCE(l.COMPANY_NAME, m.MAINDATABASE_COMPANY, t.CUST_COMPANY, '') AS company_name,
            l.loan_type, l.loan_amount, l.lead_status_new AS pipeline_stage
        FROM " . tn('TBL_LEAD_FOLLOWUPS') . " f
        LEFT JOIN " . tn('TBL_USERS') . " u ON f.user_id = u.ID
        LEFT JOIN " . tn('TBL_LEADS') . " l ON f.lead_id = l.lead_id
        LEFT JOIN " . tn('TBL_MAIN') . " m ON (f.client_contact_id = m.ID AND f.contact_source = 'MAIN')
        LEFT JOIN " . tn('TBL_TEMP') . " t ON (f.client_contact_id = t.ID AND f.contact_source = 'TEMP')
        $whereSql
        ORDER BY f.followup_at ASC
        LIMIT ?
    ";

    $stmt = $link->prepare($sql);
    $stmt->bind_param('i', $limit);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

/**
 * Calculate Performance Alerts with 4 severity levels
 */
function getPerformanceAlerts(mysqli $link, array $empRows, array $targets, ?int $userId, string $userRole): array {
    $alerts = [];
    $dailyTarget = (int)($targets['daily_call_target'] ?? 100);
    $minRate = (float)($targets['min_connect_rate_pct'] ?? 25.00);
    $maxOverdue = (int)($targets['max_overdue_followups'] ?? 5);

    foreach ($empRows as $emp) {
        $tot = (int)$emp['total_dials'];
        $conn = (int)$emp['connected'];
        $rate = $tot > 0 ? ($conn / $tot) * 100 : 0.0;
        $overdue = (int)$emp['fo_overdue'];
        $name = htmlspecialchars($emp['employee_name'] ?? 'Officer', ENT_QUOTES, 'UTF-8');

        if ($tot > 0 && $rate < $minRate && $tot >= 15) {
            $alerts[] = [
                'severity' => 'critical',
                'badge' => 'CRITICAL CONNECT RATE',
                'title' => 'Low Connect Ratio Alert',
                'message' => "$name connect rate is {$rate}% (target minimum is {$minRate}%). Requires calling script review.",
                'user_id' => $emp['user_id'],
                'officer' => $name,
            ];
        }

        if ($overdue >= $maxOverdue) {
            $alerts[] = [
                'severity' => 'critical',
                'badge' => 'OVERDUE FOLLOW-UPS',
                'title' => 'High Overdue Follow-ups',
                'message' => "$name has $overdue overdue follow-up callback(s) pending customer action.",
                'user_id' => $emp['user_id'],
                'officer' => $name,
            ];
        }

        if ($tot > 0 && $tot < ($dailyTarget * 0.40) && (int)date('H') >= 15) {
            $alerts[] = [
                'severity' => 'warning',
                'badge' => 'BEHIND TARGET',
                'title' => 'Daily Calling Quota Lagging',
                'message' => "$name has logged only $tot calls against the daily target of $dailyTarget.",
                'user_id' => $emp['user_id'],
                'officer' => $name,
            ];
        }

        if ($tot >= $dailyTarget) {
            $alerts[] = [
                'severity' => 'success',
                'badge' => 'TARGET ACHIEVED',
                'title' => 'Daily Target Achieved',
                'message' => "$name completed $tot calls reaching " . round(($tot / $dailyTarget) * 100) . "% of daily quota.",
                'user_id' => $emp['user_id'],
                'officer' => $name,
            ];
        }
    }

    // System Check: Interested contacts not yet converted to leads
    $unconvQ = $link->query("
        SELECT COUNT(ID) as c 
        FROM " . tn('TBL_MAIN') . " 
        WHERE LOWER(MAINDATABASE_CALL_DIALED_STATUS) IN ('interested', 'enquiry', 'lead') 
          AND ID NOT IN (SELECT cust_id FROM " . tn('TBL_LEADS') . " WHERE cust_id IS NOT NULL)
    ");
    $unconvCount = $unconvQ ? (int)$unconvQ->fetch_assoc()['c'] : 0;
    if ($unconvCount > 0 && ($userRole === 'Super Admin' || $userRole === 'Admin')) {
        $alerts[] = [
            'severity' => 'info',
            'badge' => 'CONVERSION PENDING',
            'title' => 'Interested Contacts Pending Lead Details',
            'message' => "$unconvCount Client Contact(s) marked as Interested have not yet been formalized with full loan details in Leads pipeline.",
            'user_id' => null,
            'officer' => 'Multiple',
        ];
    }

    return $alerts;
}

/**
 * Calculate Weighted Calling Productivity Score and Loan Sales Score
 */
function calculateProductivityRankings(array $empRows, array $targets): array {
    $dailyTarget = max(1, (int)($targets['daily_call_target'] ?? 100));
    $dailyConn = max(1, (int)($targets['daily_connected_target'] ?? 30));
    $dailyTalk = max(1, (int)($targets['daily_talk_time_minutes'] ?? 120));
    $dailyLead = max(1, (int)($targets['daily_lead_target'] ?? 3));

    $ranked = $empRows;

    foreach ($ranked as &$r) {
        $dials = (int)$r['total_dials'];
        $conn = (int)$r['connected'];
        $uniq = (int)$r['unique_called'];
        $inter = (int)$r['interested'];
        $talkMin = round((int)$r['total_duration'] / 60);
        $foCreated = (int)$r['fo_created'];
        $foComp = (int)$r['fo_completed'];
        $foRate = $foCreated > 0 ? ($foComp / $foCreated) : 1.0;

        $sTarget = min(1.0, $dials / $dailyTarget) * 30.0;
        $sConn   = min(1.0, $conn / $dailyConn) * 20.0;
        $sUniq   = min(1.0, $uniq / ($dailyTarget * 0.8)) * 15.0;
        $sInter  = min(1.0, $inter / $dailyLead) * 15.0;
        $sFo     = min(1.0, $foRate) * 10.0;
        $sTalk   = min(1.0, $talkMin / $dailyTalk) * 10.0;

        $r['calling_score'] = round($sTarget + $sConn + $sUniq + $sInter + $sFo + $sTalk, 1);

        $leads = (int)$r['converted_leads'];
        $disbCount = (int)$r['disbursed_loans'];
        $disbAmt = (float)$r['disbursed_amount'];

        $sLeads = min(1.0, $leads / 3.0) * 40.0;
        $sDisbC = min(1.0, $disbCount / 1.0) * 30.0;
        $sDisbA = min(1.0, $disbAmt / 500000.0) * 30.0;

        $r['sales_score'] = round($sLeads + $sDisbC + $sDisbA, 1);
    }
    unset($r);

    usort($ranked, function($a, $b) {
        if ($b['calling_score'] == $a['calling_score']) {
            return $b['total_dials'] <=> $a['total_dials'];
        }
        return $b['calling_score'] <=> $a['calling_score'];
    });

    return $ranked;
}

<?php

function ensureLeadModuleSchema(mysqli $link): void
{
    static $done = false;
    if ($done) {
        return;
    }

    mysqli_set_charset($link, 'utf8mb4');

    // Ensure lead_notes table exists
    mysqli_query($link, "CREATE TABLE IF NOT EXISTS " . tn('TBL_LEAD_NOTES') . " (
        id int(11) NOT NULL AUTO_INCREMENT,
        lead_id int(11) NOT NULL,
        user_id int(11) DEFAULT NULL,
        note text NOT NULL,
        created_at datetime DEFAULT current_timestamp(),
        PRIMARY KEY (id),
        KEY idx_lead_id (lead_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    // Ensure lead_followups table exists
    mysqli_query($link, "CREATE TABLE IF NOT EXISTS " . tn('TBL_LEAD_FOLLOWUPS') . " (
        id int(11) NOT NULL AUTO_INCREMENT,
        lead_id int(11) NOT NULL,
        user_id int(11) DEFAULT NULL,
        followup_at datetime NOT NULL,
        note text DEFAULT NULL,
        status enum('OPEN','DONE','CANCELLED') NOT NULL DEFAULT 'OPEN',
        created_at datetime DEFAULT current_timestamp(),
        PRIMARY KEY (id),
        KEY idx_lead_id (lead_id),
        KEY idx_followup_at (followup_at),
        KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    // Ensure lead_assignments table exists
    mysqli_query($link, "CREATE TABLE IF NOT EXISTS " . tn('TBL_LEAD_ASSIGNMENTS') . " (
        id int(11) NOT NULL AUTO_INCREMENT,
        lead_id int(11) NOT NULL,
        from_user_id int(11) DEFAULT NULL,
        to_user_id int(11) DEFAULT NULL,
        from_team_id int(11) DEFAULT NULL,
        to_team_id int(11) DEFAULT NULL,
        reason text DEFAULT NULL,
        created_at datetime DEFAULT current_timestamp(),
        PRIMARY KEY (id),
        KEY idx_lead_id (lead_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $columns = [];
    $result = mysqli_query($link, "SHOW COLUMNS FROM " . tn('TBL_LEADS'));
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $columns[$row['Field']] = $row['Type'];
    }

    $addColumn = static function (string $name, string $definition) use ($link, &$columns): void {
        if (isset($columns[$name])) {
            return;
        }
        mysqli_query($link, "ALTER TABLE " . tn('TBL_LEADS') . " ADD COLUMN {$name} {$definition}");
        $columns[$name] = $definition;
    };

    $addColumn('login_date', "DATE DEFAULT NULL AFTER updated_by");
    $addColumn('net_salary', "VARCHAR(50) DEFAULT NULL AFTER login_date");
    $addColumn('salary_account', "VARCHAR(100) DEFAULT NULL AFTER net_salary");
    $addColumn('bank_name', "VARCHAR(100) DEFAULT NULL AFTER salary_account");
    $addColumn('loan_amount', "VARCHAR(50) DEFAULT NULL AFTER bank_name");
    $addColumn('loan_tenure', "VARCHAR(50) DEFAULT NULL AFTER loan_amount");
    $addColumn('promo_code', "VARCHAR(100) DEFAULT NULL AFTER loan_tenure");
    $addColumn('login_bank_name', "VARCHAR(100) DEFAULT NULL AFTER promo_code");
    $addColumn(
        'lead_status_new',
        "ENUM('LEAD','FOLLOWUP','INTERNAL_UNDERWRITING','LOGIN','BANK_UNDERWRITING','SANCTIONED','DISBURSED','REJECT') NOT NULL DEFAULT 'LEAD' AFTER login_bank_name"
    );
    $addColumn('login_mode', "ENUM('ONLINE','MAIL','PHYSICALLY') DEFAULT NULL AFTER lead_status_new");
    $addColumn('loan_type', "VARCHAR(100) DEFAULT NULL AFTER login_mode");
    $addColumn('loan_app_no', "VARCHAR(100) DEFAULT NULL AFTER loan_type");
    $addColumn('login_location', "VARCHAR(100) DEFAULT NULL AFTER loan_app_no");
    $addColumn('bank_rm_name', "VARCHAR(100) DEFAULT NULL AFTER login_location");
    $addColumn('bt_details', "TEXT DEFAULT NULL AFTER bank_rm_name");
    $addColumn('dsa_name', "VARCHAR(100) DEFAULT NULL AFTER bt_details");

    // Phase 0 migration columns (self-heal on live without running SQL manually)
    $addColumn('team_id', "INT DEFAULT NULL AFTER dsa_name");
    $addColumn('rework_flag', "TINYINT(1) NOT NULL DEFAULT 0 AFTER team_id");
    $addColumn('rework_stage', "ENUM('INTERNAL','BANK') DEFAULT NULL AFTER rework_flag");
    $addColumn('login_status', "ENUM('PENDING','SUCCESS','REJECTED') DEFAULT NULL AFTER rework_stage");
    $addColumn('login_submitted_by', "INT DEFAULT NULL AFTER login_status");
    $addColumn('login_submitted_at', "DATETIME DEFAULT NULL AFTER login_submitted_by");
    $addColumn('forwarded_flag', "TINYINT(1) NOT NULL DEFAULT 0 AFTER login_submitted_at");
    $addColumn('sent_backward_flag', "TINYINT(1) NOT NULL DEFAULT 0 AFTER forwarded_flag");
    $addColumn('parent_lead_id', "INT DEFAULT NULL AFTER sent_backward_flag");

    if (isset($columns['lead_status_new']) && stripos((string)$columns['lead_status_new'], "'REJECT'") === false) {
        mysqli_query(
            $link,
            "ALTER TABLE " . tn('TBL_LEADS') . "
             MODIFY COLUMN lead_status_new
             ENUM('LEAD','FOLLOWUP','INTERNAL_UNDERWRITING','LOGIN','BANK_UNDERWRITING','SANCTIONED','DISBURSED','REJECT')
             NOT NULL DEFAULT 'LEAD'"
        );
        $columns['lead_status_new'] = "ENUM('LEAD','FOLLOWUP','INTERNAL_UNDERWRITING','LOGIN','BANK_UNDERWRITING','SANCTIONED','DISBURSED','REJECT')";
    }

    if (isset($columns['salary_bank_name'])) {
        mysqli_query(
            $link,
            "UPDATE " . tn('TBL_LEADS') . " SET bank_name = COALESCE(NULLIF(bank_name, ''), salary_bank_name)
             WHERE salary_bank_name IS NOT NULL AND salary_bank_name <> ''"
        );
    }

    mysqli_query(
        $link,
        "UPDATE " . tn('TBL_LEADS') . "
         SET lead_status_new = CASE lead_status
             WHEN 'Follow_Up' THEN 'FOLLOWUP'
             WHEN 'In_Progress' THEN 'LOGIN'
             WHEN 'Converted' THEN 'DISBURSED'
             WHEN 'Lost' THEN 'REJECT'
             ELSE 'LEAD'
         END
         WHERE lead_status_new IS NULL
            OR lead_status_new = ''
            OR (lead_status_new = 'LEAD' AND lead_status IN ('Follow_Up', 'In_Progress', 'Converted', 'Lost'))"
    );

    $existingIndexes = [];
    $indexResult = mysqli_query($link, "SHOW INDEX FROM " . tn('TBL_LEADS'));
    while ($indexResult && ($row = mysqli_fetch_assoc($indexResult))) {
        $existingIndexes[$row['Key_name']] = true;
    }

    $addIndex = static function (string $indexName, string $sql) use ($link, $existingIndexes): void {
        if (isset($existingIndexes[$indexName])) {
            return;
        }
        mysqli_query($link, $sql);
    };

    $addIndex('idx_login_date', "ALTER TABLE " . tn('TBL_LEADS') . " ADD INDEX idx_login_date (login_date)");
    $addIndex('idx_lead_status_new', "ALTER TABLE " . tn('TBL_LEADS') . " ADD INDEX idx_lead_status_new (lead_status_new)");
    $addIndex('idx_login_bank_name', "ALTER TABLE " . tn('TBL_LEADS') . " ADD INDEX idx_login_bank_name (login_bank_name)");
    $addIndex('idx_loan_type', "ALTER TABLE " . tn('TBL_LEADS') . " ADD INDEX idx_loan_type (loan_type)");

    $done = true;
}

function leadStatusOptions(): array
{
    return ['LEAD', 'FOLLOWUP', 'INTERNAL_UNDERWRITING', 'LOGIN', 'BANK_UNDERWRITING', 'SANCTIONED', 'DISBURSED', 'REJECT'];
}

function leadStatusMeta(): array
{
    return [
        'LEAD' => [
            'label' => 'Lead',
            'icon' => 'bi-person-badge',
            'owner' => 'Telecaller',
            'description' => 'Fresh enquiry and qualification in progress.'
        ],
        'FOLLOWUP' => [
            'label' => 'Follow-up',
            'icon' => 'bi-arrow-repeat',
            'owner' => 'Telecaller',
            'description' => 'Waiting on customer response or next action.'
        ],
        'INTERNAL_UNDERWRITING' => [
            'label' => 'Internal Underwriting',
            'icon' => 'bi-building-check',
            'owner' => 'Back Office',
            'description' => 'Our Back Office checks documents and eligibility BEFORE bank login.'
        ],
        'LOGIN' => [
            'label' => 'Login',
            'icon' => 'bi-box-arrow-in-right',
            'owner' => 'Back Office',
            'description' => 'File logged into the bank / lending partner.'
        ],
        'BANK_UNDERWRITING' => [
            'label' => 'Bank Underwriting',
            'icon' => 'bi-bank',
            'owner' => 'Bank / Manager',
            'description' => 'Bank reviews the logged-in file for approval.'
        ],
        'SANCTIONED' => [
            'label' => 'Sanctioned',
            'icon' => 'bi-patch-check',
            'owner' => 'Manager',
            'description' => 'Loan approved and ready for release steps.'
        ],
        'DISBURSED' => [
            'label' => 'Disbursed',
            'icon' => 'bi-cash-stack',
            'owner' => 'Manager',
            'description' => 'Funds released successfully.'
        ],
        'REJECT' => [
            'label' => 'Reject',
            'icon' => 'bi-x-octagon',
            'owner' => 'Manager',
            'description' => 'Case closed or declined.'
        ],
    ];
}

function leadJourneySteps(): array
{
    return [
        ['key' => 'LEAD', 'label' => 'Qualification', 'icon' => 'bi-person-lines-fill'],
        ['key' => 'FOLLOWUP', 'label' => 'Follow-up', 'icon' => 'bi-arrow-repeat'],
        ['key' => 'INTERNAL_UNDERWRITING', 'label' => 'Internal Check', 'icon' => 'bi-building-check'],
        ['key' => 'LOGIN', 'label' => 'Bank Login', 'icon' => 'bi-box-arrow-in-right'],
        ['key' => 'BANK_UNDERWRITING', 'label' => 'Bank Underwriting', 'icon' => 'bi-bank'],
        ['key' => 'SANCTIONED', 'label' => 'Sanction', 'icon' => 'bi-patch-check'],
        ['key' => 'DISBURSED', 'label' => 'Disbursal', 'icon' => 'bi-cash-coin'],
    ];
}

function loginModeOptions(): array
{
    return ['ONLINE', 'MAIL', 'PHYSICALLY'];
}

function loanTypeOptions(): array
{
    return [
        'Personal Loan',
        'Home Loan',
        'Business Loan',
        'Education Loan',
        'Car Loan',
        'Loan Against Property',
        'Balance Transfer',
        'Credit Card',
        'Working Capital Loan',
        'Professional Loan'
    ];
}

function indianBankOptions(): array
{
    return [
        'State Bank of India',
        'HDFC Bank',
        'ICICI Bank',
        'Axis Bank',
        'Kotak Mahindra Bank',
        'Punjab National Bank',
        'Bank of Baroda',
        'Canara Bank',
        'Union Bank of India',
        'Indian Bank',
        'Bank of India',
        'IndusInd Bank',
        'IDFC FIRST Bank',
        'Yes Bank',
        'AU Small Finance Bank',
        'Federal Bank',
        'South Indian Bank',
        'RBL Bank',
        'Bandhan Bank',
        'IDBI Bank',
        'UCO Bank',
        'Central Bank of India',
        'Punjab and Sind Bank',
        'Bank of Maharashtra',
        'Karnataka Bank',
        'Karur Vysya Bank',
        'City Union Bank',
        'Tamilnad Mercantile Bank'
    ];
}

function leadStatusBadgeClass(string $status): string
{
    return match (strtoupper($status)) {
        'FOLLOWUP' => 'bg-info text-dark',
        'LEAD' => 'bg-primary',
        'INTERNAL_UNDERWRITING' => 'bg-secondary',
        'LOGIN' => 'bg-warning text-dark',
        'BANK_UNDERWRITING' => 'bg-purple',
        'SANCTIONED' => 'bg-success',
        'DISBURSED' => 'bg-dark',
        'REJECT' => 'bg-danger',
        default => 'bg-light text-dark border'
    };
}

function loginStatusMeta(): array
{
    return [
        '' => ['label' => '—', 'class' => 'bg-light text-dark border'],
        'PENDING' => ['label' => 'Login Pending', 'class' => 'bg-warning text-dark'],
        'SUCCESS' => ['label' => 'Login Success', 'class' => 'bg-success'],
        'REWORK_PENDING' => ['label' => 'Rework Pending', 'class' => 'bg-danger'],
        'REJECTED' => ['label' => 'Login Rejected', 'class' => 'bg-dark'],
    ];
}

function loginStatusBadgeClass(string $status): string
{
    return loginStatusMeta()[$status]['class'] ?? 'bg-light text-dark border';
}

function loginStatusLabel(string $status): string
{
    return loginStatusMeta()[$status]['label'] ?? '—';
}

function normalizeLeadDate(?string $value): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return null;
    }

    return date('Y-m-d', $timestamp);
}

function normalizeFollowupDate(?string $value): ?string
{
    $date = normalizeLeadDate($value);
    return $date ? ($date . ' 00:00:00') : null;
}

function getLeadAssignableTBL_USERS(mysqli $link): array
{
    $accessibleUserIds = getAccessibleUserIds($link);
    $whereParts = ["STATUS = 'Active'", "ROLE != 'Super Admin'"];

    if (isOfficer()) {
        $whereParts[] = 'ID = ' . (int)USER_ID;
    } elseif (!isAdmin() && !empty($accessibleUserIds)) {
        $whereParts[] = 'ID IN (' . implode(',', array_map('intval', $accessibleUserIds)) . ')';
    }

    $sql = "SELECT ID, NAME, ROLE, TEAM_ID FROM " . tn('TBL_USERS') . " WHERE " . implode(' AND ', $whereParts) . " ORDER BY NAME";
    $result = mysqli_query($link, $sql);

    return $result ? mysqli_fetch_all($result, MYSQLI_ASSOC) : [];
}

function canAssignLeadToUserId(mysqli $link, int $targetUserId): bool
{
    if ($targetUserId <= 0) {
        return false;
    }

    foreach (getLeadAssignableTBL_USERS($link) as $user) {
        if ((int)$user['ID'] === $targetUserId) {
            return true;
        }
    }

    return false;
}

function canEditLeadAssignment(): bool
{
    return isAdmin() || isManager() || isSupervisor();
}

function getLeadAccessCondition(mysqli $link, string $alias = 'l'): ?string
{
    if (isAdmin()) {
        return null;
    }

    $accessibleUserIds = getAccessibleUserIds($link);
    if (empty($accessibleUserIds)) {
        return "{$alias}.assigned_to = " . (int)USER_ID;
    }

    return "{$alias}.assigned_to IN (" . implode(',', array_map('intval', $accessibleUserIds)) . ")";
}

/**
 * Tray / visibility helpers for the multi-stage lead workflow.
 * A lead is visible only to: its telecaller (assigned_to), their Supervisor,
 * their Manager, or a Super Admin. The Back Office team additionally sees leads
 * assigned to their team during internal underwriting / login.
 */

function getLeadTrayOwner(array $lead): array
{
    return [
        'assigned_to' => (int)($lead['assigned_to'] ?? 0),
        'team_id' => (int)($lead['team_id'] ?? 0),
    ];
}

function canEditLead(array $lead): bool
{
    if (isAdmin() || isManager()) {
        return true;
    }

    $assignedTo = (int)($lead['assigned_to'] ?? 0);
    if ($assignedTo === (int)USER_ID) {
        return true;
    }

    if (can('manage_leads')) {
        $teamId = (int)($lead['team_id'] ?? 0);
        if ($teamId > 0 && userBelongsToTeam((int)USER_ID, $teamId)) {
            return true;
        }
    }

    return false;
}

function canViewLead(mysqli $link, array $lead): bool
{
    if (isAdmin()) {
        return true;
    }

    $assignedTo = (int)($lead['assigned_to'] ?? 0);
    if ($assignedTo === (int)USER_ID) {
        return true;
    }

    $teamId = (int)($lead['team_id'] ?? 0);
    if ($teamId > 0 && userBelongsToTeam((int)USER_ID, $teamId)) {
        return true;
    }

    return inLeadManagerChain($link, $assignedTo);
}

function canPullToTray(array $lead): bool
{
    if (isAdmin() || isManager()) {
        return true;
    }

    return can('manage_leads');
}

function userBelongsToTeam(int $userId, int $teamId): bool
{
    if ($userId <= 0 || $teamId <= 0) {
        return false;
    }

    $link = $GLOBALS['link'] ?? null;
    if (!$link) {
        return false;
    }

    $stmt = mysqli_prepare($link, "SELECT 1 FROM " . tn('TBL_USERS') . " WHERE ID = ? AND TEAM_ID = ? LIMIT 1");
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, 'ii', $userId, $teamId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $ok = (bool)mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);

    return $ok;
}

function inLeadManagerChain(mysqli $link, int $ownerUserId): bool
{
    if ($ownerUserId <= 0) {
        return false;
    }

    if (isManager()) {
        $managed = getManagerTeamIds($link, (int)USER_ID);
        if (empty($managed)) {
            return true;
        }
        $stmt = mysqli_prepare($link, "SELECT TEAM_ID FROM " . tn('TBL_USERS') . " WHERE ID = ? LIMIT 1");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $ownerUserId);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            $row = mysqli_fetch_assoc($res);
            mysqli_stmt_close($stmt);
            if ($row && in_array((int)$row['TEAM_ID'], $managed, true)) {
                return true;
            }
        }
        return false;
    }

    if (isSupervisor()) {
        $stmt = mysqli_prepare($link, "SELECT TEAM_ID FROM " . tn('TBL_USERS') . " WHERE ID = ? LIMIT 1");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $ownerUserId);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            $row = mysqli_fetch_assoc($res);
            mysqli_stmt_close($stmt);
            if ($row) {
                return userBelongsToTeam((int)USER_ID, (int)$row['TEAM_ID']);
            }
        }
        return false;
    }

    return false;
}

function escapeLikeValue(mysqli $link, string $value): string
{
    return mysqli_real_escape_string(
        $link,
        str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value)
    );
}

function ensureLeadFilterPreferenceSchema(mysqli $link): void
{
    static $done = false;
    if ($done) {
        return;
    }

    mysqli_query(
        $link,
        "CREATE TABLE IF NOT EXISTS " . tn('TBL_USER_PAGE_FILTERS') . " (
            preference_id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            page_key VARCHAR(100) NOT NULL,
            filter_json LONGTEXT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_user_page (user_id, page_key),
            KEY idx_page_key (page_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $done = true;
}

function getUserPageFilterPreference(mysqli $link, int $userId, string $pageKey): array
{
    ensureLeadFilterPreferenceSchema($link);

    $stmt = mysqli_prepare(
        $link,
        "SELECT filter_json
         FROM " . tn('TBL_USER_PAGE_FILTERS') . "
         WHERE user_id = ? AND page_key = ?
         LIMIT 1"
    );
    mysqli_stmt_bind_param($stmt, 'is', $userId, $pageKey);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);

    if (!$row || !isset($row['filter_json'])) {
        return [];
    }

    $decoded = json_decode((string)$row['filter_json'], true);
    return is_array($decoded) ? $decoded : [];
}

function saveUserPageFilterPreference(mysqli $link, int $userId, string $pageKey, array $state): bool
{
    ensureLeadFilterPreferenceSchema($link);

    $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }

    $stmt = mysqli_prepare(
        $link,
        "INSERT INTO " . tn('TBL_USER_PAGE_FILTERS') . " (user_id, page_key, filter_json)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE
             filter_json = VALUES(filter_json),
             updated_at = CURRENT_TIMESTAMP"
    );
    mysqli_stmt_bind_param($stmt, 'iss', $userId, $pageKey, $json);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    return $ok;
}

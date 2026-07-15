<?php

function ensureLeadModuleSchema(mysqli $link): void
{
    static $done = false;
    if ($done) {
        return;
    }

    mysqli_set_charset($link, 'utf8mb4');

    $columns = [];
    $result = mysqli_query($link, "SHOW COLUMNS FROM LEADS_TABLE");
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $columns[$row['Field']] = $row['Type'];
    }

    $addColumn = static function (string $name, string $definition) use ($link, &$columns): void {
        if (isset($columns[$name])) {
            return;
        }
        mysqli_query($link, "ALTER TABLE LEADS_TABLE ADD COLUMN {$name} {$definition}");
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
        "ENUM('FOLLOWUP','LEAD','LOGIN','UNDERWRTING','SANCTIONED','DISBURSED','REJECT') NOT NULL DEFAULT 'LEAD' AFTER login_bank_name"
    );
    $addColumn('login_mode', "ENUM('ONLINE','MAIL','PHYSICALLY') DEFAULT NULL AFTER lead_status_new");
    $addColumn('loan_type', "VARCHAR(100) DEFAULT NULL AFTER login_mode");
    $addColumn('loan_app_no', "VARCHAR(100) DEFAULT NULL AFTER loan_type");
    $addColumn('login_location', "VARCHAR(100) DEFAULT NULL AFTER loan_app_no");
    $addColumn('bank_rm_name', "VARCHAR(100) DEFAULT NULL AFTER login_location");
    $addColumn('bt_details', "TEXT DEFAULT NULL AFTER bank_rm_name");
    $addColumn('dsa_name', "VARCHAR(100) DEFAULT NULL AFTER bt_details");

    if (isset($columns['lead_status_new']) && stripos((string)$columns['lead_status_new'], "'REJECT'") === false) {
        mysqli_query(
            $link,
            "ALTER TABLE LEADS_TABLE
             MODIFY COLUMN lead_status_new
             ENUM('FOLLOWUP','LEAD','LOGIN','UNDERWRTING','SANCTIONED','DISBURSED','REJECT')
             NOT NULL DEFAULT 'LEAD'"
        );
        $columns['lead_status_new'] = "ENUM('FOLLOWUP','LEAD','LOGIN','UNDERWRTING','SANCTIONED','DISBURSED','REJECT')";
    }

    if (isset($columns['salary_bank_name'])) {
        mysqli_query(
            $link,
            "UPDATE LEADS_TABLE SET bank_name = COALESCE(NULLIF(bank_name, ''), salary_bank_name)
             WHERE salary_bank_name IS NOT NULL AND salary_bank_name <> ''"
        );
    }

    mysqli_query(
        $link,
        "UPDATE LEADS_TABLE
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
    $indexResult = mysqli_query($link, "SHOW INDEX FROM LEADS_TABLE");
    while ($indexResult && ($row = mysqli_fetch_assoc($indexResult))) {
        $existingIndexes[$row['Key_name']] = true;
    }

    $addIndex = static function (string $indexName, string $sql) use ($link, $existingIndexes): void {
        if (isset($existingIndexes[$indexName])) {
            return;
        }
        mysqli_query($link, $sql);
    };

    $addIndex('idx_login_date', "ALTER TABLE LEADS_TABLE ADD INDEX idx_login_date (login_date)");
    $addIndex('idx_lead_status_new', "ALTER TABLE LEADS_TABLE ADD INDEX idx_lead_status_new (lead_status_new)");
    $addIndex('idx_login_bank_name', "ALTER TABLE LEADS_TABLE ADD INDEX idx_login_bank_name (login_bank_name)");
    $addIndex('idx_loan_type', "ALTER TABLE LEADS_TABLE ADD INDEX idx_loan_type (loan_type)");

    $done = true;
}

function leadStatusOptions(): array
{
    return ['FOLLOWUP', 'LEAD', 'LOGIN', 'UNDERWRTING', 'SANCTIONED', 'DISBURSED', 'REJECT'];
}

function leadStatusMeta(): array
{
    return [
        'LEAD' => [
            'label' => 'Lead',
            'icon' => 'bi-person-badge',
            'description' => 'Fresh enquiry and qualification in progress.'
        ],
        'FOLLOWUP' => [
            'label' => 'Follow-up',
            'icon' => 'bi-arrow-repeat',
            'description' => 'Waiting on customer response or next action.'
        ],
        'LOGIN' => [
            'label' => 'Login',
            'icon' => 'bi-box-arrow-in-right',
            'description' => 'Application logged with bank or lending partner.'
        ],
        'UNDERWRTING' => [
            'label' => 'Underwriting',
            'icon' => 'bi-file-earmark-check',
            'description' => 'Documents and eligibility under review.'
        ],
        'SANCTIONED' => [
            'label' => 'Sanctioned',
            'icon' => 'bi-patch-check',
            'description' => 'Loan approved and ready for release steps.'
        ],
        'DISBURSED' => [
            'label' => 'Disbursed',
            'icon' => 'bi-cash-stack',
            'description' => 'Funds released successfully.'
        ],
        'REJECT' => [
            'label' => 'Reject',
            'icon' => 'bi-x-octagon',
            'description' => 'Case closed or declined.'
        ],
    ];
}

function leadJourneySteps(): array
{
    return [
        ['key' => 'LEAD', 'label' => 'Qualification', 'icon' => 'bi-person-lines-fill'],
        ['key' => 'FOLLOWUP', 'label' => 'Follow-up', 'icon' => 'bi-arrow-repeat'],
        ['key' => 'LOGIN', 'label' => 'Application Login', 'icon' => 'bi-box-arrow-in-right'],
        ['key' => 'UNDERWRTING', 'label' => 'Underwriting', 'icon' => 'bi-clipboard2-check'],
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
        'LOGIN' => 'bg-warning text-dark',
        'UNDERWRTING' => 'bg-secondary',
        'SANCTIONED' => 'bg-success',
        'DISBURSED' => 'bg-dark',
        'REJECT' => 'bg-danger',
        default => 'bg-light text-dark border'
    };
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

function getLeadAssignableUsers(mysqli $link): array
{
    $accessibleUserIds = getAccessibleUserIds($link);
    $whereParts = ["STATUS = 'Active'"];

    if (isOfficer()) {
        $whereParts[] = 'ID = ' . (int)USER_ID;
    } elseif (!isAdmin() && !empty($accessibleUserIds)) {
        $whereParts[] = 'ID IN (' . implode(',', array_map('intval', $accessibleUserIds)) . ')';
    }

    $sql = "SELECT ID, NAME, ROLE, TEAM_ID FROM users WHERE " . implode(' AND ', $whereParts) . " ORDER BY NAME";
    $result = mysqli_query($link, $sql);

    return $result ? mysqli_fetch_all($result, MYSQLI_ASSOC) : [];
}

function canAssignLeadToUserId(mysqli $link, int $targetUserId): bool
{
    if ($targetUserId <= 0) {
        return false;
    }

    foreach (getLeadAssignableUsers($link) as $user) {
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
        "CREATE TABLE IF NOT EXISTS USER_PAGE_FILTERS (
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
         FROM USER_PAGE_FILTERS
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
        "INSERT INTO USER_PAGE_FILTERS (user_id, page_key, filter_json)
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

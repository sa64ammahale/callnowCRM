<?php
require_once __DIR__ . '/../../../php_scripts/auth.php';
require_once __DIR__ . '/../lead_common.php';
api_init();

header('Content-Type: application/json; charset=utf-8');

$allowedTBL_ROLES = ['Admin', 'Manager', 'Supervisor', 'Officer'];
if (!in_array(USER_ROLE, $allowedTBL_ROLES, true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Access denied.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);
    exit;
}

ensureLeadModuleSchema($link);
ensureLeadFilterPreferenceSchema($link);

$rawInput = file_get_contents('php://input');
$payload = json_decode($rawInput ?: '{}', true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Invalid payload.']);
    exit;
}

$sanitizeScalar = static function ($value): string {
    return trim(substr((string)$value, 0, 120));
};

$columnFilters = [];
if (isset($payload['columnFilters']) && is_array($payload['columnFilters'])) {
    foreach ($payload['columnFilters'] as $key => $value) {
        $columnFilters[(string)$key] = trim(substr((string)$value, 0, 200));
    }
}

$state = [
    'quickStatus' => $sanitizeScalar($payload['quickStatus'] ?? 'PIPELINE'),
    'loginMonth' => $sanitizeScalar($payload['loginMonth'] ?? 'current_previous'),
    'followupMonth' => $sanitizeScalar($payload['followupMonth'] ?? ''),
    'quickLoginMode' => $sanitizeScalar($payload['quickLoginMode'] ?? ''),
    'quickAssigned' => $sanitizeScalar($payload['quickAssigned'] ?? ''),
    'quickLoanType' => trim(substr((string)($payload['quickLoanType'] ?? ''), 0, 180)),
    'globalSearch' => trim(substr((string)($payload['globalSearch'] ?? ''), 0, 200)),
    'perPage' => (int)($payload['perPage'] ?? 12),
    'page' => (int)($payload['page'] ?? 1),
    'columnFilters' => $columnFilters,
];

$saved = saveUserPageFilterPreference($link, USER_ID, 'lead_list', $state);

echo json_encode([
    'ok' => $saved,
    'message' => $saved ? 'Saved' : 'Unable to save filter state.',
]);

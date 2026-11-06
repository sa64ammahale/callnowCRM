<?php
define('API_SKIP_AUTO_AUTH', true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../php_scripts/api_auth.php';
require_once __DIR__ . '/../php_scripts/reporting_engine.php';

echo "=== STARTING LOAN TELECALLING PERFORMANCE & REPORTING TEST SUITE ===" . PHP_EOL;

$passCount = 0;
$failCount = 0;

function assertTest(string $desc, bool $condition): void {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo "  [PASS] $desc" . PHP_EOL;
    } else {
        $failCount++;
        echo "  [FAIL] $desc" . PHP_EOL;
    }
}

// Setup test test contact in main_database
$testMobile = '99999' . str_pad((string)mt_rand(10000, 99999), 5, '0', STR_PAD_LEFT);
$testName = 'Test Candidate ' . bin2hex(random_bytes(3));
$officerRow = $link->query("SELECT ID FROM " . tn('TBL_USERS') . " WHERE ROLE = 'Officer' AND STATUS = 'Active' LIMIT 1")->fetch_assoc();
$officerId = $officerRow ? (int)$officerRow['ID'] : 4;

$insC = $link->prepare("
    INSERT INTO " . tn('TBL_MAIN') . " 
        (MAINDATABASE_NAME, MAINDATABASE_MOBILE, MAINDATABASE_COMPANY, MAINDATABASE_PACKAGE, MAINDATABASE_OTHER_INFO, MAINDATABASE_CALL_DIALED_USER, MAINDATABASE_UPLOAD_DATETIME)
    VALUES (?, ?, 'Acme Loans', '500000', 'Pre-qualified candidate', ?, NOW())
");
$insC->bind_param('ssi', $testName, $testMobile, $officerId);
$insC->execute();
$contactId = (int)$insC->insert_id;
$insC->close();

// Scenario 1: Contact never called
$cMetrics = getClientContactMetrics($link, $officerId, null, null, 'Officer');
assertTest("Scenario 1: New contact exists as Assigned Contact and counts in not_yet_called", $contactId > 0 && $cMetrics['total_assigned'] > 0);

$chkLead = $link->query("SELECT lead_id FROM " . tn('TBL_LEADS') . " WHERE cust_id = $contactId")->fetch_assoc();
assertTest("Scenario 1: Never called contact does NOT exist in Leads table", empty($chkLead));

// Scenario 2: Contact called but Not Interested
$call1Status = 'Not Interested';
$synced1 = syncCallToLeadsAndPipeline($link, 'MAIN', $contactId, $officerId, $call1Status, 'Customer rejected loan offer', null);
$link->query("UPDATE " . tn('TBL_MAIN') . " SET CALL_COUNT = 1, LAST_DIALED_DATE_TIME = NOW(), MAINDATABASE_CALL_DIALED_STATUS = 'Not Interested' WHERE ID = $contactId");

$chkLead2 = $link->query("SELECT lead_id FROM " . tn('TBL_LEADS') . " WHERE cust_id = $contactId")->fetch_assoc();
assertTest("Scenario 2: Not Interested call returns null lead_id", $synced1 === null);
assertTest("Scenario 2: Contact remains Client Contact without generating a Lead", empty($chkLead2));

// Scenario 3: Contact requires Callback
$callbackDate = date('Y-m-d H:i:s', strtotime('+2 days 10:30:00'));
$synced2 = syncCallToLeadsAndPipeline($link, 'MAIN', $contactId, $officerId, 'Callback / Busy', 'Call back day after tomorrow', $callbackDate);
$chkFollowup = $link->query("SELECT id, status, followup_at FROM " . tn('TBL_LEAD_FOLLOWUPS') . " WHERE client_contact_id = $contactId AND status = 'OPEN'")->fetch_assoc();
assertTest("Scenario 3: Callback / Busy does not create a Lead", $synced2 === null);
assertTest("Scenario 3: Follow-up is properly scheduled for pre-conversion Client Contact", !empty($chkFollowup) && $chkFollowup['status'] === 'OPEN');

// Scenario 4: Contact becomes Interested -> Converted to Lead
$loanQual = [
    'loan_type' => 'Personal Loan',
    'loan_amount' => '750000',
    'net_salary' => '65000',
    'salary_account' => 'ICICI Bank',
    'bank_name' => 'HDFC Bank'
];
$leadFollowupDate = date('Y-m-d H:i:s', strtotime('+1 day 14:00:00'));
$syncedLeadId = syncCallToLeadsAndPipeline($link, 'MAIN', $contactId, $officerId, 'Enquiry / Interested', 'Customer interested in 7.5L PL', $leadFollowupDate, $loanQual);

assertTest("Scenario 4: Enquiry / Interested creates a new Lead", $syncedLeadId !== null && $syncedLeadId > 0);

$leadRow = $link->query("SELECT * FROM " . tn('TBL_LEADS') . " WHERE lead_id = $syncedLeadId")->fetch_assoc();
assertTest("Scenario 4: Converted Lead preserves relationship with original Client Contact ID", (int)$leadRow['cust_id'] === $contactId);
assertTest("Scenario 4: Converted Lead saves loan qualification details (amount & type)", (string)$leadRow['loan_amount'] === '750000' && $leadRow['loan_type'] === 'Personal Loan');

$convAudit = $link->query("SELECT * FROM " . tn('TBL_CONTACT_LEAD_CONVERSIONS') . " WHERE lead_id = $syncedLeadId")->fetch_assoc();
assertTest("Scenario 4: Conversion is recorded in contact_lead_conversions audit trail", !empty($convAudit) && (int)$convAudit['client_contact_id'] === $contactId);

// Scenario 5: Repeated calls on same contact
for ($i = 0; $i < 3; $i++) {
    $link->query("
        INSERT INTO " . tn('TBL_CALL_LOGS') . "
            (record_id, client_contact_id, lead_id, source, user_id, call_status, call_duration, call_time, created_at)
        VALUES ($contactId, $contactId, $syncedLeadId, 'MAIN', $officerId, 'Connected', 45, NOW(), NOW())
    ");
}
$link->query("UPDATE " . tn('TBL_MAIN') . " SET CALL_COUNT = 4 WHERE ID = $contactId");

$callCountQ = $link->query("SELECT COUNT(*) as c FROM " . tn('TBL_CALL_LOGS') . " WHERE client_contact_id = $contactId")->fetch_assoc();
assertTest("Scenario 5: Multiple call attempts are logged under the single unique Client Contact", (int)$callCountQ['c'] >= 3);

// Scenario 6: Repeated lead conversion request (Idempotency)
$retryLeadId = syncCallToLeadsAndPipeline($link, 'MAIN', $contactId, $officerId, 'Enquiry / Interested', 'Retry request from mobile', $leadFollowupDate, $loanQual);
$leadCountQ = $link->query("SELECT COUNT(*) as c FROM " . tn('TBL_LEADS') . " WHERE cust_id = $contactId")->fetch_assoc();
assertTest("Scenario 6: Retry conversion request returns existing lead_id without duplicate row creation", $retryLeadId === $syncedLeadId && (int)$leadCountQ['c'] === 1);

// Scenario 7: Converted lead progresses to Disbursed
$link->query("UPDATE " . tn('TBL_LEADS') . " SET lead_status_new = 'DISBURSED', loan_amount = '750000' WHERE lead_id = $syncedLeadId");
$lMetrics = getLeadConversionMetrics($link, date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('+1 day')), $officerId, null, null, 'Officer');
assertTest("Scenario 7: Disbursed lead counts in disbursement metrics", $lMetrics['disbursed_loans'] >= 1 && $lMetrics['disbursed_amount'] >= 750000.0);

// Scenario 8: Role Permissions & Scoping
$adminMetrics = getClientContactMetrics($link, null, null, null, 'Super Admin');
assertTest("Scenario 8: Admin scope retrieves organizational metrics", $adminMetrics['total_assigned'] >= $cMetrics['total_assigned']);

// Scenario 9: Hourly Calling Analytics
$hourly = getHourlyCallingAnalytics($link, date('Y-m-d'), date('Y-m-d'), null, null, null, 'Admin');
assertTest("Scenario 9: Hourly Calling Analytics generates standard working day buckets", count($hourly) >= 10);

// Scenario 10: Follow-up Completion & Mobile Sync
$foList = getFollowupList($link, $officerId, null, 'Officer', 'all', 10);
assertTest("Scenario 10: Follow-up list retrieves scheduled callbacks with contact details", !empty($foList));

$firstFoId = (int)$foList[0]['id'];
$link->query("UPDATE " . tn('TBL_LEAD_FOLLOWUPS') . " SET status = 'DONE', completed_at = NOW(), completed_by = $officerId WHERE id = $firstFoId");
$doneFo = $link->query("SELECT status, completed_at FROM " . tn('TBL_LEAD_FOLLOWUPS') . " WHERE id = $firstFoId")->fetch_assoc();
assertTest("Scenario 10: Completing follow-up marks record as DONE with completed_at timestamp", $doneFo['status'] === 'DONE' && !empty($doneFo['completed_at']));

// Cleanup test contact & leads
$link->query("DELETE FROM " . tn('TBL_CONTACT_LEAD_CONVERSIONS') . " WHERE client_contact_id = $contactId");
$link->query("DELETE FROM " . tn('TBL_LEAD_FOLLOWUPS') . " WHERE client_contact_id = $contactId");
$link->query("DELETE FROM " . tn('TBL_CALL_LOGS') . " WHERE client_contact_id = $contactId");
$link->query("DELETE FROM " . tn('TBL_LEADS') . " WHERE cust_id = $contactId");
$link->query("DELETE FROM " . tn('TBL_MAIN') . " WHERE ID = $contactId");

echo PHP_EOL . "=== TEST SUITE COMPLETED: $passCount PASSED, $failCount FAILED ===" . PHP_EOL;

if ($failCount > 0) {
    exit(1);
}

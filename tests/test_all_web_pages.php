<?php
/**
 * Test script to verify that every web module page renders without fatal errors or HTTP 500
 */
$pages = [
    'dashboard.php',
    'profile.php',
    'forgot-password.php',
    'CallNowSignUp.php',
    'modules/logs/Reports.php',
    'modules/logs/call_history.php',
    'modules/logs/manage_activity.php',
    'modules/leads/leads_dashboard.php',
    'modules/leads/lead_pipeline.php',
    'modules/leads/lead_list.php',
    'modules/leads/lead_insert.php',
    'modules/database/data_management_temporary.php',
    'modules/database/data_management_main.php',
    'modules/database/upload_data.php',
    'modules/database/ViewDND.php',
    'modules/database/add_single_number.php',
    'modules/database/add_update_status.php',
    'modules/users/users_view.php',
    'modules/users/users_add.php',
    'modules/users/teams_dashboard.php',
    'modules/users/team_members.php',
    'modules/settings/settings.php',
    'modules/settings/permissions_manager.php',
    'modules/settings/super_admin_panel.php',
    'modules/settings/api_settings.php',
];

echo "====================================================\n";
echo "   CALLNOW CRM WEB PAGES EXECUTION AUDIT           \n";
echo "====================================================\n\n";

$baseDir = dirname(__DIR__);
$phpCli = "C:\\xampp\\php\\php.exe";

$passedPages = 0;
$failedPages = 0;

foreach ($pages as $relPath) {
    $fullPath = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
    if (!file_exists($fullPath)) {
        echo "  [FAIL] $relPath (File does not exist)\n";
        $failedPages++;
        continue;
    }

    // Run PHP process simulating an active logged-in session
    $cmd = "\"$phpCli\" -r \"" .
        "error_reporting(E_ALL); ini_set('display_errors', '1'); " .
        "session_start(); " .
        "\\$_SESSION['loggedin'] = true; " .
        "\\$_SESSION['id'] = 1; " .
        "\\$_SESSION['user_id'] = 1; " .
        "\\$_SESSION['role'] = 'Super Admin'; " .
        "\\$_SESSION['LAST_ACTIVITY'] = time(); " .
        "\\$_SERVER['REQUEST_METHOD'] = 'GET'; " .
        "\\$_SERVER['SCRIPT_NAME'] = '/callnowCRM/$relPath'; " .
        "\\$_SERVER['DOCUMENT_ROOT'] = 'C:/xampp/htdocs'; " .
        "\\$_SERVER['HTTP_HOST'] = 'localhost'; " .
        "chdir(dirname('" . addslashes($fullPath) . "')); " .
        "ob_start(); " .
        "include '" . addslashes($fullPath) . "'; " .
        "\\$out = ob_get_clean(); " .
        "if (strpos(\\$out, 'Fatal error') !== false || strpos(\\$out, 'Parse error') !== false) { " .
        "  echo 'ERROR: ' . substr(\\$out, 0, 500); exit(1); " .
        "} else { " .
        "  echo 'OK (' . strlen(\\$out) . ' bytes)'; exit(0); " .
        "}\"";

    $descriptors = [
        0 => ["pipe", "r"],
        1 => ["pipe", "w"],
        2 => ["pipe", "w"],
    ];

    $proc = proc_open($cmd, $descriptors, $pipes);
    if (is_resource($proc)) {
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        if ($code === 0 && strpos($stdout, 'OK') !== false) {
            echo sprintf("  [PASS] %-45s -> %s\n", $relPath, trim($stdout));
            $passedPages++;
        } else {
            echo sprintf("  [FAIL] %-45s -> Code: %d, Err: %s, Stdout: %s\n", $relPath, $code, trim($stderr), trim($stdout));
            $failedPages++;
        }
    } else {
        echo "  [FAIL] Could not spawn PHP process for $relPath\n";
        $failedPages++;
    }
}

echo "\n====================================================\n";
echo "SUMMARY: Total Pages: " . count($pages) . " | Passed: $passedPages | Failed: $failedPages\n";
echo "====================================================\n";


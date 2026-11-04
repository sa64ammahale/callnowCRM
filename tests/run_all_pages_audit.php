<?php
/**
 * Run render_page_wrapper for all major pages and report results.
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

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

$php = 'C:\\xampp\\php\\php.exe';
$wrapper = __DIR__.'/render_page_wrapper.php';
$base = realpath(__DIR__.'/../'); // project root
$pass = $fail = 0;
foreach ($pages as $rel) {
    $full = $base . DIRECTORY_SEPARATOR . $rel;
    $cmd = "\"$php\" \"$wrapper\" \"$full\"";
    $output = [];
    $ret = 0;
    exec($cmd, $output, $ret);
    $outStr = implode("\n", $output);
    if ($ret === 0 && strpos($outStr, 'OK') !== false) {
        echo "[PASS] $rel - OK\n";
        $pass++;
    } else {
        echo "[FAIL] $rel - $outStr\n";
        $fail++;
    }
}

echo "\nSUMMARY: Passed $pass, Failed $fail, Total ".count($pages)."\n";
?>

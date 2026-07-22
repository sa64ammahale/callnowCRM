<?php
require_once __DIR__ . '/php_scripts/auth.php';

header('Content-Type: text/html; charset=utf-8');
echo "<h1>Page Loading Diagnostic</h1>";
echo "<style>body{font-family:monospace;padding:20px;} .ok{color:green;} .fail{color:red;} .warn{color:orange;}</style>";

// Test function
function testPage($name, $path) {
    echo "<div style='margin:10px 0; border:1px solid #ccc; padding:10px;'>";
    echo "<strong>$name</strong>: ";
    
    try {
        // Start output buffering to capture any errors
        ob_start();
        
        // Check if file exists
        if (!file_exists($path)) {
            echo "<span class='fail'>FILE NOT FOUND</span>";
            ob_end_clean();
            echo "</div>";
            return false;
        }
        
        // Try to include the file
        $result = include $path;
        
        $output = ob_get_clean();
        
        // Check for errors in output
        if (strpos($output, 'Fatal error') !== false || 
            strpos($output, 'Parse error') !== false ||
            strpos($output, 'Warning') !== false ||
            strpos($output, 'Notice') !== false) {
            echo "<span class='fail'>ERRORS DETECTED</span>";
            echo "<pre style='background:#fee; padding:5px;'>" . htmlspecialchars($output) . "</pre>";
        } else {
            echo "<span class='ok'>OK</span>";
        }
        
    } catch (Throwable $e) {
        ob_end_clean();
        echo "<span class='fail'>EXCEPTION: " . htmlspecialchars($e->getMessage()) . "</span>";
    }
    
    echo "</div>";
    return true;
}

// Test database connection
echo "<h2>Database Connection</h2>";
if (isset($link) && $link) {
    echo "<span class='ok'>Connected</span><br>";
    
    // Test critical tables
    $tables = ['users', 'leads_table', 'lead_notes', 'lead_followups', 'lead_assignments', 'main_database', 'temporary_database'];
    echo "<h3>Critical Tables</h3>";
    foreach ($tables as $table) {
        $result = mysqli_query($link, "SHOW TABLES LIKE '$table'");
        if (mysqli_num_rows($result) > 0) {
            echo "<span class='ok'>✓ $table</span><br>";
        } else {
            echo "<span class='fail'>✗ $table (MISSING)</span><br>";
        }
    }
} else {
    echo "<span class='fail'>Not connected</span><br>";
}

// Test constants
echo "<h2>Table Constants</h2>";
$constants = ['TBL_USERS', 'TBL_LEADS', 'TBL_LEAD_NOTES', 'TBL_LEAD_FOLLOWUPS', 'TBL_LEAD_ASSIGNMENTS', 'TBL_MAIN', 'TBL_TEMP'];
foreach ($constants as $const) {
    if (defined($const)) {
        $value = constant($const);
        if ($value === $const) {
            echo "<span class='warn'>⚠ $const = '$value' (UNDEFINED CONSTANT FALLBACK)</span><br>";
        } else {
            echo "<span class='ok'>✓ $const = '$value'</span><br>";
        }
    } else {
        echo "<span class='fail'>✗ $const (NOT DEFINED)</span><br>";
    }
}

// Test tn() function
echo "<h2>tn() Function</h2>";
if (function_exists('tn')) {
    echo "<span class='ok'>✓ tn() function exists</span><br>";
    echo "Testing tn('TBL_USERS'): " . tn('TBL_USERS') . "<br>";
} else {
    echo "<span class='fail'>✗ tn() function NOT defined</span><br>";
}

// Test pages
echo "<h2>Page Loading Tests</h2>";

$pages = [
    'Dashboard' => __DIR__ . '/dashboard.php',
    'Profile' => __DIR__ . '/profile.php',
    'Lead Dashboard' => __DIR__ . '/modules/leads/leads_dashboard.php',
    'Lead List' => __DIR__ . '/modules/leads/lead_list.php',
    'Lead Pipeline' => __DIR__ . '/modules/leads/lead_pipeline.php',
    'Lead View (ID=1)' => __DIR__ . '/modules/leads/lead_view.php',
    'Lead Insert' => __DIR__ . '/modules/leads/lead_insert.php',
    'Temp DB Management' => __DIR__ . '/modules/database/data_management_temporary.php',
    'Main DB Management' => __DIR__ . '/modules/database/data_management_main.php',
    'Upload Data' => __DIR__ . '/modules/database/upload_data.php',
    'Add Single Number' => __DIR__ . '/modules/database/add_single_number.php',
    'Reports' => __DIR__ . '/modules/logs/Reports.php',
    'Activity Log' => __DIR__ . '/modules/logs/manage_activity.php',
    'Settings' => __DIR__ . '/modules/settings/settings.php',
    'Users View' => __DIR__ . '/modules/users/users_view.php',
];

foreach ($pages as $name => $path) {
    // Set dummy GET parameters if needed
    if (strpos($name, 'ID=1') !== false) {
        $_GET['id'] = 1;
    }
    
    testPage($name, $path);
    
    // Clean up
    if (strpos($name, 'ID=1') !== false) {
        unset($_GET['id']);
    }
}

echo "<h2>Header/Sidebar Test</h2>";
try {
    ob_start();
    $pageTitle = 'Test';
    include __DIR__ . '/php_scripts/header.php';
    $output = ob_get_clean();
    
    if (strpos($output, 'Fatal error') !== false || strpos($output, 'Parse error') !== false) {
        echo "<span class='fail'>Header has errors</span>";
        echo "<pre>" . htmlspecialchars($output) . "</pre>";
    } else {
        echo "<span class='ok'>Header loads OK</span>";
    }
} catch (Throwable $e) {
    ob_end_clean();
    echo "<span class='fail'>Header exception: " . htmlspecialchars($e->getMessage()) . "</span>";
}

echo "<hr><p><strong>Diagnostic complete.</strong></p>";

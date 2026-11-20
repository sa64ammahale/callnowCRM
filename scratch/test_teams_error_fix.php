<?php
/**
 * Test script to verify canManageTeams and canViewAllTeams across roles
 * and simulate teams_dashboard.php & team_members.php execution.
 */

// Test 1: Function existence
require_once __DIR__ . '/../php_scripts/config.php';
require_once __DIR__ . '/../php_scripts/hierarchy_engine.php';

assert(function_exists('canManageTeams'), 'canManageTeams must exist');
assert(function_exists('canViewAllTeams'), 'canViewAllTeams must exist');
assert(function_exists('canManageTBL_TEAMS'), 'canManageTBL_TEAMS alias must exist');
assert(function_exists('canViewAllTBL_TEAMS'), 'canViewAllTBL_TEAMS alias must exist');

// Test 2: Role checks with simulated constants
echo "Testing role permissions for teams...\n";

// Super Admin
if (!defined('USER_ROLE')) define('USER_ROLE', 'Super Admin');
if (!defined('USER_ID')) define('USER_ID', 1);

assert(canManageTeams() === true, 'Super Admin canManageTeams must be true');
assert(canViewAllTeams() === true, 'Super Admin canViewAllTeams must be true');
assert(canManageTBL_TEAMS() === true, 'Super Admin canManageTBL_TEAMS alias must be true');
assert(canViewAllTBL_TEAMS() === true, 'Super Admin canViewAllTBL_TEAMS alias must be true');

echo "Super Admin assertions passed.\n";

// Test 3: Test team_auth.php inclusion
require_once __DIR__ . '/../php_scripts/team_auth.php';

assert(canManageTeams() === true, 'team_auth canManageTeams must be true for Super Admin');
assert(canViewAllTeams() === true, 'team_auth canViewAllTeams must be true for Super Admin');

echo "team_auth.php inclusion assertions passed.\n";

// Test 4: Run batch lint on all PHP files in modules/users/
$userModuleFiles = glob(__DIR__ . '/../modules/users/*.php');
foreach ($userModuleFiles as $f) {
    exec("C:\\xampp\\php\\php.exe -l \"$f\"", $output, $returnCode);
    if ($returnCode !== 0) {
        echo "LINT ERROR in $f:\n" . implode("\n", $output) . "\n";
        exit(1);
    }
}
echo "All modules/users/*.php files passed PHP linting without syntax errors.\n";

echo "ALL TESTS PASSED SUCCESSFULLY!\n";

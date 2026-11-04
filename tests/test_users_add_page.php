<?php
/**
 * Automated tests for modules/users/users_add.php
 * Scenarios:
 *   1. Submit with invalid name -> expect error message.
 *   2. Submit with invalid mobile -> expect error message.
 *   3. Submit with short password when creating new user -> expect error.
 *   4. Successful creation (mocked) -> expect redirect (Location header).
 */

require_once __DIR__.'/../php_scripts/auth.php'; // ensure session & auth functions
require_once __DIR__.'/../php_scripts/config.php'; // DB connection

$tests = [];

function run_test($postData, $expectedFragment, $desc) {
    // Simulate environment
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = $postData;
    // Start output buffering to capture include output
    ob_start();
    include __DIR__.'/../modules/users/users_add.php';
    $output = ob_get_clean();
    $passed = strpos($output, $expectedFragment) !== false;
    return [$passed, $output];
}

// Scenario 1: Invalid name (numeric characters)
list($pass,$out) = run_test([
    'name' => 'John123',
    'mobile' => '9876543210',
    'username' => 'john@example.com',
    'password' => 'secret123',
    'role' => 'Officer',
    'team_id' => '1',
    'package' => 'Standard',
    'status' => 'Active',
    'submit' => '1'
], 'Invalid name', 'Invalid name validation');
$tests[] = ['desc'=>'Invalid name','passed'=>$pass,'output'=>$out];

// Scenario 2: Invalid mobile (wrong pattern)
list($pass,$out) = run_test([
    'name' => 'John Doe',
    'mobile' => '12345',
    'username' => 'john@example.com',
    'password' => 'secret123',
    'role' => 'Officer',
    'team_id' => '1',
    'package' => 'Standard',
    'status' => 'Active',
    'submit' => '1'
], 'Invalid 10-digit mobile number', 'Invalid mobile validation');
$tests[] = ['desc'=>'Invalid mobile','passed'=>$pass,'output'=>$out];

// Scenario 3: Short password on create (less than 6 chars)
list($pass,$out) = run_test([
    'name' => 'John Doe',
    'mobile' => '9876543210',
    'username' => 'john@example.com',
    'password' => '123',
    'role' => 'Officer',
    'team_id' => '1',
    'package' => 'Standard',
    'status' => 'Active',
    'submit' => '1'
], 'Password must be at least 6 characters', 'Short password validation');
$tests[] = ['desc'=>'Short password','passed'=>$pass,'output'=>$out];

// Scenario 4: Successful creation (assuming unique data)
list($pass,$out) = run_test([
    'name' => 'Jane Doe',
    'mobile' => '9876543211',
    'username' => 'jane@example.com',
    'password' => 'securePass',
    'role' => 'Officer',
    'team_id' => '1',
    'package' => 'Standard',
    'status' => 'Active',
    'submit' => '1'
], 'New user created successfully', 'Successful creation');
$tests[] = ['desc'=>'Successful creation','passed'=>$pass,'output'=>$out];

// Summary
$passedCount = 0;
foreach ($tests as $t) {
    if ($t['passed']) $passedCount++;
    echo "[" . ($t['passed'] ? 'PASS' : 'FAIL') . "] {$t['desc']}\n";
}

echo "\nSUMMARY: {$passedCount}/" . count($tests) . " passed.\n";
?>


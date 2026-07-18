<?php
require_once 'config.php';

echo "=== users columns ===\n";
$r = $link->query("SHOW COLUMNS FROM users");
while ($x = $r->fetch_assoc()) echo $x['Field'] . " " . $x['Type'] . "\n";

echo "\n=== does users have EMAIL? ===\n";
$r = $link->query("SHOW COLUMNS FROM users LIKE 'EMAIL'");
echo "EMAIL rows: " . $r->num_rows . "\n";

echo "\n=== simulate the page's assignment query (select user_email) ===\n";
$r = $link->query("
    SELECT a.*, u.NAME as user_name, u.ROLE as user_role, u.TEAM_ID as user_team_id,
           t.NAME as team_name, sup.NAME as assigned_by_name
    FROM api_database_assignments a
    JOIN users u ON a.user_id = u.ID
    LEFT JOIN teams t ON a.team_id = t.ID
    LEFT JOIN users sup ON a.assigned_by = sup.ID
    ORDER BY a.assigned_at DESC
");
echo "assignment query ok, rows=" . $r->num_rows . "\n";

echo "\n=== simulate tokens query (t.created_at) ===\n";
$r = $link->query("SELECT t.*, u.NAME as user_name, u.EMAIL as user_email, u.ROLE as user_role FROM api_tokens t JOIN users u ON t.user_id = u.ID ORDER BY t.created_at DESC");
echo "tokens query ok, rows=" . $r->num_rows . "\n";

echo "\n=== api_settings query ===\n";
$r = $link->query("SELECT setting_key, setting_value FROM api_settings");
echo "settings rows=" . $r->num_rows . "\n";

echo "\n=== users query (STATUS active) ===\n";
$r = $link->query("SELECT ID, NAME, EMAIL, ROLE, TEAM_ID FROM users WHERE STATUS = 'Active' ORDER BY ROLE, NAME");
echo "users rows=" . $r->num_rows . "\n";

echo "\n=== access logs query ===\n";
$r = $link->query("SELECT al.*, u.NAME as user_name FROM api_access_logs al LEFT JOIN users u ON al.user_id = u.ID ORDER BY al.created_at DESC LIMIT 50");
echo "logs query ok, rows=" . $r->num_rows . "\n";
?>
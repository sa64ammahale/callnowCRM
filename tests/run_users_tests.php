<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
putenv('AUDIT_TESTS=test_user_workflows.php,test_team_management.php,test_users_section.php');
require __DIR__ . '/run_project_audit.php';

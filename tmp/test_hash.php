<?php
$hashes = [
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    '$2y$10$tS5fdeWE6YpuLDw5kYCo1uq4.sOLF.wH5AiioOlFh/g5kYkojEOh2'
];
$passwords = ['password', 'Admin@123', 'admin123', 'callnow', 'CallNow', '123456', 'admin', 'Password1'];
foreach ($hashes as $hash) {
    echo 'Hash: ' . substr($hash, 0, 30) . '...' . PHP_EOL;
    foreach ($passwords as $pwd) {
        if (password_verify($pwd, $hash)) {
            echo '  -> MATCHES: ' . $pwd . PHP_EOL;
        }
    }
}

// Test scrypt format
$scrypt = 'scrypt:32768:8:1$K7Wq4ixpREnvV62g$44cba910a60c2bffd72b1fabfde03dbc3465c7144779b348c62b2dc2f4e67f94271e55796c4dd55ef68813fbc406857627b43e957c9063122f13febaaf85f822';
echo PHP_EOL . 'Scrypt hash format test: ' . PHP_EOL;
foreach ($passwords as $pwd) {
    if (password_verify($pwd, $scrypt)) {
        echo '  -> MATCHES: ' . $pwd . PHP_EOL;
    }
}

<?php
$scrypt = 'scrypt:32768:8:1$K7Wq4ixpREnvV62g$44cba910a60c2bffd72b1fabfde03dbc3465c7144779b348c62b2dc2f4e67f94271e55796c4dd55ef68813fbc406857627b43e957c9063122f13febaaf85f822';
$passwords = ['password', 'Admin@123', 'admin123', 'callnow', 'CallNow', '123456', 'admin', 'Password1'];

echo "Testing sodium_crypto_pwhash_str_verify:" . PHP_EOL;
foreach ($passwords as $pwd) {
    $result = sodium_crypto_pwhash_str_verify($scrypt, $pwd);
    echo "  $pwd: " . ($result ? 'MATCH' : 'NO MATCH') . PHP_EOL;
}

// Test with sodium extension format conversion
echo PHP_EOL . "Testing with sodium_crypto_pwhash:" . PHP_EOL;
$parts = explode('$', $scrypt);
// scrypt:32768:8:1$salt$hash
preg_match('/^scrypt:(\d+):(\d+):(\d+)\$(.+)\$(.+)$/', $scrypt, $m);
if ($m) {
    echo "opslimit: {$m[1]}, memlimit: {$m[2]}, parallelism: {$m[3]}" . PHP_EOL;
    echo "salt: {$m[4]}" . PHP_EOL;
    echo "hash: {$m[5]}" . PHP_EOL;
}

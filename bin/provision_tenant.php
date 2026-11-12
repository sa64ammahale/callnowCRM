<?php
/**
 * CallNow CRM — Tenant Provisioning CLI Utility
 *
 * Usage:
 *   php bin/provision_tenant.php --slug=zenith --name="Zenith Capital" --dbname=callnow_zenith --dbuser=root --dbpass="" [--dbhost=localhost] [--dbport=3306] [--admin-email=admin@callnow.com] [--admin-pass=Admin@CallNow2026!]
 */

if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.\n");
}

$shortopts = "";
$longopts = [
    "slug:",
    "name:",
    "dbname:",
    "dbuser:",
    "dbpass::",
    "dbhost::",
    "dbport::",
    "admin-email::",
    "admin-pass::",
    "help",
];

$options = getopt($shortopts, $longopts);

if (isset($options['help']) || empty($options['slug']) || empty($options['name']) || empty($options['dbname']) || empty($options['dbuser'])) {
    echo "=================================================================\n";
    echo " CallNow CRM — Tenant Provisioning Tool\n";
    echo "=================================================================\n";
    echo "Usage:\n";
    echo "  php bin/provision_tenant.php [options]\n\n";
    echo "Required Options:\n";
    echo "  --slug=<slug>          Unique company subdomain slug (e.g. zenith)\n";
    echo "  --name=<name>          Company business name (e.g. \"Zenith Capital\")\n";
    echo "  --dbname=<dbname>      Target MySQL database name (e.g. callnow_zenith)\n";
    echo "  --dbuser=<dbuser>      MySQL username for this database\n\n";
    echo "Optional Options:\n";
    echo "  --dbpass=<dbpass>      MySQL password (default: empty)\n";
    echo "  --dbhost=<dbhost>      MySQL host (default: 127.0.0.1)\n";
    echo "  --dbport=<dbport>      MySQL port (default: 3306)\n";
    echo "  --admin-email=<email>  Super Admin email (default: admin@callnow.com)\n";
    echo "  --admin-pass=<pass>    Super Admin password (default: Admin@CallNow2026!)\n";
    echo "=================================================================\n";
    exit(isset($options['help']) ? 0 : 1);
}

$slug     = strtolower(preg_replace('/[^a-zA-Z0-9\-_]/', '', trim($options['slug'])));
$name     = trim($options['name']);
$dbName   = preg_replace('/[^a-zA-Z0-9_]/', '', trim($options['dbname']));
$dbUser   = trim($options['dbuser']);
$dbPass   = isset($options['dbpass']) ? (string)$options['dbpass'] : '';
$dbHost   = !empty($options['dbhost']) ? trim($options['dbhost']) : '127.0.0.1';
$dbPort   = !empty($options['dbport']) ? (int)$options['dbport'] : 3306;
$adminEmail = !empty($options['admin-email']) ? trim($options['admin-email']) : 'admin@callnow.com';
$adminPass  = !empty($options['admin-pass']) ? trim($options['admin-pass']) : 'Admin@CallNow2026!';

echo "-----------------------------------------------------------------\n";
echo " Starting Tenant Provisioning for: $name ($slug)\n";
echo " Database: $dbName on $dbHost:$dbPort\n";
echo "-----------------------------------------------------------------\n";

// 1. Connect to MySQL Server
$link = @mysqli_connect($dbHost, $dbUser, $dbPass, '', $dbPort);
if (!$link) {
    echo "[ERROR] Could not connect to MySQL server at $dbHost:$dbPort. Reason: " . mysqli_connect_error() . "\n";
    exit(1);
}

// 2. Create Database if not exists
echo "[1/4] Creating database `$dbName`...\n";
if (!$link->query("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci")) {
    echo "[ERROR] Failed to create database: " . $link->error . "\n";
    $link->close();
    exit(1);
}

$link->select_db($dbName);

// 3. Execute Baseline Schema
echo "[2/4] Importing baseline production schema...\n";
$schemaPath = __DIR__ . '/../database/production_schema.sql';
if (!file_exists($schemaPath)) {
    echo "[ERROR] Baseline schema file not found at $schemaPath\n";
    $link->close();
    exit(1);
}

$schemaSql = file_get_contents($schemaPath);
$link->multi_query($schemaSql);
do {
    if ($res = $link->store_result()) $res->free();
} while ($link->more_results() && $link->next_result());

if ($link->errno) {
    echo "[ERROR] Schema import error: " . $link->error . "\n";
    $link->close();
    exit(1);
}

// 4. Update Super Admin user credentials if customized
if ($adminEmail !== 'admin@callnow.com' || $adminPass !== 'Admin@CallNow2026!') {
    echo "[3/4] Customizing Super Admin user...\n";
    $passHash = password_hash($adminPass, PASSWORD_BCRYPT);
    $stmt = $link->prepare("UPDATE users SET EMAIL = ?, LOGIN_ID = ?, PASSWORD = ? WHERE ID = 1");
    if ($stmt) {
        $stmt->bind_param('sss', $adminEmail, $adminEmail, $passHash);
        $stmt->execute();
        $stmt->close();
    }
}

// Update app_settings with company name
$stmtApp = $link->prepare("UPDATE app_settings SET setting_value = ? WHERE setting_key = 'company_name'");
if ($stmtApp) {
    $stmtApp->bind_param('s', $name);
    $stmtApp->execute();
    $stmtApp->close();
}

$link->close();

// 5. Update config/tenants.local.php
echo "[4/4] Registering tenant in config/tenants.local.php...\n";
$localConfigFile = __DIR__ . '/../config/tenants.local.php';
$existingTenants = [];
if (file_exists($localConfigFile)) {
    $loaded = include $localConfigFile;
    if (is_array($loaded)) {
        $existingTenants = $loaded;
    }
}

$existingTenants[$slug] = [
    'company_name' => $name,
    'db_host'      => $dbHost,
    'db_port'      => $dbPort,
    'db_name'      => $dbName,
    'db_user'      => $dbUser,
    'db_pass'      => $dbPass,
    'active'       => true,
];

$fileContent = "<?php\n/**\n * CallNow CRM — Local & Production Tenant Overrides\n * Automatically maintained by bin/provision_tenant.php\n */\n\nreturn " . var_export($existingTenants, true) . ";\n";
if (file_put_contents($localConfigFile, $fileContent, LOCK_EX) === false) {
    echo "[WARNING] Could not write to $localConfigFile. Please add tenant manually.\n";
} else {
    echo "Tenant '$slug' registered successfully in config/tenants.local.php.\n";
}

echo "=================================================================\n";
echo " SUCCESS! Tenant successfully provisioned!\n";
echo "-----------------------------------------------------------------\n";
echo " Company Name : $name\n";
echo " Subdomain    : $slug.increditsolutions.in\n";
echo " Access URL   : https://$slug.increditsolutions.in/\n";
echo " Database     : $dbName (User: $dbUser on $dbHost)\n";
echo " Super Admin  : $adminEmail\n";
echo " Password     : $adminPass\n";
echo "=================================================================\n";
exit(0);


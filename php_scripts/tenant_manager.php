<?php
/**
 * CallNow CRM — Multi-Tenant Subdomain & Database Resolution Engine
 */

/**
 * Extract company subdomain from HTTP_HOST
 */
function extractSubdomain(string $host): string {
    $cleanHost = strtolower(trim(explode(':', $host)[0]));

    // IP addresses and localhost have no subdomain
    if ($cleanHost === '' || $cleanHost === 'localhost' || filter_var($cleanHost, FILTER_VALIDATE_IP)) {
        return '';
    }

    $parts = explode('.', $cleanHost);

    // E.g. increditsolutions.in or domain.com (2 parts) -> no subdomain
    if (count($parts) <= 2) {
        return '';
    }

    // Ignore www
    if ($parts[0] === 'www') {
        if (count($parts) <= 3) {
            return '';
        }
        // e.g. www.acme.domain.com -> acme
        return preg_replace('/[^a-z0-9\-_]/', '', $parts[1]);
    }

    // e.g. acme.increditsolutions.in -> acme
    return preg_replace('/[^a-z0-9\-_]/', '', $parts[0]);
}

/**
 * Resolve tenant configuration based on host, header, or query
 */
function resolveTenant(?string $host = null, ?array $registry = null): array {
    if ($registry === null) {
        $configFile = __DIR__ . '/../config/tenants.php';
        $registry = file_exists($configFile) ? include $configFile : [];
    }

    $targetHost = $host ?? ($_SERVER['HTTP_HOST'] ?? 'localhost');

    // 1. Check explicit header X-Tenant-Code (useful for mobile app)
    $headerTenant = !empty($_SERVER['HTTP_X_TENANT_CODE']) ? strtolower(trim($_SERVER['HTTP_X_TENANT_CODE'])) : null;
    if ($headerTenant && isset($registry[$headerTenant])) {
        $t = $registry[$headerTenant];
        $t['slug'] = $headerTenant;
        $t['found'] = true;
        $t['active'] = $t['active'] ?? true;
        return $t;
    }

    // 2. Extract subdomain
    $subdomain = extractSubdomain($targetHost);

    // If a subdomain is present:
    if ($subdomain !== '') {
        if (isset($registry[$subdomain])) {
            $t = $registry[$subdomain];
            $t['slug'] = $subdomain;
            $t['found'] = true;
            $t['active'] = $t['active'] ?? true;
            return $t;
        }

        // Subdomain was requested but is NOT registered
        return [
            'found'        => false,
            'active'       => false,
            'slug'         => $subdomain,
            'company_name' => 'Unknown Company',
            'db_name'      => '',
            'db_host'      => '',
            'db_user'      => '',
            'db_pass'      => '',
            'db_port'      => 3306,
        ];
    }

    // 3. Fallback to default tenant (main domain / localhost / dev)
    $def = $registry['default'] ?? [
        'company_name' => getenv('APP_NAME') ?: 'CallNow CRM',
        'db_host'      => getenv('DB_SERVERNAME') ?: (getenv('DB_HOST') ?: 'localhost'),
        'db_port'      => (int)(getenv('DB_PORT') ?: 3306),
        'db_name'      => getenv('DB_NAME') ?: 'callnow_incredit',
        'db_user'      => getenv('DB_USERNAME') ?: (getenv('DB_USER') ?: 'root'),
        'db_pass'      => getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : (getenv('DB_PASS') !== false ? getenv('DB_PASS') : ''),
        'active'       => true,
    ];

    $def['slug'] = 'default';
    $def['found'] = true;
    $def['active'] = $def['active'] ?? true;
    return $def;
}

/**
 * Generate isolated session cookie name per tenant
 */
function getTenantSessionName(string $slug, string $dbName): string {
    $hash = substr(hash('sha256', $slug . '_' . $dbName), 0, 10);
    return 'CALLNOW_' . strtoupper($hash);
}

/**
 * Render standard 404 page when tenant is unknown or suspended
 */
function renderTenantNotFoundPage(string $host, bool $isApi = false): void {
    http_response_code(404);

    if ($isApi || (isset($_SERVER['REQUEST_URI']) && str_contains($_SERVER['REQUEST_URI'], '/api/'))) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error'   => [
                'code'    => 404,
                'message' => 'Company tenant not found or account inactive for host: ' . htmlspecialchars($host, ENT_QUOTES, 'UTF-8'),
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Company Not Found — CallNow CRM</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>
            body { background-color: #0F172A; color: #F8FAFC; display: flex; align-items: center; justify-content: center; min-height: 100vh; font-family: system-ui, -apple-system, sans-serif; }
            .card { background: #1E293B; border: 1px solid #334155; border-radius: 16px; padding: 2.5rem; max-width: 520px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.5); }
        </style>
    </head>
    <body>
        <div class="card text-center">
            <div class="mb-3 text-warning">
                <svg width="48" height="48" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
            </div>
            <h3 class="fw-bold mb-2">Company Subdomain Not Found</h3>
            <p class="text-secondary mb-4">The company workspace for <strong class="text-light"><?= htmlspecialchars($host, ENT_QUOTES, 'UTF-8') ?></strong> is not registered or has been suspended. Please check the URL or contact your platform administrator.</p>
            <a href="https://increditsolutions.in" class="btn btn-primary px-4 py-2">Go to Main Portal</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

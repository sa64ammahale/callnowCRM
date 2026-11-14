# CallNow CRM V5.00 — Production Deployment Guide

This guide covers deploying CallNow CRM to production environments, including Hostinger shared hosting, cPanel/WHM, and Linux VPS (Ubuntu/Debian with Apache or Nginx).

---

## 1. System Requirements

- **PHP**: 8.0, 8.1, 8.2, or 8.3
  - Extensions required: `mysqli`, `curl`, `json`, `session`, `mbstring`, `fileinfo`, `gd` (optional, for photo avatars)
  - Memory limit: `256M` or higher
  - Execution time: `60s` or higher
- **MySQL / MariaDB**: MySQL 8.0+ or MariaDB 10.4+
  - Default character set: `utf8mb4`
  - Default collation: `utf8mb4_unicode_ci` or `utf8mb4_general_ci`
- **Web Server**:
  - Apache with `mod_rewrite`, `mod_headers`, and `mod_authz_core` (recommended)
  - Or Nginx with PHP-FPM

---

## 2. Pre-Deployment Configuration (`.env`)

1. Copy `.env.example` to `.env` in the project root:
   ```bash
   cp .env.example .env
   ```

2. Configure production variables:
   ```ini
   # Multi-Tenant Master Database
   MASTER_DB_NAME=callnow_master
   MASTER_COMPANIES_TABLE=callnow_telecalling_companys
   DEFAULT_COMPANY_CODE=incredit

   # Database Connection Credentials
   DB_SERVERNAME=localhost
   DB_PORT=3306
   DB_USERNAME=your_db_user
   DB_PASSWORD=your_db_password
   DB_NAME=callnow_incredit

   # Application Environment
   APP_URL=https://crm.yourdomain.com/callnowCRM
   APP_DEBUG=0
   APP_ENV=production

   # Mail Delivery (SMTP)
   SMTP_HOST=smtp.hostinger.com
   SMTP_PORT=465
   SMTP_USERNAME=no-reply@yourdomain.com
   SMTP_PASSWORD=your_secure_smtp_password
   SMTP_FROM_EMAIL=no-reply@yourdomain.com
   SMTP_FROM_NAME="CallNow CRM"
   SMTP_ENCRYPTION=ssl
   ```

> **Security Note**: Never commit `.env` to version control. It is protected by `.gitignore` and `.htaccess`.

---

## 3. Database Setup

### Step A: Master Database (Multi-Tenant Registry)
1. Create a master database in your hosting control panel (e.g. `callnow_master` or `u123456789_master`).
2. Import `database/master_schema.sql`:
   ```bash
   mysql -u your_db_user -p callnow_master < database/master_schema.sql
   ```
3. Update the default company row in `callnow_telecalling_companys` with your tenant code and database name:
   ```sql
   UPDATE callnow_telecalling_companys
   SET company_name = 'Your Company Name',
       db_name = 'callnow_incredit',
       status = 'Active'
   WHERE company_code = 'incredit';
   ```

### Step B: Tenant Database
1. Create your tenant database (e.g. `callnow_incredit` or `u123456789_incredit`).
2. Import `database/hostinger_schema.sql`:
   ```bash
   mysql -u your_db_user -p callnow_incredit < database/hostinger_schema.sql
   ```
3. Verify all 29 tables are created.

---

## 4. File Permissions & Directories

Set correct file permissions on your server:

```bash
# Set directory permissions to 755
find . -type d -exec chmod 755 {} \;

# Set file permissions to 644
find . -type f -exec chmod 644 {} \;

# Ensure uploads directory is writable by web server
chmod -R 775 uploads/
```

Verify that `uploads/.htaccess` exists to prevent direct execution of PHP scripts uploaded by users:
```apache
<IfModule mod_php.c>
    php_flag engine off
</IfModule>
Options -Indexes
```

---

## 5. Web Server Configuration

### Apache (Hostinger / cPanel / DirectAdmin)
The repository includes an optimized root `.htaccess` file that handles:
- HTTPS enforcement
- Sensitive file protection (`.env`, `tests/`, `project-context/`, `.git`)
- REST API routing (`/api/v1/auth`, `/api/v1/telecaller`, `/api/v1/admin`)
- Security headers (`X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy: strict-origin-when-cross-origin`)
- Static asset caching

No additional Apache configuration is needed if `AllowOverride All` is enabled.

### Nginx (VPS Deployments)
If using Nginx, use the following server block configuration:

```nginx
server {
    listen 80;
    server_name crm.yourdomain.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name crm.yourdomain.com;

    root /var/www/callnowCRM;
    index index.php index.html;

    # SSL certificates
    ssl_certificate /etc/letsencrypt/live/crm.yourdomain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/crm.yourdomain.com/privkey.pem;

    # Security headers
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    add_header X-XSS-Protection "1; mode=block" always;

    # Block sensitive paths
    location ~ ^/(\.env|\.git|tests|project-context|\.kilo) {
        deny all;
        return 404;
    }

    # Block direct PHP execution in uploads
    location ~* ^/uploads/.*\.php$ {
        deny all;
        return 403;
    }

    # API Routing
    location ~ ^/api/(?:v1/)?(auth|telecaller|admin)(?:/.*)?$ {
        try_files $uri /api/v1/$1/index.php?$query_string;
    }

    # Clean URLs and default routing
    location / {
        try_files $uri $uri/ $uri.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

---

## 6. Post-Deployment Verification Checklist

1. **Production Debug Mode Off**:
   - Access `index.php` and verify no PHP notices, warnings, or debug banners appear.
   - Confirm `APP_DEBUG=0` in `.env`.

2. **Web Authentication**:
   - Log in using the administrator account seeded from `hostinger_schema.sql` (or your configured initial admin).
   - Verify that the login rotates the session ID and lands on `dashboard.php`.

3. **CSRF Protection**:
   - Verify all forms contain `<input type="hidden" name="csrf_token" ...>`.

4. **REST API Health**:
   - Test preflight OPTIONS request:
     ```bash
     curl -I -X OPTIONS https://crm.yourdomain.com/callnowCRM/api/v1/auth/login
     ```
     Should return `HTTP/1.1 200 OK`.
   - Test mobile authentication with POST credentials.

5. **Upload Directory Security**:
   - Attempt to access a dummy `.php` file in `/uploads/`: should return `403 Forbidden` or `404 Not Found`.

---

## 7. Automated Testing (Staging / Audit)

Before cutting over traffic, run the complete project audit suite in staging:

```bash
php tests/run_project_audit.php
```

All 27 test suites must report `[PASS]` with exit code `0`.


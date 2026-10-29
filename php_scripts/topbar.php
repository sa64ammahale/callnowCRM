<?php
if (!defined('APP_BASE')) { return; }

$userName = $_SESSION['name'] ?? 'User';
$userRole = $_SESSION['role'] ?? 'Agent';
$userInitial = mb_strtoupper(mb_substr($userName, 0, 1));
$currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
?>
<header class="app-topbar">
    <button type="button" class="app-topbar-toggle" id="sidebarToggle" aria-label="Toggle navigation">
        <i class="bi bi-list fs-6"></i>
    </button>

    <div class="app-topbar-breadcrumb d-none d-md-flex">
        <span>CallNow</span>
        <i class="bi bi-chevron-right text-muted" style="font-size:0.65rem"></i>
        <span class="crumb-active"><?= htmlspecialchars($pageTitle ?? 'CRM', ENT_QUOTES, 'UTF-8') ?></span>
    </div>

    <!-- Global Omnisearch Bar -->
    <div class="app-topbar-search" id="topbarSearchTrigger" onclick="openOmniSearch()">
        <i class="bi bi-search search-icon"></i>
        <input type="text" placeholder="Search leads, numbers, users..." aria-label="Search" readonly />
        <span class="search-kbd">Ctrl+K</span>
    </div>

    <div class="app-topbar-actions">
        <!-- Zoho Quick Create Button -->
        <div class="dropdown">
            <button class="btn btn-topbar-create dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-plus-lg"></i>
                <span class="d-none d-sm-inline">Create</span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                <li class="dropdown-header">Quick Create</li>
                <?php if (can('manage_leads')): ?>
                <li><a class="dropdown-item" href="<?= url('modules/leads/lead_insert.php') ?>"><i class="bi bi-person-plus text-primary"></i>New Lead</a></li>
                <?php endif; ?>
                <?php if (can('manage_database')): ?>
                <li><a class="dropdown-item" href="<?= url('modules/database/add_single_number.php') ?>"><i class="bi bi-telephone-plus text-success"></i>Customer Number</a></li>
                <li><a class="dropdown-item" href="<?= url('modules/database/upload_data.php') ?>"><i class="bi bi-cloud-arrow-up text-info"></i>Upload CSV</a></li>
                <?php endif; ?>
                <?php if (can('manage_users')): ?>
                <li><a class="dropdown-item" href="<?= url('modules/users/users_add.php') ?>"><i class="bi bi-person-badge text-warning"></i>New User</a></li>
                <?php endif; ?>
                <?php if (can('manage_teams')): ?>
                <li><a class="dropdown-item" href="<?= url('modules/users/teams_dashboard.php') ?>"><i class="bi bi-diagram-3 text-purple"></i>New Team</a></li>
                <?php endif; ?>
            </ul>
        </div>

        <div class="app-topbar-divider"></div>

        <!-- Telecalling Engine Status Pill -->
        <span class="status-pill success d-none d-lg-inline-flex" title="Real-time telecalling backend active">
            Telecalling Active
        </span>

        <?php if (can('view_reports')): ?>
        <a href="<?= url('modules/logs/call_history.php') ?>" class="app-topbar-icon-btn d-none d-sm-inline-flex" title="Recent Call Activity">
            <i class="bi bi-telephone"></i>
        </a>
        <?php endif; ?>

        <?php if (can('manage_settings')): ?>
        <a href="<?= url('modules/settings/settings.php') ?>" class="app-topbar-icon-btn d-none d-sm-inline-flex" title="System Settings">
            <i class="bi bi-gear"></i>
        </a>
        <?php endif; ?>

        <!-- User Profile Dropdown -->
        <div class="dropdown">
            <a class="app-topbar-user dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                <span class="app-topbar-avatar"><?= htmlspecialchars($userInitial, ENT_QUOTES, 'UTF-8') ?></span>
                <span class="app-topbar-user-name"><?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?></span>
                <i class="bi bi-chevron-down text-muted" style="font-size:0.65rem"></i>
            </a>
            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                <li class="dropdown-header">
                    <div class="fw-bold text-dark"><?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?></div>
                    <span class="badge bg-secondary"><?= htmlspecialchars($userRole, ENT_QUOTES, 'UTF-8') ?></span>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="<?= url('profile.php') ?>"><i class="bi bi-person"></i>My Profile</a></li>
                <?php if (isAdmin() || isSuperAdmin()): ?>
                <li><a class="dropdown-item" href="<?= url('modules/logs/manage_activity.php') ?>"><i class="bi bi-clock-history"></i>Activity Log</a></li>
                <?php endif; ?>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item text-danger" href="<?= url('logout.php') ?>"><i class="bi bi-box-arrow-right text-danger"></i>Logout</a></li>
            </ul>
        </div>
    </div>
</header>

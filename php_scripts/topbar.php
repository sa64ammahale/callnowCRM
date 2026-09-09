<?php
if (!defined('APP_BASE')) { return; }

$userName = $_SESSION['name'] ?? 'User';
$userRole = $_SESSION['role'] ?? '';
$userInitial = mb_strtoupper(mb_substr($userName, 0, 1));
?>
<header class="app-topbar">
    <button type="button" class="app-topbar-toggle" id="sidebarToggle" aria-label="Toggle sidebar">
        <i class="bi bi-list"></i>
    </button>

    <div class="app-topbar-search">
        <i class="bi bi-search"></i>
        <input type="search" placeholder="Search leads, numbers, TBL_USERS..." aria-label="Search" />
    </div>

    <div class="app-topbar-actions">
        <button type="button" class="app-topbar-icon-btn" id="themeToggle" aria-label="Toggle theme" title="Toggle theme">
            <i class="bi bi-moon-stars" id="themeIcon"></i>
        </button>

        <div class="app-topbar-divider"></div>

        <div class="dropdown">
            <a class="app-topbar-user dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                <span class="app-topbar-avatar"><?= htmlspecialchars($userInitial, ENT_QUOTES, 'UTF-8') ?></span>
                <span class="app-topbar-user-name"><?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?></span>
                <i class="bi bi-chevron-down app-topbar-user-caret"></i>
            </a>
            <ul class="dropdown-menu dropdown-menu-end">
                <li class="dropdown-header"><?= htmlspecialchars($userRole, ENT_QUOTES, 'UTF-8') ?></li>
                <li><a class="dropdown-item" href="<?= url('profile.php') ?>"><i class="bi bi-person"></i>My Profile</a></li>
                <?php if (isAdmin() || isSuperAdmin()): ?>
                <li><a class="dropdown-item" href="<?= url('modules/logs/manage_activity.php') ?>"><i class="bi bi-activity"></i>Activity Log</a></li>
                <?php endif; ?>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="<?= url('logout.php') ?>"><i class="bi bi-box-arrow-right"></i>Logout</a></li>
            </ul>
        </div>
    </div>
</header>

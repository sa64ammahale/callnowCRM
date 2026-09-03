<?php
if (!defined('APP_BASE')) { return; }

// $appLogoUrl is resolved in header.php and reused here (single query per page load)
if (!isset($appLogoUrl)) { $appLogoUrl = ''; }

$current = basename($_SERVER['SCRIPT_NAME']);

function navActive($patterns) {
    global $current;
    foreach ($patterns as $p) {
        if ($current === $p || strpos($current, $p) !== false) return true;
    }
    return false;
}
?>
<aside class="app-sidebar" id="appSidebar">
    <a class="app-sidebar-brand" href="<?= url('dashboard.php') ?>">
        <?php if ($appLogoUrl): ?>
            <span class="brand-mark"><img src="<?= htmlspecialchars($appLogoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Logo" class="brand-logo-img"></span>
        <?php else: ?>
            <span class="brand-mark"><i class="bi bi-telephone-fill"></i></span>
        <?php endif; ?>
        <span class="brand-text">CallNow</span>
    </a>

    <nav class="app-sidebar-nav">
        <div class="app-sidebar-section">
            <div class="app-sidebar-section-label">Overview</div>
            <a class="app-sidebar-link <?= navActive(['dashboard.php']) ? 'active' : '' ?>" href="<?= url('dashboard.php') ?>">
                <i class="bi bi-grid-1x2"></i>
                <span class="link-text">Dashboard</span>
            </a>
            <a class="app-sidebar-link <?= navActive(['profile.php']) ? 'active' : '' ?>" href="<?= url('profile.php') ?>">
                <i class="bi bi-person"></i>
                <span class="link-text">My Profile</span>
            </a>
        </div>

        <?php if (can('manage_database')): ?>
        <div class="app-sidebar-section">
            <div class="app-sidebar-section-label">Database</div>
            <a class="app-sidebar-link <?= navActive(['data_management_temporary.php']) ? 'active' : '' ?>" href="<?= url('modules/database/data_management_temporary.php') ?>">
                <i class="bi bi-table"></i>
                <span class="link-text">Temporary DB</span>
            </a>
            <a class="app-sidebar-link <?= navActive(['data_management_main.php']) ? 'active' : '' ?>" href="<?= url('modules/database/data_management_main.php') ?>">
                <i class="bi bi-database"></i>
                <span class="link-text">Main DB</span>
            </a>
            <a class="app-sidebar-link <?= navActive(['upload_data.php']) ? 'active' : '' ?>" href="<?= url('modules/database/upload_data.php') ?>">
                <i class="bi bi-cloud-upload"></i>
                <span class="link-text">Upload Data</span>
            </a>
            <a class="app-sidebar-link <?= navActive(['add_update_status.php']) ? 'active' : '' ?>" href="<?= url('modules/database/add_update_status.php') ?>">
                <i class="bi bi-arrow-repeat"></i>
                <span class="link-text">Add / Update Status</span>
            </a>
            <a class="app-sidebar-link <?= navActive(['add_single_number.php']) ? 'active' : '' ?>" href="<?= url('modules/database/add_single_number.php') ?>">
                <i class="bi bi-plus-circle"></i>
                <span class="link-text">Add Single Number</span>
            </a>
            <a class="app-sidebar-link <?= navActive(['ViewDND.php']) ? 'active' : '' ?>" href="<?= url('modules/database/ViewDND.php') ?>">
                <i class="bi bi-slash-circle"></i>
                <span class="link-text">DND Numbers</span>
            </a>
            <a class="app-sidebar-link <?= navActive(['download_bach.php']) ? 'active' : '' ?>" href="<?= url('modules/database/maindatabase_ajax/download_bach.php') ?>">
                <i class="bi bi-cloud-download"></i>
                <span class="link-text">Export Full DB</span>
            </a>
        </div>
        <?php endif; ?>

        <div class="app-sidebar-section">
            <div class="app-sidebar-section-label">Leads</div>
            <a class="app-sidebar-link <?= navActive(['leads_dashboard.php']) ? 'active' : '' ?>" href="<?= url('modules/leads/leads_dashboard.php') ?>">
                <i class="bi bi-bookmark-heart"></i>
                <span class="link-text">Leads Dashboard</span>
            </a>
            <a class="app-sidebar-link <?= navActive(['lead_pipeline.php']) ? 'active' : '' ?>" href="<?= url('modules/leads/lead_pipeline.php') ?>">
                <i class="bi bi-kanban"></i>
                <span class="link-text">Pipeline Board</span>
            </a>
            <a class="app-sidebar-link <?= navActive(['lead_list.php']) ? 'active' : '' ?>" href="<?= url('modules/leads/lead_list.php') ?>">
                <i class="bi bi-list-ul"></i>
                <span class="link-text">View Leads</span>
            </a>
            <?php if (can('manage_leads')): ?>
            <a class="app-sidebar-link <?= navActive(['lead_insert.php']) ? 'active' : '' ?>" href="<?= url('modules/leads/lead_insert.php') ?>">
                <i class="bi bi-plus-square"></i>
                <span class="link-text">Add New Lead</span>
            </a>
            <?php endif; ?>
        </div>

        <?php if (can('manage_TBL_USERS')): ?>
        <div class="app-sidebar-section">
            <div class="app-sidebar-section-label">Manage</div>
            <a class="app-sidebar-link <?= navActive(['users_view.php']) ? 'active' : '' ?>" href="<?= url('modules/users/users_view.php') ?>">
                <i class="bi bi-people"></i>
                <span class="link-text">Users</span>
            </a>
            <a class="app-sidebar-link <?= navActive(['users_add.php']) ? 'active' : '' ?>" href="<?= url('modules/users/users_add.php') ?>">
                <i class="bi bi-person-plus"></i>
                <span class="link-text">Add User</span>
            </a>
            <?php endif; ?>
            <?php if (can('manage_TBL_TEAMS')): ?>
            <a class="app-sidebar-link <?= navActive(['teams_dashboard.php']) ? 'active' : '' ?>" href="<?= url('modules/users/teams_dashboard.php') ?>">
                <i class="bi bi-diagram-3"></i>
                <span class="link-text">Teams</span>
            </a>
            <a class="app-sidebar-link <?= navActive(['team_members.php']) ? 'active' : '' ?>" href="<?= url('modules/users/team_members.php') ?>">
                <i class="bi bi-person-check"></i>
                <span class="link-text">Team Members</span>
            </a>
            <?php endif; ?>
        </div>

        <div class="app-sidebar-section">
            <div class="app-sidebar-section-label">Reports</div>
            <?php if (can('view_reports')): ?>
            <a class="app-sidebar-link <?= navActive(['call_history.php']) ? 'active' : '' ?>" href="<?= url('modules/logs/call_history.php') ?>">
                <i class="bi bi-telephone-inbound"></i>
                <span class="link-text">Call History</span>
            </a>
            <a class="app-sidebar-link <?= navActive(['Reports.php']) ? 'active' : '' ?>" href="<?= url('modules/logs/Reports.php') ?>">
                <i class="bi bi-bar-chart"></i>
                <span class="link-text">Reports</span>
            </a>
            <?php endif; ?>
        </div>

        <?php if (can('manage_settings') || can('view_activity')): ?>
        <div class="app-sidebar-section">
            <div class="app-sidebar-section-label">System</div>
            <?php if (isAdmin()): ?>
            <a class="app-sidebar-link <?= navActive(['permissions_manager.php']) ? 'active' : '' ?>" href="<?= url('modules/settings/permissions_manager.php') ?>">
                <i class="bi bi-shield-lock"></i>
                <span class="link-text">Access Control</span>
            </a>
            <a class="app-sidebar-link <?= navActive(['settings.php']) ? 'active' : '' ?>" href="<?= url('modules/settings/settings.php') ?>">
                <i class="bi bi-gear"></i>
                <span class="link-text">Settings</span>
            </a>
            <?php if (can('manage_api')): ?>
            <a class="app-sidebar-link <?= navActive(['api_settings.php']) ? 'active' : '' ?>" href="<?= url('modules/settings/api_settings.php') ?>">
                <i class="bi bi-key"></i>
                <span class="link-text">API Access</span>
            </a>
            <?php endif; ?>
            <?php endif; ?>
            <?php if (can('view_activity')): ?>
            <a class="app-sidebar-link <?= navActive(['manage_activity.php']) ? 'active' : '' ?>" href="<?= url('modules/logs/manage_activity.php') ?>">
                <i class="bi bi-activity"></i>
                <span class="link-text">Activity Log</span>
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </nav>

    <div class="app-sidebar-footer">
        <a class="app-sidebar-link" href="<?= url('logout.php') ?>">
            <i class="bi bi-box-arrow-right"></i>
            <span class="link-text">Logout</span>
        </a>
    </div>
</aside>

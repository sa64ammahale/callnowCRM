<?php
if (!defined('APP_BASE')) { return; }

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
        <div class="brand-title-wrap">
            <span class="brand-text">CallNow CRM</span>
            <span class="brand-workspace-pill">Telecalling Workspace</span>
        </div>
    </a>

    <nav class="app-sidebar-nav">
        <!-- Main / Workspace -->
        <div class="app-sidebar-section">
            <div class="app-sidebar-section-label">Workspace</div>
            <a class="app-sidebar-link <?= navActive(['dashboard.php']) ? 'active' : '' ?>" href="<?= url('dashboard.php') ?>" title="Sales & Calling Dashboard">
                <i class="bi bi-speedometer2"></i>
                <span class="link-text">Dashboard</span>
            </a>
            <a class="app-sidebar-link <?= navActive(['profile.php']) ? 'active' : '' ?>" href="<?= url('profile.php') ?>" title="User Profile">
                <i class="bi bi-person-badge"></i>
                <span class="link-text">My Profile</span>
            </a>
        </div>

        <!-- Leads & Pipeline -->
        <div class="app-sidebar-section">
            <div class="app-sidebar-section-label">Leads & Pipeline</div>
            <a class="app-sidebar-link <?= navActive(['lead_pipeline.php']) ? 'active' : '' ?>" href="<?= url('modules/leads/lead_pipeline.php') ?>" title="Pipeline Kanban Board">
                <i class="bi bi-kanban"></i>
                <span class="link-text">Pipeline Board</span>
            </a>
            <a class="app-sidebar-link <?= navActive(['lead_list.php', 'lead_view.php']) ? 'active' : '' ?>" href="<?= url('modules/leads/lead_list.php') ?>" title="View Leads Register">
                <i class="bi bi-person-lines-fill"></i>
                <span class="link-text">View Leads</span>
            </a>
            <a class="app-sidebar-link <?= navActive(['leads_dashboard.php']) ? 'active' : '' ?>" href="<?= url('modules/leads/leads_dashboard.php') ?>" title="Leads Analytics">
                <i class="bi bi-bar-chart-line"></i>
                <span class="link-text">Leads Analytics</span>
            </a>
            <?php if (can('manage_leads')): ?>
            <a class="app-sidebar-link <?= navActive(['lead_insert.php']) ? 'active' : '' ?>" href="<?= url('modules/leads/lead_insert.php') ?>" title="Add New Lead">
                <i class="bi bi-plus-circle"></i>
                <span class="link-text">Add New Lead</span>
            </a>
            <?php endif; ?>
        </div>

        <!-- Database Management -->
        <?php if (can('manage_database')): ?>
        <div class="app-sidebar-section">
            <div class="app-sidebar-section-label">Data Management</div>
            <a class="app-sidebar-link <?= navActive(['data_management_main.php']) ? 'active' : '' ?>" href="<?= url('modules/database/data_management_main.php') ?>" title="Main Customer Database">
                <i class="bi bi-database"></i>
                <span class="link-text">Main DB</span>
            </a>
            <a class="app-sidebar-link <?= navActive(['data_management_temporary.php']) ? 'active' : '' ?>" href="<?= url('modules/database/data_management_temporary.php') ?>" title="Temporary Telecalling Database">
                <i class="bi bi-table"></i>
                <span class="link-text">Temporary DB</span>
            </a>
            <a class="app-sidebar-link <?= navActive(['upload_data.php']) ? 'active' : '' ?>" href="<?= url('modules/database/upload_data.php') ?>" title="Upload CSV Data">
                <i class="bi bi-cloud-arrow-up"></i>
                <span class="link-text">Upload Data</span>
            </a>
            <a class="app-sidebar-link <?= navActive(['add_single_number.php']) ? 'active' : '' ?>" href="<?= url('modules/database/add_single_number.php') ?>" title="Add Single Number">
                <i class="bi bi-telephone-plus"></i>
                <span class="link-text">Add Single Number</span>
            </a>
            <a class="app-sidebar-link <?= navActive(['add_update_status.php']) ? 'active' : '' ?>" href="<?= url('modules/database/add_update_status.php') ?>" title="Batch Update Status">
                <i class="bi bi-arrow-repeat"></i>
                <span class="link-text">Add / Update Status</span>
            </a>
            <a class="app-sidebar-link <?= navActive(['ViewDND.php']) ? 'active' : '' ?>" href="<?= url('modules/database/ViewDND.php') ?>" title="DND Numbers Registry">
                <i class="bi bi-slash-circle"></i>
                <span class="link-text">DND Numbers</span>
            </a>
            <a class="app-sidebar-link <?= navActive(['download_bach.php']) ? 'active' : '' ?>" href="<?= url('modules/database/maindatabase_ajax/download_bach.php') ?>" title="Export Full Database">
                <i class="bi bi-cloud-arrow-down"></i>
                <span class="link-text">Export Database</span>
            </a>
        </div>
        <?php endif; ?>

        <!-- Activities & Analytics -->
        <div class="app-sidebar-section">
            <div class="app-sidebar-section-label">Activities & Reports</div>
            <?php if (can('view_reports')): ?>
            <a class="app-sidebar-link <?= navActive(['call_history.php']) ? 'active' : '' ?>" href="<?= url('modules/logs/call_history.php') ?>" title="Real-time Call Logs">
                <i class="bi bi-telephone-inbound"></i>
                <span class="link-text">Call History</span>
            </a>
            <a class="app-sidebar-link <?= navActive(['Reports.php']) ? 'active' : '' ?>" href="<?= url('modules/logs/Reports.php') ?>" title="Performance & Disposition Reports">
                <i class="bi bi-graph-up"></i>
                <span class="link-text">Call Reports</span>
            </a>
            <?php endif; ?>
            <?php if (can('view_activity')): ?>
            <a class="app-sidebar-link <?= navActive(['manage_activity.php']) ? 'active' : '' ?>" href="<?= url('modules/logs/manage_activity.php') ?>" title="System Audit Logs">
                <i class="bi bi-clock-history"></i>
                <span class="link-text">Activity Log</span>
            </a>
            <?php endif; ?>
        </div>

        <!-- Users & Teams -->
        <?php if (can('manage_users') || can('manage_teams')): ?>
        <div class="app-sidebar-section">
            <div class="app-sidebar-section-label">Users & Teams</div>
            <?php if (can('manage_users')): ?>
            <a class="app-sidebar-link <?= navActive(['users_view.php']) ? 'active' : '' ?>" href="<?= url('modules/users/users_view.php') ?>" title="Users Directory">
                <i class="bi bi-people"></i>
                <span class="link-text">Users</span>
            </a>
            <a class="app-sidebar-link <?= navActive(['users_add.php']) ? 'active' : '' ?>" href="<?= url('modules/users/users_add.php') ?>" title="Add New User">
                <i class="bi bi-person-plus"></i>
                <span class="link-text">Add User</span>
            </a>
            <?php endif; ?>
            <?php if (can('manage_teams')): ?>
            <a class="app-sidebar-link <?= navActive(['teams_dashboard.php']) ? 'active' : '' ?>" href="<?= url('modules/users/teams_dashboard.php') ?>" title="Teams Overview">
                <i class="bi bi-diagram-3"></i>
                <span class="link-text">Teams</span>
            </a>
            <a class="app-sidebar-link <?= navActive(['team_members.php']) ? 'active' : '' ?>" href="<?= url('modules/users/team_members.php') ?>" title="Team Members Assignment">
                <i class="bi bi-person-check"></i>
                <span class="link-text">Team Members</span>
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Administration & Settings -->
        <?php if (can('manage_settings') || isSuperAdmin()): ?>
        <div class="app-sidebar-section">
            <div class="app-sidebar-section-label">Settings & Admin</div>
            <?php if (isAdmin() || isSuperAdmin()): ?>
            <a class="app-sidebar-link <?= navActive(['permissions_manager.php']) ? 'active' : '' ?>" href="<?= url('modules/settings/permissions_manager.php') ?>" title="Access Control Matrix">
                <i class="bi bi-shield-lock"></i>
                <span class="link-text">Access Control</span>
            </a>
            <a class="app-sidebar-link <?= navActive(['settings.php']) ? 'active' : '' ?>" href="<?= url('modules/settings/settings.php') ?>" title="System Settings">
                <i class="bi bi-gear"></i>
                <span class="link-text">Settings</span>
            </a>
            <?php if (can('manage_api')): ?>
            <a class="app-sidebar-link <?= navActive(['api_settings.php']) ? 'active' : '' ?>" href="<?= url('modules/settings/api_settings.php') ?>" title="API Access">
                <i class="bi bi-key"></i>
                <span class="link-text">API Access</span>
            </a>
            <?php endif; ?>
            <?php endif; ?>
            <?php if (isSuperAdmin()): ?>
            <a class="app-sidebar-link <?= navActive(['super_admin_panel.php']) ? 'active' : '' ?>" href="<?= url('modules/settings/super_admin_panel.php') ?>" title="Super Admin Panel">
                <i class="bi bi-shield-shaded"></i>
                <span class="link-text">Super Admin</span>
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </nav>

    <div class="app-sidebar-footer">
        <a class="app-sidebar-link" href="<?= url('profile.php') ?>" title="My Profile">
            <i class="bi bi-person-circle"></i>
            <span class="link-text"><?= htmlspecialchars($_SESSION['name'] ?? 'Account', ENT_QUOTES, 'UTF-8') ?></span>
        </a>
        <a class="app-sidebar-link text-danger" href="<?= url('logout.php') ?>" title="Logout">
            <i class="bi bi-box-arrow-right"></i>
            <span class="link-text">Logout</span>
        </a>
    </div>
</aside>

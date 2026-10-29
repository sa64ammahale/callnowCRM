</main>

<footer class="app-footer">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
            <span>&copy; <?= date('Y') ?> CallNow CRM &middot; Sangam Mahale</span>
            <span class="text-muted">&bull;</span>
            <span class="badge bg-secondary">NextGen UI</span>
        </div>
        <div class="d-flex align-items-center gap-3">
            <span class="text-muted small">System Operational</span>
            <span class="badge bg-primary">v5.00</span>
        </div>
    </div>
</footer>
</div>
</div>

<!-- Global Omni-Search Modal (Zoho CRM Global Search) -->
<div class="modal fade" id="omniSearchModal" tabindex="-1" aria-labelledby="omniSearchModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg" style="border-radius:var(--radius-lg);">
            <div class="modal-header border-bottom p-3">
                <div class="input-group">
                    <span class="input-group-text bg-transparent border-0 text-muted"><i class="bi bi-search fs-5"></i></span>
                    <input type="text" class="form-control border-0 shadow-none fs-6" id="omniSearchInput" placeholder="Type to search leads, customer records, reports, teams, or jump to page..." autofocus>
                    <button type="button" class="btn btn-sm btn-ghost" data-bs-dismiss="modal"><kbd class="bg-light text-muted border px-1">Esc</kbd></button>
                </div>
            </div>
            <div class="modal-body p-0" style="max-height:420px; overflow-y:auto;" id="omniSearchResults">
                <div class="p-3">
                    <div class="text-uppercase text-muted fw-bold" style="font-size:0.6875rem; letter-spacing:0.05em; margin-bottom:0.5rem;">Quick Navigation</div>
                    <div class="list-group list-group-flush">
                        <a href="<?= url('modules/leads/lead_pipeline.php') ?>" class="list-group-item list-group-item-action border-0 rounded px-2 py-2 d-flex align-items-center gap-2">
                            <span class="metric-card-icon sm bg-primary text-white"><i class="bi bi-kanban"></i></span>
                            <div>
                                <div class="fw-semibold text-dark">Pipeline Board</div>
                                <div class="text-muted small">Manage lead pipeline stages</div>
                            </div>
                        </a>
                        <a href="<?= url('modules/leads/lead_list.php') ?>" class="list-group-item list-group-item-action border-0 rounded px-2 py-2 d-flex align-items-center gap-2">
                            <span class="metric-card-icon sm bg-info text-white"><i class="bi bi-person-lines-fill"></i></span>
                            <div>
                                <div class="fw-semibold text-dark">Leads Register</div>
                                <div class="text-muted small">Search and filter converted & active leads</div>
                            </div>
                        </a>
                        <a href="<?= url('modules/database/data_management_main.php') ?>" class="list-group-item list-group-item-action border-0 rounded px-2 py-2 d-flex align-items-center gap-2">
                            <span class="metric-card-icon sm bg-success text-white"><i class="bi bi-database"></i></span>
                            <div>
                                <div class="fw-semibold text-dark">Main Customer Database</div>
                                <div class="text-muted small">Full customer database records and call histories</div>
                            </div>
                        </a>
                        <a href="<?= url('modules/logs/Reports.php') ?>" class="list-group-item list-group-item-action border-0 rounded px-2 py-2 d-flex align-items-center gap-2">
                            <span class="metric-card-icon sm bg-warning text-dark"><i class="bi bi-graph-up"></i></span>
                            <div>
                                <div class="fw-semibold text-dark">Performance Reports</div>
                                <div class="text-muted small">Telecalling dispositions, connect rates, and agent analytics</div>
                            </div>
                        </a>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-top bg-subtle p-2 d-flex justify-content-between text-muted small">
                <span>Navigate: <kbd class="bg-white border">↑</kbd> <kbd class="bg-white border">↓</kbd></span>
                <span>Select: <kbd class="bg-white border">↵</kbd></span>
                <span>Close: <kbd class="bg-white border">Esc</kbd></span>
            </div>
        </div>
    </div>
</div>

<script src="<?= vnd('js/bootstrap.bundle.min.js') ?>"></script>
<script>window.APP_BASE = <?= json_encode(APP_BASE) ?>;</script>
<script>window.APP_CSRF = <?= json_encode($_SESSION['csrf_token'] ?? '') ?>;</script>
<script src="<?= url('assets/js/theme.js') ?>"></script>
<script src="<?= url('assets/js/sidebar.js') ?>"></script>
<script>
function openOmniSearch() {
    var modalEl = document.getElementById('omniSearchModal');
    if (!modalEl) return;
    var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    modal.show();
    setTimeout(function() {
        var input = document.getElementById('omniSearchInput');
        if (input) { input.focus(); input.select(); }
    }, 150);
}

document.addEventListener('keydown', function(e) {
    if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) {
        e.preventDefault();
        openOmniSearch();
    }
});
</script>
</body>
</html>

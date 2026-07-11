</main>

<footer class="app-footer">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
        <span>&copy; <?= date('Y') ?> CallNow CRM &middot; Sangam Mahale</span>
        <span class="text-soft">v5.00</span>
    </div>
</footer>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
<script>window.APP_BASE = <?= json_encode(APP_BASE) ?>;</script>
<script>window.APP_CSRF = <?= json_encode($_SESSION['csrf_token'] ?? '') ?>;</script>
<script src="<?= url('assets/js/theme.js') ?>"></script>
<script src="<?= url('assets/js/sidebar.js') ?>"></script>

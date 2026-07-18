<?php
require_once __DIR__ . '/../../../php_scripts/auth.php';

requirePermission('export_data');

if (!isset($link) || !$link) {
    exit('Database connection failed');
}

$totalRecords = 0;
$result = mysqli_query($link, "SELECT COUNT(*) as total FROM main_database");
if ($result) {
    $row = mysqli_fetch_assoc($result);
    $totalRecords = (int)$row['total'];
}

$pageTitle = 'Export Full Database';
include __DIR__ . '/../../../php_scripts/header.php';
?>

<div class="page-container">
    <!-- Page Header -->
    <div class="page-header-section">
        <div class="header-content">
            <div class="header-icon export-icon">
                <i class="bi bi-cloud-download-fill"></i>
            </div>
            <div class="header-text">
                <h1 class="page-title">Export Full Database</h1>
                <p class="page-subtitle">Download the entire main database in multiple CSV parts</p>
            </div>
        </div>
        <div class="header-actions">
            <a href="<?= url('modules/database/data_management_main.php') ?>" class="btn btn-primary">
                <i class="bi bi-table me-2"></i>View Main Database
            </a>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="stats-grid">
        <div class="stat-card stat-blue">
            <div class="stat-icon-wrapper">
                <i class="bi bi-database-fill"></i>
            </div>
            <div class="stat-content">
                <div class="stat-label">Total Records</div>
                <div class="stat-value" id="statTotalRecords"><?= number_format($totalRecords) ?></div>
                <div class="stat-description">In main database</div>
            </div>
        </div>
        
        <div class="stat-card stat-green">
            <div class="stat-icon-wrapper">
                <i class="bi bi-file-earmark-spreadsheet-fill"></i>
            </div>
            <div class="stat-content">
                <div class="stat-label">Export Format</div>
                <div class="stat-value">CSV</div>
                <div class="stat-description">Comma-separated values</div>
            </div>
        </div>
        
        <div class="stat-card stat-orange">
            <div class="stat-icon-wrapper">
                <i class="bi bi-lightning-charge-fill"></i>
            </div>
            <div class="stat-content">
                <div class="stat-label">Batch Processing</div>
                <div class="stat-value">Auto</div>
                <div class="stat-description">Split into multiple files</div>
            </div>
        </div>
    </div>

    <!-- Export Configuration -->
    <div class="config-card">
        <div class="config-header">
            <i class="bi bi-gear-fill"></i>
            <h2>Export Configuration</h2>
        </div>
        <div class="config-body">
            <div class="config-grid">
                <div class="config-group">
                    <label class="config-label">
                        <i class="bi bi-layers me-1"></i>
                        Batch Size
                    </label>
                    <select id="batchSize" class="config-select">
                        <option value="1000">1,000 rows per file</option>
                        <option value="5000">5,000 rows per file</option>
                        <option value="10000" selected>10,000 rows per file</option>
                        <option value="20000">20,000 rows per file</option>
                        <option value="50000">50,000 rows per file</option>
                    </select>
                    <small class="config-hint">Smaller batches = more files, faster processing</small>
                </div>
                
                <div class="config-group">
                    <label class="config-label">
                        <i class="bi bi-hash me-1"></i>
                        Total Records
                    </label>
                    <div id="totalRecords" class="config-display">
                        <span class="counting">Counting…</span>
                    </div>
                    <small class="config-hint">Records available for export</small>
                </div>
                
                <div class="config-group config-action">
                    <label class="config-label">
                        <i class="bi bi-play-circle me-1"></i>
                        Action
                    </label>
                    <button id="startExport" class="btn btn-primary btn-lg btn-block" disabled>
                        <i class="bi bi-play-fill me-2"></i>Start Export
                    </button>
                    <small class="config-hint">Click to begin download process</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Export Progress -->
    <div id="exportStatus" class="progress-card d-none">
        <div class="progress-header">
            <div class="progress-title">
                <i class="bi bi-arrow-repeat"></i>
                <h2>Export Progress</h2>
            </div>
            <span class="progress-badge">
                <span id="part">0</span> / <span id="totalParts">0</span> files
            </span>
        </div>
        <div class="progress-body">
            <div class="progress-wrapper">
                <div class="progress-bar-container">
                    <div id="progressBar" class="progress-bar-fill" style="width:0%">
                        <span class="progress-text">0%</span>
                    </div>
                </div>
            </div>
            <div class="progress-stats">
                <div class="progress-stat">
                    <span class="stat-label">Records Processed</span>
                    <span class="stat-value" id="current">0</span>
                </div>
                <div class="progress-stat">
                    <span class="stat-label">Completion</span>
                    <span class="stat-value"><span id="percent">0</span>%</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Download Files -->
    <div id="filesSection" class="files-card d-none">
        <div class="files-header">
            <i class="bi bi-download"></i>
            <h2>Download Files</h2>
        </div>
        <div class="files-body">
            <div id="filesList" class="files-grid"></div>
        </div>
    </div>
</div>

<script>
const CSRF_TOKEN = '<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>';
const ajaxBase = '<?= defined('APP_BASE') ? APP_BASE : '' ?>/modules/database/maindatabase_ajax/';
let totalRecords = <?= (int)$totalRecords ?>;

$(document).ready(function() {
    if (totalRecords > 0) {
        $('#totalRecords').html('<span class="count-value">' + totalRecords.toLocaleString() + '</span>');
        $('#statTotalRecords').text(totalRecords.toLocaleString());
        $('#startExport').prop('disabled', false);
    } else {
        $('#totalRecords').html('<span class="no-data">No data found</span>');
        $('#startExport').prop('disabled', true).html('<i class="bi bi-exclamation-triangle me-2"></i>No Data');
    }
});

$('#startExport').on('click', function() {
    const batchSize = parseInt($('#batchSize').val());
    const totalParts = Math.ceil(totalRecords / batchSize);
    
    $(this).prop('disabled', true).html('<i class="bi bi-arrow-repeat me-2 spin"></i>Exporting…');
    $('#exportStatus').removeClass('d-none');
    $('#filesSection').removeClass('d-none').find('#filesList').empty();
    $('#totalParts').text(totalParts.toLocaleString());
    
    startExport(0, batchSize);
});

function startExport(offset, batchSize) {
    $.post(ajaxBase + 'export_batch.php', { 
        offset: offset, 
        limit: batchSize, 
        csrf_token: CSRF_TOKEN 
    }, function(res) {
        if (res.success) {
            const current = offset + res.count;
            const percent = totalRecords ? Math.round((current / totalRecords) * 100) : 0;
            const part = Math.floor(offset / batchSize) + 1;

            $('#progressBar').css('width', percent + '%').find('.progress-text').text(percent + '%');
            $('#current').text(current.toLocaleString());
            $('#percent').text(percent);
            $('#part').text(part);

            const blob = b64toBlob(res.csv, 'text/csv');
            const url = URL.createObjectURL(blob);
            const fileName = 'MAINDATABASE_part_' + part + '_' + current.toLocaleString() + '_rows.csv';

            $('#filesList').append(`
                <div class="file-card">
                    <div class="file-icon">
                        <i class="bi bi-file-earmark-spreadsheet-fill"></i>
                    </div>
                    <div class="file-info">
                        <div class="file-name" title="${fileName}">${fileName}</div>
                        <div class="file-size">${res.count.toLocaleString()} rows</div>
                    </div>
                    <a href="${url}" download="${fileName}" class="btn btn-success btn-sm">
                        <i class="bi bi-download me-1"></i>Download
                    </a>
                </div>
            `);

            if (current < totalRecords) {
                setTimeout(() => startExport(offset + batchSize, batchSize), 100);
            } else {
                $('#progressBar').addClass('complete').find('.progress-text').text('Complete!');
                $('#startExport').html('<i class="bi bi-check2-all me-2"></i>Complete').prop('disabled', false);
                showToast('Success!', 'Full export completed successfully!', 'success');
            }
        } else {
            $('#startExport').html('<i class="bi bi-x-circle me-2"></i>Failed').prop('disabled', false);
            showToast('Error', res.message || res.error || 'Export failed', 'danger');
        }
    }, 'json').fail(function(xhr) {
        $('#startExport').html('<i class="bi bi-x-circle me-2"></i>Failed').prop('disabled', false);
        showToast('Error', 'Server error: ' + (xhr.statusText || 'Unknown error'), 'danger');
    });
}

function b64toBlob(b64Data, contentType = '', sliceSize = 512) {
    const byteCharacters = atob(b64Data);
    const byteArrays = [];
    for (let offset = 0; offset < byteCharacters.length; offset += sliceSize) {
        const slice = byteCharacters.slice(offset, offset + sliceSize);
        const byteNumbers = new Array(slice.length);
        for (let i = 0; i < slice.length; i++) {
            byteNumbers[i] = slice.charCodeAt(i);
        }
        byteArrays.push(new Uint8Array(byteNumbers));
    }
    return new Blob(byteArrays, { type: contentType });
}

function showToast(title, message, type = 'success') {
    const toastHtml = `<div class="toast-custom toast-${type}">
        <div class="toast-icon">
            <i class="bi bi-${type === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill'}"></i>
        </div>
        <div class="toast-content">
            <strong>${title}</strong><br><small>${message}</small>
        </div>
        <button type="button" class="toast-close" onclick="this.parentElement.remove()">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>`;
    
    let container = document.querySelector('.toast-container-custom');
    if (!container) {
        container = document.createElement('div');
        container.className = 'toast-container-custom';
        document.body.appendChild(container);
    }
    container.insertAdjacentHTML('beforeend', toastHtml);
    
    const toastEl = container.lastElementChild;
    setTimeout(() => {
        if (toastEl && toastEl.parentElement) {
            toastEl.remove();
        }
    }, 5000);
}

</script>

<style>
.page-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 2rem 1.5rem;
}

.page-header-section {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 2rem;
    padding-bottom: 1.5rem;
    border-bottom: 2px solid var(--border);
}

.header-content {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.header-icon {
    width: 56px;
    height: 56px;
    border-radius: var(--radius-lg);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.75rem;
    color: #fff;
    box-shadow: var(--shadow-md);
}

.export-icon {
    background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
}

.page-title {
    font-size: 1.75rem;
    font-weight: 700;
    color: var(--ink);
    margin: 0 0 0.25rem 0;
    letter-spacing: -0.02em;
}

.page-subtitle {
    font-size: 0.875rem;
    color: var(--ink-soft);
    margin: 0;
}

.header-actions {
    display: flex;
    gap: 0.75rem;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.stat-card {
    display: flex;
    align-items: center;
    gap: 1.25rem;
    padding: 1.5rem;
    border-radius: var(--radius-xl);
    box-shadow: var(--shadow-md);
    transition: all 0.2s ease;
    position: relative;
    overflow: hidden;
}

.stat-card::before {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    width: 150px;
    height: 150px;
    background: rgba(255,255,255,0.1);
    border-radius: 50%;
    transform: translate(30%, -30%);
}

.stat-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-lg);
}

.stat-icon-wrapper {
    width: 56px;
    height: 56px;
    border-radius: var(--radius-lg);
    background: rgba(255,255,255,0.2);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.75rem;
    flex-shrink: 0;
    position: relative;
    z-index: 1;
}

.stat-content {
    flex: 1;
    min-width: 0;
    position: relative;
    z-index: 1;
}

.stat-label {
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-bottom: 0.5rem;
    opacity: 0.9;
}

.stat-value {
    font-size: 2rem;
    font-weight: 700;
    line-height: 1;
    margin-bottom: 0.5rem;
    letter-spacing: -0.02em;
}

.stat-description {
    font-size: 0.8125rem;
    opacity: 0.85;
}

.stat-blue {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    color: #fff;
}

.stat-green {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: #fff;
}

.stat-orange {
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
    color: #fff;
}

.config-card {
    background: var(--surface);
    border-radius: var(--radius-xl);
    box-shadow: var(--shadow);
    overflow: hidden;
    margin-bottom: 2rem;
}

.config-header {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid var(--border);
    background: var(--surface-2);
}

.config-header i {
    font-size: 1.25rem;
    color: var(--accent);
}

.config-header h2 {
    font-size: 1.125rem;
    font-weight: 600;
    margin: 0;
    color: var(--ink);
}

.config-body {
    padding: 1.5rem;
}

.config-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1.5rem;
}

.config-group {
    display: flex;
    flex-direction: column;
}

.config-label {
    font-size: 0.8125rem;
    font-weight: 600;
    color: var(--ink-soft);
    margin-bottom: 0.5rem;
    display: flex;
    align-items: center;
    gap: 0.25rem;
}

.config-label i {
    color: var(--accent);
}

.config-select {
    padding: 0.75rem 1rem;
    border: 1.5px solid var(--border);
    border-radius: var(--radius);
    background: var(--surface);
    color: var(--ink);
    font-size: 0.875rem;
    transition: all 0.15s ease;
    cursor: pointer;
}

.config-select:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 3px var(--accent-soft);
}

.config-display {
    padding: 0.75rem 1rem;
    border: 1.5px solid var(--border);
    border-radius: var(--radius);
    background: var(--surface-2);
    color: var(--ink);
    font-size: 1rem;
    font-weight: 600;
    min-height: 44px;
    display: flex;
    align-items: center;
}

.config-display .counting {
    color: var(--ink-muted);
    font-weight: 400;
}

.config-display .count-value {
    color: var(--accent);
}

.config-display .no-data {
    color: var(--warning);
}

.config-display .error {
    color: var(--danger);
}

.config-hint {
    font-size: 0.75rem;
    color: var(--ink-muted);
    margin-top: 0.5rem;
}

.config-action {
    justify-content: flex-end;
}

.btn-block {
    width: 100%;
}

.progress-card {
    background: var(--surface);
    border-radius: var(--radius-xl);
    box-shadow: var(--shadow);
    overflow: hidden;
    margin-bottom: 2rem;
}

.progress-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid var(--border);
    background: var(--surface-2);
}

.progress-title {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.progress-title i {
    font-size: 1.25rem;
    color: var(--accent);
    animation: spin 1s linear infinite;
}

@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

.progress-title h2 {
    font-size: 1.125rem;
    font-weight: 600;
    margin: 0;
    color: var(--ink);
}

.progress-badge {
    padding: 0.375rem 0.875rem;
    background: var(--accent-soft);
    color: var(--accent);
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 600;
}

.progress-body {
    padding: 1.5rem;
}

.progress-wrapper {
    margin-bottom: 1.5rem;
}

.progress-bar-container {
    height: 2rem;
    background: var(--surface-2);
    border-radius: var(--radius);
    overflow: hidden;
    position: relative;
}

.progress-bar-fill {
    height: 100%;
    background: linear-gradient(90deg, var(--accent) 0%, var(--accent-hover) 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    transition: width 0.3s ease;
    position: relative;
}

.progress-bar-fill.complete {
    background: linear-gradient(90deg, var(--success) 0%, #059669 100%);
}

.progress-text {
    color: #fff;
    font-size: 0.875rem;
    font-weight: 600;
    position: absolute;
    left: 50%;
    transform: translateX(-50%);
}

.progress-stats {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
}

.progress-stat {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 1rem;
    background: var(--surface-2);
    border-radius: var(--radius);
}

.progress-stat .stat-label {
    font-size: 0.75rem;
    color: var(--ink-muted);
    margin-bottom: 0.5rem;
}

.progress-stat .stat-value {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--accent);
}

.files-card {
    background: var(--surface);
    border-radius: var(--radius-xl);
    box-shadow: var(--shadow);
    overflow: hidden;
}

.files-header {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid var(--border);
    background: var(--surface-2);
}

.files-header i {
    font-size: 1.25rem;
    color: var(--accent);
}

.files-header h2 {
    font-size: 1.125rem;
    font-weight: 600;
    margin: 0;
    color: var(--ink);
}

.files-body {
    padding: 1.5rem;
}

.files-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 1rem;
}

.file-card {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1rem;
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    background: var(--surface);
    transition: all 0.2s ease;
}

.file-card:hover {
    border-color: var(--accent);
    box-shadow: var(--shadow-md);
    transform: translateY(-2px);
}

.file-icon {
    width: 48px;
    height: 48px;
    border-radius: var(--radius);
    background: var(--success-soft);
    color: var(--success);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    flex-shrink: 0;
}

.file-info {
    flex: 1;
    min-width: 0;
}

.file-name {
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--ink);
    margin-bottom: 0.25rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.file-size {
    font-size: 0.75rem;
    color: var(--ink-muted);
}

.toast-container-custom {
    position: fixed;
    top: 1rem;
    right: 1rem;
    z-index: 9999;
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.toast-custom {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1rem 1.25rem;
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-lg);
    animation: slideInRight 0.3s ease;
    min-width: 300px;
}

@keyframes slideInRight {
    from {
        opacity: 0;
        transform: translateX(100%);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}

.toast-success {
    background: var(--success-soft);
    color: var(--success);
    border: 1px solid var(--success);
}

.toast-danger {
    background: var(--danger-soft);
    color: var(--danger);
    border: 1px solid var(--danger);
}

.toast-icon {
    width: 40px;
    height: 40px;
    border-radius: var(--radius);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    flex-shrink: 0;
}

.toast-success .toast-icon {
    background: var(--success);
    color: #fff;
}

.toast-danger .toast-icon {
    background: var(--danger);
    color: #fff;
}

.toast-content {
    flex: 1;
    font-size: 0.875rem;
    font-weight: 500;
}

.toast-close {
    width: 32px;
    height: 32px;
    border-radius: var(--radius);
    border: none;
    background: transparent;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: background 0.15s ease;
    font-size: 1rem;
}

.toast-close:hover {
    background: rgba(0,0,0,0.1);
}

.spin {
    animation: spin 1s linear infinite;
}

@media (max-width: 1024px) {
    .config-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    .page-container {
        padding: 1rem;
    }

    .page-header-section {
        flex-direction: column;
        align-items: flex-start;
        gap: 1rem;
    }

    .header-actions {
        width: 100%;
    }

    .header-actions .btn {
        flex: 1;
    }

    .stats-grid {
        grid-template-columns: 1fr;
        gap: 1rem;
    }

    .files-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<?php include __DIR__ . '/../../../php_scripts/footer.php'; ?>

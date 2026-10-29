<?php
require_once __DIR__ . '/../../../php_scripts/auth.php';

requirePermission('export_data');
api_init();

if (!isset($link) || !$link) {
    exit('Database connection failed');
}

$totalRecords = 0;
$result = mysqli_query($link, "SELECT COUNT(*) as total FROM " . tn('TBL_MAIN'));
if ($result) {
    $row = mysqli_fetch_assoc($result);
    $totalRecords = (int)$row['total'];
}

$pageTitle = 'Export Main Database • CallNow CRM';
include __DIR__ . '/../../../php_scripts/header.php';
?>

<div class="container-fluid px-3 py-3">
    <!-- Module Header -->
    <div class="module-header mb-3">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1 fs-xs">
                    <li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Home</a></li>
                    <li class="breadcrumb-item"><a href="<?= url('modules/database/data_management_main.php') ?>">Main Database</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Batch Export</li>
                </ol>
            </nav>
            <h1 class="h4 fw-bold text-dark mb-0">Export Full Database</h1>
            <div class="text-muted fs-xs">Download large volumes of customer records in segmented CSV parts</div>
        </div>
        <div class="module-header-actions">
            <a href="<?= url('modules/database/data_management_main.php') ?>" class="btn btn-secondary btn-sm">
                <i class="bi bi-database me-1"></i> Main Database
            </a>
        </div>
    </div>

    <!-- Metric KPI Cards -->
    <div class="row g-2 mb-3">
        <div class="col-sm-6 col-md-4">
            <div class="metric-card">
                <div class="metric-card-title">Total Main DB Records</div>
                <div class="metric-card-value text-primary" id="statTotalRecords"><?= number_format($totalRecords) ?></div>
                <div class="metric-card-subtitle text-muted">Ready for export</div>
            </div>
        </div>
        <div class="col-sm-6 col-md-4">
            <div class="metric-card">
                <div class="metric-card-title">Export File Format</div>
                <div class="metric-card-value text-dark">CSV (UTF-8)</div>
                <div class="metric-card-subtitle text-muted">Comma-delimited format</div>
            </div>
        </div>
        <div class="col-sm-6 col-md-4">
            <div class="metric-card">
                <div class="metric-card-title">Batch Strategy</div>
                <div class="metric-card-value text-success">Chunked Multi-part</div>
                <div class="metric-card-subtitle text-muted">High-performance streams</div>
            </div>
        </div>
    </div>

    <!-- Export Configuration Card -->
    <div class="crm-card mb-3">
        <div class="crm-card-header">
            <div class="crm-card-title"><i class="bi bi-sliders text-primary me-2"></i>Export Partition Settings</div>
        </div>
        <div class="crm-card-body">
            <div class="row g-3 align-items-end">
                <div class="col-md-5">
                    <label class="form-label" for="batchSize">Rows per Downloaded File</label>
                    <select id="batchSize" class="form-select">
                        <option value="1000">1,000 rows per file</option>
                        <option value="5000">5,000 rows per file</option>
                        <option value="10000" selected>10,000 rows per file (Recommended)</option>
                        <option value="20000">20,000 rows per file</option>
                        <option value="50000">50,000 rows per file</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Calculated Partitions</label>
                    <div id="totalRecords" class="form-control bg-light">
                        <span class="text-muted fs-xs">Calculating...</span>
                    </div>
                </div>
                <div class="col-md-3">
                    <button id="startExport" class="btn btn-primary w-100" disabled>
                        <i class="bi bi-play-fill me-1"></i> Start Export
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Export Progress Card -->
    <div id="exportStatus" class="crm-card mb-3 d-none">
        <div class="crm-card-header">
            <div class="crm-card-title"><i class="bi bi-arrow-repeat text-primary me-2"></i>Export Execution Status</div>
            <span class="badge badge-secondary fs-xs">
                Part <span id="part">0</span> of <span id="totalParts">0</span>
            </span>
        </div>
        <div class="crm-card-body">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="fs-xs text-muted">Records Processed: <strong id="current" class="text-dark">0</strong></span>
                <span class="fs-xs fw-semibold text-dark"><span id="percent">0</span>%</span>
            </div>
            <div class="progress mb-2" style="height: 16px;">
                <div id="progressBar" class="progress-bar progress-bar-striped progress-bar-animated bg-primary" role="progressbar" style="width:0%">
                    <span class="progress-text">0%</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Download Files Card -->
    <div id="filesSection" class="crm-card d-none">
        <div class="crm-card-header">
            <div class="crm-card-title"><i class="bi bi-file-earmark-arrow-down text-primary me-2"></i>Generated CSV Files</div>
        </div>
        <div class="crm-card-body">
            <div id="filesList" class="row g-2"></div>
        </div>
    </div>
</div>

<script>
const CSRF_TOKEN = '<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>';
const ajaxBase = '<?= defined('APP_BASE') ? APP_BASE : '' ?>/modules/database/maindatabase_ajax/';
let totalRecords = <?= (int)$totalRecords ?>;

$(document).ready(function() {
    if (totalRecords > 0) {
        $('#totalRecords').html('<span class="fw-bold text-dark fs-xs">' + totalRecords.toLocaleString() + ' records available</span>');
        $('#statTotalRecords').text(totalRecords.toLocaleString());
        $('#startExport').prop('disabled', false);
    } else {
        $('#totalRecords').html('<span class="text-muted fs-xs">No records available</span>');
        $('#startExport').prop('disabled', true).html('<i class="bi bi-exclamation-triangle me-1"></i>No Data');
    }
});

$('#startExport').on('click', function() {
    const batchSize = parseInt($('#batchSize').val());
    const totalParts = Math.ceil(totalRecords / batchSize);
    
    $(this).prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Exporting…');
    $('#exportStatus').removeClass('d-none');
    $('#filesSection').removeClass('d-none').find('#filesList').empty();
    $('#totalParts').text(totalParts.toLocaleString());
    
    startExport(0, batchSize);
});

function startExport(offset, batchSize) {
    $.post(ajaxBase + 'export_batch', { 
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
            const fileName = 'MainDB_part_' + part + '_' + current.toLocaleString() + '_rows.csv';

            $('#filesList').append(`
                <div class="col-md-6">
                    <div class="crm-card p-2 border">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <div class="fw-bold fs-xs text-dark text-truncate" style="max-width:240px;">${fileName}</div>
                                <div class="text-muted fs-xs">${res.count.toLocaleString()} rows</div>
                            </div>
                            <a href="${url}" download="${fileName}" class="btn btn-secondary btn-sm py-1 px-2 fs-xs">
                                <i class="bi bi-download me-1 text-primary"></i> Save CSV
                            </a>
                        </div>
                    </div>
                </div>
            `);

            if (current < totalRecords) {
                setTimeout(() => startExport(offset + batchSize, batchSize), 100);
            } else {
                $('#progressBar').removeClass('progress-bar-animated').addClass('bg-success').find('.progress-text').text('100% Complete');
                $('#startExport').html('<i class="bi bi-check2-all me-1"></i> Completed').prop('disabled', false);
            }
        } else {
            $('#startExport').html('<i class="bi bi-x-circle me-1"></i> Failed').prop('disabled', false);
            alert('Export failed: ' + (res.message || res.error || 'Unknown error'));
        }
    }, 'json').fail(function(xhr) {
        $('#startExport').html('<i class="bi bi-x-circle me-1"></i> Failed').prop('disabled', false);
        alert('Server error: ' + (xhr.statusText || 'Unknown error'));
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
</script>

<?php include __DIR__ . '/../../../php_scripts/footer.php'; ?>

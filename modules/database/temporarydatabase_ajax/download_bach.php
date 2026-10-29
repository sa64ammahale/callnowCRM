<?php
require_once __DIR__ . '/../../../php_scripts/auth.php';
require_once __DIR__ . '/../../../config.php';

requirePermission('export_data');
api_init();

$csrfToken = $_SESSION['csrf_token'] ?? '';
$batchSize = 10000;
$delay = 0.1;
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Export Staging Database • CallNow CRM</title>
    <link href="<?= vnd('css/bootstrap.min.css') ?>" rel="stylesheet">
    <link href="<?= vnd('css/bootstrap-icons.css') ?>" rel="stylesheet">
    <link href="<?= defined('APP_BASE') ? APP_BASE . '/assets/css/app-theme.css' : 'assets/css/app-theme.css' ?>" rel="stylesheet">
    <style>
        body { background-color: var(--crm-bg); }
    </style>
</head>
<body class="py-4">

<div class="container" style="max-width: 900px;">
    <div class="module-header mb-3">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1 fs-xs">
                    <li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Home</a></li>
                    <li class="breadcrumb-item"><a href="<?= url('modules/database/data_management_temporary.php') ?>">Temporary DB</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Batch Export</li>
                </ol>
            </nav>
            <h1 class="h4 fw-bold text-dark mb-0">Export Temporary Database</h1>
            <div class="text-muted fs-xs">Segmented CSV download for high-volume lead export</div>
        </div>
        <div class="module-header-actions">
            <button onclick="window.close()" class="btn btn-secondary btn-sm">
                <i class="bi bi-x-lg me-1"></i> Close Window
            </button>
        </div>
    </div>

    <div class="crm-card mb-3">
        <div class="crm-card-body p-4 text-center">
            <div id="exportStatus" class="py-4">
                <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
                <div class="mt-2 fs-xs text-muted">Calculating total staging records...</div>
            </div>

            <div id="progressSection" class="d-none text-start">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="fs-xs text-muted">Batch Export Progress</span>
                    <span class="fs-xs fw-semibold text-dark"><span id="current">0</span> / <span id="total">0</span> rows</span>
                </div>
                <div class="progress mb-3" style="height: 16px;">
                    <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary" role="progressbar" style="width:0%">0%</div>
                </div>

                <div class="d-flex justify-content-between align-items-center mb-3">
                    <span class="fs-xs text-muted">File Part <strong id="part" class="text-dark">1</strong> (<?= number_format($batchSize) ?> rows per batch)</span>
                    <button id="startExport" class="btn btn-primary btn-sm px-3" disabled>
                        <i class="bi bi-play-fill me-1"></i> Begin Export
                    </button>
                </div>

                <hr>

                <div class="fs-xs fw-semibold text-dark mb-2">Generated CSV Files:</div>
                <div id="filesList" class="row g-2"></div>
            </div>
        </div>
    </div>
</div>

<script src="<?= vnd('js/jquery.min.js') ?>"></script>
<script src="<?= vnd('js/bootstrap.bundle.min.js') ?>"></script>

<script>
$(document).ready(function() {
    getTotalCount();
});

const CSRF_TOKEN = '<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>';

function getTotalCount() {
    $.get('get_total_count', function(total) {
        total = parseInt(total);
        if (total === 0) {
            $('#exportStatus').html('<div class="alert alert-warning fs-xs mb-0"><strong>No records found!</strong> Temporary database is empty.</div>');
            return;
        }

        $('#total').text(total.toLocaleString());
        $('#exportStatus').fadeOut(function() {
            $('#progressSection').removeClass('d-none');
            $('#startExport').prop('disabled', false);
        });
    });
}

$('#startExport').on('click', function() {
    $(this).prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Processing...');
    startExport(0);
});

function startExport(offset) {
    const batchSize = <?= $batchSize ?>;
    $.post('export_batch', { offset: offset, limit: batchSize, csrf_token: CSRF_TOKEN }, function(res) {
        if (res.success) {
            const current = offset + res.count;
            const total = parseInt($('#total').text().replace(/,/g, ''));
            const percent = Math.round((current / total) * 100);
            const part = Math.floor(offset / batchSize) + 1;

            $('.progress-bar').css('width', percent + '%').text(percent + '%');
            $('#current').text(current.toLocaleString());
            $('#part').text(part);

            const blob = b64toBlob(res.csv, 'text/csv');
            const url = URL.createObjectURL(blob);
            const fileName = 'TempDB_part_' + part + '_(' + current.toLocaleString() + 'rows).csv';

            $('#filesList').append(`
                <div class="col-md-6">
                    <div class="crm-card p-2 border">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <div class="fw-bold fs-xs text-dark text-truncate" style="max-width:240px;">${fileName}</div>
                                <div class="text-muted fs-xs">${res.count.toLocaleString()} rows</div>
                            </div>
                            <a href="${url}" download="${fileName}" class="btn btn-secondary btn-sm py-1 px-2 fs-xs">
                                <i class="bi bi-download me-1 text-primary"></i> Save
                            </a>
                        </div>
                    </div>
                </div>
            `);

            if (current < total) {
                setTimeout(() => startExport(offset + batchSize), 100);
            } else {
                $('.progress-bar').removeClass('progress-bar-animated').addClass('bg-success');
                $('#startExport').html('<i class="bi bi-check-lg me-1"></i> Completed').prop('disabled', false);
            }
        } else {
            alert('Export Error: ' + res.message);
        }
    }, 'json');
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
</body>
</html>

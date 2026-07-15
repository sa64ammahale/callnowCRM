<?php
require_once __DIR__ . '/../../../php_scripts/auth.php';
require_once __DIR__ . '/../../../config.php';

if (!isAdmin()) {
    http_response_code(403);
    exit('Admin access required');
}

$csrfToken = $_SESSION['csrf_token'] ?? '';
// === CONFIG ===
$batchSize = 10000; // 50k rows per file (perfect balance)
$delay = 0.1;       // small delay to prevent server overload (optional)
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Export Full Database &bull; CallNow</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= defined('APP_BASE') ? APP_BASE . '/assets/css/app-theme.css' : 'assets/css/app-theme.css' ?>" rel="stylesheet">
</head>
<body>

<div class="container py-5">
    <div class="card p-5">
        <div class="text-center mb-5">
            <i class="bi bi-cloud-download display-1 text-primary mb-4"></i>
            <h1 class="fw-bold">Export Full Database</h1>
            <p class="lead text-muted">Safe & fast export in multiple CSV parts (50,000 rows each)</p>
        </div>

        <div id="exportStatus">
            <div class="text-center">
                <div class="spinner-border text-primary" style="width:4rem;height:4rem;" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <h4 class="mt-4">Counting total records...</h4>
            </div>
        </div>

        <div id="progressSection" class="d-none">
            <h5>Export Progress</h5>
            <div class="progress mb-3">
                <div class="progress-bar progress-bar-striped progress-bar-animated" style="width:0%">0%</div>
            </div>
            <p class="text-muted mb-4"><span id="current">0</span> / <span id="total">0</span> records &bull; Part <span id="part">1</span></p>

            <div id="filesList" class="row g-3"></div>
        </div>

        <div class="text-center mt-5">
            <button id="startExport" class="btn btn-success btn-lg shadow-lg" disabled>
                <i class="bi bi-play-fill"></i> Start Export Now
            </button>
        </div>
    </div>
</div>


<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

<script>
// Auto-start when page loads
$(document).ready(function() {
    getTotalCount();
});

const CSRF_TOKEN = '<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>';

function getTotalCount() {
    $.get('get_total_count.php', function(total) {
        total = parseInt(total);
        if (total === 0) {
            $('#exportStatus').html('<div class="alert alert-warning"><strong>No data found!</strong> Your database is empty.</div>');
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
    $(this).prop('disabled', true).html('<i class="spinner-border spinner-border-sm"></i> Exporting...');
    startExport(0);
});

function startExport(offset) {
    const batchSize = <?= $batchSize ?>;
    $.post('export_batch.php', { offset: offset, limit: batchSize, csrf_token: CSRF_TOKEN }, function(res) {
        if (res.success) {
            const current = offset + res.count;
            const total = parseInt($('#total').text().replace(/,/g, ''));
            const percent = Math.round((current / total) * 100);
            const part = Math.floor(offset / batchSize) + 1;

            $('.progress-bar').css('width', percent + '%').text(percent + '%');
            $('#current').text(current.toLocaleString());
            $('#part').text(part);

            // Add downloadable file
            const blob = b64toBlob(res.csv, 'text/csv');
            const url = URL.createObjectURL(blob);
            const fileName = 'TEMPORARY_DATABASE_part_' + part + '_(' + current.toLocaleString() + 'rows).csv';

            $('#filesList').append(`
                <div class="col-md-6">
                    <div class="file-item card p-4 text-center">
                        <i class="bi bi-file-earmark-text display-4 text-success mb-3"></i>
                        <h6 class="fw-bold">${fileName}</h6>
                        <a href="${url}" download="${fileName}" class="btn btn-success btn-sm">
                            <i class="bi bi-download"></i> Download Part ${part}
                        </a>
                        <small class="d-block text-muted mt-2">${res.count.toLocaleString()} rows</small>
                    </div>
                </div>
            `);

            if (current < total) {
                setTimeout(() => startExport(offset + batchSize), 100); // small delay
            } else {
                $('.progress-bar').removeClass('progress-bar-animated').addClass('bg-success');
                $('#startExport').html('<i class="bi bi-check2-all"></i> Complete!').prop('disabled', false);
                showToast('Success!', 'Full export completed successfully!', 'success');
            }
        } else {
            alert('Error: ' + res.message);
        }
    }, 'json');
}

// Helper: Convert base64 to Blob
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
    const toast = `<div class="toast align-items-center text-white bg-${type} border-0" role="alert">
        <div class="d-flex"><div class="toast-body"><strong>${title}</strong><br><small>${message}</small></div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div></div>`;
    $('body').append(toast);
    $('.toast').last().toast({ delay: 5000 }).toast('show');
    setTimeout(() => $('.toast').last().remove(), 6000);
}
</script>
</body>
</html>
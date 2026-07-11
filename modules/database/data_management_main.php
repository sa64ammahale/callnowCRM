<?php
require_once "../../php_scripts/auth.php";
// assuming you have $link here or we'll use PDO

// Get total count for header
$total_records = mysqli_fetch_assoc(mysqli_query($link, "SELECT COUNT(*) as total FROM MAIN_DATABASE"))['total'] ?? 0;
$unused = mysqli_fetch_assoc(mysqli_query($link, "SELECT COUNT(*) as c FROM MAIN_DATABASE WHERE MAINDATABASE_CALL_DIALED_STATUS = 'Not Called'"))['c'] ?? 0;

// Get all active assignees for assign dropdown
$users_result = mysqli_query($link, "SELECT ID, NAME FROM USERS WHERE STATUS = 'Active' ORDER BY NAME");
$telecallers = [];
while ($u = mysqli_fetch_assoc($users_result)) {
    $telecallers[$u['ID']] = $u['NAME'];
}
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Main Database • CallNow</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../../assets/css/app-theme.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- DataTables + Buttons -->
    <link rel="stylesheet" href="https://cdn.datatables.net/2.0.8/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/3.0.2/css/buttons.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/3.0.2/css/responsive.bootstrap5.min.css">

    <style>
        body { font-family: 'Poppins', sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; }
        .glass-card { background: rgba(255,255,255,0.95); backdrop-filter: blur(20px); border-radius: 1.5rem; box-shadow: 0 20px 40px rgba(0,0,0,0.15); }
        .table { font-size: 0.87rem; }
        .dataTables_wrapper .dataTables_length select,
        .dataTables_wrapper .dataTables_filter input { border-radius: 50px; padding: 0.4rem 1rem; }
        .dt-buttons { margin-bottom: 1rem; }
        .dt-button { border-radius: 50px !important; padding: 0.4rem 1.2rem !important; font-size: 0.85rem !important; }
        .toast-container { position: fixed; top: 20px; right: 20px; z-index: 9999; }
        .badge-status { font-size: 0.7rem; padding: 0.35rem 0.6rem; border-radius: 50px; }
        @media (max-width: 768px) {
            .dt-buttons { flex-wrap: wrap; gap: 0.5rem; }
            .dataTables_filter { margin-top: 1rem; }
        }
        
        /* Premium Pagination & Length Menu */
.dataTables_paginate .paginate_button {
    border-radius: 50px !important;
    margin: 0 3px !important;
    padding: 0.4rem 0.9rem !important;
    font-weight: 600;
}
.dataTables_paginate .paginate_button.current {
    background: linear-gradient(135deg, #667eea, #764ba2) !important;
    border: none !important;
    color: white !important;
}
.dataTables_length select {
    border-radius: 50px !important;
    padding: 0.5rem 1rem !important;
    border: 2px solid #667eea;
}
        
    </style>
</head>
<body>
<?php include '../../php_scripts/header.php'; ?>

<div class="container py-2">
    <!-- Header Stats -->

        <div class="col-12 ">
            <div class="glass-card p-4 text-center text-md-start mb-3">
                <h2 class="fw-bold mb-2 text-primary"><i class="bi bi-database-fill"></i> Main Database</h2>
                <p class="mb-0 text-muted">Total Records: <strong><?= number_format($total_records) ?></strong> • 
                Not Called: <span class="text-danger fw-bold"><?= number_format($unused) ?></span></p>
            </div>
            
        </div>


    <!-- DataTable -->
    <div class="glass-card p-4">
        <table id="mainTable" class="table table-hover table-striped table-sm" style="width:100%">
            <thead class="table-dark">
                <tr>
                    <th><input type="checkbox" id="selectAll"></th>
                    <th>Mobile</th>
                    <th>Name</th>
                    <th>Company</th>
                    <th>Package</th>
                    <th>Status</th>
                    <th>Assigned To</th>
                    <th>Uploaded</th>
                    <th>Actions</th>
                </tr>
            </thead>
        </table>
    </div>
</div>

<!-- Toast Container -->
<div class="toast-container"></div>

<!-- Assign Modal -->
<div class="modal fade" id="assignModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content rounded-4">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-people"></i> Bulk Assign Selected ( <span id="selectedCount">0</span> )</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="form-label fw-bold">Assign to Telecaller</label>
                <select class="form-select form-select-lg" id="assignUser">
                    <option value="">-- Select User --</option>
                    <?php foreach($telecallers as $id => $name): ?>
                        <option value="<?= $id ?>"><?= htmlspecialchars($name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success btn-lg" id="doAssign">
                    <i class="bi bi-check2"></i> Assign Now
                </button>
            </div>
        </div>
    </div>
</div>

<?php include '../../php_scripts/footer.php'; ?>

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/2.0.8/js/dataTables.min.js"></script>
<script src="https://cdn.datatables.net/2.0.8/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/3.0.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/3.0.2/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/responsive/3.0.2/js/responsive.bootstrap5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>
<script src="https://cdn.datatables.net/buttons/3.0.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/3.0.2/js/buttons.print.min.js"></script>

<script>
// Toast Function
function showToast(title, message, type = 'success') {
    const toast = `
    <div class="toast align-items-center text-white bg-${type} border-0" role="alert">
        <div class="d-flex">
            <div class="toast-body">
                <strong>${title}</strong><br><small>${message}</small>
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>`;
    $('.toast-container').append(toast);
    $('.toast').last()[0].show();
    setTimeout(() => $('.toast').last().remove(), 5000);
}

$(document).ready(function() {
    
    const table = $('#mainTable').DataTable({
        processing: true,
        serverSide: true,
        responsive: true,
        ajax: '../database/maindatabase_ajax/datatable.php',
        pageLength: 2500,
        lengthMenu: [ 2500, 5000, 10000, 15000,20000, [10000, -1], [10000, 'All'] ],
        order: [[7, 'desc']],
        dom: '<"row"<"col-sm-12 col-md-4"l><"col-sm-12 col-md-4 text-center"B><"col-sm-12 col-md-4"f>>rtip',
        buttons: [
            { extend: 'copy', text: '<i class="bi bi-copy"></i> Copy', className: 'btn btn-outline-secondary btn-sm text-white' },
            { extend: 'csv', text: '<i class="bi bi-file-earmark-spreadsheet"></i> CSV', className: 'btn btn-success btn-sm', title: 'MainDatabase_Export_' + new Date().toISOString().slice(0,10) },
            { extend: 'excel', text: '<i class="bi bi-file-excel"></i> Excel', className: 'btn btn-info btn-sm' },
            { text: '<i class="bi bi-trash3"></i> Delete Selected', className: 'btn btn-danger btn-sm', action: function() { bulkDelete(); }},
            {
                text: '<i class="bi bi-cloud-download"></i> Export Full DB (in Parts)',
                className: 'btn btn-primary btn-sm shadow-sm fw-bold',
                action: function () {
                    $.get('../database/maindatabase_ajax/get_total_count.php', function (total) {
                        total = parseInt(total);
                        if (total === 0) return showToast('Empty', 'No data found', 'info');
            
                        if (total > 100000 && !confirm(`Warning: ${total.toLocaleString()} records!\n\nThis will download in multiple large CSV files.\n\nContinue?`)) {
                            return;
                        }
            
                        const win = window.open('../database/maindatabase_ajax/download_bach.php', '_blank');
                        if (win) {
                            showToast('Export Started', `${total.toLocaleString()} records → downloading in parts`, 'success');
                        } else {
                            showToast('Popup Blocked!', 'Please allow popups', 'danger');
                        }
                    });
                }
            }
        ],
        columnDefs: [
            { orderable: false, targets: 0 },
            { width: '100px', targets: 1 },
            {
                targets: 5,
                render: function(data) {
                    const badge = {
                        'Not Called': 'bg-secondary',
                        'Dialed': 'bg-info',
                        'Connected': 'bg-success',
                        'Busy': 'bg-warning',
                        'No Answer': 'bg-orange',
                        'Do Not Call': 'bg-danger',
                        'Pending': 'bg-primary'
                    }[data] || 'bg-dark';
                    return `<span class="badge ${badge} badge-status">${data || 'Not Called'}</span>`;
                }
            }
        ],
        language: {
            processing: "<div class='spinner-border text-primary' role='status'><span class='visually-hidden'>Loading...</span></div>",
            lengthMenu: "Show _MENU_ records",
            info: "Showing _START_ to _END_ of _TOTAL_ leads",
            paginate: {
                next: '<i class="bi bi-chevron-right"></i>',
                previous: '<i class="bi bi-chevron-left"></i>'
            }
        }
    });

    // Select All
    $('#selectAll').on('click', function() {
        const checked = this.checked;
        table.rows({ page: 'current' }).nodes().to$().find('input[type="checkbox"]').prop('checked', checked);
        updateSelectedCount();
    });

    $('#mainTable tbody').on('change', 'input[type="checkbox"]', updateSelectedCount);

    function updateSelectedCount() {
        const count = $('#mainTable input[type="checkbox"]:checked').length - ($('#selectAll').is(':checked') ? 1 : 0);
        $('#selectedCount').text(count);
    }

    // Bulk Assign
    $('#doAssign').on('click', function() {
        const userId = $('#assignUser').val();
        if (!userId) return showToast('Error', 'Please select a user', 'danger');

        const ids = [];
        $('#mainTable input[type="checkbox"]:checked').each(function() {
            if (!$(this).is('#selectAll')) {
                const row = table.row($(this).closest('tr')).data();
                ids.push(row[0]); // ID is first column (hidden)
            }
        });

        if (ids.length === 0) return showToast('Warning', 'No records selected', 'warning');

        $.post('../database/maindatabase_ajax/bulk_assign.php', { ids: ids, user_id: userId }, function(res) {
            if (res.success) {
                table.ajax.reload();
                $('#assignModal').modal('hide');
                showToast('Success!', `${ids.length} leads assigned successfully`, 'success');
            } else {
                showToast('Error', res.message || 'Failed', 'danger');
            }
        }, 'json');
    });

    // Bulk Delete
    window.bulkDelete = function() {
        if (!confirm('Delete selected records permanently?')) return;
        const ids = [];
        $('#mainTable input[type="checkbox"]:checked').each(function() {
            if (!$(this).is('#selectAll')) {
                const row = table.row($(this).closest('tr')).data();
                ids.push(row[0]);
            }
        });
        $.post('../database/maindatabase_ajax/bulk_delete.php', { ids: ids }, function(res) {
            if (res.success) {
                table.ajax.reload();
                showToast('Deleted!', `${ids.length} records removed`, 'danger');
            }
        }, 'json');
    };
});
</script>
</body>
</html>

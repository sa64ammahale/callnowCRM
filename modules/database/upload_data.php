<?php
require_once '../../php_scripts/auth.php';
require_once '../../php_scripts/team_auth.php';
require_once '../../php_scripts/super_admin.php';

requirePermission('upload_data');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file']) && empty($_POST['import'])) {
    $_POST['import'] = '1';
}

/* ensure uploads dir */
if (!is_dir('uploads')) mkdir('uploads', 0755, true);

/* default variables for UI */
$summary = "";
$summaryType = "";
$logs = [];
$stats = [
    'inserted' => 0,
    'duplicates' => 0,
    'invalid' => 0,
    'rows_processed' => 0,
    'would_insert' => 0,
    'would_update' => 0,
    'header_errors' => 0
];
$detected_headers = [];
$expected_headers_set = [
    'name', 'mobile', 'company', 'package', 'other info'
]; // canonical names for validation

$upload_time = date('Y-m-d H:i:s');

/* helper */
function logLine(&$arr, $s) { $arr[] = $s; }
function canonicalHeader($h) {
    return strtolower(trim(preg_replace('/[^a-z0-9 ]+/i','', $h)));
}

/* --- ARCHIVE ACTION --- */
if (isset($_POST['archive_action']) && isset($_POST['archive_months'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $summary = "Error: Invalid session token.";
        $summaryType = "danger";
    } else {
    $months = intval($_POST['archive_months']);
    if ($months < 1) $months = 12;
    $source_table = tn('TBL_MAIN');
    $archive_table = tn('TBL_MAIN_ARCHIVE');

    $create_sql = "CREATE TABLE IF NOT EXISTS `$archive_table` LIKE `$source_table`";
    if (!mysqli_query($link, $create_sql)) {
        $summary = "Archive: Failed to ensure archive table exists: " . mysqli_error($link);
        $summaryType = "danger";
    } else {
        $insert_sql = "INSERT INTO `$archive_table`
                       SELECT * FROM `$source_table`
                       WHERE MAINDATABASE_UPLOAD_DATETIME < (NOW() - INTERVAL ? MONTH)";
        $ins_stmt = mysqli_prepare($link, $insert_sql);
        if ($ins_stmt) {
            mysqli_stmt_bind_param($ins_stmt, "i", $months);
            if (mysqli_stmt_execute($ins_stmt)) {
                $moved = mysqli_stmt_affected_rows($ins_stmt);
                mysqli_stmt_close($ins_stmt);

                $batch = 1000;
                $deleted_total = 0;
                while (true) {
                    $del_sql = "DELETE FROM `$source_table`
                                WHERE MAINDATABASE_UPLOAD_DATETIME < (NOW() - INTERVAL ? MONTH)
                                LIMIT $batch";
                    $del_stmt = mysqli_prepare($link, $del_sql);
                    if (!$del_stmt) break;
                    mysqli_stmt_bind_param($del_stmt, "i", $months);
                    mysqli_stmt_execute($del_stmt);
                    $del_count = mysqli_stmt_affected_rows($del_stmt);
                    mysqli_stmt_close($del_stmt);
                    $deleted_total += max(0, $del_count);
                    if ($del_count < $batch) break;
                }

                $summary = "Archive complete: moved approximately {$moved} rows; deleted {$deleted_total} from main.";
                $summaryType = "success";
            } else {
                $summary = "Archive insert failed: " . mysqli_stmt_error($ins_stmt);
                $summaryType = "danger";
                mysqli_stmt_close($ins_stmt);
            }
        } else {
            $summary = "Archive prepare failed: " . mysqli_error($link);
            $summaryType = "danger";
        }
    }
    }
}

/* --- UPLOAD / IMPORT ACTION --- */
/* Accept POST if file exists and upload OK (auto-enabled earlier if needed) */
if (isset($_POST['import']) && isset($_FILES['file']) && isset($_FILES['file']['error']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {

    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $summary = "Error: Invalid session token.";
        $summaryType = "danger";
    } else {

    // Server-side T&C check
    if (empty($_POST['terms_agreed'])) {
        $summary = "You must agree to the Terms & Conditions before uploading.";
        $summaryType = "danger";
        echo json_encode(['success' => false, 'error' => $summary]);
        exit;
    }

    $target = $_POST['target_database'] ?? 'temporary';
    if (!in_array($target, ['temporary','main','both'])) $target = 'temporary';
    $dryrun = isset($_POST['dryrun']) && $_POST['dryrun'] === '1';
    $has_header = isset($_POST['has_header']) && $_POST['has_header'] === '1';
    $file = $_FILES['file'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if ($file['size'] > 10 * 1024 * 1024 || $ext !== 'csv') {
        $summary = "Invalid file: only CSV ≤ 10MB allowed.";
        $summaryType = "danger";
        echo json_encode(['success' => false, 'error' => $summary]);
        exit;
    }

    $safe = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $file['name']);
    $path = 'uploads/' . $safe;
    if (!move_uploaded_file($file['tmp_name'], $path)) {
        echo json_encode(['success' => false, 'error' => 'Failed to save upload - check permissions.']);
        exit;
    }

    if (($handle = fopen($path, 'r')) === false) {
        echo json_encode(['success' => false, 'error' => 'Cannot open CSV file for reading.']);
        exit;
    }

    $first = null;
    if ($has_header) {
        $first = fgetcsv($handle, 4000, ",");
        if ($first === false) {
            fclose($handle);
            @unlink($path);
            echo json_encode(['success' => false, 'error' => 'CSV seems empty or unreadable.']);
            exit;
        }
        $detected_headers = array_map('canonicalHeader', $first);
    } else {
        rewind($handle);
        $detected_headers = [];
    }

    $header_aliases = [
        'name' => ['name','customer name','full name','cust name'],
        'mobile' => ['mobile','phone','mobile number','phone number','contact'],
        'company' => ['company','organisation','organization','company name'],
        'package' => ['package','plan'],
        'other info' => ['other info','other','notes','remarks']
    ];
    $header_index_map = [];
    if ($has_header) {
        foreach ($header_aliases as $canon => $alts) {
            $found = null;
            foreach ($alts as $alt) {
                $altc = canonicalHeader($alt);
                $idx = array_search($altc, $detected_headers, true);
                if ($idx !== false) { $found = $idx; break; }
            }
            $header_index_map[$canon] = $found;
        }
        if ($header_index_map['mobile'] === null) {
            fclose($handle);
            @unlink($path);
            echo json_encode(['success' => false, 'error' => "CSV header missing required 'Mobile' column. Detected: " . implode(', ', $detected_headers)]);
            exit;
        }
    } else {
        $header_index_map = ['name'=>0,'mobile'=>1,'company'=>2,'package'=>3,'other info'=>4];
    }

    $estimatedRows = 0;
    if (!$dryrun) {
        rewind($handle);
        while (fgetcsv($handle, 4000, ",") !== false) $estimatedRows++;
        rewind($handle);
        if ($has_header && $estimatedRows > 0) $estimatedRows--;
        $storageCheck = checkStorageLimit($link, max(1, $estimatedRows), USER_ID);
        if ($storageCheck) {
            fclose($handle);
            @unlink($path);
            echo json_encode(['success' => false, 'error' => $storageCheck]);
            exit;
        }
    }

    $task_id = uniqid('upl_', true);
    $progress_file = sys_get_temp_dir() . '/callnow_upload_' . md5($task_id) . '.json';
    $task_meta = [
        'task_id' => $task_id,
        'status' => 'uploaded',
        'percent' => 0,
        'processed' => 0,
        'total' => $estimatedRows,
        'message' => 'File uploaded. Starting import...',
        'path' => $path,
        'target' => $target,
        'dryrun' => $dryrun ? 1 : 0,
        'has_header' => $has_header ? 1 : 0,
        'header_index_map' => $header_index_map,
        'stats' => [
            'inserted' => 0,
            'duplicates' => 0,
            'invalid' => 0,
            'rows_processed' => 0,
            'would_insert' => 0,
            'would_update' => 0
        ],
        'logs' => []
    ];
    file_put_contents($progress_file, json_encode($task_meta));
    echo json_encode(['success' => true, 'task_id' => $task_id, 'total' => $estimatedRows]);
    exit;
}
}

if (isset($_POST['process_upload_batch']) && !empty($_POST['task_id'])) {
    $task_id = $_POST['task_id'];
    $progress_file = sys_get_temp_dir() . '/callnow_upload_' . md5($task_id) . '.json';
    if (!file_exists($progress_file)) {
        echo json_encode(['success' => false, 'error' => 'Task not found']);
        exit;
    }

    $meta = json_decode(file_get_contents($progress_file), true);
    if (($meta['status'] ?? '') === 'complete' || ($meta['status'] ?? '') === 'error') {
        echo json_encode(['status' => $meta['status'], 'percent' => $meta['percent'], 'result' => $meta]);
        exit;
    }

    $path = $meta['path'];
    $target = $meta['target'];
    $dryrun = (bool)($meta['dryrun'] ?? 0);
    $has_header = ($meta['has_header'] ?? 1) === '1';
    $header_index_map = $meta['header_index_map'] ?? [];
    $stats = $meta['stats'];
    $logs = $meta['logs'];
    $upload_time = date('Y-m-d H:i:s');

    if (!file_exists($path)) {
        $meta['status'] = 'error';
        $meta['message'] = 'Uploaded file not found.';
        file_put_contents($progress_file, json_encode($meta));
        echo json_encode(['status' => 'error', 'error' => 'Uploaded file not found.']);
        exit;
    }

    $handle = fopen($path, 'r');
    if ($has_header) {
        fgetcsv($handle, 4000, ","); // skip header
        $meta['message'] = 'Importing data...';
    }

    $targets = [];
    if ($target === 'temporary' || $target === 'both') {
        $targets['temporary'] = [
            'table' => tn('TBL_TEMP'),
            'cols' => ['name'=>'CUST_NAME','mobile'=>'CUST_MOBILE','company'=>'CUST_COMPANY','package'=>'CUST_PACKAGE','other'=>'CUST_OTHER_INFO','dt'=>'TEMP_UPLOAD_DATETIME']
        ];
    }
    if ($target === 'main' || $target === 'both') {
        $targets['main'] = [
            'table' => tn('TBL_MAIN'),
            'cols' => ['name'=>'MAINDATABASE_NAME','mobile'=>'MAINDATABASE_MOBILE','company'=>'MAINDATABASE_COMPANY','package'=>'MAINDATABASE_PACKAGE','other'=>'MAINDATABASE_OTHER_INFO','dt'=>'MAINDATABASE_UPLOAD_DATETIME']
        ];
    }

    $batch_size = 200;
    $rows_processed = 0;
    $max_rows = 200;

    while (($row = fgetcsv($handle, 4000, ",")) !== false && $rows_processed < $batch_size) {
        $meta['stats']['rows_processed']++;
        $rownum = $meta['stats']['rows_processed'];

        $name_raw   = isset($row[$header_index_map['name']]) ? $row[$header_index_map['name']] : ($row[0] ?? '');
        $mobile_raw = isset($row[$header_index_map['mobile']]) ? $row[$header_index_map['mobile']] : ($row[1] ?? '');
        $company_raw = isset($row[$header_index_map['company']]) ? $row[$header_index_map['company']] : ($row[2] ?? '');
        $package_raw = isset($row[$header_index_map['package']]) ? $row[$header_index_map['package']] : ($row[3] ?? '');
        $other_raw   = isset($row[$header_index_map['other info']]) ? $row[$header_index_map['other info']] : ($row[4] ?? '');

        $name_raw = trim($name_raw);
        $mobile_raw = trim($mobile_raw);
        $company_raw = trim($company_raw);
        $package_raw = trim($package_raw);
        $other_raw = trim($other_raw);

        $digits = preg_replace('/\D/', '', $mobile_raw);
        if (strlen($digits) > 10) $digits = substr($digits, -10);

        if (!preg_match('/^[6-9]\d{9}$/', $digits)) {
            $stats['invalid']++;
            $logs[] = "Row $rownum: Invalid mobile '$mobile_raw' -> normalized '$digits'. Skipped.";
            $rows_processed++;
            continue;
        }

        $name_val = ($name_raw === '' ? null : $name_raw);
        $mobile_val = $digits;
        $company_val = ($company_raw === '' ? null : $company_raw);
        $package_val = ($package_raw === '' ? null : $package_raw);
        $other_val = ($other_raw === '' ? null : $other_raw);

        foreach ($targets as $tkey => $tinfo) {
            $table = $tinfo['table'];
            $cols = $tinfo['cols'];
            $fields = [$cols['name'], $cols['mobile'], $cols['company'], $cols['package'], $cols['other'], $cols['dt']];
            $placeholders = [];
            $bind_types = '';
            $bind_values = [];

            if ($name_val === null) { $placeholders[] = "NULL"; }
            else { $placeholders[] = "?"; $bind_types .= "s"; $bind_values[] = $name_val; }

            $placeholders[] = "?"; $bind_types .= "s"; $bind_values[] = $mobile_val;

            if ($company_val === null) { $placeholders[] = "NULL"; }
            else { $placeholders[] = "?"; $bind_types .= "s"; $bind_values[] = $company_val; }

            if ($package_val === null) { $placeholders[] = "NULL"; }
            else { $placeholders[] = "?"; $bind_types .= "s"; $bind_values[] = $package_val; }

            if ($other_val === null) { $placeholders[] = "NULL"; }
            else { $placeholders[] = "?"; $bind_types .= "s"; $bind_values[] = $other_val; }

            $placeholders[] = "?"; $bind_types .= "s"; $bind_values[] = $upload_time;

            if ($dryrun) {
                $sel = "SELECT ID FROM `$table` WHERE `{$cols['mobile']}` = ? LIMIT 1";
                $sel_stmt = mysqli_prepare($link, $sel);
                if ($sel_stmt) {
                    mysqli_stmt_bind_param($sel_stmt, "s", $mobile_val);
                    mysqli_stmt_execute($sel_stmt);
                    mysqli_stmt_store_result($sel_stmt);
                    $exists = mysqli_stmt_num_rows($sel_stmt) > 0;
                    mysqli_stmt_close($sel_stmt);
                    if ($exists) {
                        $stats['would_update']++;
                        $logs[] = "Row $rownum: DRY-RUN would skip (duplicate) in $table for mobile $mobile_val.";
                    } else {
                        $stats['would_insert']++;
                        $logs[] = "Row $rownum: DRY-RUN would insert into $table (mobile $mobile_val).";
                    }
                }
            } else {
                $sql = "INSERT INTO `$table` (" . implode(',', array_map(function($c){return "`$c`";}, $fields)) . ")
                        VALUES (" . implode(',', $placeholders) . ")
                        ON DUPLICATE KEY UPDATE ID = ID";
                $stmt = mysqli_prepare($link, $sql);
                if ($stmt) {
                    if ($bind_types !== '') {
                        $bind_names = [$bind_types];
                        for ($i = 0; $i < count($bind_values); $i++) $bind_names[] = &$bind_values[$i];
                        call_user_func_array(array($stmt, 'bind_param'), $bind_names);
                    }
                    if (mysqli_stmt_execute($stmt)) {
                        $affected = mysqli_stmt_affected_rows($stmt);
                        if ($affected === 1) {
                            $stats['inserted']++;
                            $logs[] = "Row $rownum: Inserted into $table (mobile $mobile_val).";
                        } else {
                            $stats['duplicates']++;
                            $logs[] = "Row $rownum: Duplicate detected in $table (mobile $mobile_val). Skipped.";
                        }
                    } else {
                        $err = mysqli_stmt_error($stmt);
                        $logs[] = "Row $rownum: Insert failed into $table: $err";
                        $stats['invalid']++;
                    }
                    mysqli_stmt_close($stmt);
                }
            }
        }
        $rows_processed++;
    }

    fclose($handle);
    $meta['processed'] += $rows_processed;
    $meta['stats'] = $stats;
    $meta['logs'] = $logs;

    if ($meta['total'] > 0) {
        $meta['percent'] = min(100, (int) round($meta['processed'] / $meta['total'] * 100));
    } else {
        $meta['percent'] = 100;
    }

    $has_more = false;
    $test_handle = fopen($path, 'r');
    if ($test_handle) {
        if ($has_header) fgetcsv($test_handle, 4000, ",");
        for ($i = 0; $i < $meta['processed']; $i++) fgetcsv($test_handle, 4000, ",");
        $peek = fgetcsv($test_handle, 4000, ",");
        $has_more = ($peek !== false);
        fclose($test_handle);
    }

    if (!$has_more) {
        @unlink($path);
        $meta['status'] = 'complete';
        $meta['message'] = "Import finished. Inserted: {$stats['inserted']} | Duplicates skipped: {$stats['duplicates']} | Invalid: {$stats['invalid']}";
    } else {
        $meta['message'] = "Processed {$meta['processed']} of {$meta['total']} rows...";
    }

    file_put_contents($progress_file, json_encode($meta));
    echo json_encode([
        'status' => $meta['status'],
        'percent' => $meta['percent'],
        'processed' => $meta['processed'],
        'total' => $meta['total'],
        'message' => $meta['message'],
        'has_more' => $has_more,
        'stats' => $stats,
        'logs' => $logs
    ]);
    exit;
}

if (isset($_GET['upload_progress']) && !empty($_GET['task_id'])) {
    $task_id = $_GET['task_id'];
    $progress_file = sys_get_temp_dir() . '/callnow_upload_' . md5($task_id) . '.json';
    if (!file_exists($progress_file)) {
        echo json_encode(['status' => 'error', 'error' => 'Task not found']);
        exit;
    }
    $meta = json_decode(file_get_contents($progress_file), true);
    echo json_encode($meta);
    exit;
}
?>
<?php $pageTitle = 'Upload Customer Database'; include '../../php_scripts/header.php'; ?>

<style>
#uploadProgressModal .modal-content { border: none; box-shadow: 0 10px 40px rgba(0,0,0,0.12); }
#notificationModal .modal-content { border: none; box-shadow: 0 10px 40px rgba(0,0,0,0.12); }
</style>

<div class="container py-3">
  <div class="row mb-3 align-items-center">
    <div class="col-md-8">
      <h4 class="mb-0">Upload Customer Database</h4>
      <div class="text-muted small">Import CSV into Temporary, Main, or Both. Mobile required (Indian 10-digit).</div>
    </div>
    <div class="col-md-4 text-md-end">
      <div class="btn-group" role="group">
        <a class="btn btn-outline-secondary btn-sm" href="#" id="downloadExampleBtn"><i class="bi bi-download me-1"></i>Download Example CSV</a>
        <button class="btn btn-outline-info btn-sm" id="showHelpBtn" type="button" data-bs-toggle="collapse" data-bs-target="#helpCollapse" aria-expanded="false" aria-controls="helpCollapse">
          <i class="bi bi-question-circle me-1"></i>Help
        </button>
      </div>
    </div>
  </div>

  <div class="collapse mb-3" id="helpCollapse">
    <div class="card card-body">
      <strong>Tips:</strong> You can upload CSVs with or without a header — toggle "CSV has header". Mobile must be Indian 10 digits (starting 6-9). Use Dry-run to preview changes without writing to DB.
    </div>
  </div>

  <div class="row gy-4">
    <!-- left column: upload and preview -->
    <div class="col-lg-7">
      <div class="card shadow-sm">
        <div class="card-body">
          <form method="post" enctype="multipart/form-data" id="uploadForm" novalidate>
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="import" id="importHidden" value="0">

            <div class="d-flex justify-content-between align-items-center mb-3">
              <div class="d-flex align-items-center">
                <label class="me-2 mb-0 fw-semibold">Target</label>
                <div class="btn-group" role="group">
                  <input type="radio" class="btn-check" name="target_database" id="t_temp" value="temporary" checked>
                  <label class="btn btn-outline-primary btn-sm" for="t_temp">Temporary</label>
                  <input type="radio" class="btn-check" name="target_database" id="t_main" value="main">
                  <label class="btn btn-outline-success btn-sm" for="t_main">Main</label>
                  <input type="radio" class="btn-check" name="target_database" id="t_both" value="both">
                  <label class="btn btn-outline-secondary btn-sm" for="t_both">Both</label>
                </div>
              </div>

              <div class="d-flex align-items-center gap-3">
                <div class="form-check form-switch mb-0">
                  <input class="form-check-input" type="checkbox" id="hasHeader" name="has_header" value="1">
                  <label class="form-check-label small" for="hasHeader">CSV has header</label>
                </div>
                <div class="form-check form-switch mb-0">
                  <input class="form-check-input" type="checkbox" id="dryRun" name="dryrun" value="1">
                  <label class="form-check-label small" for="dryRun">Dry-run</label>
                </div>
              </div>
            </div>

            <div id="dropZone" class="file-zone mb-3" tabindex="0">
              <div id="dropInfoContent" style="width:100%;">
                <div class="text-center">
                  <i class="bi bi-cloud-upload" style="font-size:2.25rem;color:#0d6efd"></i>
                  <div id="dzText" class="mt-2 text-muted">Drop CSV here or click to choose • Max 10MB</div>
                </div>
              </div>
              <input type="file" name="file" id="fileInput" accept=".csv" style="display:none;">
            </div>
                            <!-- Terms & Conditions Checkbox -->
                            <div class="form-check">
                                <input type="checkbox" name="terms_agreed" class="form-check-input" id="exampleCheck1" required>
                                <div class="row justify-content-center">
                                    <label class="form-check-label" for="exampleCheck1">
                                        I Agree to all <a href="#" data-bs-toggle="modal" data-bs-target="#termsModal">Terms & Conditions</a> for Data Upload.
                                    </label>
                                </div>
                            </div>


            <div class="d-flex justify-content-between align-items-center">
              <div class="small text-muted">Preview first 10 rows below. Invalid mobiles highlighted in red.</div>
              <div>
                <button type="submit" name="import" value="1" id="uploadBtn" class="btn btn-primary btn-sm" disabled>
                  <i class="bi bi-cloud-upload me-1"></i> Upload & Import
                </button>
              </div>
            </div>
          </form>

          <!-- preview -->
          <div id="previewArea" class="mt-3" style="display:none;">
            <div class="fw-semibold mb-2">CSV Preview (first 10 data rows)</div>
            <div class="table-responsive">
              <table class="table table-bordered table-sm mb-0 preview-table">
                <thead class="table-light">
                  <tr><th>#</th><th>Name</th><th>Mobile (raw)</th><th>Norm</th><th>Valid</th><th>Company</th><th>Package</th><th>Other</th></tr>
                </thead>
                <tbody id="previewBody"></tbody>
              </table>
            </div>
            <div class="mt-2 small text-muted" id="previewMeta"></div>
          </div>

          <!-- colorful csv example -->
          <div class="mt-4">
            <div class="fw-semibold mb-2">CSV Example</div>
            <div class="table-responsive">
              <table class="table table-sm mb-0">
                <thead>
                  <tr class="table-primary">
                    <th>Name</th><th>Mobile</th><th>Company</th><th>Package</th><th>Other Info</th>
                  </tr>
                </thead>
                <tbody>
                  <tr class="table-success"><td>John Doe</td><td>919876543210</td><td>Acme Ltd</td><td>2 Lacks </td><td>Interested</td></tr>
                  <tr class="table-warning"><td>(blank)</td><td>9876543210</td><td>(blank)</td><td>50000 Monthly</td><td>Called earlier</td></tr>
                  <tr class="table-info"><td>Jane Smith</td><td>919812345678</td><td>XYZ Pvt Ltd</td><td>12000</td><td>Request callback</td></tr>
                </tbody>
              </table>
            </div>
            <div class="mt-2 small text-muted">Notes: Name can be blank (will be stored as NULL). Mobile is required (Indian 10 digits). You may upload CSVs with or without header.</div>
          </div>
        </div>
      </div>
    </div>

    <!-- right column: summary & logs -->
    <div class="col-lg-5">
      <div class="card shadow-sm">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <div class="fw-semibold">Import Summary</div>
            <div>
              <?php if ($summary): ?>
                <span class="badge bg-<?= htmlspecialchars($summaryType ?: 'secondary') ?>"><?= strtoupper(htmlspecialchars($summaryType ?: 'INFO')) ?></span>
              <?php else: ?>
                <span class="small text-muted">No actions yet</span>
              <?php endif; ?>
            </div>
          </div>

          <?php if ($summary): ?>
            <div class="mb-3">
              <div class="alert alert-<?= htmlspecialchars($summaryType) ?> py-2 mb-0"><?= $summary ?></div>
            </div>
          <?php endif; ?>

          <div class="row g-2 mb-3">
            <div class="col-6">
              <div class="card text-bg-light mb-0">
                <div class="card-body p-2">
                  <div class="d-flex align-items-center">
                    <div class="flex-grow-1">
                      <div class="small text-muted">Rows processed</div>
                      <div class="h5 mb-0"><?= $stats['rows_processed'] ?></div>
                    </div>
                    <i class="bi bi-list-check fs-3 text-muted"></i>
                  </div>
                </div>
              </div>
            </div>
            <div class="col-6">
              <div class="card text-bg-success text-white mb-0">
                <div class="card-body p-2">
                  <div class="d-flex align-items-center">
                    <div class="flex-grow-1">
                      <div class="small">Inserted</div>
                      <div class="h5 mb-0"><?= $stats['inserted'] ?></div>
                    </div>
                    <i class="bi bi-check-circle fs-3"></i>
                  </div>
                </div>
              </div>
            </div>

            <div class="col-6">
              <div class="card text-bg-warning mb-0">
                <div class="card-body p-2">
                  <div class="d-flex align-items-center">
                    <div class="flex-grow-1">
                      <div class="small">Duplicates skipped</div>
                      <div class="h5 mb-0"><?= $stats['duplicates'] ?></div>
                    </div>
                    <i class="bi bi-x-octagon fs-3"></i>
                  </div>
                </div>
              </div>
            </div>

            <div class="col-6">
              <div class="card text-bg-danger text-white mb-0">
                <div class="card-body p-2">
                  <div class="d-flex align-items-center">
                    <div class="flex-grow-1">
                      <div class="small">Invalid mobiles</div>
                      <div class="h5 mb-0"><?= $stats['invalid'] ?></div>
                    </div>
                    <i class="bi bi-exclamation-triangle fs-3"></i>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- DRY RUN box -->
          <?php if (isset($stats['would_insert'])): ?>
            <div class="mb-3">
              <div class="card border-info">
                <div class="card-body p-2">
                  <div class="small text-muted">Dry-run preview</div>
                  <div class="d-flex align-items-center justify-content-between">
                    <div class="h6 mb-0">Would insert: <span class="badge bg-info"><?= $stats['would_insert'] ?></span></div>
                    <div class="h6 mb-0">Would skip: <span class="badge bg-secondary"><?= $stats['would_update'] ?></span></div>
                  </div>
                </div>
              </div>
            </div>
          <?php endif; ?>

          <!-- Detailed log accordion -->
          <div class="mb-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <div class="fw-semibold small mb-0">Detailed Log</div>
              <div class="small text-muted"><?= count($logs) ?> entries</div>
            </div>

            <?php if (empty($logs)): ?>
              <div class="alert alert-light small mb-0">No logs yet.</div>
            <?php else: ?>
              <?php
              $logPerPage = 20;
              $logPage = max(1, (int)($_GET['log_page'] ?? 1));
              $allLogs = array_reverse(array_slice($logs, -200));
              $totalLogPages = max(1, (int)ceil(count($allLogs) / $logPerPage));
              $pageLogs = array_slice($allLogs, ($logPage - 1) * $logPerPage, $logPerPage);
              ?>
              <div class="accordion" id="logsAccordion" style="max-height:45vh; overflow:auto;">
                <?php foreach ($pageLogs as $idx => $l): 
                    $lc = strtolower($l);
                    if (strpos($lc,'error')!==false || strpos($lc,'failed')!==false) { $sev='danger'; $label='ERR'; }
                    elseif (strpos($lc,'invalid')!==false || strpos($lc,'skip')!==false) { $sev='warning'; $label='WARN'; }
                    elseif (strpos($lc,'insert')!==false || strpos($lc,'success')!==false) { $sev='success'; $label='OK'; }
                    else { $sev='info'; $label='INFO'; }
                    $itemId = "logItem" . $idx;
                ?>
                  <div class="accordion-item">
                    <h2 class="accordion-header" id="heading<?= $itemId ?>">
                      <button class="accordion-button collapsed py-2" type="button" data-bs-toggle="collapse" data-bs-target="#collapse<?= $itemId ?>" aria-expanded="false" aria-controls="collapse<?= $itemId ?>">
                        <span class="badge bg-<?= $sev ?> me-2"><?= $label ?></span>
                        <small class="text-muted"><?= htmlspecialchars($l) ?></small>
                      </button>
                    </h2>
                    <div id="collapse<?= $itemId ?>" class="accordion-collapse collapse" aria-labelledby="heading<?= $itemId ?>" data-bs-parent="#logsAccordion">
                      <div class="accordion-body small">
                        <?= nl2br(htmlspecialchars($l)) ?>
                      </div>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
              <?php if ($totalLogPages > 1): ?>
              <div class="d-flex justify-content-between align-items-center mt-2">
                <small class="text-muted">Page <?= $logPage ?> of <?= $totalLogPages ?></small>
                <nav>
                  <ul class="pagination pagination-sm mb-0">
                    <?php for ($p = 1; $p <= $totalLogPages; $p++): ?>
                      <li class="page-item <?= $p == $logPage ? 'active' : '' ?>">
                        <a class="page-link" href="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['log_page' => $p]))) ?>"><?= $p ?></a>
                      </li>
                    <?php endfor; ?>
                  </ul>
                </nav>
              </div>
              <?php endif; ?>
            <?php endif; ?>
          </div>

          <hr>

          <div class="small text-muted">Archive/Transfer old rows from Maindatabase to New Database</div>
          <form method="post" class="mt-2" onsubmit="return confirm('Proceed to archive older rows? This will move and delete from main table.');">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            <div class="input-group input-group-sm mb-2">
                <label class="me-2 mb-0"> Enter Months </label>
              <input type="number" name="archive_months" class="form-control form-control-sm" min="1" value="12" aria-label="months">
              <button class="btn btn-outline-secondary" type="submit" name="archive_action" value="1">Archive</button>
            </div>
            <div class="small text-muted">Creates New `TBL_MAIN_ARCHIVE` if missing. Moves rows older than Selected months.</div>
          </form>
        </div>
      </div>
    </div>

    <div class="col-12 text-center mt-2 small text-muted">Make sure you have verified data before uploading.</div>
    
    <!-- Terms and Conditions Modal -->
<div class="modal fade" id="termsModal" tabindex="-1" aria-labelledby="termsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="termsModalLabel">Terms & Conditions for Uploading Confidential Data</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>By uploading data to this platform, you agree to the following terms and conditions:</p>
                <ol>
                    <li>
                        <strong>Responsibility of Data Upload</strong><br>
                        - I acknowledge that the data uploaded to this platform is done entirely at my own risk and responsibility.<br>
                        - I confirm that I am fully aware of the sensitive and confidential nature of customer data, including personal and financial details.
                    </li>
                    <li>
                        <strong>Customer Consent</strong><br>
                        - I confirm that all data being uploaded has been collected with explicit consent from the customers.<br>
                        - I understand that it is my duty to ensure that no data is uploaded without prior approval from the data owner.
                    </li>
                    <li>
                        <strong>Prohibition of Misuse</strong><br>
                        - I guarantee that the uploaded data will not be used in any unethical, unauthorized, or illegal manner.<br>
                        - I will take all necessary steps to prevent the data from being misused, mishandled, or disclosed to unauthorized parties.
                    </li>
                    <li>
                        <strong>Data Security and Protection</strong><br>
                        - I acknowledge that it is my responsibility to comply with all applicable laws and regulations related to the protection of confidential and personal data.<br>
                        - I agree to use secure methods to store, process, and manage the data to prevent unauthorized access, theft, or loss.
                    </li>
                    <li>
                        <strong>Non-Liability of Website and Developers</strong><br>
                        - I understand and accept that the website and its developers are not responsible for any data stored on this platform or any consequences resulting from its usage or misuse by me or others.<br>
                        - I agree that any misuse of data or legal implications arising from its handling are solely my responsibility.
                    </li>
                    <li>
                        <strong>Compliance with Data Protection Laws</strong><br>
                        - I confirm that the uploaded data complies with applicable data protection regulations, such as the General Data Protection Regulation (GDPR) or similar laws, if applicable.<br>
                        - I agree to follow best practices for ensuring data integrity, security, and lawful processing.
                    </li>
                    <li>
                        <strong>Accuracy of Uploaded Data</strong><br>
                        - I confirm that the data uploaded is accurate, relevant, and not misleading.<br>
                        - I accept that I am solely responsible for verifying the correctness and appropriateness of the data before uploading.
                    </li>
                    <li>
                        <strong>Retention and Deletion of Data</strong><br>
                        - I acknowledge that I am responsible for ensuring the timely removal of outdated or irrelevant data from the platform.<br>
                        - I agree to follow data retention policies and ensure that no data is stored beyond its required purpose.
                    </li>
                    <li>
                        <strong>Indemnification</strong><br>
                        - I agree to indemnify and hold the website and its developers harmless from any claims, damages, or liabilities arising from the data I upload, store, or share.
                    </li>
                    <li>
                        <strong>Acknowledgment of Risk</strong><br>
                        - I understand that while the platform may provide certain security measures, absolute protection of uploaded data cannot be guaranteed.<br>
                        - I accept full liability for any potential loss, breach, or unauthorized access to the uploaded data.
                    </li>
                </ol>
                <p>By agreeing to these terms and conditions, you confirm that you understand your responsibilities and the potential risks associated with uploading and managing confidential data. Violation of these terms may result in penalties, loss of access, or legal consequences.</p>
                <p><strong>Effective Date: 01 January, 2025</p></strong>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">I Agree</button>
            </div>
        </div>
    </div>
</div>
    
    
    
  </div>
</div>

<div class="modal fade" id="uploadProgressModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-cloud-upload me-2"></i>Uploading & Importing</h5>
                <button type="button" class="btn-close" id="uploadModalCloseBtn" data-bs-dismiss="modal" aria-label="Close" disabled></button>
            </div>
            <div class="modal-body">
                <div class="d-flex justify-content-between mb-1">
                    <span class="small text-muted">Upload Progress</span>
                    <span id="uploadPercent" class="small fw-semibold">0%</span>
                </div>
                <div class="progress mb-3" style="height: 20px;">
                    <div id="uploadProgressBar" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%"></div>
                </div>
                <p class="mb-2 small text-muted" id="uploadMessage">Uploading file...</p>
                <div id="uploadResult" class="mt-3 alert" style="display:none;"></div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="notificationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content" style="border-radius:0.75rem;border:1px solid #e2e4f0;">
            <div class="modal-body text-center py-4">
                <div id="notifIcon" class="mb-2" style="font-size:2.5rem;"></div>
                <h5 id="notifTitle" class="fw-bold mb-1"></h5>
                <p id="notifMessage" class="text-muted small mb-3"></p>
                <button type="button" class="btn btn-primary btn-sm px-4" data-bs-dismiss="modal" style="border-radius:0.5rem;">OK</button>
            </div>
        </div>
    </div>
</div>

<?php include '../../php_scripts/footer.php'; ?>

<script>
/* Core elements */
const dropZone = document.getElementById('dropZone');
const dropInfoContent = document.getElementById('dropInfoContent');
const fileInput = document.getElementById('fileInput');
const uploadBtn = document.getElementById('uploadBtn');
const previewArea = document.getElementById('previewArea');
const previewBody = document.getElementById('previewBody');
const previewMeta = document.getElementById('previewMeta');
const importHidden = document.getElementById('importHidden');
const hasHeaderCheckbox = document.getElementById('hasHeader');
const downloadExampleBtn = document.getElementById('downloadExampleBtn');

const terms = document.getElementById('exampleCheck1');

/* click opens file dialog */
dropZone.addEventListener('click', ()=> fileInput.click());

/* handle file selection -- fileInput stays in DOM */
fileInput.addEventListener('change', ()=> {
  if (!fileInput.files.length) return;
  const f = fileInput.files[0];
  if (f.size > 10*1024*1024) { showNotification('Error', 'File > 10MB not allowed', 'danger'); fileInput.value=''; return; }
  if (!f.name.toLowerCase().endsWith('.csv')) { showNotification('Error', 'Only CSV allowed', 'danger'); fileInput.value=''; return; }
  setDropInfo(f);
  handlePreview(f);
  uploadBtn.disabled = false;
});

/* drag/drop */
['dragenter','dragover'].forEach(ev => dropZone.addEventListener(ev, e => { e.preventDefault(); dropZone.classList.add('dragover'); }));
['dragleave','drop'].forEach(ev => dropZone.addEventListener(ev, e => { e.preventDefault(); dropZone.classList.remove('dragover'); }));
dropZone.addEventListener('drop', ev => {
  if (ev.dataTransfer.files.length) {
    fileInput.files = ev.dataTransfer.files;
    const f = fileInput.files[0];
    setDropInfo(f);
    handlePreview(f);
    uploadBtn.disabled = false;
  }
});

/* update only display area — do NOT touch the file input element */
function setDropInfo(file) {
  dropInfoContent.innerHTML = `
    <div class="text-center">
      <i class="bi bi-file-earmark-spreadsheet" style="font-size:2rem;color:#0f172a"></i>
      <div class="mt-1"><strong>${escapeHtml(file.name)}</strong></div>
      <div class="small text-muted">${(file.size/1024/1024).toFixed(2)} MB</div>
    </div>`;
}

/* preview reader — respects hasHeader toggle */
function handlePreview(file) {
  const reader = new FileReader();
  reader.onload = function(e) {
    const text = e.target.result;
    // split lines, keep empty ones to preserve rows but filter trailing blanks
    const rows = text.split(/\r\n|\n/).filter((r,i,a)=> {
      // keep non-empty OR first row (if headerless) so preview not empty
      return r.trim() !== '';
    });
    if (rows.length === 0) {
      previewBody.innerHTML = '<tr><td colspan="8" class="text-center small text-muted">No data rows found</td></tr>';
      previewArea.style.display='block';
      previewMeta.textContent = '';
      return;
    }

    let headerRow = [];
    let dataRows = [];

    if (hasHeaderCheckbox.checked) {
      headerRow = rows[0].split(',');
      dataRows = rows.slice(1, Math.min(rows.length, 11)).map(r => r.split(',').map(c=>c.trim()));
    } else {
      // headerless: show positional headers as Name,Mobile,Company,Package,Other
      headerRow = ['Name','Mobile','Company','Package','Other Info'];
      dataRows = rows.slice(0, Math.min(rows.length, 10)).map(r => r.split(',').map(c=>c.trim()));
    }

    previewBody.innerHTML = '';
    let invalidCount = 0;
    for (let i=0;i<dataRows.length;i++) {
      const r = dataRows[i];
      const name = r[0]||'';
      const raw = r[1]||'';
      const digits = (raw.match(/\d/g)||[]).join('');
      const norm = digits.length>10 ? digits.slice(-10) : digits;
      const valid = /^[6-9]\d{9}$/.test(norm);
      if (!valid) invalidCount++;
      const tr = document.createElement('tr');
      if (!valid) tr.classList.add('table-danger');
      tr.innerHTML = `<td>${i+1}</td><td>${escapeHtml(name)}</td><td>${escapeHtml(raw)}</td><td>${escapeHtml(norm)}</td><td>${valid?'<span class="badge bg-success">Valid</span>':'<span class="badge bg-danger">Invalid</span>'}</td>
                      <td>${escapeHtml(r[2]||'')}</td><td>${escapeHtml(r[3]||'')}</td><td>${escapeHtml(r[4]||'')}</td>`;
      previewBody.appendChild(tr);
    }
    previewArea.style.display='block';
    previewMeta.textContent = `${dataRows.length} preview rows (showing up to 10). Invalid mobiles: ${invalidCount}. Header detected: ${headerRow.join(', ')}`;
  };
  reader.readAsText(file);
}

function escapeHtml(s){ if(!s) return ''; return s.replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;'); }

/* Download example CSV */
downloadExampleBtn.addEventListener('click', function(e){
  e.preventDefault();
  const example = [
    ['Name','Mobile','Company','Package','Other Info'],
    ['John Doe','919876543210','Acme Ltd','Gold','Interested'],
    ['','9876543210','','Basic','Called earlier'],
    ['Jane Smith','919812345678','XYZ Pvt Ltd','Silver','Request callback']
  ];
  const csv = example.map(r => r.map(cell => {
    // Escape cells that contain comma or quote
    if (typeof cell === 'string' && (cell.includes(',') || cell.includes('"'))) {
      return '"' + cell.replaceAll('"','""') + '"';
    }
    return cell;
  }).join(',')).join('\r\n');

  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = 'customer_example.csv';
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(url);
});

function showNotification(title, message, type = 'success') {
    const iconMap = {
        success: '<i class="bi bi-check-circle-fill text-success"></i>',
        danger: '<i class="bi bi-x-circle-fill text-danger"></i>',
        warning: '<i class="bi bi-exclamation-triangle-fill text-warning"></i>',
        info: '<i class="bi bi-info-circle-fill text-info"></i>'
    };
    const icon = iconMap[type] || iconMap.info;
    $('#notifIcon').html(icon);
    $('#notifTitle').text(title);
    $('#notifMessage').text(message || '');
    new bootstrap.Modal(document.getElementById('notificationModal')).show();
}

$(document).on('click', '.close-modal-btn', function() {
    bootstrap.Modal.getInstance(document.getElementById('uploadProgressModal'))?.hide();
});

function updateUploadProgress(percent, message) {
    $('#uploadPercent').text(percent + '%');
    $('#uploadProgressBar').css('width', percent + '%');
    if (message) $('#uploadMessage').text(message);
}

function setModalClosable(closable) {
    const btn = document.getElementById('uploadModalCloseBtn');
    if (!btn) return;
    btn.disabled = !closable;
    if (closable) {
        btn.removeAttribute('disabled');
    } else {
        btn.setAttribute('disabled', 'disabled');
    }
}

document.getElementById('uploadForm').addEventListener('submit', function(e){
    importHidden.value = '1';
    if (!fileInput.files || fileInput.files.length === 0) {
        e.preventDefault();
        showNotification('Error', 'Please select a CSV file before importing.', 'danger');
        return false;
    }
    if (!terms.checked) {
        e.preventDefault();
        showNotification('Error', 'Please agree to the Terms & Conditions before uploading.', 'danger');
        return false;
    }
    if (hasHeaderCheckbox.checked) {
        hasHeaderCheckbox.value = '1';
    } else {
        hasHeaderCheckbox.value = '0';
    }

    e.preventDefault();

    const modal = new bootstrap.Modal(document.getElementById('uploadProgressModal'), { backdrop: 'static', keyboard: false });
    const progressBar = $('#uploadProgressBar');
    const percentText = $('#uploadPercent');
    const messageText = $('#uploadMessage');
    const resultDiv = $('#uploadResult');

    modal.show();
    setModalClosable(false);
    progressBar.css('width', '0%').removeClass('bg-success bg-danger').addClass('bg-primary');
    percentText.text('0%');
    messageText.text('Uploading file...');
    resultDiv.hide();

    const formData = new FormData(this);
    formData.set('import', '1');
    formData.set('ajax_upload', '1');

    const xhr = new XMLHttpRequest();
    xhr.upload.addEventListener('progress', function(evt) {
        if (evt.lengthComputable) {
            const percent = Math.round((evt.loaded / evt.total) * 100);
            updateUploadProgress(percent, 'Uploading file...');
        }
    });

    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            if (xhr.status === 200) {
                try {
                    const resp = JSON.parse(xhr.responseText);
                    if (resp.success && resp.task_id) {
                        messageText.text('Processing data...');
                        processUploadBatch(resp.task_id);
                    } else {
                        throw new Error(resp.error || 'Upload failed');
                    }
                } catch (err) {
                    progressBar.addClass('bg-danger');
                    messageText.text('Upload failed');
                    resultDiv.show().addClass('alert-danger').html('<strong>Error:</strong> ' + (err.message || 'Failed to start upload.') + '<br><button class="btn btn-sm btn-outline-danger mt-2 close-modal-btn">Close</button>');
                    setModalClosable(true);
                    showNotification('Error', err.message || 'Failed to start upload', 'danger');
                }
            } else {
                progressBar.addClass('bg-danger');
                messageText.text('Server error');
                resultDiv.show().addClass('alert-danger').html('<strong>Error:</strong> Server error (HTTP ' + xhr.status + ').<br><button class="btn btn-sm btn-outline-danger mt-2 close-modal-btn">Close</button>');
                setModalClosable(true);
                showNotification('Error', 'Server error during upload', 'danger');
            }
        }
    };

    xhr.onerror = function() {
        progressBar.addClass('bg-danger');
        messageText.text('Network error');
        resultDiv.show().addClass('alert-danger').html('<strong>Error:</strong> Network error during upload.<br><button class="btn btn-sm btn-outline-danger mt-2 close-modal-btn">Close</button>');
        setModalClosable(true);
        showNotification('Error', 'Network error during upload', 'danger');
    };

    xhr.open('POST', location.href, true);
    xhr.send(formData);
});

function processUploadBatch(task_id) {
    const progressBar = $('#uploadProgressBar');
    const percentText = $('#uploadPercent');
    const messageText = $('#uploadMessage');
    const resultDiv = $('#uploadResult');

    const formData = new FormData();
    formData.set('process_upload_batch', '1');
    formData.set('task_id', task_id);

    $.ajax({
        type: 'POST',
        url: location.href,
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(resp) {
            if (resp.status === 'complete') {
                progressBar.css('width', '100%').removeClass('bg-primary bg-danger').addClass('bg-success');
                percentText.text('100%');
                messageText.text('Upload complete!');
                resultDiv.show().addClass('alert-success').html('<strong>Success!</strong> ' + (resp.message || 'Import completed') + '<br><button class="btn btn-sm btn-outline-success mt-2 close-modal-btn">Close</button>');
                setModalClosable(true);
                showNotification('Upload Complete', resp.message || 'Import completed', 'success');
            } else if (resp.status === 'error') {
                progressBar.addClass('bg-danger');
                messageText.text('Upload failed');
                resultDiv.show().addClass('alert-danger').html('<strong>Error:</strong> ' + (resp.message || resp.error || 'Unknown error') + '<br><button class="btn btn-sm btn-outline-danger mt-2 close-modal-btn">Close</button>');
                setModalClosable(true);
                showNotification('Error', resp.message || resp.error || 'Unknown error', 'danger');
            } else {
                progressBar.css('width', resp.percent + '%').removeClass('bg-success bg-danger').addClass('bg-primary');
                percentText.text(resp.percent + '%');
                messageText.text(resp.message || 'Processing...');
                setTimeout(function() { processUploadBatch(task_id); }, 400);
            }
        },
        error: function() {
            progressBar.addClass('bg-danger');
            messageText.text('Network error');
            resultDiv.show().addClass('alert-danger').html('<strong>Error:</strong> Network error while checking progress.<br><button class="btn btn-sm btn-outline-danger mt-2 close-modal-btn">Close</button>');
            setModalClosable(true);
            showNotification('Error', 'Network error while checking progress', 'danger');
        }
    });
}
</script>





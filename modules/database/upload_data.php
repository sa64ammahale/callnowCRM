<?php
require_once __DIR__ . '/../../php_scripts/auth.php';
require_once __DIR__ . '/../../php_scripts/team_auth.php';
require_once __DIR__ . '/../../php_scripts/super_admin.php';

requirePermission('upload_data');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file']) && empty($_POST['import'])) {
    $_POST['import'] = '1';
}

/* ensure uploads dir */
if (!is_dir(__DIR__ . '/uploads')) mkdir(__DIR__ . '/uploads', 0755, true);

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
    $path = __DIR__ . '/uploads/' . $safe;
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
        $first = fgetcsv($handle, 20000, ",");
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
        while (fgetcsv($handle, 20000, ",") !== false) $estimatedRows++;
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
        'user_id' => USER_ID,
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
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        api_error('Invalid session token', 403);
    }
    $task_id = $_POST['task_id'];
    $progress_file = sys_get_temp_dir() . '/callnow_upload_' . md5($task_id) . '.json';
    if (!file_exists($progress_file)) {
        echo json_encode(['success' => false, 'error' => 'Task not found']);
        exit;
    }

    $meta = json_decode(file_get_contents($progress_file), true);
    if ((int)($meta['user_id'] ?? 0) !== USER_ID) api_error('Upload task access denied', 403);
    if (($meta['status'] ?? '') === 'complete' || ($meta['status'] ?? '') === 'error') {
        echo json_encode(['status' => $meta['status'], 'percent' => $meta['percent'], 'result' => $meta]);
        exit;
    }

    $path = $meta['path'];
    $target = $meta['target'];
    $dryrun = (bool)($meta['dryrun'] ?? 0);
    $has_header = (int)($meta['has_header'] ?? 1) === 1;
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
        fgetcsv($handle, 20000, ",");
        $meta['message'] = 'Importing data...';
    }

    for ($i = 0; $i < $meta['processed']; $i++) {
        fgetcsv($handle, 20000, ",");
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

    $batch_size = 500;
    $rows_processed = 0;

    while (($row = fgetcsv($handle, 20000, ",")) !== false && $rows_processed < $batch_size) {
        $stats['rows_processed']++;
        $rownum = $stats['rows_processed'];

        $name_raw   = (isset($header_index_map['name']) && $header_index_map['name'] !== null && isset($row[$header_index_map['name']])) ? $row[$header_index_map['name']] : '';
        $mobile_raw = (isset($header_index_map['mobile']) && $header_index_map['mobile'] !== null && isset($row[$header_index_map['mobile']])) ? $row[$header_index_map['mobile']] : ($row[1] ?? '');
        $company_raw = (isset($header_index_map['company']) && $header_index_map['company'] !== null && isset($row[$header_index_map['company']])) ? $row[$header_index_map['company']] : '';
        $package_raw = (isset($header_index_map['package']) && $header_index_map['package'] !== null && isset($row[$header_index_map['package']])) ? $row[$header_index_map['package']] : '';
        $other_raw   = (isset($header_index_map['other info']) && $header_index_map['other info'] !== null && isset($row[$header_index_map['other info']])) ? $row[$header_index_map['other info']] : '';

        $name_raw = trim($name_raw);
        $mobile_raw = trim($mobile_raw);
        $company_raw = trim($company_raw);
        $package_raw = trim($package_raw);
        $other_raw = trim($other_raw);

        $digits = normalize_indian_phone($mobile_raw);

        if ($digits === null) {
            $stats['invalid']++;
            $logs[] = "Row $rownum: Invalid mobile '$mobile_raw'. Must be a valid 10-digit Indian mobile number (e.g., 9876543210, +91 98765 43210). Skipped.";
            $rows_processed++;
            continue;
        }

        $dndCheck = mysqli_prepare($link, "SELECT id FROM " . tn('TBL_DND_REGISTRY') . " WHERE phone_number = ? LIMIT 1");
        $isDndNum = false;
        if ($dndCheck) {
            mysqli_stmt_bind_param($dndCheck, "s", $digits);
            mysqli_stmt_execute($dndCheck);
            mysqli_stmt_store_result($dndCheck);
            $isDndNum = (mysqli_stmt_num_rows($dndCheck) > 0);
            mysqli_stmt_close($dndCheck);
        }
        if ($isDndNum) {
            $stats['dnd_skipped'] = ($stats['dnd_skipped'] ?? 0) + 1;
            $logs[] = "Row $rownum: Mobile '$digits' is registered on Do-Not-Disturb (DND). Discarded from import.";
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
    if (count($logs) > 100) {
        $logs = array_slice($logs, -100);
    }
    $meta['logs'] = $logs;

    if ($meta['total'] > 0) {
        $meta['percent'] = min(100, (int) round($meta['processed'] / $meta['total'] * 100));
    } else {
        $meta['percent'] = 100;
    }

    $has_more = false;
    $test_handle = fopen($path, 'r');
    if ($test_handle) {
        if ($has_header) fgetcsv($test_handle, 20000, ",");
        for ($i = 0; $i < $meta['processed']; $i++) fgetcsv($test_handle, 20000, ",");
        $peek = fgetcsv($test_handle, 20000, ",");
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
    if ((int)($meta['user_id'] ?? 0) !== USER_ID) api_error('Upload task access denied', 403);
    unset($meta['path']);
    echo json_encode($meta);
    exit;
}
?>
<?php 
$pageTitle = 'Upload CSV • CallNow CRM'; 
include __DIR__ . '/../../php_scripts/header.php';
?>


<style>
/* ============ Upload Customer Database — Page-Scoped Styles ============ */
.upload-csv-page {
  --uc-primary: #6D42F5;
  --uc-primary-hover: #5B32D6;
  --uc-primary-light: #F3EEFF;
  --uc-primary-border: #DDD2FF;
  --uc-blue: #3B82F6;
  --uc-blue-light: #EFF6FF;
  --uc-blue-border: #BFDBFE;
  --uc-success: #16A34A;
  --uc-success-light: #ECFDF3;
  --uc-success-border: #BBF7D0;
  --uc-warning: #F59E0B;
  --uc-warning-light: #FFFBEB;
  --uc-warning-border: #FDE68A;
  --uc-danger: #E11D48;
  --uc-danger-light: #FFF1F2;
  --uc-danger-border: #FECDD3;
  --uc-text-main: #172033;
  --uc-text-muted: #64748B;
  --uc-card-bg: #FFFFFF;
  --uc-border: #E2E8F0;
  --uc-shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.05);
  --uc-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.06), 0 2px 4px -2px rgba(0, 0, 0, 0.04);
  --uc-shadow-md: 0 10px 15px -3px rgba(109, 66, 245, 0.08), 0 4px 6px -4px rgba(0, 0, 0, 0.03);
  --uc-radius: 12px;
  --uc-radius-lg: 16px;
  --uc-radius-sm: 8px;
  color: var(--uc-text-main);
  padding: 0.5rem 0.25rem;
}

/* Page Header */
.upload-csv-page .uc-page-header {
  background: #FFFFFF;
  border: 1px solid var(--uc-border);
  border-radius: var(--uc-radius-lg);
  padding: 1.25rem 1.5rem;
  box-shadow: var(--uc-shadow-sm);
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1.25rem;
  flex-wrap: wrap;
  margin-bottom: 1.25rem;
}
.upload-csv-page .uc-header-icon-box {
  width: 48px;
  height: 48px;
  border-radius: 12px;
  background: var(--uc-primary-light);
  border: 1px solid var(--uc-primary-border);
  color: var(--uc-primary);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.45rem;
  flex-shrink: 0;
}
.upload-csv-page .uc-page-title {
  font-size: 1.25rem;
  font-weight: 700;
  color: var(--uc-text-main);
  margin-bottom: 0.15rem;
  letter-spacing: -0.01em;
}
.upload-csv-page .uc-page-subtitle {
  font-size: 0.8125rem;
  color: var(--uc-text-muted);
}
.upload-csv-page .breadcrumb {
  margin-bottom: 0.35rem;
}
.upload-csv-page .breadcrumb-item a {
  color: var(--uc-primary);
  text-decoration: none;
}
.upload-csv-page .breadcrumb-item a:hover {
  text-decoration: underline;
}
.upload-csv-page .breadcrumb-item.active {
  color: var(--uc-text-muted);
}

/* Header Buttons */
.upload-csv-page .uc-btn-ghost {
  background: #FFFFFF;
  border: 1px solid var(--uc-border);
  color: #475569;
  font-size: 0.8125rem;
  font-weight: 500;
  padding: 0.45rem 0.85rem;
  border-radius: 8px;
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  transition: all 0.15s ease;
  text-decoration: none;
  cursor: pointer;
}
.upload-csv-page .uc-btn-ghost:hover {
  background: #F8FAFC;
  border-color: #CBD5E1;
  color: #1E293B;
  transform: translateY(-1px);
}
.upload-csv-page .uc-btn-soft-primary {
  background: var(--uc-primary-light);
  border: 1px solid var(--uc-primary-border);
  color: var(--uc-primary);
  font-size: 0.8125rem;
  font-weight: 500;
  padding: 0.45rem 0.85rem;
  border-radius: 8px;
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  transition: all 0.15s ease;
  text-decoration: none;
  cursor: pointer;
}
.upload-csv-page .uc-btn-soft-primary:hover {
  background: #EBE3FF;
  border-color: #C8B8FF;
  color: var(--uc-primary-hover);
  transform: translateY(-1px);
}

/* Stepper Card */
.upload-csv-page .uc-stepper-card {
  background: #FFFFFF;
  border: 1px solid var(--uc-border);
  border-radius: var(--uc-radius);
  padding: 1rem 1.5rem;
  box-shadow: var(--uc-shadow-sm);
  margin-bottom: 1.25rem;
}
.upload-csv-page .uc-stepper {
  display: flex;
  align-items: center;
  justify-content: space-between;
  position: relative;
  width: 100%;
}
.upload-csv-page .uc-step {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  z-index: 2;
  background: #FFFFFF;
  padding: 0 0.5rem;
}
.upload-csv-page .uc-step-circle {
  width: 32px;
  height: 32px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 600;
  font-size: 0.8125rem;
  transition: all 0.25s ease;
  flex-shrink: 0;
}
.upload-csv-page .uc-step-text {
  display: flex;
  flex-direction: column;
}
.upload-csv-page .uc-step-label {
  font-size: 0.65rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  font-weight: 600;
  color: #94A3B8;
  line-height: 1;
  margin-bottom: 0.2rem;
}
.upload-csv-page .uc-step-title {
  font-size: 0.8125rem;
  font-weight: 600;
  color: var(--uc-text-main);
  line-height: 1.2;
}

/* Step States */
.upload-csv-page .uc-step.step-upcoming .uc-step-circle {
  background: #F1F5F9;
  color: #94A3B8;
  border: 1.5px solid #E2E8F0;
}
.upload-csv-page .uc-step.step-upcoming .uc-step-circle .uc-step-check { display: none; }
.upload-csv-page .uc-step.step-upcoming .uc-step-title { color: #94A3B8; }

.upload-csv-page .uc-step.step-active .uc-step-circle {
  background: var(--uc-primary);
  color: #FFFFFF;
  border: 1.5px solid var(--uc-primary);
  box-shadow: 0 0 0 4px var(--uc-primary-light);
}
.upload-csv-page .uc-step.step-active .uc-step-circle .uc-step-check { display: none; }
.upload-csv-page .uc-step.step-active .uc-step-title { color: var(--uc-primary); }

.upload-csv-page .uc-step.step-completed .uc-step-circle {
  background: var(--uc-success);
  color: #FFFFFF;
  border: 1.5px solid var(--uc-success);
}
.upload-csv-page .uc-step.step-completed .uc-step-circle .uc-step-num { display: none; }
.upload-csv-page .uc-step.step-completed .uc-step-circle .uc-step-check { display: inline-block; font-size: 0.95rem; }
.upload-csv-page .uc-step.step-completed .uc-step-title { color: var(--uc-text-main); }

.upload-csv-page .uc-step-connector {
  flex-grow: 1;
  height: 2px;
  background: #E2E8F0;
  margin: 0 0.5rem;
  transition: all 0.3s ease;
}
.upload-csv-page .uc-step-connector.conn-completed {
  background: var(--uc-success);
}
.upload-csv-page .uc-step-connector.conn-active {
  background: var(--uc-primary);
}

/* Common Card */
.upload-csv-page .uc-card {
  background: var(--uc-card-bg);
  border: 1px solid var(--uc-border);
  border-radius: var(--uc-radius);
  box-shadow: var(--uc-shadow-sm);
  overflow: hidden;
  margin-bottom: 1.25rem;
  transition: box-shadow 0.2s ease;
}
.upload-csv-page .uc-card:hover {
  box-shadow: var(--uc-shadow);
}
.upload-csv-page .uc-card-header {
  padding: 0.95rem 1.25rem;
  background: #FFFFFF;
  border-bottom: 1px solid #F1F5F9;
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.upload-csv-page .uc-card-title {
  font-size: 0.9375rem;
  font-weight: 600;
  color: var(--uc-text-main);
  display: flex;
  align-items: center;
  gap: 0.55rem;
  margin-bottom: 0;
}
.upload-csv-page .uc-header-badge-icon {
  width: 28px;
  height: 28px;
  border-radius: 8px;
  background: var(--uc-primary-light);
  color: var(--uc-primary);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 0.95rem;
}
.upload-csv-page .uc-card-body {
  padding: 1.25rem;
}

/* Target Database Grid */
.upload-csv-page .uc-target-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 0.85rem;
}
.upload-csv-page .uc-target-card {
  border: 1.5px solid var(--uc-border);
  border-radius: 10px;
  padding: 0.9rem;
  background: #FFFFFF;
  cursor: pointer;
  transition: all 0.2s ease;
  position: relative;
  user-select: none;
}
.upload-csv-page .uc-target-card:hover {
  border-color: var(--uc-primary-border);
  background: #FAF9FF;
  transform: translateY(-1px);
}
.upload-csv-page .uc-target-card.selected {
  border-color: var(--uc-primary);
  background: var(--uc-primary-light);
  box-shadow: 0 0 0 1px var(--uc-primary);
}
.upload-csv-page .uc-target-icon {
  width: 36px;
  height: 36px;
  border-radius: 8px;
  background: #F8FAFC;
  border: 1px solid #E2E8F0;
  color: #475569;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.1rem;
  transition: all 0.2s ease;
}
.upload-csv-page .uc-target-card.selected .uc-target-icon {
  background: #FFFFFF;
  border-color: var(--uc-primary-border);
  color: var(--uc-primary);
}
.upload-csv-page .uc-target-title {
  font-size: 0.875rem;
  font-weight: 600;
  color: var(--uc-text-main);
  margin-top: 0.5rem;
  margin-bottom: 0.2rem;
}
.upload-csv-page .uc-target-card.selected .uc-target-title {
  color: var(--uc-primary);
}
.upload-csv-page .uc-target-desc {
  font-size: 0.725rem;
  color: var(--uc-text-muted);
  line-height: 1.3;
}
.upload-csv-page .uc-badge-recommended {
  background: var(--uc-primary);
  color: #FFFFFF;
  font-size: 0.65rem;
  font-weight: 600;
  padding: 0.15rem 0.5rem;
  border-radius: 999px;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

/* Options Bar */
.upload-csv-page .uc-options-bar {
  background: #F8FAFC;
  border: 1px solid var(--uc-border);
}
.upload-csv-page .form-check-input:checked {
  background-color: var(--uc-primary);
  border-color: var(--uc-primary);
}
.upload-csv-page .form-check-input:focus {
  border-color: var(--uc-primary-border);
  box-shadow: 0 0 0 0.2rem rgba(109, 66, 245, 0.15);
}

/* Drop Zone */
.upload-csv-page .uc-dropzone {
  border: 2px dashed var(--uc-primary-border);
  border-radius: var(--uc-radius);
  padding: 2.25rem 1.5rem;
  text-align: center;
  background: linear-gradient(180deg, #FFFFFF 0%, #FAF8FF 100%);
  cursor: pointer;
  transition: all 0.2s ease;
  outline: none;
}
.upload-csv-page .uc-dropzone:hover,
.upload-csv-page .uc-dropzone.dragover {
  border-color: var(--uc-primary);
  background: var(--uc-primary-light);
  transform: translateY(-2px);
  box-shadow: var(--uc-shadow);
}
.upload-csv-page .uc-dropzone-icon {
  width: 52px;
  height: 52px;
  border-radius: 50%;
  background: var(--uc-primary-light);
  border: 1px solid var(--uc-primary-border);
  color: var(--uc-primary);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.6rem;
  margin: 0 auto 0.75rem auto;
}
.upload-csv-page .uc-dropzone-title {
  font-size: 0.95rem;
  font-weight: 600;
  color: var(--uc-text-main);
  margin-bottom: 0.25rem;
}
.upload-csv-page .uc-dropzone-sub {
  font-size: 0.775rem;
  color: var(--uc-text-muted);
}
.upload-csv-page .uc-pill-badge {
  display: inline-flex;
  align-items: center;
  padding: 0.25rem 0.65rem;
  border-radius: 999px;
  font-size: 0.725rem;
  font-weight: 500;
  background: #FFFFFF;
  border: 1px solid #E2E8F0;
  color: #475569;
}

/* File Selected State Card */
.upload-csv-page .uc-file-selected-card {
  background: var(--uc-success-light);
  border: 1.5px solid var(--uc-success-border);
  border-radius: var(--uc-radius);
  padding: 1rem 1.25rem;
}
.upload-csv-page .uc-file-icon {
  width: 42px;
  height: 42px;
  border-radius: 10px;
  background: #DCFCE7;
  color: var(--uc-success);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.5rem;
  flex-shrink: 0;
}

/* Consent Box */
.upload-csv-page .uc-consent-box {
  background: #F8FAFC;
  border: 1px solid var(--uc-border);
  border-radius: 8px;
}
.upload-csv-page .uc-link-primary {
  color: var(--uc-primary);
  text-decoration: underline;
}
.upload-csv-page .uc-link-primary:hover {
  color: var(--uc-primary-hover);
}

/* Primary Action Button */
.upload-csv-page .uc-btn-import {
  background: linear-gradient(135deg, #6D42F5 0%, #7E57C2 100%);
  color: #FFFFFF;
  border: none;
  border-radius: 9px;
  font-weight: 600;
  font-size: 0.875rem;
  padding: 0.65rem 1.6rem;
  box-shadow: 0 4px 12px rgba(109, 66, 245, 0.25);
  display: inline-flex;
  align-items: center;
  transition: all 0.18s ease;
  cursor: pointer;
}
.upload-csv-page .uc-btn-import:hover:not(:disabled) {
  background: linear-gradient(135deg, #5B32D6 0%, #6A45B8 100%);
  color: #FFFFFF;
  transform: translateY(-1px);
  box-shadow: 0 6px 16px rgba(109, 66, 245, 0.32);
}
.upload-csv-page .uc-btn-import:disabled {
  opacity: 0.55;
  cursor: not-allowed;
  box-shadow: none;
}

/* Metric Cards */
.upload-csv-page .uc-metric-card {
  border-radius: 10px;
  padding: 0.85rem 1rem;
  position: relative;
  transition: all 0.18s ease;
}
.upload-csv-page .uc-metric-card:hover {
  transform: translateY(-1px);
}
.upload-csv-page .metric-processed {
  background: var(--uc-blue-light);
  border: 1px solid var(--uc-blue-border);
}
.upload-csv-page .metric-processed .uc-metric-label { color: var(--uc-blue); }
.upload-csv-page .metric-processed .uc-metric-icon { color: var(--uc-blue); background: rgba(59, 130, 246, 0.12); }
.upload-csv-page .metric-processed .uc-metric-value { color: #1E3A8A; }
.upload-csv-page .metric-processed .uc-metric-sub { color: #60A5FA; }

.upload-csv-page .metric-inserted {
  background: var(--uc-success-light);
  border: 1px solid var(--uc-success-border);
}
.upload-csv-page .metric-inserted .uc-metric-label { color: var(--uc-success); }
.upload-csv-page .metric-inserted .uc-metric-icon { color: var(--uc-success); background: rgba(22, 163, 74, 0.12); }
.upload-csv-page .metric-inserted .uc-metric-value { color: #14532D; }
.upload-csv-page .metric-inserted .uc-metric-sub { color: #4ADE80; }

.upload-csv-page .metric-duplicates {
  background: var(--uc-warning-light);
  border: 1px solid var(--uc-warning-border);
}
.upload-csv-page .metric-duplicates .uc-metric-label { color: #D97706; }
.upload-csv-page .metric-duplicates .uc-metric-icon { color: #D97706; background: rgba(245, 158, 11, 0.12); }
.upload-csv-page .metric-duplicates .uc-metric-value { color: #78350F; }
.upload-csv-page .metric-duplicates .uc-metric-sub { color: #FBBF24; }

.upload-csv-page .metric-invalid {
  background: var(--uc-danger-light);
  border: 1px solid var(--uc-danger-border);
}
.upload-csv-page .metric-invalid .uc-metric-label { color: var(--uc-danger); }
.upload-csv-page .metric-invalid .uc-metric-icon { color: var(--uc-danger); background: rgba(225, 29, 72, 0.12); }
.upload-csv-page .metric-invalid .uc-metric-value { color: #881337; }
.upload-csv-page .metric-invalid .uc-metric-sub { color: #F87171; }

.upload-csv-page .uc-metric-label {
  font-size: 0.6875rem;
  font-weight: 700;
  letter-spacing: 0.05em;
  text-transform: uppercase;
}
.upload-csv-page .uc-metric-icon {
  width: 28px;
  height: 28px;
  border-radius: 6px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 0.9rem;
}
.upload-csv-page .uc-metric-value {
  font-size: 1.55rem;
  font-weight: 800;
  line-height: 1.2;
}
.upload-csv-page .uc-metric-sub {
  font-size: 0.725rem;
  margin-top: 0.2rem;
}

/* Live Progress Box in Summary */
.upload-csv-page .uc-live-progress-box {
  background: #F8FAFC;
  border: 1px solid var(--uc-border);
}
.upload-csv-page .uc-progress {
  background: #E2E8F0;
  border-radius: 999px;
  overflow: hidden;
}
.upload-csv-page .uc-progress .progress-bar {
  background: linear-gradient(90deg, #6D42F5 0%, #8C72FF 100%);
}

/* Preview Table */
.upload-csv-page .uc-preview-table-wrapper {
  max-height: 380px;
  overflow-y: auto;
}
.upload-csv-page .uc-table {
  font-size: 0.785rem;
  margin-bottom: 0;
}
.upload-csv-page .uc-table thead th {
  background: #F8FAFC;
  color: #475569;
  font-weight: 600;
  text-transform: uppercase;
  font-size: 0.7rem;
  letter-spacing: 0.03em;
  border-bottom: 1px solid var(--uc-border);
  padding: 0.65rem 0.75rem;
  position: sticky;
  top: 0;
  z-index: 10;
}
.upload-csv-page .uc-table tbody td {
  padding: 0.55rem 0.75rem;
  vertical-align: middle;
  border-bottom: 1px solid #F1F5F9;
}
.upload-csv-page .uc-table tbody tr:hover td {
  background: #FAF8FF;
}

/* Activity Logs List */
.upload-csv-page .uc-log-item {
  border-bottom: 1px solid #F1F5F9;
  padding: 0.5rem 0;
}
.upload-csv-page .uc-log-item:last-child {
  border-bottom: none;
}
.upload-csv-page .badge-danger {
  background-color: var(--uc-danger-light);
  color: var(--uc-danger);
  border: 1px solid var(--uc-danger-border);
}
.upload-csv-page .badge-warning {
  background-color: var(--uc-warning-light);
  color: #D97706;
  border: 1px solid var(--uc-warning-border);
}
.upload-csv-page .badge-success {
  background-color: var(--uc-success-light);
  color: var(--uc-success);
  border: 1px solid var(--uc-success-border);
}
.upload-csv-page .badge-info {
  background-color: var(--uc-blue-light);
  color: var(--uc-blue);
  border: 1px solid var(--uc-blue-border);
}

/* Modals */
.upload-csv-modal .modal-content {
  border: 1px solid var(--uc-border);
  border-radius: var(--uc-radius-lg);
  box-shadow: var(--uc-shadow-md);
  overflow: hidden;
}
.upload-csv-modal .modal-header {
  background: #FAF9FF;
  border-bottom: 1px solid var(--uc-primary-border);
  padding: 1rem 1.25rem;
}
.upload-csv-modal .modal-title {
  font-size: 1rem;
  font-weight: 700;
  color: var(--uc-text-main);
}
.upload-csv-modal .modal-body {
  padding: 1.25rem;
}

/* Responsive Breakpoints */
@media (max-width: 991.98px) {
  .upload-csv-page .uc-target-grid {
    grid-template-columns: 1fr;
  }
}
@media (max-width: 767.98px) {
  .upload-csv-page .uc-stepper {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 0.75rem;
  }
  .upload-csv-page .uc-step-connector {
    display: none !important;
  }
  .upload-csv-page .uc-step {
    padding: 0.5rem 0.6rem;
    background: #F8FAFC;
    border: 1px solid #E2E8F0;
    border-radius: 8px;
  }
}
@media (max-width: 575.98px) {
  .upload-csv-page .uc-stepper-card {
    padding: 0.75rem;
  }
  .upload-csv-page .uc-page-header {
    padding: 1rem;
  }
}
</style>

<div class="upload-csv-page container-fluid px-3 py-2">
    <!-- Module Header -->
    <div class="uc-page-header">
        <div class="d-flex align-items-center gap-3">
            <div class="uc-header-icon-box">
                <i class="bi bi-database-fill-gear"></i>
            </div>
            <div>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-1 fs-xs">
                        <li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Home</a></li>
                        <li class="breadcrumb-item"><a href="<?= url('modules/database/data_management_main.php') ?>">Data Management</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Upload CSV</li>
                    </ol>
                </nav>
                <h1 class="uc-page-title">Upload Customer Database</h1>
                <div class="uc-page-subtitle">Batch import records into Temporary Staging, Main Database, or Parallel Repositories</div>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <button type="button" class="uc-btn-ghost" id="resetUploadBtn" title="Reset upload form">
                <i class="bi bi-arrow-counterclockwise"></i> Reset
            </button>
            <a class="uc-btn-soft-primary" href="#" id="downloadExampleBtn" title="Download standard template">
                <i class="bi bi-download"></i> Example CSV
            </a>
            <button class="uc-btn-ghost" id="showHelpBtn" type="button" data-bs-toggle="collapse" data-bs-target="#helpCollapse" aria-expanded="false" aria-controls="helpCollapse">
                <i class="bi bi-question-circle"></i> Instructions
            </button>
        </div>
    </div>

    <!-- Instructions Collapse Panel -->
    <div class="collapse mb-3" id="helpCollapse">
        <div class="uc-card" style="border-left: 4px solid var(--uc-primary);">
            <div class="uc-card-body py-3">
                <div class="d-flex align-items-center gap-2 mb-2 text-primary fw-semibold fs-xs">
                    <i class="bi bi-info-circle-fill"></i> Data Format &amp; Ingestion Hygiene Tips
                </div>
                <div class="fs-xs text-secondary lh-base">
                    &bull; <strong>Header Detection:</strong> Supports column aliases: <code>Name</code>, <code>Mobile</code>, <code>Company</code>, <code>Package</code>, <code>Other Info</code>.<br>
                    &bull; <strong>Positional Fallback:</strong> If headerless, order follows: 1=Name, 2=Mobile, 3=Company, 4=Package, 5=Other Info.<br>
                    &bull; <strong>Indian Mobile Normalization:</strong> Numbers are scrubbed to 10 Indian digits (starting 6, 7, 8, or 9). Prefixes like +91 or 0 are auto-cleaned.<br>
                    &bull; <strong>Dry-Run Mode:</strong> Enable to simulate duplicates and invalid rows safely without inserting records.
                </div>
            </div>
        </div>
    </div>

    <!-- 4-Step Workflow Stepper -->
    <div class="uc-stepper-card">
        <div class="uc-stepper">
            <div class="uc-step step-active" id="step1">
                <div class="uc-step-circle">
                    <span class="uc-step-num">1</span>
                    <i class="bi bi-check-lg uc-step-check"></i>
                </div>
                <div class="uc-step-text">
                    <div class="uc-step-label">Step 1</div>
                    <div class="uc-step-title">Upload File</div>
                </div>
            </div>
            <div class="uc-step-connector" id="conn1"></div>
            <div class="uc-step step-upcoming" id="step2">
                <div class="uc-step-circle">
                    <span class="uc-step-num">2</span>
                    <i class="bi bi-check-lg uc-step-check"></i>
                </div>
                <div class="uc-step-text">
                    <div class="uc-step-label">Step 2</div>
                    <div class="uc-step-title">Configure &amp; Validate</div>
                </div>
            </div>
            <div class="uc-step-connector" id="conn2"></div>
            <div class="uc-step step-upcoming" id="step3">
                <div class="uc-step-circle">
                    <span class="uc-step-num">3</span>
                    <i class="bi bi-check-lg uc-step-check"></i>
                </div>
                <div class="uc-step-text">
                    <div class="uc-step-label">Step 3</div>
                    <div class="uc-step-title">Preview Data</div>
                </div>
            </div>
            <div class="uc-step-connector" id="conn3"></div>
            <div class="uc-step step-upcoming" id="step4">
                <div class="uc-step-circle">
                    <span class="uc-step-num">4</span>
                    <i class="bi bi-check-lg uc-step-check"></i>
                </div>
                <div class="uc-step-text">
                    <div class="uc-step-label">Step 4</div>
                    <div class="uc-step-title">Import &amp; Complete</div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <!-- Left Column: Configuration, Upload & Preview (60-65%) -->
        <div class="col-lg-7">
            <form method="post" enctype="multipart/form-data" id="uploadForm" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="import" id="importHidden" value="0">

                <!-- Import Configuration Card -->
                <div class="uc-card">
                    <div class="uc-card-header">
                        <div class="uc-card-title">
                            <span class="uc-header-badge-icon"><i class="bi bi-sliders"></i></span>
                            Import Configuration
                        </div>
                    </div>
                    <div class="uc-card-body">
                        <!-- Target Database 3 Visual Cards -->
                        <label class="form-label fs-xs fw-semibold text-secondary mb-2">Select Target Database Destination:</label>
                        <div class="uc-target-grid mb-3">
                            <!-- Card 1: Temporary (Staging) -->
                            <div class="uc-target-card selected" id="card_t_temp" onclick="selectTargetDatabase('temporary')">
                                <div class="d-flex justify-content-between align-items-start mb-1">
                                    <div class="uc-target-icon">
                                        <i class="bi bi-shield-check"></i>
                                    </div>
                                    <span class="uc-badge-recommended">Recommended</span>
                                </div>
                                <div class="uc-target-title">Temporary (Staging)</div>
                                <div class="uc-target-desc">Safe testing before transfer</div>
                                <input type="radio" class="btn-check" name="target_database" id="t_temp" value="temporary" checked>
                            </div>

                            <!-- Card 2: Main Database -->
                            <div class="uc-target-card" id="card_t_main" onclick="selectTargetDatabase('main')">
                                <div class="d-flex justify-content-between align-items-start mb-1">
                                    <div class="uc-target-icon">
                                        <i class="bi bi-database"></i>
                                    </div>
                                </div>
                                <div class="uc-target-title">Main Database</div>
                                <div class="uc-target-desc">Direct production import</div>
                                <input type="radio" class="btn-check" name="target_database" id="t_main" value="main">
                            </div>

                            <!-- Card 3: Both Databases -->
                            <div class="uc-target-card" id="card_t_both" onclick="selectTargetDatabase('both')">
                                <div class="d-flex justify-content-between align-items-start mb-1">
                                    <div class="uc-target-icon">
                                        <i class="bi bi-layers"></i>
                                    </div>
                                </div>
                                <div class="uc-target-title">Both Databases</div>
                                <div class="uc-target-desc">Import into staging and main</div>
                                <input type="radio" class="btn-check" name="target_database" id="t_both" value="both">
                            </div>
                        </div>

                        <!-- Staging isolation reminder banner -->
                        <div id="tempIsolationBanner" class="alert alert-secondary py-2 px-3 mb-3 border fs-xs d-flex align-items-center gap-2 rounded-3" style="background-color: #F8FAFC;">
                            <i class="bi bi-shield-check text-primary fs-5 flex-shrink-0"></i>
                            <div class="text-secondary">
                                <strong class="text-dark">Staging Isolation:</strong> Uploading to Temporary DB keeps records completely isolated. No Main DB rows will be touched until you transfer.
                            </div>
                        </div>

                        <!-- CSV Options Toggles -->
                        <div class="uc-options-bar d-flex flex-wrap align-items-center justify-content-between gap-3 p-3 rounded-3">
                            <div class="d-flex align-items-center gap-2">
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" id="hasHeader" name="has_header" value="1" checked>
                                    <label class="form-check-label fs-xs fw-semibold text-dark" for="hasHeader">CSV has header</label>
                                </div>
                                <i class="bi bi-info-circle text-muted fs-xs" data-bs-toggle="tooltip" title="First row contains column headers (Name, Mobile, Company, Package, Other Info)"></i>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" id="dryRun" name="dryrun" value="1">
                                    <label class="form-check-label fs-xs fw-semibold text-dark" for="dryRun">Dry-run preview</label>
                                </div>
                                <i class="bi bi-info-circle text-muted fs-xs" data-bs-toggle="tooltip" title="Simulate duplicate detection and mobile normalization without writing rows to database"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- CSV Upload Card -->
                <div class="uc-card">
                    <div class="uc-card-header">
                        <div class="uc-card-title">
                            <span class="uc-header-badge-icon"><i class="bi bi-cloud-arrow-up"></i></span>
                            Upload CSV File
                        </div>
                    </div>
                    <div class="uc-card-body">
                        <!-- Drop Zone -->
                        <div id="dropZone" class="uc-dropzone mb-3" tabindex="0">
                            <div id="dropInfoContent">
                                <div class="uc-dropzone-icon">
                                    <i class="bi bi-cloud-arrow-up"></i>
                                </div>
                                <div id="dzText" class="uc-dropzone-title">Drag &amp; drop your CSV file here</div>
                                <div class="uc-dropzone-sub">or click to browse from your computer</div>
                                <div class="d-flex justify-content-center align-items-center gap-2 mt-3">
                                    <span class="uc-pill-badge"><i class="bi bi-filetype-csv me-1 text-primary"></i>CSV only</span>
                                    <span class="uc-pill-badge"><i class="bi bi-hdd me-1 text-secondary"></i>Max size 10MB</span>
                                </div>
                            </div>
                            <input type="file" name="file" id="fileInput" accept=".csv" style="display:none;">
                        </div>

                        <!-- File Selected State Card -->
                        <div id="fileSelectedCard" class="uc-file-selected-card mb-3" style="display:none;">
                            <div class="d-flex align-items-center gap-3">
                                <div class="uc-file-icon">
                                    <i class="bi bi-file-earmark-spreadsheet-fill"></i>
                                </div>
                                <div class="flex-grow-1 overflow-hidden">
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <span class="fw-semibold text-dark text-truncate" id="selectedFileName">filename.csv</span>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle d-inline-flex align-items-center gap-1">
                                            <i class="bi bi-check-circle-fill"></i> Ready
                                        </span>
                                    </div>
                                    <div class="d-flex align-items-center gap-2 fs-xs text-muted">
                                        <span id="selectedFileSize">0 KB</span>
                                        <span>&bull;</span>
                                        <span id="selectedRowCount">0 records detected</span>
                                    </div>
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" id="replaceFileBtn" title="Choose a different CSV file">
                                        <i class="bi bi-arrow-repeat me-1"></i> Replace
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-danger" id="removeFileBtn" title="Remove selected file">
                                        <i class="bi bi-trash3"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Terms & Conditions / Consent Box -->
                        <div class="uc-consent-box p-3 rounded-3 mb-3">
                            <div class="form-check mb-0">
                                <input type="checkbox" name="terms_agreed" class="form-check-input" id="exampleCheck1" required>
                                <label class="form-check-label fs-xs text-dark" for="exampleCheck1">
                                    I confirm customer consent and agree to the <a href="#" data-bs-toggle="modal" data-bs-target="#termsModal" class="fw-semibold uc-link-primary">Terms &amp; Conditions</a> for uploading customer databases.
                                </label>
                            </div>
                        </div>

                        <!-- Action Row -->
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 pt-2 border-top">
                            <span class="fs-xs text-muted d-flex align-items-center gap-1">
                                <i class="bi bi-eye text-primary"></i> Previewing first 10 rows before final commit
                            </span>
                            <button type="submit" name="import" value="1" id="uploadBtn" class="uc-btn-import" disabled>
                                <i class="bi bi-arrow-right-circle me-2"></i> Start Import
                            </button>
                        </div>
                    </div>
                </div>
            </form>

            <!-- CSV Preview Card (shows when file is loaded) -->
            <div id="previewArea" class="uc-card" style="display:none;">
                <div class="uc-card-header">
                    <div class="uc-card-title">
                        <span class="uc-header-badge-icon"><i class="bi bi-table"></i></span>
                        CSV Data Preview (First 10 Data Rows)
                    </div>
                </div>
                <div class="uc-card-body p-0">
                    <div class="uc-preview-table-wrapper">
                        <table class="table table-hover align-middle uc-table">
                            <thead>
                                <tr>
                                    <th style="width:38px;">#</th>
                                    <th>Name</th>
                                    <th>Mobile (Raw)</th>
                                    <th>Normalized</th>
                                    <th>Status</th>
                                    <th>Company</th>
                                    <th>Package</th>
                                    <th>Other</th>
                                </tr>
                            </thead>
                            <tbody id="previewBody"></tbody>
                        </table>
                    </div>
                </div>
                <div class="fs-xs text-muted py-2 px-3 border-top bg-light" id="previewMeta"></div>
            </div>

            <!-- Standard Column Layout Example Card -->
            <div class="uc-card">
                <div class="uc-card-header">
                    <div class="uc-card-title">
                        <span class="uc-header-badge-icon"><i class="bi bi-file-earmark-spreadsheet"></i></span>
                        Standard Column Layout Example
                    </div>
                </div>
                <div class="uc-card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle uc-table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Mobile</th>
                                    <th>Company</th>
                                    <th>Package</th>
                                    <th>Other Info</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td>Rajesh Kumar</td><td>9876543210</td><td>Tech Corp Ltd</td><td>Personal Loan</td><td>Interested in 5 Lakhs</td></tr>
                                <tr><td><span class="text-muted">(Optional)</span></td><td>9123456789</td><td>Self Employed</td><td>Business Loan</td><td>Follow up Monday</td></tr>
                                <tr><td>Pooja Sharma</td><td>9811223344</td><td>Apex Logistics</td><td>Home Loan</td><td>Call after 5 PM</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Column: Summary, Metrics, Logs & Archival (35-40%) -->
        <div class="col-lg-5">
            <!-- Import Execution Summary Card -->
            <div class="uc-card">
                <div class="uc-card-header">
                    <div class="uc-card-title">
                        <span class="uc-header-badge-icon"><i class="bi bi-bar-chart-line"></i></span>
                        Import Execution Summary
                    </div>
                    <?php if ($summary): ?>
                        <span class="badge badge-<?= htmlspecialchars($summaryType ?: 'secondary') ?>"><?= strtoupper(htmlspecialchars($summaryType ?: 'INFO')) ?></span>
                    <?php endif; ?>
                </div>
                <div class="uc-card-body">
                    <?php if ($summary): ?>
                        <div class="alert alert-<?= htmlspecialchars($summaryType) ?> py-2 px-3 fs-xs mb-3 rounded-3"><?= $summary ?></div>
                    <?php endif; ?>

                    <!-- 2 x 2 Color Coded Metric Grid -->
                    <div class="row g-2 mb-3">
                        <!-- Processed -->
                        <div class="col-6">
                            <div class="uc-metric-card metric-processed">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <span class="uc-metric-label">PROCESSED</span>
                                    <div class="uc-metric-icon"><i class="bi bi-database"></i></div>
                                </div>
                                <div class="uc-metric-value" id="metricProcessed"><?= number_format($stats['rows_processed']) ?></div>
                                <div class="uc-metric-sub">Rows checked</div>
                            </div>
                        </div>
                        <!-- Inserted -->
                        <div class="col-6">
                            <div class="uc-metric-card metric-inserted">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <span class="uc-metric-label">INSERTED</span>
                                    <div class="uc-metric-icon"><i class="bi bi-check-circle-fill"></i></div>
                                </div>
                                <div class="uc-metric-value text-success" id="metricInserted"><?= number_format($stats['inserted']) ?></div>
                                <div class="uc-metric-sub">New records</div>
                            </div>
                        </div>
                        <!-- Duplicates -->
                        <div class="col-6">
                            <div class="uc-metric-card metric-duplicates">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <span class="uc-metric-label">DUPLICATES SKIPPED</span>
                                    <div class="uc-metric-icon"><i class="bi bi-copy"></i></div>
                                </div>
                                <div class="uc-metric-value text-warning" id="metricDuplicates"><?= number_format($stats['duplicates']) ?></div>
                                <div class="uc-metric-sub">Existing mobiles</div>
                            </div>
                        </div>
                        <!-- Invalid -->
                        <div class="col-6">
                            <div class="uc-metric-card metric-invalid">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <span class="uc-metric-label">INVALID NUMBERS</span>
                                    <div class="uc-metric-icon"><i class="bi bi-exclamation-triangle-fill"></i></div>
                                </div>
                                <div class="uc-metric-value text-danger" id="metricInvalid"><?= number_format($stats['invalid']) ?></div>
                                <div class="uc-metric-sub">Formatting error</div>
                            </div>
                        </div>
                    </div>

                    <!-- Live Progress Bar inside Summary Card -->
                    <div class="uc-live-progress-box p-3 rounded-3 mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="fs-xs fw-semibold text-secondary">Import Execution Progress</span>
                            <span class="fs-xs fw-bold text-dark" id="summaryPercentText">0%</span>
                        </div>
                        <div class="progress uc-progress" style="height: 8px;">
                            <div id="summaryProgressBar" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%"></div>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mt-2">
                            <span class="fs-xs text-muted" id="summaryStatusMsg">Awaiting file upload...</span>
                        </div>
                    </div>

                    <?php if (isset($stats['would_insert']) && $stats['would_insert'] > 0): ?>
                        <div class="p-3 mb-3 rounded-3 border bg-light fs-xs">
                            <div class="fw-semibold text-primary mb-1"><i class="bi bi-magic me-1"></i> Dry-Run Simulation Results:</div>
                            <div class="d-flex justify-content-between text-secondary">
                                <span>Simulated Inserts: <strong class="text-success"><?= number_format($stats['would_insert']) ?></strong></span>
                                <span>Simulated Skips: <strong class="text-warning"><?= number_format($stats['would_update']) ?></strong></span>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Import Activity Logs Card -->
            <div class="uc-card">
                <div class="uc-card-header">
                    <div class="uc-card-title">
                        <span class="uc-header-badge-icon"><i class="bi bi-journal-text"></i></span>
                        Import Activity Logs
                    </div>
                    <span class="badge bg-secondary-subtle text-secondary border fs-xs" id="logCountBadge"><?= count($logs) ?> entries</span>
                </div>
                <div class="uc-card-body p-3">
                    <?php if (empty($logs)): ?>
                        <div class="uc-empty-logs text-center py-4 text-muted" id="emptyLogsPlaceholder">
                            <i class="bi bi-journal-x" style="font-size:2.2rem; color:#CBD5E1;"></i>
                            <div class="fs-xs mt-2 text-secondary">No execution logs in current session.</div>
                        </div>
                        <div id="dynamicLogsContainer" style="display:none; max-height:350px; overflow-y:auto;"></div>
                    <?php else: ?>
                        <?php
                        $logPerPage = 20;
                        $logPage = max(1, (int)($_GET['log_page'] ?? 1));
                        $allLogs = array_reverse(array_slice($logs, -200));
                        $totalLogPages = max(1, (int)ceil(count($allLogs) / $logPerPage));
                        $pageLogs = array_slice($allLogs, ($logPage - 1) * $logPerPage, $logPerPage);
                        ?>
                        <div id="dynamicLogsContainer" style="max-height:350px; overflow-y:auto;">
                            <?php foreach ($pageLogs as $idx => $l): 
                                $lc = strtolower($l);
                                if (strpos($lc,'error')!==false || strpos($lc,'failed')!==false) { $sev='danger'; $badgeClass='badge-danger'; }
                                elseif (strpos($lc,'invalid')!==false || strpos($lc,'skip')!==false || strpos($lc,'dnd')!==false) { $sev='warning'; $badgeClass='badge-warning'; }
                                elseif (strpos($lc,'insert')!==false || strpos($lc,'success')!==false) { $sev='success'; $badgeClass='badge-success'; }
                                else { $sev='info'; $badgeClass='badge-info'; }
                            ?>
                                <div class="uc-log-item d-flex align-items-start gap-2 fs-xs py-2">
                                    <span class="badge <?= $badgeClass ?> flex-shrink-0" style="font-size:0.65rem;"><?= strtoupper($sev) ?></span>
                                    <span class="text-secondary lh-sm"><?= htmlspecialchars($l) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Cold Storage & Archive Card -->
            <div class="uc-card">
                <div class="uc-card-header">
                    <div class="uc-card-title">
                        <span class="uc-header-badge-icon"><i class="bi bi-archive"></i></span>
                        Cold Storage &amp; Archive
                    </div>
                </div>
                <div class="uc-card-body">
                    <div class="fs-xs text-muted mb-3">
                        Move aged records from Main Database into historical archive (<code>TBL_MAIN_ARCHIVE</code>) to maintain high search performance.
                    </div>
                    <form method="post" onsubmit="return confirm('Archive older rows? This will move historical data and purge from main database.');">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        <div class="input-group input-group-sm mb-2">
                            <span class="input-group-text bg-light border-end-0 fs-xs">Older than</span>
                            <input type="number" name="archive_months" class="form-control text-center fs-sm" min="1" value="12" aria-label="months">
                            <span class="input-group-text bg-light border-start-0 fs-xs">Months</span>
                            <button class="btn btn-outline-primary fs-xs fw-semibold" type="submit" name="archive_action" value="1">
                                <i class="bi bi-box-arrow-right me-1"></i> Archive
                            </button>
                        </div>
                        <div class="fs-xs text-muted d-flex align-items-center gap-1 mt-2">
                            <i class="bi bi-info-circle text-primary"></i> Auto-creates archive table if not present.
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Terms and Conditions Modal -->
<div class="modal fade upload-csv-modal" id="termsModal" tabindex="-1" aria-labelledby="termsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="termsModalLabel"><i class="bi bi-shield-lock text-primary me-2"></i>Terms &amp; Conditions for Data Upload</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body fs-xs lh-base">
                <p class="text-secondary mb-3">By uploading customer contact records to this platform, you agree to the following data compliance conditions:</p>
                <ol class="ps-3 mb-3 text-secondary">
                    <li class="mb-2"><strong>Responsibility of Data Upload:</strong> I acknowledge that data uploaded to this platform is done entirely under organization compliance and consent guidelines.</li>
                    <li class="mb-2"><strong>Customer Consent:</strong> I confirm that data has been collected legally with valid permissions for telecalling and contact activities.</li>
                    <li class="mb-2"><strong>Prohibition of Misuse:</strong> I guarantee uploaded data will not be exported, transferred, or disclosed outside authorized business workflows.</li>
                    <li class="mb-2"><strong>Data Protection &amp; Privacy:</strong> I confirm compliance with applicable telecom and data privacy regulations.</li>
                    <li class="mb-2"><strong>Accuracy &amp; Verification:</strong> All contact data is subject to scrubbing, DND suppression, and internal duplicate checks upon upload.</li>
                </ol>
                <div class="text-muted border-top pt-2 mt-3">Effective Date: 01 January, 2025</div>
            </div>
            <div class="modal-footer bg-light py-2">
                <button type="button" class="btn btn-primary btn-sm px-3" data-bs-dismiss="modal">I Understand &amp; Agree</button>
            </div>
        </div>
    </div>
</div>

<!-- Upload Progress Modal -->
<div class="modal fade upload-csv-modal" id="uploadProgressModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="bi bi-cloud-arrow-up text-primary me-2"></i>Uploading &amp; Importing Data</h5>
                <button type="button" class="btn-close" id="uploadModalCloseBtn" data-bs-dismiss="modal" aria-label="Close" disabled></button>
            </div>
            <div class="modal-body">
                <div class="d-flex justify-content-between mb-1">
                    <span class="fs-xs text-muted">Ingestion Progress</span>
                    <span id="uploadPercent" class="fs-xs fw-bold text-dark">0%</span>
                </div>
                <div class="progress mb-3" style="height: 14px; border-radius: 999px; background-color: #E2E8F0;">
                    <div id="uploadProgressBar" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%; background: linear-gradient(90deg, #6D42F5 0%, #8C72FF 100%);"></div>
                </div>
                <p class="mb-2 fs-xs text-secondary" id="uploadMessage">Uploading file...</p>
                <div id="uploadResult" class="mt-3 alert fs-xs rounded-3" style="display:none;"></div>
            </div>
        </div>
    </div>
</div>

<!-- Notification Modal -->
<div class="modal fade upload-csv-modal" id="notificationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-body text-center py-4">
                <div id="notifIcon" class="mb-2" style="font-size:2.2rem;"></div>
                <h6 id="notifTitle" class="fw-bold mb-1 text-dark"></h6>
                <p id="notifMessage" class="text-secondary fs-xs mb-3"></p>
                <button type="button" class="btn btn-primary btn-sm px-4 rounded-3" data-bs-dismiss="modal">OK</button>
            </div>
        </div>
    </div>
</div>

<script>
/* Core elements */
const CSRF_TOKEN = <?= json_encode($_SESSION['csrf_token'] ?? '') ?>;
const dropZone = document.getElementById('dropZone');
const fileInput = document.getElementById('fileInput');
const uploadBtn = document.getElementById('uploadBtn');
const previewArea = document.getElementById('previewArea');
const previewBody = document.getElementById('previewBody');
const previewMeta = document.getElementById('previewMeta');
const importHidden = document.getElementById('importHidden');
const hasHeaderCheckbox = document.getElementById('hasHeader');
const dryRun = document.getElementById('dryRun');
const downloadExampleBtn = document.getElementById('downloadExampleBtn');
const terms = document.getElementById('exampleCheck1');
const fileSelectedCard = document.getElementById('fileSelectedCard');
const replaceFileBtn = document.getElementById('replaceFileBtn');
const removeFileBtn = document.getElementById('removeFileBtn');

/* Stepper state management */
function setStep(stepNum) {
    for (let i = 1; i <= 4; i++) {
        const step = document.getElementById('step' + i);
        const conn = document.getElementById('conn' + (i - 1));
        if (!step) continue;
        step.classList.remove('step-active', 'step-completed', 'step-upcoming');
        if (conn) conn.classList.remove('conn-active', 'conn-completed');

        if (i < stepNum) {
            step.classList.add('step-completed');
            if (conn) conn.classList.add('conn-completed');
        } else if (i === stepNum) {
            step.classList.add('step-active');
            if (conn) conn.classList.add('conn-active');
        } else {
            step.classList.add('step-upcoming');
        }
    }
}

/* Target database visual selection */
function selectTargetDatabase(target) {
    const key = (target === 'temporary') ? 'temp' : target;
    document.querySelectorAll('.uc-target-card').forEach(c => c.classList.remove('selected'));
    const card = document.getElementById('card_t_' + key);
    if (card) card.classList.add('selected');
    const radio = document.getElementById('t_' + key);
    if (radio) {
        radio.checked = true;
    }
    updateTempIsolationBanner();
}

function updateTempIsolationBanner() {
    const banner = document.getElementById('tempIsolationBanner');
    const target = document.querySelector('input[name="target_database"]:checked');
    if (banner && target) {
        if (target.value === 'temporary') {
            banner.classList.remove('d-none');
        } else {
            banner.classList.add('d-none');
        }
    }
}
document.querySelectorAll('input[name="target_database"]').forEach(function(r) {
    r.addEventListener('change', function() {
        const key = (this.value === 'temporary') ? 'temp' : this.value;
        document.querySelectorAll('.uc-target-card').forEach(c => c.classList.remove('selected'));
        const card = document.getElementById('card_t_' + key);
        if (card) card.classList.add('selected');
        updateTempIsolationBanner();
    });
});
updateTempIsolationBanner();

/* Summary progress & live stats */
function updateSummaryProgress(percent, msg) {
    if (document.getElementById('summaryPercentText')) document.getElementById('summaryPercentText').textContent = percent + '%';
    if (document.getElementById('summaryProgressBar')) document.getElementById('summaryProgressBar').style.width = percent + '%';
    if (document.getElementById('summaryStatusMsg') && msg) document.getElementById('summaryStatusMsg').textContent = msg;
}

function updateLiveStats(stats) {
    if (!stats) return;
    if (document.getElementById('metricProcessed')) document.getElementById('metricProcessed').textContent = Number(stats.rows_processed || 0).toLocaleString();
    if (document.getElementById('metricInserted')) document.getElementById('metricInserted').textContent = Number(stats.inserted || 0).toLocaleString();
    if (document.getElementById('metricDuplicates')) document.getElementById('metricDuplicates').textContent = Number(stats.duplicates || 0).toLocaleString();
    if (document.getElementById('metricInvalid')) document.getElementById('metricInvalid').textContent = Number(stats.invalid || 0).toLocaleString();
}

function renderLiveLogs(logs) {
    if (!logs || !logs.length) return;
    const placeholder = document.getElementById('emptyLogsPlaceholder');
    if (placeholder) placeholder.style.display = 'none';
    const container = document.getElementById('dynamicLogsContainer');
    if (container) {
        container.style.display = 'block';
        const badge = document.getElementById('logCountBadge');
        if (badge) badge.textContent = logs.length + ' entries';

        const reversed = [...logs].reverse().slice(0, 50);
        let html = '';
        reversed.forEach(l => {
            const lc = l.toLowerCase();
            let sev = 'info';
            let badgeClass = 'badge-info';
            if (lc.includes('error') || lc.includes('failed')) { sev = 'danger'; badgeClass = 'badge-danger'; }
            else if (lc.includes('invalid') || lc.includes('skip') || lc.includes('dnd')) { sev = 'warning'; badgeClass = 'badge-warning'; }
            else if (lc.includes('insert') || lc.includes('success')) { sev = 'success'; badgeClass = 'badge-success'; }
            html += `<div class="uc-log-item d-flex align-items-start gap-2 fs-xs py-2">
                <span class="badge ${badgeClass} flex-shrink-0" style="font-size:0.65rem;">${sev.toUpperCase()}</span>
                <span class="text-secondary lh-sm">${escapeHtml(l)}</span>
            </div>`;
        });
        container.innerHTML = html;
    }
}

/* File selection & ready state checks */
function checkReadyState() {
    const hasFile = fileInput.files && fileInput.files.length > 0;
    const consentAgreed = terms.checked;
    uploadBtn.disabled = !(hasFile && consentAgreed);
}
terms.addEventListener('change', checkReadyState);

function onFileSelected(file) {
    if (!file) return;
    if (file.size > 10 * 1024 * 1024) {
        showNotification('File Too Large', 'File exceeds the 10MB limit. Please choose a smaller CSV.', 'danger');
        fileInput.value = '';
        return;
    }
    if (!file.name.toLowerCase().endsWith('.csv')) {
        showNotification('Invalid File Type', 'Only comma-separated (.csv) files are allowed.', 'danger');
        fileInput.value = '';
        return;
    }

    const sizeStr = (file.size < 1024 * 1024)
        ? (file.size / 1024).toFixed(1) + ' KB'
        : (file.size / (1024 * 1024)).toFixed(2) + ' MB';

    document.getElementById('selectedFileName').textContent = file.name;
    document.getElementById('selectedFileSize').textContent = sizeStr;
    dropZone.style.display = 'none';
    fileSelectedCard.style.display = 'block';

    setStep(2);
    handlePreview(file);
    checkReadyState();
    updateSummaryProgress(0, 'File loaded. Review preview and click Start Import.');
}

dropZone.addEventListener('click', () => fileInput.click());

fileInput.addEventListener('change', () => {
    if (fileInput.files.length) {
        onFileSelected(fileInput.files[0]);
    }
});

replaceFileBtn.addEventListener('click', () => fileInput.click());

removeFileBtn.addEventListener('click', () => {
    fileInput.value = '';
    fileSelectedCard.style.display = 'none';
    dropZone.style.display = 'block';
    previewArea.style.display = 'none';
    previewBody.innerHTML = '';
    previewMeta.textContent = '';
    checkReadyState();
    setStep(1);
    updateSummaryProgress(0, 'Awaiting file upload...');
});

['dragenter','dragover'].forEach(ev => dropZone.addEventListener(ev, e => { e.preventDefault(); dropZone.classList.add('dragover'); }));
['dragleave','drop'].forEach(ev => dropZone.addEventListener(ev, e => { e.preventDefault(); dropZone.classList.remove('dragover'); }));
dropZone.addEventListener('drop', ev => {
    if (ev.dataTransfer.files.length) {
        fileInput.files = ev.dataTransfer.files;
        onFileSelected(fileInput.files[0]);
    }
});

/* Reset button */
document.getElementById('resetUploadBtn').addEventListener('click', function(){
    fileInput.value = '';
    fileSelectedCard.style.display = 'none';
    dropZone.style.display = 'block';
    uploadBtn.disabled = true;
    previewArea.style.display = 'none';
    previewBody.innerHTML = '';
    previewMeta.textContent = '';
    hasHeaderCheckbox.checked = true;
    dryRun.checked = false;
    selectTargetDatabase('temporary');
    terms.checked = false;
    setStep(1);
    updateSummaryProgress(0, 'Awaiting file upload...');
    showNotification('Reset Completed', 'Upload form cleared and reset to initial state.', 'info');
});

/* CSV header toggle re-triggers preview */
hasHeaderCheckbox.addEventListener('change', function() {
    if (fileInput.files && fileInput.files.length > 0) {
        handlePreview(fileInput.files[0]);
    }
});

/* CSV Preview parser */
function handlePreview(file) {
    const reader = new FileReader();
    reader.onload = function(e) {
        const text = e.target.result;
        const rows = parseCSV(text);
        if (rows.length === 0) {
            previewBody.innerHTML = '<tr><td colspan="8" class="text-center fs-xs text-muted py-3">No data rows found</td></tr>';
            previewArea.style.display = 'block';
            previewMeta.textContent = '';
            document.getElementById('selectedRowCount').textContent = '0 records detected';
            return;
        }

        let headerRow = [];
        let dataRows = [];

        if (hasHeaderCheckbox.checked) {
            headerRow = rows[0];
            dataRows = rows.slice(1, Math.min(rows.length, 11));
        } else {
            headerRow = ['Name','Mobile','Company','Package','Other Info'];
            dataRows = rows.slice(0, Math.min(rows.length, 10));
        }

        const totalDataRows = hasHeaderCheckbox.checked ? Math.max(0, rows.length - 1) : rows.length;
        document.getElementById('selectedRowCount').textContent = Number(totalDataRows).toLocaleString() + ' records detected';

        previewBody.innerHTML = '';
        let invalidCount = 0;
        for (let i = 0; i < dataRows.length; i++) {
            const r = dataRows[i];
            const name = r[0] || '';
            const raw = r[1] || '';
            let digits = (raw.match(/\d/g) || []).join('');
            if (digits.length === 12 && digits.startsWith('91')) {
                digits = digits.slice(2);
            } else if (digits.length === 11 && digits.startsWith('0')) {
                digits = digits.slice(1);
            } else if (digits.length > 10) {
                digits = digits.slice(-10);
            }
            const norm = digits;
            const valid = /^[6-9]\d{9}$/.test(norm);
            if (!valid) invalidCount++;
            const tr = document.createElement('tr');
            if (!valid) {
                tr.style.backgroundColor = '#FFF5F5';
            }
            tr.innerHTML = `<td class="text-muted fw-semibold">${i + 1}</td>
                            <td class="fw-medium">${escapeHtml(name) || '<span class="text-muted">--</span>'}</td>
                            <td><code>${escapeHtml(raw)}</code></td>
                            <td><code>${escapeHtml(norm) || '<span class="text-muted">--</span>'}</code></td>
                            <td>${valid ? '<span class="badge bg-success-subtle text-success border border-success-subtle"><i class="bi bi-check-lg me-1"></i>Valid</span>' : '<span class="badge bg-danger-subtle text-danger border border-danger-subtle"><i class="bi bi-x-lg me-1"></i>Invalid</span>'}</td>
                            <td>${escapeHtml(r[2] || '') || '<span class="text-muted">--</span>'}</td>
                            <td>${escapeHtml(r[3] || '') || '<span class="text-muted">--</span>'}</td>
                            <td>${escapeHtml(r[4] || '') || '<span class="text-muted">--</span>'}</td>`;
            previewBody.appendChild(tr);
        }
        previewArea.style.display = 'block';
        previewMeta.innerHTML = `<i class="bi bi-check-circle text-success me-1"></i> Showing first <strong>${dataRows.length}</strong> of <strong>${totalDataRows.toLocaleString()}</strong> rows &bull; Invalid mobiles in preview: <strong>${invalidCount}</strong> &bull; Headers: <code>${escapeHtml(headerRow.join(', '))}</code>`;

        setStep(3);
    };
    reader.readAsText(file);
}

/* Quoted CSV Parser */
function parseCSV(text) {
    const rows = [];
    let current = [];
    let field = '';
    let inQuotes = false;
    let i = 0;
    while (i < text.length) {
        const ch = text[i];
        if (inQuotes) {
            if (ch === '"') {
                if (i + 1 < text.length && text[i+1] === '"') { field += '"'; i += 2; continue; }
                inQuotes = false;
            } else {
                field += ch;
            }
        } else {
            if (ch === '"') { inQuotes = true; }
            else if (ch === ',') { current.push(field); field = ''; }
            else if (ch === '\r') { /* skip */ }
            else if (ch === '\n') { current.push(field); rows.push(current); current = []; field = ''; }
            else { field += ch; }
        }
        i++;
    }
    if (field !== '' || current.length > 0) { current.push(field); rows.push(current); }
    return rows;
}

function escapeHtml(s) { 
    if (!s) return ''; 
    return s.replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;'); 
}

/* Example CSV template download */
downloadExampleBtn.addEventListener('click', function(e) {
    e.preventDefault();
    const example = [
        ['Name','Mobile','Company','Package','Other Info'],
        ['Rajesh Kumar','9876543210','Tech Corp Ltd','Personal Loan','Interested in 5 Lakhs'],
        ['Anil Mehta','9123456789','Self Employed','Business Loan','Follow up Monday'],
        ['Pooja Sharma','9811223344','Apex Logistics','Home Loan','Call after 5 PM']
    ];
    const csv = example.map(r => r.map(cell => {
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

/* Modal Notifications */
function showNotification(title, message, type = 'success') {
    const iconMap = {
        success: '<i class="bi bi-check-circle-fill text-success"></i>',
        danger: '<i class="bi bi-x-circle-fill text-danger"></i>',
        warning: '<i class="bi bi-exclamation-triangle-fill text-warning"></i>',
        info: '<i class="bi bi-info-circle-fill text-primary"></i>'
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
    updateSummaryProgress(percent, message);
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

/* Form submission & batch handling */
document.getElementById('uploadForm').addEventListener('submit', function(e) {
    importHidden.value = '1';
    if (!fileInput.files || fileInput.files.length === 0) {
        e.preventDefault();
        showNotification('No File Selected', 'Please select a CSV file before starting the import.', 'danger');
        return false;
    }
    if (!terms.checked) {
        e.preventDefault();
        showNotification('Consent Required', 'Please confirm customer consent and agree to the Terms & Conditions.', 'danger');
        return false;
    }
    if (hasHeaderCheckbox.checked) {
        hasHeaderCheckbox.value = '1';
    } else {
        hasHeaderCheckbox.value = '0';
    }

    e.preventDefault();

    setStep(4);

    const modal = new bootstrap.Modal(document.getElementById('uploadProgressModal'), { backdrop: 'static', keyboard: false });
    const progressBar = $('#uploadProgressBar');
    const percentText = $('#uploadPercent');
    const messageText = $('#uploadMessage');
    const resultDiv = $('#uploadResult');

    modal.show();
    setModalClosable(false);
    progressBar.css('width', '0%').removeClass('bg-success bg-danger').addClass('bg-primary');
    percentText.text('0%');
    messageText.text('Uploading CSV file to server...');
    resultDiv.hide();
    updateSummaryProgress(0, 'Uploading CSV file to server...');

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
                        messageText.text('File uploaded. Processing records in batches...');
                        updateSummaryProgress(10, 'Processing records in batches...');
                        processUploadBatch(resp.task_id);
                    } else {
                        throw new Error(resp.error || 'Upload failed');
                    }
                } catch (err) {
                    progressBar.addClass('bg-danger');
                    messageText.text('Upload failed');
                    resultDiv.show().addClass('alert-danger').html('<strong>Error:</strong> ' + (err.message || 'Failed to start upload.') + '<br><button class="btn btn-sm btn-secondary mt-2 close-modal-btn">Close</button>');
                    setModalClosable(true);
                    showNotification('Error', err.message || 'Failed to start upload', 'danger');
                    updateSummaryProgress(0, 'Upload failed: ' + (err.message || 'Failed to start'));
                }
            } else {
                progressBar.addClass('bg-danger');
                messageText.text('Server error');
                resultDiv.show().addClass('alert-danger').html('<strong>Error:</strong> Server error (HTTP ' + xhr.status + ').<br><button class="btn btn-sm btn-secondary mt-2 close-modal-btn">Close</button>');
                setModalClosable(true);
                showNotification('Error', 'Server error during upload', 'danger');
                updateSummaryProgress(0, 'Server error during upload');
            }
        }
    };

    xhr.onerror = function() {
        progressBar.addClass('bg-danger');
        messageText.text('Network error');
        resultDiv.show().addClass('alert-danger').html('<strong>Error:</strong> Network error during upload.<br><button class="btn btn-sm btn-secondary mt-2 close-modal-btn">Close</button>');
        setModalClosable(true);
        showNotification('Network Error', 'Network error occurred while uploading file.', 'danger');
        updateSummaryProgress(0, 'Network error');
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
    formData.set('csrf_token', CSRF_TOKEN || '');

    $.ajax({
        type: 'POST',
        url: location.href,
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(resp) {
            if (resp.stats) updateLiveStats(resp.stats);
            if (resp.logs) renderLiveLogs(resp.logs);

            if (resp.status === 'complete') {
                progressBar.css('width', '100%').removeClass('bg-primary bg-danger').addClass('bg-success');
                percentText.text('100%');
                messageText.text('Import complete!');
                resultDiv.show().addClass('alert-success').html('<strong>Success!</strong> ' + (resp.message || 'Import completed') + '<br><button class="btn btn-sm btn-secondary mt-2 close-modal-btn">Close</button>');
                setModalClosable(true);
                setStep(5);
                updateSummaryProgress(100, resp.message || 'Import completed successfully!');
                showNotification('Upload Complete', resp.message || 'Import completed', 'success');
            } else if (resp.status === 'error') {
                progressBar.addClass('bg-danger');
                messageText.text('Upload failed');
                resultDiv.show().addClass('alert-danger').html('<strong>Error:</strong> ' + (resp.message || resp.error || 'Unknown error') + '<br><button class="btn btn-sm btn-secondary mt-2 close-modal-btn">Close</button>');
                setModalClosable(true);
                updateSummaryProgress(resp.percent || 0, 'Import halted: ' + (resp.message || resp.error));
                showNotification('Error', resp.message || resp.error || 'Unknown error', 'danger');
            } else {
                progressBar.css('width', resp.percent + '%').removeClass('bg-success bg-danger').addClass('bg-primary');
                percentText.text(resp.percent + '%');
                messageText.text(resp.message || 'Processing records...');
                updateSummaryProgress(resp.percent || 10, resp.message || 'Processing records...');
                setTimeout(function() { processUploadBatch(task_id); }, 200);
            }
        },
        error: function() {
            progressBar.addClass('bg-danger');
            messageText.text('Network error');
            resultDiv.show().addClass('alert-danger').html('<strong>Error:</strong> Network error while checking progress.<br><button class="btn btn-sm btn-secondary mt-2 close-modal-btn">Close</button>');
            setModalClosable(true);
            updateSummaryProgress(0, 'Network error while checking progress');
            showNotification('Network Error', 'Network error while checking progress', 'danger');
        }
    });
}
</script>

<?php include __DIR__ . '/../../php_scripts/footer.php'; ?>

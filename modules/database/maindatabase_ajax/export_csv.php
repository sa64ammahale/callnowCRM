<?php
require_once "../../../php_scripts/auth.php";

requirePermission('export_data');
api_init();

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="MainDatabase_'.date('Y-m-d_His').'.csv"');

$output = fopen('php://output', 'w');
fputcsv($output, ['Mobile','Name','Company','Package','Status','Assigned To','Uploaded On']);

$sql = "SELECT MAINDATABASE_MOBILE,
               MAINDATABASE_NAME,
               MAINDATABASE_COMPANY,
               MAINDATABASE_PACKAGE,
               MAINDATABASE_CALL_DIALED_STATUS,
               u.NAME AS assigned,
               DATE_FORMAT(MAINDATABASE_UPLOAD_DATETIME, '%d-%m-%Y %H:%i') AS dt
        FROM TBL_MAIN 
        LEFT JOIN TBL_USERS u ON TBL_MAIN.MAINDATABASE_CALL_DIALED_USER = u.ID
        ORDER BY MAINDATABASE_UPLOAD_DATETIME DESC";

$res = mysqli_query($link, $sql);
while ($row = mysqli_fetch_assoc($res)) {
    fputcsv($output, [
        $row['MAINDATABASE_MOBILE'],
        $row['MAINDATABASE_NAME'] ?: '',
        $row['MAINDATABASE_COMPANY'] ?: '',
        $row['MAINDATABASE_PACKAGE'] ?: '',
        $row['MAINDATABASE_CALL_DIALED_STATUS'] ?: 'Not Called',
        $row['assigned'] ?: '',
        $row['dt']
    ]);
}
exit;
?>

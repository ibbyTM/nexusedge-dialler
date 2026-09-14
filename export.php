<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/helpers.php';
$user = require_admin();
session_write_close();

[$whereSql, $params] = leads_filter($user, $_GET);

$columns = [
    'id', 'company_name', 'contact_name', 'job_title', 'phone_raw', 'phone_e164', 'email', 'website',
    'address', 'town', 'postcode', 'tier', 'source', 'batch_name', 'assigned_name', 'dial_status',
    'do_not_dial', 'dial_attempts', 'last_dial_at', 'callback_at', 'notes', 'custom_json', 'created_at', 'updated_at',
];
// Free-text columns get a guard against spreadsheet formula injection.
$textCols = ['company_name', 'contact_name', 'job_title', 'phone_raw', 'email', 'website', 'address', 'town', 'postcode', 'source', 'batch_name', 'assigned_name', 'notes', 'custom_json'];

$filename = 'leads-' . date('Y-m-d-Hi') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM so spreadsheet apps read UTF-8 correctly
fputcsv($out, $columns, ',', '"', '');

$st = q(LEAD_SELECT . " WHERE $whereSql ORDER BY l.id ASC", $params);
while ($row = $st->fetch()) {
    $line = [];
    foreach ($columns as $c) {
        $v = (string)($row[$c] ?? '');
        if (in_array($c, $textCols, true) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) {
            $v = "'" . $v;
        }
        $line[] = $v;
    }
    fputcsv($out, $line, ',', '"', '');
    if (function_exists('ob_get_level') && ob_get_level() > 0) {
        ob_flush();
    }
    flush();
}
fclose($out);
exit;

<?php
declare(strict_types=1);

// مؤقت أثناء الاختبار — عطل في بيئة الإنتاج لاحقاً
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Session.php';
require_once __DIR__ . '/../core/Csrf.php';
require_once __DIR__ . '/../core/Auth.php';

use Core\Auth;
use Core\Csrf;
use Core\Database;
use Core\Session;

Session::start();
$auth = new Auth();
$auth->requireAdmin();

$db = Database::getConnection();

$errors = [];
$success = '';

/* ====== import dir + log setup ====== */
$preferredDirs = [
    __DIR__ . '/../storage/imports/',
    __DIR__ . '/../public/uploads/imports/',
    __DIR__ . '/uploads/imports/',
    sys_get_temp_dir() . '/attendance_imports/'
];

$importDir = null;
foreach ($preferredDirs as $d) {
    if (!is_dir($d)) @mkdir($d, 0755, true);
    if (is_dir($d) && is_writable($d)) { $importDir = rtrim($d, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR; break; }
}
if ($importDir === null) $importDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR;

$logFile = $importDir . 'import_errors.log';
function import_log(string $m) {
    global $logFile;
    @file_put_contents($logFile, date('Y-m-d H:i:s') . " - " . $m . PHP_EOL, FILE_APPEND | LOCK_EX);
}
import_log("Chosen importDir: {$importDir}");

/* ====== helpers ====== */
function map_get(array $map, array $row, string $key): string {
    $k = mb_strtolower(trim($key));
    if (!isset($map[$k])) return '';
    $idx = $map[$k];
    return isset($row[$idx]) ? trim((string)$row[$idx]) : '';
}

/* ====== main ====== */
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        Csrf::verifyOrFail();

        if (!isset($_FILES['csv_file']) || ($_FILES['csv_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('لم يتم رفع ملف صالح.');
        }

        $up = $_FILES['csv_file'];
        $origName = basename($up['name']);
        $safeName = time() . '_' . preg_replace('/[^a-zA-Z0-9\._-]/', '_', $origName);
        $dest = $importDir . $safeName;

        if (!@move_uploaded_file($up['tmp_name'], $dest)) {
            import_log("move_uploaded_file failed for {$origName} -> {$dest}. PHP error: " . ($up['error'] ?? 'unknown'));
            throw new RuntimeException('فشل حفظ الملف المرفوع. تأكد من أذونات المجلد.');
        }

        $fh = fopen($dest, 'r');
        if (!$fh) {
            import_log("fopen failed: {$dest}");
            throw new RuntimeException('فشل فتح الملف المرفوع.');
        }

        // find header row
        $header = null;
        while (($line = fgets($fh)) !== false) {
            $line = trim($line, "\r\n");
            if ($line === '') continue;
            $cols = str_getcsv($line, ';');
            $first = mb_strtolower(trim($cols[0] ?? ''));
            if ($first === 'first name' || $first === 'firstname') { $header = $cols; break; }
        }
        if (!$header) {
            fclose($fh);
            import_log("Header not found in file: {$dest}");
            throw new RuntimeException('لم أجد صف الرأس (First Name). تأكد من ملف CSV.');
        }

        // build map
        $map = [];
        foreach ($header as $i => $h) $map[mb_strtolower(trim((string)$h))] = $i;

        // prepare insert into attendance_raw (assumes attendance_raw exists with appropriate columns)
        $insertRawSql = "INSERT INTO attendance_raw
            (first_name, last_name, employee_number, department, att_date, att_time, weekday, data_source, device_name, device_serial, punch_state, location, remarks, source_file)
            VALUES
            (:first_name, :last_name, :employee_number, :department, :att_date, :att_time, :weekday, :data_source, :device_name, :device_serial, :punch_state, :location, :remarks, :source_file)";
        $stmtRaw = $db->prepare($insertRawSql);

        $rowsCount = 0;
        while (($row = fgetcsv($fh, 0, ';')) !== false) {
            $allEmpty = true;
            foreach ($row as $c) { if (trim((string)$c) !== '') { $allEmpty = false; break; } }
            if ($allEmpty) continue;

            $firstName = map_get($map, $row, 'first name');
            $lastName  = map_get($map, $row, 'last name');
            $empNum    = map_get($map, $row, 'id');
            $dept      = map_get($map, $row, 'department');
            $dateRaw   = map_get($map, $row, 'date');
            $timeRaw   = map_get($map, $row, 'time');
            $weekday   = map_get($map, $row, 'weekday');
            $dataSrc   = map_get($map, $row, 'data source');
            $deviceNm  = map_get($map, $row, 'device name');
            $deviceSn  = map_get($map, $row, 'device serial no.') ?: map_get($map,$row,'device serial n') ?: map_get($map,$row,'device serial');
            $punchSt   = map_get($map, $row, 'punch state');
            $location  = map_get($map, $row, 'location');
            $remarks   = map_get($map, $row, 'remarks');

            // parse date/time
            $attDate = null;
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateRaw)) $attDate = $dateRaw;
            elseif (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $dateRaw)) { [$d,$m,$y]=explode('/',$dateRaw); $attDate=sprintf('%04d-%02d-%02d',$y,$m,$d); }
            else { $ts=strtotime($dateRaw); if ($ts!==false) $attDate=date('Y-m-d',$ts); }
            if (!$attDate) continue;

            $attTime = null;
            if (preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $timeRaw)) {
                $attTime = (preg_match('/^\d{1,2}:\d{2}$/',$timeRaw) ? $timeRaw . ':00' : $timeRaw);
                $p = explode(':',$attTime);
                $attTime = sprintf('%02d:%02d:%02d',(int)$p[0],(int)$p[1],(int)($p[2]??0));
            } else {
                $ts = strtotime($timeRaw); if ($ts!==false) $attTime = date('H:i:s',$ts);
            }
            if (!$attTime) continue;

            $params = [
                ':first_name' => $firstName ?: null,
                ':last_name' => $lastName ?: null,
                ':employee_number' => $empNum ?: null,
                ':department' => $dept ?: null,
                ':att_date' => $attDate,
                ':att_time' => $attTime,
                ':weekday' => $weekday ?: null,
                ':data_source' => $dataSrc ?: null,
                ':device_name' => $deviceNm ?: null,
                ':device_serial' => $deviceSn ?: null,
                ':punch_state' => $punchSt ?: null,
                ':location' => $location ?: null,
                ':remarks' => $remarks ?: null,
                ':source_file' => $origName,
            ];

            try {
                $stmtRaw->execute($params);
                $rowsCount++;
            } catch (Throwable $e) {
                import_log("Raw insert failed: " . $e->getMessage());
                import_log("SQL: {$insertRawSql}");
                import_log("Params: " . json_encode($params));
                throw $e;
            }
        }
        fclose($fh);

        // Aggregate rows from attendance_raw for this file
        $aggSql = "
            SELECT
                employee_number,
                att_date,
                MIN(att_time) AS check_in,
                MAX(att_time) AS check_out,
                COUNT(*) AS punch_count,
                GROUP_CONCAT(DISTINCT remarks SEPARATOR '; ') AS remarks,
                GROUP_CONCAT(DISTINCT device_name SEPARATOR '; ') AS device_names,
                GROUP_CONCAT(DISTINCT device_serial SEPARATOR '; ') AS device_serials,
                GROUP_CONCAT(DISTINCT data_source SEPARATOR '; ') AS data_sources,
                GROUP_CONCAT(DISTINCT location SEPARATOR '; ') AS locations
            FROM attendance_raw
            WHERE source_file = :source_file
            GROUP BY employee_number, att_date
        ";
        $stAgg = $db->prepare($aggSql);
        $stAgg->execute([':source_file' => $origName]);
        $aggRows = $stAgg->fetchAll(PDO::FETCH_ASSOC);

        // Upsert into attendance using employee_id (resolve from users) and name fields
        $upsertSql = "
            INSERT INTO attendance
            (employee_id, employee_first_name, employee_last_name, department, att_date, check_in, check_out, punch_count, data_source, device_name, device_serial, location, remarks, source_file)
            VALUES
            (:employee_id, :employee_first_name, :employee_last_name, :department, :att_date, :check_in, :check_out, :punch_count, :data_source, :device_name, :device_serial, :location, :remarks, :source_file)
            ON DUPLICATE KEY UPDATE
              check_in = VALUES(check_in),
              check_out = VALUES(check_out),
              punch_count = VALUES(punch_count),
              data_source = VALUES(data_source),
              device_name = VALUES(device_name),
              device_serial = VALUES(device_serial),
              location = VALUES(location),
              remarks = VALUES(remarks),
              updated_at = NOW()
        ";
        $stmtUpsert = $db->prepare($upsertSql);

        $upserted = 0;
        foreach ($aggRows as $ar) {
            $empNum = trim((string)$ar['employee_number']);
            $employee_id = null;
            $first_name = null;
            $last_name = null;
            $department = null;

            if ($empNum !== '') {
                // try resolve user by employee_number or id
                $chk = $db->prepare("SELECT id, full_name FROM users WHERE employee_number = :num OR id = :num LIMIT 1");
                try {
                    $chk->execute([':num' => $empNum]);
                    $user = $chk->fetch(PDO::FETCH_ASSOC);
                    if ($user) {
                        $employee_id = (int)$user['id'];
                        // if your users table stores full_name, we still keep first/last from raw
                    }
                } catch (Throwable $e) {
                    import_log("User lookup failed: " . $e->getMessage());
                    import_log("Lookup params: " . $empNum);
                }
            }

            // pick sample raw row to get names/department when available
            $sample = $db->prepare("SELECT first_name,last_name,department FROM attendance_raw WHERE source_file = :sf AND employee_number = :en AND att_date = :d LIMIT 1");
            $sample->execute([':sf' => $origName, ':en' => $empNum, ':d' => $ar['att_date']]);
            $s = $sample->fetch(PDO::FETCH_ASSOC);
            if ($s) { $first_name = $s['first_name']; $last_name = $s['last_name']; $department = $s['department']; }
            // fallback: if no sample and empNum empty, try any row for date
            if (!$s && $empNum === '') {
                $sample2 = $db->prepare("SELECT first_name,last_name,department,employee_number FROM attendance_raw WHERE source_file = :sf AND att_date = :d LIMIT 1");
                $sample2->execute([':sf' => $origName, ':d' => $ar['att_date']]);
                $s2 = $sample2->fetch(PDO::FETCH_ASSOC);
                if ($s2) { $first_name = $s2['first_name']; $last_name = $s2['last_name']; $department = $s2['department']; }
            }

            $paramsUp = [
                ':employee_id' => $employee_id,
                ':employee_first_name' => $first_name ?: null,
                ':employee_last_name' => $last_name ?: null,
                ':department' => $department ?: null,
                ':att_date' => $ar['att_date'],
                ':check_in' => $ar['check_in'],
                ':check_out' => $ar['check_out'],
                ':punch_count' => (int)$ar['punch_count'],
                ':data_source' => $ar['data_sources'] ?: null,
                ':device_name' => $ar['device_names'] ?: null,
                ':device_serial' => $ar['device_serials'] ?: null,
                ':location' => $ar['locations'] ?: null,
                ':remarks' => $ar['remarks'] ?: null,
                ':source_file' => $origName,
            ];

            try {
                $stmtUpsert->execute($paramsUp);
                $upserted++;
            } catch (Throwable $e) {
                import_log("Upsert failed: " . $e->getMessage());
                import_log("Upsert SQL: {$upsertSql}");
                import_log("Upsert Params: " . json_encode($paramsUp));
                throw $e;
            }
        }

        $success = "تم حفظ {$rowsCount} صفّ(صفوف) خام، وتحديث {$upserted} سجل(سجلات) في جدول attendance.";
        import_log("Import finished: rows={$rowsCount}, upserted={$upserted}, file={$origName}");
    }
} catch (Throwable $ex) {
    $errors[] = 'حدث خطأ أثناء المعالجة — تم تسجيله في لوج الاستيراد.';
    import_log("Exception: " . $ex->getMessage() . " | Trace: " . $ex->getTraceAsString());
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head><meta charset="utf-8"><title>رفع حضور - Admin</title>
<style>
body{font-family:Tahoma,Arial;margin:20px;background:#f5f7fb}
.wrap{max-width:980px;margin:18px auto;background:#fff;padding:18px;border-radius:10px;box-shadow:0 8px 20px rgba(10,20,40,.06)}
h1{margin:0 0 12px;font-size:22px}
.form-row{margin-bottom:12px}
label{display:block;margin-bottom:6px;color:#333;font-weight:600}
input[type="file"]{display:block}
.button{background:#1976d2;color:#fff;padding:8px 12px;border-radius:8px;border:0;cursor:pointer}
.msg{padding:10px;border-radius:8px;margin-bottom:12px}
.err{background:#ffe8e8;color:#900;border:1px solid #ffbcbc}
.ok{background:#e8ffef;color:#083;border:1px solid #c7efd8}
.help{font-size:13px;color:#666;margin-top:6px}
.small{font-size:12px;color:#666}
a.link{color:#1976d2;text-decoration:none}
</style>
</head>
<body>
<div class="wrap">
    <div style="margin-bottom:12px">
      <a href="/admin/dashboard.php" class="button" style="text-decoration:none;display:inline-block">← الصفحة الرئيسية</a>
    </div>
    <h1>رفع ملف الحضور (CSV)</h1>

    <?php if (!empty($errors)): ?>
        <div class="msg err"><?php foreach ($errors as $er): ?><div><?php echo htmlspecialchars($er,ENT_QUOTES,'UTF-8'); ?></div><?php endforeach; ?>
            <div class="small">راجع ملف اللوج: <?php echo htmlspecialchars($logFile,ENT_QUOTES,'UTF-8'); ?></div>
        </div>
    <?php endif; ?>

    <?php if ($success !== ''): ?><div class="msg ok"><?php echo htmlspecialchars($success,ENT_QUOTES,'UTF-8'); ?></div><?php endif; ?>

    <form method="post" enctype="multipart/form-data" action="">
        <?php echo \Core\Csrf::inputField(); ?>
        <div class="form-row">
            <label>اختار ملف CSV (فاصل ; )</label>
            <input type="file" name="csv_file" accept=".csv,text/csv">
            <div class="help">تأكد أن صف الرأس يحتوي: <strong>First Name;Last Name;ID;Department;Date;Time;...</strong></div>
        </div>
        <div class="form-row">
            <button class="button" type="submit">رفع واستيراد</button>
            <a class="link" href="/admin/attendance_report.php" style="margin-left:12px">عرض تقرير الحضور</a>
        </div>
    </form>

    <div class="small">ملاحظة: بعد الاختبار أوقف display_errors في الملف.</div>
</div>
</body>
</html>
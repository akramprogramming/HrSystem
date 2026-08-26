<?php
declare(strict_types=1);

// مؤقت: فكّ التعليق لو احتجت عرض الأخطاء أثناء الاختبار
// ini_set('display_errors', '1');
// ini_set('display_startup_errors', '1');
// error_reporting(E_ALL);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Session.php';
require_once __DIR__ . '/../core/Auth.php';

use Core\Auth;
use Core\Database;
use Core\Session;

Session::start();
$auth = new Auth();
$auth->requireAdmin();

// مسار لوج الاستيراد (لتسجيل الأخطاء)
$import_log = __DIR__ . '/../storage/imports/import_errors.log';
function import_log_msg(string $m) {
    global $import_log;
    @file_put_contents($import_log, date('Y-m-d H:i:s') . " - " . $m . PHP_EOL, FILE_APPEND | LOCK_EX);
}

// قراءة مدخلات البحث
$qRaw = trim((string)($_GET['q'] ?? ''));
$qDept = trim((string)($_GET['department'] ?? ''));
$dateFrom = trim((string)($_GET['from'] ?? ''));
$dateTo = trim((string)($_GET['to'] ?? ''));
$export = isset($_GET['export']) && $_GET['export'] === 'csv';

try {
    $db = Database::getConnection();

    // نبني شروط WHERE باستخدام معلمات موضعية (؟) لتجنُّب مشكلات PDO في بيئات مختلفة
    $whereParts = [];
    $params = []; // مصفوفة مرتبة للقيم

    if ($qRaw !== '') {
        // إن كان الرقم رقماً كاملاً نضيف شرط مطابق لرقم الموظف أو employee_id
        if (ctype_digit($qRaw)) {
            $whereParts[] = "(u.employee_number = ? OR a.employee_id = ?)";
            $params[] = $qRaw;
            $params[] = (int)$qRaw;
        }
        // بحث جزئي في الأسماء واسم المستخدم
        $whereParts[] = "(u.full_name LIKE ? OR a.employee_first_name LIKE ? OR a.employee_last_name LIKE ? OR u.username LIKE ?)";
        $like = "%{$qRaw}%";
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    if ($qDept !== '') {
        $whereParts[] = "a.department LIKE ?";
        $params[] = "%{$qDept}%";
    }
    if ($dateFrom !== '') {
        $whereParts[] = "a.att_date >= ?";
        $params[] = $dateFrom;
    }
    if ($dateTo !== '') {
        $whereParts[] = "a.att_date <= ?";
        $params[] = $dateTo;
    }

    $whereSql = '';
    if (!empty($whereParts)) {
        $whereSql = ' WHERE ' . implode(' AND ', $whereParts);
    }

    // استعلام رئيسي — لا نفترض وجود عمود a.employee_number
    $sql = "
        SELECT a.*, u.full_name AS user_full_name, u.username AS user_username, u.employee_number AS user_employee_number
        FROM attendance a
        LEFT JOIN users u ON u.id = a.employee_id
        {$whereSql}
        ORDER BY a.att_date DESC, COALESCE(u.full_name, a.employee_first_name) ASC
        LIMIT 500
    ";

    $st = $db->prepare($sql);
    $st->execute($params); // نمرّر المصفوفة المرتبة لمعادلة علامات السؤال

    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    // قائمة الموظفين للاقتراحات (داتاليست) — محاولة آمنة
    try {
        $emps = $db->query("SELECT id, full_name, username, employee_number FROM users ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $_) {
        $emps = [];
    }

    // تصدير CSV إن طُلب
    if ($export) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="attendance_export_' . date('Ymd_His') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // BOM
        fputcsv($out, ['Employee Number','Employee ID','Name','Username','Department','Date','Check In','Check Out','Punch Count','Device','Device Serial','Location','Remarks','Source File'], ';');
        foreach ($rows as $r) {
            $empNum = $r['user_employee_number'] ?? ($r['employee_number'] ?? '');
            $empId = $r['employee_id'] ?? '';
            $name = $r['user_full_name'] ?: trim((($r['employee_first_name'] ?? '') . ' ' . ($r['employee_last_name'] ?? '')));
            fputcsv($out, [
                $empNum,
                $empId,
                $name,
                $r['user_username'] ?? '',
                $r['department'] ?? '',
                $r['att_date'] ?? '',
                $r['check_in'] ?? '',
                $r['check_out'] ?? '',
                $r['punch_count'] ?? '',
                $r['device_name'] ?? '',
                $r['device_serial'] ?? '',
                $r['location'] ?? '',
                $r['remarks'] ?? '',
                $r['source_file'] ?? '',
            ], ';');
        }
        fclose($out);
        exit;
    }

} catch (Throwable $ex) {
    // سجّل الخطأ التفصيلي لسهولة التشخيص
    import_log_msg("attendance_report.php exception: " . $ex->getMessage());
    import_log_msg("Trace: " . $ex->getTraceAsString());
    import_log_msg("GET: " . json_encode($_GET));
    http_response_code(500);
    echo '<div style="padding:18px;background:#fee;border:1px solid #fcc;border-radius:6px;margin:18px">حدث خطأ أثناء معالجة الطلب — تم تسجيله، راجع اللوج.</div>';
    exit;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<title>تقرير الحضور والانصراف</title>
<style>
body{font-family:Tahoma,Arial;margin:18px}
form.filters{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px}
input,select{padding:6px;border:1px solid #ccc;border-radius:6px}
table{width:100%;border-collapse:collapse}
th,td{padding:6px;border:1px solid #eee;text-align:right;font-size:13px}
th{background:#fafafa}
a.btn{display:inline-block;padding:6px 10px;background:#1976d2;color:#fff;border-radius:6px;text-decoration:none}
.results-note{margin-bottom:12px;color:#333}
</style>
</head>
<body>
<h2>تقرير الحضور والانصراف</h2>

<form method="get" class="filters" action="">
    <label style="display:block;">
        بحث باسم الموظف أو رقم الموظف:
        <input list="emps_list" name="q" value="<?php echo htmlspecialchars($qRaw,ENT_QUOTES,'UTF-8'); ?>" placeholder="ادخل اسم أو رقم الموظف" style="min-width:260px">
        <datalist id="emps_list">
            <?php foreach ($emps as $e):
                $val = trim((string)($e['employee_number'] ?? ''));
                if ($val === '') continue;
            ?>
                <option value="<?php echo htmlspecialchars($val,ENT_QUOTES,'UTF-8'); ?>"><?php echo htmlspecialchars(($e['full_name'] ?? '') . ' (' . $val . ')',ENT_QUOTES,'UTF-8'); ?></option>
            <?php endforeach; ?>
        </datalist>
    </label>

    <label>
        القسم:
        <input type="text" name="department" value="<?php echo htmlspecialchars($qDept,ENT_QUOTES,'UTF-8'); ?>">
    </label>

    <label>
        من:
        <input type="date" name="from" value="<?php echo htmlspecialchars($dateFrom,ENT_QUOTES,'UTF-8'); ?>">
    </label>
    <label>
        الى:
        <input type="date" name="to" value="<?php echo htmlspecialchars($dateTo,ENT_QUOTES,'UTF-8'); ?>">
    </label>

    <button type="submit" class="btn">عرض</button>
    <a class="btn" href="?<?php echo htmlspecialchars(http_build_query(array_merge($_GET,['export'=>'csv'])),ENT_QUOTES,'UTF-8'); ?>">تصدير CSV</a>
    <a class="btn" href="/admin/upload_attendance.php" style="background:#6c757d;margin-left:8px">صفحة الرفع</a>
            <a class="btn btn-gray" href="/admin/dashboard.php">لوحة المدير</a>
</form>

<div class="results-note">
    <?php $countRows = is_array($rows) ? count($rows) : 0; echo "النتائج: " . $countRows . " سجل(سجلات)."; ?>
</div>

<table>
    <thead>
        <tr>
            <th>#</th>
            <th>رقم الموظف</th>
            <th>الموظف</th>
            <th>القسم</th>
            <th>التاريخ</th>
            <th>حضور</th>
            <th>انصراف</th>
            <th>عدد القرعات</th>
            <th>جهاز</th>
            <th>ملاحظات</th>
            <th>ملف المصدر</th>
        </tr>
    </thead>
    <tbody>
    <?php if (empty($rows)): ?>
        <tr><td colspan="11" style="text-align:center">لا توجد سجلات</td></tr>
    <?php else: foreach ($rows as $i => $r): ?>
        <tr>
            <td><?php echo $i+1; ?></td>
            <td><?php echo htmlspecialchars($r['user_employee_number'] ?? ($r['employee_number'] ?? ''),ENT_QUOTES,'UTF-8'); ?></td>
            <td>
                <?php $name = $r['user_full_name'] ?: trim((($r['employee_first_name'] ?? '') . ' ' . ($r['employee_last_name'] ?? ''))); echo htmlspecialchars($name,ENT_QUOTES,'UTF-8'); ?>
                <div style="font-size:11px;color:#666"><?php echo htmlspecialchars($r['user_username'] ?? '-',ENT_QUOTES,'UTF-8'); ?></div>
            </td>
            <td><?php echo htmlspecialchars($r['department'] ?? '-',ENT_QUOTES,'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars($r['att_date'] ?? '-',ENT_QUOTES,'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars($r['check_in'] ?? '-',ENT_QUOTES,'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars($r['check_out'] ?? '-',ENT_QUOTES,'UTF-8'); ?></td>
            <td><?php echo (int)($r['punch_count'] ?? 0); ?></td>
            <td><?php echo htmlspecialchars($r['device_name'] ?? '-',ENT_QUOTES,'UTF-8'); ?></td>
            <td style="max-width:220px;white-space:normal"><?php echo nl2br(htmlspecialchars($r['remarks'] ?? '-',ENT_QUOTES,'UTF-8')); ?></td>
            <td><?php echo htmlspecialchars($r['source_file'] ?? '-',ENT_QUOTES,'UTF-8'); ?></td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>

</body>
</html>
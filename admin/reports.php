<?php
declare(strict_types=1);

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

$db = Database::getConnection();

$errors = [];

/**
 * Helpers
 */
function statusLabel(string $s): string {
    return match ($s) {
        'pending'     => 'قيد الانتظار',
        'in_progress' => 'قيد التنفيذ',
        'done'        => 'منتهية',
        default       => $s
    };
}
function statusClass(string $s): string {
    return match ($s) {
        'pending'     => 'b-pending',
        'in_progress' => 'b-progress',
        'done'        => 'b-done',
        default       => 'b-pending'
    };
}
function priorityLabel(string $p): string {
    return match ($p) {
        'low'    => 'منخفضة',
        'medium' => 'متوسطة',
        'high'   => 'عالية',
        default  => $p
    };
}
function priorityClass(string $p): string {
    return match ($p) {
        'low'    => 'p-low',
        'medium' => 'p-medium',
        'high'   => 'p-high',
        default  => ''
    };
}
function validDate(?string $d): ?string {
    if (!$d) return null;
    $dt = \DateTime::createFromFormat('Y-m-d', $d);
    return ($dt && $dt->format('Y-m-d') === $d) ? $d : null;
}

/**
 * 0) تحميل الموظفين للفلاتر
 */
$employees = [];
try {
    $st = $db->query("
        SELECT id, full_name, username
        FROM users
        WHERE role='user' AND is_active=1
        ORDER BY full_name ASC
    ");
    $employees = $st->fetchAll();
} catch (Throwable $e) {
    $errors[] = 'خطأ في تحميل قائمة الموظفين: ' . $e->getMessage();
}

/**
 * 1) قراءة الفلاتر
 */
$filterFrom     = validDate($_GET['date_from'] ?? null);
$filterTo       = validDate($_GET['date_to'] ?? null);
$filterEmployee = isset($_GET['employee_id']) && ctype_digit((string)$_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;
$filterStatus   = $_GET['status'] ?? '';
$filterPriority = $_GET['priority'] ?? '';

$allowedStatus = ['pending', 'in_progress', 'done'];
$allowedPriority = ['low', 'medium', 'high'];

if ($filterStatus !== '' && !in_array($filterStatus, $allowedStatus, true)) {
    $filterStatus = '';
}
if ($filterPriority !== '' && !in_array($filterPriority, $allowedPriority, true)) {
    $filterPriority = '';
}
if ($filterFrom && $filterTo && $filterFrom > $filterTo) {
    $errors[] = 'تاريخ "من" يجب أن يكون قبل أو يساوي تاريخ "إلى".';
}

/**
 * تجهيز WHERE ديناميكي (نطبّقه على created_at)
 */
$where = [];
$params = [];

if ($filterFrom) {
    $where[] = "DATE(t.created_at) >= :date_from";
    $params[':date_from'] = $filterFrom;
}
if ($filterTo) {
    $where[] = "DATE(t.created_at) <= :date_to";
    $params[':date_to'] = $filterTo;
}
if ($filterEmployee > 0) {
    $where[] = "t.assigned_to = :employee_id";
    $params[':employee_id'] = $filterEmployee;
}
if ($filterStatus !== '') {
    $where[] = "t.status = :status";
    $params[':status'] = $filterStatus;
}
if ($filterPriority !== '') {
    $where[] = "t.priority = :priority";
    $params[':priority'] = $filterPriority;
}

$tasksWhereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

/**
 * 2) إحصائيات عامة (وفق الفلاتر)
 */
$stats = [
    'total_tasks'       => 0,
    'pending_tasks'     => 0,
    'in_progress_tasks' => 0,
    'done_tasks'        => 0,
    'overdue_tasks'     => 0,
];

try {
    $sql = "
        SELECT
            COUNT(*) AS total_tasks,
            SUM(CASE WHEN t.status='pending' THEN 1 ELSE 0 END) AS pending_tasks,
            SUM(CASE WHEN t.status='in_progress' THEN 1 ELSE 0 END) AS in_progress_tasks,
            SUM(CASE WHEN t.status='done' THEN 1 ELSE 0 END) AS done_tasks,
            SUM(CASE
                WHEN t.due_date IS NOT NULL
                 AND t.due_date < CURDATE()
                 AND t.status <> 'done'
                THEN 1 ELSE 0
            END) AS overdue_tasks
        FROM tasks t
        {$tasksWhereSql}
    ";
    $st = $db->prepare($sql);
    $st->execute($params);
    $row = $st->fetch() ?: [];

    $stats['total_tasks']       = (int)($row['total_tasks'] ?? 0);
    $stats['pending_tasks']     = (int)($row['pending_tasks'] ?? 0);
    $stats['in_progress_tasks'] = (int)($row['in_progress_tasks'] ?? 0);
    $stats['done_tasks']        = (int)($row['done_tasks'] ?? 0);
    $stats['overdue_tasks']     = (int)($row['overdue_tasks'] ?? 0);
} catch (Throwable $e) {
    $errors[] = 'خطأ في تحميل الإحصائيات العامة: ' . $e->getMessage();
}

/**
 * 3) تقرير الموظفين (وفق نفس الفلاتر)
 */
$employeeReport = [];
try {
    $whereEmp = [];
    $paramsEmp = [];

    if ($filterFrom) {
        $whereEmp[] = "DATE(t.created_at) >= :date_from";
        $paramsEmp[':date_from'] = $filterFrom;
    }
    if ($filterTo) {
        $whereEmp[] = "DATE(t.created_at) <= :date_to";
        $paramsEmp[':date_to'] = $filterTo;
    }
    if ($filterEmployee > 0) {
        $whereEmp[] = "t.assigned_to = :employee_id";
        $paramsEmp[':employee_id'] = $filterEmployee;
    }
    if ($filterStatus !== '') {
        $whereEmp[] = "t.status = :status";
        $paramsEmp[':status'] = $filterStatus;
    }
    if ($filterPriority !== '') {
        $whereEmp[] = "t.priority = :priority";
        $paramsEmp[':priority'] = $filterPriority;
    }

    $joinFilter = $whereEmp ? (' AND ' . implode(' AND ', $whereEmp)) : '';

    $sql = "
        SELECT
            u.id,
            u.full_name,
            u.username,
            COUNT(t.id) AS total_tasks,
            SUM(CASE WHEN t.status='pending' THEN 1 ELSE 0 END) AS pending_tasks,
            SUM(CASE WHEN t.status='in_progress' THEN 1 ELSE 0 END) AS in_progress_tasks,
            SUM(CASE WHEN t.status='done' THEN 1 ELSE 0 END) AS done_tasks,
            SUM(CASE
                WHEN t.due_date IS NOT NULL
                 AND t.due_date < CURDATE()
                 AND t.status <> 'done'
                THEN 1 ELSE 0
            END) AS overdue_tasks
        FROM users u
        LEFT JOIN tasks t
               ON t.assigned_to = u.id
              {$joinFilter}
        WHERE u.role='user' AND u.is_active=1
        GROUP BY u.id, u.full_name, u.username
        ORDER BY total_tasks DESC, u.full_name ASC
    ";
    $st = $db->prepare($sql);
    $st->execute($paramsEmp);
    $employeeReport = $st->fetchAll();
} catch (Throwable $e) {
    $errors[] = 'خطأ في تحميل تقرير الموظفين: ' . $e->getMessage();
}

/**
 * 4) المهام المتأخرة (وفق الفلاتر + شرط التأخير)
 */
$overdueList = [];
try {
    $whereOver = $where;
    $paramsOver = $params;

    $whereOver[] = "t.due_date IS NOT NULL";
    $whereOver[] = "t.due_date < CURDATE()";
    $whereOver[] = "t.status <> 'done'";

    $overSql = 'WHERE ' . implode(' AND ', $whereOver);

    $sql = "
        SELECT
            t.id, t.title, t.status, t.priority, t.start_date, t.due_date, t.created_at,
            u.full_name AS assigned_name, u.username AS assigned_username
        FROM tasks t
        INNER JOIN users u ON u.id = t.assigned_to
        {$overSql}
        ORDER BY t.due_date ASC
        LIMIT 50
    ";
    $st = $db->prepare($sql);
    $st->execute($paramsOver);
    $overdueList = $st->fetchAll();
} catch (Throwable $e) {
    $errors[] = 'خطأ في تحميل قائمة المهام المتأخرة: ' . $e->getMessage();
}

/** روابط التصدير بنفس الفلاتر الحالية */
$queryString = http_build_query([
    'date_from'   => $filterFrom ?? '',
    'date_to'     => $filterTo ?? '',
    'employee_id' => $filterEmployee ?: '',
    'status'      => $filterStatus ?? '',
    'priority'    => $filterPriority ?? '',
]);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>التقارير</title>
    <style>
        body { margin:0; font-family:Tahoma,Arial,sans-serif; background:#f4f6f9; }
        .container { max-width:1200px; margin:24px auto; padding:0 12px; }

        .top, .card, .box {
            background:#fff; border-radius:12px; box-shadow:0 8px 22px rgba(0,0,0,.07);
        }
        .top { padding:18px 20px; margin-bottom:16px; }
        .top h1 { margin:0; font-size:26px; }

        .stats {
            display:grid; grid-template-columns:repeat(5,1fr); gap:12px; margin-bottom:16px;
        }
        .card { padding:14px; }
        .label { color:#666; font-size:13px; }
        .num { font-size:26px; font-weight:bold; margin-top:6px; }

        .box { padding:16px; margin-bottom:16px; }
        .box h3 { margin:0 0 12px; }

        .btn {
            border:0; border-radius:8px; padding:9px 12px; cursor:pointer;
            color:#fff; background:#1976d2; text-decoration:none; display:inline-block; font-size:14px;
        }
        .btn:hover { background:#145ca3; }
        .btn-gray { background:#6c757d; }
        .top-actions { margin-top:10px; display:flex; gap:8px; flex-wrap:wrap; }

        .filters { display:grid; grid-template-columns: repeat(5, 1fr); gap:10px; align-items:end; }
        .fg label { display:block; margin-bottom:6px; font-size:13px; color:#444; }
        .fg input, .fg select {
            width:100%; box-sizing:border-box; border:1px solid #d7dce3; border-radius:8px;
            padding:8px 10px; background:#fff;
        }
        .filter-actions { display:flex; gap:8px; }

        .msg-error { background:#ffe8e8; color:#b30000; border:1px solid #ffcccc; border-radius:8px; padding:10px; margin-bottom:10px; }

        table { width:100%; border-collapse:collapse; }
        th, td { padding:10px; border-bottom:1px solid #eee; text-align:right; vertical-align:top; font-size:14px; }
        th { background:#fafbfc; }

        .badge { padding:4px 8px; border-radius:12px; color:#fff; font-size:12px; }
        .b-pending { background:#6c757d; }
        .b-progress { background:#ef6c00; }
        .b-done { background:#198754; }

        .p-low { color:#198754; font-weight:bold; }
        .p-medium { color:#ef6c00; font-weight:bold; }
        .p-high { color:#c62828; font-weight:bold; }

        .empty { color:#666; text-align:center; padding:12px; }

        @media (max-width: 1100px) {
            .stats, .filters { grid-template-columns:1fr 1fr; }
            table, thead, tbody, th, td, tr { display:block; }
            th { display:none; }
            tr { border:1px solid #eee; border-radius:10px; margin-bottom:10px; padding:8px; background:#fff; }
            td { border:none; padding:6px 4px; }
            td::before { content: attr(data-label) ": "; font-weight:bold; color:#333; }
        }
        @media (max-width: 650px) {
            .stats, .filters { grid-template-columns:1fr; }
            .filter-actions { flex-wrap:wrap; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="top">
        <h1>التقارير</h1>
        <div class="top-actions">
            <a class="btn btn-gray" href="/admin/dashboard.php">لوحة المدير</a>
            <a class="btn" href="/admin/tasks_list.php">عرض المهام</a>

            <a class="btn" href="/admin/reports_export_csv.php?type=employees&<?php echo $queryString; ?>">تصدير الموظفين CSV</a>
            <a class="btn" href="/admin/reports_export_csv.php?type=overdue&<?php echo $queryString; ?>">تصدير المتأخرة CSV</a>
            <a class="btn" href="/admin/reports_export_csv.php?type=all_tasks&<?php echo $queryString; ?>">تصدير كل المهام CSV</a>
        </div>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="msg-error">
            <?php foreach ($errors as $err): ?>
                <div>• <?php echo htmlspecialchars($err, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="box">
        <h3>فلاتر التقرير</h3>
        <form method="get" action="">
            <div class="filters">
                <div class="fg">
                    <label for="date_from">من تاريخ (حسب تاريخ الإنشاء)</label>
                    <input type="date" id="date_from" name="date_from" value="<?php echo htmlspecialchars((string)($filterFrom ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="fg">
                    <label for="date_to">إلى تاريخ (حسب تاريخ الإنشاء)</label>
                    <input type="date" id="date_to" name="date_to" value="<?php echo htmlspecialchars((string)($filterTo ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="fg">
                    <label for="employee_id">الموظف</label>
                    <select id="employee_id" name="employee_id">
                        <option value="">الكل</option>
                        <?php foreach ($employees as $emp): ?>
                            <option value="<?php echo (int)$emp['id']; ?>" <?php echo ($filterEmployee === (int)$emp['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars((string)$emp['full_name'], ENT_QUOTES, 'UTF-8'); ?>
                                (<?php echo htmlspecialchars((string)$emp['username'], ENT_QUOTES, 'UTF-8'); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="fg">
                    <label for="status">الحالة</label>
                    <select id="status" name="status">
                        <option value="">الكل</option>
                        <option value="pending" <?php echo $filterStatus==='pending' ? 'selected' : ''; ?>>قيد الانتظار</option>
                        <option value="in_progress" <?php echo $filterStatus==='in_progress' ? 'selected' : ''; ?>>قيد التنفيذ</option>
                        <option value="done" <?php echo $filterStatus==='done' ? 'selected' : ''; ?>>منتهية</option>
                    </select>
                </div>
                <div class="fg">
                    <label for="priority">الأولوية</label>
                    <select id="priority" name="priority">
                        <option value="">الكل</option>
                        <option value="low" <?php echo $filterPriority==='low' ? 'selected' : ''; ?>>منخفضة</option>
                        <option value="medium" <?php echo $filterPriority==='medium' ? 'selected' : ''; ?>>متوسطة</option>
                        <option value="high" <?php echo $filterPriority==='high' ? 'selected' : ''; ?>>عالية</option>
                    </select>
                </div>
            </div>
            <div class="filter-actions" style="margin-top:12px;">
                <button type="submit" class="btn">تطبيق الفلاتر</button>
                <a class="btn btn-gray" href="/admin/reports.php">إعادة تعيين</a>
            </div>

            <div class="top-actions" style="margin-top:10px;">
                <a class="btn" href="/admin/reports_export_csv.php?type=employees&<?php echo $queryString; ?>">تصدير الموظفين CSV</a>
                <a class="btn" href="/admin/reports_export_csv.php?type=overdue&<?php echo $queryString; ?>">تصدير المتأخرة CSV</a>
                <a class="btn" href="/admin/reports_export_csv.php?type=all_tasks&<?php echo $queryString; ?>">تصدير كل المهام CSV</a>
            </div>
        </form>
    </div>

    <div class="stats">
        <div class="card"><div class="label">إجمالي المهام</div><div class="num"><?php echo (int)$stats['total_tasks']; ?></div></div>
        <div class="card"><div class="label">قيد الانتظار</div><div class="num"><?php echo (int)$stats['pending_tasks']; ?></div></div>
        <div class="card"><div class="label">قيد التنفيذ</div><div class="num"><?php echo (int)$stats['in_progress_tasks']; ?></div></div>
        <div class="card"><div class="label">منتهية</div><div class="num"><?php echo (int)$stats['done_tasks']; ?></div></div>
        <div class="card"><div class="label">متأخرة</div><div class="num"><?php echo (int)$stats['overdue_tasks']; ?></div></div>
    </div>

    <div class="box">
        <h3>تقرير الموظفين</h3>
        <?php if (empty($employeeReport)): ?>
            <div class="empty">لا توجد بيانات.</div>
        <?php else: ?>
            <table>
                <thead>
                <tr>
                    <th>الموظف</th><th>إجمالي المهام</th><th>قيد الانتظار</th><th>قيد التنفيذ</th><th>منتهية</th><th>متأخرة</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($employeeReport as $r): ?>
                    <tr>
                        <td data-label="الموظف"><?php echo htmlspecialchars((string)$r['full_name'], ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars((string)$r['username'], ENT_QUOTES, 'UTF-8'); ?>)</td>
                        <td data-label="إجمالي المهام"><?php echo (int)$r['total_tasks']; ?></td>
                        <td data-label="قيد الانتظار"><?php echo (int)$r['pending_tasks']; ?></td>
                        <td data-label="قيد التنفيذ"><?php echo (int)$r['in_progress_tasks']; ?></td>
                        <td data-label="منتهية"><?php echo (int)$r['done_tasks']; ?></td>
                        <td data-label="متأخرة"><?php echo (int)$r['overdue_tasks']; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="box">
        <h3>المهام المتأخرة</h3>
        <?php if (empty($overdueList)): ?>
            <div class="empty">ممتاز 🎉 لا توجد مهام متأخرة حاليًا.</div>
        <?php else: ?>
            <table>
                <thead>
                <tr>
                    <th>#</th><th>العنوان</th><th>الموظف</th><th>الأولوية</th><th>الحالة</th><th>تاريخ التسليم</th><th>إجراء</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($overdueList as $t): ?>
                    <tr>
                        <td data-label="#"><?php echo (int)$t['id']; ?></td>
                        <td data-label="العنوان"><?php echo htmlspecialchars((string)$t['title'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td data-label="الموظف"><?php echo htmlspecialchars((string)$t['assigned_name'], ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars((string)$t['assigned_username'], ENT_QUOTES, 'UTF-8'); ?>)</td>
                        <td data-label="الأولوية"><span class="<?php echo priorityClass((string)$t['priority']); ?>"><?php echo htmlspecialchars(priorityLabel((string)$t['priority']), ENT_QUOTES, 'UTF-8'); ?></span></td>
                        <td data-label="الحالة"><span class="badge <?php echo statusClass((string)$t['status']); ?>"><?php echo htmlspecialchars(statusLabel((string)$t['status']), ENT_QUOTES, 'UTF-8'); ?></span></td>
                        <td data-label="تاريخ التسليم"><?php echo htmlspecialchars((string)$t['due_date'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td data-label="إجراء"><a class="btn" href="/admin/tasks_edit.php?id=<?php echo (int)$t['id']; ?>">تعديل</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
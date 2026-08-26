<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Session.php';
require_once __DIR__ . '/../core/Csrf.php';
require_once __DIR__ . '/../core/Auth.php';

use Core\Auth;
use Core\Csrf;
use Core\Database;
use Core\Session;
use PDO;

Session::start();
$auth = new Auth();
$auth->requireAdmin();

$db = Database::getConnection();

$errors = [];
$success = '';

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function validDate(?string $d): ?string {
    if (!$d) return null;
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return ($dt && $dt->format('Y-m-d') === $d) ? $d : null;
}
function statusLabel(string $s): string {
    return match ($s) {
        'pending' => 'قيد الانتظار',
        'in_progress' => 'قيد التنفيذ',
        'done' => 'منتهية',
        default => $s
    };
}
function statusClass(string $s): string {
    return match ($s) {
        'pending' => 'b-pending',
        'in_progress' => 'b-progress',
        'done' => 'b-done',
        default => 'b-pending'
    };
}
function priorityLabel(string $p): string {
    return match ($p) {
        'low' => 'منخفضة',
        'medium' => 'متوسطة',
        'high' => 'عالية',
        default => $p
    };
}
function priorityClass(string $p): string {
    return match ($p) {
        'low' => 'p-low',
        'medium' => 'p-medium',
        'high' => 'p-high',
        default => ''
    };
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Csrf::verifyOrFail();

    $action = (string)($_POST['action'] ?? '');
    $taskId = (int)($_POST['task_id'] ?? 0);

    if ($action === 'delete_task') {
        if ($taskId <= 0) {
            $errors[] = 'معرّف المهمة غير صالح.';
        } else {
            try {
                $st = $db->prepare("SELECT title FROM tasks WHERE id = :id LIMIT 1");
                $st->execute([':id' => $taskId]);
                $task = $st->fetch(PDO::FETCH_ASSOC);

                if (!$task) {
                    $errors[] = 'المهمة غير موجودة.';
                } else {
                    $del = $db->prepare("DELETE FROM tasks WHERE id = :id");
                    $del->execute([':id' => $taskId]);
                    $success = 'تم حذف المهمة بنجاح.';
                }
            } catch (Throwable $e) {
                $errors[] = 'حدث خطأ أثناء الحذف: ' . $e->getMessage();
            }
        }
    } else {
        $errors[] = 'إجراء غير معروف.';
    }
}

$q         = trim((string)($_GET['q'] ?? ''));
$fStatus   = trim((string)($_GET['status'] ?? ''));
$fPrio     = trim((string)($_GET['priority'] ?? ''));
$fUser     = (int)($_GET['assigned_to'] ?? 0);
$fDateFrom = validDate($_GET['date_from'] ?? null);
$fDateTo   = validDate($_GET['date_to'] ?? null);

$allowedStatus = ['pending', 'in_progress', 'done'];
$allowedPrio   = ['low', 'medium', 'high'];

if ($fStatus !== '' && !in_array($fStatus, $allowedStatus, true)) $fStatus = '';
if ($fPrio !== '' && !in_array($fPrio, $allowedPrio, true)) $fPrio = '';
if ($fDateFrom && $fDateTo && $fDateFrom > $fDateTo) $errors[] = 'تاريخ "من" يجب أن يكون قبل أو يساوي تاريخ "إلى".';

$page = isset($_GET['page']) && ctype_digit((string)$_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 10;
$offset = ($page - 1) * $perPage;

$employees = [];
try {
    $empSt = $db->query("
        SELECT id, full_name, username
        FROM users
        WHERE role = 'user' AND is_active = 1
        ORDER BY full_name ASC
    ");
    $employees = $empSt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $errors[] = 'تعذر تحميل الموظفين: ' . $e->getMessage();
}

$where = [];
$params = [];

if ($q !== '') {
    $where[] = "(t.title LIKE :q OR t.description LIKE :q OR t.notes LIKE :q)";
    $params[':q'] = "%{$q}%";
}
if ($fStatus !== '') {
    $where[] = "t.status = :status";
    $params[':status'] = $fStatus;
}
if ($fPrio !== '') {
    $where[] = "t.priority = :priority";
    $params[':priority'] = $fPrio;
}
if ($fUser > 0) {
    $where[] = "t.assigned_to = :assigned_to";
    $params[':assigned_to'] = $fUser;
}
if ($fDateFrom) {
    $where[] = "DATE(t.created_at) >= :date_from";
    $params[':date_from'] = $fDateFrom;
}
if ($fDateTo) {
    $where[] = "DATE(t.created_at) <= :date_to";
    $params[':date_to'] = $fDateTo;
}

$whereSql = !empty($where) ? (' WHERE ' . implode(' AND ', $where)) : '';

$totalRows = 0;
$totalPages = 1;
try {
    $countSql = "SELECT COUNT(*) FROM tasks t {$whereSql}";
    $countSt = $db->prepare($countSql);
    $countSt->execute($params);
    $totalRows = (int)$countSt->fetchColumn();
    $totalPages = max(1, (int)ceil($totalRows / $perPage));

    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $perPage;
    }
} catch (Throwable $e) {
    $errors[] = 'تعذر حساب عدد النتائج: ' . $e->getMessage();
}

$tasks = [];
try {
    $sql = "
        SELECT
            t.id, t.title, t.description, t.notes, t.image_path,
            t.priority, t.status, t.start_date, t.due_date, t.created_at,
            t.employee_update_notes, t.employee_update_image_path, t.employee_update_at,
            u.full_name AS assigned_name, u.username AS assigned_username,
            c.full_name AS creator_name
        FROM tasks t
        INNER JOIN users u ON u.id = t.assigned_to
        LEFT JOIN users c ON c.id = t.created_by
        {$whereSql}
        ORDER BY t.id DESC
        LIMIT :limit OFFSET :offset
    ";

    $st = $db->prepare($sql);
    foreach ($params as $k => $v) {
        $st->bindValue($k, $v);
    }
    $st->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $st->bindValue(':offset', $offset, PDO::PARAM_INT);

    $st->execute();
    $tasks = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $errors[] = 'تعذر تحميل المهام: ' . $e->getMessage();
}

$qs = $_GET; unset($qs['page']);
$baseQuery = http_build_query($qs);
$baseUrl = '/admin/tasks_list.php' . ($baseQuery !== '' ? ('?' . $baseQuery . '&') : '?');

$exportQuery = http_build_query([
    'date_from'   => $fDateFrom ?? '',
    'date_to'     => $fDateTo ?? '',
    'employee_id' => $fUser > 0 ? $fUser : '',
    'status'      => $fStatus ?? '',
    'priority'    => $fPrio ?? '',
]);
$exportEmployeesUrl = '/admin/reports_export_csv.php?type=employees' . ($exportQuery !== '' ? '&' . $exportQuery : '');
$exportOverdueUrl   = '/admin/reports_export_csv.php?type=overdue' . ($exportQuery !== '' ? '&' . $exportQuery : '');
$exportAllTasksUrl  = '/admin/reports_export_csv.php?type=all_tasks' . ($exportQuery !== '' ? '&' . $exportQuery : '');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>إدارة المهام</title>
<style>
body{margin:0;font-family:Tahoma,Arial,sans-serif;background:#f4f6f9}
.container{max-width:1250px;margin:24px auto;background:#fff;border-radius:12px;padding:20px;box-shadow:0 8px 22px rgba(0,0,0,.08)}
h2{margin:0 0 16px}
.msg-error{background:#ffe8e8;color:#b30000;border:1px solid #ffcccc;border-radius:8px;padding:10px;margin-bottom:10px}
.msg-ok{background:#e8ffef;color:#0f6d2f;border:1px solid #bdeccb;border-radius:8px;padding:10px;margin-bottom:10px}
.filters{display:grid;grid-template-columns:2fr 1fr 1fr 1fr 1fr 1fr auto;gap:8px;margin-bottom:12px}
input[type="text"],input[type="date"],select{width:100%;padding:9px;border:1px solid #ccd3db;border-radius:8px;box-sizing:border-box}
.btn{border:0;border-radius:8px;padding:9px 12px;cursor:pointer;color:#fff;background:#1976d2;text-decoration:none;display:inline-block;font-size:14px}
.btn:hover{background:#145ca3}.btn-green{background:#1b8f3a}.btn-red{background:#c62828}.btn-gray{background:#6c757d}.btn-orange{background:#ef6c00}
.top-actions{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px}
.small{font-size:12px;color:#666;margin-bottom:10px}
table{width:100%;border-collapse:collapse}
th,td{padding:10px;border-bottom:1px solid #eee;text-align:right;vertical-align:top;font-size:14px}
th{background:#fafbfc}
.badge{padding:4px 8px;border-radius:12px;color:#fff;font-size:12px}
.b-pending{background:#6c757d}.b-progress{background:#ef6c00}.b-done{background:#198754}
.p-low{color:#198754;font-weight:bold}.p-medium{color:#ef6c00;font-weight:bold}.p-high{color:#c62828;font-weight:bold}
.actions{display:flex;gap:6px;flex-wrap:wrap}.inline{display:inline}.empty{text-align:center;color:#666;padding:18px}
.pagination{display:flex;gap:6px;flex-wrap:wrap;margin-top:14px}
.page-link{padding:7px 10px;border-radius:8px;text-decoration:none;font-size:13px;border:1px solid #d7dce3;color:#333;background:#fff}
.page-link.active{background:#1976d2;color:#fff;border-color:#1976d2}

/* Thumbnails */
.thumb{width:54px;height:54px;object-fit:cover;border-radius:8px;border:1px solid #ddd;display:block;margin-bottom:4px}
.thumb-sm{width:28px;height:28px;object-fit:cover;border-radius:6px;border:1px solid #ddd;display:inline-block;margin-left:8px}

/* Notes layout: place creator and employee update side-by-side */
.notes-cell{display:flex;gap:8px;align-items:center;min-width:0}
.notes-block{display:flex;flex-direction:column;gap:2px;min-width:0}
.note-title{font-size:10px;color:#444;font-weight:700}
.note-text{font-size:8px;color:#111;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:120px}
.note-meta{font-size:8px;color:#666;white-space:nowrap}

/* employee update compact */
.employee-update{background:#fafbfc;padding:4px;border-radius:8px;display:flex;gap:6px;align-items:center;min-width:0}
.employee-note{font-size:8px;color:#111;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100px}

/* --- Force very compact size (8px) for the requested columns --- */
td[data-label="الموظف"],
td[data-label="الأولوية"],
td[data-label="الحالة"],
td[data-label="البدء"],
td[data-label="التسليم"],
td[data-label="الملاحظات"],
td[data-label="الإجراءات"],
td[data-label="المنشئ"] {
    font-size: 8px;
    padding: 3px 4px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    vertical-align: middle;
}

/* Notes text and employee note */
td[data-label="الملاحظات"] .note-text,
td[data-label="الملاحظات"] .employee-note {
    font-size: 8px;
    max-width: 120px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Smaller badges and priority labels */
td[data-label="الحالة"] .badge {
    font-size: 8px;
    padding: 2px 5px;
    line-height: 1;
}
td[data-label="الأولوية"] .p-low,
td[data-label="الأولوية"] .p-medium,
td[data-label="الأولوية"] .p-high {
    font-size: 8px;
    display:inline-block;
}

/* Thumbnails even smaller to fit compact rows */
.thumb { width:42px; height:42px; }
.thumb-sm { width:28px; height:28px; }

/* Smaller action buttons */
.actions .btn {
    padding: 4px 6px;
    font-size: 8px;
    border-radius: 6px;
}

/* compact employee / creator name */
td[data-label="الموظف"] strong,
td[data-label="المنشئ"] strong {
    display: block;
    font-size: 9px;
    margin-bottom: 2px;
}
td[data-label="الموظف"] span,
td[data-label="المنشئ"] span {
    display: block;
    font-size: 8px;
    color: #666;
    margin-top: 0;
}

/* Reduce general cell padding to compress rows */
th, td { padding: 4px 6px; }

/* Ensure dates are single-line */
td[data-label="البدء"],
td[data-label="التسليم"],
td .note-meta {
    white-space: nowrap;
}

/* Responsive: increase sizes on small screens for readability */
@media (max-width:1000px){
    .filters{grid-template-columns:1fr}
    table,thead,tbody,th,td,tr{display:block}
    th{display:none}
    tr{border:1px solid #eee;border-radius:10px;margin-bottom:10px;padding:8px;background:#fff}
    td{border:none;padding:6px 4px}
    td::before{content:attr(data-label) ": ";font-weight:bold;color:#333}
    .notes-cell{flex-direction:column;align-items:flex-start}
    .note-text, .employee-note{max-width:280px; font-size:11px}
    td[data-label="الموظف"], td[data-label="المنشئ"], td[data-label="الأولوية"],
    td[data-label="الحالة"], td[data-label="البدء"], td[data-label="التسليم"],
    td[data-label="الملاحظات"], td[data-label="الإجراءات"] {
        white-space: normal;
        max-width: none;
        font-size: 11px;
    }
}
</style>
</head>
<body>
<div class="container">
    <h2>إدارة المهام</h2>

    <?php if (!empty($errors)): ?><div class="msg-error"><?php foreach ($errors as $err): ?><div>• <?php echo h($err); ?></div><?php endforeach; ?></div><?php endif; ?>
    <?php if ($success !== ''): ?><div class="msg-ok"><?php echo h($success); ?></div><?php endif; ?>

    <div class="top-actions">
        <a class="btn btn-green" href="/admin/tasks_create.php">+ إضافة مهمة</a>
        <a class="btn btn-gray" href="/admin/dashboard.php">لوحة المدير</a>
        <a class="btn btn-orange" href="/admin/reports.php">التقارير</a>
        <a class="btn" href="<?php echo h($exportEmployeesUrl); ?>">تصدير الموظفين CSV</a>
        <a class="btn" href="<?php echo h($exportOverdueUrl); ?>">تصدير المتأخرة CSV</a>
        <a class="btn" href="<?php echo h($exportAllTasksUrl); ?>">تصدير كل المهام CSV</a>
    </div>

    <form method="get" action="">
        <div class="filters">
            <input type="text" name="q" placeholder="بحث بعنوان/وصف/ملاحظات..." value="<?php echo h($q); ?>">
            <select name="status">
                <option value="">كل الحالات</option>
                <option value="pending" <?php echo $fStatus==='pending'?'selected':''; ?>>قيد الانتظار</option>
                <option value="in_progress" <?php echo $fStatus==='in_progress'?'selected':''; ?>>قيد التنفيذ</option>
                <option value="done" <?php echo $fStatus==='done'?'selected':''; ?>>منتهية</option>
            </select>
            <select name="priority">
                <option value="">كل الأولويات</option>
                <option value="low" <?php echo $fPrio==='low'?'selected':''; ?>>منخفضة</option>
                <option value="medium" <?php echo $fPrio==='medium'?'selected':''; ?>>متوسطة</option>
                <option value="high" <?php echo $fPrio==='high'?'selected':''; ?>>عالية</option>
            </select>
            <select name="assigned_to">
                <option value="0">كل الموظفين</option>
                <?php foreach ($employees as $emp): ?>
                    <option value="<?php echo (int)$emp['id']; ?>" <?php echo $fUser===(int)$emp['id']?'selected':''; ?>>
                        <?php echo h((string)$emp['full_name'].' ('.(string)$emp['username'].')'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <input type="date" name="date_from" value="<?php echo h((string)($fDateFrom ?? '')); ?>">
            <input type="date" name="date_to" value="<?php echo h((string)($fDateTo ?? '')); ?>">
            <div style="display:flex;gap:8px;">
                <button class="btn" type="submit">فلترة</button>
                <a class="btn btn-gray" href="/admin/tasks_list.php">مسح</a>
            </div>
        </div>
    </form>

    <div class="small">عدد النتائج: <?php echo (int)$totalRows; ?> | الصفحة: <?php echo (int)$page; ?> من <?php echo (int)$totalPages; ?></div>

    <?php if (empty($tasks)): ?>
        <div class="empty">لا توجد مهام مطابقة.</div>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>العنوان</th>
                    <th>الموظف</th>
                    <th>الأولوية</th>
                    <th>الحالة</th>
                    <th>البدء</th>
                    <th>التسليم</th>
                    <th>الملاحظات</th>
                    <th>الصورة</th>
                    <th>المنشئ</th>
                    <th>الإجراءات</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($tasks as $t):
                $img = trim((string)($t['image_path'] ?? ''));
                $empImg = trim((string)($t['employee_update_image_path'] ?? ''));
                $creatorNotes = (string)($t['notes'] ?? '');
                $empNotes = (string)($t['employee_update_notes'] ?? '');
                $empAt = (string)($t['employee_update_at'] ?? '');
            ?>
                <tr>
                    <td data-label="#"><?php echo (int)$t['id']; ?></td>
                    <td data-label="العنوان">
                        <strong style="font-size:11px;"><?php echo h((string)$t['title']); ?></strong><br>
                        <span style="color:#666;font-size:9px;"><?php echo h(mb_strimwidth((string)($t['description'] ?? ''), 0, 100, '...')); ?></span>
                    </td>
                    <td data-label="الموظف">
                        <strong><?php echo h((string)$t['assigned_name']); ?></strong>
                        <span><?php echo h((string)$t['assigned_username']); ?></span>
                    </td>
                    <td data-label="الأولوية"><span class="<?php echo priorityClass((string)$t['priority']); ?>"><?php echo h(priorityLabel((string)$t['priority'])); ?></span></td>
                    <td data-label="الحالة"><span class="badge <?php echo statusClass((string)$t['status']); ?>"><?php echo h(statusLabel((string)$t['status'])); ?></span></td>
                    <td data-label="البدء"><?php echo h((string)$t['start_date']); ?></td>
                    <td data-label="التسليم"><?php echo h((string)($t['due_date'] ?? '-')); ?></td>

                    <td data-label="الملاحظات">
                        <div class="notes-cell">
                            <div class="notes-block" title="<?php echo h($creatorNotes); ?>">
                                <div class="note-title">ملاحظات المنشئ:</div>
                                <div class="note-text"><?php echo h($creatorNotes !== '' ? $creatorNotes : '-'); ?></div>
                            </div>

                            <?php if ($empNotes !== '' || $empImg !== ''): ?>
                                <div class="employee-update" title="<?php echo h(($empNotes ?: '') . ' ' . ($empAt ?: '')); ?>">
                                    <?php if ($empImg !== ''): ?>
                                        <a href="<?php echo h($empImg); ?>" target="_blank" rel="noopener">
                                            <img class="thumb-sm" src="<?php echo h($empImg); ?>" alt="employee update image">
                                        </a>
                                    <?php endif; ?>

                                    <div style="display:flex;flex-direction:column;min-width:0;">
                                        <?php if ($empNotes !== ''): ?>
                                            <div class="employee-note"><?php echo h($empNotes); ?></div>
                                        <?php endif; ?>
                                        <?php if ($empAt !== ''): ?>
                                            <div class="note-meta"><?php echo h($empAt); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </td>

                    <td data-label="الصورة">
                        <?php if ($img !== ''): ?>
                            <a href="<?php echo h($img); ?>" target="_blank" rel="noopener">
                                <img class="thumb" src="<?php echo h($img); ?>" alt="task image">
                            </a>
                            <a href="<?php echo h($img); ?>" target="_blank" rel="noopener">فتح</a>
                        <?php else: ?>-<?php endif; ?>
                    </td>
                    <td data-label="المنشئ">
                        <strong><?php echo h((string)($t['creator_name'] ?? '-')); ?></strong>
                    </td>
                    <td data-label="الإجراءات">
                        <div class="actions">
                            <a class="btn btn-orange btn-sm" href="/admin/tasks_edit.php?id=<?php echo (int)$t['id']; ?>">تعديل</a>
                            <form class="inline" method="post" action="" onsubmit="return confirm('هل أنت متأكد من حذف المهمة؟');">
                                <?php echo Csrf::inputField(); ?>
                                <input type="hidden" name="action" value="delete_task">
                                <input type="hidden" name="task_id" value="<?php echo (int)$t['id']; ?>">
                                <button type="submit" class="btn btn-red btn-sm">حذف</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?><a class="page-link" href="<?php echo $baseUrl . 'page=' . ($page - 1); ?>">السابق</a><?php endif; ?>
                <?php for ($i=max(1,$page-2); $i<=min($totalPages,$page+2); $i++): ?>
                    <a class="page-link <?php echo $i === $page ? 'active' : ''; ?>" href="<?php echo $baseUrl . 'page=' . $i; ?>"><?php echo $i; ?></a>
                <?php endfor; ?>
                <?php if ($page < $totalPages): ?><a class="page-link" href="<?php echo $baseUrl . 'page=' . ($page + 1); ?>">التالي</a><?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
</body>
</html>
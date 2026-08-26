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

Session::start();
$auth = new Auth();
$auth->requireUserRole();

$db = Database::getConnection();
$user = $auth->user();
$userId = (int)($user['id'] ?? 0);

$errors = [];
$success = '';

/**
 * Pagination
 */
$page = isset($_GET['page']) && ctype_digit((string)$_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 10;
$offset = ($page - 1) * $perPage;

/**
 * POST handling: update status or employee update
 */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Csrf::verifyOrFail();

    $action = (string)($_POST['action'] ?? '');
    $taskId = (int)($_POST['task_id'] ?? 0);

    if ($action === 'update_status') {
        $newStatus = (string)($_POST['status'] ?? '');

        if ($taskId <= 0) {
            $errors[] = 'معرّف المهمة غير صالح.';
        } elseif (!in_array($newStatus, ['pending', 'in_progress', 'done'], true)) {
            $errors[] = 'الحالة المختارة غير صالحة.';
        } else {
            try {
                $chk = $db->prepare("
                    SELECT id, title, status
                    FROM tasks
                    WHERE id = :id AND assigned_to = :uid
                    LIMIT 1
                ");
                $chk->execute([
                    ':id'  => $taskId,
                    ':uid' => $userId
                ]);
                $task = $chk->fetch();

                if (!$task) {
                    $errors[] = 'المهمة غير موجودة أو غير مصرح لك بتعديلها.';
                } else {
                    $up = $db->prepare("UPDATE tasks SET status = :status WHERE id = :id");
                    $up->execute([
                        ':status' => $newStatus,
                        ':id'     => $taskId
                    ]);

                    $success = 'تم تحديث حالة المهمة بنجاح.';

                    try {
                        $log = $db->prepare("
                            INSERT INTO audit_logs
                            (actor_user_id, action_type, entity_type, entity_id, description, ip_address, user_agent)
                            VALUES
                            (:actor_user_id, :action_type, :entity_type, :entity_id, :description, :ip_address, :user_agent)
                        ");
                        $log->execute([
                            ':actor_user_id' => $userId,
                            ':action_type'   => 'UPDATE_TASK_STATUS',
                            ':entity_type'   => 'tasks',
                            ':entity_id'     => $taskId,
                            ':description'   => 'User updated task status to: ' . $newStatus . ' | Task: ' . (string)$task['title'],
                            ':ip_address'    => $_SERVER['REMOTE_ADDR'] ?? null,
                            ':user_agent'    => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                        ]);
                    } catch (Throwable $e) {
                        // تجاهل خطأ اللوج
                    }
                }
            } catch (Throwable $e) {
                $errors[] = 'حدث خطأ أثناء تحديث الحالة: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'employee_update') {
        // معالجة تحديث الموظف: ملاحظة + صورة (اختياري)
        $employeeNotes = trim((string)($_POST['employee_notes'] ?? ''));

        if ($taskId <= 0) {
            $errors[] = 'معرّف المهمة غير صالح.';
        } else {
            try {
                // تأكد ان المهمة تخص هذا المستخدم
                $chk = $db->prepare("SELECT id, title FROM tasks WHERE id = :id AND assigned_to = :uid LIMIT 1");
                $chk->execute([':id' => $taskId, ':uid' => $userId]);
                $own = $chk->fetch();

                if (!$own) {
                    $errors[] = 'المهمة غير موجودة أو غير مصرح لك بتحديثها.';
                } else {
                    // معالجة رفع الملف (اختياري)
                    $empImagePath = null;
                    if (isset($_FILES['employee_image']) && ($_FILES['employee_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                        $fileErr = (int)($_FILES['employee_image']['error'] ?? UPLOAD_ERR_NO_FILE);
                        if ($fileErr !== UPLOAD_ERR_OK) {
                            $errors[] = 'حدث خطأ أثناء رفع صورة التحديث.';
                        } else {
                            $size = (int)($_FILES['employee_image']['size'] ?? 0);
                            if ($size <= 0 || $size > 1024 * 1024) {
                                $errors[] = 'حجم الصورة يجب أن يكون 1MB أو أقل.';
                            } else {
                                $tmp = (string)($_FILES['employee_image']['tmp_name'] ?? '');
                                $finfo = new finfo(FILEINFO_MIME_TYPE);
                                $mime = (string)$finfo->file($tmp);

                                $allowed = [
                                    'image/jpeg' => 'jpg',
                                    'image/png'  => 'png',
                                    'image/webp' => 'webp',
                                    'image/gif'  => 'gif',
                                ];

                                if (!isset($allowed[$mime])) {
                                    $errors[] = 'نوع الصورة غير مدعوم (JPG, PNG, WEBP, GIF).';
                                } else {
                                    $ext = $allowed[$mime];
                                    $filename = 'task_update_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;

                                    $uploadDirFs = __DIR__ . '/../public/uploads/task_updates/';
                                    if (!is_dir($uploadDirFs) && !@mkdir($uploadDirFs, 0775, true) && !is_dir($uploadDirFs)) {
                                        $errors[] = 'تعذر إنشاء مجلد رفع الصور.';
                                    } else {
                                        $destFs = $uploadDirFs . $filename;
                                        if (!move_uploaded_file($tmp, $destFs)) {
                                            $errors[] = 'تعذر حفظ الصورة على السيرفر.';
                                        } else {
                                            $empImagePath = '/public/uploads/task_updates/' . $filename;
                                        }
                                    }
                                }
                            }
                        }
                    }

                    if (empty($errors)) {
                        $up = $db->prepare("UPDATE tasks SET
                            employee_update_notes = :emp_notes,
                            employee_update_image_path = :emp_image,
                            employee_update_at = :emp_at
                            WHERE id = :id AND assigned_to = :uid
                        ");
                        $up->execute([
                            ':emp_notes' => $employeeNotes !== '' ? $employeeNotes : null,
                            ':emp_image' => $empImagePath,
                            ':emp_at'    => date('Y-m-d H:i:s'),
                            ':id'        => $taskId,
                            ':uid'       => $userId
                        ]);

                        try {
                            $log = $db->prepare("INSERT INTO audit_logs
                                (actor_user_id, action_type, entity_type, entity_id, description, ip_address, user_agent)
                                VALUES
                                (:actor_user_id, :action_type, :entity_type, :entity_id, :description, :ip_address, :user_agent)");
                            $log->execute([
                                ':actor_user_id' => $userId,
                                ':action_type'   => 'EMPLOYEE_UPDATE_TASK',
                                ':entity_type'   => 'tasks',
                                ':entity_id'     => $taskId,
                                ':description'   => 'Employee updated task notes/image',
                                ':ip_address'    => $_SERVER['REMOTE_ADDR'] ?? null,
                                ':user_agent'    => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                            ]);
                        } catch (Throwable $e) {
                            // تجاهل خطأ اللوج
                        }

                        $success = 'تم حفظ تحديثك بنجاح.';
                    }
                }
            } catch (Throwable $e) {
                $errors[] = 'حدث خطأ أثناء حفظ التحديث: ' . $e->getMessage();
            }
        }
    } else {
        $errors[] = 'إجراء غير معروف.';
    }
}

/**
 * فلتر الحالة
 */
$filterStatus = trim((string)($_GET['status'] ?? ''));
if (!in_array($filterStatus, ['', 'pending', 'in_progress', 'done'], true)) {
    $filterStatus = '';
}

/**
 * إحصائيات عامة (من كل مهام المستخدم)
 */
$totalTasks = 0;
$pendingCount = 0;
$progressCount = 0;
$doneCount = 0;
$overdueCount = 0;
$todayTasks = 0;

try {
    $stStats = $db->prepare("
        SELECT
            COUNT(*) AS total_tasks,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
            SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) AS progress_count,
            SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) AS done_count,
            SUM(CASE WHEN due_date IS NOT NULL AND due_date < CURDATE() AND status <> 'done' THEN 1 ELSE 0 END) AS overdue_count,
            SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) AS today_tasks
        FROM tasks
        WHERE assigned_to = :uid
    ");
    $stStats->execute([':uid' => $userId]);
    $stats = $stStats->fetch();

    if ($stats) {
        $totalTasks   = (int)($stats['total_tasks'] ?? 0);
        $pendingCount = (int)($stats['pending_count'] ?? 0);
        $progressCount= (int)($stats['progress_count'] ?? 0);
        $doneCount    = (int)($stats['done_count'] ?? 0);
        $overdueCount = (int)($stats['overdue_count'] ?? 0);
        $todayTasks   = (int)($stats['today_tasks'] ?? 0);
    }
} catch (Throwable $e) {
    $errors[] = 'تعذر تحميل الإحصائيات: ' . $e->getMessage();
}

/**
 * WHERE للعرض حسب الفلتر
 */
$where = " WHERE t.assigned_to = :uid ";
$params = [':uid' => $userId];

if ($filterStatus !== '') {
    $where .= " AND t.status = :status ";
    $params[':status'] = $filterStatus;
}

/**
 * عدد النتائج بعد الفلتر
 */
$totalRows = 0;
$totalPages = 1;

try {
    $stCount = $db->prepare("SELECT COUNT(*) FROM tasks t {$where}");
    $stCount->execute($params);
    $totalRows = (int)$stCount->fetchColumn();
    $totalPages = max(1, (int)ceil($totalRows / $perPage));

    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $perPage;
    }
} catch (Throwable $e) {
    $errors[] = 'تعذر حساب عدد المهام: ' . $e->getMessage();
}

/**
 * تحميل المهام للجدول -- الآن نأتي بالحقول الجديدة
 */
$tasks = [];
try {
    $sql = "SELECT
                t.id, t.title, t.description, t.priority, t.status, t.start_date, t.due_date, t.created_at,
                t.image_path AS admin_image_path,
                t.employee_update_notes, t.employee_update_image_path, t.employee_update_at,
                c.full_name AS creator_name
            FROM tasks t
            LEFT JOIN users c ON c.id = t.created_by
            {$where}
            ORDER BY t.id DESC
            LIMIT :limit OFFSET :offset";

    $st = $db->prepare($sql);

    foreach ($params as $k => $v) {
        $st->bindValue($k, $v);
    }
    $st->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $st->bindValue(':offset', $offset, PDO::PARAM_INT);

    $st->execute();
    $tasks = $st->fetchAll();
} catch (Throwable $e) {
    $errors[] = 'تعذر تحميل المهام: ' . $e->getMessage();
}

/**
 * Pagination links helper
 */
$qs = $_GET;
unset($qs['page']);
$baseQuery = http_build_query($qs);
$baseUrl = '/user/dashboard.php' . ($baseQuery !== '' ? ('?' . $baseQuery . '&') : '?');

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>لوحة الموظف</title>
    <style>
        body { margin:0; font-family:Tahoma,Arial,sans-serif; background:#f4f6f9; }
        .container { max-width:1200px; margin:24px auto; padding:0 12px; }

        .top, .card, .box {
            background:#fff; border-radius:12px; box-shadow:0 8px 22px rgba(0,0,0,.07);
        }

        .top { padding:18px 20px; margin-bottom:16px; }
        .top h1 { margin:0 0 6px; font-size:24px; }
        .muted { color:#666; }

        .stats { display:grid; grid-template-columns:repeat(6,1fr); gap:12px; margin-bottom:16px; }
        .card { padding:14px; }
        .num { font-size:24px; font-weight:bold; }

        .box { padding:16px; }

        .msg-error { background:#ffe8e8; color:#b30000; border:1px solid #ffcccc; border-radius:8px; padding:10px; margin-bottom:10px; }
        .msg-ok    { background:#e8ffef; color:#0f6d2f; border:1px solid #bdeccb; border-radius:8px; padding:10px; margin-bottom:10px; }

        .toolbar { display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; margin-bottom:12px; }
        .btn {
            border:0; border-radius:8px; padding:9px 12px; cursor:pointer;
            color:#fff; background:#1976d2; text-decoration:none; display:inline-block; font-size:14px;
        }
        .btn:hover { background:#145ca3; }
        .btn-gray { background:#6c757d; }
        .btn-green { background:#1b8f3a; }

        select {
            padding:9px; border:1px solid #ccd3db; border-radius:8px;
        }

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

        .inline-form { display:flex; gap:6px; align-items:center; flex-wrap:wrap; }

        .small { font-size:12px; color:#666; margin-bottom:10px; }
        .empty { text-align:center; color:#666; padding:18px; }

        .thumb { width:64px; height:64px; object-fit:cover; border-radius:8px; border:1px solid #ddd; display:block; margin-bottom:6px; }

        .employee-update { background:#fafbfc; padding:8px; border-radius:8px; margin-top:8px; }

        .pagination { display:flex; gap:6px; flex-wrap:wrap; margin-top:14px; }
        .page-link {
            padding:7px 10px; border-radius:8px; text-decoration:none; font-size:13px;
            border:1px solid #d7dce3; color:#333; background:#fff;
        }
        .page-link.active {
            background:#1976d2; color:#fff; border-color:#1976d2;
        }

        @media (max-width: 1100px) {
            .stats { grid-template-columns:1fr 1fr; }
            table, thead, tbody, th, td, tr { display:block; }
            th { display:none; }
            tr { border:1px solid #eee; border-radius:10px; margin-bottom:10px; padding:8px; background:#fff; }
            td { border:none; padding:6px 4px; }
            td::before { content: attr(data-label) ": "; font-weight:bold; color:#333; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="top">
        <h1>لوحة الموظف</h1>
        <div class="muted">
            أهلاً،
            <strong><?php echo h($user['full_name'] ?? 'User'); ?></strong>
            (<?php echo h($user['username'] ?? ''); ?>)
        </div>
    </div>

    <div class="stats">
        <div class="card">
            <div class="muted">إجمالي مهامي</div>
            <div class="num"><?php echo $totalTasks; ?></div>
        </div>
        <div class="card">
            <div class="muted">قيد الانتظار</div>
            <div class="num"><?php echo $pendingCount; ?></div>
        </div>
        <div class="card">
            <div class="muted">قيد التنفيذ</div>
            <div class="num"><?php echo $progressCount; ?></div>
        </div>
        <div class="card">
            <div class="muted">منتهية</div>
            <div class="num"><?php echo $doneCount; ?></div>
        </div>
        <div class="card">
            <div class="muted">متأخرة</div>
            <div class="num"><?php echo $overdueCount; ?></div>
        </div>
        <div class="card">
            <div class="muted">مهام اليوم</div>
            <div class="num"><?php echo $todayTasks; ?></div>
        </div>
    </div>

    <div class="box">
        <?php if (!empty($errors)): ?>
            <div class="msg-error">
                <?php foreach ($errors as $err): ?>
                    <div>• <?php echo h($err); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <div class="msg-ok"><?php echo h($success); ?></div>
        <?php endif; ?>

        <div class="toolbar">
            <form method="get" action="">
                <select name="status" onchange="this.form.submit()">
                    <option value="">كل الحالات</option>
                    <option value="pending" <?php echo $filterStatus === 'pending' ? 'selected' : ''; ?>>قيد الانتظار</option>
                    <option value="in_progress" <?php echo $filterStatus === 'in_progress' ? 'selected' : ''; ?>>قيد التنفيذ</option>
                    <option value="done" <?php echo $filterStatus === 'done' ? 'selected' : ''; ?>>منتهية</option>
                </select>
                <noscript><button class="btn" type="submit">فلترة</button></noscript>
            </form>

            <div>
                <a class="btn btn-gray" href="/index.php">الصفحة الرئيسية</a>
                <a class="btn btn-gray" href="/logout.php">تسجيل الخروج</a>
            </div>
        </div>

        <div class="small">
            عدد النتائج:
            <?php echo (int)$totalRows; ?>
            |
            الصفحة:
            <?php echo (int)$page; ?>
            من
            <?php echo (int)$totalPages; ?>
        </div>

        <?php if (empty($tasks)): ?>
            <div class="empty">لا توجد مهام لعرضها.</div>
        <?php else: ?>
            <table>
                <thead>
                <tr>
                    <th>#</th>
                    <th>العنوان</th>
                    <th>الأولوية</th>
                    <th>الحالة الحالية</th>
                    <th>البدء</th>
                    <th>التسليم</th>
                    <th>أنشأها</th>
                    <th>صورة المشرف</th>
                    <th>تحديث الموظف</th>
                    <th>تحديث الحالة</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($tasks as $t): ?>
                    <tr>
                        <td data-label="#"><?php echo (int)$t['id']; ?></td>

                        <td data-label="العنوان">
                            <strong><?php echo h($t['title']); ?></strong><br>
                            <span style="color:#666;font-size:12px;">
                                <?php echo h(mb_strimwidth((string)($t['description'] ?? ''), 0, 120, '...')); ?>
                            </span>
                        </td>

                        <td data-label="الأولوية">
                            <?php if ($t['priority'] === 'low'): ?>
                                <span class="p-low">منخفضة</span>
                            <?php elseif ($t['priority'] === 'medium'): ?>
                                <span class="p-medium">متوسطة</span>
                            <?php else: ?>
                                <span class="p-high">عالية</span>
                            <?php endif; ?>
                        </td>

                        <td data-label="الحالة الحالية">
                            <?php if ($t['status'] === 'pending'): ?>
                                <span class="badge b-pending">قيد الانتظار</span>
                            <?php elseif ($t['status'] === 'in_progress'): ?>
                                <span class="badge b-progress">قيد التنفيذ</span>
                            <?php else: ?>
                                <span class="badge b-done">منتهية</span>
                            <?php endif; ?>
                        </td>

                        <td data-label="البدء"><?php echo h($t['start_date']); ?></td>
                        <td data-label="التسليم"><?php echo h($t['due_date'] ?? '-'); ?></td>
                        <td data-label="أنشأها"><?php echo h($t['creator_name'] ?? '-'); ?></td>

                        <td data-label="صورة المشرف">
                            <?php if (!empty($t['admin_image_path'])): ?>
                                <a href="<?php echo h($t['admin_image_path']); ?>" target="_blank" rel="noopener">
                                    <img class="thumb" src="<?php echo h($t['admin_image_path']); ?>" alt="admin image">
                                </a>
                                <div class="muted" style="font-size:12px;">صورة المشرف</div>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>

                        <td data-label="تحديث الموظف">
                            <?php if (!empty($t['employee_update_notes']) || !empty($t['employee_update_image_path'])): ?>
                                <div class="employee-update">
                                    <?php if (!empty($t['employee_update_notes'])): ?>
                                        <div style="margin-bottom:6px;"><strong>ملاحظة:</strong><br><?php echo nl2br(h($t['employee_update_notes'])); ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($t['employee_update_image_path'])): ?>
                                        <a href="<?php echo h($t['employee_update_image_path']); ?>" target="_blank" rel="noopener">
                                            <img class="thumb" src="<?php echo h($t['employee_update_image_path']); ?>" alt="employee update image">
                                        </a>
                                    <?php endif; ?>
                                    <?php if (!empty($t['employee_update_at'])): ?>
                                        <div class="muted" style="font-size:12px;margin-top:6px;">آخر تحديث: <?php echo h($t['employee_update_at']); ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="muted" style="font-size:12px;">لا يوجد تحديثات</div>
                            <?php endif; ?>

                            <!-- form لارسال تحديث الموظف -->
                            <form method="post" action="" enctype="multipart/form-data" style="margin-top:8px;">
                                <?php echo Csrf::inputField(); ?>
                                <input type="hidden" name="action" value="employee_update">
                                <input type="hidden" name="task_id" value="<?php echo (int)$t['id']; ?>">

                                <textarea name="employee_notes" rows="2" style="width:100%;padding:6px;border-radius:6px;border:1px solid #ccd3db;" placeholder="أدخل ملاحظاتك..."></textarea>
                                <input type="file" name="employee_image" accept="image/jpeg,image/png,image/webp,image/gif" style="margin-top:6px;">
                                <button type="submit" class="btn btn-green" style="margin-top:6px;">إرسال تحديث</button>
                            </form>
                        </td>

                        <td data-label="تحديث الحالة">
                            <form class="inline-form" method="post" action="">
                                <?php echo Csrf::inputField(); ?>
                                <input type="hidden" name="action" value="update_status">
                                <input type="hidden" name="task_id" value="<?php echo (int)$t['id']; ?>">

                                <select name="status">
                                    <option value="pending" <?php echo $t['status'] === 'pending' ? 'selected' : ''; ?>>قيد الانتظار</option>
                                    <option value="in_progress" <?php echo $t['status'] === 'in_progress' ? 'selected' : ''; ?>>قيد التنفيذ</option>
                                    <option value="done" <?php echo $t['status'] === 'done' ? 'selected' : ''; ?>>منتهية</option>
                                </select>

                                <button class="btn btn-green" type="submit">حفظ</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a class="page-link" href="<?php echo $baseUrl . 'page=' . ($page - 1); ?>">السابق</a>
                    <?php endif; ?>

                    <?php
                    $start = max(1, $page - 2);
                    $end = min($totalPages, $page + 2);
                    for ($i = $start; $i <= $end; $i++):
                    ?>
                        <a class="page-link <?php echo $i === $page ? 'active' : ''; ?>" href="<?php echo $baseUrl . 'page=' . $i; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>

                    <?php if ($page < $totalPages): ?>
                        <a class="page-link" href="<?php echo $baseUrl . 'page=' . ($page + 1); ?>">التالي</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
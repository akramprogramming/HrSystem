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
$auth->requireLogin();

if (!$auth->isModerator()) {
    http_response_code(403);
    exit('Forbidden: Moderator access only.');
}

$db = Database::getConnection();
$user = $auth->user();
$moderatorId = (int)($user['id'] ?? 0);

$errors = [];
$success = '';

// Filters
$q = trim((string)($_GET['q'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));
$priorityFilter = trim((string)($_GET['priority'] ?? ''));
$userFilter = (string)($_GET['user_id'] ?? '');

if (!in_array($statusFilter, ['', 'pending', 'in_progress', 'done'], true)) {
    $statusFilter = '';
}
if (!in_array($priorityFilter, ['', 'low', 'medium', 'high'], true)) {
    $priorityFilter = '';
}
if ($userFilter !== '' && !ctype_digit($userFilter)) {
    $userFilter = '';
}

// Quick status update
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Csrf::verifyOrFail();

    $action = (string)($_POST['action'] ?? '');

    if ($action === 'change_status') {
        $taskId = (int)($_POST['task_id'] ?? 0);
        $newStatus = trim((string)($_POST['new_status'] ?? ''));

        if ($taskId <= 0) {
            $errors[] = 'رقم المهمة غير صالح.';
        }
        if (!in_array($newStatus, ['pending', 'in_progress', 'done'], true)) {
            $errors[] = 'الحالة الجديدة غير صالحة.';
        }

        if (empty($errors)) {
            try {
                $chk = $db->prepare("
                    SELECT id
                    FROM tasks
                    WHERE id = :id
                      AND moderator_id = :mid
                    LIMIT 1
                ");
                $chk->execute([
                    ':id' => $taskId,
                    ':mid' => $moderatorId
                ]);
                $task = $chk->fetch(PDO::FETCH_ASSOC);

                if (!$task) {
                    $errors[] = 'لا يمكنك تعديل هذه المهمة.';
                } else {
                    $up = $db->prepare("
                        UPDATE tasks
                        SET status = :status
                        WHERE id = :id
                          AND moderator_id = :mid
                        LIMIT 1
                    ");
                    $up->execute([
                        ':status' => $newStatus,
                        ':id' => $taskId,
                        ':mid' => $moderatorId
                    ]);

                    $success = ($up->rowCount() > 0)
                        ? 'تم تحديث الحالة بنجاح.'
                        : 'لم يتم تغيير الحالة (ربما نفس الحالة الحالية).';
                }
            } catch (Throwable $e) {
                $errors[] = 'خطأ أثناء تحديث الحالة: ' . $e->getMessage();
            }
        }
    }
}

// Load team users
$teamUsers = [];
try {
    $stTeam = $db->prepare("
        SELECT u.id, u.full_name, u.username
        FROM moderator_users mu
        JOIN users u ON u.id = mu.user_id
        WHERE mu.moderator_id = :mid
          AND u.role = 'user'
          AND u.is_active = 1
        ORDER BY u.full_name ASC
    ");
    $stTeam->execute([':mid' => $moderatorId]);
    $teamUsers = $stTeam->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $errors[] = 'تعذر تحميل فريقك: ' . $e->getMessage();
}

// Load tasks
$tasks = [];
try {
    $where = " WHERE t.moderator_id = :mid ";
    $params = [':mid' => $moderatorId];

    $where .= " AND EXISTS (
        SELECT 1
        FROM moderator_users mu
        WHERE mu.moderator_id = :mid2
          AND mu.user_id = t.assigned_to
    ) ";
    $params[':mid2'] = $moderatorId;

    if ($statusFilter !== '') {
        $where .= " AND t.status = :status ";
        $params[':status'] = $statusFilter;
    }

    if ($priorityFilter !== '') {
        $where .= " AND t.priority = :priority ";
        $params[':priority'] = $priorityFilter;
    }

    if ($userFilter !== '') {
        $where .= " AND t.assigned_to = :uid ";
        $params[':uid'] = (int)$userFilter;
    }

    if ($q !== '') {
        $where .= " AND (t.title LIKE :q OR t.description LIKE :q OR t.notes LIKE :q) ";
        $params[':q'] = '%' . $q . '%';
    }

    $sql = "
        SELECT
            t.id, t.title, t.description, t.notes, t.image_path,
            t.priority, t.status, t.start_date, t.due_date, t.created_at,
            u.full_name AS employee_name, u.username AS employee_username
        FROM tasks t
        JOIN users u ON u.id = t.assigned_to
        {$where}
        ORDER BY t.id DESC
        LIMIT 300
    ";

    $st = $db->prepare($sql);
    foreach ($params as $k => $v) {
        $type = PDO::PARAM_STR;
        if (in_array($k, [':mid', ':mid2', ':uid'], true)) $type = PDO::PARAM_INT;
        $st->bindValue($k, $v, $type);
    }
    $st->execute();
    $tasks = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $errors[] = 'تعذر تحميل المهام: ' . $e->getMessage();
}

// Counters
$totalCount = count($tasks);
$pendingCount = 0;
$inProgressCount = 0;
$doneCount = 0;
$overdueCount = 0;

$today = date('Y-m-d');
foreach ($tasks as $t) {
    $st = (string)$t['status'];
    if ($st === 'pending') $pendingCount++;
    if ($st === 'in_progress') $inProgressCount++;
    if ($st === 'done') $doneCount++;
    if (!empty($t['due_date']) && $t['due_date'] < $today && $st !== 'done') $overdueCount++;
}

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مهام المشرف</title>
    <style>
        body{margin:0;background:#f4f6f9;font-family:Tahoma,Arial,sans-serif}
        .container{max-width:1300px;margin:24px auto;padding:0 12px}
        .card{background:#fff;border-radius:12px;padding:16px;box-shadow:0 8px 22px rgba(0,0,0,.07);margin-bottom:14px}
        h1{margin:0 0 10px;font-size:28px}
        .muted{color:#666}
        .msg-error { background:#ffe8e8; color:#b30000; border:1px solid #ffcccc; border-radius:8px; padding:10px; margin-bottom:10px; }
        .msg-ok    { background:#e8ffef; color:#0f6d2f; border:1px solid #bdeccb; border-radius:8px; padding:10px; margin-bottom:10px; }

        .filters{display:grid;grid-template-columns:2fr 1fr 1fr 1fr auto;gap:8px}
        input[type="text"], select{width:100%;box-sizing:border-box;padding:9px;border:1px solid #ccd3db;border-radius:8px;background:#fff}
        .btn{border:0;border-radius:8px;padding:9px 12px;cursor:pointer;text-decoration:none;color:#fff;background:#1976d2;display:inline-block}
        .btn:hover{background:#145ca3}
        .btn-gray{background:#6c757d}
        .btn-green{background:#1b8f3a}
        .btn-orange{background:#ef6c00}

        .stats{display:grid;grid-template-columns:repeat(5,1fr);gap:10px}
        .stat{background:#fafbfc;border:1px solid #edf0f3;border-radius:10px;padding:10px}
        .stat .n{font-size:20px;font-weight:bold}

        table{width:100%;border-collapse:collapse}
        th,td{padding:10px;border-bottom:1px solid #eee;text-align:right;vertical-align:top;font-size:14px}
        th{background:#fafbfc}
        .badge{padding:3px 8px;border-radius:10px;color:#fff;font-size:12px}
        .p-low{background:#6c757d}.p-medium{background:#ef6c00}.p-high{background:#c62828}
        .s-pending{background:#6c757d}.s-progress{background:#1565c0}.s-done{background:#198754}
        .inline-form{display:flex;gap:6px;align-items:center}
        .inline-form select{min-width:120px}
        .thumb{width:54px;height:54px;object-fit:cover;border-radius:8px;border:1px solid #ddd;display:block;margin-bottom:4px}

        @media (max-width:1100px){
            .filters{grid-template-columns:1fr}
            .stats{grid-template-columns:1fr 1fr}
            table,thead,tbody,th,td,tr{display:block}
            th{display:none}
            tr{border:1px solid #eee;border-radius:10px;margin-bottom:10px;padding:8px;background:#fff}
            td{border:none;padding:6px 4px}
            td::before{content:attr(data-label)": ";font-weight:bold}
        }
    </style>
</head>
<body>
<div class="container">

    <div class="card">
        <h1>قائمة مهام المشرف</h1>
        <div class="muted">تعرض هذه الصفحة فقط المهام المرتبطة بك كمشرف.</div>
    </div>

    <div class="card">
        <?php if (!empty($errors)): ?>
            <div class="msg-error"><?php foreach ($errors as $e): ?><div>• <?php echo h($e); ?></div><?php endforeach; ?></div>
        <?php endif; ?>
        <?php if ($success !== ''): ?>
            <div class="msg-ok"><?php echo h($success); ?></div>
        <?php endif; ?>

        <form method="get" action="" class="filters">
            <input type="text" name="q" placeholder="بحث بالعنوان/الوصف/الملاحظات..." value="<?php echo h($q); ?>">
            <select name="status">
                <option value="">كل الحالات</option>
                <option value="pending" <?php echo $statusFilter==='pending'?'selected':''; ?>>قيد الانتظار</option>
                <option value="in_progress" <?php echo $statusFilter==='in_progress'?'selected':''; ?>>قيد التنفيذ</option>
                <option value="done" <?php echo $statusFilter==='done'?'selected':''; ?>>منتهية</option>
            </select>
            <select name="priority">
                <option value="">كل الأولويات</option>
                <option value="low" <?php echo $priorityFilter==='low'?'selected':''; ?>>منخفضة</option>
                <option value="medium" <?php echo $priorityFilter==='medium'?'selected':''; ?>>متوسطة</option>
                <option value="high" <?php echo $priorityFilter==='high'?'selected':''; ?>>عالية</option>
            </select>
            <select name="user_id">
                <option value="">كل الموظفين</option>
                <?php foreach ($teamUsers as $u): ?>
                    <option value="<?php echo (int)$u['id']; ?>" <?php echo ($userFilter!=='' && (int)$userFilter===(int)$u['id'])?'selected':''; ?>>
                        <?php echo h($u['full_name'].' ('.$u['username'].')'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button class="btn" type="submit">تصفية</button>
        </form>

        <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;">
            <a class="btn btn-green" href="/moderator/tasks_create.php">إضافة مهمة</a>
            <a class="btn btn-orange" href="/moderator/team_reports.php">تقارير فريقي</a>
            <a class="btn btn-gray" href="/moderator/dashboard.php">لوحة المشرف</a>
        </div>
    </div>

    <div class="card">
        <div class="stats">
            <div class="stat"><div class="muted">الإجمالي</div><div class="n"><?php echo $totalCount; ?></div></div>
            <div class="stat"><div class="muted">قيد الانتظار</div><div class="n"><?php echo $pendingCount; ?></div></div>
            <div class="stat"><div class="muted">قيد التنفيذ</div><div class="n"><?php echo $inProgressCount; ?></div></div>
            <div class="stat"><div class="muted">منتهية</div><div class="n"><?php echo $doneCount; ?></div></div>
            <div class="stat"><div class="muted">متأخرة</div><div class="n"><?php echo $overdueCount; ?></div></div>
        </div>
    </div>

    <div class="card">
        <?php if (empty($tasks)): ?>
            <p class="muted" style="margin:0;">لا توجد مهام مطابقة.</p>
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
                        <th>ملاحظات</th>
                        <th>صورة</th>
                        <th>تحديث سريع</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($tasks as $t): ?>
                    <?php
                    $priority = (string)$t['priority'];
                    $status = (string)$t['status'];
                    $pClass = $priority === 'high' ? 'p-high' : ($priority === 'medium' ? 'p-medium' : 'p-low');
                    $sClass = $status === 'done' ? 's-done' : ($status === 'in_progress' ? 's-progress' : 's-pending');
                    $img = trim((string)($t['image_path'] ?? ''));
                    ?>
                    <tr>
                        <td data-label="#"><?php echo (int)$t['id']; ?></td>
                        <td data-label="العنوان">
                            <strong><?php echo h($t['title']); ?></strong><br>
                            <span class="muted"><?php echo h(mb_substr((string)($t['description'] ?? ''), 0, 120)); ?></span>
                        </td>
                        <td data-label="الموظف"><?php echo h($t['employee_name'] . ' (' . $t['employee_username'] . ')'); ?></td>
                        <td data-label="الأولوية"><span class="badge <?php echo $pClass; ?>"><?php echo h($priority); ?></span></td>
                        <td data-label="الحالة"><span class="badge <?php echo $sClass; ?>"><?php echo h($status); ?></span></td>
                        <td data-label="البدء"><?php echo h($t['start_date']); ?></td>
                        <td data-label="التسليم"><?php echo h($t['due_date'] ?? '-'); ?></td>
                        <td data-label="ملاحظات"><?php echo h($t['notes'] ?? '-'); ?></td>
                        <td data-label="صورة">
                            <?php if ($img !== ''): ?>
                                <a href="<?php echo h($img); ?>" target="_blank" rel="noopener">
                                    <img class="thumb" src="<?php echo h($img); ?>" alt="task image">
                                </a>
                                <a href="<?php echo h($img); ?>" target="_blank" rel="noopener">فتح</a>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td data-label="تحديث سريع">
                            <form method="post" action="" class="inline-form">
                                <?php echo Csrf::inputField(); ?>
                                <input type="hidden" name="action" value="change_status">
                                <input type="hidden" name="task_id" value="<?php echo (int)$t['id']; ?>">
                                <select name="new_status">
                                    <option value="pending" <?php echo $status==='pending'?'selected':''; ?>>قيد الانتظار</option>
                                    <option value="in_progress" <?php echo $status==='in_progress'?'selected':''; ?>>قيد التنفيذ</option>
                                    <option value="done" <?php echo $status==='done'?'selected':''; ?>>منتهية</option>
                                </select>
                                <button type="submit" class="btn">حفظ</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
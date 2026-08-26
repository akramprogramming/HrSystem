<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Session.php';
require_once __DIR__ . '/../core/Auth.php';

use Core\Auth;
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
$me = $auth->user();
$moderatorId = (int)($me['id'] ?? 0);

$errors = [];

/**
 * Filters
 */
$filterUserId = isset($_GET['user_id']) && ctype_digit((string)$_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$filterStatus = trim((string)($_GET['status'] ?? ''));
if (!in_array($filterStatus, ['', 'pending', 'in_progress', 'done'], true)) {
    $filterStatus = '';
}

/**
 * Team members for this moderator
 */
$teamUsers = [];
try {
    $st = $db->prepare("
        SELECT u.id, u.full_name, u.username
        FROM moderator_users mu
        JOIN users u ON u.id = mu.user_id
        WHERE mu.moderator_id = :mid
          AND u.role = 'user'
          AND u.is_active = 1
        ORDER BY u.full_name ASC
    ");
    $st->execute([':mid' => $moderatorId]);
    $teamUsers = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $errors[] = 'تعذر تحميل أعضاء الفريق: ' . $e->getMessage();
}

// team ids set
$teamIds = array_map(static fn($r) => (int)$r['id'], $teamUsers);
if ($filterUserId > 0 && !in_array($filterUserId, $teamIds, true)) {
    $filterUserId = 0; // prevent tampering
}

/**
 * Stats per employee
 */
$stats = [];
try {
    $sql = "
        SELECT
            u.id AS user_id,
            u.full_name,
            u.username,
            COUNT(t.id) AS total_tasks,
            SUM(CASE WHEN t.status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
            SUM(CASE WHEN t.status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress_count,
            SUM(CASE WHEN t.status = 'done' THEN 1 ELSE 0 END) AS done_count,
            SUM(CASE WHEN t.due_date IS NOT NULL AND t.due_date < CURDATE() AND t.status <> 'done' THEN 1 ELSE 0 END) AS overdue_count
        FROM moderator_users mu
        JOIN users u ON u.id = mu.user_id
        LEFT JOIN tasks t
               ON t.assigned_to = u.id
              AND t.moderator_id = mu.moderator_id
        WHERE mu.moderator_id = :mid
          AND u.role = 'user'
          AND u.is_active = 1
        GROUP BY u.id, u.full_name, u.username
        ORDER BY u.full_name ASC
    ";
    $st = $db->prepare($sql);
    $st->execute([':mid' => $moderatorId]);
    $stats = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $errors[] = 'تعذر تحميل الإحصائيات: ' . $e->getMessage();
}

/**
 * Latest tasks table (for this moderator's team)
 */
$tasks = [];
try {
    $where = "
        WHERE t.moderator_id = :mid
          AND EXISTS (
              SELECT 1
              FROM moderator_users mu
              WHERE mu.moderator_id = :mid2
                AND mu.user_id = t.assigned_to
          )
    ";
    $params = [
        ':mid' => $moderatorId,
        ':mid2' => $moderatorId
    ];

    if ($filterUserId > 0) {
        $where .= " AND t.assigned_to = :uid ";
        $params[':uid'] = $filterUserId;
    }

    if ($filterStatus !== '') {
        $where .= " AND t.status = :status ";
        $params[':status'] = $filterStatus;
    }

    $sql = "
        SELECT
            t.id, t.title, t.priority, t.status, t.start_date, t.due_date, t.created_at,
            u.full_name AS employee_name, u.username AS employee_username,
            c.full_name AS creator_name
        FROM tasks t
        JOIN users u ON u.id = t.assigned_to
        LEFT JOIN users c ON c.id = t.created_by
        {$where}
        ORDER BY t.id DESC
        LIMIT 100
    ";
    $st = $db->prepare($sql);
    foreach ($params as $k => $v) {
        $type = ($k === ':status') ? PDO::PARAM_STR : PDO::PARAM_INT;
        $st->bindValue($k, $v, $type);
    }
    $st->execute();
    $tasks = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $errors[] = 'تعذر تحميل المهام: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تقارير فريق المشرف</title>
    <style>
        body { margin:0; font-family:Tahoma,Arial,sans-serif; background:#f4f6f9; }
        .container { max-width:1250px; margin:24px auto; padding:0 12px; }
        .top, .box, .card { background:#fff; border-radius:12px; box-shadow:0 8px 22px rgba(0,0,0,.07); }
        .top { padding:16px 18px; margin-bottom:14px; }
        .top h1 { margin:0 0 6px; font-size:28px; }
        .muted { color:#666; }

        .box { padding:16px; margin-bottom:14px; }
        .msg-error { background:#ffe8e8; color:#b30000; border:1px solid #ffcccc; border-radius:8px; padding:10px; margin-bottom:10px; }

        .toolbar { display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; }
        .btn { border:0; border-radius:8px; padding:9px 12px; cursor:pointer; color:#fff; background:#1976d2; text-decoration:none; display:inline-block; font-size:14px; }
        .btn:hover { background:#145ca3; }
        .btn-gray { background:#6c757d; }
        select { padding:9px; border:1px solid #ccd3db; border-radius:8px; }

        .stats-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; }
        .card { padding:12px; }
        .name { font-weight:bold; margin-bottom:8px; }
        .badges { display:flex; gap:6px; flex-wrap:wrap; }
        .badge { padding:4px 8px; border-radius:12px; color:#fff; font-size:12px; }
        .b-total { background:#1565c0; }
        .b-p { background:#6c757d; }
        .b-i { background:#ef6c00; }
        .b-d { background:#198754; }
        .b-o { background:#c62828; }

        table { width:100%; border-collapse:collapse; }
        th, td { padding:10px; border-bottom:1px solid #eee; text-align:right; vertical-align:top; font-size:14px; }
        th { background:#fafbfc; }

        @media (max-width: 1000px) {
            .stats-grid { grid-template-columns:1fr; }
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
        <h1>تقارير فريق المشرف</h1>
        <div class="muted">
            أهلاً
            <strong><?php echo htmlspecialchars((string)($me['full_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong>
            (<?php echo htmlspecialchars((string)($me['username'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>)
        </div>
    </div>

    <div class="box">
        <?php if (!empty($errors)): ?>
            <div class="msg-error">
                <?php foreach ($errors as $err): ?>
                    <div>• <?php echo htmlspecialchars($err, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="toolbar">
            <form method="get" action="">
                <select name="user_id">
                    <option value="0">كل الموظفين</option>
                    <?php foreach ($teamUsers as $u): ?>
                        <option value="<?php echo (int)$u['id']; ?>" <?php echo ($filterUserId === (int)$u['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($u['full_name'] . ' (' . $u['username'] . ')', ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="status">
                    <option value="" <?php echo $filterStatus === '' ? 'selected' : ''; ?>>كل الحالات</option>
                    <option value="pending" <?php echo $filterStatus === 'pending' ? 'selected' : ''; ?>>قيد الانتظار</option>
                    <option value="in_progress" <?php echo $filterStatus === 'in_progress' ? 'selected' : ''; ?>>قيد التنفيذ</option>
                    <option value="done" <?php echo $filterStatus === 'done' ? 'selected' : ''; ?>>منتهية</option>
                </select>

                <button class="btn" type="submit">تطبيق الفلتر</button>
            </form>

            <div>
                <a class="btn btn-gray" href="/moderator/dashboard.php">لوحة المشرف</a>
                <a class="btn btn-gray" href="/logout.php">تسجيل الخروج</a>
            </div>
        </div>
    </div>

    <div class="box">
        <h3 style="margin-top:0;">ملخص كل موظف</h3>

        <?php if (empty($stats)): ?>
            <p class="muted">لا يوجد موظفون مرتبطون بك حتى الآن.</p>
        <?php else: ?>
            <div class="stats-grid">
                <?php foreach ($stats as $s): ?>
                    <div class="card">
                        <div class="name">
                            <?php echo htmlspecialchars((string)$s['full_name'], ENT_QUOTES, 'UTF-8'); ?>
                            (<?php echo htmlspecialchars((string)$s['username'], ENT_QUOTES, 'UTF-8'); ?>)
                        </div>
                        <div class="badges">
                            <span class="badge b-total">إجمالي: <?php echo (int)$s['total_tasks']; ?></span>
                            <span class="badge b-p">انتظار: <?php echo (int)$s['pending_count']; ?></span>
                            <span class="badge b-i">تنفيذ: <?php echo (int)$s['in_progress_count']; ?></span>
                            <span class="badge b-d">منتهية: <?php echo (int)$s['done_count']; ?></span>
                            <span class="badge b-o">متأخرة: <?php echo (int)$s['overdue_count']; ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="box">
        <h3 style="margin-top:0;">آخر المهام (حتى 100 مهمة)</h3>

        <?php if (empty($tasks)): ?>
            <p class="muted">لا توجد مهام مطابقة للفلتر.</p>
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
                    <th>أنشأها</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($tasks as $t): ?>
                    <tr>
                        <td data-label="#"><?php echo (int)$t['id']; ?></td>
                        <td data-label="العنوان"><?php echo htmlspecialchars((string)$t['title'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td data-label="الموظف">
                            <?php echo htmlspecialchars((string)$t['employee_name'], ENT_QUOTES, 'UTF-8'); ?>
                            (<?php echo htmlspecialchars((string)$t['employee_username'], ENT_QUOTES, 'UTF-8'); ?>)
                        </td>
                        <td data-label="الأولوية"><?php echo htmlspecialchars((string)$t['priority'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td data-label="الحالة"><?php echo htmlspecialchars((string)$t['status'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td data-label="البدء"><?php echo htmlspecialchars((string)($t['start_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td data-label="التسليم"><?php echo htmlspecialchars((string)($t['due_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td data-label="أنشأها"><?php echo htmlspecialchars((string)($t['creator_name'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
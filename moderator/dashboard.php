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

$user = $auth->user();
$moderatorId = (int)($user['id'] ?? 0);

// إحصائيات المشرف
$totalTeamUsers = 0;
$totalTasks = 0;
$pendingTasks = 0;
$inProgressTasks = 0;
$doneTasks = 0;
$overdueTasks = 0;

try {
    $db = Database::getConnection();

    // عدد الموظفين المرتبطين بالمشرف
    $st = $db->prepare("
        SELECT COUNT(*)
        FROM moderator_users mu
        JOIN users u ON u.id = mu.user_id
        WHERE mu.moderator_id = :mid
          AND u.role = 'user'
          AND u.is_active = 1
    ");
    $st->execute([':mid' => $moderatorId]);
    $totalTeamUsers = (int)$st->fetchColumn();

    // إحصائيات المهام المرتبطة بالمشرف
    $st2 = $db->prepare("
        SELECT
            COUNT(*) AS total_tasks,
            SUM(CASE WHEN t.status = 'pending' THEN 1 ELSE 0 END) AS pending_tasks,
            SUM(CASE WHEN t.status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress_tasks,
            SUM(CASE WHEN t.status = 'done' THEN 1 ELSE 0 END) AS done_tasks,
            SUM(CASE WHEN t.due_date IS NOT NULL AND t.due_date < CURDATE() AND t.status <> 'done' THEN 1 ELSE 0 END) AS overdue_tasks
        FROM tasks t
        WHERE t.moderator_id = :mid
    ");
    $st2->execute([':mid' => $moderatorId]);
    $row = $st2->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $totalTasks      = (int)($row['total_tasks'] ?? 0);
        $pendingTasks    = (int)($row['pending_tasks'] ?? 0);
        $inProgressTasks = (int)($row['in_progress_tasks'] ?? 0);
        $doneTasks       = (int)($row['done_tasks'] ?? 0);
        $overdueTasks    = (int)($row['overdue_tasks'] ?? 0);
    }

} catch (Throwable $e) {
    // تجاهل أخطاء الإحصائيات مؤقتًا
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>لوحة المشرف</title>
    <style>
        body{margin:0;background:#f4f6f9;font-family:Tahoma,Arial,sans-serif}
        .container{max-width:1100px;margin:24px auto;padding:0 12px}
        .top{
            background:#fff;border-radius:12px;padding:18px 20px;
            box-shadow:0 8px 22px rgba(0,0,0,.07);margin-bottom:16px
        }
        .top h1{margin:0 0 8px;font-size:24px}
        .muted{color:#666}
        .stats{
            display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:16px
        }
        .card{
            background:#fff;border-radius:12px;padding:16px;
            box-shadow:0 8px 22px rgba(0,0,0,.07)
        }
        .card .num{font-size:24px;font-weight:bold}
        .sections{
            display:grid;grid-template-columns:1fr 1fr;gap:16px
        }
        .box{
            background:#fff;border-radius:12px;padding:16px;
            box-shadow:0 8px 22px rgba(0,0,0,.07)
        }
        .box h3{margin-top:0}
        .links{display:flex;flex-wrap:wrap;gap:8px}
        .btn{
            display:inline-block;text-decoration:none;border:0;cursor:pointer;
            background:#1976d2;color:#fff;padding:9px 12px;border-radius:8px;font-size:14px
        }
        .btn:hover{background:#145ca3}
        .btn-green{background:#1b8f3a}
        .btn-orange{background:#ef6c00}
        .btn-gray{background:#6c757d}
        .btn-red{background:#c62828}
        @media (max-width:900px){
            .stats{grid-template-columns:1fr 1fr}
            .sections{grid-template-columns:1fr}
        }
    </style>
</head>
<body>
<div class="container">
    <div class="top">
        <h1>لوحة تحكم المشرف</h1>
        <div class="muted">
            أهلاً،
            <strong><?php echo htmlspecialchars((string)($user['full_name'] ?? 'Moderator'), ENT_QUOTES, 'UTF-8'); ?></strong>
            (<?php echo htmlspecialchars((string)($user['username'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>)
        </div>
    </div>

    <div class="stats">
        <div class="card">
            <div class="muted">عدد موظفي فريقي</div>
            <div class="num"><?php echo $totalTeamUsers; ?></div>
        </div>
        <div class="card">
            <div class="muted">إجمالي مهام الإشراف</div>
            <div class="num"><?php echo $totalTasks; ?></div>
        </div>
        <div class="card">
            <div class="muted">مهام متأخرة</div>
            <div class="num"><?php echo $overdueTasks; ?></div>
        </div>
        <div class="card">
            <div class="muted">قيد الانتظار</div>
            <div class="num"><?php echo $pendingTasks; ?></div>
        </div>
        <div class="card">
            <div class="muted">قيد التنفيذ</div>
            <div class="num"><?php echo $inProgressTasks; ?></div>
        </div>
        <div class="card">
            <div class="muted">مهام منتهية</div>
            <div class="num"><?php echo $doneTasks; ?></div>
        </div>
    </div>

    <div class="sections">
        <div class="box">
            <h3>المهام والتقارير</h3>
            <div class="links">
                <a class="btn btn-green" href="/moderator/tasks_create.php">إضافة مهمة</a>
                <a class="btn" href="/moderator/tasks_list.php">عرض المهام</a>
                <a class="btn btn-orange" href="/moderator/team_reports.php">تقارير فريقي</a>
            </div>
        </div>

        <div class="box">
            <h3>روابط سريعة</h3>
            <div class="links">
                <a class="btn btn-gray" href="/index.php">الصفحة الرئيسية</a>
                <a class="btn btn-red" href="/logout.php">تسجيل الخروج</a>
            </div>
        </div>
    </div>
</div>
</body>
</html>
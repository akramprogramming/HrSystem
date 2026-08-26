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

$user = $auth->user();

// إحصائيات بسيطة (اختياري)
$totalUsers = 0;
$totalTasks = 0;
$openTasks  = 0;
$doneTasks  = 0;

try {
    $db = Database::getConnection();

    $totalUsers = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $totalTasks = (int)$db->query("SELECT COUNT(*) FROM tasks")->fetchColumn();
    $openTasks  = (int)$db->query("SELECT COUNT(*) FROM tasks WHERE status IN ('pending','in_progress')")->fetchColumn();
    $doneTasks  = (int)$db->query("SELECT COUNT(*) FROM tasks WHERE status = 'done'")->fetchColumn();
} catch (Throwable $e) {
    // تجاهل أخطاء الإحصائيات لو جدول tasks لسه ما اتعملش
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>لوحة المدير</title>
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
            display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:16px
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
        <h1>لوحة تحكم المدير</h1>
        <div class="muted">
            أهلاً،
            <strong><?php echo htmlspecialchars((string)($user['full_name'] ?? 'Admin'), ENT_QUOTES, 'UTF-8'); ?></strong>
            (<?php echo htmlspecialchars((string)($user['username'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>)
        </div>
    </div>

    <div class="stats">
        <div class="card">
            <div class="muted">إجمالي المستخدمين</div>
            <div class="num"><?php echo $totalUsers; ?></div>
        </div>
        <div class="card">
            <div class="muted">إجمالي المهام</div>
            <div class="num"><?php echo $totalTasks; ?></div>
        </div>
        <div class="card">
            <div class="muted">مهام مفتوحة</div>
            <div class="num"><?php echo $openTasks; ?></div>
        </div>
        <div class="card">
            <div class="muted">مهام منتهية</div>
            <div class="num"><?php echo $doneTasks; ?></div>
        </div>
    </div>

    <div class="sections">
        <div class="box">
            <h3>إدارة المستخدمين</h3>
            <div class="links">
                <a class="btn btn-green" href="/admin/users_create.php">إضافة مستخدم</a>
                <a class="btn" href="/admin/users_list.php">عرض المستخدمين</a>
                <a class="btn btn-orange" href="/admin/moderator_users.php">ربط المشرفين بالموظفين</a>
            </div>
        </div>

        <div class="box">
            <h3>إدارة المهام</h3>
            <div class="links">
                <a class="btn btn-green" href="/admin/tasks_create.php">إضافة مهمة</a>
                <a class="btn" href="/admin/tasks_list.php">عرض المهام</a>
                <a class="btn btn-orange" href="/admin/reports.php">التقارير</a>
                <a class="btn btn-gray" href="/admin/audit_logs.php">سجل النشاطات</a>
            </div>
        </div>

        <!-- New attendance section: بيان الحضور و الانصراف -->
        <div class="box">
            <h3>بيان الحضور و الانصراف</h3>
            <div class="links">
                <a class="btn btn-green" href="/admin/upload_attendance.php">رفع واستيراد الحضور</a>
                <a class="btn" href="/admin/attendance_report.php">عرض تقرير الحضور</a>
            </div>
            <p class="muted" style="margin-top:10px;font-size:13px">استخدم لتحميل ملفات CSV وإدارة سجلات الحضور والانصراف.</p>
        </div>
        <!-- End attendance section -->

        <div class="box">
            <h3>روابط سريعة</h3>
            <div class="links">
                <a class="btn btn-gray" href="/public/login.php">صفحة الدخول</a>
                <a class="btn btn-gray" href="/index.php">الصفحة الرئيسية</a>
                <a class="btn btn-red" href="/logout.php">تسجيل الخروج</a>
            </div>
        </div>

        <div class="box">
            <h3>هام</h3>
            <p class="muted" style="margin:0;">
                لاي ملاحظات يرجى مراجعة  a.essam@ekelenza-me.com
            </p>
        </div>
    </div>
</div>
</body>
</html>
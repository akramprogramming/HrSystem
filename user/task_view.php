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

$taskId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($taskId <= 0) {
    http_response_code(400);
    exit('Invalid task id.');
}

/**
 * تحديث الحالة (من داخل صفحة التفاصيل)
 */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Csrf::verifyOrFail();

    $action = (string)($_POST['action'] ?? '');
    $postedTaskId = (int)($_POST['task_id'] ?? 0);
    $newStatus = trim((string)($_POST['status'] ?? ''));

    if ($action === 'update_status') {
        if ($postedTaskId !== $taskId) {
            $errors[] = 'طلب غير صالح.';
        } elseif (!in_array($newStatus, ['pending', 'in_progress', 'done'], true)) {
            $errors[] = 'الحالة المختارة غير صالحة.';
        } else {
            try {
                // تأكد أن المهمة تخص هذا المستخدم
                $chk = $db->prepare("SELECT id, title, status FROM tasks WHERE id = :id AND assigned_to = :uid LIMIT 1");
                $chk->execute([
                    ':id'  => $taskId,
                    ':uid' => $userId
                ]);
                $ownTask = $chk->fetch();

                if (!$ownTask) {
                    $errors[] = 'المهمة غير موجودة أو غير مصرح لك.';
                } else {
                    $up = $db->prepare("UPDATE tasks SET status = :status WHERE id = :id");
                    $up->execute([
                        ':status' => $newStatus,
                        ':id'     => $taskId
                    ]);

                    $success = 'تم تحديث حالة المهمة بنجاح.';

                    // Audit log اختياري
                    try {
                        $log = $db->prepare("INSERT INTO audit_logs
                            (actor_user_id, action_type, entity_type, entity_id, description, ip_address, user_agent)
                            VALUES
                            (:actor_user_id, :action_type, :entity_type, :entity_id, :description, :ip_address, :user_agent)");
                        $log->execute([
                            ':actor_user_id' => $userId,
                            ':action_type'   => 'UPDATE_TASK_STATUS',
                            ':entity_type'   => 'tasks',
                            ':entity_id'     => $taskId,
                            ':description'   => 'Updated task status to: ' . $newStatus . ' | Task: ' . (string)$ownTask['title'],
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
    } else {
        $errors[] = 'إجراء غير معروف.';
    }
}

/**
 * تحميل بيانات المهمة (المستخدم يشوف فقط مهمته)
 */
$task = null;
try {
    $sql = "SELECT
                t.id, t.title, t.description, t.priority, t.status, t.start_date, t.due_date, t.created_at, t.updated_at,
                c.full_name AS creator_name, c.username AS creator_username
            FROM tasks t
            LEFT JOIN users c ON c.id = t.created_by
            WHERE t.id = :id AND t.assigned_to = :uid
            LIMIT 1";
    $st = $db->prepare($sql);
    $st->execute([
        ':id'  => $taskId,
        ':uid' => $userId
    ]);
    $task = $st->fetch();
} catch (Throwable $e) {
    $errors[] = 'تعذر تحميل بيانات المهمة: ' . $e->getMessage();
}

if (!$task) {
    http_response_code(404);
    exit('Task not found or access denied.');
}

/**
 * Helpers لعرض الأولوية/الحالة بالعربي
 */
function priorityLabel(string $p): string {
    return match ($p) {
        'low'    => 'منخفضة',
        'medium' => 'متوسطة',
        'high'   => 'عالية',
        default  => $p
    };
}

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

function priorityClass(string $p): string {
    return match ($p) {
        'low'    => 'p-low',
        'medium' => 'p-medium',
        'high'   => 'p-high',
        default  => ''
    };
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تفاصيل المهمة #<?php echo (int)$task['id']; ?></title>
    <style>
        body { margin:0; font-family:Tahoma,Arial,sans-serif; background:#f4f6f9; }
        .container { max-width:900px; margin:24px auto; padding:0 12px; }

        .card {
            background:#fff; border-radius:12px; padding:20px;
            box-shadow:0 8px 22px rgba(0,0,0,.07);
        }

        h2 { margin:0 0 14px; }
        .meta {
            display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:14px;
        }
        .item {
            background:#fafbfc; border:1px solid #eef1f5; border-radius:10px; padding:10px;
        }
        .label { color:#666; font-size:12px; margin-bottom:3px; }
        .value { font-weight:bold; color:#222; }

        .desc {
            background:#fff; border:1px solid #eef1f5; border-radius:10px; padding:12px;
            margin:12px 0 14px; white-space:pre-wrap; line-height:1.8;
        }

        .msg-error { background:#ffe8e8; color:#b30000; border:1px solid #ffcccc; border-radius:8px; padding:10px; margin-bottom:10px; }
        .msg-ok    { background:#e8ffef; color:#0f6d2f; border:1px solid #bdeccb; border-radius:8px; padding:10px; margin-bottom:10px; }

        .badge { padding:4px 8px; border-radius:12px; color:#fff; font-size:12px; display:inline-block; }
        .b-pending { background:#6c757d; }
        .b-progress { background:#ef6c00; }
        .b-done { background:#198754; }

        .p-low { color:#198754; }
        .p-medium { color:#ef6c00; }
        .p-high { color:#c62828; }

        .actions {
            display:flex; gap:8px; flex-wrap:wrap; align-items:center;
            margin-top:12px;
        }

        select {
            padding:9px; border:1px solid #ccd3db; border-radius:8px; min-width:180px;
        }

        .btn {
            border:0; border-radius:8px; padding:9px 12px; cursor:pointer;
            color:#fff; background:#1976d2; text-decoration:none; display:inline-block;
        }
        .btn:hover { background:#145ca3; }
        .btn-green { background:#1b8f3a; }
        .btn-gray { background:#6c757d; }

        @media (max-width: 760px) {
            .meta { grid-template-columns:1fr; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="card">
        <h2>تفاصيل المهمة #<?php echo (int)$task['id']; ?></h2>

        <?php if (!empty($errors)): ?>
            <div class="msg-error">
                <?php foreach ($errors as $err): ?>
                    <div>• <?php echo htmlspecialchars($err, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <div class="msg-ok"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <div class="item" style="margin-bottom:10px;">
            <div class="label">العنوان</div>
            <div class="value"><?php echo htmlspecialchars((string)$task['title'], ENT_QUOTES, 'UTF-8'); ?></div>
        </div>

        <div class="meta">
            <div class="item">
                <div class="label">الأولوية</div>
                <div class="value <?php echo priorityClass((string)$task['priority']); ?>">
                    <?php echo htmlspecialchars(priorityLabel((string)$task['priority']), ENT_QUOTES, 'UTF-8'); ?>
                </div>
            </div>

            <div class="item">
                <div class="label">الحالة الحالية</div>
                <div class="value">
                    <span class="badge <?php echo statusClass((string)$task['status']); ?>">
                        <?php echo htmlspecialchars(statusLabel((string)$task['status']), ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                </div>
            </div>

            <div class="item">
                <div class="label">تاريخ البدء</div>
                <div class="value"><?php echo htmlspecialchars((string)$task['start_date'], ENT_QUOTES, 'UTF-8'); ?></div>
            </div>

            <div class="item">
                <div class="label">تاريخ التسليم</div>
                <div class="value"><?php echo htmlspecialchars((string)($task['due_date'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></div>
            </div>

            <div class="item">
                <div class="label">تم إنشاؤها بواسطة</div>
                <div class="value">
                    <?php
                    $creator = (string)($task['creator_name'] ?? '');
                    $creatorUser = (string)($task['creator_username'] ?? '');
                    echo htmlspecialchars($creator !== '' ? $creator . ' (' . $creatorUser . ')' : '-', ENT_QUOTES, 'UTF-8');
                    ?>
                </div>
            </div>

            <div class="item">
                <div class="label">آخر تحديث</div>
                <div class="value"><?php echo htmlspecialchars((string)($task['updated_at'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
        </div>

        <div class="label">وصف المهمة</div>
        <div class="desc"><?php echo htmlspecialchars((string)($task['description'] ?? 'لا يوجد وصف.'), ENT_QUOTES, 'UTF-8'); ?></div>

        <form method="post" action="">
            <?php echo Csrf::inputField(); ?>
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="task_id" value="<?php echo (int)$task['id']; ?>">

            <div class="actions">
                <select name="status">
                    <option value="pending" <?php echo $task['status'] === 'pending' ? 'selected' : ''; ?>>قيد الانتظار</option>
                    <option value="in_progress" <?php echo $task['status'] === 'in_progress' ? 'selected' : ''; ?>>قيد التنفيذ</option>
                    <option value="done" <?php echo $task['status'] === 'done' ? 'selected' : ''; ?>>منتهية</option>
                </select>

                <button type="submit" class="btn btn-green">حفظ الحالة</button>
                <a class="btn btn-gray" href="/user/dashboard.php">رجوع للوحة الموظف</a>
            </div>
        </form>
    </div>
</div>
</body>
</html>
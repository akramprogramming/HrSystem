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
$auth->requireAdmin();

$db = Database::getConnection();

$errors = [];
$success = '';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    exit('Invalid task id.');
}

// تحميل الموظفين (role=user)
$employees = [];
try {
    $empSt = $db->query("SELECT id, full_name, username
                         FROM users
                         WHERE role='user' AND is_active=1
                         ORDER BY full_name ASC");
    $employees = $empSt->fetchAll();
} catch (Throwable $e) {
    $errors[] = 'تعذر تحميل الموظفين: ' . $e->getMessage();
}

// تحميل المهمة (بما في ذلك image_path)
$taskSt = $db->prepare("SELECT id, title, description, assigned_to, priority, status, start_date, due_date, image_path
                        FROM tasks
                        WHERE id = :id
                        LIMIT 1");
$taskSt->execute([':id' => $id]);
$task = $taskSt->fetch();

if (!$task) {
    http_response_code(404);
    exit('Task not found.');
}

// قيم افتراضية
$title       = (string)$task['title'];
$description = (string)($task['description'] ?? '');
$assignedTo  = (int)$task['assigned_to'];
$priority    = (string)$task['priority']; // low|medium|high
$status      = (string)$task['status'];   // pending|in_progress|done
$startDate   = (string)$task['start_date'];
$dueDate     = (string)($task['due_date'] ?? '');
$currentImagePath = $task['image_path'] ?? null; // e.g. /public/uploads/tasks/filename.jpg

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Csrf::verifyOrFail();

    $title       = trim((string)($_POST['title'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $assignedTo  = (int)($_POST['assigned_to'] ?? 0);
    $priority    = trim((string)($_POST['priority'] ?? 'medium'));
    $status      = trim((string)($_POST['status'] ?? 'pending'));
    $startDate   = trim((string)($_POST['start_date'] ?? ''));
    $dueDate     = trim((string)($_POST['due_date'] ?? ''));
    $removeImage = isset($_POST['remove_image']) && $_POST['remove_image'] === '1';

    // Validation
    if ($title === '') {
        $errors[] = 'عنوان المهمة مطلوب.';
    } elseif (mb_strlen($title) < 3) {
        $errors[] = 'عنوان المهمة يجب أن يكون 3 أحرف على الأقل.';
    }

    if ($assignedTo <= 0) {
        $errors[] = 'يجب اختيار موظف.';
    } else {
        $chk = $db->prepare("SELECT id FROM users WHERE id = :id AND role='user' AND is_active=1 LIMIT 1");
        $chk->execute([':id' => $assignedTo]);
        if (!$chk->fetch()) {
            $errors[] = 'الموظف المختار غير صالح.';
        }
    }

    if (!in_array($priority, ['low', 'medium', 'high'], true)) {
        $errors[] = 'الأولوية غير صالحة.';
    }

    if (!in_array($status, ['pending', 'in_progress', 'done'], true)) {
        $errors[] = 'الحالة غير صالحة.';
    }

    if ($startDate === '') {
        $errors[] = 'تاريخ البدء مطلوب.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
        $errors[] = 'صيغة تاريخ البدء غير صحيحة.';
    }

    if ($dueDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
        $errors[] = 'صيغة تاريخ التسليم غير صحيحة.';
    }

    if ($startDate !== '' && $dueDate !== '' && strtotime($dueDate) < strtotime($startDate)) {
        $errors[] = 'تاريخ التسليم لا يمكن أن يكون قبل تاريخ البدء.';
    }

    // معالجة الصورة (حذف أو رفع)
    $newImagePath = $currentImagePath; // by default keep old
    $uploadedNewImage = false;

    if ($removeImage) {
        // طلب حذف الصورة الحالية
        if (!empty($currentImagePath)) {
            $oldFs = __DIR__ . '/../' . ltrim($currentImagePath, '/');
            if (is_file($oldFs)) {
                @unlink($oldFs);
            }
        }
        $newImagePath = null;
        $uploadedNewImage = true; // mark as changed so DB updated
    }

    // معالجة رفع ملف جديد إن وُجد
    if (empty($errors) && isset($_FILES['task_image']) && ($_FILES['task_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $fileErr = (int)($_FILES['task_image']['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($fileErr !== UPLOAD_ERR_OK) {
            $errors[] = 'حدث خطأ أثناء رفع الصورة.';
        } else {
            $size = (int)($_FILES['task_image']['size'] ?? 0);
            if ($size <= 0 || $size > 1024 * 1024) {
                $errors[] = 'حجم الصورة يجب أن يكون 1MB أو أقل.';
            } else {
                $tmp = (string)($_FILES['task_image']['tmp_name'] ?? '');
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
                    $filename = 'task_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;

                    $uploadDirFs = __DIR__ . '/../public/uploads/tasks/';
                    if (!is_dir($uploadDirFs) && !@mkdir($uploadDirFs, 0775, true) && !is_dir($uploadDirFs)) {
                        $errors[] = 'تعذر إنشاء مجلد رفع الصور.';
                    } else {
                        $destFs = $uploadDirFs . $filename;
                        if (!move_uploaded_file($tmp, $destFs)) {
                            $errors[] = 'تعذر حفظ الصورة على السيرفر.';
                        } else {
                            // حذف الصورة القديمة إن كانت موجودة
                            if (!empty($currentImagePath)) {
                                $oldFs = __DIR__ . '/../' . ltrim($currentImagePath, '/');
                                if (is_file($oldFs)) {
                                    @unlink($oldFs);
                                }
                            }

                            $newImagePath = '/public/uploads/tasks/' . $filename;
                            $uploadedNewImage = true;
                        }
                    }
                }
            }
        }
    }

    if (empty($errors)) {
        try {
            // نحدّث كل الحقول بما فيها image_path (سواء تغيّر أم لا)
            $sql = "UPDATE tasks SET
                        title = :title,
                        description = :description,
                        assigned_to = :assigned_to,
                        priority = :priority,
                        status = :status,
                        start_date = :start_date,
                        due_date = :due_date,
                        image_path = :image_path
                    WHERE id = :id";
            $up = $db->prepare($sql);
            $up->execute([
                ':title'       => $title,
                ':description' => $description !== '' ? $description : null,
                ':assigned_to' => $assignedTo,
                ':priority'    => $priority,
                ':status'      => $status,
                ':start_date'  => $startDate,
                ':due_date'    => $dueDate !== '' ? $dueDate : null,
                ':image_path'  => $newImagePath !== '' ? $newImagePath : null,
                ':id'          => $id,
            ]);

            $success = 'تم تحديث المهمة بنجاح.';

            // Audit log اختياري
            try {
                $actor = $auth->user();
                if ($actor) {
                    $log = $db->prepare("INSERT INTO audit_logs
                        (actor_user_id, action_type, entity_type, entity_id, description, ip_address, user_agent)
                        VALUES
                        (:actor_user_id, :action_type, :entity_type, :entity_id, :description, :ip_address, :user_agent)");
                    $desc = 'Updated task: ' . $title;
                    if ($uploadedNewImage) {
                        $desc .= ' | image updated';
                    }
                    $log->execute([
                        ':actor_user_id' => (int)$actor['id'],
                        ':action_type'   => 'UPDATE_TASK',
                        ':entity_type'   => 'tasks',
                        ':entity_id'     => $id,
                        ':description'   => $desc,
                        ':ip_address'    => $_SERVER['REMOTE_ADDR'] ?? null,
                        ':user_agent'    => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                    ]);
                }
            } catch (Throwable $e) {
                // تجاهل خطأ اللوج
            }

            // إعادة تحميل المهمة لتحديث المتغيرات المعروضة
            $taskSt->execute([':id' => $id]);
            $task = $taskSt->fetch();
            $currentImagePath = $task['image_path'] ?? null;
        } catch (Throwable $e) {
            $errors[] = 'حدث خطأ أثناء تحديث المهمة: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تعديل مهمة</title>
    <style>
        body { margin:0; font-family:Tahoma,Arial,sans-serif; background:#f4f6f9; }
        .container { max-width:900px; margin:28px auto; background:#fff; border-radius:12px; padding:22px; box-shadow:0 8px 22px rgba(0,0,0,.08);}
        h2 { margin:0 0 16px; color:#222; }
        .row { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        .form-group { margin-bottom:12px; }
        label { display:block; margin-bottom:6px; color:#333; font-size:14px; }
        input[type="text"], input[type="date"], select, textarea {
            width:100%; padding:10px; border:1px solid #ccd3db; border-radius:8px; box-sizing:border-box;
            font-family:inherit;
        }
        textarea { min-height:120px; resize:vertical; }
        .actions { margin-top:14px; display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
        .btn { border:0; background:#1976d2; color:#fff; padding:10px 16px; border-radius:8px; cursor:pointer; text-decoration:none; display:inline-block; }
        .btn:hover { background:#145ca3; }
        .btn-gray { background:#6c757d; }
        .error { background:#ffe8e8; color:#b30000; border:1px solid #ffcccc; border-radius:8px; padding:10px; margin-bottom:12px; }
        .success { background:#e8ffef; color:#0f6d2f; border:1px solid #bdeccb; border-radius:8px; padding:10px; margin-bottom:12px; }
        .thumb{width:120px;height:120px;object-fit:cover;border-radius:8px;border:1px solid #ddd;display:block;margin-bottom:6px}
        .img-box{display:flex;gap:12px;align-items:center}
        @media (max-width: 900px) { .row { grid-template-columns:1fr; } }
    </style>
</head>
<body>
<div class="container">
    <h2>تعديل المهمة #<?php echo (int)$id; ?></h2>

    <?php if (!empty($errors)): ?>
        <div class="error">
            <?php foreach ($errors as $err): ?>
                <div>• <?php echo htmlspecialchars($err, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($success !== ''): ?>
        <div class="success"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <form method="post" action="" enctype="multipart/form-data">
        <?php echo Csrf::inputField(); ?>

        <div class="form-group">
            <label for="title">عنوان المهمة *</label>
            <input type="text" id="title" name="title" required
                   value="<?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>">
        </div>

        <div class="form-group">
            <label for="description">وصف المهمة</label>
            <textarea id="description" name="description"><?php echo htmlspecialchars($description, ENT_QUOTES, 'UTF-8'); ?></textarea>
        </div>

        <div class="row">
            <div class="form-group">
                <label for="assigned_to">تعيين إلى (موظف) *</label>
                <select id="assigned_to" name="assigned_to" required>
                    <option value="">-- اختر موظف --</option>
                    <?php foreach ($employees as $emp): ?>
                        <option value="<?php echo (int)$emp['id']; ?>" <?php echo $assignedTo === (int)$emp['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars((string)$emp['full_name'] . ' (' . (string)$emp['username'] . ')', ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="priority">الأولوية *</label>
                <select id="priority" name="priority" required>
                    <option value="low" <?php echo $priority === 'low' ? 'selected' : ''; ?>>منخفضة</option>
                    <option value="medium" <?php echo $priority === 'medium' ? 'selected' : ''; ?>>متوسطة</option>
                    <option value="high" <?php echo $priority === 'high' ? 'selected' : ''; ?>>عالية</option>
                </select>
            </div>
        </div>

        <div class="row">
            <div class="form-group">
                <label for="status">الحالة *</label>
                <select id="status" name="status" required>
                    <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>قيد الانتظار</option>
                    <option value="in_progress" <?php echo $status === 'in_progress' ? 'selected' : ''; ?>>قيد التنفيذ</option>
                    <option value="done" <?php echo $status === 'done' ? 'selected' : ''; ?>>منتهية</option>
                </select>
            </div>

            <div class="form-group"></div>
        </div>

        <div class="row">
            <div class="form-group">
                <label for="start_date">تاريخ البدء *</label>
                <input type="date" id="start_date" name="start_date" required
                       value="<?php echo htmlspecialchars($startDate, ENT_QUOTES, 'UTF-8'); ?>">
            </div>

            <div class="form-group">
                <label for="due_date">تاريخ التسليم</label>
                <input type="date" id="due_date" name="due_date"
                       value="<?php echo htmlspecialchars($dueDate, ENT_QUOTES, 'UTF-8'); ?>">
            </div>
        </div>

        <div class="form-group full">
            <label>الصورة الحالية</label>
            <?php if (!empty($currentImagePath)): ?>
                <div class="img-box">
                    <div>
                        <a href="<?php echo htmlspecialchars($currentImagePath, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">
                            <img class="thumb" src="<?php echo htmlspecialchars($currentImagePath, ENT_QUOTES, 'UTF-8'); ?>" alt="task image">
                        </a>
                        <div style="font-size:13px;color:#666;margin-top:6px;">
                            <label><input type="checkbox" name="remove_image" value="1"> حذف الصورة الحالية</label>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div style="color:#666;">لا توجد صورة مرفوعة للمهمة.</div>
            <?php endif; ?>
        </div>

        <div class="form-group full">
            <label for="task_image">رفع صورة جديدة (اختياري — 1MB max)</label>
            <input type="file" id="task_image" name="task_image" accept="image/jpeg,image/png,image/webp,image/gif">
            <div style="font-size:13px;color:#666;margin-top:6px;">رفع صورة جديدة سيستبدل الصورة الحالية (إن وُجدت).</div>
        </div>

        <div class="actions">
            <button type="submit" class="btn">حفظ التعديلات</button>
            <a class="btn btn-gray" href="/admin/tasks_list.php">رجوع لقائمة المهام</a>
            <a class="btn btn-gray" href="/admin/dashboard.php">لوحة المدير</a>
        </div>
    </form>
</div>
</body>
</html>
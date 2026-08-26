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
$currentUser = $auth->user();
$moderatorId = (int)($currentUser['id'] ?? 0);

$errors = [];
$success = '';

$title = '';
$description = '';
$notes = '';
$assignedTo = '';
$priority = 'medium';
$status = 'pending';
$startDate = date('Y-m-d');
$dueDate = '';

// تحميل موظفي هذا المشرف فقط
$users = [];
try {
    $stUsers = $db->prepare("
        SELECT u.id, u.full_name, u.username
        FROM users u
        INNER JOIN moderator_users mu ON mu.user_id = u.id
        WHERE mu.moderator_id = :mid
          AND u.role = 'user'
          AND u.is_active = 1
        ORDER BY u.full_name ASC
    ");
    $stUsers->execute([':mid' => $moderatorId]);
    $users = $stUsers->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $errors[] = 'تعذر تحميل قائمة الموظفين: ' . $e->getMessage();
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Csrf::verifyOrFail();

    $title = trim((string)($_POST['title'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));
    $assignedTo = (string)($_POST['assigned_to'] ?? '');
    $priority = trim((string)($_POST['priority'] ?? 'medium'));
    $status = trim((string)($_POST['status'] ?? 'pending'));
    $startDate = trim((string)($_POST['start_date'] ?? ''));
    $dueDate = trim((string)($_POST['due_date'] ?? ''));

    // Validation
    if ($title === '') {
        $errors[] = 'عنوان المهمة مطلوب.';
    } elseif (mb_strlen($title) > 255) {
        $errors[] = 'عنوان المهمة يجب ألا يزيد عن 255 حرف.';
    }

    if (!in_array($priority, ['low', 'medium', 'high'], true)) {
        $errors[] = 'الأولوية غير صالحة.';
    }

    if (!in_array($status, ['pending', 'in_progress', 'done'], true)) {
        $errors[] = 'الحالة غير صالحة.';
    }

    if ($assignedTo === '' || !ctype_digit($assignedTo) || (int)$assignedTo <= 0) {
        $errors[] = 'يجب اختيار موظف صحيح.';
    }

    $startObj = DateTime::createFromFormat('Y-m-d', $startDate);
    if (!$startObj || $startObj->format('Y-m-d') !== $startDate) {
        $errors[] = 'تاريخ البدء غير صالح.';
    }

    if ($dueDate !== '') {
        $dueObj = DateTime::createFromFormat('Y-m-d', $dueDate);
        if (!$dueObj || $dueObj->format('Y-m-d') !== $dueDate) {
            $errors[] = 'تاريخ التسليم غير صالح.';
        } elseif ($startObj && $dueObj < $startObj) {
            $errors[] = 'تاريخ التسليم لا يمكن أن يكون قبل تاريخ البدء.';
        }
    }

    // التحقق أن الموظف مرتبط بهذا المشرف
    if (empty($errors)) {
        try {
            $chkEmp = $db->prepare("
                SELECT u.id
                FROM users u
                INNER JOIN moderator_users mu ON mu.user_id = u.id
                WHERE u.id = :uid
                  AND u.role = 'user'
                  AND u.is_active = 1
                  AND mu.moderator_id = :mid
                LIMIT 1
            ");
            $chkEmp->execute([
                ':uid' => (int)$assignedTo,
                ':mid' => $moderatorId,
            ]);

            if (!$chkEmp->fetch(PDO::FETCH_ASSOC)) {
                $errors[] = 'الموظف غير مرتبط بك.';
            }
        } catch (Throwable $e) {
            $errors[] = 'تعذر التحقق من الموظف: ' . $e->getMessage();
        }
    }

    // رفع الصورة (اختياري) - بحد أقصى 1MB
    $imagePath = null;
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
                    $newName = 'task_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;

                    $uploadDirFs = __DIR__ . '/../public/uploads/tasks/';
                    if (!is_dir($uploadDirFs) && !@mkdir($uploadDirFs, 0775, true) && !is_dir($uploadDirFs)) {
                        $errors[] = 'تعذر إنشاء مجلد رفع الصور.';
                    } else {
                        $destFs = $uploadDirFs . $newName;
                        if (!move_uploaded_file($tmp, $destFs)) {
                            $errors[] = 'تعذر حفظ الصورة على السيرفر.';
                        } else {
                            $imagePath = '/public/uploads/tasks/' . $newName;
                        }
                    }
                }
            }
        }
    }

    if (empty($errors)) {
        try {
            $sql = "INSERT INTO tasks
                    (title, description, notes, image_path, assigned_to, moderator_id, created_by, priority, status, start_date, due_date)
                    VALUES
                    (:title, :description, :notes, :image_path, :assigned_to, :moderator_id, :created_by, :priority, :status, :start_date, :due_date)";
            $stmt = $db->prepare($sql);
            $stmt->execute([
                ':title'        => $title,
                ':description'  => ($description !== '' ? $description : null),
                ':notes'        => ($notes !== '' ? $notes : null),
                ':image_path'   => $imagePath,
                ':assigned_to'  => (int)$assignedTo,
                ':moderator_id' => $moderatorId,
                ':created_by'   => $moderatorId,
                ':priority'     => $priority,
                ':status'       => $status,
                ':start_date'   => $startDate,
                ':due_date'     => ($dueDate !== '' ? $dueDate : null),
            ]);

            $newTaskId = (int)$db->lastInsertId();

            // Audit log
            try {
                $log = $db->prepare("
                    INSERT INTO audit_logs
                    (actor_user_id, action_type, entity_type, entity_id, description, ip_address, user_agent)
                    VALUES
                    (:actor_user_id, :action_type, :entity_type, :entity_id, :description, :ip_address, :user_agent)
                ");
                $log->execute([
                    ':actor_user_id' => $moderatorId,
                    ':action_type'   => 'CREATE_TASK_BY_MODERATOR',
                    ':entity_type'   => 'tasks',
                    ':entity_id'     => $newTaskId,
                    ':description'   => 'Moderator created task: ' . $title,
                    ':ip_address'    => $_SERVER['REMOTE_ADDR'] ?? null,
                    ':user_agent'    => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                ]);
            } catch (Throwable $e) {}

            $success = 'تم إنشاء المهمة بنجاح.';
            $title = '';
            $description = '';
            $notes = '';
            $assignedTo = '';
            $priority = 'medium';
            $status = 'pending';
            $startDate = date('Y-m-d');
            $dueDate = '';
        } catch (Throwable $e) {
            $errors[] = 'حدث خطأ أثناء إنشاء المهمة: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إضافة مهمة جديدة (مشرف)</title>
    <style>
        body { margin:0; font-family:Tahoma,Arial,sans-serif; background:#f4f6f9; }
        .container { max-width:900px; margin:30px auto; padding:0 12px; }
        .card {
            background:#fff; border-radius:14px; padding:20px;
            box-shadow:0 8px 22px rgba(0,0,0,.08);
        }
        h1 { margin:0 0 18px; font-size:34px; }
        .msg-error { background:#ffe8e8; color:#b30000; border:1px solid #ffcccc; border-radius:8px; padding:10px; margin-bottom:12px; }
        .msg-ok    { background:#e8ffef; color:#0f6d2f; border:1px solid #bdeccb; border-radius:8px; padding:10px; margin-bottom:12px; }

        .grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
        .full { grid-column:1 / -1; }

        label { display:block; margin:6px 0; font-weight:bold; }
        input[type="text"], input[type="date"], input[type="file"], textarea, select {
            width:100%; box-sizing:border-box; padding:10px; border-radius:10px;
            border:1px solid #ccd3db; font-size:16px; background:#fff;
        }
        textarea { min-height:130px; resize:vertical; }

        .actions { margin-top:16px; display:flex; gap:8px; flex-wrap:wrap; }
        .btn {
            border:0; border-radius:10px; padding:10px 14px; color:#fff;
            text-decoration:none; cursor:pointer; font-size:15px;
            background:#1976d2;
        }
        .btn:hover { background:#145ca3; }
        .btn-gray { background:#6c757d; }
        .help { color:#666; font-size:13px; margin-top:4px; }

        @media (max-width: 700px) {
            .grid { grid-template-columns:1fr; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="card">
        <h1>إضافة مهمة جديدة (مشرف)</h1>

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

        <form method="post" action="" enctype="multipart/form-data">
            <?php echo Csrf::inputField(); ?>

            <div class="grid">
                <div class="full">
                    <label for="title">عنوان المهمة *</label>
                    <input type="text" id="title" name="title" maxlength="255"
                           value="<?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>

                <div class="full">
                    <label for="description">وصف المهمة</label>
                    <textarea id="description" name="description"><?php echo htmlspecialchars($description, ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>

                <div class="full">
                    <label for="notes">ملاحظات</label>
                    <textarea id="notes" name="notes"><?php echo htmlspecialchars($notes, ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>

                <div class="full">
                    <label for="task_image">إرفاق صورة (اختياري - 1MB max)</label>
                    <input type="file" id="task_image" name="task_image" accept="image/jpeg,image/png,image/webp,image/gif">
                    <div class="help">الحد الأقصى لحجم الصورة: 1MB.</div>
                </div>

                <div>
                    <label for="assigned_to">تعيين إلى (الموظف) *</label>
                    <select id="assigned_to" name="assigned_to" required>
                        <option value="">-- اختر موظف --</option>
                        <?php foreach ($users as $u): ?>
                            <?php $uid = (int)$u['id']; ?>
                            <option value="<?php echo $uid; ?>" <?php echo ((string)$uid === $assignedTo) ? 'selected' : ''; ?>>
                                <?php
                                echo htmlspecialchars((string)$u['full_name'], ENT_QUOTES, 'UTF-8')
                                    . ' (' . htmlspecialchars((string)$u['username'], ENT_QUOTES, 'UTF-8') . ')';
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="priority">الأولوية *</label>
                    <select id="priority" name="priority" required>
                        <option value="low" <?php echo $priority === 'low' ? 'selected' : ''; ?>>منخفضة</option>
                        <option value="medium" <?php echo $priority === 'medium' ? 'selected' : ''; ?>>متوسطة</option>
                        <option value="high" <?php echo $priority === 'high' ? 'selected' : ''; ?>>عالية</option>
                    </select>
                </div>

                <div>
                    <label for="status">الحالة *</label>
                    <select id="status" name="status" required>
                        <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>قيد الانتظار</option>
                        <option value="in_progress" <?php echo $status === 'in_progress' ? 'selected' : ''; ?>>قيد التنفيذ</option>
                        <option value="done" <?php echo $status === 'done' ? 'selected' : ''; ?>>منتهية</option>
                    </select>
                </div>

                <div>
                    <label for="start_date">تاريخ البدء *</label>
                    <input type="date" id="start_date" name="start_date"
                           value="<?php echo htmlspecialchars($startDate, ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>

                <div>
                    <label for="due_date">تاريخ التسليم</label>
                    <input type="date" id="due_date" name="due_date"
                           value="<?php echo htmlspecialchars($dueDate, ENT_QUOTES, 'UTF-8'); ?>">
                </div>
            </div>

            <div class="actions">
                <button class="btn" type="submit">حفظ المهمة</button>
                <a class="btn btn-gray" href="/moderator/tasks_list.php">عرض المهام</a>
                <a class="btn btn-gray" href="/moderator/dashboard.php">العودة للوحة المشرف</a>
            </div>
        </form>
    </div>
</div>
</body>
</html>
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

$errors = [];
$success = '';

$fullName       = '';
$username       = '';
$role           = 'user';
$employeeNumber = '';
$jobTitle       = '';
$isActive       = 1;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Csrf::verifyOrFail();

    $fullName       = trim((string)($_POST['full_name'] ?? ''));
    $username       = trim((string)($_POST['username'] ?? ''));
    $password       = (string)($_POST['password'] ?? '');
    $role           = trim((string)($_POST['role'] ?? 'user'));
    $employeeNumber = trim((string)($_POST['employee_number'] ?? ''));
    $jobTitle       = trim((string)($_POST['job_title'] ?? ''));
    $isActive       = isset($_POST['is_active']) ? 1 : 0;

    // Validation
    if ($fullName === '') {
        $errors[] = 'الاسم الكامل مطلوب.';
    }

    if ($username === '') {
        $errors[] = 'اسم المستخدم مطلوب.';
    } elseif (mb_strlen($username) < 3) {
        $errors[] = 'اسم المستخدم يجب أن يكون 3 أحرف على الأقل.';
    }

    if ($password === '') {
        $errors[] = 'كلمة المرور مطلوبة.';
    } elseif (mb_strlen($password) < 6) {
        $errors[] = 'كلمة المرور يجب أن تكون 6 أحرف على الأقل.';
    }

    // admin + moderator + user
    if (!in_array($role, ['admin', 'moderator', 'user'], true)) {
        $errors[] = 'الدور غير صالح.';
    }

    if (empty($errors)) {
        try {
            $db = Database::getConnection();

            // تحقق من عدم تكرار username
            $checkStmt = $db->prepare("SELECT id FROM users WHERE username = :username LIMIT 1");
            $checkStmt->execute([':username' => $username]);
            if ($checkStmt->fetch()) {
                $errors[] = 'اسم المستخدم مستخدم بالفعل.';
            } else {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                $insertSql = "INSERT INTO users
                    (full_name, username, password_hash, role, employee_number, job_title, is_active)
                    VALUES
                    (:full_name, :username, :password_hash, :role, :employee_number, :job_title, :is_active)";
                $insertStmt = $db->prepare($insertSql);
                $insertStmt->execute([
                    ':full_name'       => $fullName,
                    ':username'        => $username,
                    ':password_hash'   => $passwordHash,
                    ':role'            => $role,
                    ':employee_number' => $employeeNumber !== '' ? $employeeNumber : null,
                    ':job_title'       => $jobTitle !== '' ? $jobTitle : null,
                    ':is_active'       => $isActive,
                ]);

                $newUserId = (int)$db->lastInsertId();

                // Audit log (اختياري آمن)
                try {
                    $actor = $auth->user();
                    if ($actor) {
                        $logSql = "INSERT INTO audit_logs
                            (actor_user_id, action_type, entity_type, entity_id, description, ip_address, user_agent)
                            VALUES
                            (:actor_user_id, :action_type, :entity_type, :entity_id, :description, :ip_address, :user_agent)";
                        $logStmt = $db->prepare($logSql);
                        $logStmt->execute([
                            ':actor_user_id' => (int)$actor['id'],
                            ':action_type'   => 'CREATE_USER',
                            ':entity_type'   => 'users',
                            ':entity_id'     => $newUserId,
                            ':description'   => 'Admin created user: ' . $username . ' | role: ' . $role,
                            ':ip_address'    => $_SERVER['REMOTE_ADDR'] ?? null,
                            ':user_agent'    => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                        ]);
                    }
                } catch (Throwable $e) {
                    // تجاهل خطأ اللوج حتى لا يعطل العملية الأساسية
                }

                $success = 'تم إنشاء المستخدم بنجاح.';
                $fullName = $username = $employeeNumber = $jobTitle = '';
                $role = 'user';
                $isActive = 1;
            }
        } catch (Throwable $e) {
            $errors[] = 'حدث خطأ أثناء حفظ البيانات: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إضافة موظف</title>
    <style>
        body { margin:0; font-family:Tahoma,Arial,sans-serif; background:#f4f6f9; }
        .container { max-width:700px; margin:30px auto; background:#fff; border-radius:12px; padding:22px; box-shadow:0 8px 22px rgba(0,0,0,.08);}
        h2 { margin:0 0 18px; color:#222; }
        .row { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        .form-group { margin-bottom:12px; }
        label { display:block; margin-bottom:6px; color:#333; font-size:14px; }
        input[type="text"], input[type="password"], select {
            width:100%; padding:10px; border:1px solid #ccd3db; border-radius:8px; box-sizing:border-box;
        }
        .actions { margin-top:14px; display:flex; gap:10px; align-items:center; }
        .btn { border:0; background:#1976d2; color:#fff; padding:10px 16px; border-radius:8px; cursor:pointer; }
        .btn:hover { background:#145ca3; }
        .link { color:#1976d2; text-decoration:none; }
        .error { background:#ffe8e8; color:#b30000; border:1px solid #ffcccc; border-radius:8px; padding:10px; margin-bottom:12px; }
        .success { background:#e8ffef; color:#0f6d2f; border:1px solid #bdeccb; border-radius:8px; padding:10px; margin-bottom:12px; }
        .check { display:flex; align-items:center; gap:8px; margin-top:8px; }
        .hint { font-size:12px; color:#666; margin-top:4px; }
        @media (max-width: 700px) { .row { grid-template-columns:1fr; } }
    </style>
</head>
<body>
<div class="container">
    <h2>إضافة موظف / مستخدم جديد</h2>

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

    <form method="post" action="">
        <?php echo Csrf::inputField(); ?>

        <div class="row">
            <div class="form-group">
                <label for="full_name">الاسم الكامل *</label>
                <input type="text" id="full_name" name="full_name" required
                       value="<?php echo htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'); ?>">
            </div>

            <div class="form-group">
                <label for="username">اسم المستخدم *</label>
                <input type="text" id="username" name="username" required
                       value="<?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?>">
            </div>
        </div>

        <div class="row">
            <div class="form-group">
                <label for="password">كلمة المرور *</label>
                <input type="password" id="password" name="password" required>
            </div>

            <div class="form-group">
                <label for="role">الدور *</label>
                <select id="role" name="role" required>
                    <option value="user" <?php echo $role === 'user' ? 'selected' : ''; ?>>User</option>
                    <option value="moderator" <?php echo $role === 'moderator' ? 'selected' : ''; ?>>Moderator</option>
                    <option value="admin" <?php echo $role === 'admin' ? 'selected' : ''; ?>>Admin</option>
                </select>
                <div class="hint">Moderator: مشرف على مجموعة موظفين بصلاحيات يحددها المدير.</div>
            </div>
        </div>

        <div class="row">
            <div class="form-group">
                <label for="employee_number">الرقم الوظيفي</label>
                <input type="text" id="employee_number" name="employee_number"
                       value="<?php echo htmlspecialchars($employeeNumber, ENT_QUOTES, 'UTF-8'); ?>">
            </div>

            <div class="form-group">
                <label for="job_title">المسمى الوظيفي</label>
                <input type="text" id="job_title" name="job_title"
                       value="<?php echo htmlspecialchars($jobTitle, ENT_QUOTES, 'UTF-8'); ?>">
            </div>
        </div>

        <div class="check">
            <input type="checkbox" id="is_active" name="is_active" <?php echo $isActive === 1 ? 'checked' : ''; ?>>
            <label for="is_active" style="margin:0;">الحساب نشط</label>
        </div>

        <div class="actions">
            <button type="submit" class="btn">حفظ المستخدم</button>
            <a class="link" href="/admin/dashboard.php">العودة للوحة المدير</a>
        </div>
    </form>
</div>
</body>
</html>
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
    header('Location: /admin/users_list.php?err=invalid_user_id');
    exit;
}

$stmt = $db->prepare("
    SELECT id, full_name, username, role, employee_number, job_title, is_active
    FROM users
    WHERE id = :id
    LIMIT 1
");
$stmt->execute([':id' => $id]);
$targetUser = $stmt->fetch();

if (!$targetUser) {
    header('Location: /admin/users_list.php?err=user_not_found');
    exit;
}

$currentUser = $auth->user();
$currentUserId = (int)($currentUser['id'] ?? 0);

// old values for audit diff
$oldFullName       = (string)$targetUser['full_name'];
$oldUsername       = (string)$targetUser['username'];
$oldRole           = (string)$targetUser['role'];
$oldEmployeeNumber = (string)($targetUser['employee_number'] ?? '');
$oldJobTitle       = (string)($targetUser['job_title'] ?? '');
$oldIsActive       = (int)$targetUser['is_active'];

// form values
$fullName       = $oldFullName;
$username       = $oldUsername;
$role           = $oldRole;
$employeeNumber = $oldEmployeeNumber;
$jobTitle       = $oldJobTitle;
$isActive       = $oldIsActive;

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

    if ($username === '' || mb_strlen($username) < 3) {
        $errors[] = 'اسم المستخدم غير صالح.';
    }

    // admin + moderator + user
    if (!in_array($role, ['admin', 'moderator', 'user'], true)) {
        $errors[] = 'الدور غير صالح.';
    }

    if ($password !== '' && mb_strlen($password) < 6) {
        $errors[] = 'كلمة المرور الجديدة قصيرة.';
    }

    // self-protection for current admin account
    if ($id === $currentUserId) {
        if ($isActive !== 1) {
            $errors[] = 'لا يمكنك تعطيل حسابك.';
        }
        if ($role !== 'admin') {
            $errors[] = 'لا يمكنك تغيير دورك من Admin.';
        }
    }

    if (empty($errors)) {
        $check = $db->prepare("SELECT id FROM users WHERE username = :username AND id <> :id LIMIT 1");
        $check->execute([
            ':username' => $username,
            ':id'       => $id
        ]);

        if ($check->fetch()) {
            $errors[] = 'اسم المستخدم مستخدم بالفعل.';
        } else {
            if ($password !== '') {
                $sql = "UPDATE users SET
                            full_name = :full_name,
                            username = :username,
                            password_hash = :password_hash,
                            role = :role,
                            employee_number = :employee_number,
                            job_title = :job_title,
                            is_active = :is_active
                        WHERE id = :id";
                $params = [
                    ':full_name'       => $fullName,
                    ':username'        => $username,
                    ':password_hash'   => password_hash($password, PASSWORD_DEFAULT),
                    ':role'            => $role,
                    ':employee_number' => $employeeNumber !== '' ? $employeeNumber : null,
                    ':job_title'       => $jobTitle !== '' ? $jobTitle : null,
                    ':is_active'       => $isActive,
                    ':id'              => $id
                ];
            } else {
                $sql = "UPDATE users SET
                            full_name = :full_name,
                            username = :username,
                            role = :role,
                            employee_number = :employee_number,
                            job_title = :job_title,
                            is_active = :is_active
                        WHERE id = :id";
                $params = [
                    ':full_name'       => $fullName,
                    ':username'        => $username,
                    ':role'            => $role,
                    ':employee_number' => $employeeNumber !== '' ? $employeeNumber : null,
                    ':job_title'       => $jobTitle !== '' ? $jobTitle : null,
                    ':is_active'       => $isActive,
                    ':id'              => $id
                ];
            }

            $up = $db->prepare($sql);
            $up->execute($params);

            // refresh session if admin edited himself
            if ($id === $currentUserId) {
                Session::set('user', [
                    'id'        => $id,
                    'full_name' => $fullName,
                    'username'  => $username,
                    'role'      => $role
                ]);
            }

            // audit log
            try {
                $changes = [];
                if ($oldFullName !== $fullName) $changes[] = "full_name: {$oldFullName} -> {$fullName}";
                if ($oldUsername !== $username) $changes[] = "username: {$oldUsername} -> {$username}";
                if ($oldRole !== $role) $changes[] = "role: {$oldRole} -> {$role}";
                if ($oldEmployeeNumber !== $employeeNumber) $changes[] = "employee_number changed";
                if ($oldJobTitle !== $jobTitle) $changes[] = "job_title changed";
                if ($oldIsActive !== $isActive) $changes[] = "is_active: {$oldIsActive} -> {$isActive}";
                if ($password !== '') $changes[] = "password changed";

                $description = 'Admin updated user #' . $id;
                if (!empty($changes)) {
                    $description .= ' | ' . implode(' | ', $changes);
                }

                $actor = $auth->user();
                if ($actor) {
                    $logSql = "INSERT INTO audit_logs
                        (actor_user_id, action_type, entity_type, entity_id, description, ip_address, user_agent)
                        VALUES
                        (:actor_user_id, :action_type, :entity_type, :entity_id, :description, :ip_address, :user_agent)";
                    $logStmt = $db->prepare($logSql);
                    $logStmt->execute([
                        ':actor_user_id' => (int)$actor['id'],
                        ':action_type'   => 'UPDATE_USER',
                        ':entity_type'   => 'users',
                        ':entity_id'     => $id,
                        ':description'   => $description,
                        ':ip_address'    => $_SERVER['REMOTE_ADDR'] ?? null,
                        ':user_agent'    => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                    ]);
                }
            } catch (Throwable $e) {
                // لا توقف العملية لو اللوج فشل
            }

            // refresh old values after successful save
            $oldFullName       = $fullName;
            $oldUsername       = $username;
            $oldRole           = $role;
            $oldEmployeeNumber = $employeeNumber;
            $oldJobTitle       = $jobTitle;
            $oldIsActive       = $isActive;

            $success = 'تم تحديث بيانات المستخدم بنجاح.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تعديل مستخدم</title>
    <style>
        body { font-family: Tahoma, Arial, sans-serif; background: #f4f6f9; margin: 0; }
        .container { max-width: 760px; margin: 28px auto; background: #fff; padding: 22px; border-radius: 12px; }
        .row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .form-group { margin-bottom: 12px; }
        label { display:block; margin-bottom:6px; color:#333; }
        input, select { width: 100%; padding: 10px; border: 1px solid #ccd3db; border-radius: 8px; box-sizing: border-box; }
        .btn { border: 0; background: #1976d2; color: #fff; padding: 10px 16px; border-radius: 8px; cursor: pointer; }
        .btn:hover { background:#145ca3; }
        .msg { padding: 10px; border-radius: 8px; margin-bottom: 10px; }
        .ok { background: #e8ffef; color:#0f6d2f; }
        .err { background: #ffe8e8; color:#b30000; }
        .hint { font-size:12px; color:#666; margin-top:4px; }
    </style>
</head>
<body>
<div class="container">
    <h2>تعديل المستخدم #<?php echo (int)$id; ?></h2>

    <?php if ($success): ?>
        <div class="msg ok"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php foreach ($errors as $e): ?>
        <div class="msg err"><?php echo htmlspecialchars($e, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endforeach; ?>

    <form method="post">
        <?php echo Csrf::inputField(); ?>

        <div class="row">
            <div class="form-group">
                <label>الاسم الكامل</label>
                <input type="text" name="full_name" value="<?php echo htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="form-group">
                <label>اسم المستخدم</label>
                <input type="text" name="username" value="<?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?>">
            </div>
        </div>

        <div class="row">
            <div class="form-group">
                <label>كلمة المرور الجديدة (اختياري)</label>
                <input type="password" name="password">
            </div>
            <div class="form-group">
                <label>الدور</label>
                <select name="role">
                    <option value="user" <?php echo $role === 'user' ? 'selected' : ''; ?>>User</option>
                    <option value="moderator" <?php echo $role === 'moderator' ? 'selected' : ''; ?>>Moderator</option>
                    <option value="admin" <?php echo $role === 'admin' ? 'selected' : ''; ?>>Admin</option>
                </select>
                <div class="hint">Moderator: مشرف على مجموعة موظفين بصلاحيات يحددها المدير.</div>
            </div>
        </div>

        <div class="row">
            <div class="form-group">
                <label>الرقم الوظيفي</label>
                <input type="text" name="employee_number" value="<?php echo htmlspecialchars($employeeNumber, ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="form-group">
                <label>المسمى الوظيفي</label>
                <input type="text" name="job_title" value="<?php echo htmlspecialchars($jobTitle, ENT_QUOTES, 'UTF-8'); ?>">
            </div>
        </div>

        <label>
            <input type="checkbox" name="is_active" <?php echo $isActive === 1 ? 'checked' : ''; ?>>
            الحساب نشط
        </label>

        <br><br>

        <button class="btn" type="submit">حفظ التعديلات</button>
        <a class="btn" style="text-decoration:none;background:#6c757d" href="/admin/users_list.php">رجوع</a>
    </form>
</div>
</body>
</html>
<?php
declare(strict_types=1);

/**
 * استخدم الملف مرة واحدة فقط لإنشاء أول مدير
 * وبعد النجاح احذفه فورًا لأسباب أمنية
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Database.php';

use Core\Database;

$done = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $fullName       = trim((string)($_POST['full_name'] ?? ''));
    $username       = trim((string)($_POST['username'] ?? ''));
    $password       = (string)($_POST['password'] ?? '');
    $employeeNumber = trim((string)($_POST['employee_number'] ?? 'ADM-001'));
    $jobTitle       = trim((string)($_POST['job_title'] ?? 'System Admin'));

    if ($fullName === '' || $username === '' || $password === '') {
        $error = 'من فضلك أدخل كل الحقول المطلوبة.';
    } elseif (mb_strlen($password) < 6) {
        $error = 'كلمة المرور لازم تكون 6 أحرف على الأقل.';
    } else {
        try {
            $hash = password_hash($password, PASSWORD_DEFAULT);

            $sql = "INSERT INTO users
                    (full_name, username, password_hash, role, employee_number, job_title, is_active)
                    VALUES
                    (:full_name, :username, :password_hash, 'admin', :employee_number, :job_title, 1)";
            $stmt = Database::getConnection()->prepare($sql);
            $stmt->execute([
                ':full_name'       => $fullName,
                ':username'        => $username,
                ':password_hash'   => $hash,
                ':employee_number' => $employeeNumber !== '' ? $employeeNumber : null,
                ':job_title'       => $jobTitle !== '' ? $jobTitle : null,
            ]);

            $done = 'تم إنشاء حساب المدير بنجاح. احذف ملف create_admin.php الآن ثم اذهب إلى /login.php';
        } catch (Throwable $e) {
            $error = 'فشل إنشاء الحساب. غالبًا اسم المستخدم أو الرقم الوظيفي مستخدم بالفعل.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إنشاء أول مدير</title>
</head>
<body style="font-family:Tahoma,Arial;background:#f7f7f7;padding:20px;">
    <h2>إنشاء أول حساب مدير</h2>

    <?php if ($error): ?>
        <div style="background:#ffeaea;color:#a80000;padding:10px;border-radius:8px;margin-bottom:10px;">
            <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <?php if ($done): ?>
        <div style="background:#e9ffef;color:#116b2f;padding:10px;border-radius:8px;margin-bottom:10px;">
            <?php echo htmlspecialchars($done, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <form method="post" action="">
        <div style="margin-bottom:10px;">
            <label>الاسم الكامل</label><br>
            <input type="text" name="full_name" required style="width:320px;padding:8px;">
        </div>

        <div style="margin-bottom:10px;">
            <label>اسم المستخدم</label><br>
            <input type="text" name="username" required style="width:320px;padding:8px;">
        </div>

        <div style="margin-bottom:10px;">
            <label>كلمة المرور</label><br>
            <input type="password" name="password" required style="width:320px;padding:8px;">
        </div>

        <div style="margin-bottom:10px;">
            <label>الرقم الوظيفي</label><br>
            <input type="text" name="employee_number" value="ADM-001" style="width:320px;padding:8px;">
        </div>

        <div style="margin-bottom:10px;">
            <label>الوظيفة</label><br>
            <input type="text" name="job_title" value="System Admin" style="width:320px;padding:8px;">
        </div>

        <button type="submit" style="padding:10px 20px;">إنشاء الحساب</button>
    </form>
</body>
</html>
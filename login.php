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

if ($auth->check()) {
    if ($auth->isAdmin()) {
        header('Location: /admin/dashboard.php');
        exit;
    }

    if ($auth->isModerator()) {
        header('Location: /moderator/dashboard.php');
        exit;
    }

    header('Location: /user/dashboard.php');
    exit;
}

$error = '';
$username = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Csrf::verifyOrFail();

    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'من فضلك أدخل اسم المستخدم وكلمة المرور.';
    } else {
        if ($auth->attemptLogin($username, $password)) {

            // Audit Log اختياري وآمن
            try {
                $currentUser = $auth->user();
                if ($currentUser) {
                    $sql = "INSERT INTO audit_logs
                            (actor_user_id, action_type, entity_type, entity_id, description, ip_address, user_agent)
                            VALUES
                            (:actor_user_id, :action_type, :entity_type, :entity_id, :description, :ip_address, :user_agent)";
                    $stmt = Database::getConnection()->prepare($sql);
                    $stmt->execute([
                        ':actor_user_id' => (int)$currentUser['id'],
                        ':action_type'   => 'LOGIN',
                        ':entity_type'   => 'users',
                        ':entity_id'     => (int)$currentUser['id'],
                        ':description'   => 'User logged in successfully',
                        ':ip_address'    => $_SERVER['REMOTE_ADDR'] ?? null,
                        ':user_agent'    => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                    ]);
                }
            } catch (\Throwable $e) {
                // لا توقف تسجيل الدخول بسبب اللوج
            }

            if ($auth->isAdmin()) {
                header('Location: /admin/dashboard.php');
                exit;
            }

            if ($auth->isModerator()) {
                header('Location: /moderator/dashboard.php');
                exit;
            }

            header('Location: /user/dashboard.php');
            exit;
        } else {
            $error = 'بيانات الدخول غير صحيحة أو الحساب غير نشط.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تسجيل الدخول</title>
    <style>
        body{font-family:Tahoma,Arial;background:#f4f6f9}
        .box{max-width:420px;margin:70px auto;background:#fff;padding:24px;border-radius:12px}
        input{width:100%;padding:10px;margin:6px 0 12px}
        button{width:100%;padding:11px;background:#1976d2;color:#fff;border:0;border-radius:8px}
        .err{background:#ffe8e8;color:#b30000;padding:10px;border-radius:8px;margin-bottom:10px}
        .warn{background:#fff3cd;color:#7a5a00;padding:10px;border-radius:8px;margin-bottom:10px}
    </style>
</head>
<body>
<div class="box">
    <h2 style="text-align:center;">تسجيل الدخول</h2>

    <?php if (isset($_GET['timeout']) && $_GET['timeout'] == '1'): ?>
        <div class="warn">تم تسجيل الخروج تلقائيًا بعد دقيقة من عدم النشاط.</div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="err"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <form method="POST" action="">
        <?php echo Csrf::inputField(); ?>
        <label>اسم المستخدم</label>
        <input type="text" name="username" value="<?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?>">
        <label>كلمة المرور</label>
        <input type="password" name="password">
        <button type="submit">دخول</button>
    </form>
</div>
</body>
</html>
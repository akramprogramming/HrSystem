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
$auth->requireAdmin();

$db = Database::getConnection();
$admin = $auth->user();
$adminId = (int)($admin['id'] ?? 0);

$errors = [];
$success = '';

$selectedModeratorId = isset($_GET['moderator_id']) && ctype_digit((string)$_GET['moderator_id'])
    ? (int)$_GET['moderator_id'] : 0;

// Handle POST (Save assignments)
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Csrf::verifyOrFail();

    $selectedModeratorId = (int)($_POST['moderator_id'] ?? 0);
    $selectedUsersRaw = $_POST['user_ids'] ?? [];
    $selectedUsers = [];

    if ($selectedModeratorId <= 0) {
        $errors[] = 'الرجاء اختيار مشرف.';
    }

    if (!is_array($selectedUsersRaw)) {
        $selectedUsersRaw = [];
    }

    foreach ($selectedUsersRaw as $uid) {
        $uid = (string)$uid;
        if (ctype_digit($uid) && (int)$uid > 0) {
            $selectedUsers[] = (int)$uid;
        }
    }
    $selectedUsers = array_values(array_unique($selectedUsers));

    // Validate moderator
    if (empty($errors)) {
        $st = $db->prepare("
            SELECT id
            FROM users
            WHERE id = :id AND role = 'moderator' AND is_active = 1
            LIMIT 1
        ");
        $st->execute([':id' => $selectedModeratorId]);
        if (!$st->fetch(PDO::FETCH_ASSOC)) {
            $errors[] = 'المشرف المحدد غير صالح أو غير نشط.';
        }
    }

    // Validate users are active role=user
    if (empty($errors) && !empty($selectedUsers)) {
        $in = implode(',', array_fill(0, count($selectedUsers), '?'));
        $st = $db->prepare("
            SELECT id
            FROM users
            WHERE id IN ($in) AND role = 'user' AND is_active = 1
        ");
        $st->execute($selectedUsers);
        $validIds = array_map('intval', array_column($st->fetchAll(PDO::FETCH_ASSOC), 'id'));
        sort($validIds);

        $tmp = $selectedUsers;
        sort($tmp);

        if ($validIds !== $tmp) {
            $errors[] = 'يوجد موظف واحد على الأقل غير صالح/غير نشط.';
        }
    }

    // Save (SYNC): delete old then insert new
    if (empty($errors)) {
        try {
            $db->beginTransaction();

            $del = $db->prepare("DELETE FROM moderator_users WHERE moderator_id = :mid");
            $del->execute([':mid' => $selectedModeratorId]);

            if (!empty($selectedUsers)) {
                $ins = $db->prepare("
                    INSERT INTO moderator_users (moderator_id, user_id)
                    VALUES (:mid, :uid)
                ");
                foreach ($selectedUsers as $uid) {
                    $ins->execute([
                        ':mid' => $selectedModeratorId,
                        ':uid' => $uid,
                    ]);
                }
            }

            $db->commit();
            $success = 'تم تحديث موظفي المشرف بنجاح.';
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $errors[] = 'حدث خطأ أثناء الحفظ: ' . $e->getMessage();
        }

        // Audit log
        try {
            $log = $db->prepare("
                INSERT INTO audit_logs
                (actor_user_id, action_type, entity_type, entity_id, description, ip_address, user_agent)
                VALUES
                (:actor_user_id, :action_type, :entity_type, :entity_id, :description, :ip_address, :user_agent)
            ");
            $log->execute([
                ':actor_user_id' => $adminId > 0 ? $adminId : null,
                ':action_type'   => 'SYNC_MODERATOR_USERS',
                ':entity_type'   => 'moderator_users',
                ':entity_id'     => $selectedModeratorId > 0 ? $selectedModeratorId : null,
                ':description'   => 'Admin synced moderator employees',
                ':ip_address'    => $_SERVER['REMOTE_ADDR'] ?? null,
                ':user_agent'    => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);
        } catch (Throwable $e) {}
    }
}

// Load moderators
$moderators = [];
try {
    $st = $db->query("
        SELECT id, full_name, username
        FROM users
        WHERE role = 'moderator' AND is_active = 1
        ORDER BY full_name ASC
    ");
    $moderators = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $errors[] = 'تعذر تحميل المشرفين: ' . $e->getMessage();
}

// Load all employees
$employees = [];
try {
    $st = $db->query("
        SELECT id, full_name, username
        FROM users
        WHERE role = 'user' AND is_active = 1
        ORDER BY full_name ASC
    ");
    $employees = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $errors[] = 'تعذر تحميل الموظفين: ' . $e->getMessage();
}

// Load selected moderator current employee ids
$selectedEmployeeIds = [];
if ($selectedModeratorId > 0) {
    try {
        $st = $db->prepare("
            SELECT user_id
            FROM moderator_users
            WHERE moderator_id = :mid
        ");
        $st->execute([':mid' => $selectedModeratorId]);
        $selectedEmployeeIds = array_map('intval', array_column($st->fetchAll(PDO::FETCH_ASSOC), 'user_id'));
    } catch (Throwable $e) {
        $errors[] = 'تعذر تحميل موظفي المشرف المختار: ' . $e->getMessage();
    }
}

// Summary table (all mappings)
$summary = [];
try {
    $st = $db->query("
        SELECT
            m.id AS moderator_id,
            m.full_name AS moderator_name,
            m.username AS moderator_username,
            COUNT(mu.user_id) AS employees_count
        FROM users m
        LEFT JOIN moderator_users mu ON mu.moderator_id = m.id
        WHERE m.role = 'moderator' AND m.is_active = 1
        GROUP BY m.id, m.full_name, m.username
        ORDER BY m.full_name ASC
    ");
    $summary = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $errors[] = 'تعذر تحميل الملخص: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة ربط المشرفين بالموظفين</title>
    <style>
        body { margin:0; font-family:Tahoma,Arial,sans-serif; background:#f4f6f9; }
        .container { max-width:1100px; margin:24px auto; padding:0 12px; }
        .card { background:#fff; border-radius:12px; padding:16px; box-shadow:0 8px 22px rgba(0,0,0,.07); margin-bottom:14px; }
        h1 { margin:0 0 12px; font-size:30px; }

        .msg-error { background:#ffe8e8; color:#b30000; border:1px solid #ffcccc; border-radius:8px; padding:10px; margin-bottom:10px; }
        .msg-ok    { background:#e8ffef; color:#0f6d2f; border:1px solid #bdeccb; border-radius:8px; padding:10px; margin-bottom:10px; }

        label { display:block; margin:8px 0 5px; font-weight:bold; }
        select, input[type="text"] {
            width:100%; box-sizing:border-box; padding:10px;
            border:1px solid #ccd3db; border-radius:8px; font-size:15px;
        }

        .grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        .full { grid-column:1 / -1; }

        .employees-box {
            border:1px solid #e5e8ec; border-radius:10px; padding:10px;
            max-height:320px; overflow:auto; background:#fff;
        }
        .emp-item {
            display:flex; align-items:center; gap:8px;
            padding:6px 4px; border-bottom:1px dashed #eee;
        }
        .emp-item:last-child { border-bottom:0; }

        .actions { display:flex; gap:8px; flex-wrap:wrap; margin-top:12px; }
        .btn {
            border:0; border-radius:9px; padding:10px 14px; cursor:pointer;
            color:#fff; background:#1976d2; text-decoration:none;
        }
        .btn:hover { background:#145ca3; }
        .btn-gray { background:#6c757d; }

        table { width:100%; border-collapse:collapse; }
        th, td { padding:10px; border-bottom:1px solid #eee; text-align:right; }
        th { background:#fafbfc; }

        .muted { color:#666; font-size:13px; }
    </style>
</head>
<body>
<div class="container">
    <div class="card">
        <h1>إدارة ربط المشرفين بالموظفين</h1>
        <div class="actions">
            <a class="btn btn-gray" href="/admin/dashboard.php">العودة للوحة المدير</a>
            <a class="btn btn-gray" href="/admin/tasks_create.php">إضافة مهمة</a>
        </div>
    </div>

    <div class="card">
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

        <form method="get" action="" class="grid">
            <div>
                <label for="moderator_id">اختر المشرف</label>
                <select name="moderator_id" id="moderator_id" onchange="this.form.submit()">
                    <option value="0">-- اختر مشرف --</option>
                    <?php foreach ($moderators as $m): ?>
                        <?php $mid = (int)$m['id']; ?>
                        <option value="<?php echo $mid; ?>" <?php echo ($selectedModeratorId === $mid) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($m['full_name'] . ' (' . $m['username'] . ')', ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="muted" style="align-self:end;">
                اختَر المشرف أولاً، ثم حدّد موظفًا أو أكثر.
            </div>
        </form>

        <?php if ($selectedModeratorId > 0): ?>
            <form method="post" action="" style="margin-top:12px;">
                <?php echo Csrf::inputField(); ?>
                <input type="hidden" name="moderator_id" value="<?php echo $selectedModeratorId; ?>">

                <label for="emp_search">بحث عن موظف</label>
                <input type="text" id="emp_search" placeholder="اكتب اسم الموظف أو username...">

                <div style="margin:8px 0;">
                    <button type="button" class="btn btn-gray" onclick="selectAll(true)">تحديد الكل</button>
                    <button type="button" class="btn btn-gray" onclick="selectAll(false)">إلغاء الكل</button>
                </div>

                <div class="employees-box" id="employeesBox">
                    <?php foreach ($employees as $e): ?>
                        <?php
                        $uid = (int)$e['id'];
                        $checked = in_array($uid, $selectedEmployeeIds, true) ? 'checked' : '';
                        $text = $e['full_name'] . ' (' . $e['username'] . ')';
                        ?>
                        <label class="emp-item emp-row" data-text="<?php echo htmlspecialchars(mb_strtolower($text), ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="checkbox" name="user_ids[]" value="<?php echo $uid; ?>" <?php echo $checked; ?>>
                            <span><?php echo htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div class="actions">
                    <button type="submit" class="btn">حفظ التعديلات</button>
                </div>
                <div class="muted">* الحفظ هنا يعمل مزامنة كاملة لموظفي المشرف (يضيف المحدد ويحذف غير المحدد).</div>
            </form>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3 style="margin-top:0;">ملخص المشرفين</h3>
        <table>
            <thead>
                <tr>
                    <th>المشرف</th>
                    <th>عدد الموظفين المرتبطين</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($summary as $s): ?>
                <tr>
                    <td><?php echo htmlspecialchars($s['moderator_name'] . ' (' . $s['moderator_username'] . ')', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo (int)$s['employees_count']; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function selectAll(flag) {
    document.querySelectorAll('#employeesBox input[type="checkbox"]').forEach(function (el) {
        if (el.closest('.emp-row').style.display !== 'none') {
            el.checked = flag;
        }
    });
}

document.getElementById('emp_search')?.addEventListener('input', function () {
    const q = this.value.trim().toLowerCase();
    document.querySelectorAll('.emp-row').forEach(function (row) {
        const text = row.getAttribute('data-text') || '';
        row.style.display = text.includes(q) ? '' : 'none';
    });
});
</script>
</body>
</html>
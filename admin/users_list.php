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
$currentUser = $auth->user();
$currentUserId = (int)($currentUser['id'] ?? 0);

$errors = [];
$success = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Csrf::verifyOrFail();

    $action = (string)($_POST['action'] ?? '');
    $targetUserId = (int)($_POST['user_id'] ?? 0);

    if ($targetUserId <= 0) {
        $errors[] = 'معرّف المستخدم غير صالح.';
    } else {
        try {
            $st = $db->prepare("SELECT id, full_name, username, role, is_active FROM users WHERE id = :id LIMIT 1");
            $st->execute([':id' => $targetUserId]);
            $target = $st->fetch(PDO::FETCH_ASSOC);

            if (!$target) {
                $errors[] = 'المستخدم غير موجود.';
            } else {
                $isSelf = ($targetUserId === $currentUserId);

                if ($action === 'toggle_active') {
                    if ($isSelf) {
                        $errors[] = 'لا يمكنك تعطيل/تفعيل حسابك من هذه الصفحة.';
                    } else {
                        $newActive = ((int)$target['is_active'] === 1) ? 0 : 1;
                        $up = $db->prepare("UPDATE users SET is_active = :is_active WHERE id = :id");
                        $up->execute([
                            ':is_active' => $newActive,
                            ':id' => $targetUserId
                        ]);

                        $success = $newActive === 1 ? 'تم تفعيل المستخدم بنجاح.' : 'تم تعطيل المستخدم بنجاح.';
                    }
                } elseif ($action === 'delete_user') {
                    if ($isSelf) {
                        $errors[] = 'لا يمكنك حذف حسابك الحالي.';
                    } else {
                        $del = $db->prepare("DELETE FROM users WHERE id = :id");
                        $del->execute([':id' => $targetUserId]);

                        $success = 'تم حذف المستخدم بنجاح.';
                    }
                } else {
                    $errors[] = 'إجراء غير معروف.';
                }
            }
        } catch (Throwable $e) {
            $errors[] = 'حدث خطأ أثناء تنفيذ العملية: ' . $e->getMessage();
        }
    }
}

$q = trim((string)($_GET['q'] ?? ''));
$fRole = trim((string)($_GET['role'] ?? ''));
$fActive = trim((string)($_GET['is_active'] ?? ''));

// ✅ تم إضافة moderator هنا
if (!in_array($fRole, ['', 'admin', 'moderator', 'user'], true)) $fRole = '';
if (!in_array($fActive, ['', '1', '0'], true)) $fActive = '';

$page = isset($_GET['page']) && ctype_digit((string)$_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 12;
$offset = ($page - 1) * $perPage;

$where = [];
$params = [];

if ($q !== '') {
    $where[] = "(full_name LIKE :q OR username LIKE :q)";
    $params[':q'] = "%{$q}%";
}
if ($fRole !== '') {
    $where[] = "role = :role";
    $params[':role'] = $fRole;
}
if ($fActive !== '') {
    $where[] = "is_active = :is_active";
    $params[':is_active'] = (int)$fActive;
}

$whereSql = !empty($where) ? (' WHERE ' . implode(' AND ', $where)) : '';

$totalRows = 0;
$totalPages = 1;

try {
    $countSql = "SELECT COUNT(*) FROM users {$whereSql}";
    $stCount = $db->prepare($countSql);
    $stCount->execute($params);
    $totalRows = (int)$stCount->fetchColumn();
    $totalPages = max(1, (int)ceil($totalRows / $perPage));

    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $perPage;
    }
} catch (Throwable $e) {
    $errors[] = 'تعذر حساب عدد المستخدمين: ' . $e->getMessage();
}

$users = [];
try {
    $sql = "
        SELECT id, full_name, username, role, is_active, created_at
        FROM users
        {$whereSql}
        ORDER BY id DESC
        LIMIT :limit OFFSET :offset
    ";

    $st = $db->prepare($sql);

    foreach ($params as $k => $v) {
        if ($k === ':is_active') $st->bindValue($k, (int)$v, PDO::PARAM_INT);
        else $st->bindValue($k, $v);
    }
    $st->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $st->bindValue(':offset', $offset, PDO::PARAM_INT);

    $st->execute();
    $users = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $errors[] = 'تعذر تحميل المستخدمين: ' . $e->getMessage();
}

$qs = $_GET;
unset($qs['page']);
$baseQuery = http_build_query($qs);
$baseUrl = '/admin/users_list.php' . ($baseQuery !== '' ? ('?' . $baseQuery . '&') : '?');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة المستخدمين</title>
    <style>
        body { margin:0; font-family:Tahoma,Arial,sans-serif; background:#f4f6f9; }
        .container { max-width:1200px; margin:24px auto; background:#fff; border-radius:12px; padding:20px; box-shadow:0 8px 22px rgba(0,0,0,.08);}
        h2 { margin:0 0 16px; }
        .msg-error { background:#ffe8e8; color:#b30000; border:1px solid #ffcccc; border-radius:8px; padding:10px; margin-bottom:10px; }
        .msg-ok    { background:#e8ffef; color:#0f6d2f; border:1px solid #bdeccb; border-radius:8px; padding:10px; margin-bottom:10px; }
        .top-actions { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:12px; }
        .btn { border:0; border-radius:8px; padding:9px 12px; cursor:pointer; color:#fff; background:#1976d2; text-decoration:none; display:inline-block; font-size:14px; }
        .btn:hover { background:#145ca3; }
        .btn-green { background:#1b8f3a; } .btn-gray { background:#6c757d; } .btn-red { background:#c62828; } .btn-orange { background:#ef6c00; }
        .filters { display:grid; grid-template-columns: 2fr 1fr 1fr auto; gap:8px; margin-bottom:12px; }
        input[type="text"], select { width:100%; padding:9px; border:1px solid #ccd3db; border-radius:8px; box-sizing:border-box; }
        .small { font-size:12px; color:#666; margin-bottom:10px; }
        table { width:100%; border-collapse:collapse; }
        th, td { padding:10px; border-bottom:1px solid #eee; text-align:right; vertical-align:top; font-size:14px; }
        th { background:#fafbfc; }

        .badge { padding:4px 8px; border-radius:12px; color:#fff; font-size:12px; }
        .b-admin { background:#6f42c1; }
        .b-moderator { background:#fd7e14; }
        .b-user { background:#0d6efd; }
        .b-active { background:#198754; }
        .b-inactive { background:#6c757d; }

        .actions { display:flex; gap:6px; flex-wrap:wrap; } .inline { display:inline; }
        .empty { text-align:center; color:#666; padding:18px; }
        .pagination { display:flex; gap:6px; flex-wrap:wrap; margin-top:14px; }
        .page-link { padding:7px 10px; border-radius:8px; text-decoration:none; font-size:13px; border:1px solid #d7dce3; color:#333; background:#fff; }
        .page-link.active { background:#1976d2; color:#fff; border-color:#1976d2; }

        @media (max-width:1000px){
            .filters{grid-template-columns:1fr;}
            table, thead, tbody, th, td, tr{display:block;}
            th{display:none;}
            tr{border:1px solid #eee; border-radius:10px; margin-bottom:10px; padding:8px; background:#fff;}
            td{border:none; padding:6px 4px;}
            td::before{content:attr(data-label) ": "; font-weight:bold; color:#333;}
        }
    </style>
</head>
<body>
<div class="container">
    <h2>إدارة المستخدمين</h2>

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

    <div class="top-actions">
        <a class="btn btn-green" href="/admin/users_create.php">+ إضافة مستخدم</a>
        <a class="btn btn-gray" href="/admin/dashboard.php">لوحة المدير</a>
        <a class="btn btn-orange" href="/admin/audit_logs.php">سجل النشاطات</a>
    </div>

    <form method="get" action="">
        <div class="filters">
            <input type="text" name="q" placeholder="بحث بالاسم / اسم المستخدم..." value="<?php echo htmlspecialchars($q, ENT_QUOTES, 'UTF-8'); ?>">

            <select name="role">
                <option value="">كل الأدوار</option>
                <option value="admin" <?php echo $fRole === 'admin' ? 'selected' : ''; ?>>admin</option>
                <option value="moderator" <?php echo $fRole === 'moderator' ? 'selected' : ''; ?>>moderator</option>
                <option value="user" <?php echo $fRole === 'user' ? 'selected' : ''; ?>>user</option>
            </select>

            <select name="is_active">
                <option value="">كل الحالات</option>
                <option value="1" <?php echo $fActive === '1' ? 'selected' : ''; ?>>نشط</option>
                <option value="0" <?php echo $fActive === '0' ? 'selected' : ''; ?>>موقوف</option>
            </select>

            <div style="display:flex; gap:8px;">
                <button class="btn" type="submit">فلترة</button>
                <a class="btn btn-gray" href="/admin/users_list.php">مسح</a>
            </div>
        </div>
    </form>

    <div class="small">
        عدد النتائج: <?php echo (int)$totalRows; ?> |
        الصفحة: <?php echo (int)$page; ?> من <?php echo (int)$totalPages; ?>
    </div>

    <?php if (empty($users)): ?>
        <div class="empty">لا يوجد مستخدمون مطابقون.</div>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>الاسم</th>
                    <th>اسم المستخدم</th>
                    <th>الدور</th>
                    <th>الحالة</th>
                    <th>تاريخ الإنشاء</th>
                    <th>الإجراءات</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td data-label="#"><?php echo (int)$u['id']; ?></td>
                    <td data-label="الاسم"><?php echo htmlspecialchars((string)$u['full_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td data-label="اسم المستخدم"><?php echo htmlspecialchars((string)$u['username'], ENT_QUOTES, 'UTF-8'); ?></td>

                    <td data-label="الدور">
                        <?php
                        $role = (string)$u['role'];
                        if ($role === 'admin') {
                            echo '<span class="badge b-admin">admin</span>';
                        } elseif ($role === 'moderator') {
                            echo '<span class="badge b-moderator">moderator</span>';
                        } else {
                            echo '<span class="badge b-user">user</span>';
                        }
                        ?>
                    </td>

                    <td data-label="الحالة">
                        <?php echo ((int)$u['is_active'] === 1)
                            ? '<span class="badge b-active">نشط</span>'
                            : '<span class="badge b-inactive">موقوف</span>'; ?>
                    </td>

                    <td data-label="تاريخ الإنشاء"><?php echo htmlspecialchars((string)$u['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>

                    <td data-label="الإجراءات">
                        <div class="actions">
                            <a class="btn btn-orange" href="/admin/users_edit.php?id=<?php echo (int)$u['id']; ?>">تعديل</a>

                            <?php if ((int)$u['id'] !== $currentUserId): ?>
                                <form class="inline" method="post" action="">
                                    <?php echo Csrf::inputField(); ?>
                                    <input type="hidden" name="action" value="toggle_active">
                                    <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                                    <button type="submit" class="btn <?php echo ((int)$u['is_active'] === 1) ? 'btn-gray' : 'btn-green'; ?>">
                                        <?php echo ((int)$u['is_active'] === 1) ? 'تعطيل' : 'تفعيل'; ?>
                                    </button>
                                </form>

                                <form class="inline" method="post" action="" onsubmit="return confirm('هل أنت متأكد من حذف المستخدم؟');">
                                    <?php echo Csrf::inputField(); ?>
                                    <input type="hidden" name="action" value="delete_user">
                                    <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                                    <button type="submit" class="btn btn-red">حذف</button>
                                </form>
                            <?php else: ?>
                                <span style="font-size:12px;color:#666;">هذا حسابك الحالي</span>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a class="page-link" href="<?php echo $baseUrl . 'page=' . ($page - 1); ?>">السابق</a>
                <?php endif; ?>

                <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                    <a class="page-link <?php echo $i === $page ? 'active' : ''; ?>" href="<?php echo $baseUrl . 'page=' . $i; ?>">
                        <?php echo $i; ?>
                    </a>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <a class="page-link" href="<?php echo $baseUrl . 'page=' . ($page + 1); ?>">التالي</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
</body>
</html>
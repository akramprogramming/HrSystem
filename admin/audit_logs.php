<?php
declare(strict_types=1);

date_default_timezone_set('Africa/Cairo');

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

$db = Database::getConnection();

$errors = [];

/**
 * Helpers
 */
function validDate(?string $d): ?string {
    if (!$d) return null;
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return ($dt && $dt->format('Y-m-d') === $d) ? $d : null;
}

/**
 * Filters
 */
$q           = trim((string)($_GET['q'] ?? ''));
$fActor      = (int)($_GET['actor_user_id'] ?? 0);
$fAction     = trim((string)($_GET['action_type'] ?? ''));
$fEntityType = trim((string)($_GET['entity_type'] ?? ''));
$fDateFrom   = validDate($_GET['date_from'] ?? null);
$fDateTo     = validDate($_GET['date_to'] ?? null);

if ($fDateFrom && $fDateTo && $fDateFrom > $fDateTo) {
    $errors[] = 'تاريخ "من" يجب أن يكون قبل أو يساوي تاريخ "إلى".';
}

/**
 * Pagination
 */
$page = isset($_GET['page']) && ctype_digit((string)$_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

/**
 * Load users for actor filter
 */
$actors = [];
try {
    $stActors = $db->query("
        SELECT id, full_name, username
        FROM users
        ORDER BY full_name ASC
    ");
    $actors = $stActors->fetchAll();
} catch (Throwable $e) {
    $errors[] = 'تعذر تحميل المستخدمين: ' . $e->getMessage();
}

/**
 * Action types list
 */
$actionTypes = [];
try {
    $stActions = $db->query("
        SELECT DISTINCT action_type
        FROM audit_logs
        WHERE action_type IS NOT NULL AND action_type <> ''
        ORDER BY action_type ASC
    ");
    $actionTypes = $stActions->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    // تجاهل
}

/**
 * Entity types list
 */
$entityTypes = [];
try {
    $stEntities = $db->query("
        SELECT DISTINCT entity_type
        FROM audit_logs
        WHERE entity_type IS NOT NULL AND entity_type <> ''
        ORDER BY entity_type ASC
    ");
    $entityTypes = $stEntities->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    // تجاهل
}

/**
 * Build dynamic WHERE
 */
$where = [];
$params = [];

if ($q !== '') {
    $where[] = "(
        al.description LIKE :q
        OR al.action_type LIKE :q
        OR al.entity_type LIKE :q
        OR al.ip_address LIKE :q
        OR u.full_name LIKE :q
        OR u.username LIKE :q
    )";
    $params[':q'] = "%{$q}%";
}

if ($fActor > 0) {
    $where[] = "al.actor_user_id = :actor_user_id";
    $params[':actor_user_id'] = $fActor;
}

if ($fAction !== '') {
    $where[] = "al.action_type = :action_type";
    $params[':action_type'] = $fAction;
}

if ($fEntityType !== '') {
    $where[] = "al.entity_type = :entity_type";
    $params[':entity_type'] = $fEntityType;
}

if ($fDateFrom) {
    $where[] = "DATE(al.created_at) >= :date_from";
    $params[':date_from'] = $fDateFrom;
}

if ($fDateTo) {
    $where[] = "DATE(al.created_at) <= :date_to";
    $params[':date_to'] = $fDateTo;
}

$whereSql = !empty($where) ? (' WHERE ' . implode(' AND ', $where)) : '';

/**
 * Count total
 */
$totalRows = 0;
$totalPages = 1;

try {
    $countSql = "
        SELECT COUNT(*)
        FROM audit_logs al
        LEFT JOIN users u ON u.id = al.actor_user_id
        {$whereSql}
    ";
    $stCount = $db->prepare($countSql);
    $stCount->execute($params);
    $totalRows = (int)$stCount->fetchColumn();
    $totalPages = max(1, (int)ceil($totalRows / $perPage));

    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $perPage;
    }
} catch (Throwable $e) {
    $errors[] = 'تعذر حساب عدد السجلات: ' . $e->getMessage();
}

/**
 * Fetch logs
 */
$logs = [];
try {
    $sql = "
        SELECT
            al.id,
            al.actor_user_id,
            al.action_type,
            al.entity_type,
            al.entity_id,
            al.description,
            al.ip_address,
            al.user_agent,
            al.created_at,
            u.full_name AS actor_name,
            u.username AS actor_username
        FROM audit_logs al
        LEFT JOIN users u ON u.id = al.actor_user_id
        {$whereSql}
        ORDER BY al.id DESC
        LIMIT :limit OFFSET :offset
    ";

    $st = $db->prepare($sql);

    foreach ($params as $k => $v) {
        $st->bindValue($k, $v);
    }
    $st->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $st->bindValue(':offset', $offset, PDO::PARAM_INT);

    $st->execute();
    $logs = $st->fetchAll();
} catch (Throwable $e) {
    $errors[] = 'تعذر تحميل السجلات: ' . $e->getMessage();
}

/**
 * Pagination links helper
 */
$qs = $_GET;
unset($qs['page']);
$baseQuery = http_build_query($qs);
$baseUrl = '/admin/audit_logs.php' . ($baseQuery !== '' ? ('?' . $baseQuery . '&') : '?');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>سجل النشاطات</title>
    <style>
        body { margin:0; font-family:Tahoma,Arial,sans-serif; background:#f4f6f9; }
        .container { max-width:1300px; margin:24px auto; background:#fff; border-radius:12px; padding:20px; box-shadow:0 8px 22px rgba(0,0,0,.08); }
        h2 { margin:0 0 16px; }

        .msg-error { background:#ffe8e8; color:#b30000; border:1px solid #ffcccc; border-radius:8px; padding:10px; margin-bottom:10px; }

        .top-actions { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:12px; }
        .btn {
            border:0; border-radius:8px; padding:9px 12px; cursor:pointer; color:#fff;
            background:#1976d2; text-decoration:none; display:inline-block; font-size:14px;
        }
        .btn:hover { background:#145ca3; }
        .btn-gray { background:#6c757d; }

        .filters {
            display:grid;
            grid-template-columns: 2fr 1fr 1fr 1fr 1fr 1fr auto;
            gap:8px;
            margin-bottom:12px;
        }
        input[type="text"], input[type="date"], select {
            width:100%; padding:9px; border:1px solid #ccd3db; border-radius:8px; box-sizing:border-box;
        }

        .small { font-size:12px; color:#666; margin-bottom:10px; }

        table { width:100%; border-collapse:collapse; }
        th, td { padding:10px; border-bottom:1px solid #eee; text-align:right; vertical-align:top; font-size:13px; }
        th { background:#fafbfc; }

        .ua {
            max-width:320px;
            white-space:nowrap;
            overflow:hidden;
            text-overflow:ellipsis;
            display:inline-block;
            direction:ltr;
            text-align:left;
        }

        .empty { text-align:center; color:#666; padding:18px; }

        .pagination { display:flex; gap:6px; flex-wrap:wrap; margin-top:14px; }
        .page-link {
            padding:7px 10px; border-radius:8px; text-decoration:none; font-size:13px;
            border:1px solid #d7dce3; color:#333; background:#fff;
        }
        .page-link.active {
            background:#1976d2; color:#fff; border-color:#1976d2;
        }

        @media (max-width: 1100px) {
            .filters { grid-template-columns:1fr; }
            table, thead, tbody, th, td, tr { display:block; }
            th { display:none; }
            tr { border:1px solid #eee; border-radius:10px; margin-bottom:10px; padding:8px; background:#fff; }
            td { border:none; padding:6px 4px; }
            td::before { content: attr(data-label) ": "; font-weight:bold; color:#333; }
            .ua { max-width:100%; }
        }
    </style>
</head>
<body>
<div class="container">
    <h2>سجل النشاطات</h2>

    <?php if (!empty($errors)): ?>
        <div class="msg-error">
            <?php foreach ($errors as $err): ?>
                <div>• <?php echo htmlspecialchars($err, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="top-actions">
        <a class="btn btn-gray" href="/admin/dashboard.php">لوحة المدير</a>
        <a class="btn btn-gray" href="/admin/audit_logs.php">تحديث</a>
    </div>

    <form method="get" action="">
        <div class="filters">
            <input type="text" name="q" placeholder="بحث في الوصف/العملية/المستخدم/IP..."
                   value="<?php echo htmlspecialchars($q, ENT_QUOTES, 'UTF-8'); ?>">

            <select name="actor_user_id">
                <option value="0">كل المستخدمين</option>
                <?php foreach ($actors as $a): ?>
                    <option value="<?php echo (int)$a['id']; ?>" <?php echo $fActor === (int)$a['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars((string)$a['full_name'] . ' (' . (string)$a['username'] . ')', ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="action_type">
                <option value="">كل العمليات</option>
                <?php foreach ($actionTypes as $at): ?>
                    <option value="<?php echo htmlspecialchars((string)$at, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $fAction === (string)$at ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars((string)$at, ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="entity_type">
                <option value="">كل الكيانات</option>
                <?php foreach ($entityTypes as $et): ?>
                    <option value="<?php echo htmlspecialchars((string)$et, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $fEntityType === (string)$et ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars((string)$et, ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <input type="date" name="date_from" value="<?php echo htmlspecialchars((string)($fDateFrom ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
            <input type="date" name="date_to" value="<?php echo htmlspecialchars((string)($fDateTo ?? ''), ENT_QUOTES, 'UTF-8'); ?>">

            <div style="display:flex; gap:8px;">
                <button class="btn" type="submit">فلترة</button>
                <a class="btn btn-gray" href="/admin/audit_logs.php">مسح</a>
            </div>
        </div>
    </form>

    <div class="small">
        عدد السجلات: <?php echo (int)$totalRows; ?> |
        الصفحة: <?php echo (int)$page; ?> من <?php echo (int)$totalPages; ?>
    </div>

    <?php if (empty($logs)): ?>
        <div class="empty">لا توجد سجلات مطابقة.</div>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>المستخدم</th>
                    <th>العملية</th>
                    <th>الكيان</th>
                    <th>الوصف</th>
                    <th>IP</th>
                    <th>User Agent</th>
                    <th>الوقت</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($logs as $log): ?>
                <tr>
                    <td data-label="#"><?php echo (int)$log['id']; ?></td>

                    <td data-label="المستخدم">
                        <?php echo htmlspecialchars((string)($log['actor_name'] ?? 'غير معروف'), ENT_QUOTES, 'UTF-8'); ?><br>
                        <span style="font-size:12px;color:#666;">
                            <?php echo htmlspecialchars((string)($log['actor_username'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </td>

                    <td data-label="العملية">
                        <?php echo htmlspecialchars((string)$log['action_type'], ENT_QUOTES, 'UTF-8'); ?>
                    </td>

                    <td data-label="الكيان">
                        <?php
                        $entityText = (string)($log['entity_type'] ?? '');
                        $entityId = (int)($log['entity_id'] ?? 0);
                        echo htmlspecialchars($entityText . ($entityId > 0 ? (' #' . $entityId) : ''), ENT_QUOTES, 'UTF-8');
                        ?>
                    </td>

                    <td data-label="الوصف">
                        <?php echo nl2br(htmlspecialchars((string)($log['description'] ?? ''), ENT_QUOTES, 'UTF-8')); ?>
                    </td>

                    <td data-label="IP">
                        <?php echo htmlspecialchars((string)($log['ip_address'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                    </td>

                    <td data-label="User Agent">
                        <span class="ua" title="<?php echo htmlspecialchars((string)($log['user_agent'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo htmlspecialchars((string)($log['user_agent'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </td>

<td data-label="الوقت">
    <?php
    try {
        $dt = new DateTime((string)$log['created_at']); // بدون UTC
        echo htmlspecialchars($dt->format('Y-m-d H:i') . ' (بتوقيت القاهرة)', ENT_QUOTES, 'UTF-0');
    } catch (Throwable $e) {
        echo htmlspecialchars((string)$log['created_at'], ENT_QUOTES, 'UTF-0');
    }
    ?>
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

                <?php
                $start = max(1, $page - 2);
                $end = min($totalPages, $page + 2);
                for ($i = $start; $i <= $end; $i++):
                ?>
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
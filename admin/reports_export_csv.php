<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Session.php';

use Core\Database;
use Core\Session;

Session::start();

/** حماية بدون redirect */
if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Unauthorized');
}
if (($_SESSION['user']['role'] ?? '') !== 'admin') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Forbidden');
}

/** نفس اتصال النظام (المفروض شغال) */
try {
    $db = Database::getConnection();
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('DB CONNECTION ERROR: ' . $e->getMessage());
}

function validDate(?string $d): ?string {
    if (!$d) return null;
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return ($dt && $dt->format('Y-m-d') === $d) ? $d : null;
}

$type = trim((string)($_GET['type'] ?? 'employees'));
if (!in_array($type, ['employees', 'overdue', 'all_tasks'], true)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Invalid export type.');
}

/** فلاتر اختيارية (نفس reports.php) */
$filterFrom     = validDate($_GET['date_from'] ?? null);
$filterTo       = validDate($_GET['date_to'] ?? null);
$filterEmployee = isset($_GET['employee_id']) && ctype_digit((string)$_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;
$filterStatus   = (string)($_GET['status'] ?? '');
$filterPriority = (string)($_GET['priority'] ?? '');

$allowedStatus = ['pending', 'in_progress', 'done'];
$allowedPriority = ['low', 'medium', 'high'];

if ($filterStatus !== '' && !in_array($filterStatus, $allowedStatus, true)) $filterStatus = '';
if ($filterPriority !== '' && !in_array($filterPriority, $allowedPriority, true)) $filterPriority = '';

if ($filterFrom && $filterTo && $filterFrom > $filterTo) {
    http_response_code(422);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Invalid date range.');
}

while (ob_get_level() > 0) ob_end_clean();

$filename = 'report_' . $type . '_' . date('Y-m-d_H-i-s') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'w');
if (!$out) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Cannot open output.');
}
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM

$where = [];
$params = [];

if ($filterFrom)     { $where[] = "DATE(t.created_at) >= :date_from"; $params[':date_from'] = $filterFrom; }
if ($filterTo)       { $where[] = "DATE(t.created_at) <= :date_to";   $params[':date_to'] = $filterTo; }
if ($filterEmployee) { $where[] = "t.assigned_to = :employee_id";     $params[':employee_id'] = $filterEmployee; }
if ($filterStatus)   { $where[] = "t.status = :status";               $params[':status'] = $filterStatus; }
if ($filterPriority) { $where[] = "t.priority = :priority";           $params[':priority'] = $filterPriority; }

if ($type === 'employees') {
    fputcsv($out, ['ID', 'Full Name', 'Username', 'Total Tasks', 'Pending', 'In Progress', 'Done', 'Overdue']);

    $joinExtra = $where ? (' AND ' . implode(' AND ', $where)) : '';

    $sql = "
        SELECT
            u.id,
            u.full_name,
            u.username,
            COUNT(t.id) AS total_tasks,
            SUM(CASE WHEN t.status='pending' THEN 1 ELSE 0 END) AS pending_tasks,
            SUM(CASE WHEN t.status='in_progress' THEN 1 ELSE 0 END) AS in_progress_tasks,
            SUM(CASE WHEN t.status='done' THEN 1 ELSE 0 END) AS done_tasks,
            SUM(CASE WHEN t.due_date IS NOT NULL AND t.due_date < CURDATE() AND t.status <> 'done' THEN 1 ELSE 0 END) AS overdue_tasks
        FROM users u
        LEFT JOIN tasks t
               ON t.assigned_to = u.id
              {$joinExtra}
        WHERE u.role='user' AND u.is_active=1
        GROUP BY u.id, u.full_name, u.username
        ORDER BY total_tasks DESC, u.full_name ASC
    ";
    $st = $db->prepare($sql);
    $st->execute($params);

    while ($r = $st->fetch()) {
        fputcsv($out, [
            (int)$r['id'], (string)$r['full_name'], (string)$r['username'],
            (int)$r['total_tasks'], (int)$r['pending_tasks'],
            (int)$r['in_progress_tasks'], (int)$r['done_tasks'], (int)$r['overdue_tasks']
        ]);
    }

} elseif ($type === 'overdue') {
    fputcsv($out, ['Task ID', 'Title', 'Assigned Name', 'Assigned Username', 'Priority', 'Status', 'Start Date', 'Due Date', 'Created At']);

    $whereOver = $where;
    $whereOver[] = "t.due_date IS NOT NULL";
    $whereOver[] = "t.due_date < CURDATE()";
    $whereOver[] = "t.status <> 'done'";
    $whereSql = 'WHERE ' . implode(' AND ', $whereOver);

    $sql = "
        SELECT
            t.id, t.title, t.priority, t.status, t.start_date, t.due_date, t.created_at,
            u.full_name AS assigned_name, u.username AS assigned_username
        FROM tasks t
        INNER JOIN users u ON u.id = t.assigned_to
        {$whereSql}
        ORDER BY t.due_date ASC
    ";
    $st = $db->prepare($sql);
    $st->execute($params);

    while ($r = $st->fetch()) {
        fputcsv($out, [
            (int)$r['id'], (string)$r['title'], (string)$r['assigned_name'], (string)$r['assigned_username'],
            (string)$r['priority'], (string)$r['status'], (string)$r['start_date'],
            (string)($r['due_date'] ?? ''), (string)$r['created_at']
        ]);
    }

} else { // all_tasks
    fputcsv($out, ['Task ID', 'Title', 'Description', 'Assigned Name', 'Assigned Username', 'Created By', 'Priority', 'Status', 'Start Date', 'Due Date', 'Created At']);

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $sql = "
        SELECT
            t.id, t.title, t.description, t.priority, t.status, t.start_date, t.due_date, t.created_at,
            u.full_name AS assigned_name, u.username AS assigned_username,
            c.full_name AS creator_name
        FROM tasks t
        INNER JOIN users u ON u.id = t.assigned_to
        LEFT JOIN users c ON c.id = t.created_by
        {$whereSql}
        ORDER BY t.id DESC
    ";
    $st = $db->prepare($sql);
    $st->execute($params);

    while ($r = $st->fetch()) {
        fputcsv($out, [
            (int)$r['id'], (string)$r['title'], (string)($r['description'] ?? ''),
            (string)$r['assigned_name'], (string)$r['assigned_username'], (string)($r['creator_name'] ?? ''),
            (string)$r['priority'], (string)$r['status'], (string)$r['start_date'],
            (string)($r['due_date'] ?? ''), (string)$r['created_at']
        ]);
    }
}

fclose($out);
exit;
<?php
declare(strict_types=1);

// تشخيص مؤقت (احذفه بعد التأكد إن كل شيء شغال)
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/core/Database.php'; // مهم جدًا
require_once __DIR__ . '/core/Session.php';
require_once __DIR__ . '/core/Auth.php';

use Core\Auth;
use Core\Session;

Session::start();
$auth = new Auth();

if (!$auth->check()) {
    header('Location: /public/login.php');
    exit;
}

if ($auth->isAdmin()) {
    header('Location: /admin/dashboard.php');
    exit;
}

header('Location: /user/dashboard.php');
exit;
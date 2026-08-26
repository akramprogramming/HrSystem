<?php
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/core/Database.php';
require_once __DIR__ . '/core/Session.php';
require_once __DIR__ . '/core/Auth.php';

use Core\Auth;
use Core\Session;

Session::start();

$auth = new Auth();
$auth->logout();

// fallback لو logout() ما عملش redirect
header('Location: /public/login.php');
exit;
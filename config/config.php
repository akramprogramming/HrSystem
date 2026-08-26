<?php
declare(strict_types=1);

/**
 * إعدادات عامة
 */
date_default_timezone_set('Africa/Cairo');

const APP_NAME = 'Task Tracker';
const BASE_URL = ''; // لو المشروع داخل فولدر اكتب مثلاً: /tracker

/**
 * إعدادات قاعدة البيانات - InfinityFree
 */
const DB_HOST = 'sql210.infinityfree.com';
const DB_PORT = '3306';
const DB_NAME = 'if0_42468758_followupworks';
const DB_USER = 'if0_42468758';
const DB_PASS = 'Rb3nNCQKHRqe7xj';
const DB_CHARSET = 'utf8mb4';

/**
 * إنشاء اتصال PDO آمن
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    }

    return $pdo;
}
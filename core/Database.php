<?php
declare(strict_types=1);

namespace Core;

use PDO;
use PDOException;

final class Database
{
    private static ?PDO $connection = null;

    private function __construct()
    {
        // منع إنشاء كائن من الكلاس
    }

    public static function getConnection(): PDO
    {
        if (self::$connection === null) {
            // تحميل إعدادات قاعدة البيانات من config/config.php
            require_once dirname(__DIR__) . '/config/config.php';

            try {
                $dsn = "mysql:host=" . DB_HOST .
                       ";port=" . DB_PORT .
                       ";dbname=" . DB_NAME .
                       ";charset=" . DB_CHARSET;

                self::$connection = new PDO($dsn, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);
            } catch (PDOException $e) {
                // لا نعرض تفاصيل حساسة للمستخدم النهائي
                http_response_code(500);
                exit('Database connection failed.');
            }
        }

        return self::$connection;
    }
}
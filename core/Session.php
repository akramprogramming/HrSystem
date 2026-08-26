<?php
declare(strict_types=1);

namespace Core;

final class Session
{
    private const IDLE_TIMEOUT_SECONDS = 120; // 1 minute

    private function __construct() {}

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            self::enforceIdleTimeout();
            return;
        }

        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');

        session_start();

        self::enforceIdleTimeout();
    }

    private static function enforceIdleTimeout(): void
    {
        $now = time();
        $lastActivity = $_SESSION['_last_activity'] ?? null;

        if (is_int($lastActivity) && ($now - $lastActivity) > self::IDLE_TIMEOUT_SECONDS) {
            self::destroy();
            header('Location: /public/login.php?timeout=1');
            exit;
        }

        $_SESSION['_last_activity'] = $now;
    }

    public static function regenerate(bool $deleteOldSession = true): void
    {
        self::start();
        session_regenerate_id($deleteOldSession);
    }

    public static function set(string $key, mixed $value): void
    {
        self::start();
        $_SESSION[$key] = $value;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::start();
        return $_SESSION[$key] ?? $default;
    }

    public static function has(string $key): bool
    {
        self::start();
        return array_key_exists($key, $_SESSION);
    }

    public static function remove(string $key): void
    {
        self::start();
        unset($_SESSION[$key]);
    }

    public static function destroy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                (bool)$params['secure'],
                (bool)$params['httponly']
            );
        }

        session_destroy();
    }
}
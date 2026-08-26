<?php
declare(strict_types=1);

namespace Core;

final class Csrf
{
    private const SESSION_KEY = '_csrf_token';

    private function __construct()
    {
        // منع إنشاء كائن من الكلاس
    }

    public static function token(): string
    {
        Session::start();

        $token = Session::get(self::SESSION_KEY);
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            Session::set(self::SESSION_KEY, $token);
        }

        return $token;
    }

    public static function inputField(): string
    {
        $token = htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8');
        return '<input type="hidden" name="_csrf" value="' . $token . '">';
    }

    public static function verifyOrFail(): void
    {
        Session::start();

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return;
        }

        $requestToken = $_POST['_csrf'] ?? '';
        $sessionToken = Session::get(self::SESSION_KEY, '');

        if (!is_string($requestToken) || !is_string($sessionToken) || $requestToken === '' || $sessionToken === '') {
            http_response_code(419);
            exit('CSRF token missing.');
        }

        if (!hash_equals($sessionToken, $requestToken)) {
            http_response_code(419);
            exit('CSRF token mismatch.');
        }
    }
}
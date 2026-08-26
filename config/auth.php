<?php
declare(strict_types=1);

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_logged_in(): bool
{
    return !empty($_SESSION['user']['id']);
}

function require_login(): void
{
    if (!is_logged_in()) {
        redirect('/login.php');
    }
}

function require_admin(): void
{
    require_login();

    if (($_SESSION['user']['role'] ?? '') !== 'admin') {
        http_response_code(403);
        exit('Forbidden: Admin access only.');
    }
}

function require_user_role(): void
{
    require_login();

    if (($_SESSION['user']['role'] ?? '') !== 'user') {
        http_response_code(403);
        exit('Forbidden: User access only.');
    }
}
<?php
declare(strict_types=1);

namespace Core;

use Core\Database;
use Core\Session;
use PDO;

final class Auth
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
        Session::start();
    }

    public function attemptLogin(string $username, string $password): bool
    {
        $sql = "SELECT id, full_name, username, password_hash, role, is_active
                FROM users
                WHERE username = :username
                LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':username' => $username]);
        $user = $stmt->fetch();

        if (!$user || (int)$user['is_active'] !== 1) {
            return false;
        }

        if (!password_verify($password, (string)$user['password_hash'])) {
            return false;
        }

        Session::regenerate(true);

        Session::set('user', [
            'id'        => (int)$user['id'],
            'full_name' => (string)$user['full_name'],
            'username'  => (string)$user['username'],
            'role'      => (string)$user['role'], // admin | moderator | user
        ]);

        return true;
    }

    public function check(): bool
    {
        $user = Session::get('user');
        return is_array($user) && !empty($user['id']);
    }

    public function user(): ?array
    {
        $user = Session::get('user');
        return is_array($user) ? $user : null;
    }

    public function isAdmin(): bool
    {
        return (($this->user()['role'] ?? null) === 'admin');
    }

    public function isModerator(): bool
    {
        return (($this->user()['role'] ?? null) === 'moderator');
    }

    public function isUser(): bool
    {
        return (($this->user()['role'] ?? null) === 'user');
    }

    public function requireLogin(): void
    {
        if (!$this->check()) {
            header('Location: /public/login.php');
            exit;
        }
    }

    public function requireAdmin(): void
    {
        $this->requireLogin();

        if (!$this->isAdmin()) {
            http_response_code(403);
            exit('Forbidden: Admin access only.');
        }
    }

    // جديد: Admin أو Moderator
    public function requireAdminOrModerator(): void
    {
        $this->requireLogin();

        if (!$this->isAdmin() && !$this->isModerator()) {
            http_response_code(403);
            exit('Forbidden: Admin or Moderator access only.');
        }
    }

    public function requireUserRole(): void
    {
        $this->requireLogin();

        if (!$this->isUser()) {
            http_response_code(403);
            exit('Forbidden: User access only.');
        }
    }

    // جديد: التحقق إن المشرف له صلاحية على موظف معيّن
    public function canAccessEmployee(int $employeeUserId): bool
    {
        $me = $this->user();
        if (!$me) {
            return false;
        }

        if ($this->isAdmin()) {
            return true;
        }

        if (!$this->isModerator()) {
            return false;
        }

        $moderatorId = (int)($me['id'] ?? 0);
        if ($moderatorId <= 0 || $employeeUserId <= 0) {
            return false;
        }

        $sql = "SELECT 1
                FROM moderator_users
                WHERE moderator_id = :moderator_id
                  AND user_id = :user_id
                LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':moderator_id' => $moderatorId,
            ':user_id'      => $employeeUserId,
        ]);

        return (bool)$stmt->fetchColumn();
    }

    public function logout(): void
    {
        Session::destroy();
        header('Location: /public/login.php');
        exit;
    }
}
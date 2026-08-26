<?php
declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): never
{
    header('Location: ' . BASE_URL . $path);
    exit;
}

function is_post(): bool
{
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

function audit_log(
    int $actorUserId,
    string $actionType,
    string $entityType,
    ?int $entityId = null,
    ?string $description = null
): void {
    $sql = "INSERT INTO audit_logs
            (actor_user_id, action_type, entity_type, entity_id, description, ip_address, user_agent)
            VALUES
            (:actor_user_id, :action_type, :entity_type, :entity_id, :description, :ip_address, :user_agent)";

    $stmt = db()->prepare($sql);
    $stmt->execute([
        ':actor_user_id' => $actorUserId,
        ':action_type'   => $actionType,
        ':entity_type'   => $entityType,
        ':entity_id'     => $entityId,
        ':description'   => $description,
        ':ip_address'    => $_SERVER['REMOTE_ADDR'] ?? null,
        ':user_agent'    => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
    ]);
}
<?php

declare(strict_types=1);

/**
 * Authentication helpers. Loaded by includes/bootstrap.php, which has already
 * started the session, loaded the config and created $db.
 */

function login_admin(int $id): void
{
    session_regenerate_id(true);
    $_SESSION['admin_id'] = $id;

    $db = $GLOBALS['db'] ?? null;
    if ($db instanceof PDO) {
        $stmt = $db->prepare('SELECT email FROM admins WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $email = $stmt->fetchColumn();
        if (is_string($email) && $email !== '') {
            $_SESSION['admin_email'] = $email;
        }
    }
}

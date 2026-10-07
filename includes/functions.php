<?php

declare(strict_types=1);

function e(mixed $value): string
{
    if ($value === null) {
        return '';
    }
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function storage_path(string $path = ''): string
{
    $base = __DIR__ . '/../storage';
    if ($path === '') {
        return $base;
    }
    return $base . '/' . ltrim($path, '/');
}

function app_url(array $config, string $path = ''): string
{
    $base = $config['app_url'] ?? 'http://localhost';
    if ($path === '') {
        return $base;
    }
    return rtrim($base, '/') . '/' . ltrim($path, '/');
}

function verify_csrf(): void
{
    $token = $_POST['_csrf'] ?? '';
    if ($token === '' || !hash_equals($_SESSION['_csrf'] ?? '', $token)) {
        http_response_code(403);
        exit('CSRF token mismatch.');
    }
}

function csrf_field(): string
{
    if (!isset($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return '<input type="hidden" name="_csrf" value="' . e($_SESSION['_csrf']) . '">';
}

function setting(PDO $db, string $key, string $default = ''): string
{
    try {
        $stmt = $db->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return $row ? (string)$row['setting_value'] : $default;
    } catch (Exception $e) {
        return $default;
    }
}

function update_setting(PDO $db, string $key, string $value): void
{
    try {
        $stmt = $db->prepare('INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?');
        $stmt->execute([$key, $value, $value]);
    } catch (Exception $e) {
        // Fail silently
    }
}

function ensure_storage(): void
{
    $dirs = ['storage', 'storage/certificates', 'storage/qrcodes', 'storage/signatures', 'storage/logs'];
    foreach ($dirs as $dir) {
        $path = __DIR__ . '/../' . $dir;
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }
}

function admin_logged_in(): bool
{
    return isset($_SESSION['admin_id']) && is_int($_SESSION['admin_id']) && $_SESSION['admin_id'] > 0;
}

function require_admin(): void
{
    if (!admin_logged_in()) {
        redirect(app_url($GLOBALS['config'], 'admin/login.php'));
    }
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function random_token(int $length = 64): string
{
    $bytes = random_bytes($length / 2);
    return bin2hex($bytes);
}

function next_certificate_number(PDO $db, string $prefix): string
{
    $year = date('Y');
    $stmt = $db->prepare(
        'SELECT certificate_number FROM certificates WHERE certificate_number LIKE ? ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$prefix . '-' . $year . '-%']);
    $last = $stmt->fetchColumn();

    if ($last) {
        $parts = explode('-', $last);
        $number = (int)end($parts) + 1;
    } else {
        $number = 1;
    }

    return sprintf('%s-%s-%06d', $prefix, $year, $number);
}

function get_certificate_by_number(PDO $db, string $number): ?array
{
    $stmt = $db->prepare('SELECT * FROM certificates WHERE certificate_number = ? LIMIT 1');
    $stmt->execute([$number]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function get_certificate(PDO $db, string $token): ?array
{
    $stmt = $db->prepare('SELECT * FROM certificates WHERE verification_token = ? LIMIT 1');
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function certificate_status(array $certificate): string
{
    if ($certificate['status'] === 'revoked') {
        return 'revoked';
    }
    if ($certificate['expiry_date'] && strtotime($certificate['expiry_date']) < time()) {
        return 'expired';
    }
    return 'valid';
}

function status_badge(string $status): string
{
    $colors = ['valid' => 'success', 'expired' => 'warning', 'revoked' => 'danger'];
    $color = $colors[$status] ?? 'secondary';
    return '<span class="badge text-bg-' . e($color) . '">' . e(strtoupper($status)) . '</span>';
}

function log_verification(PDO $db, ?array $certificate, string $result): void
{
    try {
        $stmt = $db->prepare(
            'INSERT INTO verification_logs (certificate_id, certificate_number, result, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $certificate['id'] ?? null,
            $certificate['certificate_number'] ?? null,
            $result,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    } catch (Exception $e) {
        // Fail silently
    }
}

<?php

// Pengaturan sesi harus dilakukan sebelum session_start().
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/inventory-app/',
        'secure' => !empty($_SERVER['HTTPS'])
            && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

function isLoggedIn(): bool
{
    return isset($_SESSION['user']['id'])
        && filter_var(
            $_SESSION['user']['id'],
            FILTER_VALIDATE_INT
        ) !== false
        && (int) $_SESSION['user']['id'] > 0;
}

function requireLogin(): void
{
    if (!isLoggedIn()) {
        header('Location: /inventory-app/public/login.php');
        exit;
    }
}

function currentUser(): ?array
{
    if (!isLoggedIn()) {
        return null;
    }

    return $_SESSION['user'];
}

function requireRole(array $allowedRoles): void
{
    requireLogin();

    $role = $_SESSION['user']['role'] ?? '';

    if (!is_string($role)
        || !in_array($role, $allowedRoles, true)
    ) {
        http_response_code(403);
        exit('403 Forbidden - Anda tidak memiliki hak akses.');
    }
}

function requireAdmin(): void
{
    requireRole(['admin']);
}

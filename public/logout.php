<?php

require_once __DIR__ . '/../helpers/auth.php';

// Hapus seluruh data sesi
$_SESSION = [];

// Hapus cookie sesi
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// Hancurkan sesi
session_destroy();

// Kembali ke halaman login
header('Location: /inventory-app/public/login.php');
exit;
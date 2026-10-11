<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/escape.php';

if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$username = '';

if (empty($_SESSION['login_csrf_token'])) {
    $_SESSION['login_csrf_token'] = bin2hex(random_bytes(32));
}

$now = time();
$lockDuration = 15 * 60;
$maxAttempts = 5;

if (!isset($_SESSION['login_attempts'])
    || !is_array($_SESSION['login_attempts'])
) {
    $_SESSION['login_attempts'] = [
        'count' => 0,
        'started_at' => $now
    ];
}

$attempts = &$_SESSION['login_attempts'];

if (
    !isset($attempts['started_at'], $attempts['count'])
    || $now - (int) $attempts['started_at'] >= $lockDuration
    || $now < (int) $attempts['started_at']
) {
    $attempts = [
        'count' => 0,
        'started_at' => $now
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usernameInput = $_POST['username'] ?? '';
    $passwordInput = $_POST['password'] ?? '';
    $csrfInput = $_POST['csrf_token'] ?? '';

    if (!is_string($usernameInput)
        || !is_string($passwordInput)
        || !is_string($csrfInput)
    ) {
        $error = 'Input tidak valid.';
    } elseif (
        !hash_equals(
            $_SESSION['login_csrf_token'],
            $csrfInput
        )
    ) {
        $error = 'Form sudah kedaluwarsa. Silakan muat ulang halaman.';
        $_SESSION['login_csrf_token'] = bin2hex(random_bytes(32));
    } elseif ((int) $attempts['count'] >= $maxAttempts) {
        $error = 'Terlalu banyak percobaan login. Silakan coba lagi setelah 15 menit.';
    } else {
        $username = trim($usernameInput);
        $password = $passwordInput;

        if (
            $username === ''
            || $password === ''
            || strlen($username) > 50
            || strlen($password) > 4096
        ) {
            $error = 'Username atau password tidak valid.';
            $attempts['count']++;
        } else {
            try {
                $stmt = $pdo->prepare(
                    'SELECT id, username, password_hash, full_name, role
                     FROM users
                     WHERE username = :username
                     LIMIT 1'
                );

                $stmt->execute(['username' => $username]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if (
                    $user
                    && password_verify(
                        $password,
                        $user['password_hash']
                    )
                ) {
                    session_regenerate_id(true);

                    $_SESSION['user'] = [
                        'id' => (int) $user['id'],
                        'username' => $user['username'],
                        'full_name' => $user['full_name'],
                        'role' => $user['role']
                    ];

                    unset(
                        $_SESSION['login_attempts'],
                        $_SESSION['login_csrf_token']
                    );

                    header('Location: dashboard.php');
                    exit;
                }

                $attempts['count']++;
                $error = 'Username atau password salah.';
            } catch (PDOException $e) {
                error_log('Login database error: ' . $e->getMessage());
                $error = 'Login gagal diproses. Silakan coba lagi nanti.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | Inventory Management</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
            font-family: Arial, sans-serif;
            background: #eef2f0;
            color: #26332d;
        }

        .login-card {
            width: 100%;
            max-width: 420px;
            padding: 35px;
            border-radius: 18px;
            background: white;
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.08);
        }

        .logo {
            width: 55px;
            height: 55px;
            display: grid;
            place-items: center;
            border-radius: 14px;
            background: #176b50;
            color: white;
            font-size: 25px;
            margin-bottom: 22px;
        }

        h1 {
            margin: 0 0 10px;
            font-size: 27px;
        }

        .subtitle {
            color: #718078;
            line-height: 1.6;
            margin-bottom: 28px;
        }

        label {
            display: block;
            margin: 16px 0 8px;
            font-weight: bold;
            font-size: 14px;
        }

        input {
            width: 100%;
            padding: 13px;
            border: 1px solid #d5ded9;
            border-radius: 9px;
            font-size: 15px;
        }

        input:focus {
            outline: 2px solid #9bd5bd;
            border-color: #176b50;
        }

        button {
            width: 100%;
            margin-top: 24px;
            padding: 14px;
            border: none;
            border-radius: 9px;
            background: #176b50;
            color: white;
            font-weight: bold;
            font-size: 15px;
            cursor: pointer;
        }

        button:hover {
            background: #10553f;
        }

        .error {
            padding: 12px;
            background: #fff0ef;
            color: #a52c24;
            border-radius: 8px;
            font-size: 14px;
        }

        .footer {
            margin-top: 25px;
            text-align: center;
            color: #87928c;
            font-size: 12px;
        }
    </style>
</head>

<body>
    <main class="login-card">
        <div class="logo">&#9638;</div>

        <h1>Selamat Datang</h1>
        <p class="subtitle">
            Login untuk mengakses Inventory Management System.
        </p>

        <?php if ($error !== ''): ?>
            <div class="error" role="alert">
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= e($_SESSION['login_csrf_token']) ?>"
            >

            <label for="username">Username</label>
            <input
                type="text"
                id="username"
                name="username"
                value="<?= e($username) ?>"
                maxlength="50"
                autocomplete="username"
                required
            >

            <label for="password">Password</label>
            <input
                type="password"
                id="password"
                name="password"
                maxlength="4096"
                autocomplete="current-password"
                required
            >

            <button type="submit">Login</button>
        </form>

        <div class="footer">
            Inventory Management System &copy; 2026
        </div>
    </main>
</body>
</html>
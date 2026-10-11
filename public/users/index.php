<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/escape.php';

requireRole(['admin']);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];
$errors = [];
$successMessages = [
    'created' => 'Akun pengguna berhasil dibuat.',
    'updated' => 'Data pengguna berhasil diperbarui.',
    'deleted' => 'Akun pengguna berhasil dihapus.'
];

$username = '';
$fullName = '';
$role = 'staff';
$editUser = null;

/*
 * Sesuaikan jika auth.php menggunakan nama session ID yang berbeda.
 * Umumnya aplikasi menggunakan $_SESSION['user']['id'] atau $_SESSION['user_id'].
 */
$currentUserId = $_SESSION['user']['id']
    ?? $_SESSION['user_id']
    ?? $_SESSION['id']
    ?? null;

$currentUserId = is_numeric($currentUserId) ? (int) $currentUserId : null;

/* Proses form */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? '';

    if (!is_string($postedToken) || !hash_equals($csrfToken, $postedToken)) {
        http_response_code(403);
        exit('Permintaan tidak valid. Muat ulang halaman.');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);

        if (!$id || $id < 1) {
            $errors[] = 'ID pengguna tidak valid.';
        } elseif ($currentUserId !== null && $id === $currentUserId) {
            $errors[] = 'Kamu tidak bisa menghapus akun yang sedang digunakan.';
        } else {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare(
                    "SELECT id, role FROM users WHERE id = ? FOR UPDATE"
                );
                $stmt->execute([$id]);
                $targetUser = $stmt->fetch();

                if (!$targetUser) {
                    $pdo->rollBack();
                    $errors[] = 'Pengguna tidak ditemukan.';
                } else {
                    if ($targetUser['role'] === 'admin') {
                        $countStmt = $pdo->query(
                            "SELECT COUNT(*) FROM users WHERE role = 'admin'"
                        );

                        if ((int) $countStmt->fetchColumn() <= 1) {
                            $pdo->rollBack();
                            $errors[] = 'Admin terakhir tidak boleh dihapus.';
                        } else {
                            $deleteStmt = $pdo->prepare(
                                "DELETE FROM users WHERE id = ?"
                            );
                            $deleteStmt->execute([$id]);
                            $pdo->commit();

                            header('Location: index.php?success=deleted');
                            exit;
                        }
                    } else {
                        $deleteStmt = $pdo->prepare(
                            "DELETE FROM users WHERE id = ?"
                        );
                        $deleteStmt->execute([$id]);
                        $pdo->commit();

                        header('Location: index.php?success=deleted');
                        exit;
                    }
                }
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log($e->getMessage());
                $errors[] = 'Gagal menghapus akun pengguna.';
            }
        }
    } elseif ($action === 'save') {
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
        $isEdit = $id !== false && $id !== null && $id > 0;

        $username = trim($_POST['username'] ?? '');
        $fullName = trim($_POST['full_name'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'staff';

        if ($username === '' || strlen($username) > 50) {
            $errors[] = 'Username wajib diisi dan maksimal 50 karakter.';
        } elseif (!preg_match('/^[a-zA-Z0-9_.-]+$/', $username)) {
            $errors[] = 'Username hanya boleh berisi huruf, angka, titik, garis bawah, dan tanda hubung.';
        }

        if ($fullName === '' || strlen($fullName) > 100) {
            $errors[] = 'Nama lengkap wajib diisi dan maksimal 100 karakter.';
        }

        if (!is_string($password)) {
            $errors[] = 'Password tidak valid.';
        } elseif ($password !== '' && (strlen($password) < 8 || strlen($password) > 72)) {
            $errors[] = 'Password baru harus terdiri dari 8 sampai 72 karakter.';
        } elseif (!$isEdit && $password === '') {
            $errors[] = 'Password wajib diisi untuk akun baru.';
        }

        if (!in_array($role, ['admin', 'staff'], true)) {
            $errors[] = 'Peran pengguna tidak valid.';
        }

        if ($isEdit && !$errors) {
            $check = $pdo->prepare(
                "SELECT id, role FROM users WHERE id = ?"
            );
            $check->execute([$id]);
            $editUser = $check->fetch();

            if (!$editUser) {
                $errors[] = 'Pengguna yang ingin diedit tidak ditemukan.';
            } elseif (
                $editUser['role'] === 'admin'
                && $role !== 'admin'
                && (int) $pdo->query(
                    "SELECT COUNT(*) FROM users WHERE role = 'admin'"
                )->fetchColumn() <= 1
            ) {
                $errors[] = 'Role admin terakhir tidak boleh diubah menjadi staff.';
            }
        }

        if (!$errors) {
            $check = $pdo->prepare(
                "SELECT id FROM users WHERE username = ? AND id <> ?"
            );
            $check->execute([$username, $isEdit ? $id : 0]);

            if ($check->fetch()) {
                $errors[] = 'Username sudah digunakan. Pilih username lain.';
            }
        }

        if (!$errors) {
            try {
                if ($isEdit) {
                    if ($password !== '') {
                        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                        $stmt = $pdo->prepare(
                            "UPDATE users
                             SET username = ?, full_name = ?, role = ?, password_hash = ?
                             WHERE id = ?"
                        );

                        $stmt->execute([
                            $username,
                            $fullName,
                            $role,
                            $passwordHash,
                            $id
                        ]);
                    } else {
                        $stmt = $pdo->prepare(
                            "UPDATE users
                             SET username = ?, full_name = ?, role = ?
                             WHERE id = ?"
                        );

                        $stmt->execute([
                            $username,
                            $fullName,
                            $role,
                            $id
                        ]);
                    }

                    header('Location: index.php?success=updated');
                    exit;
                } else {
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                    $stmt = $pdo->prepare(
                        "INSERT INTO users (username, password_hash, full_name, role)
                         VALUES (?, ?, ?, ?)"
                    );

                    $stmt->execute([
                        $username,
                        $passwordHash,
                        $fullName,
                        $role
                    ]);

                    header('Location: index.php?success=created');
                    exit;
                }
            } catch (PDOException $e) {
                error_log($e->getMessage());
                $errors[] = 'Gagal menyimpan akun. Periksa kembali data yang dimasukkan.';
            }
        }

        if ($isEdit) {
            $editUser = ['id' => $id];
        }
    } else {
        $errors[] = 'Aksi tidak dikenal.';
    }
}

/* Muat data pengguna yang akan diedit */
$editId = filter_var($_GET['edit'] ?? null, FILTER_VALIDATE_INT);

if (!$editUser && $editId && $editId > 0) {
    $stmt = $pdo->prepare(
        "SELECT id, username, full_name, role
         FROM users WHERE id = ?"
    );
    $stmt->execute([$editId]);
    $editUser = $stmt->fetch();

    if (!$editUser) {
        $errors[] = 'Pengguna tidak ditemukan.';
    }
}

if ($editUser && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $username = $editUser['username'];
    $fullName = $editUser['full_name'];
    $role = $editUser['role'];
}

/* Ambil daftar pengguna */
$stmt = $pdo->query(
    "SELECT id, username, full_name, role, created_at
     FROM users
     ORDER BY id DESC"
);
$users = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manajemen User</title>
    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 25px;
            font-family: Arial, sans-serif;
            background: #f3f6fa;
            color: #263238;
        }

        .container { max-width: 1150px; margin: auto; }

        .card {
            background: white;
            padding: 24px;
            margin-bottom: 22px;
            border-radius: 12px;
            box-shadow: 0 3px 12px rgba(0,0,0,.06);
        }

        h1, h2 { margin-top: 0; }

        label {
            display: block;
            margin-top: 14px;
            font-weight: bold;
        }

        input, select {
            width: 100%;
            padding: 11px;
            margin-top: 7px;
            border: 1px solid #ccd5df;
            border-radius: 6px;
            font: inherit;
        }

        button, .btn {
            display: inline-block;
            padding: 10px 14px;
            margin-top: 12px;
            border: none;
            border-radius: 6px;
            background: #218838;
            color: white;
            text-decoration: none;
            cursor: pointer;
            font-size: 14px;
        }

        .back { margin-top: 0; margin-bottom: 18px; background: #526477; }
        .edit { background: #2878c7; }
        .delete { background: #c0392b; }
        .cancel { background: #6c757d; }

        .error, .success {
            padding: 12px;
            margin-bottom: 10px;
            border-radius: 6px;
        }

        .error { background: #ffe8e8; color: #a21d1d; }
        .success { background: #e2f7e7; color: #17652b; }

        .table-wrap { overflow-x: auto; }

        table {
            width: 100%;
            min-width: 800px;
            border-collapse: collapse;
        }

        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e3e8ee;
        }

        th { background: #eaf2ec; }

        .role-admin { color: #17652b; font-weight: bold; }
        .role-staff { color: #315b9a; font-weight: bold; }

        .actions { display: flex; gap: 7px; align-items: center; }
        .actions form { margin: 0; }
        .actions button, .actions .btn { margin-top: 0; }

        @media (max-width: 600px) {
            body { padding: 12px; }
            .card { padding: 16px; }
        }
    </style>
</head>
<body>
<div class="container">
    <a class="btn back" href="../dashboard.php">← Kembali ke Dashboard</a>

    <h1>Manajemen User</h1>

    <div class="card">
        <h2><?= $editUser ? 'Edit Akun Pengguna' : 'Tambah Akun Pengguna' ?></h2>

        <?php foreach ($errors as $error): ?>
            <div class="error"><?= e($error) ?></div>
        <?php endforeach; ?>

        <?php
        $success = $_GET['success'] ?? '';
        if (isset($successMessages[$success])):
        ?>
            <div class="success"><?= e($successMessages[$success]) ?></div>
        <?php endif; ?>

        <form method="POST" action="index.php">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" value="save">

            <?php if ($editUser): ?>
                <input type="hidden" name="id" value="<?= (int) $editUser['id'] ?>">
            <?php endif; ?>

            <label for="username">Username</label>
            <input type="text" id="username" name="username" maxlength="50"
                   value="<?= e($username) ?>" required>

            <label for="full_name">Nama Lengkap</label>
            <input type="text" id="full_name" name="full_name" maxlength="100"
                   value="<?= e($fullName) ?>" required>

            <label for="password">
                Password <?= $editUser ? '(kosongkan jika tidak ingin mengganti)' : '' ?>
            </label>
            <input type="password" id="password" name="password"
                   minlength="8" maxlength="72"
                   <?= $editUser ? '' : 'required' ?>
                   autocomplete="new-password">

            <label for="role">Peran Pengguna</label>
            <select id="role" name="role" required>
                <option value="staff" <?= $role === 'staff' ? 'selected' : '' ?>>Staff</option>
                <option value="admin" <?= $role === 'admin' ? 'selected' : '' ?>>Admin</option>
            </select>

            <button type="submit">
                <?= $editUser ? 'Simpan Perubahan' : 'Simpan Akun' ?>
            </button>

            <?php if ($editUser): ?>
                <a class="btn cancel" href="index.php">Batal Edit</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="card">
        <h2>Daftar Pengguna (<?= count($users) ?>)</h2>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>No.</th>
                        <th>Username</th>
                        <th>Nama Lengkap</th>
                        <th>Peran</th>
                        <th>Tanggal Dibuat</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$users): ?>
                    <tr><td colspan="6">Belum ada pengguna.</td></tr>
                <?php else: ?>
                    <?php foreach ($users as $i => $user): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><?= e($user['username']) ?></td>
                            <td><?= e($user['full_name']) ?></td>
                            <td class="<?= $user['role'] === 'admin' ? 'role-admin' : 'role-staff' ?>">
                                <?= e(ucfirst($user['role'])) ?>
                            </td>
                            <td><?= e($user['created_at']) ?></td>
                            <td>
                                <div class="actions">
                                    <a class="btn edit"
                                       href="index.php?edit=<?= (int) $user['id'] ?>">
                                        Edit
                                    </a>

                                    <?php if ($currentUserId === null || (int) $user['id'] !== $currentUserId): ?>
                                        <form method="POST"
                                              onsubmit="return confirm('Yakin ingin menghapus akun ini?');">
                                            <input type="hidden" name="csrf_token"
                                                   value="<?= e($csrfToken) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id"
                                                   value="<?= (int) $user['id'] ?>">
                                            <button class="delete" type="submit">Hapus</button>
                                        </form>
                                    <?php else: ?>
                                        <span>Akun kamu</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</body>
</html>
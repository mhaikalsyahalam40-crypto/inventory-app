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

$name = '';
$phone = '';
$email = '';
$address = '';
$editSupplier = null;

/* Proses tambah, edit, dan hapus */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? '';

    if (!is_string($postedToken) || !hash_equals($csrfToken, $postedToken)) {
        $errors[] = 'Permintaan tidak valid. Silakan muat ulang halaman.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'delete') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);

            if (!$id || $id < 1) {
                $errors[] = 'ID supplier tidak valid.';
            } else {
                try {
                    $check = $pdo->prepare(
                        "SELECT COUNT(*) FROM product_suppliers WHERE supplier_id = ?"
                    );
                    $check->execute([$id]);

                    if ((int) $check->fetchColumn() > 0) {
                        $errors[] = 'Supplier tidak bisa dihapus karena masih terhubung dengan produk.';
                    } else {
                        $stmt = $pdo->prepare("DELETE FROM suppliers WHERE id = ?");
                        $stmt->execute([$id]);

                        if ($stmt->rowCount() > 0) {
                            header('Location: index.php?success=deleted');
                            exit;
                        }

                        $errors[] = 'Supplier tidak ditemukan.';
                    }
                } catch (PDOException $e) {
                    error_log($e->getMessage());
                    $errors[] = 'Gagal menghapus supplier.';
                }
            }
        } elseif ($action === 'save') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
            $isEdit = $id !== false && $id !== null && $id > 0;

            $name = trim($_POST['name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $address = trim($_POST['address'] ?? '');

            if ($name === '') {
                $errors[] = 'Nama supplier wajib diisi.';
            } elseif (strlen($name) > 150) {
                $errors[] = 'Nama supplier maksimal 150 karakter.';
            }

            if (strlen($phone) > 30) {
                $errors[] = 'Nomor telepon maksimal 30 karakter.';
            }

            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Format email tidak valid.';
            } elseif (strlen($email) > 150) {
                $errors[] = 'Email maksimal 150 karakter.';
            }

            if (strlen($address) > 1000) {
                $errors[] = 'Alamat maksimal 1000 karakter.';
            }

            if ($isEdit && !$errors) {
                $check = $pdo->prepare("SELECT id FROM suppliers WHERE id = ?");
                $check->execute([$id]);

                if (!$check->fetch()) {
                    $errors[] = 'Supplier yang ingin diedit tidak ditemukan.';
                }
            }

            if (!$errors) {
                try {
                    if ($isEdit) {
                        $stmt = $pdo->prepare(
                            "UPDATE suppliers
                             SET name = ?, phone = ?, email = ?, address = ?
                             WHERE id = ?"
                        );

                        $stmt->execute([
                            $name,
                            $phone !== '' ? $phone : null,
                            $email !== '' ? $email : null,
                            $address !== '' ? $address : null,
                            $id
                        ]);

                        header('Location: index.php?success=updated');
                        exit;
                    } else {
                        $stmt = $pdo->prepare(
                            "INSERT INTO suppliers (name, phone, email, address)
                             VALUES (?, ?, ?, ?)"
                        );

                        $stmt->execute([
                            $name,
                            $phone !== '' ? $phone : null,
                            $email !== '' ? $email : null,
                            $address !== '' ? $address : null
                        ]);

                        header('Location: index.php?success=created');
                        exit;
                    }
                } catch (PDOException $e) {
                    error_log($e->getMessage());

                    if ($e->getCode() === '23000') {
                        $errors[] = 'Data supplier bertentangan dengan aturan database.';
                    } else {
                        $errors[] = 'Terjadi kesalahan saat menyimpan supplier.';
                    }
                }
            }

            if ($isEdit) {
                $editSupplier = ['id' => $id];
            }
        } else {
            $errors[] = 'Aksi tidak dikenal.';
        }
    }
}

/* Ambil data untuk diedit */
$editId = filter_var($_GET['edit'] ?? null, FILTER_VALIDATE_INT);

if (!$editSupplier && $editId && $editId > 0) {
    $stmt = $pdo->prepare(
        "SELECT id, name, phone, email, address
         FROM suppliers
         WHERE id = ?"
    );
    $stmt->execute([$editId]);
    $editSupplier = $stmt->fetch();

    if (!$editSupplier) {
        $errors[] = 'Supplier yang ingin diedit tidak ditemukan.';
    }
}

if ($editSupplier && !$errors) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $name = $editSupplier['name'];
        $phone = $editSupplier['phone'] ?? '';
        $email = $editSupplier['email'] ?? '';
        $address = $editSupplier['address'] ?? '';
    }
}

$stmt = $pdo->query(
    "SELECT id, name, phone, email, address, created_at
     FROM suppliers
     ORDER BY id DESC"
);
$suppliers = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manajemen Supplier</title>
    <style>
        * { box-sizing: border-box; }

        body {
            font-family: Arial, sans-serif;
            background: #f3f6fa;
            margin: 0;
            padding: 25px;
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
            font-weight: bold;
            margin-top: 8px;
        }

        input, textarea {
            width: 100%;
            padding: 11px;
            margin: 7px 0 15px;
            border: 1px solid #ccd5df;
            border-radius: 6px;
            font: inherit;
        }

        input:focus, textarea:focus {
            outline: 2px solid #b8dec4;
            border-color: #218838;
        }

        button, .btn {
            display: inline-block;
            border: none;
            border-radius: 6px;
            padding: 10px 14px;
            color: white;
            background: #218838;
            text-decoration: none;
            cursor: pointer;
            font-size: 14px;
        }

        .back { background: #526477; margin-bottom: 18px; }
        .edit { background: #2878c7; }
        .delete { background: #c0392b; }
        .cancel { background: #6c757d; }

        .error, .success {
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 12px;
        }

        .error { background: #ffe8e8; color: #a21d1d; }
        .success { background: #e2f7e7; color: #17652b; }

        .table-wrap { overflow-x: auto; }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 750px;
        }

        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e3e8ee;
        }

        th { background: #eaf2ec; }
        tr:hover td { background: #fafcfb; }

        .actions {
            display: flex;
            gap: 7px;
            align-items: center;
        }

        .actions form { margin: 0; }

        @media (max-width: 600px) {
            body { padding: 12px; }
            .card { padding: 16px; }
        }
    </style>
</head>
<body>
<div class="container">
    <a class="btn back" href="../dashboard.php">← Kembali ke Dashboard</a>

    <h1>Manajemen Supplier</h1>

    <div class="card">
        <h2><?= $editSupplier ? 'Edit Supplier' : 'Tambah Supplier' ?></h2>

        <?php foreach ($errors as $error): ?>
            <div class="error"><?= e($error) ?></div>
        <?php endforeach; ?>

        <?php if (($_GET['success'] ?? '') === 'created'): ?>
            <div class="success">Supplier berhasil ditambahkan.</div>
        <?php elseif (($_GET['success'] ?? '') === 'updated'): ?>
            <div class="success">Data supplier berhasil diperbarui.</div>
        <?php elseif (($_GET['success'] ?? '') === 'deleted'): ?>
            <div class="success">Supplier berhasil dihapus.</div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" value="save">

            <?php if ($editSupplier): ?>
                <input type="hidden" name="id" value="<?= e($editSupplier['id']) ?>">
            <?php endif; ?>

            <label for="name">Nama Supplier *</label>
            <input id="name" type="text" name="name" maxlength="150"
                   value="<?= e($name) ?>" required>

            <label for="phone">Nomor Telepon</label>
            <input id="phone" type="text" name="phone" maxlength="30"
                   value="<?= e($phone) ?>">

            <label for="email">Email</label>
            <input id="email" type="email" name="email" maxlength="150"
                   value="<?= e($email) ?>">

            <label for="address">Alamat</label>
            <textarea id="address" name="address" rows="3"
                      maxlength="1000"><?= e($address) ?></textarea>

            <button type="submit">
                <?= $editSupplier ? 'Simpan Perubahan' : 'Tambah Supplier' ?>
            </button>

            <?php if ($editSupplier): ?>
                <a class="btn cancel" href="index.php">Batal Edit</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="card">
        <h2>Daftar Supplier (<?= count($suppliers) ?>)</h2>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>No.</th>
                        <th>Nama Supplier</th>
                        <th>Telepon</th>
                        <th>Email</th>
                        <th>Alamat</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$suppliers): ?>
                    <tr>
                        <td colspan="6">Belum ada data supplier.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($suppliers as $i => $supplier): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><?= e($supplier['name']) ?></td>
                            <td><?= e($supplier['phone'] ?? '-') ?></td>
                            <td><?= e($supplier['email'] ?? '-') ?></td>
                            <td><?= e($supplier['address'] ?? '-') ?></td>
                            <td>
                                <div class="actions">
                                    <a class="btn edit"
                                       href="index.php?edit=<?= (int) $supplier['id'] ?>">
                                        Edit
                                    </a>

                                    <form method="POST"
                                          onsubmit="return confirm('Yakin ingin menghapus supplier ini?');">
                                        <input type="hidden" name="csrf_token"
                                               value="<?= e($csrfToken) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id"
                                               value="<?= (int) $supplier['id'] ?>">
                                        <button class="delete" type="submit">Hapus</button>
                                    </form>
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
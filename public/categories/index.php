<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/escape.php';

requireRole(['admin']);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$success = '';
$name = '';
$description = '';
$editCategory = null;
$editId = null;
$formSubmitted = false;

function redirectCategory(string $query): void
{
    header('Location: index.php?' . $query);
    exit;
}

// Proses formulir.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formSubmitted = true;

    $csrfToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($csrfToken) ||
        !hash_equals($_SESSION['csrf_token'], $csrfToken)
    ) {
        http_response_code(403);
        exit('Permintaan tidak valid. Silakan muat ulang halaman.');
    }

    $actionInput = $_POST['action'] ?? '';

    if (!is_string($actionInput)) {
        $actionInput = '';
    }

    $action = $actionInput;

    if ($action === 'create' || $action === 'update') {
        $nameInput = $_POST['name'] ?? '';
        $descriptionInput = $_POST['description'] ?? '';

        if (!is_string($nameInput) || !is_string($descriptionInput)) {
            $error = 'Format input tidak valid.';
        } else {
            $name = trim($nameInput);
            $description = trim($descriptionInput);

            if ($name === '') {
                $error = 'Nama kategori wajib diisi.';
            } elseif (strlen($name) > 100) {
                $error = 'Nama kategori maksimal 100 karakter.';
            } elseif (strlen($description) > 1000) {
                $error = 'Deskripsi maksimal 1000 karakter.';
            }
        }

        $id = null;

        if ($action === 'update') {
            $idInput = $_POST['id'] ?? '';
            $id = is_string($idInput)
                ? filter_var($idInput, FILTER_VALIDATE_INT)
                : false;

            if (!$id || $id < 1) {
                $error = 'ID kategori tidak valid.';
            }
        }

        if ($error === '') {
            try {
                $pdo->beginTransaction();

                if ($action === 'update') {
                    $stmt = $pdo->prepare(
                        "SELECT id FROM categories WHERE id = ? FOR UPDATE"
                    );
                    $stmt->execute([$id]);

                    if (!$stmt->fetch()) {
                        $pdo->rollBack();
                        redirectCategory('error=notfound');
                    }
                }

                // Periksa nama kategori yang sama.
                if ($action === 'update') {
                    $stmt = $pdo->prepare(
                        "SELECT id FROM categories
                         WHERE name = ? AND id <> ?
                         LIMIT 1"
                    );
                    $stmt->execute([$name, $id]);
                } else {
                    $stmt = $pdo->prepare(
                        "SELECT id FROM categories
                         WHERE name = ?
                         LIMIT 1"
                    );
                    $stmt->execute([$name]);
                }

                if ($stmt->fetch()) {
                    $pdo->rollBack();
                    $error = $action === 'update'
                        ? 'Nama kategori sudah digunakan kategori lain.'
                        : 'Kategori tersebut sudah tersedia.';
                } else {
                    if ($action === 'create') {
                        $stmt = $pdo->prepare(
                            "INSERT INTO categories (name, description)
                             VALUES (?, ?)"
                        );

                        $stmt->execute([
                            $name,
                            $description !== '' ? $description : null
                        ]);

                        $pdo->commit();
                        redirectCategory('success=created');
                    }

                    $stmt = $pdo->prepare(
                        "UPDATE categories
                         SET name = ?, description = ?
                         WHERE id = ?"
                    );

                    $stmt->execute([
                        $name,
                        $description !== '' ? $description : null,
                        $id
                    ]);

                    $pdo->commit();
                    redirectCategory('success=updated');
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log($e->getMessage());

                if (
                    $e instanceof PDOException &&
                    $e->getCode() === '23000'
                ) {
                    $error = 'Nama kategori sudah digunakan.';
                } else {
                    $error = $action === 'update'
                        ? 'Kategori gagal diperbarui.'
                        : 'Kategori gagal ditambahkan.';
                }
            }
        }

        if ($action === 'update') {
            $editId = isset($id) && $id ? (int) $id : null;
        }
    } elseif ($action === 'delete') {
        $idInput = $_POST['id'] ?? '';
        $id = is_string($idInput)
            ? filter_var($idInput, FILTER_VALIDATE_INT)
            : false;

        if (!$id || $id < 1) {
            $error = 'ID kategori tidak valid.';
        } else {
            try {
                $pdo->beginTransaction();

                // Kunci kategori selama pengecekan dan penghapusan.
                $stmt = $pdo->prepare(
                    "SELECT id FROM categories WHERE id = ? FOR UPDATE"
                );
                $stmt->execute([$id]);

                if (!$stmt->fetch()) {
                    $pdo->rollBack();
                    redirectCategory('error=notfound');
                }

                // Pastikan kategori belum dipakai produk.
                $stmt = $pdo->prepare(
                    "SELECT id FROM products
                     WHERE category_id = ?
                     LIMIT 1"
                );
                $stmt->execute([$id]);

                if ($stmt->fetch()) {
                    $pdo->rollBack();
                    redirectCategory('error=in_use');
                }

                $stmt = $pdo->prepare(
                    "DELETE FROM categories WHERE id = ?"
                );
                $stmt->execute([$id]);

                $pdo->commit();
                redirectCategory('success=deleted');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log($e->getMessage());
                $error = 'Kategori gagal dihapus. Silakan coba kembali.';
            }
        }
    } else {
        $error = 'Aksi tidak dikenali.';
    }
}

// Notifikasi.
$successMessages = [
    'created' => 'Kategori berhasil ditambahkan.',
    'updated' => 'Kategori berhasil diperbarui.',
    'deleted' => 'Kategori berhasil dihapus.'
];

$successKey = $_GET['success'] ?? '';

if (is_string($successKey)) {
    $success = $successMessages[$successKey] ?? '';
}

$errorKey = $_GET['error'] ?? '';

if (is_string($errorKey)) {
    if ($errorKey === 'in_use') {
        $error = 'Kategori tidak bisa dihapus karena masih digunakan oleh produk.';
    } elseif ($errorKey === 'notfound') {
        $error = 'Kategori tidak ditemukan.';
    }
}

// Tentukan kategori yang sedang diedit.
if ($editId === null && !$formSubmitted) {
    $editInput = $_GET['edit'] ?? '';
    $editId = is_string($editInput)
        ? filter_var($editInput, FILTER_VALIDATE_INT)
        : false;

    if (!$editId || $editId < 1) {
        $editId = null;
    }
}

if ($editId !== null) {
    $stmt = $pdo->prepare(
        "SELECT id, name, description
         FROM categories
         WHERE id = ?"
    );
    $stmt->execute([$editId]);
    $editCategory = $stmt->fetch();

    if (!$editCategory) {
        $editId = null;

        if ($error === '') {
            $error = 'Kategori yang ingin diedit tidak ditemukan.';
        }
    }
}

// Ambil semua kategori.
$stmt = $pdo->query(
    "SELECT id, name, description, created_at
     FROM categories
     ORDER BY name ASC"
);
$categories = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Data Kategori | Inventory Management</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 25px 15px;
            background: #f1f5f2;
            color: #26332d;
            font-family: Arial, sans-serif;
        }

        main {
            max-width: 1100px;
            margin: 20px auto;
        }

        .card {
            background: white;
            border-radius: 14px;
            padding: 25px;
            margin-bottom: 22px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, .04);
        }

        h1, h2 {
            margin-top: 0;
        }

        label {
            display: block;
            margin: 15px 0 7px;
            font-weight: bold;
        }

        input, textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #d5ded9;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
        }

        textarea {
            resize: vertical;
        }

        input:focus, textarea:focus {
            outline: 2px solid #9bd5bd;
            border-color: #176b50;
        }

        button, .btn {
            display: inline-block;
            padding: 10px 14px;
            border: none;
            border-radius: 8px;
            background: #176b50;
            color: white;
            text-decoration: none;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
        }

        button:hover, .btn:hover {
            opacity: .88;
        }

        .form-button {
            margin-top: 16px;
        }

        .back, .cancel {
            background: #65736b;
        }

        .edit {
            background: #218838;
        }

        .delete {
            background: #b93838;
        }

        .error, .success {
            padding: 12px 15px;
            border-radius: 8px;
            margin: 15px 0;
            line-height: 1.5;
        }

        .error {
            color: #8a2525;
            background: #fde8e8;
        }

        .success {
            color: #17652b;
            background: #e2f7e7;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 650px;
        }

        th, td {
            text-align: left;
            padding: 12px;
            border-bottom: 1px solid #e8eeea;
            vertical-align: top;
        }

        th {
            background: #f5f8f6;
        }

        .actions {
            display: flex;
            gap: 7px;
            flex-wrap: wrap;
            align-items: center;
        }

        .actions form {
            margin: 0;
        }

        .muted {
            color: #718078;
            font-size: 14px;
        }

        @media (max-width: 600px) {
            .card {
                padding: 17px;
            }

            h1 {
                font-size: 23px;
            }
        }
    </style>
</head>
<body>
<main>
    <section class="card">
        <h1>Data Kategori</h1>
        <p class="muted">
            Tambahkan dan kelola kategori untuk mengelompokkan produk inventaris.
        </p>

        <?php if ($error !== ''): ?>
            <div class="error"><?= e($error) ?></div>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <div class="success"><?= e($success) ?></div>
        <?php endif; ?>

        <?php if ($editCategory): ?>
            <h2>Edit Kategori</h2>

            <form method="POST">
                <input type="hidden" name="csrf_token"
                       value="<?= e($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id"
                       value="<?= (int) $editCategory['id'] ?>">

                <label for="edit_name">Nama Kategori</label>
                <input
                    type="text"
                    id="edit_name"
                    name="name"
                    maxlength="100"
                    value="<?= e($formSubmitted ? $name : $editCategory['name']) ?>"
                    required
                >

                <label for="edit_description">Deskripsi</label>
                <textarea
                    id="edit_description"
                    name="description"
                    rows="3"
                    maxlength="1000"
                ><?= e($formSubmitted ? $description : ($editCategory['description'] ?? '')) ?></textarea>

                <button class="form-button" type="submit">
                    Simpan Perubahan
                </button>
                <a class="btn cancel" href="index.php">Batal</a>
            </form>
        <?php else: ?>
            <h2>Tambah Kategori</h2>

            <form method="POST">
                <input type="hidden" name="csrf_token"
                       value="<?= e($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="create">

                <label for="name">Nama Kategori</label>
                <input
                    type="text"
                    id="name"
                    name="name"
                    maxlength="100"
                    value="<?= e($formSubmitted ? $name : '') ?>"
                    required
                >

                <label for="description">Deskripsi</label>
                <textarea
                    id="description"
                    name="description"
                    rows="3"
                    maxlength="1000"
                ><?= e($formSubmitted ? $description : '') ?></textarea>

                <button class="form-button" type="submit">
                    Simpan Kategori
                </button>
                <a class="btn back" href="../dashboard.php">Dashboard</a>
            </form>
        <?php endif; ?>
    </section>

    <section class="card">
        <h2>Daftar Kategori</h2>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>No.</th>
                        <th>Nama Kategori</th>
                        <th>Deskripsi</th>
                        <th>Tanggal Dibuat</th>
                        <th>Aksi</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (!$categories): ?>
                    <tr>
                        <td colspan="5">Belum ada kategori.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($categories as $i => $category): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><?= e($category['name']) ?></td>
                            <td><?= e($category['description'] ?? '-') ?></td>
                            <td><?= e($category['created_at']) ?></td>
                            <td>
                                <div class="actions">
                                    <a
                                        class="btn edit"
                                        href="index.php?edit=<?= (int) $category['id'] ?>"
                                    >
                                        Edit
                                    </a>

                                    <form
                                        method="POST"
                                        onsubmit="return confirm('Yakin ingin menghapus kategori ini?');"
                                    >
                                        <input type="hidden" name="csrf_token"
                                               value="<?= e($_SESSION['csrf_token']) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id"
                                               value="<?= (int) $category['id'] ?>">

                                        <button class="delete" type="submit">
                                            Hapus
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <a class="btn back" href="../dashboard.php">
            Kembali ke Dashboard
        </a>
    </section>
</main>
</body>
</html>

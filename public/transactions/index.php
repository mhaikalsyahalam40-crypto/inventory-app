<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/escape.php';

requireRole(['admin', 'staff']);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];
$errors = [];

$product_id = '';
$type = 'IN';
$quantity = '';
$note = '';

/* =========================
   PROSES TRANSAKSI STOK
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? '';

    if (!is_string($postedToken) || !hash_equals($csrfToken, $postedToken)) {
        http_response_code(403);
        exit('Permintaan tidak valid. Silakan muat ulang halaman.');
    }

    $product_id = $_POST['product_id'] ?? '';
    $type = $_POST['type'] ?? '';
    $quantity = $_POST['quantity'] ?? '';
    $note = trim($_POST['note'] ?? '');

    $validProductId = filter_var($product_id, FILTER_VALIDATE_INT);
    $validQuantity = filter_var($quantity, FILTER_VALIDATE_INT);

    if ($validProductId === false || $validProductId < 1) {
        $errors[] = 'Pilih produk yang valid.';
    }

    if (!in_array($type, ['IN', 'OUT'], true)) {
        $errors[] = 'Jenis transaksi tidak valid.';
    }

    if ($validQuantity === false || $validQuantity < 1) {
        $errors[] = 'Jumlah harus berupa bilangan bulat lebih dari nol.';
    }

    if (strlen($note) > 500) {
        $errors[] = 'Catatan maksimal 500 karakter.';
    }

    if (!$errors) {
        try {
            $user = currentUser();
            $userId = is_array($user) ? ($user['id'] ?? null) : null;

            if (!$userId || !filter_var($userId, FILTER_VALIDATE_INT)) {
                throw new RuntimeException('Identitas pengguna tidak ditemukan.');
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                "SELECT id, stock
                 FROM products
                 WHERE id = ?
                 FOR UPDATE"
            );
            $stmt->execute([$validProductId]);
            $product = $stmt->fetch();

            if (!$product) {
                $pdo->rollBack();
                $errors[] = 'Produk tidak ditemukan.';
            } elseif (
                $type === 'OUT'
                && $validQuantity > (int) $product['stock']
            ) {
                $availableStock = (int) $product['stock'];
                $pdo->rollBack();

                $errors[] = 'Stok tidak mencukupi. Stok tersedia: '
                    . $availableStock . '.';
            } else {
                if ($type === 'IN') {
                    $update = $pdo->prepare(
                        "UPDATE products
                         SET stock = stock + ?
                         WHERE id = ?"
                    );
                } else {
                    $update = $pdo->prepare(
                        "UPDATE products
                         SET stock = stock - ?
                         WHERE id = ?"
                    );
                }

                $update->execute([$validQuantity, $validProductId]);

                $insert = $pdo->prepare(
                    "INSERT INTO stock_movements
                     (product_id, user_id, type, quantity, note)
                     VALUES (?, ?, ?, ?, ?)"
                );

                $insert->execute([
                    $validProductId,
                    (int) $userId,
                    $type,
                    $validQuantity,
                    $note !== '' ? $note : null
                ]);

                $pdo->commit();

                header('Location: index.php?success=created');
                exit;
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log($e->getMessage());
            $errors[] = 'Transaksi gagal disimpan. Periksa data dan database.';
        }
    }
}

/* =========================
   AMBIL DATA PRODUK
========================= */
$stmt = $pdo->query(
    "SELECT id, sku, name, stock
     FROM products
     ORDER BY name ASC"
);
$products = $stmt->fetchAll();

/* =========================
   PENCARIAN DAN FILTER
========================= */
$search = trim($_GET['search'] ?? '');
$filterType = $_GET['type'] ?? '';

if (!in_array($filterType, ['', 'IN', 'OUT'], true)) {
    $filterType = '';
}

$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);
$page = ($page !== false && $page > 0) ? $page : 1;

$perPage = 10;
$where = [];
$params = [];

if ($search !== '') {
    $where[] = '(p.name LIKE ? OR p.sku LIKE ? OR u.full_name LIKE ?)';
    $keyword = '%' . $search . '%';

    $params[] = $keyword;
    $params[] = $keyword;
    $params[] = $keyword;
}

if ($filterType !== '') {
    $where[] = 'sm.type = ?';
    $params[] = $filterType;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

/* Hitung jumlah transaksi sesuai filter */
$countStmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM stock_movements sm
     JOIN products p ON p.id = sm.product_id
     JOIN users u ON u.id = sm.user_id
     $whereSql"
);
$countStmt->execute($params);

$totalMovements = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalMovements / $perPage));

$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

/* Ambil transaksi pada halaman aktif */
$stmt = $pdo->prepare(
    "SELECT sm.id, p.name AS product_name, p.sku,
            u.full_name, sm.type, sm.quantity, sm.note, sm.created_at
     FROM stock_movements sm
     JOIN products p ON p.id = sm.product_id
     JOIN users u ON u.id = sm.user_id
     $whereSql
     ORDER BY sm.id DESC
     LIMIT $perPage OFFSET $offset"
);
$stmt->execute($params);
$movements = $stmt->fetchAll();

function transactionPageUrl($page, $search, $filterType)
{
    return '?' . http_build_query([
        'page' => $page,
        'search' => $search,
        'type' => $filterType
    ]);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Transaksi Stok</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            background: #f3f6fa;
            color: #263238;
            margin: 0;
            padding: 25px;
        }

        .container {
            max-width: 1200px;
            margin: auto;
        }

        .card {
            background: white;
            padding: 24px;
            margin-bottom: 22px;
            border-radius: 12px;
            box-shadow: 0 3px 12px rgba(0,0,0,.06);
        }

        h1, h2 {
            margin-top: 0;
        }

        label {
            display: block;
            margin-top: 12px;
            font-weight: bold;
        }

        input, select, textarea {
            width: 100%;
            padding: 11px;
            margin-top: 7px;
            border: 1px solid #ccd5df;
            border-radius: 6px;
            font: inherit;
        }

        input:focus, select:focus, textarea:focus {
            outline: 2px solid #b8dec4;
            border-color: #218838;
        }

        button, .btn {
            display: inline-block;
            border: none;
            border-radius: 6px;
            padding: 11px 16px;
            margin-top: 16px;
            background: #218838;
            color: white;
            text-decoration: none;
            cursor: pointer;
            font-size: 14px;
        }

        .back {
            background: #526477;
            margin-top: 0;
            margin-bottom: 18px;
        }

        .reset {
            background: #6c757d;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            min-width: 850px;
            border-collapse: collapse;
        }

        th, td {
            text-align: left;
            padding: 12px;
            border-bottom: 1px solid #e3e8ee;
            vertical-align: top;
        }

        th {
            background: #eaf2ec;
        }

        tbody tr:hover {
            background: #f8fbf9;
        }

        .error, .success {
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 12px;
        }

        .error {
            background: #ffe8e8;
            color: #a21d1d;
        }

        .success {
            background: #e2f7e7;
            color: #17652b;
        }

        .in {
            color: #218838;
            font-weight: bold;
        }

        .out {
            color: #c62828;
            font-weight: bold;
        }

        .filter-form {
            display: grid;
            grid-template-columns: 2fr 1fr auto auto;
            gap: 10px;
            align-items: end;
            margin-bottom: 16px;
        }

        .filter-form label {
            margin-top: 0;
        }

        .filter-form input,
        .filter-form select {
            margin-bottom: 0;
        }

        .filter-form button,
        .filter-form .btn {
            margin-top: 0;
            white-space: nowrap;
        }

        .pagination {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
            margin-top: 18px;
        }

        .pagination .btn {
            margin-top: 0;
        }

        .page-info {
            padding: 10px;
        }

        .empty {
            text-align: center;
            padding: 22px;
            color: #687782;
        }

        @media (max-width: 750px) {
            body {
                padding: 12px;
            }

            .card {
                padding: 16px;
            }

            .filter-form {
                grid-template-columns: 1fr;
            }

            .filter-form button,
            .filter-form .btn {
                width: 100%;
                text-align: center;
            }
        }
    </style>
</head>

<body>
<div class="container">

    <a class="btn back" href="../dashboard.php">
        ← Kembali ke Dashboard
    </a>

    <h1>Transaksi Stok Barang</h1>

    <!-- FORM TRANSAKSI -->
    <div class="card">
        <h2>Catat Transaksi</h2>

        <?php foreach ($errors as $error): ?>
            <div class="error"><?= e($error) ?></div>
        <?php endforeach; ?>

        <?php if (($_GET['success'] ?? '') === 'created'): ?>
            <div class="success">
                Transaksi berhasil disimpan dan stok telah diperbarui.
            </div>
        <?php endif; ?>

        <?php if (!$products): ?>
            <div class="error">
                Belum ada produk. Tambahkan produk terlebih dahulu.
            </div>
        <?php else: ?>
            <form method="POST" action="index.php">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e($csrfToken) ?>"
                >

                <label for="product_id">Produk</label>
                <select id="product_id" name="product_id" required>
                    <option value="">-- Pilih Produk --</option>

                    <?php foreach ($products as $product): ?>
                        <option
                            value="<?= (int) $product['id'] ?>"
                            <?= (string) $product_id === (string) $product['id'] ? 'selected' : '' ?>
                        >
                            <?= e($product['sku']) ?> -
                            <?= e($product['name']) ?>
                            (Stok: <?= (int) $product['stock'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="type">Jenis Transaksi</label>
                <select id="type" name="type" required>
                    <option value="IN" <?= $type === 'IN' ? 'selected' : '' ?>>
                        Barang Masuk (IN)
                    </option>
                    <option value="OUT" <?= $type === 'OUT' ? 'selected' : '' ?>>
                        Barang Keluar (OUT)
                    </option>
                </select>

                <label for="quantity">Jumlah</label>
                <input
                    type="number"
                    id="quantity"
                    name="quantity"
                    min="1"
                    step="1"
                    value="<?= e($quantity) ?>"
                    required
                >

                <label for="note">Catatan</label>
                <textarea
                    id="note"
                    name="note"
                    rows="3"
                    maxlength="500"
                    placeholder="Contoh: pembelian barang atau barang digunakan"
                ><?= e($note) ?></textarea>

                <button type="submit">Simpan Transaksi</button>
            </form>
        <?php endif; ?>
    </div>

    <!-- RIWAYAT TRANSAKSI -->
    <div class="card">
        <h2>Riwayat Transaksi</h2>

        <form method="GET" action="index.php" class="filter-form">
            <div>
                <label for="search">Cari Produk / SKU / Petugas</label>
                <input
                    type="text"
                    id="search"
                    name="search"
                    value="<?= e($search) ?>"
                    placeholder="Ketik kata kunci..."
                >
            </div>

            <div>
                <label for="filter_type">Jenis Transaksi</label>
                <select id="filter_type" name="type">
                    <option value="">Semua Transaksi</option>
                    <option value="IN" <?= $filterType === 'IN' ? 'selected' : '' ?>>
                        Barang Masuk
                    </option>
                    <option value="OUT" <?= $filterType === 'OUT' ? 'selected' : '' ?>>
                        Barang Keluar
                    </option>
                </select>
            </div>

            <button type="submit">Cari</button>
            <a href="index.php" class="btn reset">Reset</a>
        </form>

        <p>
            Menampilkan <strong><?= count($movements) ?></strong>
            dari <strong><?= $totalMovements ?></strong> transaksi.
        </p>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Waktu</th>
                        <th>SKU</th>
                        <th>Nama Produk</th>
                        <th>Jenis</th>
                        <th>Jumlah</th>
                        <th>Petugas</th>
                        <th>Catatan</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (!$movements): ?>
                    <tr>
                        <td colspan="7" class="empty">
                            Tidak ada transaksi yang sesuai dengan pencarian.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($movements as $movement): ?>
                        <tr>
                            <td><?= e($movement['created_at']) ?></td>
                            <td><?= e($movement['sku']) ?></td>
                            <td><?= e($movement['product_name']) ?></td>
                            <td class="<?= $movement['type'] === 'IN' ? 'in' : 'out' ?>">
                                <?= $movement['type'] === 'IN' ? 'Masuk (IN)' : 'Keluar (OUT)' ?>
                            </td>
                            <td><?= (int) $movement['quantity'] ?></td>
                            <td><?= e($movement['full_name']) ?></td>
                            <td><?= e($movement['note'] ?? '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a
                        class="btn back"
                        href="<?= e(transactionPageUrl($page - 1, $search, $filterType)) ?>"
                    >
                        ← Sebelumnya
                    </a>
                <?php endif; ?>

                <span class="page-info">
                    Halaman <?= $page ?> dari <?= $totalPages ?>
                </span>

                <?php if ($page < $totalPages): ?>
                    <a
                        class="btn back"
                        href="<?= e(transactionPageUrl($page + 1, $search, $filterType)) ?>"
                    >
                        Berikutnya →
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

</div>
</body>
</html>
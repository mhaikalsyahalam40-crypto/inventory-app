<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/escape.php';

requireLogin();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Pencarian produk
$search = trim($_GET['search'] ?? '');

// Ambil daftar kategori
$stmt = $pdo->query("SELECT id, name FROM categories ORDER BY name ASC");
$categories = $stmt->fetchAll();

// Filter kategori
$categoryId = filter_input(INPUT_GET, 'category_id', FILTER_VALIDATE_INT);

if (!$categoryId || $categoryId < 1) {
    $categoryId = null;
}

// Kolom pengurutan yang diizinkan
$allowedSorts = [
    'name' => 'p.name',
    'price' => 'p.price',
    'stock' => 'p.stock'
];

$sort = $_GET['sort'] ?? 'name';

if (!isset($allowedSorts[$sort])) {
    $sort = 'name';
}

$order = strtoupper($_GET['order'] ?? 'ASC');

if (!in_array($order, ['ASC', 'DESC'], true)) {
    $order = 'ASC';
}

// Pagination
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
$page = ($page && $page > 0) ? $page : 1;
$perPage = 10;

// Susun kondisi pencarian
$where = [];
$params = [];

if ($search !== '') {
    $where[] = '(p.name LIKE ? OR p.sku LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

if ($categoryId !== null) {
    $where[] = 'p.category_id = ?';
    $params[] = $categoryId;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Hitung jumlah produk
$countSql = "
    SELECT COUNT(*)
    FROM products p
    $whereSql
";

$stmt = $pdo->prepare($countSql);
$stmt->execute($params);

$totalProducts = (int) $stmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalProducts / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $perPage;

// Ambil data produk
$sql = "
    SELECT
        p.id,
        p.sku,
        p.name,
        p.price,
        p.stock,
        p.minimum_stock,
        c.name AS category_name
    FROM products p
    LEFT JOIN categories c ON c.id = p.category_id
    $whereSql
    ORDER BY {$allowedSorts[$sort]} $order
    LIMIT $perPage OFFSET $offset
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

// Pertahankan filter saat berpindah halaman
function pageUrl(int $pageNumber): string
{
    $query = $_GET;
    $query['page'] = $pageNumber;

    return '?' . http_build_query($query);
}

// Pesan notifikasi
$success = $_GET['success'] ?? '';
$error = $_GET['error'] ?? '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Data Produk | Inventory Management</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f1f5f2;
            color: #26332d;
        }

        header {
            background: #176b50;
            color: white;
            padding: 20px 6%;
        }

        header h2 {
            margin: 0;
        }

        main {
            max-width: 1200px;
            margin: 30px auto;
            padding: 0 20px;
        }

        .panel {
            background: white;
            padding: 24px;
            border-radius: 14px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, .05);
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 22px;
        }

        h1 {
            margin: 0;
            font-size: 26px;
        }

        .filters {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 22px;
        }

        input,
        select,
        button,
        .btn {
            padding: 11px 13px;
            border: 1px solid #d5ded9;
            border-radius: 8px;
            font-size: 14px;
        }

        input {
            flex: 1;
            min-width: 180px;
        }

        button,
        .btn {
            border: none;
            background: #176b50;
            color: white;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            text-align: center;
            font-weight: bold;
        }

        button:hover,
        .btn:hover {
            opacity: .88;
        }

        .btn-secondary {
            background: #65736b;
        }

        .btn-edit {
            background: #218838;
            padding: 8px 12px;
            margin-bottom: 5px;
        }

        .btn-delete {
            background: #b93838;
            padding: 8px 12px;
        }

        .message {
            padding: 13px 16px;
            margin: 15px 0;
            border-radius: 8px;
            font-size: 14px;
            line-height: 1.5;
        }

        .success {
            background: #e2f7e7;
            color: #17652b;
        }

        .warning {
            background: #fff0d5;
            color: #8a5200;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 850px;
        }

        th,
        td {
            padding: 13px;
            text-align: left;
            border-bottom: 1px solid #e8eeea;
            vertical-align: middle;
        }

        th {
            background: #f5f8f6;
            font-size: 13px;
            white-space: nowrap;
        }

        td {
            font-size: 14px;
        }

        .low-stock {
            color: #b93838;
            font-weight: bold;
        }

        .pagination {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 22px;
        }

        .pagination a {
            padding: 9px 13px;
            border-radius: 7px;
            background: #e8eeea;
            color: #26332d;
            text-decoration: none;
        }

        .pagination a.active {
            background: #176b50;
            color: white;
        }

        .back {
            margin-top: 22px;
        }

        .muted {
            color: #718078;
            font-size: 14px;
        }

        .actions {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        .actions form {
            margin: 0;
        }

        @media (max-width: 600px) {
            .panel {
                padding: 16px;
            }

            h1 {
                font-size: 22px;
            }

            .topbar .btn {
                width: 100%;
            }
        }
    </style>
</head>

<body>

<header>
    <h2>Inventory Management System</h2>
</header>

<main>
    <section class="panel">

        <div class="topbar">
            <h1>Data Produk</h1>
            <a class="btn" href="create.php">+ Tambah Produk</a>
        </div>

        <?php if ($success === 'created'): ?>
            <div class="message success">
                Produk berhasil ditambahkan.
            </div>
        <?php elseif ($success === 'updated'): ?>
            <div class="message success">
                Data produk berhasil diperbarui.
            </div>
        <?php elseif ($success === 'deleted'): ?>
            <div class="message success">
                Produk berhasil dihapus.
            </div>
        <?php elseif ($error === 'has_history'): ?>
            <div class="message warning">
                Produk tidak bisa dihapus karena sudah memiliki riwayat transaksi stok.
            </div>
        <?php elseif ($error === 'notfound'): ?>
            <div class="message warning">
                Produk tidak ditemukan.
            </div>
        <?php elseif ($error === 'invalid'): ?>
            <div class="message warning">
                ID produk tidak valid.
            </div>
        <?php elseif ($error === 'delete_failed'): ?>
            <div class="message warning">
                Produk gagal dihapus. Silakan coba kembali.
            </div>
        <?php endif; ?>

        <form method="GET" class="filters">
            <input
                type="text"
                name="search"
                placeholder="Cari nama produk atau SKU..."
                value="<?= e($search) ?>"
            >

            <select name="category_id">
                <option value="">Semua kategori</option>

                <?php foreach ($categories as $category): ?>
                    <option
                        value="<?= (int) $category['id'] ?>"
                        <?= $categoryId === (int) $category['id'] ? 'selected' : '' ?>
                    >
                        <?= e($category['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="sort">
                <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>
                    Urut nama
                </option>
                <option value="price" <?= $sort === 'price' ? 'selected' : '' ?>>
                    Urut harga
                </option>
                <option value="stock" <?= $sort === 'stock' ? 'selected' : '' ?>>
                    Urut stok
                </option>
            </select>

            <select name="order">
                <option value="ASC" <?= $order === 'ASC' ? 'selected' : '' ?>>
                    Naik
                </option>
                <option value="DESC" <?= $order === 'DESC' ? 'selected' : '' ?>>
                    Turun
                </option>
            </select>

            <button type="submit">Cari / Filter</button>
            <a class="btn btn-secondary" href="index.php">Reset</a>
        </form>

        <p class="muted">
            Total produk ditemukan:
            <strong><?= number_format($totalProducts, 0, ',', '.') ?></strong>
        </p>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>No.</th>
                        <th>SKU</th>
                        <th>Nama Produk</th>
                        <th>Kategori</th>
                        <th>Harga</th>
                        <th>Stok</th>
                        <th>Minimum</th>
                        <th>Aksi</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (!$products): ?>
                    <tr>
                        <td colspan="8" class="muted">
                            Belum ada produk atau data tidak ditemukan.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($products as $i => $product): ?>
                        <tr>
                            <td><?= $offset + $i + 1 ?></td>

                            <td><?= e($product['sku']) ?></td>

                            <td><?= e($product['name']) ?></td>

                            <td><?= e($product['category_name'] ?? '-') ?></td>

                            <td>
                                Rp <?= number_format((float) $product['price'], 0, ',', '.') ?>
                            </td>

                            <td class="<?= (int) $product['stock'] <= (int) $product['minimum_stock'] ? 'low-stock' : '' ?>">
                                <?= (int) $product['stock'] ?>
                            </td>

                            <td><?= (int) $product['minimum_stock'] ?></td>

                            <td>
                                <div class="actions">
                                    <a
                                        class="btn btn-edit"
                                        href="edit.php?id=<?= (int) $product['id'] ?>"
                                    >
                                        Edit
                                    </a>

                                    <form
                                        method="POST"
                                        action="delete.php"
                                        onsubmit="return confirm('Yakin ingin menghapus produk ini?');"
                                    >
                                        <input
                                            type="hidden"
                                            name="id"
                                            value="<?= (int) $product['id'] ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?= e($_SESSION['csrf_token']) ?>"
                                        >

                                        <button
                                            type="submit"
                                            class="btn-delete"
                                        >
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

        <?php if ($totalPages > 1): ?>
            <nav class="pagination" aria-label="Pagination">
                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                    <a
                        class="<?= $p === $page ? 'active' : '' ?>"
                        href="<?= e(pageUrl($p)) ?>"
                    >
                        <?= $p ?>
                    </a>
                <?php endfor; ?>
            </nav>
        <?php endif; ?>

        <div class="back">
            <a class="btn btn-secondary" href="../dashboard.php">
                Kembali ke Dashboard
            </a>
        </div>

    </section>
</main>

</body>
</html>
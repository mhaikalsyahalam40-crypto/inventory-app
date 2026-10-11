<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/escape.php';

requireLogin();

$user = currentUser();
$isAdmin = ($user['role'] ?? '') === 'admin';

// Total produk
$stmt = $pdo->query("SELECT COUNT(*) AS total FROM products");
$totalProduk = (int) $stmt->fetch()['total'];

// Nilai inventaris
$stmt = $pdo->query("
    SELECT COALESCE(SUM(stock * price), 0) AS total
    FROM products
");
$nilaiInventaris = (float) $stmt->fetch()['total'];

// Produk stok menipis
$stmt = $pdo->query("
    SELECT COUNT(*) AS total
    FROM products
    WHERE stock <= minimum_stock
");
$stokMenipis = (int) $stmt->fetch()['total'];

// Total barang masuk
$stmt = $pdo->query("
    SELECT COALESCE(SUM(quantity), 0) AS total
    FROM stock_movements
    WHERE type = 'IN'
");
$totalMasuk = (int) $stmt->fetch()['total'];

// Total barang keluar
$stmt = $pdo->query("
    SELECT COALESCE(SUM(quantity), 0) AS total
    FROM stock_movements
    WHERE type = 'OUT'
");
$totalKeluar = (int) $stmt->fetch()['total'];

// Daftar stok menipis
$stmt = $pdo->query("
    SELECT name, sku, stock, minimum_stock
    FROM products
    WHERE stock <= minimum_stock
    ORDER BY stock ASC
    LIMIT 5
");
$daftarStokMenipis = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | Inventory Management</title>

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
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        header h2 {
            margin: 0;
            font-size: 22px;
        }

        .user-info {
            font-size: 14px;
        }

        main {
            max-width: 1200px;
            margin: 30px auto;
            padding: 0 20px;
        }

        .welcome {
            background: white;
            padding: 25px;
            border-radius: 14px;
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, .04);
        }

        .welcome h1 {
            margin: 0 0 10px;
            font-size: 26px;
        }

        .welcome p {
            color: #718078;
            margin-bottom: 0;
            line-height: 1.6;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 18px;
        }

        .stat-card {
            background: white;
            padding: 23px;
            border-radius: 14px;
            border-top: 5px solid #176b50;
            box-shadow: 0 4px 15px rgba(0, 0, 0, .04);
            min-width: 0;
        }

        .stat-card:nth-child(2) {
            border-top-color: #d99a25;
        }

        .stat-card:nth-child(3) {
            border-top-color: #d9534f;
        }

        .stat-card:nth-child(4) {
            border-top-color: #3679b9;
        }

        .stat-card:nth-child(5) {
            border-top-color: #8b63b6;
        }

        .stat-card p {
            color: #718078;
            margin: 0 0 12px;
            font-size: 14px;
        }

        .stat-card h2 {
            margin: 0;
            font-size: 25px;
            overflow-wrap: anywhere;
        }

        /* Khusus nilai inventaris agar tetap satu baris */
        .stat-card:nth-child(2) h2 {
            white-space: nowrap;
            font-size: clamp(14px, 1.4vw, 20px);
            letter-spacing: -0.4px;
        }

        .section {
            margin-top: 28px;
            background: white;
            border-radius: 14px;
            padding: 24px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, .04);
        }

        .section h2 {
            margin-top: 0;
            font-size: 20px;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 550px;
        }

        th, td {
            text-align: left;
            padding: 13px;
            border-bottom: 1px solid #e8eeea;
        }

        th {
            background: #f5f8f6;
            font-size: 13px;
        }

        td {
            font-size: 14px;
        }

        .low-stock {
            color: #b93838;
            font-weight: bold;
        }

        .empty {
            color: #718078;
            padding: 10px 0;
        }

        .menu {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 16px;
        }

        .menu-item {
            display: block;
            border: 1px solid #e1e9e3;
            border-radius: 12px;
            padding: 20px;
            text-decoration: none;
            color: #26332d;
            background: #fff;
            transition: transform .2s, box-shadow .2s, border-color .2s;
        }

        .menu-item:hover {
            transform: translateY(-3px);
            border-color: #176b50;
            box-shadow: 0 6px 16px rgba(0, 0, 0, .07);
        }

        .menu-icon {
            font-size: 27px;
            margin-bottom: 12px;
        }

        .menu-item h3 {
            margin: 0 0 10px;
            font-size: 17px;
        }

        .menu-item p {
            color: #718078;
            font-size: 14px;
            line-height: 1.6;
            margin: 0;
        }

        .menu-label {
            display: inline-block;
            margin-top: 14px;
            color: #176b50;
            font-size: 13px;
            font-weight: bold;
        }

        .logout {
            display: inline-block;
            margin-top: 25px;
            padding: 12px 18px;
            background: #b93838;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: bold;
        }

        .logout:hover {
            background: #922b2b;
        }

        footer {
            text-align: center;
            padding: 25px;
            color: #718078;
            font-size: 13px;
        }

        @media (max-width: 600px) {
            header {
                padding: 18px 5%;
            }

            header h2 {
                font-size: 19px;
            }

            main {
                padding: 0 14px;
                margin-top: 20px;
            }

            .welcome,
            .section {
                padding: 18px;
            }

            .welcome h1 {
                font-size: 23px;
            }

            .stat-card {
                padding: 18px;
            }

            .stat-card:nth-child(2) h2 {
                font-size: clamp(14px, 4vw, 18px);
            }
        }
    </style>
</head>

<body>

<header>
    <h2>Inventory Management System</h2>

    <div class="user-info">
        Login sebagai:
        <strong><?= e($user['full_name'] ?? $user['username']) ?></strong>
        (<?= e($user['role'] ?? '') ?>)
    </div>
</header>

<main>

    <section class="welcome">
        <h1>Dashboard Inventaris</h1>
        <p>
            Selamat datang di sistem inventaris.
            Pantau persediaan, nilai barang, dan transaksi stok melalui dashboard ini.
        </p>
    </section>

    <section class="stats">

        <div class="stat-card">
            <p>Total Produk</p>
            <h2><?= number_format($totalProduk, 0, ',', '.') ?></h2>
        </div>

        <div class="stat-card">
            <p>Nilai Inventaris</p>
            <h2>Rp <?= number_format($nilaiInventaris, 0, ',', '.') ?></h2>
        </div>

        <div class="stat-card">
            <p>Produk Stok Menipis</p>
            <h2><?= number_format($stokMenipis, 0, ',', '.') ?></h2>
        </div>

        <div class="stat-card">
            <p>Total Barang Masuk</p>
            <h2><?= number_format($totalMasuk, 0, ',', '.') ?></h2>
        </div>

        <div class="stat-card">
            <p>Total Barang Keluar</p>
            <h2><?= number_format($totalKeluar, 0, ',', '.') ?></h2>
        </div>

    </section>

    <section class="section">
        <h2>Daftar Produk dengan Stok Menipis</h2>

        <?php if (count($daftarStokMenipis) > 0): ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>No.</th>
                            <th>SKU</th>
                            <th>Nama Produk</th>
                            <th>Stok Saat Ini</th>
                            <th>Stok Minimum</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($daftarStokMenipis as $index => $produk): ?>
                            <tr>
                                <td><?= $index + 1 ?></td>
                                <td><?= e($produk['sku']) ?></td>
                                <td><?= e($produk['name']) ?></td>
                                <td class="low-stock"><?= (int) $produk['stock'] ?></td>
                                <td><?= (int) $produk['minimum_stock'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="empty">
                Tidak ada produk dengan stok menipis saat ini.
            </p>
        <?php endif; ?>
    </section>

    <section class="section">
        <h2>Menu Sistem</h2>

        <div class="menu">

            <a class="menu-item" href="products/index.php">
                <div class="menu-icon">📦</div>
                <h3>Data Produk</h3>
                <p>Melihat, mencari, dan mengelola data produk inventaris.</p>
                <span class="menu-label">Buka menu →</span>
            </a>

            <?php if ($isAdmin): ?>
                <a class="menu-item" href="categories/index.php">
                    <div class="menu-icon">🗂️</div>
                    <h3>Kategori</h3>
                    <p>Mengelola kategori dan pengelompokan produk.</p>
                    <span class="menu-label">Buka menu →</span>
                </a>
            <?php endif; ?>

            <?php if ($isAdmin): ?>
                <a class="menu-item" href="suppliers/index.php">
                    <div class="menu-icon">🚚</div>
                    <h3>Supplier</h3>
                    <p>Mengelola informasi pemasok barang.</p>
                    <span class="menu-label">Buka menu →</span>
                </a>
            <?php endif; ?>

            <a class="menu-item" href="transactions/index.php">
                <div class="menu-icon">🔄</div>
                <h3>Transaksi Stok</h3>
                <p>Mencatat barang masuk, barang keluar, dan riwayat transaksi.</p>
                <span class="menu-label">Buka menu →</span>
            </a>

            <?php if ($isAdmin): ?>
                <a class="menu-item" href="users/index.php">
                    <div class="menu-icon">👥</div>
                    <h3>Pengguna</h3>
                    <p>Membuat dan melihat akun pengguna sistem.</p>
                    <span class="menu-label">Buka menu →</span>
                </a>
            <?php endif; ?>

        </div>

        <a class="logout" href="logout.php"
           onclick="return confirm('Yakin ingin logout?');">
            Logout
        </a>
    </section>

</main>

<footer>
    Inventory Management System &copy; 2026
</footer>

</body>
</html>
<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/escape.php';

requireRole(['admin']);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (
        !isset($_POST['csrf_token']) ||
        !is_string($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        http_response_code(403);
        exit('Permintaan tidak valid. Muat ulang halaman.');
    }

    $productId = filter_input(INPUT_POST, 'product_id', FILTER_VALIDATE_INT);
    $supplierId = filter_input(INPUT_POST, 'supplier_id', FILTER_VALIDATE_INT);
    $supplierPrice = $_POST['supplier_price'] ?? '';

    if ($productId === false || $productId === null || $productId < 1) {
        $errors[] = 'Silakan pilih produk yang valid.';
    }

    if ($supplierId === false || $supplierId === null || $supplierId < 1) {
        $errors[] = 'Silakan pilih supplier yang valid.';
    }

    if (
        !is_string($supplierPrice) ||
        trim($supplierPrice) === '' ||
        !is_numeric($supplierPrice) ||
        !is_finite((float) $supplierPrice) ||
        (float) $supplierPrice < 0 ||
        (float) $supplierPrice > 9999999999.99
    ) {
        $errors[] = 'Harga beli harus berupa angka yang valid dan tidak negatif.';
    }

    if (!$errors) {
        $stmt = $pdo->prepare("SELECT id FROM products WHERE id = ?");
        $stmt->execute([$productId]);
        $productExists = $stmt->fetch();

        $stmt = $pdo->prepare("SELECT id FROM suppliers WHERE id = ?");
        $stmt->execute([$supplierId]);
        $supplierExists = $stmt->fetch();

        if (!$productExists) {
            $errors[] = 'Produk tidak ditemukan.';
        }

        if (!$supplierExists) {
            $errors[] = 'Supplier tidak ditemukan.';
        }
    }

    if (!$errors) {
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO product_suppliers
                    (product_id, supplier_id, supplier_price)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    supplier_price = VALUES(supplier_price)"
            );

            $stmt->execute([
                $productId,
                $supplierId,
                number_format((float) $supplierPrice, 2, '.', '')
            ]);

            $success = 'Supplier berhasil dihubungkan dengan produk.';
        } catch (PDOException $e) {
            error_log($e->getMessage());
            $errors[] = 'Gagal menyimpan hubungan produk dan supplier.';
        }
    }
}

$products = $pdo->query(
    "SELECT id, sku, name
     FROM products
     ORDER BY name ASC"
)->fetchAll();

$suppliers = $pdo->query(
    "SELECT id, name
     FROM suppliers
     ORDER BY name ASC"
)->fetchAll();

$links = $pdo->query(
    "SELECT ps.product_id, ps.supplier_id, ps.supplier_price,
            p.sku, p.name AS product_name,
            s.name AS supplier_name
     FROM product_suppliers ps
     JOIN products p ON p.id = ps.product_id
     JOIN suppliers s ON s.id = ps.supplier_id
     ORDER BY p.name ASC, s.name ASC"
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hubungan Supplier dan Produk</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 25px;
            font-family: Arial, sans-serif;
            background: #f3f6fa;
            color: #263238;
        }

        .container {
            max-width: 1100px;
            margin: auto;
        }

        .card {
            background: white;
            padding: 24px;
            margin-bottom: 22px;
            border-radius: 12px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.06);
        }

        h1, h2 {
            margin-top: 0;
        }

        label {
            display: block;
            margin-top: 15px;
            font-weight: bold;
        }

        input, select {
            width: 100%;
            padding: 11px;
            margin-top: 7px;
            border: 1px solid #ccd5df;
            border-radius: 6px;
        }

        button, .back {
            display: inline-block;
            margin-top: 18px;
            padding: 11px 16px;
            border: none;
            border-radius: 6px;
            background: #218838;
            color: white;
            text-decoration: none;
            cursor: pointer;
        }

        .back {
            margin-top: 0;
            margin-bottom: 18px;
            background: #526477;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e3e8ee;
        }

        th {
            background: #eaf2ec;
        }

        .table-wrap {
            overflow-x: auto;
        }

        .error {
            margin-bottom: 10px;
            padding: 12px;
            border-radius: 6px;
            background: #ffe8e8;
            color: #a21d1d;
        }

        .success {
            margin-bottom: 10px;
            padding: 12px;
            border-radius: 6px;
            background: #e2f7e7;
            color: #17652b;
        }
    </style>
</head>

<body>
<div class="container">

    <a class="back" href="../dashboard.php">
        ← Kembali ke Dashboard
    </a>

    <h1>Hubungkan Supplier dengan Produk</h1>

    <div class="card">
        <h2>Tambah Hubungan</h2>

        <?php foreach ($errors as $error): ?>
            <div class="error"><?= e($error) ?></div>
        <?php endforeach; ?>

        <?php if ($success !== ''): ?>
            <div class="success"><?= e($success) ?></div>
        <?php endif; ?>

        <?php if (empty($products) || empty($suppliers)): ?>

            <div class="error">
                Tambahkan produk dan supplier terlebih dahulu sebelum
                menghubungkan keduanya.
            </div>

        <?php else: ?>

            <form method="POST" action="product.php">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e($_SESSION['csrf_token']) ?>"
                >

                <label for="product_id">Pilih Produk</label>
                <select id="product_id" name="product_id" required>
                    <option value="">-- Pilih Produk --</option>

                    <?php foreach ($products as $product): ?>
                        <option value="<?= (int) $product['id'] ?>">
                            <?= e($product['sku']) ?> -
                            <?= e($product['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="supplier_id">Pilih Supplier</label>
                <select id="supplier_id" name="supplier_id" required>
                    <option value="">-- Pilih Supplier --</option>

                    <?php foreach ($suppliers as $supplier): ?>
                        <option value="<?= (int) $supplier['id'] ?>">
                            <?= e($supplier['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="supplier_price">Harga Beli dari Supplier (Rp)</label>
                <input
                    type="number"
                    id="supplier_price"
                    name="supplier_price"
                    min="0"
                    max="9999999999.99"
                    step="0.01"
                    required
                >

                <button type="submit">Simpan Hubungan</button>
            </form>

        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Daftar Supplier dan Produk</h2>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>No.</th>
                        <th>SKU</th>
                        <th>Nama Produk</th>
                        <th>Supplier</th>
                        <th>Harga Beli (Rp)</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (empty($links)): ?>

                    <tr>
                        <td colspan="5">
                            Belum ada hubungan supplier dan produk.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($links as $i => $link): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><?= e($link['sku']) ?></td>
                            <td><?= e($link['product_name']) ?></td>
                            <td><?= e($link['supplier_name']) ?></td>
                            <td>
                                Rp <?= number_format(
                                    (float) $link['supplier_price'],
                                    0,
                                    ',',
                                    '.'
                                ) ?>
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
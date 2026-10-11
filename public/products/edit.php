<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/escape.php';

requireRole(['admin']);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Validasi ID produk dari URL.
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id || $id < 1) {
    header('Location: index.php?error=invalid');
    exit;
}

// Ambil data produk.
$stmt = $pdo->prepare(
    "SELECT id, category_id, sku, name, price, stock, minimum_stock
     FROM products
     WHERE id = ?"
);
$stmt->execute([$id]);
$product = $stmt->fetch();

if (!$product) {
    http_response_code(404);
    exit('Produk tidak ditemukan.');
}

$errors = [];

$sku = (string) $product['sku'];
$name = (string) $product['name'];
$categoryId = (string) $product['category_id'];
$price = (string) $product['price'];
$stock = (string) $product['stock'];
$minimumStock = (string) $product['minimum_stock'];

// Ambil kategori yang tersedia.
$stmt = $pdo->query(
    "SELECT id, name FROM categories ORDER BY name ASC"
);
$categories = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validasi CSRF.
    $csrfToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($csrfToken) ||
        !hash_equals($_SESSION['csrf_token'], $csrfToken)
    ) {
        http_response_code(403);
        exit('Permintaan tidak valid. Silakan muat ulang halaman.');
    }

    // Pastikan input berupa teks, bukan array.
    $inputs = [
        'sku' => $_POST['sku'] ?? '',
        'name' => $_POST['name'] ?? '',
        'category_id' => $_POST['category_id'] ?? '',
        'price' => $_POST['price'] ?? '',
        'stock' => $_POST['stock'] ?? '',
        'minimum_stock' => $_POST['minimum_stock'] ?? ''
    ];

    foreach ($inputs as $field => $value) {
        if (!is_string($value)) {
            $errors[$field] = 'Format input tidak valid.';
        }
    }

    if (!$errors) {
        $sku = trim($inputs['sku']);
        $name = trim($inputs['name']);
        $categoryId = trim($inputs['category_id']);
        $price = trim($inputs['price']);
        $stock = trim($inputs['stock']);
        $minimumStock = trim($inputs['minimum_stock']);

        // Validasi kolom wajib.
        if ($sku === '') {
            $errors['sku'] = 'SKU wajib diisi.';
        } elseif (strlen($sku) > 50) {
            $errors['sku'] = 'SKU maksimal 50 karakter.';
        }

        if ($name === '') {
            $errors['name'] = 'Nama produk wajib diisi.';
        } elseif (strlen($name) > 150) {
            $errors['name'] = 'Nama produk maksimal 150 karakter.';
        }

        // Validasi harga.
        if (
            $price === '' ||
            !is_numeric($price) ||
            !is_finite((float) $price) ||
            (float) $price < 0 ||
            (float) $price > 9999999999.99
        ) {
            $errors['price'] = 'Harga harus berupa angka valid dan tidak negatif.';
        }

        // Validasi stok yang dikirim harus sama dengan stok database.
        $stockNumber = filter_var($stock, FILTER_VALIDATE_INT);

        if (
            $stockNumber === false ||
            $stockNumber < 0 ||
            $stockNumber !== (int) $product['stock']
        ) {
            $errors['stock'] =
                'Stok tidak boleh diubah di halaman edit. Gunakan transaksi stok.';
        }

        // Validasi minimum stok.
        $minimumNumber = filter_var(
            $minimumStock,
            FILTER_VALIDATE_INT
        );

        if ($minimumNumber === false || $minimumNumber < 0) {
            $errors['minimum_stock'] =
                'Stok minimum harus berupa bilangan bulat nol atau lebih.';
        }

        // Validasi kategori.
        $validCategory = false;

        if (
            $categoryId !== '' &&
            ctype_digit($categoryId) &&
            (int) $categoryId > 0
        ) {
            $stmt = $pdo->prepare(
                "SELECT id FROM categories WHERE id = ?"
            );
            $stmt->execute([(int) $categoryId]);
            $validCategory = (bool) $stmt->fetch();
        }

        if (!$validCategory) {
            $errors['category_id'] = 'Pilih kategori yang tersedia.';
        }

        // Periksa SKU yang digunakan produk lain.
        if ($sku !== '' && strlen($sku) <= 50) {
            $stmt = $pdo->prepare(
                "SELECT id
                 FROM products
                 WHERE sku = ? AND id <> ?
                 LIMIT 1"
            );
            $stmt->execute([$sku, $id]);

            if ($stmt->fetch()) {
                $errors['sku'] = 'SKU sudah digunakan produk lain.';
            }
        }
    }

    // Simpan perubahan jika seluruh validasi lolos.
    if (!$errors) {
        try {
            $pdo->beginTransaction();

            // Kunci baris produk selama proses pembaruan.
            $stmt = $pdo->prepare(
                "SELECT id, stock
                 FROM products
                 WHERE id = ?
                 FOR UPDATE"
            );
            $stmt->execute([$id]);
            $currentProduct = $stmt->fetch();

            if (!$currentProduct) {
                $pdo->rollBack();
                header('Location: index.php?error=notfound');
                exit;
            }

            // Pastikan stok tidak berubah sejak formulir dibuka.
            if ((int) $currentProduct['stock'] !== (int) $stock) {
                $pdo->rollBack();

                $errors['stock'] =
                    'Stok telah berubah. Muat ulang halaman dan coba lagi.';
            } else {
                $stmt = $pdo->prepare(
                    "UPDATE products
                     SET category_id = ?,
                         sku = ?,
                         name = ?,
                         price = ?,
                         minimum_stock = ?
                     WHERE id = ?"
                );

                $stmt->execute([
                    (int) $categoryId,
                    $sku,
                    $name,
                    $price,
                    (int) $minimumStock,
                    $id
                ]);

                $pdo->commit();

                header('Location: index.php?success=updated');
                exit;
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
                $errors['sku'] =
                    'SKU sudah digunakan produk lain.';
            } else {
                $errors['general'] =
                    'Gagal memperbarui produk. Silakan coba lagi.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Edit Produk | Inventory Management</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 25px 15px;
            font-family: Arial, sans-serif;
            background: #f1f5f2;
            color: #26332d;
        }

        .container {
            max-width: 700px;
            margin: 20px auto;
            padding: 30px;
            background: white;
            border-radius: 14px;
            box-shadow: 0 4px 20px rgba(0,0,0,.05);
        }

        h1 {
            margin-top: 0;
        }

        .description {
            color: #718078;
            margin-bottom: 25px;
        }

        label {
            display: block;
            margin: 18px 0 8px;
            font-weight: bold;
            font-size: 14px;
        }

        input, select {
            width: 100%;
            padding: 12px;
            border: 1px solid #d5ded9;
            border-radius: 8px;
            font-size: 15px;
        }

        input:focus, select:focus {
            outline: 2px solid #9bd5bd;
            border-color: #176b50;
        }

        input[readonly] {
            background: #f1f5f2;
            color: #65736b;
        }

        button, .back {
            display: inline-block;
            margin-top: 22px;
            padding: 12px 18px;
            border: none;
            border-radius: 8px;
            background: #176b50;
            color: white;
            font-size: 14px;
            text-decoration: none;
            cursor: pointer;
        }

        .back {
            background: #65736b;
        }

        .error {
            margin-top: 7px;
            color: #b93838;
            font-size: 13px;
        }

        .alert {
            padding: 12px;
            background: #fff0ef;
            color: #a52c24;
            border-radius: 8px;
        }

        .hint {
            color: #718078;
            font-size: 13px;
            line-height: 1.5;
        }

        @media (max-width: 500px) {
            .container {
                padding: 20px;
            }

            button, .back {
                text-align: center;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <h1>Edit Produk</h1>

    <p class="description">
        Perbarui informasi produk inventaris.
    </p>

    <?php if (isset($errors['general'])): ?>
        <div class="alert"><?= e($errors['general']) ?></div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="alert">
            Periksa kembali data yang kamu masukkan.
        </div>
    <?php endif; ?>

    <form method="POST">
        <input
            type="hidden"
            name="csrf_token"
            value="<?= e($_SESSION['csrf_token']) ?>"
        >

        <label for="sku">SKU / Kode Produk</label>
        <input
            type="text"
            id="sku"
            name="sku"
            maxlength="50"
            value="<?= e($sku) ?>"
            required
        >
        <?php if (isset($errors['sku'])): ?>
            <div class="error"><?= e($errors['sku']) ?></div>
        <?php endif; ?>

        <label for="name">Nama Produk</label>
        <input
            type="text"
            id="name"
            name="name"
            maxlength="150"
            value="<?= e($name) ?>"
            required
        >
        <?php if (isset($errors['name'])): ?>
            <div class="error"><?= e($errors['name']) ?></div>
        <?php endif; ?>

        <label for="category_id">Kategori</label>
        <select id="category_id" name="category_id" required>
            <option value="">-- Pilih Kategori --</option>

            <?php foreach ($categories as $category): ?>
                <option
                    value="<?= (int) $category['id'] ?>"
                    <?= (string) $categoryId === (string) $category['id']
                        ? 'selected'
                        : '' ?>
                >
                    <?= e($category['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php if (isset($errors['category_id'])): ?>
            <div class="error"><?= e($errors['category_id']) ?></div>
        <?php endif; ?>

        <label for="price">Harga (Rp)</label>
        <input
            type="number"
            id="price"
            name="price"
            min="0"
            max="9999999999.99"
            step="0.01"
            value="<?= e($price) ?>"
            required
        >
        <?php if (isset($errors['price'])): ?>
            <div class="error"><?= e($errors['price']) ?></div>
        <?php endif; ?>

        <label for="stock">Stok Saat Ini</label>
        <input
            type="number"
            id="stock"
            name="stock"
            value="<?= e($stock) ?>"
            readonly
        >
        <p class="hint">
            Stok hanya dapat diubah melalui transaksi barang masuk
            atau barang keluar agar riwayat inventaris tetap akurat.
        </p>
        <?php if (isset($errors['stock'])): ?>
            <div class="error"><?= e($errors['stock']) ?></div>
        <?php endif; ?>

        <label for="minimum_stock">Batas Minimum Stok</label>
        <input
            type="number"
            id="minimum_stock"
            name="minimum_stock"
            min="0"
            step="1"
            value="<?= e($minimumStock) ?>"
            required
        >
        <?php if (isset($errors['minimum_stock'])): ?>
            <div class="error"><?= e($errors['minimum_stock']) ?></div>
        <?php endif; ?>

        <button type="submit">Simpan Perubahan</button>
        <a class="back" href="index.php">Kembali</a>
    </form>
</div>
</body>
</html>

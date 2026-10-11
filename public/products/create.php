```php
<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/escape.php';
require_once __DIR__ . '/../../helpers/validation.php';

requireRole(['admin']);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$errors = [];
$sku = '';
$name = '';
$categoryId = '';
$price = '';
$stock = '0';
$minimumStock = '5';

$stmt = $pdo->query(
    "SELECT id, name FROM categories ORDER BY name ASC"
);
$categories = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($csrfToken) ||
        !hash_equals($_SESSION['csrf_token'], $csrfToken)
    ) {
        http_response_code(403);
        exit('Permintaan tidak valid. Silakan muat ulang halaman.');
    }

    $skuInput = $_POST['sku'] ?? '';
    $nameInput = $_POST['name'] ?? '';
    $categoryInput = $_POST['category_id'] ?? '';
    $priceInput = $_POST['price'] ?? '';
    $stockInput = $_POST['stock'] ?? '';
    $minimumInput = $_POST['minimum_stock'] ?? '';

    $sku = is_string($skuInput) ? trim($skuInput) : '';
    $name = is_string($nameInput) ? trim($nameInput) : '';
    $categoryId = is_string($categoryInput) ? $categoryInput : '';
    $price = is_string($priceInput) ? trim($priceInput) : '';
    $stock = is_string($stockInput) ? trim($stockInput) : '';
    $minimumStock = is_string($minimumInput) ? trim($minimumInput) : '';

    if ($sku === '' || strlen($sku) > 50) {
        $errors['sku'] = 'SKU wajib diisi dan maksimal 50 karakter.';
    }

    if ($name === '' || strlen($name) > 150) {
        $errors['name'] = 'Nama produk wajib diisi dan maksimal 150 karakter.';
    }

    if (
        $price === '' ||
        !is_numeric($price) ||
        !is_finite((float) $price) ||
        (float) $price < 0 ||
        (float) $price > 9999999999.99
    ) {
        $errors['price'] = 'Harga harus berupa angka yang valid dan tidak negatif.';
    }

    foreach (
        ['stock' => $stock, 'minimum_stock' => $minimumStock]
        as $field => $value
    ) {
        $number = filter_var($value, FILTER_VALIDATE_INT);

        if ($number === false || $number < 0) {
            $errors[$field] = 'Nilai harus berupa bilangan bulat nol atau lebih.';
        }
    }

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
        $errors['category_id'] = 'Silakan pilih kategori yang tersedia.';
    }

    if ($sku !== '' && strlen($sku) <= 50) {
        $stmt = $pdo->prepare(
            "SELECT id FROM products WHERE sku = ? LIMIT 1"
        );
        $stmt->execute([$sku]);

        if ($stmt->fetch()) {
            $errors['sku'] = 'SKU sudah digunakan. Gunakan SKU lain.';
        }
    }

    if (!$errors) {
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO products
                    (category_id, sku, name, price, stock, minimum_stock)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );

            $stmt->execute([
                (int) $categoryId,
                $sku,
                $name,
                $price,
                (int) $stock,
                (int) $minimumStock
            ]);

            header('Location: index.php?success=created');
            exit;
        } catch (PDOException $e) {
            error_log($e->getMessage());

            if ($e->getCode() === '23000') {
                $errors['sku'] = 'SKU sudah digunakan. Gunakan SKU lain.';
            } else {
                $errors['general'] = 'Produk gagal disimpan. Silakan coba kembali.';
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
    <title>Tambah Produk | Inventory Management</title>

    <style>
        * { box-sizing: border-box; }

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

        h1 { margin-top: 0; }

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

        .error {
            color: #a52c24;
            font-size: 13px;
            margin-top: 6px;
        }

        .alert {
            padding: 13px;
            margin-bottom: 15px;
            background: #fff0ef;
            color: #a52c24;
            border-radius: 8px;
        }

        .actions {
            display: flex;
            gap: 10px;
            margin-top: 25px;
            flex-wrap: wrap;
        }

        button, .back {
            padding: 12px 18px;
            border: none;
            border-radius: 8px;
            background: #176b50;
            color: white;
            font-size: 14px;
            text-decoration: none;
            cursor: pointer;
        }

        .back { background: #65736b; }

        @media (max-width: 500px) {
            .container { padding: 20px; }
            .actions > * {
                flex: 1;
                text-align: center;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <h1>Tambah Produk</h1>
    <p class="description">
        Masukkan informasi produk inventaris baru.
    </p>

    <?php if ($errors): ?>
        <div class="alert">
            Periksa kembali data yang kamu masukkan.
            <?php if (isset($errors['general'])): ?>
                <p><?= e($errors['general']) ?></p>
            <?php endif; ?>
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
                    <?= (string) $categoryId === (string) $category['id'] ? 'selected' : '' ?>
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

        <label for="stock">Stok Awal</label>
        <input
            type="number"
            id="stock"
            name="stock"
            min="0"
            step="1"
            value="<?= e($stock) ?>"
            required
        >
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

        <div class="actions">
            <button type="submit">Simpan Produk</button>
            <a class="back" href="index.php">Kembali</a>
        </div>
    </form>
</div>
</body>
</html>
```
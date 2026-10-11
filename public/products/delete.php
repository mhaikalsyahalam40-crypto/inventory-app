<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/auth.php';

requireRole(['admin']);

// Hanya menerima permintaan POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

// Periksa ID produk
$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

if (!$id || $id < 1) {
    header('Location: index.php?error=invalid');
    exit;
}

// Periksa token keamanan CSRF
if (
    empty($_SESSION['csrf_token']) ||
    !isset($_POST['csrf_token']) ||
    !is_string($_POST['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
) {
    http_response_code(403);
    exit('Permintaan tidak valid. Silakan kembali ke halaman produk.');
}

try {
    $pdo->beginTransaction();

    // Periksa apakah produk tersedia
    $stmt = $pdo->prepare(
        "SELECT id FROM products WHERE id = ? FOR UPDATE"
    );
    $stmt->execute([$id]);

    if (!$stmt->fetch()) {
        $pdo->rollBack();
        header('Location: index.php?error=notfound');
        exit;
    }

    // Produk yang memiliki riwayat transaksi tidak boleh dihapus
    $stmt = $pdo->prepare(
        "SELECT id FROM stock_movements
         WHERE product_id = ?
         LIMIT 1"
    );
    $stmt->execute([$id]);

    if ($stmt->fetch()) {
        $pdo->rollBack();
        header('Location: index.php?error=has_history');
        exit;
    }

    // Hapus hubungan produk dengan supplier terlebih dahulu
    $stmt = $pdo->prepare(
        "DELETE FROM product_suppliers WHERE product_id = ?"
    );
    $stmt->execute([$id]);

    // Hapus produk
    $stmt = $pdo->prepare(
        "DELETE FROM products WHERE id = ?"
    );
    $stmt->execute([$id]);

    $pdo->commit();

    header('Location: index.php?success=deleted');
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log($e->getMessage());

    header('Location: index.php?error=delete_failed');
    exit;
}

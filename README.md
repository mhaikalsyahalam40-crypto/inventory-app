# Inventory App

## Deskripsi
Inventory App adalah aplikasi berbasis web untuk membantu pengelolaan persediaan barang. Aplikasi ini menggunakan PHP dan MySQL serta dijalankan melalui XAMPP.

## Fitur Utama
- Login dan logout pengguna.
- Hak akses berdasarkan peran Admin dan Staff.
- Pengelolaan data produk.
- Pengelolaan kategori barang.
- Pengelolaan supplier.
- Pencatatan transaksi barang masuk dan barang keluar.
- Riwayat transaksi stok.
- Pencarian produk.
- Validasi stok agar barang keluar tidak melebihi stok tersedia.
- Pembatasan penghapusan produk yang memiliki riwayat transaksi.
- Pengelolaan akun pengguna khusus Admin.

## Teknologi
- PHP
- MySQL
- HTML
- CSS
- XAMPP
- phpMyAdmin

## Cara Menjalankan
1. Instal dan buka XAMPP.
2. Aktifkan Apache dan MySQL.
3. Letakkan folder proyek di dalam direktori htdocs.
4. Buat database bernama inventory_db melalui phpMyAdmin.
5. Impor file SQL database jika tersedia.
6. Sesuaikan konfigurasi koneksi database pada config/database.php.
7. Buka aplikasi melalui http://localhost/inventory-app/public/login.php.

## Akun Pengujian
Akun Admin dan Staff digunakan untuk menguji fitur serta pembatasan hak akses. Gunakan akun yang telah dibuat di database.

## Pengujian
Pengujian yang telah dilakukan meliputi login, pembatasan hak akses, pencarian dan pengeditan produk, pengelolaan kategori dan supplier, serta pencatatan dan validasi transaksi stok.
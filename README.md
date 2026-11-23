# 🛍️ TokoKita — Sistem Informasi Penjualan Toko

Aplikasi web untuk mengelola penjualan toko, data produk, pelanggan, dan riwayat aktivitas. Proyek ini dibuat menggunakan PHP, MariaDB/MySQL, dan CSS.

## ✨ Fitur

- 📊 **Dashboard** untuk melihat ringkasan penjualan, transaksi, produk, dan pelanggan.
- 🧾 **Transaksi** yang memproses penjualan serta memperbarui stok produk.
- 📦 **Produk dan pelanggan** dengan fitur tambah, ubah, hapus, pencarian, dan filter.
- 🕒 **Aktivitas** untuk melihat catatan perubahan data.
- 🔐 **Login dan logout** untuk membatasi akses aplikasi. Akun admin pertama dibuat melalui halaman login.
- 🌙 Tema antarmuka gelap bernuansa navy.

## 🧰 Teknologi dan kebutuhan

- 🐘 PHP 8.0 atau lebih baru dengan ekstensi `PDO MySQL` dan `mbstring`.
- 🗄️ MariaDB 10.4 atau lebih baru (atau MySQL yang kompatibel).
- 🖥️ XAMPP atau server web PHP lain.

## 🚀 Menjalankan dengan XAMPP

1. 📁 Salin folder proyek ke `C:\xampp\htdocs\tokoku`.
2. ▶️ Jalankan **Apache** dan **MySQL** dari XAMPP Control Panel.
3. 🌐 Buka phpMyAdmin di `http://localhost/phpmyadmin`.
4. 📥 Pilih tab **Import**, unggah file `db_tokoku_lengkap.sql`, lalu jalankan impor. Skrip akan membuat database `db_tokoku` beserta tabel, data contoh, fungsi, prosedur, trigger, dan view.
5. ⚙️ Pastikan pengaturan koneksi di `auth.php` sesuai konfigurasi MySQL lokal:

   ```php
   'mysql:host=127.0.0.1;dbname=db_tokoku;charset=utf8mb4',
   'root',
   '',
   ```

   Nilai di atas adalah konfigurasi bawaan XAMPP. Ubah host, username, dan password jika server Anda menggunakan kredensial berbeda.

6. 🔑 Buka `http://localhost/tokoku/login.php`.
7. Jika belum ada akun, halaman akan meminta Anda membuat akun admin pertama. Gunakan username 3–50 karakter dan kata sandi 10–72 karakter.
8. Setelah akun dibuat, aplikasi akan mengarahkan Anda ke Dashboard. Pada kunjungan berikutnya, masuk menggunakan akun tersebut.

> 🔒 Kata sandi disimpan sebagai hash. Jangan membagikan kredensial akun admin.

## 🧭 Halaman aplikasi

| Halaman | URL |
|---|---|
| 🔐 Login | `http://localhost/tokoku/login.php` |
| 📊 Dashboard | `http://localhost/tokoku/?page=dashboard` |
| 🧾 Transaksi | `http://localhost/tokoku/?page=transactions` |
| 📦 Produk | `http://localhost/tokoku/?page=products` |
| 👥 Pelanggan | `http://localhost/tokoku/?page=customers` |
| 🕒 Aktivitas | `http://localhost/tokoku/?page=activity` |

Halaman selain login memerlukan sesi yang sudah terautentikasi. Gunakan tombol **Logout** di bagian atas aplikasi untuk mengakhiri sesi.

## 🗃️ Struktur database

- 👥 `tbl_pelanggan` — data pelanggan.
- 🏷️ `tbl_kategori` — kategori produk.
- 📦 `tbl_produk` — data produk dan stok.
- 🧾 `tbl_transaksi` — data transaksi.
- 📋 `tbl_detail_transaksi` — rincian produk dalam transaksi.
- 🕵️ `tbl_log` — catatan aktivitas yang dibuat oleh trigger.
- 👤 `tbl_pengguna` — akun admin, dibuat otomatis saat halaman login dibuka.

Objek database untuk kebutuhan proyek:

- 👓 View `vw_laporan_penjualan` dan `vw_detail_transaksi`.
- ⚙️ Stored procedure `sp_tambah_transaksi`.
- 🧮 Function `fn_hitung_diskon` dan `fn_hitung_total`.
- 🪝 Trigger untuk mencatat perubahan data transaksi, produk, dan pelanggan ke `tbl_log`.
- ⚡ Index untuk mendukung pencarian dan query laporan.

## 🧪 Pengujian database

File `db_tokoku_lengkap.sql` juga menyertakan query demonstrasi di bagian akhir untuk melihat hasil view dan JOIN, analisis subquery, menguji function, menjalankan `EXPLAIN`, memanggil stored procedure, serta contoh `COMMIT` dan `ROLLBACK`. Jalankan query uji secara terpisah di phpMyAdmin atau klien MariaDB.

Contoh pemanggilan prosedur:

```sql
CALL sp_tambah_transaksi(1, 1, 1);
```

Argumen prosedur tersebut adalah ID pelanggan, ID produk, dan jumlah produk. Sesuaikan ID dengan data yang tersedia. Prosedur menangani satu produk per pemanggilan dan mencatat perubahan melalui trigger.

## 📂 File utama

- 🖥️ `index.php` — halaman utama, navigasi, dan fitur pengelolaan.
- 🔑 `login.php`, `logout.php`, `auth.php` — autentikasi dan koneksi database.
- 🎨 `style.css` — tampilan aplikasi.
- 🗄️ `db_tokoku_lengkap.sql` — skema database, data contoh, serta objek dan query demonstrasi.

<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
requireAuth();
requireAdmin();

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function rupiah(int|float|string|null $value): string
{
    return 'Rp ' . number_format((float) $value, 0, ',', '.');
}

function parseHarga(string $value): ?float
{
    // Menerima format Indonesia: 6.000 | 6.000,50 | 6.000.00 | 6000 | Rp 6.000
    $value = trim((string) preg_replace('/^Rp\.?\s*/i', '', trim($value)));

    if (preg_match('/^\d{1,3}(\.\d{3})+(,\d{1,2})?$/', $value)) {
        $value = str_replace(['.', ','], ['', '.'], $value);
    } elseif (preg_match('/^\d{1,3}(\.\d{3})+\.\d{1,2}$/', $value)) {
        $position = (int) strrpos($value, '.');
        $value = str_replace('.', '', substr($value, 0, $position)) . substr($value, $position);
    } elseif (preg_match('/^\d+,\d{1,2}$/', $value)) {
        $value = str_replace(',', '.', $value);
    } elseif (!preg_match('/^\d+(\.\d{1,2})?$/', $value)) {
        return null;
    }

    return (float) $value;
}

function saveProductImage(array $file): ?string
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Foto produk gagal diunggah. Pilih ulang file gambar.');
    }
    if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new RuntimeException('Ukuran foto maksimal 5 MB.');
    }

    $fileInfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $fileInfo->file((string) $file['tmp_name']);
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    if (!isset($extensions[$mimeType]) || !getimagesize((string) $file['tmp_name'])) {
        throw new RuntimeException('Format foto harus JPG, PNG, atau WEBP.');
    }

    $uploadDirectory = __DIR__ . '/uploads/products';
    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
        throw new RuntimeException('Folder penyimpanan foto tidak dapat dibuat.');
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mimeType];
    if (!move_uploaded_file((string) $file['tmp_name'], $uploadDirectory . '/' . $filename)) {
        throw new RuntimeException('Foto produk tidak dapat disimpan.');
    }

    return 'uploads/products/' . $filename;
}

function deleteProductImage(?string $imagePath): void
{
    if (!$imagePath || !str_starts_with($imagePath, 'uploads/products/')) {
        return;
    }

    $filename = basename($imagePath);
    $absolutePath = __DIR__ . '/uploads/products/' . $filename;
    if (is_file($absolutePath)) {
        unlink($absolutePath);
    }
}

function savePaymentQrImage(array $file): ?string
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $uploadError = match ($file['error']) {
            UPLOAD_ERR_INI_SIZE => 'Gambar QRIS melebihi batas upload server.',
            UPLOAD_ERR_FORM_SIZE => 'Gambar QRIS melebihi batas 5 MB.',
            UPLOAD_ERR_PARTIAL => 'Upload gambar QRIS tidak lengkap. Coba pilih ulang file.',
            UPLOAD_ERR_NO_TMP_DIR => 'Folder sementara upload PHP tidak tersedia.',
            UPLOAD_ERR_CANT_WRITE => 'Server tidak dapat menulis file QRIS ke penyimpanan.',
            UPLOAD_ERR_EXTENSION => 'Upload gambar QRIS dihentikan oleh konfigurasi PHP.',
            default => 'Gambar QRIS gagal diunggah. Pilih ulang file gambar.',
        };
        throw new RuntimeException($uploadError);
    }
    if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new RuntimeException('Ukuran gambar QRIS maksimal 5 MB.');
    }

    $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($extensions[$mimeType]) || !getimagesize((string) $file['tmp_name'])) {
        throw new RuntimeException('Gambar QRIS harus berformat JPG, PNG, atau WEBP.');
    }

    $directory = __DIR__ . '/uploads/payments';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Folder gambar pembayaran tidak dapat dibuat.');
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mimeType];
    if (!move_uploaded_file((string) $file['tmp_name'], $directory . '/' . $filename)) {
        throw new RuntimeException('Gambar QRIS tidak dapat disimpan.');
    }

    return 'uploads/payments/' . $filename;
}

function deletePaymentQrImage(?string $imagePath): void
{
    if (!$imagePath || !str_starts_with($imagePath, 'uploads/payments/')) {
        return;
    }

    $path = __DIR__ . '/uploads/payments/' . basename($imagePath);
    if (is_file($path)) {
        unlink($path);
    }
}

function gambarFallbackProduk(string $name, string $category): string
{
    $search = mb_strtolower($name . ' ' . $category, 'UTF-8');
    $images = [
        ['keywords' => ['kursi', 'chair'], 'photo' => 'photo-1503602642458-232111445657'],
        ['keywords' => ['meja', 'table'], 'photo' => 'photo-1533090481720-856c6e3c1fdc'],
        ['keywords' => ['mouse'], 'photo' => 'photo-1527814050087-3793815479db'],
        ['keywords' => ['keyboard'], 'photo' => 'photo-1587829741301-dc798b83add3'],
        ['keywords' => ['flashdisk', 'penyimpanan', 'storage'], 'photo' => 'photo-1597872200969-2b65d56bd16b'],
        ['keywords' => ['aksesori', 'periferal', 'elektronik'], 'photo' => 'photo-1498049794561-7780e7231661'],
    ];

    foreach ($images as $image) {
        foreach ($image['keywords'] as $keyword) {
            if (str_contains($search, $keyword)) {
                return 'https://images.unsplash.com/' . $image['photo'] . '?auto=format&fit=crop&w=240&q=80';
            }
        }
    }

    return 'https://images.unsplash.com/photo-1498050108023-c5249f4df085?auto=format&fit=crop&w=240&q=80';
}

function tanggalIndonesia(string $value, bool $withDay = false): string
{
    $timestamp = strtotime($value);
    $days = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    $months = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $date = date('d', $timestamp) . ' ' . $months[(int) date('n', $timestamp) - 1] . ' ' . date('Y', $timestamp);

    return $withDay ? $days[(int) date('w', $timestamp)] . ', ' . $date : $date;
}

$notice = $_SESSION['notice'] ?? null;
unset($_SESSION['notice']);
$pageError = null;
$pdo = null;
$pageTitles = [
    'dashboard' => 'Dashboard',
    'transactions' => 'Transaksi',
    'products' => 'Produk',
    'customers' => 'Pelanggan',
    'activity' => 'Aktivitas',
    'reports' => 'Laporan',
    'payments' => 'Pembayaran',
    'users' => 'Pengguna',
];
$page = (string) ($_GET['page'] ?? 'dashboard');
if (!isset($pageTitles[$page])) {
    $page = 'dashboard';
}
$stats = ['sales' => 0, 'transactions' => 0, 'products' => 0, 'customers' => 0];
$activityToday = 0;
$repeatCustomers = 0;
$transactions = $products = $customers = $categories = $logs = $monthlySales = [];
$customerHistories = $customerHistoryItems = [];
$paymentSettings = ['qris_image' => null, 'nama_bank' => null, 'nomor_rekening' => null, 'nama_pemilik' => null];

try {
    $pdo = tokokuPdo();
    ensureUserSchema($pdo);
    ensureProductImageSchema($pdo);
    ensureProductBarcodeSchema($pdo);
    ensurePaymentSettingsSchema($pdo);
    $paymentSettings = $pdo->query(
        'SELECT qris_image, nama_bank, nomor_rekening, nama_pemilik
         FROM tbl_pengaturan_pembayaran WHERE id = 1'
    )->fetch() ?: $paymentSettings;

    $stats['sales'] = $pdo->query(
        'SELECT COALESCE(SUM(total_belanja), 0) FROM tbl_transaksi
         WHERE YEAR(tanggal) = YEAR(CURDATE()) AND MONTH(tanggal) = MONTH(CURDATE())'
    )->fetchColumn();
    $stats['transactions'] = $pdo->query(
        'SELECT COUNT(*) FROM tbl_transaksi
         WHERE YEAR(tanggal) = YEAR(CURDATE()) AND MONTH(tanggal) = MONTH(CURDATE())'
    )->fetchColumn();
    $stats['products'] = $pdo->query('SELECT COUNT(*) FROM tbl_produk')->fetchColumn();
    $stats['customers'] = $pdo->query('SELECT COUNT(*) FROM tbl_pelanggan')->fetchColumn();
    $repeatCustomers = (int) $pdo->query(
        'SELECT COUNT(*) FROM (
            SELECT id_pelanggan FROM tbl_transaksi
            GROUP BY id_pelanggan HAVING COUNT(*) > 1
         ) AS pelanggan_kembali'
    )->fetchColumn();

    $transactions = $pdo->query(
        'SELECT id_transaksi, tanggal, nama_pelanggan, subtotal, diskon, total_belanja
         FROM vw_laporan_penjualan ORDER BY tanggal DESC, id_transaksi DESC LIMIT 8'
    )->fetchAll();
    $products = $pdo->query(
        'SELECT pr.id_produk, pr.id_kategori, pr.nama_produk, pr.gambar, pr.barcode, pr.harga, pr.stok, k.nama_kategori
         FROM tbl_produk AS pr
         JOIN tbl_kategori AS k ON k.id_kategori = pr.id_kategori
         ORDER BY pr.nama_produk'
    )->fetchAll();
    $recentlyUpdatedCustomerId = (int) ($_SESSION['recently_updated_customer_id'] ?? 0);
    unset($_SESSION['recently_updated_customer_id']);
    $customerQuery = $pdo->prepare(
        'SELECT p.id_pelanggan, p.nama_pelanggan, p.kota,
            (SELECT COUNT(*) FROM tbl_transaksi AS t
             WHERE t.id_pelanggan = p.id_pelanggan) AS jumlah_transaksi
         FROM tbl_pelanggan AS p
         ORDER BY (p.id_pelanggan = ?) DESC, p.id_pelanggan DESC'
    );
    $customerQuery->execute([$recentlyUpdatedCustomerId]);
    $customers = $customerQuery->fetchAll();
    if ($page === 'customers' && $customers) {
        $historyTransactions = $pdo->query(
            'SELECT id_transaksi, id_pelanggan, tanggal, total_belanja
             FROM (
                SELECT t.id_transaksi, t.id_pelanggan, t.tanggal, t.total_belanja,
                       ROW_NUMBER() OVER (
                           PARTITION BY t.id_pelanggan
                           ORDER BY t.tanggal DESC, t.id_transaksi DESC
                       ) AS urutan
                FROM tbl_transaksi AS t
             ) AS riwayat
             WHERE urutan <= 5
             ORDER BY id_pelanggan, tanggal DESC, id_transaksi DESC'
        )->fetchAll();

        $historyTransactionIds = [];
        foreach ($historyTransactions as $history) {
            $customerHistories[(int) $history['id_pelanggan']][] = $history;
            $historyTransactionIds[] = (int) $history['id_transaksi'];
        }

        if ($historyTransactionIds) {
            $placeholders = implode(',', array_fill(0, count($historyTransactionIds), '?'));
            $historyDetails = $pdo->prepare(
                'SELECT d.id_transaksi, d.jumlah, pr.nama_produk
                 FROM tbl_detail_transaksi AS d
                 JOIN tbl_produk AS pr ON pr.id_produk = d.id_produk
                 WHERE d.id_transaksi IN (' . $placeholders . ')
                 ORDER BY d.id_detail'
            );
            $historyDetails->execute($historyTransactionIds);
            foreach ($historyDetails->fetchAll() as $detail) {
                $customerHistoryItems[(int) $detail['id_transaksi']][] =
                    $detail['nama_produk'] . ' ×' . (int) $detail['jumlah'];
            }
        }
    }
    $categories = $pdo->query(
        'SELECT id_kategori, nama_kategori FROM tbl_kategori ORDER BY nama_kategori'
    )->fetchAll();
    $logs = $pdo->query(
        'SELECT id_log, waktu, nama_tabel, aksi, id_data, keterangan, pengguna
         FROM tbl_log ORDER BY waktu DESC, id_log DESC LIMIT 20'
    )->fetchAll();
    $activityToday = (int) $pdo->query(
        'SELECT COUNT(*) FROM tbl_log WHERE DATE(waktu) = CURDATE()'
    )->fetchColumn();
    $monthlySales = $pdo->query(
        'SELECT DATE_FORMAT(tanggal, "%Y-%m") AS periode,
                DATE_FORMAT(tanggal, "%b") AS bulan,
                COALESCE(SUM(total_belanja), 0) AS total
         FROM tbl_transaksi
         WHERE tanggal >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH)
         GROUP BY YEAR(tanggal), MONTH(tanggal), DATE_FORMAT(tanggal, "%b")
         ORDER BY YEAR(tanggal), MONTH(tanggal)'
    )->fetchAll();
} catch (PDOException $exception) {
    $pageError = 'Database belum dapat diakses. Pastikan MySQL XAMPP aktif dan skrip db_tokoku_lengkap.sql sudah diimpor. Detail: '
        . $exception->getMessage();
}

$monthlyByPeriod = array_column($monthlySales, null, 'periode');
$monthlySales = [];
$currentMonth = new DateTimeImmutable('first day of this month');
for ($monthsAgo = 11; $monthsAgo >= 0; $monthsAgo--) {
    $period = $currentMonth->modify("-{$monthsAgo} months");
    $key = $period->format('Y-m');
    $monthlySales[] = $monthlyByPeriod[$key] ?? [
        'periode' => $key,
        'bulan' => $period->format('M'),
        'total' => 0,
    ];
}
$totalStock = array_sum(array_map(static fn (array $product): int => (int) $product['stok'], $products));
$lowStockCount = count(array_filter($products, static fn (array $product): bool => (int) $product['stok'] <= 5));
$cityCount = count(array_unique(array_filter(array_map(
    static fn (array $customer): string => trim((string) ($customer['kota'] ?? '')),
    $customers
))));
$activityCounts = ['INSERT' => 0, 'UPDATE' => 0, 'DELETE' => 0];
foreach ($logs as $log) {
    if (isset($activityCounts[$log['aksi']])) {
        $activityCounts[$log['aksi']]++;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pendingProductImage = null;
    $pendingPaymentQrImage = null;
    $redirect = in_array($_POST['redirect'] ?? '', ['dashboard', 'transactions', 'products', 'customers', 'activity', 'reports', 'payments', 'users'], true)
        ? $_POST['redirect']
        : 'dashboard';

    try {
        if (!($pdo instanceof PDO)) {
            throw new RuntimeException('Koneksi database belum tersedia.');
        }
        if (!hash_equals($_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Sesi formulir tidak valid. Silakan muat ulang halaman.');
        }

        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'save_payment_settings') {
            $bankName = trim((string) ($_POST['nama_bank'] ?? ''));
            $accountNumber = trim((string) ($_POST['nomor_rekening'] ?? ''));
            $accountHolder = trim((string) ($_POST['nama_pemilik'] ?? ''));
            $bankFieldsProvided = $bankName !== '' || $accountNumber !== '' || $accountHolder !== '';
            if (mb_strlen($bankName) > 100 || mb_strlen($accountNumber) > 50 || mb_strlen($accountHolder) > 100) {
                throw new RuntimeException('Nama bank maksimal 100 karakter, nomor rekening 50, dan nama penerima 100 karakter.');
            }
            if ($bankFieldsProvided && ($bankName === '' || $accountNumber === '' || $accountHolder === '')) {
                throw new RuntimeException('Isi nama bank, nomor rekening, dan nama penerima secara lengkap.');
            }
            if ($accountNumber !== '' && !preg_match('/^[0-9 -]+$/', $accountNumber)) {
                throw new RuntimeException('Nomor rekening hanya boleh berisi angka, spasi, dan tanda hubung.');
            }

            $currentSettings = $pdo->query(
                'SELECT qris_image FROM tbl_pengaturan_pembayaran WHERE id = 1'
            )->fetch() ?: ['qris_image' => null];
            $pendingPaymentQrImage = savePaymentQrImage($_FILES['qris_image'] ?? []);
            $qrisImage = $pendingPaymentQrImage
                ?? (isset($_POST['hapus_qris']) ? null : $currentSettings['qris_image']);

            $statement = $pdo->prepare(
                'UPDATE tbl_pengaturan_pembayaran
                 SET qris_image = ?, nama_bank = ?, nomor_rekening = ?, nama_pemilik = ?
                 WHERE id = 1'
            );
            $statement->execute([
                $qrisImage,
                $bankName !== '' ? $bankName : null,
                $accountNumber !== '' ? $accountNumber : null,
                $accountHolder !== '' ? $accountHolder : null,
            ]);
            $pendingPaymentQrImage = null;
            if ($qrisImage !== $currentSettings['qris_image']) {
                deletePaymentQrImage($currentSettings['qris_image']);
            }
            $_SESSION['notice'] = ['type' => 'success', 'text' => 'Pengaturan pembayaran berhasil disimpan.'];
        } elseif ($action === 'add_sale') {
            $customerId = filter_var($_POST['id_pelanggan'] ?? null, FILTER_VALIDATE_INT);
            $productId = filter_var($_POST['id_produk'] ?? null, FILTER_VALIDATE_INT);
            $quantity = filter_var($_POST['jumlah'] ?? null, FILTER_VALIDATE_INT);
            if (!$customerId || !$productId || !$quantity || $quantity < 1) {
                throw new RuntimeException('Pelanggan, produk, dan jumlah wajib diisi dengan benar.');
            }

            $statement = $pdo->prepare('CALL sp_tambah_transaksi(?, ?, ?)');
            $statement->execute([$customerId, $productId, $quantity]);
            $result = $statement->fetch();
            $statement->closeCursor();
            $_SESSION['notice'] = [
                'type' => 'success',
                'text' => 'Transaksi #' . ($result['id_transaksi'] ?? '') . ' berhasil disimpan.',
            ];
        } elseif (in_array($action, ['create_customer', 'update_customer'], true)) {
            $name = trim((string) ($_POST['nama_pelanggan'] ?? ''));
            $city = trim((string) ($_POST['kota'] ?? ''));
            if ($name === '' || mb_strlen($name) > 100 || mb_strlen($city) > 50) {
                throw new RuntimeException('Nama wajib diisi (maksimal 100 karakter) dan kota maksimal 50 karakter.');
            }

            if ($action === 'create_customer') {
                $statement = $pdo->prepare('INSERT INTO tbl_pelanggan (nama_pelanggan, kota) VALUES (?, ?)');
                $statement->execute([$name, $city !== '' ? $city : null]);
                $_SESSION['notice'] = ['type' => 'success', 'text' => 'Pelanggan berhasil ditambahkan.'];
            } else {
                $customerId = filter_var($_POST['id_pelanggan'] ?? null, FILTER_VALIDATE_INT);
                if (!$customerId) {
                    throw new RuntimeException('Pelanggan yang akan diubah tidak valid.');
                }
                $statement = $pdo->prepare('UPDATE tbl_pelanggan SET nama_pelanggan = ?, kota = ? WHERE id_pelanggan = ?');
                $statement->execute([$name, $city !== '' ? $city : null, $customerId]);
                $_SESSION['recently_updated_customer_id'] = $customerId;
                $_SESSION['notice'] = ['type' => 'success', 'text' => 'Data pelanggan berhasil diperbarui.'];
            }
        } elseif ($action === 'delete_customer') {
            $customerId = filter_var($_POST['id_pelanggan'] ?? null, FILTER_VALIDATE_INT);
            if (!$customerId) {
                throw new RuntimeException('Pelanggan yang akan dihapus tidak valid.');
            }

            $transactionCount = $pdo->prepare(
                'SELECT COUNT(*) FROM tbl_transaksi WHERE id_pelanggan = ?'
            );
            $transactionCount->execute([$customerId]);
            $existingTransactions = (int) $transactionCount->fetchColumn();
            if ($existingTransactions > 0) {
                throw new RuntimeException(
                    'Pelanggan memiliki ' . number_format($existingTransactions, 0, ',', '.')
                    . ' transaksi dan tidak dapat dihapus agar riwayat penjualan tetap utuh.'
                );
            }

            $statement = $pdo->prepare('DELETE FROM tbl_pelanggan WHERE id_pelanggan = ?');
            $statement->execute([$customerId]);
            if ($statement->rowCount() === 0) {
                throw new RuntimeException('Pelanggan tidak ditemukan atau sudah dihapus.');
            }
            $_SESSION['notice'] = ['type' => 'success', 'text' => 'Pelanggan berhasil dihapus.'];
        } elseif (in_array($action, ['create_product', 'update_product'], true)) {
            $name = trim((string) ($_POST['nama_produk'] ?? ''));
            $categoryId = filter_var($_POST['id_kategori'] ?? null, FILTER_VALIDATE_INT);
            $barcode = trim((string) ($_POST['barcode'] ?? ''));
            $barcode = mb_strlen($barcode) > 100 ? '' : $barcode;
            $price = parseHarga((string) ($_POST['harga'] ?? ''));
            $stock = filter_var($_POST['stok'] ?? null, FILTER_VALIDATE_INT);
            if ($name === '' || mb_strlen($name) > 120 || !$categoryId
                || $price === null || $price < 0 || $price > 9999999999.99 || $stock === false || $stock < 0) {
                throw new RuntimeException('Nama, kategori, harga, dan stok produk harus diisi dengan benar.');
            }

            $pendingProductImage = saveProductImage($_FILES['gambar'] ?? []);
            if ($action === 'create_product') {
                if ($pendingProductImage === null) {
                    throw new RuntimeException('Foto produk wajib dipilih.');
                }
                $statement = $pdo->prepare(
                    'INSERT INTO tbl_produk (id_kategori, nama_produk, gambar, barcode, harga, stok) VALUES (?, ?, ?, ?, ?, ?)'
                );
                $statement->execute([$categoryId, $name, $pendingProductImage, $barcode !== '' ? $barcode : null, $price, $stock]);
                $pendingProductImage = null;
                $_SESSION['notice'] = ['type' => 'success', 'text' => 'Produk berhasil ditambahkan.'];
            } else {
                $productId = filter_var($_POST['id_produk'] ?? null, FILTER_VALIDATE_INT);
                if (!$productId) {
                    throw new RuntimeException('Produk yang akan diubah tidak valid.');
                }
                $existingImageQuery = $pdo->prepare('SELECT gambar FROM tbl_produk WHERE id_produk = ?');
                $existingImageQuery->execute([$productId]);
                $previousImage = $existingImageQuery->fetchColumn();
                if ($previousImage === false) {
                    throw new RuntimeException('Produk tidak ditemukan.');
                }
                $imagePath = $pendingProductImage ?? $previousImage;
                $statement = $pdo->prepare(
                    'UPDATE tbl_produk SET id_kategori = ?, nama_produk = ?, gambar = ?, barcode = ?, harga = ?, stok = ? WHERE id_produk = ?'
                );
                $statement->execute([$categoryId, $name, $imagePath, $barcode !== '' ? $barcode : null, $price, $stock, $productId]);
                $pendingProductImage = null;
                if ($imagePath !== $previousImage) {
                    deleteProductImage((string) $previousImage);
                }
                $_SESSION['notice'] = ['type' => 'success', 'text' => 'Data produk berhasil diperbarui.'];
            }
        } elseif ($action === 'create_customer_user') {
            $customerId = filter_var($_POST['id_pelanggan'] ?? null, FILTER_VALIDATE_INT);
            $username = trim((string) ($_POST['username'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            if (!$customerId) {
                throw new RuntimeException('Pilih pelanggan terlebih dahulu.');
            }
            if (!preg_match('/^[\pL\pN._-]{3,50}$/u', $username)) {
                throw new RuntimeException('Username harus 3–50 karakter: huruf, angka, titik, garis bawah, atau tanda hubung.');
            }
            if (strlen($password) < 10 || strlen($password) > 72) {
                throw new RuntimeException('Kata sandi harus terdiri dari 10 sampai 72 karakter.');
            }
            $check = $pdo->prepare('SELECT COUNT(*) FROM tbl_pelanggan WHERE id_pelanggan = ?');
            $check->execute([$customerId]);
            if ((int) $check->fetchColumn() === 0) {
                throw new RuntimeException('Pelanggan tidak ditemukan.');
            }
            try {
                $statement = $pdo->prepare(
                    "INSERT INTO tbl_pengguna (username, password_hash, role, id_pelanggan) VALUES (?, ?, 'pelanggan', ?)"
                );
                $statement->execute([$username, password_hash($password, PASSWORD_DEFAULT), $customerId]);
            } catch (PDOException $exception) {
                if ($exception->getCode() === '23000') {
                    throw new RuntimeException('Username sudah digunakan atau pelanggan ini sudah memiliki akun.');
                }
                throw $exception;
            }
            $_SESSION['notice'] = ['type' => 'success', 'text' => 'Akun pelanggan berhasil dibuat.'];
        } elseif ($action === 'create_kasir_user') {
            $username = trim((string) ($_POST['username'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            if (!preg_match('/^[\pL\pN._-]{3,50}$/u', $username)) {
                throw new RuntimeException('Username harus 3–50 karakter: huruf, angka, titik, garis bawah, atau tanda hubung.');
            }
            if (strlen($password) < 10 || strlen($password) > 72) {
                throw new RuntimeException('Kata sandi harus terdiri dari 10 sampai 72 karakter.');
            }
            try {
                $statement = $pdo->prepare(
                    "INSERT INTO tbl_pengguna (username, password_hash, role, id_pelanggan) VALUES (?, ?, 'kasir', NULL)"
                );
                $statement->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
            } catch (PDOException $exception) {
                if ($exception->getCode() === '23000') {
                    throw new RuntimeException('Username sudah digunakan.');
                }
                throw $exception;
            }
            $_SESSION['notice'] = ['type' => 'success', 'text' => 'Akun kasir berhasil dibuat.'];
        } elseif ($action === 'delete_user') {
            $userId = filter_var($_POST['id_pengguna'] ?? null, FILTER_VALIDATE_INT);
            if (!$userId) {
                throw new RuntimeException('Akun yang akan dihapus tidak valid.');
            }
            $statement = $pdo->prepare("DELETE FROM tbl_pengguna WHERE id_pengguna = ? AND role IN ('pelanggan', 'kasir')");
            $statement->execute([$userId]);
            if ($statement->rowCount() === 0) {
                throw new RuntimeException('Akun tidak ditemukan atau akun admin tidak dapat dihapus.');
            }
            $_SESSION['notice'] = ['type' => 'success', 'text' => 'Akun berhasil dihapus.'];
        } elseif ($action === 'adjust_stock') {
            $productId = filter_var($_POST['id_produk'] ?? null, FILTER_VALIDATE_INT);
            $amount = filter_var($_POST['jumlah'] ?? null, FILTER_VALIDATE_INT);
            $direction = (string) ($_POST['arah'] ?? '');
            if (!$productId || !$amount || $amount < 1 || $amount > 1000000
                || !in_array($direction, ['tambah', 'kurang'], true)) {
                throw new RuntimeException('Jumlah stok harus berupa angka bulat antara 1 dan 1.000.000.');
            }

            if ($direction === 'tambah') {
                $statement = $pdo->prepare(
                    'UPDATE tbl_produk SET stok = stok + ? WHERE id_produk = ? AND stok + ? <= 2147483647'
                );
                $statement->execute([$amount, $productId, $amount]);
            } else {
                $statement = $pdo->prepare(
                    'UPDATE tbl_produk SET stok = stok - ? WHERE id_produk = ? AND stok >= ?'
                );
                $statement->execute([$amount, $productId, $amount]);
            }

            if ($statement->rowCount() === 0) {
                $check = $pdo->prepare('SELECT COUNT(*) FROM tbl_produk WHERE id_produk = ?');
                $check->execute([$productId]);
                throw new RuntimeException(
                    (int) $check->fetchColumn() === 0
                        ? 'Produk tidak ditemukan.'
                        : 'Stok tidak mencukupi untuk dikurangi sebanyak ' . number_format($amount, 0, ',', '.') . ' unit.'
                );
            }
            $_SESSION['notice'] = ['type' => 'success', 'text' => 'Stok produk berhasil '
                . ($direction === 'tambah' ? 'ditambah ' : 'dikurangi ') . number_format($amount, 0, ',', '.') . ' unit.'];
        } elseif ($action === 'delete_product') {
            $productId = filter_var($_POST['id_produk'] ?? null, FILTER_VALIDATE_INT);
            if (!$productId) {
                throw new RuntimeException('Produk yang akan dihapus tidak valid.');
            }
            $statement = $pdo->prepare('DELETE FROM tbl_produk WHERE id_produk = ?');
            $statement->execute([$productId]);
            $_SESSION['notice'] = ['type' => 'success', 'text' => 'Produk berhasil dihapus.'];
        } else {
            throw new RuntimeException('Aksi tidak dikenali.');
        }
    } catch (Throwable $exception) {
        if ($pendingProductImage !== null) {
            deleteProductImage($pendingProductImage);
        }
        if ($pendingPaymentQrImage !== null) {
            deletePaymentQrImage($pendingPaymentQrImage);
        }
        $message = $exception->getMessage();
        if ($exception instanceof PDOException && $exception->getCode() === '23000') {
            $message = str_contains($message, 'tbl_transaksi_ibfk')
                || str_contains($message, 'fk_transaksi_pelanggan')
                ? 'Pelanggan tidak dapat dihapus karena sudah memiliki transaksi.'
                : 'Data tidak dapat dihapus karena masih digunakan oleh transaksi.';
        } elseif ($exception instanceof PDOException) {
            $message = 'Perubahan gagal disimpan. Periksa data dan pastikan database masih tersedia.';
        }
        $_SESSION['notice'] = ['type' => 'error', 'text' => $message];
    }

    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?page=' . rawurlencode($redirect));
    exit;
}

$reportFrom = date('Y-01-01');
$reportTo = date('Y-m-d');
$reportSummary = ['jumlah' => 0, 'kotor' => 0, 'diskon' => 0, 'bersih' => 0];
$reportSales = $reportCategories = $reportProducts = $reportCustomers = [];

if ($page === 'reports' && $pdo instanceof PDO) {
    $parseDate = static function (mixed $value): ?string {
        $text = (string) $value;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $text);

        return $date !== false && $date->format('Y-m-d') === $text ? $text : null;
    };
    $reportFrom = $parseDate($_GET['dari'] ?? '') ?? $reportFrom;
    $reportTo = $parseDate($_GET['sampai'] ?? '') ?? $reportTo;
    if ($reportFrom > $reportTo) {
        [$reportFrom, $reportTo] = [$reportTo, $reportFrom];
    }
    $range = [$reportFrom, $reportTo];

    try {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) AS jumlah,
                    COALESCE(SUM(subtotal), 0) AS kotor,
                    COALESCE(SUM(diskon), 0) AS diskon,
                    COALESCE(SUM(total_belanja), 0) AS bersih
             FROM tbl_transaksi WHERE tanggal BETWEEN ? AND ?'
        );
        $statement->execute($range);
        $reportSummary = $statement->fetch() ?: $reportSummary;

        $statement = $pdo->prepare(
            'SELECT id_transaksi, tanggal, nama_pelanggan, kota, subtotal, diskon, total_belanja
             FROM vw_laporan_penjualan
             WHERE tanggal BETWEEN ? AND ?
             ORDER BY tanggal DESC, id_transaksi DESC'
        );
        $statement->execute($range);
        $reportSales = $statement->fetchAll();

        $statement = $pdo->prepare(
            'SELECT k.nama_kategori, SUM(d.jumlah) AS unit, SUM(d.subtotal) AS pendapatan
             FROM tbl_detail_transaksi AS d
             JOIN tbl_transaksi AS t ON t.id_transaksi = d.id_transaksi
             JOIN tbl_produk AS pr ON pr.id_produk = d.id_produk
             JOIN tbl_kategori AS k ON k.id_kategori = pr.id_kategori
             WHERE t.tanggal BETWEEN ? AND ?
             GROUP BY k.id_kategori, k.nama_kategori
             ORDER BY pendapatan DESC'
        );
        $statement->execute($range);
        $reportCategories = $statement->fetchAll();

        $statement = $pdo->prepare(
            'SELECT pr.nama_produk, SUM(d.jumlah) AS unit, SUM(d.subtotal) AS pendapatan
             FROM tbl_detail_transaksi AS d
             JOIN tbl_transaksi AS t ON t.id_transaksi = d.id_transaksi
             JOIN tbl_produk AS pr ON pr.id_produk = d.id_produk
             WHERE t.tanggal BETWEEN ? AND ?
             GROUP BY pr.id_produk, pr.nama_produk
             ORDER BY unit DESC, pendapatan DESC
             LIMIT 5'
        );
        $statement->execute($range);
        $reportProducts = $statement->fetchAll();

        $statement = $pdo->prepare(
            'SELECT p.nama_pelanggan, p.kota, COUNT(*) AS jumlah, SUM(t.total_belanja) AS total
             FROM tbl_transaksi AS t
             JOIN tbl_pelanggan AS p ON p.id_pelanggan = t.id_pelanggan
             WHERE t.tanggal BETWEEN ? AND ?
             GROUP BY p.id_pelanggan, p.nama_pelanggan, p.kota
             ORDER BY total DESC
             LIMIT 5'
        );
        $statement->execute($range);
        $reportCustomers = $statement->fetchAll();
    } catch (PDOException $exception) {
        $pageError = 'Laporan belum dapat dimuat. Detail: ' . $exception->getMessage();
    }
}

$userRows = $customersWithoutUser = [];
if ($page === 'users' && $pdo instanceof PDO) {
    try {
        $userRows = $pdo->query(
            'SELECT u.id_pengguna, u.username, u.role, u.dibuat_pada, p.nama_pelanggan, p.kota
             FROM tbl_pengguna AS u
             LEFT JOIN tbl_pelanggan AS p ON p.id_pelanggan = u.id_pelanggan
             ORDER BY u.role, u.username'
        )->fetchAll();
        $customersWithoutUser = $pdo->query(
            'SELECT id_pelanggan, nama_pelanggan, kota FROM tbl_pelanggan
             WHERE id_pelanggan NOT IN (SELECT id_pelanggan FROM tbl_pengguna WHERE id_pelanggan IS NOT NULL)
             ORDER BY nama_pelanggan'
        )->fetchAll();
    } catch (PDOException $exception) {
        $pageError = 'Data pengguna belum dapat dimuat. Detail: ' . $exception->getMessage();
    }
}

$maxMonth = max(array_map(static fn (array $row): float => (float) $row['total'], $monthlySales) ?: [1]);
$outOfStock = $lowStockCount;
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f5f6fa">
    <title>Dashboard — TokoKita</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="app-shell">
    <aside class="sidebar">
        <a class="brand" href="?page=dashboard" aria-label="TokoKita Dashboard">
            <span class="brand-mark">t<span>.</span></span>
            <span class="brand-copy">tokokita<small>STORE MANAGEMENT</small></span>
        </a>
        <div class="nav-caption">MENU</div>
        <nav class="navigation" aria-label="Navigasi utama">
            <a class="nav-link <?= $page === 'dashboard' ? 'active' : '' ?>" href="?page=dashboard"><span class="nav-icon">⌂</span>Dashboard</a>
            <a class="nav-link <?= $page === 'transactions' ? 'active' : '' ?>" href="?page=transactions"><span class="nav-icon">▤</span>Transaksi</a>
            <a class="nav-link <?= $page === 'products' ? 'active' : '' ?>" href="?page=products"><span class="nav-icon">▦</span>Produk</a>
            <a class="nav-link <?= $page === 'customers' ? 'active' : '' ?>" href="?page=customers"><span class="nav-icon">♙</span>Pelanggan</a>
            <a class="nav-link <?= $page === 'activity' ? 'active' : '' ?>" href="?page=activity"><span class="nav-icon">◷</span>Aktivitas</a>
            <a class="nav-link <?= $page === 'reports' ? 'active' : '' ?>" href="?page=reports"><span class="nav-icon">▥</span>Laporan</a>
            <a class="nav-link <?= $page === 'payments' ? 'active' : '' ?>" href="?page=payments"><span class="nav-icon">▣</span>Pembayaran</a>
            <a class="nav-link <?= $page === 'users' ? 'active' : '' ?>" href="?page=users"><span class="nav-icon">♧</span>Pengguna</a>
        </nav>
        <div class="sidebar-bottom">
            <div class="store-card">
                <span class="store-avatar"><?= e(mb_strtoupper(mb_substr(authUser()['username'], 0, 1))) ?></span>
                <span><strong><?= e(authUser()['username']) ?></strong><small>Admin toko</small></span>
            </div>
            <div class="sidebar-foot">SISTEM INFORMASI PENJUALAN<br>© <?= date('Y') ?> TOKOKITA</div>
        </div>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <div class="breadcrumb">TokoKita <span>/</span> <strong><?= e($pageTitles[$page]) ?></strong></div>
            <div class="topbar-right">
                <span class="top-search">⌕ <span>Cari apa saja...</span><kbd>⌘ K</kbd></span>
                <span class="today-date"><?= e(tanggalIndonesia(date('Y-m-d'), true)) ?></span>
                <span class="top-notification">♧</span>
                <span class="top-avatar">A</span>
                <form method="post" action="logout.php" class="top-logout-form">
                    <input type="hidden" name="csrf_token" value="<?= e(authCsrfToken()) ?>">
                    <button class="logout-button" type="submit" title="Logout dari akun">Logout <span>↗</span></button>
                </form>
            </div>
        </header>

        <div class="page-content <?= $page === 'reports' ? 'report-print-page' : '' ?>">
            <?php if ($pageError): ?>
                <div class="alert error" role="alert"><?= e($pageError) ?></div>
            <?php endif; ?>
            <?php if ($notice): ?>
                <div class="alert <?= e($notice['type']) ?>" role="status"><?= e($notice['text']) ?></div>
            <?php endif; ?>

            <?php if ($page === 'dashboard'): ?>
            <section class="welcome-row">
                <div>
                    <h1>Selamat datang, Admin</h1>
                    <p class="muted">Anda memiliki <strong class="orders-highlight"><?= number_format((int) $stats['transactions'], 0, ',', '.') ?> transaksi</strong> bulan ini.</p>
                </div>
                <a class="button button-primary" href="?page=transactions"><span>＋</span> Buat transaksi</a>
            </section>

            <section class="stat-grid dashboard-stats" aria-label="Dashboard bulan ini">
                <article class="stat-card stat-orange">
                    <div class="stat-top"><span class="stat-label">Total penjualan</span><span class="stat-icon">▤</span></div>
                    <div class="stat-value"><?= rupiah($stats['sales']) ?></div>
                    <div class="stat-note">Penjualan bulan ini <span class="stat-change">↗</span></div>
                </article>
                <article class="stat-card stat-navy">
                    <div class="stat-top"><span class="stat-label">Total transaksi</span><span class="stat-icon">▣</span></div>
                    <div class="stat-value"><?= number_format((int) $stats['transactions'], 0, ',', '.') ?></div>
                    <div class="stat-note">Pesanan tercatat <span class="stat-change">● Aktif</span></div>
                </article>
                <article class="stat-card stat-teal">
                    <div class="stat-top"><span class="stat-label">Produk tersedia</span><span class="stat-icon">▦</span></div>
                    <div class="stat-value"><?= number_format((int) $stats['products'], 0, ',', '.') ?></div>
                    <div class="stat-note"><?= $outOfStock ?> produk perlu restok</div>
                </article>
                <article class="stat-card stat-blue">
                    <div class="stat-top"><span class="stat-label">Pelanggan</span><span class="stat-icon">♙</span></div>
                    <div class="stat-value"><?= number_format((int) $stats['customers'], 0, ',', '.') ?></div>
                    <div class="stat-note">Pelanggan terdaftar <span class="stat-change">● Aktif</span></div>
                </article>
            </section>

            <section class="content-grid dashboard-panels">
                <article class="panel sales-panel">
                    <div class="panel-heading">
                        <div><h2>Penjualan toko</h2><p class="muted">Performa penjualan 12 bulan terakhir</p></div>
                        <span class="period-pill">12 Bulan⌄</span>
                    </div>
                    <?php if ($monthlySales): ?>
                        <div class="chart vertical-chart">
                            <div class="chart-axis"><span>Rp <?= number_format($maxMonth / 1000, 0, ',', '.') ?>k</span><span>Rp <?= number_format($maxMonth / 2000, 0, ',', '.') ?>k</span><span>Rp 0</span></div>
                            <?php foreach ($monthlySales as $month): ?>
                                <?php $width = max(3, (int) round(((float) $month['total'] / $maxMonth) * 100)); ?>
                                <div class="chart-column" title="<?= e($month['bulan']) ?>: <?= e(rupiah($month['total'])) ?>">
                                    <div class="chart-bars"><span class="chart-bar chart-bar-muted" style="height: <?= max(8, $width - 18) ?>%"></span><span class="chart-bar" style="height: <?= $width ?>%"></span></div>
                                    <span class="chart-label"><?= e($month['bulan']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">Belum ada data penjualan untuk periode ini.</div>
                    <?php endif; ?>
                    <div class="chart-footer"><span><i class="legend-dot"></i> Penjualan</span><span><i class="legend-dot legend-muted"></i> Periode sebelumnya</span></div>
                </article>

                <article class="panel information-panel">
                    <div class="panel-heading">
                        <div><h2>Informasi toko</h2><p class="muted">Data toko saat ini</p></div>
                        <span class="quick-icon">◉</span>
                    </div>
                    <div class="info-counts">
                        <a class="info-count" href="?page=products"><span class="info-count-icon">▦</span><span>Produk<strong><?= number_format((int) $stats['products'], 0, ',', '.') ?></strong></span></a>
                        <a class="info-count" href="?page=customers"><span class="info-count-icon customer-count-icon">♙</span><span>Pelanggan<strong><?= number_format((int) $stats['customers'], 0, ',', '.') ?></strong></span></a>
                        <a class="info-count" href="?page=transactions"><span class="info-count-icon order-count-icon">▤</span><span>Transaksi<strong><?= number_format((int) $stats['transactions'], 0, ',', '.') ?></strong></span></a>
                    </div>
                    <div class="stock-overview">
                        <div class="stock-ring"><span><?= number_format((int) $stats['products'], 0, ',', '.') ?><small>produk</small></span></div>
                        <div><strong><?= $outOfStock ?> produk</strong><small>perlu diperiksa stoknya</small><a href="?page=products">Kelola produk →</a></div>
                    </div>
                </article>
            </section>
            <?php endif; ?>

            <?php if ($page === 'transactions'): ?>
            <section class="welcome-row page-heading-row">
                <div><p class="eyebrow">PENJUALAN</p><h1>Transaksi</h1><p class="muted">Catat transaksi baru dan lihat riwayat penjualan.</p></div>
            </section>
            <section class="content-grid transaction-panels">
                <article class="panel quick-panel" id="new-sale">
                    <div class="panel-heading"><div><h2>Transaksi baru</h2><p class="muted">Penjualan cepat satu produk</p></div><span class="quick-icon">＋</span></div>
                    <form method="post" class="sale-form">
                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action" value="add_sale">
                        <input type="hidden" name="redirect" value="transactions">
                        <label for="id_pelanggan">Pelanggan</label>
                        <select id="id_pelanggan" name="id_pelanggan" required><option value="">Pilih pelanggan</option>
                            <?php foreach ($customers as $customer): ?><option value="<?= (int) $customer['id_pelanggan'] ?>"><?= e($customer['nama_pelanggan']) ?> · <?= e($customer['kota'] ?? 'Kota tidak diisi') ?></option><?php endforeach; ?>
                        </select>
                        <label for="id_produk">Produk</label>
                        <select id="id_produk" name="id_produk" required><option value="">Pilih produk</option>
                            <?php foreach ($products as $product): ?><option value="<?= (int) $product['id_produk'] ?>" <?= (int) $product['stok'] < 1 ? 'disabled' : '' ?>><?= e($product['nama_produk']) ?> · <?= rupiah($product['harga']) ?> (stok <?= (int) $product['stok'] ?>)</option><?php endforeach; ?>
                        </select>
                        <div class="quantity-row"><label for="jumlah">Jumlah</label><input id="jumlah" name="jumlah" type="number" min="1" value="1" required></div>
                        <button class="button button-primary submit-button" type="submit">Simpan transaksi <span>→</span></button>
                        <p class="form-hint">Diskon dan stok dihitung otomatis oleh sistem.</p>
                    </form>
                </article>
            </section>
            <?php endif; ?>

            <?php if ($page === 'products'): ?>
            <section class="management-hero product-hero">
                <div class="management-hero-copy">
                    <span class="hero-icon">▦</span>
                    <div><p class="eyebrow">KATALOG TOKO</p><h1>Kelola Produk</h1><p class="muted">Pantau katalog, harga, dan ketersediaan stok dari satu tempat.</p></div>
                </div>
                <span class="hero-decoration">✳</span>
            </section>
            <section class="management-stats" aria-label="Statistik produk">
                <article class="management-stat"><span class="management-stat-icon purple-icon">▦</span><span class="management-stat-copy"><small>Total produk</small><strong><?= number_format((int) $stats['products'], 0, ',', '.') ?></strong><em>Produk terdaftar</em></span></article>
                <article class="management-stat"><span class="management-stat-icon green-icon">▤</span><span class="management-stat-copy"><small>Total stok</small><strong><?= number_format($totalStock, 0, ',', '.') ?></strong><em>Unit tersedia</em></span></article>
                <article class="management-stat"><span class="management-stat-icon amber-icon">⌁</span><span class="management-stat-copy"><small>Stok menipis</small><strong><?= number_format($lowStockCount, 0, ',', '.') ?></strong><em>Perlu restok</em></span></article>
                <article class="management-stat"><span class="management-stat-icon blue-icon">◈</span><span class="management-stat-copy"><small>Kategori</small><strong><?= number_format(count($categories), 0, ',', '.') ?></strong><em>Kategori produk</em></span></article>
            </section>
            <?php endif; ?>

            <?php if ($page === 'customers'): ?>
            <section class="management-hero customer-hero">
                <div class="management-hero-copy">
                    <span class="hero-icon">♙</span>
                    <div><p class="eyebrow">HUBUNGAN PELANGGAN</p><h1>Kenali Pelanggan Anda</h1><p class="muted">Kelola informasi pelanggan dan jaga hubungan baik dengan mereka.</p></div>
                </div>
                <span class="hero-decoration">✳</span>
            </section>
            <section class="management-stats" aria-label="Statistik pelanggan">
                <article class="management-stat"><span class="management-stat-icon purple-icon">♙</span><span class="management-stat-copy"><small>Total pelanggan</small><strong><?= number_format((int) $stats['customers'], 0, ',', '.') ?></strong><em>Terdaftar di toko</em></span></article>
                <article class="management-stat"><span class="management-stat-icon green-icon">⌖</span><span class="management-stat-copy"><small>Kota terjangkau</small><strong><?= number_format($cityCount, 0, ',', '.') ?></strong><em>Lokasi pelanggan</em></span></article>
                <article class="management-stat"><span class="management-stat-icon amber-icon">▤</span><span class="management-stat-copy"><small>Transaksi bulan ini</small><strong><?= number_format((int) $stats['transactions'], 0, ',', '.') ?></strong><em>Pesanan pelanggan</em></span></article>
                <article class="management-stat"><span class="management-stat-icon blue-icon">♡</span><span class="management-stat-copy"><small>Pelanggan kembali</small><strong><?= number_format($repeatCustomers, 0, ',', '.') ?></strong><em>Lebih dari satu transaksi</em></span></article>
            </section>
            <?php endif; ?>

            <?php if ($page === 'activity'): ?>
            <section class="management-hero activity-hero">
                <div class="management-hero-copy">
                    <span class="hero-icon">◷</span>
                    <div><p class="eyebrow">PUSAT PEMANTAUAN</p><h1>Aktivitas Sistem</h1><p class="muted">Semua perubahan penting di toko tercatat otomatis dan transparan.</p></div>
                </div>
                <span class="activity-live hero-live"><i></i> PEMANTAUAN AKTIF</span>
            </section>
            <section class="management-stats" aria-label="Statistik aktivitas">
                <article class="management-stat"><span class="management-stat-icon purple-icon">◷</span><span class="management-stat-copy"><small>Aktivitas hari ini</small><strong><?= number_format($activityToday, 0, ',', '.') ?></strong><em>Perubahan tercatat</em></span></article>
                <article class="management-stat"><span class="management-stat-icon green-icon">＋</span><span class="management-stat-copy"><small>Data ditambahkan</small><strong><?= number_format($activityCounts['INSERT'], 0, ',', '.') ?></strong><em>Dari 20 aktivitas terakhir</em></span></article>
                <article class="management-stat"><span class="management-stat-icon amber-icon">↻</span><span class="management-stat-copy"><small>Data diperbarui</small><strong><?= number_format($activityCounts['UPDATE'], 0, ',', '.') ?></strong><em>Dari 20 aktivitas terakhir</em></span></article>
                <article class="management-stat"><span class="management-stat-icon rose-icon">×</span><span class="management-stat-copy"><small>Data dihapus</small><strong><?= number_format($activityCounts['DELETE'], 0, ',', '.') ?></strong><em>Dari 20 aktivitas terakhir</em></span></article>
            </section>
            <?php endif; ?>

            <?php if ($page === 'transactions' || $page === 'dashboard'): ?>
            <section class="panel table-panel" id="sales">
                <div class="panel-heading">
                    <div><h2>Transaksi terbaru</h2><p class="muted">Daftar penjualan yang baru dicatat</p></div>
                    <a class="text-link" href="?page=transactions">Lihat semua <span>→</span></a>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>ID TRANSAKSI</th><th>TANGGAL</th><th>PELANGGAN</th><th>SUBTOTAL</th><th>DISKON</th><th>TOTAL</th><th>STATUS</th></tr></thead>
                        <tbody>
                        <?php foreach ($transactions as $transaction): ?>
                            <tr>
                                <td><span class="transaction-id">#<?= (int) $transaction['id_transaksi'] ?></span></td>
                                <td><?= e(tanggalIndonesia($transaction['tanggal'])) ?></td>
                                <td><span class="customer-name"><?= e($transaction['nama_pelanggan']) ?></span></td>
                                <td><?= rupiah($transaction['subtotal']) ?></td>
                                <td class="discount-cell"><?= rupiah($transaction['diskon']) ?></td>
                                <td><strong><?= rupiah($transaction['total_belanja']) ?></strong></td>
                                <td><span class="badge badge-success"><i></i> Selesai</span></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$transactions): ?>
                            <tr><td class="empty-cell" colspan="7">Belum ada transaksi. Buat transaksi pertama Anda di atas.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
            <?php endif; ?>

            <?php if ($page === 'products' || $page === 'activity'): ?>
            <section class="bottom-grid management-grid">
                <?php if ($page === 'products'): ?>
                <article class="panel table-panel" id="products">
                    <div class="panel-heading management-panel-heading">
                        <div><h2>Daftar produk</h2><p class="muted">Kelola katalog dan stok produk toko</p></div>
                        <details class="add-menu">
                            <summary class="button button-primary"><span>＋</span> Produk baru</summary>
                            <form method="post" class="manager-form" enctype="multipart/form-data">
                                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="action" value="create_product">
                                <input type="hidden" name="redirect" value="products">
                                <label>Nama produk<input name="nama_produk" maxlength="120" required></label>
                                <label>Barcode / SKU produk (opsional)<input name="barcode" maxlength="100" placeholder="Contoh: 8991234567890"></label>
                                <label>Kategori
                                    <select name="id_kategori" required>
                                        <option value="">Pilih kategori</option>
                                        <?php foreach ($categories as $category): ?>
                                            <option value="<?= (int) $category['id_kategori'] ?>"><?= e($category['nama_kategori']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label>Foto produk (JPG, PNG, WEBP; maks. 5 MB)
                                    <input id="product-image-input" name="gambar" type="file" accept="image/jpeg,image/png,image/webp" required>
                                </label>
                                <div id="product-image-preview" class="product-image-preview" hidden>
                                    <img id="product-image-preview-source" alt="Pratinjau foto produk">
                                    <small>Pratinjau foto yang dipilih atau ditempel</small>
                                </div>
                                <div class="manager-form-row">
                                    <label>Harga (Rp)<input name="harga" type="text" inputmode="decimal" placeholder="Contoh: 6.000" required></label>
                                    <label>Stok<input name="stok" type="number" min="0" step="1" value="0" required></label>
                                </div>
                                <button class="button button-primary" type="submit">Simpan produk</button>
                            </form>
                        </details>
                    </div>
                    <div class="management-toolbar">
                        <label class="table-search"><span>⌕</span><input type="search" data-filter-input="products" placeholder="Cari nama produk..."></label>
                        <label class="filter-select"><span>Kategori</span><select data-filter-category="products"><option value="">Semua kategori</option><?php foreach ($categories as $category): ?><option value="<?= e(mb_strtolower($category['nama_kategori'])) ?>"><?= e($category['nama_kategori']) ?></option><?php endforeach; ?></select></label>
                        <span class="toolbar-count"><strong data-filter-count="products"><?= number_format((int) $stats['products'], 0, ',', '.') ?></strong> produk</span>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>PRODUK</th><th>KATEGORI</th><th>HARGA JUAL</th><th>STOK</th><th>STATUS</th><th>AKSI</th></tr></thead>
                            <tbody>
                            <?php foreach ($products as $product): ?>
                                <tr class="filter-item" data-filter-item="products" data-search="<?= e(mb_strtolower($product['nama_produk'] . ' ' . $product['nama_kategori'])) ?>" data-category="<?= e(mb_strtolower($product['nama_kategori'])) ?>">
                                    <td><span class="product-identity"><span class="product-thumbnail"><img src="<?= e(($product['gambar'] ?: gambarFallbackProduk($product['nama_produk'], $product['nama_kategori'])) . (str_starts_with($product['gambar'] ?? '', 'uploads/products/') ? '?v=' . filemtime(__DIR__ . '/' . $product['gambar']) : '')) ?>" alt="<?= e($product['nama_produk']) ?>" loading="lazy"></span><span><strong><?= e($product['nama_produk']) ?></strong><small>SKU-<?= str_pad((string) $product['id_produk'], 4, '0', STR_PAD_LEFT) ?></small></span></span></td>
                                    <td><span class="category-pill"><?= e($product['nama_kategori']) ?></span></td>
                                    <td><strong class="price-value"><?= rupiah($product['harga']) ?></strong></td>
                                    <td><span class="stock-value"><?= number_format((int) $product['stok'], 0, ',', '.') ?> <small>unit</small></span></td>
                                    <td><span class="product-status <?= (int) $product['stok'] <= 5 ? 'status-warning' : 'status-ready' ?>"><i></i><?= (int) $product['stok'] <= 5 ? 'Stok menipis' : 'Tersedia' ?></span></td>
                                    <td>
                                        <div class="row-actions">
                                            <details class="row-editor stock-editor">
                                                <summary class="small-action product-edit-action">Stok</summary>
                                                <form method="post" class="manager-form editor-form" enctype="multipart/form-data">
                                                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                                    <input type="hidden" name="action" value="adjust_stock">
                                                    <input type="hidden" name="redirect" value="products">
                                                    <input type="hidden" name="id_produk" value="<?= (int) $product['id_produk'] ?>">
                                                    <label>Jumlah unit (stok saat ini: <?= number_format((int) $product['stok'], 0, ',', '.') ?>)<input name="jumlah" type="number" min="1" max="1000000" step="1" value="1" required></label>
                                                    <div class="stock-buttons">
                                                        <button class="button button-primary" type="submit" name="arah" value="tambah">＋ Tambah</button>
                                                        <button class="button button-light" type="submit" name="arah" value="kurang">－ Kurang</button>
                                                    </div>
                                                </form>
                                            </details>
                                            <details class="row-editor">
                                                <summary class="small-action product-edit-action">Edit</summary>
                                                <form method="post" class="manager-form editor-form">
                                                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                                    <input type="hidden" name="action" value="update_product">
                                                    <input type="hidden" name="redirect" value="products">
                                                    <input type="hidden" name="id_produk" value="<?= (int) $product['id_produk'] ?>">
                                                    <label>Nama produk<input name="nama_produk" maxlength="120" value="<?= e($product['nama_produk']) ?>" required></label>
                                                    <label>Barcode / SKU produk (opsional)<input name="barcode" maxlength="100" value="<?= e($product['barcode'] ?? '') ?>" placeholder="Contoh: 8991234567890"></label>
                                                    <label>Ganti foto produk (opsional)<input name="gambar" type="file" accept="image/jpeg,image/png,image/webp"></label>
                                                    <label>Kategori
                                                        <select name="id_kategori" required>
                                                            <?php foreach ($categories as $category): ?>
                                                                <option value="<?= (int) $category['id_kategori'] ?>" <?= (int) $category['id_kategori'] === (int) $product['id_kategori'] ? 'selected' : '' ?>>
                                                                    <?= e($category['nama_kategori']) ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </label>
                                                    <div class="manager-form-row">
                                                        <label>Harga (Rp)<input name="harga" type="text" inputmode="decimal" value="<?= e(number_format((float) $product['harga'], 0, ',', '.')) ?>" required></label>
                                                        <label>Stok<input name="stok" type="number" min="0" step="1" value="<?= (int) $product['stok'] ?>" required></label>
                                                    </div>
                                                    <button class="button button-primary" type="submit">Simpan perubahan</button>
                                                </form>
                                            </details>
                                            <form method="post" class="inline-delete" onsubmit="return confirm('Hapus produk ini? Produk yang sudah ada di transaksi tidak dapat dihapus.');">
                                                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                                <input type="hidden" name="action" value="delete_product">
                                                <input type="hidden" name="redirect" value="products">
                                                <input type="hidden" name="id_produk" value="<?= (int) $product['id_produk'] ?>">
                                                <button class="small-action danger-action" type="submit" aria-label="Hapus produk">Hapus</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$products): ?><tr><td class="empty-cell" colspan="6">Data produk belum tersedia.</td></tr><?php endif; ?>
                            <tr class="filter-empty" data-filter-empty="products" hidden><td class="empty-cell" colspan="6">Produk tidak ditemukan. Coba kata kunci lain.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </article>
                <?php endif; ?>

                <?php if ($page === 'activity'): ?>
                <article class="panel activity-panel" id="activity">
                    <div class="panel-heading management-panel-heading">
                        <div><h2>Riwayat aktivitas</h2><p class="muted">Jejak perubahan data yang tercatat oleh sistem</p></div>
                        <span class="activity-live"><i></i> LIVE</span>
                    </div>
                    <div class="management-toolbar activity-toolbar">
                        <label class="table-search"><span>⌕</span><input type="search" data-filter-input="activity" placeholder="Cari aktivitas, tabel, atau pengguna..."></label>
                        <label class="filter-select"><span>Jenis</span><select data-filter-action="activity"><option value="">Semua aktivitas</option><option value="insert">Data ditambahkan</option><option value="update">Data diperbarui</option><option value="delete">Data dihapus</option></select></label>
                        <span class="toolbar-count">Menampilkan <strong data-filter-count="activity"><?= count($logs) ?></strong> terbaru</span>
                    </div>
                    <div class="activity-list modern-activity-list">
                        <?php foreach ($logs as $log): ?>
                            <div class="activity-item modern-activity-item filter-item" data-filter-item="activity" data-search="<?= e(mb_strtolower($log['aksi'] . ' ' . $log['nama_tabel'] . ' ' . $log['keterangan'] . ' ' . $log['pengguna'])) ?>" data-action="<?= e(mb_strtolower($log['aksi'])) ?>">
                                <span class="activity-timeline"><i></i></span>
                                <span class="activity-icon <?= strtolower(e($log['aksi'])) ?>"><?= $log['aksi'] === 'INSERT' ? '+' : ($log['aksi'] === 'DELETE' ? '×' : '↻') ?></span>
                                <div class="activity-copy">
                                    <div class="activity-title-row"><strong><?= $log['aksi'] === 'INSERT' ? 'Data ditambahkan' : ($log['aksi'] === 'DELETE' ? 'Data dihapus' : 'Data diperbarui') ?></strong><span class="activity-action-pill <?= strtolower(e($log['aksi'])) ?>"><?= e($log['aksi']) ?></span></div>
                                    <p><?= e($log['keterangan']) ?></p>
                                    <div class="activity-meta"><span class="activity-table-tag"><?= e($log['nama_tabel']) ?> · #<?= (int) $log['id_data'] ?></span><span><?= e(date('d.m.Y · H:i', strtotime($log['waktu']))) ?></span><span><?= e($log['pengguna']) ?></span></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (!$logs): ?><div class="empty-state">Aktivitas akan muncul di sini setelah ada perubahan data.</div><?php endif; ?>
                        <?php if ($logs): ?><div class="filter-empty activity-empty" data-filter-empty="activity" hidden>Aktivitas tidak ditemukan. Coba kata kunci atau jenis lain.</div><?php endif; ?>
                    </div>
                </article>
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <?php if ($page === 'customers'): ?>
            <section class="panel customers-panel" id="customers">
                <div class="panel-heading management-panel-heading">
                    <div><h2>Semua pelanggan</h2><p class="muted">Kenali dan kelola komunitas pelanggan toko Anda</p></div>
                    <details class="add-menu">
                        <summary class="button button-primary"><span>＋</span> Pelanggan baru</summary>
                        <form method="post" class="manager-form">
                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="action" value="create_customer">
                            <input type="hidden" name="redirect" value="customers">
                            <label>Nama pelanggan<input name="nama_pelanggan" maxlength="100" required></label>
                            <label>Kota<input name="kota" maxlength="50" placeholder="Opsional"></label>
                            <button class="button button-primary" type="submit">Simpan pelanggan</button>
                        </form>
                    </details>
                </div>
                <div class="management-toolbar customer-toolbar">
                    <label class="table-search"><span>⌕</span><input type="search" data-filter-input="customers" placeholder="Cari nama atau kota pelanggan..."></label>
                    <span class="toolbar-count"><strong data-filter-count="customers"><?= number_format((int) $stats['customers'], 0, ',', '.') ?></strong> pelanggan</span>
                </div>
                <div class="customer-grid modern-customer-grid">
                    <?php foreach ($customers as $customer): ?>
                        <div class="customer-card filter-item" data-filter-item="customers" data-search="<?= e(mb_strtolower($customer['nama_pelanggan'] . ' ' . ($customer['kota'] ?? ''))) ?>">
                            <span class="customer-avatar"><?= e(mb_strtoupper(mb_substr($customer['nama_pelanggan'], 0, 1))) ?></span>
                            <span class="customer-info"><strong><?= e($customer['nama_pelanggan']) ?></strong><small><span class="customer-location-dot">⌖</span><?= e($customer['kota'] ?? 'Kota tidak diisi') ?></small></span>
                            <span class="customer-id">ID #<?= (int) $customer['id_pelanggan'] ?><small><?= number_format((int) $customer['jumlah_transaksi'], 0, ',', '.') ?> transaksi</small></span>
                            <div class="customer-card-actions">
                                <details class="row-editor customer-editor">
                                    <summary class="small-action product-edit-action"><span class="customer-edit-open-label">Edit data pelanggan</span><span class="customer-edit-close-label">Tutup editor</span></summary>
                                    <form method="post" class="manager-form editor-form">
                                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                        <input type="hidden" name="action" value="update_customer">
                                        <input type="hidden" name="redirect" value="customers">
                                        <input type="hidden" name="id_pelanggan" value="<?= (int) $customer['id_pelanggan'] ?>">
                                        <div class="customer-editor-title"><span>UBAH DATA PELANGGAN</span><strong><?= e($customer['nama_pelanggan']) ?></strong></div>
                                        <label>Nama pelanggan<input name="nama_pelanggan" maxlength="100" value="<?= e($customer['nama_pelanggan']) ?>" required></label>
                                        <label>Kota<input name="kota" maxlength="50" value="<?= e($customer['kota'] ?? '') ?>"></label>
                                        <button class="button button-primary" type="submit">Simpan perubahan</button>
                                    </form>
                                </details>
                                <form method="post" class="inline-delete" onsubmit="return confirm('Hapus pelanggan ini?');">
                                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                    <input type="hidden" name="action" value="delete_customer">
                                    <input type="hidden" name="redirect" value="customers">
                                    <input type="hidden" name="id_pelanggan" value="<?= (int) $customer['id_pelanggan'] ?>">
                                    <button class="small-action danger-action" type="submit" <?= (int) $customer['jumlah_transaksi'] > 0 ? 'disabled title="Pelanggan dengan transaksi tidak dapat dihapus"' : '' ?>>Hapus</button>
                                </form>
                            </div>
                            <details class="customer-history">
                                <summary><span aria-hidden="true">▤</span> Riwayat transaksi <small><?= number_format((int) $customer['jumlah_transaksi'], 0, ',', '.') ?></small></summary>
                                <?php $customerTransactions = $customerHistories[(int) $customer['id_pelanggan']] ?? []; ?>
                                <?php if ($customerTransactions): ?>
                                    <div class="customer-history-list">
                                        <?php foreach ($customerTransactions as $history): ?>
                                            <article class="customer-history-item">
                                                <div><strong>Transaksi #<?= (int) $history['id_transaksi'] ?></strong><small><?= e(tanggalIndonesia($history['tanggal'])) ?></small></div>
                                                <p><?= e(isset($customerHistoryItems[(int) $history['id_transaksi']]) ? implode(', ', $customerHistoryItems[(int) $history['id_transaksi']]) : 'Rincian produk tidak tersedia') ?></p>
                                                <strong class="customer-history-total"><?= rupiah($history['total_belanja']) ?></strong>
                                            </article>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <p class="customer-history-empty">Belum ada transaksi untuk pelanggan ini.</p>
                                <?php endif; ?>
                            </details>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$customers): ?><div class="empty-state">Belum ada data pelanggan.</div><?php endif; ?>
                    <div class="filter-empty customer-empty" data-filter-empty="customers" hidden>Pelanggan tidak ditemukan. Coba kata kunci lain.</div>
                </div>
            </section>
            <?php endif; ?>

            <?php if ($page === 'reports'): ?>
            <section class="management-hero report-hero">
                <div class="management-hero-copy">
                    <span class="hero-icon">▥</span>
                    <div><p class="eyebrow">LAPORAN PENJUALAN</p><h1>Laporan Penjualan</h1><p class="muted">Periode <?= e(tanggalIndonesia($reportFrom)) ?> – <?= e(tanggalIndonesia($reportTo)) ?></p></div>
                </div>
            </section>
            <form method="get" class="report-filter">
                <input type="hidden" name="page" value="reports">
                <label for="dari">Dari tanggal<input id="dari" name="dari" type="date" value="<?= e($reportFrom) ?>" required></label>
                <label for="sampai">Sampai tanggal<input id="sampai" name="sampai" type="date" value="<?= e($reportTo) ?>" required></label>
                <button class="button button-primary" type="submit">Tampilkan laporan</button>
                <button class="button button-light" type="button" onclick="printReportOnePage()">Cetak laporan</button>
            </form>
            <section class="management-stats" aria-label="Ringkasan laporan">
                <article class="management-stat"><span class="management-stat-icon purple-icon">▤</span><span class="management-stat-copy"><small>Jumlah transaksi</small><strong><?= number_format((int) $reportSummary['jumlah'], 0, ',', '.') ?></strong><em>Pada periode terpilih</em></span></article>
                <article class="management-stat"><span class="management-stat-icon amber-icon">Rp</span><span class="management-stat-copy"><small>Penjualan kotor</small><strong><?= rupiah($reportSummary['kotor']) ?></strong><em>Sebelum diskon</em></span></article>
                <article class="management-stat"><span class="management-stat-icon rose-icon">%</span><span class="management-stat-copy"><small>Total diskon</small><strong><?= rupiah($reportSummary['diskon']) ?></strong><em>Diberikan ke pelanggan</em></span></article>
                <article class="management-stat"><span class="management-stat-icon green-icon">✓</span><span class="management-stat-copy"><small>Pendapatan bersih</small><strong><?= rupiah($reportSummary['bersih']) ?></strong><em>Setelah diskon</em></span></article>
            </section>

            <section class="panel table-panel" id="report-sales">
                <div class="panel-heading">
                    <div><h2>Rincian penjualan</h2><p class="muted">Seluruh transaksi pada periode terpilih</p></div>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>ID TRANSAKSI</th><th>TANGGAL</th><th>PELANGGAN</th><th>KOTA</th><th>SUBTOTAL</th><th>DISKON</th><th>TOTAL</th></tr></thead>
                        <tbody>
                        <?php foreach ($reportSales as $row): ?>
                            <tr>
                                <td><span class="transaction-id">#<?= (int) $row['id_transaksi'] ?></span></td>
                                <td><?= e(tanggalIndonesia($row['tanggal'])) ?></td>
                                <td><span class="customer-name"><?= e($row['nama_pelanggan']) ?></span></td>
                                <td><?= e($row['kota'] ?? '-') ?></td>
                                <td><?= rupiah($row['subtotal']) ?></td>
                                <td class="discount-cell"><?= rupiah($row['diskon']) ?></td>
                                <td><strong><?= rupiah($row['total_belanja']) ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$reportSales): ?>
                            <tr><td class="empty-cell" colspan="7">Tidak ada transaksi pada periode ini.</td></tr>
                        <?php else: ?>
                            <tr class="report-total-row"><td colspan="4">TOTAL</td><td><?= rupiah($reportSummary['kotor']) ?></td><td class="discount-cell"><?= rupiah($reportSummary['diskon']) ?></td><td><strong><?= rupiah($reportSummary['bersih']) ?></strong></td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="bottom-grid report-grid">
                <article class="panel table-panel">
                    <div class="panel-heading"><div><h2>Penjualan per kategori</h2><p class="muted">Berdasarkan rincian transaksi</p></div></div>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>KATEGORI</th><th>UNIT TERJUAL</th><th>PENDAPATAN</th></tr></thead>
                            <tbody>
                            <?php foreach ($reportCategories as $row): ?>
                                <tr><td><span class="customer-name"><?= e($row['nama_kategori']) ?></span></td><td><?= number_format((int) $row['unit'], 0, ',', '.') ?></td><td><strong><?= rupiah($row['pendapatan']) ?></strong></td></tr>
                            <?php endforeach; ?>
                            <?php if (!$reportCategories): ?><tr><td class="empty-cell" colspan="3">Belum ada rincian produk pada periode ini.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </article>
                <article class="panel table-panel">
                    <div class="panel-heading"><div><h2>Produk terlaris</h2><p class="muted">5 produk dengan unit terjual terbanyak</p></div></div>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>PRODUK</th><th>UNIT TERJUAL</th><th>PENDAPATAN</th></tr></thead>
                            <tbody>
                            <?php foreach ($reportProducts as $row): ?>
                                <tr><td><span class="customer-name"><?= e($row['nama_produk']) ?></span></td><td><?= number_format((int) $row['unit'], 0, ',', '.') ?></td><td><strong><?= rupiah($row['pendapatan']) ?></strong></td></tr>
                            <?php endforeach; ?>
                            <?php if (!$reportProducts): ?><tr><td class="empty-cell" colspan="3">Belum ada rincian produk pada periode ini.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </article>
            </section>

            <section class="panel table-panel">
                <div class="panel-heading"><div><h2>Pelanggan teratas</h2><p class="muted">5 pelanggan dengan total belanja terbesar</p></div></div>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>PELANGGAN</th><th>KOTA</th><th>JUMLAH TRANSAKSI</th><th>TOTAL BELANJA</th></tr></thead>
                        <tbody>
                        <?php foreach ($reportCustomers as $row): ?>
                            <tr><td><span class="customer-name"><?= e($row['nama_pelanggan']) ?></span></td><td><?= e($row['kota'] ?? '-') ?></td><td><?= (int) $row['jumlah'] ?></td><td><strong><?= rupiah($row['total']) ?></strong></td></tr>
                        <?php endforeach; ?>
                        <?php if (!$reportCustomers): ?><tr><td class="empty-cell" colspan="4">Tidak ada data pelanggan pada periode ini.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
            <?php endif; ?>

            <?php if ($page === 'payments'): ?>
            <section class="management-hero payment-settings-hero">
                <div class="management-hero-copy">
                    <span class="hero-icon">▣</span>
                    <div>
                        <p class="eyebrow">PEMBAYARAN NON-TUNAI</p>
                        <h1>Pengaturan pembayaran</h1>
                        <p class="muted">Atur QRIS Dana dan rekening yang akan dicantumkan pada struk kasir.</p>
                    </div>
                </div>
            </section>
            <section class="panel payment-settings-panel">
                <div class="panel-heading">
                    <div><h2>QRIS dan transfer bank</h2><p class="muted">Data ini hanya ditampilkan pada struk jika metode pembayaran terkait dipilih.</p></div>
                </div>
                <form method="post" class="payment-settings-form" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="save_payment_settings">
                    <input type="hidden" name="redirect" value="payments">
                    <div class="payment-settings-grid">
                        <div class="payment-qr-field">
                            <label for="qris_image">Gambar QRIS Dana <small>JPG, PNG, atau WEBP · maksimal 5 MB</small></label>
                            <?php if (!empty($paymentSettings['qris_image'])): ?>
                                <img class="payment-qr-preview" src="<?= e($paymentSettings['qris_image']) ?>" alt="QRIS yang tersimpan">
                                <label class="payment-remove-qr"><input type="checkbox" name="hapus_qris" value="1"> Hapus QRIS tersimpan</label>
                            <?php else: ?>
                                <div class="payment-qr-empty">QRIS Dana belum diatur</div>
                            <?php endif; ?>
                            <input id="qris_image" name="qris_image" type="file" accept="image/jpeg,image/png,image/webp">
                        </div>
                        <div class="payment-bank-fields">
                            <label>Nama bank<input name="nama_bank" maxlength="100" value="<?= e($paymentSettings['nama_bank'] ?? '') ?>" placeholder="Contoh: Bank BCA"></label>
                            <label>Nomor rekening<input name="nomor_rekening" maxlength="50" inputmode="numeric" value="<?= e($paymentSettings['nomor_rekening'] ?? '') ?>" placeholder="Nomor rekening toko"></label>
                            <label>Nama penerima<input name="nama_pemilik" maxlength="100" value="<?= e($paymentSettings['nama_pemilik'] ?? '') ?>" placeholder="Nama pemilik rekening"></label>
                        </div>
                    </div>
                    <p class="payment-settings-note">Isi ketiga data bank atau kosongkan semuanya. Informasi pembayaran ditampilkan pada struk sesuai pilihan kasir.</p>
                    <button class="button button-primary" type="submit">Simpan pengaturan pembayaran</button>
                </form>
            </section>
            <?php endif; ?>

            <?php if ($page === 'users'): ?>
            <section class="management-hero">
                <div class="management-hero-copy">
                    <span class="hero-icon">♧</span>
                    <div><p class="eyebrow">AKSES APLIKASI</p><h1>Pengguna</h1><p class="muted">Kelola akun admin, kasir, dan pelanggan yang dapat masuk ke TokoKita.</p></div>
                </div>
            </section>
            <section class="panel table-panel" id="users">
                <div class="panel-heading management-panel-heading">
                    <div><h2>Daftar akun</h2><p class="muted"><?= count($userRows) ?> akun terdaftar · pelanggan juga dapat mendaftar sendiri di <strong>register.php</strong></p></div>
                    <div class="row-actions">
                    <details class="add-menu">
                        <summary class="button button-primary"><span>＋</span> User pelanggan</summary>
                        <form method="post" class="manager-form">
                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="action" value="create_customer_user">
                            <input type="hidden" name="redirect" value="users">
                            <label>Pelanggan
                                <select name="id_pelanggan" required>
                                    <option value=""><?= $customersWithoutUser ? 'Pilih pelanggan' : 'Semua pelanggan sudah punya akun' ?></option>
                                    <?php foreach ($customersWithoutUser as $customer): ?>
                                        <option value="<?= (int) $customer['id_pelanggan'] ?>"><?= e($customer['nama_pelanggan']) ?> · <?= e($customer['kota'] ?? 'Kota tidak diisi') ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>Username<input name="username" minlength="3" maxlength="50" autocomplete="off" required></label>
                            <label>Kata sandi<input name="password" type="password" minlength="10" maxlength="72" autocomplete="new-password" placeholder="Minimal 10 karakter" required></label>
                            <button class="button button-primary" type="submit">Simpan akun</button>
                        </form>
                    </details>
                    <details class="add-menu">
                        <summary class="button button-light"><span>＋</span> User kasir</summary>
                        <form method="post" class="manager-form">
                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="action" value="create_kasir_user">
                            <input type="hidden" name="redirect" value="users">
                            <label>Username<input name="username" minlength="3" maxlength="50" autocomplete="off" required></label>
                            <label>Kata sandi<input name="password" type="password" minlength="10" maxlength="72" autocomplete="new-password" placeholder="Minimal 10 karakter" required></label>
                            <button class="button button-primary" type="submit">Simpan akun kasir</button>
                        </form>
                    </details>
                    </div>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>USERNAME</th><th>PERAN</th><th>PELANGGAN</th><th>KOTA</th><th>DIBUAT</th><th>AKSI</th></tr></thead>
                        <tbody>
                        <?php foreach ($userRows as $row): ?>
                            <tr>
                                <td><span class="customer-name"><?= e($row['username']) ?></span></td>
                                <td><span class="category-pill"><?= ['admin' => 'Admin', 'kasir' => 'Kasir'][$row['role']] ?? 'Pelanggan' ?></span></td>
                                <td><?= e($row['nama_pelanggan'] ?? '-') ?></td>
                                <td><?= e($row['kota'] ?? '-') ?></td>
                                <td><?= e(tanggalIndonesia($row['dibuat_pada'])) ?></td>
                                <td>
                                    <?php if ($row['role'] !== 'admin'): ?>
                                    <form method="post" class="inline-delete" onsubmit="return confirm('Hapus akun ini? Data pelanggan dan transaksi tetap tersimpan.');">
                                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                        <input type="hidden" name="action" value="delete_user">
                                        <input type="hidden" name="redirect" value="users">
                                        <input type="hidden" name="id_pengguna" value="<?= (int) $row['id_pengguna'] ?>">
                                        <button class="small-action danger-action" type="submit">Hapus akun</button>
                                    </form>
                                    <?php else: ?>—<?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$userRows): ?><tr><td class="empty-cell" colspan="6">Belum ada akun.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
            <?php endif; ?>

            <footer class="page-footer"><span>© <?= date('Y') ?> TokoKita · Sistem Informasi Penjualan</span><span>Dibuat untuk pengelolaan toko yang lebih mudah <b>♥</b></span></footer>
        </div>
    </main>
</div>
<script>
function printReportOnePage() {
    const report = document.querySelector('.report-print-page');
    if (!report) {
        window.print();
        return;
    }

    const availableWidth = 285 * 96 / 25.4;
    const availableHeight = 198 * 96 / 25.4;
    document.body.classList.add('report-one-page');
    report.style.zoom = '1';

    requestAnimationFrame(() => {
        const scale = Math.min(
            1,
            availableWidth / report.scrollWidth,
            availableHeight / report.scrollHeight
        );
        report.style.zoom = String(scale);
        window.addEventListener('afterprint', () => {
            report.style.zoom = '';
            document.body.classList.remove('report-one-page');
        }, { once: true });
        window.print();
    });
}

document.querySelectorAll('[data-filter-input]').forEach((input) => {
    const group = input.dataset.filterInput;
    const items = [...document.querySelectorAll(`[data-filter-item="${group}"]`)];
    const emptyState = document.querySelector(`[data-filter-empty="${group}"]`);
    const visibleCountLabel = document.querySelector(`[data-filter-count="${group}"]`);
    const categoryFilter = document.querySelector(`[data-filter-category="${group}"]`);
    const actionFilter = document.querySelector(`[data-filter-action="${group}"]`);

    const filterItems = () => {
        const query = input.value.trim().toLocaleLowerCase('id');
        const category = categoryFilter?.value ?? '';
        const action = actionFilter?.value ?? '';
        let visibleCount = 0;

        items.forEach((item) => {
            const matchesQuery = item.dataset.search.includes(query);
            const matchesCategory = !category || item.dataset.category === category;
            const matchesAction = !action || item.dataset.action === action;
            item.hidden = !(matchesQuery && matchesCategory && matchesAction);
            if (!item.hidden) visibleCount++;
        });

        if (visibleCountLabel) visibleCountLabel.textContent = visibleCount.toLocaleString('id');
        if (emptyState) emptyState.hidden = visibleCount > 0;
    };

    input.addEventListener('input', filterItems);
    categoryFilter?.addEventListener('change', filterItems);
    actionFilter?.addEventListener('change', filterItems);
});

const productImageInput = document.getElementById('product-image-input');
const productImagePreview = document.getElementById('product-image-preview');
const productImagePreviewSource = document.getElementById('product-image-preview-source');

if (productImageInput && productImagePreview && productImagePreviewSource) {
    const showProductImage = (file) => {
        if (!file || !/^image\/(jpeg|png|webp)$/i.test(file.type)) return;
        const reader = new FileReader();
        reader.onload = (event) => {
            productImagePreviewSource.src = event.target.result;
            productImagePreview.hidden = false;
        };
        reader.readAsDataURL(file);
    };

    productImageInput.addEventListener('change', () => showProductImage(productImageInput.files[0]));

    const productForm = productImageInput.closest('form');
    if (productForm) {
        productForm.addEventListener('paste', (event) => {
            const item = Array.from(event.clipboardData?.items || []).find((entry) => entry.type.startsWith('image/'));
            if (!item) return;
            const file = item.getAsFile();
            if (!file) return;
            const transfer = new DataTransfer();
            transfer.items.add(file);
            productImageInput.files = transfer.files;
            showProductImage(file);
            event.preventDefault();
        });

        productForm.addEventListener('dragover', (event) => event.preventDefault());
        productForm.addEventListener('drop', (event) => {
            const file = Array.from(event.dataTransfer?.files || []).find((entry) => /^image\/(jpeg|png|webp)$/i.test(entry.type));
            if (!file) return;
            const transfer = new DataTransfer();
            transfer.items.add(file);
            productImageInput.files = transfer.files;
            showProductImage(file);
            event.preventDefault();
        });
    }
}
</script>
</body>
</html>
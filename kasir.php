<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
requireAuth();
requireKasir();

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function rupiah(int|float|string|null $value): string
{
    return 'Rp ' . number_format((float) $value, 0, ',', '.');
}

function hitungDiskonKasir(float $subtotal): float
{
    if ($subtotal >= 500000) {
        return round($subtotal * 0.10, 2);
    }
    if ($subtotal >= 250000) {
        return round($subtotal * 0.05, 2);
    }

    return 0.0;
}

function tanggalIndonesia(string $value): string
{
    $timestamp = strtotime($value);
    $months = [
        'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
    ];

    return date('d', $timestamp) . ' ' .
        $months[(int) date('n', $timestamp) - 1] . ' ' .
        date('Y', $timestamp);
}

$user = authUser();
$notice = $_SESSION['notice'] ?? null;
unset($_SESSION['notice']);

$pageError = null;
$customers = [];
$products = [];
$recent = [];
$today = ['jumlah' => 0, 'total' => 0];

try {
    $pdo = tokokuPdo();
    ensureUserSchema($pdo);
    ensurePaymentMethodSchema($pdo);
    ensureCustomerCheckoutSchema($pdo);
    ensureProductBarcodeSchema($pdo);
} catch (PDOException $exception) {
    $pdo = null;
    $pageError = 'Database belum dapat diakses. Pastikan MySQL XAMPP aktif.';
}

/*
|--------------------------------------------------------------------------
| SIMPAN TRANSAKSI
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!($pdo instanceof PDO)) {
            throw new RuntimeException('Koneksi database belum tersedia.');
        }

        if (!verifyAuthCsrf()) {
            throw new RuntimeException('Sesi formulir tidak valid. Muat ulang halaman.');
        }

        $action = (string) ($_POST['action'] ?? 'create_sale');
        if ($action === 'add_customer') {
            $name = trim((string) ($_POST['nama_pelanggan'] ?? ''));
            $city = trim((string) ($_POST['kota'] ?? ''));
            if ($name === '' || mb_strlen($name) > 100 || mb_strlen($city) > 50) {
                throw new RuntimeException('Nama pelanggan wajib diisi (maksimal 100 karakter); kota maksimal 50 karakter.');
            }

            $statement = $pdo->prepare(
                'INSERT INTO tbl_pelanggan (nama_pelanggan, kota) VALUES (?, ?)'
            );
            $statement->execute([$name, $city !== '' ? $city : null]);
            $_SESSION['notice'] = [
                'type' => 'success',
                'text' => 'Pelanggan berhasil ditambahkan dan tersedia untuk transaksi.',
            ];
        } elseif ($action === 'add_stock') {
            $productId = filter_var($_POST['id_produk'] ?? null, FILTER_VALIDATE_INT);
            $amount = filter_var($_POST['jumlah_stok'] ?? null, FILTER_VALIDATE_INT);
            if (!$productId || !$amount || $amount < 1 || $amount > 1000000) {
                throw new RuntimeException('Jumlah stok harus berupa angka antara 1 dan 1.000.000.');
            }

            $statement = $pdo->prepare(
                'UPDATE tbl_produk
                 SET stok = stok + ?
                 WHERE id_produk = ? AND stok + ? <= 2147483647'
            );
            $statement->execute([$amount, $productId, $amount]);
            if ($statement->rowCount() === 0) {
                $productCheck = $pdo->prepare('SELECT stok FROM tbl_produk WHERE id_produk = ?');
                $productCheck->execute([$productId]);
                $currentStock = $productCheck->fetchColumn();
                throw new RuntimeException(
                    $currentStock === false
                        ? 'Produk tidak ditemukan.'
                        : 'Penambahan stok melebihi batas maksimum produk.'
                );
            }

            $_SESSION['notice'] = [
                'type' => 'success',
                'text' => 'Stok produk berhasil ditambah ' . number_format($amount, 0, ',', '.') . ' unit.',
            ];
        } elseif ($action === 'create_sale') {
            $paymentMethod = (string) ($_POST['metode_pembayaran'] ?? '');
            $customerId = filter_var(
                $_POST['id_pelanggan'] ?? null,
                FILTER_VALIDATE_INT
            );

            $productId = filter_var(
                $_POST['id_produk'] ?? null,
                FILTER_VALIDATE_INT
            );

            $quantity = filter_var(
                $_POST['jumlah'] ?? null,
                FILTER_VALIDATE_INT
            );

            if (
                !$customerId ||
                !$productId ||
                !$quantity ||
                $quantity < 1 ||
                $quantity > 1000
            ) {
                throw new RuntimeException(
                    'Pelanggan, produk, dan jumlah wajib diisi dengan benar.'
                );
            }
            if (!in_array($paymentMethod, ['QRIS', 'Tunai', 'Transfer Bank'], true)) {
                throw new RuntimeException('Pilih metode pembayaran QRIS, Tunai, atau Transfer Bank.');
            }

            $pdo->beginTransaction();

            $customerStatement = $pdo->prepare(
                'SELECT id_pelanggan FROM tbl_pelanggan WHERE id_pelanggan = ?'
            );
            $customerStatement->execute([$customerId]);
            if (!$customerStatement->fetch()) {
                throw new RuntimeException('Pelanggan tidak ditemukan.');
            }

            $productStatement = $pdo->prepare(
                'SELECT id_produk, nama_produk, harga, stok
                 FROM tbl_produk WHERE id_produk = ? FOR UPDATE'
            );
            $productStatement->execute([$productId]);
            $product = $productStatement->fetch();
            if (!$product) {
                throw new RuntimeException('Produk tidak ditemukan.');
            }
            if ($quantity > (int) $product['stok']) {
                throw new RuntimeException('Stok produk tidak mencukupi.');
            }

            $subtotal = round((float) $product['harga'] * $quantity, 2);
            if ($subtotal > 9999999999.99) {
                throw new RuntimeException('Total transaksi melebihi batas database.');
            }

            $discount = hitungDiskonKasir($subtotal);
            $total = round($subtotal - $discount, 2);

            $transactionStatement = $pdo->prepare(
                'INSERT INTO tbl_transaksi
                 (tanggal, id_pelanggan, subtotal, diskon, total_belanja, metode_pembayaran)
                 VALUES (CURDATE(), ?, ?, ?, ?, ?)'
            );
            $transactionStatement->execute([
                $customerId,
                $subtotal,
                $discount,
                $total,
                $paymentMethod,
            ]);
            $transactionId = (int) $pdo->lastInsertId();

            $detailStatement = $pdo->prepare(
                'INSERT INTO tbl_detail_transaksi
                 (id_transaksi, id_produk, jumlah, harga_satuan, subtotal)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $detailStatement->execute([
                $transactionId,
                $productId,
                $quantity,
                $product['harga'],
                $subtotal,
            ]);

            $stockStatement = $pdo->prepare(
                'UPDATE tbl_produk SET stok = stok - ?
                 WHERE id_produk = ? AND stok >= ?'
            );
            $stockStatement->execute([$quantity, $productId, $quantity]);
            if ($stockStatement->rowCount() !== 1) {
                throw new RuntimeException('Stok berubah saat transaksi diproses. Silakan coba lagi.');
            }

            $pdo->commit();

            // Langsung menuju halaman struk setelah transaksi berhasil.
            header('Location: struk.php?id=' . $transactionId);
            exit;
        } else {
            throw new RuntimeException('Aksi kasir tidak dikenali.');
        }

    } catch (PDOException $exception) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $message = $exception->getCode() === '45000'
            ? (string) ($exception->errorInfo[2] ?? 'Transaksi tidak dapat diproses.')
            : 'Transaksi gagal disimpan. Periksa database dan coba lagi.';

        $_SESSION['notice'] = [
            'type' => 'error',
            'text' => $message
        ];

    } catch (Throwable $exception) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['notice'] = [
            'type' => 'error',
            'text' => $exception->getMessage()
        ];
    }

    header('Location: kasir.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| AMBIL DATA UNTUK HALAMAN KASIR
|--------------------------------------------------------------------------
*/

if ($pdo instanceof PDO) {
    try {
        $customers = $pdo->query(
            'SELECT id_pelanggan, nama_pelanggan, kota
             FROM tbl_pelanggan
             ORDER BY nama_pelanggan'
        )->fetchAll();

        $products = $pdo->query(
            'SELECT
                pr.id_produk,
                pr.nama_produk,
                pr.barcode,
                pr.harga,
                pr.stok,
                k.nama_kategori
             FROM tbl_produk AS pr
             JOIN tbl_kategori AS k
                ON k.id_kategori = pr.id_kategori
             ORDER BY pr.nama_produk'
        )->fetchAll();

        $recent = $pdo->query(
            'SELECT
                id_transaksi,
                tanggal,
                nama_pelanggan,
                subtotal,
                diskon,
                total_belanja
             FROM vw_laporan_penjualan
             ORDER BY tanggal DESC, id_transaksi DESC
             LIMIT 15'
        )->fetchAll();

        $today = $pdo->query(
            'SELECT
                COUNT(*) AS jumlah,
                COALESCE(SUM(total_belanja), 0) AS total
             FROM tbl_transaksi
             WHERE tanggal = CURDATE()'
        )->fetch() ?: $today;

    } catch (PDOException $exception) {
        $pageError = 'Data belum dapat dimuat. Periksa tabel database.';
    }
}
?>

<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f5f6fa">

    <title>Kasir — TokoKita</title>

    <link rel="stylesheet" href="style.css">
</head>

<body>

<header class="topbar">
    <div class="breadcrumb">
        TokoKita <span>/</span> <strong>Kasir</strong>
    </div>

    <div class="topbar-right">
        <span class="today-date">
            <?= e($user['username']) ?> · Kasir
        </span>

        <span class="top-avatar">
            <?= e(mb_strtoupper(mb_substr($user['username'], 0, 1))) ?>
        </span>

        <form method="post" action="logout.php" class="top-logout-form">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= e(authCsrfToken()) ?>"
            >

            <button class="logout-button" type="submit">
                Logout <span>↗</span>
            </button>
        </form>
    </div>
</header>

<div class="page-content cashier-area">

    <?php if ($pageError): ?>
        <div class="alert error" role="alert">
            <?= e($pageError) ?>
        </div>
    <?php endif; ?>

    <?php if ($notice): ?>
        <div class="alert <?= e($notice['type']) ?>" role="status">
            <?= e($notice['text']) ?>
        </div>
    <?php endif; ?>

    <section class="management-hero">
        <div class="management-hero-copy">
            <span class="hero-icon">▤</span>

            <div>
                <p class="eyebrow">KASIR TOKOKITA</p>

                <h1>
                    Halo, <?= e($user['username']) ?>
                </h1>

                <p class="muted">
                    Kelola transaksi penjualan dan cetak struk pelanggan.
                </p>
            </div>
        </div>
    </section>

    <!-- RINGKASAN TRANSAKSI -->

    <section class="management-stats customer-stats">

        <article class="management-stat">
            <span class="management-stat-icon purple-icon">▤</span>

            <span class="management-stat-copy">
                <small>Transaksi hari ini</small>

                <strong>
                    <?= number_format((int) $today['jumlah'], 0, ',', '.') ?>
                </strong>

                <em>Tercatat hari ini</em>
            </span>
        </article>

        <article class="management-stat">
            <span class="management-stat-icon green-icon">✓</span>

            <span class="management-stat-copy">
                <small>Penjualan hari ini</small>

                <strong>
                    <?= rupiah($today['total']) ?>
                </strong>

                <em>Setelah diskon</em>
            </span>
        </article>

    </section>

    <section class="panel cashier-customer-panel" id="tambah-pelanggan">
        <div class="panel-heading">
            <div>
                <h2>Tambah pelanggan</h2>
                <p class="muted">Pelanggan baru langsung tersedia di pilihan transaksi.</p>
            </div>
            <span class="quick-icon" aria-hidden="true">＋</span>
        </div>
        <form method="post" class="cashier-customer-form">
            <input type="hidden" name="csrf_token" value="<?= e(authCsrfToken()) ?>">
            <input type="hidden" name="action" value="add_customer">
            <label>Nama pelanggan<input name="nama_pelanggan" maxlength="100" required></label>
            <label>Kota <span>(opsional)</span><input name="kota" maxlength="50"></label>
            <button class="button button-primary" type="submit">Tambah pelanggan <span>＋</span></button>
        </form>
    </section>

    <!-- FORM TRANSAKSI -->

    <article class="panel quick-panel" id="new-sale">

        <div class="panel-heading">
            <div>
                <h2>Transaksi baru</h2>
                <p class="muted">
                    Masukkan pelanggan dan produk yang dibeli.
                </p>
            </div>

            <span class="quick-icon">＋</span>
        </div>

        <form method="post" class="sale-form">

            <label for="barcode_produk">Barcode / SKU produk</label>
            <div class="quantity-row">
                <input
                    id="barcode_produk"
                    name="barcode_produk"
                    type="text"
                    autocomplete="off"
                    placeholder="Pindai atau ketik SKU-0001"
                >
                <button id="barcode_pilih" class="button button-light" type="button">Pilih</button>
            </div>
            <small id="barcode_status" class="muted" aria-live="polite"></small>

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e(authCsrfToken()) ?>"
            >

            <label for="id_pelanggan">
                Pelanggan
            </label>

            <select id="id_pelanggan" name="id_pelanggan" required>
                <option value="">Pilih pelanggan</option>

                <?php foreach ($customers as $customer): ?>
                    <option value="<?= (int) $customer['id_pelanggan'] ?>">
                        <?= e($customer['nama_pelanggan']) ?>
                        ·
                        <?= e($customer['kota'] ?? 'Kota tidak diisi') ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="id_produk">
                Produk
            </label>

            <select id="id_produk" name="id_produk" required>
                <option value="">Pilih produk</option>

                <?php foreach ($products as $product): ?>
                    <option
                        value="<?= (int) $product['id_produk'] ?>"
                        data-barcode="<?= e(mb_strtoupper(trim((string) ($product['barcode'] ?? '')))) ?>"
                        <?= (int) $product['stok'] < 1 ? 'disabled' : '' ?>
                    >
                        <?= e($product['nama_produk']) ?>
                        · <?= rupiah($product['harga']) ?>
                        (stok <?= (int) $product['stok'] ?>)
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="metode_pembayaran">Metode pembayaran</label>
            <select id="metode_pembayaran" name="metode_pembayaran" required>
                <option value="">Pilih metode pembayaran</option>
                <option value="QRIS">QRIS Dana</option>
                <option value="Tunai">Tunai</option>
                <option value="Transfer Bank">Transfer Bank</option>
            </select>

            <div class="quantity-row">
                <label for="jumlah">
                    Jumlah
                </label>

                <input
                    id="jumlah"
                    name="jumlah"
                    type="number"
                    min="1"
                    max="1000"
                    value="1"
                    required
                >
            </div>

            <button
                class="button button-primary submit-button"
                type="submit"
            >
                Simpan transaksi <span>→</span>
            </button>

            <p class="form-hint">
                Diskon dan pengurangan stok dihitung otomatis oleh sistem.
                Setelah berhasil, struk akan langsung ditampilkan.
            </p>

        </form>
    </article>

    <script>
    (function () {
        const input = document.getElementById('barcode_produk');
        const button = document.getElementById('barcode_pilih');
        const productSelect = document.getElementById('id_produk');
        const quantityInput = document.getElementById('jumlah');
        const status = document.getElementById('barcode_status');

        function pilihProdukBarcode() {
            const code = input.value.trim().toUpperCase();
            if (!code) return;

            const option = Array.from(productSelect.options).find((item) =>
                item.dataset.barcode === code && !item.disabled
            );

            if (!option) {
                status.textContent = 'Barcode tidak ditemukan. Isi Barcode pada menu Produk Admin terlebih dahulu.';
                return;
            }

            productSelect.value = option.value;
            status.textContent = 'Produk dipilih: ' + option.textContent.trim();
            input.value = '';
            quantityInput.focus();
            quantityInput.select();
        }

        button.addEventListener('click', pilihProdukBarcode);
        input.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                pilihProdukBarcode();
            }
        });
    })();
    </script>

    <!-- DAFTAR STOK PRODUK -->

    <section class="panel table-panel" id="stok">

        <div class="panel-heading">
            <div>
                <h2>Stok produk</h2>
                <p class="muted">Informasi persediaan produk toko.</p>
            </div>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>PRODUK</th>
                        <th>KATEGORI</th>
                        <th>HARGA</th>
                        <th>STOK</th>
                        <th>STATUS</th>
                        <th>TAMBAH STOK</th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($products as $product): ?>
                        <tr>
                            <td>
                                <span class="customer-name">
                                    <?= e($product['nama_produk']) ?>
                                </span>
                            </td>

                            <td>
                                <span class="category-pill">
                                    <?= e($product['nama_kategori']) ?>
                                </span>
                            </td>

                            <td>
                                <strong class="price-value">
                                    <?= rupiah($product['harga']) ?>
                                </strong>
                            </td>

                            <td>
                                <span class="stock-value">
                                    <?= number_format((int) $product['stok'], 0, ',', '.') ?>
                                    <small>unit</small>
                                </span>
                            </td>

                            <td>
                                <span class="product-status <?= (int) $product['stok'] <= 5 ? 'status-warning' : 'status-ready' ?>">
                                    <i></i>

                                    <?= (int) $product['stok'] <= 5
                                        ? 'Stok menipis'
                                        : 'Tersedia' ?>
                                </span>
                            </td>

                            <td>
                                <form method="post" class="cashier-stock-form">
                                    <input type="hidden" name="csrf_token" value="<?= e(authCsrfToken()) ?>">
                                    <input type="hidden" name="action" value="add_stock">
                                    <input type="hidden" name="id_produk" value="<?= (int) $product['id_produk'] ?>">
                                    <label class="cashier-stock-label" for="tambah-stok-<?= (int) $product['id_produk'] ?>">Jumlah stok untuk <?= e($product['nama_produk']) ?></label>
                                    <input id="tambah-stok-<?= (int) $product['id_produk'] ?>" name="jumlah_stok" type="number" min="1" max="1000000" value="1" required>
                                    <button class="button button-light" type="submit" aria-label="Tambah stok <?= e($product['nama_produk']) ?>">＋ Tambah</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (!$products): ?>
                        <tr>
                            <td class="empty-cell" colspan="6">
                                Data produk belum tersedia.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- RIWAYAT TRANSAKSI -->

    <section class="panel table-panel" id="sales">

        <div class="panel-heading">
            <div>
                <h2>Riwayat transaksi</h2>
                <p class="muted">
                    15 transaksi terakhir.
                </p>
            </div>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>ID TRANSAKSI</th>
                        <th>TANGGAL</th>
                        <th>PELANGGAN</th>
                        <th>SUBTOTAL</th>
                        <th>DISKON</th>
                        <th>TOTAL</th>
                        <th>AKSI</th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($recent as $row): ?>
                        <tr>
                            <td>
                                <span class="transaction-id">
                                    #<?= (int) $row['id_transaksi'] ?>
                                </span>
                            </td>

                            <td>
                                <?= e(tanggalIndonesia($row['tanggal'])) ?>
                            </td>

                            <td>
                                <span class="customer-name">
                                    <?= e($row['nama_pelanggan']) ?>
                                </span>
                            </td>

                            <td>
                                <?= rupiah($row['subtotal']) ?>
                            </td>

                            <td class="discount-cell">
                                <?= rupiah($row['diskon']) ?>
                            </td>

                            <td>
                                <strong>
                                    <?= rupiah($row['total_belanja']) ?>
                                </strong>
                            </td>

                            <td>
                                <a
                                    class="button button-primary"
                                    href="struk.php?id=<?= (int) $row['id_transaksi'] ?>"
                                    target="_blank"
                                    rel="noopener"
                                >
                                    Cetak struk
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (!$recent): ?>
                        <tr>
                            <td class="empty-cell" colspan="7">
                                Belum ada transaksi.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <footer class="page-footer">
        <span>
            © <?= date('Y') ?> TokoKita · Sistem Informasi Penjualan
        </span>
    </footer>

</div>

</body>
</html>

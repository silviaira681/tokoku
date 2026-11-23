<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
requireAuth();
requirePelanggan();

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function rupiah(int|float|string|null $value): string
{
    return 'Rp ' . number_format((float) $value, 0, ',', '.');
}

function hitungDiskonKeranjang(float $subtotal): float
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
    $months = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    return date('d', $timestamp) . ' ' . $months[(int) date('n', $timestamp) - 1] . ' ' . date('Y', $timestamp);
}

function gambarProduk(string $name, string $category): string
{
    $search = mb_strtolower($name . ' ' . $category, 'UTF-8');
    $images = [
        ['keywords' => ['kursi', 'chair'], 'url' => 'photo-1503602642458-232111445657'],
        ['keywords' => ['meja', 'table'], 'url' => 'photo-1533090481720-856c6e3c1fdc'],
        ['keywords' => ['mouse'], 'url' => 'photo-1527814050087-3793815479db'],
        ['keywords' => ['keyboard'], 'url' => 'photo-1587829741301-dc798b83add3'],
        ['keywords' => ['flashdisk', 'penyimpanan', 'storage'], 'url' => 'photo-1597872200969-2b65d56bd16b'],
        ['keywords' => ['headphone', 'audio', 'speaker'], 'url' => 'photo-1505740420928-5e560c06d30e'],
        ['keywords' => ['buku', 'book'], 'url' => 'photo-1544947950-fa07a98d237f'],
    ];

    foreach ($images as $image) {
        foreach ($image['keywords'] as $keyword) {
            if (str_contains($search, $keyword)) {
                return 'https://images.unsplash.com/' . $image['url'] . '?auto=format&fit=crop&w=800&q=82';
            }
        }
    }

    return 'https://images.unsplash.com/photo-1517336714731-489689fd1ca8?auto=format&fit=crop&w=800&q=82';
}

$user = authUser();
$customerId = (int) ($user['id_pelanggan'] ?? 0);
$notice = $_SESSION['notice'] ?? null;
unset($_SESSION['notice']);
$pageError = null;
$products = $orders = [];
$items = [];
$cart = $_SESSION['cart'] ?? [];
if (!is_array($cart)) {
    $cart = [];
}
$customer = ['nama_pelanggan' => $user['username'], 'kota' => null];

try {
    $pdo = tokokuPdo();
    ensureUserSchema($pdo);
    ensureProductImageSchema($pdo);
    ensureCustomerCheckoutSchema($pdo);
} catch (PDOException $exception) {
    $pdo = null;
    $pageError = 'Database belum dapat diakses. Pastikan MySQL XAMPP aktif.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!($pdo instanceof PDO)) {
            throw new RuntimeException('Koneksi database belum tersedia.');
        }
        if (!verifyAuthCsrf()) {
            throw new RuntimeException('Sesi formulir tidak valid. Silakan muat ulang halaman.');
        }
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'add_to_cart' || $action === 'update_cart') {
            $productId = filter_var($_POST['id_produk'] ?? null, FILTER_VALIDATE_INT);
            $quantity = filter_var($_POST['jumlah'] ?? null, FILTER_VALIDATE_INT);
            if (!$productId || !$quantity || $quantity < 1 || $quantity > 1000) {
                throw new RuntimeException('Jumlah produk harus antara 1 dan 1.000.');
            }

            $stockStatement = $pdo->prepare('SELECT stok FROM tbl_produk WHERE id_produk = ?');
            $stockStatement->execute([$productId]);
            $availableStock = $stockStatement->fetchColumn();
            if ($availableStock === false) {
                throw new RuntimeException('Produk tidak ditemukan.');
            }

            $cartQuantity = $action === 'add_to_cart'
                ? (int) ($cart[$productId] ?? 0) + $quantity
                : $quantity;
            if ($cartQuantity > (int) $availableStock) {
                throw new RuntimeException('Jumlah melebihi stok produk yang tersedia.');
            }

            $cart[$productId] = $cartQuantity;
            $_SESSION['cart'] = $cart;
            $_SESSION['notice'] = [
                'type' => 'success',
                'text' => $action === 'add_to_cart'
                    ? 'Produk berhasil ditambahkan ke keranjang.'
                    : 'Jumlah produk di keranjang diperbarui.',
            ];
        } elseif ($action === 'remove_from_cart') {
            $productId = filter_var($_POST['id_produk'] ?? null, FILTER_VALIDATE_INT);
            if ($productId) {
                unset($cart[$productId]);
            }
            $_SESSION['cart'] = $cart;
            $_SESSION['notice'] = ['type' => 'success', 'text' => 'Produk dihapus dari keranjang.'];
        } elseif ($action === 'clear_cart') {
            $cart = [];
            $_SESSION['cart'] = [];
            $_SESSION['notice'] = ['type' => 'success', 'text' => 'Keranjang dikosongkan.'];
        } elseif ($action === 'checkout_cart') {
            if (!$customerId || !$cart) {
                throw new RuntimeException('Keranjang masih kosong.');
            }

            ksort($cart, SORT_NUMERIC);
            try {
                $pdo->beginTransaction();
                $lockProduct = $pdo->prepare(
                    'SELECT id_produk, nama_produk, harga, stok
                     FROM tbl_produk WHERE id_produk = ? FOR UPDATE'
                );
                $lockedProducts = [];
                $subtotal = 0.0;

                foreach ($cart as $productId => $quantity) {
                    $productId = filter_var($productId, FILTER_VALIDATE_INT);
                    $quantity = filter_var($quantity, FILTER_VALIDATE_INT);
                    if (!$productId || !$quantity || $quantity < 1 || $quantity > 1000) {
                        throw new RuntimeException('Isi keranjang tidak valid.');
                    }

                    $lockProduct->execute([$productId]);
                    $product = $lockProduct->fetch();
                    if (!$product) {
                        throw new RuntimeException('Salah satu produk di keranjang sudah tidak tersedia.');
                    }
                    if ($quantity > (int) $product['stok']) {
                        throw new RuntimeException('Stok ' . $product['nama_produk'] . ' tidak mencukupi.');
                    }

                    $product['jumlah'] = $quantity;
                    $product['subtotal'] = (float) $product['harga'] * $quantity;
                    $subtotal += $product['subtotal'];
                    $lockedProducts[] = $product;
                }

                if ($subtotal > 9999999999.99) {
                    throw new RuntimeException('Total keranjang melebihi batas transaksi.');
                }

                $discount = hitungDiskonKeranjang($subtotal);
                $totals = [
                    'diskon' => $discount,
                    'total_belanja' => round($subtotal - $discount, 2),
                ];

                $transactionStatement = $pdo->prepare(
                    'INSERT INTO tbl_transaksi (tanggal, id_pelanggan, subtotal, diskon, total_belanja)
                     VALUES (CURDATE(), ?, ?, ?, ?)'
                );
                $transactionStatement->execute([
                    $customerId,
                    $subtotal,
                    $totals['diskon'],
                    $totals['total_belanja'],
                ]);
                $transactionId = (int) $pdo->lastInsertId();

                $detailStatement = $pdo->prepare(
                    'INSERT INTO tbl_detail_transaksi (id_transaksi, id_produk, jumlah, harga_satuan, subtotal)
                     VALUES (?, ?, ?, ?, ?)'
                );
                $stockUpdate = $pdo->prepare(
                    'UPDATE tbl_produk SET stok = stok - ? WHERE id_produk = ? AND stok >= ?'
                );
                foreach ($lockedProducts as $product) {
                    $detailStatement->execute([
                        $transactionId,
                        $product['id_produk'],
                        $product['jumlah'],
                        $product['harga'],
                        $product['subtotal'],
                    ]);
                    $stockUpdate->execute([
                        $product['jumlah'],
                        $product['id_produk'],
                        $product['jumlah'],
                    ]);
                    if ($stockUpdate->rowCount() !== 1) {
                        throw new RuntimeException('Stok ' . $product['nama_produk'] . ' berubah. Silakan coba lagi.');
                    }
                }

                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }

            $_SESSION['cart'] = [];
            header('Location: struk.php?id=' . $transactionId);
            exit;
        } else {
            throw new RuntimeException('Aksi keranjang tidak dikenali.');
        }
    } catch (PDOException $exception) {
        $_SESSION['notice'] = [
            'type' => 'error',
            'text' => $exception->getCode() === '45000'
                ? (string) ($exception->errorInfo[2] ?? 'Pesanan tidak dapat diproses.')
                : 'Pesanan gagal disimpan. Silakan coba lagi.',
        ];
    } catch (Throwable $exception) {
        $_SESSION['notice'] = ['type' => 'error', 'text' => $exception->getMessage()];
    }

    header('Location: pelanggan.php#keranjang');
    exit;
}

if ($pdo instanceof PDO) {
    try {
        $statement = $pdo->prepare('SELECT nama_pelanggan, kota FROM tbl_pelanggan WHERE id_pelanggan = ?');
        $statement->execute([$customerId]);
        $customer = $statement->fetch() ?: $customer;

        $products = $pdo->query(
            'SELECT pr.id_produk, pr.nama_produk, pr.gambar, pr.harga, pr.stok, k.nama_kategori
             FROM tbl_produk AS pr
             JOIN tbl_kategori AS k ON k.id_kategori = pr.id_kategori
             ORDER BY pr.nama_produk'
        )->fetchAll();

        $statement = $pdo->prepare(
            'SELECT id_transaksi, tanggal, subtotal, diskon, total_belanja
             FROM tbl_transaksi WHERE id_pelanggan = ?
             ORDER BY tanggal DESC, id_transaksi DESC LIMIT 50'
        );
        $statement->execute([$customerId]);
        $orders = $statement->fetchAll();

        $statement = $pdo->prepare(
            'SELECT d.id_transaksi, pr.nama_produk, d.jumlah
             FROM tbl_detail_transaksi AS d
             JOIN tbl_transaksi AS t ON t.id_transaksi = d.id_transaksi
             JOIN tbl_produk AS pr ON pr.id_produk = d.id_produk
             WHERE t.id_pelanggan = ?
             ORDER BY d.id_detail'
        );
        $statement->execute([$customerId]);
        foreach ($statement->fetchAll() as $row) {
            $items[(int) $row['id_transaksi']][] = $row['nama_produk'] . ' ×' . (int) $row['jumlah'];
        }
    } catch (PDOException $exception) {
        $pageError = 'Data belum dapat dimuat. Detail: ' . $exception->getMessage();
    }
}

$cartProducts = [];
$cartItemCount = 0;
$cartSubtotal = 0.0;
$cartDiscount = 0.0;
$cartTotal = 0.0;
$productById = [];
foreach ($products as $product) {
    $productById[(int) $product['id_produk']] = $product;
}
foreach ($cart as $productId => $quantity) {
    $productId = filter_var($productId, FILTER_VALIDATE_INT);
    $quantity = filter_var($quantity, FILTER_VALIDATE_INT);
    if (!$productId || !$quantity || $quantity < 1 || !isset($productById[$productId])) {
        unset($cart[$productId]);
        continue;
    }

    $product = $productById[$productId];
    if ((int) $product['stok'] < 1) {
        unset($cart[$productId]);
        continue;
    }
    if ($quantity > (int) $product['stok']) {
        $quantity = (int) $product['stok'];
        $cart[$productId] = $quantity;
    }

    $product['jumlah_keranjang'] = $quantity;
    $product['subtotal_keranjang'] = (float) $product['harga'] * $quantity;
    $cartProducts[] = $product;
    $cartItemCount += $quantity;
    $cartSubtotal += $product['subtotal_keranjang'];
}
$_SESSION['cart'] = $cart;
$cartSubtotal = round($cartSubtotal, 2);

if ($cartSubtotal > 0) {
    $cartDiscount = hitungDiskonKeranjang($cartSubtotal);
    $cartTotal = round($cartSubtotal - $cartDiscount, 2);
}

$totalSpent = array_sum(array_map(static fn (array $o): float => (float) $o['total_belanja'], $orders));
$categoryCounts = [];
foreach ($products as $product) {
    $category = (string) $product['nama_kategori'];
    $categoryCounts[$category] = ($categoryCounts[$category] ?? 0) + 1;
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f5f4f0">
    <title>TokoKita — Belanja pilihan harian</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body id="top" class="storefront-body">
<header class="store-header">
    <div class="store-ribbon">Pilihan tepat untuk kebutuhan sehari-hari <span>·</span> Belanja mudah, stok selalu diperbarui</div>
    <div class="store-header-main">
        <a class="store-brand" href="pelanggan.php" aria-label="TokoKita beranda">
            <span class="store-brand-mark">T</span>
            <span class="store-brand-copy">TokoKita<small>EVERYDAY GOODS</small></span>
        </a>
        <label class="store-search">
            <span aria-hidden="true">⌕</span>
            <input id="product-search" type="search" placeholder="Cari produk dan kategori..." aria-label="Cari produk dan kategori">
        </label>
        <nav class="store-account-nav" aria-label="Akun pelanggan">
            <a class="store-order-link" href="#riwayat"><span class="store-nav-icon" aria-hidden="true">▤</span><span>Pesanan<small><?= number_format(count($orders), 0, ',', '.') ?> transaksi</small></span></a>
            <a class="store-cart-link" href="#keranjang" aria-label="Keranjang, <?= number_format($cartItemCount, 0, ',', '.') ?> item"><span class="store-cart-icon" aria-hidden="true">🛒</span><span class="store-cart-copy">Keranjang<small><?= number_format($cartItemCount, 0, ',', '.') ?> item</small></span><strong><?= number_format($cartItemCount, 0, ',', '.') ?></strong></a>
            <span class="store-user-mark" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($customer['nama_pelanggan'], 0, 1))) ?></span>
            <span class="store-user-name"><?= e($customer['nama_pelanggan']) ?></span>
            <form method="post" action="logout.php" class="store-logout-form">
                <input type="hidden" name="csrf_token" value="<?= e(authCsrfToken()) ?>">
                <button type="submit" aria-label="Keluar dari akun">Keluar <span aria-hidden="true">↗</span></button>
            </form>
            <a class="store-switch-link" href="login.php?switch=1">Ganti akun</a>
        </nav>
    </div>
    <nav class="store-subnav" aria-label="Navigasi toko">
        <a href="#kategori">Kategori</a>
        <a href="#produk">Semua produk</a>
        <a href="#riwayat">Riwayat pesanan</a>
        <span>Diskon otomatis pada transaksi tertentu</span>
    </nav>
</header>

<main class="store-main">
    <?php if ($pageError): ?><div class="store-alert store-alert-error" role="alert"><?= e($pageError) ?></div><?php endif; ?>
    <?php if ($notice): ?><div class="store-alert store-alert-<?= e($notice['type']) ?>" role="status"><?= e($notice['text']) ?></div><?php endif; ?>

    <section class="store-hero" aria-labelledby="store-hero-title">
        <div class="store-hero-copy">
            <p class="store-kicker"><span></span> TEMUKAN YANG KAMU BUTUHKAN</p>
            <h1 id="store-hero-title">Hal kecil yang<br><em>membuat hari.</em></h1>
            <p>Produk pilihan untuk menemani aktivitasmu, dari meja kerja sampai waktu santai.</p>
            <a class="store-hero-link" href="#produk">Jelajahi pilihan <span aria-hidden="true">→</span></a>
            <div class="store-hero-note"><span>01</span><span>Kurasi simpel, belanja nyaman.</span></div>
        </div>
        <div class="store-hero-image" role="img" aria-label="Ruang kerja modern dengan laptop dan cahaya alami">
            <span class="store-image-label">TOKOKITA / SELECTED</span>
            <span class="store-image-index">01 — 03</span>
        </div>
        <div class="store-hero-side"><span>DAILY ESSENTIALS</span><span>EST. 2026</span></div>
    </section>

    <div class="store-benefits" aria-label="Layanan toko">
        <div><span class="benefit-icon">↗</span><span><strong>Belanja praktis</strong><small>Pesan langsung dari katalog</small></span></div>
        <div><span class="benefit-icon benefit-green">✓</span><span><strong>Stok aktual</strong><small>Ketersediaan diperbarui otomatis</small></span></div>
        <div><span class="benefit-icon benefit-blue">▤</span><span><strong>Pesanan tercatat</strong><small>Lihat transaksi kapan saja</small></span></div>
    </div>

    <section class="store-cart-section" id="keranjang" aria-labelledby="cart-title">
        <div class="store-section-heading store-cart-heading">
            <div><p class="store-overline">PILIHANMU</p><h2 id="cart-title">Keranjang belanja</h2></div>
            <span class="store-section-meta"><?= number_format($cartItemCount, 0, ',', '.') ?> item</span>
        </div>
        <?php if ($cartProducts): ?>
            <div class="store-cart-layout">
                <div class="store-cart-list">
                    <?php foreach ($cartProducts as $product): ?>
                        <article class="store-cart-item">
                            <img src="<?= e(($product['gambar'] ?: gambarProduk($product['nama_produk'], $product['nama_kategori'])) . (str_starts_with($product['gambar'] ?? '', 'uploads/products/') && is_file(__DIR__ . '/' . $product['gambar']) ? '?v=' . filemtime(__DIR__ . '/' . $product['gambar']) : '')) ?>" alt="" loading="lazy">
                            <div class="store-cart-product-info">
                                <span><?= e($product['nama_kategori']) ?></span>
                                <strong><?= e($product['nama_produk']) ?></strong>
                                <small><?= rupiah($product['harga']) ?> · Stok <?= number_format((int) $product['stok'], 0, ',', '.') ?></small>
                            </div>
                            <form method="post" class="store-cart-quantity-form">
                                <input type="hidden" name="csrf_token" value="<?= e(authCsrfToken()) ?>">
                                <input type="hidden" name="action" value="update_cart">
                                <input type="hidden" name="id_produk" value="<?= (int) $product['id_produk'] ?>">
                                <label class="store-quantity-label" for="cart-quantity-<?= (int) $product['id_produk'] ?>">Jumlah <?= e($product['nama_produk']) ?></label>
                                <input id="cart-quantity-<?= (int) $product['id_produk'] ?>" name="jumlah" type="number" min="1" max="<?= min(1000, (int) $product['stok']) ?>" value="<?= (int) $product['jumlah_keranjang'] ?>" required>
                                <button type="submit" aria-label="Perbarui jumlah <?= e($product['nama_produk']) ?>">Perbarui</button>
                            </form>
                            <strong class="store-cart-line-total"><?= rupiah($product['subtotal_keranjang']) ?></strong>
                            <form method="post" class="store-cart-remove-form">
                                <input type="hidden" name="csrf_token" value="<?= e(authCsrfToken()) ?>">
                                <input type="hidden" name="action" value="remove_from_cart">
                                <input type="hidden" name="id_produk" value="<?= (int) $product['id_produk'] ?>">
                                <button type="submit" aria-label="Hapus <?= e($product['nama_produk']) ?> dari keranjang">×</button>
                            </form>
                        </article>
                    <?php endforeach; ?>
                </div>
                <aside class="store-cart-summary" aria-label="Ringkasan keranjang">
                    <p class="store-overline">RINGKASAN</p>
                    <div><span>Subtotal</span><strong><?= rupiah($cartSubtotal) ?></strong></div>
                    <div><span>Diskon</span><strong class="store-cart-discount">− <?= rupiah($cartDiscount) ?></strong></div>
                    <div class="store-cart-total"><span>Total</span><strong><?= rupiah($cartTotal) ?></strong></div>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= e(authCsrfToken()) ?>">
                        <input type="hidden" name="action" value="checkout_cart">
                        <button class="store-checkout-button" type="submit">Lanjutkan pembayaran <span aria-hidden="true">→</span></button>
                    </form>
                    <form method="post" class="store-clear-cart-form">
                        <input type="hidden" name="csrf_token" value="<?= e(authCsrfToken()) ?>">
                        <input type="hidden" name="action" value="clear_cart">
                        <button type="submit">Kosongkan keranjang</button>
                    </form>
                </aside>
            </div>
        <?php else: ?>
            <div class="store-empty-cart"><span aria-hidden="true">▱</span><div><strong>Keranjangmu masih kosong</strong><p>Pilih produk di katalog untuk mulai menambahkan barang.</p></div><a href="#produk">Lihat produk <span aria-hidden="true">→</span></a></div>
        <?php endif; ?>
    </section>

    <section class="store-section" id="kategori">
        <div class="store-section-heading">
            <div><p class="store-overline">PILIH JALURMU</p><h2>Belanja berdasarkan kategori</h2></div>
            <span class="store-section-meta"><?= number_format(count($categoryCounts), 0, ',', '.') ?> kategori</span>
        </div>
        <div class="store-category-list" role="group" aria-label="Filter kategori produk">
            <button class="store-category is-active" type="button" data-category-filter="*"><span class="category-monogram">TK</span><span>Semua<small><?= count($products) ?> produk</small></span><i aria-hidden="true">↗</i></button>
            <?php foreach ($categoryCounts as $category => $count): ?>
                <button class="store-category" type="button" data-category-filter="<?= e(mb_strtolower($category, 'UTF-8')) ?>"><span class="category-monogram"><?= e(mb_strtoupper(mb_substr($category, 0, 2))) ?></span><span><?= e($category) ?><small><?= number_format($count, 0, ',', '.') ?> produk</small></span><i aria-hidden="true">↗</i></button>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="store-feature-band" aria-label="Informasi belanja">
        <div class="feature-band-mark">K</div>
        <div><p class="store-overline">PILIHAN YANG LEBIH MUDAH</p><h2>Temukan kebutuhanmu, satu pesanan dalam satu waktu.</h2></div>
        <a href="#produk" aria-label="Lihat produk">Lihat produk <span aria-hidden="true">→</span></a>
    </section>

    <section class="store-section store-products-section" id="produk">
        <div class="store-section-heading store-products-heading">
            <div><p class="store-overline">DARI TOKOKITA</p><h2>Pilihan untukmu</h2><p class="store-section-description">Produk yang tersedia di katalog kami, siap dipesan langsung.</p></div>
            <span class="store-section-meta"><strong id="visible-product-count"><?= number_format(count($products), 0, ',', '.') ?></strong> produk</span>
        </div>
        <div class="store-product-grid" id="product-grid">
            <?php foreach ($products as $product): ?>
                <?php $productCategory = mb_strtolower((string) $product['nama_kategori'], 'UTF-8'); ?>
                <article class="store-product" data-product-card data-category="<?= e($productCategory) ?>" data-search="<?= e(mb_strtolower($product['nama_produk'] . ' ' . $product['nama_kategori'], 'UTF-8')) ?>">
                    <div class="store-product-visual">
                        <img src="<?= e(($product['gambar'] ?: gambarProduk($product['nama_produk'], $product['nama_kategori'])) . (str_starts_with($product['gambar'] ?? '', 'uploads/products/') && is_file(__DIR__ . '/' . $product['gambar']) ? '?v=' . filemtime(__DIR__ . '/' . $product['gambar']) : '')) ?>" alt="<?= e($product['nama_produk']) ?>" loading="lazy">
                        <?php if ((int) $product['stok'] > 0): ?>
                            <span class="store-stock-label"><i></i> Tersedia</span>
                        <?php else: ?>
                            <span class="store-stock-label is-empty"><i></i> Habis</span>
                        <?php endif; ?>
                    </div>
                    <div class="store-product-details">
                        <p class="store-product-category"><?= e($product['nama_kategori']) ?></p>
                        <h3><?= e($product['nama_produk']) ?></h3>
                        <div class="store-product-meta"><strong><?= rupiah($product['harga']) ?></strong><span>Stok <?= number_format((int) $product['stok'], 0, ',', '.') ?></span></div>
                        <?php if ((int) $product['stok'] > 0): ?>
                            <form method="post" class="store-buy-form">
                                <input type="hidden" name="csrf_token" value="<?= e(authCsrfToken()) ?>">
                                <input type="hidden" name="action" value="add_to_cart">
                                <input type="hidden" name="id_produk" value="<?= (int) $product['id_produk'] ?>">
                                <label class="store-quantity-label" for="jumlah-<?= (int) $product['id_produk'] ?>">Jumlah</label>
                                <input id="jumlah-<?= (int) $product['id_produk'] ?>" name="jumlah" type="number" min="1" max="<?= min(1000, (int) $product['stok']) ?>" step="1" value="1" required aria-label="Jumlah <?= e($product['nama_produk']) ?>">
                                <button type="submit" aria-label="Tambah <?= e($product['nama_produk']) ?> ke keranjang"><span aria-hidden="true">＋</span> Tambah</button>
                            </form>
                        <?php else: ?>
                            <button class="store-out-of-stock" type="button" disabled>Stok habis</button>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <?php if (!$products): ?><div class="store-empty-state"><span>TK</span><h3>Belum ada produk</h3><p>Produk yang tersedia akan muncul di sini.</p></div><?php endif; ?>
        <p class="store-no-results" id="no-product-results" hidden>Produk tidak ditemukan. Coba kata kunci atau kategori lain.</p>
    </section>

    <section class="store-order-section" id="riwayat">
        <div class="store-section-heading store-orders-heading">
            <div><p class="store-overline">AKUNMU</p><h2>Riwayat pesanan</h2><p class="store-section-description"><?= number_format(count($orders), 0, ',', '.') ?> pesanan terakhir · Total belanja <?= rupiah($totalSpent) ?></p></div>
            <a href="#top" class="store-back-top">Kembali ke atas <span aria-hidden="true">↑</span></a>
        </div>
        <?php if ($orders): ?>
            <div class="store-order-list">
                <?php foreach ($orders as $order): ?>
                    <article class="store-order-row">
                        <span class="store-order-number">#<?= (int) $order['id_transaksi'] ?></span>
                        <div class="store-order-info"><strong><?= e(isset($items[(int) $order['id_transaksi']]) ? implode(', ', $items[(int) $order['id_transaksi']]) : 'Rincian produk tidak tersedia') ?></strong><small><?= e(tanggalIndonesia($order['tanggal'])) ?></small></div>
                        <span class="store-order-discount">Diskon <?= rupiah($order['diskon']) ?></span>
                        <strong class="store-order-total"><?= rupiah($order['total_belanja']) ?></strong>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="store-empty-orders"><span>0</span><div><strong>Belum ada pesanan</strong><p>Produk yang kamu pesan akan tersimpan di sini.</p></div><a href="#produk">Mulai belanja <span aria-hidden="true">→</span></a></div>
        <?php endif; ?>
    </section>
</main>

<footer class="store-footer">
    <a class="store-brand store-footer-brand" href="#top"><span class="store-brand-mark">T</span><span class="store-brand-copy">TokoKita<small>EVERYDAY GOODS</small></span></a>
    <span>Belanja yang terasa mudah, setiap hari.</span>
    <span>© <?= date('Y') ?> TokoKita</span>
</footer>

<script>
    const productSearch = document.querySelector('#product-search');
    const productCards = [...document.querySelectorAll('[data-product-card]')];
    const categoryFilters = [...document.querySelectorAll('[data-category-filter]')];
    const visibleProductCount = document.querySelector('#visible-product-count');
    const noProductResults = document.querySelector('#no-product-results');
    let activeCategory = '*';

    function filterProducts() {
        const searchTerm = productSearch.value.trim().toLocaleLowerCase('id');
        let visibleCount = 0;

        productCards.forEach((card) => {
            const matchesCategory = activeCategory === '*' || card.dataset.category === activeCategory;
            const matchesSearch = card.dataset.search.includes(searchTerm);
            card.hidden = !matchesCategory || !matchesSearch;
            if (!card.hidden) visibleCount += 1;
        });

        visibleProductCount.textContent = new Intl.NumberFormat('id-ID').format(visibleCount);
        noProductResults.hidden = visibleCount > 0;
    }

    categoryFilters.forEach((button) => {
        button.addEventListener('click', () => {
            activeCategory = button.dataset.categoryFilter;
            categoryFilters.forEach((filter) => filter.classList.toggle('is-active', filter === button));
            filterProducts();
            document.querySelector('#produk').scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });

    productSearch.addEventListener('input', filterProducts);
</script>
</body>
</html>
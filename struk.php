<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
requireAuth();

$user = authUser();
$role = authRole();

function strukEscape(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function strukRupiah(mixed $value): string
{
    return 'Rp ' . number_format((float) $value, 0, ',', '.');
}

$transactionId = filter_var(
    $_GET['id'] ?? null,
    FILTER_VALIDATE_INT
);

if (!$transactionId || $transactionId < 1) {
    http_response_code(400);
    exit('Nomor transaksi tidak valid.');
}

try {
    $pdo = tokokuPdo();
    ensureProductBarcodeSchema($pdo);
    ensurePaymentMethodSchema($pdo);
    ensurePaymentSettingsSchema($pdo);
    $paymentSettings = $pdo->query(
        'SELECT qris_image, nama_bank, nomor_rekening, nama_pemilik
         FROM tbl_pengaturan_pembayaran WHERE id = 1'
    )->fetch() ?: ['qris_image' => null, 'nama_bank' => null, 'nomor_rekening' => null, 'nama_pemilik' => null];

    // Mengambil informasi utama transaksi.
    $statement = $pdo->prepare(
        'SELECT
            t.id_transaksi,
            t.tanggal,
            t.subtotal,
            t.diskon,
            t.total_belanja,
            t.metode_pembayaran,
            p.id_pelanggan,
            p.nama_pelanggan,
            p.kota
         FROM tbl_transaksi AS t
         JOIN tbl_pelanggan AS p
            ON p.id_pelanggan = t.id_pelanggan
         WHERE t.id_transaksi = ?
         LIMIT 1'
    );

    $statement->execute([$transactionId]);
    $transaction = $statement->fetch();

    if (!$transaction) {
        http_response_code(404);
        exit('Transaksi tidak ditemukan.');
    }

    // Pelanggan hanya boleh melihat struk miliknya sendiri.
    if (
        $role === 'pelanggan' &&
        (int) ($user['id_pelanggan'] ?? 0) !==
        (int) $transaction['id_pelanggan']
    ) {
        http_response_code(403);
        exit('Anda tidak memiliki akses ke struk ini.');
    }

    // Mengambil rincian produk transaksi.
    $detailStatement = $pdo->prepare(
        'SELECT
            d.id_detail,
            d.jumlah,
            d.harga_satuan,
            d.subtotal,
            pr.nama_produk,
            pr.barcode
         FROM tbl_detail_transaksi AS d
         JOIN tbl_produk AS pr
            ON pr.id_produk = d.id_produk
         WHERE d.id_transaksi = ?
         ORDER BY d.id_detail ASC'
    );

    $detailStatement->execute([$transactionId]);
    $details = $detailStatement->fetchAll();

} catch (PDOException $exception) {
    http_response_code(500);
    exit('Data struk gagal dimuat. Periksa koneksi database.');
}

$months = [
    'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
    'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
];

$date = strtotime((string) $transaction['tanggal']);

$formattedDate = date('d', $date) . ' ' .
    $months[(int) date('n', $date) - 1] . ' ' .
    date('Y', $date);

$ppn = round((float) $transaction['total_belanja'] * 0.11, 2);
?>

<!doctype html>
<html lang="id">

<head>
    <meta charset="utf-8">

    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>
        Struk Transaksi #<?= (int) $transaction['id_transaksi'] ?> — TokoKita
    </title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 30px 15px;
            background: #f2f4f8;
            color: #222;
            font-family: Arial, Helvetica, sans-serif;
        }

        .receipt-container {
            width: 100%;
            max-width: 420px;
            margin: 0 auto;
        }

        .receipt {
            background: #fff;
            padding: 28px;
            border-radius: 12px;
            box-shadow: 0 5px 25px rgba(0, 0, 0, .08);
        }

        .receipt-header {
            text-align: center;
            border-bottom: 1px dashed #aaa;
            padding-bottom: 18px;
        }

        .receipt-header h1 {
            margin: 0 0 7px;
            font-size: 27px;
            color: #2458d3;
            letter-spacing: 1px;
        }

        .receipt-header p {
            margin: 4px 0;
            font-size: 12px;
            color: #666;
        }

        .receipt-title {
            margin: 18px 0;
            text-align: center;
            font-size: 15px;
            font-weight: bold;
            letter-spacing: 1px;
        }

        .receipt-info {
            font-size: 13px;
            margin-bottom: 17px;
        }

        .receipt-info div {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            margin: 7px 0;
        }

        .receipt-info span:first-child {
            color: #666;
        }

        .receipt-info span:last-child {
            text-align: right;
            font-weight: 600;
        }

        .receipt-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
            margin: 12px 0;
        }

        .receipt-table th {
            padding: 9px 4px;
            text-align: left;
            border-top: 1px dashed #aaa;
            border-bottom: 1px dashed #aaa;
        }

        .receipt-table td {
            padding: 9px 4px;
            border-bottom: 1px solid #eee;
            vertical-align: top;
        }

        .text-right {
            text-align: right !important;
        }

        .receipt-barcode {
            border-top: 1px dashed #aaa;
            margin-top: 14px;
            padding-top: 12px;
            text-align: center;
            font-size: 11px;
            color: #555;
        }

        .receipt-barcode strong {
            display: block;
            margin-top: 4px;
            color: #222;
            font-family: monospace;
            font-size: 15px;
            letter-spacing: 2px;
        }

        .summary {
            border-top: 1px dashed #aaa;
            padding-top: 12px;
            font-size: 13px;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            margin: 9px 0;
        }

        .summary-total {
            padding-top: 12px;
            margin-top: 12px;
            border-top: 1px dashed #aaa;
            font-size: 17px;
            font-weight: bold;
            color: #2458d3;
        }

        .receipt-footer {
            text-align: center;
            border-top: 1px dashed #aaa;
            margin-top: 20px;
            padding-top: 17px;
            font-size: 12px;
            color: #666;
        }

        .receipt-footer strong {
            display: block;
            color: #222;
            margin-bottom: 6px;
        }

        .receipt-actions {
            display: flex;
            gap: 10px;
            margin-top: 18px;
        }

        .receipt-actions button,
        .receipt-actions a {
            flex: 1;
            padding: 12px;
            border: 0;
            border-radius: 7px;
            text-align: center;
            text-decoration: none;
            font-size: 13px;
            font-weight: bold;
            cursor: pointer;
        }

        .print-button {
            background: #2458d3;
            color: white;
        }

        .back-button {
            background: #e8edf7;
            color: #2458d3;
        }

        @media print {
            @page {
                margin: 1mm;
            }

            body {
                background: white;
                padding: 0;
                margin: 0;
            }

            .receipt-container {
                width: 55mm;
                max-width: 100%;
                margin: 0 auto;
            }

            .receipt {
                box-shadow: none;
                border-radius: 0;
                padding: 1mm;
            }

            .receipt-header {
                padding-bottom: 2mm;
            }

            .receipt-header h1 {
                margin-bottom: 2px;
                font-size: 16px;
                letter-spacing: 0;
            }

            .receipt-header p {
                margin: 1px 0;
                font-size: 8px;
            }

            .receipt-title {
                margin: 2.5mm 0;
                font-size: 10px;
                letter-spacing: 0;
            }

            .receipt-info {
                margin-bottom: 2mm;
                font-size: 8px;
            }

            .receipt-info div {
                gap: 2mm;
                margin: 1mm 0;
            }

            .receipt-table {
                margin: 2mm 0;
                font-size: 8px;
            }

            .receipt-table th,
            .receipt-table td {
                padding: 1.2mm 1px;
            }

            .summary {
                padding-top: 2mm;
                font-size: 8px;
            }

            .summary-row {
                gap: 2mm;
                margin: 1mm 0;
            }

            .summary-total {
                padding-top: 2mm;
                margin-top: 2mm;
                font-size: 11px;
            }

            .receipt-footer {
                margin-top: 3mm;
                padding-top: 2mm;
                font-size: 7px;
            }

            .receipt-footer strong {
                margin-bottom: 2px;
            }

            .receipt-actions {
                display: none !important;
            }
        }
    </style>
</head>

<body>

<div class="receipt-container">

    <div class="receipt">

        <header class="receipt-header">
            <h1>TokoKita</h1>

            <p>SISTEM INFORMASI PENJUALAN</p>

            <p>Terima kasih telah berbelanja</p>
        </header>

        <div class="receipt-title">
            STRUK PEMBELIAN
        </div>

        <div class="receipt-info">

            <div>
                <span>No. Transaksi</span>

                <span>
                    #<?= (int) $transaction['id_transaksi'] ?>
                </span>
            </div>

            <div>
                <span>Tanggal</span>

                <span>
                    <?= strukEscape($formattedDate) ?>
                </span>
            </div>

            <div>
                <span>Pelanggan</span>

                <span>
                    <?= strukEscape($transaction['nama_pelanggan']) ?>
                </span>
            </div>

            <div>
                <span>Pembayaran</span>

                <span>
                    <?= strukEscape($transaction['metode_pembayaran'] ?: '-') ?>
                </span>
            </div>

            <?php if ($transaction['metode_pembayaran'] === 'QRIS'): ?>
                <div class="payment-instruction">
                    <strong>Scan QRIS Dana untuk membayar</strong>
                    <?php if (!empty($paymentSettings['qris_image'])): ?>
                        <img src="<?= strukEscape($paymentSettings['qris_image']) ?>" alt="QRIS pembayaran TokoKita">
                    <?php else: ?>
                        <span class="payment-instruction-unavailable">QRIS Dana belum diatur. Silakan minta QRIS Dana kepada kasir.</span>
                    <?php endif; ?>
                </div>
            <?php elseif ($transaction['metode_pembayaran'] === 'Transfer Bank'): ?>
                <div class="payment-instruction">
                    <strong>Transfer bank</strong>
                    <?php if ($paymentSettings['nama_bank'] && $paymentSettings['nomor_rekening'] && $paymentSettings['nama_pemilik']): ?>
                        <span><?= strukEscape($paymentSettings['nama_bank']) ?></span>
                        <span>No. rekening: <?= strukEscape($paymentSettings['nomor_rekening']) ?></span>
                        <span>Atas nama: <?= strukEscape($paymentSettings['nama_pemilik']) ?></span>
                    <?php else: ?>
                        <span class="payment-instruction-unavailable">Rekening transfer belum diatur. Silakan minta detail kepada kasir.</span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div>
                <span>Kota</span>

                <span>
                    <?= strukEscape($transaction['kota'] ?? '-') ?>
                </span>
            </div>

        </div>

        <table class="receipt-table">

            <thead>
                <tr>
                    <th>Produk</th>
                    <th class="text-right">Qty</th>
                    <th class="text-right">Jumlah</th>
                </tr>
            </thead>

            <tbody>

                <?php foreach ($details as $item): ?>

                    <tr>
                        <td>
                            <strong>
                                <?= strukEscape($item['nama_produk']) ?>
                            </strong>

                            <br>

                            <?= strukRupiah($item['harga_satuan']) ?>
                            / unit
                        </td>

                        <td class="text-right">
                            <?= (int) $item['jumlah'] ?>
                        </td>

                        <td class="text-right">
                            <?= strukRupiah($item['subtotal']) ?>
                        </td>
                    </tr>

                <?php endforeach; ?>

                <?php if (!$details): ?>

                    <tr>
                        <td colspan="3" class="text-right">
                            Tidak ada rincian produk.
                        </td>
                    </tr>

                <?php endif; ?>

            </tbody>

        </table>

        <div class="summary">

            <div class="summary-row">
                <span>Subtotal</span>

                <strong>
                    <?= strukRupiah($transaction['subtotal']) ?>
                </strong>
            </div>

            <div class="summary-row">
                <span>Diskon</span>

                <strong>
                    <?= strukRupiah($transaction['diskon']) ?>
                </strong>
            </div>

            <div class="summary-row">
                <span>PPN (11%)</span>

                <strong>
                    <?= strukRupiah($ppn) ?>
                </strong>
            </div>

            <div class="summary-row summary-total">
                <span>Total</span>

                <span>
                    <?= strukRupiah($transaction['total_belanja']) ?>
                </span>
            </div>

        </div>

        <?php $receiptBarcodes = array_values(array_unique(array_filter(array_map(static fn(array $item): string => trim((string) ($item['barcode'] ?? '')), $details)))); ?>
        <?php if ($receiptBarcodes): ?>
            <div class="receipt-barcode">
                Barcode produk dibeli
                <?php foreach ($receiptBarcodes as $barcode): ?>
                    <strong><?= strukEscape($barcode) ?></strong>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <footer class="receipt-footer">
            <strong>Terima kasih atas kunjungan Anda!</strong>

            Barang yang sudah dibeli sesuai dengan transaksi.

            <p>Struk ini merupakan bukti transaksi TokoKita.</p>
        </footer>

    </div>

    <div class="receipt-actions">

        <button
            type="button"
            class="print-button"
            onclick="window.print()"
        >
            Cetak Struk
        </button>

        <a
            href="<?= $role === 'kasir' ? 'kasir.php' : ($role === 'pelanggan' ? 'pelanggan.php' : 'index.php?page=transactions') ?>"
            class="back-button"
        >
            Kembali
        </a>

    </div>

</div>

</body>
</html>

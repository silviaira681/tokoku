<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
    ]);
    session_start();
}

function tokokuPdo(): PDO
{
    static $pdo;

    if (!$pdo instanceof PDO) {
        $pdo = new PDO(
            'mysql:host=127.0.0.1;dbname=db_tokoku;charset=utf8mb4',
            'root',
            '',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }

    return $pdo;
}

function authCsrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verifyAuthCsrf(): bool
{
    return isset($_SESSION['csrf_token'], $_POST['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], (string) $_POST['csrf_token']);
}

function authUser(): ?array
{
    $user = $_SESSION['auth_user'] ?? null;

    return is_array($user) && isset($user['id'], $user['username']) ? $user : null;
}

function requireAuth(): void
{
    if (authUser() === null) {
        header('Location: login.php');
        exit;
    }
}

function authRole(): string
{
    $user = authUser();

    // Sesi lama (sebelum ada peran) dianggap akun admin.
    $role = $user['role'] ?? 'admin';

    return in_array($role, ['pelanggan', 'kasir'], true) ? $role : 'admin';
}

function authHome(): string
{
    return match (authRole()) {
        'pelanggan' => 'pelanggan.php',
        'kasir' => 'kasir.php',
        default => 'index.php?page=dashboard',
    };
}

function requireRole(string|array $roles): void
{
    $allowedRoles = is_array($roles) ? $roles : [$roles];
    if (!in_array(authRole(), $allowedRoles, true)) {
        header('Location: ' . authHome());
        exit;
    }
}

function requireAdmin(): void
{
    requireRole('admin');
}

function requireKasir(): void
{
    requireRole('kasir');
}

function requirePelanggan(): void
{
    requireRole('pelanggan');
}

/**
 * Menambahkan kolom peran (admin / pelanggan) dan relasi ke tbl_pelanggan
 * pada tbl_pengguna bila belum ada. Aman dijalankan berulang kali.
 */
function ensureUserSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $columns = $pdo->query('SHOW COLUMNS FROM tbl_pengguna')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('role', $columns, true)) {
        $pdo->exec(
            "ALTER TABLE tbl_pengguna
             ADD COLUMN role ENUM('admin','pelanggan','kasir') NOT NULL DEFAULT 'admin' AFTER password_hash"
        );
    }
    // Tabel dari versi sebelumnya hanya mengenal admin dan pelanggan.
    $roleColumn = $pdo->query("SHOW COLUMNS FROM tbl_pengguna LIKE 'role'")->fetch();
    if ($roleColumn && !str_contains((string) $roleColumn['Type'], 'kasir')) {
        $pdo->exec(
            "ALTER TABLE tbl_pengguna
             MODIFY COLUMN role ENUM('admin','pelanggan','kasir') NOT NULL DEFAULT 'admin'"
        );
    }
    if (!in_array('id_pelanggan', $columns, true)) {
        $pdo->exec('ALTER TABLE tbl_pengguna ADD COLUMN id_pelanggan INT NULL DEFAULT NULL AFTER role');
        $pdo->exec('ALTER TABLE tbl_pengguna ADD UNIQUE KEY uq_pengguna_pelanggan (id_pelanggan)');
        $pdo->exec(
            'ALTER TABLE tbl_pengguna
             ADD CONSTRAINT fk_pengguna_pelanggan FOREIGN KEY (id_pelanggan)
             REFERENCES tbl_pelanggan (id_pelanggan) ON DELETE CASCADE'
        );
    }

    $done = true;
}

function ensureRfidSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $column = $pdo->query("SHOW COLUMNS FROM tbl_pengguna LIKE 'rfid'")->fetch();
    if (!$column) {
        $pdo->exec('ALTER TABLE tbl_pengguna ADD COLUMN rfid VARCHAR(50) DEFAULT NULL AFTER username');
        $pdo->exec('CREATE UNIQUE INDEX uq_pengguna_rfid ON tbl_pengguna (rfid)');
    }

    $done = true;
}

function ensureProductImageSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $column = $pdo->query("SHOW COLUMNS FROM tbl_produk LIKE 'gambar'")->fetch();
    if (!$column) {
        $pdo->exec('ALTER TABLE tbl_produk ADD COLUMN gambar VARCHAR(255) DEFAULT NULL AFTER nama_produk');
    }

    $done = true;
}

function ensureProductBarcodeSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $column = $pdo->query("SHOW COLUMNS FROM tbl_produk LIKE 'barcode'")->fetch();
    if (!$column) {
        $pdo->exec('ALTER TABLE tbl_produk ADD COLUMN barcode VARCHAR(100) DEFAULT NULL AFTER nama_produk');
    }

    $done = true;
}

function ensurePaymentMethodSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $column = $pdo->query("SHOW COLUMNS FROM tbl_transaksi LIKE 'metode_pembayaran'")->fetch();
    if (!$column) {
        $pdo->exec('ALTER TABLE tbl_transaksi ADD COLUMN metode_pembayaran VARCHAR(30) DEFAULT NULL AFTER total_belanja');
    }

    $done = true;
}

function ensureCustomerCheckoutSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tbl_detail_transaksi (
            id_detail INT NOT NULL AUTO_INCREMENT,
            id_transaksi INT NOT NULL,
            id_produk INT NOT NULL,
            jumlah INT NOT NULL,
            harga_satuan DECIMAL(12,2) NOT NULL,
            subtotal DECIMAL(12,2) NOT NULL,
            PRIMARY KEY (id_detail),
            KEY idx_detail_transaksi (id_transaksi),
            KEY idx_detail_produk (id_produk),
            CONSTRAINT fk_detail_transaksi
              FOREIGN KEY (id_transaksi) REFERENCES tbl_transaksi (id_transaksi),
            CONSTRAINT fk_detail_produk
              FOREIGN KEY (id_produk) REFERENCES tbl_produk (id_produk),
            CONSTRAINT chk_detail_jumlah CHECK (jumlah > 0),
            CONSTRAINT chk_detail_harga CHECK (harga_satuan >= 0 AND subtotal >= 0)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
    );

    $done = true;
}

function ensurePaymentSettingsSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tbl_pengaturan_pembayaran (
            id TINYINT UNSIGNED NOT NULL,
            qris_image VARCHAR(255) DEFAULT NULL,
            nama_bank VARCHAR(100) DEFAULT NULL,
            nomor_rekening VARCHAR(50) DEFAULT NULL,
            nama_pemilik VARCHAR(100) DEFAULT NULL,
            diperbarui_pada TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
    );
    $pdo->exec('INSERT IGNORE INTO tbl_pengaturan_pembayaran (id) VALUES (1)');

    $done = true;
}
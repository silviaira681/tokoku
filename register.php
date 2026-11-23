<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function regEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

if (authUser() !== null) {
    header('Location: ' . authHome());
    exit;
}

$error = null;
$ready = false;
$pdo = null;

try {
    $pdo = tokokuPdo();
    ensureUserSchema($pdo);
    $ready = (int) $pdo->query("SELECT COUNT(*) FROM tbl_pengguna WHERE role = 'admin'")->fetchColumn() > 0;
    if (!$ready) {
        $error = 'Pendaftaran belum dibuka. Akun admin harus dibuat lebih dulu melalui halaman login.';
    }
} catch (PDOException $exception) {
    error_log('Tokoku register database error: ' . $exception->getMessage());
    $error = 'Database belum dapat diakses. Buka halaman login terlebih dahulu dan pastikan database sudah diimpor.';
}

$old = ['nama' => '', 'kota' => '', 'username' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $ready && $pdo instanceof PDO) {
    $old = [
        'nama' => trim((string) ($_POST['nama_pelanggan'] ?? '')),
        'kota' => trim((string) ($_POST['kota'] ?? '')),
        'username' => trim((string) ($_POST['username'] ?? '')),
    ];
    $password = (string) ($_POST['password'] ?? '');
    $confirmation = (string) ($_POST['password_confirmation'] ?? '');

    try {
        if (!verifyAuthCsrf()) {
            throw new RuntimeException('Sesi formulir tidak valid. Muat ulang halaman, lalu coba lagi.');
        }
        if ((int) ($_SESSION['register_count'] ?? 0) >= 5) {
            throw new RuntimeException('Terlalu banyak pendaftaran dari sesi ini. Coba lagi nanti.');
        }
        if ($old['nama'] === '' || mb_strlen($old['nama']) > 100) {
            throw new RuntimeException('Nama wajib diisi (maksimal 100 karakter).');
        }
        if (mb_strlen($old['kota']) > 50) {
            throw new RuntimeException('Kota maksimal 50 karakter.');
        }
        if (!preg_match('/^[\pL\pN._-]{3,50}$/u', $old['username'])) {
            throw new RuntimeException('Username harus 3–50 karakter: huruf, angka, titik, garis bawah, atau tanda hubung.');
        }
        if (strlen($password) < 10 || strlen($password) > 72) {
            throw new RuntimeException('Kata sandi harus terdiri dari 10 sampai 72 karakter.');
        }
        if (!hash_equals($password, $confirmation)) {
            throw new RuntimeException('Konfirmasi kata sandi tidak cocok.');
        }

        $pdo->beginTransaction();
        $statement = $pdo->prepare('INSERT INTO tbl_pelanggan (nama_pelanggan, kota) VALUES (?, ?)');
        $statement->execute([$old['nama'], $old['kota'] === '' ? null : $old['kota']]);
        $customerId = (int) $pdo->lastInsertId();

        $statement = $pdo->prepare(
            "INSERT INTO tbl_pengguna (username, password_hash, role, id_pelanggan) VALUES (?, ?, 'pelanggan', ?)"
        );
        $statement->execute([$old['username'], password_hash($password, PASSWORD_DEFAULT), $customerId]);
        $userId = (int) $pdo->lastInsertId();
        $pdo->commit();

        $_SESSION['register_count'] = (int) ($_SESSION['register_count'] ?? 0) + 1;
        session_regenerate_id(true);
        $_SESSION['auth_user'] = [
            'id' => $userId,
            'username' => $old['username'],
            'role' => 'pelanggan',
            'id_pelanggan' => $customerId,
        ];
        header('Location: pelanggan.php');
        exit;
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Tokoku register error: ' . $exception->getMessage());
        $error = $exception->getCode() === '23000'
            ? 'Username tersebut sudah digunakan.'
            : 'Pendaftaran gagal. Periksa koneksi database dan coba lagi.';
    }
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f7f8fa">
    <title>Daftar Pelanggan — TokoKita</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="auth-page">
<main class="auth-shell">
    <section class="auth-brand-panel">
        <a class="brand auth-brand" href="login.php" aria-label="TokoKita">
            <span class="brand-mark">t<span>.</span></span>
            <span class="brand-copy">tokokita<small>STORE MANAGEMENT</small></span>
        </a>
        <div class="auth-illustration" aria-hidden="true">
            <span class="illustration-orbit orbit-one"></span>
            <span class="illustration-orbit orbit-two"></span>
            <span class="illustration-box">▤</span>
            <span class="illustration-spark spark-one">✳</span>
            <span class="illustration-spark spark-two">✦</span>
        </div>
        <div class="auth-brand-copy">
            <p class="eyebrow">TOKOKITA · AREA PELANGGAN</p>
            <h1>Belanja lebih mudah, riwayat tercatat rapi.</h1>
            <p>Daftar sebagai pelanggan untuk memesan produk dan melihat riwayat transaksi Anda.</p>
        </div>
        <span class="auth-brand-footer">© <?= date('Y') ?> TokoKita</span>
    </section>

    <section class="auth-form-panel">
        <div class="auth-form-wrap">
            <span class="auth-mobile-brand"><span class="brand-mark">t<span>.</span></span><strong>tokokita</strong></span>
            <div class="auth-heading">
                <span class="auth-heading-icon">✦</span>
                <p class="eyebrow">AKUN PELANGGAN</p>
                <h2>Daftar akun pelanggan</h2>
                <p>Isi data berikut untuk membuat akun pelanggan TokoKita.</p>
            </div>

            <?php if ($error): ?>
                <div class="auth-alert" role="alert"><?= regEscape($error) ?></div>
            <?php endif; ?>

            <?php if ($ready): ?>
            <form method="post" class="auth-form">
                <input type="hidden" name="csrf_token" value="<?= regEscape(authCsrfToken()) ?>">
                <label for="nama_pelanggan">Nama lengkap</label>
                <div class="auth-input-wrap"><span>♙</span><input id="nama_pelanggan" name="nama_pelanggan" type="text" maxlength="100" value="<?= regEscape($old['nama']) ?>" placeholder="Nama lengkap" required></div>
                <label for="kota">Kota</label>
                <div class="auth-input-wrap"><span>⌂</span><input id="kota" name="kota" type="text" maxlength="50" value="<?= regEscape($old['kota']) ?>" placeholder="Opsional"></div>
                <label for="username">Username</label>
                <div class="auth-input-wrap"><span>@</span><input id="username" name="username" type="text" minlength="3" maxlength="50" autocomplete="username" value="<?= regEscape($old['username']) ?>" placeholder="Masukkan username" required></div>
                <label for="password">Kata sandi</label>
                <div class="auth-input-wrap"><span>⌑</span><input id="password" name="password" type="password" autocomplete="new-password" placeholder="Minimal 10 karakter" required></div>
                <label for="password_confirmation">Konfirmasi kata sandi</label>
                <div class="auth-input-wrap"><span>⌑</span><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" placeholder="Ulangi kata sandi" required></div>
                <button class="button button-primary auth-submit" type="submit">Daftar sekarang<span>→</span></button>
            </form>
            <?php endif; ?>

            <div class="auth-security"><span>♧</span> Akses aman · Kata sandi disimpan dengan enkripsi hash</div>
            <a class="auth-back-link" href="login.php">← Sudah punya akun? Masuk</a>
        </div>
    </section>
</main>
</body>
</html>
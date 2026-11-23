-- =====================================================================
-- Membuat / mereset akun demo KASIR dan PELANGGAN langsung di database.
-- Cara: phpMyAdmin -> pilih database db_tokoku -> tab SQL -> tempel -> Kirim
--       (atau tab Import -> pilih file ini)
-- Target: MariaDB 10.4+ (XAMPP).
--
--   admin     : admin1      / Admin12345
--   kasir     : kasir1      / Kasir12345!
--   pelanggan : pelanggan1  / Pelanggan12345!
--
-- Aman dijalankan berulang kali. Jika akun sudah ada, password-nya
-- dikembalikan ke nilai di atas.
-- =====================================================================

USE db_tokoku;

-- 1. Pastikan tabel pengguna punya kolom peran (admin / pelanggan / kasir)
ALTER TABLE tbl_pengguna
  ADD COLUMN IF NOT EXISTS role ENUM('admin','pelanggan','kasir') NOT NULL DEFAULT 'admin' AFTER password_hash;

ALTER TABLE tbl_pengguna
  MODIFY COLUMN role ENUM('admin','pelanggan','kasir') NOT NULL DEFAULT 'admin';

ALTER TABLE tbl_pengguna
  ADD COLUMN IF NOT EXISTS id_pelanggan INT NULL DEFAULT NULL AFTER role;

ALTER TABLE tbl_pengguna
  ADD UNIQUE KEY IF NOT EXISTS uq_pengguna_pelanggan (id_pelanggan);

-- 2. Akun admin
INSERT INTO tbl_pengguna (username, password_hash, role, id_pelanggan)
VALUES ('admin1',
        '$2y$10$yWt4pmOTw54yRdbchYMQouJQxGmQoyjBmFoNLVVUrRRLCrfZ4va8G',
        'admin', NULL)
ON DUPLICATE KEY UPDATE
  password_hash = VALUES(password_hash),
  role = 'admin',
  id_pelanggan = NULL;

-- 3. Akun kasir
INSERT INTO tbl_pengguna (username, password_hash, role, id_pelanggan)
VALUES ('kasir1',
        '$6$Tk7qWz9Lm2Xa$D/ihVPaUr6xdpxOHjOy/yb23UprpXe0uXGYgUCNJmL9usq8IkB3d9LnwMZpXYuGj50RqgiOSQzV7i2EwHRx4/1',
        'kasir', NULL)
ON DUPLICATE KEY UPDATE
  password_hash = VALUES(password_hash),
  role = 'kasir',
  id_pelanggan = NULL;

-- 4. Akun pelanggan (data pelanggan dibuat dulu bila akunnya belum ada)
INSERT INTO tbl_pelanggan (nama_pelanggan, kota)
SELECT 'Pelanggan Demo', 'Sumenep' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM tbl_pengguna WHERE username = 'pelanggan1');

INSERT INTO tbl_pengguna (username, password_hash, role, id_pelanggan)
VALUES ('pelanggan1',
        '$6$Pg4rNb8Vc1Ds$8/yLzsjrOXfFHpGB3hn5wzQjDqQamiBnFO8zzwhbM1KJcfaKm7pwoY40wGTqx2CXy5snbQghA4bmfPHH8EVqS0',
        'pelanggan',
        (SELECT id_pelanggan FROM tbl_pelanggan
         WHERE nama_pelanggan = 'Pelanggan Demo'
         ORDER BY id_pelanggan DESC LIMIT 1))
ON DUPLICATE KEY UPDATE
  password_hash = VALUES(password_hash),
  role = 'pelanggan';

-- 5. Cek hasil: admin1, kasir1, dan pelanggan1 harus tampil
SELECT id_pengguna, username, role, id_pelanggan FROM tbl_pengguna ORDER BY id_pengguna;
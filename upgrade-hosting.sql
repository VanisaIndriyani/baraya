-- ========================================================================
-- SQL MIGRASI AMAN HOSTING BARAYA DAWET
-- !!! HANYA CREATE TABLE BARU + ALTER TABLE (TAMBAH KOLOM) + UPDATE SELECTIF
-- !!! TIDAK ADA PERINTAH DELETE / DROP TABLE / TRUNCATE SATU PUN
-- !!! SEMUA DATA LAMA HOSTING = TETAP UTUH, TIDAK HILANG SATU BARIS PUN
-- Jalankan di phpMyAdmin hosting, tab SQL.
-- ========================================================================

-- 1. BUAT TABEL biaya_wajib JIKA BELUM ADA (multi-frekuensi)
CREATE TABLE IF NOT EXISTS biaya_wajib (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nama_biaya VARCHAR(100) NOT NULL,
    nominal_bulanan DECIMAL(15,2) NOT NULL DEFAULT 0,
    frekuensi ENUM('bulanan','mingguan','setiap_n_hari','harian') NOT NULL DEFAULT 'bulanan',
    setiap_n_hari INT NULL DEFAULT NULL,
    keterangan TEXT,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- 2. TAMBAH KOLOM frekuensi KE biaya_wajib (jika tabel sudah ada tapi kolom ini blm ada)
-- (akan diabaikan oleh MySQL jikalau kolom sudah ada, tidak error)
SET @dbname = DATABASE();
SET @tablename = 'biaya_wajib';
SET @columnname = 'frekuensi';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_schema = @dbname)
      AND (table_name = @tablename)
      AND (column_name = @columnname)
  ) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN frekuensi ENUM(''bulanan'',''mingguan'',''setiap_n_hari'',''harian'') NOT NULL DEFAULT ''bulanan'' AFTER nominal_bulanan')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

SET @columnname = 'setiap_n_hari';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_schema = @dbname)
      AND (table_name = @tablename)
      AND (column_name = @columnname)
  ) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN setiap_n_hari INT NULL DEFAULT NULL AFTER frekuensi')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- 3. TAMBAH KOLOM kode_rekening KE saldo_rekening (supaya bisa bedakan Kas Operasional vs Rek Penjualan)
SET @tablename = 'saldo_rekening';
SET @columnname = 'kode_rekening';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_schema = @dbname)
      AND (table_name = @tablename)
      AND (column_name = @columnname)
  ) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN kode_rekening VARCHAR(50) NULL AFTER nama_rekening')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- 4. PASTIKAN ENUM metode_pembayaran DI TABEL pembelian SUDAH TERBARU (tambah 'bri' jikalau belum)
-- (kita tidak bisa detect enum value dengan COUNT sederhana, tapi jalankan modify saja, MySQL OK jikalau sama)
ALTER TABLE pembelian MODIFY COLUMN metode_pembayaran ENUM('kas_operasional','bri','owner_kredit','owner_cash','hutang') NOT NULL DEFAULT 'kas_operasional';

-- 5. ASSIGN REKENING YANG ADA SAAT INI (ID=1 biasanya Rekening BRI, saldo 1,7jt++)
--    MENJADI REKENING PENJUALAN (uang owner, karena dulu itu semua uang numpuk disana)
UPDATE saldo_rekening
   SET kode_rekening = 'penjualan',
       nama_rekening = 'Rekening Penjualan'
 WHERE kode_rekening IS NULL
   AND (nama_rekening IN ('','Rekening BRI','Rekening Penjualan') OR id = 1)
 LIMIT 1;

-- 6. BUAT REKENING KEDUA: KAS OPERASIONAL (saldo AWAL 0, nanti diisi otomatis oleh upgrade-hosting.php
--    saat rebuild semua alokasi penjualan lama target rutin/hari + kas bon owner)
INSERT IGNORE INTO saldo_rekening (nama_rekening, kode_rekening, saldo, updated_at)
SELECT 'Kas Operasional', 'operasional', 0, NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM saldo_rekening WHERE kode_rekening = 'operasional' LIMIT 1);

-- ========================================================================
-- SAMPAI DISINI = STRUKTUR SUDAH SAMA DENGAN DATABASE LOKAL KITA.
-- (SEMUA DATA HOSTING LAMA = TETAP ADA, TIDAK ADA YANG DIHAPUS)
-- LANJUT BUKA /upgrade-hosting.php = 1x KLIK UNTUK SPLIT SALDO + REBUILD ALLOKASI
-- ========================================================================

<?php
require_once 'config/database.php';

echo "<h2>Memperbarui Database...</h2>";

function kolomSudahAda($pdo, $table, $column)
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

try {
    // Tambahkan role rekening agar aliran uang tidak tercampur
    if (!kolomSudahAda($pdo, 'saldo_rekening', 'kode_rekening')) {
        $pdo->exec("ALTER TABLE saldo_rekening ADD COLUMN kode_rekening VARCHAR(50) NULL AFTER nama_rekening");
        echo "<p>✅ Kolom kode_rekening berhasil ditambahkan ke tabel saldo_rekening</p>";
    } else {
        echo "<p>ℹ️ Kolom kode_rekening sudah ada di tabel saldo_rekening</p>";
    }

    $rekening_penjualan = $pdo->query("SELECT * FROM saldo_rekening WHERE kode_rekening = 'penjualan' ORDER BY id ASC LIMIT 1")->fetch();
    if (!$rekening_penjualan) {
        $rekening_pertama = $pdo->query("SELECT * FROM saldo_rekening ORDER BY id ASC LIMIT 1")->fetch();
        if ($rekening_pertama) {
            $stmt = $pdo->prepare("UPDATE saldo_rekening SET kode_rekening = ?, nama_rekening = ? WHERE id = ?");
            $nama_rekening = in_array($rekening_pertama['nama_rekening'], ['Rekening BRI', 'Rekening Penjualan', ''], true)
                ? 'Rekening Penjualan'
                : $rekening_pertama['nama_rekening'];
            $stmt->execute(['penjualan', $nama_rekening, $rekening_pertama['id']]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO saldo_rekening (nama_rekening, kode_rekening, saldo) VALUES (?, ?, 0)");
            $stmt->execute(['Rekening Penjualan', 'penjualan']);
        }
    }

    $rekening_operasional = $pdo->query("SELECT * FROM saldo_rekening WHERE kode_rekening = 'operasional' ORDER BY id ASC LIMIT 1")->fetch();
    if (!$rekening_operasional) {
        $stmt = $pdo->prepare("INSERT INTO saldo_rekening (nama_rekening, kode_rekening, saldo) VALUES (?, ?, 0)");
        $stmt->execute(['Kas Operasional', 'operasional']);
    }
    echo "<p>✅ Rekening penjualan dan kas operasional berhasil dipastikan tersedia</p>";

    // Tambahkan kolom tipe_hutang ke tabel hutang_owner
    if (!kolomSudahAda($pdo, 'hutang_owner', 'tipe_hutang')) {
        $pdo->exec("ALTER TABLE hutang_owner ADD COLUMN tipe_hutang ENUM('owner', 'kasir') DEFAULT 'owner' AFTER tipe");
        echo "<p>✅ Kolom tipe_hutang berhasil ditambahkan ke tabel hutang_owner</p>";
    } else {
        echo "<p>ℹ️ Kolom tipe_hutang sudah ada di tabel hutang_owner</p>";
    }
    
    // Tambahkan kolom id_periode ke tabel hutang_owner
    if (!kolomSudahAda($pdo, 'hutang_owner', 'id_periode')) {
        $pdo->exec("ALTER TABLE hutang_owner ADD COLUMN id_periode INT AFTER id_pembelian");
        echo "<p>✅ Kolom id_periode berhasil ditambahkan ke tabel hutang_owner</p>";
    } else {
        echo "<p>ℹ️ Kolom id_periode sudah ada di tabel hutang_owner</p>";
    }
    
    // Buat tabel saldo_rekening_transaksi
    $pdo->exec("CREATE TABLE IF NOT EXISTS saldo_rekening_transaksi (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_rekening INT NOT NULL,
        id_periode INT,
        tipe_transaksi ENUM('debit', 'kredit') NOT NULL,
        jumlah DECIMAL(15,2) NOT NULL,
        keterangan TEXT,
        tanggal DATE NOT NULL,
        id_penjualan INT,
        id_pengeluaran INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (id_rekening) REFERENCES saldo_rekening(id) ON DELETE CASCADE,
        FOREIGN KEY (id_periode) REFERENCES periode(id) ON DELETE SET NULL,
        FOREIGN KEY (id_penjualan) REFERENCES penjualan(id) ON DELETE SET NULL,
        FOREIGN KEY (id_pengeluaran) REFERENCES pengeluaran(id) ON DELETE SET NULL
    )");
    echo "<p>✅ Tabel saldo_rekening_transaksi berhasil dibuat</p>";
    
    // Buat tabel periode
    $pdo->exec("CREATE TABLE IF NOT EXISTS periode (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nama_periode VARCHAR(100) NOT NULL,
        tanggal_mulai DATE NOT NULL,
        saldo_owner_awal DECIMAL(15,2) NOT NULL DEFAULT 0,
        saldo_kasir_awal DECIMAL(15,2) NOT NULL DEFAULT 0,
        saldo_rekening_awal DECIMAL(15,2) NOT NULL DEFAULT 0,
        is_active BOOLEAN DEFAULT FALSE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    echo "<p>✅ Tabel periode berhasil dibuat</p>";
    
    // Tambahkan kolom id_periode ke tabel penjualan
    if (!kolomSudahAda($pdo, 'penjualan', 'id_periode')) {
        $pdo->exec("ALTER TABLE penjualan ADD COLUMN id_periode INT AFTER id");
        echo "<p>✅ Kolom id_periode berhasil ditambahkan ke tabel penjualan</p>";
    } else {
        echo "<p>ℹ️ Kolom id_periode sudah ada di tabel penjualan</p>";
    }
    
    // Tambahkan kolom id_periode ke tabel pengeluaran
    if (!kolomSudahAda($pdo, 'pengeluaran', 'id_periode')) {
        $pdo->exec("ALTER TABLE pengeluaran ADD COLUMN id_periode INT AFTER id");
        echo "<p>✅ Kolom id_periode berhasil ditambahkan ke tabel pengeluaran</p>";
    } else {
        echo "<p>ℹ️ Kolom id_periode sudah ada di tabel pengeluaran</p>";
    }
    
    // Tambahkan kolom id_periode ke tabel pembelian
    if (!kolomSudahAda($pdo, 'pembelian', 'id_periode')) {
        $pdo->exec("ALTER TABLE pembelian ADD COLUMN id_periode INT AFTER id");
        echo "<p>✅ Kolom id_periode berhasil ditambahkan ke tabel pembelian</p>";
    } else {
        echo "<p>ℹ️ Kolom id_periode sudah ada di tabel pembelian</p>";
    }

    // Update enum metode pembayaran pembelian agar mendukung BRI
    $pdo->exec("ALTER TABLE pembelian MODIFY COLUMN metode_pembayaran ENUM('kas_operasional', 'bri', 'owner_kredit', 'owner_cash') NOT NULL");
    echo "<p>✅ Metode pembayaran pembelian berhasil diperbarui untuk mendukung BRI</p>";

    // Buat tabel modal owner
    $pdo->exec("CREATE TABLE IF NOT EXISTS modal_owner (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_periode INT NULL,
        owner_key ENUM('vanisa', 'dimas') NOT NULL,
        periode_bulan CHAR(7) NOT NULL,
        modal_awal DECIMAL(15,2) NOT NULL DEFAULT 0,
        penghasilan DECIMAL(15,2) NOT NULL DEFAULT 0,
        gaji DECIMAL(15,2) NOT NULL DEFAULT 0,
        keterangan TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_modal_owner_periode (owner_key, periode_bulan),
        FOREIGN KEY (id_periode) REFERENCES periode(id) ON DELETE SET NULL
    )");
    echo "<p>✅ Tabel modal_owner berhasil dibuat</p>";

    // Buat tabel catatan bulanan
    $pdo->exec("CREATE TABLE IF NOT EXISTS catatan_bulanan (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_periode INT NULL,
        periode_bulan CHAR(7) NOT NULL,
        tanggal DATE NOT NULL,
        kategori VARCHAR(50) NOT NULL,
        judul VARCHAR(150) NOT NULL,
        nominal DECIMAL(15,2) NOT NULL DEFAULT 0,
        status_bayar ENUM('belum_bayar', 'sudah_bayar') NOT NULL DEFAULT 'sudah_bayar',
        catatan TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (id_periode) REFERENCES periode(id) ON DELETE SET NULL
    )");
    echo "<p>✅ Tabel catatan_bulanan berhasil dibuat</p>";

    // Buat tabel biaya wajib bulanan
    $pdo->exec("CREATE TABLE IF NOT EXISTS biaya_wajib (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nama_biaya VARCHAR(100) NOT NULL,
        nominal_bulanan DECIMAL(15,2) NOT NULL DEFAULT 0,
        keterangan TEXT,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    echo "<p>✅ Tabel biaya_wajib berhasil dibuat</p>";
    
    echo "<br><h3>Database berhasil diperbarui! Silakan hapus file update_database.php ini.</h3>";
    echo "<p><a href='admin_dashboard.php'>Kembali ke Dashboard</a></p>";
    
} catch (PDOException $e) {
    echo "<p style='color: red;'>❌ Error: " . $e->getMessage() . "</p>";
}
?>

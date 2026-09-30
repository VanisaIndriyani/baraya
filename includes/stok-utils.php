<?php

function stokPastikanTabel(PDO $pdo): void
{
    static $sudah = false;
    if ($sudah) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS stok_item (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nama VARCHAR(150) NOT NULL,
        satuan VARCHAR(30) NOT NULL DEFAULT 'pcs',
        stok_awal DECIMAL(12,2) NOT NULL DEFAULT 0,
        min_stok DECIMAL(12,2) NOT NULL DEFAULT 0,
        catatan VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $sqlFk = "CREATE TABLE IF NOT EXISTS stok_mutasi (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_item INT NOT NULL,
        tanggal DATE NOT NULL,
        tipe ENUM('pakai','tambah') NOT NULL,
        qty DECIMAL(12,2) NOT NULL DEFAULT 0,
        catatan VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_stok_item_tgl (id_item, tanggal),
        CONSTRAINT fk_stok_mutasi_item FOREIGN KEY (id_item) REFERENCES stok_item(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    try {
        $pdo->exec($sqlFk);
    } catch (Throwable $e) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS stok_mutasi (
            id INT AUTO_INCREMENT PRIMARY KEY,
            id_item INT NOT NULL,
            tanggal DATE NOT NULL,
            tipe ENUM('pakai','tambah') NOT NULL,
            qty DECIMAL(12,2) NOT NULL DEFAULT 0,
            catatan VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_stok_item_tgl (id_item, tanggal)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    $sudah = true;
}

function stokTanggalValid(string $tanggal): bool
{
    $dt = DateTime::createFromFormat('Y-m-d', $tanggal);
    return $dt && $dt->format('Y-m-d') === $tanggal;
}

function stokAngka($nilai): float
{
    if (is_string($nilai)) {
        $nilai = trim(str_replace(' ', '', $nilai));
        $nilai = str_replace(',', '.', $nilai);
    }
    return round((float) $nilai, 2);
}

function stokFormatJumlah($angka): string
{
    $n = (float) $angka;
    if (abs($n - round($n)) < 0.001) {
        return number_format((int) round($n), 0, ',', '.');
    }
    $teks = number_format($n, 2, ',', '.');
    return rtrim(rtrim($teks, '0'), ',');
}

function stokNilaiInput($angka): string
{
    $n = (float) $angka;
    if (abs($n - round($n)) < 0.001) {
        return (string) (int) round($n);
    }
    return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
}

function stokAmbilDaftar(PDO $pdo, string $tanggal): array
{
    stokPastikanTabel($pdo);
    $stmt = $pdo->prepare("SELECT i.*,
            (SELECT COALESCE(SUM(qty), 0) FROM stok_mutasi WHERE id_item = i.id AND tipe = 'tambah') AS total_tambah,
            (SELECT COALESCE(SUM(qty), 0) FROM stok_mutasi WHERE id_item = i.id AND tipe = 'pakai') AS total_pakai,
            (SELECT COALESCE(SUM(qty), 0) FROM stok_mutasi WHERE id_item = i.id AND tipe = 'pakai' AND tanggal = ?) AS pakai_tanggal
        FROM stok_item i
        ORDER BY i.nama ASC");
    $stmt->execute([$tanggal]);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        $tersedia = (float) $row['stok_awal'] + (float) $row['total_tambah'];
        $row['tersedia'] = $tersedia;
        $row['sisa'] = $tersedia - (float) $row['total_pakai'];
        $row['persen_sisa'] = $tersedia > 0 ? max(0, min(100, ($row['sisa'] / $tersedia) * 100)) : 0;
    }
    unset($row);

    return $rows;
}

function stokAmbilRiwayat(PDO $pdo, int $limit = 24): array
{
    stokPastikanTabel($pdo);
    $limit = max(1, min(100, $limit));
    $stmt = $pdo->query("SELECT m.*, i.nama, i.satuan
        FROM stok_mutasi m
        JOIN stok_item i ON i.id = m.id_item
        ORDER BY m.tanggal DESC, m.id DESC
        LIMIT {$limit}");
    return $stmt->fetchAll();
}

function stokStatus(array $item): array
{
    $sisa = (float) $item['sisa'];
    $min = (float) $item['min_stok'];
    if ($sisa <= 0) {
        return ['label' => 'Habis', 'warna' => '#991B1B', 'latar' => '#FEE2E2', 'bar' => '#DC2626'];
    }
    if ($min > 0 && $sisa <= $min) {
        return ['label' => 'Hampir habis', 'warna' => '#B45309', 'latar' => '#FEF3C7', 'bar' => '#D97706'];
    }
    return ['label' => 'Aman', 'warna' => '#047857', 'latar' => '#D1FAE5', 'bar' => '#059669'];
}

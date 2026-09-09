<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once '../includes/header.php';
require_once '../includes/finance-utils.php';

$redirect_url = $base_url . '/pages/pembelian.php';
$GLOBALS['alert_script'] = '';

function pembelianPastikanStrukturTabelBarang($pdo)
{
    static $sudah_dicek = false;
    if ($sudah_dicek) return;

    $kolom_ada = [];
    $stmt = $pdo->query("SHOW COLUMNS FROM barang");
    foreach ($stmt->fetchAll() as $kol) {
        $kolom_ada[strtolower($kol['Field'])] = true;
    }

    if (!isset($kolom_ada['kategori'])) {
        try { $pdo->exec("ALTER TABLE barang ADD COLUMN kategori VARCHAR(50) NOT NULL DEFAULT 'stok' AFTER nama"); } catch (Exception $e) {}
    }
    if (!isset($kolom_ada['harga_beli'])) {
        try { $pdo->exec("ALTER TABLE barang ADD COLUMN harga_beli DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER kategori"); } catch (Exception $e) {}
    }
    if (!isset($kolom_ada['satuan'])) {
        try { $pdo->exec("ALTER TABLE barang ADD COLUMN satuan VARCHAR(20) NOT NULL DEFAULT 'pcs' AFTER harga_beli"); } catch (Exception $e) {}
    }
    if (!isset($kolom_ada['stok'])) {
        try { $pdo->exec("ALTER TABLE barang ADD COLUMN stok INT NOT NULL DEFAULT 0 AFTER satuan"); } catch (Exception $e) {}
    }
    if (!isset($kolom_ada['min_stok'])) {
        try { $pdo->exec("ALTER TABLE barang ADD COLUMN min_stok INT NOT NULL DEFAULT 0 AFTER stok"); } catch (Exception $e) {}
    }
    if (!isset($kolom_ada['id_supplier'])) {
        try { $pdo->exec("ALTER TABLE barang ADD COLUMN id_supplier INT NULL DEFAULT NULL AFTER nama"); } catch (Exception $e) {}
    }
    if (!isset($kolom_ada['foto'])) {
        try { $pdo->exec("ALTER TABLE barang ADD COLUMN foto VARCHAR(255) NULL DEFAULT NULL AFTER min_stok"); } catch (Exception $e) {}
    }

    $sudah_dicek = true;
}

function pembelianGetOrCreateBarangByName($pdo, $nama, $harga_beli = 0)
{
    $nama = trim((string) $nama);
    if ($nama === '') {
        throw new Exception('Nama barang tidak boleh kosong.');
    }

    pembelianPastikanStrukturTabelBarang($pdo);

    $stmt = $pdo->prepare("SELECT id FROM barang WHERE nama = ? ORDER BY id ASC LIMIT 1");
    $stmt->execute([$nama]);
    $existing = $stmt->fetch();

    if ($existing) {
        return (int) $existing['id'];
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO barang (nama, kategori, harga_beli, satuan, stok, min_stok) VALUES (?, 'stok', ?, 'pcs', 0, 0)");
        $stmt->execute([$nama, (float) $harga_beli]);
    } catch (Exception $e) {
        $stmt = $pdo->prepare("INSERT INTO barang (nama, harga_beli, satuan, stok) VALUES (?, ?, 'pcs', 0)");
        $stmt->execute([$nama, (float) $harga_beli]);
    }
    return (int) $pdo->lastInsertId();
}

function tampilkanAlertPembelian($icon, $title, $text, $redirect = null, $timer = 1500)
{
    $redirect_script = $redirect
        ? ".then(() => window.location.href = '" . addslashes($redirect) . "')"
        : '';

    $GLOBALS['alert_script'] = "<script>
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: '" . addslashes($icon) . "',
                    title: '" . addslashes($title) . "',
                    text: '" . addslashes($text) . "',
                    timer: " . (int) $timer . "
                })" . $redirect_script . ";
            }
        });
    </script>";
}

function simpanNotaPembelian($file)
{
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    $upload_dir = dirname(__DIR__) . '/uploads/nota';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $filename = uniqid('nota_', true) . ($ext ? '.' . $ext : '');
    $target_path = $upload_dir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $target_path)) {
        throw new Exception('Nota gagal diupload.');
    }

    require_once '../includes/image-utils.php';
    compressImage($target_path, $target_path, 70);

    return $filename;
}

function hapusNotaPembelian($filename)
{
    if (!$filename) {
        return;
    }

    $file_path = dirname(__DIR__) . '/uploads/nota/' . $filename;
    if (is_file($file_path)) {
        @unlink($file_path);
    }
}

function normalisasiItemsPembelian($items)
{
    $hasil = [];

    foreach ((array) $items as $item) {
        $nama_barang = trim((string) ($item['nama_barang'] ?? ''));
        $qty = (int) ($item['qty'] ?? 0);
        $harga = (float) ($item['harga'] ?? 0);

        if ($nama_barang === '' && $qty <= 0 && $harga <= 0) {
            continue;
        }

        if ($nama_barang === '' || $qty <= 0 || $harga <= 0) {
            throw new Exception('Semua item pembelian harus lengkap (nama, qty, harga).');
        }

        $hasil[] = [
            'nama_barang' => $nama_barang,
            'qty' => $qty,
            'harga' => $harga,
            'subtotal' => $qty * $harga
        ];
    }

    if (empty($hasil)) {
        throw new Exception('Minimal harus ada satu item pembelian.');
    }

    return $hasil;
}

function hitungTotalPembelian($items)
{
    $total = 0;
    foreach ($items as $item) {
        $total += $item['subtotal'];
    }
    return $total;
}

function pastikanMetodePembelianBriTersedia($pdo)
{
    static $sudah_dicek = false;

    if ($sudah_dicek) {
        return;
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM pembelian LIKE 'metode_pembayaran'");
    $kolom = $stmt->fetch();

    if ($kolom && isset($kolom['Type']) && stripos((string) $kolom['Type'], "'bri'") === false) {
        $pdo->exec("ALTER TABLE pembelian MODIFY COLUMN metode_pembayaran ENUM('kas_operasional', 'bri', 'owner_kredit', 'owner_cash') NOT NULL");
    }

    $sudah_dicek = true;
}

function ambilDetailPembelian($pdo, $id_pembelian)
{
    $stmt = $pdo->prepare("
        SELECT pd.*, b.nama AS barang_nama
        FROM pembelian_detail pd
        LEFT JOIN barang b ON pd.id_barang = b.id
        WHERE pd.id_pembelian = ?
        ORDER BY pd.id ASC
    ");
    $stmt->execute([$id_pembelian]);
    return $stmt->fetchAll();
}

function ambilHutangPembelian($pdo, $id_pembelian)
{
    $stmt = $pdo->prepare("
        SELECT h.*, COALESCE(SUM(p.jumlah), 0) AS dibayar
        FROM hutang_owner h
        LEFT JOIN hutang_owner_pembayaran p ON h.id = p.id_hutang
        WHERE h.id_pembelian = ? AND h.tipe = 'pembelian'
        GROUP BY h.id
        ORDER BY h.id DESC
        LIMIT 1
    ");
    $stmt->execute([$id_pembelian]);
    return $stmt->fetch();
}

function effectFloatHutangPembelian($tipe_hutang, $jumlah, $dibayar)
{
    return $tipe_hutang === 'kasir' ? (-1 * (float) $jumlah) + (float) $dibayar : 0;
}

function buatRiwayatFloatKasirPembelian($pdo, $delta)
{
    if ((float) $delta === 0.0) {
        return;
    }

    $stmt = $pdo->prepare("SELECT * FROM float_kasir ORDER BY id DESC LIMIT 1");
    $stmt->execute();
    $float = $stmt->fetch();
    $saldo_awal = $float ? (float) $float['saldo_sekarang'] : 0;
    $saldo_baru = $saldo_awal + (float) $delta;

    $stmt = $pdo->prepare("INSERT INTO float_kasir (saldo_awal, saldo_sekarang) VALUES (?, ?)");
    $stmt->execute([$saldo_awal, $saldo_baru]);
}

function pembelianPastikanKolomIdPembelian($pdo)
{
    static $sudah_dicek = false;
    if ($sudah_dicek) {
        return;
    }

    if (!financeColumnExists($pdo, 'saldo_rekening_transaksi', 'id_pembelian')) {
        try {
            $pdo->exec("ALTER TABLE saldo_rekening_transaksi ADD COLUMN id_pembelian INT NULL AFTER id_pengeluaran");
        } catch (Throwable $e) {
        }
    }

    $sudah_dicek = true;
}

function pembelianRekeningByMetode($metode)
{
    return 'operasional';
}

function pembelianAmbilTransaksiSaldo($pdo, $id_pembelian)
{
    pembelianPastikanKolomIdPembelian($pdo);

    $stmt = $pdo->prepare("SELECT * FROM saldo_rekening_transaksi WHERE id_pembelian = ? ORDER BY id ASC LIMIT 1");
    $stmt->execute([(int) $id_pembelian]);
    return $stmt->fetch() ?: null;
}

function pembelianSinkronSaldo($pdo, $id_pembelian, $id_periode, $total, $tanggal, $metode, $detail_items = [])
{
    pembelianPastikanKolomIdPembelian($pdo);
    financeEnsureAccountSystem($pdo);

    $kode_rekening = pembelianRekeningByMetode($metode);
    $transaksi_lama = pembelianAmbilTransaksiSaldo($pdo, $id_pembelian);

    $barang_note = '';
    if (is_array($detail_items) && !empty($detail_items)) {
        $names = [];
        foreach ($detail_items as $d) {
            if (!empty($d['barang_nama'])) {
                $names[] = $d['barang_nama'] . ' x' . (int) ($d['qty'] ?? 1);
            }
        }
        if (!empty($names)) {
            $barang_note = ' (' . implode(', ', array_slice($names, 0, 3)) . (count($names) > 3 ? ', ...' : '') . ')';
        }
    }
    $keterangan = 'Pembelian bahan' . $barang_note;

    if ($transaksi_lama) {
        $stmt = $pdo->prepare("SELECT * FROM saldo_rekening WHERE id = ?");
        $stmt->execute([(int) $transaksi_lama['id_rekening']]);
        $rekening_lama = $stmt->fetch();

        if ($rekening_lama) {
            $saldo_kembali = $rekening_lama['saldo'] + (float) $transaksi_lama['jumlah'];
            financeAdjustSaldoRekening($pdo, $rekening_lama['id'], (float) $transaksi_lama['jumlah']);
        }

        if ($kode_rekening !== null) {
            $rekening_baru = financeGetAccountByCode($pdo, $kode_rekening);
            if ($rekening_baru) {
                $stmt = $pdo->prepare("
                    UPDATE saldo_rekening_transaksi
                    SET id_rekening = ?, id_periode = ?, tipe_transaksi = 'kredit', jumlah = ?, keterangan = ?, tanggal = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    (int) $rekening_baru['id'],
                    $id_periode ?: null,
                    (float) $total,
                    $keterangan,
                    $tanggal,
                    (int) $transaksi_lama['id']
                ]);
                financeAdjustSaldoRekening($pdo, $rekening_baru['id'], -1 * (float) $total);
            }
        } else {
            $stmt = $pdo->prepare("DELETE FROM saldo_rekening_transaksi WHERE id = ?");
            $stmt->execute([(int) $transaksi_lama['id']]);
        }
        return;
    }

    if ($kode_rekening !== null) {
        $rekening = financeGetAccountByCode($pdo, $kode_rekening);
        if ($rekening) {
            $stmt = $pdo->prepare("
                INSERT INTO saldo_rekening_transaksi (id_rekening, id_periode, tipe_transaksi, jumlah, keterangan, tanggal, id_pembelian)
                VALUES (?, ?, 'kredit', ?, ?, ?, ?)
            ");
            $stmt->execute([
                (int) $rekening['id'],
                $id_periode ?: null,
                (float) $total,
                $keterangan,
                $tanggal,
                (int) $id_pembelian
            ]);
            financeAdjustSaldoRekening($pdo, $rekening['id'], -1 * (float) $total);
        }
    }
}

function pembelianHapusTransaksiSaldo($pdo, $id_pembelian)
{
    $transaksi = pembelianAmbilTransaksiSaldo($pdo, $id_pembelian);
    if (!$transaksi) {
        return;
    }

    financeRollbackSaldoTransaksi($pdo, $transaksi);

    $stmt = $pdo->prepare("DELETE FROM saldo_rekening_transaksi WHERE id = ?");
    $stmt->execute([(int) $transaksi['id']]);
}

function metaHutangPembelianDariMetode($metode)
{
    if ($metode === 'owner_kredit') {
        return [
            'tipe_hutang' => 'owner',
            'keterangan' => 'Hutang dari pembelian barang',
        ];
    }

    return null;
}

function sinkronHutangPembelian($pdo, $id_pembelian, $id_periode, $total, $tanggal, $tanggal_jatuh_tempo, $metode)
{
    $hutang = ambilHutangPembelian($pdo, $id_pembelian);
    $meta_hutang = metaHutangPembelianDariMetode($metode);
    $dibayar = $hutang ? (float) $hutang['dibayar'] : 0.0;
    $old_effect = $hutang ? effectFloatHutangPembelian($hutang['tipe_hutang'] ?? 'owner', $hutang['jumlah'], $dibayar) : 0.0;

    if ($meta_hutang !== null) {
        if ($dibayar > (float) $total) {
            throw new Exception('Jumlah hutang pembelian tidak boleh lebih kecil dari total yang sudah dibayar.');
        }

        $new_effect = effectFloatHutangPembelian($meta_hutang['tipe_hutang'], $total, $dibayar);
        buatRiwayatFloatKasirPembelian($pdo, $new_effect - $old_effect);

        if ($hutang) {
            $status = ((float) $hutang['dibayar'] >= (float) $total) ? 'lunas' : 'belum_lunas';
            $stmt = $pdo->prepare("
                UPDATE hutang_owner
                SET id_periode = ?, jumlah = ?, keterangan = ?, tanggal = ?, tanggal_jatuh_tempo = ?, status = ?, tipe_hutang = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $id_periode,
                $total,
                $meta_hutang['keterangan'],
                $tanggal,
                $tanggal_jatuh_tempo,
                $status,
                $meta_hutang['tipe_hutang'],
                $hutang['id']
            ]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO hutang_owner (id_periode, jumlah, keterangan, tanggal, tanggal_jatuh_tempo, tipe, id_pembelian, tipe_hutang)
                VALUES (?, ?, ?, ?, ?, 'pembelian', ?, ?)
            ");
            $stmt->execute([$id_periode, $total, $meta_hutang['keterangan'], $tanggal, $tanggal_jatuh_tempo, $id_pembelian, $meta_hutang['tipe_hutang']]);
        }
        return;
    }

    if ($hutang) {
        if ($dibayar > 0) {
            throw new Exception('Pembelian ini sudah memiliki pembayaran hutang, metode tidak bisa diubah.');
        }

        buatRiwayatFloatKasirPembelian($pdo, -1 * $old_effect);

        $stmt = $pdo->prepare("DELETE FROM hutang_owner WHERE id = ?");
        $stmt->execute([$hutang['id']]);
    }
}

function sinkronHutangPembelianTertinggal($pdo)
{
    $stmt = $pdo->prepare("
        SELECT p.id, p.id_periode, p.total, p.tanggal, p.metode_pembayaran
        FROM pembelian p
        LEFT JOIN hutang_owner h ON h.id_pembelian = p.id AND h.tipe = 'pembelian'
        WHERE p.metode_pembayaran IN ('kas_operasional', 'owner_kredit')
          AND h.id IS NULL
        ORDER BY p.id ASC
    ");
    $stmt->execute();
    $pembelian_tertinggal = $stmt->fetchAll();

    if (empty($pembelian_tertinggal)) {
        return;
    }

    try {
        $pdo->beginTransaction();

        foreach ($pembelian_tertinggal as $item) {
            $meta_hutang = metaHutangPembelianDariMetode($item['metode_pembayaran']);
            if ($meta_hutang === null) {
                continue;
            }

            $stmt_insert = $pdo->prepare("
                INSERT INTO hutang_owner (id_periode, jumlah, keterangan, tanggal, tanggal_jatuh_tempo, tipe, id_pembelian, tipe_hutang)
                VALUES (?, ?, ?, ?, NULL, 'pembelian', ?, ?)
            ");
            $stmt_insert->execute([
                $item['id_periode'] ?: null,
                $item['total'],
                $meta_hutang['keterangan'],
                $item['tanggal'],
                $item['id'],
                $meta_hutang['tipe_hutang']
            ]);

            buatRiwayatFloatKasirPembelian($pdo, effectFloatHutangPembelian($meta_hutang['tipe_hutang'], $item['total'], 0));
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}

function labelMetodePembelian($metode)
{
    return 'Kas Operasional';
}

function badgeMetodePembelian($metode)
{
    return 'info';
}

pastikanMetodePembelianBriTersedia($pdo);
pembelianPastikanKolomIdPembelian($pdo);
financeEnsureAccountSystem($pdo);
sinkronHutangPembelianTertinggal($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'tambah_pembelian') {
        $id_supplier = !empty($_POST['id_supplier']) ? (int) $_POST['id_supplier'] : null;
        $metode = 'kas_operasional';
        $tanggal = $_POST['tanggal'] ?? date('Y-m-d');
        $tanggal_jatuh_tempo = !empty($_POST['tanggal_jatuh_tempo']) ? $_POST['tanggal_jatuh_tempo'] : null;
        $id_periode = null;
        $nota = null;

        try {
            $items = normalisasiItemsPembelian($_POST['items'] ?? []);
            $itemsWithId = [];
            foreach ($items as $item) {
                $idb = pembelianGetOrCreateBarangByName($pdo, $item['nama_barang'], $item['harga']);
                $itemsWithId[] = [
                    'id_barang' => $idb,
                    'nama_barang' => $item['nama_barang'],
                    'qty' => $item['qty'],
                    'harga' => $item['harga'],
                    'subtotal' => $item['subtotal']
                ];
            }
            $total = hitungTotalPembelian($itemsWithId);
            $nota = simpanNotaPembelian($_FILES['nota'] ?? null);

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("INSERT INTO pembelian (id_periode, id_supplier, total, metode_pembayaran, nota, tanggal) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$id_periode, $id_supplier, $total, $metode, $nota, $tanggal]);
            $id_pembelian = $pdo->lastInsertId();

            $stmt = $pdo->prepare("INSERT INTO pembelian_detail (id_pembelian, id_barang, qty, harga, subtotal) VALUES (?, ?, ?, ?, ?)");
            foreach ($itemsWithId as $item) {
                $stmt->execute([$id_pembelian, $item['id_barang'], $item['qty'], $item['harga'], $item['subtotal']]);
            }

            sinkronHutangPembelian($pdo, $id_pembelian, $id_periode, $total, $tanggal, $tanggal_jatuh_tempo, $metode);

            $detail_transaksi = ambilDetailPembelian($pdo, $id_pembelian);
            pembelianSinkronSaldo($pdo, $id_pembelian, $id_periode, $total, $tanggal, $metode, $detail_transaksi);

            $pdo->commit();
            tampilkanAlertPembelian('success', 'Berhasil', 'Pembelian berhasil ditambahkan.', $redirect_url);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            hapusNotaPembelian($nota);
            tampilkanAlertPembelian('error', 'Gagal', $e->getMessage(), null, 3000);
        }
    }

    if ($action === 'edit_pembelian') {
        $id_pembelian = (int) ($_POST['id'] ?? 0);
        $id_supplier = !empty($_POST['id_supplier']) ? (int) $_POST['id_supplier'] : null;
        $metode = 'kas_operasional';
        $tanggal = $_POST['tanggal'] ?? date('Y-m-d');
        $tanggal_jatuh_tempo = !empty($_POST['tanggal_jatuh_tempo']) ? $_POST['tanggal_jatuh_tempo'] : null;
        $id_periode = null;
        $nota_baru = null;
        $nota_lama_hapus = null;
        $pembelian_lama = null;

        try {
            $items_baru_raw = normalisasiItemsPembelian($_POST['items'] ?? []);
            $items_baru = [];
            foreach ($items_baru_raw as $item) {
                $idb = pembelianGetOrCreateBarangByName($pdo, $item['nama_barang'], $item['harga']);
                $items_baru[] = [
                    'id_barang' => $idb,
                    'nama_barang' => $item['nama_barang'],
                    'qty' => $item['qty'],
                    'harga' => $item['harga'],
                    'subtotal' => $item['subtotal']
                ];
            }
            $total_baru = hitungTotalPembelian($items_baru);

            $stmt = $pdo->prepare("SELECT * FROM pembelian WHERE id = ?");
            $stmt->execute([$id_pembelian]);
            $pembelian_lama = $stmt->fetch();

            if (!$pembelian_lama) {
                throw new Exception('Data pembelian tidak ditemukan.');
            }

            $detail_lama = ambilDetailPembelian($pdo, $id_pembelian);
            $nota_baru = $pembelian_lama['nota'];

            if (isset($_FILES['nota']) && $_FILES['nota']['error'] === UPLOAD_ERR_OK) {
                $nota_baru = simpanNotaPembelian($_FILES['nota']);
                $nota_lama_hapus = $pembelian_lama['nota'];
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("DELETE FROM pembelian_detail WHERE id_pembelian = ?");
            $stmt->execute([$id_pembelian]);

            $stmt = $pdo->prepare("INSERT INTO pembelian_detail (id_pembelian, id_barang, qty, harga, subtotal) VALUES (?, ?, ?, ?, ?)");
            foreach ($items_baru as $item) {
                $stmt->execute([$id_pembelian, $item['id_barang'], $item['qty'], $item['harga'], $item['subtotal']]);
            }

            $stmt = $pdo->prepare("UPDATE pembelian SET id_periode = ?, id_supplier = ?, total = ?, metode_pembayaran = ?, nota = ?, tanggal = ? WHERE id = ?");
            $stmt->execute([$id_periode, $id_supplier, $total_baru, $metode, $nota_baru, $tanggal, $id_pembelian]);

            sinkronHutangPembelian($pdo, $id_pembelian, $id_periode, $total_baru, $tanggal, $tanggal_jatuh_tempo, $metode);

            $detail_transaksi = ambilDetailPembelian($pdo, $id_pembelian);
            pembelianSinkronSaldo($pdo, $id_pembelian, $id_periode, $total_baru, $tanggal, $metode, $detail_transaksi);

            $pdo->commit();

            if ($nota_lama_hapus && $nota_lama_hapus !== $nota_baru) {
                hapusNotaPembelian($nota_lama_hapus);
            }

            tampilkanAlertPembelian('success', 'Berhasil', 'Pembelian berhasil diperbarui.', $redirect_url);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($nota_baru && $nota_baru !== ($pembelian_lama['nota'] ?? null)) {
                hapusNotaPembelian($nota_baru);
            }

            tampilkanAlertPembelian('error', 'Gagal', $e->getMessage(), null, 3000);
        }
    }
}

if (isset($_GET['hapus'])) {
    $id_pembelian = (int) $_GET['hapus'];

    try {
        $stmt = $pdo->prepare("SELECT * FROM pembelian WHERE id = ?");
        $stmt->execute([$id_pembelian]);
        $pembelian_hapus = $stmt->fetch();

        if (!$pembelian_hapus) {
            throw new Exception('Data pembelian tidak ditemukan.');
        }

        $detail_hapus = ambilDetailPembelian($pdo, $id_pembelian);
        $hutang = ambilHutangPembelian($pdo, $id_pembelian);
        if ($hutang && (float) $hutang['dibayar'] > 0) {
            throw new Exception('Pembelian ini sudah punya pembayaran hutang, jadi tidak bisa dihapus.');
        }

        $pdo->beginTransaction();

        if ($hutang) {
            buatRiwayatFloatKasirPembelian($pdo, -1 * effectFloatHutangPembelian($hutang['tipe_hutang'] ?? 'owner', $hutang['jumlah'], $hutang['dibayar']));

            $stmt = $pdo->prepare("DELETE FROM hutang_owner WHERE id = ?");
            $stmt->execute([$hutang['id']]);
        }

        pembelianHapusTransaksiSaldo($pdo, $id_pembelian);

        $stmt = $pdo->prepare("DELETE FROM pembelian WHERE id = ?");
        $stmt->execute([$id_pembelian]);

        $pdo->commit();
        hapusNotaPembelian($pembelian_hapus['nota']);
        tampilkanAlertPembelian('success', 'Berhasil', 'Pembelian berhasil dihapus.', $redirect_url);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        tampilkanAlertPembelian('error', 'Gagal', $e->getMessage(), null, 3000);
    }
}

$search = trim($_GET['search'] ?? '');
if ($search !== '') {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total_data, COALESCE(SUM(p.total), 0) AS total_nominal
        FROM pembelian p
        LEFT JOIN supplier s ON p.id_supplier = s.id
        WHERE s.nama LIKE ? OR p.metode_pembayaran LIKE ?
    ");
    $keyword = '%' . $search . '%';
    $stmt->execute([$keyword, $keyword]);
    $ringkasan_pembelian = $stmt->fetch();

    $stmt = $pdo->prepare("
        SELECT p.*, s.nama AS supplier_nama
        FROM pembelian p
        LEFT JOIN supplier s ON p.id_supplier = s.id
        WHERE s.nama LIKE ? OR p.metode_pembayaran LIKE ?
        ORDER BY p.tanggal DESC, p.created_at DESC
        LIMIT 50
    ");
    $stmt->execute([$keyword, $keyword]);
    $pembelian = $stmt->fetchAll();
} else {
    $ringkasan_pembelian = $pdo->query("
        SELECT COUNT(*) AS total_data, COALESCE(SUM(total), 0) AS total_nominal
        FROM pembelian
    ")->fetch();
    $pembelian = $pdo->query("
        SELECT p.*, s.nama AS supplier_nama
        FROM pembelian p
        LEFT JOIN supplier s ON p.id_supplier = s.id
        ORDER BY p.tanggal DESC, p.created_at DESC
        LIMIT 50
    ")->fetchAll();
}
?>

<div class="page-header" style="background: linear-gradient(135deg,#450A0A 0%,#7F1D1D 50%,#991B1B 100%); border-bottom: 3px solid #FBBF24;">
    <a href="<?php echo $base_url; ?>/admin_dashboard.php" class="text-white text-decoration-none mb-2 d-inline-block opacity-90 hover-opacity-100" style="transition:opacity 0.2s ease;"><i class="bi bi-arrow-left-circle me-1"></i> Kembali ke Dashboard</a>
    <h4 class="mb-0 fw-black" style="text-shadow: 0 2px 12px rgba(251,191,36,0.25);"><i class="bi bi-cart-plus me-2" style="color:#FDE68A;"></i>Beli Bahan (Stok)</h4>
</div>

<div class="d-flex justify-content-end mb-3 pt-3">
    <button type="button" class="btn btn-lg fw-bold rounded-4 px-5" data-bs-toggle="modal" data-bs-target="#tambahModal" style="background: linear-gradient(135deg,#450A0A 0%,#7F1D1D 50%,#991B1B 100%); color:#FDE68A; border: 2px solid #FBBF24; box-shadow: 0 10px 22px rgba(153,27,27,0.32);">
        <i class="bi bi-plus-lg me-2"></i> Tambah Pembelian
    </button>
</div>

<div class="row g-4 mb-4">
    <div class="col-md-6 col-xl">
        <div class="stat-card rounded-4 p-4" style="background: linear-gradient(135deg,#450A0A 0%,#7F1D1D 50%,#991B1B 100%); color: #FDE68A; border: 2px solid rgba(251,191,36,0.5); box-shadow: 0 18px 34px rgba(69,10,10,0.28);">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-label text-white-50 fw-semibold">Total Nominal Pembelian</div>
                    <h3 class="stat-value fw-black mt-1" style="color:#FFF;">Rp <?php echo number_format($ringkasan_pembelian['total_nominal'] ?? 0, 0, ',', '.'); ?></h3>
                </div>
                <i class="bi bi-bag-check-fill stat-icon" style="color:#FBBF24; font-size: 36px;"></i>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-xl">
        <div class="stat-card rounded-4 p-4" style="background: linear-gradient(135deg,#FEF3C7 0%,#FDE68A 55%,#FBBF24 100%); color: #450A0A; border: 2px solid #F59E0B; box-shadow: 0 18px 34px rgba(251,191,36,0.22);">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-label fw-semibold opacity-80">Jumlah Transaksi</div>
                    <h3 class="stat-value fw-black mt-1" style="color:#7F1D1D;"><?php echo number_format($ringkasan_pembelian['total_data'] ?? 0, 0, ',', '.'); ?></h3>
                    <small class="opacity-75 fw-medium">data pembelian stok bahan</small>
                </div>
                <i class="bi bi-receipt-cutoff stat-icon" style="color:#7F1D1D; font-size: 36px;"></i>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 rounded-4 mb-4 overflow-hidden" style="box-shadow: 0 20px 45px rgba(15,23,42,0.12); border: 2px solid rgba(251,191,36,0.18);">
    <div class="card-header bg-white border-0 px-4 py-4 d-flex flex-wrap gap-3 justify-content-between align-items-center" style="border-bottom: 2px dashed rgba(251,191,36,0.4);">
        <div>
            <h6 class="fw-black mb-1" style="color: #7F1D1D; font-size: 1.1rem;">
                <i class="bi bi-clock-history me-2" style="color:#FBBF24;"></i>Riwayat Pembelian
            </h6>
            <small class="text-muted fw-semibold opacity-85">
                📊 <?php echo number_format($ringkasan_pembelian['total_data'] ?? 0); ?> data • Total: <span style="color:#991B1B;" class="fw-bold">Rp <?php echo number_format($ringkasan_pembelian['total_nominal'] ?? 0, 0, ',', '.'); ?></span>
            </small>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <form method="GET" class="input-group input-group-lg rounded-4 overflow-hidden" style="width: 340px; border: 2px solid rgba(251,191,36,0.45); box-shadow: 0 4px 12px rgba(251,191,36,0.12);">
                <input type="text" class="form-control border-0" style="background:#FFFBEB;" name="search" placeholder="🔍 Cari nama barang / nota..." value="<?php echo htmlspecialchars($search); ?>">
                <button class="btn border-0" type="submit" style="background: linear-gradient(135deg,#450A0A,#7F1D1D,#991B1B); color:#FDE68A;"><i class="bi bi-search fw-bold"></i></button>
                <?php if ($search !== ''): ?>
                <a href="pembelian.php" class="btn btn-outline-secondary border-0" style="background:#F3F4F6; color:#6B7280;">
                    <i class="bi bi-x-lg"></i>
                </a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <?php if (empty($pembelian)): ?>
    <div class="card-body text-center py-6" style="padding: 7rem 2rem;">
        <i class="bi bi-inbox fs-1 opacity-25 d-block mb-4" style="color:#7F1D1D;"></i>
        <h5 class="mb-2 fw-bold" style="color:#450A0A;">Belum ada data pembelian</h5>
        <p class="text-muted small mb-4 fw-medium opacity-80">Silakan klik tombol Tambah Pembelian di kanan atas ya.</p>
        <button class="btn btn-lg fw-bold rounded-4 px-5" data-bs-toggle="modal" data-bs-target="#tambahModal" style="background: linear-gradient(135deg,#450A0A,#7F1D1D,#991B1B); color:#FDE68A; border: 2px solid #FBBF24;">
            <i class="bi bi-plus-lg me-2"></i>Tambah Pembelian
        </button>
    </div>
    <?php else: ?>
    <div class="table-responsive px-1 pb-1">
        <table class="table table-hover align-middle mb-0" style="min-width: 920px;">
            <thead>
                <tr style="background: linear-gradient(135deg,#450A0A 0%,#7F1D1D 50%,#991B1B 100%);">
                    <th class="px-4 py-4 fw-black border-0" style="border-top-left-radius: 18px; letter-spacing: 0.4px; color:#FFFFFF !important; opacity: 1 !important; text-shadow: 0 1px 2px rgba(69,10,10,0.9), 0 0 3px rgba(251,191,36,0.55);">
                        <i class="bi bi-calendar3 me-2" style="font-size:15px; color:#FDE68A; display:inline-block; vertical-align:-2px;"></i><span style="color:#FFFFFF; opacity:1; font-size:13.5px;">TANGGAL</span>
                    </th>
                    <th class="px-3 py-4 fw-black border-0" style="letter-spacing: 0.4px; color:#FFFFFF !important; opacity: 1 !important; text-shadow: 0 1px 2px rgba(69,10,10,0.9), 0 0 3px rgba(251,191,36,0.55);">
                        <i class="bi bi-receipt me-2" style="font-size:15px; color:#FDE68A; display:inline-block; vertical-align:-2px;"></i><span style="color:#FFFFFF; opacity:1; font-size:13.5px;">NOTA</span>
                    </th>
                    <th class="px-3 py-4 fw-black border-0" style="letter-spacing: 0.4px; color:#FFFFFF !important; opacity: 1 !important; text-shadow: 0 1px 2px rgba(69,10,10,0.9), 0 0 3px rgba(251,191,36,0.55);">
                        <i class="bi bi-box-seam me-2" style="font-size:15px; color:#FDE68A; display:inline-block; vertical-align:-2px;"></i><span style="color:#FFFFFF; opacity:1; font-size:13.5px;">ITEM BARANG</span>
                    </th>
                    <th class="px-3 py-4 fw-black border-0" style="letter-spacing: 0.4px; color:#FFFFFF !important; opacity: 1 !important; text-shadow: 0 1px 2px rgba(69,10,10,0.9), 0 0 3px rgba(251,191,36,0.55);">
                        <i class="bi bi-credit-card me-2" style="font-size:15px; color:#FDE68A; display:inline-block; vertical-align:-2px;"></i><span style="color:#FFFFFF; opacity:1; font-size:13.5px;">METODE</span>
                    </th>
                    <th class="px-3 py-4 fw-black border-0 text-end" style="letter-spacing: 0.4px; color:#FFFFFF !important; opacity: 1 !important; text-shadow: 0 1px 2px rgba(69,10,10,0.9), 0 0 3px rgba(251,191,36,0.55);">
                        <i class="bi bi-cash-coin me-2" style="font-size:15px; color:#FDE68A; display:inline-block; vertical-align:-2px;"></i><span style="color:#FFFFFF; opacity:1; font-size:13.5px;">TOTAL</span>
                    </th>
                    <th class="px-4 py-4 fw-black border-0 text-center" style="border-top-right-radius: 18px; letter-spacing: 0.4px; color:#FFFFFF !important; opacity: 1 !important; text-shadow: 0 1px 2px rgba(69,10,10,0.9), 0 0 3px rgba(251,191,36,0.55);">
                        <i class="bi bi-gear-wide-connected me-2" style="font-size:15px; color:#FDE68A; display:inline-block; vertical-align:-2px;"></i><span style="color:#FFFFFF; opacity:1; font-size:13.5px;">AKSI</span>
                    </th>
                </tr>
            </thead>
            <tbody class="border-top-0">

    <?php foreach ($pembelian as $p): ?>
        <?php
        $details = ambilDetailPembelian($pdo, $p['id']);
        $hutang_pembelian = ambilHutangPembelian($pdo, $p['id']);
        $tanggal_jatuh_tempo_edit = $hutang_pembelian['tanggal_jatuh_tempo'] ?? '';
        $item_names = array_map(fn($d) => $d['barang_nama'] . ' x' . ((int) ($d['qty'] ?? 1)), $details);
        ?>
                <tr class="border-bottom border-light cursor-pointer transition-card" data-bs-toggle="modal" data-bs-target="#detailPembelianModal<?php echo $p['id']; ?>" style="border-bottom: 1.5px solid rgba(251,191,36,0.18) !important;">
                    <td class="px-4 py-4">
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <span class="badge rounded-pill px-3 py-2 fw-semibold" style="background: linear-gradient(135deg,rgba(254,243,199,0.7),rgba(253,230,138,0.6)); color:#7F1D1D; border:1.5px solid rgba(251,191,36,0.55);">
                                <i class="bi bi-calendar-event-fill me-1"></i>
                                <?php echo date('d M Y', strtotime($p['tanggal'])); ?>
                            </span>
                            <span class="small fw-bold text-muted opacity-75">#PBL-<?php echo str_pad($p['id'], 5, '0', STR_PAD_LEFT); ?></span>
                        </div>
                    </td>
                    <td class="px-3 py-4">
                        <?php if ($p['nota']): ?>
                        <div class="rounded-3 overflow-hidden" style="width:52px;height:52px; box-shadow:0 4px 10px rgba(127,29,29,0.15); border: 1.5px solid #FBBF24;">
                            <img src="<?php echo $base_url; ?>/uploads/nota/<?php echo $p['nota']; ?>" alt="Nota" style="width:100%;height:100%;object-fit:cover;">
                        </div>
                        <?php else: ?>
                        <div class="d-flex align-items-center justify-content-center rounded-3" style="width:52px;height:52px; background:linear-gradient(135deg,#FEF2F2,#FEE2E2); color:#7F1D1D; border:1.5px dashed rgba(127,29,29,0.45);">
                            <i class="bi bi-file-earmark-image-fill"></i>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td class="px-3 py-4">
                        <?php if (!empty($item_names)): ?>
                        <span class="d-block fw-bold text-dark mb-1" style="max-width:360px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis; font-size:0.97rem;">
                            <?php echo htmlspecialchars(implode(', ', array_slice($item_names, 0, 2))); ?>
                        </span>
                        <?php if (count($item_names) > 2): ?>
                        <small class="fw-semibold opacity-80" style="color:#991B1B;">+<?php echo count($item_names) - 2; ?> item lainnya</small>
                        <?php endif; ?>
                        <?php else: ?>
                        <span class="small fst-italic text-muted opacity-80 fw-medium">Tidak ada detail barang</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-3 py-4">
                        <span class="badge rounded-pill px-3 py-2 fw-semibold" style="background: linear-gradient(135deg,#450A0A,#7F1D1D,#991B1B); color:#FDE68A; border:1.5px solid #FBBF24;">
                            <i class="bi bi-credit-card-2-front-fill me-1"></i>
                            <?php echo labelMetodePembelian($p['metode_pembayaran']); ?>
                        </span>
                    </td>
                    <td class="px-3 py-4 text-end">
                        <h6 class="fw-black mb-0" style="color:#7F1D1D; font-size: 1.1rem; letter-spacing:0.2px;">
                            Rp <?php echo number_format($p['total'], 0, ',', '.'); ?>
                        </h6>
                    </td>
                    <td class="px-4 py-4 text-center">
                        <div class="d-flex justify-content-center gap-3 align-items-center">
                            <button class="btn-edit-row" onclick="event.stopPropagation(); bukaEditPembelian(<?php echo $p['id']; ?>);" title="Edit Pembelian">
                                <i class="bi bi-pencil-fill"></i>
                            </button>
                            <button class="btn-del-row" onclick="event.stopPropagation(); hapusPembelian(<?php echo $p['id']; ?>);" title="Hapus Pembelian">
                                <i class="bi bi-trash3-fill"></i>
                            </button>
                        </div>
                    </td>
                </tr>
    <?php endforeach; ?>

            </tbody>
            <tfoot>
                <tr style="background: linear-gradient(90deg,#450A0A 0%,#7F1D1D 50%,#991B1B 100%); border-top: 3px solid #FBBF24;">
                    <td colspan="4" class="px-4 py-4 fw-black text-end text-white">
                        <span style="color:#FDE68A; letter-spacing:0.4px;">📌 TOTAL SEMUA (<?php echo number_format($ringkasan_pembelian['total_data'] ?? 0); ?> transaksi):</span>
                    </td>
                    <td class="px-3 py-4 fw-black text-end" style="border-bottom-right-radius: 0;">
                        <h4 class="mb-0" style="color:#FBBF24; letter-spacing: 0.8px; text-shadow: 0 2px 10px rgba(251,191,36,0.35);">Rp <?php echo number_format($ringkasan_pembelian['total_nominal'] ?? 0, 0, ',', '.'); ?></h4>
                    </td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- MODAL SEMUA DATA PEMBELIAN (DILETAKKAN DI LUAR TABLE = HTML VALID, GA ADA TOMBOL NYANGKUT!) -->
<?php foreach ($pembelian as $p): ?>
    <?php
    $details = ambilDetailPembelian($pdo, $p['id']);
    $hutang_pembelian = ambilHutangPembelian($pdo, $p['id']);
    $tanggal_jatuh_tempo_edit = $hutang_pembelian['tanggal_jatuh_tempo'] ?? '';
    ?>
    <div class="modal fade" id="detailPembelianModal<?php echo $p['id']; ?>" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content rounded-4 border-0" style="box-shadow: 0 30px 70px rgba(69,10,10,0.35); border-top:4px solid #FBBF24;">
                <div class="modal-header border-0 px-4 pt-4 pb-3" style="background: linear-gradient(135deg,#450A0A 0%,#7F1D1D 55%,#991B1B 100%); color:#fff;">
                    <div>
                        <h5 class="modal-title fw-black mb-0" style="color:#FDE68A;"><i class="bi bi-receipt-cutoff me-2" style="color:#FBBF24;"></i>Detail Pembelian Bahan</h5>
                        <small class="text-white-50 mt-1 d-block fw-semibold">#PBL-<?php echo str_pad($p['id'],5,'0',STR_PAD_LEFT); ?></small>
                    </div>
                    <button type="button" class="btn-close border-0 rounded-circle" data-bs-dismiss="modal" style="background-color: rgba(255,255,255,0.92); opacity:1;"></button>
                </div>

                <div class="modal-body px-5 py-4">
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="text-muted small fw-bold mb-1 d-block opacity-80">📅 Tanggal</label>
                            <div class="rounded-3 p-3" style="background: linear-gradient(135deg,#FEF2F2,#FEE2E2); color:#7F1D1D; font-weight:800;">
                                <?php echo date('d F Y', strtotime($p['tanggal'])); ?>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="text-muted small fw-bold mb-1 d-block opacity-80">💳 Metode Bayar</label>
                            <div class="rounded-3 p-3" style="background: linear-gradient(135deg,#450A0A,#7F1D1D,#991B1B); color:#FDE68A; border:2px solid #FBBF24; font-weight:800;">
                                <i class="bi bi-credit-card-2-front-fill me-1" style="color:#FBBF24;"></i><?php echo labelMetodePembelian($p['metode_pembayaran']); ?>
                            </div>
                        </div>
                    </div>

                    <?php if ($hutang_pembelian && $hutang_pembelian['tanggal_jatuh_tempo']): ?>
                    <div class="mb-4">
                        <label class="text-muted small fw-bold mb-1 d-block opacity-80">⏰ Jatuh Tempo Hutang</label>
                        <div class="rounded-3 p-3" style="background: linear-gradient(135deg,#FEF9C3,#FEF3C7); color:#713F12; border:2px dashed #F59E0B; font-weight:800;">
                            <i class="bi bi-exclamation-triangle-fill me-1"></i><?php echo date('d F Y', strtotime($hutang_pembelian['tanggal_jatuh_tempo'])); ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="mb-4">
                        <label class="small fw-black mb-2 d-block" style="color:#7F1D1D; letter-spacing:0.5px;">📦 RINCIAN ITEM BARANG</label>
                        <div class="table-responsive rounded-4 overflow-hidden" style="border:2px solid rgba(251,191,36,0.4); box-shadow:0 8px 20px rgba(69,10,10,0.1);">
                            <table class="table table-sm align-middle mb-0">
                                <thead style="background: linear-gradient(135deg,#450A0A,#7F1D1D,#991B1B); color:#FDE68A;">
                                    <tr>
                                        <th class="px-4 py-3 fw-black border-0">Barang</th>
                                        <th class="px-3 py-3 fw-black border-0 text-center">Qty</th>
                                        <th class="px-3 py-3 fw-black border-0 text-end">Harga Satuan</th>
                                        <th class="px-4 py-3 fw-black border-0 text-end">Subtotal</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $no = 1; foreach ($details as $d): ?>
                                    <tr style="border-bottom: 1.5px solid rgba(251,191,36,0.18);">
                                        <td class="px-4 py-3 fw-bold" style="color:#1F2937;"><?php echo $no; ?>. <?php echo htmlspecialchars($d['barang_nama']); ?></td>
                                        <td class="px-3 py-3 text-center fw-black" style="color:#991B1B;"><?php echo (int) $d['qty']; ?></td>
                                        <td class="px-3 py-3 text-end fw-semibold opacity-90">Rp <?php echo number_format($d['harga'], 0, ',', '.'); ?></td>
                                        <td class="px-4 py-3 text-end fw-black" style="color:#7F1D1D;">Rp <?php echo number_format($d['subtotal'], 0, ',', '.'); ?></td>
                                    </tr>
                                    <?php $no++; endforeach; ?>
                                </tbody>
                                <tfoot style="background: linear-gradient(135deg,#FEF3C7 0%,#FDE68A 100%); border-top:3px solid #FBBF24;">
                                    <tr>
                                        <td colspan="3" class="px-4 py-3 fw-black text-end" style="color:#713F12; letter-spacing:0.4px;">TOTAL TAGIHAN:</td>
                                        <td class="px-4 py-3 fw-black text-end" style="color:#7F1D1D; font-size: 1.35rem; letter-spacing: 0.8px;">Rp <?php echo number_format($p['total'], 0, ',', '.'); ?></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>

                    <?php if ($p['nota']): ?>
                    <div class="mb-2">
                        <label class="small fw-black mb-2 d-block" style="color:#7F1D1D; letter-spacing:0.5px;">🖼 BUKTI / NOTA PEMBELIAN</label>
                        <div class="rounded-4 overflow-hidden" style="border:3px solid #FBBF24; box-shadow: 0 12px 28px rgba(69,10,10,0.18);">
                            <img src="<?php echo $base_url; ?>/uploads/nota/<?php echo htmlspecialchars($p['nota']); ?>" class="img-fluid w-100" alt="Nota Pembelian">
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="modal-footer border-0 px-5 pb-5 pt-3" style="border-top:2px dashed rgba(251,191,36,0.5);">
                    <button type="button" class="btn btn-edit-modal" onclick="bukaEditPembelian(<?php echo $p['id']; ?>)">
                        <i class="bi bi-pencil-fill me-1"></i> Edit Data
                    </button>

                    <button type="button" class="btn btn-del-modal" data-bs-dismiss="modal" onclick="hapusPembelian(<?php echo $p['id']; ?>)">
                        <i class="bi bi-trash3-fill me-1"></i> Hapus
                    </button>

                    <button type="button" class="btn btn-tutup-modal" data-bs-dismiss="modal">
                        <i class="bi bi-x-lg me-1"></i> Tutup
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="editModal<?php echo $p['id']; ?>" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content rounded-4 border-0" style="box-shadow:0 28px 65px rgba(69,10,10,0.32); border-top: 4px solid #FBBF24;">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="edit_pembelian">
                    <input type="hidden" name="id" value="<?php echo $p['id']; ?>">

                    <div class="modal-header border-0 px-4 pt-4 pb-3" style="background: linear-gradient(135deg,#450A0A,#7F1D1D,#991B1B);">
                        <div>
                            <h5 class="modal-title fw-black mb-0" style="color:#FDE68A;"><i class="bi bi-pencil-square me-2" style="color:#FBBF24;"></i>Edit Pembelian Bahan</h5>
                            <small class="text-white-50 mt-1 d-block fw-semibold">Sesuaikan detail transaksi dibawah ini ya</small>
                        </div>
                        <button type="button" class="btn-close border-0 rounded-circle" data-bs-dismiss="modal" style="background-color: rgba(255,255,255,0.92); opacity:1;"></button>
                    </div>

                    <div class="modal-body px-5 py-4">
                        <div class="mb-4">
                            <label class="form-label fw-bold" style="color:#450A0A;">📅 Tanggal Pembelian</label>
                            <input type="date" class="form-control form-control-lg" style="border:2px solid rgba(251,191,36,0.45); border-radius: 14px; background:#FFFBEB;" name="tanggal" value="<?php echo htmlspecialchars($p['tanggal']); ?>" required>
                        </div>

                        <input type="hidden" name="metode_pembayaran" value="kas_operasional">
                        <div class="mb-4">
                            <label class="form-label fw-bold" style="color:#450A0A;">💳 Metode Pembayaran</label>
                            <div class="form-control form-control-lg rounded-4" style="background: linear-gradient(135deg,#450A0A,#7F1D1D,#991B1B); color:#FDE68A; border:2px solid #FBBF24; font-weight:800; min-height:54px; display:flex; align-items:center;"><i class="bi bi-credit-card-2-front-fill me-2" style="color:#FBBF24;"></i> Kas Operasional (Rekening Utama)</div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-bold d-flex align-items-center gap-2" style="color:#450A0A;">
                                <i class="bi bi-box-seam me-1" style="color:#FBBF24;"></i>Item Barang
                            </label>
                            <div id="itemsContainerEdit<?php echo $p['id']; ?>" data-total-target="totalDisplayEdit<?php echo $p['id']; ?>" class="mb-2">
                                <?php foreach ($details as $index => $d): ?>
                                <div class="card mb-2 p-3 item-row rounded-4" style="background: linear-gradient(135deg,#FFFBEB,#FEF3C7); border: 2px solid rgba(251,191,36,0.5); box-shadow: 0 4px 10px rgba(251,191,36,0.12);">
                                    <div class="row g-3 align-items-end">
                                        <div class="col-md-5">
                                            <label class="form-label small fw-bold" style="color:#7F1D1D;">Nama Barang</label>
                                            <input type="text" class="form-control item-nama-barang rounded-3 border-2" style="border-color:#FBBF24;" name="items[<?php echo $index; ?>][nama_barang]" value="<?php echo htmlspecialchars($d['barang_nama']); ?>" placeholder="Contoh: Gula Pasir, Alpukat, dll" required>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label small fw-bold" style="color:#7F1D1D;">Qty</label>
                                            <input type="number" class="form-control item-qty rounded-3 border-2" style="border-color:#FBBF24;" name="items[<?php echo $index; ?>][qty]" value="<?php echo (int) $d['qty']; ?>" min="1" required oninput="calculateTotalForContainer(this.closest('[data-total-target]'))">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label small fw-bold" style="color:#7F1D1D;">Harga Satuan</label>
                                            <div class="input-group">
                                                <span class="input-group-text rounded-start-3 fw-bold border-2 border-end-0" style="background: linear-gradient(135deg,#450A0A,#7F1D1D); color:#FDE68A; border-color:#7F1D1D;">Rp</span>
                                                <input type="number" class="form-control item-harga rounded-end-3 border-start-0 border-2" style="border-color:#7F1D1D; background:#FFF;" name="items[<?php echo $index; ?>][harga]" value="<?php echo htmlspecialchars($d['harga']); ?>" min="1" required oninput="calculateTotalForContainer(this.closest('[data-total-target]'))">
                                            </div>
                                        </div>
                                        <div class="col-md-1">
                                            <button type="button" class="btn-hapus-item-row w-100" onclick="removeItemRow(this)">
                                                <i class="bi bi-trash3"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" class="btn btn-tambah-item w-100 mt-2 fw-bold" onclick="addItem('itemsContainerEdit<?php echo $p['id']; ?>')">
                                <i class="bi bi-plus-lg me-1"></i>Tambah Item Lainnya
                            </button>
                        </div>

                        <div class="mb-4 rounded-4 p-4" style="background: linear-gradient(90deg,#450A0A 0%,#7F1D1D 50%,#991B1B 100%); border:2px solid #FBBF24;">
                            <label class="d-flex justify-content-between align-items-center text-white fw-bold mb-0 w-100">
                                <span style="letter-spacing: 0.6px; color:#FDE68A;"><i class="bi bi-cash-coin me-1" style="color:#FBBF24;"></i> TOTAL SEMENTARA</span>
                                <h3 class="mb-0 fw-black" style="color:#FBBF24; text-shadow: 0 2px 10px rgba(251,191,36,0.35);">Rp <span id="totalDisplayEdit<?php echo $p['id']; ?>">0</span></h3>
                            </label>
                        </div>

                        <div class="mb-2">
                            <label class="form-label fw-bold" style="color:#450A0A;">🖼 Ganti Nota / Bukti (Opsional)</label>
                            <input type="file" class="form-control form-control-lg rounded-4" style="border:2px dashed rgba(251,191,36,0.6); background:#FFFBEB;" name="nota" accept="image/*">
                            <?php if ($p['nota']): ?>
                            <div class="mt-3 d-flex gap-2 align-items-center bg-white rounded-3 p-2" style="border: 1.5px solid rgba(127,29,29,0.18);">
                                <img src="<?php echo $base_url; ?>/uploads/nota/<?php echo htmlspecialchars($p['nota']); ?>" style="width:64px; height:64px; object-fit:cover; border-radius: 12px; border:1.5px solid #FBBF24;">
                                <div>
                                    <div class="fw-bold small" style="color:#7F1D1D;">📎 Nota saat ini</div>
                                    <small class="text-muted fw-medium opacity-80"><?php echo htmlspecialchars($p['nota']); ?></small>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="modal-footer border-0 px-5 pb-5 pt-2" style="border-top: 2px dashed rgba(251,191,36,0.5);">
                        <button type="button" class="btn btn-batal-modal" data-bs-dismiss="modal"><i class="bi bi-arrow-left-circle me-1"></i> Batal</button>
                        <button type="submit" class="btn btn-simpan-modal"><i class="bi bi-check-circle-fill me-1"></i> Simpan Perubahan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<div class="modal fade" id="tambahModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content rounded-4 border-0" style="box-shadow:0 28px 65px rgba(69,10,10,0.32); border-top:4px solid #FBBF24;">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="tambah_pembelian">

                <div class="modal-header border-0 px-4 pt-4 pb-3" style="background: linear-gradient(135deg,#450A0A 0%,#7F1D1D 55%,#991B1B 100%);">
                    <div>
                        <h5 class="modal-title fw-black mb-0" style="color:#FDE68A;"><i class="bi bi-cart-plus-fill me-2" style="color:#FBBF24;"></i>Tambah Pembelian Bahan Baru</h5>
                        <small class="text-white-50 mt-1 d-block fw-semibold">Catat pengeluaran stok barang disini yaa</small>
                    </div>
                    <button type="button" class="btn-close border-0 rounded-circle" data-bs-dismiss="modal" style="background-color: rgba(255,255,255,0.92); opacity:1;"></button>
                </div>

                <div class="modal-body px-5 py-4">
                    <div class="mb-4">
                        <label class="form-label fw-bold" style="color:#450A0A;">📅 Tanggal Pembelian</label>
                        <input type="date" class="form-control form-control-lg" style="border:2px solid rgba(251,191,36,0.45); border-radius: 14px; background:#FFFBEB;" name="tanggal" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>

                    <input type="hidden" name="metode_pembayaran" value="kas_operasional">
                    <div class="mb-4">
                        <label class="form-label fw-bold" style="color:#450A0A;">💳 Metode Pembayaran</label>
                        <div class="form-control form-control-lg rounded-4" style="background: linear-gradient(135deg,#450A0A,#7F1D1D,#991B1B); color:#FDE68A; border:2px solid #FBBF24; font-weight:800; min-height:54px; display:flex; align-items:center;"><i class="bi bi-credit-card-2-front-fill me-2" style="color:#FBBF24;"></i> Kas Operasional (Rekening Utama)</div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-bold d-flex align-items-center gap-2" style="color:#450A0A;">
                            <i class="bi bi-box-seam me-1" style="color:#FBBF24;"></i>Daftar Item Barang
                        </label>
                        <div id="itemsContainerTambah" data-total-target="totalDisplayTambah" class="mb-2"></div>
                        <button type="button" class="btn btn-tambah-item w-100 mt-2 fw-bold" onclick="addItem('itemsContainerTambah')">
                            <i class="bi bi-plus-lg me-1"></i>Tambah Item Lainnya
                        </button>
                    </div>

                    <div class="mb-4 rounded-4 p-4" style="background: linear-gradient(90deg,#450A0A 0%,#7F1D1D 50%,#991B1B 100%); border:2px solid #FBBF24;">
                        <label class="d-flex justify-content-between align-items-center text-white fw-bold mb-0 w-100">
                            <span style="letter-spacing:0.6px; color:#FDE68A;"><i class="bi bi-cash-coin me-1" style="color:#FBBF24;"></i> TOTAL SEMENTARA</span>
                            <h3 class="mb-0 fw-black" style="color:#FBBF24; text-shadow:0 2px 10px rgba(251,191,36,0.35);">Rp <span id="totalDisplayTambah">0</span></h3>
                        </label>
                    </div>

                    <div class="mb-2">
                        <label class="form-label fw-bold" style="color:#450A0A;">🖼 Upload Nota / Bukti (Opsional)</label>
                        <input type="file" class="form-control form-control-lg rounded-4" style="border:2px dashed rgba(251,191,36,0.6); background:#FFFBEB;" name="nota" accept="image/*">
                    </div>
                </div>

                <div class="modal-footer border-0 px-5 pb-5 pt-2" style="border-top: 2px dashed rgba(251,191,36,0.5);">
                    <button type="button" class="btn btn-batal-modal" data-bs-dismiss="modal"><i class="bi bi-arrow-left-circle me-1"></i> Batal</button>
                    <button type="submit" class="btn btn-simpan-modal"><i class="bi bi-check-circle-fill me-1"></i> Simpan Pembelian</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const itemCounters = {};

function ensureItemCounter(containerId) {
    if (!(containerId in itemCounters)) {
        const existingRows = document.querySelectorAll('#' + containerId + ' .item-row').length;
        itemCounters[containerId] = existingRows;
    }
}

function formatNumberId(value) {
    return new Intl.NumberFormat('id-ID').format(value || 0);
}

function itemRowTemplate(index, item = null) {
    const namaBarang = item && item.barang_nama ? item.barang_nama : (item && item.nama_barang ? item.nama_barang : '');
    const qty = item && item.qty ? item.qty : '';
    const harga = item && item.harga ? item.harga : '';

    return `
        <div class="card mb-2 p-3 item-row rounded-4" style="background: linear-gradient(135deg,#FFFBEB,#FEF3C7); border: 2px solid rgba(251,191,36,0.5); box-shadow: 0 4px 10px rgba(251,191,36,0.12);">
            <div class="row g-3 align-items-end">
                <div class="col-md-5">
                    <label class="form-label small fw-bold" style="color:#7F1D1D;">Nama Barang</label>
                    <input type="text" class="form-control item-nama-barang rounded-3 border-2" style="border-color:#FBBF24;" name="items[${index}][nama_barang]" value="${namaBarang}" placeholder="Contoh: Gula Pasir, Alpukat" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold" style="color:#7F1D1D;">Qty</label>
                    <input type="number" class="form-control item-qty rounded-3 border-2" style="border-color:#FBBF24;" name="items[${index}][qty]" value="${qty}" min="1" required oninput="calculateTotalForContainer(this.closest('[data-total-target]'))">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold" style="color:#7F1D1D;">Harga Satuan</label>
                    <div class="input-group">
                        <span class="input-group-text rounded-start-3 fw-bold border-2 border-end-0" style="background: linear-gradient(135deg,#450A0A,#7F1D1D); color:#FDE68A; border-color:#7F1D1D;">Rp</span>
                        <input type="number" class="form-control item-harga rounded-end-3 border-start-0 border-2" style="border-color:#7F1D1D; background:#FFF;" name="items[${index}][harga]" value="${harga}" min="1" required oninput="calculateTotalForContainer(this.closest('[data-total-target]'))">
                    </div>
                </div>
                <div class="col-md-1">
                    <button type="button" class="btn-hapus-item-row w-100" onclick="removeItemRow(this)">
                        <i class="bi bi-trash3"></i>
                    </button>
                </div>
            </div>
        </div>
    `;
}

function addItem(containerId, item = null) {
    const container = document.getElementById(containerId);
    if (!container) {
        return;
    }

    ensureItemCounter(containerId);
    container.insertAdjacentHTML('beforeend', itemRowTemplate(itemCounters[containerId], item));
    itemCounters[containerId]++;
    calculateTotalForContainer(container);
}

function removeItemRow(button) {
    const row = button.closest('.item-row');
    const container = button.closest('[data-total-target]');
    if (row) {
        row.remove();
    }
    if (container) {
        calculateTotalForContainer(container);
    }
}

function calculateTotalForContainer(container) {
    if (!container) {
        return;
    }

    let total = 0;
    container.querySelectorAll('.item-row').forEach((row) => {
        const qty = parseInt(row.querySelector('.item-qty')?.value || 0, 10);
        const harga = parseFloat(row.querySelector('.item-harga')?.value || 0);
        total += qty * harga;
    });

    const totalTargetId = container.getAttribute('data-total-target');
    const totalEl = document.getElementById(totalTargetId);
    if (totalEl) {
        totalEl.textContent = formatNumberId(total);
    }
}

function bukaEditPembelian(id) {
    const detailEl = document.getElementById('detailPembelianModal' + id);
    const editEl = document.getElementById('editModal' + id);

    if (!detailEl || !editEl || typeof bootstrap === 'undefined') {
        return;
    }

    detailEl.addEventListener('hidden.bs.modal', function handler() {
        const editModal = bootstrap.Modal.getInstance(editEl) || new bootstrap.Modal(editEl);
        editModal.show();
    }, { once: true });

    const detailModal = bootstrap.Modal.getInstance(detailEl) || new bootstrap.Modal(detailEl);
    detailModal.hide();
}

document.addEventListener('DOMContentLoaded', function () {
    if (document.getElementById('itemsContainerTambah')) {
        const container = document.getElementById('itemsContainerTambah');
        if (container.querySelectorAll('.item-row').length === 0) {
            addItem('itemsContainerTambah');
        }
    }
    document.querySelectorAll('[id^="itemsContainerEdit"]').forEach(function (el) {
        calculateTotalForContainer(el);
    });

    const tambahModalEl = document.getElementById('tambahModal');
    if (tambahModalEl) {
        tambahModalEl.addEventListener('shown.bs.modal', function () {
            const container = document.getElementById('itemsContainerTambah');
            if (container && container.querySelectorAll('.item-row').length === 0) {
                addItem('itemsContainerTambah');
            } else if (container) {
                calculateTotalForContainer(container);
            }
        });
    }

    document.querySelectorAll('[id^="editModal"]').forEach(function (modalEl) {
        modalEl.addEventListener('shown.bs.modal', function () {
            const container = modalEl.querySelector('[id^="itemsContainerEdit"]');
            if (container) {
                calculateTotalForContainer(container);
                if (container.querySelectorAll('.item-row').length === 0) {
                    const id = container.id.replace('itemsContainerEdit', '');
                    addItem('itemsContainerEdit' + id);
                }
            }
        });
    });
});

function hapusPembelian(id) {
    Swal.fire({
        title: 'Hapus Pembelian?',
        text: 'Data pembelian akan dihapus dan stok akan disesuaikan.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        confirmButtonText: 'Ya, Hapus',
        cancelButtonText: 'Batal'
    }).then((result) => {
        if (result.isConfirmed) {
            window.location = 'pembelian.php?hapus=' + id;
        }
    });
}

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-total-target]').forEach((container) => {
        calculateTotalForContainer(container);
    });

    document.querySelectorAll('.metode-pembayaran').forEach((select) => {
        toggleJatuhTempoByElement(select);
    });

    addItem('itemsContainerTambah');
});
</script>

<style>
.cursor-pointer { cursor: pointer; }
.transition-card { transition: all 0.3s ease; }
.transition-card:hover {
    background: linear-gradient(90deg, rgba(254,243,199,0.28), rgba(254,226,226,0.32)) !important;
    transform: translateX(4px);
    border-bottom-color: #FBBF24 !important;
}

/* ================= TOMBOL KOLOM AKSI DI TABEL ================= */
.btn-edit-row, .btn-del-row {
    width: 44px; height: 44px;
    border: 2.5px solid transparent;
    border-radius: 14px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 17px;
    transition: all 0.22s ease;
    cursor: pointer;
}
.btn-edit-row {
    background: linear-gradient(135deg,#FEF3C7,#FDE68A);
    color: #7F1D1D;
    border-color: #FBBF24;
    box-shadow: 0 4px 10px rgba(251,191,36,0.25);
}
.btn-edit-row:hover { transform: translateY(-2px) rotate(-3deg); box-shadow: 0 8px 16px rgba(251,191,36,0.45); }
.btn-del-row {
    background: linear-gradient(135deg,#FEF2F2,#FEE2E2);
    color: #991B1B;
    border-color: rgba(153,27,27,0.4);
    box-shadow: 0 4px 10px rgba(153,27,27,0.18);
}
.btn-del-row:hover { transform: translateY(-2px) rotate(3deg); background: linear-gradient(135deg,#FEE2E2,#FECACA); box-shadow: 0 8px 16px rgba(153,27,27,0.4); }

/* ================= TOMBOL DELETE ITEM ROW DI FORM ================= */
.btn-hapus-item-row {
    height: 48px;
    border: 2.5px solid #991B1B;
    border-radius: 14px;
    background: linear-gradient(135deg,#FEF2F2,#FEE2E2);
    color: #991B1B;
    font-size: 16px;
    cursor: pointer;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.btn-hapus-item-row:hover {
    background: linear-gradient(135deg,#FEE2E2,#FECACA);
    transform: scale(1.06);
    box-shadow: 0 6px 14px rgba(153,27,27,0.3);
}

/* ================= TOMBOL TAMBAH ITEM ROW ================= */
.btn-tambah-item {
    background: linear-gradient(135deg,#FFFBEB,#FEF3C7);
    color: #7F1D1D;
    border: 2.5px dashed #FBBF24;
    border-radius: 16px;
    padding: 12px 20px;
    cursor: pointer;
    transition: all 0.25s ease;
}
.btn-tambah-item:hover {
    background: linear-gradient(135deg,#FEF3C7,#FDE68A);
    transform: translateY(-2px);
    box-shadow: 0 10px 20px rgba(251,191,36,0.3);
    border-style: solid;
}

/* ================= TOMBOL MODAL FOOTER ================= */
.btn-edit-modal, .btn-del-modal, .btn-tutup-modal, .btn-batal-modal, .btn-simpan-modal {
    border: 2.5px solid transparent;
    border-radius: 16px;
    padding: 11px 22px;
    font-weight: 800;
    cursor: pointer;
    transition: all 0.25s ease;
    display: inline-flex;
    align-items: center;
    letter-spacing: 0.2px;
}
.btn-edit-modal {
    background: linear-gradient(135deg,#FEF3C7,#FDE68A);
    color: #7F1D1D;
    border-color: #FBBF24;
    box-shadow: 0 6px 14px rgba(251,191,36,0.3);
}
.btn-edit-modal:hover { transform: translateY(-2px); box-shadow: 0 12px 22px rgba(251,191,36,0.5); color:#450A0A; }
.btn-del-modal {
    background: linear-gradient(135deg,#7F1D1D,#991B1B,#B91C1C);
    color: #FDE68A;
    border-color: #FCA5A5;
    box-shadow: 0 6px 14px rgba(153,27,27,0.35);
}
.btn-del-modal:hover { transform: translateY(-2px); filter: brightness(1.08); box-shadow: 0 12px 22px rgba(153,27,27,0.5); color: #FFF; }
.btn-tutup-modal, .btn-batal-modal {
    background: linear-gradient(135deg,#F3F4F6,#E5E7EB);
    color: #374151;
    border-color: #9CA3AF;
    box-shadow: 0 4px 10px rgba(107,114,128,0.18);
}
.btn-tutup-modal:hover, .btn-batal-modal:hover { transform: translateY(-2px); background: linear-gradient(135deg,#E5E7EB,#D1D5DB); }
.btn-simpan-modal {
    background: linear-gradient(135deg,#450A0A 0%,#7F1D1D 50%,#991B1B 100%);
    color: #FDE68A;
    border-color: #FBBF24;
    box-shadow: 0 10px 22px rgba(153,27,27,0.4);
}
.btn-simpan-modal:hover { transform: translateY(-2px); box-shadow: 0 16px 30px rgba(153,27,27,0.55); color: #FFF; filter: brightness(1.08); }
</style>

<?php include '../includes/footer.php'; ?>
<?php if (!empty($GLOBALS['alert_script'])) echo $GLOBALS['alert_script']; ?>

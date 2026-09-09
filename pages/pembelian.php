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

<div class="page-header">
    <a href="<?php echo $base_url; ?>/admin_dashboard.php" class="text-white text-decoration-none mb-2 d-inline-block"><i class="bi bi-arrow-left"></i> Kembali</a>
    <h4 class="mb-0"><i class="bi bi-cart-plus me-2"></i>Pembelian</h4>
</div>

<div class="d-flex justify-content-end mb-3">
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#tambahModal">
        <i class="bi bi-plus-lg"></i> Tambah Pembelian
    </button>
</div>

<div class="row g-4 mb-4">
    <div class="col-md-6 col-xl">
        <div class="stat-card" style="background: linear-gradient(135deg,#DBEAFE 0%,#BFDBFE 100%); color: #1E3A8A;">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-label">Total Pembelian</div>
                    <h3 class="stat-value">Rp <?php echo number_format($ringkasan_pembelian['total_nominal'] ?? 0, 0, ',', '.'); ?></h3>
                </div>
                <i class="bi bi-bag-check-fill stat-icon"></i>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-xl">
        <div class="stat-card" style="background: linear-gradient(135deg,#D1FAE5 0%,#A7F3D0 100%); color: #065F46;">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-label">Jumlah Transaksi</div>
                    <h3 class="stat-value"><?php echo number_format($ringkasan_pembelian['total_data'] ?? 0, 0, ',', '.'); ?></h3>
                    <small class="opacity-75">data pembelian bahan</small>
                </div>
                <i class="bi bi-receipt-cutoff stat-icon"></i>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm rounded-4 mb-4 overflow-hidden">
    <div class="card-header bg-white border-0 px-4 py-3 d-flex flex-wrap gap-2 justify-content-between align-items-center">
        <div>
            <h6 class="fw-bold mb-0 text-primary">
                <i class="bi bi-clock-history me-2"></i>Riwayat Pembelian
            </h6>
            <small class="text-muted">
                <?php echo number_format($ringkasan_pembelian['total_data'] ?? 0); ?> data • Total Rp <?php echo number_format($ringkasan_pembelian['total_nominal'] ?? 0, 0, ',', '.'); ?>
            </small>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <form method="GET" class="input-group input-group-lg" style="width: 320px;">
                <input type="text" class="form-control" name="search" placeholder="Cari pembelian..." value="<?php echo htmlspecialchars($search); ?>">
                <button class="btn btn-primary" type="submit"><i class="bi bi-search"></i></button>
                <?php if ($search !== ''): ?>
                <a href="pembelian.php" class="btn btn-outline-secondary">
                    <i class="bi bi-x-lg"></i>
                </a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <?php if (empty($pembelian)): ?>
    <div class="card-body text-center py-5">
        <i class="bi bi-inbox fs-1 opacity-25 d-block mb-3 text-muted"></i>
        <h5 class="text-muted mb-1">Belum ada data pembelian</h5>
        <p class="text-muted small mb-3">Silakan klik tombol Tambah Pembelian di atas.</p>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#tambahModal">
            <i class="bi bi-plus-lg me-2"></i>Tambah Pembelian
        </button>
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" style="min-width: 900px;">
            <thead>
                <tr style="background: linear-gradient(135deg,#DBEAFE 0%,#BFDBFE 100%);">
                    <th class="px-4 py-3 fw-semibold text-primary-emphasis border-0" style="border-top-left-radius: 12px;">Tanggal</th>
                    <th class="px-3 py-3 fw-semibold text-primary-emphasis border-0">Nota</th>
                    <th class="px-3 py-3 fw-semibold text-primary-emphasis border-0">Item Barang</th>
                    <th class="px-3 py-3 fw-semibold text-primary-emphasis border-0">Metode</th>
                    <th class="px-3 py-3 fw-semibold text-primary-emphasis border-0 text-end">Total</th>
                    <th class="px-4 py-3 fw-semibold text-primary-emphasis border-0 text-center" style="border-top-right-radius: 12px;">Aksi</th>
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
                <tr class="border-bottom border-light cursor-pointer transition-card" data-bs-toggle="modal" data-bs-target="#detailPembelianModal<?php echo $p['id']; ?>">
                    <td class="px-4 py-3">
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge rounded-pill bg-light text-primary border border-primary-subtle px-3 py-2">
                                <i class="bi bi-calendar-event me-1"></i>
                                <?php echo date('d M Y', strtotime($p['tanggal'])); ?>
                            </span>
                            <span class="small text-muted">#PBL-<?php echo str_pad($p['id'], 5, '0', STR_PAD_LEFT); ?></span>
                        </div>
                    </td>
                    <td class="px-3 py-3">
                        <?php if ($p['nota']): ?>
                        <div class="rounded-3 overflow-hidden border border-light" style="width:48px;height:48px;">
                            <img src="<?php echo $base_url; ?>/uploads/nota/<?php echo $p['nota']; ?>" alt="Nota" style="width:100%;height:100%;object-fit:cover;">
                        </div>
                        <?php else: ?>
                        <div class="d-flex align-items-center justify-content-center rounded-3 bg-primary bg-opacity-10 text-primary" style="width:48px;height:48px;">
                            <i class="bi bi-file-text"></i>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td class="px-3 py-3">
                        <?php if (!empty($item_names)): ?>
                        <span class="d-block fw-medium text-dark mb-1" style="max-width:350px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                            <?php echo htmlspecialchars(implode(', ', array_slice($item_names, 0, 2))); ?>
                        </span>
                        <?php if (count($item_names) > 2): ?>
                        <small class="text-muted opacity-75">+<?php echo count($item_names) - 2; ?> item lainnya</small>
                        <?php endif; ?>
                        <?php else: ?>
                        <span class="small fst-italic text-muted opacity-75">Tidak ada detail</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-3 py-3">
                        <span class="badge rounded-pill bg-<?php echo badgeMetodePembelian($p['metode_pembayaran']); ?> px-3 py-2">
                            <i class="bi bi-credit-card me-1"></i>
                            <?php echo labelMetodePembelian($p['metode_pembayaran']); ?>
                        </span>
                    </td>
                    <td class="px-3 py-3 text-end">
                        <h6 class="fw-bold text-primary mb-0">
                            Rp <?php echo number_format($p['total'], 0, ',', '.'); ?>
                        </h6>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <div class="d-flex justify-content-center gap-2">
                            <button class="action-btn edit" onclick="event.stopPropagation(); bukaEditPembelian(<?php echo $p['id']; ?>);">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button class="action-btn delete" onclick="event.stopPropagation(); hapusPembelian(<?php echo $p['id']; ?>);">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>

        <div class="modal fade" id="detailPembelianModal<?php echo $p['id']; ?>" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content rounded-4">
                    <div class="modal-header border-0">
                        <h5 class="modal-title">Detail Pembelian</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="text-muted small">Tanggal</label>
                            <p class="mb-0"><?php echo date('d/m/Y', strtotime($p['tanggal'])); ?></p>
                        </div>

                        <div class="mb-3">
                            <label class="text-muted small">Metode Pembayaran</label>
                            <p class="mb-0"><?php echo labelMetodePembelian($p['metode_pembayaran']); ?></p>
                        </div>

                        <?php if ($hutang_pembelian && $hutang_pembelian['tanggal_jatuh_tempo']): ?>
                        <div class="mb-3">
                            <label class="text-muted small">Jatuh Tempo Hutang</label>
                            <p class="mb-0"><?php echo date('d/m/Y', strtotime($hutang_pembelian['tanggal_jatuh_tempo'])); ?></p>
                        </div>
                        <?php endif; ?>

                        <div class="mb-3">
                            <label class="text-muted small">Item Barang</label>
                            <div class="table-responsive mt-2">
                                <table class="table table-sm align-middle">
                                    <thead>
                                        <tr>
                                            <th>Barang</th>
                                            <th class="text-end">Qty</th>
                                            <th class="text-end">Harga</th>
                                            <th class="text-end">Subtotal</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($details as $d): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($d['barang_nama']); ?></td>
                                            <td class="text-end"><?php echo (int) $d['qty']; ?></td>
                                            <td class="text-end">Rp <?php echo number_format($d['harga'], 0, ',', '.'); ?></td>
                                            <td class="text-end">Rp <?php echo number_format($d['subtotal'], 0, ',', '.'); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr class="fw-bold">
                                            <td colspan="3" class="text-end">Total</td>
                                            <td class="text-end text-primary">Rp <?php echo number_format($p['total'], 0, ',', '.'); ?></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>

                        <?php if ($p['nota']): ?>
                        <div class="mb-3">
                            <label class="text-muted small">Nota</label>
                            <img src="<?php echo $base_url; ?>/uploads/nota/<?php echo htmlspecialchars($p['nota']); ?>" class="img-fluid rounded mt-1" alt="Nota Pembelian">
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="modal-footer border-0">
                        <button type="button" class="btn btn-warning" onclick="bukaEditPembelian(<?php echo $p['id']; ?>)">
                            <i class="bi bi-pencil"></i> Edit
                        </button>

                        <button type="button" class="btn btn-danger" data-bs-dismiss="modal" onclick="hapusPembelian(<?php echo $p['id']; ?>)">
                            <i class="bi bi-trash"></i> Hapus
                        </button>

                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            Tutup
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal fade" id="editModal<?php echo $p['id']; ?>" tabindex="-1">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content rounded-4">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="edit_pembelian">
                        <input type="hidden" name="id" value="<?php echo $p['id']; ?>">

                        <div class="modal-header border-0">
                            <h5 class="modal-title">Edit Pembelian</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>

                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">Tanggal</label>
                                <input type="date" class="form-control form-control-lg" name="tanggal" value="<?php echo htmlspecialchars($p['tanggal']); ?>" required>
                            </div>

                            <input type="hidden" name="metode_pembayaran" value="kas_operasional">
                            <div class="mb-3">
                                <label class="form-label">Metode Pembayaran</label>
                                <div class="form-control form-control-lg bg-light">Kas Operasional</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Item Barang</label>
                                <div id="itemsContainerEdit<?php echo $p['id']; ?>" data-total-target="totalDisplayEdit<?php echo $p['id']; ?>">
                                    <?php foreach ($details as $index => $d): ?>
                                    <div class="card mb-2 p-3 item-row">
                                        <div class="row g-2 align-items-end">
                                            <div class="col-md-5">
                                                <label class="form-label small">Nama Barang</label>
                                                <input type="text" class="form-control item-nama-barang" name="items[<?php echo $index; ?>][nama_barang]" value="<?php echo htmlspecialchars($d['barang_nama']); ?>" placeholder="Contoh: Alpukat, Gula, dll" required>
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label small">Qty</label>
                                                <input type="number" class="form-control item-qty" name="items[<?php echo $index; ?>][qty]" value="<?php echo (int) $d['qty']; ?>" min="1" required oninput="calculateTotalForContainer(this.closest('[data-total-target]'))">
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label small">Harga</label>
                                                <div class="input-group">
                                                    <span class="input-group-text bg-light fw-semibold">Rp</span>
                                                    <input type="number" class="form-control item-harga" name="items[<?php echo $index; ?>][harga]" value="<?php echo htmlspecialchars($d['harga']); ?>" min="1" required oninput="calculateTotalForContainer(this.closest('[data-total-target]'))">
                                                </div>
                                            </div>
                                            <div class="col-md-1">
                                                <button type="button" class="btn btn-outline-danger w-100" onclick="removeItemRow(this)">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <button type="button" class="btn btn-outline-primary w-100 mt-2" onclick="addItem('itemsContainerEdit<?php echo $p['id']; ?>')">
                                    <i class="bi bi-plus-lg me-1"></i>Tambah Item
                                </button>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Total: Rp <span id="totalDisplayEdit<?php echo $p['id']; ?>">0</span></label>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Ganti Nota</label>
                                <input type="file" class="form-control form-control-lg" name="nota" accept="image/*">
                                <?php if ($p['nota']): ?>
                                <small class="text-muted d-block mt-2">Nota saat ini: <?php echo htmlspecialchars($p['nota']); ?></small>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="modal-footer border-0">
                            <button type="button" class="btn btn-secondary btn-lg px-4" data-bs-dismiss="modal">Batal</button>
                            <button type="submit" class="btn btn-primary btn-lg px-5 fw-semibold">Update</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

            </tbody>
            <tfoot>
                <tr style="background: linear-gradient(135deg,#FEF3C7 0%,#FDE68A 100%);">
                    <td colspan="4" class="px-4 py-3 fw-bold text-end">
                        <span class="text-warning-emphasis">TOTAL (<?php echo number_format($ringkasan_pembelian['total_data'] ?? 0); ?> transaksi)</span>
                    </td>
                    <td class="px-3 py-3 fw-bold text-end">
                        <h5 class="mb-0 text-warning-emphasis">Rp <?php echo number_format($ringkasan_pembelian['total_nominal'] ?? 0, 0, ',', '.'); ?></h5>
                    </td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php endif; ?>
</div>

<div class="modal fade" id="tambahModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow-lg">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="tambah_pembelian">

                <div class="modal-header border-0 px-4 pt-4 pb-2">
                    <h5 class="modal-title fw-bold">Tambah Pembelian</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body px-4 py-3">
                    <div class="mb-4">
                        <label class="form-label fw-semibold small">Tanggal</label>
                        <input type="date" class="form-control form-control-lg" name="tanggal" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>

                    <input type="hidden" name="metode_pembayaran" value="kas_operasional">
                    <div class="mb-4">
                        <label class="form-label fw-semibold small">Metode Pembayaran</label>
                        <div class="form-control form-control-lg bg-light">Kas Operasional</div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold small">Item Barang</label>
                        <div id="itemsContainerTambah" data-total-target="totalDisplayTambah"></div>
                        <button type="button" class="btn btn-outline-primary w-100 mt-2 fw-medium" onclick="addItem('itemsContainerTambah')">
                            <i class="bi bi-plus-lg me-1"></i>Tambah Item
                        </button>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold">Total: Rp <span id="totalDisplayTambah">0</span></label>
                    </div>

                    <div class="mb-2">
                        <label class="form-label fw-semibold small">Nota (opsional)</label>
                        <input type="file" class="form-control form-control-lg" name="nota" accept="image/*">
                    </div>
                </div>

                <div class="modal-footer border-0 px-4 pb-4 pt-2">
                    <button type="button" class="btn btn-secondary btn-lg px-4" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary btn-lg px-5 fw-semibold">Simpan</button>
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
        <div class="card mb-2 p-3 item-row border border-opacity-50">
            <div class="row g-2 align-items-end">
                <div class="col-md-5">
                    <label class="form-label small fw-medium">Nama Barang</label>
                    <input type="text" class="form-control item-nama-barang" name="items[${index}][nama_barang]" value="${namaBarang}" placeholder="Contoh: Alpukat, Gula" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-medium">Qty</label>
                    <input type="number" class="form-control item-qty" name="items[${index}][qty]" value="${qty}" min="1" required oninput="calculateTotalForContainer(this.closest('[data-total-target]'))">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-medium">Harga</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light fw-semibold border-end-0">Rp</span>
                        <input type="number" class="form-control item-harga border-start-0" name="items[${index}][harga]" value="${harga}" min="1" required oninput="calculateTotalForContainer(this.closest('[data-total-target]'))">
                    </div>
                </div>
                <div class="col-md-1">
                    <button type="button" class="btn btn-outline-danger w-100" onclick="removeItemRow(this)">
                        <i class="bi bi-trash"></i>
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
.transition-card {
    transition: all 0.25s ease;
}
.transition-card:hover {
    background: rgba(59, 130, 246, 0.03) !important;
}
.cursor-pointer {
    cursor: pointer;
}
.action-btn {
    width: 38px;
    height: 38px;
    border: none;
    border-radius: 12px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    transition: all 0.2s ease;
    background: #f3f4f6;
    color: #6b7280;
}
.action-btn:hover {
    transform: scale(1.05);
}
.action-btn.edit {
    background: #FEF3C7;
    color: #92400E;
}
.action-btn.edit:hover {
    background: #FDE68A;
}
.action-btn.delete {
    background: #FEE2E2;
    color: #991B1B;
}
.action-btn.delete:hover {
    background: #FECACA;
}
</style>

<?php include '../includes/footer.php'; ?>
<?php if (!empty($GLOBALS['alert_script'])) echo $GLOBALS['alert_script']; ?>

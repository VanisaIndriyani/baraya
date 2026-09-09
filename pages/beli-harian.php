<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once '../includes/header.php';
require_once '../includes/finance-utils.php';

$redirect_url = $base_url . '/pages/beli-harian.php';

function alertHarian($icon, $title, $text, $redirect = null, $timer = 1500)
{
    $redirect_script = $redirect
        ? ".then(() => window.location.href = '" . addslashes($redirect) . "')"
        : '';
    echo "<script>
        Swal.fire({
            icon: '" . addslashes($icon) . "',
            title: '" . addslashes($title) . "',
            text: '" . addslashes($text) . "',
            timer: " . (int) $timer . "
        })" . $redirect_script . ";
    </script>";
}

function harianPastikanTabel($pdo)
{
    static $sudah_dicek = false;
    if ($sudah_dicek) return;

    $pdo->exec("CREATE TABLE IF NOT EXISTS beli_harian (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_periode INT NULL,
        id_rekening INT NULL DEFAULT NULL,
        nama_barang VARCHAR(200) NOT NULL,
        qty DECIMAL(10,2) NOT NULL DEFAULT 0,
        satuan VARCHAR(20) NOT NULL DEFAULT 'kg',
        harga DECIMAL(15,2) NOT NULL DEFAULT 0,
        subtotal DECIMAL(15,2) NOT NULL DEFAULT 0,
        keterangan VARCHAR(255) NULL,
        tanggal DATE NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_tanggal (tanggal),
        INDEX idx_periode (id_periode),
        INDEX idx_id_rekening (id_rekening)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if (!financeColumnExists($pdo, 'beli_harian', 'id_rekening')) {
        try {
            $pdo->exec("ALTER TABLE beli_harian ADD COLUMN id_rekening INT NULL DEFAULT NULL AFTER id_periode");
        } catch (Throwable $e) {}
    }
    try { $pdo->exec("ALTER TABLE beli_harian ADD INDEX idx_id_rekening (id_rekening)"); } catch (Throwable $e) {}

    financeEnsureAccountSystem($pdo);

    if (!financeColumnExists($pdo, 'saldo_rekening_transaksi', 'id_beli_harian')) {
        try {
            $pdo->exec("ALTER TABLE saldo_rekening_transaksi ADD COLUMN id_beli_harian INT NULL AFTER id_pembelian");
        } catch (Throwable $e) {}
    }

    $sudah_dicek = true;
}

function beliHarianAmbilRekeningSumber($pdo, $kodeRek)
{
    $peta = [
        'uang_ruko' => 'uang_ruko',
        'bank_jago' => 'operasional',
        'bank_bri'  => 'penjualan',
    ];
    $kodeAsli = $peta[$kodeRek] ?? 'uang_ruko';
    $rekening = financeGetAccountByCode($pdo, $kodeAsli);
    if (!$rekening) {
        $rekening = financeGetAccountByCode($pdo, 'uang_ruko');
    }
    return $rekening;
}

function ambilTransaksiHarian($pdo, $id_beli_harian)
{
    $stmt = $pdo->prepare("SELECT * FROM saldo_rekening_transaksi WHERE id_beli_harian = ? ORDER BY id ASC LIMIT 1");
    $stmt->execute([(int) $id_beli_harian]);
    return $stmt->fetch() ?: null;
}

function sinkronSaldoHarian($pdo, $id_harian, $id_periode, $subtotal, $tanggal, $catatan, $id_rekening_sumber = null)
{
    harianPastikanTabel($pdo);

    if ($id_rekening_sumber > 0) {
        $stmt = $pdo->prepare("SELECT * FROM saldo_rekening WHERE id = ? LIMIT 1");
        $stmt->execute([(int) $id_rekening_sumber]);
        $rekening = $stmt->fetch();
    }
    if (empty($rekening)) {
        $rekening = financeGetAccountByCode($pdo, 'uang_ruko');
    }
    if (!$rekening) return;

    $transaksi_lama = ambilTransaksiHarian($pdo, $id_harian);

    $keterangan = 'Beli harian: ' . trim($catatan);

    if ($transaksi_lama) {
        financeAdjustSaldoRekening($pdo, $transaksi_lama['id_rekening'], (float) $transaksi_lama['jumlah']);
        $stmt = $pdo->prepare("
            UPDATE saldo_rekening_transaksi
            SET id_rekening = ?, id_periode = ?, tipe_transaksi = 'kredit', jumlah = ?, keterangan = ?, tanggal = ?
            WHERE id = ?
        ");
        $stmt->execute([
            (int) $rekening['id'],
            $id_periode ?: null,
            (float) $subtotal,
            $keterangan,
            $tanggal,
            (int) $transaksi_lama['id']
        ]);
        financeAdjustSaldoRekening($pdo, $rekening['id'], -1 * (float) $subtotal);
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO saldo_rekening_transaksi (id_rekening, id_periode, tipe_transaksi, jumlah, keterangan, tanggal, id_beli_harian)
        VALUES (?, ?, 'kredit', ?, ?, ?, ?)
    ");
    $stmt->execute([
        (int) $rekening['id'],
        $id_periode ?: null,
        (float) $subtotal,
        $keterangan,
        $tanggal,
        (int) $id_harian
    ]);
    financeAdjustSaldoRekening($pdo, $rekening['id'], -1 * (float) $subtotal);
}

function hapusTransaksiHarian($pdo, $id_harian)
{
    $transaksi = ambilTransaksiHarian($pdo, $id_harian);
    if (!$transaksi) return;
    financeRollbackSaldoTransaksi($pdo, $transaksi);
    $stmt = $pdo->prepare("DELETE FROM saldo_rekening_transaksi WHERE id = ?");
    $stmt->execute([(int) $transaksi['id']]);
}

harianPastikanTabel($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'tambah') {
        $nama_barang = trim($_POST['nama_barang'] ?? '');
        $qty = 1;
        $satuan = 'item';
        $harga = (float) ($_POST['harga'] ?? 0);
        $keterangan = trim($_POST['keterangan'] ?? '');
        $tanggal = date('Y-m-d');
        $id_periode = null;
        $subtotal = $qty * $harga;
        $sumber_bayar = trim($_POST['sumber_bayar'] ?? 'uang_ruko');
        if (!in_array($sumber_bayar, ['uang_ruko', 'bank_jago', 'bank_bri'])) $sumber_bayar = 'uang_ruko';
        $rekeningSumber = beliHarianAmbilRekeningSumber($pdo, $sumber_bayar);
        $id_rekening = $rekeningSumber ? (int) $rekeningSumber['id'] : null;

        if ($nama_barang === '' || $harga <= 0) {
            alertHarian('error', 'Gagal', 'Nama barang dan harga harus diisi.', null, 3000);
        } else {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("INSERT INTO beli_harian (id_periode, id_rekening, nama_barang, qty, satuan, harga, subtotal, keterangan, tanggal) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$id_periode, $id_rekening, $nama_barang, $qty, $satuan, $harga, $subtotal, $keterangan, $tanggal]);
                $id_harian = $pdo->lastInsertId();

                $catatan_saldo = $nama_barang . ($keterangan ? ' - ' . $keterangan : '');
                sinkronSaldoHarian($pdo, $id_harian, $id_periode, $subtotal, $tanggal, $catatan_saldo, $id_rekening);

                $pdo->commit();
                alertHarian('success', 'Berhasil', 'Pembelian harian tersimpan & saldo terpotong.', $redirect_url);
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                alertHarian('error', 'Gagal', $e->getMessage(), null, 3000);
            }
        }
    }

    if ($action === 'edit') {
        $id_harian = (int) ($_POST['id'] ?? 0);
        $nama_barang = trim($_POST['nama_barang'] ?? '');
        $qty = 1;
        $satuan = 'item';
        $harga = (float) ($_POST['harga'] ?? 0);
        $keterangan = trim($_POST['keterangan'] ?? '');
        $tanggal = date('Y-m-d');
        $id_periode = null;
        $subtotal = $qty * $harga;
        $sumber_bayar = trim($_POST['sumber_bayar'] ?? 'uang_ruko');
        if (!in_array($sumber_bayar, ['uang_ruko', 'bank_jago', 'bank_bri'])) $sumber_bayar = 'uang_ruko';
        $rekeningSumber = beliHarianAmbilRekeningSumber($pdo, $sumber_bayar);
        $id_rekening = $rekeningSumber ? (int) $rekeningSumber['id'] : null;

        if ($id_harian <= 0 || $nama_barang === '' || $harga <= 0) {
            alertHarian('error', 'Gagal', 'Data tidak lengkap.', null, 3000);
        } else {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("UPDATE beli_harian SET id_periode = ?, id_rekening = ?, nama_barang = ?, qty = ?, satuan = ?, harga = ?, subtotal = ?, keterangan = ?, tanggal = ? WHERE id = ?");
                $stmt->execute([$id_periode, $id_rekening, $nama_barang, $qty, $satuan, $harga, $subtotal, $keterangan, $tanggal, $id_harian]);

                $catatan_saldo = $nama_barang . ($keterangan ? ' - ' . $keterangan : '');
                sinkronSaldoHarian($pdo, $id_harian, $id_periode, $subtotal, $tanggal, $catatan_saldo, $id_rekening);

                $pdo->commit();
                alertHarian('success', 'Berhasil', 'Data berhasil diperbarui.', $redirect_url);
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                alertHarian('error', 'Gagal', $e->getMessage(), null, 3000);
            }
        }
    }
}

if (isset($_GET['hapus'])) {
    $id_harian = (int) $_GET['hapus'];
    try {
        $stmt = $pdo->prepare("SELECT * FROM beli_harian WHERE id = ?");
        $stmt->execute([$id_harian]);
        $data = $stmt->fetch();
        if (!$data) throw new Exception('Data tidak ditemukan.');

        $pdo->beginTransaction();
        hapusTransaksiHarian($pdo, $id_harian);
        $stmt = $pdo->prepare("DELETE FROM beli_harian WHERE id = ?");
        $stmt->execute([$id_harian]);
        $pdo->commit();
        alertHarian('success', 'Berhasil', 'Data dihapus & saldo dikembalikan.', $redirect_url);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        alertHarian('error', 'Gagal', $e->getMessage(), null, 3000);
    }
}

$search = trim($_GET['search'] ?? '');
$tanggal_filter = $_GET['tanggal'] ?? '';

$where = [];
$params = [];
if ($search !== '') {
    $where[] = "(nama_barang LIKE ? OR keterangan LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($tanggal_filter !== '') {
    $where[] = "tanggal = ?";
    $params[] = $tanggal_filter;
}
$where_sql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

$stmt = $pdo->prepare("SELECT COUNT(*) AS total, COALESCE(SUM(subtotal),0) AS total_nominal FROM beli_harian" . $where_sql);
$stmt->execute($params);
$ringkasan = $stmt->fetch();

$stmt = $pdo->prepare("SELECT bh.*, sr.kode_rekening, sr.nama_rekening FROM beli_harian bh LEFT JOIN saldo_rekening sr ON bh.id_rekening = sr.id" . $where_sql . " ORDER BY bh.tanggal DESC, bh.id DESC LIMIT 100");
$stmt->execute($params);
$data_list = $stmt->fetchAll();

function badgeSumberBayar($kode)
{
    $warna = [
        'uang_ruko'   => 'background: linear-gradient(135deg, #991B1B, #7F1D1D); color: #fff; border: 1px solid #FBBF24;',
        'operasional' => 'background: linear-gradient(135deg, #1E40AF, #1D4ED8); color: #fff; border: 1px solid #3B82F6;',
        'penjualan'   => 'background: linear-gradient(135deg, #1E3A8A, #1E40AF); color: #fff; border: 1px solid #60A5FA;',
    ];
    $label = [
        'uang_ruko'   => '🏠 Ruko',
        'operasional' => '💳 Jago (Kas Ops)',
        'penjualan'   => '🏦 BRI (Hasil Jual)',
    ];
    $k = $kode ?? 'uang_ruko';
    if (!isset($warna[$k])) $k = 'uang_ruko';
    return '<span class="badge px-3 py-1 rounded-pill small" style="' . $warna[$k] . '">' . $label[$k] . '</span>';
}
?>

<div class="page-header" style="background: linear-gradient(135deg, #450A0A 0%, #7F1D1D 50%, #991B1B 100%); border-bottom: 2px solid #FBBF24; padding: 22px 28px !important;">
    <a href="<?php echo $base_url; ?>/admin_dashboard.php" class="text-white text-decoration-none mb-2 d-inline-block"><i class="bi bi-arrow-left"></i> Kembali</a>
    <h4 class="mb-0" style="color: #fff; font-weight: 700;"><i class="bi bi-bag-dash me-2" style="color: #FBBF24;"></i>Beli Bahan Harian</h4>
    <small style="color: rgba(255,255,255,0.75);">Catat pengeluaran kecil harian. Pilih sumber pembayaran otomatis potong saldo: <b style="color:#FBBF24;">Uang Ruko / Bank Jago / Bank BRI</b>.</small>
</div>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2" style="margin-top: 18px;">
    <div></div>
    <button type="button" class="btn" data-bs-toggle="modal" data-bs-target="#tambahModal" style="background: linear-gradient(135deg, #7F1D1D 0%, #991B1B 100%); color: #fff; border: 1.5px solid #FBBF24; border-radius: 14px; font-weight: 600; padding: 10px 22px; box-shadow: 0 6px 18px rgba(153,27,27,0.25); transition: all 0.25s ease;" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 10px 26px rgba(153,27,27,0.32)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 6px 18px rgba(153,27,27,0.25)';">
        <i class="bi bi-plus-lg me-1"></i> Catat Beli Harian
    </button>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card h-100 transition-card" style="border: 0; border-radius: 18px; background: linear-gradient(135deg, #450A0A 0%, #7F1D1D 100%); box-shadow: 0 8px 24px rgba(153,27,27,0.18); border: 1px solid #FBBF24;">
            <div class="card-body" style="padding: 20px 24px;">
                <div class="small mb-1" style="color: rgba(255,255,255,0.7); letter-spacing: 0.5px;">Total Beli (Filter)</div>
                <h3 class="mb-0 fw-bold" style="color: #fff; font-size: 1.8rem; letter-spacing: -0.5px;">Rp <?php echo number_format($ringkasan['total_nominal'] ?? 0, 0, ',', '.'); ?></h3>
                <div style="margin-top: 8px;" class="d-flex align-items-center gap-2">
                    <span style="background: #FBBF24; color: #450A0A; border-radius: 999px; padding: 3px 12px; font-size: 0.72rem; font-weight: 700;"><i class="bi bi-cash-stack me-1"></i>Pengeluaran</span>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card h-100 transition-card" style="border: 0; border-radius: 18px; background: linear-gradient(135deg, #FBBF24 0%, #F59E0B 100%); box-shadow: 0 8px 24px rgba(251,191,36,0.18); border: 1px solid #991B1B;">
            <div class="card-body" style="padding: 20px 24px;">
                <div class="small mb-1" style="color: #450A0A; letter-spacing: 0.5px; font-weight: 600;">Jumlah Transaksi</div>
                <h3 class="mb-0 fw-bold" style="color: #450A0A; font-size: 1.8rem; letter-spacing: -0.5px;"><?php echo number_format($ringkasan['total'] ?? 0, 0, ',', '.'); ?> <span style="font-size: 1rem; font-weight: 500; opacity: 0.8;">transaksi</span></h3>
                <div style="margin-top: 8px;" class="d-flex align-items-center gap-2">
                    <span style="background: #450A0A; color: #FBBF24; border-radius: 999px; padding: 3px 12px; font-size: 0.72rem; font-weight: 700;"><i class="bi bi-receipt-cutoff me-1"></i>Riwayat</span>
                </div>
            </div>
        </div>
    </div>
</div>

<form method="GET" class="row g-2 mb-4 p-4" style="background: #fff; border-radius: 18px; box-shadow: 0 4px 16px rgba(153,27,27,0.08); border: 1px solid rgba(251,191,36,0.35);">
    <div class="col-md-5">
        <div class="input-group" style="border-radius: 14px; overflow: hidden;">
            <span class="input-group-text" style="background: rgba(153,27,27,0.06); border: 0; color: #991B1B;"><i class="bi bi-search"></i></span>
            <input type="text" class="form-control" name="search" placeholder="Cari nama barang / catatan..." value="<?php echo htmlspecialchars($search); ?>" style="border: 0; padding: 10px 14px;">
        </div>
    </div>
    <div class="col-md-3">
        <div class="input-group" style="border-radius: 14px; overflow: hidden;">
            <span class="input-group-text" style="background: rgba(153,27,27,0.06); border: 0; color: #991B1B;"><i class="bi bi-calendar3"></i></span>
            <input type="date" class="form-control" name="tanggal" value="<?php echo htmlspecialchars($tanggal_filter); ?>" style="border: 0; padding: 10px 14px;">
        </div>
    </div>
    <div class="col-md-4 d-flex gap-2">
        <button class="btn w-100" type="submit" style="background: linear-gradient(135deg, #7F1D1D 0%, #991B1B 100%); color: #fff; border-radius: 14px; border: 1px solid #FBBF24; font-weight: 600;"><i class="bi bi-funnel me-1"></i> Terapkan</button>
        <a href="beli-harian.php" class="btn w-100" style="background: #fff; color: #991B1B; border-radius: 14px; border: 1.5px solid #991B1B; font-weight: 600;">Reset</a>
    </div>
</form>

<div class="mb-3 d-flex align-items-center justify-content-between">
    <h6 class="mb-0" style="color: #450A0A; font-weight: 700;"><i class="bi bi-clock-history me-1" style="color:#FBBF24;"></i>Riwayat Beli Harian</h6>
</div>

<div class="row g-3">
    <?php if (empty($data_list)): ?>
    <div class="col-12">
        <div class="card" style="border: 0; border-radius: 18px; box-shadow: 0 4px 14px rgba(153,27,27,0.06);">
            <div class="card-body text-center text-muted py-5" style="color: #6B7280;">
                <i class="bi bi-inbox fs-1 mb-3" style="color: rgba(153,27,27,0.2);"></i><br>
                Belum ada data. Klik <b style="color:#991B1B;">Catat Beli Harian</b> untuk mulai.
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php foreach ($data_list as $d): ?>
    <div class="col-12 col-md-6 col-xl-4">
        <div class="card h-100 transition-card" style="border: 0; border-radius: 18px; box-shadow: 0 4px 16px rgba(153,27,27,0.07); border: 1px solid rgba(251,191,36,0.18); overflow: hidden;">
            <div class="card-body" style="padding: 18px 20px;">
                <div class="d-flex align-items-start justify-content-between mb-3">
                    <?php echo badgeSumberBayar($d['kode_rekening'] ?? null); ?>
                    <span class="small" style="color: #6B7280;"><i class="bi bi-calendar3 me-1"></i><?php echo date('d M Y', strtotime($d['tanggal'])); ?></span>
                </div>
                <div style="margin-bottom: 10px;">
                    <h5 class="mb-1 fw-bold" style="color: #1F2937; font-size: 1.15rem; letter-spacing: -0.3px;"><?php echo htmlspecialchars($d['nama_barang']); ?></h5>
                    <?php if (!empty($d['keterangan'])): ?>
                    <div class="small" style="color: #6B7280; font-style: italic; line-height: 1.5;"><i class="bi bi-chat-dots me-1" style="color: #9CA3AF;"></i><?php echo htmlspecialchars($d['keterangan']); ?></div>
                    <?php endif; ?>
                </div>
                <div class="d-flex align-items-end justify-content-between mt-3">
                    <div>
                        <div class="small mb-1" style="color: #9CA3AF; letter-spacing: 0.3px;">Subtotal</div>
                        <div class="fw-bold" style="color: #991B1B; font-size: 1.4rem; letter-spacing: -0.5px;">Rp <?php echo number_format($d['subtotal'], 0, ',', '.'); ?></div>
                    </div>
                    <div class="d-flex gap-2">
                        <button class="btn" style="padding: 7px 11px; border-radius: 10px; background: #fff; color: #991B1B; border: 1.5px solid #991B1B; line-height: 1;" onclick="bukaEdit(<?php echo $d['id']; ?>, '<?php echo htmlspecialchars($d['nama_barang'], ENT_QUOTES); ?>', <?php echo (float)$d['harga']; ?>, '<?php echo htmlspecialchars($d['keterangan'] ?? '', ENT_QUOTES); ?>', '<?php echo $d['kode_rekening'] ?? 'uang_ruko'; ?>')" title="Edit">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button class="btn" style="padding: 7px 11px; border-radius: 10px; background: linear-gradient(135deg,#7F1D1D,#991B1B); color: #fff; border: 1px solid #FBBF24; line-height: 1;" onclick="hapusData(<?php echo $d['id']; ?>)" title="Hapus">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<style>
    .sumber-card {
        border: 2px solid #E5E7EB;
        border-radius: 14px;
        padding: 14px 16px;
        cursor: pointer;
        transition: all 0.2s ease;
        background: #fff;
        position: relative;
    }
    .sumber-card:hover {
        border-color: rgba(251,191,36,0.6);
        transform: translateY(-1px);
    }
    .sumber-card.active {
        border-color: #991B1B !important;
        background: linear-gradient(135deg, rgba(153,27,27,0.05), rgba(251,191,36,0.08));
        box-shadow: 0 4px 12px rgba(153,27,27,0.12);
    }
    .sumber-card .dot-check {
        width: 18px; height: 18px;
        border-radius: 50%;
        border: 2px solid #D1D5DB;
        display: flex;
        align-items: center; justify-content: center;
        transition: all 0.2s ease;
    }
    .sumber-card.active .dot-check {
        border-color: #991B1B;
        background: #991B1B;
    }
    .sumber-card.active .dot-check::after {
        content: '';
        width: 8px; height: 8px;
        border-radius: 50%;
        background: #FBBF24;
    }
    .transition-card {
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .transition-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 14px 30px rgba(153,27,27,0.15) !important;
    }
</style>

<div class="modal fade" id="tambahModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 20px; border: 0; overflow: hidden; box-shadow: 0 20px 50px rgba(69,10,10,0.25);">
            <form method="POST" id="formTambah">
                <input type="hidden" name="action" value="tambah">
                <input type="hidden" name="qty" value="1">
                <input type="hidden" name="satuan" value="item">
                <input type="hidden" name="tanggal" value="<?php echo date('Y-m-d'); ?>">
                <input type="hidden" name="sumber_bayar" id="tambah-sumber_bayar" value="uang_ruko">
                <div class="modal-header border-0" style="background: linear-gradient(135deg, #450A0A 0%, #7F1D1D 50%, #991B1B 100%); color: #fff; padding: 20px 26px; border-bottom: 2px solid #FBBF24;">
                    <h5 class="modal-title fw-bold" style="font-size: 1.1rem;"><i class="bi bi-plus-circle me-1" style="color:#FBBF24;"></i>Catat Beli Harian</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" style="background: rgba(255,255,255,0.2) url(\"data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16' fill='%23fff'%3e%3cpath d='M.293.293a1 1 0 0 1 1.414 0L8 6.586 14.293.293a1 1 0 1 1 1.414 1.414L9.414 8l6.293 6.293a1 1 0 0 1-1.414 1.414L8 9.414l-6.293 6.293a1 1 0 0 1-1.414-1.414L6.586 8 .293 1.707a1 1 0 0 1 0-1.414z'/%3e%3c/svg%3e\") center/1em auto no-repeat;"></button>
                </div>
                <div class="modal-body" style="padding: 24px 26px;">
                    <div class="mb-4">
                        <label class="form-label mb-2" style="color: #450A0A; font-weight: 700; font-size: 0.95rem;">Nama Barang</label>
                        <input type="text" class="form-control" name="nama_barang" id="tambah-nama" placeholder="Contoh: Gula Pasir, Plastik Sedotan" required style="border-radius: 12px; border: 1.5px solid #E5E7EB; padding: 12px 16px; font-size: 1rem;" onfocus="this.style.borderColor='#991B1B'" onblur="this.style.borderColor='#E5E7EB'">
                    </div>
                    <div class="mb-4">
                        <label class="form-label mb-2" style="color: #450A0A; font-weight: 700; font-size: 0.95rem;">Harga (Rp)</label>
                        <div class="input-group" style="border-radius: 12px; overflow: hidden;">
                            <span class="input-group-text" style="background: linear-gradient(135deg, #450A0A, #7F1D1D); color: #FBBF24; border: 0; font-weight: 700; padding: 12px 18px;">Rp</span>
                            <input type="number" class="form-control" name="harga" id="tambah-harga" value="0" min="0" required oninput="hitungSubtotal('tambah')" style="border: 1.5px solid #E5E7EB; border-left: 0; padding: 12px 16px; font-size: 1.1rem; font-weight: 600; color: #1F2937;">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label mb-2" style="color: #450A0A; font-weight: 700; font-size: 0.95rem;">Catatan (opsional)</label>
                        <textarea class="form-control" name="keterangan" id="tambah-keterangan" rows="2" placeholder="Contoh: Beli di warung dekat ruko" style="border-radius: 12px; border: 1.5px solid #E5E7EB; padding: 12px 16px; resize: vertical;"></textarea>
                    </div>
                    <div class="mb-2">
                        <label class="form-label mb-3 d-block" style="color: #450A0A; font-weight: 700; font-size: 0.95rem;">Sumber Pembayaran <span class="small fw-normal" style="color: #6B7280;">(Otomatis potong saldo)</span></label>
                        <div class="row g-2">
                            <div class="col-12">
                                <div class="sumber-card active d-flex align-items-center gap-3" onclick="pilihSumber('tambah', 'uang_ruko', this)">
                                    <div class="rounded-circle d-flex align-items-center justify-content-center" style="width: 42px; height: 42px; background: linear-gradient(135deg,#991B1B,#7F1D1D); color:#FBBF24; flex-shrink: 0;">
                                        <i class="bi bi-house-door fs-5"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div style="font-weight: 800; color: #1F2937;">Uang Ruko</div>
                                        <div class="small" style="color: #6B7280;"><i class="bi bi-cash-stack me-1"></i>Kas Tunai Fisik</div>
                                    </div>
                                    <div class="dot-check"></div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="sumber-card d-flex align-items-center gap-3" onclick="pilihSumber('tambah', 'bank_jago', this)">
                                    <div class="rounded-circle d-flex align-items-center justify-content-center" style="width: 42px; height: 42px; background: linear-gradient(135deg,#1E40AF,#1D4ED8); color:#fff; flex-shrink: 0;">
                                        <i class="bi bi-credit-card fs-5"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div style="font-weight: 800; color: #1F2937; font-size: 0.92rem;">Bank Jago</div>
                                        <div class="small" style="color: #6B7280;"><i class="bi bi-wallet me-1"></i>Kas Operasional</div>
                                    </div>
                                    <div class="dot-check"></div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="sumber-card d-flex align-items-center gap-3" onclick="pilihSumber('tambah', 'bank_bri', this)">
                                    <div class="rounded-circle d-flex align-items-center justify-content-center" style="width: 42px; height: 42px; background: linear-gradient(135deg,#1E3A8A,#1E40AF); color:#fff; flex-shrink: 0;">
                                        <i class="bi bi-building fs-5"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div style="font-weight: 800; color: #1F2937; font-size: 0.92rem;">Bank BRI</div>
                                        <div class="small" style="color: #6B7280;"><i class="bi bi-graph-up me-1"></i>Hasil Penjualan</div>
                                    </div>
                                    <div class="dot-check"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div style="background: linear-gradient(135deg, rgba(251,191,36,0.12), rgba(153,27,27,0.06)); border: 1px dashed #FBBF24; border-radius: 14px; padding: 14px 18px; margin-top: 18px;">
                        <div class="d-flex align-items-center justify-content-between">
                            <span class="small" style="color: #450A0A; font-weight: 600;"><i class="bi bi-info-circle me-1" style="color:#FBBF24;"></i>Total Subtotal</span>
                            <span class="fw-bold" id="tambah-subtotal-display" style="color: #991B1B; font-size: 1.2rem;">Rp 0</span>
                        </div>
                        <input type="hidden" id="tambah-subtotal">
                    </div>
                </div>
                <div class="modal-footer border-0" style="padding: 16px 26px 24px 26px; gap: 10px;">
                    <button type="button" class="btn" data-bs-dismiss="modal" style="background: #fff; color: #991B1B; border: 1.5px solid #991B1B; border-radius: 12px; font-weight: 600; padding: 10px 22px;">Batal</button>
                    <button type="submit" class="btn" style="background: linear-gradient(135deg, #7F1D1D 0%, #991B1B 100%); color: #fff; border: 1.5px solid #FBBF24; border-radius: 12px; font-weight: 700; padding: 10px 24px; box-shadow: 0 6px 18px rgba(153,27,27,0.25);"><i class="bi bi-save2 me-1"></i>Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 20px; border: 0; overflow: hidden; box-shadow: 0 20px 50px rgba(69,10,10,0.25);">
            <form method="POST" id="formEdit">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit-id">
                <input type="hidden" name="qty" value="1">
                <input type="hidden" name="satuan" value="item">
                <input type="hidden" name="tanggal" value="<?php echo date('Y-m-d'); ?>">
                <input type="hidden" name="sumber_bayar" id="edit-sumber_bayar" value="uang_ruko">
                <div class="modal-header border-0" style="background: linear-gradient(135deg, #450A0A 0%, #7F1D1D 50%, #991B1B 100%); color: #fff; padding: 20px 26px; border-bottom: 2px solid #FBBF24;">
                    <h5 class="modal-title fw-bold" style="font-size: 1.1rem;"><i class="bi bi-pencil-square me-1" style="color:#FBBF24;"></i>Edit Beli Harian</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" style="background: rgba(255,255,255,0.2) url(\"data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16' fill='%23fff'%3e%3cpath d='M.293.293a1 1 0 0 1 1.414 0L8 6.586 14.293.293a1 1 0 1 1 1.414 1.414L9.414 8l6.293 6.293a1 1 0 0 1-1.414 1.414L8 9.414l-6.293 6.293a1 1 0 0 1-1.414-1.414L6.586 8 .293 1.707a1 1 0 0 1 0-1.414z'/%3e%3c/svg%3e\") center/1em auto no-repeat;"></button>
                </div>
                <div class="modal-body" style="padding: 24px 26px;">
                    <div class="mb-4">
                        <label class="form-label mb-2" style="color: #450A0A; font-weight: 700; font-size: 0.95rem;">Nama Barang</label>
                        <input type="text" class="form-control" name="nama_barang" id="edit-nama" required style="border-radius: 12px; border: 1.5px solid #E5E7EB; padding: 12px 16px; font-size: 1rem;">
                    </div>
                    <div class="mb-4">
                        <label class="form-label mb-2" style="color: #450A0A; font-weight: 700; font-size: 0.95rem;">Harga (Rp)</label>
                        <div class="input-group" style="border-radius: 12px; overflow: hidden;">
                            <span class="input-group-text" style="background: linear-gradient(135deg, #450A0A, #7F1D1D); color: #FBBF24; border: 0; font-weight: 700; padding: 12px 18px;">Rp</span>
                            <input type="number" class="form-control" name="harga" id="edit-harga" min="0" required oninput="hitungSubtotal('edit')" style="border: 1.5px solid #E5E7EB; border-left: 0; padding: 12px 16px; font-size: 1.1rem; font-weight: 600; color: #1F2937;">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label mb-2" style="color: #450A0A; font-weight: 700; font-size: 0.95rem;">Catatan (opsional)</label>
                        <textarea class="form-control" name="keterangan" id="edit-keterangan" rows="2" style="border-radius: 12px; border: 1.5px solid #E5E7EB; padding: 12px 16px; resize: vertical;"></textarea>
                    </div>
                    <div class="mb-2">
                        <label class="form-label mb-3 d-block" style="color: #450A0A; font-weight: 700; font-size: 0.95rem;">Sumber Pembayaran</label>
                        <div class="row g-2" id="edit-sumber-list">
                            <div class="col-12">
                                <div class="sumber-card d-flex align-items-center gap-3" data-kode="uang_ruko" onclick="pilihSumber('edit', 'uang_ruko', this)">
                                    <div class="rounded-circle d-flex align-items-center justify-content-center" style="width: 42px; height: 42px; background: linear-gradient(135deg,#991B1B,#7F1D1D); color:#FBBF24; flex-shrink: 0;">
                                        <i class="bi bi-house-door fs-5"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div style="font-weight: 800; color: #1F2937;">Uang Ruko</div>
                                        <div class="small" style="color: #6B7280;"><i class="bi bi-cash-stack me-1"></i>Kas Tunai Fisik</div>
                                    </div>
                                    <div class="dot-check"></div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="sumber-card d-flex align-items-center gap-3" data-kode="bank_jago" onclick="pilihSumber('edit', 'bank_jago', this)">
                                    <div class="rounded-circle d-flex align-items-center justify-content-center" style="width: 42px; height: 42px; background: linear-gradient(135deg,#1E40AF,#1D4ED8); color:#fff; flex-shrink: 0;">
                                        <i class="bi bi-credit-card fs-5"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div style="font-weight: 800; color: #1F2937; font-size: 0.92rem;">Bank Jago</div>
                                        <div class="small" style="color: #6B7280;"><i class="bi bi-wallet me-1"></i>Kas Operasional</div>
                                    </div>
                                    <div class="dot-check"></div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="sumber-card d-flex align-items-center gap-3" data-kode="bank_bri" onclick="pilihSumber('edit', 'bank_bri', this)">
                                    <div class="rounded-circle d-flex align-items-center justify-content-center" style="width: 42px; height: 42px; background: linear-gradient(135deg,#1E3A8A,#1E40AF); color:#fff; flex-shrink: 0;">
                                        <i class="bi bi-building fs-5"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div style="font-weight: 800; color: #1F2937; font-size: 0.92rem;">Bank BRI</div>
                                        <div class="small" style="color: #6B7280;"><i class="bi bi-graph-up me-1"></i>Hasil Penjualan</div>
                                    </div>
                                    <div class="dot-check"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div style="background: linear-gradient(135deg, rgba(251,191,36,0.12), rgba(153,27,27,0.06)); border: 1px dashed #FBBF24; border-radius: 14px; padding: 14px 18px; margin-top: 18px;">
                        <div class="d-flex align-items-center justify-content-between">
                            <span class="small" style="color: #450A0A; font-weight: 600;"><i class="bi bi-info-circle me-1" style="color:#FBBF24;"></i>Total Subtotal</span>
                            <span class="fw-bold" id="edit-subtotal-display" style="color: #991B1B; font-size: 1.2rem;">Rp 0</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0" style="padding: 16px 26px 24px 26px; gap: 10px;">
                    <button type="button" class="btn" data-bs-dismiss="modal" style="background: #fff; color: #991B1B; border: 1.5px solid #991B1B; border-radius: 12px; font-weight: 600; padding: 10px 22px;">Batal</button>
                    <button type="submit" class="btn" style="background: linear-gradient(135deg, #7F1D1D 0%, #991B1B 100%); color: #fff; border: 1.5px solid #FBBF24; border-radius: 12px; font-weight: 700; padding: 10px 24px; box-shadow: 0 6px 18px rgba(153,27,27,0.25);"><i class="bi bi-check-lg me-1"></i>Update</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function formatIDR(n) {
    return new Intl.NumberFormat('id-ID').format(n || 0);
}

function hitungSubtotal(prefix) {
    const harga = parseFloat(document.getElementById(prefix + '-harga').value || 0);
    const subtotal = harga;
    const elDis = document.getElementById(prefix + '-subtotal-display');
    if (elDis) elDis.textContent = 'Rp ' + formatIDR(subtotal);
    const elInp = document.getElementById(prefix + '-subtotal');
    if (elInp) elInp.value = subtotal;
}

function pilihSumber(prefix, kode, elCard) {
    document.getElementById(prefix + '-sumber_bayar').value = kode;
    const parent = elCard.closest('.row');
    parent.querySelectorAll('.sumber-card').forEach(c => c.classList.remove('active'));
    elCard.classList.add('active');
}

function bukaEdit(id, nama, harga, keterangan, kodeRekening) {
    document.getElementById('edit-id').value = id;
    document.getElementById('edit-nama').value = nama;
    document.getElementById('edit-harga').value = harga;
    document.getElementById('edit-keterangan').value = keterangan || '';
    document.getElementById('edit-sumber_bayar').value = kodeRekening || 'uang_ruko';

    const list = document.querySelectorAll('#edit-sumber-list .sumber-card');
    list.forEach(c => {
        c.classList.toggle('active', c.dataset.kode === (kodeRekening || 'uang_ruko'));
    });

    hitungSubtotal('edit');
    const modal = new bootstrap.Modal(document.getElementById('editModal'));
    modal.show();
}

function hapusData(id) {
    Swal.fire({
        title: 'Hapus data ini?',
        text: 'Saldo rekening sumber akan otomatis dikembalikan.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#991B1B',
        confirmButtonText: 'Ya, Hapus',
        cancelButtonText: 'Batal',
        confirmButtonBorder: '1px solid #FBBF24'
    }).then((result) => {
        if (result.isConfirmed) {
            window.location = 'beli-harian.php?hapus=' + id;
        }
    });
}

document.addEventListener('DOMContentLoaded', function () {
    hitungSubtotal('tambah');
});
</script>

<?php include '../includes/footer.php'; ?>

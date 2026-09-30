<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (($_GET['action'] ?? '') === 'export_excel') {
    require_once '../config/database.php';
    require_once '../includes/finance-utils.php';

    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $tanggal_awal = isset($_GET['tanggal_awal']) ? trim($_GET['tanggal_awal']) : '';
    $tanggal_akhir = isset($_GET['tanggal_akhir']) ? trim($_GET['tanggal_akhir']) : '';
    if ($tanggal_awal === '' && $tanggal_akhir === '') {
        $tanggal_awal = date('Y-m-01');
        $tanggal_akhir = date('Y-m-t');
    }

    $where_clauses = [];
    $params = [];

    if ($search !== '') {
        $where_clauses[] = "(catatan LIKE ? OR CAST(total_pendapatan AS CHAR) LIKE ?)";
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
    }
    if ($tanggal_awal !== '') {
        $where_clauses[] = "tanggal >= ?";
        $params[] = $tanggal_awal;
    }
    if ($tanggal_akhir !== '') {
        $where_clauses[] = "tanggal <= ?";
        $params[] = $tanggal_akhir;
    }

    $where_sql = '';
    if (!empty($where_clauses)) {
        $where_sql = ' WHERE ' . implode(' AND ', $where_clauses);
    }

    $sql_data = "SELECT * FROM penjualan" . $where_sql . " ORDER BY tanggal DESC, id DESC";
    $stmt = $pdo->prepare($sql_data);
    $stmt->execute($params);
    $penjualan_export = $stmt->fetchAll();

    $nama_file = 'Penjualan_' . date('Ymd_His');
    if ($tanggal_awal || $tanggal_akhir) {
        $nama_file .= '_' . ($tanggal_awal ? date('Ymd', strtotime($tanggal_awal)) : 'semua') . '_sampai_' . ($tanggal_akhir ? date('Ymd', strtotime($tanggal_akhir)) : 'semua');
    }
    $nama_file .= '.xls';

    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $nama_file . '"');
    header('Cache-Control: max-age=0');

    $total_nominal = 0;
    $html = '';
    $html .= '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel">';
    $html .= '<head><meta charset="UTF-8"></head><body>';
    $html .= '<table border="1" width="100%">';
    $html .= '<tr>';
    $html .= '<td colspan="5" style="font-weight:bold;font-size:18px;background:#10b981;color:white;padding:8px;">LAPORAN PENJUALAN</td>';
    $html .= '</tr>';
    $html .= '<tr>';
    $html .= '<td colspan="5" style="padding:6px;background:#f3f4f6;">';
    if ($search !== '') $html .= 'Pencarian: ' . $search . ' &nbsp;&nbsp; ';
    if ($tanggal_awal || $tanggal_akhir) $html .= 'Tanggal: ' . ($tanggal_awal ? date('d/m/Y', strtotime($tanggal_awal)) : 'Semua') . ' s/d ' . ($tanggal_akhir ? date('d/m/Y', strtotime($tanggal_akhir)) : 'Semua');
    if ($search === '' && !$tanggal_awal && !$tanggal_akhir) $html .= 'Semua Data';
    $html .= '</td>';
    $html .= '</tr>';
    $html .= '<tr style="background:#D1FAE5;font-weight:bold;">';
    $html .= '<th style="padding:6px;">NO</th>';
    $html .= '<th style="padding:6px;">TANGGAL</th>';
    $html .= '<th style="padding:6px;">CATATAN</th>';
    $html .= '<th style="padding:6px;text-align:right;">TOTAL PENDAPATAN</th>';
    $html .= '<th style="padding:6px;">ID TRANSAKSI</th>';
    $html .= '</tr>';

    $no = 1;
    foreach ($penjualan_export as $p) {
        $html .= '<tr>';
        $html .= '<td style="padding:6px;">' . $no++ . '</td>';
        $html .= '<td style="padding:6px;">' . date('d/m/Y', strtotime($p['tanggal'])) . '</td>';
        $html .= '<td style="padding:6px;">' . htmlspecialchars($p['catatan'] ?? '-') . '</td>';
        $html .= '<td style="padding:6px;text-align:right;">Rp ' . number_format($p['total_pendapatan'], 0, ',', '.') . '</td>';
        $html .= '<td style="padding:6px;">PNJ-' . str_pad($p['id'], 5, '0', STR_PAD_LEFT) . '</td>';
        $html .= '</tr>';
        $total_nominal += (float) $p['total_pendapatan'];
    }

    $html .= '<tr style="background:#fef3c7;font-weight:bold;">';
    $html .= '<td colspan="3" style="padding:8px;">TOTAL</td>';
    $html .= '<td style="padding:8px;text-align:right;">Rp ' . number_format($total_nominal, 0, ',', '.') . '</td>';
    $html .= '<td style="padding:8px;">' . count($penjualan_export) . ' Transaksi</td>';
    $html .= '</tr>';
    $html .= '</table></body></html>';

    echo $html;
    exit;
}

require_once '../includes/header.php';
require_once '../includes/finance-utils.php';

function normalisasiAngkaPenjualan($value)
{
    if (is_int($value) || is_float($value)) {
        return (float) $value;
    }

    $value = trim((string) $value);
    if ($value === '') {
        return 0.0;
    }

    $value = preg_replace('/[^\d,.\-]/', '', $value);

    if (strpos($value, ',') !== false && strpos($value, '.') !== false) {
        if (strrpos($value, ',') > strrpos($value, '.')) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } else {
            $value = str_replace(',', '', $value);
        }
    } elseif (strpos($value, ',') !== false) {
        $value = str_replace(',', '.', $value);
    }

    return is_numeric($value) ? (float) $value : 0.0;
}

financeEnsureAccountSystem($pdo);
financeEnsureBiayaWajibTable($pdo);

// Handle hapus
if (isset($_GET['hapus'])) {
    $id_hapus = $_GET['hapus'];
    
    $pdo->beginTransaction();
    try {
        // Ambil data penjualan untuk rollback saldo
        $stmt = $pdo->prepare("SELECT * FROM penjualan WHERE id = ?");
        $stmt->execute([$id_hapus]);
        $penjualan_hapus = $stmt->fetch();
        
        if ($penjualan_hapus) {
            $tanggal_penjualan = $penjualan_hapus['tanggal'];
            financeDeleteLinkedPenjualanTransactions($pdo, $id_hapus);
            
            // Hapus foto jika ada
            if ($penjualan_hapus['foto']) {
                $foto_path = '../uploads/bukti/' . $penjualan_hapus['foto'];
                if (file_exists($foto_path)) {
                    unlink($foto_path);
                }
            }
            
            // Hapus data penjualan
            $stmt = $pdo->prepare("DELETE FROM penjualan WHERE id = ?");
            $stmt->execute([$id_hapus]);

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM penjualan WHERE tanggal = ?");
            $stmt->execute([$tanggal_penjualan]);
            if ((int) $stmt->fetchColumn() > 0) {
                financeRebuildPenjualanAllocationsByDate($pdo, $tanggal_penjualan);
            }
        }
        
        $pdo->commit();
        
        echo "<script>
            Swal.fire({
                icon: 'success',
                title: 'Berhasil',
                text: 'Penjualan berhasil dihapus!',
                timer: 1500
            }).then(() => window.location.href = 'penjualan.php');
        </script>";
    } catch (Throwable $e) {
        $pdo->rollBack();
        echo "<script>
            Swal.fire({
                icon: 'error',
                title: 'Gagal',
                text: '" . addslashes($e->getMessage()) . "',
                timer: 3000
            });
        </script>";
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Cek apakah ini edit atau tambah
    if (isset($_POST['id'])) {
        // Edit penjualan
        $id_edit = $_POST['id'];
        $total = normalisasiAngkaPenjualan($_POST['total_pendapatan'] ?? 0);
        $catatan = $_POST['catatan'];
        $tanggal = $_POST['tanggal'];
        
        $pdo->beginTransaction();
        try {
            // Ambil data lama
            $stmt = $pdo->prepare("SELECT * FROM penjualan WHERE id = ?");
            $stmt->execute([$id_edit]);
            $penjualan_lama = $stmt->fetch();

            if (!$penjualan_lama) {
                throw new Exception('Data penjualan tidak ditemukan.');
            }

            $tanggal_lama = $penjualan_lama['tanggal'] ?? $tanggal;
            
            // Update foto jika ada
            $foto = $penjualan_lama['foto'];
            if (isset($_FILES['foto']) && $_FILES['foto']['error'] == 0) {
                // Hapus foto lama
                if ($penjualan_lama['foto']) {
                    $foto_path_lama = '../uploads/bukti/' . $penjualan_lama['foto'];
                    if (file_exists($foto_path_lama)) {
                        unlink($foto_path_lama);
                    }
                }
                
                $ext = pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION);
                $filename = uniqid() . '.' . $ext;
                $filepath = '../uploads/bukti/' . $filename;
                move_uploaded_file($_FILES['foto']['tmp_name'], $filepath);
                $foto = $filename;
            }
            
            // Update penjualan
            $stmt = $pdo->prepare("UPDATE penjualan SET total_pendapatan = ?, catatan = ?, foto = ?, tanggal = ? WHERE id = ?");
            $stmt->execute([$total, $catatan, $foto, $tanggal, $id_edit]);
            
            financeDeleteLinkedPenjualanTransactions($pdo, $id_edit);

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM penjualan WHERE tanggal = ?");
            $stmt->execute([$tanggal_lama]);
            if ((int) $stmt->fetchColumn() > 0) {
                financeRebuildPenjualanAllocationsByDate($pdo, $tanggal_lama);
            }

            if ($tanggal !== $tanggal_lama) {
                financeRebuildPenjualanAllocationsByDate($pdo, $tanggal);
            }
            
            $pdo->commit();
            
            echo "<script>
                Swal.fire({
                    icon: 'success',
                    title: 'Berhasil',
                    text: 'Penjualan berhasil diupdate!',
                    timer: 1500
                }).then(() => window.location.href = 'penjualan.php');
            </script>";
        } catch (Throwable $e) {
            $pdo->rollBack();
            echo "<script>
                Swal.fire({
                    icon: 'error',
                    title: 'Gagal',
                    text: '" . addslashes($e->getMessage()) . "',
                    timer: 3000
                });
            </script>";
        }
    } else {
        // Tambah penjualan
        $total = normalisasiAngkaPenjualan($_POST['total_pendapatan'] ?? 0);
        $catatan = $_POST['catatan'];
        $tanggal = $_POST['tanggal'];
        $foto = null;
        $id_periode = null;

        if (isset($_FILES['foto']) && $_FILES['foto']['error'] == 0) {
            $ext = pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION);
            $filename = uniqid() . '.' . $ext;
            $filepath = '../uploads/bukti/' . $filename;
            move_uploaded_file($_FILES['foto']['tmp_name'], $filepath);
            $foto = $filename;
        }

        $pdo->beginTransaction();
        try {
            // Insert penjualan
            $stmt = $pdo->prepare("INSERT INTO penjualan (id_periode, total_pendapatan, catatan, foto, tanggal) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$id_periode, $total, $catatan, $foto, $tanggal]);
            $id_penjualan = $pdo->lastInsertId();

            financeRebuildPenjualanAllocationsByDate($pdo, $tanggal);

            $pdo->commit();

            echo "<script>
                Swal.fire({
                    icon: 'success',
                    title: 'Berhasil',
                    text: 'Penjualan berhasil dicatat! Saldo rekening otomatis terupdate.',
                    timer: 1500
                }).then(() => window.location.href = '$base_url/admin_dashboard.php');
            </script>";
        } catch (Throwable $e) {
            $pdo->rollBack();
            echo "<script>
                Swal.fire({
                    icon: 'error',
                    title: 'Gagal',
                    text: '" . addslashes($e->getMessage()) . "',
                    timer: 3000
                });
            </script>";
        }
    }
}

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$tanggal_awal = isset($_GET['tanggal_awal']) ? trim($_GET['tanggal_awal']) : '';
$tanggal_akhir = isset($_GET['tanggal_akhir']) ? trim($_GET['tanggal_akhir']) : '';
$filter_bulan_otomatis = false;
if ($tanggal_awal === '' && $tanggal_akhir === '') {
    $tanggal_awal = date('Y-m-01');
    $tanggal_akhir = date('Y-m-t');
    $filter_bulan_otomatis = true;
}
$nama_bulan = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$label_bulan_penjualan = $nama_bulan[(int) date('n', strtotime($tanggal_awal))] . ' ' . date('Y', strtotime($tanggal_awal));

$where_clauses = [];
$params = [];

if ($search !== '') {
    $where_clauses[] = "(catatan LIKE ? OR CAST(total_pendapatan AS CHAR) LIKE ?)";
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

if ($tanggal_awal !== '') {
    $where_clauses[] = "tanggal >= ?";
    $params[] = $tanggal_awal;
}

if ($tanggal_akhir !== '') {
    $where_clauses[] = "tanggal <= ?";
    $params[] = $tanggal_akhir;
}

$where_sql = '';
if (!empty($where_clauses)) {
    $where_sql = ' WHERE ' . implode(' AND ', $where_clauses);
}

$sql_count = "SELECT COUNT(*) AS total_data, COALESCE(SUM(total_pendapatan), 0) AS total_nominal FROM penjualan" . $where_sql;
$stmt = $pdo->prepare($sql_count);
$stmt->execute($params);
$ringkasan_penjualan = $stmt->fetch();

$sql_data = "SELECT * FROM penjualan" . $where_sql . " ORDER BY tanggal DESC, id DESC LIMIT 200";
$stmt = $pdo->prepare($sql_data);
$stmt->execute($params);
$penjualan = $stmt->fetchAll();

$ringkasan_biaya_harian = financeGetBiayaWajibSummary($pdo, date('Y-m-d'));
?>

<style>
.jual-hero {
    background: linear-gradient(135deg, #450A0A 0%, #7F1D1D 55%, #991B1B 100%);
    color: #fff;
    border-radius: 22px;
    padding: 22px;
    box-shadow: 0 16px 36px rgba(127, 29, 29, 0.22);
    position: relative;
    overflow: hidden;
}
.jual-hero::after {
    content: "";
    position: absolute;
    right: -36px;
    top: -46px;
    width: 170px;
    height: 170px;
    border-radius: 50%;
    background: rgba(251, 191, 36, 0.16);
}
.jual-stat {
    border-radius: 18px;
    padding: 16px;
    height: 100%;
    box-shadow: 0 8px 22px rgba(15, 23, 42, 0.05);
}
.jual-stat .angka {
    font-family: 'Poppins', sans-serif;
    font-weight: 800;
    font-size: clamp(1.15rem, 3.2vw, 1.55rem);
    letter-spacing: -0.4px;
    line-height: 1.15;
    overflow-wrap: anywhere;
}
.jual-item {
    background: #fff;
    border-radius: 18px;
    border: 1px solid rgba(15, 23, 42, 0.06);
    box-shadow: 0 8px 20px rgba(15, 23, 42, 0.04);
    padding: 14px;
}
.jual-aksi .btn {
    width: 38px;
    height: 38px;
    border-radius: 12px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0;
}
@media (max-width: 576px) {
    .jual-hero { padding: 16px; border-radius: 18px; }
}
</style>

<div class="jual-hero mb-4">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 position-relative" style="z-index:1;">
        <div>
            <a href="<?php echo $base_url; ?>/admin_dashboard.php" class="text-white text-decoration-none d-inline-flex align-items-center gap-1 mb-2 opacity-75">
                <i class="bi bi-arrow-left"></i> Dashboard
            </a>
            <h4 class="mb-1 fw-bold">Penjualan</h4>
            <div class="small" style="color:#FDE68A;">Catatan <?php echo htmlspecialchars($label_bulan_penjualan); ?>. Bulan baru, daftar ini mulai kosong. Data lama ada di laporan.</div>
        </div>
        <div class="d-flex flex-column flex-sm-row gap-2">
            <a href="<?php echo $base_url; ?>/pages/laporan.php" class="btn rounded-pill fw-semibold" style="background:rgba(255,255,255,0.12); color:#fff; border:1px solid rgba(251,191,36,0.45);">
                <i class="bi bi-journal-text me-1"></i>Laporan
            </a>
            <button class="btn rounded-pill fw-semibold px-4" style="background:#FBBF24; color:#450A0A;" data-bs-toggle="modal" data-bs-target="#tambahModal">
                <i class="bi bi-plus-lg me-1"></i>Tambah Penjualan
            </button>
        </div>
    </div>
</div>

<?php
$rata_penjualan = ($ringkasan_penjualan['total_data'] ?? 0) > 0
    ? ($ringkasan_penjualan['total_nominal'] / $ringkasan_penjualan['total_data'])
    : 0;
?>
<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3">
        <div class="jual-stat" style="background:linear-gradient(135deg,#D1FAE5,#A7F3D0); color:#065F46;">
            <div class="small fw-semibold mb-1"><i class="bi bi-wallet2 me-1"></i>Total bulan ini</div>
            <div class="angka">Rp <?php echo number_format($ringkasan_penjualan['total_nominal'] ?? 0, 0, ',', '.'); ?></div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="jual-stat" style="background:linear-gradient(135deg,#FEF3C7,#FDE68A); color:#78350F;">
            <div class="small fw-semibold mb-1"><i class="bi bi-receipt me-1"></i>Jumlah catatan</div>
            <div class="angka"><?php echo number_format($ringkasan_penjualan['total_data'] ?? 0, 0, ',', '.'); ?></div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="jual-stat" style="background:#fff; color:#450A0A; border:1px solid rgba(153,27,27,0.08);">
            <div class="small fw-semibold mb-1"><i class="bi bi-bar-chart-fill me-1"></i>Rata-rata</div>
            <div class="angka">Rp <?php echo number_format($rata_penjualan, 0, ',', '.'); ?></div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="jual-stat" style="background:linear-gradient(135deg,#450A0A,#991B1B); color:#fff;">
            <div class="d-flex justify-content-between align-items-start gap-2">
                <div>
                    <div class="small fw-semibold mb-1" style="color:#FDE68A;"><i class="bi bi-safe-fill me-1"></i>Target kas hari ini</div>
                    <div class="angka">Rp <?php echo number_format($ringkasan_biaya_harian['target_harian'] ?? 0, 0, ',', '.'); ?></div>
                    <div class="small mt-1 opacity-75">Sisa Rp <?php echo number_format($ringkasan_biaya_harian['sisa_target_hari_ini'] ?? 0, 0, ',', '.'); ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm rounded-4 mb-4">
    <div class="card-body">
        <form method="GET" id="filterForm">
            <div class="row g-2 align-items-end">
                <div class="col-12 col-lg-4">
                    <label class="form-label small fw-semibold text-muted mb-1">Cari catatan</label>
                    <input type="text" class="form-control" name="search" placeholder="Catatan atau nominal" value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-6 col-lg-3">
                    <label class="form-label small fw-semibold text-muted mb-1">Dari</label>
                    <input type="date" class="form-control" name="tanggal_awal" value="<?php echo htmlspecialchars($tanggal_awal); ?>">
                </div>
                <div class="col-6 col-lg-3">
                    <label class="form-label small fw-semibold text-muted mb-1">Sampai</label>
                    <input type="date" class="form-control" name="tanggal_akhir" value="<?php echo htmlspecialchars($tanggal_akhir); ?>">
                </div>
                <div class="col-12 col-lg-2 d-flex gap-2">
                    <button type="submit" class="btn text-white w-100" style="background:#991B1B;">
                        <i class="bi bi-funnel me-1"></i>Lihat
                    </button>
                    <?php if ($search !== '' || !$filter_bulan_otomatis): ?>
                    <a href="penjualan.php" class="btn btn-outline-secondary" aria-label="Reset filter"><i class="bi bi-x-lg"></i></a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2 mb-3">
    <div>
        <h5 class="mb-0 fw-bold">Riwayat <?php echo htmlspecialchars($label_bulan_penjualan); ?></h5>
        <small class="text-muted"><?php echo number_format($ringkasan_penjualan['total_data'] ?? 0); ?> catatan</small>
    </div>
        <?php if (!empty($penjualan)): ?>
        <form method="GET" class="d-inline-flex gap-2">
            <input type="hidden" name="action" value="export_excel">
            <?php if ($search !== ''): ?>
            <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
            <?php endif; ?>
            <?php if ($tanggal_awal !== ''): ?>
            <input type="hidden" name="tanggal_awal" value="<?php echo htmlspecialchars($tanggal_awal); ?>">
            <?php endif; ?>
            <?php if ($tanggal_akhir !== ''): ?>
            <input type="hidden" name="tanggal_akhir" value="<?php echo htmlspecialchars($tanggal_akhir); ?>">
            <?php endif; ?>
            <button type="submit" class="btn btn-outline-success btn-sm fw-medium px-3 py-2">
                <i class="bi bi-file-earmark-spreadsheet me-1"></i>Download Excel
            </button>
        </form>
        <?php endif; ?>
    </div>

    <?php if (empty($penjualan)): ?>
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body text-center py-5">
            <div class="rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width:72px;height:72px;background:#FEF3C7;color:#92400E;">
                <i class="bi bi-inbox" style="font-size:1.7rem;"></i>
            </div>
            <h5 class="fw-bold mb-1">Belum ada penjualan bulan ini</h5>
            <p class="text-muted small mb-3">Data bulan lalu tetap ada di laporan.</p>
            <button class="btn rounded-pill text-white px-4" style="background:#991B1B;" data-bs-toggle="modal" data-bs-target="#tambahModal">
                <i class="bi bi-plus-lg me-1"></i>Tambah Penjualan
            </button>
        </div>
    </div>
    <?php else: ?>
    <div class="d-grid gap-3 mb-4">

<?php foreach ($penjualan as $p): ?>
<?php
$stmt = $pdo->prepare("
    SELECT t.*, r.nama_rekening
    FROM saldo_rekening_transaksi t
    JOIN saldo_rekening r ON r.id = t.id_rekening
    WHERE t.id_penjualan = ?
    ORDER BY t.id ASC
");
$stmt->execute([$p['id']]);
$alokasi_penjualan = $stmt->fetchAll();
?>

        <div class="jual-item">
            <div class="d-flex justify-content-between align-items-start gap-3">
                <button type="button" class="btn p-0 text-start border-0 bg-transparent flex-grow-1 min-w-0" data-bs-toggle="modal" data-bs-target="#detailPenjualanModal<?php echo $p['id']; ?>">
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                        <span class="badge rounded-pill" style="background:#D1FAE5; color:#065F46;">
                            <i class="bi bi-calendar-event me-1"></i><?php echo date('d M Y', strtotime($p['tanggal'])); ?>
                        </span>
                        <span class="small text-muted">#PNJ-<?php echo str_pad($p['id'], 5, '0', STR_PAD_LEFT); ?></span>
                    </div>
                    <div class="fw-bold" style="color:#065F46; font-size:1.15rem;">Rp <?php echo number_format($p['total_pendapatan'], 0, ',', '.'); ?></div>
                    <div class="small text-muted"><?php echo htmlspecialchars($p['catatan'] ?: 'Tanpa catatan'); ?></div>
                    <?php if (!empty($alokasi_penjualan)): ?>
                    <div class="d-flex flex-wrap gap-1 mt-2">
                        <?php foreach ($alokasi_penjualan as $alokasi): ?>
                        <span class="badge rounded-pill" style="background:#FEF3C7; color:#78350F;">
                            <?php echo htmlspecialchars($alokasi['nama_rekening']); ?> · Rp <?php echo number_format($alokasi['jumlah'], 0, ',', '.'); ?>
                        </span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </button>
                <div class="jual-aksi d-flex gap-2 flex-shrink-0">
                    <button class="btn" style="background:#FEF3C7; color:#92400E;" data-bs-toggle="modal" data-bs-target="#editModal<?php echo $p['id']; ?>" title="Edit">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <button class="btn" style="background:#FEE2E2; color:#991B1B;" onclick="hapusPenjualan(<?php echo $p['id']; ?>);" title="Hapus">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </div>
        </div>

    <?php
        $waktu_jual = strtotime($p['tanggal']);
        $hari_id = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'][(int) date('w', $waktu_jual)];
        $tgl_detail = $hari_id . ', ' . date('j', $waktu_jual) . ' ' . $nama_bulan[(int) date('n', $waktu_jual)] . ' ' . date('Y', $waktu_jual);
        $kode_jual = '#PNJ-' . str_pad($p['id'], 5, '0', STR_PAD_LEFT);
    ?>
    <div class="modal fade jual-detail" id="detailPenjualanModal<?php echo $p['id']; ?>" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title mb-0">Detail Penjualan</h5>
                        <div class="small" style="color:#FDE68A;"><?php echo htmlspecialchars($tgl_detail); ?> · <?php echo $kode_jual; ?></div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="modal-body">
                    <div class="jual-detail-total">
                        <div class="small" style="color:#047857;">Total pendapatan</div>
                        <div class="jual-detail-angka">Rp <?php echo number_format($p['total_pendapatan'], 0, ',', '.'); ?></div>
                    </div>
                    <?php if ($p['catatan']): ?>
                    <div class="jual-detail-catatan">
                        <div class="small text-muted mb-1">Catatan</div>
                        <div><?php echo nl2br(htmlspecialchars($p['catatan'])); ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($alokasi_penjualan)): ?>
                    <div class="small text-muted mt-3 mb-2">Pembagian otomatis</div>
                    <div class="d-flex flex-column gap-2">
                        <?php foreach ($alokasi_penjualan as $alokasi):
                            $nama_alokasi = strtolower($alokasi['nama_rekening']);
                            if (str_contains($nama_alokasi, 'ruko')) {
                                $warna_bagi = '#991B1B';
                                $latar_bagi = '#FEF2F2';
                            } elseif (str_contains($nama_alokasi, 'operasional') || str_contains($nama_alokasi, 'kas')) {
                                $warna_bagi = '#92400E';
                                $latar_bagi = '#FFFBEB';
                            } else {
                                $warna_bagi = '#065F46';
                                $latar_bagi = '#ECFDF5';
                            }
                        ?>
                        <div class="jual-bagi" style="background:<?php echo $latar_bagi; ?>; color:<?php echo $warna_bagi; ?>;">
                            <span><?php echo htmlspecialchars($alokasi['nama_rekening']); ?></span>
                            <strong>Rp <?php echo number_format($alokasi['jumlah'], 0, ',', '.'); ?></strong>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <?php if ($p['foto']): ?>
                    <div class="mt-3">
                        <div class="small text-muted mb-2">Bukti foto</div>
                        <img src="<?php echo $base_url; ?>/uploads/bukti/<?php echo $p['foto']; ?>" class="img-fluid rounded-3 d-block" alt="Bukti penjualan" style="max-height:280px; object-fit:contain; background:#F8FAFC;">
                    </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer jual-detail-aksi">
                    <button type="button" class="btn jual-btn-edit" data-bs-toggle="modal" data-bs-target="#editModal<?php echo $p['id']; ?>" data-bs-dismiss="modal">
                        <i class="bi bi-pencil"></i> Edit
                    </button>
                    <button type="button" class="btn jual-btn-hapus" onclick="document.getElementById('detailPenjualanModal<?php echo $p['id']; ?>') && bootstrap.Modal.getOrCreateInstance(document.getElementById('detailPenjualanModal<?php echo $p['id']; ?>')).hide(); setTimeout(()=>hapusPenjualan(<?php echo $p['id']; ?>),200);">
                        <i class="bi bi-trash"></i> Hapus
                    </button>
                    <button type="button" class="btn jual-btn-tutup" data-bs-dismiss="modal">Tutup</button>
                </div>
            </div>
        </div>
    </div>
    <div class="modal fade" id="editModal<?php echo $p['id']; ?>">
    <div class="modal-dialog modal-lg">
        <div class="modal-content rounded-4 border-0">

          <form method="POST" enctype="multipart/form-data">

                <input type="hidden" name="id" value="<?php echo $p['id']; ?>">

                <div class="modal-header border-0">
                    <h5 class="modal-title fw-bold">
                        <i class="bi bi-pencil-square me-2 text-warning"></i>Edit Penjualan
                    </h5>

                 <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body p-4">

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Tanggal</label>
                        <input type="date" name="tanggal" class="form-control form-control-lg" value="<?php echo $p['tanggal']; ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Total Pendapatan</label>
                        <div class="input-group input-group-lg">
                            <span class="input-group-text bg-light fw-semibold">Rp</span>
                            <input type="number" name="total_pendapatan" class="form-control form-control-lg" value="<?php echo $p['total_pendapatan']; ?>">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Catatan</label>
                        <textarea class="form-control form-control-lg" name="catatan" rows="3" placeholder="Tambahkan catatan..."><?php echo htmlspecialchars($p['catatan']); ?></textarea>
                    </div>

                    <?php if ($p['foto']): ?>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Foto Saat Ini</label>
                        <div class="rounded-4 overflow-hidden border border-light bg-light p-2 d-inline-block">
                            <img src="<?php echo $base_url; ?>/uploads/bukti/<?php echo $p['foto']; ?>" alt="Foto saat ini" style="max-width:200px;max-height:150px;object-fit:contain;">
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?php echo $p['foto'] ? 'Ganti Foto' : 'Tambah Foto'; ?></label>
                        <input type="file" class="form-control form-control-lg" name="foto" accept="image/*">
                    </div>

                </div>

                <div class="modal-footer border-0 flex-wrap gap-2">

                  <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
               <button type="submit" class="btn btn-primary">
                <i class="bi bi-save me-2"></i>Simpan Perubahan
               </button>

                </div>

            </form>

        </div>
    </div>
</div>
   
<?php endforeach; ?>

    </div>
    <?php endif; ?>

<div class="modal fade" id="tambahModal">
    <div class="modal-dialog modal-lg">
        <div class="modal-content rounded-4 border-0">

            <form method="POST" enctype="multipart/form-data">

                <div class="modal-header border-0">
                    <h5 class="modal-title fw-bold">
                        <i class="bi bi-plus-circle me-2 text-success"></i>Tambah Penjualan
                    </h5>

                    <button class="btn-close" data-bs-dismiss="modal"></button>

                </div>

                <div class="modal-body p-4">

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Tanggal</label>
                        <input type="date" name="tanggal" class="form-control form-control-lg" value="<?= date('Y-m-d') ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Total Pendapatan</label>
                        <div class="input-group input-group-lg">
                            <span class="input-group-text bg-light fw-semibold">Rp</span>
                            <input type="number" name="total_pendapatan" class="form-control form-control-lg" placeholder="Masukkan nominal penjualan...">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Catatan</label>
                        <textarea name="catatan" class="form-control form-control-lg" rows="3" placeholder="Contoh: Penjualan siang di acara Bazar..."></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Foto Bukti</label>
                        <input type="file" name="foto" class="form-control form-control-lg" accept="image/*">
                        <small class="text-muted mt-1 d-block">Opsional: upload foto struk / catatan penjualan</small>
                    </div>

                </div>

                <div class="modal-footer border-0 flex-wrap gap-2">

                    <button class="btn btn-outline-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-lg me-2"></i>Batal
                    </button>

                    <button class="btn btn-primary" type="submit">
                        <i class="bi bi-save me-2"></i>Simpan
                    </button>

                </div>

            </form>

        </div>
    </div>
</div>

<style>
.transition-card {
    transition: all 0.25s ease;
}
.transition-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 30px rgba(0,0,0,0.08) !important;
    border-color: rgba(16, 185, 129, 0.2) !important;
}
.cursor-pointer {
    cursor: pointer;
}
.btn-emerald {
    background: #10b981;
    border-color: #10b981;
    color: white;
}
.btn-emerald:hover {
    background: #059669;
    border-color: #059669;
    color: white;
}
.text-emerald {
    color: #065f46 !important;
}
.min-w-0 {
    min-width: 0;
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
.jual-detail .modal-dialog { max-width: 460px; }
.jual-detail .modal-content { border-radius: 22px; overflow: hidden; }
.jual-detail .modal-body { padding: 16px 16px 8px; }
.jual-detail-total {
    background: #ECFDF5;
    border-radius: 16px;
    padding: 14px 16px;
}
.jual-detail-angka {
    font-family: 'Poppins', sans-serif;
    font-weight: 800;
    font-size: clamp(1.45rem, 4vw, 1.85rem);
    color: #065F46;
    letter-spacing: -0.5px;
    line-height: 1.15;
    overflow-wrap: anywhere;
}
.jual-detail-catatan {
    margin-top: 12px;
    padding: 12px 14px;
    border-radius: 14px;
    background: #FFFBEB;
    color: #78350F;
}
.jual-bagi {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    border-radius: 14px;
    padding: 12px 14px;
}
.jual-detail-aksi {
    display: flex;
    gap: 8px;
    border-top: 0;
    padding-top: 8px;
}
.jual-detail-aksi .btn {
    border: 0;
    border-radius: 12px;
    font-weight: 600;
    flex: 1;
}
.jual-btn-edit { background: #FEF3C7; color: #92400E; }
.jual-btn-edit:hover { background: #FDE68A; color: #78350F; }
.jual-btn-hapus { background: #FEE2E2; color: #991B1B; }
.jual-btn-hapus:hover { background: #FECACA; color: #7F1D1D; }
.jual-btn-tutup { background: #F3F4F6; color: #374151; }
.jual-btn-tutup:hover { background: #E5E7EB; color: #111827; }
</style>

<script>

function hapusPenjualan(id){

Swal.fire({

title:'Hapus Penjualan?',

text:'Data tidak dapat dikembalikan.',

icon:'warning',

showCancelButton:true,

confirmButtonColor:'#dc3545',

confirmButtonText:'Ya, Hapus',

cancelButtonText:'Batal'

}).then((result)=>{

if(result.isConfirmed){

window.location='penjualan.php?hapus='+id +
    '<?php
        $qs = [];
        if ($search !== '') $qs[] = 'search=' . urlencode($search);
        if ($tanggal_awal !== '') $qs[] = 'tanggal_awal=' . urlencode($tanggal_awal);
        if ($tanggal_akhir !== '') $qs[] = 'tanggal_akhir=' . urlencode($tanggal_akhir);
        echo !empty($qs) ? '&' . implode('&', $qs) : '';
    ?>';

}

});

}

</script>
<?php include '../includes/footer.php'; ?>

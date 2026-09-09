<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (($_GET['action'] ?? '') === 'export_excel') {
    require_once '../config/database.php';
    require_once '../includes/finance-utils.php';

    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $tanggal_awal = isset($_GET['tanggal_awal']) ? trim($_GET['tanggal_awal']) : '';
    $tanggal_akhir = isset($_GET['tanggal_akhir']) ? trim($_GET['tanggal_akhir']) : '';

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

<div class="page-header">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3 w-100">
        <div>
            <a href="<?php echo $base_url; ?>/admin_dashboard.php" class="text-white text-decoration-none mb-2 d-inline-block opacity-75"><i class="bi bi-arrow-left"></i> Kembali</a>
            <h4 class="mb-0"><i class="bi bi-cash-stack me-2"></i>Penjualan</h4>
        </div>
        <button class="btn btn-light text-emerald"
            data-bs-toggle="modal"
            data-bs-target="#tambahModal">
            <i class="bi bi-plus-lg me-2"></i>Tambah Penjualan
        </button>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-md-4 col-xl">
        <div class="stat-card" style="background: linear-gradient(135deg,#D1FAE5 0%,#A7F3D0 100%); color: #065F46;">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-label">Total Pemasukan</div>
                    <h3 class="stat-value">Rp <?php echo number_format($ringkasan_penjualan['total_nominal'] ?? 0, 0, ',', '.'); ?></h3>
                </div>
                <i class="bi bi-wallet2 stat-icon" style="color:#065F46"></i>
            </div>
        </div>
    </div>
    <div class="col-md-4 col-xl">
        <div class="stat-card" style="background: linear-gradient(135deg,#DBEAFE 0%,#BFDBFE 100%); color: #1E40AF;">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-label">Jumlah Transaksi</div>
                    <h3 class="stat-value"><?php echo number_format($ringkasan_penjualan['total_data'] ?? 0, 0, ',', '.'); ?></h3>
                    <small class="opacity-75">transaksi penjualan</small>
                </div>
                <i class="bi bi-receipt-cutoff stat-icon" style="color:#1E40AF"></i>
            </div>
        </div>
    </div>
    <div class="col-md-4 col-xl">
        <div class="stat-card" style="background: linear-gradient(135deg,#FEF3C7 0%,#FDE68A 100%); color: #92400E;">
            <div class="d-flex justify-content-between align-items-start gap-3">
                <div>
                    <div class="stat-label">Target Kas Wajib Hari Ini</div>
                    <h3 class="stat-value">Rp <?php echo number_format($ringkasan_biaya_harian['target_harian'] ?? 0, 0, ',', '.'); ?></h3>
                    <small class="opacity-75 d-block">Teralokasi: Rp <?php echo number_format($ringkasan_biaya_harian['teralokasi_hari_ini'] ?? 0, 0, ',', '.'); ?></small>
                    <small class="opacity-75 d-block">Sisa: Rp <?php echo number_format($ringkasan_biaya_harian['sisa_target_hari_ini'] ?? 0, 0, ',', '.'); ?></small>
                </div>
                <a href="<?php echo $base_url; ?>/pages/biaya-wajib.php" class="btn btn-sm btn-light" style="color:#92400E;">
                    Kelola
                </a>
            </div>
        </div>
    </div>
    <?php if ($ringkasan_penjualan['total_data'] > 0): ?>
    <div class="col-md-4 col-xl">
        <div class="stat-card gold">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-label">Rata-rata / Transaksi</div>
                    <h3 class="stat-value">Rp <?php
                        $avg = $ringkasan_penjualan['total_data'] > 0 ? ($ringkasan_penjualan['total_nominal'] / $ringkasan_penjualan['total_data']) : 0;
                        echo number_format($avg, 0, ',', '.');
                    ?></h3>
                </div>
                <i class="bi bi-bar-chart-fill stat-icon"></i>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Filter & Search Card -->
<div class="card border-0 shadow-sm rounded-4 mb-4">
    <div class="card-body p-4">
        <form method="GET" id="filterForm">
            <div class="row g-3 align-items-end">
                <div class="col-md-5 col-lg-4">
                    <label class="form-label small fw-semibold text-muted mb-1">
                        <i class="bi bi-search me-1"></i>Cari Penjualan
                    </label>
                    <input type="text" class="form-control form-control-lg" name="search" placeholder="Cari catatan, nominal..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-6 col-md-3 col-lg-2">
                    <label class="form-label small fw-semibold text-muted mb-1">
                        <i class="bi bi-calendar3 me-1"></i>Tanggal Awal
                    </label>
                    <input type="date" class="form-control form-control-lg" name="tanggal_awal" value="<?php echo htmlspecialchars($tanggal_awal); ?>">
                </div>
                <div class="col-6 col-md-3 col-lg-2">
                    <label class="form-label small fw-semibold text-muted mb-1">
                        <i class="bi bi-calendar3 me-1"></i>Tanggal Akhir
                    </label>
                    <input type="date" class="form-control form-control-lg" name="tanggal_akhir" value="<?php echo htmlspecialchars($tanggal_akhir); ?>">
                </div>
                <div class="col-md-12 col-lg-4 d-flex gap-2">
                    <button type="submit" class="btn btn-emerald btn-lg w-100">
                        <i class="bi bi-funnel me-2"></i>Terapkan
                    </button>
                    <?php if ($search !== '' || $tanggal_awal !== '' || $tanggal_akhir !== ''): ?>
                    <a href="penjualan.php" class="btn btn-outline-secondary btn-lg">
                        <i class="bi bi-x-lg"></i>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
        <?php if ($search !== '' || $tanggal_awal !== '' || $tanggal_akhir !== ''): ?>
        <div class="mt-3 pt-3 border-top">
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <span class="small text-muted">Filter aktif:</span>
                <?php if ($search !== ''): ?>
                    <span class="badge rounded-pill bg-primary px-3 py-2">
                        <i class="bi bi-search me-1"></i><?php echo htmlspecialchars($search); ?>
                    </span>
                <?php endif; ?>
                <?php if ($tanggal_awal !== '' || $tanggal_akhir !== ''): ?>
                    <span class="badge rounded-pill bg-warning text-dark px-3 py-2">
                        <i class="bi bi-calendar-range me-1"></i>
                        <?php echo $tanggal_awal ? date('d/m/Y', strtotime($tanggal_awal)) : 'Semua'; ?>
                        <span class="mx-1">—</span>
                        <?php echo $tanggal_akhir ? date('d/m/Y', strtotime($tanggal_akhir)) : 'Semua'; ?>
                    </span>
                <?php endif; ?>
                <span class="badge rounded-pill bg-success px-3 py-2 ms-auto">
                    <?php echo number_format($ringkasan_penjualan['total_data'] ?? 0); ?> data
                    <span class="mx-1">•</span>
                    Rp <?php echo number_format($ringkasan_penjualan['total_nominal'] ?? 0, 0, ',', '.'); ?>
                </span>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="card border-0 shadow-sm rounded-4 mb-4 overflow-hidden">
    <div class="card-header bg-white border-0 px-4 py-3 d-flex flex-wrap gap-2 justify-content-between align-items-center">
        <div>
            <h6 class="fw-bold mb-0 text-emerald">
                <i class="bi bi-clock-history me-2"></i>Riwayat Penjualan
            </h6>
            <small class="text-muted">
                <?php echo number_format($ringkasan_penjualan['total_data'] ?? 0); ?> data • Total Rp <?php echo number_format($ringkasan_penjualan['total_nominal'] ?? 0, 0, ',', '.'); ?>
            </small>
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
    <div class="card-body text-center py-5">
        <i class="bi bi-inbox fs-1 opacity-25 d-block mb-3 text-muted"></i>
        <h5 class="text-muted mb-1">Belum ada data penjualan</h5>
        <p class="text-muted small mb-3">Silakan klik tombol Tambah Penjualan di atas.</p>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#tambahModal">
            <i class="bi bi-plus-lg me-2"></i>Tambah Penjualan
        </button>
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" style="min-width: 880px; table-layout: fixed;">
            <colgroup>
                <col style="width: 24%;">
                <col style="width: 40%;">
                <col style="width: 18%;">
                <col style="width: 18%;">
            </colgroup>
            <thead>
                <tr style="background: linear-gradient(135deg,#D1FAE5 0%,#A7F3D0 100%);">
                    <th class="px-4 py-3 fw-semibold text-emerald border-0 text-start" style="border-top-left-radius: 12px;">Tanggal</th>
                    <th class="px-3 py-3 fw-semibold text-emerald border-0 text-start">Alokasi</th>
                    <th class="px-3 py-3 fw-semibold text-emerald border-0 text-end">Total</th>
                    <th class="px-4 py-3 fw-semibold text-emerald border-0 text-center" style="border-top-right-radius: 12px;">Aksi</th>
                </tr>
            </thead>
            <tbody class="border-top-0">

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

                <tr class="border-bottom border-light-subtle cursor-pointer transition-card" data-bs-toggle="modal" data-bs-target="#detailPenjualanModal<?php echo $p['id']; ?>" style="height: 70px;">
                    <td class="px-4 py-3 align-middle text-start">
                        <div class="d-flex flex-column gap-1">
                            <span class="badge rounded-pill bg-light text-emerald border border-success-subtle px-3 py-1.5 align-self-start" style="font-size: 0.8rem;">
                                <i class="bi bi-calendar-event me-1"></i>
                                <?php echo date('d M Y', strtotime($p['tanggal'])); ?>
                            </span>
                            <span class="small text-muted ms-1" style="font-size: 0.75rem;">#PNJ-<?php echo str_pad($p['id'], 5, '0', STR_PAD_LEFT); ?></span>
                        </div>
                    </td>
                    <td class="px-3 py-3 align-middle text-start">
                        <?php if (!empty($alokasi_penjualan)): ?>
                        <div class="d-flex flex-wrap gap-1.5">
                            <?php foreach (array_slice($alokasi_penjualan, 0, 3) as $alokasi): ?>
                            <span class="badge rounded-pill bg-primary-subtle text-primary-emphasis px-2.5 py-1" style="font-size: 0.78rem; line-height: 1.45;">
                                <i class="bi bi-bank me-1"></i>
                                <?php echo htmlspecialchars($alokasi['nama_rekening']); ?>
                            </span>
                            <?php endforeach; ?>
                            <?php if (count($alokasi_penjualan) > 3): ?>
                            <span class="badge rounded-pill bg-secondary-subtle text-secondary-emphasis px-2.5 py-1" style="font-size: 0.78rem; line-height: 1.45;">
                                +<?php echo count($alokasi_penjualan) - 3; ?> rekening
                            </span>
                            <?php endif; ?>
                        </div>
                        <?php else: ?>
                        <span class="small text-muted opacity-50">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-3 py-3 align-middle text-end">
                        <h6 class="fw-bold text-success mb-0 text-success-emphasis" style="font-size: 1.05rem; letter-spacing: -0.2px;">
                            Rp <?php echo number_format($p['total_pendapatan'], 0, ',', '.'); ?>
                        </h6>
                    </td>
                    <td class="px-4 py-3 align-middle text-center">
                        <div class="d-flex justify-content-center gap-2">
                            <button class="action-btn edit" data-bs-toggle="modal" data-bs-target="#editModal<?php echo $p['id']; ?>" onclick="event.stopPropagation();" title="Edit">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button class="action-btn delete" onclick="event.stopPropagation(); hapusPenjualan(<?php echo $p['id']; ?>);" title="Hapus">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>

    <!-- Modal Detail Penjualan -->
    <div class="modal fade" id="detailPenjualanModal<?php echo $p['id']; ?>" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content rounded-4 border-0">
                <div class="modal-header border-0 pb-0">
                    <div>
                        <h5 class="modal-title fw-bold">
                            <i class="bi bi-receipt me-2 text-success"></i>Detail Penjualan
                        </h5>
                        <small class="text-muted">
                            <i class="bi bi-calendar3 me-1"></i><?php echo date('d F Y', strtotime($p['tanggal'])); ?>
                        </small>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-4">
                        <div class="col-md-6">
                            <div class="p-4 rounded-4 bg-success bg-opacity-10 border border-success-subtle h-100">
                                <div class="small text-success-emphasis mb-2">Total Pendapatan</div>
                                <h3 class="fw-bold mb-0 text-success">Rp <?php echo number_format($p['total_pendapatan'], 0, ',', '.'); ?></h3>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="p-4 rounded-4 bg-light h-100">
                                <div class="small text-muted mb-2">Tanggal Transaksi</div>
                                <h5 class="fw-bold mb-0"><?php echo date('l, d F Y', strtotime($p['tanggal'])); ?></h5>
                                <small class="text-muted d-block mt-1">ID: #PNJ-<?php echo str_pad($p['id'], 5, '0', STR_PAD_LEFT); ?></small>
                            </div>
                        </div>
                    </div>
                    <?php if ($p['catatan']): ?>
                    <div class="mt-4">
                        <label class="small fw-semibold text-muted d-block mb-2">
                            <i class="bi bi-journal-text me-1"></i>Catatan
                        </label>
                        <div class="p-4 rounded-4 border border-light bg-white">
                            <p class="mb-0"><?php echo nl2br(htmlspecialchars($p['catatan'])); ?></p>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($alokasi_penjualan)): ?>
                    <div class="mt-4">
                        <label class="small fw-semibold text-muted d-block mb-3">
                            <i class="bi bi-split me-1"></i>Pembagian Otomatis
                        </label>
                        <div class="row g-3">
                            <?php foreach ($alokasi_penjualan as $alokasi): ?>
                            <div class="col-md-6">
                                <div class="p-4 rounded-4 border border-primary-subtle bg-primary bg-opacity-5">
                                    <div class="small text-muted mb-1"><?php echo htmlspecialchars($alokasi['nama_rekening']); ?></div>
                                    <h5 class="fw-bold mb-0 text-primary-emphasis">Rp <?php echo number_format($alokasi['jumlah'], 0, ',', '.'); ?></h5>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if ($p['foto']): ?>
                    <div class="mt-4">
                        <label class="small fw-semibold text-muted d-block mb-2">
                            <i class="bi bi-image me-1"></i>Bukti Foto
                        </label>
                        <div class="rounded-4 overflow-hidden border border-light bg-light p-2">
                            <img src="<?php echo $base_url; ?>/uploads/bukti/<?php echo $p['foto']; ?>" class="img-fluid rounded-3 d-block mx-auto" alt="Bukti Penjualan" style="max-height:400px;object-fit:contain;">
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer border-0 pt-0 flex-wrap gap-2">
                    <button class="btn btn-outline-warning" data-bs-toggle="modal" data-bs-target="#editModal<?php echo $p['id']; ?>" data-bs-dismiss="modal">
                        <i class="bi bi-pencil-square me-2"></i>Edit
                    </button>
                    <button class="btn btn-outline-danger" onclick="$('#detailPenjualanModal<?php echo $p['id']; ?>').modal('hide'); setTimeout(()=>hapusPenjualan(<?php echo $p['id']; ?>),200);">
                        <i class="bi bi-trash me-2"></i>Hapus
                    </button>
                    <button class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-lg me-2"></i>Tutup
                    </button>
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

            </tbody>
            <tfoot>
                <tr style="background: linear-gradient(135deg,#FEF3C7 0%,#FDE68A 100%);">
                    <td colspan="2" class="px-4 py-3 fw-bold text-end">
                        <span class="text-warning-emphasis" style="font-size: 1rem;">TOTAL (<?php echo number_format($ringkasan_penjualan['total_data'] ?? 0); ?> transaksi)</span>
                    </td>
                    <td class="px-3 py-3 fw-bold text-end">
                        <h5 class="mb-0 text-warning-emphasis">Rp <?php echo number_format($ringkasan_penjualan['total_nominal'] ?? 0, 0, ',', '.'); ?></h5>
                    </td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php endif; ?>
</div>

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

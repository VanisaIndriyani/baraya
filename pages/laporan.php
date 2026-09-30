<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once '../includes/header.php';

$nama_bulan = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$bulan_dipilih = trim($_GET['bulan'] ?? '');
if ($bulan_dipilih !== '' && !preg_match('/^\d{4}-\d{2}$/', $bulan_dipilih)) {
    $bulan_dipilih = '';
}

function laporanLabelBulan(array $nama_bulan, string $ym): string
{
    $waktu = strtotime($ym . '-01');
    return $nama_bulan[(int) date('n', $waktu)] . ' ' . date('Y', $waktu);
}

$where_jual = '';
$where_beli = '';
$params = [];
if ($bulan_dipilih !== '') {
    $where_jual = " WHERE DATE_FORMAT(tanggal, '%Y-%m') = ?";
    $where_beli = " WHERE DATE_FORMAT(p.tanggal, '%Y-%m') = ?";
    $params[] = $bulan_dipilih;
}

$stmt = $pdo->prepare("SELECT id, tanggal, catatan, total_pendapatan, DATE_FORMAT(tanggal, '%Y-%m') AS bulan FROM penjualan" . $where_jual . " ORDER BY tanggal DESC, id DESC");
$stmt->execute($params);
$penjualan = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT p.id, p.tanggal, p.total, p.metode_pembayaran, s.nama AS supplier_nama,
           DATE_FORMAT(p.tanggal, '%Y-%m') AS bulan,
           (
                SELECT GROUP_CONCAT(CONCAT(COALESCE(b.nama, 'Barang'), ' x', pd.qty) ORDER BY pd.id SEPARATOR ', ')
                FROM pembelian_detail pd
                LEFT JOIN barang b ON b.id = pd.id_barang
                WHERE pd.id_pembelian = p.id
           ) AS item
    FROM pembelian p
    LEFT JOIN supplier s ON s.id = p.id_supplier
    " . $where_beli . "
    ORDER BY p.tanggal DESC, p.id DESC
");
$stmt->execute($params);
$pembelian = $stmt->fetchAll();

$bulan_tersedia = $pdo->query("
    SELECT bulan FROM (
        SELECT DATE_FORMAT(tanggal, '%Y-%m') AS bulan FROM penjualan
        UNION
        SELECT DATE_FORMAT(tanggal, '%Y-%m') AS bulan FROM pembelian
    ) sumber
    WHERE bulan IS NOT NULL
    ORDER BY bulan DESC
")->fetchAll(PDO::FETCH_COLUMN);

$grup = [];
foreach ($penjualan as $row) {
    $grup[$row['bulan']]['jual'][] = $row;
}
foreach ($pembelian as $row) {
    $grup[$row['bulan']]['beli'][] = $row;
}
krsort($grup);

$total_jual = array_sum(array_map(static function ($row) {
    return (float) $row['total_pendapatan'];
}, $penjualan));
$total_beli = array_sum(array_map(static function ($row) {
    return (float) $row['total'];
}, $pembelian));
?>

<style>
.lap-hero {
    background: linear-gradient(135deg, #450A0A 0%, #7F1D1D 55%, #991B1B 100%);
    color: #fff;
    border-radius: 22px;
    padding: 22px;
    box-shadow: 0 16px 36px rgba(127, 29, 29, 0.22);
    position: relative;
    overflow: hidden;
}
.lap-hero::after {
    content: "";
    position: absolute;
    right: -40px;
    top: -50px;
    width: 180px;
    height: 180px;
    border-radius: 50%;
    background: rgba(251, 191, 36, 0.16);
}
.lap-stat {
    border-radius: 18px;
    padding: 16px;
    height: 100%;
    border: 1px solid rgba(15, 23, 42, 0.06);
    box-shadow: 0 8px 22px rgba(15, 23, 42, 0.05);
}
.lap-stat .angka {
    font-family: 'Poppins', sans-serif;
    font-weight: 800;
    font-size: clamp(1.15rem, 3vw, 1.55rem);
    letter-spacing: -0.4px;
    line-height: 1.15;
    overflow-wrap: anywhere;
}
.lap-chip {
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    padding: 7px 14px;
    text-decoration: none;
    font-weight: 600;
    font-size: 0.86rem;
    border: 1px solid #FECACA;
    background: #fff;
    color: #7F1D1D;
    white-space: nowrap;
}
.lap-chip.active {
    background: #991B1B;
    color: #fff;
    border-color: #991B1B;
}
.lap-scroll {
    display: flex;
    gap: 8px;
    overflow-x: auto;
    padding-bottom: 4px;
    -webkit-overflow-scrolling: touch;
}
.lap-panel {
    border-radius: 16px;
    padding: 14px;
    height: 100%;
}
.lap-row {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    align-items: flex-start;
    background: #fff;
    border-radius: 14px;
    padding: 12px;
    margin-top: 8px;
}
.lap-tanggal {
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: 0.2px;
}
@media (max-width: 576px) {
    .lap-hero { padding: 16px; border-radius: 18px; }
    .lap-stat { padding: 14px; }
}
</style>

<div class="lap-hero mb-4">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 position-relative" style="z-index:1;">
        <div>
            <a href="<?php echo $base_url; ?>/admin_dashboard.php" class="text-white text-decoration-none d-inline-flex align-items-center gap-1 mb-2 opacity-75">
                <i class="bi bi-arrow-left"></i> Dashboard
            </a>
            <h4 class="mb-1 fw-bold">Laporan Lengkap</h4>
            <div class="small" style="color:#FDE68A;">Semua penjualan dan beli bahan tetap di sini, termasuk bulan yang sudah lewat.</div>
        </div>
        <form method="get" class="bg-white rounded-pill px-3 py-1 d-flex align-items-center gap-2">
            <i class="bi bi-calendar3" style="color:#991B1B;"></i>
            <select name="bulan" class="form-select border-0 shadow-none bg-transparent" onchange="this.form.submit()" style="min-width: 170px;">
                <option value="">Semua bulan</option>
                <?php foreach ($bulan_tersedia as $bulan): ?>
                <option value="<?php echo htmlspecialchars($bulan); ?>" <?php echo $bulan === $bulan_dipilih ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars(laporanLabelBulan($nama_bulan, $bulan)); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-12 col-md-4">
        <div class="lap-stat" style="background: linear-gradient(135deg,#D1FAE5,#A7F3D0); color:#065F46;">
            <div class="d-flex justify-content-between align-items-start gap-2">
                <div>
                    <div class="small fw-semibold mb-1"><i class="bi bi-cart-check-fill me-1"></i>Penjualan</div>
                    <div class="angka">Rp <?php echo number_format($total_jual, 0, ',', '.'); ?></div>
                    <div class="small mt-1"><?php echo count($penjualan); ?> catatan</div>
                </div>
                <i class="bi bi-graph-up-arrow" style="font-size:1.6rem; opacity:0.45;"></i>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="lap-stat" style="background: linear-gradient(135deg,#FEF3C7,#FDE68A); color:#78350F;">
            <div class="small fw-semibold mb-1"><i class="bi bi-bag-fill me-1"></i>Beli bahan</div>
            <div class="angka">Rp <?php echo number_format($total_beli, 0, ',', '.'); ?></div>
            <div class="small mt-1"><?php echo count($pembelian); ?> catatan</div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="lap-stat" style="background: linear-gradient(135deg,#450A0A,#991B1B); color:#fff;">
            <div class="small fw-semibold mb-1" style="color:#FDE68A;"><i class="bi bi-piggy-bank-fill me-1"></i>Selisih</div>
            <div class="angka">Rp <?php echo number_format($total_jual - $total_beli, 0, ',', '.'); ?></div>
            <div class="small mt-1 opacity-75"><?php echo $bulan_dipilih === '' ? 'Semua periode' : htmlspecialchars(laporanLabelBulan($nama_bulan, $bulan_dipilih)); ?></div>
        </div>
    </div>
</div>

<?php if ($bulan_tersedia): ?>
<div class="lap-scroll mb-4">
    <a class="lap-chip <?php echo $bulan_dipilih === '' ? 'active' : ''; ?>" href="<?php echo $base_url; ?>/pages/laporan.php">Semua</a>
    <?php foreach ($bulan_tersedia as $bulan): ?>
    <a class="lap-chip <?php echo $bulan === $bulan_dipilih ? 'active' : ''; ?>" href="<?php echo $base_url; ?>/pages/laporan.php?bulan=<?php echo urlencode($bulan); ?>">
        <?php echo htmlspecialchars(laporanLabelBulan($nama_bulan, $bulan)); ?>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!$grup): ?>
<div class="card border-0 shadow-sm rounded-4">
    <div class="card-body text-center py-5">
        <div class="rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width:72px;height:72px;background:#FEF3C7;color:#92400E;">
            <i class="bi bi-journal-x" style="font-size:1.7rem;"></i>
        </div>
        <h5 class="fw-bold mb-1">Belum ada laporan</h5>
        <p class="text-muted mb-0">Catatan penjualan dan beli bahan akan muncul di sini.</p>
    </div>
</div>
<?php endif; ?>

<?php foreach ($grup as $bulan => $isi):
    $jual = $isi['jual'] ?? [];
    $beli = $isi['beli'] ?? [];
    $sum_jual = array_sum(array_column($jual, 'total_pendapatan'));
    $sum_beli = array_sum(array_column($beli, 'total'));
    $selisih = $sum_jual - $sum_beli;
?>
<div class="card border-0 shadow-sm rounded-4 mb-4 overflow-hidden">
    <div class="px-3 px-md-4 py-3 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2" style="background: linear-gradient(135deg,#450A0A,#991B1B); color:#fff;">
        <div>
            <div class="fw-bold" style="font-size:1.15rem;"><?php echo htmlspecialchars(laporanLabelBulan($nama_bulan, $bulan)); ?></div>
            <div class="small" style="color:#FDE68A;"><?php echo count($jual); ?> penjualan · <?php echo count($beli); ?> beli bahan</div>
        </div>
        <span class="badge rounded-pill px-3 py-2" style="background:rgba(255,255,255,0.14); color:#fff; border:1px solid rgba(251,191,36,0.35);">
            Selisih Rp <?php echo number_format($selisih, 0, ',', '.'); ?>
        </span>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-12 col-lg-6">
                <div class="lap-panel" style="background:#F0FDF4;">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="fw-bold" style="color:#047857;"><i class="bi bi-cart-check-fill me-1"></i>Penjualan</div>
                        <div class="fw-bold" style="color:#047857;">Rp <?php echo number_format($sum_jual, 0, ',', '.'); ?></div>
                    </div>
                    <?php if (!$jual): ?>
                        <div class="text-muted small mt-3">Tidak ada penjualan bulan ini.</div>
                    <?php else: foreach ($jual as $row): ?>
                        <div class="lap-row">
                            <div class="min-w-0">
                                <div class="lap-tanggal" style="color:#047857;"><?php echo date('d M Y', strtotime($row['tanggal'])); ?></div>
                                <div class="small text-muted"><?php echo htmlspecialchars($row['catatan'] ?: 'Tanpa catatan'); ?></div>
                            </div>
                            <div class="fw-bold flex-shrink-0" style="color:#065F46;">Rp <?php echo number_format($row['total_pendapatan'], 0, ',', '.'); ?></div>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
            <div class="col-12 col-lg-6">
                <div class="lap-panel" style="background:#FFFBEB;">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="fw-bold" style="color:#991B1B;"><i class="bi bi-bag-fill me-1"></i>Beli bahan</div>
                        <div class="fw-bold" style="color:#991B1B;">Rp <?php echo number_format($sum_beli, 0, ',', '.'); ?></div>
                    </div>
                    <?php if (!$beli): ?>
                        <div class="text-muted small mt-3">Tidak ada beli bahan bulan ini.</div>
                    <?php else: foreach ($beli as $row): ?>
                        <div class="lap-row">
                            <div class="min-w-0">
                                <div class="lap-tanggal" style="color:#991B1B;"><?php echo date('d M Y', strtotime($row['tanggal'])); ?></div>
                                <div class="small text-muted"><?php echo htmlspecialchars($row['item'] ?: ($row['supplier_nama'] ?: 'Tanpa item')); ?></div>
                            </div>
                            <div class="fw-bold flex-shrink-0" style="color:#7F1D1D;">Rp <?php echo number_format($row['total'], 0, ',', '.'); ?></div>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>

<?php include '../includes/footer.php'; ?>

<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once 'includes/header.php';
require_once 'includes/finance-utils.php';

$today = date('Y-m-d');
$month = date('Y-m');

function dashboardFetchValue($pdo, $sql, $params = [])
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

function dashboardFetchAll($pdo, $sql, $params = [])
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

financeEnsureAccountSystem($pdo);
financeEnsureBiayaWajibTable($pdo);

$float_kasir = $pdo->query("SELECT * FROM float_kasir ORDER BY id DESC LIMIT 1")->fetch();
$pendapatan_hari_ini = (float) dashboardFetchValue(
    $pdo,
    "SELECT COALESCE(SUM(total_pendapatan), 0) FROM penjualan WHERE tanggal = ?",
    [$today]
);
$pembelian_hari_ini = (float) dashboardFetchValue(
    $pdo,
    "SELECT COALESCE(SUM(total), 0) FROM pembelian WHERE tanggal = ?",
    [$today]
);
$beli_harian_hari_ini = (float) dashboardFetchValue(
    $pdo,
    "SELECT COALESCE(SUM(subtotal), 0) FROM beli_harian WHERE tanggal = ?",
    [$today]
);
$pengeluaran_hari_ini = (float) dashboardFetchValue(
    $pdo,
    "SELECT COALESCE(SUM(jumlah), 0) FROM pengeluaran WHERE tanggal = ?",
    [$today]
);

try {
    $pendapatan_bulan_ini = (float) dashboardFetchValue($pdo, "SELECT COALESCE(SUM(total_pendapatan), 0) FROM penjualan WHERE DATE_FORMAT(tanggal, '%Y-%m') = ?", [$month]);
} catch (Exception $e) {
    $pendapatan_bulan_ini = 0;
}

try {
    $pengeluaran_bulan_ini = (float) dashboardFetchValue($pdo, "SELECT COALESCE(SUM(jumlah), 0) FROM pengeluaran WHERE DATE_FORMAT(tanggal, '%Y-%m') = ?", [$month]);
} catch (Exception $e) {
    $pengeluaran_bulan_ini = 0;
}

try {
    $pembelian_bulan_ini = (float) dashboardFetchValue($pdo, "SELECT COALESCE(SUM(total), 0) FROM pembelian WHERE DATE_FORMAT(tanggal, '%Y-%m') = ?", [$month]);
} catch (Exception $e) {
    $pembelian_bulan_ini = 0;
}

try {
    $beli_harian_bulan_ini = (float) dashboardFetchValue($pdo, "SELECT COALESCE(SUM(subtotal), 0) FROM beli_harian WHERE DATE_FORMAT(tanggal, '%Y-%m') = ?", [$month]);
} catch (Exception $e) {
    $beli_harian_bulan_ini = 0;
}

$laba_periode = $pendapatan_bulan_ini - ($pengeluaran_bulan_ini + $pembelian_bulan_ini + $beli_harian_bulan_ini);

try {
    $sql_hutang = "SELECT h.jumlah - COALESCE(SUM(p.jumlah), 0) as sisa FROM hutang_owner h LEFT JOIN hutang_owner_pembayaran p ON h.id = p.id_hutang WHERE h.status = 'belum_lunas' GROUP BY h.id";
    $sisa_hutang = dashboardFetchAll($pdo, $sql_hutang);
} catch (Exception $e) {
    $sisa_hutang = [];
}
$total_sisa_hutang = array_sum(array_column($sisa_hutang, 'sisa'));
$stok_hampir_habis = $pdo->query("SELECT * FROM barang WHERE stok <= min_stok ORDER BY stok ASC")->fetchAll();

try {
    $sql_pendapatan_chart = "SELECT DATE(tanggal) as tanggal, COALESCE(SUM(total_pendapatan), 0) as total FROM penjualan WHERE tanggal >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY DATE(tanggal) ORDER BY tanggal ASC";
    $pendapatan_30_hari = dashboardFetchAll($pdo, $sql_pendapatan_chart);
} catch (Exception $e) {
    $pendapatan_30_hari = [];
}

try {
    $sql_pengeluaran_chart = "SELECT DATE(tanggal) as tanggal, COALESCE(SUM(jumlah), 0) as total FROM pengeluaran WHERE tanggal >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY DATE(tanggal) ORDER BY tanggal ASC";
    $pengeluaran_30_hari = dashboardFetchAll($pdo, $sql_pengeluaran_chart);
} catch (Exception $e) {
    $pengeluaran_30_hari = [];
}

try {
    $saldo_rekening = $pdo->query("SELECT * FROM saldo_rekening ORDER BY CASE kode_rekening WHEN 'operasional' THEN 1 WHEN 'penjualan' THEN 2 ELSE 3 END, id ASC")->fetchAll();
} catch (Exception $e) {
    $saldo_rekening = [];
}

$saldo_float_kasir = $float_kasir['saldo_sekarang'] ?? 0;
$total_saldo_rekening = array_sum(array_map(function ($rekening) {
    return (float) ($rekening['saldo'] ?? 0);
}, $saldo_rekening));
$ringkasan_biaya_harian = financeGetBiayaWajibSummary($pdo, $today);
$ringkasan_biaya_bulanan = financeGetBiayaWajibSummaryByMonth($pdo, $month);
$estimasi_sisa_hari_ini = $pendapatan_hari_ini - (float) ($ringkasan_biaya_harian['target_harian'] ?? 0);
$bersih_real_hari_ini = $pendapatan_hari_ini - ($pengeluaran_hari_ini + $pembelian_hari_ini + $beli_harian_hari_ini);
$estimasi_sisa_bulan = $pendapatan_bulan_ini - (float) ($ringkasan_biaya_bulanan['total_bulanan'] ?? 0);
$bersih_real_bulan_ini = $pendapatan_bulan_ini - ($pengeluaran_bulan_ini + $pembelian_bulan_ini + $beli_harian_bulan_ini);
?>

<div class="page-header" style="border-top:3px solid transparent; border-image: linear-gradient(90deg,#FBBF24,#991B1B,#FBBF24) 1;">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3">
        <div>
            <h4 class="mb-1 fw-bold" style="letter-spacing:-0.3px;">
                <i class="bi bi-grid-fill me-2" style="color:#991B1B;"></i>Dashboard
            </h4>
            <small class="text-muted">
                <i class="bi bi-calendar3 me-1"></i><?php echo date('d F Y'); ?>
            </small>
        </div>
    </div>
</div>

<!-- Stat Cards (RINGKAS) -->
<div class="row g-4 mb-4">
    <div class="col-md-6 col-xl">
        <div class="stat-card transition-card" style="background: linear-gradient(135deg,#FEF3C7 0%,#FDE68A 50%,#FCD34D 100%); color: #78350F; box-shadow: 0 10px 28px rgba(251,191,36,0.18), inset 0 1px 0 rgba(255,255,255,0.6); border: 1px solid rgba(251,191,36,0.35);">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-label fw-semibold mb-2" style="opacity:1;">
                        <i class="bi bi-wallet2 me-1.5"></i>Kas Operasional
                    </div>
                    <h3 class="stat-value fw-black mb-1" style="text-shadow: 0 1px 2px rgba(120,53,15,0.1);">Rp <?php
                        $saldo_ops = 0;
                        foreach ($saldo_rekening as $r) {
                            if (($r['kode_rekening'] ?? '') === 'operasional') $saldo_ops = (float) ($r['saldo'] ?? 0);
                        }
                        echo number_format($saldo_ops, 0, ',', '.');
                    ?></h3>
                </div>
                <div class="rounded-3 d-flex align-items-center justify-content-center" style="width:56px;height:56px;background:rgba(120,53,15,0.15); border: 1px solid rgba(120,53,15,0.2);">
                    <i class="bi bi-wallet2" style="font-size: 1.6rem; color:#78350F;"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-12 col-xl-6">
        <div class="stat-card gold transition-card" style="box-shadow: 0 12px 32px rgba(146,64,14,0.18), inset 0 1px 0 rgba(255,255,255,0.6); border:1px solid rgba(217,119,6,0.3);">
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div>
                    <div class="stat-label fw-semibold mb-1" style="opacity:1;">
                        <i class="bi bi-graph-up-arrow me-1.5"></i>Hasil Penjualan Bulan Ini
                    </div>
                </div>
                <div class="rounded-3 d-flex align-items-center justify-content-center" style="width:52px;height:52px;background:rgba(120,53,15,0.18); border: 1px solid rgba(120,53,15,0.22);">
                    <i class="bi bi-cash-stack" style="font-size: 1.4rem; color:#78350F;"></i>
                </div>
            </div>
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="rounded-3 p-3" style="background: rgba(255,255,255,0.3); backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px); border:1px solid rgba(255,255,255,0.4);">
                        <div class="small mb-1" style="color:#78350F;opacity:0.85;"><i class="bi bi-arrow-up-right me-1"></i>Kotor</div>
                        <h5 class="mb-0 fw-black" style="color:#78350F; text-shadow: 0 1px 2px rgba(120,53,15,0.1);">Rp <?php echo number_format($pendapatan_bulan_ini, 0, ',', '.'); ?></h5>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="rounded-3 p-3" style="background: rgba(255,255,255,0.45); backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px); border:1px solid rgba(255,255,255,0.55);">
                        <div class="small mb-1" style="color:#78350F;opacity:0.85;"><i class="bi bi-check2-circle me-1"></i>Bersih</div>
                        <h5 class="mb-0 fw-black <?php echo $bersih_real_bulan_ini >= 0 ? '' : 'text-danger-emphasis'; ?>" style="color:<?php echo $bersih_real_bulan_ini >= 0 ? '#78350F' : ''; ?>; text-shadow: 0 1px 2px rgba(0,0,0,0.08);">Rp <?php echo number_format($bersih_real_bulan_ini, 0, ',', '.'); ?></h5>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-xl">
        <div class="transition-card rounded-4 p-4 h-100" style="background: linear-gradient(135deg,#450A0A 0%,#7F1D1D 50%,#991B1B 100%); color:white; box-shadow: 0 12px 30px rgba(153,27,27,0.22), inset 0 1px 0 rgba(251,191,36,0.15); border: 1px solid rgba(251,191,36,0.25);">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="small mb-2 fw-semibold" style="color:#FDE68A; letter-spacing:0.2px;">
                        <i class="bi bi-exclamation-triangle-fill me-1.5"></i>Sisa Utang Owner
                    </div>
                    <h3 class="mb-0 fw-black lh-1" style="color:white; text-shadow: 0 1px 3px rgba(0,0,0,0.25); letter-spacing:-0.3px;">Rp <?php echo number_format($total_sisa_hutang, 0, ',', '.'); ?></h3>
                </div>
                <div class="rounded-3 d-flex align-items-center justify-content-center" style="width:56px;height:56px;background: rgba(251,191,36,0.15); border: 1px solid rgba(251,191,36,0.3);">
                    <i class="bi bi-person-exclamation" style="font-size: 1.5rem; color:#FDE68A;"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-md-6">
        <div class="card border-0 shadow-sm rounded-4 h-100 transition-card" style="border-top: 3px solid #10B981;">
            <div class="card-body p-4">
                <h5 class="mb-4 fw-bold" style="letter-spacing:-0.2px;">
                    <i class="bi bi-calendar-day-fill me-2 text-success-emphasis"></i>Ringkasan Hari Ini
                </h5>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <div class="rounded-3 p-3" style="background: rgba(16,185,129,0.08); border-left: 3px solid #10B981;">
                            <div class="small mb-1 fw-semibold" style="color:#047857;">Penjualan</div>
                            <div class="fw-black text-success-emphasis" style="font-size: 1.05rem;">Rp <?php echo number_format($pendapatan_hari_ini, 0, ',', '.'); ?></div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="rounded-3 p-3" style="background: rgba(251,191,36,0.10); border-left: 3px solid #F59E0B;">
                            <div class="small mb-1 fw-semibold" style="color:#B45309;">Target Kas Ops</div>
                            <div class="fw-black text-warning-emphasis" style="font-size: 1.05rem;">Rp <?php echo number_format($ringkasan_biaya_harian['target_harian'] ?? 0, 0, ',', '.'); ?></div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="rounded-3 p-3" style="background: rgba(239,68,68,0.08); border-left: 3px solid #EF4444;">
                            <div class="small mb-1 fw-semibold" style="color:#B91C1C;">Pengeluaran</div>
                            <div class="fw-black text-danger-emphasis" style="font-size: 1.05rem;">Rp <?php echo number_format($pengeluaran_hari_ini, 0, ',', '.'); ?></div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="rounded-3 p-3" style="background: rgba(37,99,235,0.08); border-left: 3px solid #2563EB;">
                            <div class="small mb-1 fw-semibold" style="color:#1D4ED8;">Beli Bahan</div>
                            <div class="fw-black text-primary-emphasis" style="font-size: 1.05rem;">Rp <?php echo number_format($pembelian_hari_ini + $beli_harian_hari_ini, 0, ',', '.'); ?></div>
                        </div>
                    </div>
                </div>
                <hr class="my-2 opacity-25">
                <div class="row g-3">
                    <div class="col-6">
                        <div class="small mb-1 fw-semibold text-muted"><i class="bi bi-piggy-bank me-1"></i>Estimasi Sisa</div>
                        <div class="fw-black <?php echo $estimasi_sisa_hari_ini >= 0 ? 'text-success-emphasis' : 'text-danger-emphasis'; ?>" style="font-size: 1.1rem;">
                            Rp <?php echo number_format($estimasi_sisa_hari_ini, 0, ',', '.'); ?>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="small mb-1 fw-semibold text-muted"><i class="bi bi-check2-circle me-1"></i>Bersih Real</div>
                        <div class="fw-black <?php echo $bersih_real_hari_ini >= 0 ? 'text-success-emphasis' : 'text-danger-emphasis'; ?>" style="font-size: 1.1rem;">
                            Rp <?php echo number_format($bersih_real_hari_ini, 0, ',', '.'); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card border-0 shadow-sm rounded-4 h-100 transition-card" style="border-top: 3px solid #F59E0B;">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-start mb-4">
                    <h5 class="mb-0 fw-bold" style="letter-spacing:-0.2px;">
                        <i class="bi bi-calendar2-month-fill me-2 text-warning-emphasis"></i>Ringkasan Bulanan
                    </h5>
                    <a href="<?php echo $base_url; ?>/pages/biaya-wajib.php" class="btn btn-sm rounded-pill fw-semibold px-3" style="background: rgba(251,191,36,0.12); color:#B45309; border:1px solid rgba(245,158,11,0.3);">
                        <i class="bi bi-sliders me-1"></i>Kelola
                    </a>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <div class="rounded-3 p-3" style="background: rgba(251,191,36,0.10); border-left: 3px solid #F59E0B;">
                            <div class="small mb-1 fw-semibold" style="color:#B45309;">Target Rutin</div>
                            <div class="fw-black text-warning-emphasis" style="font-size: 1rem;">Rp <?php echo number_format($ringkasan_biaya_bulanan['total_bulanan'] ?? 0, 0, ',', '.'); ?></div>
                            <div class="small opacity-75 mt-1" style="color:#92400E;"><?php echo (int) ($ringkasan_biaya_bulanan['jumlah_item'] ?? 0); ?> item aktif</div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="rounded-3 p-3" style="background: <?php echo $estimasi_sisa_bulan >= 0 ? 'rgba(16,185,129,0.08)' : 'rgba(239,68,68,0.08)'; ?>; border-left: 3px solid <?php echo $estimasi_sisa_bulan >= 0 ? '#10B981' : '#EF4444'; ?>;">
                            <div class="small mb-1 fw-semibold" style="color:<?php echo $estimasi_sisa_bulan >= 0 ? '#047857' : '#B91C1C'; ?>;">Estimasi Sisa</div>
                            <div class="fw-black <?php echo $estimasi_sisa_bulan >= 0 ? 'text-success-emphasis' : 'text-danger-emphasis'; ?>" style="font-size: 1rem;">Rp <?php echo number_format($estimasi_sisa_bulan, 0, ',', '.'); ?></div>
                            <div class="small opacity-75 mt-1">jual - target</div>
                        </div>
                    </div>
                </div>
                <hr class="my-2 opacity-25">
                <div class="row g-2">
                    <div class="col-6">
                        <div class="small mb-1 fw-semibold text-muted">Beli Bahan (Stok)</div>
                        <div class="fw-black text-primary-emphasis">Rp <?php echo number_format($pembelian_bulan_ini, 0, ',', '.'); ?></div>
                    </div>
                    <div class="col-6">
                        <div class="small mb-1 fw-semibold text-muted">Beli Harian</div>
                        <div class="fw-black text-primary-emphasis">Rp <?php echo number_format($beli_harian_bulan_ini, 0, ',', '.'); ?></div>
                    </div>
                    <div class="col-6">
                        <div class="small mb-1 fw-semibold text-muted">Pengeluaran</div>
                        <div class="fw-black text-danger-emphasis">Rp <?php echo number_format($pengeluaran_bulan_ini, 0, ',', '.'); ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-12">
        <div class="card border-0 shadow-sm rounded-4 transition-card" style="border-top: 3px solid #991B1B;">
            <div class="card-body">
                <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-4">
                    <div>
                        <h5 class="mb-1 fw-bold" style="letter-spacing:-0.2px;">
                            <i class="bi bi-bank2 me-2" style="color:#991B1B;"></i>Kas &amp; Rekening
                        </h5>
                        <small class="text-muted">Total gabungan: <b>Rp <?php echo number_format($total_saldo_rekening, 0, ',', '.'); ?></b></small>
                    </div>
                    <a href="<?php echo $base_url; ?>/pages/saldo_rekening.php" class="btn rounded-pill fw-semibold px-4" style="background: linear-gradient(135deg,#450A0A,#991B1B); color:white; box-shadow: 0 6px 16px rgba(153,27,27,0.25);">
                        <i class="bi bi-arrow-right-short me-1"></i>Kelola
                    </a>
                </div>
                <div class="row g-3">
                    <?php foreach ($saldo_rekening as $rekening): ?>
                    <div class="col-md-6 col-xl-4">
                        <?php
                            $kode = $rekening['kode_rekening'] ?? '';
                            if ($kode === 'ruko') {
                                $bg = 'linear-gradient(135deg,#7F1D1D 0%,#991B1B 50%,#B91C1C 100%);';
                                $teks = 'white';
                                $label = 'Uang Ruko (Wajib)';
                                $icon = 'bi-building-fill-lock';
                                $subtitle = 'Wajib disimpan untuk bayar sewa ruko';
                            } elseif ($kode === 'operasional') {
                                $bg = 'linear-gradient(135deg,#FEF3C7 0%,#FDE68A 50%,#FCD34D 100%);';
                                $teks = '#78350F';
                                $label = 'Kas Operasional';
                                $icon = 'bi-wallet-fill';
                                $subtitle = 'Belanja bahan &amp; bayar biaya rutin';
                            } elseif ($kode === 'penjualan') {
                                $bg = 'linear-gradient(135deg,#065F46 0%,#047857 50%,#059669 100%);';
                                $teks = 'white';
                                $label = 'Rekening Penjualan';
                                $icon = 'bi-piggy-bank-fill';
                                $subtitle = 'Tabungan bersih owner';
                            } else {
                                $bg = 'linear-gradient(135deg,#334155 0%,#475569 100%);';
                                $teks = 'white';
                                $label = htmlspecialchars($rekening['nama_rekening']);
                                $icon = 'bi-credit-card-fill';
                                $subtitle = 'Rekening tambahan';
                            }
                        ?>
                        <div class="rounded-4 p-4 h-100 transition-card" style="background: <?php echo $bg; ?> color: <?php echo $teks; ?>; box-shadow: 0 10px 24px rgba(15,23,42,0.08); border:1px solid rgba(255,255,255,0.2);">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <span class="badge rounded-pill fw-semibold" style="background: rgba(255,255,255,0.22); color: <?php echo $teks; ?>; backdrop-filter: blur(4px); border:1px solid rgba(255,255,255,0.28);">
                                    <i class="bi <?php echo $icon; ?> me-1"></i><?php echo $label; ?>
                                </span>
                                <div class="rounded-3 d-flex align-items-center justify-content-center" style="width:44px;height:44px; background: rgba(255,255,255,0.18); border:1px solid rgba(255,255,255,0.25);">
                                    <i class="bi <?php echo $icon; ?>" style="font-size: 1.2rem;"></i>
                                </div>
                            </div>
                            <div class="fw-black mb-1" style="font-size: 1.35rem; letter-spacing: -0.4px; text-shadow: 0 1px 3px rgba(0,0,0,0.18);">Rp <?php echo number_format($rekening['saldo'], 0, ',', '.'); ?></div>
                            <div class="small opacity-80" style="font-size:0.72rem;"><?php echo $subtitle; ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($saldo_rekening)): ?>
                    <div class="col-12">
                        <div class="text-muted p-4 text-center rounded-4" style="background:rgba(15,23,42,0.04);">Belum ada data rekening.</div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Charts Row -->
<div class="row g-4 mb-4">
    <div class="col-md-6">
        <div class="card border-0 shadow-sm rounded-4 h-100 transition-card" style="border-top:3px solid #10B981;">
            <div class="card-body p-4">
                <h5 class="card-title mb-3 fw-bold" style="letter-spacing:-0.2px;">
                    <i class="bi bi-bar-chart-line-fill me-2 text-success-emphasis"></i>Pendapatan 30 Hari
                </h5>
                <div style="height:280px;">
                    <canvas id="chartPendapatan"></canvas>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card border-0 shadow-sm rounded-4 h-100 transition-card" style="border-top:3px solid #991B1B;">
            <div class="card-body p-4">
                <h5 class="card-title mb-3 fw-bold" style="letter-spacing:-0.2px;">
                    <i class="bi bi-graph-down-arrow me-2" style="color:#991B1B;"></i>Pengeluaran 30 Hari
                </h5>
                <div style="height:280px;">
                    <canvas id="chartPengeluaran"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (count($stok_hampir_habis) > 0): ?>
<div class="card border-0 shadow-sm rounded-4 mb-4 transition-card" style="border-left:4px solid #F59E0B;">
    <div class="card-body p-4">
        <h5 class="card-title mb-3 fw-bold" style="letter-spacing:-0.2px;">
            <i class="bi bi-exclamation-triangle-fill me-2 text-warning-emphasis"></i>Stok Hampir Habis
            <span class="badge rounded-pill bg-danger ms-2 fw-semibold" style="font-size:0.75rem;"><?php echo count($stok_hampir_habis); ?> item</span>
        </h5>
        <div class="row g-2">
            <?php foreach ($stok_hampir_habis as $barang): ?>
            <div class="col-6 col-md-4 col-xl-3">
                <div class="d-flex align-items-center justify-content-between gap-2 p-2.5 rounded-3" style="background: rgba(251,191,36,0.08); border:1px solid rgba(245,158,11,0.2);">
                    <div class="fw-semibold small text-truncate" style="color:#92400E;"><?php echo htmlspecialchars($barang['nama']); ?></div>
                    <span class="badge rounded-pill bg-danger flex-shrink-0 fw-bold" style="font-size:0.72rem;"><?php echo $barang['stok']; ?> <?php echo $barang['satuan']; ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Quick Action Button -->
<div class="position-fixed bottom-0 end-0 p-4" style="z-index: 999;">
    <button class="btn rounded-circle shadow-lg transition-card" style="width: 64px; height: 64px; font-size: 28px; background: linear-gradient(135deg,#450A0A,#991B1B); color: #FDE68A; border: 2px solid #FBBF24; box-shadow: 0 12px 28px rgba(153,27,27,0.35), 0 0 0 4px rgba(251,191,36,0.12);" data-bs-toggle="modal" data-bs-target="#menuModal">
        <i class="bi bi-plus-lg"></i>
    </button>
</div>

<!-- Panduan Alur Uang (Mulai Hari Pertama) - COLLAPSE DEFAULT biar ga nyampah -->
<div class="card border-0 shadow-sm rounded-4 mb-4 transition-card" style="border-top:3px solid #334155;">
    <div class="card-body">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-0">
            <div>
                <h5 class="mb-1 fw-bold" style="letter-spacing:-0.2px;"><i class="bi bi-info-circle-fill me-2" style="color:#334155;"></i>Panduan Alur Uang &amp; Cara Mulai</h5>
                <small class="text-muted">Panduan disembunyikan default biar ga nyampah</small>
            </div>
            <button class="btn btn-sm fw-semibold px-4 rounded-pill" type="button" data-bs-toggle="collapse" data-bs-target="#panduanAlur" aria-expanded="false" aria-controls="panduanAlur" style="background: rgba(51,65,85,0.08); color:#334155; border:1px solid rgba(51,65,85,0.2);">
                <i class="bi bi-chevron-down me-1"></i> Buka Panduan
            </button>
        </div>

        <div class="collapse mt-3 pt-3" id="panduanAlur" style="border-top:1px solid rgba(15,23,42,0.08);">
            <div class="row g-3 mb-3">
                <div class="col-12 col-md-6 col-xl-6">
                    <div class="border rounded-4 p-3 h-100 bg-warning-50">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="badge bg-warning text-dark rounded-pill">Jenis 1</span>
                            <small class="fw-bold text-uppercase">Kas Operasional</small>
                        </div>
                        <ul class="small mb-0 ps-3 text-muted">
                            <li>Uang untuk <b>BELANJA</b>: beli bahan &amp; bayar biaya rutin</li>
                            <li>DIIPSI OTOMATIS dari alokasi penjualan tiap hari</li>
                            <li>Semua metode "Kas Operasional" di Beli Bahan / Pengeluaran pakai uang ini</li>
                        </ul>
                    </div>
                </div>
                <div class="col-12 col-md-6 col-xl-6">
                    <div class="border rounded-4 p-3 h-100 bg-success-50">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="badge bg-success rounded-pill">Jenis 2</span>
                            <small class="fw-bold text-uppercase">Rekening Penjualan</small>
                        </div>
                        <ul class="small mb-0 ps-3 text-muted">
                            <li><b>TABUNGAN HASIL PENJUALAN BERSIH</b></li>
                            <li>Isinya = penjualan - (target biaya rutin /hari)</li>
                            <li><b>JANGAN dipakai belanja harian</b> - ini uang owner</li>
                        </ul>
                    </div>
                </div>
            </div>

            <hr class="my-3">

            <div class="row g-3">
                <div class="col-12 col-md-6 col-xl-4">
                    <div class="border rounded-4 p-3 h-100 bg-light">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="badge bg-primary rounded-pill">Langkah 1</span>
                            <small class="fw-bold text-uppercase">Owner Kas Bon: Isi Modal Awal Kas Operasional</small>
                        </div>
                        <div class="small mb-2">
                            Hari pertama, <b>Kas Operasional masih kosong</b>. Owner kasih pinjaman DULU sebagai modal belanja (nanti setelah ada uang kasnya owner ambil kembali = LUNAS).
                        </div>
                        <div class="small text-muted mb-2">
                            Buka menu <b>Utang Owner</b> → klik tombol <b>Owner Kas Bon (+ Kas Operasional)</b> → pilih owner, isi jumlah (contoh Rp 500.000) → Simpan.
                        </div>
                        <div class="small text-muted mb-2">
                            ✅ Hasilnya otomatis:
                        </div>
                        <ul class="small text-muted mb-2 ps-3">
                            <li>Kas Operasional <b>bertambah Rp 500.000</b></li>
                            <li>Tercatat sebagai <b>Utang Owner Rp 500.000</b> (belum lunas)</li>
                        </ul>
                        <a href="<?php echo $base_url; ?>/pages/hutang-owner.php" class="btn btn-sm btn-warning w-100">Buka Utang Owner → Owner Kas Bon</a>
                    </div>
                </div>

                <div class="col-12 col-md-6 col-xl-4">
                    <div class="border rounded-4 p-3 h-100 bg-light">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="badge bg-primary rounded-pill">Langkah 2</span>
                            <small class="fw-bold text-uppercase">Beli Bahan Stok Awal</small>
                        </div>
                        <div class="small mb-2">
                            Kalau beli alpukat/gula/barang stok awal, pakai uang dari <b>Rekening Penjualan</b>.
                        </div>
                        <div class="small text-muted mb-2">
                            Buka <b>Beli Bahan (Stok)</b> → isi data → otomatis nominal berkurang dari <b>Rekening Penjualan</b>.
                        </div>
                        <a href="<?php echo $base_url; ?>/pages/pembelian.php" class="btn btn-sm btn-outline-primary w-100">Buka Beli Bahan (Stok)</a>
                    </div>
                </div>

                <div class="col-12 col-md-6 col-xl-4">
                    <div class="border rounded-4 p-3 h-100 bg-light">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="badge bg-primary rounded-pill">Langkah 3</span>
                            <small class="fw-bold text-uppercase">Catat Penjualan (Sore)</small>
                        </div>
                        <div class="small mb-2">
                            Catat penjualan di <b>Penjualan</b>. Uangnya otomatis di-SPLIT oleh sistem:
                        </div>
                        <ul class="small text-muted mb-2 ps-3">
                            <li>Bagian 1 → masuk ke <b>Kas Operasional</b> (alokasi biaya rutin /hari)</li>
                            <li>SISANYA → masuk ke <b>Rekening Penjualan</b> (tabungan bersih)</li>
                        </ul>
                        <a href="<?php echo $base_url; ?>/pages/penjualan.php" class="btn btn-sm btn-outline-success w-100">Buka Penjualan</a>
                    </div>
                </div>

                <div class="col-12 col-md-6 col-xl-4">
                    <div class="border rounded-4 p-3 h-100 bg-light">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="badge bg-warning text-dark rounded-pill">Bonus: Owner Tarik Kembali</span>
                            <small class="fw-bold text-uppercase">Setelah Uang Kas Operasional Terkumpul</small>
                        </div>
                        <div class="small mb-2">
                            Misal setelah 10 hari Kas Operasional keisi Rp 800.000 (dari target rutin tiap hari). Owner ingin ambil kembali Rp 500.000 pinjaman awal = LUNAS.
                        </div>
                        <div class="small text-muted mb-2">
                            Buka menu <b>Utang Owner</b> → klik tombol <b>Owner Tarik / Bayar Utang</b> → pilih utang owner yang mau dibayar → isi jumlah Rp 500.000 → Konfirmasi.
                        </div>
                        <ul class="small text-muted mb-2 ps-3">
                            <li>Kas Operasional <b>berkurang Rp 500.000</b></li>
                            <li>Utang Owner <b>berkurang / jadi LUNAS</b></li>
                        </ul>
                        <a href="<?php echo $base_url; ?>/pages/hutang-owner.php" class="btn btn-sm btn-outline-danger w-100">Owner Tarik Uang</a>
                    </div>
                </div>

                <div class="col-12 col-md-6 col-xl-4">
                    <div class="border rounded-4 p-3 h-100 bg-light">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="badge bg-warning text-dark rounded-pill">Contoh Angka Kamu</span>
                            <small class="fw-bold text-uppercase">Jual 200rb, Target 100rb, Es batu 30rb</small>
                        </div>
                        <div class="small mb-2">
                            Penjualan hari ini = Rp 200.000. Target biaya rutin / hari = Rp 100.000. Kamu beli es batu plastik = Rp 30.000.
                        </div>
                        <ul class="small text-muted mb-2 ps-3">
                            <li>Catat <b>Penjualan</b> → otomatis SPLIT:
                              <ul>
                                <li>✅ <b>Rp 100.000</b> → <b>Kas Operasional</b> (siapkan bayar rutin)</li>
                                <li>✅ <b>Rp 100.000</b> → <b>Rekening Penjualan</b> (UANG KAMU BERSIH)</li>
                              </ul>
                            </li>
                            <li>Catat <b>Pengeluaran</b> (es batu/plastik Rp 30rb, Metode: Kas Operasional) →
                              <ul>
                                <li>👉 Kas Operasional akhir = 100.000 - 30.000 = <b>Rp 70.000</b></li>
                              </ul>
                            </li>
                        </ul>
                    </div>
                </div>


            </div>
        </div>
    </div>
</div>

<!-- Quick Action Modal -->
<div class="modal fade" id="menuModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-5 border-0 overflow-hidden" style="box-shadow: 0 25px 60px rgba(153,27,27,0.25); border:2px solid #FBBF24;">
            <div class="modal-header border-0 text-center pt-4 pb-2 px-4" style="background: linear-gradient(135deg,#450A0A,#991B1B); color:white;">
                <h5 class="modal-title fw-black w-100" style="letter-spacing:-0.3px;"><i class="bi bi-plus-circle-fill me-2" style="color:#FDE68A;"></i> Tambah Transaksi</h5>
            </div>
            <div class="modal-body p-4">
                <div class="d-grid gap-3">
                    <a href="<?php echo $base_url; ?>/pages/penjualan.php" class="btn btn-lg rounded-4 fw-bold py-3 px-4 text-white" style="background: linear-gradient(135deg,#065F46,#10B981); box-shadow: 0 8px 20px rgba(16,185,129,0.25); border:0;">
                        <i class="bi bi-cart-check-fill me-2 fs-5"></i> Input Penjualan
                    </a>
                    <a href="<?php echo $base_url; ?>/pages/pembelian.php" class="btn btn-lg rounded-4 fw-bold py-3 px-4 text-white" style="background: linear-gradient(135deg,#7C2D12,#EA580C); box-shadow: 0 8px 20px rgba(234,88,12,0.25); border:0;">
                        <i class="bi bi-bag-plus-fill me-2 fs-5"></i> Input Beli Bahan (Stok)
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const ctxPendapatan = document.getElementById('chartPendapatan');

if (ctxPendapatan) {
    new Chart(ctxPendapatan, {
        type: 'line',
        data: {
            labels: <?= json_encode(array_column($pendapatan_30_hari, 'tanggal')); ?>,
            datasets: [{
                label: 'Pendapatan',
                data: <?= json_encode(array_column($pendapatan_30_hari, 'total')); ?>,
                borderColor: '#10B981',
                backgroundColor: 'rgba(16,185,129,0.18)',
                fill: true,
                tension: 0.4,
                borderWidth: 3,
                pointBackgroundColor: '#059669',
                pointRadius: 3
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true
                }
            }
        }
    });
}

const ctxPengeluaran = document.getElementById('chartPengeluaran');

if (ctxPengeluaran) {
    new Chart(ctxPengeluaran, {
        type: 'bar',
        data: {
            labels: <?= json_encode(array_column($pengeluaran_30_hari, 'tanggal')); ?>,
            datasets: [{
                label: 'Pengeluaran',
                data: <?= json_encode(array_column($pengeluaran_30_hari, 'total')); ?>,
                backgroundColor: 'rgba(153,27,27,0.85)',
                borderRadius: 8,
                borderSkipped: false
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true
                }
            }
        }
    });
}
</script>

<?php include 'includes/footer.php'; ?>

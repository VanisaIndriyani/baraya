<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once '../includes/header.php';
require_once '../includes/finance-utils.php';

$redirect_url = $base_url . '/pages/saldo_rekening.php';
financeEnsureAccountSystem($pdo);

function transaksiManual($transaksi)
{
    return !$transaksi['id_penjualan'] && !$transaksi['id_pengeluaran'];
}

function labelTipeTransaksi($tipe)
{
    return $tipe === 'debit' ? 'Pemasukan' : 'Pengeluaran';
}

function badgeTipeTransaksi($tipe)
{
    return $tipe === 'debit' ? 'success' : 'danger';
}

function asalTransaksiLabel($t)
{
    if (!empty($t['id_penjualan'])) {
        return '<span class="badge bg-info text-dark me-1"><i class="bi bi-cart-check"></i> Penjualan</span>';
    }
    if (!empty($t['id_pembelian'])) {
        return '<span class="badge bg-primary me-1"><i class="bi bi-bag-plus"></i> Beli Bahan</span>';
    }
    if (!empty($t['id_pengeluaran'])) {
        return '<span class="badge bg-danger me-1"><i class="bi bi-receipt"></i> Pengeluaran</span>';
    }
    return '<span class="badge bg-secondary me-1"><i class="bi bi-person"></i> Manual</span>';
}

function &ambilRekeningByKode($pdo, $kode, $namaDefault, $deskBadge, $deskLengkap, $warnaCard) {
    $stmt = $pdo->prepare("SELECT * FROM saldo_rekening WHERE kode_rekening = ? LIMIT 1");
    $stmt->execute([$kode]);
    $row = $stmt->fetch();
    if (!$row) {
        $stmt_ins = $pdo->prepare("INSERT INTO saldo_rekening (nama_rekening, kode_rekening, saldo) VALUES (?,?,0)");
        $stmt_ins->execute([$namaDefault, $kode]);
        $stmt->execute([$kode]);
        $row = $stmt->fetch();
    }
    $row['_display_nama'] = $namaDefault;
    $row['_desk_badge'] = $deskBadge;
    $row['_desk_lengkap'] = $deskLengkap;
    $row['_warna'] = $warnaCard;
    return $row;
}

$rek_operasional = ambilRekeningByKode($pdo, 'operasional',
    'Kas Operasional',
    'Bayar Bahan & Biaya Rutin',
    '',
    'warning');

$rek_penjualan = ambilRekeningByKode($pdo, 'penjualan',
    'Rekening Penjualan',
    'Hasil Jual Bersih (Owner)',
    '',
    'success');

$rek_ruko = ambilRekeningByKode($pdo, 'uang_ruko',
    'Uang Ruko',
    'Dipotong Dulu dari Penjualan',
    '',
    'danger');

$daftar_rekening = [$rek_ruko, $rek_operasional, $rek_penjualan];

$stmt_lain = $pdo->query("SELECT * FROM saldo_rekening WHERE kode_rekening NOT IN ('operasional','penjualan','uang_ruko') ORDER BY id ASC");
$rek_lain_list = $stmt_lain->fetchAll();
foreach ($rek_lain_list as $rl) {
    $rl['_display_nama'] = $rl['nama_rekening'];
    $rl['_desk_badge'] = 'Rekening Tambahan';
    $rl['_desk_lengkap'] = '';
    $rl['_warna'] = 'primary';
    $daftar_rekening[] = $rl;
}

$total_semua = 0.0;
foreach ($daftar_rekening as $r) {
    $total_semua += (float) $r['saldo'];
}
?>

<div class="page-header">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3 w-100">
        <div>
            <a href="<?php echo $base_url; ?>/admin_dashboard.php" class="text-white text-decoration-none mb-2 d-inline-block opacity-75"><i class="bi bi-arrow-left"></i> Kembali</a>
            <h4 class="mb-0"><i class="bi bi-wallet2 me-2"></i>Saldo Rekening</h4>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <?php foreach ($daftar_rekening as $rekening):
        $kode = $rekening['kode_rekening'] ?? '';
        $display_nama = $rekening['_display_nama'];
        $deskripsi_badge = $rekening['_desk_badge'];
        $card_color = $rekening['_warna'];
        $id_rekening = (int) $rekening['id'];
        $saldo_rek = (float) $rekening['saldo'];

        $card_gradients = [
            'danger'  => 'linear-gradient(135deg, #7F1D1D 0%, #991B1B 50%, #B91C1C 100%)',
            'warning' => 'linear-gradient(135deg, #78350F 0%, #92400E 50%, #B45309 100%)',
            'success' => 'linear-gradient(135deg, #064E3B 0%, #065F46 50%, #047857 100%)',
            'primary' => 'linear-gradient(135deg, #1E3A8A 0%, #1E40AF 50%, #2563EB 100%)',
        ];
        $bg_gradient = $card_gradients[$card_color] ?? $card_gradients['primary'];
        $card_icon = match ($card_color) {
            'danger'  => 'building-fill-lock',
            'warning' => 'wallet2',
            'success' => 'piggy-bank-fill',
            default   => 'credit-card-fill',
        };
    ?>
    <div class="col-md-6 col-xl-4">
        <div class="card border-0 shadow rounded-4 h-100 overflow-hidden text-white transition-card" style="background: <?php echo $bg_gradient; ?>;">
            <div class="card-body p-5">
                <div class="d-flex justify-content-between align-items-start mb-4">
                    <div>
                        <div class="opacity-75 small mb-1"><?php echo $deskripsi_badge; ?></div>
                        <h3 class="fw-bold mb-0 lh-1"><?php echo $display_nama; ?></h3>
                    </div>
                    <div class="bg-white bg-opacity-10 rounded-4 p-3 backdrop-blur-sm border border-white border-opacity-10">
                        <i class="bi bi-<?php echo $card_icon; ?> fs-3"></i>
                    </div>
                </div>
                <div class="rounded-3 bg-white bg-opacity-10 backdrop-blur-sm p-4 mb-4 border border-white border-opacity-10">
                    <div class="opacity-75 small mb-2 d-flex align-items-center">
                        <i class="bi bi-cash-stack me-2"></i>Saldo
                    </div>
                    <h1 class="fw-black mb-0 lh-1" style="font-size: clamp(1.5rem, 3vw, 2rem); letter-spacing: -0.5px;">
                        Rp <?php echo number_format($saldo_rek, 0, ',', '.'); ?>
                    </h1>
                </div>
                <?php
                    $stmt = $pdo->prepare("SELECT * FROM saldo_rekening_transaksi WHERE id_rekening = ? ORDER BY tanggal DESC, created_at DESC LIMIT 5");
                    $stmt->execute([$id_rekening]);
                    $transaksi_list = $stmt->fetchAll();
                ?>
                <div class="small mb-3 fw-bold d-flex align-items-center text-white" style="letter-spacing: 0.3px;">
                    <i class="bi bi-clock-history me-2 opacity-90"></i>Transaksi Terakhir
                </div>
                <?php if (empty($transaksi_list)): ?>
                <div class="rounded-3 p-4 text-center small fw-medium text-white border border-white border-opacity-15" style="background: rgba(255,255,255,0.07);">
                    <i class="bi bi-inbox me-1 opacity-70"></i> Belum ada transaksi
                </div>
                <?php else: ?>
                <div class="rounded-3 overflow-hidden border border-white border-opacity-12" style="background: rgba(255,255,255,0.06);">
                    <?php foreach ($transaksi_list as $idx => $t): ?>
                    <?php
                        $ket = $t['keterangan'] ?: labelTipeTransaksi($t['tipe_transaksi']);
                        $isLast = $idx === count($transaksi_list) - 1;
                    ?>
                    <div class="d-flex align-items-center justify-content-between gap-3 px-3 py-2.5 <?php echo !$isLast ? 'border-bottom border-white border-opacity-10' : ''; ?>">
                        <div class="flex-grow-1 min-w-0">
                            <div class="text-truncate fw-bold text-white mb-1" style="font-size: 0.88rem;">
                                <?php
                                    echo htmlspecialchars(mb_substr($ket, 0, 24));
                                    echo mb_strlen($ket) > 24 ? '...' : '';
                                ?>
                            </div>
                            <div class="text-white opacity-70 d-flex align-items-center" style="font-size: 0.72rem;">
                                <i class="bi bi-calendar3 me-1.5" style="font-size: 0.7rem;"></i><?php echo date('d M Y', strtotime($t['tanggal'])); ?>
                            </div>
                        </div>
                        <div class="fw-black flex-shrink-0 text-end <?php echo $t['tipe_transaksi'] === 'debit' ? 'text-success-emphasis' : 'text-danger-emphasis'; ?>" style="font-size: 0.92rem; letter-spacing: -0.2px; text-shadow: 0 1px 2px rgba(0,0,0,0.18);">
                            <?php echo $t['tipe_transaksi'] === 'debit' ? '+' : '-'; ?>Rp <?php echo number_format($t['jumlah'], 0, ',', '.'); ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <div class="col-12">
        <div class="card border-0 shadow rounded-4 overflow-hidden transition-card" style="background: linear-gradient(135deg, #0F172A 0%, #1E293B 50%, #334155 100%); color: white;">
            <div class="card-body d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-4 p-5">
                <div>
                    <div class="opacity-75 small mb-2"><i class="bi bi-graph-up-arrow me-2"></i>Total Aset</div>
                    <h1 class="fw-black mb-0 lh-1" style="font-size: clamp(1.75rem, 4vw, 2.5rem); letter-spacing: -0.5px;">
                        Rp <?php echo number_format($total_semua, 0, ',', '.'); ?>
                    </h1>
                    <small class="opacity-60 mt-2 d-block">Gabungan semua rekening</small>
                </div>
                <div class="d-flex flex-wrap gap-3 small">
                    <span class="badge rounded-pill bg-white bg-opacity-10 border border-white border-opacity-10 px-3 py-2">
                        <i class="bi bi-building-fill-lock me-1"></i>Ruko
                    </span>
                    <span class="badge rounded-pill bg-white bg-opacity-10 border border-white border-opacity-10 px-3 py-2">
                        <i class="bi bi-wallet2 me-1"></i>Operasional
                    </span>
                    <span class="badge rounded-pill bg-white bg-opacity-10 border border-white border-opacity-10 px-3 py-2">
                        <i class="bi bi-piggy-bank-fill me-1"></i>Penjualan
                    </span>
                    <span class="badge rounded-pill bg-white bg-opacity-10 border border-white border-opacity-10 px-3 py-2">
                        <i class="bi bi-lightning-fill me-1"></i>Auto Update
                    </span>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.transition-card {
    transition: all 0.3s ease;
}
.transition-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 20px 40px rgba(0,0,0,0.12) !important;
}
.backdrop-blur-sm {
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
}
</style>

<?php include '../includes/footer.php'; ?>

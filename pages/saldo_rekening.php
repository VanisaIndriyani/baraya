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
    if (!empty($t['id_pembelian'] ?? null)) {
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

<style>
.rek-hero {
    background: linear-gradient(135deg, #450A0A 0%, #7F1D1D 55%, #991B1B 100%);
    color: #fff;
    border-radius: 22px;
    padding: 22px;
    box-shadow: 0 16px 36px rgba(127, 29, 29, 0.22);
    position: relative;
    overflow: hidden;
}
.rek-hero::after {
    content: "";
    position: absolute;
    right: -36px;
    top: -46px;
    width: 170px;
    height: 170px;
    border-radius: 50%;
    background: rgba(251, 191, 36, 0.16);
}
.rek-total {
    font-family: 'Poppins', sans-serif;
    font-weight: 800;
    font-size: clamp(1.45rem, 4vw, 2rem);
    letter-spacing: -0.6px;
    line-height: 1.1;
    overflow-wrap: anywhere;
}
.rek-card {
    display: block;
    width: 100%;
    font: inherit;
    appearance: none;
    text-align: left;
    background: #fff;
    border: 1px solid rgba(15, 23, 42, 0.06);
    border-radius: 20px;
    box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05);
    overflow: hidden;
    height: 100%;
    cursor: pointer;
    padding: 0;
    color: inherit;
}
.rek-card:hover { box-shadow: 0 14px 28px rgba(15, 23, 42, 0.1); }
.rek-head { padding: 18px; color: #fff; }
.rek-saldo {
    font-family: 'Poppins', sans-serif;
    font-weight: 800;
    font-size: clamp(1.35rem, 3vw, 1.7rem);
    letter-spacing: -0.4px;
    overflow-wrap: anywhere;
}
.rek-hint {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 18px;
    background: #fff;
    color: #6B7280;
    font-size: 0.85rem;
    font-weight: 600;
}
.rek-row {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    align-items: flex-start;
    padding: 12px 0;
    border-bottom: 1px solid #F3F4F6;
}
.rek-row:last-child { border-bottom: 0; }
.rek-modal .modal-content { border: 0; border-radius: 20px; overflow: hidden; }
.rek-modal .modal-body { max-height: min(68vh, 560px); overflow-y: auto; }
@media (max-width: 576px) {
    .rek-hero { padding: 16px; border-radius: 18px; }
    .rek-modal .modal-dialog { margin: 12px; }
}
</style>

<div class="rek-hero mb-4">
    <div class="position-relative" style="z-index:1;">
        <a href="<?php echo $base_url; ?>/admin_dashboard.php" class="text-white text-decoration-none d-inline-flex align-items-center gap-1 mb-2 opacity-75">
            <i class="bi bi-arrow-left"></i> Dashboard
        </a>
        <h4 class="mb-1 fw-bold">Saldo Rekening</h4>
        <div class="small mb-3" style="color:#FDE68A;">Uang ruko, kas operasional, dan tabungan penjualan.</div>
        <div class="small opacity-75">Total semua rekening</div>
        <div class="rek-total">Rp <?php echo number_format($total_semua, 0, ',', '.'); ?></div>
    </div>
</div>

<div class="row g-3">
<?php foreach ($daftar_rekening as $rekening):
    $kode = $rekening['kode_rekening'] ?? '';
    $id_rekening = (int) $rekening['id'];
    $saldo_rek = (float) $rekening['saldo'];
    if ($kode === 'uang_ruko') {
        $bg = 'linear-gradient(135deg,#450A0A,#991B1B)';
        $ikon = 'building-fill-lock';
    } elseif ($kode === 'operasional') {
        $bg = 'linear-gradient(135deg,#92400E,#D97706)';
        $ikon = 'wallet2';
    } elseif ($kode === 'penjualan') {
        $bg = 'linear-gradient(135deg,#065F46,#059669)';
        $ikon = 'piggy-bank-fill';
    } else {
        $bg = 'linear-gradient(135deg,#1E3A8A,#2563EB)';
        $ikon = 'credit-card-fill';
    }
    $stmt = $pdo->prepare("SELECT * FROM saldo_rekening_transaksi WHERE id_rekening = ? ORDER BY tanggal DESC, id DESC");
    $stmt->execute([$id_rekening]);
    $transaksi_list = $stmt->fetchAll();
    $jumlah_riwayat = count($transaksi_list);
?>
    <div class="col-12 col-lg-4">
        <button type="button" class="rek-card" data-bs-toggle="modal" data-bs-target="#riwayatRek<?php echo $id_rekening; ?>">
            <div class="rek-head" style="background:<?php echo $bg; ?>;">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div class="min-w-0">
                        <div class="small opacity-75"><?php echo htmlspecialchars($rekening['_desk_badge']); ?></div>
                        <div class="fw-bold"><?php echo htmlspecialchars($rekening['_display_nama']); ?></div>
                    </div>
                    <i class="bi bi-<?php echo $ikon; ?>" style="font-size:1.4rem; opacity:0.85;"></i>
                </div>
                <div class="rek-saldo mt-3">Rp <?php echo number_format($saldo_rek, 0, ',', '.'); ?></div>
            </div>
            <div class="rek-hint">
                <span><?php echo $jumlah_riwayat; ?> transaksi</span>
                <span>Lihat riwayat <i class="bi bi-chevron-right"></i></span>
            </div>
        </button>
    </div>

    <div class="modal fade rek-modal" id="riwayatRek<?php echo $id_rekening; ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header border-0 text-white" style="background:<?php echo $bg; ?>;">
                    <div>
                        <div class="small opacity-75"><?php echo htmlspecialchars($rekening['_desk_badge']); ?></div>
                        <h5 class="modal-title fw-bold mb-0"><?php echo htmlspecialchars($rekening['_display_nama']); ?></h5>
                        <div class="fw-bold mt-1">Rp <?php echo number_format($saldo_rek, 0, ',', '.'); ?></div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="modal-body px-4">
                    <?php if (!$transaksi_list): ?>
                        <div class="text-center text-muted py-4">
                            <i class="bi bi-inbox d-block mb-2" style="font-size:1.6rem;"></i>
                            Belum ada riwayat di rekening ini.
                        </div>
                    <?php else: foreach ($transaksi_list as $t):
                        $ket = $t['keterangan'] ?: labelTipeTransaksi($t['tipe_transaksi']);
                        $masuk = $t['tipe_transaksi'] === 'debit';
                    ?>
                        <div class="rek-row">
                            <div class="min-w-0">
                                <div class="fw-semibold"><?php echo htmlspecialchars($ket); ?></div>
                                <div class="small text-muted mt-1">
                                    <?php echo date('d M Y', strtotime($t['tanggal'])); ?>
                                    · <?php echo $masuk ? 'Masuk' : 'Keluar'; ?>
                                </div>
                                <div class="mt-1"><?php echo asalTransaksiLabel($t); ?></div>
                            </div>
                            <div class="fw-bold flex-shrink-0 text-end" style="color:<?php echo $masuk ? '#047857' : '#991B1B'; ?>;">
                                <?php echo $masuk ? '+' : '−'; ?>Rp <?php echo number_format($t['jumlah'], 0, ',', '.'); ?>
                            </div>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>
    </div>
<?php endforeach; ?>
</div>

<?php include '../includes/footer.php'; ?>

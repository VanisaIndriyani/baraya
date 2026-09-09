<?php
require_once '../includes/header.php';
require_once '../includes/finance-utils.php';

financeEnsureBiayaWajibTable($pdo);
financeEnsureAccountSystem($pdo);

$float_kasir = $pdo->query("SELECT * FROM float_kasir ORDER BY id DESC LIMIT 1")->fetch();
if (!$float_kasir) {
    $pdo->exec("INSERT INTO float_kasir (saldo_awal, saldo_sekarang) VALUES (500000, 500000)");
    $float_kasir = $pdo->query("SELECT * FROM float_kasir ORDER BY id DESC LIMIT 1")->fetch();
}

$today = date('Y-m-d');
$id_user = $_SESSION['user_id'] ?? null;

$msg = '';
$msg_type = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    if (isset($_POST['aksi']) && $_POST['aksi'] === 'tambah') {
        $tanggal = $_POST['tanggal'] ?? $today;
        $uang_awal = (float) ($_POST['uang_awal'] ?? 0);
        $uang_akhir = (float) ($_POST['uang_akhir'] ?? 0);
        $keter = trim($_POST['keter'] ?? '');

        $nominal_sistem = $uang_awal;
        $nominal_fisik = $uang_akhir;
        $selisih = $nominal_fisik - $nominal_sistem;

        $stmt = $pdo->prepare("INSERT INTO float_kasir_shift (tanggal, shift, nominal_sistem, nominal_fisik, selisih, keter, id_user) VALUES (?,?,?,?,?,?,?)");
        $stmt->execute([$tanggal, null, $nominal_sistem, $nominal_fisik, $selisih, $keter ?: null, $id_user]);

        $stmt_upd = $pdo->prepare("INSERT INTO float_kasir (saldo_awal, saldo_sekarang) VALUES (?, ?)");
        $stmt_upd->execute([$uang_awal, $uang_akhir]);

        $label_selisih = $selisih > 0 ? "LEBIH Rp " . number_format($selisih,0,',','.') : ($selisih < 0 ? "KURANG Rp " . number_format(abs($selisih),0,',','.') : "PAS (Rp 0 selisih)");
        $msg = "✅ <b>Tambah Berhasil!</b><br>Catatan Uang Kasir " . date('d M Y', strtotime($tanggal)) . " tersimpan.<br>Awal Rp " . number_format($uang_awal,0,',','.') . " &rarr; Akhir Rp " . number_format($uang_akhir,0,',','.') . "<br>Selisih: " . $label_selisih;
        $msg_type = ($selisih >= 0) ? 'success' : 'warning';
    }

    if (isset($_POST['aksi']) && $_POST['aksi'] === 'edit') {
        $id = (int) $_POST['edit_id'];
        $tanggal = $_POST['tanggal'] ?? $today;
        $uang_awal = (float) ($_POST['uang_awal'] ?? 0);
        $uang_akhir = (float) ($_POST['uang_akhir'] ?? 0);
        $keter = trim($_POST['keter'] ?? '');

        $nominal_sistem = $uang_awal;
        $nominal_fisik = $uang_akhir;
        $selisih = $nominal_fisik - $nominal_sistem;

        $stmt = $pdo->prepare("UPDATE float_kasir_shift SET tanggal=?, nominal_sistem=?, nominal_fisik=?, selisih=?, keter=? WHERE id=? LIMIT 1");
        $stmt->execute([$tanggal, $nominal_sistem, $nominal_fisik, $selisih, $keter ?: null, $id]);

        $stmt_upd = $pdo->prepare("INSERT INTO float_kasir (saldo_awal, saldo_sekarang) VALUES (?, ?)");
        $stmt_upd->execute([$uang_awal, $uang_akhir]);

        $label_selisih = $selisih > 0 ? "LEBIH Rp " . number_format($selisih,0,',','.') : ($selisih < 0 ? "KURANG Rp " . number_format(abs($selisih),0,',','.') : "PAS (Rp 0 selisih)");
        $msg = "✅ <b>Edit Berhasil!</b><br>Catatan ID #$id di-update.<br>" . date('d M Y', strtotime($tanggal)) . " | Awal Rp " . number_format($uang_awal,0,',','.') . " &rarr; Akhir Rp " . number_format($uang_akhir,0,',','.') . "<br>Selisih: " . $label_selisih;
        $msg_type = 'success';
    }

    if (isset($_POST['hapus_id'])) {
        $id = (int) $_POST['hapus_id'];
        $stmt = $pdo->prepare("DELETE FROM float_kasir_shift WHERE id=? LIMIT 1");
        $stmt->execute([$id]);
        $msg = "🗑️ <b>Catatan Dihapus!</b><br>Catatan uang kasir ID #$id berhasil dihapus permanen.";
        $msg_type = 'danger';
    }

    if (isset($_POST['aksi']) && $_POST['aksi'] === 'update_saldo' && !empty($_POST['saldo_awal']) && !empty($_POST['saldo_sekarang'])) {
        $saldo_awal = (float) $_POST['saldo_awal'];
        $saldo_sekarang = (float) $_POST['saldo_sekarang'];
        $stmt = $pdo->prepare("INSERT INTO float_kasir (saldo_awal, saldo_sekarang) VALUES (?, ?)");
        $stmt->execute([$saldo_awal, $saldo_sekarang]);
        $msg = "✅ Manual Update saldo float berhasil disimpan!";
        $msg_type = 'success';
    }

    $float_kasir = $pdo->query("SELECT * FROM float_kasir ORDER BY id DESC LIMIT 1")->fetch();

    echo "<script>
        Swal.fire({
            icon: '" . ($msg_type === 'danger' ? 'warning' : ($msg_type === 'warning' ? 'warning' : 'success')) . "',
            title: 'Sukses',
            html: " . json_encode($msg) . ",
            timer: 2500
        }).then(() => window.location.href = window.location.pathname);
    </script>";
    exit;
}

$riwayat = [];
$filter_tanggal_dari = trim($_GET['filter_tanggal_dari'] ?? '');
$filter_tanggal_sampai = trim($_GET['filter_tanggal_sampai'] ?? '');

$where_clauses = [];
$bind_params = [];

if ($filter_tanggal_dari && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_tanggal_dari)) {
    $where_clauses[] = "tanggal >= ?";
    $bind_params[] = $filter_tanggal_dari;
}
if ($filter_tanggal_sampai && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_tanggal_sampai)) {
    $where_clauses[] = "tanggal <= ?";
    $bind_params[] = $filter_tanggal_sampai;
}

$sql = "SELECT * FROM float_kasir_shift";
if ($where_clauses) {
    $sql .= " WHERE " . implode(" AND ", $where_clauses);
}
$sql .= " ORDER BY tanggal DESC, id DESC LIMIT " . ($where_clauses ? 500 : 20);

$stmt_riwayat = $pdo->prepare($sql);
$stmt_riwayat->execute($bind_params ?: null);
$riwayat = $stmt_riwayat->fetchAll();

$ada_filter_aktif = ($filter_tanggal_dari || $filter_tanggal_sampai);
$reset_filter_url = $base_url . '/pages/float-kasir.php';

function fmtTanggalIndo($t) {
    return date('d M Y', strtotime($t));
}
?>

<div class="page-header">
    <a href="<?php echo $base_url; ?>/admin_dashboard.php" class="text-white text-decoration-none mb-2 d-inline-block"><i class="bi bi-arrow-left"></i> Kembali</a>
    <h4 class="mb-0"><i class="bi bi-cash-coin me-2"></i>Uang Kasir (Khusus Kembalian)</h4>
</div>

<?php if ($msg): ?>
<div class="alert alert-<?php echo $msg_type === 'danger' ? 'danger' : $msg_type; ?> mb-3 rounded-3 shadow-sm"><i class="bi bi-check2-circle me-2"></i><?php echo $msg; ?></div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-xl-4">
        <div class="card rounded-4 border-0 shadow-sm p-3 h-100" style="background: linear-gradient(135deg, #fff7ed 0%, #ffedd5 100%); border-left: 6px solid #f97316 !important;">
            <div class="card-body py-1">
                <h6 class="mb-1 fw-bold" style="color:#ea580c;"><i class="bi bi-wallet-fill me-2"></i>Saldo Uang Kembalian Saat Ini</h6>
                <div class="display-6 fw-bold mb-1 text-dark" style="font-family: ui-monospace, Menlo, monospace;">Rp <?php echo number_format((float) $float_kasir['saldo_sekarang'],0,',','.'); ?></div>
                <div class="small text-muted mb-3"><i class="bi bi-info-circle me-1"></i>Saldo master terakhir di sistem.</div>
                <div class="row g-2">
                    <div class="col-6">
                        <div class="bg-white rounded-3 p-2 text-center border">
                            <div class="small text-muted">Awal Hari</div>
                            <div class="fw-bold" style="color:#ea580c;">Rp <?php echo number_format((float) $float_kasir['saldo_awal'],0,',','.'); ?></div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="bg-white rounded-3 p-2 text-center border">
                            <div class="small text-muted">Saldo Kini</div>
                            <div class="fw-bold text-primary">Rp <?php echo number_format((float) $float_kasir['saldo_sekarang'],0,',','.'); ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-8">
        <div class="card rounded-4 border-0 shadow-sm h-100 overflow-hidden">
            <div class="card-header py-3 text-white d-flex justify-content-between align-items-center flex-wrap gap-2" style="background: linear-gradient(135deg, #0f766e, #0891b2);">
                <div>
                    <h5 class="mb-0 fw-bold"><i class="bi bi-journal-check me-2"></i>📝 CRUD UANG KEMBALIAN</h5>
                    <div class="small text-white-50 mt-1">Form untuk <span id="crud-mode-label">TAMBAH CATATAN BARU</span>. Edit/Hapus manual via tombol di tabel riwayat ✨</div>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" id="btn-reset-form" class="btn btn-light text-teal rounded-3 fw-bold border-0 shadow-sm"><i class="bi bi-arrow-counterclockwise me-1"></i> Reset Form</button>
                </div>
            </div>
            <div class="card-body">
                <form id="crud-form" method="POST" class="needs-validation">
                    <input type="hidden" name="aksi" id="crud-aksi" value="tambah">
                    <input type="hidden" name="edit_id" id="crud-edit-id" value="">

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label small fw-bold mb-1"><i class="bi bi-calendar3 me-1 text-primary"></i> Tanggal</label>
                            <input type="date" class="form-control rounded-3 form-control-lg" id="crud-tanggal" name="tanggal" value="<?php echo $today; ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold mb-1" style="color:#ea580c;"><i class="bi bi-cash-stack me-1"></i> UANG AWAL</label>
                            <input type="number" class="form-control rounded-3 form-control-lg" id="crud-awal" name="uang_awal" value="<?php echo (float) $float_kasir['saldo_awal']; ?>" placeholder="cth: 500000" required>
                            <div class="form-text small">Uang kembalian yang ada di laci saat awal pencatatan.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold mb-1 text-primary"><i class="bi bi-cash-coin me-1"></i> UANG AKHIR</label>
                            <input type="number" class="form-control rounded-3 form-control-lg" id="crud-akhir" name="uang_akhir" placeholder="cth: 495000" required>
                            <div class="form-text small">Uang kembalian yang dihitung saat akhir (setelah selesai transaksi).</div>
                        </div>
                    </div>
                    <div class="row g-3 mt-1 align-items-end">
                        <div class="col-md-8">
                            <label class="form-label small fw-bold mb-1">Catatan (opsional)</label>
                            <input type="text" class="form-control rounded-3" id="crud-keter" name="keter" placeholder="cth: Kurang 5rb salah kembalian ke customer A">
                        </div>
                        <div class="col-md-4 d-grid">
                            <button type="submit" id="crud-submit" class="btn btn-lg text-white fw-bold rounded-3 shadow-sm" style="background: linear-gradient(135deg, #0f766e, #0891b2);"><i class="bi bi-save2-fill me-2"></i><span id="crud-btn-label">TAMBAH CATATAN</span></button>
                        </div>
                    </div>
                    <div class="mt-3 p-3 rounded-3" style="background:#ecfeff;">
                        <div class="small fw-bold mb-1" style="color:#0e7490;"><i class="bi bi-lightbulb-fill me-1"></i> PETUNJUK SINGKAT CRUD MANUAL:</div>
                        <div class="small text-muted mb-0 row g-2">
                            <div class="col-md-6">
                                <b>📗 CREATE</b> (Tambah): Isi semua field → Klik [TAMBAH CATATAN]<br>
                                <b>📘 READ</b> (Lihat): Tabel Riwayat di bawah ini menampilkan semua data.
                            </div>
                            <div class="col-md-6">
                                <b>📙 UPDATE</b> (Edit): Klik icon <span class="text-primary">pencil</span> di kanan baris data.<br>
                                <b>📕 DELETE</b> (Hapus): Klik icon <span class="text-danger">trash</span> → Konfirmasi Hapus → OK.
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="card rounded-4 border-0 shadow-sm mb-4">
    <div class="card-header bg-white border-bottom py-3">
        <div class="d-flex align-items-start justify-content-between flex-wrap gap-3 mb-3">
            <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-clock-history me-2 text-primary"></i>Riwayat Catatan Uang Kembalian<?php echo $ada_filter_aktif ? ' (Hasil Filter)' : ' (20 Terakhir)'; ?></h5>
            <div class="d-flex gap-2 align-items-center flex-wrap">
                <button type="button" id="btn-quick-tambah" class="btn btn-primary py-2 px-3 rounded-3 fw-bold shadow-sm"><i class="bi bi-plus-lg me-1"></i>➕ Tambah Catatan</button>
                <span class="badge bg-success py-2 px-3 rounded-3"><i class="bi bi-arrow-up-right me-1"></i>LEBIH = Hijau</span>
                <span class="badge bg-danger py-2 px-3 rounded-3"><i class="bi bi-arrow-down-right me-1"></i>KURANG = Merah</span>
                <span class="badge bg-secondary py-2 px-3 rounded-3"><i class="bi bi-dash me-1"></i>PAS / 0 = Abu</span>
            </div>
        </div>
        <div class="p-3 rounded-3" style="background: #f8fafc;">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small fw-bold mb-1"><i class="bi bi-calendar3 me-1 text-primary"></i> Tanggal Mulai</label>
                    <input type="date" class="form-control rounded-3" name="filter_tanggal_dari" value="<?php echo htmlspecialchars($filter_tanggal_dari); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-bold mb-1"><i class="bi bi-calendar-check me-1 text-primary"></i> Sampai Tanggal</label>
                    <input type="date" class="form-control rounded-3" name="filter_tanggal_sampai" value="<?php echo htmlspecialchars($filter_tanggal_sampai); ?>">
                </div>
                <div class="col-md-4 d-flex gap-2">
                    <button type="submit" class="btn btn-primary rounded-3 fw-bold w-100"><i class="bi bi-funnel-fill me-1"></i> FILTER TANGGAL</button>
                    <?php if ($ada_filter_aktif): ?>
                    <a href="<?php echo htmlspecialchars($reset_filter_url); ?>" class="btn btn-outline-secondary rounded-3 fw-bold text-nowrap"><i class="bi bi-x-circle me-1"></i>RESET</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
        <?php if ($ada_filter_aktif): ?>
        <div class="mt-3 mb-0 small d-flex gap-2 flex-wrap align-items-center">
            <i class="bi bi-info-circle-fill text-primary me-1"></i>
            <span class="text-muted">Filter aktif:</span>
            <?php if ($filter_tanggal_dari): ?><span class="badge bg-primary py-1 px-2 rounded-2">Tgl Mulai: <?php echo fmtTanggalIndo($filter_tanggal_dari); ?></span><?php endif; ?>
            <?php if ($filter_tanggal_sampai): ?><span class="badge bg-primary py-1 px-2 rounded-2">Sampai: <?php echo fmtTanggalIndo($filter_tanggal_sampai); ?></span><?php endif; ?>
            <span class="badge bg-dark py-1 px-2 rounded-2 ms-auto">Ditemukan: <?php echo count($riwayat); ?> data</span>
        </div>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead style="background: #f8fafc;">
                    <tr class="text-muted small">
                        <th class="border-0 ps-4 py-3">TANGGAL</th>
                        <th class="border-0 py-3 text-end">UANG AWAL</th>
                        <th class="border-0 py-3 text-end">UANG AKHIR</th>
                        <th class="border-0 py-3 text-end">SELISIH</th>
                        <th class="border-0 py-3">CATATAN</th>
                        <th class="border-0 pe-4 py-3 text-center" style="min-width: 120px;">AKSI</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$riwayat): ?>
                    <tr>
                        <td colspan="6" class="text-center py-5 text-muted">
                            <i class="bi bi-inbox" style="font-size: 3rem; opacity: .3;"></i><br>
                            <div class="mt-2 mb-3">Belum ada catatan sama sekali.<br>Klik tombol <b>➕ Tambah Catatan</b> hijau di atas untuk input data pertama ✨</div>
                            <button type="button" id="btn-empty-tambah" class="btn btn-primary rounded-3 fw-bold shadow-sm"><i class="bi bi-plus-lg me-2"></i>Tambah Catatan Pertama</button>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($riwayat as $r):
                        $selisih = (float) $r['selisih'];
                        if ($selisih > 0) {
                            $badge_class = 'bg-success';
                            $badge_ikon = '<i class="bi bi-arrow-up-right me-1"></i>LEBIH';
                        } elseif ($selisih < 0) {
                            $badge_class = 'bg-danger';
                            $badge_ikon = '<i class="bi bi-arrow-down-right me-1"></i>KURANG';
                            $selisih = abs($selisih);
                        } else {
                            $badge_class = 'bg-secondary';
                            $badge_ikon = '<i class="bi bi-check2 me-1"></i>PAS';
                        }
                        $id = (int) $r['id'];
                        $tgl = htmlspecialchars($r['tanggal']);
                        $awal = (float) $r['nominal_sistem'];
                        $akhir = (float) $r['nominal_fisik'];
                        $ket = htmlspecialchars($r['keter'] ?? '');
                    ?>
                    <tr>
                        <td class="ps-4 py-3 fw-medium">
                            <?php echo fmtTanggalIndo($r['tanggal']); ?>
                            <div class="small text-muted">#ID <?php echo $id; ?> · <?php echo date('H:i', strtotime($r['created_at'])); ?> WIB</div>
                        </td>
                        <td class="py-3 text-end fw-medium">Rp <?php echo number_format($awal,0,',','.'); ?></td>
                        <td class="py-3 text-end fw-bold">Rp <?php echo number_format($akhir,0,',','.'); ?></td>
                        <td class="py-3 text-end">
                            <span class="badge <?php echo $badge_class; ?> py-2 px-3 rounded-3 text-nowrap">
                                <?php echo $badge_ikon; ?> Rp <?php echo number_format((float) $selisih,0,',','.'); ?>
                            </span>
                        </td>
                        <td class="py-3 small text-muted"><?php echo ($ket !== '') ? $ket : '<span class="text-muted opacity-75">—</span>'; ?></td>
                        <td class="py-3 pe-4 text-center">
                            <div class="d-flex gap-1 justify-content-center">
                                <button type="button" class="action-btn edit btn btn-sm btn-outline-primary rounded-3 px-3" onclick="fkasir_edit(<?php echo $id; ?>, '<?php echo $tgl; ?>', <?php echo $awal; ?>, <?php echo $akhir; ?>, '<?php echo str_replace("'", "\\'", $ket); ?>')"><i class="bi bi-pencil me-1"></i>Edit</button>
                                <button type="button" class="action-btn delete btn btn-sm btn-outline-danger rounded-3 px-3" onclick="fkasir_hapus(<?php echo $id; ?>)"><i class="bi bi-trash me-1"></i>Hapus</button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<form id="form-hapus" method="POST" class="d-none">
    <input type="hidden" name="hapus_id" id="input-hapus-id" value="">
</form>

<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="alert alert-danger" role="alert" style="border-radius: 1rem;">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <b>INI UANG KHUSUS KEMBALIAN SAJA.</b><br>
            JANGAN pakai uang ini untuk beli bahan, bayar listrik, atau belanja apapun! Semua belanja &amp; pembayaran pakai <b>Kas Operasional</b>, bukan Uang Kasir ini.
        </div>
    </div>
    <div class="col-md-6">
        <div class="alert alert-info" role="alert" style="border-radius: 1rem;">
            <i class="bi bi-info-circle-fill me-2"></i>
            <b>Tips CRUD:</b> Jika salah input angka → klik icon <span class="text-primary">Edit biru</span> di baris itu, perbaiki fieldnya, lalu submit. Jika data salah total &amp; ingin buang → <span class="text-danger">Hapus merah</span> + confirm SweetAlert.
        </div>
    </div>
</div>

<div class="card rounded-4 border-0 shadow-sm mb-4">
    <div class="card-header bg-white border-bottom py-3">
        <h6 class="mb-0 fw-bold text-muted"><i class="bi bi-tools me-2"></i>⚙️ Pengaturan Manual (Update Master Saldo — darurat saja)</h6>
    </div>
    <div class="card-body">
        <form method="POST" class="row g-3 align-items-end">
            <input type="hidden" name="aksi" value="update_saldo">
            <div class="col-md-4">
                <label class="form-label small fw-bold mb-1">Saldo Awal (default master)</label>
                <input type="number" class="form-control rounded-3" name="saldo_awal" value="<?php echo (float) $float_kasir['saldo_awal']; ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-bold mb-1">Saldo Sekarang (master)</label>
                <input type="number" class="form-control rounded-3" name="saldo_sekarang" value="<?php echo (float) $float_kasir['saldo_sekarang']; ?>" required>
            </div>
            <div class="col-md-4 d-grid">
                <button type="submit" class="btn btn-outline-secondary rounded-3 fw-bold"><i class="bi bi-arrow-repeat me-2"></i>UPDATE SALDO MASTER</button>
            </div>
        </form>
    </div>
</div>

<script>
const FLOAT_DEFAULT_AWAL = <?php echo (float) $float_kasir['saldo_awal']; ?>;
const FLOAT_TODAY = '<?php echo $today; ?>';

function fkasir_reset() {
    document.getElementById('crud-aksi').value = 'tambah';
    document.getElementById('crud-edit-id').value = '';
    document.getElementById('crud-mode-label').innerHTML = '<b class="text-success">TAMBAH CATATAN BARU</b>';
    document.getElementById('crud-btn-label').textContent = 'TAMBAH CATATAN';
    document.getElementById('crud-submit').style.background = 'linear-gradient(135deg, #0f766e, #0891b2)';
    document.getElementById('crud-tanggal').value = FLOAT_TODAY;
    document.getElementById('crud-awal').value = FLOAT_DEFAULT_AWAL;
    document.getElementById('crud-akhir').value = '';
    document.getElementById('crud-keter').value = '';
    window.scrollTo({top: 0, behavior: 'smooth'});
}

function fkasir_edit(id, tgl, awal, akhir, keter) {
    document.getElementById('crud-aksi').value = 'edit';
    document.getElementById('crud-edit-id').value = id;
    document.getElementById('crud-mode-label').innerHTML = '<b class="text-warning">SEDANG EDIT ID #' + id + '</b>';
    document.getElementById('crud-btn-label').textContent = 'UPDATE CATATAN';
    document.getElementById('crud-submit').style.background = 'linear-gradient(135deg, #f97316, #ea580c)';
    document.getElementById('crud-tanggal').value = tgl;
    document.getElementById('crud-awal').value = awal;
    document.getElementById('crud-akhir').value = akhir;
    document.getElementById('crud-keter').value = keter;
    window.scrollTo({top: 0, behavior: 'smooth'});
    showAlert('info', 'Mode Edit', 'Form sudah diisi data catatan ID #' + id + '. Silakan ubah fieldnya lalu klik UPDATE CATATAN.', 2500);
}

function fkasir_hapus(id) {
    Swal.fire({
        title: 'Hapus Catatan ID #' + id + '?',
        text: 'Data ini akan dihapus PERMANEN dari riwayat uang kembalian, tidak bisa dikembalikan lagi!',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Ya, HAPUS PERMANEN!',
        cancelButtonText: 'Batal'
    }).then((result) => {
        if (result.isConfirmed) {
            document.getElementById('input-hapus-id').value = id;
            document.getElementById('form-hapus').submit();
        }
    });
}

document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('btn-reset-form').addEventListener('click', fkasir_reset);
    document.getElementById('btn-quick-tambah').addEventListener('click', fkasir_reset);
    if (document.getElementById('btn-empty-tambah')) {
        document.getElementById('btn-empty-tambah').addEventListener('click', fkasir_reset);
    }
});
</script>

<?php include '../includes/footer.php'; ?>

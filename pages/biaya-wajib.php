<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once '../includes/header.php';
require_once '../includes/finance-utils.php';

$redirect_url = $base_url . '/pages/biaya-wajib.php';
financeEnsureBiayaWajibTable($pdo);
financeEnsureAccountSystem($pdo);

function tampilkanAlertBiayaWajib($icon, $title, $text, $redirect = null, $timer = 1500)
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'simpan_ruko') {
            $nominal_per_hari = financeNormalisasiNominal($_POST['nominal_per_hari'] ?? 0);
            $is_active = isset($_POST['is_active']) ? 1 : 0;

            if ($nominal_per_hari < 0) {
                throw new Exception('Nominal tidak valid.');
            }

            financeSaveUangRukoSetting($pdo, $nominal_per_hari, $is_active);
            tampilkanAlertBiayaWajib('success', 'Berhasil', 'Setting Uang Ruko berhasil disimpan!', $redirect_url);
        }

        if ($action === 'tambah_biaya') {
            $nama_biaya = trim($_POST['nama_biaya'] ?? '');
            $nominal_bulanan = financeNormalisasiNominal($_POST['nominal_bulanan'] ?? 0);
            $frekuensi = in_array($_POST['frekuensi'] ?? '', ['bulanan','mingguan','setiap_n_hari','harian'], true)
                ? $_POST['frekuensi']
                : 'bulanan';
            $setiap_n_hari = null;
            if ($frekuensi === 'setiap_n_hari') {
                $setiap_n_hari = max(1, (int) ($_POST['setiap_n_hari'] ?? 1));
            }
            $keterangan = trim($_POST['keterangan'] ?? '');
            $is_active = isset($_POST['is_active']) ? 1 : 0;

            if ($nama_biaya === '' || $nominal_bulanan <= 0) {
                throw new Exception('Nama biaya dan nominal wajib diisi.');
            }
            if ($frekuensi === 'setiap_n_hari' && (!$setiap_n_hari || $setiap_n_hari < 1)) {
                throw new Exception('Pilih jumlah hari untuk frekuensi ini.');
            }

            $stmt = $pdo->prepare("INSERT INTO biaya_wajib (nama_biaya, nominal_bulanan, frekuensi, setiap_n_hari, keterangan, is_active) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$nama_biaya, $nominal_bulanan, $frekuensi, $setiap_n_hari, $keterangan, $is_active]);
            tampilkanAlertBiayaWajib('success', 'Berhasil', 'Biaya wajib berhasil ditambahkan.', $redirect_url);
        }

        if ($action === 'edit_biaya') {
            $id = (int) ($_POST['id'] ?? 0);
            $nama_biaya = trim($_POST['nama_biaya'] ?? '');
            $nominal_bulanan = financeNormalisasiNominal($_POST['nominal_bulanan'] ?? 0);
            $frekuensi = in_array($_POST['frekuensi'] ?? '', ['bulanan','mingguan','setiap_n_hari','harian'], true)
                ? $_POST['frekuensi']
                : 'bulanan';
            $setiap_n_hari = null;
            if ($frekuensi === 'setiap_n_hari') {
                $setiap_n_hari = max(1, (int) ($_POST['setiap_n_hari'] ?? 1));
            }
            $keterangan = trim($_POST['keterangan'] ?? '');
            $is_active = isset($_POST['is_active']) ? 1 : 0;

            if ($id <= 0 || $nama_biaya === '' || $nominal_bulanan <= 0) {
                throw new Exception('Data biaya wajib belum lengkap.');
            }
            if ($frekuensi === 'setiap_n_hari' && (!$setiap_n_hari || $setiap_n_hari < 1)) {
                throw new Exception('Pilih jumlah hari untuk frekuensi ini.');
            }

            $stmt = $pdo->prepare("UPDATE biaya_wajib SET nama_biaya = ?, nominal_bulanan = ?, frekuensi = ?, setiap_n_hari = ?, keterangan = ?, is_active = ? WHERE id = ?");
            $stmt->execute([$nama_biaya, $nominal_bulanan, $frekuensi, $setiap_n_hari, $keterangan, $is_active, $id]);
            tampilkanAlertBiayaWajib('success', 'Berhasil', 'Biaya wajib berhasil diperbarui.', $redirect_url);
        }

        if ($action === 'hapus_biaya') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new Exception('Data biaya wajib tidak ditemukan.');
            }

            $stmt = $pdo->prepare("DELETE FROM biaya_wajib WHERE id = ?");
            $stmt->execute([$id]);
            tampilkanAlertBiayaWajib('success', 'Berhasil', 'Biaya wajib berhasil dihapus.', $redirect_url);
        }
    } catch (Exception $e) {
        tampilkanAlertBiayaWajib('error', 'Gagal', $e->getMessage(), null, 3000);
    }
}

$biaya_wajib = $pdo->query("SELECT * FROM biaya_wajib ORDER BY is_active DESC, nama_biaya ASC")->fetchAll();
$ringkasan_hari_ini = financeGetBiayaWajibSummary($pdo, date('Y-m-d'));
$ringkasan_ruko = financeGetUangRukoDailySummary($pdo, date('Y-m-d'));
$setting_ruko = $ringkasan_ruko['setting'];
$rekening_operasional = financeGetAccountByCode($pdo, 'operasional');
$rekening_ruko = financeGetAccountByCode($pdo, 'uang_ruko');
?>

<div class="page-header">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3 w-100">
        <div>
            <a href="<?php echo $base_url; ?>/admin_dashboard.php" class="text-white text-decoration-none mb-2 d-inline-block opacity-90"><i class="bi bi-arrow-left"></i> Kembali</a>
            <h4 class="mb-0"><i class="bi bi-calendar2-check me-2"></i>Biaya Rutin & Alokasi Wajib</h4>
        </div>
        <button type="button" class="btn btn-light text-emerald" data-bs-toggle="modal" data-bs-target="#tambahBiayaModal">
            <i class="bi bi-plus-lg me-2"></i>Tambah Biaya Rutin
        </button>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-md-6 col-xl">
        <div class="stat-card" style="background: linear-gradient(135deg, #FEE2E2 0%, #FECACA 100%); color: #991B1B;">
            <div class="d-flex justify-content-between align-items-start gap-3">
                <div>
                    <div class="stat-label">Uang Ruko (Target / Hari)</div>
                    <h3 class="stat-value">Rp <?php echo number_format($ringkasan_ruko['target_harian'] ?? 0, 0, ',', '.'); ?></h3>
                    <small class="fw-medium d-block">
                        <?php echo $setting_ruko['is_active'] ? '<i class="bi bi-check-circle-fill me-1"></i>Wajib Aktif' : '<i class="bi bi-pause-circle me-1"></i>Nonaktif'; ?>
                    </small>
                </div>
                <i class="bi bi-building-fill-lock stat-icon" style="color:#991B1B"></i>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-xl">
        <div class="stat-card" style="background: linear-gradient(135deg, #FECDD3 0%, #FDA4AF 100%); color: #881337;">
            <div class="d-flex justify-content-between align-items-start gap-3">
                <div>
                    <div class="stat-label">Saldo Uang Ruko Saat Ini</div>
                    <h3 class="stat-value">Rp <?php echo number_format($ringkasan_ruko['saldo_rekening'] ?? 0, 0, ',', '.'); ?></h3>
                    <small class="fw-medium d-block">Teralokasi hari ini: Rp <?php echo number_format($ringkasan_ruko['teralokasi_hari_ini'] ?? 0, 0, ',', '.'); ?></small>
                </div>
                <i class="bi bi-piggy-bank-fill stat-icon" style="color:#881337"></i>
            </div>
        </div>
    </div>
    <div class="col-md-4 col-xl">
        <div class="stat-card" style="background: linear-gradient(135deg,#DBEAFE 0%,#BFDBFE 100%); color: #1E40AF;">
            <div class="d-flex justify-content-between align-items-start gap-3">
                <div>
                    <div class="stat-label">Target Kas Operasional / Hari</div>
                    <h3 class="stat-value">Rp <?php echo number_format($ringkasan_hari_ini['target_harian'], 0, ',', '.'); ?></h3>
                    <small class="fw-medium"><?php echo (int) $ringkasan_hari_ini['jumlah_hari_bulan']; ?> hari / bulan ini</small>
                </div>
                <i class="bi bi-safe-fill stat-icon" style="color:#1E40AF"></i>
            </div>
        </div>
    </div>
 
    <div class="col-md-4 col-xl">
        <div class="stat-card" style="background: linear-gradient(135deg,#D1FAE5 0%,#A7F3D0 100%); color: #065F46;">
            <div class="d-flex justify-content-between align-items-start gap-3">
                <div>
                    <div class="stat-label">Kas Operasional</div>
                    <h3 class="stat-value">Rp <?php echo number_format($rekening_operasional['saldo'] ?? 0, 0, ',', '.'); ?></h3>
                    <small class="fw-medium">Teralokasi hari ini: Rp <?php echo number_format($ringkasan_hari_ini['teralokasi_hari_ini'], 0, ',', '.'); ?></small>
                </div>
                <i class="bi bi-wallet2 stat-icon" style="color:#065F46"></i>
            </div>
        </div>
    </div>
</div>

<!-- SECTION KHUSUS UANG RUKO -->
<div class="card border-0 shadow-sm rounded-4 mb-4 overflow-hidden">
    <div class="card-header border-0 py-3 px-4" style="background: linear-gradient(135deg,#7F1D1D 0%,#991B1B 100%); color:#ffffff;">
        <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap">
            <div>
                <h5 class="mb-0 fw-bold text-white">
                    <i class="bi bi-building-fill-lock me-2"></i>Uang Ruko Wajib
                </h5>
                <small class="fw-medium d-block text-white">Dipotong DULU dari setiap penjualan (urutan #1).</small>
            </div>
            <button type="button" class="btn btn-light btn-sm fw-semibold" data-bs-toggle="collapse" data-bs-target="#collapseRuko">
                <i class="bi bi-pencil-square me-2"></i>Edit Setting
            </button>
        </div>
    </div>
    <div class="card-body p-4">

        <!-- Progress Bar -->
        <?php if ($ringkasan_ruko['target_harian'] > 0): ?>
        <div class="mb-4">
            <div class="d-flex justify-content-between small text-muted mb-2">
                <span>Progress pencapaian hari ini</span>
                <span class="fw-semibold"><?php echo number_format(min(100, ($ringkasan_ruko['teralokasi_hari_ini'] / $ringkasan_ruko['target_harian']) * 100), 1); ?>%</span>
            </div>
            <div class="progress" style="height:14px;">
                <div class="progress-bar progress-bar-striped progress-bar-animated bg-danger" style="width: <?php echo min(100, ($ringkasan_ruko['teralokasi_hari_ini'] / $ringkasan_ruko['target_harian']) * 100); ?>%"></div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Collapse Form Edit -->
        <div class="collapse" id="collapseRuko">
            <form method="POST" class="mt-2 pt-3 border-top">
                <input type="hidden" name="action" value="simpan_ruko">
                <div class="row g-3 align-items-end">
                    <div class="col-md-5">
                        <label class="form-label fw-semibold small">Nominal Uang Ruko / Hari</label>
                        <div class="input-group input-group-lg">
                            <span class="input-group-text bg-light fw-semibold">Rp</span>
                            <input type="number" name="nominal_per_hari" class="form-control form-control-lg" min="0" step="0.01" value="<?php echo number_format($setting_ruko['nominal_per_hari'], 2, '.', ''); ?>" required>
                        </div>
                    </div>
                    <div class="col-md-5">
                        <div class="form-check form-switch p-4 pe-5 border rounded-4 h-100 d-flex align-items-center justify-content-between cursor-pointer transition-all" style="background: linear-gradient(135deg, #ECFDF5 0%, #D1FAE5 100%); border-color: #A7F3D0 !important;">
                            <div class="flex-grow-1 pe-3">
                                <label class="form-check-label fw-bold text-emerald mb-1 d-block" for="rukoAktif" style="font-size: 1.05rem;">
                                    <i class="bi bi-shield-check me-1"></i>Aktifkan Uang Ruko Wajib
                                </label>
                                <small class="text-muted opacity-75">Potong otomatis di urutan #1 setiap penjualan</small>
                            </div>
                            <input class="form-check-input form-check-input-lg me-0 cursor-pointer" style="width: 3.2rem; height: 1.7rem; accent-color: #10B981;" type="checkbox" name="is_active" id="rukoAktif" <?php echo $setting_ruko['is_active'] ? 'checked' : ''; ?>>
                        </div>
                    </div>
                    <div class="col-md-2 d-flex gap-2">
                        <button type="submit" class="btn btn-emerald btn-lg w-100">
                            <i class="bi bi-save me-2"></i>Simpan
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <div class="mt-3 p-3 rounded-4 border border-info-subtle bg-info bg-opacity-5">
            <small class="text-info-emphasis fw-medium">
                <i class="bi bi-info-circle-fill me-2"></i>
                <strong>Urutan alokasi:</strong>
                <span class="fw-bold text-danger">1. Uang Ruko</span>
                → <span class="fw-bold text-primary">2. Kas Operasional</span>
                → <span class="fw-bold text-success">3. Rekening Penjualan</span>
            </small>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm rounded-4">
    <div class="card-body">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-4">
            <div>
                <h5 class="mb-1">Daftar Biaya Rutin</h5>
                <small class="fw-medium text-muted">Otomatis jadi target alokasi ke Kas Operasional.</small>
            </div>
            <div class="small fw-semibold text-muted">Sisa target hari ini: Rp <?php echo number_format($ringkasan_hari_ini['sisa_target_hari_ini'], 0, ',', '.'); ?></div>
        </div>

        <div class="row g-4">
            <?php if (empty($biaya_wajib)): ?>
            <div class="col-12">
                <div class="border rounded-4 p-5 text-center text-muted">
                    <i class="bi bi-receipt-cutoff display-4 d-block mb-2 opacity-50"></i>
                    <div class="fw-medium">Belum ada biaya rutin.</div>
                </div>
            </div>
            <?php endif; ?>

            <?php foreach ($biaya_wajib as $item): ?>
            <?php
                $nominal_item = financeNormalisasiNominal($item['nominal_bulanan']);
                $nominal_per_hari = financeHitungNominalPerHari($item, (int) $ringkasan_hari_ini['jumlah_hari_bulan']);
                $frekuensi_label = financeGetFrekuensiLabel($item);
                $item_frekuensi = $item['frekuensi'] ?? 'bulanan';
                $badge_frek = match ($item_frekuensi) {
                    'bulanan' => 'bg-primary',
                    'mingguan' => 'bg-info text-dark',
                    'harian' => 'bg-success',
                    'setiap_n_hari' => 'bg-warning text-dark',
                    default => 'bg-secondary',
                };
                $frek_label_pendek = match ($item_frekuensi) {
                    'bulanan' => 'Bulanan',
                    'mingguan' => 'Mingguan',
                    'harian' => 'Harian',
                    'setiap_n_hari' => 'Tiap ' . max(1, (int) ($item['setiap_n_hari'] ?? 1)) . ' hari',
                    default => 'Bulanan',
                };
            ?>
            <div class="col-12 col-lg-6">
                <div class="card border-0 bg-light shadow-sm rounded-4 h-100 overflow-hidden">
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between align-items-start gap-3">
                            <div class="flex-grow-1">
                                <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                                    <h5 class="mb-0 fw-bold"><?php echo htmlspecialchars($item['nama_biaya']); ?></h5>
                                    <span class="badge <?php echo $badge_frek; ?> rounded-pill px-3 py-1"><?php echo $frek_label_pendek; ?></span>
                                    <span class="badge bg-<?php echo (int) $item['is_active'] === 1 ? 'success' : 'secondary'; ?> rounded-pill px-3 py-1">
                                        <?php echo (int) $item['is_active'] === 1 ? 'Aktif' : 'Nonaktif'; ?>
                                    </span>
                                </div>
                                <div class="fw-black text-danger mb-2" style="font-size: clamp(1.1rem, 2vw, 1.35rem);">Rp <?php echo number_format($nominal_item, 0, ',', '.'); ?> <span class="fw-medium text-danger" style="font-size: 0.85rem;"><?php echo $frekuensi_label; ?></span></div>
                                <div class="small text-muted fw-medium"><i class="bi bi-calendar3 me-1"></i>Per hari: Rp <?php echo number_format($nominal_per_hari, 0, ',', '.'); ?></div>
                                <?php if ($item['keterangan']): ?>
                                <div class="small text-muted mt-2 pt-2 border-top"><?php echo htmlspecialchars($item['keterangan']); ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="d-flex flex-column gap-2">
                                <button type="button" class="btn btn-warning btn-sm shadow-sm" data-bs-toggle="modal" data-bs-target="#editBiayaModal<?php echo (int) $item['id']; ?>">
                                    <i class="bi bi-pencil me-1"></i>
                                </button>
                                <form method="POST" onsubmit="return confirm('Hapus biaya wajib ini?');">
                                    <input type="hidden" name="action" value="hapus_biaya">
                                    <input type="hidden" name="id" value="<?php echo (int) $item['id']; ?>">
                                    <button type="submit" class="btn btn-danger btn-sm shadow-sm">
                                        <i class="bi bi-trash me-1"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal fade" id="editBiayaModal<?php echo (int) $item['id']; ?>" tabindex="-1">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content rounded-4 border-0 shadow-lg">
                        <form method="POST" id="formEditBiaya<?php echo (int) $item['id']; ?>">
                            <input type="hidden" name="action" value="edit_biaya">
                            <input type="hidden" name="id" value="<?php echo (int) $item['id']; ?>">

                            <div class="modal-header border-0 px-4 pt-4 pb-2">
                                <h5 class="modal-title fw-bold">Edit Biaya Rutin</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>

                            <div class="modal-body px-4 py-3">
                                <div class="mb-4">
                                    <label class="form-label fw-semibold small">Nama Biaya</label>
                                    <input type="text" name="nama_biaya" class="form-control form-control-lg" value="<?php echo htmlspecialchars($item['nama_biaya']); ?>" required>
                                </div>
                                <div class="mb-4">
                                    <label class="form-label fw-semibold small">Frekuensi Pembayaran</label>
                                    <select name="frekuensi" class="form-select form-select-lg frekuensi-select" data-target="#nHariEdit<?php echo (int) $item['id']; ?>" required>
                                        <option value="bulanan" <?php echo ($item_frekuensi === 'bulanan') ? 'selected' : ''; ?>>Bulanan</option>
                                        <option value="mingguan" <?php echo ($item_frekuensi === 'mingguan') ? 'selected' : ''; ?>>Mingguan</option>
                                        <option value="harian" <?php echo ($item_frekuensi === 'harian') ? 'selected' : ''; ?>>Harian</option>
                                        <option value="setiap_n_hari" <?php echo ($item_frekuensi === 'setiap_n_hari') ? 'selected' : ''; ?>>Setiap N hari</option>
                                    </select>
                                </div>
                                <div class="mb-4 setiap-n-hari-group" id="nHariEdit<?php echo (int) $item['id']; ?>" style="display: <?php echo $item_frekuensi === 'setiap_n_hari' ? 'block' : 'none'; ?>;">
                                    <label class="form-label fw-semibold small">Jumlah Hari (N)</label>
                                    <input type="number" name="setiap_n_hari" class="form-control form-control-lg" min="1" value="<?php echo max(1, (int) ($item['setiap_n_hari'] ?? 3)); ?>">
                                </div>
                                <div class="mb-4">
                                    <label class="form-label fw-semibold small">Nominal <?php echo $frekuensi_label; ?></label>
                                    <div class="input-group input-group-lg">
                                        <span class="input-group-text bg-light fw-semibold border-end-0">Rp</span>
                                        <input type="number" name="nominal_bulanan" class="form-control form-control-lg border-start-0" min="0" step="0.01" value="<?php echo htmlspecialchars($item['nominal_bulanan']); ?>" required>
                                    </div>
                                </div>
                                <div class="mb-4">
                                    <label class="form-label fw-semibold small">Keterangan</label>
                                    <textarea name="keterangan" class="form-control form-control-lg" rows="2"><?php echo htmlspecialchars($item['keterangan']); ?></textarea>
                                </div>
                                <div class="form-check p-3 border rounded-3 bg-light mb-2">
                                    <input class="form-check-input me-3" type="checkbox" name="is_active" id="aktifBiaya<?php echo (int) $item['id']; ?>" <?php echo (int) $item['is_active'] === 1 ? 'checked' : ''; ?>>
                                    <label class="form-check-label fw-semibold" for="aktifBiaya<?php echo (int) $item['id']; ?>">
                                        Aktif dihitung ke target harian
                                    </label>
                                </div>
                            </div>

                            <div class="modal-footer border-0 px-4 pb-4 pt-2">
                                <button type="button" class="btn btn-secondary btn-lg px-4" data-bs-dismiss="modal">Batal</button>
                                <button type="submit" class="btn btn-primary btn-lg px-5 fw-semibold">Update</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="modal fade" id="tambahBiayaModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow-lg">
            <form method="POST" id="formTambahBiaya">
                <input type="hidden" name="action" value="tambah_biaya">

                <div class="modal-header border-0 px-4 pt-4 pb-2">
                    <h5 class="modal-title fw-bold">Tambah Biaya Rutin</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body px-4 py-3">
                    <div class="mb-4">
                        <label class="form-label fw-semibold small">Nama Biaya</label>
                        <input type="text" name="nama_biaya" class="form-control form-control-lg" placeholder="Listrik, Wifi, dll" required>
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold small">Frekuensi Pembayaran</label>
                        <select name="frekuensi" id="tambahFrekuensi" class="form-select form-select-lg" required>
                            <option value="bulanan" selected>Bulanan</option>
                            <option value="mingguan">Mingguan</option>
                            <option value="harian">Harian</option>
                            <option value="setiap_n_hari">Setiap N hari</option>
                        </select>
                    </div>
                    <div class="mb-4" id="tambahNHari" style="display: none;">
                        <label class="form-label fw-semibold small">Jumlah Hari (N)</label>
                        <input type="number" name="setiap_n_hari" class="form-control form-control-lg" min="1" value="3">
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold small" id="tambahNominalLabel">Nominal / bulan</label>
                        <div class="input-group input-group-lg">
                            <span class="input-group-text bg-light fw-semibold border-end-0">Rp</span>
                            <input type="number" name="nominal_bulanan" class="form-control form-control-lg border-start-0" min="0" step="0.01" required>
                        </div>
                        <small class="text-muted d-none mt-1" id="tambahNominalHint"></small>
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold small">Keterangan</label>
                        <textarea name="keterangan" class="form-control form-control-lg" rows="2" placeholder="Opsional"></textarea>
                    </div>
                    <div class="form-check p-3 border rounded-3 bg-light mb-2">
                        <input class="form-check-input me-3" type="checkbox" name="is_active" id="aktifBiayaBaru" checked>
                        <label class="form-check-label fw-semibold" for="aktifBiayaBaru">
                            Aktif dihitung ke target harian
                        </label>
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
(function () {
    function updateNominalLabel(selectEl, labelEl, hintEl, nHariEl) {
        const val = selectEl.value;
        let label = 'Nominal / bulan';
        let hint = 'Isi nominal bulanan, misal 300000 untuk listrik.';
        let nHariVisible = false;
        switch (val) {
            case 'bulanan':
                label = 'Nominal / bulan';
                hint = 'Isi nominal bulanan, misal 300000 untuk listrik.';
                break;
            case 'mingguan':
                label = 'Nominal / minggu';
                hint = 'Isi nominal per minggu, misal 70000 untuk kebersihan tiap minggu.';
                break;
            case 'harian':
                label = 'Nominal / hari';
                hint = 'Isi nominal per hari.';
                break;
            case 'setiap_n_hari':
                label = 'Nominal / N hari';
                hint = 'Isi nominal sesuai jangka hari di atas. Contoh: alpukat Rp 50.000 tiap 3 hari -> isi 50000.';
                nHariVisible = true;
                break;
        }
        if (labelEl) labelEl.textContent = label;
        if (hintEl) hintEl.textContent = hint;
        if (nHariEl) nHariEl.style.display = nHariVisible ? 'block' : 'none';
    }

    const tambahSel = document.getElementById('tambahFrekuensi');
    if (tambahSel) {
        tambahSel.addEventListener('change', function () {
            updateNominalLabel(
                tambahSel,
                document.getElementById('tambahNominalLabel'),
                document.getElementById('tambahNominalHint'),
                document.getElementById('tambahNHari')
            );
        });
    }

    document.querySelectorAll('.frekuensi-select').forEach(function (sel) {
        sel.addEventListener('change', function () {
            const target = document.querySelector(sel.getAttribute('data-target'));
            updateNominalLabel(sel, null, null, target);
        });
    });
})();
</script>

<style>
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
.stat-card {
    border-radius: 20px !important;
    border: none;
    box-shadow: 0 4px 20px rgba(0,0,0,0.06) !important;
    padding: 1.5rem;
    transition: all 0.25s ease;
}
.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 30px rgba(0,0,0,0.08) !important;
}
.stat-label {
    font-size: 0.875rem;
    opacity: 0.8;
    margin-bottom: 0.5rem;
    font-weight: 500;
}
.stat-value {
    font-weight: 800;
    margin: 0;
    font-size: clamp(1.25rem, 2.5vw, 1.75rem);
    line-height: 1.2;
}
.stat-icon {
    font-size: 1.75rem;
    opacity: 0.75;
}
.stat-card.gold {
    background: linear-gradient(135deg,#FEF3C7 0%,#FCD34D 100%);
    color: #92400E;
}
.transition-all {
    transition: all 0.25s ease;
}
.cursor-pointer {
    cursor: pointer !important;
}
.form-switch:has(.form-check-input:checked) {
    background: linear-gradient(135deg, #065F46 0%, #047857 100%) !important;
    border-color: #065F46 !important;
}
.form-switch:has(.form-check-input:checked) .form-check-label {
    color: white !important;
}
.form-switch:has(.form-check-input:checked) small {
    color: rgba(255,255,255,0.85) !important;
    opacity: 1 !important;
}
.form-switch:hover {
    transform: translateY(-1px);
    box-shadow: 0 8px 24px rgba(16,185,129,0.15);
}
.form-switch .form-check-input:focus {
    box-shadow: 0 0 0 4px rgba(16,185,129,0.25);
    border-color: #10B981;
}
.form-check-input:checked.form-check-input-lg {
    background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20'%3e%3cpath fill='none' stroke='%23fff' stroke-linecap='round' stroke-linejoin='round' stroke-width='3' d='m6 10 3 3 6-6'/%3e%3c/svg%3e");
}
</style>

<?php include '../includes/footer.php'; ?>

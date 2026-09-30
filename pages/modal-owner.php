<?php
require_once '../includes/header.php';

$redirect_url = $base_url . '/pages/modal-owner.php';

function alertGajiOwner($icon, $title, $text, $redirect = null, $timer = 1500)
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

function labelOwnerGaji($owner_key)
{
    if ($owner_key === 'dimas') return 'Dimas';
    if ($owner_key === 'vanisa') return 'vanisa';
    return (string) $owner_key;
}

function pastikanTabelGajiOwner($pdo)
{
    static $sudah_dicek = false;
    if ($sudah_dicek) return;
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS gaji_owner (
            id INT AUTO_INCREMENT PRIMARY KEY,
            owner_key ENUM('vanisa', 'dimas') NOT NULL,
            bulan CHAR(7) NOT NULL COMMENT 'Format YYYY-MM',
            nominal DECIMAL(15,2) NOT NULL DEFAULT 0,
            keterangan VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_gaji_owner (owner_key, bulan)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $sudah_dicek = true;
}

pastikanTabelGajiOwner($pdo);

$bulan_terpilih = $_GET['bulan'] ?? date('Y-m');
$filter_semua = ((string) $bulan_terpilih === 'all');
if (!$filter_semua && !preg_match('/^\d{4}-\d{2}$/', (string) $bulan_terpilih)) {
    $bulan_terpilih = date('Y-m');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'simpan') {
        $id = (int) ($_POST['id'] ?? 0);
        $owner_key = $_POST['owner_key'] ?? 'vanisa';
        $bulan = $_POST['bulan'] ?? date('Y-m');
        $nominal = (float) str_replace([',', '.'], '', $_POST['nominal'] ?? 0);
        if ($nominal === 0.0) $nominal = (float) ($_POST['nominal'] ?? 0);
        $keterangan = trim($_POST['keterangan'] ?? '');

        try {
            if (!in_array($owner_key, ['vanisa', 'dimas'], true)) {
                throw new Exception('Nama owner tidak valid.');
            }
            if (!preg_match('/^\d{4}-\d{2}$/', (string) $bulan)) {
                throw new Exception('Format bulan tidak valid.');
            }
            if ($nominal <= 0) {
                throw new Exception('Nominal gaji harus diisi lebih dari 0.');
            }

            if ($id > 0) {
                $stmt = $pdo->prepare("SELECT * FROM gaji_owner WHERE id = ?");
                $stmt->execute([$id]);
                $current = $stmt->fetch();
                if (!$current) throw new Exception('Data tidak ditemukan.');

                $stmt = $pdo->prepare("UPDATE gaji_owner SET owner_key = ?, bulan = ?, nominal = ?, keterangan = ? WHERE id = ?");
                $stmt->execute([$owner_key, $bulan, $nominal, $keterangan, $id]);
            } else {
                $stmt = $pdo->prepare("SELECT id FROM gaji_owner WHERE owner_key = ? AND bulan = ? LIMIT 1");
                $stmt->execute([$owner_key, $bulan]);
                $existing = $stmt->fetch();
                if ($existing) {
                    throw new Exception(labelOwnerGaji($owner_key) . ' untuk bulan tersebut sudah ada. Silakan edit saja.');
                }
                $stmt = $pdo->prepare("INSERT INTO gaji_owner (owner_key, bulan, nominal, keterangan) VALUES (?, ?, ?, ?)");
                $stmt->execute([$owner_key, $bulan, $nominal, $keterangan]);
            }
            $redirect_after = $filter_semua ? ($redirect_url . '?bulan=all') : ($redirect_url . '?bulan=' . urlencode($bulan));
            alertGajiOwner('success', 'Berhasil', 'Data gaji owner tersimpan.', $redirect_after);
        } catch (Throwable $e) {
            alertGajiOwner('error', 'Gagal', $e->getMessage(), null, 3000);
        }
    }

    if ($action === 'hapus') {
        $id = (int) ($_POST['id'] ?? 0);
        try {
            $stmt = $pdo->prepare("DELETE FROM gaji_owner WHERE id = ?");
            $stmt->execute([$id]);
            $redirect_after = $filter_semua ? ($redirect_url . '?bulan=all') : ($redirect_url . '?bulan=' . urlencode($bulan_terpilih));
            alertGajiOwner('success', 'Berhasil', 'Data gaji dihapus.', $redirect_after);
        } catch (Throwable $e) {
            alertGajiOwner('error', 'Gagal', $e->getMessage(), null, 3000);
        }
    }
}

function ambilDataGajiOwner($pdo, $bulan, $semua)
{
    $sql = "SELECT id, owner_key, bulan, nominal, keterangan, 'gaji' AS sumber FROM gaji_owner";
    $params = [];
    if (!$semua) {
        $sql .= ' WHERE bulan = ?';
        $params[] = $bulan;
    }
    $sql .= " ORDER BY bulan DESC, FIELD(owner_key, 'vanisa','dimas'), id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $sudah = [];
    foreach ($rows as $row) {
        $sudah[$row['owner_key'] . '|' . $row['bulan']] = true;
    }

    try {
        $sql_modal = "SELECT id, owner_key, periode_bulan AS bulan, gaji AS nominal, keterangan, 'modal' AS sumber FROM modal_owner WHERE gaji > 0";
        $params_modal = [];
        if (!$semua) {
            $sql_modal .= ' AND periode_bulan = ?';
            $params_modal[] = $bulan;
        }
        $sql_modal .= " ORDER BY periode_bulan DESC, FIELD(owner_key, 'vanisa','dimas'), id";
        $stmt = $pdo->prepare($sql_modal);
        $stmt->execute($params_modal);
        foreach ($stmt->fetchAll() as $row) {
            $kunci = $row['owner_key'] . '|' . $row['bulan'];
            if (isset($sudah[$kunci])) {
                continue;
            }
            $rows[] = $row;
        }
    } catch (Throwable $e) {
    }

    return $rows;
}

$data_bulan_ini = ambilDataGajiOwner($pdo, $bulan_terpilih, $filter_semua);
$menampilkan_semua_karena_kosong = false;
if (!$filter_semua && !$data_bulan_ini) {
    $data_bulan_ini = ambilDataGajiOwner($pdo, $bulan_terpilih, true);
    $menampilkan_semua_karena_kosong = (bool) $data_bulan_ini;
}

$total_bulan_ini = 0;
foreach ($data_bulan_ini as $d) $total_bulan_ini += (float) $d['nominal'];
$nama_bulan = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$labelBulanGaji = function ($ym) use ($nama_bulan) {
    $waktu = strtotime($ym . '-01');
    if (!$waktu) return (string) $ym;
    return $nama_bulan[(int) date('n', $waktu)] . ' ' . date('Y', $waktu);
};
$label_bulan = $filter_semua ? 'Semua Bulan' : $labelBulanGaji($bulan_terpilih);
$bulan_form = $filter_semua ? date('Y-m') : $bulan_terpilih;
?>

<style>
.gaji-hero {
    background: linear-gradient(135deg, #450A0A 0%, #7F1D1D 55%, #991B1B 100%);
    color: #fff;
    border-radius: 22px;
    padding: 22px;
    box-shadow: 0 16px 36px rgba(127, 29, 29, 0.22);
    position: relative;
    overflow: hidden;
}
.gaji-hero::after {
    content: "";
    position: absolute;
    right: -36px;
    top: -46px;
    width: 170px;
    height: 170px;
    border-radius: 50%;
    background: rgba(251, 191, 36, 0.16);
}
.gaji-total {
    font-family: 'Poppins', sans-serif;
    font-weight: 800;
    font-size: clamp(1.45rem, 4vw, 2rem);
    letter-spacing: -0.6px;
    line-height: 1.1;
    overflow-wrap: anywhere;
}
.gaji-card {
    background: #fff;
    border-radius: 20px;
    border: 1px solid rgba(15, 23, 42, 0.06);
    box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05);
    overflow: hidden;
    height: 100%;
}
.gaji-top { padding: 16px 18px; color: #fff; }
.gaji-nominal {
    font-family: 'Poppins', sans-serif;
    font-weight: 800;
    font-size: clamp(1.15rem, 2.4vw, 1.4rem);
    letter-spacing: -0.3px;
    overflow-wrap: anywhere;
}
.gaji-filter {
    background: #fff;
    border-radius: 18px;
    border: 1px solid rgba(15, 23, 42, 0.06);
    box-shadow: 0 8px 20px rgba(15, 23, 42, 0.04);
    padding: 14px;
}
.btn-gaji {
    background: #991B1B;
    color: #fff;
    border: 0;
    border-radius: 12px;
    font-weight: 600;
}
.btn-gaji:hover { background: #7F1D1D; color: #fff; }
@media (max-width: 576px) {
    .gaji-hero { padding: 16px; border-radius: 18px; }
    .gaji-filter .btn, .gaji-filter .form-control { width: 100%; }
}
</style>

<div class="gaji-hero mb-4">
    <div class="position-relative" style="z-index:1;">
        <a href="<?php echo $base_url; ?>/admin_dashboard.php" class="text-white text-decoration-none d-inline-flex align-items-center gap-1 mb-2 opacity-75">
            <i class="bi bi-arrow-left"></i> Dashboard
        </a>
        <h4 class="mb-1 fw-bold">Gaji Owner</h4>
        <div class="small mb-3" style="color:#FDE68A;">Satu kartu untuk tiap gaji Vanisa dan Dimas.</div>
        <div class="small opacity-75"><?php echo $menampilkan_semua_karena_kosong ? 'Belum ada di bulan ini. Ini gaji yang sudah tersimpan' : 'Total ' . htmlspecialchars($label_bulan); ?></div>
        <div class="gaji-total">Rp <?php echo number_format($total_bulan_ini, 0, ',', '.'); ?></div>
    </div>
</div>

<div class="gaji-filter mb-4">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-12 col-md-4">
            <label class="form-label mb-1">Pilih bulan</label>
            <input type="month" class="form-control" name="bulan" value="<?php echo $filter_semua ? '' : htmlspecialchars($bulan_terpilih); ?>">
        </div>
        <div class="col-6 col-md-auto">
            <button type="submit" class="btn btn-outline-secondary"><i class="bi bi-funnel"></i> Filter</button>
        </div>
        <div class="col-6 col-md-auto">
            <a href="modal-owner.php" class="btn btn-outline-secondary">Bulan ini</a>
        </div>
        <div class="col-12 col-md-auto">
            <a href="modal-owner.php?bulan=all" class="btn <?php echo $filter_semua ? 'btn-gaji' : 'btn-outline-secondary'; ?>">Semua gaji</a>
        </div>
        <div class="col-12 col-md-auto ms-md-auto">
            <button type="button" class="btn btn-gaji" onclick="bukaModalGaji()">
                <i class="bi bi-plus-lg"></i> Catat gaji
            </button>
        </div>
    </form>
</div>

<?php if (empty($data_bulan_ini)): ?>
    <div class="gaji-card text-center text-muted py-5 px-3">
        Belum ada gaji <?php echo $filter_semua ? '' : 'di bulan ini'; ?>.
        Klik <b>Catat gaji</b> untuk mengisi gaji Vanisa atau Dimas.
    </div>
<?php else: ?>
<?php if ($menampilkan_semua_karena_kosong): ?>
    <div class="alert border-0 rounded-4 mb-3" style="background:#FEF3C7; color:#78350F;">
        Belum ada gaji <?php echo htmlspecialchars($label_bulan); ?>. Kartu di bawah adalah gaji yang sudah tersimpan di bulan lain.
    </div>
<?php endif; ?>
<div class="row g-3">
    <?php foreach ($data_bulan_ini as $d):
        $vanisa = $d['owner_key'] === 'vanisa';
        $bg = $vanisa ? 'linear-gradient(135deg,#450A0A,#991B1B)' : 'linear-gradient(135deg,#92400E,#D97706)';
        $nama = $vanisa ? 'Vanisa' : 'Dimas';
        $bisa_ubah = ($d['sumber'] ?? 'gaji') === 'gaji';
    ?>
    <div class="col-12 col-md-6">
        <div class="gaji-card">
            <div class="gaji-top" style="background:<?php echo $bg; ?>;">
                <div class="d-flex justify-content-between align-items-start gap-3">
                    <div class="min-w-0">
                        <div class="small opacity-75">Gaji owner</div>
                        <div class="fw-bold" style="font-size:1.25rem;"><?php echo $nama; ?></div>
                        <div class="small mt-1 opacity-75"><?php echo htmlspecialchars($labelBulanGaji($d['bulan'])); ?></div>
                    </div>
                    <i class="bi bi-person-fill" style="font-size:1.5rem; opacity:0.8;"></i>
                </div>
                <div class="gaji-nominal mt-3">Rp <?php echo number_format($d['nominal'], 0, ',', '.'); ?></div>
            </div>
            <?php if (!empty($d['keterangan'])): ?>
            <div class="px-3 pt-3 small text-muted">
                <i class="bi bi-chat-dots"></i> <?php echo htmlspecialchars($d['keterangan']); ?>
            </div>
            <?php endif; ?>
            <?php if ($bisa_ubah): ?>
            <div class="p-3 d-flex gap-2 justify-content-end">
                <button type="button" class="btn btn-sm btn-warning"
                    data-id="<?php echo (int) $d['id']; ?>"
                    data-owner="<?php echo htmlspecialchars($d['owner_key'], ENT_QUOTES); ?>"
                    data-bulan="<?php echo htmlspecialchars($d['bulan'], ENT_QUOTES); ?>"
                    data-nominal="<?php echo (float) $d['nominal']; ?>"
                    data-keterangan="<?php echo htmlspecialchars($d['keterangan'] ?? '', ENT_QUOTES); ?>"
                    onclick="bukaModalGajiDariBtn(this)">
                    <i class="bi bi-pencil"></i> Edit
                </button>
                <form method="POST" class="d-inline" onsubmit="return confirm('Hapus data gaji ini?')">
                    <input type="hidden" name="action" value="hapus">
                    <input type="hidden" name="id" value="<?php echo (int) $d['id']; ?>">
                    <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i> Hapus</button>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="modal fade" id="modalGaji" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4">
            <form method="POST">
                <input type="hidden" name="action" value="simpan">
                <input type="hidden" name="id" id="gaji-id" value="0">
                <div class="modal-header border-0">
                    <h5 class="modal-title" id="gaji-title">Catat Gaji Bulanan</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Bulan</label>
                        <input type="month" class="form-control" name="bulan" id="gaji-bulan" value="<?php echo htmlspecialchars($bulan_form); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Nama Owner</label>
                        <select class="form-select" name="owner_key" id="gaji-owner" required>
                            <option value="vanisa">Vanisa</option>
                            <option value="dimas">Dimas</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Nominal Gaji / Bagi Hasil (Rp)</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light">Rp</span>
                            <input type="number" class="form-control fw-bold text-danger" name="nominal" id="gaji-nominal" min="0" step="1000" value="0" required placeholder="Contoh: 3000000">
                        </div>
                    </div>
                    <div class="mb-0">
                        <label class="form-label">Keterangan (opsional)</label>
                        <input type="text" class="form-control" name="keterangan" id="gaji-keterangan" placeholder="Contoh: Gaji bulan Juni, THR, bagi hasil">
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function bukaModalGaji(data = null) {
    const modal = new bootstrap.Modal(document.getElementById('modalGaji'));
    document.getElementById('gaji-id').value = data && data.id ? data.id : 0;
    document.getElementById('gaji-bulan').value = data && data.bulan ? data.bulan : '<?php echo htmlspecialchars($bulan_form, ENT_QUOTES); ?>';
    document.getElementById('gaji-owner').value = data && data.owner ? data.owner : 'vanisa';
    document.getElementById('gaji-nominal').value = data && data.nominal ? data.nominal : 0;
    document.getElementById('gaji-keterangan').value = data && data.keterangan ? data.keterangan : '';
    document.getElementById('gaji-title').textContent = data && data.id ? 'Edit Gaji Owner' : 'Catat Gaji Bulanan';
    modal.show();
}
function bukaModalGajiDariBtn(btn) {
    bukaModalGaji({
        id: btn.getAttribute('data-id') || 0,
        owner: btn.getAttribute('data-owner') || 'vanisa',
        bulan: btn.getAttribute('data-bulan') || '<?php echo htmlspecialchars($bulan_form, ENT_QUOTES); ?>',
        nominal: btn.getAttribute('data-nominal') || 0,
        keterangan: btn.getAttribute('data-keterangan') || ''
    });
}
</script>

<?php include '../includes/footer.php'; ?>

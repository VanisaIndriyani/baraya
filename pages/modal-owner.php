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

if ($filter_semua) {
    $stmt = $pdo->query("SELECT * FROM gaji_owner ORDER BY bulan DESC, FIELD(owner_key, 'vanisa','dimas'), id");
    $data_bulan_ini = $stmt->fetchAll();
} else {
    $stmt = $pdo->prepare("SELECT * FROM gaji_owner WHERE bulan = ? ORDER BY FIELD(owner_key, 'vanisa','dimas'), id");
    $stmt->execute([$bulan_terpilih]);
    $data_bulan_ini = $stmt->fetchAll();
}

$stmt = $pdo->query("SELECT * FROM gaji_owner ORDER BY bulan DESC, FIELD(owner_key,'vanisa','dimas')");
$data_riwayat = $stmt->fetchAll();

$total_bulan_ini = 0;
foreach ($data_bulan_ini as $d) $total_bulan_ini += (float) $d['nominal'];
$label_bulan = $filter_semua ? 'Semua Bulan' : date('F Y', strtotime($bulan_terpilih . '-01'));

$label_bulan_list = [];
$stmt = $pdo->query("SELECT DISTINCT bulan FROM gaji_owner ORDER BY bulan DESC LIMIT 12");
foreach ($stmt->fetchAll() as $row) $label_bulan_list[] = $row['bulan'];
?>

<div class="page-header">
    <a href="<?php echo $base_url; ?>/admin_dashboard.php" class="text-white text-decoration-none mb-2 d-inline-block"><i class="bi bi-arrow-left"></i> Kembali</a>
    <h4 class="mb-0"><i class="bi bi-wallet2 me-2"></i>Gaji &amp; Bagi Hasil Owner</h4>
    <small class="text-white-50">Catat gaji Dimas / vanisa per bulan.</small>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-body">
                <small class="text-muted">Total Gaji <?php echo htmlspecialchars($label_bulan); ?></small>
                <h4 class="mb-0 text-danger">Rp <?php echo number_format($total_bulan_ini, 0, ',', '.'); ?></h4>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-body">
                <small class="text-muted">Data Gaji <?php echo $filter_semua ? 'Total' : 'Bulan Ini'; ?></small>
                <h4 class="mb-0 text-primary"><?php echo count($data_bulan_ini); ?> data</h4>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-body">
                <small class="text-muted">Rata-rata / Data (<?php echo $filter_semua ? 'Semua' : 'Bulan Ini'; ?>)</small>
                <h4 class="mb-0 text-success">Rp <?php echo count($data_bulan_ini) > 0 ? number_format($total_bulan_ini / count($data_bulan_ini), 0, ',', '.') : 0; ?></h4>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm rounded-4 mb-4">
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-end gap-3">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-auto">
                    <label class="form-label mb-1">Pilih Bulan</label>
                    <input type="month" class="form-control" name="bulan" value="<?php echo $filter_semua ? '' : htmlspecialchars($bulan_terpilih); ?>" <?php echo $filter_semua ? '' : 'required'; ?>>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-outline-primary"><i class="bi bi-funnel"></i> Filter</button>
                </div>
                <div class="col-auto">
                    <a href="modal-owner.php" class="btn btn-outline-secondary">Bulan Ini</a>
                </div>
                <div class="col-auto">
                    <a href="modal-owner.php?bulan=all" class="btn btn-outline-success <?php echo $filter_semua ? 'active' : ''; ?>">
                        <i class="bi bi-list-ul"></i> Semua
                    </a>
                </div>
            </form>
            <button type="button" class="btn btn-primary" onclick="bukaModalGaji()">
                <i class="bi bi-plus-lg"></i> Catat Gaji Bulanan
            </button>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm rounded-4 mb-4">
    <div class="card-body">
        <h5 class="mb-3">Gaji <?php echo htmlspecialchars($label_bulan); ?></h5>
        <?php if (empty($data_bulan_ini)): ?>
            <div class="text-center py-4 text-muted">
                Belum ada data. Klik <b>Catat Gaji Bulanan</b> untuk mengisi gaji Dimas / vanisa.
            </div>
        <?php else: ?>
        <div class="row g-3">
            <?php foreach ($data_bulan_ini as $d): ?>
            <div class="col-md-6">
                <div class="card border-0 rounded-4 bg-light">
                    <div class="card-body">
                        <div class="d-flex align-items-center justify-content-between">
                            <div class="d-flex align-items-center">
                                <div class="rounded-circle bg-primary bg-opacity-10 p-3 me-3">
                                    <i class="bi bi-person-fill text-primary fs-4"></i>
                                </div>
                                <div>
                                    <h5 class="mb-0 fw-bold"><?php echo htmlspecialchars(labelOwnerGaji($d['owner_key'])); ?></h5>
                                    <small class="text-muted"><?php echo htmlspecialchars(date('F Y', strtotime($d['bulan'] . '-01'))); ?></small>
                                </div>
                            </div>
                            <h3 class="fw-bold text-danger mb-0">Rp <?php echo number_format($d['nominal'], 0, ',', '.'); ?></h3>
                        </div>
                        <?php if (!empty($d['keterangan'])): ?>
                        <div class="mt-3 small text-secondary border-top pt-2">
                            <i class="bi bi-chat-dots"></i> <?php echo htmlspecialchars($d['keterangan']); ?>
                        </div>
                        <?php endif; ?>
                        <div class="mt-3 d-flex gap-2 justify-content-end">
                            <button class="btn btn-sm btn-warning"
                                data-id="<?php echo (int)$d['id']; ?>"
                                data-owner="<?php echo htmlspecialchars($d['owner_key'], ENT_QUOTES); ?>"
                                data-bulan="<?php echo htmlspecialchars($d['bulan'], ENT_QUOTES); ?>"
                                data-nominal="<?php echo (float)$d['nominal']; ?>"
                                data-keterangan="<?php echo htmlspecialchars($d['keterangan'] ?? '', ENT_QUOTES); ?>"
                                onclick="bukaModalGajiDariBtn(this)">
                                <i class="bi bi-pencil"></i> Edit
                            </button>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Hapus data gaji ini?')">
                                <input type="hidden" name="action" value="hapus">
                                <input type="hidden" name="id" value="<?php echo (int)$d['id']; ?>">
                                <button class="btn btn-sm btn-danger"><i class="bi bi-trash"></i> Hapus</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="card border-0 shadow-sm rounded-4">
    <div class="card-body">
        <h5 class="mb-3">Riwayat Gaji Owner</h5>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Owner</th>
                        <th>Bulan</th>
                        <th class="text-end">Nominal Gaji</th>
                        <th>Keterangan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($data_riwayat)): ?>
                    <tr><td colspan="4" class="text-center py-4 text-muted">Belum ada data gaji sama sekali.</td></tr>
                    <?php else: ?>
                    <?php foreach ($data_riwayat as $d): ?>
                    <tr>
                        <td class="fw-semibold"><?php echo htmlspecialchars(labelOwnerGaji($d['owner_key'])); ?></td>
                        <td><?php echo htmlspecialchars(date('F Y', strtotime($d['bulan'] . '-01'))); ?></td>
                        <td class="text-end text-danger fw-bold">Rp <?php echo number_format($d['nominal'], 0, ',', '.'); ?></td>
                        <td class="text-muted small"><?php echo htmlspecialchars($d['keterangan'] ?? '-'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

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
                        <input type="month" class="form-control" name="bulan" id="gaji-bulan" value="<?php echo htmlspecialchars($bulan_terpilih); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Nama Owner</label>
                        <select class="form-select" name="owner_key" id="gaji-owner" required>
                            <option value="vanisa">vanisa</option>
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
    document.getElementById('gaji-bulan').value = data && data.bulan ? data.bulan : '<?php echo htmlspecialchars($bulan_terpilih, ENT_QUOTES); ?>';
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
        bulan: btn.getAttribute('data-bulan') || '<?php echo htmlspecialchars($bulan_terpilih, ENT_QUOTES); ?>',
        nominal: btn.getAttribute('data-nominal') || 0,
        keterangan: btn.getAttribute('data-keterangan') || ''
    });
}
</script>

<?php include '../includes/footer.php'; ?>

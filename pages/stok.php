<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once '../includes/header.php';
require_once '../includes/stok-utils.php';

$redirect_dasar = $base_url . '/pages/stok.php';

function alertStok($icon, $title, $text, $redirect = null, $timer = 1600)
{
    $redirect_script = $redirect
        ? ".then(() => window.location.href = '" . addslashes($redirect) . "')"
        : '';
    echo "<script>
        Swal.fire({
            icon: '" . addslashes($icon) . "',
            title: '" . addslashes($title) . "',
            text: '" . addslashes($text) . "',
            timer: " . (int) $timer . ",
            showConfirmButton: false
        })" . $redirect_script . ";
    </script>";
}

$tanggal = $_GET['tanggal'] ?? date('Y-m-d');
if (!stokTanggalValid($tanggal)) {
    $tanggal = date('Y-m-d');
}
$redirect_url = $redirect_dasar . '?tanggal=' . urlencode($tanggal);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $tanggal_post = $_POST['tanggal'] ?? $tanggal;
    if (!stokTanggalValid($tanggal_post)) {
        $tanggal_post = date('Y-m-d');
    }
    $redirect_url = $redirect_dasar . '?tanggal=' . urlencode($tanggal_post);

    try {
        stokPastikanTabel($pdo);

        if ($action === 'tambah_item') {
            $nama = trim($_POST['nama'] ?? '');
            $satuan = trim($_POST['satuan'] ?? 'pcs');
            $stok_awal = stokAngka($_POST['stok_awal'] ?? 0);
            $min_stok = stokAngka($_POST['min_stok'] ?? 0);
            $catatan = trim($_POST['catatan'] ?? '');

            if ($nama === '' || $satuan === '') {
                throw new Exception('Nama barang dan satuan wajib diisi.');
            }
            if ($stok_awal < 0 || $min_stok < 0) {
                throw new Exception('Angka stok tidak boleh minus.');
            }

            $stmt = $pdo->prepare("INSERT INTO stok_item (nama, satuan, stok_awal, min_stok, catatan) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$nama, $satuan, $stok_awal, $min_stok, $catatan !== '' ? $catatan : null]);
            alertStok('success', 'Stok pertama tersimpan', $nama . ' masuk dengan stok ' . stokFormatJumlah($stok_awal) . ' ' . $satuan . '.', $redirect_url);
        }

        if ($action === 'edit_item') {
            $id = (int) ($_POST['id'] ?? 0);
            $nama = trim($_POST['nama'] ?? '');
            $satuan = trim($_POST['satuan'] ?? 'pcs');
            $stok_awal = stokAngka($_POST['stok_awal'] ?? 0);
            $min_stok = stokAngka($_POST['min_stok'] ?? 0);
            $catatan = trim($_POST['catatan'] ?? '');

            if ($id <= 0 || $nama === '' || $satuan === '') {
                throw new Exception('Data barang belum lengkap.');
            }
            if ($stok_awal < 0 || $min_stok < 0) {
                throw new Exception('Angka stok tidak boleh minus.');
            }

            $stmt = $pdo->prepare("UPDATE stok_item SET nama = ?, satuan = ?, stok_awal = ?, min_stok = ?, catatan = ? WHERE id = ?");
            $stmt->execute([$nama, $satuan, $stok_awal, $min_stok, $catatan !== '' ? $catatan : null, $id]);
            alertStok('success', 'Barang diperbarui', 'Data ' . $nama . ' sudah diupdate. Sisa stok ikut berubah.', $redirect_url);
        }

        if ($action === 'hapus_item') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new Exception('Barang tidak ditemukan.');
            }
            $pdo->prepare("DELETE FROM stok_mutasi WHERE id_item = ?")->execute([$id]);
            $stmt = $pdo->prepare("DELETE FROM stok_item WHERE id = ?");
            $stmt->execute([$id]);
            alertStok('success', 'Barang dihapus', 'Item stok dan riwayatnya sudah dihapus.', $redirect_url);
        }

        if ($action === 'tambah_stok') {
            $id = (int) ($_POST['id'] ?? 0);
            $qty = stokAngka($_POST['qty'] ?? 0);
            $catatan = trim($_POST['catatan'] ?? '');
            if ($id <= 0 || $qty <= 0) {
                throw new Exception('Jumlah tambah stok harus lebih dari 0.');
            }
            $cek = $pdo->prepare("SELECT nama, satuan FROM stok_item WHERE id = ?");
            $cek->execute([$id]);
            $item = $cek->fetch();
            if (!$item) {
                throw new Exception('Barang tidak ditemukan.');
            }
            $stmt = $pdo->prepare("INSERT INTO stok_mutasi (id_item, tanggal, tipe, qty, catatan) VALUES (?, ?, 'tambah', ?, ?)");
            $stmt->execute([$id, $tanggal_post, $qty, $catatan !== '' ? $catatan : null]);
            alertStok('success', 'Stok ditambah', $item['nama'] . ' bertambah ' . stokFormatJumlah($qty) . ' ' . $item['satuan'] . '.', $redirect_url);
        }

        if ($action === 'simpan_closing') {
            $pakai = $_POST['pakai'] ?? [];
            if (!is_array($pakai)) {
                throw new Exception('Data closing tidak valid.');
            }

            $pdo->beginTransaction();
            $tercatat = 0;
            $cari = $pdo->prepare("SELECT id FROM stok_mutasi WHERE id_item = ? AND tanggal = ? AND tipe = 'pakai' LIMIT 1");
            $update = $pdo->prepare("UPDATE stok_mutasi SET qty = ? WHERE id = ?");
            $insert = $pdo->prepare("INSERT INTO stok_mutasi (id_item, tanggal, tipe, qty, catatan) VALUES (?, ?, 'pakai', ?, 'Closing harian')");
            $hapus = $pdo->prepare("DELETE FROM stok_mutasi WHERE id = ?");
            $ada_item = $pdo->prepare("SELECT id FROM stok_item WHERE id = ?");

            foreach ($pakai as $id_item => $qty_raw) {
                $id_item = (int) $id_item;
                if ($id_item <= 0) {
                    continue;
                }
                $ada_item->execute([$id_item]);
                if (!$ada_item->fetch()) {
                    continue;
                }
                $qty = stokAngka($qty_raw);
                if ($qty < 0) {
                    throw new Exception('Pemakaian tidak boleh minus.');
                }

                $cari->execute([$id_item, $tanggal_post]);
                $lama = $cari->fetch();
                if ($qty <= 0) {
                    if ($lama) {
                        $hapus->execute([(int) $lama['id']]);
                    }
                    continue;
                }
                if ($lama) {
                    $update->execute([$qty, (int) $lama['id']]);
                } else {
                    $insert->execute([$id_item, $tanggal_post, $qty]);
                }
                $tercatat++;
            }
            $pdo->commit();
            alertStok('success', 'Closing tersimpan', 'Pemakaian tanggal ' . date('d/m/Y', strtotime($tanggal_post)) . ' sudah diupdate. Sisa stok langsung berubah.', $redirect_url);
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        alertStok('error', 'Gagal', $e->getMessage(), null, 3200);
    }
}

$items = stokAmbilDaftar($pdo, $tanggal);
$riwayat = stokAmbilRiwayat($pdo, 24);
$total_item = count($items);
$total_pakai_hari = 0;
$hampir_habis = 0;
foreach ($items as $item) {
    $total_pakai_hari += (float) $item['pakai_tanggal'];
    $status = stokStatus($item);
    if ($status['label'] !== 'Aman') {
        $hampir_habis++;
    }
}
$label_tanggal = date('d M Y', strtotime($tanggal));
?>

<style>
.stok-hero {
    background: linear-gradient(135deg, #450A0A 0%, #7F1D1D 55%, #991B1B 100%);
    color: #fff;
    border-radius: 22px;
    padding: 22px 22px 18px;
    box-shadow: 0 16px 36px rgba(127, 29, 29, 0.22);
    position: relative;
    overflow: hidden;
}
.stok-hero::after {
    content: "";
    position: absolute;
    right: -30px;
    top: -40px;
    width: 160px;
    height: 160px;
    border-radius: 50%;
    background: rgba(251, 191, 36, 0.16);
}
.stok-stat {
    background: #fff;
    border-radius: 18px;
    padding: 16px 16px 14px;
    border: 1px solid rgba(153, 27, 27, 0.08);
    box-shadow: 0 8px 22px rgba(15, 23, 42, 0.05);
    height: 100%;
}
.stok-stat .angka {
    font-family: 'Poppins', sans-serif;
    font-weight: 700;
    font-size: clamp(1.25rem, 3vw, 1.7rem);
    letter-spacing: -0.4px;
    line-height: 1.15;
    overflow-wrap: anywhere;
}
.stok-card {
    background: #fff;
    border-radius: 20px;
    border: 1px solid rgba(15, 23, 42, 0.06);
    box-shadow: 0 10px 26px rgba(15, 23, 42, 0.05);
    padding: 16px;
    height: 100%;
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.stok-sisa {
    font-family: 'Poppins', sans-serif;
    font-weight: 800;
    font-size: clamp(1.8rem, 5vw, 2.3rem);
    letter-spacing: -0.8px;
    line-height: 1;
}
.stok-bar {
    height: 8px;
    border-radius: 99px;
    background: #F3F4F6;
    overflow: hidden;
}
.stok-bar > span {
    display: block;
    height: 100%;
    border-radius: 99px;
}
.stok-savebar {
    position: sticky;
    bottom: 12px;
    z-index: 20;
    background: rgba(255,255,255,0.94);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(153, 27, 27, 0.12);
    border-radius: 18px;
    padding: 12px;
    box-shadow: 0 12px 30px rgba(69, 10, 10, 0.12);
}
.stok-riwayat {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    align-items: center;
    padding: 12px 0;
    border-bottom: 1px solid #F3F4F6;
}
.stok-riwayat:last-child { border-bottom: 0; }
@media (max-width: 576px) {
    .stok-hero { padding: 16px; border-radius: 18px; }
    .stok-card { padding: 14px; }
    .stok-aksi .btn { flex: 1 1 calc(50% - 8px); }
}
</style>

<div class="stok-hero mb-4">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 position-relative" style="z-index:1;">
        <div>
            <a href="<?php echo $base_url; ?>/admin_dashboard.php" class="text-white text-decoration-none d-inline-flex align-items-center gap-1 mb-2 opacity-75">
                <i class="bi bi-arrow-left"></i> Dashboard
            </a>
            <h4 class="mb-1 fw-bold">Stok Barang</h4>
            <div class="small" style="color:#FDE68A;">Isi stok pertama sekali, lalu tiap closing catat yang kepake. Sisanya langsung kelihatan.</div>
        </div>
        <div class="d-flex flex-column flex-sm-row gap-2">
            <form method="get" class="d-flex align-items-center gap-2 bg-white rounded-pill px-3 py-1">
                <i class="bi bi-calendar3" style="color:#991B1B;"></i>
                <input type="date" class="form-control border-0 shadow-none bg-transparent" name="tanggal" value="<?php echo htmlspecialchars($tanggal); ?>" onchange="this.form.submit()" style="min-width: 150px;">
            </form>
            <button type="button" class="btn rounded-pill fw-semibold px-4" style="background:#FBBF24; color:#450A0A;" data-bs-toggle="modal" data-bs-target="#modalTambah">
                <i class="bi bi-plus-lg me-1"></i> Stok Pertama
            </button>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3">
        <div class="stok-stat">
            <div class="small text-muted mb-1">Jenis barang</div>
            <div class="angka" style="color:#450A0A;"><?php echo $total_item; ?></div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="stok-stat">
            <div class="small text-muted mb-1">Kepake <?php echo htmlspecialchars($label_tanggal); ?></div>
            <div class="angka" style="color:#B45309;"><?php echo stokFormatJumlah($total_pakai_hari); ?></div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="stok-stat">
            <div class="small text-muted mb-1">Perlu perhatian</div>
            <div class="angka" style="color:#991B1B;"><?php echo $hampir_habis; ?></div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="stok-stat">
            <div class="small text-muted mb-1">Tanggal closing</div>
            <div class="angka" style="color:#047857; font-size:1.15rem;"><?php echo htmlspecialchars($label_tanggal); ?></div>
        </div>
    </div>
</div>

<?php if ($total_item === 0): ?>
<div class="card border-0 shadow-sm rounded-4 mb-4">
    <div class="card-body text-center py-5 px-3">
        <div class="rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width:72px;height:72px;background:#FEF3C7;color:#92400E;">
            <i class="bi bi-box-seam" style="font-size:1.8rem;"></i>
        </div>
        <h5 class="fw-bold mb-2">Belum ada stok</h5>
        <p class="text-muted mb-3">Contoh: Cup 22 oz, stok pertama 500 pcs. Nanti tiap closing isi berapa yang kepake.</p>
        <button type="button" class="btn rounded-pill px-4 fw-semibold text-white" style="background:linear-gradient(135deg,#450A0A,#991B1B);" data-bs-toggle="modal" data-bs-target="#modalTambah">
            <i class="bi bi-plus-lg me-1"></i> Tambah stok pertama
        </button>
    </div>
</div>
<?php else: ?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-3">
    <div>
        <h5 class="mb-0 fw-bold">Sisa stok</h5>
        <small class="text-muted">Isi angka kepake lalu simpan closing. Kalau sudah pernah diisi, angka itu bisa diubah.</small>
    </div>
    <div class="w-100" style="max-width:320px;">
        <input type="search" id="cariStok" class="form-control" placeholder="Cari barang, contoh cup 22 oz">
    </div>
</div>

<form method="post" id="formClosing">
    <input type="hidden" name="action" value="simpan_closing">
    <input type="hidden" name="tanggal" value="<?php echo htmlspecialchars($tanggal); ?>">
    <div class="row g-3 mb-3" id="daftarStok">
        <?php foreach ($items as $item):
            $status = stokStatus($item);
            $nama_aman = htmlspecialchars($item['nama'], ENT_QUOTES);
        ?>
        <div class="col-12 col-md-6 col-xl-4 kartu-stok" data-nama="<?php echo strtolower($nama_aman); ?>">
            <div class="stok-card">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div class="min-w-0">
                        <div class="fw-bold text-truncate" style="font-size:1.05rem;"><?php echo $nama_aman; ?></div>
                        <div class="small text-muted text-truncate"><?php echo htmlspecialchars($item['catatan'] ?: 'Stok operasional'); ?></div>
                    </div>
                    <span class="badge rounded-pill flex-shrink-0" style="background:<?php echo $status['latar']; ?>; color:<?php echo $status['warna']; ?>;"><?php echo $status['label']; ?></span>
                </div>

                <div>
                    <div class="small text-muted mb-1">Sisa sekarang</div>
                    <div class="stok-sisa" style="color:<?php echo $status['warna']; ?>;">
                        <?php echo stokFormatJumlah($item['sisa']); ?>
                        <span style="font-size:0.95rem; font-weight:600;"><?php echo htmlspecialchars($item['satuan']); ?></span>
                    </div>
                </div>

                <div class="stok-bar" title="Sisa dari stok yang tersedia">
                    <span style="width:<?php echo (float) $item['persen_sisa']; ?>%; background:<?php echo $status['bar']; ?>;"></span>
                </div>

                <div class="row g-2 small">
                    <div class="col-4">
                        <div class="text-muted">Pertama</div>
                        <div class="fw-semibold"><?php echo stokFormatJumlah($item['stok_awal']); ?></div>
                    </div>
                    <div class="col-4">
                        <div class="text-muted">Tambahan</div>
                        <div class="fw-semibold"><?php echo stokFormatJumlah($item['total_tambah']); ?></div>
                    </div>
                    <div class="col-4">
                        <div class="text-muted">Total pakai</div>
                        <div class="fw-semibold"><?php echo stokFormatJumlah($item['total_pakai']); ?></div>
                    </div>
                </div>

                <div>
                    <label class="form-label small mb-1">Kepake tanggal ini</label>
                    <div class="input-group">
                        <input type="number" class="form-control" min="0" step="0.01" inputmode="decimal"
                            name="pakai[<?php echo (int) $item['id']; ?>]"
                            value="<?php echo htmlspecialchars(stokNilaiInput($item['pakai_tanggal'])); ?>">
                        <span class="input-group-text"><?php echo htmlspecialchars($item['satuan']); ?></span>
                    </div>
                </div>

                <div class="d-flex flex-wrap gap-2 stok-aksi mt-auto">
                    <button type="button" class="btn btn-sm rounded-pill"
                        style="background:#FEF3C7; color:#92400E; border:1px solid #FDE68A;"
                        data-id="<?php echo (int) $item['id']; ?>"
                        data-nama="<?php echo $nama_aman; ?>"
                        data-satuan="<?php echo htmlspecialchars($item['satuan'], ENT_QUOTES); ?>"
                        onclick="bukaTambahStok(this)">
                        <i class="bi bi-plus-circle me-1"></i>Tambah
                    </button>
                    <button type="button" class="btn btn-sm rounded-pill"
                        style="background:#fff; color:#7F1D1D; border:1px solid #FECACA;"
                        data-id="<?php echo (int) $item['id']; ?>"
                        data-nama="<?php echo $nama_aman; ?>"
                        data-satuan="<?php echo htmlspecialchars($item['satuan'], ENT_QUOTES); ?>"
                        data-awal="<?php echo htmlspecialchars(stokNilaiInput($item['stok_awal'])); ?>"
                        data-min="<?php echo htmlspecialchars(stokNilaiInput($item['min_stok'])); ?>"
                        data-catatan="<?php echo htmlspecialchars($item['catatan'] ?? '', ENT_QUOTES); ?>"
                        onclick="bukaEdit(this)">
                        <i class="bi bi-pencil me-1"></i>Edit
                    </button>
                    <button type="button" class="btn btn-sm rounded-pill"
                        style="background:#fff; color:#991B1B; border:1px solid #FECACA;"
                        data-id="<?php echo (int) $item['id']; ?>"
                        data-nama="<?php echo $nama_aman; ?>"
                        onclick="hapusStok(this)">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="stok-savebar d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-2">
        <div class="small text-muted">Closing <?php echo htmlspecialchars($label_tanggal); ?>. Angka 0 berarti hari itu tidak kepake.</div>
        <button type="submit" class="btn rounded-pill fw-semibold px-4 text-white" style="background:linear-gradient(135deg,#450A0A,#991B1B);">
            <i class="bi bi-check2-circle me-1"></i> Simpan closing
        </button>
    </div>
</form>
<?php endif; ?>

<div class="card border-0 shadow-sm rounded-4 mt-4">
    <div class="card-body">
        <h5 class="fw-bold mb-1"><i class="bi bi-clock-history me-2" style="color:#991B1B;"></i>Riwayat update</h5>
        <div class="small text-muted mb-2">Pemakaian closing dan penambahan stok terbaru.</div>
        <?php if (!$riwayat): ?>
            <div class="text-muted py-3">Belum ada riwayat.</div>
        <?php else: ?>
            <?php foreach ($riwayat as $r): ?>
            <div class="stok-riwayat">
                <div class="min-w-0">
                    <div class="fw-semibold text-truncate"><?php echo htmlspecialchars($r['nama']); ?></div>
                    <div class="small text-muted">
                        <?php echo date('d/m/Y', strtotime($r['tanggal'])); ?>
                        · <?php echo $r['tipe'] === 'pakai' ? 'Closing, kepake' : 'Tambah stok'; ?>
                        <?php if (!empty($r['catatan'])): ?> · <?php echo htmlspecialchars($r['catatan']); ?><?php endif; ?>
                    </div>
                </div>
                <div class="fw-bold flex-shrink-0" style="color:<?php echo $r['tipe'] === 'pakai' ? '#991B1B' : '#047857'; ?>;">
                    <?php echo $r['tipe'] === 'pakai' ? '-' : '+'; ?><?php echo stokFormatJumlah($r['qty']); ?> <?php echo htmlspecialchars($r['satuan']); ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade" id="modalTambah" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="post" class="modal-content">
            <input type="hidden" name="action" value="tambah_item">
            <input type="hidden" name="tanggal" value="<?php echo htmlspecialchars($tanggal); ?>">
            <div class="modal-header">
                <h5 class="modal-title">Stok pertama</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Nama barang</label>
                    <input type="text" class="form-control" name="nama" required placeholder="Contoh: Cup 22 oz">
                </div>
                <div class="row g-3">
                    <div class="col-6">
                        <label class="form-label">Stok pertama</label>
                        <input type="number" class="form-control" name="stok_awal" min="0" step="0.01" required placeholder="500">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Satuan</label>
                        <input type="text" class="form-control" name="satuan" value="pcs" list="satuanList" required>
                        <datalist id="satuanList">
                            <option value="pcs">
                            <option value="pack">
                            <option value="box">
                            <option value="lusin">
                            <option value="kg">
                            <option value="liter">
                        </datalist>
                    </div>
                </div>
                <div class="mt-3">
                    <label class="form-label">Peringatan kalau sisa di bawah</label>
                    <input type="number" class="form-control" name="min_stok" min="0" step="0.01" value="20" placeholder="20">
                </div>
                <div class="mt-3">
                    <label class="form-label">Catatan</label>
                    <input type="text" class="form-control" name="catatan" placeholder="Opsional">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn text-white" style="background:#991B1B;">Simpan</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="modalEdit" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="post" class="modal-content">
            <input type="hidden" name="action" value="edit_item">
            <input type="hidden" name="tanggal" value="<?php echo htmlspecialchars($tanggal); ?>">
            <input type="hidden" name="id" id="edit-id">
            <div class="modal-header">
                <h5 class="modal-title">Edit barang</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Nama barang</label>
                    <input type="text" class="form-control" name="nama" id="edit-nama" required>
                </div>
                <div class="row g-3">
                    <div class="col-6">
                        <label class="form-label">Stok pertama</label>
                        <input type="number" class="form-control" name="stok_awal" id="edit-awal" min="0" step="0.01" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Satuan</label>
                        <input type="text" class="form-control" name="satuan" id="edit-satuan" required>
                    </div>
                </div>
                <div class="mt-3">
                    <label class="form-label">Peringatan sisa di bawah</label>
                    <input type="number" class="form-control" name="min_stok" id="edit-min" min="0" step="0.01">
                </div>
                <div class="mt-3">
                    <label class="form-label">Catatan</label>
                    <input type="text" class="form-control" name="catatan" id="edit-catatan">
                </div>
                <div class="small text-muted mt-2">Mengubah stok pertama akan mengubah sisa. Pemakaian yang sudah dicatat tidak ikut terhapus.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn text-white" style="background:#991B1B;">Update</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="modalTambahStok" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="post" class="modal-content">
            <input type="hidden" name="action" value="tambah_stok">
            <input type="hidden" name="tanggal" value="<?php echo htmlspecialchars($tanggal); ?>">
            <input type="hidden" name="id" id="tambah-id">
            <div class="modal-header">
                <h5 class="modal-title">Tambah stok</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="fw-semibold mb-3" id="tambah-nama"></div>
                <div class="mb-3">
                    <label class="form-label">Jumlah masuk</label>
                    <input type="number" class="form-control" name="qty" min="0.01" step="0.01" required placeholder="Contoh: 200">
                </div>
                <div class="mb-0">
                    <label class="form-label">Catatan</label>
                    <input type="text" class="form-control" name="catatan" placeholder="Contoh: beli cup baru">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn text-white" style="background:#047857;">Tambah</button>
            </div>
        </form>
    </div>
</div>

<form method="post" id="formHapus" class="d-none">
    <input type="hidden" name="action" value="hapus_item">
    <input type="hidden" name="tanggal" value="<?php echo htmlspecialchars($tanggal); ?>">
    <input type="hidden" name="id" id="hapus-id">
</form>

<script>
function bukaEdit(btn) {
    document.getElementById('edit-id').value = btn.dataset.id;
    document.getElementById('edit-nama').value = btn.dataset.nama;
    document.getElementById('edit-satuan').value = btn.dataset.satuan;
    document.getElementById('edit-awal').value = btn.dataset.awal;
    document.getElementById('edit-min').value = btn.dataset.min;
    document.getElementById('edit-catatan').value = btn.dataset.catatan || '';
    new bootstrap.Modal(document.getElementById('modalEdit')).show();
}
function bukaTambahStok(btn) {
    document.getElementById('tambah-id').value = btn.dataset.id;
    document.getElementById('tambah-nama').textContent = btn.dataset.nama + ' (' + btn.dataset.satuan + ')';
    new bootstrap.Modal(document.getElementById('modalTambahStok')).show();
}
function hapusStok(btn) {
    Swal.fire({
        icon: 'warning',
        title: 'Hapus ' + btn.dataset.nama + '?',
        text: 'Riwayat pemakaian barang ini ikut terhapus.',
        showCancelButton: true,
        confirmButtonColor: '#991B1B',
        cancelButtonText: 'Batal',
        confirmButtonText: 'Hapus'
    }).then((hasil) => {
        if (!hasil.isConfirmed) return;
        document.getElementById('hapus-id').value = btn.dataset.id;
        document.getElementById('formHapus').submit();
    });
}
const cariStok = document.getElementById('cariStok');
if (cariStok) {
    cariStok.addEventListener('input', function () {
        const kata = this.value.toLowerCase().trim();
        document.querySelectorAll('.kartu-stok').forEach(function (el) {
            el.style.display = el.dataset.nama.includes(kata) ? '' : 'none';
        });
    });
}
</script>

<?php include '../includes/footer.php'; ?>

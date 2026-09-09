<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/image-utils.php';

$GLOBALS['alert_script'] = '';

function kasirPastikanTabelProduk($pdo) {
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'kasir_produk'");
        $tabelAda = $stmt->rowCount() > 0;

        if (!$tabelAda) {
            try {
                $pdo->exec("
                    CREATE TABLE kasir_produk (
                        id INT PRIMARY KEY AUTO_INCREMENT,
                        nama VARCHAR(255) NOT NULL,
                        harga INT NOT NULL DEFAULT 0,
                        gambar VARCHAR(255) DEFAULT NULL,
                        kategori VARCHAR(100) NOT NULL DEFAULT 'Minuman',
                        urutan INT NOT NULL DEFAULT 0,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                ");
            } catch (PDOException $e) { /* ignore duplicate */ }
        }

        $kolomDibutuhkan = [
            'id' => 'INT PRIMARY KEY AUTO_INCREMENT',
            'nama' => 'VARCHAR(255) NOT NULL',
            'harga' => 'INT NOT NULL DEFAULT 0',
            'gambar' => 'VARCHAR(255) DEFAULT NULL',
            'kategori' => "VARCHAR(100) NOT NULL DEFAULT 'Minuman'",
            'urutan' => 'INT NOT NULL DEFAULT 0',
            'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP'
        ];
        try {
            $stmtCol = $pdo->query("SHOW COLUMNS FROM kasir_produk");
            $kolomAda = [];
            while ($row = $stmtCol->fetch(PDO::FETCH_ASSOC)) { $kolomAda[$row['Field']] = true; }
            foreach ($kolomDibutuhkan as $nama => $definisi) {
                if (!isset($kolomAda[$nama])) {
                    try { $pdo->exec("ALTER TABLE kasir_produk ADD COLUMN {$nama} {$definisi}"); } catch (PDOException $e) { /* ignore */ }
                }
            }
        } catch (PDOException $e) { /* ignore */ }
    } catch (PDOException $e) { /* ignore */ }
}

function tampilkanAlertProduk($icon, $title, $text, $redirect = null) {
    $iconSafe = $icon === 'error' ? 'error' : ($icon === 'success' ? 'success' : 'info');
    $js = 'document.addEventListener("DOMContentLoaded", function() { if (typeof Swal !== "undefined") { Swal.fire({ icon: "' . $iconSafe . '", title: ' . json_encode($title) . ', html: ' . json_encode($text) . ', confirmButtonColor: "#991B1B", confirmButtonText: "Oke", customClass: { popup: "swal2-rounded" } })' . ($redirect ? '.then(() => { window.location.href = ' . json_encode($redirect) . '; })' : '') . '; } });';
    $GLOBALS['alert_script'] = '<script>' . $js . '</script>';
}

function kasirUploadGambar($fileInput, $baseDir, $idSementara = null) {
    if (!isset($_FILES[$fileInput]) || $_FILES[$fileInput]['error'] !== UPLOAD_ERR_OK) {
        return [true, null, null];
    }
    $file = $_FILES[$fileInput];
    if ($file['size'] > 2 * 1024 * 1024) {
        return [false, null, 'Gambar terlalu besar, maksimum 2MB'];
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = @$finfo->file($file['tmp_name']);
    $allowed = ['image/jpeg', 'image/png', 'image/webp'];
    if (!in_array($mime, $allowed)) {
        return [false, null, 'Format gambar tidak diizinkan, gunakan JPG/PNG/WEBP'];
    }
    $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $ext = $extMap[$mime];

    if (!is_dir($baseDir)) {
        @mkdir($baseDir, 0777, true);
    }
    if (!is_writable($baseDir)) {
        @chmod($baseDir, 0777);
    }
    $ts = time() . '_' . mt_rand(100, 999);
    $idPart = $idSementara ? 'id' . $idSementara . '_' : '';
    $namaFile = 'produk_' . $idPart . $ts . '.' . $ext;
    $targetLengkap = rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR . $namaFile;

    $suksesUpload = @move_uploaded_file($file['tmp_name'], $targetLengkap);
    if (!$suksesUpload) {
        return [false, null, 'Gagal upload, folder upload tidak writable'];
    }
    $compressOk = compressImage($targetLengkap, $targetLengkap, 82);
    if (!$compressOk) {
        // compress gagal, biarkan apa adanya
    }
    $relative = 'uploads/produk_kasir/' . $namaFile;
    return [true, $relative, $targetLengkap];
}

function kasirHapusFileGambar($relativePath, $rootPath) {
    if (!$relativePath) return;
    $abs = rtrim($rootPath, '/\\') . DIRECTORY_SEPARATOR . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath), DIRECTORY_SEPARATOR);
    if (file_exists($abs) && is_file($abs)) {
        @unlink($abs);
    }
}

kasirPastikanTabelProduk($pdo);

$rootDir = realpath(__DIR__ . '/..');
$uploadDir = $rootDir . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'produk_kasir';
$base_url_kasir = $base_url;
$redirectUrl = $base_url . '/pages/produk_kasir.php';

// ============= HANDLE POST CRUD =============
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = $_POST['aksi'] ?? '';

    if ($aksi === 'tambah_produk') {
        $nama = trim($_POST['nama'] ?? '');
        $hargaRaw = $_POST['harga'] ?? '0';
        $harga = (int) preg_replace('/[^0-9]/', '', (string)$hargaRaw);
        $kategori = trim($_POST['kategori'] ?? 'Minuman');
        if ($kategori === '') $kategori = 'Minuman';
        if ($nama === '') {
            tampilkanAlertProduk('error', 'Gagal', 'Nama produk tidak boleh kosong');
        } elseif ($harga <= 0) {
            tampilkanAlertProduk('error', 'Gagal', 'Harga produk harus lebih dari 0');
        } else {
            try {
                $stmtIns = $pdo->prepare("INSERT INTO kasir_produk (nama, harga, kategori, urutan) VALUES (?, ?, ?, 0)");
                $stmtIns->execute([$nama, $harga, $kategori]);
                $idBaru = $pdo->lastInsertId();

                // upload gambar jika ada, dengan idBaru di nama
                $upload = kasirUploadGambar('gambar', $uploadDir, $idBaru);
                if ($upload[0] && !empty($upload[1])) {
                    $stmtUp = $pdo->prepare("UPDATE kasir_produk SET gambar = ? WHERE id = ?");
                    $stmtUp->execute([$upload[1], $idBaru]);
                } elseif (!$upload[0]) {
                    tampilkanAlertProduk('warning', 'Produk tersimpan, tapi gambar gagal', 'Produk berhasil ditambahkan. ' . (!empty($upload[2]) ? $upload[2] : ''));
                    header('Location: ' . $redirectUrl);
                    exit;
                }
                tampilkanAlertProduk('success', 'Berhasil!', 'Produk <b>' . htmlspecialchars($nama) . '</b> ditambahkan.', $redirectUrl);
            } catch (PDOException $e) {
                tampilkanAlertProduk('error', 'Gagal', 'Error database: ' . $e->getMessage());
            }
        }
    }

    if ($aksi === 'edit_produk') {
        $id = (int)($_POST['id'] ?? 0);
        $nama = trim($_POST['nama'] ?? '');
        $hargaRaw = $_POST['harga'] ?? '0';
        $harga = (int) preg_replace('/[^0-9]/', '', (string)$hargaRaw);
        $kategori = trim($_POST['kategori'] ?? 'Minuman');
        if ($kategori === '') $kategori = 'Minuman';
        if ($id <= 0 || $nama === '' || $harga <= 0) {
            tampilkanAlertProduk('error', 'Gagal', 'Data tidak lengkap');
        } else {
            try {
                $stmtOld = $pdo->prepare("SELECT * FROM kasir_produk WHERE id = ? LIMIT 1");
                $stmtOld->execute([$id]);
                $old = $stmtOld->fetch();
                if (!$old) {
                    tampilkanAlertProduk('error', 'Gagal', 'Produk tidak ditemukan');
                } else {
                    $gambarFinal = $old['gambar'];
                    $upload = kasirUploadGambar('gambar', $uploadDir, $id);
                    if ($upload[0] && !empty($upload[1])) {
                        kasirHapusFileGambar($old['gambar'], $rootDir);
                        $gambarFinal = $upload[1];
                    } elseif (!$upload[0]) {
                        tampilkanAlertProduk('warning', 'Data tersimpan, tapi gambar gagal', (!empty($upload[2]) ? $upload[2] : ''));
                        $stmtUp = $pdo->prepare("UPDATE kasir_produk SET nama = ?, harga = ?, kategori = ? WHERE id = ?");
                        $stmtUp->execute([$nama, $harga, $kategori, $id]);
                        header('Location: ' . $redirectUrl);
                        exit;
                    }
                    $stmtUp = $pdo->prepare("UPDATE kasir_produk SET nama = ?, harga = ?, kategori = ?, gambar = ? WHERE id = ?");
                    $stmtUp->execute([$nama, $harga, $kategori, $gambarFinal, $id]);
                    tampilkanAlertProduk('success', 'Berhasil!', 'Produk <b>' . htmlspecialchars($nama) . '</b> diperbarui.', $redirectUrl);
                }
            } catch (PDOException $e) {
                tampilkanAlertProduk('error', 'Gagal', 'Error database: ' . $e->getMessage());
            }
        }
    }

    if ($aksi === 'hapus_produk') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            tampilkanAlertProduk('error', 'Gagal', 'ID tidak valid');
        } else {
            try {
                $stmtOld = $pdo->prepare("SELECT * FROM kasir_produk WHERE id = ? LIMIT 1");
                $stmtOld->execute([$id]);
                $old = $stmtOld->fetch();
                if ($old) {
                    kasirHapusFileGambar($old['gambar'], $rootDir);
                    $stmtDel = $pdo->prepare("DELETE FROM kasir_produk WHERE id = ?");
                    $stmtDel->execute([$id]);
                    tampilkanAlertProduk('success', 'Dihapus!', 'Produk <b>' . htmlspecialchars($old['nama']) . '</b> dihapus permanen.', $redirectUrl);
                } else {
                    tampilkanAlertProduk('error', 'Gagal', 'Produk tidak ditemukan');
                }
            } catch (PDOException $e) {
                tampilkanAlertProduk('error', 'Gagal', 'Error database: ' . $e->getMessage());
            }
        }
    }
}

// ============= FETCH DATA =============
try {
    $stmtList = $pdo->query("SELECT * FROM kasir_produk ORDER BY urutan ASC, id DESC");
    $produkList = $stmtList->fetchAll();
} catch (PDOException $e) {
    $produkList = [];
}
$totalProduk = count($produkList);
?>

<!-- Page Header -->
<div class="card border-0 shadow-sm rounded-4 mb-4 transition-card" style="border-top:3px solid #991B1B;">
    <div class="card-body p-4">
        <div class="d-flex flex-column flex-lg-row align-items-start align-items-lg-center justify-content-between gap-3">
            <div>
                <h3 class="mb-1 fw-black" style="letter-spacing:-0.4px; color:#450A0A;"><i class="bi bi-grid-1x2-fill me-2" style="color:#991B1B;"></i>Kelola Produk Kasir</h3>
                <p class="mb-0 text-muted small">Kelola menu & harga yang muncul di halaman kasir publik (standalone, tidak terkait data stok/keuangan).</p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <a href="<?php echo $base_url_kasir; ?>/kasir.php" target="_blank" class="btn btn-sm fw-semibold rounded-pill px-4 transition-card" style="background: linear-gradient(135deg,#FEF3C7,#FBBF24); color:#78350F; border:1px solid #F59E0B;">
                    <i class="bi bi-cash-register me-1"></i> Buka Kasir Publik <i class="bi bi-box-arrow-up-right ms-1 small"></i>
                </a>
                <button type="button" class="btn btn-sm fw-semibold rounded-pill px-4 text-white transition-card" data-bs-toggle="modal" data-bs-target="#tambahModal" style="background: linear-gradient(135deg,#450A0A,#991B1B); box-shadow: 0 8px 20px rgba(153,27,27,0.3); border:0;">
                    <i class="bi bi-plus-lg me-1"></i> Tambah Produk
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Stat Ringkasan -->
<div class="row g-4 mb-4">
    <div class="col-12 col-md-6 col-lg-4">
        <div class="card border-0 rounded-4 transition-card h-100" style="background: linear-gradient(135deg,#FEF3C7 0%,#FDE68A 50%,#FCD34D 100%); color:#78350F; box-shadow:0 10px 24px rgba(245,158,11,0.15); border:1px solid rgba(255,255,255,0.35);">
            <div class="card-body p-4">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-3 p-3 flex-shrink-0" style="background: rgba(255,255,255,0.28); backdrop-filter: blur(4px); border:1px solid rgba(255,255,255,0.4);">
                        <i class="bi bi-collection-fill fs-2"></i>
                    </div>
                    <div>
                        <div class="fw-semibold mb-1 small opacity-90">Total Menu Aktif</div>
                        <div class="fw-black mb-0" style="font-size:2rem; letter-spacing:-0.5px; text-shadow:0 2px 4px rgba(120,53,15,0.15);"><?php echo $totalProduk; ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- List Produk Card Grid -->
<?php if ($totalProduk === 0): ?>
<div class="card border-0 rounded-4 shadow-sm text-center py-5 px-3">
    <div class="rounded-circle p-4 mx-auto mb-3" style="width:90px; height:90px; background: rgba(153,27,27,0.08); color:#991B1B;">
        <i class="bi bi-emoji-frown fs-1"></i>
    </div>
    <h5 class="fw-bold mb-1" style="color:#450A0A;">Belum Ada Produk</h5>
    <p class="text-muted mb-4 small px-3 px-md-5 mx-auto" style="max-width:520px;">Belum ada menu untuk ditampilkan di halaman kasir. Klik tombol <b>"+ Tambah Produk"</b> di atas untuk memasukkan menu (nama, harga, dan gambar) agar muncul di kasir publik.</p>
    <button type="button" class="btn fw-semibold rounded-pill px-5 py-2 text-white" data-bs-toggle="modal" data-bs-target="#tambahModal" style="background: linear-gradient(135deg,#450A0A,#991B1B); box-shadow:0 8px 20px rgba(153,27,27,0.3); border:0;">
        <i class="bi bi-plus-lg me-1"></i> Tambah Produk Pertama
    </button>
</div>
<?php else: ?>
<div class="row g-4">
    <?php foreach ($produkList as $p):
        $gambarUrl = !empty($p['gambar']) ? ($base_url_kasir . '/' . ltrim($p['gambar'], '/')) : '';
    ?>
    <div class="col-6 col-md-4 col-xl-3">
        <div class="card border-0 rounded-4 overflow-hidden transition-card h-100" style="box-shadow:0 6px 20px rgba(15,23,42,0.08); border-top:3px solid #FBBF24;">
            <div style="height:180px; overflow:hidden; background: linear-gradient(135deg,#FEF3C7,#FDE68A);">
                <?php if ($gambarUrl): ?>
                    <img src="<?php echo htmlspecialchars($gambarUrl); ?>" alt="<?php echo htmlspecialchars($p['nama']); ?>" class="w-100 h-100" style="object-fit: cover;">
                <?php else: ?>
                    <div class="w-100 h-100 d-flex align-items-center justify-content-center text-center" style="color:#78350F;">
                        <div>
                            <i class="bi bi-cup-straw fs-1 mb-1 d-block opacity-70"></i>
                            <div class="small fw-semibold opacity-70">Tidak ada gambar</div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            <div class="card-body p-3 d-flex flex-column">
                <div class="d-flex align-items-start justify-content-between gap-2 mb-2">
                    <div class="fw-bold mb-0 text-truncate pe-2" style="color:#1F2937; letter-spacing:-0.2px;" title="<?php echo htmlspecialchars($p['nama']); ?>"><?php echo htmlspecialchars($p['nama']); ?></div>
                </div>
                <div class="mb-2">
                    <span class="badge rounded-pill" style="background:rgba(153,27,27,0.08); color:#991B1B; border:1px solid rgba(251,191,36,0.35); font-weight:700; font-size:0.72rem; padding:4px 10px;">
                        <i class="bi bi-tag-fill me-1" style="color:#FBBF24;"></i><?php echo htmlspecialchars($p['kategori'] ?? 'Minuman'); ?>
                    </span>
                </div>
                <div class="fw-black mb-3" style="color:#991B1B; font-size:1.25rem; letter-spacing:-0.4px;">Rp <?php echo number_format($p['harga'], 0, ',', '.'); ?></div>
                <div class="mt-auto d-flex gap-2">
                    <button type="button" class="btn btn-sm rounded-3 fw-semibold flex-grow-1 text-white edit-btn transition-card"
                        data-bs-toggle="modal" data-bs-target="#editModal"
                        data-id="<?php echo $p['id']; ?>"
                        data-nama="<?php echo htmlspecialchars($p['nama']); ?>"
                        data-harga="<?php echo $p['harga']; ?>"
                        data-kategori="<?php echo htmlspecialchars($p['kategori'] ?? 'Minuman'); ?>"
                        style="background: rgba(251,191,36,0.95); color:#78350F; border:1px solid #F59E0B;">
                        <i class="bi bi-pencil-square me-1"></i> Edit
                    </button>
                    <form method="POST" class="flex-grow-1 m-0 delete-form" onsubmit="return confirmHapus(event, this);">
                        <input type="hidden" name="aksi" value="hapus_produk">
                        <input type="hidden" name="id" value="<?php echo $p['id']; ?>">
                        <button type="submit" class="btn btn-sm rounded-3 fw-semibold w-100 text-white transition-card" style="background: linear-gradient(135deg,#7F1D1D,#991B1B); border:0;">
                            <i class="bi bi-trash3 me-1"></i> Hapus
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Modal Tambah -->
<div class="modal fade" id="tambahModal" tabindex="-1" aria-labelledby="tambahModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-5 border-0 overflow-hidden" style="box-shadow: 0 25px 60px rgba(153,27,27,0.25); border:2px solid #FBBF24;">
            <div class="modal-header border-0 text-center pt-4 pb-3 px-4" style="background: linear-gradient(135deg,#450A0A,#991B1B); color:white;">
                <h5 class="modal-title fw-black w-100" style="letter-spacing:-0.3px;"><i class="bi bi-plus-circle-fill me-2" style="color:#FDE68A;"></i> Tambah Produk Baru</h5>
            </div>
            <form method="POST" enctype="multipart/form-data" class="m-0">
                <input type="hidden" name="aksi" value="tambah_produk">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold" style="color:#334155;">Nama Menu / Produk <span class="text-danger">*</span></label>
                        <input type="text" class="form-control rounded-3" name="nama" required placeholder="Contoh: Es Teller Original, Dawet Cokelat, dll" minlength="2" maxlength="120" style="border-color:#E2E8F0; min-height:48px;">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold" style="color:#334155;">Harga (Rupiah) <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text rounded-start-3 fw-bold" style="border-color:#E2E8F0; background:#FFF; color:#991B1B;">Rp</span>
                            <input type="number" step="100" min="0" class="form-control rounded-end-3" name="harga" required placeholder="Contoh: 12000" style="border-color:#E2E8F0; min-height:48px; border-left:0;">
                        </div>
                        <div class="form-text text-muted small mt-1">Ketik angka saja (tanpa titik / Rp).</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold" style="color:#334155;">Kategori Produk</label>
                        <input type="text" class="form-control rounded-3" name="kategori" value="Minuman" placeholder="Minuman / Shake / Es Teller / Dawet / Cemilan / dll" style="border-color:#E2E8F0; min-height:48px;">
                        <div class="form-text small mt-1">Untuk filter di kasir: <b style="color:#991B1B;">Minuman, Shake, Es Teller, Dawet</b> (atau kategori custom sendiri).</div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label fw-semibold" style="color:#334155;">Gambar Produk <span class="text-muted small fw-normal">(Opsional, max 2MB, JPG/PNG/WEBP)</span></label>
                        <input type="file" class="form-control rounded-3" name="gambar" accept="image/jpeg,image/png,image/webp" style="border-color:#E2E8F0; padding:0.8rem;">
                        <div class="form-text text-muted small mt-1">Rekomendasi gambar kotak / portrait, berkualitas bagus. Tanpa gambar pun tetap tampil (placeholder).</div>
                    </div>
                </div>
                <div class="modal-footer border-0 p-4 pt-1 d-flex gap-2">
                    <button type="button" class="btn rounded-3 fw-semibold px-4" data-bs-dismiss="modal" style="background:#F1F5F9; color:#334155; border:0;">Batal</button>
                    <button type="submit" class="btn rounded-3 fw-semibold px-5 text-white" style="background: linear-gradient(135deg,#450A0A,#991B1B); box-shadow:0 8px 20px rgba(153,27,27,0.3); border:0;"><i class="bi bi-save2 me-1"></i> Simpan Produk</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Edit -->
<div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-5 border-0 overflow-hidden" style="box-shadow: 0 25px 60px rgba(245,158,11,0.25); border:2px solid #FBBF24;">
            <div class="modal-header border-0 text-center pt-4 pb-3 px-4" style="background: linear-gradient(135deg,#78350F,#B45309); color:white;">
                <h5 class="modal-title fw-black w-100" style="letter-spacing:-0.3px;"><i class="bi bi-pencil-square me-2" style="color:#FEF3C7;"></i> Edit Produk</h5>
            </div>
            <form method="POST" enctype="multipart/form-data" class="m-0">
                <input type="hidden" name="aksi" value="edit_produk">
                <input type="hidden" name="id" id="edit-id" value="">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold" style="color:#334155;">Nama Produk <span class="text-danger">*</span></label>
                        <input type="text" class="form-control rounded-3" name="nama" id="edit-nama" required minlength="2" maxlength="120" style="border-color:#E2E8F0; min-height:48px;">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold" style="color:#334155;">Harga (Rupiah) <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text rounded-start-3 fw-bold" style="border-color:#E2E8F0; background:#FFF; color:#991B1B;">Rp</span>
                            <input type="number" step="100" min="0" class="form-control rounded-end-3" name="harga" id="edit-harga" required style="border-color:#E2E8F0; min-height:48px; border-left:0;">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold" style="color:#334155;">Kategori Produk</label>
                        <input type="text" class="form-control rounded-3" name="kategori" id="edit-kategori" placeholder="Minuman / Shake / Es Teller / Dawet" style="border-color:#E2E8F0; min-height:48px;">
                        <div class="form-text small mt-1">Untuk filter menu di kasir publik. Contoh: <b style="color:#991B1B;">Minuman, Es Teller, Dawet, Shake, Cemilan</b>.</div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label fw-semibold" style="color:#334155;">Ganti Gambar <span class="text-muted small fw-normal">(Kosongkan jika tidak ingin diganti)</span></label>
                        <input type="file" class="form-control rounded-3" name="gambar" accept="image/jpeg,image/png,image/webp" style="border-color:#E2E8F0; padding:0.8rem;">
                    </div>
                </div>
                <div class="modal-footer border-0 p-4 pt-1 d-flex gap-2">
                    <button type="button" class="btn rounded-3 fw-semibold px-4" data-bs-dismiss="modal" style="background:#F1F5F9; color:#334155; border:0;">Batal</button>
                    <button type="submit" class="btn rounded-3 fw-semibold px-5 text-white" style="background: linear-gradient(135deg,#78350F,#D97706); box-shadow:0 8px 20px rgba(217,119,6,0.3); border:0;"><i class="bi bi-check2-circle me-1"></i> Update Produk</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function confirmHapus(e, form) {
    e.preventDefault();
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            icon: 'warning',
            title: 'Hapus Produk Ini?',
            html: 'Data produk & gambarnya akan dihapus permanen dan <b>tidak bisa dikembalikan</b>.',
            showCancelButton: true,
            cancelButtonText: 'Batal',
            confirmButtonText: 'Ya, Hapus!',
            confirmButtonColor: '#991B1B',
            cancelButtonColor: '#64748B',
            reverseButtons: true,
            customClass: { popup: 'swal2-rounded' }
        }).then(function(res) {
            if (res.isConfirmed) { form.submit(); }
        });
    } else {
        if (confirm('Hapus produk ini permanen?')) { form.submit(); }
    }
    return false;
}

document.addEventListener('DOMContentLoaded', function() {
    var editBtns = document.querySelectorAll('.edit-btn');
    editBtns.forEach(function(btn) {
        btn.addEventListener('click', function() {
            var id = btn.getAttribute('data-id');
            var nama = btn.getAttribute('data-nama');
            var harga = btn.getAttribute('data-harga');
            var kategori = btn.getAttribute('data-kategori');
            document.getElementById('edit-id').value = id || '';
            document.getElementById('edit-nama').value = nama || '';
            document.getElementById('edit-harga').value = harga || '';
            document.getElementById('edit-kategori').value = kategori || 'Minuman';
        });
    });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
<?php if (!empty($GLOBALS['alert_script'])) echo $GLOBALS['alert_script']; ?>

<?php
require_once '../includes/header.php';

$barang_list = $pdo->query("SELECT * FROM barang ORDER BY nama ASC")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id_barang = $_POST['id_barang'];
    $qty = $_POST['qty'];
    $catatan = $_POST['catatan'];
    $tanggal = $_POST['tanggal'];

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("INSERT INTO pemakaian (id_barang, qty, catatan, tanggal) VALUES (?, ?, ?, ?)");
        $stmt->execute([$id_barang, $qty, $catatan, $tanggal]);

        $stmt = $pdo->prepare("UPDATE barang SET stok = stok - ? WHERE id = ?");
        $stmt->execute([$qty, $id_barang]);

        $pdo->commit();
        echo "<script>
            Swal.fire({
                icon: 'success',
                title: 'Berhasil',
                text: 'Pemakaian berhasil dicatat!',
                timer: 1500
            }).then(() => window.location.href = '$base_url/index.php');
        </script>";
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "<script>
            Swal.fire({
                icon: 'error',
                title: 'Gagal',
                text: '" . $e->getMessage() . "'
            });
        </script>";
    }
}

$pemakaian = $pdo->query("SELECT pk.*, b.nama as barang_nama, b.satuan FROM pemakaian pk JOIN barang b ON pk.id_barang = b.id ORDER BY pk.tanggal DESC, pk.created_at DESC LIMIT 20")->fetchAll();
?>

<div class="page-header">
    <a href="<?php echo $base_url; ?>/index.php" class="text-white text-decoration-none mb-2 d-inline-block"><i class="bi bi-arrow-left"></i> Kembali</a>
    <h4 class="mb-0"><i class="bi bi-box-arrow-right me-2"></i>Pemakaian Barang</h4>
</div>

<div class="card p-3 mb-4">
    <form method="POST">
        <div class="mb-3">
            <label class="form-label">Tanggal</label>
            <input type="date" class="form-control" name="tanggal" value="<?php echo date('Y-m-d'); ?>" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Barang</label>
            <select class="form-select" name="id_barang" required>
                <option value="">Pilih Barang</option>
                <?php foreach ($barang_list as $b): ?>
                <option value="<?php echo $b['id']; ?>"><?php echo $b['nama']; ?> (Stok: <?php echo $b['stok']; ?> <?php echo $b['satuan']; ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label">Qty</label>
            <input type="number" class="form-control" name="qty" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Catatan</label>
            <textarea class="form-control" name="catatan" rows="2"></textarea>
        </div>
        <button type="submit" class="btn btn-primary w-100 btn-lg"><i class="bi bi-check-lg me-2"></i>Simpan</button>
    </form>
</div>

<h6 class="mb-3">Riwayat Pemakaian</h6>
<div class="list-group">
    <?php foreach ($pemakaian as $p): ?>
    <div class="list-group-item">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <strong><?php echo $p['barang_nama']; ?></strong><br>
                <small class="text-muted"><?php echo $p['qty']; ?> <?php echo $p['satuan']; ?></small>
            </div>
            <small class="text-muted"><?php echo date('d/m/Y', strtotime($p['tanggal'])); ?></small>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php include '../includes/footer.php'; ?>

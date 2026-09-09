<?php
require_once '../includes/header.php';
?>

<div class="page-header">
    <h4 class="mb-0"><i class="bi bi-cash-stack me-2"></i>Transaksi</h4>
</div>

<div class="row g-3 mb-4">
    <div class="col-6">
        <a href="<?php echo $base_url; ?>/pages/penjualan.php" class="card p-3 text-decoration-none text-dark h-100">
            <i class="bi bi-cash-coin fs-2 text-merah mb-2 d-block"></i>
            <strong>Penjualan</strong>
        </a>
    </div>
    <div class="col-6">
        <a href="<?php echo $base_url; ?>/pages/pembelian.php" class="card p-3 text-decoration-none text-dark h-100">
            <i class="bi bi-cart-plus fs-2 text-merah mb-2 d-block"></i>
            <strong>Pembelian</strong>
        </a>
    </div>
    <div class="col-6">
        <a href="<?php echo $base_url; ?>/pages/float-kasir.php" class="card p-3 text-decoration-none text-dark h-100">
            <i class="bi bi-wallet2 fs-2 text-emas mb-2 d-block"></i>
            <strong>Float Kasir</strong>
        </a>
    </div>
    <div class="col-6">
        <a href="<?php echo $base_url; ?>/pages/hutang-owner.php" class="card p-3 text-decoration-none text-dark h-100">
            <i class="bi bi-exclamation-triangle fs-2 text-emas mb-2 d-block"></i>
            <strong>Hutang Owner</strong>
        </a>
    </div>
    <div class="col-12">
        <a href="<?php echo $base_url; ?>/pages/saldo_rekening.php" class="card p-3 text-decoration-none text-dark h-100">
            <i class="bi bi-bank fs-2 text-merah mb-2 d-block"></i>
            <strong>Saldo Rekening</strong>
        </a>
    </div>
</div>

<?php include '../includes/footer.php'; ?>

<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Tentukan base URL otomatis
$base_url = dirname($_SERVER['PHP_SELF']);
while (strpos($base_url, '/pages') !== false || strpos($base_url, '/includes') !== false || strpos($base_url, '/controllers') !== false) {
    $base_url = dirname($base_url);
}
if ($base_url == '\\' || $base_url == '/') $base_url = '';

// Cek login kecuali di login.php
$current_page = basename($_SERVER['PHP_SELF']);
if ($current_page != 'login.php') {
    if (!isset($_SESSION['user_id'])) {
        header('Location: ' . $base_url . '/login.php');
        exit;
    }
}

require_once __DIR__ . '/../config/database.php';

// Helper function to check active menu
function is_active($pages) {
    global $current_page;
    return in_array($current_page, (array)$pages) ? 'active' : '';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manajemen Keuangan Baraya</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="<?php echo $base_url; ?>/assets/css/style.css?v=20260909_MAROON_V2" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>
<body>
<?php if ($current_page != 'login.php'): ?>
<!-- Overlay for mobile -->
<div class="overlay" id="overlay"></div>

<!-- Sidebar -->
<aside class="sidebar" id="sidebar">
    <button class="offcanvas-close d-md-none" id="closeSidebar">
        <i class="bi bi-x-lg"></i>
    </button>
    
    <div class="sidebar-brand">
        <i class="bi bi-cup-hot-fill"></i>
        <h1>Baraya Dawet</h1>
    </div>

    <!-- Menu Utama -->
    <div class="menu-section">
        <button type="button" class="menu-label menu-kategori-toggle w-100 text-start border-0 bg-transparent" data-bs-toggle="collapse" data-bs-target="#collapseKatUtama" aria-expanded="true" aria-controls="collapseKatUtama">
            <span>Menu Utama</span>
            <i class="bi bi-chevron-right ms-auto menu-kategori-chevron"></i>
        </button>
        <div class="collapse show" id="collapseKatUtama">
            <a href="<?php echo $base_url; ?>/admin_dashboard.php" class="menu-item <?php echo is_active(['admin_dashboard.php']); ?>">
                <i class="bi bi-house-door-fill"></i>
                <span>Dashboard</span>
            </a>
        </div>
    </div>

    <!-- Transaksi Harian -->
    <div class="menu-section">
        <button type="button" class="menu-label menu-kategori-toggle w-100 text-start border-0 bg-transparent" data-bs-toggle="collapse" data-bs-target="#collapseKatTrxHarian" aria-expanded="true" aria-controls="collapseKatTrxHarian">
            <span>Transaksi Harian</span>
            <i class="bi bi-chevron-right ms-auto menu-kategori-chevron"></i>
        </button>
        <div class="collapse show" id="collapseKatTrxHarian">
            <a href="<?php echo $base_url; ?>/pages/penjualan.php" class="menu-item <?php echo is_active(['penjualan.php']); ?>">
                <i class="bi bi-cart-check-fill"></i>
                <span>Penjualan</span>
            </a>
            <a href="<?php echo $base_url; ?>/pages/pembelian.php" class="menu-item <?php echo is_active(['pembelian.php']); ?>">
                <i class="bi bi-bag-plus-fill"></i>
                <span>Beli Bahan (Stok)</span>
            </a>
            <a href="<?php echo $base_url; ?>/pages/beli-harian.php" class="menu-item <?php echo is_active(['beli-harian.php']); ?>">
                <i class="bi bi-bag-dash"></i>
                <span>Beli Harian</span>
            </a>
        </div>
    </div>

    <!-- Menu Publik -->
    <div class="menu-section">
        <button type="button" class="menu-label menu-kategori-toggle w-100 text-start border-0 bg-transparent" data-bs-toggle="collapse" data-bs-target="#collapseKatPublik" aria-expanded="false" aria-controls="collapseKatPublik">
            <span>Menu Publik</span>
            <i class="bi bi-chevron-right ms-auto menu-kategori-chevron"></i>
        </button>
        <div class="collapse" id="collapseKatPublik">
            <a href="<?php echo $base_url; ?>/pages/produk_kasir.php" class="menu-item <?php echo is_active(['produk_kasir.php']); ?>">
                <i class="bi bi-grid-1x2-fill"></i>
                <span>Produk Kasir</span>
            </a>
            <a href="<?php echo $base_url; ?>/pages/transaksi_kasir.php" class="menu-item <?php echo is_active(['transaksi_kasir.php']); ?>">
                <i class="bi bi-receipt-cutoff"></i>
                <span>Transaksi Kasir</span>
            </a>
            <a href="<?php echo $base_url; ?>/kasir.php" target="_blank" class="menu-item">
                <i class="bi bi-cash-register"></i>
                <span>Buka Kasir <i class="bi bi-box-arrow-up-right ms-1" style="font-size:0.78rem; opacity:0.75;"></i></span>
            </a>
        </div>
    </div>

    <!-- Uang & Keuangan -->
    <div class="menu-section">
        <button type="button" class="menu-label menu-kategori-toggle w-100 text-start border-0 bg-transparent" data-bs-toggle="collapse" data-bs-target="#collapseKatKeuangan" aria-expanded="false" aria-controls="collapseKatKeuangan">
            <span>Uang & Keuangan</span>
            <i class="bi bi-chevron-right ms-auto menu-kategori-chevron"></i>
        </button>
        <div class="collapse" id="collapseKatKeuangan">
            <a href="<?php echo $base_url; ?>/pages/saldo_rekening.php" class="menu-item <?php echo is_active(['saldo_rekening.php']); ?>">
                <i class="bi bi-bank2"></i>
                <span>Rekening Penjualan</span>
            </a>
            <a href="<?php echo $base_url; ?>/pages/biaya-wajib.php" class="menu-item <?php echo is_active(['biaya-wajib.php']); ?>">
                <i class="bi bi-calendar2-check"></i>
                <span>Biaya Rutin</span>
            </a>
        </div>
    </div>

    <!-- Owner & Laporan -->
    <div class="menu-section">
        <button type="button" class="menu-label menu-kategori-toggle w-100 text-start border-0 bg-transparent" data-bs-toggle="collapse" data-bs-target="#collapseKatOwner" aria-expanded="false" aria-controls="collapseKatOwner">
            <span>Owner & Laporan</span>
            <i class="bi bi-chevron-right ms-auto menu-kategori-chevron"></i>
        </button>
        <div class="collapse" id="collapseKatOwner">
            <a href="<?php echo $base_url; ?>/pages/modal-owner.php" class="menu-item <?php echo is_active(['modal-owner.php']); ?>">
                <i class="bi bi-wallet2"></i>
                <span>Gaji Owner</span>
            </a>
            <a href="<?php echo $base_url; ?>/pages/hutang-owner.php" class="menu-item <?php echo is_active(['hutang-owner.php']); ?>">
                <i class="bi bi-cash-stack"></i>
                <span>Utang Owner</span>
            </a>
        </div>
    </div>

    <div class="sidebar-footer">
        <div class="user-info">
            <i class="bi bi-person-circle"></i>
            <span><?php echo htmlspecialchars($_SESSION['nama'] ?? 'Admin'); ?></span>
        </div>
        <a href="<?php echo $base_url; ?>/logout.php" class="menu-item">
            <i class="bi bi-box-arrow-left"></i>
            <span>Logout</span>
        </a>
    </div>
</aside>
<?php endif; ?>

<div class="main-wrapper">
    <?php if ($current_page != 'login.php'): ?>
    <!-- Top Header -->
    <header class="top-header">
        <div class="d-flex align-items-center gap-3">
            <button class="hamburger-btn" id="openSidebar">
                <i class="bi bi-list"></i>
            </button>
            <h2 class="header-title mb-0">
                <?php
                $page_titles = [
                    'admin_dashboard.php' => 'Dashboard',
                    'transaksi.php' => 'Transaksi',
                    'penjualan.php' => 'Penjualan',
                    'pembelian.php' => 'Beli Bahan (Stok)',
                    'beli-harian.php' => 'Beli Harian',
                    'pemakaian.php' => 'Pemakaian',
                    'saldo_rekening.php' => 'Kas Operasional &amp; Rekening',
                    'biaya-wajib.php' => 'Biaya Rutin',
                    'modal-owner.php' => 'Gaji &amp; Bagi Hasil Owner',
                    'hutang-owner.php' => 'Utang Owner',
                    'produk_kasir.php' => 'Kelola Produk Kasir',
                    'transaksi_kasir.php' => 'Data Transaksi Kasir'
                ];
                echo $page_titles[$current_page] ?? 'Dashboard';
                ?>
            </h2>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="text-muted small d-none d-sm-inline">
                <?php echo date('d F Y'); ?>
            </span>
        </div>
    </header>
    <?php endif; ?>

    <main class="main-content">

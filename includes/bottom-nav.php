<nav class="bottom-nav">
    <div class="container-fluid">
        <div class="row justify-content-around">
            <div class="col">
                <a href="<?php echo $base_url; ?>/admin_dashboard.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'admin_dashboard.php' ? 'active' : ''; ?>">
                    <i class="bi bi-house-fill"></i>
                    <span>Home</span>
                </a>
            </div>
            <div class="col">
                <a href="<?php echo $base_url; ?>/pages/penjualan.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'penjualan.php' ? 'active' : ''; ?>">
                    <i class="bi bi-cart-check-fill"></i>
                    <span>Jual</span>
                </a>
            </div>
            <div class="col">
                <a href="<?php echo $base_url; ?>/pages/float-kasir.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'float-kasir.php' ? 'active' : ''; ?>">
                    <i class="bi bi-cash-coin"></i>
                    <span>Kasir</span>
                </a>
            </div>
        </div>
    </div>
</nav>

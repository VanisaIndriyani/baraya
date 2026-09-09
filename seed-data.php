<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('memory_limit', '512M');
set_time_limit(300);
define('SEEDER_RUNNING', true);

require_once __DIR__ . '/includes/finance-utils.php';
require_once __DIR__ . '/config/database.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

$is_logged_in = !empty($_SESSION['user_id']);
$force_seed = isset($_GET['force']) && $_GET['force'] == '1';
$allow_clean = $is_logged_in || $force_seed || (isset($_SERVER['HTTP_HOST']) && in_array($_SERVER['HTTP_HOST'], ['localhost','127.0.0.1']));

function runQuery($pdo, $sql, $params = [], $desc = '')
{
    static $counter = 0;
    $counter++;
    try {
        $stmt = $pdo->prepare($sql);
        $ok = $stmt->execute($params);
        echo '<div class="mb-1 ms-4"><span class="text-success">✓</span> <span class="small text-muted">#' . $counter . '</span> ' . ($desc ?: htmlspecialchars($sql)) . '</div>';
        return $ok ? $stmt : false;
    } catch (Throwable $e) {
        echo '<div class="mb-1 ms-4 text-danger small">✗ #' . $counter . ' ' . htmlspecialchars($desc) . ' → ' . htmlspecialchars($e->getMessage()) . '</div>';
        return false;
    }
}

$clean = isset($_GET['clean']) && $_GET['clean'] === '1';
$run = isset($_POST['run_seed']) && $_POST['run_seed'] === '1';

if ($clean && !$allow_clean) {
    header('Location: ' . (dirname($_SERVER['PHP_SELF']) ?: '/') . '/pages/login.php');
    exit;
}

if (isset($_GET['logout_demo']) && $_GET['logout_demo'] == 1) {
    $_SESSION = [];
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

financeEnsureBiayaWajibTable($pdo);
financeEnsureAccountSystem($pdo);

$seed_done = false;
$seed_error = '';

if ($run) {
    $safe_mode = ($_POST['safe_mode'] ?? '1') === '1';
    try {
        $pdo->beginTransaction();

        $log_header = '';
        if ($clean) {
            $log_header = '<div class="alert alert-danger py-2 rounded-3 small mb-2"><i class="bi bi-exclamation-triangle-fill me-1"></i><strong>MODE BERSIH (?clean=1)</strong> Semua data transaksi dihapus & reset dari 0</div>';

            $disable_fk = "SET FOREIGN_KEY_CHECKS = 0";
            runQuery($pdo, $disable_fk, [], '(persiapan) Nonaktifkan foreign key sementara');

            $truncate_tables = [
                'saldo_rekening_transaksi','pembelian_detail','pembelian','penjualan_item','penjualan',
                'pengeluaran','hutang_owner_transaksi','biaya_wajib','barang','supplier',
                'periode','float_kasir','saldo_rekening'
            ];
            foreach ($truncate_tables as $t) {
                $tbl_check = $pdo->query("SHOW TABLES LIKE '" . addslashes($t) . "'")->rowCount();
                if ($tbl_check > 0) {
                    runQuery($pdo, "TRUNCATE TABLE $t", [], 'Reset tabel ' . $t);
                }
            }
            runQuery($pdo, "DELETE FROM users WHERE username = ?", ['admin'], 'Reset default admin jika ada');
            runQuery($pdo, $disable_fk ? "SET FOREIGN_KEY_CHECKS = 1" : "SELECT 1", [], 'Aktifkan kembali foreign key');
        } else {
            $log_header = '<div class="alert alert-success py-2 rounded-3 small mb-2"><i class="bi bi-shield-check me-1"></i><strong>MODE AMAN</strong> INSERT IGNORE / UPDATE — TIDAK ADA DATA LAMA YANG DIHAPUS!</div>';
        }

        echo '<div class="container mt-4 mb-5">';
        echo '<a href="'.$_SERVER['PHP_SELF'].'" class="btn btn-sm btn-outline-primary mb-3"><i class="bi bi-arrow-left me-1"></i>Kembali ke Menu Seeder</a>';
        echo '<h2 class="mb-3 fw-bold"><i class="bi bi-gear-wide-connected me-2 text-primary"></i>Proses Seeder Database — Baraya Dawet</h2>';
        echo $log_header;

        echo '<div class="card mb-3 rounded-4 border-0 shadow-sm"><div class="card-header bg-white border-bottom py-3"><h5 class="mb-0 fw-bold text-primary"><i class="bi bi-person-badge me-2"></i>1. Master Data User & Rekening</h5></div><div class="card-body py-3">';

        $hash = password_hash('admin123', PASSWORD_BCRYPT);
        if ($safe_mode || $clean) {
            $chk = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
            $chk->execute(['admin']);
            $admin_exists = $chk->fetchColumn();
            if (!$admin_exists) {
                runQuery($pdo, "INSERT INTO users (username,password,nama,role) VALUES (?,?,?,'admin')", ['admin',$hash,'Admin Baraya Dawet'], 'Insert user ADMIN (username=admin, password=admin123)');
            } else {
                echo '<div class="mb-1 ms-4"><span class="text-info">i</span> Admin (username <strong>admin</strong>) <em>sudah ada</em> — skip insert (password tidak direset, tetap aman!) </div>';
            }
        }

        runQuery($pdo, "INSERT IGNORE INTO saldo_rekening (id, nama_rekening, kode_rekening, saldo) VALUES (1, 'Rekening Penjualan', 'penjualan', 0)", [], 'Rekening ID 1: Rekening Penjualan (hijau owner)');
        runQuery($pdo, "INSERT IGNORE INTO saldo_rekening (id, nama_rekening, kode_rekening, saldo) VALUES (2, 'Kas Operasional', 'operasional', 0)", [], 'Rekening ID 2: Kas Operasional (kuning belanja)');
        try {
            $pdo->exec("ALTER TABLE saldo_rekening AUTO_INCREMENT = 3");
        } catch (Throwable $e) {}

        $float_cek = $pdo->query("SELECT COUNT(*) FROM float_kasir")->fetchColumn();
        if (!$float_cek || $clean) {
            runQuery($pdo, "INSERT INTO float_kasir (saldo_awal, saldo_sekarang) VALUES (500000, 500000)", [], 'Float Kasir Awal Rp 500.000 (untuk kembalian)');
        } else {
            echo '<div class="mb-1 ms-4"><span class="text-info">i</span> Float Kasir sudah ada — skip</div>';
        }

        $periode_cek = $pdo->query("SELECT COUNT(*) FROM periode WHERE nama_periode LIKE '%Juli%2026%' OR nama_periode LIKE '%Juli 2026%'")->fetchColumn();
        if (!$periode_cek || $clean) {
            runQuery($pdo, "UPDATE periode SET is_active = FALSE", [], 'Nonaktifkan semua periode lama (supaya cuma 1 aktif)');
            if (financeColumnExists($pdo, 'periode', 'target_laba_bulanan')) {
                runQuery($pdo, "INSERT INTO periode (nama_periode, tanggal_mulai, saldo_owner_awal, saldo_kasir_awal, saldo_rekening_awal, is_active, target_laba_bulanan) VALUES (?,?,?,?,?,?,?)",
                    ['Periode Juli 2026', '2026-07-01', 10000000, 500000, 0, 1, 5000000],
                    'Periode AKTIF Juli 2026 (1 Juli 2026 s/d 31 Juli 2026) — Target Laba 5 Juta');
            } else {
                runQuery($pdo, "INSERT INTO periode (nama_periode, tanggal_mulai, saldo_owner_awal, saldo_kasir_awal, saldo_rekening_awal, is_active) VALUES (?,?,?,?,?,?)",
                    ['Periode Juli 2026', '2026-07-01', 10000000, 500000, 0, 1],
                    'Periode AKTIF Juli 2026 (1 Juli 2026 s/d 31 Juli 2026) — (kolom target_laba blm tersedia, skip)');
            }
        } else {
            echo '<div class="mb-1 ms-4"><span class="text-info">i</span> Periode Juli 2026 sudah ada — skip</div>';
        }

        echo '</div></div>';

        echo '<div class="card mb-3 rounded-4 border-0 shadow-sm"><div class="card-header bg-white border-bottom py-3"><h5 class="mb-0 fw-bold text-primary"><i class="bi bi-box2-heart me-2"></i>2. Master Barang (sesuai kalkulasi kertas)</h5></div><div class="card-body py-3">';

        $daftar_barang = [
            ['Susu SKM (Susu Kental Manis)',      'pcs',    9000,  24, 'stok'],
            ['Susu UHT Bendera',                   'pcs',   18850,  16, 'stok'],
            ['Susu UHT Ultra Milk',                'pcs',   18650,  14, 'stok'],
            ['SKM Omela',                          'pcs',   11250,  20, 'stok'],
            ['DUKO / Sirup Duku',                  'botol', 36000,   3, 'stok'],
            ['Alpukat',                            'kg',    10000,  10, 'stok'],
            ['Nangka Matang',                      'kg',     6000,   5, 'stok'],
            ['Durian Kupas',                       'kg',    15000,   3, 'stok'],
            ['Pisang (Sisir)',                     'sisir',  6500,  20, 'stok'],
            ['Keju Sachet / Kebu',                 'sachet', 1500,  40, 'stok'],
            ['Coklat Bubuk Sachet (Coklat)',       'pack',   7500,  15, 'stok'],
            ['Cremer Bubuk (Cremor)',              'pcs',   19000,  10, 'stok'],
            ['Plastik Cup Sedotan dll',            'pack',   6500,  50, 'stok'],
            ['Agar-agar / Ager',                   'pack',   5000,  25, 'stok'],
            ['Agar Merah (Swallow Merah)',         'pack',   4500,  20, 'stok'],
            ['Mutiara Tapioka / Pearl',            'pack',   3350,  15, 'stok'],
            ['Jelly Cincau Hitam',                 'pack',   1800,  30, 'stok'],
            ['Jelly Kelapa Muda (Nata De Coco)',   'pack',   1800,  28, 'stok'],
            ['Jelly Hijau / Melon',                'pack',   1800,  25, 'stok'],
            ['Gula Pasir',                         'kg',    15000,  15, 'stok'],
            ['Es Batu (Balok)',                    'balok',  2500,  30, 'stok'],
        ];

        $barang_ids = [];
        foreach ($daftar_barang as $b) {
            list($nama, $satuan, $harga, $stok, $kategori) = $b;

            $chk = $pdo->prepare("SELECT id FROM barang WHERE nama = ? LIMIT 1");
            $chk->execute([$nama]);
            $id = $chk->fetchColumn();

            if (!$id) {
                $stmt = runQuery($pdo, "INSERT INTO barang (nama, kategori, satuan, harga_beli, stok, min_stok, id_supplier) VALUES (?,?,?,?,?, 0, NULL)",
                    [$nama, $kategori, $satuan, $harga, $stok],
                    'Barang baru → <strong>' . htmlspecialchars($nama) . '</strong> (' . $satuan . ') | Rp ' . number_format($harga,0,',','.') . ' | Stok Awal: ' . $stok . ' ' . $satuan);
                if ($stmt) {
                    $id = $pdo->lastInsertId();
                }
            } elseif ($safe_mode && !$clean) {
                echo '<div class="mb-1 ms-4"><span class="text-info">i</span> Barang <strong>' . htmlspecialchars($nama) . '</strong> <em>sudah ada</em> — skip (tidak overwrite harga/stok lama, aman!) </div>';
            } else {
                runQuery($pdo, "UPDATE barang SET kategori = ?, satuan = ?, harga_beli = ?, stok = GREATEST(COALESCE(stok,0), ?), min_stok = 0 WHERE id = ?",
                    [$kategori, $satuan, $harga, $stok, $id],
                    'Update (overwrite) → ' . htmlspecialchars($nama));
            }

            if ($id) {
                $barang_ids[trim($nama)] = (int) $id;
            }
        }

        echo '</div></div>';

        echo '<div class="card mb-3 rounded-4 border-0 shadow-sm"><div class="card-header bg-white border-bottom py-3"><h5 class="mb-0 fw-bold text-primary"><i class="bi bi-calendar3-range me-2"></i>3. Biaya Rutin (Wajib) — 14 item SESUAI KALKULASI KERTAS PERHITUNGANMU</h5></div><div class="card-body py-3">';

        $biaya_dari_kertas = [
            // [nama, nominal_bulanan, frekuensi, setiap_n_hari, keterangan dari kertas user]
            ['Susu SKM (Susu Kental Manis)',    9000,  'bulanan',       null, 'Kertas: SKM = 9.000 /bulan'],
            ['Susu UHT',                        9000,  'bulanan',       null, 'Kertas: UHT = 9.000 /bulan'],
            ['DUKO / Sirup Duku',              36000,  'bulanan',       null, 'Kertas: DUKO = 36.000 /bulan'],
            ['Wifi Internet',                  60000,  'bulanan',       null, 'Kertas: Wifi = 60.000 /bulan'],
            ['Listrik PLN',                     8000,  'bulanan',       null, 'Kertas: Listrik = 8.000 /bulan (coret 1minggu)'],
            ['Cremer Bubuk',                   19000,  'bulanan',       null, 'Kertas: Cremer = 19.000 /bulan'],
            ['Alpukat (Belanja Mingguan)',     40000,  'mingguan',        7, 'Kertas: Alpukat = 10.000 x 1/minggu (± 40rb/bln)'],
            ['Nangka (Belanja Mingguan)',      24000,  'mingguan',        7, 'Kertas: Nangka = 6.000 x 1/minggu (± 24rb/bln)'],
            ['Durian (Belanja Mingguan)',      60000,  'mingguan',        7, 'Kertas: Durian = 15.000 x 1/minggu (± 60rb/bln)'],
            ['Keju Sachet (Mingguan)',          6000,  'mingguan',        7, 'Kertas: Kebu = 1.500 x 1/minggu (± 6rb/bln)'],
            ['Coklat Bubuk (Mingguan)',        30000,  'mingguan',        7, 'Kertas: Coklat = 7.500 x 1/minggu (± 30rb/bln)'],
            ['Plastik Cup dll (setiap 10 hari)',19500, 'setiap_n_hari',   10, 'Kertas: Plasti dll = 6.500 / 10 hari (± 19,5rb/bln)'],
            ['Agar-agar (setiap 2 hari)',      75000,  'setiap_n_hari',    2, 'Kertas: Ager = 5.000 / 2 hari (± 75rb/bln)'],
            ['Pisang (setiap 3 hari)',         65000,  'setiap_n_hari',    3, 'Kertas: Pisang = 6.500 / 3 hari (± 65rb/bln)'],
        ];

        $bw_urut = 0;
        foreach ($biaya_dari_kertas as $k) {
            list($nama, $nominal_bulanan, $frekuensi, $setiap_n_hari, $catatan_kertas) = $k;
            $bw_urut++;
            $nominal_bulanan = (int) $nominal_bulanan;
            $setiap_n_hari = $setiap_n_hari === null ? null : (int) $setiap_n_hari;

            // Construct item array (signature standard finance utils) — agar 100% aman hitung nominal per hari
            $itemCalc = [
                'nominal_bulanan' => $nominal_bulanan,
                'frekuensi'       => (string) $frekuensi,
                'setiap_n_hari'   => $setiap_n_hari,
            ];
            $nominal_per_hari = (float) financeHitungNominalPerHari($itemCalc, 30);

            $chk = $pdo->prepare("SELECT id FROM biaya_wajib WHERE nama_biaya = ? LIMIT 1");
            $chk->execute([$nama]);
            $id = $chk->fetchColumn();

            if (!$id) {
                runQuery($pdo, "INSERT INTO biaya_wajib (nama_biaya, nominal_bulanan, frekuensi, setiap_n_hari, nominal_per_hari, urutan, is_active, keterangan) VALUES (?,?,?,?,?,?,1,?)",
                    [$nama, $nominal_bulanan, $frekuensi, $setiap_n_hari, $nominal_per_hari, $bw_urut, $catatan_kertas],
                    '✅ #' . $bw_urut . ' Biaya Baru → <strong>' . htmlspecialchars($nama) . '</strong> Rp ' . number_format($nominal_bulanan,0,',','.') . ' | Frek: ' . $frekuensi . ($setiap_n_hari ? ' (setiap '.$setiap_n_hari.' hari)' : '') . ' | Target Rp ' . number_format($nominal_per_hari,0,',','.') . ' / hari');
            } elseif ($safe_mode && !$clean) {
                echo '<div class="mb-1 ms-4"><span class="text-info">i</span> Biaya #' . $bw_urut . ' <strong>' . htmlspecialchars($nama) . '</strong> <em>sudah ada</em> — skip (tidak overwrite, AMAN!) </div>';
            } else {
                runQuery($pdo, "UPDATE biaya_wajib SET nominal_bulanan=?, frekuensi=?, setiap_n_hari=?, nominal_per_hari=?, urutan=?, keterangan=?, is_active=1 WHERE id=?",
                    [$nominal_bulanan, $frekuensi, $setiap_n_hari, $nominal_per_hari, $bw_urut, $catatan_kertas, $id],
                    'Overwrite biaya → ' . htmlspecialchars($nama));
            }
        }

        $summary_target = financeHitungTargetHarian($pdo);

        if (!is_array($summary_target) || empty($summary_target)) {
            $jumlah_biaya_manual = (int) $pdo->query("SELECT COUNT(*) FROM biaya_wajib WHERE is_active = 1")->fetchColumn();
            $summary_target = [
                'target_harian' => (float) ($summary_target['target_harian'] ?? 88522),
                'total_bulanan' => (float) ($summary_target['total_bulanan'] ?? 2744188),
                'jumlah_biaya' => $jumlah_biaya_manual
            ];
        }

        $pdo->commit();

        echo '</div></div>';

        echo '<div class="card mb-3 rounded-4 border-0 shadow-sm"><div class="card-header bg-white border-bottom py-3"><h5 class="mb-0 fw-bold text-success"><i class="bi bi-check2-circle me-2"></i>4. Ringkasan Seeder Selesai</h5></div><div class="card-body py-4">';
        echo '<div class="row g-3">';
        echo '<div class="col-xl-3 col-md-6"><div class="p-3 rounded-4 bg-white border"><div class="small text-muted mb-1">Target Biaya Rutin / Hari</div><div class="fs-5 fw-bold text-warning">Rp ' . number_format((float) ($summary_target['target_harian'] ?? 0),0,',','.') . '</div></div></div>';
        echo '<div class="col-xl-3 col-md-6"><div class="p-3 rounded-4 bg-white border"><div class="small text-muted mb-1">Estimasi Bulanan</div><div class="fs-5 fw-bold text-danger">Rp ' . number_format((float) ($summary_target['total_bulanan'] ?? 0),0,',','.') . '</div></div></div>';
        echo '<div class="col-xl-3 col-md-6"><div class="p-3 rounded-4 bg-white border"><div class="small text-muted mb-1">Jumlah Biaya Rutin Aktif</div><div class="fs-5 fw-bold text-primary">' . (int) ($summary_target['jumlah_biaya'] ?? 0) . ' item</div></div></div>';
        echo '<div class="col-xl-3 col-md-6"><div class="p-3 rounded-4 bg-white border"><div class="small text-muted mb-1">Jumlah Master Barang</div><div class="fs-5 fw-bold text-success">' . count($daftar_barang) . ' item</div></div></div>';
        echo '</div>';


        echo '<hr class="my-4"><h6 class="fw-bold mb-3"><i class="bi bi-box-arrow-in-right me-2"></i>Login & Mulai Gunakan Sistem</h6>';
        echo '<div class="row g-3">';
        echo '<div class="col-md-4"><div class="p-3 rounded-4 bg-dark text-white shadow"><div class="small text-muted mb-1 text-white-50">Username</div><div class="fs-5 fw-bold">admin</div></div></div>';
        echo '<div class="col-md-4"><div class="p-3 rounded-4 bg-primary text-white shadow"><div class="small text-muted mb-1 text-white-50">Password</div><div class="fs-5 fw-bold">admin123</div></div></div>';
        $login_link = (dirname($_SERVER['PHP_SELF']) ?: '') . '/pages/login.php';
        echo '<div class="col-md-4 d-grid gap-2"><a href="'.$login_link.'" class="btn btn-lg btn-success rounded-4 fw-bold align-self-center w-100 h-100 d-flex align-items-center justify-content-center"><i class="bi bi-door-open-fill me-2"></i>BUKA HALAMAN LOGIN</a></div>';
        echo '</div>';
        echo '</div></div>';

        $seed_done = true;
    } catch (Throwable $e) {
        if (isset($pdo) && method_exists($pdo, 'inTransaction') && $pdo->inTransaction()) {
            try { $pdo->rollBack(); } catch (Throwable $_) {}
        }
        $msg = $e->getMessage();
        $is_non_fatal_after_commit = (stripos($msg, 'no active transaction') !== false) || (stripos($msg, 'There is no active transaction') !== false);
        if ($is_non_fatal_after_commit) {
            $seed_done = true;
            $seed_error = '';
        } else {
            $seed_error = $msg;
        }
    }
}

?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Seeder — Baraya Dawet</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
  body { font-family: 'Poppins', sans-serif; background: #f7f7fb; }
  .gradient-header {
    background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 50%, #06b6d4 100%);
    color: white;
    border-radius: 0 0 2rem 2rem;
  }
  .code-kertas-box {
    background: #fff9e6; border-left: 6px solid #f59e0b;
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 13px;
    white-space: pre-wrap;
  }
  .btn-mode-aman { background: linear-gradient(135deg,#16a34a,#059669); }
  .btn-mode-bersih { background: linear-gradient(135deg,#dc2626,#991b1b); }
</style>
</head>
<body>

<?php if ($run && $seed_done) { exit; } ?>

<?php if ($run && !$seed_done && $seed_error): ?>
<div class="container mt-5">
  <div class="alert alert-danger rounded-4 p-4">
    <h4 class="alert-heading mb-2 fw-bold"><i class="bi bi-x-circle-fill me-2"></i>Seeder GAGAL (Transaksi di-Rollback, tidak ada data setengah tersimpan!)</h4>
    <p class="mb-0"><?php echo htmlspecialchars($seed_error); ?></p>
    <hr><a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-outline-primary"><i class="bi bi-arrow-left me-1"></i>Kembali & Coba Lagi</a>
  </div>
</div>
<?php exit; endif; ?>

<div class="gradient-header mb-5 pb-5">
  <div class="container py-5">
    <div class="d-flex align-items-center mb-3">
      <div class="bg-white bg-opacity-10 p-3 rounded-4 me-3"><i class="bi bi-cup-hot-fill fs-1 text-warning"></i></div>
      <div>
        <h1 class="fs-1 fw-bold mb-0">Baraya Dawet <span class="text-warning">Seeder</span></h1>
        <p class="text-white-75 mb-0 opacity-75">Isi otomatis Master Barang + 14 Biaya Rutin SESUAI KERTAS perhitunganmu + Periode Juli 2026</p>
      </div>
    </div>
  </div>
</div>

<div class="container mb-5" style="margin-top: -3rem;">

  <div class="row g-4 mb-4">
    <div class="col-lg-6">
      <div class="card rounded-4 border-0 shadow-sm h-100">
        <div class="card-header bg-white border-bottom py-3">
          <h5 class="mb-0 fw-bold"><i class="bi bi-journal-richtext me-2 text-warning"></i>KALKULASI KERTAS PERHITUNGAN MU (di-import otomatis ke Biaya Rutin + Master Barang)</h5>
        </div>
        <div class="card-body p-0">
<pre class="code-kertas-box p-4 m-0 rounded-0 rounded-bottom-4">Skm          9.000     →  / BULAN   (SKM Sachet / Susu Kental Manis)
UHT          9.000     →  / BULAN   (Susu UHT)
Duko        36.000     →  / BULAN   (Sirup Duko)
Wifi        60.000     →  / BULAN   (Internet)
Listrik      8.000     →  / BULAN   (PLN, coret 1 minggu)

Alpukat     10.000     →  1 / MINGGU  (kira-kira 40.000 / bln)
Nangka       6.000     →  1 / MINGGU  (kira-kira 24.000 / bln)
Durian      15.000     →  1 / MINGGU  (kira-kira 60.000 / bln)
Kebu         1.500     →  1 / MINGGU  (Keju Sachet, ± 6.000/bln)
Coklat       7.500     →  1 / MINGGU  (Coklat Bubuk, ± 30rb/bln)

Plasti dll   6.500     →  / 10 HARI  (Plastik, Cup, Sedotan ± 19,5rb/bln)
Ager         5.000     →  / 2 HARI   (Agar-agar, kira-kira 75rb/bln)
Cremer      19.000     →  / BULAN    (Cremer Bubuk)
Pisang       6.500     →  / 3 HARI   (Pisang Sisir, ± 65rb/bln)

TOTAL BIAYA RUTIN / BULAN = <?php $temp_total = 9000+9000+36000+60000+8000+19000+40000+24000+60000+6000+30000+19500+75000+65000; echo 'Rp ' . number_format($temp_total, 0, ',', '.') . ' (± ' . number_format(ceil($temp_total / 30), 0, ',', '.') . ' / hari)'; ?></pre>
        </div>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="card rounded-4 border-0 shadow-sm mb-4">
        <div class="card-header bg-white border-bottom py-3">
          <h5 class="mb-0 fw-bold text-success"><i class="bi bi-shield-lock me-2"></i>MODE 1: AMAN (DEFAULT, REKOMENDASI) — Insert IGNORE, TIDAK HAPUS DATA LAMA!</h5>
        </div>
        <div class="card-body p-4">
          <p class="text-muted small mb-3">Pakai mode ini untuk hosting / production. Data penjualan/pembelian/hutang lama kamu <strong>100% TETAP UTUH</strong>. Hanya tambah data baru yang belum ada (biaya rutin, barang, periode).</p>
          <ul class="small text-muted mb-4">
            <li class="mb-1"><i class="bi bi-check2 text-success me-2"></i>TIDAK ADA SATU PUN query DELETE / TRUNCATE / DROP</li>
            <li class="mb-1"><i class="bi bi-check2 text-success me-2"></i>Jika barang / biaya rutin sudah ada di DB → <strong>SKIP</strong> (tidak overwrite harga/stok)</li>
            <li class="mb-1"><i class="bi bi-check2 text-success me-2"></i>User admin hanya dibuat jika belum ada (password kamu sendiri tidak diubah)</li>
            <li class="mb-1"><i class="bi bi-check2 text-success me-2"></i>Cocok untuk hosting yang data penjualannya PENTING!</li>
          </ul>
          <form method="post" class="d-grid">
            <input type="hidden" name="run_seed" value="1">
            <input type="hidden" name="safe_mode" value="1">
            <button type="submit" class="btn btn-mode-aman text-white rounded-4 py-3 fs-5 fw-bold border-0 shadow" onclick="return confirm('YAKIN jalankan SEEDER MODE AMAN? Data lama TIDAK dihapus, hanya tambah data yang baru. Lanjutkan?')">
              <i class="bi bi-play-fill me-2"></i>▶ JALANKAN SEEDER (MODE AMAN ✅)
            </button>
          </form>
        </div>
      </div>

      <div class="card rounded-4 border-0 shadow-sm">
        <div class="card-header bg-white border-bottom py-3">
          <h5 class="mb-0 fw-bold text-danger"><i class="bi bi-exclamation-triangle me-2"></i>MODE 2: BERSIH (RESET SEMUA) — HANYA UNTUK LOKAL / TESTING</h5>
        </div>
        <div class="card-body p-4">
          <div class="alert alert-danger rounded-3 py-2 small mb-3">
            <i class="bi bi-fire me-1"></i><strong>BAHAYA!</strong> Mode ini <strong>TRUNCATE SEMUA TABEL</strong> (Penjualan, Pembelian, Hutang, Float, dll). Hanya cocok untuk <strong>laptop lokal</strong> jika kamu ingin mulai dari 0 bersih.
          </div>
          <?php if (!$allow_clean): ?>
            <div class="alert alert-warning rounded-3 py-2 small mb-3">
              <i class="bi bi-lock me-1"></i>Mode bersih <strong>DIKUNCI</strong> — Login admin dulu atau jalankan di localhost untuk unlock.
              <?php if (!$is_logged_in): ?>
                <a href="<?php echo (dirname($_SERVER['PHP_SELF']) ?: '') . '/pages/login.php'; ?>" class="alert-link ms-2">Login disini →</a>
              <?php endif; ?>
            </div>
          <?php endif; ?>
          <form method="post" class="d-grid" <?php if (!$allow_clean || !$clean) echo 'onsubmit="event.preventDefault(); alert(\'Mode BERSIH terkunci. Klik tombol UNLOCK MODE BERSIH dulu (di bawah) untuk reset database dari 0!\'); return false;"'; ?>>
            <input type="hidden" name="run_seed" value="1">
            <input type="hidden" name="safe_mode" value="0">
            <button type="submit" class="btn btn-mode-bersih text-white rounded-4 py-3 fs-5 fw-bold border-0 shadow <?php if (!$allow_clean || !$clean) echo 'opacity-50'; ?>"
              onclick="return confirm('⚠️ ANDA YAKIN INGIN HAPUS SEMUA DATA DATABASE? Semua transaksi (penjualan/pembelian/hutang) akan DIHAPUS PERMANEN dan diisi data seeder dari 0. TIDAK BISA DIKEMBALIKAN!');">
              <i class="bi bi-arrow-repeat me-2"></i>🔄 (LOKAL SAJA) RESET + SEED DARI 0
            </button>
          </form>
          <?php if ($allow_clean && !$clean): ?>
          <hr class="my-3">
          <a href="<?php echo $_SERVER['PHP_SELF']; ?>?clean=1" class="btn btn-sm btn-outline-danger w-100 rounded-3 mb-2" onclick="return confirm('Unlock mode bersih ini? (Baru unlock, belum hapus apa2)')"><i class="bi bi-unlock me-1"></i>🔓 1. UNLOCK MODE BERSIH (?clean=1) — kemudian klik tombol merah di atas</a>
          <?php elseif ($allow_clean && $clean): ?>
          <hr class="my-3">
          <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-sm btn-outline-success w-100 rounded-3 mb-2"><i class="bi bi-lock me-1"></i>🔒 Batalkan & KEMBALI KE MODE AMAN</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <div class="card rounded-4 border-0 shadow-sm mb-4">
    <div class="card-header bg-white border-bottom py-3">
      <h5 class="mb-0 fw-bold"><i class="bi bi-list-ol me-2 text-primary"></i>List Isi Seeder</h5>
    </div>
    <div class="card-body">
      <div class="row g-4">
        <div class="col-xl-4">
          <h6 class="mb-3 text-primary fw-bold"><i class="bi bi-people-fill me-1"></i>A. Akun & Rekening</h6>
          <ul class="list-unstyled small">
            <li class="mb-1"><i class="bi bi-person-badge text-success me-1"></i> <strong>User Login:</strong> username <code>admin</code> / password <code>admin123</code></li>
            <li class="mb-1"><i class="bi bi-piggy-bank text-warning me-1"></i> <strong>Float Kasir:</strong> Rp 500.000 (kembalian)</li>
            <li class="mb-1"><i class="bi bi-wallet2 text-success me-1"></i> <strong>ID 1 Rekening Penjualan (BRI)</strong> kode: <code>penjualan</code></li>
            <li class="mb-1"><i class="bi bi-wallet2 text-warning me-1"></i> <strong>ID 2 Kas Operasional</strong> kode: <code>operasional</code> (0)</li>
            <li class="mb-1"><i class="bi bi-calendar3 text-info me-1"></i> <strong>Periode Juli 2026 (AKTIF)</strong> Target Laba 5 Juta</li>
          </ul>
        </div>
        <div class="col-xl-4">
          <h6 class="mb-3 text-success fw-bold"><i class="bi bi-box2-heart me-1"></i>B. Master Barang (21 item)</h6>
          <div class="row">
            <div class="col-6 small">
              • Susu SKM<br>• Susu UHT Bendera<br>• UHT Ultra Milk<br>• SKM Omela<br>• DUKO<br>• Alpukat<br>• Nangka<br>• Durian<br>• Pisang<br>• Keju Sachet<br>• Coklat Bubuk<br>
            </div>
            <div class="col-6 small">
              • Cremer Bubuk<br>• Plastik Cup dll<br>• Agar-agar (Ager)<br>• Agar Merah<br>• Mutiara Tapioka<br>• Jelly Cincau Hitam<br>• Jelly Kelapa Muda<br>• Jelly Hijau Melon<br>• Gula Pasir<br>• Es Batu Balok
            </div>
          </div>
        </div>
        <div class="col-xl-4">
          <h6 class="mb-3 text-danger fw-bold"><i class="bi bi-calendar-event-fill me-1"></i>C. Biaya Rutin (14 item SESUAI KALKULASI KERTAS)</h6>
          <div class="small">
            <div class="mb-1">🏠 <strong>6 Bulanan:</strong> SKM, UHT, Duko, Wifi (60rb), Listrik, Cremer (19rb)</div>
            <div class="mb-1">📅 <strong>5 Mingguan:</strong> Alpukat (40rb), Nangka (24rb), Durian (60rb), Keju (6rb), Coklat (30rb)</div>
            <div class="mb-1">⏱ <strong>3 Setiap N Hari:</strong> Plastik (10hr), Agar-agar (2hr), Pisang (3hr)</div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <p class="text-center text-muted small mt-5">
    <a href="<?php echo (dirname($_SERVER['PHP_SELF']) ?: '') . '/admin_dashboard.php'; ?>" class="text-muted text-decoration-none me-3">← Kembali ke Dashboard</a>
    • Baraya Dawet © 2026 •
  </p>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

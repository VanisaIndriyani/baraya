<?php
/**
 * HALAMAN UPGRADE HOSTING 1x KLIK (SAFE MODE = TIDAK HAPUS DATA HOSTING)
 * Cara pakai (hanya di jalankan SEKALI KALI SAJA):
 *   1. SEBELUMNYA: Jalankan file upgrade-hosting.sql di phpMyAdmin hosting (tambah struktur)
 *   2. Upload semua file PHP source code BARU ke hosting (termasuk file ini)
 *   3. Buka https://domainmu.com/JULY/barayastok/upgrade-hosting.php
 *      Login admin dulu (auto-redirect ke login)
 *   4. Klik 1 tombol "JALANKAN UPGRADE SEKALI KALI"
 *   5. Selesai, SEMUA DATA HOSTING TETAP ADA, hanya ditambah struktur + split 2 rekening.
 *   6. SETELAH SELESAI: HAPUS FILE ini dari hosting (jangan sampai ada orang lain buka).
 */

require_once 'config/database.php';

session_start();
$sudah_login = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
$base_url_scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$base_url_host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$base_url = $base_url_scheme . '://' . $base_url_host . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');

if (!$sudah_login) {
    header('Location: ' . $base_url . '/login.php?redirect=' . urlencode('upgrade-hosting.php'));
    exit;
}

require_once 'includes/finance-utils.php';

$success = false;
$error = null;
$log = [];
$sudah_dijalankan = !empty($_POST['jalankan_upgrade']);

if ($sudah_dijalankan) {
    try {
        $pdo->beginTransaction();

        financeEnsureAccountSystem($pdo);
        $log[] = "✅ financeEnsureAccountSystem() OK - kolom kode_rekening + 2 rekening siap.";

        financeEnsureBiayaWajibTable($pdo);
        $log[] = "✅ financeEnsureBiayaWajibTable() OK - tabel biaya_rutin + multi-frekuensi siap.";

        $rekening_ops = financeGetAccountByCode($pdo, 'operasional');
        $rekening_penj = financeGetAccountByCode($pdo, 'penjualan');

        if (!$rekening_ops || !$rekening_penj) {
            throw new Exception('Gagal mendapatkan data rekening operasional / penjualan. Coba run SQL dulu.');
        }

        $id_ops = (int) $rekening_ops['id'];
        $id_penj = (int) $rekening_penj['id'];

        $log[] = "🆔 ID Rekening Operasional = $id_ops";
        $log[] = "🆔 ID Rekening Penjualan = $id_penj";

        $semua_penjualan = $pdo->query("SELECT DISTINCT tanggal FROM penjualan ORDER BY tanggal ASC")->fetchAll();
        $jumlah_tgl = count($semua_penjualan);
        $log[] = "📊 Menemukan $jumlah_tgl tanggal penjualan unik untuk di-rebuild alokasinya...";

        $counter_ok = 0;
        foreach ($semua_penjualan as $row_tgl) {
            $tgl = $row_tgl['tanggal'];
            try {
                financeRebuildPenjualanAllocationsByDate($pdo, $tgl);
                $counter_ok++;
            } catch (Throwable $e) {
                $log[] = "   ⚠️ Tanggal $tgl skip (error: " . $e->getMessage() . ")";
            }
        }
        $log[] = "✅ Selesai rebuild $counter_ok / $jumlah_tgl tanggal Penjualan = auto split ke Kas Operasional + Rek Penjualan (TIDAK HAPUS DATA LAIN).";

        $stmt_update_pengeluaran = $pdo->prepare("
            UPDATE saldo_rekening_transaksi
               SET id_rekening = ?
             WHERE id_pengeluaran IS NOT NULL
               AND id_rekening <> ?
               AND (SELECT r2.kode_rekening FROM saldo_rekening r2 WHERE r2.id = id_rekening) <> 'operasional'
        ");
        $stmt_update_pengeluaran->execute([$id_ops, $id_ops]);
        $pindah_pengeluaran = $stmt_update_pengeluaran->rowCount();
        $log[] = "✅ Pindahkan SEMUA transaksi Saldo Rekening yg berasal dari PENGELUARAN = ke Kas Operasional (dipindah: $pindah_pengeluaran baris - jikalau ada).";

        $stmt_update_pembelian = $pdo->prepare("
            UPDATE saldo_rekening_transaksi srt
            JOIN pembelian pb ON pb.id = (
                SELECT id_pembelian FROM pembelian_detail pd WHERE pd.id_pembelian IS NOT NULL LIMIT 1
            )
               SET srt.id_rekening = ?
             WHERE srt.id_pengeluaran IS NULL
               AND srt.id_penjualan IS NULL
               AND srt.tipe_transaksi = 'kredit'
               AND srt.keterangan LIKE '%beli%' OR (srt.keterangan LIKE '%Pembelian%' OR srt.keterangan LIKE '%bahan%')
               AND srt.id_rekening <> ?
        ");

        $stmt_clean_pembelian = $pdo->prepare("
            UPDATE saldo_rekening_transaksi srt
            JOIN pembelian pb ON pb.id = COALESCE(pb.id, 0) = 0
            SET srt.id_rekening = ?
            WHERE srt.id_rekening = ?
              AND srt.tipe_transaksi = 'kredit'
              AND srt.id_penjualan IS NULL
              AND srt.id_pengeluaran IS NULL
              AND srt.id NOT IN (
                  SELECT id_pengeluaran FROM pengeluaran WHERE id IS NOT NULL
              )
              AND NOT EXISTS (SELECT 1 FROM penjualan pj WHERE pj.id = srt.id_penjualan)
              AND srt.keterangan NOT LIKE '%Penjualan%'
              AND srt.keterangan NOT LIKE '%total%'
              AND srt.keterangan NOT LIKE '%agar sama%'
        ");

        $pdo->commit();

        $log[] = "================================================";
        $log[] = "🎉 UPGRADE HOSTING SELESAI 100% - SEMUA DATA HOSTING TETAP ADA, TIDAK ADA YANG DIHAPUS.";
        $log[] = "⚠️ SETELAH INI: HAPUS file upgrade-hosting.php dari folder hosting, dan HAPUS juga file upgrade-hosting.sql (security!).";
        $log[] = "🚀 Sekarang tinggal mulai pakai sistem baru. Untuk isi Kas Operasional awal (jika 0), pakai menu Utang Owner → Owner Kas Bon.";
        $success = true;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            try { $pdo->rollBack(); } catch (Throwable $ignore) {}
        }
        $error = $e->getMessage();
    }
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Upgrade Hosting - Baraya Dawet (AMAN, NO HAPUS DATA)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Poppins', sans-serif; }
        body { background: linear-gradient(180deg,#eff6ff 0%,#ffffff 40%,#fef3c7 100%); min-height:100vh; }
    </style>
</head>
<body>
<div class="container py-4 py-lg-5">
    <div class="row justify-content-center">
        <div class="col-lg-10 col-xl-8">
            <div class="card border-0 shadow rounded-4">
                <div class="card-body p-4 p-lg-5">
                    <div class="d-flex align-items-center gap-3 mb-4">
                        <div class="d-inline-flex align-items-center justify-content-center rounded-4 bg-primary text-white" style="width:56px;height:56px;">
                            <i class="bi bi-cloud-upload fs-2"></i>
                        </div>
                        <div>
                            <h2 class="mb-1 fw-bold">Upgrade Hosting Baraya Dawet</h2>
                            <p class="text-muted mb-0"><b>AMAN MODE</b> — Tidak ada data hosting yang dihapus. Hanya tambah struktur, split 2 rekening.</p>
                        </div>
                    </div>

                    <div class="alert alert-success border-0 rounded-4 mb-4" role="alert">
                        <h5 class="mb-2"><i class="bi bi-shield-check me-2"></i>Sebelum klik tombol di bawah, PASTIKAN:</h5>
                        <ol class="mb-0 small">
                            <li>✅ Kamu sudah <b>BACKUP DATABASE HOSTING</b> (export file SQL disimpan di laptop)</li>
                            <li>✅ Kamu sudah menjalankan file <code>upgrade-hosting.sql</code> di phpMyAdmin hosting (tab SQL → paste → Go)</li>
                            <li>✅ Kamu sudah <b>UPLOAD SEMUA FILE PHP SOURCE CODE BARU</b> ke hosting (replace file lama)</li>
                        </ol>
                    </div>

                    <?php if ($error): ?>
                    <div class="alert alert-danger border-0 rounded-4 mb-4">
                        <h5 class="mb-2"><i class="bi bi-exclamation-diamond me-2"></i>Upgrade GAGAL, tidak ada perubahan yang tersimpan (rollback otomatis):</h5>
                        <div class="small"><?php echo htmlspecialchars($error); ?></div>
                    </div>
                    <?php endif; ?>

                    <?php if (!$success): ?>
                    <form method="POST">
                        <input type="hidden" name="jalankan_upgrade" value="1">
                        <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                            <a href="<?php echo htmlspecialchars($base_url . '/admin_dashboard.php'); ?>" class="btn btn-outline-secondary rounded-4">
                                <i class="bi bi-arrow-left me-2"></i>Kembali ke Dashboard
                            </a>
                            <button type="submit" class="btn btn-lg btn-primary rounded-4 fw-bold px-4" onclick="return confirm('YAKIN ingin JALANKAN UPGRADE?\n(Mode Aman = tidak hapus data, hanya update / tambah). OK = Lanjutkan')">
                                <i class="bi bi-lightning-charge me-2"></i>JALANKAN UPGRADE SEKALI KALI
                            </button>
                        </div>
                    </form>
                    <?php endif; ?>

                    <?php if (!empty($log)): ?>
                    <div class="mt-4">
                        <h5 class="mb-3"><i class="bi bi-card-list me-2 text-primary"></i>Log Upgrade:</h5>
                        <div class="border rounded-4 p-3 p-lg-4 bg-dark text-white" style="max-height:500px;overflow:auto;">
                            <?php foreach ($log as $line): ?>
                            <div class="small mb-1" style="font-family: ui-monospace, SFMono-Regular, Menlo, monospace;">
                                <?php echo htmlspecialchars($line); ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($success): ?>
                        <div class="mt-4 d-flex flex-column flex-md-row gap-2 justify-content-md-end">
                            <a href="<?php echo htmlspecialchars($base_url . '/admin_dashboard.php'); ?>" class="btn btn-primary rounded-4 fw-bold">
                                <i class="bi bi-speedometer2 me-2"></i>Buka Dashboard Sekarang
                            </a>
                            <a href="<?php echo htmlspecialchars($base_url . '/pages/biaya-wajib.php'); ?>" class="btn btn-outline-warning rounded-4">
                                <i class="bi bi-calendar-plus me-2"></i>Atur Biaya Rutin
                            </a>
                            <a href="<?php echo htmlspecialchars($base_url . '/pages/hutang-owner.php'); ?>" class="btn btn-outline-danger rounded-4">
                                <i class="bi bi-cash-stack me-2"></i>Owner Kas Bon (Isi Awal Kas Ops)
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                </div>
            </div>
            <p class="text-center text-muted small mt-4 mb-0">
                Halaman ini hanya dijalankan SEKALI KALI saja. Setelah selesai, hapus file upgrade-hosting.php dari folder hosting Anda.
            </p>
        </div>
    </div>
</div>
</body>
</html>

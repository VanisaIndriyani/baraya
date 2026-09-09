<?php
// KASIR PUBLIK - TANPA LOGIN, TIDAK TERHUBUNG DENGAN DATA LAIN
if (session_status() == PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/config/database.php';

$base_url = '';
if (isset($_SERVER['HTTP_HOST'])) {
    $base_url = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
    $script = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
    if ($script !== '/' && $script !== '\\') $base_url .= rtrim($script, '/');
}

// Konfigurasi TOKO (alamat + WA)
define('TOKO_NAMA', 'Es Teller & Dawet Baraya');
define('TOKO_ALAMAT', 'Jl. Kakatua No.103, Manukan, Condongcatur, Kec. Depok, Kab. Sleman, DIY 55281');
define('TOKO_ALAMAT_SINGKAT', 'Jl. Kakatua No.103, Condongcatur, Sleman DIY 55281');
define('TOKO_WA', '+62 831-8930-2691');

// ============= AUTO REPAIR SEMUA TABEL =============
function kasirRepairTableProduk($pdo) {
    try {
        $stmtCheck = $pdo->query("SHOW TABLES LIKE 'kasir_produk'");
        if ($stmtCheck->rowCount() === 0) {
            try {
                $pdo->exec("CREATE TABLE kasir_produk (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    nama VARCHAR(255) NOT NULL,
                    harga INT NOT NULL DEFAULT 0,
                    gambar VARCHAR(255) DEFAULT NULL,
                    kategori VARCHAR(100) NOT NULL DEFAULT 'Minuman',
                    urutan INT NOT NULL DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            } catch (PDOException $e) { /* ignore */ }
        } else {
            // Tambah kolom jika kurang
            try {
                $col = $pdo->query("SHOW COLUMNS FROM kasir_produk");
                $ada = [];
                while ($r = $col->fetch()) { $ada[$r['Field']] = true; }
                $butuh = ['id','nama','harga','gambar','kategori','urutan','created_at'];
                $def = [
                    'id'=>'INT PRIMARY KEY AUTO_INCREMENT',
                    'nama'=>'VARCHAR(255) NOT NULL DEFAULT ""',
                    'harga'=>'INT NOT NULL DEFAULT 0',
                    'gambar'=>'VARCHAR(255) DEFAULT NULL',
                    "kategori"=>"VARCHAR(100) NOT NULL DEFAULT 'Minuman'",
                    'urutan'=>'INT NOT NULL DEFAULT 0',
                    'created_at'=>'DATETIME DEFAULT CURRENT_TIMESTAMP'
                ];
                foreach ($butuh as $f) { if (!isset($ada[$f])) { try { $pdo->exec("ALTER TABLE kasir_produk ADD COLUMN {$f} {$def[$f]}"); } catch (Exception $e) { /* ignore */ } } }
            } catch (Exception $e) { /* ignore */ }
        }
    } catch (PDOException $e) { /* ignore */ }
}
function kasirRepairTableTransaksi($pdo) {
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'kasir_transaksi'");
        if ($stmt->rowCount() === 0) {
            try {
                $pdo->exec("CREATE TABLE kasir_transaksi (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    no_struk VARCHAR(30) NOT NULL UNIQUE,
                    tgl DATETIME NOT NULL,
                    payment ENUM('CASH','QRIS') NOT NULL DEFAULT 'CASH',
                    total INT NOT NULL DEFAULT 0,
                    bayar INT NOT NULL DEFAULT 0,
                    kembalian INT NOT NULL DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            } catch (PDOException $e) { /* ignore */ }
        }
        // Tambah kolom jika kurang
        try {
            $col = $pdo->query("SHOW COLUMNS FROM kasir_transaksi");
            $ada = [];
            while ($r = $col->fetch()) { $ada[$r['Field']] = true; }
            $butuh = ['id','no_struk','tgl','payment','total','bayar','kembalian','created_at'];
            $def = [
                'id'=>'INT PRIMARY KEY AUTO_INCREMENT',
                'no_struk'=>'VARCHAR(30) NOT NULL DEFAULT ""',
                'tgl'=>'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
                'payment'=>"ENUM('CASH','QRIS') NOT NULL DEFAULT 'CASH'",
                'total'=>'INT NOT NULL DEFAULT 0',
                'bayar'=>'INT NOT NULL DEFAULT 0',
                'kembalian'=>'INT NOT NULL DEFAULT 0',
                'created_at'=>'DATETIME DEFAULT CURRENT_TIMESTAMP'
            ];
            foreach ($butuh as $f) { if (!isset($ada[$f])) { try { $pdo->exec("ALTER TABLE kasir_transaksi ADD COLUMN {$f} {$def[$f]}"); } catch (Exception $e) { /* ignore */ } } }
        } catch (Exception $e) { /* ignore */ }
    } catch (PDOException $e) { /* ignore */ }

    // TABEL ITEM TRANSAKSI
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'kasir_transaksi_item'");
        if ($stmt->rowCount() === 0) {
            try {
                $pdo->exec("CREATE TABLE kasir_transaksi_item (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    id_transaksi INT NOT NULL,
                    nama_produk VARCHAR(255) NOT NULL,
                    harga INT NOT NULL DEFAULT 0,
                    qty INT NOT NULL DEFAULT 0,
                    subtotal INT NOT NULL DEFAULT 0,
                    KEY id_transaksi (id_transaksi)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            } catch (PDOException $e) { /* ignore */ }
        }
        try {
            $col = $pdo->query("SHOW COLUMNS FROM kasir_transaksi_item");
            $ada = [];
            while ($r = $col->fetch()) { $ada[$r['Field']] = true; }
            $butuh = ['id','id_transaksi','nama_produk','harga','qty','subtotal'];
            $def = [
                'id'=>'INT PRIMARY KEY AUTO_INCREMENT',
                'id_transaksi'=>'INT NOT NULL DEFAULT 0',
                'nama_produk'=>'VARCHAR(255) NOT NULL DEFAULT ""',
                'harga'=>'INT NOT NULL DEFAULT 0',
                'qty'=>'INT NOT NULL DEFAULT 0',
                'subtotal'=>'INT NOT NULL DEFAULT 0'
            ];
            foreach ($butuh as $f) { if (!isset($ada[$f])) { try { $pdo->exec("ALTER TABLE kasir_transaksi_item ADD COLUMN {$f} {$def[$f]}"); } catch (Exception $e) { /* ignore */ } } }
        } catch (Exception $e) { /* ignore */ }
    } catch (PDOException $e) { /* ignore */ }
}

function kasirAddColIfMissing($pdo, $tabel, $field, $def) {
    try {
        $ada = false;
        $stmt = $pdo->query("SHOW COLUMNS FROM {$tabel} LIKE '{$field}'");
        if ($stmt->rowCount() > 0) $ada = true;
        if (!$ada) { @$pdo->exec("ALTER TABLE {$tabel} ADD COLUMN {$field} {$def}"); }
    } catch (Exception $e) { /* ignore */ }
}

kasirRepairTableProduk($pdo);
kasirRepairTableTransaksi($pdo);
kasirAddColIfMissing($pdo, 'kasir_transaksi', 'catatan', 'VARCHAR(255) DEFAULT NULL'); // future use

// ============= HANDLER SIMPAN TRANSAKSI (JSON POST ?aksi=simpan_transaksi) =============
if (isset($_GET['aksi']) && $_GET['aksi'] === 'simpan_transaksi' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        if (!$data) { echo json_encode(['ok'=>false,'error'=>'Data tidak valid']); exit; }
        $no_struk = trim($data['no_struk'] ?? '');
        // FIX MySQL DATETIME REJECT: JS kirim ISO 8601 (ada T & Z) → convert ke 'Y-m-d H:i:s' + Asia/Jakarta TZ
        $tglRaw = trim($data['tgl'] ?? '');
        $tgl = date('Y-m-d H:i:s'); // default fallback
        if ($tglRaw !== '') {
            try {
                $dt = null;
                if (strpos($tglRaw, 'T') !== false && (substr($tglRaw, -1) === 'Z' || strpos($tglRaw, '+') !== false || preg_match('/-\d{2}:\d{2}$/', $tglRaw))) {
                    // ISO 8601 UTC / dengan offset
                    $dt = new DateTime($tglRaw, new DateTimeZone('UTC'));
                } elseif (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}$/', $tglRaw)) {
                    // Sudah format Y-m-d H:i:s atau Y-m-dTH:i:s (tanpa zona)
                    $dt = new DateTime(str_replace('T', ' ', $tglRaw), new DateTimeZone('Asia/Jakarta'));
                } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $tglRaw)) {
                    // Tanggal doang
                    $dt = new DateTime($tglRaw . ' ' . date('H:i:s'), new DateTimeZone('Asia/Jakarta'));
                }
                if ($dt) {
                    // Paksa convert ke TZ Asia/Jakarta (Indonesia UTC+7)
                    $dt->setTimezone(new DateTimeZone('Asia/Jakarta'));
                    $tgl = $dt->format('Y-m-d H:i:s');
                }
            } catch (Exception $e) {
                // Fallback: sekarang server
                $tgl = date('Y-m-d H:i:s');
            }
        }
        $payment = isset($data['payment']) && strtoupper($data['payment']) === 'QRIS' ? 'QRIS' : 'CASH';
        $total = (int)($data['total'] ?? 0);
        $bayar = (int)($data['bayar'] ?? 0);
        $kembalian = (int)($data['kembalian'] ?? 0);
        $items = isset($data['items']) && is_array($data['items']) ? $data['items'] : [];

        if ($no_struk === '' || $total <= 0 || count($items) === 0) {
            echo json_encode(['ok'=>false,'error'=>'Data transaksi tidak lengkap']); exit;
        }

        // Cek duplicate no struk
        $cek = $pdo->prepare("SELECT id FROM kasir_transaksi WHERE no_struk = ? LIMIT 1");
        $cek->execute([$no_struk]);
        if ($cek->rowCount() > 0) {
            // Jika duplicate, ganti no struk tambah suffix
            $no_struk = $no_struk . '_' . substr(md5(microtime()), 0, 4);
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("INSERT INTO kasir_transaksi (no_struk, tgl, payment, total, bayar, kembalian) VALUES (?,?,?,?,?,?)");
            $stmt->execute([$no_struk, $tgl, $payment, $total, $bayar, $kembalian]);
            $idt = (int)$pdo->lastInsertId();

            $stmtItem = $pdo->prepare("INSERT INTO kasir_transaksi_item (id_transaksi, nama_produk, harga, qty, subtotal) VALUES (?,?,?,?,?)");
            foreach ($items as $it) {
                $nama = trim($it['nama_produk'] ?? ($it['nama'] ?? ''));
                $h = (int)($it['harga'] ?? 0);
                $q = (int)($it['qty'] ?? 0);
                $s = (int)($it['subtotal'] ?? 0);
                if ($nama === '' || $q <= 0) continue;
                $stmtItem->execute([$idt, $nama, $h, $q, $s]);
            }
            $pdo->commit();
            echo json_encode(['ok'=>true,'id'=>$idt,'no_struk'=>$no_struk]);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
        }
    } catch (Exception $e) {
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

try {
    $stmtList = $pdo->query("SELECT * FROM kasir_produk ORDER BY urutan ASC, id DESC");
    $produkList = $stmtList->fetchAll();
} catch (PDOException $e) {
    $produkList = [];
}
$totalProduk = count($produkList);

// ===== DAFTAR KATEGORI FILTER HALAMAN KASIR (DEFAULT WAJIB + DINAMIS DARI DB) =====
// Kategori default SELALU MUNCUL meskipun belum ada produk (sesuai nama brand: Es Teller & Dawet Baraya)
$defaultKategori = ['Minuman', 'Shake', 'Es Teller', 'Dawet'];
$kategoriDbOnly = [];
try {
    $stmtKat = $pdo->query("SELECT DISTINCT kategori FROM kasir_produk");
    $rowsKat = $stmtKat->fetchAll();
    foreach ($rowsKat as $rk) {
        $kat = trim($rk['kategori'] ?? '');
        if ($kat !== '') {
            $kategoriDbOnly[] = $kat;
        }
    }
} catch (PDOException $e) {
    $kategoriDbOnly = [];
}
// GABUNGKAN default + DB (hapus duplikat)
$kategoriListRaw = array_values(array_unique(array_merge($defaultKategori, $kategoriDbOnly)));
// URUTKAN: kategori default duluan (sesuai urutan nama brand), baru kategori custom DB lain (misal vanisa) di belakangnya
$kategoriPriority = array_flip($defaultKategori);
usort($kategoriListRaw, function($a, $b) use ($kategoriPriority) {
    $pa = $kategoriPriority[$a] ?? 9999;
    $pb = $kategoriPriority[$b] ?? 9999;
    if ($pa !== $pb) return $pa - $pb;
    return strcasecmp($a, $b);
});
$kategoriListDb = $kategoriListRaw;
// Fallback final jika kosong banget
if (empty($kategoriListDb)) {
    $kategoriListDb = ['Minuman', 'Shake', 'Es Teller', 'Dawet'];
}

// ===== AGGRESSIVE ANTI-CACHE (HTTP Headers lebih kuat dari meta tag untuk HP) =====
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, post-check=0, pre-check=0');
    header('Pragma: no-cache');
    header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
    header('ETag: "' . md5(uniqid(mt_rand(), true)) . '"');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>Kasir - Es Teller & Dawet Baraya</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Poppins:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11.10.8/dist/sweetalert2.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.10.8/dist/sweetalert2.all.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <style>
        * {
            font-family: 'Inter', 'Poppins', sans-serif;
            -webkit-tap-highlight-color: transparent;
            box-sizing: border-box;
        }
        body {
            background: #F8FAFC;
            min-height: 100vh;
            padding-bottom: env(safe-area-inset-bottom, 0);
        }
        button, input { -webkit-appearance: none; appearance: none; }

        /* ============ DESKTOP & LARGE TABLET DEFAULT (NAV BARU: PUTIH CARD Rounded Top) ============ */
        html, body { margin:0; padding:0; background:#FFFFFF !important; min-height: 100vh; }
        .topnav {
            background: #FFFFFF;
            color: #991B1B;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 6px 18px rgba(15,23,42,0.08);
            border-radius: 24px 24px 0 0;
            width: 100%;
            max-width: 100vw;
            margin: 0 auto;
            padding: 0;
            height: auto;
            overflow: hidden;
        }
        .nav-force-one-row { flex-wrap: nowrap !important; width: 100% !important; min-width: 0; }
        .topnav-brand { display:flex; align-items:center; gap:12px; padding:14px 22px; min-width:0; flex-shrink:1; overflow:hidden; }
        .topnav-brand-icon {
            width:44px; height:44px; border-radius:14px;
            background: linear-gradient(135deg,#FEF3C7 0%, #FDE68A 100%);
            border:1px solid rgba(153,27,27,0.15);
            display:flex; align-items:center; justify-content:center;
            color:#991B1B; font-size:22px;
            box-shadow: 0 4px 10px rgba(153,27,27,0.12);
            flex-shrink:0;
        }
        .topnav h1 { margin:0; font-family:'Poppins'; font-weight:900; font-size:1.35rem; letter-spacing:-0.3px; color:#991B1B; white-space:nowrap; }
        .topnav-right { display:flex; align-items:center; gap:10px; padding:14px 22px; flex-shrink:0; }
        .btn-topnav-admin {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 7px 18px;
            border-radius: 14px;
            font-weight: 700;
            font-size: 0.9rem;
            color: #991B1B;
            background: #FFFFFF;
            border: 2px solid #991B1B;
            box-shadow: 0 3px 10px rgba(153,27,27,0.1);
            text-decoration: none !important;
        }
        .btn-topnav-admin:hover {
            background: linear-gradient(135deg,#991B1B 0%, #7F1D1D 100%);
            color: #FFFFFF !important;
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(153,27,27,0.25);
        }
        .btn-topnav-admin i { font-size: 1.05rem; }

        /* ============ MAIN CONTENT PADDING (RAPIHKAN PUTIH KOSONG) ============ */
        .container-fluid { padding: 16px 22px 80px 22px !important; }
        /* PENTING: KECUALIKAN container-fluid YANG ADA DI DALAM TOPNAV (jangan kena padding content!) */
        .topnav .container-fluid { padding: 0 !important; margin: 0 !important; }
        @media (min-width: 1200px) {
            .container-fluid { padding: 20px 28px 80px 28px !important; }
        }

        .produk-card {
            border:0;
            border-radius:18px;
            overflow:hidden;
            background:white;
            box-shadow:0 6px 18px rgba(15,23,42,0.07);
            transition: all 0.25s ease;
            border-top:3px solid #FBBF24;
            cursor: pointer;
            height:100%;
            display:flex; flex-direction:column;
            -webkit-user-select: none;
            user-select: none;
        }
        .produk-card:hover { transform: translateY(-3px); box-shadow:0 12px 28px rgba(153,27,27,0.15); border-top-color:#991B1B; }
        .produk-card:active { transform: scale(0.98); }

        /* ============ FILTER KATEGORI PILLS (BARU: Active Solid, Nonactive Outline) ============ */
        .kategori-pills {
            display: flex;
            flex-wrap: nowrap;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
            -ms-overflow-style: none;
            gap: 10px;
            margin-bottom: 20px;
            padding: 8px 4px 12px 4px;
        }
        .kategori-pills::-webkit-scrollbar { display: none; }
        .kategori-pills .pill {
            flex-shrink: 0;
            border-radius: 18px;
            padding: 10px 20px;
            font-weight: 800;
            font-size: 13px;
            letter-spacing: 0px;
            border: 2px solid #991B1B;
            cursor: pointer;
            transition: all 0.2s ease;
            -webkit-user-select: none;
            user-select: none;
            font-family: 'Poppins', 'Inter', sans-serif;
            white-space: nowrap;
            background: #FFFFFF;
            color: #991B1B;
            box-shadow: 0 2px 8px rgba(15,23,42,0.05);
        }
        .kategori-pills .pill:hover {
            transform: translateY(-1px);
            box-shadow: 0 5px 14px rgba(153,27,27,0.18);
        }
        .kategori-pills .pill.kat-active {
            background: linear-gradient(135deg,#991B1B 0%, #7F1D1D 55%, #450A0A 100%);
            color: #FFFFFF;
            border-color: transparent;
            box-shadow: 0 6px 16px rgba(153,27,27,0.32);
        }
        .kategori-pills .pill.kat-active:hover { transform: translateY(-1px); }

        /* ============ MODAL KERANJANG KECIL CUSTOM ============ */
        .modal-sm-custom .modal-dialog { max-width: 380px; margin: 0.5rem auto; width: calc(100% - 1rem); }
        .modal-sm-custom .modal-content {
            border-radius: 20px; border: 2.5px solid #FBBF24; overflow: hidden;
            box-shadow: 0 20px 60px rgba(69,10,10,0.4);
        }
        .modal-sm-custom .modal-header {
            background: white; border-bottom: 1.5px solid #E5E7EB;
            padding: 14px 18px;
        }
        .modal-sm-custom .modal-title {
            font-family: 'Poppins'; font-weight: 900; color:#1F2937; font-size:1.1rem; letter-spacing:-0.2px;
        }
        .modal-sm-custom .modal-body { padding: 12px 18px 6px 18px; max-height: 52vh; overflow-y:auto; background:white; }
        .modal-sm-custom .modal-footer {
            padding: 12px 18px 16px 18px; border-top: 1.5px dashed #FBBF24; background: #FFFEF7;
            display:flex; align-items:center; justify-content:space-between; gap:12px;
        }
        .modal-keranjang-empty {
            text-align:center; padding: 22px 10px; color:#9CA3AF;
        }
        .modal-keranjang-empty i { font-size: 3rem; opacity:0.6; display:block; margin-bottom: 8px;}
        .modal-item-row {
            display:flex; align-items:flex-start; justify-content:space-between; gap:10px;
            padding: 10px 0; border-bottom: 1px solid #F3F4F6;
        }
        .modal-item-row:last-child { border-bottom: 0; }
        .modal-item-info { flex:1; min-width:0; }
        .modal-item-nama { font-weight: 800; color:#1F2937; font-size: 0.95rem; line-height:1.2; margin:0; }
        .modal-item-harga { font-weight: 700; color:#991B1B; font-size: 0.82rem; margin-top:3px; letter-spacing:-0.1px; }
        .modal-item-qty-wrap {
            display:flex; align-items:center; gap:4px; background:#F1F5F9; border-radius:10px; padding:2px; flex-shrink:0;
        }
        .qty-mini-btn {
            width: 26px; height: 26px; border-radius:8px; border:0; background:white;
            display:flex; align-items:center; justify-content:center; cursor:pointer; font-weight: 900;
            color:#991B1B; box-shadow:0 1px 2px rgba(0,0,0,0.06);
        }
        .qty-mini-btn:active { transform:scale(0.93); }
        .qty-mini-num { min-width: 26px; text-align:center; font-weight:900; color:#1F2937; font-size:0.88rem; }

        .footer-total-label { font-size:0.78rem; color:#6B7280; font-weight:700; margin-bottom:2px; }
        .footer-total-val { font-family:'Poppins'; font-weight:900; color:#991B1B; font-size:1.2rem; letter-spacing:-0.3px; }
        .btn-checkout-modal {
            flex-shrink:0; min-width: 145px; padding: 11px 14px; border-radius: 14px;
            background: linear-gradient(135deg,#991B1B 0%, #7F1D1D 55%, #450A0A 100%);
            color:#FFF8DC; font-weight: 900; letter-spacing: 0.2px;
            border: 2px solid #FBBF24; cursor: pointer;
            box-shadow: 0 8px 18px rgba(153,27,27,0.3); font-size:0.92rem;
            transition: transform 0.1s ease;
        }
        .btn-checkout-modal:active { transform:scale(0.97); }
        .btn-checkout-modal:disabled { opacity:0.5; cursor:not-allowed; box-shadow:none; }

        /* ============ MODAL PEMBAYARAN BESAR CUSTOM ============ */
        .modal-lg-custom .modal-dialog { max-width: 420px; margin: 0.5rem auto; width: calc(100% - 1rem); }
        .modal-lg-custom .modal-content {
            border-radius: 22px; border: 2.5px solid #FBBF24; overflow: hidden;
            box-shadow: 0 24px 70px rgba(69,10,10,0.45);
        }
        .modal-lg-custom .modal-header {
            background: white; border-bottom: 1.5px solid #E5E7EB; padding: 16px 20px;
        }
        .modal-lg-custom .modal-title {
            font-family:'Poppins'; font-weight:900; color:#1F2937; font-size:1.15rem; letter-spacing:-0.2px;
        }
        .modal-lg-custom .modal-body { padding: 18px 20px 20px 20px; background: white; }

        .bayar-total-wrap { text-align:center; margin-bottom: 18px; padding: 8px 4px 4px 4px; }
        .bayar-total-label { color:#6B7280; font-weight:700; font-size: 0.82rem; letter-spacing:0.5px; }
        .bayar-total-big {
            font-family:'Poppins'; font-weight: 900; color:#991B1B; font-size: 1.85rem; letter-spacing:-0.4px;
            margin-top: 2px; line-height: 1.1;
        }

        .bayar-card {
            background: #FAFBFC; border-radius: 16px; padding: 14px 14px; margin-bottom: 14px;
            border: 1.5px solid #E5E7EB;
        }
        .bayar-card-label {
            font-weight: 900; color:#374151; font-size:0.9rem; margin-bottom:10px; display:flex; align-items:center; gap:6px;
        }

        .payment-btn-duo { display:flex; gap:8px; }
        .pay-btn {
            flex:1; padding: 12px 8px; border-radius: 14px; border: 2px solid #E5E7EB; background: white;
            font-weight: 800; color:#6B7280; cursor:pointer; font-size: 0.88rem;
            display:flex; flex-direction:column; align-items:center; gap:4px; transition: all 0.15s ease;
        }
        .pay-btn i { font-size: 1.4rem; }
        .pay-btn.pay-active {
            background: linear-gradient(135deg,#991B1B 0%, #7F1D1D 55%, #450A0A 100%);
            border-color: #FBBF24; color:#FFF8DC;
            box-shadow: 0 8px 18px rgba(153,27,27,0.3);
        }

        .input-bayar-group {
            display:flex; align-items:center; gap:8px; background:white; border-radius: 12px;
            padding: 8px 12px; border: 2px solid #E5E7EB;
        }
        .input-bayar-group:focus-within { border-color:#FBBF24; }
        .input-bayar-prefix { font-weight:900; color:#991B1B; font-size:1rem; }
        .input-bayar-text {
            border:0; flex:1; outline:none; font-weight:900; color:#1F2937;
            font-size: 1.1rem; background: transparent; font-family:'Poppins';
        }

        .quick-nominal-wrap { display:flex; flex-wrap:wrap; gap:6px; margin-top:10px; }
        .quick-nominal-btn {
            padding: 6px 10px; border-radius: 999px; background:white; border:1.5px solid #E5E7EB;
            font-weight:800; color:#4B5563; font-size:0.78rem; cursor:pointer; transition: all 0.12s ease;
        }
        .quick-nominal-btn:hover { border-color:#991B1B; color:#991B1B; }
        .quick-nominal-btn.qn-special {
            border-color:#FBBF24; background: rgba(251,191,36,0.08);
            color:#991B1B; font-weight: 900;
        }
        .quick-nominal-btn:active { transform:scale(0.95); }
        .quick-nominal-btn:disabled { opacity:0.4; cursor:not-allowed; }

        .kembalian-card {
            display:flex; align-items:center; justify-content:space-between;
            padding: 12px 16px; border-radius: 14px; background: #EFF6FF; border: 1.5px solid #BFDBFE;
        }
        .kembalian-card-label { display:flex; align-items:center; gap:8px; color:#374151; font-weight:800; font-size:0.88rem;}
        .kembalian-card-label i { color:#991B1B; font-size:1.2rem; }
        .kembalian-card-value { font-family:'Poppins'; font-weight:900; color:#991B1B; font-size: 1.15rem; }
        .kembalian-card.kurang {
            background: #FEF2F2; border-color: #FECACA;
        }
        .kembalian-card.kurang .kembalian-card-value { color:#DC2626; }

        .proses-transaksi-btn {
            width:100%; min-height:54px; border-radius: 16px;
            background: linear-gradient(135deg,#991B1B 0%, #7F1D1D 50%, #450A0A 100%);
            border: 2.5px solid #FBBF24; color:#FFF8DC;
            font-family:'Poppins'; font-weight:900; font-size: 1rem; letter-spacing:0.3px;
            cursor:pointer; margin-top: 16px;
            box-shadow: 0 12px 26px rgba(153,27,27,0.4);
            display:flex; align-items:center; justify-content:center; gap:8px;
            transition: transform 0.1s ease;
        }
        .proses-transaksi-btn:active { transform:scale(0.98); }
        .proses-transaksi-btn:disabled { opacity:0.5; cursor:not-allowed; box-shadow:none; }

        .btn-batal-bawah {
            display:block; text-align:center; margin-top:12px; color:#9CA3AF; font-weight:700;
            font-size:0.82rem; cursor:pointer; background:none; border:0; padding:4px; width:100%;
        }
        .btn-batal-bawah:hover { color:#4B5563; }

        .produk-img-wrap {
            height: 170px; overflow:hidden; background: linear-gradient(135deg,#FEF3C7,#FDE68A); position:relative;
        }
        .produk-img-wrap img { width:100%; height:100%; object-fit:cover; display:block; }
        .produk-img-placeholder {
            width:100%; height:100%; display:flex; align-items:center; justify-content:center; color:#78350F;
        }
        .produk-body { padding:14px 14px 16px 14px; display:flex; flex-direction:column; flex-grow:1; }
        .produk-nama {
            font-weight:700; font-size:1rem; color:#1F2937; margin:0 0 6px 0;
            display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;
            min-height:2.6rem; line-height: 1.25;
        }
        .produk-harga { font-weight:900; color:#991B1B; font-size:1.25rem; letter-spacing:-0.4px; margin:0 0 12px 0; }
        .qty-wrap {
            display:flex; align-items:center; justify-content:space-between; gap:8px;
            background: #F1F5F9; border-radius:12px; padding:4px; margin-top:auto;
        }
        .qty-btn {
            width:44px; height:44px; min-width:44px; min-height:44px; border-radius:11px; border:0;
            font-size:1.25rem; font-weight:800; cursor:pointer;
            display:flex; align-items:center; justify-content:center;
            transition: transform 0.1s ease;
            -webkit-user-select: none;
        }
        .qty-btn:active { transform: scale(0.88); }
        .qty-btn.minus { background:#FEF3C7; color:#78350F; }
        .qty-btn.plus { background: linear-gradient(135deg,#450A0A,#991B1B); color:#FDE68A; box-shadow:0 4px 10px rgba(153,27,27,0.28); }
        .qty-num {
            font-weight:800; font-size:1rem; color:#1F2937; min-width:32px; text-align:center;
        }

        .keranjang-wrap { position: sticky; top: 96px; z-index: 50; }
        .keranjang-toggle {
            background: transparent; border:0; color:white; font-size:1.1rem;
            width:40px; height:40px; border-radius:12px; display:none;
            align-items:center; justify-content:center;
        }
        .keranjang-card {
            background:white; border-radius:22px; overflow:hidden;
            box-shadow: 0 10px 32px rgba(15,23,42,0.10);
            border-top:4px solid #991B1B;
        }
        .keranjang-header {
            padding:18px 20px;
            background: linear-gradient(135deg,#450A0A,#991B1B);
            color:white;
            display:flex; align-items:center; justify-content:space-between;
            gap: 12px;
        }
        .keranjang-header-left {
            display:flex; align-items:center; gap:10px; flex-grow:1; min-width:0;
        }
        .keranjang-header h4 { margin:0; font-family:'Poppins'; font-weight:800; letter-spacing:-0.2px; font-size:1.15rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .keranjang-badge {
            background: rgba(251,191,36,0.22);
            border:1px solid rgba(251,191,36,0.45);
            color:#FDE68A;
            font-weight:800; font-size:0.78rem;
            padding:4px 10px; border-radius:999px;
            flex-shrink:0;
        }
        .keranjang-body {
            max-height: calc(100vh - 460px);
            overflow-y: auto;
            padding: 10px 14px 6px 14px;
            border-bottom:1px solid #F1F5F9;
            -webkit-overflow-scrolling: touch;
        }
        .keranjang-empty { padding:40px 20px; text-align:center; color:#94A3B8; }
        .keranjang-empty i { font-size:3rem; opacity:0.4; margin-bottom:10px; display:block; }
        .keranjang-item {
            display:flex; align-items:center; gap:10px;
            padding:10px 4px;
            border-bottom:1px dashed #E2E8F0;
            flex-wrap: wrap;
        }
        .keranjang-item:last-child { border-bottom:0; }
        .keranjang-item-info { flex-grow:1; min-width: 130px; }
        .keranjang-item-nama {
            font-weight:700; font-size:0.92rem; color:#1F2937; margin:0 0 2px 0;
            white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width: 95%;
        }
        .keranjang-item-harga { font-weight:600; color:#64748B; font-size:0.78rem; margin:0; }
        .keranjang-item-kanan {
            display:flex; align-items:center; gap:10px; flex:1 1 100%;
            justify-content: space-between;
            padding-top: 4px;
        }
        .keranjang-item-qty {
            display:flex; align-items:center; gap:4px; background:#F1F5F9; padding:4px; border-radius:10px; flex-shrink:0;
        }
        .keranjang-item-qty .qty-btn { width:36px; height:36px; min-width:36px; min-height:36px; border-radius:9px; font-size:1rem; }
        .keranjang-item-qty .qty-num { font-size:0.9rem; min-width:24px; }
        .keranjang-subtotal-item {
            font-weight:900; color:#991B1B; font-size:1rem; min-width:80px; text-align:right; flex-shrink:0;
        }
        .keranjang-hapus {
            width:40px; height:40px; min-width:40px; min-height:40px; border-radius:12px; border:0; background:#FEF2F2; color:#DC2626;
            display:flex; align-items:center; justify-content:center; cursor:pointer;
            transition: transform 0.1s ease;
        }
        .keranjang-hapus:active { transform: scale(0.85); background:#DC2626; color:white; }

        .keranjang-footer { padding:16px 20px 20px 20px; }
        .ringkasan-row {
            display:flex; align-items:center; justify-content:space-between;
            padding:6px 0;
        }
        .ringkasan-label { font-weight:500; color:#64748B; font-size:0.95rem; }
        .ringkasan-total-label { font-weight:700; color:#1F2937; font-size:1rem; }
        .ringkasan-value { font-weight:800; color:#1F2937; font-size:0.95rem; }
        .ringkasan-total-value {
            font-weight:900; color:#991B1B; font-size:1.6rem; letter-spacing:-0.5px;
        }
        .divider-tebal {
            height:3px; background: linear-gradient(90deg, transparent, #FBBF24, transparent);
            margin:8px 0 10px 0; border-radius:3px; border:0;
        }
        .input-group-bayar { margin-top:6px; }
        .input-bayar-label { display:block; font-weight:700; color:#334155; font-size:0.9rem; margin:14px 0 6px 0; }
        .input-bayar {
            min-height:58px !important;
            border-radius:16px !important;
            border-color:#E2E8F0 !important;
            font-weight:800 !important;
            font-size:1.2rem !important;
            color:#450A0A !important;
            padding-left:20px !important;
            padding-right: 20px !important;
        }
        .input-bayar:focus { border-color:#991B1B !important; box-shadow: 0 0 0 0.3rem rgba(153,27,27,0.18) !important; }
        .quick-bayar-wrap {
            margin-top: 10px;
            display:grid; grid-template-columns: repeat(4, 1fr); gap: 7px;
        }
        .quick-bayar-btn {
            min-height: 42px;
            border: 1px solid rgba(153,27,27,0.2);
            border-radius: 12px;
            background: rgba(254,243,199,0.4);
            color: #78350F;
            font-weight: 800;
            font-size: 0.82rem;
            cursor: pointer;
            padding: 4px 3px;
            transition: transform 0.1s ease, background 0.15s ease;
            display:flex; align-items:center; justify-content:center;
            -webkit-user-select: none;
        }
        .quick-bayar-btn:active { transform: scale(0.92); background: rgba(251,191,36,0.45); }
        .quick-bayar-btn.active {
            background: linear-gradient(135deg,#78350F,#92400E);
            color: #FEF3C7;
            border-color: #92400E;
            box-shadow: 0 4px 10px rgba(146,64,14,0.25);
        }
        .payment-toggle {
            margin-top: 14px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px;
            padding: 5px;
            background: #F1F5F9;
            border-radius: 14px;
            border: 1px solid #E2E8F0;
        }
        .payment-btn {
            min-height: 50px;
            border-radius: 10px;
            border: 0;
            background: transparent;
            color: #64748B;
            font-weight: 800;
            font-size: 0.95rem;
            cursor: pointer;
            display:flex; align-items:center; justify-content:center; gap: 6px;
            padding: 8px 6px;
            transition: all 0.2s ease;
        }
        .payment-btn i { font-size: 1.05rem; }
        .payment-btn.active {
            background: white;
            color: #991B1B;
            box-shadow: 0 4px 12px rgba(15,23,42,0.08);
        }
        .payment-btn.active.qris {
            color: #065F46;
        }
        .payment-btn.active.cash {
            color: #991B1B;
        }
        .kembalian-card {
            margin-top:14px;
            padding:16px 18px;
            border-radius:16px;
            background: linear-gradient(135deg,#ECFDF5,#D1FAE5);
            border:1px solid rgba(16,185,129,0.3);
            display:flex; align-items:center; justify-content:space-between; gap:10px;
        }
        .kembalian-card.negative {
            background: linear-gradient(135deg,#FEF2F2,#FECACA);
            border-color: rgba(239,68,68,0.3);
        }
        .kembalian-label { font-weight:700; color:#065F46; font-size:0.95rem; display:flex; align-items:center; gap:8px; }
        .kembalian-card.negative .kembalian-label { color:#991B1B; }
        .kembalian-value { font-weight:900; font-size:1.5rem; letter-spacing:-0.3px; color:#065F46; }
        .kembalian-card.negative .kembalian-value { color:#991B1B; }

        .btn-cetak {
            margin-top:16px;
            width:100%;
            min-height:64px;
            border-radius:18px !important;
            background: linear-gradient(135deg,#450A0A,#991B1B) !important;
            color:white !important;
            border:2px solid #FBBF24 !important;
            font-weight:900 !important;
            font-size:1.2rem !important;
            letter-spacing:0.3px;
            box-shadow: 0 12px 28px rgba(153,27,27,0.4), 0 0 0 4px rgba(251,191,36,0.14) !important;
            transition: all 0.2s ease !important;
            display:flex; align-items:center; justify-content:center; gap:10px;
        }
        .btn-cetak:active { transform: scale(0.98); }
        .btn-cetak:disabled {
            background: #CBD5E1 !important;
            border-color:#CBD5E1 !important;
            color:#64748B !important;
            box-shadow:none !important;
            cursor:not-allowed !important;
        }
        .btn-cetak-ucapan {
            margin-top: 10px;
            width: 100%;
            min-height: 52px;
            border-radius: 14px !important;
            background: linear-gradient(135deg,#FDE68A,#FBBF24) !important;
            color: #450A0A !important;
            border: 2px solid #991B1B !important;
            font-weight: 900 !important;
            font-size: 1rem !important;
            letter-spacing: 0.2px;
            box-shadow: 0 10px 20px rgba(251,191,36,0.35), 0 0 0 4px rgba(153,27,27,0.1) !important;
            transition: all 0.2s ease !important;
            display:flex; align-items:center; justify-content:center; gap:8px;
        }
        .btn-cetak-ucapan:active { transform: scale(0.98); box-shadow: 0 5px 12px rgba(251,191,36,0.2) !important; }
        .btn-topnav-ucapan {
            padding: 5.5px 11px !important;
            background: linear-gradient(135deg,#FDE68A,#FBBF24) !important;
            color: #450A0A !important;
            border: 1.5px solid #991B1B !important;
            box-shadow: 0 3px 7px rgba(251,191,36,0.4) !important;
            border-radius: 11px !important;
            font-size: 0.72rem !important;
            font-weight: 900 !important;
            gap: 3px;
            display: inline-flex;
            align-items: center;
            transition: all 0.15s ease;
        }
        .btn-topnav-ucapan:active { transform: scale(0.97); }
        .btn-reset {
            margin-top:10px;
            width:100%;
            border-radius:14px !important;
            background:#F1F5F9 !important;
            color:#64748B !important;
            border:0 !important;
            font-weight:700 !important;
            font-size:0.95rem !important;
            min-height:48px;
            transition: transform 0.1s ease;
        }
        .btn-reset:active { transform: scale(0.97); background:#E2E8F0 !important; }

        /* ============ RECEIPT / PRINT (THERMAL 58mm CLEAN MINIMALIS MODERN - SPASI ANTI MEPEL) ============ */
        .receipt {
            display:none; width:100%; max-width:58mm; min-width:58mm; margin:0 auto;
            padding: 5mm 4mm 5mm 4mm;
            color:#000000;
            font-family: 'Courier New', Courier, monospace !important;
            font-size: 11.5px; line-height: 1.55;
            background: #FFFFFF; max-height:none; min-height:auto;
            box-sizing: border-box;
            -webkit-print-color-adjust: exact; print-color-adjust: exact;
        }
        .receipt * { font-family: 'Courier New', Courier, monospace !important; letter-spacing: 0 !important; line-height: inherit !important; }
        .receipt-card {
            border: none; border-radius: 0; padding: 0; margin: 0;
            background: #FFFFFF; width: 100%; box-sizing: border-box;
            box-shadow: none;
        }
        .receipt-center { text-align:center; }
        .receipt-divider {
            border: none; border-top: 1px dashed #374151; margin: 9px 0;
            height: 0; clear: both;
        }
        .receipt-divider-solid {
            border: none; border-top: 1px solid #111827; margin: 8px 0;
            height: 0; clear: both;
        }
        .receipt-title {
            font-weight: 900; font-size: 15px; text-align: center;
            color: #000000; line-height: 1.15; letter-spacing: 0.3px;
            margin: 0 0 2px 0;
        }
        .receipt-sub-nama {
            text-align: center; font-size: 10.5px; color: #374151; font-weight: 700;
            margin-top: 4px; line-height: 1.2;
        }
        .receipt-alamat-wa {
            text-align: center; font-size: 10px; color: #374151;
            margin-top: 7px; line-height: 1.45;
            font-weight: 600;
        }
        .receipt-meta-wrapper {
            margin-top: 9px;
            margin-bottom: 2px;
        }
        .receipt-meta-row {
            display: flex; justify-content: flex-start; align-items: flex-start;
            gap: 5px;
            padding: 1.5px 0;
        }
        .receipt-meta-label {
            font-weight: 900; font-size: 10px; color: #000000;
            flex: 0 0 auto; white-space: nowrap;
        }
        .receipt-meta-value {
            font-size: 10px; color: #000000; font-weight: 700;
            flex: 1; min-width: 0; word-break: break-all;
        }
        .receipt-pay-method {
            text-align: center;
            padding: 7px 0;
            border-top: 1px dashed #374151;
            border-bottom: 1px dashed #374151;
            font-weight: 900; font-size: 11px;
            color: #000000;
            margin: 9px 0 10px 0;
            letter-spacing: 0.35px;
        }
        .receipt-lunas {
            text-align: center;
            margin-top: 8px;
            padding: 5px 0;
            font-weight: 900; font-size: 10.5px;
            color: #000000;
            letter-spacing: 0.25px;
        }
        .receipt-section-label {
            font-weight: 900; font-size: 11px; color: #000000;
            margin-bottom: 6px; letter-spacing: 0.4px;
            padding-top: 2px;
        }
        .receipt-item {
            padding: 5px 0 7px 0;
            border-bottom: 1px dashed #D1D5DB;
            margin-bottom: 3px;
        }
        .receipt-item:last-child { border-bottom: none; padding-bottom: 0; margin-bottom: 0; }
        .receipt-item-top {
            display: flex; justify-content: space-between; align-items: flex-start;
            gap: 6px;
            padding-bottom: 1px;
        }
        .receipt-item-nama {
            flex: 1; min-width: 0;
            font-size: 11.5px; font-weight: 800; color: #000000;
            line-height: 1.3;
        }
        .receipt-item-harga-satuan {
            font-size: 10px; color: #4B5563;
            margin-top: 4px;
            font-weight: 600;
            line-height: 1.2;
            padding-left: 1px;
        }
        .receipt-item-kanan {
            flex-shrink: 0; text-align: right;
            min-width: 0;
            padding-top: 1px;
        }
        .receipt-subtotal-item {
            font-weight: 900; color: #000000; font-size: 11.5px;
            white-space: nowrap;
        }
        .receipt-total-row {
            display: flex; justify-content: space-between; align-items: baseline;
            padding: 3px 0;
            gap: 10px;
        }
        .receipt-total-row .label {
            font-weight: 900; color: #000000; font-size: 11px;
            letter-spacing: 0.3px;
            flex: 0 0 auto;
        }
        .receipt-total-row .value {
            font-weight: 900; color: #000000; font-size: 11px;
            text-align: right;
            flex: 1; min-width: 0;
            white-space: nowrap;
        }
        .receipt-total-row.row-total {
            margin-top: 5px;
            padding-top: 7px;
            border-top: 1.5px solid #111827;
        }
        .receipt-total-row.row-total .label,
        .receipt-total-row.row-total .value {
            font-size: 13px; font-weight: 900;
        }
        .receipt-total-row.row-bayar { padding-top: 5px; }
        .receipt-total-row.row-bayar .label,
        .receipt-total-row.row-bayar .value {
            font-size: 11.5px;
        }
        .receipt-total-row.row-kembalian { padding: 5px 0 2px 0; }
        .receipt-total-row.row-kembalian .label,
        .receipt-total-row.row-kembalian .value {
            font-size: 12.5px;
        }
        .receipt-footer-thank {
            text-align: center;
            margin-top: 10px;
            line-height: 1.4;
            font-size: 10.5px;
            color: #000000;
            font-weight: 800;
        }
        .receipt-footer-hashtag {
            text-align: center;
            font-weight: 700;
            font-size: 9.5px;
            color: #374151;
            margin-top: 6px;
            line-height: 1.35;
            letter-spacing: 0.15px;
        }
        .receipt-footer-bless {
            text-align: center;
            font-size: 9.5px;
            color: #000000;
            font-weight: 700;
            margin-top: 4px;
            line-height: 1.35;
        }
        .ucapan-platform-row {
            display: flex; justify-content: space-between; align-items: center;
            gap: 8px;
            padding: 8px 8px;
            margin-bottom: 6px;
            border: 1.2px solid #111827;
            border-radius: 12px;
            background: #FFFFFF;
        }
        .ucapan-platform-kiri {
            display:flex; align-items:center; gap:8px;
            flex: 1; min-width: 0;
        }
        .ucapan-platform-icon {
            flex-shrink: 0;
            width: 26px; height: 26px;
            border-radius: 9px;
            display: flex; align-items: center; justify-content: center;
            font-size: 16px; line-height: 1;
            background: linear-gradient(135deg,#FEF3C7,#FDE68A);
            border: 1px solid #D97706;
        }
        .ucapan-platform-nama {
            font-size: 11px;
            font-weight: 900;
            color: #000000;
            line-height: 1.15;
            letter-spacing: 0.1px;
        }
        .ucapan-platform-star {
            flex-shrink: 0;
            text-align: right;
            line-height: 1;
            font-size: 11px;
            letter-spacing: 1px;
            color: #111827;
        }
        .ucapan-platform-star-label {
            display: block;
            margin-top: 2px;
            font-size: 7.8px;
            font-weight: 800;
            color: #111827;
            letter-spacing: 0.1px;
            line-height: 1.1;
        }
        /* Support GAMBAR KARTU UCAPAN CUSTOM */
        .ucapan-gambar-wrapper-top {
            text-align: center;
            padding: 1px 1px 7px 1px;
            margin-bottom: 1px;
        }
        .ucapan-gambar-wrapper-bottom {
            text-align: center;
            padding: 7px 1px 1px 1px;
            margin-top: 2px;
        }
        .ucapan-gambar-hero {
            display: block;
            margin: 0 auto;
            width: 100%;
            max-width: 100%;
            height: auto;
            border: 1.5px dashed #FBBF24;
            border-radius: 14px;
            padding: 4px;
            background: #FFFFFF;
            box-sizing: border-box;
        }
        .ucapan-gambar-stiker {
            display: inline-block;
            max-width: 78%;
            height: auto;
            border-radius: 10px;
        }
        .ucapan-logo-toko {
            width: 50px; height: 50px;
            border-radius: 14px;
            border: 1.5px solid #991B1B;
            background: linear-gradient(135deg,#FEF3C7,#FDE68A);
            padding: 3px;
            display: block;
            margin: 0 auto 6px auto;
        }
        @media print {
            @page { size: 58mm auto; margin: 0mm; }
            html, body {
                width: 100% !important; max-width:58mm !important; min-width:58mm !important;
                margin: 0 !important; padding: 0 !important;
                background: #FFFFFF !important;
                -webkit-print-color-adjust: exact; print-color-adjust: exact;
                overflow: visible !important;
                font-size: 11.5px !important;
                line-height: 1.55 !important;
            }
            body > *:not(.receipt):not(.receipt *) { display: none !important; }
            .receipt {
                display: block !important; visibility: visible !important;
                width: 100% !important; max-width:58mm !important; min-width:58mm !important;
                position: absolute; left: 0; top: 0; margin: 0 !important;
                padding: 5mm 4mm 5mm 4mm !important;
                page-break-inside: auto !important; page-break-after: avoid !important;
                -webkit-print-color-adjust: exact; print-color-adjust: exact;
                box-sizing: border-box !important;
                background: #FFFFFF !important;
                font-size: 11.5px !important;
                line-height: 1.55 !important;
            }
            .receipt * { line-height: 1.55 !important; }
            .receipt-divider { margin: 9px 0 !important; }
            .receipt-divider-solid { margin: 8px 0 !important; }
            .receipt-title { font-size: 15px !important; line-height: 1.15 !important; }
            .receipt-sub-nama { font-size: 10.5px !important; margin-top: 4px !important; }
            .receipt-alamat-wa { font-size: 10px !important; margin-top: 7px !important; line-height: 1.45 !important; }
            .receipt-meta-wrapper { margin-top: 9px !important; margin-bottom: 2px !important; }
            .receipt-meta-row { padding: 1.5px 0 !important; }
            .receipt-meta-label, .receipt-meta-value { font-size: 10px !important; }
            .receipt-pay-method { padding: 7px 0 !important; font-size: 11px !important; margin: 9px 0 10px 0 !important; }
            .receipt-lunas { margin-top: 8px !important; font-size: 10.5px !important; padding: 5px 0 !important; }
            .receipt-section-label { font-size: 11px !important; margin-bottom: 6px !important; padding-top: 2px !important; }
            .receipt-item { padding: 5px 0 7px 0 !important; margin-bottom: 3px !important; }
            .receipt-item-nama { font-size: 11.5px !important; line-height: 1.3 !important; }
            .receipt-item-harga-satuan { font-size: 10px !important; margin-top: 4px !important; line-height: 1.2 !important; }
            .receipt-subtotal-item { font-size: 11.5px !important; }
            .receipt-total-row { padding: 3px 0 !important; }
            .receipt-total-row .label, .receipt-total-row .value { font-size: 11px !important; }
            .receipt-total-row.row-total { margin-top: 5px !important; padding-top: 7px !important; border-top: 1.5px solid #111827 !important; }
            .receipt-total-row.row-total .label, .receipt-total-row.row-total .value { font-size: 13px !important; }
            .receipt-total-row.row-bayar { padding-top: 5px !important; }
            .receipt-total-row.row-bayar .label, .receipt-total-row.row-bayar .value { font-size: 11.5px !important; }
            .receipt-total-row.row-kembalian { padding: 5px 0 2px 0 !important; }
            .receipt-total-row.row-kembalian .label, .receipt-total-row.row-kembalian .value { font-size: 12.5px !important; }
            .receipt-footer-thank { margin-top: 10px !important; font-size: 10.5px !important; line-height: 1.4 !important; }
            .receipt-footer-hashtag { font-size: 9.5px !important; margin-top: 6px !important; line-height: 1.35 !important; }
            .receipt-footer-bless { font-size: 9.5px !important; margin-top: 4px !important; }
            .ucapan-platform-row { padding: 8px 8px !important; margin-bottom: 6px !important; gap: 8px !important; border: 1.2px solid #111827 !important; border-radius: 12px !important; }
            .ucapan-platform-kiri { gap:8px !important; }
            .ucapan-platform-icon { width:26px !important; height:26px !important; border-radius:9px !important; font-size:16px !important; border:1px solid #D97706 !important; }
            .ucapan-platform-nama { font-size: 11px !important; font-weight: 900 !important; line-height:1.15 !important; }
            .ucapan-platform-star { font-size:11px !important; letter-spacing:1px !important; }
            .ucapan-platform-star-label { margin-top:2px !important; font-size:7.8px !important; font-weight:800 !important; line-height:1.1 !important; }
            .ucapan-gambar-wrapper-top { padding: 1px 1px 7px 1px !important; margin-bottom: 1px !important; text-align:center !important; }
            .ucapan-gambar-wrapper-bottom { padding: 7px 1px 1px 1px !important; margin-top: 2px !important; text-align:center !important; }
            .ucapan-gambar-hero { width: 100% !important; max-width: 100% !important; height: auto !important; border: 1.5px dashed #FBBF24 !important; border-radius: 14px !important; padding: 4px !important; box-sizing: border-box !important; display:block !important; margin: 0 auto !important; }
            .ucapan-gambar-stiker { max-width: 78% !important; height: auto !important; border-radius: 10px !important; }
            .ucapan-logo-toko { width:50px !important; height:50px !important; border-radius:14px !important; border:1.5px solid #991B1B !important; padding:3px !important; margin:0 auto 6px auto !important; display:block !important; }
            .receipt img { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; display:block !important; visibility: visible !important; opacity: 1 !important; }
        }

        .keranjang-body::-webkit-scrollbar { width:6px; }
        .keranjang-body::-webkit-scrollbar-track { background:transparent; }
        .keranjang-body::-webkit-scrollbar-thumb { background:rgba(153,27,27,0.25); border-radius:99px; }

        /* ============ MOBILE FOCUS (LAYAR <= 991px - HP & TABLET KECIL) ============ */
        @media (max-width: 991.98px) {
            html, body { overflow-x: hidden; margin: 0; padding: 0; max-width: 100vw; background: #FFFFFF !important; }
            .topnav .container-fluid.p-0 > .d-flex,
            .nav-force-one-row {
                flex-direction: row !important;
                flex-wrap: nowrap !important;
                align-items: center !important;
                justify-content: space-between !important;
                width: 100% !important;
                min-width: 0 !important;
                overflow: hidden !important;
            }
            .topnav { border-radius: 18px 18px 0 0; padding: 0; overflow: hidden; }
            .topnav-brand { padding: 9px 12px 9px 12px !important; gap: 8px !important; min-width: 0 !important; flex: 1 1 auto !important; overflow: hidden !important; }
            .topnav-brand-icon { width: 34px !important; height: 34px !important; border-radius: 11px !important; font-size: 17px !important; }
            .topnav h1 { font-size: 0.98rem !important; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            .topnav-right { padding: 9px 12px 9px 12px !important; gap: 5px !important; flex: 0 0 auto !important; }
            .btn-topnav-admin { font-size: 0.72rem !important; padding: 5.5px 11px !important; border-radius: 11px !important; gap: 3px; min-height: 0; }
            .btn-topnav-admin i { font-size: 0.85rem !important; }

            .container-fluid { padding: 10px 13px 110px 13px !important; }
            .topnav .container-fluid { padding: 0 !important; }

            .order-produk h5 { margin-top: 0 !important; margin-bottom: 10px !important; }

            .kategori-pills { padding: 2px 0 10px 0 !important; margin-bottom: 12px !important; gap: 8px; }
            .kategori-pills .pill { padding: 8px 17px; font-size: 12px; border-radius: 16px; }

            /* URUTAN: KERANJANG PERTAMA (ATAS), PRODUK KEDUA (BAWAH) */
            .order-produk { order: 2; }
            .order-keranjang { order: 1; }
            .g-lg-5 { --bs-gutter-y: 1.2rem; --bs-gutter-x: 0; }

            /* Keranjang COLLAPSIBLE di HP */
            .keranjang-wrap { position: relative; top: auto; }
            .keranjang-toggle { display: inline-flex; }
            .keranjang-card { border-radius: 20px; border-top-width: 3px; }
            .keranjang-header { padding: 14px 14px; }
            .keranjang-body-mobile {
                max-height: 0; overflow: hidden;
                transition: max-height 0.3s ease, padding 0.3s ease;
                padding-top: 0 !important; padding-bottom: 0 !important;
            }
            .keranjang-body-mobile.show {
                max-height: 55vh; overflow-y: auto;
                padding: 10px 14px 6px 14px !important;
                border-bottom: 1px solid #F1F5F9;
            }
            .keranjang-footer {
                max-height: 0; overflow: hidden;
                transition: max-height 0.3s ease, padding 0.3s ease;
                padding-top: 0 !important; padding-bottom: 0 !important;
            }
            .keranjang-footer.show {
                max-height: 1000px; padding: 12px 14px 14px 14px !important;
            }
            .keranjang-empty { padding: 20px 14px; }
            .keranjang-empty i { font-size: 2rem; }

            /* ==== BOTTOM BAR STICKY 1 BARIS PERSIS CONTOH (PUTIH FLAT) ==== */
            .bottom-action-mobile {
                position: fixed !important;
                left: 0; right: 0; bottom: 0;
                z-index: 99;
                background: #FFFFFF !important;
                padding: 10px 12px calc(10px + env(safe-area-inset-bottom, 0px)) 12px !important;
                border-top: 1px solid #E2E8F0;
                box-shadow: 0 -4px 18px rgba(15,23,42,0.08);
                backdrop-filter: none !important;
            }
            .bottom-action-mobile .btn-bayar {
                min-height: 46px !important;
                height: 46px !important;
                border-radius: 999px !important;
                padding: 0 26px;
                font-size: 1rem !important;
                font-weight: 800 !important;
                border: 2px solid #FBBF24 !important;
                background: linear-gradient(135deg,#450A0A 0%, #7F1D1D 60%, #991B1B 100%) !important;
                color: #FDE68A !important;
                letter-spacing: -0.2px;
                box-shadow: 0 8px 20px rgba(153,27,27,0.3);
                transition: all 0.15s ease;
                width: auto !important;
                margin: 0 !important;
                flex-shrink: 0 !important;
            }
            .bottom-action-mobile .btn-bayar:disabled {
                background: #E2E8F0 !important;
                color: #94A3B8 !important;
                border-color: #CBD5E1 !important;
                box-shadow: none !important;
                cursor: not-allowed;
            }
            .bottom-action-mobile .btn-bayar:not(:disabled):active {
                transform: scale(0.98);
            }
            .bottom-action-mobile-sub {
                display: flex !important;
                gap: 12px !important;
                margin-top: 0 !important;
                align-items: center !important;
                justify-content: space-between !important;
                width: 100% !important;
                padding: 0 !important;
            }
            /* Keranjang info + total */
            .bottom-kiri {
                display: flex; align-items: center; gap: 10px; flex: 1;
            }
            .bottom-keranjang-btn {
                position: relative;
                width: 44px; height: 44px; min-width: 44px;
                border-radius: 14px;
                background: #F1F5F9;
                border: 1.5px solid #E2E8F0;
                display: flex; align-items: center; justify-content: center;
                color: #0F172A; font-size: 22px;
                flex-shrink: 0;
            }
            .bottom-keranjang-btn .badge-bulet {
                position: absolute;
                top: -6px; right: -6px;
                min-width: 22px; height: 22px; padding: 0 6px;
                border-radius: 99px;
                background: #991B1B;
                color: #FFF8DC;
                border: 2px solid #FFFFFF;
                font-size: 0.7rem;
                font-weight: 900;
                display: flex; align-items: center; justify-content: center;
                box-shadow: 0 2px 6px rgba(153,27,27,0.4);
            }
            .bottom-total-group { display: flex; flex-direction: column; line-height: 1.1; gap: 2px; flex: 1; min-width: 0;}
            .bottom-total-label {
                font-size: 0.7rem; font-weight: 700; color: #64748B; letter-spacing: 0.1px;
            }
            .bottom-total-val {
                font-weight: 900; color: #0F172A; font-size: 1.1rem;
                letter-spacing: -0.3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            }

            /* Keranjang HP: HIDDEN di page, nanti muncul via MODAL */
            .order-keranjang .keranjang-wrap { display: none !important; }
            .order-keranjang { display: none !important; }

            /* Quick bayar HP jadi 3 kolom, lebih besar */
            .quick-bayar-wrap { grid-template-columns: repeat(3, 1fr); gap: 7px; }
            .quick-bayar-btn { min-height: 46px; font-size: 0.88rem; border-radius: 13px; }

            /* Item keranjang di HP: 2 baris (nama+harga atas, qty+subtotal bawah) */
            .keranjang-item { padding: 12px 2px; }
            .keranjang-item-info { flex: 1 1 100%; min-width: 0; }
            .keranjang-item-kanan { padding-top: 8px; }
            .keranjang-item-qty .qty-btn { width: 38px; height: 38px; min-width: 38px; min-height: 38px; }
            .keranjang-hapus { width: 42px; height: 42px; min-width: 42px; min-height: 42px; }
            .keranjang-item-nama { font-size: 0.95rem; }
            .keranjang-item-harga { font-size: 0.82rem; }
            .keranjang-subtotal-item { font-size: 1.02rem; }
        }

        /* ============ SUPER SMALL HP (LAYAR <= 420px) ============ */
        @media (max-width: 419.98px) {
            /* Produk default 2 kolom di HP super kecil -> 2 kolom, image lebih pendek */
            .produk-img-wrap { height: 130px; }
            .produk-body { padding: 10px 10px 12px 10px; }
            .produk-nama { font-size: 0.88rem; min-height: 2.2rem; }
            .produk-harga { font-size: 1.02rem; margin-bottom: 10px; }
            .qty-btn { width: 36px; height: 36px; min-width: 36px; min-height: 36px; }
            .qty-num { min-width: 24px; font-size: 0.92rem; }

            .ringkasan-total-value { font-size: 1.35rem; }
            .input-bayar { min-height: 54px !important; font-size: 1.1rem !important; padding-left: 16px !important; padding-right: 16px !important; }
            .kembalian-value { font-size: 1.25rem; }
            .kembalian-card { padding: 12px 14px; }
            .quick-bayar-btn { font-size: 0.78rem; min-height: 42px; padding: 5px 2px; }

            .topnav { border-radius: 16px 16px 0 0; }
            .topnav h1 { font-size: 0.88rem !important; }
            .topnav-brand { padding: 7px 10px !important; gap: 6px !important; }
            .topnav-brand-icon { width: 30px !important; height: 30px !important; border-radius: 10px !important; font-size: 15px !important; }
            .topnav-right { padding: 7px 10px !important; gap: 4px !important; }
            .btn-topnav-admin { font-size: 0.68rem !important; padding: 4.5px 9px !important; border-radius: 10px !important; }
            .btn-topnav-admin i { font-size: 0.78rem !important; }
            .kategori-pills .pill { padding: 7px 15px; font-size: 11.5px; border-radius: 14px; }
            .container-fluid { padding: 9px 10px 105px 10px !important; }
            .topnav .container-fluid { padding: 0 !important; }

            .bottom-total-val { font-size: 0.98rem; }
            .bottom-action-mobile .btn-cetak { min-height: 56px; font-size: 1rem; }
        }

        /* ============ TABLET (768px - 991px) : produk 3 kolom ============ */
        @media (min-width: 768px) and (max-width: 991.98px) {
            .produk-img-wrap { height: 160px; }
        }

        /* ==== RECEIPT / PRINT (THERMAL 58mm CLEAN MINIMALIS MODERN - MOBILE) ==== */
        .receipt {
            display:none; width:100%; max-width:58mm; min-width:58mm; margin:0 auto;
            padding: 4mm 3.5mm 4mm 3.5mm;
            color:#000000;
            font-family: 'Courier New', Courier, monospace !important;
            font-size: 11px; line-height: 1.4;
            background: #FFFFFF; max-height:none; min-height:auto;
            box-sizing: border-box;
        }
        .receipt * { font-family: 'Courier New', Courier, monospace !important; letter-spacing: 0 !important; }
        .receipt-card {
            border: none; border-radius: 0; padding: 0; margin: 0;
            background: #FFFFFF; width: 100%; box-sizing: border-box;
            box-shadow: none;
        }
        .receipt-center { text-align:center; }
        .receipt-divider {
            border: none; border-top: 1px dashed #374151; margin: 7px 0;
            height: 0; clear: both;
        }
        .receipt-divider-solid {
            border: none; border-top: 1px solid #111827; margin: 6px 0;
            height: 0; clear: both;
        }
        .receipt-title {
            font-weight: 900; font-size: 14px; text-align: center;
            color: #000000; line-height: 1.1; letter-spacing: 0.3px;
            margin: 0;
        }
        .receipt-sub-nama {
            text-align: center; font-size: 10px; color: #374151; font-weight: 600;
            margin-top: 2px; line-height: 1.1;
        }
        .receipt-alamat-wa {
            text-align: center; font-size: 9.5px; color: #374151;
            margin-top: 5px; line-height: 1.3;
            font-weight: 500;
        }
        .receipt-meta-wrapper {
            margin-top: 6px;
        }
        .receipt-meta-row {
            display: flex; justify-content: flex-start; align-items: flex-start;
            gap: 4px;
        }
        .receipt-meta-label {
            font-weight: 800; font-size: 9.5px; color: #000000;
            flex: 0 0 auto; white-space: nowrap;
        }
        .receipt-meta-value {
            font-size: 9.5px; color: #000000; font-weight: 600;
            flex: 1; min-width: 0; word-break: break-all;
        }
        .receipt-pay-method {
            text-align: center;
            padding: 5px 0;
            border-top: 1px dashed #374151;
            border-bottom: 1px dashed #374151;
            font-weight: 900; font-size: 10.5px;
            color: #000000;
            margin: 6px 0 7px 0;
            letter-spacing: 0.3px;
        }
        .receipt-lunas {
            text-align: center;
            margin-top: 6px;
            padding: 4px 0;
            font-weight: 900; font-size: 10px;
            color: #000000;
            letter-spacing: 0.2px;
        }
        .receipt-section-label {
            font-weight: 900; font-size: 10.5px; color: #000000;
            margin-bottom: 3px; letter-spacing: 0.3px;
        }
        .receipt-item {
            padding: 4px 0 5px 0;
            border-bottom: 1px dashed #E5E7EB;
            margin-bottom: 2px;
        }
        .receipt-item:last-child { border-bottom: none; padding-bottom: 0; margin-bottom: 0; }
        .receipt-item-top {
            display: flex; justify-content: space-between; align-items: flex-start;
            gap: 6px;
        }
        .receipt-item-nama {
            flex: 1; min-width: 0;
            font-size: 11px; font-weight: 700; color: #000000;
            line-height: 1.2;
        }
        .receipt-item-harga-satuan {
            font-size: 9.5px; color: #4B5563;
            margin-top: 2px;
            font-weight: 500;
            line-height: 1.1;
        }
        .receipt-item-kanan {
            flex-shrink: 0; text-align: right;
            min-width: 0;
        }
        .receipt-subtotal-item {
            font-weight: 900; color: #000000; font-size: 11px;
            white-space: nowrap;
        }
        .receipt-total-row {
            display: flex; justify-content: space-between; align-items: baseline;
            padding: 1.5px 0;
            gap: 8px;
        }
        .receipt-total-row .label {
            font-weight: 800; color: #000000; font-size: 10.5px;
            letter-spacing: 0.2px;
            flex: 0 0 auto;
        }
        .receipt-total-row .value {
            font-weight: 900; color: #000000; font-size: 10.5px;
            text-align: right;
            flex: 1; min-width: 0;
            white-space: nowrap;
        }
        .receipt-total-row.row-total {
            margin-top: 2px;
            padding-top: 4px;
        }
        .receipt-total-row.row-total .label,
        .receipt-total-row.row-total .value {
            font-size: 12px; font-weight: 900;
        }
        .receipt-total-row.row-bayar .label,
        .receipt-total-row.row-bayar .value {
            font-size: 11px;
        }
        .receipt-total-row.row-kembalian .label,
        .receipt-total-row.row-kembalian .value {
            font-size: 12px;
        }
        .receipt-footer-thank {
            text-align: center;
            margin-top: 6px;
            line-height: 1.2;
            font-size: 10px;
            color: #000000;
            font-weight: 700;
        }
        .receipt-footer-hashtag {
            text-align: center;
            font-weight: 600;
            font-size: 9px;
            color: #374151;
            margin-top: 4px;
            line-height: 1.2;
            letter-spacing: 0.1px;
        }
        .receipt-footer-bless {
            text-align: center;
            font-size: 9.5px;
            color: #000000;
            font-weight: 700;
            margin-top: 4px;
            line-height: 1.1;
        }
        @media print {
            @page { size: 58mm auto; margin: 0mm; }
            html, body {
                width: 100% !important; max-width:58mm !important; min-width:58mm !important;
                margin: 0 !important; padding: 0 !important;
                background: #FFFFFF !important;
                -webkit-print-color-adjust: exact; print-color-adjust: exact;
                overflow: visible !important;
                font-size: 11.5px !important;
                line-height: 1.55 !important;
            }
            body > *:not(.receipt):not(.receipt *) { display: none !important; }
            .receipt {
                display: block !important; visibility: visible !important;
                width: 100% !important; max-width:58mm !important; min-width:58mm !important;
                position: absolute; left: 0; top: 0; margin: 0 !important;
                padding: 5mm 4mm 5mm 4mm !important;
                page-break-inside: auto !important; page-break-after: avoid !important;
                -webkit-print-color-adjust: exact; print-color-adjust: exact;
                box-sizing: border-box !important;
                background: #FFFFFF !important;
                font-size: 11.5px !important;
                line-height: 1.55 !important;
            }
            .receipt * { line-height: 1.55 !important; }
            .receipt-divider { margin: 9px 0 !important; }
            .receipt-divider-solid { margin: 8px 0 !important; }
            .receipt-title { font-size: 15px !important; line-height: 1.15 !important; }
            .receipt-sub-nama { font-size: 10.5px !important; margin-top: 4px !important; }
            .receipt-alamat-wa { font-size: 10px !important; margin-top: 7px !important; line-height: 1.45 !important; }
            .receipt-meta-wrapper { margin-top: 9px !important; margin-bottom: 2px !important; }
            .receipt-meta-row { padding: 1.5px 0 !important; }
            .receipt-meta-label, .receipt-meta-value { font-size: 10px !important; }
            .receipt-pay-method { padding: 7px 0 !important; font-size: 11px !important; margin: 9px 0 10px 0 !important; }
            .receipt-lunas { margin-top: 8px !important; font-size: 10.5px !important; padding: 5px 0 !important; }
            .receipt-section-label { font-size: 11px !important; margin-bottom: 6px !important; padding-top: 2px !important; }
            .receipt-item { padding: 5px 0 7px 0 !important; margin-bottom: 3px !important; }
            .receipt-item-nama { font-size: 11.5px !important; line-height: 1.3 !important; }
            .receipt-item-harga-satuan { font-size: 10px !important; margin-top: 4px !important; line-height: 1.2 !important; }
            .receipt-subtotal-item { font-size: 11.5px !important; }
            .receipt-total-row { padding: 3px 0 !important; }
            .receipt-total-row .label, .receipt-total-row .value { font-size: 11px !important; }
            .receipt-total-row.row-total { margin-top: 5px !important; padding-top: 7px !important; border-top: 1.5px solid #111827 !important; }
            .receipt-total-row.row-total .label, .receipt-total-row.row-total .value { font-size: 13px !important; }
            .receipt-total-row.row-bayar { padding-top: 5px !important; }
            .receipt-total-row.row-bayar .label, .receipt-total-row.row-bayar .value { font-size: 11.5px !important; }
            .receipt-total-row.row-kembalian { padding: 5px 0 2px 0 !important; }
            .receipt-total-row.row-kembalian .label, .receipt-total-row.row-kembalian .value { font-size: 12.5px !important; }
            .receipt-footer-thank { margin-top: 10px !important; font-size: 10.5px !important; line-height: 1.4 !important; }
            .receipt-footer-hashtag { font-size: 9.5px !important; margin-top: 6px !important; line-height: 1.35 !important; }
            .receipt-footer-bless { font-size: 9.5px !important; margin-top: 4px !important; }
            .ucapan-platform-row { padding: 8px 8px !important; margin-bottom: 6px !important; gap: 8px !important; border: 1.2px solid #111827 !important; border-radius: 12px !important; }
            .ucapan-platform-kiri { gap:8px !important; }
            .ucapan-platform-icon { width:26px !important; height:26px !important; border-radius:9px !important; font-size:16px !important; border:1px solid #D97706 !important; }
            .ucapan-platform-nama { font-size: 11px !important; font-weight: 900 !important; line-height:1.15 !important; }
            .ucapan-platform-star { font-size:11px !important; letter-spacing:1px !important; }
            .ucapan-platform-star-label { margin-top:2px !important; font-size:7.8px !important; font-weight:800 !important; line-height:1.1 !important; }
        }

        /* ==== SCROLLBAR ==== */
        .keranjang-body::-webkit-scrollbar { width:6px; }
        .keranjang-body::-webkit-scrollbar-track { background:transparent; }
        .keranjang-body::-webkit-scrollbar-thumb { background:rgba(153,27,27,0.25); border-radius:99px; }
    </style>
</head>
<body>

<!-- TOP NAV (MODEL BARU: 1 BARIS PUTIH CARD Rounded Top, sesuai referensi user) -->
<div class="topnav">
    <div class="container-fluid p-0">
        <div class="d-flex flex-nowrap align-items-center justify-content-between w-100 nav-force-one-row">
            <div class="topnav-brand">
                <div class="topnav-brand-icon"><i class="bi bi-cup-straw"></i></div>
                <div>
                    <h1>Es Baraya</h1>
                </div>
            </div>
            <div class="topnav-right">
                <button type="button" class="btn btn-topnav-ucapan transition-card" onclick="cetakKartuUcapan();" title="Cetak Kartu Ucapan Review 5 Bintang">
                    <i class="bi bi-gift-fill"></i> Ucapan
                </button>
                <a href="<?php echo $base_url; ?>/login.php" target="_blank" class="btn btn-topnav-admin transition-card">
                    <i class="bi bi-person-badge"></i> Admin
                </a>
            </div>
        </div>
    </div>
</div>

<!-- MAIN LAYOUT -->
<div class="container-fluid p-0">
    <?php if ($totalProduk === 0): ?>
    <div class="row justify-content-center mt-5">
        <div class="col-12 col-md-10 col-lg-7 col-xl-6">
            <div class="card text-center border-0 rounded-5 py-6 px-4" style="box-shadow: 0 20px 50px rgba(15,23,42,0.1); border-top:5px solid #FBBF24;">
                <div class="rounded-circle mx-auto mb-4 d-flex align-items-center justify-content-center" style="width:100px; height:100px; background: linear-gradient(135deg,#FEF3C7,#FDE68A); color:#78350F; font-size:3.2rem;">
                    <i class="bi bi-cup-straw"></i>
                </div>
                <h3 class="fw-black mb-2" style="color:#450A0A; letter-spacing:-0.3px;">Belum Ada Produk</h3>
                <p class="text-muted mb-5 mx-auto" style="max-width:460px;">Menu belum disiapkan. Silakan login sebagai admin untuk menambahkan daftar produk (nama, harga, gambar) pada menu <b>"Produk Kasir"</b>.</p>
                <a href="<?php echo $base_url; ?>/login.php" class="btn fw-bold rounded-pill px-6 py-3 text-white" style="background: linear-gradient(135deg,#450A0A,#991B1B); box-shadow: 0 10px 24px rgba(153,27,27,0.35); border:2px solid #FBBF24;">
                    <i class="bi bi-box-arrow-in-right me-2"></i> Login Admin &amp; Tambah Produk
                </a>
            </div>
        </div>
    </div>
    <?php else: ?>
    <div class="row g-5">
        <!-- KIRI: GRID PRODUK (order 2 di HP, 1 di desktop) -->
        <div class="col-12 col-lg-8 col-xl-8-5 order-produk">
            <h5 class="fw-bold mb-3 mt-2" style="color:#334155; letter-spacing:-0.2px;">
                <i class="bi bi-grid-fill me-2" style="color:#991B1B;"></i> Pilih Menu
                <span class="badge rounded-pill ms-2 fw-semibold" style="background: rgba(153,27,27,0.12); color:#991B1B; font-size:0.8rem;"><?php echo $totalProduk; ?> item</span>
            </h5>

            <!-- FILTER KATEGORI PILLS (DINAMIS DARI DATABASE - otomatis munculkan kategori baru seperti vanisa) -->
            <div class="kategori-pills">
                <button type="button" class="pill kat-active" onclick="filterProduk('all', this)">Semua</button>
                <?php foreach ($kategoriListDb as $katItem):
                    $katTrim = trim($katItem);
                    $katDisplay = htmlspecialchars($katTrim, ENT_QUOTES);
                    // Escape untuk onclick JS string pakai SINGLE QUOTE (hindari bentrok double quote attribute onclick="...")
                    $katJsSingleEsc = str_replace(
                        ['\\', "'", "\n", "\r", "\t", '</'],
                        ['\\\\', "\\'", '\\n', '\\r', '\\t', '<\\/'],
                        $katTrim
                    );
                ?>
                <button type="button" class="pill" onclick="filterProduk('<?php echo $katJsSingleEsc; ?>', this)"><?php echo $katDisplay; ?></button>
                <?php endforeach; ?>
            </div>

            <div class="row g-3 g-md-4" id="produkGrid">
                <?php foreach ($produkList as $p):
                    $gambarUrl = !empty($p['gambar']) ? ($base_url . '/' . ltrim($p['gambar'], '/')) : '';
                    $kategori = htmlspecialchars($p['kategori'] ?? 'Minuman');
                ?>
                <div class="col-6 col-md-4 col-xl-3" data-kategori="<?php echo $kategori; ?>">
                    <div class="produk-card" data-id="<?php echo $p['id']; ?>" data-nama="<?php echo htmlspecialchars($p['nama'], ENT_QUOTES); ?>" data-harga="<?php echo $p['harga']; ?>">
                        <div class="produk-img-wrap">
                            <?php if ($gambarUrl): ?>
                                <img src="<?php echo htmlspecialchars($gambarUrl); ?>" alt="<?php echo htmlspecialchars($p['nama']); ?>" loading="lazy">
                            <?php else: ?>
                                <div class="produk-img-placeholder">
                                    <div class="text-center">
                                        <i class="bi bi-cup-straw fs-1 mb-1 d-block opacity-80"></i>
                                        <div class="small fw-semibold opacity-80">No Image</div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="produk-body">
                            <div class="produk-nama"><?php echo htmlspecialchars($p['nama']); ?></div>
                            <div class="produk-harga">Rp <?php echo number_format($p['harga'], 0, ',', '.'); ?></div>
                            <div class="qty-wrap" onclick="event.stopPropagation();">
                                <button type="button" class="qty-btn minus" onclick="event.stopPropagation(); produkMinus(<?php echo $p['id']; ?>);" aria-label="Kurang">
                                    <i class="bi bi-dash-lg"></i>
                                </button>
                                <div class="qty-num" id="qty-produk-<?php echo $p['id']; ?>">0</div>
                                <button type="button" class="qty-btn plus" onclick="event.stopPropagation(); produkPlus(<?php echo $p['id']; ?>);" aria-label="Tambah">
                                    <i class="bi bi-plus-lg"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- KANAN: KERANJANG STICKY (order 1 di HP, 2 di desktop) -->
        <div class="col-12 col-lg-4 col-xl-3-5 order-keranjang">
            <div class="keranjang-wrap">
                <div class="keranjang-card">
                    <div class="keranjang-header" id="keranjangHeader" onclick="toggleKeranjang();">
                        <div class="keranjang-header-left">
                            <h4 style="margin:0;"><i class="bi bi-bag-check-fill me-2" style="color:#FDE68A;"></i> Pesanan</h4>
                        </div>
                        <div style="display:flex; align-items:center; gap:10px;">
                            <span class="keranjang-badge" onclick="event.stopPropagation();"><span id="totalItemBadge">0</span> item</span>
                            <button type="button" class="keranjang-toggle" id="keranjangToggleIcon" aria-label="Toggle keranjang">
                                <i class="bi bi-chevron-down"></i>
                            </button>
                        </div>
                    </div>

                    <div class="keranjang-body keranjang-body-mobile show" id="keranjangBody">
                        <div class="keranjang-empty">
                            <i class="bi bi-cart-x"></i>
                            <div class="fw-bold mb-1 small opacity-80">Keranjang Kosong</div>
                            <div class="small opacity-70">Klik tombol [+] pada menu untuk menambahkan pesanan.</div>
                        </div>
                    </div>

                    <div class="keranjang-footer keranjang-footer show" id="keranjangFooter">
                        <div class="ringkasan-row">
                            <span class="ringkasan-total-label">TOTAL</span>
                            <span class="ringkasan-total-value" id="totalBayarText">Rp 0</span>
                        </div>
                        <hr class="divider-tebal">

                        <!-- Payment Toggle (CASH / QRIS) -->
                        <div class="payment-toggle mb-3" style="margin-bottom: 14px !important;">
                            <button type="button" class="payment-btn cash active" id="payBtnCash" onclick="setPayment('CASH');">
                                <i class="bi bi-cash-coin me-1"></i> CASH
                            </button>
                            <button type="button" class="payment-btn qris" id="payBtnQris" onclick="setPayment('QRIS');">
                                <i class="bi bi-qr-code-scan me-1"></i> QRIS
                            </button>
                        </div>

                        <label class="input-bayar-label" for="nominalBayar"><i class="bi bi-cash-stack me-1" style="color:#991B1B;"></i> Nominal Bayar (Customer)</label>
                        <div class="input-group input-group-bayar">
                            <span class="input-group-text fw-bold" style="border-radius:16px 0 0 16px; background:#FFF; border-color:#E2E8F0; color:#991B1B; border-right:0;">Rp</span>
                            <input type="text" id="nominalBayar" class="form-control input-bayar" placeholder="0" inputmode="numeric" autocomplete="off" style="border-radius:0 16px 16px 0;">
                        </div>

                        <!-- Quick Bayar Shortcut (8 pecahan) -->
                        <div class="quick-bayar-wrap" id="quickBayarWrap">
                            <button type="button" class="quick-bayar-btn" data-nominal="20000" onclick="quickBayar(this, 20000);">20K</button>
                            <button type="button" class="quick-bayar-btn" data-nominal="25000" onclick="quickBayar(this, 25000);">25K</button>
                            <button type="button" class="quick-bayar-btn" data-nominal="50000" onclick="quickBayar(this, 50000);">50K</button>
                            <button type="button" class="quick-bayar-btn" data-nominal="75000" onclick="quickBayar(this, 75000);">75K</button>
                            <button type="button" class="quick-bayar-btn" data-nominal="100000" onclick="quickBayar(this, 100000);">100K</button>
                            <button type="button" class="quick-bayar-btn" data-nominal="150000" onclick="quickBayar(this, 150000);">150K</button>
                            <button type="button" class="quick-bayar-btn" data-nominal="200000" onclick="quickBayar(this, 200000);">200K</button>
                            <button type="button" class="quick-bayar-btn" data-nominal="0" onclick="quickBayar(this, null);" title="Pas Total">
                                <i class="bi bi-check2-all"></i> Pas
                            </button>
                        </div>

                        <div class="kembalian-card" id="kembalianCard">
                            <div class="kembalian-label"><i class="bi bi-wallet2"></i> Kembalian</div>
                            <div class="kembalian-value" id="kembalianText">Rp 0</div>
                        </div>

                        <button type="button" class="btn btn-cetak d-none d-lg-block" id="btnCetakDesktop" onclick="cetakResi()" disabled>
                            <i class="bi bi-printer-fill fs-5"></i>
                            CETAK RESI
                        </button>
                        <button type="button" class="btn btn-cetak-ucapan d-none d-lg-block" onclick="cetakKartuUcapan();">
                            <i class="bi bi-gift-fill fs-6"></i>
                            CETAK KARTU UCAPAN
                        </button>
                        <button type="button" class="btn btn-reset d-none d-lg-block" onclick="resetKeranjang()">
                            <i class="bi bi-arrow-counterclockwise me-1"></i> Bersihkan Semua
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- STICKY ACTION BAR MOBILE (bawah layar) - 1 BARIS PERSIS CONTOH -->
    <div class="bottom-action-mobile d-lg-none">
        <div class="bottom-action-mobile-sub">
            <div class="bottom-kiri" onclick="bukaModalKeranjang();" style="cursor:pointer;">
                <div class="bottom-keranjang-btn" title="Lihat Keranjang">
                    <i class="bi bi-cart-fill"></i>
                    <span class="badge-bulet" id="bottomBadgeItem">0</span>
                </div>
                <div class="bottom-total-group">
                    <div class="bottom-total-label">TOTAL</div>
                    <div class="bottom-total-val" id="bottomTotalVal">Rp 0</div>
                </div>
            </div>
            <button type="button" class="btn-bayar" id="btnBayarBottom" onclick="bukaModalKeranjang();" disabled>
                <i class="bi bi-wallet2 me-1"></i> Bayar
            </button>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- HIDDEN RECEIPT (UNTUK PRINT RESI TRANSAKSI) -->
<div class="receipt" id="receiptPrint"></div>

<!-- HIDDEN RECEIPT (KARTU UCAPAN TERIMA KASIH - CETAK THERMAL 58mm) -->
<div class="receipt" id="receiptKartuUcapan">
    <div class="receipt-card" style="padding: 2px 0 1px 0;">
        <div class="ucapan-gambar-wrapper-top">
            <img
                src="assets/img/ucapan.png"
                class="ucapan-gambar-hero"
                alt="Kartu Ucapan Es Teller Dawet Baraya"
                onerror="this.style.display='none'; document.getElementById('ucapanBackup').style.display='block';"
                loading="eager"
                decoding="sync">
        </div>

        <!-- Fallback / Backup: Jika gambar tidak ketemu / error, tampil teks cadangan (default DISPLAY: NONE). -->
        <div id="ucapanBackup" style="display:none; padding: 2px 3px 1px 3px;">
            <div class="receipt-center">
                <div class="receipt-title" style="font-size:16px; line-height:1.2;">✨ TERIMA KASIH ✨</div>
                <div class="receipt-sub-nama" style="margin-top:5px; font-size:11px; color:#111827; font-weight:900;">Es Teller & Dawet Baraya</div>
                <div class="receipt-alamat-wa" style="margin-top:6px; font-weight:700;">Jl. Kakatua No.103, Condongcatur<br>Sleman DIY 55281</div>
                <div style="text-align:center; margin-top:7px; font-size:10.5px; font-weight:900; letter-spacing:0.2px;">
                    <i class="bi bi-whatsapp" style="color:#10B981;"></i> WhatsApp: +62 831-8930-2691
                </div>
            </div>
            <div class="receipt-divider-solid" style="margin:10px 0;"></div>
            <div style="padding: 2px 0 0 0;">
                <div style="font-weight:900; font-size:11px; text-align:center; letter-spacing:0.3px; margin-bottom:9px; line-height:1.25;">
                    🙏 MOHON BANTUAN RATING BINTANG 5 🙏
                </div>
                <div class="ucapan-platform-row">
                    <div class="ucapan-platform-kiri">
                        <div class="ucapan-platform-icon">📍</div>
                        <div class="ucapan-platform-nama">GOOGLE MAPS</div>
                    </div>
                    <div class="ucapan-platform-star">🌟🌟🌟🌟🌟<span class="ucapan-platform-star-label">Rating 5 Ya</span></div>
                </div>
                <div class="ucapan-platform-row">
                    <div class="ucapan-platform-kiri">
                        <div class="ucapan-platform-icon">🛵</div>
                        <div class="ucapan-platform-nama">SHOPEEFOOD</div>
                    </div>
                    <div class="ucapan-platform-star">🌟🌟🌟🌟🌟<span class="ucapan-platform-star-label">Rating 5 Ya</span></div>
                </div>
                <div class="ucapan-platform-row">
                    <div class="ucapan-platform-kiri">
                        <div class="ucapan-platform-icon">🚗</div>
                        <div class="ucapan-platform-nama">GOFOOD · GOJEK</div>
                    </div>
                    <div class="ucapan-platform-star">🌟🌟🌟🌟🌟<span class="ucapan-platform-star-label">Rating 5 Ya</span></div>
                </div>
                <div class="ucapan-platform-row" style="margin-bottom:2px;">
                    <div class="ucapan-platform-kiri">
                        <div class="ucapan-platform-icon">🛺</div>
                        <div class="ucapan-platform-nama">GRABFOOD</div>
                    </div>
                    <div class="ucapan-platform-star">🌟🌟🌟🌟🌟<span class="ucapan-platform-star-label">Rating 5 Ya</span></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ============== MODAL KERANJANG KECIL (STEP 1) ============== -->
<div class="modal fade modal-sm-custom" id="modalKeranjangKecil" tabindex="-1" aria-labelledby="modalKeranjangKecilLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalKeranjangKecilLabel">
                    <i class="bi bi-cart-fill me-2" style="color:#991B1B;"></i> Keranjang
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body" id="modalKeranjangKecilBody">
                <!-- Isi di-render via JS renderModalKeranjangBody() -->
            </div>
            <div class="modal-footer">
                <div style="flex:1;">
                    <div class="footer-total-label">TOTAL</div>
                    <div class="footer-total-val" id="modalKeranjangTotal">Rp 0</div>
                </div>
                <button type="button" class="btn-checkout-modal" id="modalKeranjangCheckoutBtn" onclick="prosesCheckout();" disabled>
                    Checkout <i class="bi bi-arrow-right-short ms-1"></i>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ============== MODAL PEMBAYARAN BESAR (STEP 2) ============== -->
<div class="modal fade modal-lg-custom" id="modalPembayaranBesar" tabindex="-1" aria-labelledby="modalPembayaranBesarLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalPembayaranBesarLabel">
                    <i class="bi bi-wallet2 me-2" style="color:#991B1B;"></i> Pembayaran
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body">

                <!-- 1. TOTAL TAGIHAN BESAR -->
                <div class="bayar-total-wrap">
                    <div class="bayar-total-label">TOTAL TAGIHAN</div>
                    <div class="bayar-total-big" id="bayarTotalTagihan">Rp 0</div>
                </div>

                <!-- 2. METODE PEMBAYARAN -->
                <div class="bayar-card">
                    <div class="bayar-card-label"><i class="bi bi-credit-card-2-front-fill" style="color:#FBBF24;"></i> Metode Pembayaran</div>
                    <div class="payment-btn-duo">
                        <button type="button" class="pay-btn pay-active" id="payBtnCashModal" onclick="setPaymentModal('CASH');">
                            <i class="bi bi-wallet2"></i>
                            <span>CASH</span>
                        </button>
                        <button type="button" class="pay-btn" id="payBtnQrisModal" onclick="setPaymentModal('QRIS');">
                            <i class="bi bi-qr-code-scan"></i>
                            <span>QRIS</span>
                        </button>
                    </div>
                </div>

                <!-- 3. UANG DITERIMA + QUICK NOMINAL -->
                <div class="bayar-card">
                    <div class="bayar-card-label input-bayar-label-modal"><i class="bi bi-cash-stack" style="color:#10B981;"></i> Uang Diterima</div>
                    <div class="input-bayar-group input-group-bayar-modal">
                        <span class="input-bayar-prefix">Rp</span>
                        <input type="text" id="nominalBayarModal" class="input-bayar-text" value="" placeholder="0" inputmode="numeric" autocomplete="off">
                    </div>
                    <div class="quick-nominal-wrap" id="quickNominalWrapModal">
                        <button type="button" class="quick-nominal-btn" onclick="quickBayarModal(5000, this)">5.000</button>
                        <button type="button" class="quick-nominal-btn" onclick="quickBayarModal(10000, this)">10.000</button>
                        <button type="button" class="quick-nominal-btn" onclick="quickBayarModal(20000, this)">20.000</button>
                        <button type="button" class="quick-nominal-btn" onclick="quickBayarModal(50000, this)">50.000</button>
                        <button type="button" class="quick-nominal-btn" onclick="quickBayarModal(100000, this)">100.000</button>
                        <button type="button" class="quick-nominal-btn qn-special" onclick="quickBayarModal(0, this)">
                            <i class="bi bi-check-lg me-1"></i> Uang Pas
                        </button>
                    </div>
                </div>

                <!-- 4. KEMBALIAN -->
                <div class="kembalian-card" id="kembalianCardModal">
                    <div class="kembalian-card-label">
                        <i class="bi bi-wallet2"></i> Kembalian
                    </div>
                    <div class="kembalian-card-value" id="kembalianNilaiModal">Rp 0</div>
                </div>

                <!-- 5. TOMBOL PROSES TRANSAKSI -->
                <button type="button" class="proses-transaksi-btn" id="prosesTransaksiBtn" onclick="prosesTransaksiDanCetak();" disabled>
                    <i class="bi bi-printer-fill"></i> PROSES TRANSAKSI
                </button>

                <!-- 6. BATAL -->
                <button type="button" class="btn-batal-bawah" onclick="tutupModalPembayaran();">Batal</button>
            </div>
        </div>
    </div>
</div>

<script>
// ============== KONFIGURASI TOKO (JS GLOBAL dari PHP) ==============
const TOKO_NAMA_JS = <?php echo json_encode(TOKO_NAMA, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
const TOKO_ALAMAT_SINGKAT_JS = <?php echo json_encode(TOKO_ALAMAT_SINGKAT, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
const TOKO_WA_JS = <?php echo json_encode(TOKO_WA, JSON_HEX_TAG | JSON_HEX_AMP); ?>;

// ============== DATA PRODUK DARI PHP ==============
const PRODUK_DB = [
    <?php foreach ($produkList as $p): ?>
        {
            id: <?php echo $p['id']; ?>,
            nama: <?php echo json_encode($p['nama']); ?>,
            harga: <?php echo (int)$p['harga']; ?>,
            gambar: <?php echo json_encode(!empty($p['gambar']) ? ($base_url.'/'.ltrim($p['gambar'],'/')) : null); ?>,
            kategori: <?php echo json_encode($p['kategori'] ?? 'Minuman'); ?>
        },
    <?php endforeach; ?>
];

// ============== STATE KERANJANG ==============
const keranjang = {}; // key=produkId, value={ id, nama, harga, qty }
let keranjangTerbuka = true; // state toggle keranjang mobile
let CURRENT_PAYMENT = 'CASH'; // 'CASH' | 'QRIS'

// ============== FILTER KATEGORI PRODUK (CASE INSENSITIVE, cocok vanisa/Vanisa/VANISA) ==============
function filterProduk(kat, btnEl) {
    // 1. Toggle class active semua pill (SEMUANYA default PUTIH BORDER OUTLINE; active = SOLID MAROON)
    document.querySelectorAll('.kategori-pills .pill').forEach(b => {
        b.classList.remove('kat-active');
    });
    if (btnEl) {
        btnEl.classList.add('kat-active');
    }
    // 2. Show/Hide semua col produk berdasarkan data-kategori (COMPARE lowercase, tahan case aneh dari DB)
    const katLow = (kat || '').toString().trim().toLowerCase();
    const items = document.querySelectorAll('#produkGrid > [data-kategori]');
    items.forEach(el => {
        const elKat = ((el.getAttribute('data-kategori') || 'Minuman') + '').trim().toLowerCase();
        if (katLow === 'all' || elKat === katLow) {
            el.style.display = '';
        } else {
            el.style.display = 'none';
        }
    });
}

// ============== GLOBAL MODAL REF & INIT (attach ke WINDOW agar scope global accessible dari onclick inline) ==============
window.bsModalKeranjangKecil = null;
window.bsModalPembayaranBesar = null;

document.addEventListener('DOMContentLoaded', function() {
    // Init 2 Bootstrap Modal (new instance)
    try {
        const mk = document.getElementById('modalKeranjangKecil');
        if (mk) window.bsModalKeranjangKecil = new bootstrap.Modal(mk);
        const mb = document.getElementById('modalPembayaranBesar');
        if (mb) window.bsModalPembayaranBesar = new bootstrap.Modal(mb);
    } catch(e) { console.warn('Init modal error:', e); }

    // Auto format ribuan untuk input nominal bayar MODAL BESAR
    const inpModal = document.getElementById('nominalBayarModal');
    if (inpModal) {
        inpModal.addEventListener('input', function() {
            const val = angkaOnly(this.value);
            if (val === 0) this.value = '';
            else this.value = val.toLocaleString('id-ID');
            updateKalkulasiPembayaranModal();
        });
        inpModal.addEventListener('focus', function() { this.select(); });
        inpModal.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                const btnProses = document.getElementById('prosesTransaksiBtn');
                if (btnProses && !btnProses.disabled) prosesTransaksiDanCetak();
            }
        });
    }
});

// ============== PAYMENT TOGGLE (CASH / QRIS) ==============
function setPayment(tipe) {
    CURRENT_PAYMENT = (tipe === 'QRIS') ? 'QRIS' : 'CASH';
    const btnCash = document.getElementById('payBtnCash');
    const btnQris = document.getElementById('payBtnQris');
    const inp = document.getElementById('nominalBayar');
    const btnsQuick = document.querySelectorAll('.quick-bayar-btn');
    const labelInput = document.querySelector('.input-bayar-label');
    const inputGroup = document.querySelector('.input-group-bayar');
    const total = hitungTotal();

    if (CURRENT_PAYMENT === 'QRIS') {
        if (btnCash) btnCash.classList.remove('active');
        if (btnQris) btnQris.classList.add('active');
        // Disable input nominal + set PAS TOTAL (otomatis bayar = total)
        if (inp) {
            if (total > 0) inp.value = total.toLocaleString('id-ID');
            else inp.value = '';
            inp.disabled = true;
            inp.style.opacity = '0.55';
            inp.style.cursor = 'not-allowed';
            inp.style.background = '#F8FAFC';
        }
        if (labelInput) labelInput.style.opacity = '0.55';
        if (inputGroup) inputGroup.style.opacity = '0.85';
        // Disable semua quick bayar KECUALI tombol "Pas" (nominal=0) biar user bisa toggle
        btnsQuick.forEach(b => {
            const nom = Number(b.getAttribute('data-nominal') || '0');
            if (nom === 0) {
                b.disabled = false;
                b.classList.add('active'); // auto aktif Pas di QRIS
            } else {
                b.disabled = true;
                b.style.opacity = '0.4';
                b.style.cursor = 'not-allowed';
            }
        });
    } else {
        // CASH mode: enable semua
        if (btnCash) btnCash.classList.add('active');
        if (btnQris) btnQris.classList.remove('active');
        if (inp) {
            inp.disabled = false;
            inp.style.opacity = '';
            inp.style.cursor = '';
            inp.style.background = '';
        }
        if (labelInput) labelInput.style.opacity = '';
        if (inputGroup) inputGroup.style.opacity = '';
        btnsQuick.forEach(b => {
            b.disabled = false;
            b.style.opacity = '';
            b.style.cursor = '';
            b.classList.remove('active');
        });
    }
    updateKembalian();
    updateBtnCetak();
}

function formatRupiah(num) {
    if (isNaN(num) || num === null || num === undefined) num = 0;
    num = Math.round(Number(num));
    return 'Rp ' + num.toLocaleString('id-ID');
}
function angkaOnly(str) {
    if (!str) return 0;
    return parseInt(String(str).replace(/[^0-9]/g, '')) || 0;
}

// ============== QTY PRODUK CARD ==============
function produkPlus(id) {
    const p = PRODUK_DB.find(x => x.id === id);
    if (!p) return;
    if (!keranjang[id]) {
        keranjang[id] = { id: p.id, nama: p.nama, harga: p.harga, qty: 0 };
    }
    keranjang[id].qty += 1;
    updateQtyDisplay(id);
    renderKeranjang();
}
function produkMinus(id) {
    if (!keranjang[id]) return;
    keranjang[id].qty -= 1;
    if (keranjang[id].qty <= 0) {
        delete keranjang[id];
    }
    updateQtyDisplay(id);
    renderKeranjang();
}
function updateQtyDisplay(id) {
    const el = document.getElementById('qty-produk-' + id);
    if (el) el.textContent = keranjang[id] ? keranjang[id].qty : 0;
}

// ============== RENDER KERANJANG ==============
function renderKeranjang() {
    const body = document.getElementById('keranjangBody');
    const keys = Object.keys(keranjang);
    let total = 0;
    let totalItem = 0;
    if (keys.length === 0) {
        body.innerHTML = `
        <div class="keranjang-empty">
            <i class="bi bi-cart-x"></i>
            <div class="fw-bold mb-1 small opacity-80">Keranjang Kosong</div>
            <div class="small opacity-70">Klik tombol [+] pada menu untuk menambahkan pesanan.</div>
        </div>`;
    } else {
        let html = '';
        for (const k of keys) {
            const it = keranjang[k];
            const subtotal = it.harga * it.qty;
            total += subtotal;
            totalItem += it.qty;
            html += `
            <div class="keranjang-item">
                <div class="keranjang-item-info">
                    <div class="keranjang-item-nama">${escapeHtml(it.nama)}</div>
                    <div class="keranjang-item-harga">${formatRupiah(it.harga)}</div>
                </div>
                <div class="keranjang-item-qty" onclick="event.stopPropagation();">
                    <button type="button" class="qty-btn minus" onclick="event.stopPropagation(); produkMinus(${it.id});"><i class="bi bi-dash"></i></button>
                    <div class="qty-num">${it.qty}</div>
                    <button type="button" class="qty-btn plus" onclick="event.stopPropagation(); produkPlus(${it.id});"><i class="bi bi-plus"></i></button>
                </div>
                <div class="keranjang-subtotal-item">${formatRupiah(subtotal)}</div>
                <button type="button" class="keranjang-hapus" title="Hapus item" onclick="hapusItem(${it.id})"><i class="bi bi-x-lg"></i></button>
            </div>`;
        }
        body.innerHTML = html;
    }

    document.getElementById('totalBayarText').textContent = formatRupiah(total);
    document.getElementById('totalItemBadge').textContent = totalItem;
    // Update badge modal & tombol mobile keranjang (badge baru di bottom bar kiri)
    const badgeMobile = document.getElementById('bottomBadgeItem');
    if (badgeMobile) badgeMobile.textContent = totalItem;
    const badgeModal = document.getElementById('totalItemBadgeModal');
    if (badgeModal) badgeModal.textContent = totalItem + ' item';
    // Update sticky bottom mobile TOTAL
    const bottomTotal = document.getElementById('bottomTotalVal');
    if (bottomTotal) bottomTotal.textContent = formatRupiah(total);
    updateKembalian();
    updateBtnCetak();
}
function hapusItem(id) {
    if (keranjang[id]) delete keranjang[id];
    updateQtyDisplay(id);
    renderKeranjang();
}
function resetKeranjang() {
    if (Object.keys(keranjang).length === 0) return;
    Swal.fire({
        title: 'Reset Keranjang?',
        html: 'Semua item di keranjang akan dihapus.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Ya, Reset',
        cancelButtonText: 'Batal',
        confirmButtonColor: '#991B1B',
        cancelButtonColor: '#64748B',
        reverseButtons: true
    }).then(res => {
        if (res.isConfirmed) {
            const ids = Object.keys(keranjang);
            for (const id of ids) { delete keranjang[id]; updateQtyDisplay(Number(id)); }
            renderKeranjang();
            document.getElementById('nominalBayar').value = '';
            updateKembalian();
        }
    });
}

// ============== NOMINAL BAYAR & KEMBALIAN ==============
const inputBayar = document.getElementById('nominalBayar');
if (inputBayar) {
    inputBayar.addEventListener('input', function() {
        const val = angkaOnly(this.value);
        if (val === 0) {
            this.value = '';
        } else {
            this.value = val.toLocaleString('id-ID');
        }
        updateKembalian();
        updateBtnCetak();
    });
    inputBayar.addEventListener('focus', function() { this.select(); });
    // Shortcuts: tekan enter di nominal bayar langsung cetak
    inputBayar.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            const btnDesktop = document.getElementById('btnCetakDesktop');
            if (btnDesktop && !btnDesktop.disabled) cetakResi();
        }
    });
}

function updateKembalian() {
    const total = hitungTotal();
    const bayar = angkaOnly(inputBayar ? inputBayar.value : '');
    const card = document.getElementById('kembalianCard');
    const label = document.querySelector('#kembalianCard .kembalian-label');
    const valEl = document.getElementById('kembalianText');

    if (CURRENT_PAYMENT === 'QRIS') {
        // QRIS: selalu bayar = total, kembalian 0 (tampilkan NETO)
        card.classList.remove('negative');
        valEl.textContent = formatRupiah(0);
        label.innerHTML = '<i class="bi bi-qr-code-scan"></i> QRIS · Pas';
        return;
    }

    // CASH MODE: hitung seperti biasa
    const kembalian = bayar - total;
    if (Object.keys(keranjang).length === 0) {
        card.classList.remove('negative');
        valEl.textContent = formatRupiah(0);
        label.innerHTML = '<i class="bi bi-wallet2"></i> Kembalian';
        return;
    }
    if (bayar === 0) {
        card.classList.remove('negative');
        valEl.textContent = formatRupiah(0);
        label.innerHTML = '<i class="bi bi-wallet2"></i> Kembalian';
        return;
    }
    if (kembalian < 0) {
        card.classList.add('negative');
        valEl.textContent = '- ' + formatRupiah(Math.abs(kembalian));
        label.innerHTML = '<i class="bi bi-exclamation-triangle-fill"></i> Kurang';
    } else {
        card.classList.remove('negative');
        valEl.textContent = formatRupiah(kembalian);
        label.innerHTML = '<i class="bi bi-wallet2"></i> Kembalian';
    }
}
function updateBtnCetak() {
    const total = hitungTotal();

    // 1) TOMBOL BAYAR BOTTOM BAR (HP) -> enable hanya jika TOTAL > 0 (kliknya buka modal, input bayar di dalam)
    const btnBayarHp = document.getElementById('btnBayarBottom');
    if (btnBayarHp) btnBayarHp.disabled = !(total > 0);

    // 2) TOMBOL CETAK RESI (footer keranjang-card / di dalam modal) -> logic lama sesuai payment
    let enableCetak;
    if (CURRENT_PAYMENT === 'QRIS') {
        enableCetak = total > 0;
    } else {
        const bayar = angkaOnly(inputBayar ? inputBayar.value : '');
        enableCetak = total > 0 && bayar >= total;
    }
    const btnDesktop = document.getElementById('btnCetakDesktop');
    if (btnDesktop) btnDesktop.disabled = !enableCetak;
}

// Toggle collapse keranjang (HANYA di HP / mobile)
function toggleKeranjang() {
    const isMobile = window.matchMedia && window.matchMedia('(max-width: 991.98px)').matches;
    if (!isMobile) return; // desktop ga usah collapse
    const body = document.getElementById('keranjangBody');
    const footer = document.getElementById('keranjangFooter');
    const icon = document.querySelector('#keranjangToggleIcon i');
    if (!body || !footer) return;

    keranjangTerbuka = !keranjangTerbuka;
    if (keranjangTerbuka) {
        body.classList.add('show');
        footer.classList.add('show');
        if (icon) { icon.classList.remove('bi-chevron-up'); icon.classList.add('bi-chevron-down'); }
    } else {
        body.classList.remove('show');
        footer.classList.remove('show');
        if (icon) { icon.classList.remove('bi-chevron-down'); icon.classList.add('bi-chevron-up'); }
    }
}

// Quick Nominal Bayar shortcut
function quickBayar(btnEl, nominal) {
    // Nonaktifkan active semua button dulu
    document.querySelectorAll('.quick-bayar-btn').forEach(b => b.classList.remove('active'));
    if (!nominal) {
        // "PAS TOTAL" -> set sesuai total tagihan
        const total = hitungTotal();
        const inp = document.getElementById('nominalBayar');
        if (inp) {
            if (total > 0) {
                inp.value = total.toLocaleString('id-ID');
            } else {
                inp.value = '';
            }
        }
        if (btnEl) btnEl.classList.add('active');
    } else {
        if (btnEl) btnEl.classList.add('active');
        const nominalBulat = Number(nominal) || 0;
        const total = hitungTotal();

        // ✅ JIKA NOMINAL < TOTAL: TETAP SET NOMINAL ASLI, MUNCULIN NOTIF GAGAL!
        let hasil = nominalBulat;
        if (hasil < total && nominalBulat > 0 && total > 0) {
            const kurang = total - hasil;
            Swal.fire({
                icon: 'error',
                title: '⚠️ Uang Kurang!',
                html: `Total tagihan <b style="color:#991B1B;">${formatRupiah(total)}</b><br>Uang anda cuma <b style="color:#B91C1C;">${formatRupiah(hasil)}</b><br>Masih kurang <b style="color:#DC2626;">${formatRupiah(kurang)}</b><br><small style="color:#64748B;">Silahkan pilih nominal lain atau isi manual.</small>`,
                confirmButtonColor: '#991B1B',
                confirmButtonText: 'Oke, Saya Isi Lagi',
                backdrop: 'rgba(69,10,10,0.35)'
            });
        }

        const inp = document.getElementById('nominalBayar');
        if (inp && hasil > 0) {
            inp.value = hasil.toLocaleString('id-ID');
        }
    }
    updateKembalian();
    updateBtnCetak();
    // Fokus ke input nominal biar user bisa edit kalo mau
    const inpNominal = document.getElementById('nominalBayar');
    if (inpNominal) setTimeout(() => inpNominal.blur(), 50);
}
function hitungTotal() {
    let total = 0;
    for (const k in keranjang) {
        total += keranjang[k].harga * keranjang[k].qty;
    }
    return total;
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// ============== STEP 1: BUKA MODAL KERANJANG KECIL ==============
function bukaModalKeranjang() {
    // LAZY INIT: Jikalau instance masih null (DOMContentLoaded kelewat / error), create baru di sini
    if (!window.bsModalKeranjangKecil) {
        try {
            const mk = document.getElementById('modalKeranjangKecil');
            if (mk) window.bsModalKeranjangKecil = new bootstrap.Modal(mk);
        } catch(e) { console.warn('Lazy init modal keranjang error:', e); }
    }
    if (!window.bsModalPembayaranBesar) {
        try {
            const mb = document.getElementById('modalPembayaranBesar');
            if (mb) window.bsModalPembayaranBesar = new bootstrap.Modal(mb);
        } catch(e) { console.warn('Lazy init modal bayar error:', e); }
    }

    renderModalKeranjangBody();
    const total = hitungTotal();
    const totalEl = document.getElementById('modalKeranjangTotal');
    const checkoutBtn = document.getElementById('modalKeranjangCheckoutBtn');
    if (totalEl) totalEl.textContent = formatRupiah(total);
    if (checkoutBtn) checkoutBtn.disabled = (Object.keys(keranjang).length === 0 || total <= 0);
    if (window.bsModalKeranjangKecil) window.bsModalKeranjangKecil.show();
}

function renderModalKeranjangBody() {
    const body = document.getElementById('modalKeranjangKecilBody');
    if (!body) return;
    const keys = Object.keys(keranjang);
    if (keys.length === 0) {
        body.innerHTML = `
        <div class="modal-keranjang-empty">
            <i class="bi bi-cart-x-fill"></i>
            <div class="fw-bold mb-1 small opacity-80">Keranjang Kosong</div>
            <div class="small opacity-70">Klik tombol [+] pada menu untuk menambahkan pesanan.</div>
        </div>`;
        return;
    }
    let html = '';
    for (const id of keys) {
        const k = keranjang[id];
        const subtotal = k.harga * k.qty;
        html += `
        <div class="modal-item-row">
            <div class="modal-item-info">
                <div class="modal-item-nama">${escapeHtml(k.nama)}</div>
                <div class="modal-item-harga">${formatRupiah(k.harga)} × ${k.qty} = ${formatRupiah(subtotal)}</div>
            </div>
            <div class="modal-item-qty-wrap">
                <button type="button" class="qty-mini-btn" onclick="event.stopPropagation(); produkMinus(${k.id}); renderModalKeranjangBody(); renderKeranjang(); const t=hitungTotal(); document.getElementById('modalKeranjangTotal').textContent=formatRupiah(t); document.getElementById('modalKeranjangCheckoutBtn').disabled=(Object.keys(keranjang).length===0||t<=0);" aria-label="Kurang">
                    <i class="bi bi-dash"></i>
                </button>
                <div class="qty-mini-num">${k.qty}</div>
                <button type="button" class="qty-mini-btn" onclick="event.stopPropagation(); produkPlus(${k.id}); renderModalKeranjangBody(); renderKeranjang(); const t=hitungTotal(); document.getElementById('modalKeranjangTotal').textContent=formatRupiah(t); document.getElementById('modalKeranjangCheckoutBtn').disabled=(Object.keys(keranjang).length===0||t<=0);" aria-label="Tambah">
                    <i class="bi bi-plus"></i>
                </button>
            </div>
        </div>`;
    }
    body.innerHTML = html;
}

// ============== STEP 2: CHECKOUT DARI MODAL KECIL → BUKA MODAL PEMBAYARAN BESAR ==============
function prosesCheckout() {
    const total = hitungTotal();
    if (total <= 0 || Object.keys(keranjang).length === 0) return;
    // Pastikan instance sudah ada sebelum hide/show
    if (!window.bsModalKeranjangKecil) {
        try {
            const mk = document.getElementById('modalKeranjangKecil');
            if (mk) window.bsModalKeranjangKecil = new bootstrap.Modal(mk);
        } catch(e) {}
    }
    if (!window.bsModalPembayaranBesar) {
        try {
            const mb = document.getElementById('modalPembayaranBesar');
            if (mb) window.bsModalPembayaranBesar = new bootstrap.Modal(mb);
        } catch(e) {}
    }
    // Tutup modal keranjang kecil dulu
    if (window.bsModalKeranjangKecil) window.bsModalKeranjangKecil.hide();
    // Update UI modal pembayaran
    updateModalPembayaranUI();
    // Buka modal pembayaran besar dengan delay smooth
    setTimeout(() => {
        if (window.bsModalPembayaranBesar) window.bsModalPembayaranBesar.show();
    }, 150);
}

function updateModalPembayaranUI() {
    const total = hitungTotal();
    // 1. Total tagihan besar
    const totalEl = document.getElementById('bayarTotalTagihan');
    if (totalEl) totalEl.textContent = formatRupiah(total);
    // 2. Default set payment CASH (panggil setPaymentModal trigger side effect)
    setPaymentModal(CURRENT_PAYMENT || 'CASH', true);
    // 3. Kosongkan input nominal / isi QRIS auto pas
    const inp = document.getElementById('nominalBayarModal');
    if (inp) {
        if (CURRENT_PAYMENT === 'QRIS') {
            inp.value = total > 0 ? total.toLocaleString('id-ID') : '';
        } else {
            inp.value = '';
        }
    }
    // 4. Kalkulasi kembalian & enable/disable proses
    updateKalkulasiPembayaranModal();
}

// ============== MODAL PEMBAYARAN: TOGGLE PAYMENT METHOD ==============
function setPaymentModal(tipe, skipResetInput) {
    CURRENT_PAYMENT = (tipe === 'QRIS') ? 'QRIS' : 'CASH';
    const btnCash = document.getElementById('payBtnCashModal');
    const btnQris = document.getElementById('payBtnQrisModal');
    const inp = document.getElementById('nominalBayarModal');
    const labelInput = document.querySelector('.input-bayar-label-modal');
    const inputGroup = document.querySelector('.input-group-bayar-modal');
    const btnsQuick = document.querySelectorAll('#quickNominalWrapModal .quick-nominal-btn');
    const total = hitungTotal();

    if (CURRENT_PAYMENT === 'QRIS') {
        if (btnCash) btnCash.classList.remove('pay-active');
        if (btnQris) btnQris.classList.add('pay-active');
        if (inp) {
            if (total > 0) inp.value = total.toLocaleString('id-ID');
            else inp.value = '';
            inp.disabled = true;
            inp.style.opacity = '0.55';
            inp.style.cursor = 'not-allowed';
        }
        if (labelInput) labelInput.style.opacity = '0.55';
        if (inputGroup) inputGroup.style.opacity = '0.85';
        // Disable semua quick nominal KECUALI "Uang Pas" (nominal 0)
        btnsQuick.forEach(b => {
            const onclick = b.getAttribute('onclick') || '';
            const isPas = onclick.includes('quickBayarModal(0');
            if (isPas) {
                b.disabled = false;
            } else {
                b.disabled = true;
                b.style.opacity = '0.4';
                b.style.cursor = 'not-allowed';
            }
        });
    } else {
        if (btnCash) btnCash.classList.add('pay-active');
        if (btnQris) btnQris.classList.remove('pay-active');
        if (inp) {
            inp.disabled = false;
            inp.style.opacity = '';
            inp.style.cursor = '';
            if (!skipResetInput) inp.value = '';
        }
        if (labelInput) labelInput.style.opacity = '';
        if (inputGroup) inputGroup.style.opacity = '';
        btnsQuick.forEach(b => {
            b.disabled = false;
            b.style.opacity = '';
            b.style.cursor = '';
        });
    }
    updateKalkulasiPembayaranModal();
}

// ============== MODAL PEMBAYARAN: QUICK NOMINAL BUTTONS ==============
function quickBayarModal(nominal, btnEl) {
    const total = hitungTotal();
    const inp = document.getElementById('nominalBayarModal');
    // Reset active style semua quick btn di modal
    document.querySelectorAll('#quickNominalWrapModal .quick-nominal-btn').forEach(b => {
        b.style.background = ''; b.style.color = ''; b.style.borderColor = ''; b.style.fontWeight = '';
    });

    if (!nominal || nominal <= 0) {
        // "Uang Pas" → auto set = total
        if (total > 0 && inp) inp.value = total.toLocaleString('id-ID');
        if (btnEl) { btnEl.style.background = 'rgba(251,191,36,0.18)'; btnEl.style.color = '#991B1B'; btnEl.style.fontWeight = '900'; }
    } else {
        const nominalBulat = Number(nominal) || 0;
        // ✅ JIKA NOMINAL < TOTAL: TETAP SET NOMINAL ASLI, MUNCULIN SWEETALERT ERROR!
        let hasil = nominalBulat;
        if (hasil < total && nominalBulat > 0 && total > 0) {
            const kurang = total - hasil;
            Swal.fire({
                icon: 'error',
                title: '⚠️ Uang Kurang!',
                html: `Total tagihan <b style="color:#991B1B;">${formatRupiah(total)}</b><br>Uang anda cuma <b style="color:#B91C1C;">${formatRupiah(hasil)}</b><br>Masih kurang <b style="color:#DC2626;">${formatRupiah(kurang)}</b><br><small style="color:#64748B;">Silahkan pilih nominal lain atau isi manual.</small>`,
                confirmButtonColor: '#991B1B',
                confirmButtonText: 'Oke, Saya Isi Lagi',
                backdrop: 'rgba(69,10,10,0.35)'
            });
        }
        if (inp && hasil > 0) inp.value = hasil.toLocaleString('id-ID');
        if (btnEl) { btnEl.style.background = '#991B1B'; btnEl.style.color = '#FFF8DC'; btnEl.style.borderColor = '#FBBF24'; btnEl.style.fontWeight = '900'; }
    }
    updateKalkulasiPembayaranModal();
    if (inp) setTimeout(() => inp.blur(), 50);
}

// ============== MODAL PEMBAYARAN: HITUNG KEMBALIAN & ENABLE/DISABLE PROSES ==============
function updateKalkulasiPembayaranModal() {
    const total = hitungTotal();
    const inp = document.getElementById('nominalBayarModal');
    const bayar = angkaOnly(inp ? inp.value : '');
    const cardKem = document.getElementById('kembalianCardModal');
    const nilaiKem = document.getElementById('kembalianNilaiModal');
    const btnProses = document.getElementById('prosesTransaksiBtn');
    let kembalian;

    if (CURRENT_PAYMENT === 'QRIS') {
        kembalian = 0;
        if (cardKem) cardKem.classList.remove('kurang');
        if (nilaiKem) nilaiKem.textContent = formatRupiah(kembalian);
        if (btnProses) btnProses.disabled = !(total > 0);
        return;
    }
    // CASH MODE
    kembalian = bayar - total;
    if (Object.keys(keranjang).length === 0 || bayar === 0) {
        if (cardKem) cardKem.classList.remove('kurang');
        if (nilaiKem) nilaiKem.textContent = formatRupiah(0);
        if (btnProses) btnProses.disabled = true;
        return;
    }
    if (kembalian < 0) {
        if (cardKem) cardKem.classList.add('kurang');
    } else {
        if (cardKem) cardKem.classList.remove('kurang');
    }
    if (nilaiKem) nilaiKem.textContent = formatRupiah(kembalian);
    if (btnProses) btnProses.disabled = !(total > 0 && bayar >= total);
}

// ============== STEP 3: PROSES TRANSAKSI + CETAK RESI ==============
function prosesTransaksiDanCetak() {
    const total = hitungTotal();
    if (total <= 0 || Object.keys(keranjang).length === 0) return;

    // ✅ SINKRONKAN ke ID DESKTOP ORIGINAL agar cetakResi() existing TANPA DIUBAH jalan normal
    const inpModal = document.getElementById('nominalBayarModal');
    const inpDesktop = document.getElementById('nominalBayar');
    if (inpDesktop && inpModal) inpDesktop.value = inpModal.value;
    // Pastikan juga payment toggle desktop ikut sinkron (agar hitung kembalian desktop setara)
    try { setPayment(CURRENT_PAYMENT); } catch(e){}

    // Hide modal pembayaran DULU biar window.print() yang muncul, bukan modal ketutup
    if (bsModalPembayaranBesar) bsModalPembayaranBesar.hide();

    // ✅ PANGGIL cetakResi() existing (dia urus SIMPAN DB + BUAT HTML RESI + window.print() otomatis)
    setTimeout(() => {
        cetakResi();
        // Kosongkan input modal setelah delay
        setTimeout(() => { if (inpModal) inpModal.value = ''; }, 1500);
    }, 200);
}

function tutupModalPembayaran() {
    if (bsModalPembayaranBesar) bsModalPembayaranBesar.hide();
}

// ============== CETAK KARTU UCAPAN TERIMA KASIH (THERMAL 58mm) ==============
function cetakKartuUcapan() {
    const elResiTransaksi = document.getElementById('receiptPrint');
    const elKartu = document.getElementById('receiptKartuUcapan');
    if (!elKartu) return;

    // Pastikan receipt resi transaksi disembunyikan (tidak ikut print kartu)
    if (elResiTransaksi) elResiTransaksi.style.setProperty('display', 'none', 'important');
    elKartu.style.setProperty('display', 'block', 'important');

    // Tutup semua modal biar backdrop tidak ngambek
    try { if (bsModalPembayaranBesar) bsModalPembayaranBesar.hide(); } catch(e){}
    try { if (bsModalKeranjangKecil) bsModalKeranjangKecil.hide(); } catch(e){}

    // Scroll ke atas biar print posisi start atas
    try { window.scrollTo(0, 0); } catch(e){}

    setTimeout(function() {
        window.focus();
        try { window.print(); } catch(e){}

        // Setelah print (atau cancel dialog print): reset BALIK display seperti semula (hapus inline style, biar default class .receipt display:none)
        setTimeout(function() {
            try {
                if (elResiTransaksi) elResiTransaksi.style.removeProperty('display');
            } catch(e){ try { elResiTransaksi.removeAttribute('style'); } catch(e2){} }
            try {
                elKartu.style.removeProperty('display');
            } catch(e){ try { elKartu.removeAttribute('style'); } catch(e2){} }
        }, 800);
    }, 220);
}

// ============== CETAK RESI ==============
function padZero(num, len) {
    num = String(num);
    while (num.length < len) num = '0' + num;
    return num;
}
function tanggalStruk(d) {
    const tgl = padZero(d.getDate(), 2) + '-' + padZero(d.getMonth() + 1, 2) + '-' + d.getFullYear();
    const jam = padZero(d.getHours(), 2) + ':' + padZero(d.getMinutes(), 2) + ':' + padZero(d.getSeconds(), 2);
    return tgl + ' ' + jam;
}
function noStruk(d) {
    return 'KSR' + d.getFullYear() + padZero(d.getMonth() + 1, 2) + padZero(d.getDate(), 2)
        + padZero(d.getHours(), 2) + padZero(d.getMinutes(), 2) + padZero(d.getSeconds(), 2);
}
function cetakResi() {
    // Pastikan receipt kartu ucapan disembunyikan dulu (tidak double print bareng resi transaksi)
    const elKartuPre = document.getElementById('receiptKartuUcapan');
    if (elKartuPre) elKartuPre.style.setProperty('display', 'none', 'important');
    const elResiPre = document.getElementById('receiptPrint');
    if (elResiPre) elResiPre.style.removeProperty('display');

    const total = hitungTotal();
    if (total <= 0) { Swal.fire({ icon:'error', title:'Keranjang Kosong', text:'Tambahkan minimal 1 item', confirmButtonColor:'#991B1B' }); return; }
    const bayar = (CURRENT_PAYMENT === 'QRIS') ? total : angkaOnly(inputBayar.value);
    if (CURRENT_PAYMENT === 'CASH' && bayar < total) {
        Swal.fire({ icon:'error', title:'Nominal Kurang!', html:'Total: <b>' + formatRupiah(total) + '</b><br>Bayar: <b>' + formatRupiah(bayar) + '</b><br>Kurang: <b class="text-danger">' + formatRupiah(total - bayar) + '</b>', confirmButtonColor:'#991B1B' });
        return;
    }
    const kembalian = (CURRENT_PAYMENT === 'QRIS') ? 0 : (bayar - total);

    const now = new Date();
    const noStr = noStruk(now);
    const tglStr = tanggalStruk(now);
    const paymentMethod = CURRENT_PAYMENT; // 'CASH' atau 'QRIS'

    // ===== BUILD PAYLOAD & SIMPAN TRANSAKSI KE DB SEBELUM PRINT =====
    const itemsArr = [];
    for (const k in keranjang) {
        const it = keranjang[k];
        itemsArr.push({
            nama_produk: it.nama,
            harga: it.harga,
            qty: it.qty,
            subtotal: it.harga * it.qty
        });
    }
    const payload = {
        no_struk: noStr,
        tgl: now.toISOString(),
        payment: paymentMethod,
        total: total,
        bayar: bayar,
        kembalian: kembalian,
        items: itemsArr
    };

    // ===== BUAT HTML STRUK DULU (sebelum async) biar DOM siap =====
    let itemsHtml = '';
    for (const k in keranjang) {
        const it = keranjang[k];
        const sub = it.harga * it.qty;
        let nama = it.nama;
        if (nama.length > 28) nama = nama.substring(0, 25) + '...';
        itemsHtml += `
            <div class="receipt-item">
                <div class="receipt-item-top">
                    <div class="receipt-item-nama">
                        ${escapeHtml(nama)}
                        <div class="receipt-item-harga-satuan">${formatRupiah(it.harga)} x ${it.qty}</div>
                    </div>
                    <div class="receipt-item-kanan receipt-subtotal-item">${formatRupiah(sub).replace('Rp ','Rp')}</div>
                </div>
            </div>`;
    }

    // Metode bayar (standard struk: dashed atas bawah TENGAH, TANPA IKON & TANPA BADGE WARNA)
    let badgeMetode = '';
    if (paymentMethod === 'QRIS') {
        badgeMetode = `<div class="receipt-pay-method">PEMBAYARAN: CASH (TUNAI)</div>`.replace('CASH (TUNAI)', 'QRIS (NON TUNAI)');
    } else {
        badgeMetode = `<div class="receipt-pay-method">PEMBAYARAN: CASH (TUNAI)</div>`;
    }

    // Ringkasan TOTAL / BAYAR / KEMBALIAN (RAPIH SEPERTI MINIMARKET - rata kanan nominal sejajar)
    let bayarKembalianHtml = '';
    if (paymentMethod === 'CASH') {
        bayarKembalianHtml = `
        <div class="receipt-total-row row-total">
            <span class="label">TOTAL</span>
            <span class="value">${formatRupiah(total).replace('Rp ','Rp')}</span>
        </div>
        <div class="receipt-total-row row-bayar">
            <span class="label">BAYAR</span>
            <span class="value">${formatRupiah(bayar).replace('Rp ','Rp')}</span>
        </div>
        <div class="receipt-total-row row-kembalian">
            <span class="label">KEMBALIAN</span>
            <span class="value">${formatRupiah(kembalian).replace('Rp ','Rp')}</span>
        </div>`;
    } else {
        // QRIS: TOTAL + badge SUDAH LUNAS (teks sederhana TANPA BORDER WARNA)
        bayarKembalianHtml = `
        <div class="receipt-total-row row-total">
            <span class="label">TOTAL</span>
            <span class="value">${formatRupiah(total).replace('Rp ','Rp')}</span>
        </div>
        <div class="receipt-lunas">SUDAH LUNAS - QRIS NON TUNAI</div>`;
    }

    // ===== STRUK UTAMA (CLEEN MINIMALIS - TANPA NO. STRUK, TANPA BORDER KOTAK BESAR) =====
    const struk = `
    <div class="receipt-card">
        <div class="receipt-title">${escapeHtml(TOKO_NAMA_JS)}</div>
        <div class="receipt-sub-nama">Minuman &amp; Cemilan Kekinian</div>
        <div class="receipt-alamat-wa">
            ${escapeHtml(TOKO_ALAMAT_SINGKAT_JS).replace(', Sleman, DIY 55281', '<br>Sleman DIY 55281')}<br>
            WhatsApp: ${escapeHtml(TOKO_WA_JS)}
        </div>

        <div class="receipt-divider"></div>

        <div class="receipt-meta-wrapper">
            <div class="receipt-meta-row">
                <div class="receipt-meta-label">TANGGAL :</div>
                <div class="receipt-meta-value">${tglStr}</div>
            </div>
        </div>

        ${badgeMetode}

        <div class="receipt-section-label">PESANAN</div>
        <div class="receipt-divider"></div>
        <div style="display:flex; justify-content:space-between; align-items:center; padding-bottom:3px; margin-bottom:2px; gap:6px;">
            <span style="font-weight:800; font-size:9.5px; color:#374151; letter-spacing:0.1px;">Nama produk</span>
            <span style="font-weight:800; font-size:9.5px; color:#374151; letter-spacing:0.1px; text-align:right; white-space:nowrap;">Harga</span>
        </div>
        ${itemsHtml}

        <div class="receipt-divider"></div>
        ${bayarKembalianHtml}

        <div class="receipt-divider"></div>

        <div class="receipt-footer-thank">Terima Kasih Atas Kunjungannya</div>
     
        <div class="receipt-footer-bless">Semoga Berkah Selalu</div>
    </div>`;

    const printDiv = document.getElementById('receiptPrint');
    printDiv.innerHTML = struk;

    // ===== JALANKAN SIMPAN TRANSAKSI (ASYNC) lalu PRINT =====
    (async function() {
        try {
            const resp = await fetch('?aksi=simpan_transaksi', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const text = await resp.text();
            let jsonOk = false;
            try {
                const j = JSON.parse(text);
                jsonOk = (j && (j.ok === true || j.status === 'ok')); // terima key ok atau status
            } catch(e) { jsonOk = resp.ok; }
            if (!jsonOk) {
                console.warn('[Simpan Transaksi] Gagal / invalid response:', text);
            }
        } catch(err) {
            console.warn('[Simpan Transaksi] Network error (tetap lanjut print):', err);
        }

        // Delay sedikit biar DOM di-render dulu sebelum print
        setTimeout(function() {
            window.print();
            // Setelah print (atau cancel): reset BALIK style display kedua receipt biar tidak bentrok cetak berikutnya
            setTimeout(function() {
                try {
                    const elKartuAfter = document.getElementById('receiptKartuUcapan');
                    if (elKartuAfter) elKartuAfter.style.removeProperty('display');
                } catch(e){}
                try {
                    const elResiAfter = document.getElementById('receiptPrint');
                    if (elResiAfter) elResiAfter.style.removeProperty('display');
                } catch(e){}
            }, 700);
            // Setelah reset → tampilkan alert berhasil
            setTimeout(function() {
                Swal.fire({
                    icon: 'success',
                    title: (paymentMethod === 'QRIS' ? 'Pembayaran QRIS Berhasil!' : 'Resi Berhasil Dicetak!'),
                    html: 'No. Struk: <b>' + noStr + '</b><br>Metode: <b>' + (paymentMethod === 'QRIS' ? 'QRIS (Non Tunai)' : 'Cash (Tunai)') + '</b><br>Total Pesanan: <b>' + formatRupiah(total) + '</b>',
                    showCancelButton: true,
                    confirmButtonText: 'Transaksi Baru (Reset)',
                    cancelButtonText: 'Tetap di Keranjang',
                    confirmButtonColor: '#991B1B',
                    cancelButtonColor: '#64748B',
                    reverseButtons: true,
                    customClass: { popup: 'swal2-rounded' }
                }).then(res => {
                    if (res.isConfirmed) {
                        // Reset
                        const ids = Object.keys(keranjang);
                        for (const id of ids) { delete keranjang[id]; updateQtyDisplay(Number(id)); }
                        renderKeranjang();
                        // Jika QRIS mode, balik ke default CASH biar transaksi baru bersih
                        setPayment('CASH');
                        const inp = document.getElementById('nominalBayar');
                        if (inp) inp.value = '';
                        updateKembalian();
                        window.scrollTo({ top:0, behavior:'smooth' });
                    }
                });
            }, 600);
        }, 250);
    })();
}

// ============== INISIALISASI ==============
function applyToggleIconState() {
    const icon = document.querySelector('#keranjangToggleIcon i');
    const isMobile = window.matchMedia && window.matchMedia('(max-width: 991.98px)').matches;
    if (!icon) return;
    if (!isMobile) {
        // Pastikan body & footer terbuka & icon biarkan kebawah
        const body = document.getElementById('keranjangBody');
        const footer = document.getElementById('keranjangFooter');
        if (body) body.classList.add('show');
        if (footer) footer.classList.add('show');
        icon.classList.remove('bi-chevron-up'); icon.classList.add('bi-chevron-down');
        return;
    }
    // Mobile: sesuaikan dengan state
    if (keranjangTerbuka) {
        icon.classList.remove('bi-chevron-up'); icon.classList.add('bi-chevron-down');
    } else {
        icon.classList.remove('bi-chevron-down'); icon.classList.add('bi-chevron-up');
    }
}
document.addEventListener('DOMContentLoaded', function() {
    // Klik produk card (selain qty button) -> langsung +1
    document.querySelectorAll('.produk-card').forEach(function(card) {
        card.addEventListener('click', function() {
            const id = Number(card.getAttribute('data-id'));
            if (!isNaN(id) && id > 0) produkPlus(id);
            // Di mobile, auto-scroll ke bawah kalo user mau lihat total / keranjang
            const isMobile = window.matchMedia && window.matchMedia('(max-width: 991.98px)').matches;
            const totalItem = Object.keys(keranjang).reduce((a, k) => a + keranjang[k].qty, 0);
            if (isMobile && totalItem === 1) {
                const produkGrid = document.getElementById('produkGrid');
                if (produkGrid) produkGrid.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    });

    // Prevent double-click zoom / double-tap pada touch device
    let lastTouch = 0;
    document.addEventListener('touchend', function(e) {
        const now = Date.now();
        if (now - lastTouch <= 300) { e.preventDefault(); }
        lastTouch = now;
    }, { passive: false });

    // Initial state toggle
    applyToggleIconState();
    if (window.addEventListener) {
        window.addEventListener('resize', applyToggleIconState);
    }
    renderKeranjang();
});

// ============== MODAL KERANJANG HP (PINDAHKAN DOM) ==============
let _modalKeranjangInstance = null;
let _tempatAsliKeranjangCard = null;

function bukaModalKeranjang() {
    // LAZY INIT: Jikalau instance masih null (DOMContentLoaded kelewat / error), create baru di sini
    if (!window.bsModalKeranjangKecil) {
        try {
            const mk = document.getElementById('modalKeranjangKecil');
            if (mk) window.bsModalKeranjangKecil = new bootstrap.Modal(mk);
        } catch(e) { console.warn('Lazy init modal keranjang error (dup):', e); }
    }
    if (!window.bsModalPembayaranBesar) {
        try {
            const mb = document.getElementById('modalPembayaranBesar');
            if (mb) window.bsModalPembayaranBesar = new bootstrap.Modal(mb);
        } catch(e) { console.warn('Lazy init modal bayar error (dup):', e); }
    }

    renderModalKeranjangBody();
    const total = hitungTotal();
    const totalEl = document.getElementById('modalKeranjangTotal');
    const checkoutBtn = document.getElementById('modalKeranjangCheckoutBtn');
    if (totalEl) totalEl.textContent = formatRupiah(total);
    if (checkoutBtn) checkoutBtn.disabled = (Object.keys(keranjang).length === 0 || total <= 0);
    if (window.bsModalKeranjangKecil) window.bsModalKeranjangKecil.show();
}

document.addEventListener('DOMContentLoaded', function() {
    const modalEl = document.getElementById('modalKeranjang');
    const cardAsli = document.querySelector('.order-keranjang .keranjang-card');
    if (!modalEl || !cardAsli) return;
    _tempatAsliKeranjangCard = cardAsli.parentNode;

    // Saat modal SHOW: pindahkan keranjang-card ke modal body
    modalEl.addEventListener('show.bs.modal', function() {
        const bodyModal = document.getElementById('modalKeranjangBody');
        if (bodyModal && cardAsli) {
            bodyModal.appendChild(cardAsli);
            // Di modal, pastikan header+body+footer TERBUKA SEMUA (ga usah collapse biar user ga ribet toggle)
            const bodyK = document.getElementById('keranjangBody');
            const footerK = document.getElementById('keranjangFooter');
            if (bodyK) bodyK.classList.add('show');
            if (footerK) footerK.classList.add('show');
            const icon = document.querySelector('#keranjangToggleIcon i');
            if (icon) { icon.classList.remove('bi-chevron-up'); icon.classList.add('bi-chevron-down'); }
            keranjangTerbuka = true;
        }
    });

    // Saat modal HIDDEN: pindahkan kembali ke tempat asli / simpan (di HP gpp di tmp juga, tapi biar rapih balik)
    modalEl.addEventListener('hidden.bs.modal', function() {
        if (_tempatAsliKeranjangCard && cardAsli.parentNode !== _tempatAsliKeranjangCard) {
            _tempatAsliKeranjangCard.appendChild(cardAsli);
        }
    });
});
</script>
</body>
</html>

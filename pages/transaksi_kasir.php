<?php
// ===== AGGRESSIVE ANTI-CACHE HEADERS (sebelum apapun) =====
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, post-check=0, pre-check=0');
    header('Pragma: no-cache');
    header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
    header('ETag: "' . md5(uniqid(mt_rand(), true)) . '"');
}

require_once __DIR__ . '/../includes/header.php';

define('TOKO_NAMA', 'Es Teller & Dawet Baraya');
define('TOKO_ALAMAT_SINGKAT', 'Jl. Kakatua No.103, Condongcatur, Sleman, DIY 55281');
define('TOKO_WA', '+62 831-8930-2691');

$GLOBALS['alert_script'] = '';

function transKasirAddColIfMissing($pdo, $tabel, $field, $def) {
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$tabel}` LIKE '{$field}'");
        if ($stmt->rowCount() === 0) {
            $pdo->exec("ALTER TABLE `{$tabel}` ADD COLUMN `{$field}` {$def}");
        }
    } catch (PDOException $e) { /* ignore duplicate */ }
}

function transKasirRepairTable($pdo) {
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'kasir_transaksi'");
        $tabelAda = $stmt->rowCount() > 0;
        if (!$tabelAda) {
            try {
                $pdo->exec("
                    CREATE TABLE kasir_transaksi (
                        id INT PRIMARY KEY AUTO_INCREMENT,
                        no_struk VARCHAR(50) NOT NULL UNIQUE,
                        tgl DATETIME NOT NULL,
                        payment ENUM('CASH','QRIS') NOT NULL DEFAULT 'CASH',
                        total INT NOT NULL DEFAULT 0,
                        bayar INT NOT NULL DEFAULT 0,
                        kembalian INT NOT NULL DEFAULT 0,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                ");
            } catch (PDOException $e) { /* ignore */ }
        } else {
            transKasirAddColIfMissing($pdo, 'kasir_transaksi', 'id', 'INT PRIMARY KEY AUTO_INCREMENT');
            transKasirAddColIfMissing($pdo, 'kasir_transaksi', 'no_struk', "VARCHAR(50) NOT NULL DEFAULT ''");
            transKasirAddColIfMissing($pdo, 'kasir_transaksi', 'tgl', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');
            transKasirAddColIfMissing($pdo, 'kasir_transaksi', 'payment', "ENUM('CASH','QRIS') NOT NULL DEFAULT 'CASH'");
            transKasirAddColIfMissing($pdo, 'kasir_transaksi', 'total', 'INT NOT NULL DEFAULT 0');
            transKasirAddColIfMissing($pdo, 'kasir_transaksi', 'bayar', 'INT NOT NULL DEFAULT 0');
            transKasirAddColIfMissing($pdo, 'kasir_transaksi', 'kembalian', 'INT NOT NULL DEFAULT 0');
            transKasirAddColIfMissing($pdo, 'kasir_transaksi', 'created_at', 'DATETIME DEFAULT CURRENT_TIMESTAMP');
            try { $pdo->exec("ALTER TABLE kasir_transaksi ADD UNIQUE KEY idx_no_struk (no_struk)"); } catch (PDOException $e) {}
        }

        $stmt2 = $pdo->query("SHOW TABLES LIKE 'kasir_transaksi_item'");
        $tabel2Ada = $stmt2->rowCount() > 0;
        if (!$tabel2Ada) {
            try {
                $pdo->exec("
                    CREATE TABLE kasir_transaksi_item (
                        id INT PRIMARY KEY AUTO_INCREMENT,
                        id_transaksi INT NOT NULL,
                        nama_produk VARCHAR(255) NOT NULL,
                        harga INT NOT NULL DEFAULT 0,
                        qty INT NOT NULL DEFAULT 0,
                        subtotal INT NOT NULL DEFAULT 0,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        KEY idx_id_transaksi (id_transaksi)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                ");
            } catch (PDOException $e) { /* ignore */ }
        } else {
            transKasirAddColIfMissing($pdo, 'kasir_transaksi_item', 'id', 'INT PRIMARY KEY AUTO_INCREMENT');
            transKasirAddColIfMissing($pdo, 'kasir_transaksi_item', 'id_transaksi', 'INT NOT NULL DEFAULT 0');
            transKasirAddColIfMissing($pdo, 'kasir_transaksi_item', 'nama_produk', "VARCHAR(255) NOT NULL DEFAULT ''");
            transKasirAddColIfMissing($pdo, 'kasir_transaksi_item', 'harga', 'INT NOT NULL DEFAULT 0');
            transKasirAddColIfMissing($pdo, 'kasir_transaksi_item', 'qty', 'INT NOT NULL DEFAULT 0');
            transKasirAddColIfMissing($pdo, 'kasir_transaksi_item', 'subtotal', 'INT NOT NULL DEFAULT 0');
            transKasirAddColIfMissing($pdo, 'kasir_transaksi_item', 'created_at', 'DATETIME DEFAULT CURRENT_TIMESTAMP');
            try { $pdo->exec("ALTER TABLE kasir_transaksi_item ADD KEY idx_id_transaksi (id_transaksi)"); } catch (PDOException $e) {}
        }

        // ===== REPAIR TABEL kasir_produk TERMASUK KOLOM kategori =====
        try {
            $stmtP = $pdo->query("SHOW TABLES LIKE 'kasir_produk'");
            $produkAda = $stmtP->rowCount() > 0;
            if (!$produkAda) {
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
                } catch (PDOException $e) { /* ignore */ }
            } else {
                $butuhKolom = [
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
                    foreach ($butuhKolom as $nf => $ndef) {
                        if (!isset($kolomAda[$nf])) {
                            try { $pdo->exec("ALTER TABLE kasir_produk ADD COLUMN {$nf} {$ndef}"); } catch (PDOException $e) {}
                        }
                    }
                } catch (PDOException $e) { /* ignore */ }
            }
        } catch (PDOException $e) { /* ignore */ }
    } catch (PDOException $e) { /* ignore */ }
}
transKasirRepairTable($pdo);

// ===== HELPER FUNGSI UNTUK CETAK =====
function formatRp($num) {
    $num = (int)$num;
    return 'Rp ' . number_format($num, 0, ',', '.');
}
function padZeroN($num, $len) {
    $num = (string)$num;
    while (strlen($num) < $len) $num = '0' . $num;
    return $num;
}
function tanggalStrukIndo($d) {
    if (is_string($d)) $d = strtotime($d);
    return date('d-m-Y H:i:s', $d);
}

// ===== HANDLER: CETAK RESI THERMAL 58mm KERTAS KECIL (dari DB) - WARNA MAROON EMAS PREMIUM =====
$aksiGet = $_GET['aksi'] ?? '';
$idGet = (int)($_GET['id'] ?? 0);

if ($aksiGet === 'cetak_resi' && $idGet > 0) {
    $stmt = $pdo->prepare("SELECT * FROM kasir_transaksi WHERE id = ? LIMIT 1");
    $stmt->execute([$idGet]);
    $tr = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$tr) { echo '<h3 style="text-align:center;color:#991B1B;font-family:sans-serif;">Transaksi tidak ditemukan (ID ' . $idGet . ')</h3>'; exit; }
    $stmtItem = $pdo->prepare("SELECT * FROM kasir_transaksi_item WHERE id_transaksi = ? ORDER BY id ASC");
    $stmtItem->execute([$idGet]);
    $items = $stmtItem->fetchAll(PDO::FETCH_ASSOC);
    $paymentMethod = $tr['payment'] ?? 'CASH';
    $total = (int)$tr['total'];
    $bayar = (int)$tr['bayar'];
    $kembalian = (int)$tr['kembalian'];
    $tglStr = tanggalStrukIndo($tr['tgl']);
    $noStr = htmlspecialchars($tr['no_struk']);

    $itemsHtml = '';
    foreach ($items as $it) {
        $nama = $it['nama_produk'];
        if (strlen($nama) > 26) $nama = substr($nama, 0, 23) . '...';
        $hargaSat = (int)$it['harga'];
        $qty = (int)$it['qty'];
        $sub = (int)$it['subtotal'];
        $itemsHtml .= '
        <div class="receipt-item">
            <div class="receipt-item-top">
                <div class="receipt-item-nama">
                    ' . htmlspecialchars($nama) . '
                    <div class="receipt-item-harga-satuan">' . str_replace('Rp ','Rp ', formatRp($hargaSat)) . ' x ' . $qty . '</div>
                </div>
                <div class="receipt-item-kanan receipt-subtotal-item">' . str_replace('Rp ','Rp', formatRp($sub)) . '</div>
            </div>
        </div>';
    }

    $badgeMetode = '';
    if ($paymentMethod === 'QRIS') {
        $badgeMetode = '<div class="receipt-pay-method">PEMBAYARAN: QRIS (NON TUNAI)</div>';
    } else {
        $badgeMetode = '<div class="receipt-pay-method">PEMBAYARAN: CASH (TUNAI)</div>';
    }

    $bayarKembalianHtml = '';
    if ($paymentMethod === 'CASH') {
        $bayarKembalianHtml = '
        <div class="receipt-total-row row-total">
            <span class="label">TOTAL</span>
            <span class="value">' . str_replace('Rp ','Rp', formatRp($total)) . '</span>
        </div>
        <div class="receipt-total-row row-bayar">
            <span class="label">BAYAR</span>
            <span class="value">' . str_replace('Rp ','Rp', formatRp($bayar)) . '</span>
        </div>
        <div class="receipt-total-row row-kembalian">
            <span class="label">KEMBALIAN</span>
            <span class="value">' . str_replace('Rp ','Rp', formatRp($kembalian)) . '</span>
        </div>';
    } else {
        $bayarKembalianHtml = '
        <div class="receipt-total-row row-total">
            <span class="label">TOTAL</span>
            <span class="value">' . str_replace('Rp ','Rp', formatRp($total)) . '</span>
        </div>
        <div class="receipt-lunas">SUDAH LUNAS - QRIS NON TUNAI</div>';
    }

    $alamatDuaBaris = str_replace(', Sleman, DIY 55281', '<br>Sleman DIY 55281', htmlspecialchars(TOKO_ALAMAT_SINGKAT));

    $htmlPrint = '<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Cetak Resi ' . $noStr . '</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        * { box-sizing: border-box; }
        body { margin:0; padding:0; background: #fff; -webkit-print-color-adjust: exact; print-color-adjust: exact; font-family: \'Courier New\', Courier, monospace; font-size:11.5px; line-height:1.55; }
        .receipt { width:100%; max-width:58mm; min-width:58mm; margin:0 auto; padding:5mm 4mm 5mm 4mm; color:#000000; font-family: \'Courier New\', Courier, monospace; font-size: 11.5px; line-height:1.55; background: #FFFFFF; max-height:none; min-height:auto; box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .receipt * { font-family: \'Courier New\', Courier, monospace !important; letter-spacing: 0 !important; line-height: 1.55 !important; }
        .receipt-card { border: none; border-radius: 0; padding: 0; margin: 0; background: #FFFFFF; width: 100%; box-sizing: border-box; box-shadow: none; }
        .receipt-center { text-align:center; }
        .receipt-divider { border: none; border-top: 1px dashed #374151; margin: 9px 0; height: 0; clear: both; }
        .receipt-divider-solid { border: none; border-top: 1px solid #111827; margin: 8px 0; height: 0; clear: both; }
        .receipt-divider-dashed { border: none; border-top: 1px dashed #374151; margin: 9px 0; height: 0; clear: both; }
        .receipt-title { font-weight: 900; font-size: 15px; text-align: center; color: #000000; line-height: 1.15; letter-spacing: 0.3px; margin: 0 0 2px 0; }
        .receipt-sub-nama { text-align: center; font-size: 10.5px; color: #374151; font-weight: 700; margin-top: 4px; line-height: 1.2; }
        .receipt-alamat-wa { text-align: center; font-size: 10px; color: #374151; margin-top: 7px; line-height: 1.45; font-weight: 600; }
        .receipt-meta-wrapper { margin-top: 9px; margin-bottom: 2px; display:block; }
        .receipt-meta-row { display: flex; justify-content: flex-start; align-items: flex-start; gap: 5px; padding: 1.5px 0; }
        .receipt-meta-label { font-weight: 900; font-size: 10px; color: #000000; flex: 0 0 auto; white-space: nowrap; }
        .receipt-meta-value { font-size: 10px; color: #000000; font-weight: 700; flex: 1; min-width: 0; word-break: break-all; }
        .receipt-pay-method { text-align: center; padding: 7px 0; border-top: 1px dashed #374151; border-bottom: 1px dashed #374151; font-weight: 900; font-size: 11px; color: #000000; margin: 9px 0 10px 0; letter-spacing: 0.35px; }
        .receipt-lunas { text-align: center; margin-top: 8px; padding: 5px 0; font-weight: 900; font-size: 10.5px; color: #000000; letter-spacing: 0.25px; }
        .receipt-section-label { font-weight: 900; font-size: 11px; color: #000000; margin-bottom: 6px; letter-spacing: 0.4px; padding-top: 2px; }
        .receipt-item { padding: 5px 0 7px 0; border-bottom: 1px dashed #D1D5DB; margin-bottom: 3px; }
        .receipt-item:last-child { border-bottom: none; padding-bottom: 0; margin-bottom: 0; }
        .receipt-item-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 6px; padding-bottom: 1px; }
        .receipt-item-nama { flex: 1; min-width: 0; font-size: 11.5px; font-weight: 800; color: #000000; line-height: 1.3; }
        .receipt-item-harga-satuan, .receipt-item-harga { font-size: 10px; color: #4B5563; margin-top: 4px; font-weight: 600; line-height: 1.2; padding-left: 1px; }
        .receipt-item-kanan { flex-shrink: 0; text-align: right; min-width: 0; padding-top: 1px; }
        .receipt-subtotal-item { font-weight: 900; color: #000000; font-size: 11.5px; white-space: nowrap; }
        .receipt-total-row { display: flex; justify-content: space-between; align-items: baseline; padding: 3px 0; gap: 10px; }
        .receipt-total-row .label { font-weight: 900; color: #000000; font-size: 11px; letter-spacing: 0.3px; flex: 0 0 auto; }
        .receipt-total-row .value { font-weight: 900; color: #000000; font-size: 11px; text-align: right; flex: 1; min-width: 0; white-space: nowrap; }
        .receipt-total-row.row-total { margin-top: 5px; padding-top: 7px; border-top: 1.5px solid #111827; margin-bottom:1px; }
        .receipt-total-row.row-total .label, .receipt-total-row.row-total .value { font-size: 13px; font-weight: 900; }
        .receipt-total-row.row-bayar { padding-top:5px; }
        .receipt-total-row.row-bayar .label, .receipt-total-row.row-bayar .value { font-size: 11.5px; }
        .receipt-total-row.row-kembalian { padding: 5px 0 2px 0; }
        .receipt-total-row.row-kembalian .label, .receipt-total-row.row-kembalian .value { font-size: 12.5px; }
        .receipt-footer-thank { text-align: center; margin-top: 10px; line-height: 1.4; font-size: 10.5px; color: #000000; font-weight: 800; }
        .receipt-footer-bless { text-align:center; font-size:9.5px; color:#000000; font-weight:700; margin-top:4px; line-height:1.35; }
        .receipt-footer-hashtag { text-align: center; font-weight: 700; font-size: 9.5px; color: #374151; margin-top: 6px; line-height: 1.35; letter-spacing: 0.15px; }
        @page { size: 58mm auto; margin: 0mm; }
        @media print {
            html, body { width:100% !important; max-width:58mm !important; min-width:58mm !important; margin:0 !important; padding:0 !important; background:#FFFFFF !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; overflow:visible !important; font-size:11.5px !important; line-height:1.55 !important; }
            body > *:not(.receipt):not(.receipt *) { display:none !important; }
            .receipt { display:block !important; visibility:visible !important; width:100% !important; max-width:58mm !important; min-width:58mm !important; position:absolute; left:0; top:0; margin:0 !important; padding:5mm 4mm 5mm 4mm !important; page-break-inside:auto !important; page-break-after:avoid !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; box-sizing: border-box !important; background:#FFFFFF !important; font-size:11.5px !important; line-height:1.55 !important; }
            .receipt * { line-height:1.55 !important; }
            .receipt-divider { margin:9px 0 !important; }
            .receipt-divider-solid, .receipt-divider-dashed { margin:8px 0 !important; }
            .receipt-title { font-size:15px !important; line-height:1.15 !important; }
            .receipt-sub-nama { font-size:10.5px !important; margin-top:4px !important; }
            .receipt-alamat-wa { font-size:10px !important; margin-top:7px !important; line-height:1.45 !important; }
            .receipt-meta-wrapper { margin-top:9px !important; margin-bottom:2px !important; }
            .receipt-meta-row { padding:1.5px 0 !important; }
            .receipt-meta-label, .receipt-meta-value { font-size:10px !important; }
            .receipt-pay-method { padding:7px 0 !important; font-size:11px !important; margin:9px 0 10px 0 !important; }
            .receipt-lunas { margin-top:8px !important; font-size:10.5px !important; padding:5px 0 !important; }
            .receipt-item { padding:5px 0 7px 0 !important; margin-bottom:3px !important; }
            .receipt-item-nama { font-size:11.5px !important; line-height:1.3 !important; }
            .receipt-item-harga-satuan, .receipt-item-harga { font-size:10px !important; margin-top:4px !important; line-height:1.2 !important; }
            .receipt-subtotal-item { font-size:11.5px !important; }
            .receipt-total-row { padding:3px 0 !important; }
            .receipt-total-row .label, .receipt-total-row .value { font-size:11px !important; }
            .receipt-total-row.row-total { margin-top:5px !important; padding-top:7px !important; border-top:1.5px solid #111827 !important; }
            .receipt-total-row.row-total .label, .receipt-total-row.row-total .value { font-size:13px !important; }
            .receipt-total-row.row-bayar { padding-top:5px !important; }
            .receipt-total-row.row-bayar .label, .receipt-total-row.row-bayar .value { font-size:11.5px !important; }
            .receipt-total-row.row-kembalian { padding:5px 0 2px 0 !important; }
            .receipt-total-row.row-kembalian .label, .receipt-total-row.row-kembalian .value { font-size:12.5px !important; }
            .receipt-footer-thank { margin-top:10px !important; font-size:10.5px !important; line-height:1.4 !important; }
            .receipt-footer-hashtag { font-size:9.5px !important; margin-top:6px !important; line-height:1.35 !important; }
        }
    </style></head><body>
    <div class="receipt"><div class="receipt-card">
        <div class="receipt-center">
            <div class="receipt-title">' . htmlspecialchars(TOKO_NAMA) . '</div>
            <div class="receipt-sub-nama">Minuman &amp; Cemilan Kekinian</div>
            <div class="receipt-alamat-wa">' . $alamatDuaBaris . '<br>WhatsApp: ' . htmlspecialchars(TOKO_WA) . '</div>
        </div>
        <div class="receipt-divider-solid"></div>
        <div class="receipt-meta-wrapper">
            <div class="receipt-meta-row">
                <div class="receipt-meta-label">TANGGAL :</div>
                <div class="receipt-meta-value">' . $tglStr . '</div>
            </div>
            <div class="receipt-meta-row">
                <div class="receipt-meta-label">NO. STRUK :</div>
                <div class="receipt-meta-value">' . $noStr . '</div>
            </div>
        </div>
        ' . $badgeMetode . '
        <div style="margin-top:6px;">' . $itemsHtml . '</div>
        <div class="receipt-divider-dashed"></div>
        ' . $bayarKembalianHtml . '
        <div class="receipt-divider-solid"></div>
        <div class="receipt-footer-thank">Terima Kasih Atas Kunjungannya<br>Semoga Berkah Selalu</div>
    </div></div>
    <script>window.onload = function() { setTimeout(function() { window.focus(); window.print(); setTimeout(function() { if (window.history.length > 1) window.history.back(); }, 450); }, 220); }<' . '/script>
    </body></html>';
    echo $htmlPrint;
    exit;
}

// ===== HANDLER: CETAK NOTA A4 (1 transaksi) BISA SAVE AS PDF =====
if ($aksiGet === 'cetak_a4' && $idGet > 0) {
    $stmt = $pdo->prepare("SELECT * FROM kasir_transaksi WHERE id = ? LIMIT 1");
    $stmt->execute([$idGet]);
    $tr = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$tr) { echo '<h3 style="text-align:center;color:#991B1B;font-family:sans-serif;">Transaksi tidak ditemukan (ID ' . $idGet . ')</h3>'; exit; }
    $stmtItem = $pdo->prepare("SELECT * FROM kasir_transaksi_item WHERE id_transaksi = ? ORDER BY id ASC");
    $stmtItem->execute([$idGet]);
    $items = $stmtItem->fetchAll(PDO::FETCH_ASSOC);
    $paymentMethod = $tr['payment'] ?? 'CASH';
    $total = (int)$tr['total'];
    $bayar = (int)$tr['bayar'];
    $kembalian = (int)$tr['kembalian'];
    $tglStr = tanggalStrukIndo($tr['tgl']);
    $noStr = htmlspecialchars($tr['no_struk']);
    $payBadgeColor = ($paymentMethod === 'QRIS') ? '#10B981' : '#991B1B';
    $payBadgeBg = ($paymentMethod === 'QRIS') ? '#D1FAE5' : '#FEE2E2';
    $payLabel = ($paymentMethod === 'QRIS') ? 'QRIS (Non Tunai)' : 'CASH (Tunai)';
    $jmlItem = 0; foreach ($items as $it) $jmlItem += (int)$it['qty'];

    $rowsItem = '';
    $noUrut = 1;
    foreach ($items as $it) {
        $hargaSat = (int)$it['harga'];
        $qty = (int)$it['qty'];
        $sub = (int)$it['subtotal'];
        $rowsItem .= '
        <tr>
            <td style="border:1px solid #CBD5E1; padding:8px 10px; text-align:center; vertical-align:top;">' . $noUrut++ . '</td>
            <td style="border:1px solid #CBD5E1; padding:8px 10px; vertical-align:top;">' . htmlspecialchars($it['nama_produk']) . '</td>
            <td style="border:1px solid #CBD5E1; padding:8px 10px; text-align:right; vertical-align:top;">' . formatRp($hargaSat) . '</td>
            <td style="border:1px solid #CBD5E1; padding:8px 10px; text-align:center; vertical-align:top;">' . $qty . '</td>
            <td style="border:1px solid #CBD5E1; padding:8px 10px; text-align:right; font-weight:700; vertical-align:top;">' . formatRp($sub) . '</td>
        </tr>';
    }

    $extraPayHtml = '';
    if ($paymentMethod === 'CASH') {
        $extraPayHtml = '
        <tr>
            <td colspan="4" style="padding:10px 14px; text-align:right; font-weight:700;">Uang Dibayarkan</td>
            <td style="padding:10px 14px; text-align:right; font-weight:800;">' . formatRp($bayar) . '</td>
        </tr>
        <tr>
            <td colspan="4" style="padding:10px 14px; text-align:right; font-weight:700; color:#065F46;">Kembalian</td>
            <td style="padding:10px 14px; text-align:right; font-weight:900; color:#065F46;">' . formatRp($kembalian) . '</td>
        </tr>';
    }

    $htmlA4 = '<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><title>Nota Transaksi ' . $noStr . '</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: "Segoe UI", Tahoma, sans-serif; margin: 0; padding: 28px 32px; color: #0F172A; background: #fff; }
        .kop-wrap { border-bottom: 3px solid #450A0A; padding-bottom: 14px; margin-bottom: 18px; }
        .kop-nama { font-size: 26px; font-weight: 900; color: #450A0A; letter-spacing: -0.3px; }
        .kop-sub { font-size: 13px; color: #475569; margin-top: 3px; }
        .kop-wa { font-size: 12.5px; color: #991B1B; margin-top: 2px; font-weight: 600; }
        .title-nota { text-align: center; margin: 14px 0 18px 0; font-size: 20px; font-weight: 800; color: #450A0A; letter-spacing: 0.3px; text-transform: uppercase; }
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px 20px; margin-bottom: 16px; font-size: 14px; }
        .info-row { display: flex; }
        .info-label { width: 120px; font-weight: 600; color: #475569; }
        .info-val { flex: 1; font-weight: 700; color: #0F172A; }
        .pay-badge { display:inline-block; padding: 4px 14px; background:' . $payBadgeBg . '; color:' . $payBadgeColor . '; border:1.5px solid ' . $payBadgeColor . '; border-radius: 99px; font-weight: 800; font-size: 12px; }
        table.tbl-item { width: 100%; border-collapse: collapse; margin-top: 4px; font-size: 13.5px; }
        table.tbl-item th { background: linear-gradient(135deg,#450A0A,#991B1B); color: #FFF8DC; padding: 10px 12px; text-align: left; font-weight: 700; letter-spacing: 0.2px; border: 1px solid #450A0A; }
        table.tbl-item th.text-right, table.tbl-item td.text-right { text-align: right; }
        table.tbl-item th.text-center, table.tbl-item td.text-center { text-align: center; }
        table.tbl-total { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 14px; }
        table.tbl-total td { border: 1px solid #E2E8F0; }
        .grand-total { background: linear-gradient(135deg,#450A0A,#991B1B); color: #FDE68A !important; font-size: 16px !important; padding: 12px 14px !important; font-weight: 900 !important; }
        .footer-sign { margin-top: 42px; display: grid; grid-template-columns: 1fr 1fr; gap: 40px; font-size: 13.5px; }
        .sign-box { text-align: center; }
        .sign-jab { font-weight: 700; margin-bottom: 80px; color: #334155; }
        .sign-nama { font-weight: 800; text-decoration: underline; color: #0F172A; }
        .sign-catatan { font-size: 11px; color: #64748B; margin-top: 4px; }
        .foot-cat { margin-top: 18px; font-size: 12px; color: #64748B; text-align: center; border-top: 1px dashed #CBD5E1; padding-top: 10px; }
        @page { size: A4 portrait; margin: 15mm 14mm; }
        @media print {
            body { margin: 0; padding: 0; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .no-print { display: none !important; }
        }
        .btn-print-top { position: fixed; top: 18px; right: 24px; z-index: 99; background: linear-gradient(135deg,#450A0A,#991B1B); color: #FDE68A; border: 2px solid #FBBF24; border-radius: 14px; padding: 10px 20px; font-weight: 800; cursor: pointer; box-shadow: 0 8px 20px rgba(153,27,27,0.35); }
    </style></head><body>
    <button class="btn-print-top no-print" onclick="window.print()"><i class="bi bi-printer-fill"></i> Cetak / Simpan PDF</button>

    <div class="kop-wrap">
        <div class="kop-nama">' . TOKO_NAMA . '</div>
        <div class="kop-sub">' . TOKO_ALAMAT_SINGKAT . '</div>
        <div class="kop-wa">WhatsApp: ' . TOKO_WA . '</div>
    </div>

    <div class="title-nota">— Nota Transaksi Penjualan —</div>

    <div class="info-grid">
        <div class="info-row"><div class="info-label">No. Struk</div><div class="info-val">' . $noStr . '</div></div>
        <div class="info-row"><div class="info-label">Waktu Transaksi</div><div class="info-val">' . $tglStr . '</div></div>
        <div class="info-row"><div class="info-label">Metode Bayar</div><div class="info-val"><span class="pay-badge">' . $payLabel . '</span></div></div>
        <div class="info-row"><div class="info-label">Jumlah Item</div><div class="info-val">' . $jmlItem . ' pcs</div></div>
    </div>

    <table class="tbl-item">
        <thead>
            <tr>
                <th style="text-align:center; width:6%;">No</th>
                <th style="width:48%;">Nama Produk</th>
                <th style="text-align:right; width:16%;">Harga Satuan</th>
                <th style="text-align:center; width:8%;">Qty</th>
                <th style="text-align:right; width:22%;">Subtotal</th>
            </tr>
        </thead>
        <tbody>' . $rowsItem . '</tbody>
    </table>

    <table class="tbl-total">
        <tr>
            <td colspan="4" style="padding:12px 14px; text-align:right; font-size:15px; font-weight:800; color:#450A0A;">TOTAL TAGIHAN</td>
            <td class="grand-total" style="text-align:right;">' . formatRp($total) . '</td>
        </tr>
        ' . $extraPayHtml . '
    </table>

    <div class="footer-sign">
        <div class="sign-box">
            <div class="sign-jab">Penerima,</div>
            <div style="height: 22px;"></div>
            <div class="sign-nama">___________________</div>
            <div class="sign-catatan">(Kasir Baraya Dawet)</div>
        </div>
        <div class="sign-box">
            <div class="sign-jab">Pembeli / Customer,</div>
            <div style="height: 22px;"></div>
            <div class="sign-nama">___________________</div>
            <div class="sign-catatan">(Tanda terima sesuai)</div>
        </div>
    </div>

    <div class="foot-cat">
        Dokumen ini dicetak secara otomatis oleh sistem &middot; ' . TOKO_NAMA . ' &middot; Terima kasih atas kunjungan anda.
    </div>
    <script>window.onload = function() { setTimeout(function() { window.focus(); }, 150); }<' . '/script>
    </body></html>';
    echo $htmlA4;
    exit;
}

// ===== HANDLER: EXPORT SEMUA FILTER KE PDF A4 =====
if ($aksiGet === 'export_semua') {
    $tgl = $_GET['tgl'] ?? date('Y-m-d');
    $pay = $_GET['payment'] ?? 'SEMUA';
    $whereTgl = "DATE(tgl) = ?";
    $params = [$tgl];
    if ($pay !== 'SEMUA') {
        $whereTgl .= " AND payment = ?";
        $params[] = $pay;
    }
    $stmt = $pdo->prepare("SELECT * FROM kasir_transaksi WHERE {$whereTgl} ORDER BY tgl ASC");
    $stmt->execute($params);
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $payLabel = ($pay === 'QRIS') ? 'QRIS' : (($pay === 'CASH') ? 'CASH' : 'Semua Metode');
    $totalAll = 0;
    $totalCash = 0; $totalQris = 0;
    $jmlCash = 0; $jmlQris = 0;
    $bodyHtml = '';
    $noUrut = 1;
    foreach ($list as $tr) {
        $idTr = (int)$tr['id'];
        $stmtItem = $pdo->prepare("SELECT SUM(qty) AS jml, COUNT(*) AS jenis FROM kasir_transaksi_item WHERE id_transaksi = ?");
        $stmtItem->execute([$idTr]);
        $rc = $stmtItem->fetch(PDO::FETCH_ASSOC);
        $jml = (int)($rc['jml'] ?? 0);
        $jenis = (int)($rc['jenis'] ?? 0);
        $pm = $tr['payment'] ?? 'CASH';
        $tot = (int)$tr['total'];
        $totalAll += $tot;
        if ($pm === 'QRIS') { $totalQris += $tot; $jmlQris++; } else { $totalCash += $tot; $jmlCash++; }
        $payColor = ($pm === 'QRIS') ? '#10B981' : '#991B1B';
        $payBg = ($pm === 'QRIS') ? '#D1FAE5' : '#FEE2E2';
        $bodyHtml .= '
        <tr>
            <td style="border:1px solid #CBD5E1; padding:8px 10px; text-align:center;">' . $noUrut++ . '</td>
            <td style="border:1px solid #CBD5E1; padding:8px 10px; font-weight:700;">' . htmlspecialchars($tr['no_struk']) . '</td>
            <td style="border:1px solid #CBD5E1; padding:8px 10px; text-align:center;">' . date('H:i:s', strtotime($tr['tgl'])) . '</td>
            <td style="border:1px solid #CBD5E1; padding:8px 10px; text-align:center;"><span style="display:inline-block; padding:2px 10px; background:' . $payBg . '; color:' . $payColor . '; border:1px solid ' . $payColor . '; border-radius:99px; font-weight:800; font-size:11.5px;">' . $pm . '</span></td>
            <td style="border:1px solid #CBD5E1; padding:8px 10px; text-align:center;">' . $jenis . ' jenis / ' . $jml . ' pcs</td>
            <td style="border:1px solid #CBD5E1; padding:8px 10px; text-align:right; font-weight:900; color:#991B1B;">' . formatRp($tot) . '</td>
        </tr>';
    }

    $tglJudul = date('d F Y', strtotime($tgl));
    $htmlAll = '<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Transaksi Kasir ' . $tglJudul . '</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: "Segoe UI", Tahoma, sans-serif;
            margin: 0; padding: 28px 30px 24px 30px;
            color: #0F172A;
            background:
                radial-gradient(1200px 600px at 10% -10%, rgba(254,243,199,0.55), transparent 60%),
                radial-gradient(1000px 500px at 100% 0%, rgba(255,228,230,0.45), transparent 55%),
                linear-gradient(180deg,#fffdf8 0%, #ffffff 100%);
            border-top: 7px solid transparent;
            background-origin: border-box;
            background-clip: padding-box, border-box;
            position: relative;
        }
        body::before {
            content:""; position:fixed; inset:0 0 auto 0; height:7px;
            background: linear-gradient(90deg,#FBBF24 0%, #F59E0B 25%, #991B1B 50%, #7F1D1D 75%, #FBBF24 100%);
            z-index:9999;
        }
        .kop-wrap {
            padding: 14px 18px 16px 18px;
            margin-bottom: 20px;
            border: 2px solid rgba(251,191,36,0.55);
            border-radius: 18px;
            background:
                linear-gradient(135deg, rgba(254,243,199,0.65) 0%, rgba(255,255,255,0.95) 50%, rgba(255,228,230,0.55) 100%);
            box-shadow: 0 12px 30px rgba(69,10,10,0.12), inset 0 0 0 1px rgba(255,255,255,0.7);
            position: relative;
            overflow: hidden;
        }
        .kop-wrap::after {
            content:""; position:absolute; right:-30px; top:-30px; width:150px; height:150px;
            background: radial-gradient(circle, rgba(251,191,36,0.35), transparent 65%);
            pointer-events:none;
        }
        .kop-head { display:flex; align-items:center; gap:14px; position:relative; z-index:2; }
        .kop-icon {
            width: 56px; height: 56px; flex-shrink:0;
            border-radius: 16px;
            background: linear-gradient(135deg,#450A0A 0%, #7F1D1D 55%, #991B1B 100%);
            color: #FDE68A;
            display:flex; align-items:center; justify-content:center;
            font-size: 26px;
            border: 2px solid #FBBF24;
            box-shadow: 0 8px 18px rgba(153,27,27,0.35);
        }
        .kop-text-wrap { flex:1; min-width:0; }
        .kop-nama { font-size: 26px; font-weight: 900; color: #450A0A; letter-spacing: -0.3px; line-height: 1.05; }
        .kop-sub { font-size: 13px; color: #475569; margin-top: 4px; font-weight: 500; }
        .kop-wa { font-size: 12.5px; color: #991B1B; margin-top: 3px; font-weight: 700; display:inline-flex; align-items:center; gap:5px; }
        .title-nota {
            text-align: center; margin: 4px 0 6px 0;
            font-size: 21px; font-weight: 900; color: #450A0A;
            letter-spacing: 0.2px; text-transform: uppercase;
            position: relative;
        }
        .title-nota::after {
            content:""; display:block; margin: 8px auto 0 auto;
            width: 80px; height: 4px; border-radius: 99px;
            background: linear-gradient(90deg, #991B1B, #FBBF24);
        }
        .sub-filter {
            text-align: center; font-size: 13.5px; color: #475569;
            margin: 8px 0 22px 0;
        }
        .sub-filter b { color: #0F172A; font-weight: 800; }
        .sub-filter .pill {
            display:inline-flex; align-items:center; gap:5px;
            padding: 4px 12px; border-radius: 99px;
            background: rgba(254,243,199,0.7);
            border: 1.5px solid #FBBF24;
            color: #92400E;
            font-weight: 800;
            margin: 0 3px;
        }
        .card-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 22px; }
        .stat-card {
            border-radius: 18px; padding: 18px 18px 16px 18px;
            border: 2px solid; box-shadow: 0 12px 28px rgba(15,23,42,0.12);
            position: relative; overflow: hidden;
            transition: transform 0.2s ease;
        }
        .stat-card::after { content:""; position:absolute; right:-28px; top:-28px; width:130px; height:130px; border-radius:50%; opacity:0.18; }
        .stat-icon {
            width: 48px; height: 48px; border-radius: 14px;
            display:flex; align-items:center; justify-content:center;
            font-size: 22px;
            margin-bottom: 10px;
            position: relative; z-index: 2;
            box-shadow: 0 6px 14px rgba(15,23,42,0.18);
        }
        .stat-label { font-size: 12.5px; font-weight: 700; opacity: 0.9; position: relative; z-index: 2; }
        .stat-trx { font-size: 12px; font-weight: 700; margin-top: 3px; opacity: 0.82; position: relative; z-index: 2; }
        .stat-val { font-size: 24px; font-weight: 900; letter-spacing: -0.3px; margin-top: 6px; position: relative; z-index: 2; line-height: 1.08; }

        .stat-cash {
            background: linear-gradient(135deg,#FFF5F5 0%, #FECDD3 40%, #FDA4AF 100%);
            border-color:#991B1B;
        }
        .stat-cash::after { background:#991B1B; }
        .stat-cash .stat-icon { background:#991B1B; color:#FDE68A; }
        .stat-cash .stat-val { color:#7F1D1D; text-shadow: 0 1px 0 rgba(255,255,255,0.5); }
        .stat-cash .stat-label { color:#450A0A; }

        .stat-qris {
            background: linear-gradient(135deg,#ECFDF5 0%, #A7F3D0 40%, #6EE7B7 100%);
            border-color:#10B981;
        }
        .stat-qris::after { background:#065F46; }
        .stat-qris .stat-icon { background:#10B981; color:#FFF; }
        .stat-qris .stat-val { color:#065F46; text-shadow: 0 1px 0 rgba(255,255,255,0.5); }
        .stat-qris .stat-label { color:#064E3B; }

        .stat-grand {
            background: linear-gradient(135deg,#FFFBEB 0%, #FEF3C7 40%, #FDE68A 100%);
            border-color:#FBBF24;
        }
        .stat-grand::after { background:#B45309; }
        .stat-grand .stat-icon { background:#B45309; color:#FFFBEB; }
        .stat-grand .stat-val { color:#78350F; text-shadow: 0 1px 0 rgba(255,255,255,0.5); }
        .stat-grand .stat-label { color:#451A03; }

        .table-wrap {
            background: #ffffff;
            border-radius: 18px;
            padding: 18px 20px 20px 20px;
            box-shadow: 0 12px 32px rgba(15,23,42,0.1);
            border: 1.5px solid rgba(251,191,36,0.35);
        }
        table { width: 100%; border-collapse: collapse; font-size: 13px; overflow: hidden; border-radius: 14px; }
        table thead th {
            background: linear-gradient(135deg,#450A0A 0%, #7F1D1D 55%, #991B1B 100%);
            color: #FFF8DC; padding: 12px 14px; text-align: left;
            font-weight: 800; border: 0;
            border-bottom: 2.5px solid #FBBF24;
            letter-spacing: 0.15px;
            font-size: 12.5px;
        }
        table tbody td {
            padding: 10px 13px;
            border-bottom: 1px solid #F1F5F9;
        }
        table tbody tr:nth-child(even) td {
            background: rgba(254,243,199,0.18);
        }
        table tbody tr:hover td { background: rgba(254,243,199,0.45); }

        .grand-row td {
            background: linear-gradient(135deg,#450A0A 0%, #7F1D1D 55%, #991B1B 100%) !important;
            color: #FDE68A !important;
            font-weight: 900 !important;
            font-size: 15px !important;
            padding: 13px 14px !important;
            border-top: 2.5px solid #FBBF24 !important;
            border-bottom: 0 !important;
        }

        .foot-cat {
            margin-top: 24px;
            padding: 14px 18px;
            border: 1.5px dashed #FBBF24;
            border-radius: 14px;
            background: rgba(254,243,199,0.45);
            font-size: 12px; color: #64748B;
            text-align: center;
            font-weight: 600;
        }
        .foot-cat b { color:#991B1B; }

        @page { size: A4 portrait; margin: 12mm 10mm 14mm 10mm; }
        @media print {
            body { margin: 0; padding: 0; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .no-print { display: none !important; }
            .table-wrap { box-shadow: none !important; }
            .stat-card { box-shadow: none !important; }
            .kop-wrap { box-shadow: none !important; }
        }
        .btn-print-top {
            position: fixed; top: 22px; right: 28px; z-index: 99999;
            background: linear-gradient(135deg,#450A0A 0%, #7F1D1D 55%, #991B1B 100%);
            color: #FDE68A; border: 2.5px solid #FBBF24;
            border-radius: 16px; padding: 11px 22px;
            font-weight: 800; font-size: 14px;
            cursor: pointer; display:inline-flex; align-items:center; gap:8px;
            box-shadow: 0 12px 30px rgba(153,27,27,0.45);
            letter-spacing: 0.2px;
            transition: transform 0.15s ease;
        }
        .btn-print-top:hover { transform: translateY(-2px); }
    </style></head><body>
    <button class="btn-print-top no-print" onclick="window.print()">
        <i class="bi bi-printer-fill"></i> Cetak / Simpan PDF
    </button>

    <div class="kop-wrap">
        <div class="kop-head">
            <div class="kop-icon"><i class="bi bi-cup-hot-fill"></i></div>
            <div class="kop-text-wrap">
                <div class="kop-nama">' . TOKO_NAMA . '</div>
                <div class="kop-sub"><i class="bi bi-geo-alt-fill me-1"></i>' . TOKO_ALAMAT_SINGKAT . '</div>
                <div class="kop-wa"><i class="bi bi-whatsapp"></i> WhatsApp: ' . TOKO_WA . '</div>
            </div>
        </div>
    </div>

    <div class="title-nota">— Laporan Penjualan Kasir Harian —</div>
    <div class="sub-filter">
        <span class="pill"><i class="bi bi-calendar3"></i> Tanggal: <b>' . $tglJudul . '</b></span>
        <span class="pill"><i class="bi bi-funnel-fill"></i> Metode: <b>' . $payLabel . '</b></span>
    </div>

    <div class="card-row">
        <div class="stat-card stat-cash">
            <div class="stat-icon"><i class="bi bi-cash-coin"></i></div>
            <div class="stat-label">TOTAL CASH (Tunai)</div>
            <div class="stat-trx">' . $jmlCash . ' transaksi</div>
            <div class="stat-val">' . formatRp($totalCash) . '</div>
        </div>
        <div class="stat-card stat-qris">
            <div class="stat-icon"><i class="bi bi-qr-code-scan"></i></div>
            <div class="stat-label">TOTAL QRIS (Non Tunai)</div>
            <div class="stat-trx">' . $jmlQris . ' transaksi</div>
            <div class="stat-val">' . formatRp($totalQris) . '</div>
        </div>
        <div class="stat-card stat-grand">
            <div class="stat-icon"><i class="bi bi-gem"></i></div>
            <div class="stat-label">GRAND TOTAL SEMUA</div>
            <div class="stat-trx">' . ($jmlCash + $jmlQris) . ' transaksi</div>
            <div class="stat-val">' . formatRp($totalAll) . '</div>
        </div>
    </div>

    <div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th style="text-align:center; width:6%;">No</th>
                <th style="width:22%;">No. Struk</th>
                <th style="text-align:center; width:12%;">Jam</th>
                <th style="text-align:center; width:14%;">Metode</th>
                <th style="text-align:center; width:20%;">Item</th>
                <th style="text-align:right; width:26%;">Total</th>
            </tr>
        </thead>
        <tbody>
            ' . (count($list) === 0 ? '<tr><td colspan="6" style="text-align:center; padding:28px 12px; border-bottom:1px solid #E2E8F0; color:#64748B; font-style:italic; font-weight:600;"><i class="bi bi-inbox me-2"></i>Belum ada data transaksi di tanggal dan filter ini</td></tr>' : $bodyHtml) . '
            <tr class="grand-row">
                <td colspan="5" style="text-align:right;"><i class="bi bi-cash-stack me-2"></i>GRAND TOTAL</td>
                <td style="text-align:right;">' . formatRp($totalAll) . '</td>
            </tr>
        </tbody>
    </table>
    </div>

    <div class="foot-cat">
        <i class="bi bi-printer me-1"></i> Dicetak pada: <b>' . date('d F Y H:i:s') . ' WIB</b> &middot;
        <i class="bi bi-layers me-1"></i> Sistem Manajemen Keuangan Baraya Dawet &middot;
        <i class="bi bi-star-fill me-1" style="color:#FBBF24;"></i><b>' . TOKO_NAMA . '</b>
    </div>
    <script>window.onload = function() { setTimeout(function() { window.focus(); }, 200); }<' . '/script>
    </body></html>';
    echo $htmlAll;
    exit;
}

// ===== HANDLER: GET DETAIL JSON (AJAX) =====
if ($aksiGet === 'detail_json' && $idGet > 0) {
    header('Content-Type: application/json');
    $stmt = $pdo->prepare("SELECT * FROM kasir_transaksi WHERE id = ? LIMIT 1");
    $stmt->execute([$idGet]);
    $tr = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$tr) { echo json_encode(['ok' => false, 'msg' => 'Transaksi tidak ditemukan']); exit; }
    $stmtItem = $pdo->prepare("SELECT * FROM kasir_transaksi_item WHERE id_transaksi = ? ORDER BY id ASC");
    $stmtItem->execute([$idGet]);
    $items = $stmtItem->fetchAll(PDO::FETCH_ASSOC);
    $jmlPcs = 0; foreach ($items as $it) $jmlPcs += (int)$it['qty'];
    $out = [
        'ok' => true,
        'no_struk' => $tr['no_struk'],
        'tgl_str' => tanggalStrukIndo($tr['tgl']),
        'payment' => $tr['payment'] ?? 'CASH',
        'total' => (int)$tr['total'],
        'bayar' => (int)$tr['bayar'],
        'kembalian' => (int)$tr['kembalian'],
        'jml_jenis' => count($items),
        'jml_pcs' => $jmlPcs,
        'items' => $items
    ];
    echo json_encode($out);
    exit;
}

// ===== FILTER DEFAULT HALAMAN LIST =====
$tglDefault = date('Y-m-d');
$filterTgl = $_GET['tgl'] ?? $tglDefault;
$filterPayment = $_GET['payment'] ?? 'SEMUA';

$whereTgl = "DATE(tgl) = ?";
$params = [$filterTgl];
if ($filterPayment !== 'SEMUA') {
    $whereTgl .= " AND payment = ?";
    $params[] = $filterPayment;
}

// Query 3 stat card
$totalCash = 0; $totalQris = 0; $jmlCash = 0; $jmlQris = 0; $grandTotal = 0; $grandJml = 0;
$stmtStat = $pdo->prepare("SELECT payment, SUM(total) AS tot, COUNT(*) AS jml FROM kasir_transaksi WHERE {$whereTgl} GROUP BY payment");
$stmtStat->execute($params);
while ($r = $stmtStat->fetch(PDO::FETCH_ASSOC)) {
    $tot = (int)($r['tot'] ?? 0);
    $jml = (int)($r['jml'] ?? 0);
    $grandTotal += $tot;
    $grandJml += $jml;
    if (($r['payment'] ?? '') === 'QRIS') { $totalQris = $tot; $jmlQris = $jml; }
    else { $totalCash = $tot; $jmlCash = $jml; }
}

// Query list transaksi + jumlah item sekaligus (subquery)
$stmtList = $pdo->prepare("
    SELECT t.*,
        (SELECT SUM(qty) FROM kasir_transaksi_item i WHERE i.id_transaksi = t.id) AS jml_pcs,
        (SELECT COUNT(*) FROM kasir_transaksi_item i WHERE i.id_transaksi = t.id) AS jml_jenis
    FROM kasir_transaksi t
    WHERE {$whereTgl}
    ORDER BY t.tgl DESC
");
$stmtList->execute($params);
$listTransaksi = $stmtList->fetchAll(PDO::FETCH_ASSOC);

$current_filter_page = 'transaksi_kasir.php';
?>

<style>
    .filter-bar-wrap { background: #fff; border-radius: 18px; padding: 16px 18px; box-shadow: 0 8px 24px rgba(15,23,42,0.08); border: 1px solid rgba(251,191,36,0.3); margin-bottom: 18px; display: flex; flex-wrap: wrap; gap: 14px; align-items: center; justify-content: space-between; }
    .filter-left { display:flex; flex-wrap: wrap; gap: 12px; align-items: center; }
    .filter-label { font-weight: 700; color:#450A0A; font-size: 0.92rem; margin-right: 4px; }
    .input-tgl { padding: 8px 14px; border-radius: 12px; border: 1.5px solid #E2E8F0; font-weight: 600; color:#0F172A; min-height: 40px; }
    .input-tgl:focus { outline: none; border-color:#991B1B; box-shadow: 0 0 0 3px rgba(153,27,27,0.15); }
    .payment-tabs { display:inline-flex; background:#F1F5F9; padding: 5px; border-radius: 14px; gap: 4px; }
    .pay-tab { padding: 7px 18px; border-radius: 10px; font-weight: 700; font-size: 0.85rem; cursor: pointer; color: #64748B; border: 0; background: transparent; transition: all 0.2s ease; }
    .pay-tab.active { color:#fff; background: linear-gradient(135deg,#450A0A,#991B1B); box-shadow: 0 4px 12px rgba(153,27,27,0.35); }
    .btn-apply-filter { background: linear-gradient(135deg,#450A0A,#991B1B); color:#FDE68A; border: 1.5px solid #FBBF24; border-radius: 12px; padding: 8px 20px; font-weight: 800; min-height: 40px; }
    .btn-apply-filter:hover { color:#fff; transform: translateY(-1px); box-shadow: 0 6px 16px rgba(153,27,27,0.3); }
    .btn-export-all { background: linear-gradient(135deg,#FBBF24,#F59E0B); color:#451A03; border: 1.5px solid #D97706; border-radius: 12px; padding: 8px 18px; font-weight: 800; min-height: 40px; }
    .btn-export-all:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(217,119,6,0.35); }

    .stat-row-grid { display:grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 20px; }
    .stat-card-kasir { border-radius: 18px; padding: 18px 20px; box-shadow: 0 10px 28px rgba(15,23,42,0.09); border: 2px solid; position: relative; overflow: hidden; transition: transform 0.2s ease; }
    .stat-card-kasir:hover { transform: translateY(-2px); }
    .stat-card-kasir::after { content:''; position:absolute; right:-24px; top:-24px; width:120px; height:120px; border-radius:50%; opacity:0.15; }
    .stat-kasir-cash { background: linear-gradient(135deg,#FFF1F2 0%,#FECDD3 40%,#FDA4AF 100%); border-color:#991B1B; }
    .stat-kasir-cash::after { background:#991B1B; }
    .stat-kasir-qris { background: linear-gradient(135deg,#ECFDF5 0%,#A7F3D0 40%,#6EE7B7 100%); border-color:#10B981; }
    .stat-kasir-qris::after { background:#065F46; }
    .stat-kasir-grand { background: linear-gradient(135deg,#FFFBEB 0%,#FEF3C7 40%,#FDE68A 100%); border-color:#FBBF24; }
    .stat-kasir-grand::after { background:#B45309; }
    .stat-kasir-icon { width: 48px; height: 48px; border-radius: 14px; display:flex; align-items:center; justify-content:center; font-size: 22px; margin-bottom: 10px; position: relative; z-index: 2; }
    .stat-kasir-cash .stat-kasir-icon { background:#991B1B; color:#FDE68A; box-shadow: 0 6px 14px rgba(153,27,27,0.3); }
    .stat-kasir-qris .stat-kasir-icon { background:#10B981; color:#FFF; box-shadow: 0 6px 14px rgba(16,185,129,0.3); }
    .stat-kasir-grand .stat-kasir-icon { background:#B45309; color:#FFFBEB; box-shadow: 0 6px 14px rgba(180,83,9,0.3); }
    .stat-kasir-label { font-size: 0.85rem; font-weight: 700; opacity: 0.8; position: relative; z-index: 2; }
    .stat-kasir-jml { font-size: 0.8rem; font-weight: 700; opacity: 0.7; margin-top: 2px; position: relative; z-index: 2; }
    .stat-kasir-val { font-size: 1.7rem; font-weight: 900; letter-spacing: -0.4px; margin-top: 4px; position: relative; z-index: 2; line-height: 1.1; }
    .stat-kasir-cash .stat-kasir-val { color:#7F1D1D; text-shadow: 0 1px 0 rgba(255,255,255,0.4); }
    .stat-kasir-qris .stat-kasir-val { color:#065F46; text-shadow: 0 1px 0 rgba(255,255,255,0.4); }
    .stat-kasir-grand .stat-kasir-val { color:#78350F; text-shadow: 0 1px 0 rgba(255,255,255,0.4); }

    .table-wrap { background:#fff; border-radius: 18px; padding: 18px 20px; box-shadow: 0 10px 28px rgba(15,23,42,0.08); border: 1px solid rgba(251,191,36,0.25); }
    .tbl-kasir thead th { background: linear-gradient(135deg,#450A0A 0%, #7F1D1D 55%, #991B1B 100%); color:#FFF8DC; padding: 12px 14px; font-weight: 700; letter-spacing: 0.2px; border: 0 !important; border-bottom: 2px solid #FBBF24 !important; font-size: 0.85rem; }
    .tbl-kasir tbody td { padding: 12px 14px; vertical-align: middle; font-size: 0.9rem; border-bottom: 1px solid #F1F5F9; }
    .tbl-kasir tbody tr:hover { background: rgba(254,243,199,0.3); }
    .pay-badge-cash, .pay-badge-qris { display:inline-flex; align-items: center; gap: 5px; padding: 4px 12px; border-radius: 99px; font-weight: 800; font-size: 0.78rem; letter-spacing: 0.2px; }
    .pay-badge-cash { background:#FEE2E2; color:#991B1B; border: 1.5px solid #991B1B; }
    .pay-badge-qris { background:#D1FAE5; color:#065F46; border: 1.5px solid #10B981; }
    .total-col { font-weight: 900; color:#991B1B; letter-spacing: -0.3px; font-size: 1rem; }
    .btn-aksi-row { display:inline-flex; gap: 6px; flex-wrap: wrap; }
    .btn-resi, .btn-pdf, .btn-detail { border-radius: 10px; padding: 6px 12px; font-weight: 700; font-size: 0.78rem; display:inline-flex; align-items:center; gap: 5px; border: 1.5px solid; transition: all 0.15s ease; cursor: pointer; }
    .btn-detail { background: #EEF2FF; color:#3730A3; border-color:#4F46E5; }
    .btn-detail:hover { background: linear-gradient(135deg,#4F46E5,#3730A3); color:#fff; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(79,70,229,0.3); }
    .btn-resi { background: #FFF1F2; color:#991B1B; border-color:#991B1B; }
    .btn-resi:hover { background:#991B1B; color:#FDE68A; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(153,27,27,0.25); }
    .btn-pdf { background: #FEF3C7; color:#92400E; border-color:#D97706; }
    .btn-pdf:hover { background: linear-gradient(135deg,#F59E0B,#D97706); color:#FFFBEB; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(217,119,6,0.35); }
    .detail-item-row { display:flex; justify-content:space-between; align-items:flex-start; gap:10px; padding:11px 14px; border-radius: 12px; background: #F9FAFB; margin-bottom: 7px; border: 1px solid #F1F5F9; }
    .detail-item-row:nth-child(even) { background: #FEF3C7; background: rgba(254,243,199,0.35); border-color: rgba(251,191,36,0.3); }
    .detail-item-nama { font-weight: 800; color:#1F2937; font-size: 0.95rem; line-height: 1.2; }
    .detail-item-meta { font-size: 0.78rem; color:#6B7280; margin-top: 3px; font-weight: 600; }
    .detail-item-sub { font-weight: 900; color:#991B1B; letter-spacing: -0.2px; font-size: 1rem; white-space: nowrap; }
    .no-struk-cell { font-weight: 800; color:#1E293B; letter-spacing: -0.1px; font-family: "Courier New", monospace; }
    .empty-row td { padding: 40px 12px; text-align: center; color:#64748B; font-style: italic; }
    @media (max-width: 991.98px) {
        .stat-row-grid { grid-template-columns: 1fr; }
        .filter-bar-wrap { flex-direction: column; align-items: stretch; }
        .filter-left { width: 100%; }
        .filter-right { display:flex; gap: 10px; margin-top: 4px; }
        .filter-right > * { flex: 1; }
    }
</style>

<!-- FILTER BAR -->
<div class="filter-bar-wrap">
    <form method="get" class="filter-left" id="formFilter">
        <input type="hidden" name="payment" id="hiddenPayment" value="<?php echo htmlspecialchars($filterPayment); ?>">
        <div>
            <span class="filter-label"><i class="bi bi-calendar3 me-1"></i> Tanggal</span>
            <input type="date" name="tgl" class="input-tgl" value="<?php echo htmlspecialchars($filterTgl); ?>" max="<?php echo date('Y-m-d'); ?>">
        </div>
        <div>
            <span class="filter-label ms-2"><i class="bi bi-filter me-1"></i> Metode</span>
            <div class="payment-tabs" role="tablist">
                <button type="button" class="pay-tab <?php echo ($filterPayment === 'SEMUA') ? 'active' : ''; ?>" data-pay="SEMUA" onclick="pilihTab('SEMUA');">SEMUA</button>
                <button type="button" class="pay-tab <?php echo ($filterPayment === 'CASH') ? 'active' : ''; ?>" data-pay="CASH" onclick="pilihTab('CASH');">💰 CASH</button>
                <button type="button" class="pay-tab <?php echo ($filterPayment === 'QRIS') ? 'active' : ''; ?>" data-pay="QRIS" onclick="pilihTab('QRIS');">📱 QRIS</button>
            </div>
        </div>
        <button type="submit" class="btn-apply-filter ms-2"><i class="bi bi-funnel-fill me-1"></i> Terapkan</button>
    </form>
    <div class="filter-right">
        <a href="<?php echo $base_url; ?>/pages/transaksi_kasir.php?aksi=export_semua&tgl=<?php echo urlencode($filterTgl); ?>&payment=<?php echo urlencode($filterPayment); ?>" target="_blank" class="btn-export-all">
            <i class="bi bi-file-earmark-pdf-fill me-1"></i> Export PDF
        </a>
        <a href="<?php echo $base_url; ?>/kasir.php" target="_blank" class="btn-apply-filter" style="background: linear-gradient(135deg,#10B981,#059669); border-color:#FBBF24;">
            <i class="bi bi-cash-register me-1"></i> Buka Kasir
        </a>
    </div>
</div>

<!-- 3 STAT CARD -->
<div class="stat-row-grid">
    <div class="stat-card-kasir stat-kasir-cash">
        <div class="stat-kasir-icon"><i class="bi bi-cash-coin"></i></div>
        <div class="stat-kasir-label">TOTAL PENDAPATAN CASH</div>
        <div class="stat-kasir-jml"><?php echo $jmlCash; ?> transaksi · Tunai</div>
        <div class="stat-kasir-val"><?php echo formatRp($totalCash); ?></div>
    </div>
    <div class="stat-card-kasir stat-kasir-qris">
        <div class="stat-kasir-icon"><i class="bi bi-qr-code-scan"></i></div>
        <div class="stat-kasir-label">TOTAL PENDAPATAN QRIS</div>
        <div class="stat-kasir-jml"><?php echo $jmlQris; ?> transaksi · Non Tunai</div>
        <div class="stat-kasir-val"><?php echo formatRp($totalQris); ?></div>
    </div>
    <div class="stat-card-kasir stat-kasir-grand">
        <div class="stat-kasir-icon"><i class="bi bi-graph-up-arrow"></i></div>
        <div class="stat-kasir-label">GRAND TOTAL HARI INI</div>
        <div class="stat-kasir-jml"><?php echo $grandJml; ?> total transaksi</div>
        <div class="stat-kasir-val"><?php echo formatRp($grandTotal); ?></div>
    </div>
</div>

<!-- TABEL LIST -->
<div class="table-wrap">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h5 class="fw-black mb-0" style="color:#450A0A; letter-spacing:-0.3px;">
            <i class="bi bi-receipt-cutoff me-2" style="color:#FBBF24;"></i> Data Transaksi Kasir
            <small class="ms-2 fw-semibold opacity-70" style="font-size:0.8rem;">Tanggal <?php echo date('d F Y', strtotime($filterTgl)); ?></small>
        </h5>
        <span class="small text-muted opacity-75 fw-semibold">
            Jumlah: <b style="color:#991B1B;"><?php echo count($listTransaksi); ?> transaksi</b>
        </span>
    </div>
    <div class="table-responsive">
        <table class="table tbl-kasir align-middle mb-0" id="tblKasir">
            <thead>
                <tr>
                    <th style="text-align:center; width:6%;">No</th>
                    <th style="width:18%;">No. Struk</th>
                    <th style="width:11%; text-align:center;">Jam</th>
                    <th style="width:13%; text-align:center;">Metode</th>
                    <th style="width:14%; text-align:center;">Jumlah Item</th>
                    <th style="width:15%; text-align:right;">Total</th>
                    <th style="width:12%; text-align:right;">Bayar</th>
                    <th style="width:23%; text-align:center;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($listTransaksi) === 0): ?>
                <tr class="empty-row">
                    <td colspan="8">
                        <i class="bi bi-receipt me-2"></i> Belum ada transaksi pada tanggal <?php echo date('d F Y', strtotime($filterTgl)); ?> dengan filter metode <?php echo htmlspecialchars($filterPayment); ?>.
                    </td>
                </tr>
                <?php else: ?>
                <?php $noUrut = 1; foreach ($listTransaksi as $tr): ?>
                <?php
                    $idTr = (int)$tr['id'];
                    $noStr = htmlspecialchars($tr['no_struk']);
                    $jam = date('H:i', strtotime($tr['tgl']));
                    $pm = $tr['payment'] ?? 'CASH';
                    $jmlPcs = (int)($tr['jml_pcs'] ?? 0);
                    $jmlJenis = (int)($tr['jml_jenis'] ?? 0);
                    $tot = (int)$tr['total'];
                    $byr = (int)$tr['bayar'];
                ?>
                <tr>
                    <td style="text-align:center; font-weight:700; color:#64748B;"><?php echo $noUrut++; ?>.</td>
                    <td><span class="no-struk-cell"><?php echo $noStr; ?></span></td>
                    <td style="text-align:center; font-weight:700; color:#1E293B; font-family:'Courier New', monospace;"><?php echo $jam; ?></td>
                    <td style="text-align:center;">
                        <?php if ($pm === 'QRIS'): ?>
                        <span class="pay-badge-qris"><i class="bi bi-qr-code-scan"></i> QRIS</span>
                        <?php else: ?>
                        <span class="pay-badge-cash"><i class="bi bi-cash-coin"></i> CASH</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center; font-weight:700; color:#334155;"><?php echo $jmlJenis; ?> jenis / <?php echo $jmlPcs; ?> pcs</td>
                    <td class="total-col" style="text-align:right;"><?php echo formatRp($tot); ?></td>
                    <td style="text-align:right; font-weight:700; color:#1E293B;"><?php echo ($pm === 'QRIS') ? '<span class="opacity-65">— Pas —</span>' : formatRp($byr); ?></td>
                    <td style="text-align:center;">
                        <div class="btn-aksi-row justify-content-center">
                            <button type="button" class="btn-detail" onclick="bukaDetail(<?php echo $idTr; ?>)" title="Lihat detail produk yang dibeli">
                                <i class="bi bi-eye-fill"></i> Detail
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- MODAL DETAIL PRODUK DIBELI -->
<div class="modal fade" id="detailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg" style="max-width: 620px;">
        <div class="modal-content" style="border-radius: 22px; overflow: hidden; border: 0; box-shadow: 0 24px 60px rgba(69,10,10,0.28);">
            <div class="modal-header border-0" style="background: linear-gradient(135deg,#450A0A 0%,#7F1D1D 50%,#991B1B 100%); padding: 20px 26px; border-bottom: 2.5px solid #FBBF24;">
                <div>
                    <h5 class="modal-title fw-black mb-0" style="color:#fff; letter-spacing:-0.2px; font-size: 1.1rem;">
                        <i class="bi bi-receipt-cutoff me-2" style="color:#FBBF24;"></i>Detail Transaksi
                    </h5>
                    <div class="small mt-1" style="color: rgba(255,255,255,0.78);">
                        Struk <span id="dt-no-struk" style="font-family:'Courier New',monospace; font-weight:700; color:#FDE68A;">—</span>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" style="background: rgba(255,255,255,0.2) url(&quot;data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16' fill='%23fff'%3e%3cpath d='M.293.293a1 1 0 0 1 1.414 0L8 6.586 14.293.293a1 1 0 1 1 1.414 1.414L9.414 8l6.293 6.293a1 1 0 0 1-1.414 1.414L8 9.414l-6.293 6.293a1 1 0 0 1-1.414-1.414L6.586 8 .293 1.707a1 1 0 0 1 0-1.414z'/%3e%3c/svg%3e&quot;) center/1em auto no-repeat; opacity: 1;"></button>
            </div>
            <div class="modal-body" style="padding: 22px 26px;">
                <!-- INFO ATAS -->
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 10px 16px; margin-bottom: 18px; padding: 14px 16px; border-radius: 16px; background: linear-gradient(135deg, rgba(254,243,199,0.45), rgba(255,228,230,0.35)); border: 1.5px solid rgba(251,191,36,0.5);">
                    <div>
                        <div class="small fw-bold mb-1" style="color:#78350F;"><i class="bi bi-calendar3 me-1"></i>Tanggal / Waktu</div>
                        <div class="fw-black" style="color:#451A03;" id="dt-tanggal">—</div>
                    </div>
                    <div style="text-align:right;">
                        <div class="small fw-bold mb-1" style="color:#78350F;"><i class="bi bi-credit-card me-1"></i>Metode</div>
                        <div id="dt-badge-pay">—</div>
                    </div>
                    <div>
                        <div class="small fw-bold mb-1" style="color:#374151;"><i class="bi bi-bag me-1"></i>Jenis Produk</div>
                        <div class="fw-black" style="color:#111827;" id="dt-jml-jenis">— jenis</div>
                    </div>
                    <div style="text-align:right;">
                        <div class="small fw-bold mb-1" style="color:#374151;"><i class="bi bi-boxes me-1"></i>Total Pcs</div>
                        <div class="fw-black" style="color:#111827;" id="dt-jml-pcs">— pcs</div>
                    </div>
                </div>

                <!-- JUDUL DAFTAR -->
                <div class="mb-2 d-flex align-items-center justify-content-between">
                    <div class="fw-black" style="color:#450A0A; letter-spacing: -0.1px;">
                        <i class="bi bi-list-ul me-1" style="color:#FBBF24;"></i>Daftar Produk Dibeli
                    </div>
                </div>

                <!-- LIST ITEM -->
                <div id="dt-item-list" style="max-height: 340px; overflow-y: auto; padding-right: 4px;">
                    <div class="text-center text-muted small py-4">
                        <div class="spinner-border spinner-border-sm me-2" style="color:#991B1B;" role="status"></div>
                        Memuat detail produk...
                    </div>
                </div>

                <!-- DIVIDER -->
                <div style="height:2px; background: linear-gradient(90deg, transparent 0%, #FBBF24 20%, #991B1B 50%, #FBBF24 80%, transparent 100%); margin: 18px 0 14px 0; border-radius: 99px;"></div>

                <!-- RINGKASAN TOTAL -->
                <div style="background: linear-gradient(135deg, rgba(69,10,10,0.05), rgba(251,191,36,0.15)); border: 1.5px dashed #FBBF24; border-radius: 16px; padding: 14px 18px;">
                    <div class="d-flex justify-content-between align-items-center py-1">
                        <span style="color:#450A0A; font-weight: 800; font-size: 0.95rem;"><i class="bi bi-cash-stack me-1" style="color:#D97706;"></i>TOTAL TAGIHAN</span>
                        <span id="dt-total" style="color:#991B1B; font-weight: 900; letter-spacing: -0.3px; font-size: 1.1rem;">Rp 0</span>
                    </div>
                    <div id="dt-bayar-row" class="d-flex justify-content-between align-items-center py-1" style="margin-top: 3px;">
                        <span style="color:#374151; font-weight: 700; font-size: 0.9rem;">Uang Dibayarkan</span>
                        <span id="dt-bayar" style="color:#111827; font-weight: 800; font-size: 1rem;">Rp 0</span>
                    </div>
                    <div id="dt-kembali-row" class="d-flex justify-content-between align-items-center py-1" style="margin-top: 3px;">
                        <span style="color:#065F46; font-weight: 800; font-size: 0.9rem;"><i class="bi bi-cash-stack me-1"></i>Kembalian</span>
                        <span id="dt-kembali" style="color:#059669; font-weight: 900; font-size: 1.05rem;">Rp 0</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0" style="padding: 14px 26px 22px 26px;">
                <button type="button" class="btn" data-bs-dismiss="modal" style="background:#fff; color:#991B1B; border: 1.5px solid #991B1B; border-radius: 12px; font-weight: 700; padding: 9px 22px;">Tutup</button>
                <button id="dt-btn-cetak-resi" type="button" target="_blank" class="btn" style="background: #FFF1F2; color:#991B1B; border: 1.5px solid #991B1B; border-radius: 12px; font-weight: 700; padding: 9px 20px; display:inline-flex; align-items:center; gap:6px;"><i class="bi bi-printer-fill"></i>Cetak Resi</button>
                <button id="dt-btn-cetak-pdf" type="button" target="_blank" class="btn" style="background: linear-gradient(135deg,#7F1D1D,#991B1B); color:#FDE68A; border: 1.5px solid #FBBF24; border-radius: 12px; font-weight: 800; padding: 9px 22px; box-shadow: 0 6px 16px rgba(153,27,27,0.28); display:inline-flex; align-items:center; gap:6px;"><i class="bi bi-file-earmark-pdf-fill"></i>Cetak PDF</button>
            </div>
        </div>
    </div>
</div>

<script>
let _dtLastId = 0;
function pilihTab(pay) {
    document.getElementById('hiddenPayment').value = pay;
    document.querySelectorAll('.pay-tab').forEach(el => {
        if (el.getAttribute('data-pay') === pay) el.classList.add('active');
        else el.classList.remove('active');
    });
    document.getElementById('formFilter').submit();
}
function fmtRp(n) { return 'Rp ' + new Intl.NumberFormat('id-ID').format(parseInt(n) || 0); }
function bukaDetail(id) {
    _dtLastId = parseInt(id) || 0;
    if (!_dtLastId) return;
    const m = (window._detailModal = window._detailModal || new bootstrap.Modal(document.getElementById('detailModal')));
    document.getElementById('dt-no-struk').textContent = '—';
    document.getElementById('dt-tanggal').textContent = '—';
    document.getElementById('dt-badge-pay').innerHTML = '<span class="opacity-75">—</span>';
    document.getElementById('dt-jml-jenis').textContent = '— jenis';
    document.getElementById('dt-jml-pcs').textContent = '— pcs';
    document.getElementById('dt-total').textContent = 'Rp 0';
    document.getElementById('dt-bayar').textContent = 'Rp 0';
    document.getElementById('dt-kembali').textContent = 'Rp 0';
    document.getElementById('dt-bayar-row').style.display = '';
    document.getElementById('dt-kembali-row').style.display = '';
    document.getElementById('dt-item-list').innerHTML = '<div class="text-center text-muted small py-4"><div class="spinner-border spinner-border-sm me-2" style="color:#991B1B;" role="status"></div>Memuat detail produk...</div>';
    document.getElementById('dt-btn-cetak-resi').onclick = () => window.open('<?php echo $base_url; ?>/pages/transaksi_kasir.php?aksi=cetak_resi&id=' + _dtLastId, '_blank');
    document.getElementById('dt-btn-cetak-pdf').onclick = () => window.open('<?php echo $base_url; ?>/pages/transaksi_kasir.php?aksi=cetak_a4&id=' + _dtLastId, '_blank');
    m.show();

    const url = '<?php echo $base_url; ?>/pages/transaksi_kasir.php?aksi=detail_json&id=' + _dtLastId + '&v=' + Date.now();
    fetch(url, { cache: 'no-store', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json())
        .then(data => {
            if (!data.ok) {
                document.getElementById('dt-item-list').innerHTML = '<div class="text-center small py-4" style="color:#B91C1C; font-weight:700;"><i class="bi bi-exclamation-triangle-fill me-2"></i>Gagal memuat: ' + (data.msg || 'Unknown error') + '</div>';
                return;
            }
            document.getElementById('dt-no-struk').textContent = data.no_struk || '—';
            document.getElementById('dt-tanggal').textContent = data.tgl_str || '—';
            if ((data.payment || '') === 'QRIS') {
                document.getElementById('dt-badge-pay').innerHTML = '<span class="pay-badge-qris"><i class="bi bi-qr-code-scan"></i> QRIS</span>';
                document.getElementById('dt-bayar-row').style.display = 'none';
                document.getElementById('dt-kembali-row').style.display = 'none';
            } else {
                document.getElementById('dt-badge-pay').innerHTML = '<span class="pay-badge-cash"><i class="bi bi-cash-coin"></i> CASH</span>';
                document.getElementById('dt-bayar-row').style.display = '';
                document.getElementById('dt-kembali-row').style.display = '';
                document.getElementById('dt-bayar').textContent = fmtRp(data.bayar);
                document.getElementById('dt-kembali').textContent = fmtRp(data.kembalian);
            }
            document.getElementById('dt-jml-jenis').textContent = (data.jml_jenis || 0) + ' jenis';
            document.getElementById('dt-jml-pcs').textContent = (data.jml_pcs || 0) + ' pcs';
            document.getElementById('dt-total').textContent = fmtRp(data.total);
            const items = data.items || [];
            if (items.length === 0) {
                document.getElementById('dt-item-list').innerHTML = '<div class="text-center small py-4 text-muted"><i class="bi bi-inbox me-2"></i>Tidak ada item produk.</div>';
                return;
            }
            let html = '';
            let no = 1;
            items.forEach(it => {
                const nama = (it.nama_produk || '').toString();
                const hrg = parseInt(it.harga) || 0;
                const qty = parseInt(it.qty) || 0;
                const sub = parseInt(it.subtotal) || 0;
                html += `<div class="detail-item-row">
                    <div style="display:flex; align-items:flex-start; gap:10px; min-width:0; flex:1;">
                        <div style="width:26px; height:26px; border-radius:8px; background: linear-gradient(135deg,#450A0A,#991B1B); color:#FDE68A; display:flex; align-items:center; justify-content:center; font-weight: 900; font-size: 0.78rem; flex-shrink:0;">${no++}</div>
                        <div style="min-width:0; flex:1;">
                            <div class="detail-item-nama">${nama}</div>
                            <div class="detail-item-meta">${fmtRp(hrg)} &nbsp;×&nbsp; ${qty} pcs</div>
                        </div>
                    </div>
                    <div class="detail-item-sub">${fmtRp(sub)}</div>
                </div>`;
            });
            document.getElementById('dt-item-list').innerHTML = html;
        })
        .catch(err => {
            console.error(err);
            document.getElementById('dt-item-list').innerHTML = '<div class="text-center small py-4" style="color:#B91C1C; font-weight:700;"><i class="bi bi-exclamation-triangle-fill me-2"></i>Error koneksi. Silakan coba lagi.</div>';
        });
}
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
if (!empty($GLOBALS['alert_script'])) echo $GLOBALS['alert_script'];

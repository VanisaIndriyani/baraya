<?php

require_once __DIR__ . '/finance-utils.php';
require_once __DIR__ . '/stok-utils.php';

function asistenLower(string $teks): string
{
    $teks = trim($teks);
    return function_exists('mb_strtolower') ? mb_strtolower($teks, 'UTF-8') : strtolower($teks);
}

function asistenRapikan(string $teks): string
{
    $teks = asistenLower($teks);
    $teks = str_replace(["\n", "\r", "\t"], ' ', $teks);
    $ganti = [
        'pendapatahn' => 'pendapatan',
        'pendapatanh' => 'pendapatan',
        'pendapatah' => 'pendapatan',
        'pendapatn' => 'pendapatan',
        'pendaptan' => 'pendapatan',
        'pemasukann' => 'pemasukan',
        'pengeluarann' => 'pengeluaran',
        'pengeluran' => 'pengeluaran',
        'pengluaran' => 'pengeluaran',
        'pengluran' => 'pengeluaran',
        'pngeluaran' => 'pengeluaran',
        'berpaa' => 'berapa',
        'berpa' => 'berapa',
        'brp' => 'berapa',
        'segini' => ' ',
        'sgini' => ' ',
        'kepakai' => 'kepake',
        'terpakai' => 'kepake',
        'dipakai' => 'kepake',
    ];
    uksort($ganti, static function ($a, $b) {
        return strlen($b) <=> strlen($a);
    });
    $teks = strtr($teks, $ganti);

    $kata = ['pendapatan', 'pengeluaran', 'pemasukan', 'belanja', 'berapa', 'kemarin', 'bulan', 'kepake', 'stok', 'saldo', 'bersih'];
    foreach ($kata as $k) {
        $teks = preg_replace('/(?<=\S)(' . $k . ')/u', ' $1', $teks);
        $teks = preg_replace('/(' . $k . ')(?=\S)/u', '$1 ', $teks);
    }

    $teks = preg_replace('/\s+/', ' ', $teks);
    return trim($teks);
}

function asistenRp($angka): string
{
    return 'Rp ' . number_format((float) $angka, 0, ',', '.');
}

function asistenCariUang(string $teks): array
{
    $hasil = [];
    $pola = '/(?:rp\.?\s*)?(\d{1,3}(?:\.\d{3})+|\d{1,3}(?:,\d{3})+|\d+(?:[.,]\d{1,2})?)(?:\s*(rb|ribu|jt|juta|k))?(?![\d\/\-])/iu';
    if (!preg_match_all($pola, $teks, $cocok, PREG_OFFSET_CAPTURE)) {
        return $hasil;
    }

    foreach ($cocok[0] as $i => $penuh) {
        $mentah = str_replace(' ', '', asistenLower($cocok[1][$i][0]));
        $satuan = asistenLower($cocok[2][$i][0] ?? '');
        if (preg_match('/^\d{1,3}(\.\d{3})+$/', $mentah)) {
            $angka = (float) str_replace('.', '', $mentah);
        } elseif (preg_match('/^\d{1,3}(,\d{3})+$/', $mentah)) {
            $angka = (float) str_replace(',', '', $mentah);
        } else {
            $angka = (float) str_replace(',', '.', $mentah);
        }
        if ($satuan === 'rb' || $satuan === 'ribu' || $satuan === 'k') {
            $angka *= 1000;
        } elseif ($satuan === 'jt' || $satuan === 'juta') {
            $angka *= 1000000;
        }
        $hasil[] = [
            'nilai' => round($angka, 2),
            'awal' => $penuh[1],
            'akhir' => $penuh[1] + strlen($penuh[0]),
            'ada_satuan' => $satuan !== '',
        ];
    }

    return $hasil;
}

function asistenBersihkanCatatan(string $teks): string
{
    $teks = asistenLower($teks);
    $buang = ['hari', 'ini', 'kemarin', 'tanggal', 'dan', 'sama', 'serta', 'ya', 'yaa', 'dong', 'tolong', 'catat', 'input', 'masukin', 'masukkan', 'sebesar', 'sejumlah', 'rp', 'rupiah', 'aja', 'saja', 'tadi', 'untuk', 'buat', 'yang', 'di', 'ke', 'dari', 'asisten', 'tolongin', 'please'];
    $potong = preg_split('/\s+/', trim($teks)) ?: [];
    $sisa = [];
    foreach ($potong as $kata) {
        $kata = trim($kata, ".,");
        if ($kata === '' || in_array($kata, $buang, true)) {
            continue;
        }
        $sisa[] = $kata;
    }
    $catatan = trim(implode(' ', $sisa));
    if (function_exists('mb_convert_case')) {
        return mb_convert_case($catatan, MB_CASE_TITLE, 'UTF-8');
    }
    return ucwords($catatan);
}

function asistenTanggal(string $teks): string
{
    if (str_contains($teks, 'kemarin')) {
        return date('Y-m-d', strtotime('-1 day'));
    }
    if (preg_match('/(\d{1,2})[\/\-](\d{1,2})(?:[\/\-](\d{2,4}))?/', $teks, $m)) {
        $tahun = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : (int) date('Y');
        if ($tahun < 100) {
            $tahun += 2000;
        }
        $bulan = (int) $m[2];
        $hari = (int) $m[1];
        if (checkdate($bulan, $hari, $tahun)) {
            return sprintf('%04d-%02d-%02d', $tahun, $bulan, $hari);
        }
    }
    return date('Y-m-d');
}

function asistenScopeBulan(string $teks): bool
{
    return str_contains($teks, 'bulan');
}

function asistenAmbilAngka(PDO $pdo, string $tanggal): array
{
    $bulan = date('Y-m', strtotime($tanggal));
    $ambil = static function (PDO $pdo, string $sql, array $params): float {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return (float) $stmt->fetchColumn();
        } catch (Throwable $e) {
            return 0.0;
        }
    };

    return [
        'pendapatan' => $ambil($pdo, 'SELECT COALESCE(SUM(total_pendapatan),0) FROM penjualan WHERE tanggal = ?', [$tanggal]),
        'pengeluaran' => $ambil($pdo, 'SELECT COALESCE(SUM(jumlah),0) FROM pengeluaran WHERE tanggal = ?', [$tanggal]),
        'pembelian' => $ambil($pdo, 'SELECT COALESCE(SUM(total),0) FROM pembelian WHERE tanggal = ?', [$tanggal]),
        'beli_harian' => $ambil($pdo, 'SELECT COALESCE(SUM(subtotal),0) FROM beli_harian WHERE tanggal = ?', [$tanggal]),
        'pendapatan_bulan' => $ambil($pdo, "SELECT COALESCE(SUM(total_pendapatan),0) FROM penjualan WHERE DATE_FORMAT(tanggal, '%Y-%m') = ?", [$bulan]),
        'pengeluaran_bulan' => $ambil($pdo, "SELECT COALESCE(SUM(jumlah),0) FROM pengeluaran WHERE DATE_FORMAT(tanggal, '%Y-%m') = ?", [$bulan]),
        'pembelian_bulan' => $ambil($pdo, "SELECT COALESCE(SUM(total),0) FROM pembelian WHERE DATE_FORMAT(tanggal, '%Y-%m') = ?", [$bulan]),
        'beli_harian_bulan' => $ambil($pdo, "SELECT COALESCE(SUM(subtotal),0) FROM beli_harian WHERE DATE_FORMAT(tanggal, '%Y-%m') = ?", [$bulan]),
        'kasir' => $ambil($pdo, 'SELECT COALESCE(SUM(total),0) FROM kasir_transaksi WHERE DATE(tgl) = ?', [$tanggal]),
        'kasir_jumlah' => $ambil($pdo, 'SELECT COUNT(*) FROM kasir_transaksi WHERE DATE(tgl) = ?', [$tanggal]),
        'kasir_cash' => $ambil($pdo, "SELECT COALESCE(SUM(total),0) FROM kasir_transaksi WHERE DATE(tgl) = ? AND payment = 'CASH'", [$tanggal]),
        'kasir_qris' => $ambil($pdo, "SELECT COALESCE(SUM(total),0) FROM kasir_transaksi WHERE DATE(tgl) = ? AND payment = 'QRIS'", [$tanggal]),
        'kasir_bulan' => $ambil($pdo, "SELECT COALESCE(SUM(total),0) FROM kasir_transaksi WHERE DATE_FORMAT(tgl, '%Y-%m') = ?", [$bulan]),
        'kasir_jumlah_bulan' => $ambil($pdo, "SELECT COUNT(*) FROM kasir_transaksi WHERE DATE_FORMAT(tgl, '%Y-%m') = ?", [$bulan]),
    ];
}

function asistenDetailKasir(PDO $pdo, string $tanggal): array
{
    $kosong = ['struk' => [], 'produk' => []];
    try {
        $stmt = $pdo->prepare('SELECT id, no_struk, tgl, payment, total FROM kasir_transaksi WHERE DATE(tgl) = ? ORDER BY tgl DESC LIMIT 10');
        $stmt->execute([$tanggal]);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        return $kosong;
    }
    if (!$rows) {
        return $kosong;
    }

    $perId = [];
    try {
        $ids = array_map(static function ($row) {
            return (int) $row['id'];
        }, $rows);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $itemStmt = $pdo->prepare("SELECT id_transaksi, nama_produk, qty FROM kasir_transaksi_item WHERE id_transaksi IN ($in) ORDER BY id ASC");
        $itemStmt->execute($ids);
        foreach ($itemStmt->fetchAll() as $it) {
            $perId[(int) $it['id_transaksi']][] = $it['nama_produk'] . ' x' . (int) $it['qty'];
        }
    } catch (Throwable $e) {
        $perId = [];
    }

    $struk = [];
    foreach ($rows as $row) {
        $struk[] = [
            'no_struk' => $row['no_struk'],
            'jam' => date('H:i', strtotime($row['tgl'])),
            'bayar' => $row['payment'],
            'total' => (int) $row['total'],
            'item' => implode(', ', array_slice($perId[(int) $row['id']] ?? [], 0, 6)),
        ];
    }

    $produk = [];
    try {
        $stmt = $pdo->prepare("SELECT i.nama_produk, SUM(i.qty) AS qty, SUM(i.subtotal) AS subtotal
            FROM kasir_transaksi_item i
            JOIN kasir_transaksi t ON t.id = i.id_transaksi
            WHERE DATE(t.tgl) = ?
            GROUP BY i.nama_produk
            ORDER BY qty DESC
            LIMIT 12");
        $stmt->execute([$tanggal]);
        foreach ($stmt->fetchAll() as $p) {
            $produk[] = $p['nama_produk'] . ' x' . (int) $p['qty'] . ' (' . asistenRp($p['subtotal']) . ')';
        }
    } catch (Throwable $e) {
        $produk = [];
    }

    return ['struk' => $struk, 'produk' => $produk];
}

function asistenTeksKasir(array $angka, string $tanggal, bool $bulan): string
{
    if ($bulan) {
        return 'Omzet kasir bulan ini: ' . asistenRp($angka['kasir_bulan'] ?? 0)
            . ' dari ' . (int) ($angka['kasir_jumlah_bulan'] ?? 0) . ' struk.';
    }
    $label = $tanggal === date('Y-m-d') ? 'hari ini' : date('d/m/Y', strtotime($tanggal));
    return "Omzet kasir $label: " . asistenRp($angka['kasir'] ?? 0)
        . ' dari ' . (int) ($angka['kasir_jumlah'] ?? 0) . " struk.\n"
        . 'Cash: ' . asistenRp($angka['kasir_cash'] ?? 0) . "\n"
        . 'QRIS: ' . asistenRp($angka['kasir_qris'] ?? 0);
}

function asistenTeksRingkas(array $angka, string $tanggal, bool $bulan): string
{
    if ($bulan) {
        $keluar = $angka['pengeluaran_bulan'] + $angka['pembelian_bulan'] + $angka['beli_harian_bulan'];
        $bersih = ($angka['pendapatan_bulan'] + ($angka['kasir_bulan'] ?? 0)) - $keluar;
        return "Ringkasan " . date('F Y', strtotime($tanggal)) . ":\n"
            . "Pendapatan: " . asistenRp($angka['pendapatan_bulan']) . "\n"
            . "Pengeluaran: " . asistenRp($angka['pengeluaran_bulan']) . "\n"
            . "Beli bahan: " . asistenRp($angka['pembelian_bulan']) . "\n"
            . "Beli harian: " . asistenRp($angka['beli_harian_bulan']) . "\n"
            . "Omzet kasir: " . asistenRp($angka['kasir_bulan'] ?? 0) . ' (' . (int) ($angka['kasir_jumlah_bulan'] ?? 0) . " struk)\n"
            . "Bersih: " . asistenRp($bersih);
    }

    $label = $tanggal === date('Y-m-d') ? 'hari ini' : date('d/m/Y', strtotime($tanggal));
    $keluar = $angka['pengeluaran'] + $angka['pembelian'] + $angka['beli_harian'];
    $bersih = ($angka['pendapatan'] + ($angka['kasir'] ?? 0)) - $keluar;
    return "Ringkasan $label:\n"
        . "Pendapatan: " . asistenRp($angka['pendapatan']) . "\n"
        . "Pengeluaran: " . asistenRp($angka['pengeluaran']) . "\n"
        . "Beli bahan: " . asistenRp($angka['pembelian']) . "\n"
        . "Beli harian: " . asistenRp($angka['beli_harian']) . "\n"
        . "Omzet kasir: " . asistenRp($angka['kasir'] ?? 0) . ' (' . (int) ($angka['kasir_jumlah'] ?? 0) . " struk, cash " . asistenRp($angka['kasir_cash'] ?? 0) . ', QRIS ' . asistenRp($angka['kasir_qris'] ?? 0) . ")\n"
        . "Total keluar: " . asistenRp($keluar) . "\n"
        . "Bersih: " . asistenRp($bersih);
}

function asistenTeksSaldo(PDO $pdo): string
{
    financeEnsureAccountSystem($pdo);
    $baris = [];
    foreach (['operasional' => 'Kas operasional', 'penjualan' => 'Rekening penjualan', 'uang_ruko' => 'Uang ruko'] as $kode => $label) {
        $rek = financeGetAccountByCode($pdo, $kode);
        if ($rek) {
            $baris[] = $label . ': ' . asistenRp($rek['saldo'] ?? 0);
        }
    }
    return $baris ? implode("\n", $baris) : 'Saldo rekening belum ada.';
}

function asistenTeksStok(PDO $pdo, string $teks): string
{
    try {
        $items = stokAmbilDaftar($pdo, date('Y-m-d'));
    } catch (Throwable $e) {
        return 'Data stok belum bisa dibaca.';
    }
    if (!$items) {
        return 'Stok masih kosong. Tambah dulu di menu Stok Barang.';
    }

    $cocok = asistenCariItemStok($items, $teks);
    if ($cocok) {
        return $cocok['nama'] . " sisa " . stokFormatJumlah($cocok['sisa']) . ' ' . $cocok['satuan']
            . ". Hari ini kepake " . stokFormatJumlah($cocok['pakai_tanggal']) . '.';
    }

    $baris = ["Sisa stok:"];
    foreach ($items as $item) {
        $baris[] = $item['nama'] . ': ' . stokFormatJumlah($item['sisa']) . ' ' . $item['satuan'];
    }
    return implode("\n", $baris);
}

function asistenCariItemStok(array $items, string $teks): ?array
{
    $terbaik = null;
    $skorTerbaik = 0;
    foreach ($items as $item) {
        $nama = asistenRapikan($item['nama']);
        if ($nama !== '' && str_contains($teks, $nama)) {
            return $item;
        }
        $skor = 0;
        foreach (preg_split('/\s+/', $nama) as $kata) {
            if (strlen($kata) >= 2 && str_contains($teks, $kata)) {
                $skor++;
            }
        }
        if ($skor > $skorTerbaik) {
            $skorTerbaik = $skor;
            $terbaik = $item;
        }
    }
    return $skorTerbaik >= 2 ? $terbaik : null;
}

function asistenPerintahUang(string $teks): array
{
    $perintah = [];
    $pola = '/\b(pendapatan|pemasukan|masuk|pengeluaran|belanja|keluar)\b/u';
    if (!preg_match_all($pola, $teks, $ketemu, PREG_OFFSET_CAPTURE)) {
        return $perintah;
    }

    $jumlah = count($ketemu[0]);
    for ($i = 0; $i < $jumlah; $i++) {
        $kata = $ketemu[1][$i][0];
        $awal = $ketemu[0][$i][1] + strlen($ketemu[0][$i][0]);
        $akhir = $i + 1 < $jumlah ? $ketemu[0][$i + 1][1] : strlen($teks);
        $isi = trim(substr($teks, $awal, $akhir - $awal));
        $uang = asistenCariUang($isi);
        if (!$uang) {
            continue;
        }
        $jenis = in_array($kata, ['pengeluaran', 'belanja', 'keluar'], true) ? 'pengeluaran' : 'pendapatan';
        $cursor = 0;
        $catatanTerakhir = null;
        foreach ($uang as $idx => $u) {
            $sebelum = substr($isi, $cursor, $u['awal'] - $cursor);
            $cursor = $u['akhir'];
            $catatan = asistenBersihkanCatatan($sebelum);
            if ($catatan === '' && $idx === count($uang) - 1) {
                $catatan = asistenBersihkanCatatan(substr($isi, $cursor));
            }
            if ($u['nilai'] <= 0) {
                continue;
            }
            if ($u['nilai'] < 1000 && !$u['ada_satuan']) {
                $perintah[] = [
                    'jenis' => 'klarifikasi',
                    'nilai' => $u['nilai'],
                    'catatan' => $catatan,
                ];
                continue;
            }
            $perintah[] = [
                'jenis' => $jenis,
                'nilai' => $u['nilai'],
                'catatan' => $catatan !== '' ? $catatan : 'Dari asisten',
            ];
            $catatanTerakhir = $catatan;
        }
        unset($catatanTerakhir);
    }

    return $perintah;
}

function asistenSimpanUang(PDO $pdo, array $perintah, string $tanggal): array
{
    $idsPenjualan = [];
    $idsPengeluaran = [];
    $baris = [];
    financeEnsureAccountSystem($pdo);
    financeEnsureBiayaWajibTable($pdo);
    $pdo->beginTransaction();
    try {
        foreach ($perintah as $item) {
            if ($item['jenis'] === 'pendapatan') {
                $stmt = $pdo->prepare("INSERT INTO penjualan (id_periode, total_pendapatan, catatan, foto, tanggal) VALUES (NULL, ?, ?, NULL, ?)");
                $stmt->execute([$item['nilai'], $item['catatan'], $tanggal]);
                $idsPenjualan[] = (int) $pdo->lastInsertId();
                $baris[] = 'Pendapatan ' . asistenRp($item['nilai']) . ' masuk.';
            }
            if ($item['jenis'] === 'pengeluaran') {
                $kategori = function_exists('mb_substr') ? mb_substr($item['catatan'], 0, 50, 'UTF-8') : substr($item['catatan'], 0, 50);
                $stmt = $pdo->prepare("INSERT INTO pengeluaran (id_periode, kategori, jumlah, keterangan, tanggal) VALUES (NULL, ?, ?, ?, ?)");
                $stmt->execute([$kategori, $item['nilai'], $item['catatan'], $tanggal]);
                $idPengeluaran = (int) $pdo->lastInsertId();
                $idsPengeluaran[] = $idPengeluaran;

                $rek = financeGetAccountByCode($pdo, 'operasional');
                if ($rek && financeColumnExists($pdo, 'saldo_rekening_transaksi', 'id_pengeluaran')) {
                    financeInsertSaldoTransaksi($pdo, $rek['id'], null, 'kredit', $item['nilai'], $item['catatan'], $tanggal, null, $idPengeluaran);
                    financeAdjustSaldoRekening($pdo, $rek['id'], -1 * (float) $item['nilai']);
                    $baris[] = 'Pengeluaran ' . $item['catatan'] . ' ' . asistenRp($item['nilai']) . ' masuk, kas operasional berkurang.';
                } else {
                    $baris[] = 'Pengeluaran ' . $item['catatan'] . ' ' . asistenRp($item['nilai']) . ' masuk.';
                }
            }
        }

        if ($idsPenjualan) {
            financeRebuildPenjualanAllocationsByDate($pdo, $tanggal);
            $baris[] = 'Uang penjualan ikut dibagi ke kas operasional, uang ruko, dan rekening penjualan.';
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return [
        'penjualan' => $idsPenjualan,
        'pengeluaran' => $idsPengeluaran,
        'baris' => $baris,
    ];
}

function asistenSimpanStok(PDO $pdo, string $teks, string $tanggal): ?string
{
    if (!preg_match('/\b(kepake|pakai)\b/', $teks) && !str_contains($teks, 'tambah stok')) {
        return null;
    }
    try {
        $items = stokAmbilDaftar($pdo, $tanggal);
    } catch (Throwable $e) {
        return 'Stok belum siap. Buka menu Stok Barang dulu.';
    }
    $item = asistenCariItemStok($items, $teks);
    if (!$item) {
        return 'Sebut nama barangnya, contoh: cup 22 oz kepake 5.';
    }

    $sisaTeks = str_replace(asistenRapikan($item['nama']), ' ', $teks);
    if (str_contains($teks, 'tambah stok')) {
        $uang = asistenCariUang($sisaTeks);
        $qty = $uang[0]['nilai'] ?? 0;
        if ($qty <= 0) {
            return 'Tambah stok ' . $item['nama'] . ' berapa pcs?';
        }
        $stmt = $pdo->prepare("INSERT INTO stok_mutasi (id_item, tanggal, tipe, qty, catatan) VALUES (?, ?, 'tambah', ?, 'Dari asisten')");
        $stmt->execute([(int) $item['id'], $tanggal, $qty]);
        $baru = stokAmbilDaftar($pdo, $tanggal);
        $sekarang = asistenCariItemStok($baru, asistenRapikan($item['nama']));
        return $item['nama'] . ' ditambah ' . stokFormatJumlah($qty) . ' ' . $item['satuan']
            . '. Sisa sekarang ' . stokFormatJumlah($sekarang['sisa'] ?? 0) . '.';
    }

    if (!preg_match('/(?:kepake|pakai)\s+(\d+(?:[.,]\d+)?)/', $sisaTeks, $m)) {
        return $item['nama'] . ' kepake berapa?';
    }
    $qty = (float) str_replace(',', '.', $m[1]);
    if ($qty < 0) {
        return 'Jumlah pakai tidak boleh minus.';
    }

    $cari = $pdo->prepare("SELECT id FROM stok_mutasi WHERE id_item = ? AND tanggal = ? AND tipe = 'pakai' LIMIT 1");
    $cari->execute([(int) $item['id'], $tanggal]);
    $lama = $cari->fetch();
    if ($qty == 0.0 && $lama) {
        $pdo->prepare('DELETE FROM stok_mutasi WHERE id = ?')->execute([(int) $lama['id']]);
    } elseif ($lama) {
        $pdo->prepare('UPDATE stok_mutasi SET qty = ?, catatan = ? WHERE id = ?')->execute([$qty, 'Dari asisten', (int) $lama['id']]);
    } elseif ($qty > 0) {
        $pdo->prepare("INSERT INTO stok_mutasi (id_item, tanggal, tipe, qty, catatan) VALUES (?, ?, 'pakai', ?, 'Dari asisten')")->execute([(int) $item['id'], $tanggal, $qty]);
    }

    $baru = stokAmbilDaftar($pdo, $tanggal);
    $sekarang = asistenCariItemStok($baru, asistenRapikan($item['nama']));
    return $item['nama'] . ' hari ini kepake ' . stokFormatJumlah($qty) . ' ' . $item['satuan']
        . '. Sisa ' . stokFormatJumlah($sekarang['sisa'] ?? 0) . ' ' . $item['satuan'] . '.';
}

function asistenBantuan(): string
{
    return "Ketik biasa, nanti langsung dijawab atau dicatat.\n\n"
        . "Tanya:\n"
        . "• pendapatan hari ini berapa\n"
        . "• pengeluaran hari ini berapa\n"
        . "• bersih bulan ini\n"
        . "• saldo kas\n"
        . "• sisa stok\n\n"
        . "Catat sekaligus:\n"
        . "• pendapatan 500rb pengeluaran 30rb\n"
        . "• pengeluaran es batu 10rb\n"
        . "• cup 22 oz kepake 5";
}

function asistenJawabLokal(PDO $pdo, string $pesanMentah): array
{
    $pesanMentah = trim($pesanMentah);
    if ($pesanMentah === '') {
        return ['teks' => asistenBantuan(), 'simpan' => false];
    }
    if (function_exists('mb_strlen') && mb_strlen($pesanMentah) > 500) {
        return ['teks' => 'Kalimatnya kepanjangan. Coba dipotong jadi satu catatan.', 'simpan' => false];
    }

    $teks = asistenRapikan($pesanMentah);
    $tanggal = asistenTanggal($teks);
    $bulan = asistenScopeBulan($teks);
    $perintah = asistenPerintahUang($teks);
    $klarifikasi = array_filter($perintah, static function ($item) {
        return $item['jenis'] === 'klarifikasi';
    });
    $simpanUang = array_values(array_filter($perintah, static function ($item) {
        return $item['jenis'] === 'pendapatan' || $item['jenis'] === 'pengeluaran';
    }));

    if ($klarifikasi && !$simpanUang) {
        $contoh = (int) $klarifikasi[array_key_first($klarifikasi)]['nilai'];
        return [
            'teks' => $contoh . " itu rupiah atau ribu? Tulis {$contoh}rb atau " . ($contoh * 1000) . '.',
            'simpan' => false,
        ];
    }

    $bagian = [];
    $sudahSimpan = false;

    if ($simpanUang) {
        $hasil = asistenSimpanUang($pdo, $simpanUang, $tanggal);
        $bagian[] = "Sudah dicatat untuk " . date('d/m/Y', strtotime($tanggal)) . '.';
        $bagian = array_merge($bagian, $hasil['baris']);
        $sudahSimpan = true;
    }

    $teksStok = asistenSimpanStok($pdo, $teks, $tanggal);
    if ($teksStok !== null && (str_contains($teks, 'kepake') || str_contains($teks, 'pakai') || str_contains($teks, 'tambah stok'))) {
        $bagian[] = $teksStok;
        if (!str_contains($teksStok, 'berapa') && !str_contains($teksStok, 'Sebut nama')) {
            $sudahSimpan = true;
        }
    }

    $tanya = str_contains($teks, 'berapa') || str_contains($teks, 'total') || str_contains($teks, 'cek') || str_contains($teks, 'lihat') || str_contains($teks, 'sisa') || str_contains($teks, 'ringkas');
    $angka = asistenAmbilAngka($pdo, $tanggal);

    if (!$simpanUang && !$sudahSimpan) {
        $soalKasir = str_contains($teks, 'kasir') || str_contains($teks, 'struk') || str_contains($teks, 'qris');
        if ($soalKasir) {
            $bagian[] = asistenTeksKasir($angka, $tanggal, $bulan);
        }
        if (str_contains($teks, 'saldo') || str_contains($teks, 'rekening') || (preg_match('/\bkas\b/', $teks) && !$soalKasir)) {
            $bagian[] = asistenTeksSaldo($pdo);
        }
        if (str_contains($teks, 'stok') || (str_contains($teks, 'sisa') && !str_contains($teks, 'bersih'))) {
            $bagian[] = asistenTeksStok($pdo, $teks);
        }
        if (str_contains($teks, 'bersih') || str_contains($teks, 'laba') || str_contains($teks, 'untung') || ($tanya && !str_contains($teks, 'stok') && !str_contains($teks, 'saldo') && !str_contains($teks, 'pendapatan') && !str_contains($teks, 'pengeluaran') && !str_contains($teks, 'pemasukan'))) {
            $bagian[] = asistenTeksRingkas($angka, $tanggal, $bulan);
        } else {
            if (str_contains($teks, 'pendapatan') || str_contains($teks, 'pemasukan') || str_contains($teks, 'masuk')) {
                $nilai = $bulan ? $angka['pendapatan_bulan'] : $angka['pendapatan'];
                $label = $bulan ? 'bulan ini' : ($tanggal === date('Y-m-d') ? 'hari ini' : date('d/m/Y', strtotime($tanggal)));
                $omzetKasir = $bulan ? ($angka['kasir_bulan'] ?? 0) : ($angka['kasir'] ?? 0);
                $jumlahStruk = $bulan ? (int) ($angka['kasir_jumlah_bulan'] ?? 0) : (int) ($angka['kasir_jumlah'] ?? 0);
                $bagian[] = "Catatan penjualan $label: " . asistenRp($nilai) . ".\n"
                    . "Omzet kasir $label: " . asistenRp($omzetKasir) . " dari $jumlahStruk struk.\n"
                    . 'Total gabungan: ' . asistenRp($nilai + $omzetKasir) . '.';
            }
            if (str_contains($teks, 'pengeluaran') || str_contains($teks, 'belanja') || (str_contains($teks, 'keluar') && !str_contains($teks, 'kepake'))) {
                if ($bulan) {
                    $bagian[] = "Pengeluaran bulan ini: " . asistenRp($angka['pengeluaran_bulan'])
                        . "\nBeli bahan: " . asistenRp($angka['pembelian_bulan'])
                        . "\nBeli harian: " . asistenRp($angka['beli_harian_bulan']) . '.';
                } else {
                    $label = $tanggal === date('Y-m-d') ? 'hari ini' : date('d/m/Y', strtotime($tanggal));
                    $bagian[] = "Pengeluaran $label: " . asistenRp($angka['pengeluaran'])
                        . "\nBeli bahan: " . asistenRp($angka['pembelian'])
                        . "\nBeli harian: " . asistenRp($angka['beli_harian']) . '.';
                }
            }
        }
    } elseif ($sudahSimpan) {
        $angka = asistenAmbilAngka($pdo, $tanggal);
        $bagian[] = asistenTeksRingkas($angka, $tanggal, false);
    }

    if (!$bagian) {
        $bagian[] = asistenBantuan();
    }

    return [
        'teks' => implode("\n\n", $bagian),
        'simpan' => $sudahSimpan,
    ];
}

function asistenMuatEnv(): void
{
    static $sudah = false;
    if ($sudah) {
        return;
    }
    $sudah = true;
    $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
    if (!is_file($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value, " \t\"'");
        if ($name === '') {
            continue;
        }
        $existing = getenv($name);
        if ($existing === false || $existing === '') {
            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
        }
    }
}

function asistenEnv(string $name, string $default = ''): string
{
    asistenMuatEnv();
    $v = getenv($name);
    if ($v === false || $v === '') {
        $v = $_ENV[$name] ?? $default;
    }
    return is_string($v) ? trim($v) : $default;
}

function asistenDataAi(PDO $pdo): array
{
    $hari = date('Y-m-d');
    $kemarin = date('Y-m-d', strtotime('-1 day'));
    $angka = asistenAmbilAngka($pdo, $hari);
    $angkaKemarin = asistenAmbilAngka($pdo, $kemarin);
    $kasirHari = asistenDetailKasir($pdo, $hari);
    $stok = [];
    try {
        foreach (array_slice(stokAmbilDaftar($pdo, $hari), 0, 20) as $item) {
            $stok[] = $item['nama'] . ' sisa ' . stokFormatJumlah($item['sisa']) . ' ' . $item['satuan'];
        }
    } catch (Throwable $e) {
        $stok = [];
    }
    return [
        'hari_ini' => $hari,
        'bulan' => date('Y-m'),
        'catatan_penjualan_manual_hari_ini' => (float) $angka['pendapatan'],
        'omzet_kasir_hari_ini' => (float) ($angka['kasir'] ?? 0),
        'jumlah_struk_kasir_hari_ini' => (int) ($angka['kasir_jumlah'] ?? 0),
        'kasir_cash_hari_ini' => (float) ($angka['kasir_cash'] ?? 0),
        'kasir_qris_hari_ini' => (float) ($angka['kasir_qris'] ?? 0),
        'omzet_kasir_kemarin' => (float) ($angkaKemarin['kasir'] ?? 0),
        'jumlah_struk_kasir_kemarin' => (int) ($angkaKemarin['kasir_jumlah'] ?? 0),
        'struk_kasir_hari_ini' => $kasirHari['struk'],
        'produk_laku_kasir_hari_ini' => $kasirHari['produk'],
        'pendapatan_hari_ini' => (float) $angka['pendapatan'],
        'pengeluaran_catatan_hari_ini' => (float) $angka['pengeluaran'],
        'beli_bahan_hari_ini' => (float) $angka['pembelian'],
        'beli_harian_hari_ini' => (float) $angka['beli_harian'],
        'catatan_penjualan_manual_bulan_ini' => (float) $angka['pendapatan_bulan'],
        'omzet_kasir_bulan_ini' => (float) ($angka['kasir_bulan'] ?? 0),
        'jumlah_struk_kasir_bulan_ini' => (int) ($angka['kasir_jumlah_bulan'] ?? 0),
        'pendapatan_bulan_ini' => (float) $angka['pendapatan_bulan'],
        'pengeluaran_catatan_bulan_ini' => (float) $angka['pengeluaran_bulan'],
        'beli_bahan_bulan_ini' => (float) $angka['pembelian_bulan'],
        'beli_harian_bulan_ini' => (float) $angka['beli_harian_bulan'],
        'saldo' => asistenTeksSaldo($pdo),
        'stok' => $stok,
    ];
}

function asistenCariCa(): ?string
{
    foreach ([ini_get('curl.cainfo'), ini_get('openssl.cafile')] as $path) {
        if (is_string($path) && $path !== '' && is_file($path)) {
            return $path;
        }
    }
    $calon = [
        'D:\\APLIKASI\\laragon\\etc\\ssl\\cacert.pem',
        'D:\\APLIKASI\\laragon\\bin\\php\\cacert.pem',
        dirname(__DIR__, 3) . '\\SEPTEMBER\\Travel\\config\\cacert.pem',
    ];
    foreach ($calon as $path) {
        if (is_file($path)) {
            return $path;
        }
    }
    return null;
}

function asistenHttpJson(string $url, array $payload, string $key): ?string
{
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($body === false || !function_exists('curl_init')) {
        return null;
    }
    $ch = curl_init($url);
    if ($ch === false) {
        return null;
    }
    $opts = [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $key,
        ],
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 40,
        CURLOPT_CONNECTTIMEOUT => 12,
    ];
    $ca = asistenCariCa();
    if ($ca) {
        $opts[CURLOPT_CAINFO] = $ca;
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($errno || $raw === false || $http >= 400 || $http === 0) {
        return null;
    }
    return $raw;
}

function asistenAiBalas(PDO $pdo, string $pesan, array $riwayat): ?array
{
    $key = asistenEnv('OPENAI_API_KEY', '');
    if ($key === '') {
        $key = asistenEnv('AI_API_KEY', '');
    }
    if ($key === '') {
        return null;
    }

    $data = asistenDataAi($pdo);
    $sys = "Kamu Asisten Baraya untuk warung dawet. Jawab bahasa Indonesia, singkat dan ramah seperti chat.\n"
        . "Balas HANYA JSON dengan field: aksi (tanya atau simpan), jawaban, tanggal (YYYY-MM-DD), pendapatan (angka atau null), pengeluaran (angka atau null), catatan (string).\n"
        . "Aturan:\n"
        . "- Angka uang hanya dari DATA. Jangan mengarang saldo, stok, atau omzet.\n"
        . "- 500rb = 500000, 1jt = 1000000, 30 ribu = 30000.\n"
        . "- Jika user minta mencatat pendapatan atau pengeluaran, aksi=simpan dan isi nominal rupiah penuh.\n"
        . "- Nominal di bawah 1000 tanpa satuan rb/ribu/jt jangan disimpan. aksi=tanya, tanyakan rupiah atau ribu.\n"
        . "- tanggal default hari_ini. kemarin = hari_ini dikurangi 1 hari.\n"
        . "- Untuk pertanyaan, aksi=tanya. Sebut angka dari DATA.\n"
        . "- Transaksi Kasir (halaman transaksi kasir, tabel kasir_transaksi) TERPISAH dari catatan penjualan manual.\n"
        . "- omzet_kasir, jumlah struk, cash, QRIS, struk_kasir_hari_ini, dan produk_laku_kasir_hari_ini itu data struk kasir. Jangan bilang tidak ada data kasir kalau field itu ada.\n"
        . "- Kalau ditanya transaksi kasir, struk, QRIS, cash kasir, atau menu yang laku, jawab dari field kasir saja.\n"
        . "- Kalau ditanya pendapatan tanpa disebut kasir, sebut catatan penjualan manual, omzet kasir, dan total gabungan keduanya.\n"
        . "- Bersih = (catatan penjualan manual + omzet kasir) - pengeluaran catatan - beli bahan - beli harian.\n"
        . "- Pengeluaran catatan, beli bahan, dan beli harian itu beda. Kalau ditanya pengeluaran, sebut ketiganya.\n"
        . "- Jangan bilang sudah dicatat kalau aksi=tanya.\n"
        . "DATA:\n" . json_encode($data, JSON_UNESCAPED_UNICODE);

    $messages = [['role' => 'system', 'content' => $sys]];
    foreach (array_slice($riwayat, -6) as $item) {
        if (!is_array($item)) {
            continue;
        }
        $role = ($item['dari'] ?? '') === 'user' ? 'user' : 'assistant';
        $teks = trim((string) ($item['teks'] ?? ''));
        if ($teks === '') {
            continue;
        }
        $messages[] = [
            'role' => $role,
            'content' => function_exists('mb_substr') ? mb_substr($teks, 0, 400, 'UTF-8') : substr($teks, 0, 400),
        ];
    }
    $messages[] = ['role' => 'user', 'content' => $pesan];

    $model = asistenEnv('AI_MODEL', 'gpt-4o-mini');
    if ($model === '') {
        $model = 'gpt-4o-mini';
    }
    $base = rtrim(asistenEnv('AI_BASE_URL', 'https://api.openai.com/v1'), '/');
    $raw = asistenHttpJson($base . '/chat/completions', [
        'model' => $model,
        'temperature' => 0.2,
        'max_tokens' => 400,
        'response_format' => ['type' => 'json_object'],
        'messages' => $messages,
    ], $key);
    if ($raw === null) {
        return null;
    }
    $json = json_decode($raw, true);
    $content = $json['choices'][0]['message']['content'] ?? '';
    $parsed = json_decode(is_string($content) ? $content : '', true);
    if (!is_array($parsed)) {
        return null;
    }

    $aksi = (string) ($parsed['aksi'] ?? 'tanya');
    $jawaban = trim((string) ($parsed['jawaban'] ?? ''));
    $tanggal = (string) ($parsed['tanggal'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
        $tanggal = date('Y-m-d');
    }

    if ($aksi === 'simpan') {
        $perintah = [];
        $pendapatan = (float) ($parsed['pendapatan'] ?? 0);
        $pengeluaran = (float) ($parsed['pengeluaran'] ?? 0);
        $catatan = trim((string) ($parsed['catatan'] ?? ''));
        if ($catatan === '') {
            $catatan = 'Dari asisten';
        }
        if ($pendapatan >= 1000) {
            $perintah[] = ['jenis' => 'pendapatan', 'nilai' => $pendapatan, 'catatan' => 'Dari asisten'];
        }
        if ($pengeluaran >= 1000) {
            $perintah[] = ['jenis' => 'pengeluaran', 'nilai' => $pengeluaran, 'catatan' => $catatan];
        }
        if (!$perintah) {
            return [
                'teks' => $jawaban !== '' ? $jawaban : 'Nominalnya kurang jelas. Tulis misalnya 30rb.',
                'simpan' => false,
            ];
        }
        $hasil = asistenSimpanUang($pdo, $perintah, $tanggal);
        $baris = ['Sudah dicatat untuk ' . date('d/m/Y', strtotime($tanggal)) . '.'];
        $baris = array_merge($baris, $hasil['baris']);
        $baris[] = asistenTeksRingkas(asistenAmbilAngka($pdo, $tanggal), $tanggal, false);
        return ['teks' => implode("\n\n", $baris), 'simpan' => true];
    }

    if ($jawaban === '') {
        return null;
    }
    return ['teks' => $jawaban, 'simpan' => false];
}

function asistenJawab(PDO $pdo, string $pesanMentah, array $riwayat = []): array
{
    $pesanMentah = trim($pesanMentah);
    if ($pesanMentah === '') {
        return ['teks' => asistenBantuan(), 'simpan' => false];
    }

    $lokal = asistenJawabLokal($pdo, $pesanMentah);
    if (!empty($lokal['simpan'])) {
        return $lokal;
    }

    $ai = asistenAiBalas($pdo, $pesanMentah, $riwayat);
    if ($ai) {
        return $ai;
    }
    return $lokal;
}

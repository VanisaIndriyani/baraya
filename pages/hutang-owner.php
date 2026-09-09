<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once '../includes/header.php';
require_once '../includes/finance-utils.php';

$redirect_url = $base_url . '/pages/hutang-owner.php';

function tampilkanAlertHutang($icon, $title, $text, $redirect = null, $timer = 1500)
{
    $redirect_script = $redirect
        ? ".then(() => window.location.href = '" . addslashes($redirect) . "')"
        : '';

    echo "<script>
        Swal.fire({
            icon: '" . addslashes($icon) . "',
            title: '" . addslashes($title) . "',
            text: '" . addslashes($text) . "',
            timer: " . (int) $timer . "
        })" . $redirect_script . ";
    </script>";
}

function labelTipeHutang($tipe)
{
    if ($tipe === 'kasir') {
        return 'Hutang Kasir';
    }
    if ($tipe === 'owner_vanisa') {
        return 'Hutang Owner Vanisa';
    }
    if ($tipe === 'owner_dimas') {
        return 'Hutang Owner Dimas';
    }
    return 'Hutang Owner';
}

function badgeTipeHutang($tipe)
{
    return $tipe === 'kasir' ? 'warning' : 'primary';
}

function modalKeyTipeHutang($tipe)
{
    return preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $tipe);
}

function effectFloatHutang($tipe_hutang, $jumlah, $dibayar)
{
    return $tipe_hutang === 'kasir' ? (-1 * (float) $jumlah) + (float) $dibayar : 0;
}

function buatRiwayatFloatKasir($pdo, $delta)
{
    if ((float) $delta === 0.0) {
        return;
    }

    $float = $pdo->query("SELECT * FROM float_kasir ORDER BY id DESC LIMIT 1")->fetch();
    $saldo_awal = $float ? (float) $float['saldo_sekarang'] : 0;
    $saldo_baru = $saldo_awal + (float) $delta;

    $stmt = $pdo->prepare("INSERT INTO float_kasir (saldo_awal, saldo_sekarang) VALUES (?, ?)");
    $stmt->execute([$saldo_awal, $saldo_baru]);
}

function ambilHutangById($pdo, $id_hutang)
{
    $stmt = $pdo->prepare("
        SELECT h.*, COALESCE(SUM(p.jumlah), 0) AS dibayar, (h.jumlah - COALESCE(SUM(p.jumlah), 0)) AS sisa
        FROM hutang_owner h
        LEFT JOIN hutang_owner_pembayaran p ON h.id = p.id_hutang
        WHERE h.id = ?
        GROUP BY h.id
        LIMIT 1
    ");
    $stmt->execute([$id_hutang]);
    return $stmt->fetch();
}

function ambilPembayaranHutang($pdo, $id_hutang)
{
    $stmt = $pdo->prepare("SELECT * FROM hutang_owner_pembayaran WHERE id_hutang = ? ORDER BY tanggal DESC, id DESC");
    $stmt->execute([$id_hutang]);
    return $stmt->fetchAll();
}

function ambilRekeningOperasional($pdo)
{
    $stmt = $pdo->prepare("SELECT * FROM saldo_rekening WHERE kode_rekening = 'operasional' ORDER BY id ASC LIMIT 1");
    $stmt->execute();
    return $stmt->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'kas_bon_owner') {
        $mode = $_POST['mode'] ?? 'manual';
        $owner = $_POST['owner'] ?? 'owner_vanisa';
        $jumlah_total = (float) ($_POST['jumlah'] ?? 0);
        $keterangan = trim($_POST['keterangan'] ?? '');
        $tanggal = $_POST['tanggal'] ?? date('Y-m-d');
        $id_periode = null;

        try {
            if ($jumlah_total <= 0) {
                throw new Exception('Jumlah harus lebih dari nol.');
            }

            $rekening_ops = ambilRekeningOperasional($pdo);
            if (!$rekening_ops) {
                throw new Exception('Rekening Kas Operasional belum tersedia (belum diinisialisasi).');
            }

            $pdo->beginTransaction();

            if ($mode === 'auto') {
                $jumlah_vanisa = floor($jumlah_total / 2);
                $jumlah_dimas = $jumlah_total - $jumlah_vanisa;

                $ket_v = $keterangan === ''
                    ? 'Kas Bon Owner (Vanisa) - Auto Bagi 2 dari total Rp ' . number_format($jumlah_total, 0, ',', '.')
                    : $keterangan . ' (Vanisa - auto bagi 2)';
                $ket_d = $keterangan === ''
                    ? 'Kas Bon Owner (Dimas) - Auto Bagi 2 dari total Rp ' . number_format($jumlah_total, 0, ',', '.')
                    : $keterangan . ' (Dimas - auto bagi 2)';

                if ($jumlah_vanisa > 0) {
                    $stmt = $pdo->prepare("INSERT INTO hutang_owner (id_periode, jumlah, keterangan, tanggal, tipe, tipe_hutang, status) VALUES (?, ?, ?, ?, 'manual', 'owner_vanisa', 'belum_lunas')");
                    $stmt->execute([$id_periode, $jumlah_vanisa, $ket_v, $tanggal]);
                }
                if ($jumlah_dimas > 0) {
                    $stmt = $pdo->prepare("INSERT INTO hutang_owner (id_periode, jumlah, keterangan, tanggal, tipe, tipe_hutang, status) VALUES (?, ?, ?, ?, 'manual', 'owner_dimas', 'belum_lunas')");
                    $stmt->execute([$id_periode, $jumlah_dimas, $ket_d, $tanggal]);
                }

                $ket_total = $keterangan === ''
                    ? 'Kas Bon Owner Auto Bagi 2 - Top Up Kas Operasional (Vanisa Rp ' . number_format($jumlah_vanisa, 0, ',', '.') . ' + Dimas Rp ' . number_format($jumlah_dimas, 0, ',', '.') . ')'
                    : $keterangan . ' (auto bagi 2)';

                financeInsertSaldoTransaksi($pdo, (int) $rekening_ops['id'], $id_periode, 'debit', $jumlah_total, $ket_total, $tanggal);
                financeAdjustSaldoRekening($pdo, (int) $rekening_ops['id'], $jumlah_total);

                $pdo->commit();
                tampilkanAlertHutang(
                    'success',
                    'Berhasil',
                    'Auto bagi 2 owner sukses. Kas Operasional bertambah Rp ' . number_format($jumlah_total, 0, ',', '.')
                    . '. Vanisa: Rp ' . number_format($jumlah_vanisa, 0, ',', '.')
                    . ' • Dimas: Rp ' . number_format($jumlah_dimas, 0, ',', '.')
                    . ' & keduanya tercatat sebagai hutang.',
                    $redirect_url
                );
            } else {
                $tipe_hutang = $owner === 'owner_dimas' ? 'owner_dimas' : 'owner_vanisa';
                $keterangan_default = 'Kas Bon Owner (' . ($owner === 'owner_dimas' ? 'Dimas' : 'Vanisa') . ') - Top Up Kas Operasional Awal';
                if ($keterangan === '') {
                    $keterangan = $keterangan_default;
                }

                $stmt = $pdo->prepare("INSERT INTO hutang_owner (id_periode, jumlah, keterangan, tanggal, tipe, tipe_hutang, status) VALUES (?, ?, ?, ?, 'manual', ?, 'belum_lunas')");
                $stmt->execute([$id_periode, $jumlah_total, $keterangan, $tanggal, $tipe_hutang]);

                financeInsertSaldoTransaksi($pdo, (int) $rekening_ops['id'], $id_periode, 'debit', $jumlah_total, $keterangan, $tanggal);
                financeAdjustSaldoRekening($pdo, (int) $rekening_ops['id'], $jumlah_total);

                $pdo->commit();
                tampilkanAlertHutang('success', 'Berhasil', 'Kas Bon Owner masuk. Kas Operasional bertambah Rp ' . number_format($jumlah_total, 0, ',', '.') . ' & tercatat sebagai hutang.', $redirect_url);
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            tampilkanAlertHutang('error', 'Gagal', $e->getMessage(), null, 3000);
        }
    }

    if ($action === 'owner_tarik_bayar_hutang') {
        $id_hutang = (int) ($_POST['id_hutang'] ?? 0);
        $jumlah_bayar = (float) ($_POST['jumlah_bayar'] ?? 0);
        $tanggal_bayar = $_POST['tanggal_bayar'] ?? date('Y-m-d');
        $bayar_tanpa_kas = ($_POST['bayar_tanpa_kas'] ?? '') === 'on';

        try {
            if ($jumlah_bayar <= 0) {
                throw new Exception('Jumlah harus lebih dari nol.');
            }

            $hutang = ambilHutangById($pdo, $id_hutang);
            if (!$hutang) {
                throw new Exception('Hutang tidak ditemukan.');
            }
            if ((float) $hutang['sisa'] <= 0) {
                throw new Exception('Hutang ini sudah lunas.');
            }
            if ($jumlah_bayar > (float) $hutang['sisa']) {
                throw new Exception('Jumlah bayar melebihi sisa hutang (Rp ' . number_format($hutang['sisa'], 0, ',', '.') . ').');
            }

            $pdo->beginTransaction();

            $keterangan = ($bayar_tanpa_kas ? 'Pembayaran Hutang Owner (Dibayar Diluar / Uang Pribadi - tanpa potong Kas Operasional) : ' : 'Pembayaran Hutang / Owner Tarik Uang (') . labelTipeHutang($hutang['tipe_hutang']) . ')';
            $stmt = $pdo->prepare("INSERT INTO hutang_owner_pembayaran (id_hutang, jumlah, tanggal) VALUES (?, ?, ?)");
            $stmt->execute([$id_hutang, $jumlah_bayar, $tanggal_bayar]);

            if (!$bayar_tanpa_kas) {
                $rekening_ops = ambilRekeningOperasional($pdo);
                if (!$rekening_ops) {
                    throw new Exception('Rekening Kas Operasional belum tersedia.');
                }
                if ((float) $rekening_ops['saldo'] < $jumlah_bayar) {
                    throw new Exception('Saldo Kas Operasional (Rp ' . number_format($rekening_ops['saldo'], 0, ',', '.') . ') tidak cukup untuk membayar owner Rp ' . number_format($jumlah_bayar, 0, ',', '.') . '. Jika ingin bayar tanpa kas ops, centang opsi "Bayar tanpa memotong Kas Operasional".');
                }
                financeInsertSaldoTransaksi($pdo, (int) $rekening_ops['id'], null, 'kredit', $jumlah_bayar, $keterangan, $tanggal_bayar);
                financeAdjustSaldoRekening($pdo, (int) $rekening_ops['id'], -$jumlah_bayar);
            }

            $dibayar_baru = (float) $hutang['dibayar'] + $jumlah_bayar;
            $status = $dibayar_baru >= (float) $hutang['jumlah'] ? 'lunas' : 'belum_lunas';
            $stmt = $pdo->prepare("UPDATE hutang_owner SET status = ? WHERE id = ?");
            $stmt->execute([$status, $id_hutang]);

            $pdo->commit();
            $pesan_ok = $bayar_tanpa_kas
                ? ('Pembayaran hutang Rp ' . number_format($jumlah_bayar, 0, ',', '.') . ' sukses (Dicatat tanpa memotong Kas Operasional). Hutang berkurang, Status otomatis update.')
                : ('Pembayaran / Penarikan owner Rp ' . number_format($jumlah_bayar, 0, ',', '.') . ' sukses. Hutang berkurang & Kas Operasional berkurang.');
            tampilkanAlertHutang('success', 'Berhasil', $pesan_ok, $redirect_url, 2200);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            tampilkanAlertHutang('error', 'Gagal Bayar Hutang', $e->getMessage(), null, 6500);
        }
    }

    if ($action === 'tambah') {
        $jumlah = (float) ($_POST['jumlah'] ?? 0);
        $keterangan = trim($_POST['keterangan'] ?? '');
        $tanggal = $_POST['tanggal'] ?? date('Y-m-d');
        $tanggal_jatuh_tempo = !empty($_POST['tanggal_jatuh_tempo']) ? $_POST['tanggal_jatuh_tempo'] : null;
        $tipe_hutang = $_POST['tipe_hutang'] ?? 'owner_vanisa';
        $id_periode = null;

        try {
            if ($jumlah <= 0) {
                throw new Exception('Jumlah hutang harus lebih dari nol.');
            }

            $rekening_ops = ambilRekeningOperasional($pdo);
            if (!$rekening_ops) {
                throw new Exception('Rekening Kas Operasional belum tersedia (belum diinisialisasi).');
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("INSERT INTO hutang_owner (id_periode, jumlah, keterangan, tanggal, tanggal_jatuh_tempo, tipe, tipe_hutang) VALUES (?, ?, ?, ?, ?, 'manual', ?)");
            $stmt->execute([$id_periode, $jumlah, $keterangan, $tanggal, $tanggal_jatuh_tempo, $tipe_hutang]);

            buatRiwayatFloatKasir($pdo, effectFloatHutang($tipe_hutang, $jumlah, 0));

            $ket_sync = $keterangan === '' ? ('Tambah Utang Manual - ' . labelTipeHutang($tipe_hutang)) : ($keterangan . ' [' . labelTipeHutang($tipe_hutang) . ']');
            financeInsertSaldoTransaksi($pdo, (int) $rekening_ops['id'], $id_periode, 'debit', $jumlah, $ket_sync, $tanggal);
            financeAdjustSaldoRekening($pdo, (int) $rekening_ops['id'], $jumlah);

            $pdo->commit();
            tampilkanAlertHutang('success', 'Berhasil', 'Hutang berhasil ditambahkan. Kas Operasional bertambah Rp ' . number_format($jumlah, 0, ',', '.') . '.', $redirect_url);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            tampilkanAlertHutang('error', 'Gagal', $e->getMessage(), null, 3000);
        }
    }

    if ($action === 'edit') {
        $id_hutang = (int) ($_POST['id_hutang'] ?? 0);
        $jumlah_baru = (float) ($_POST['jumlah'] ?? 0);
        $keterangan = trim($_POST['keterangan'] ?? '');
        $tanggal = $_POST['tanggal'] ?? date('Y-m-d');
        $tanggal_jatuh_tempo = !empty($_POST['tanggal_jatuh_tempo']) ? $_POST['tanggal_jatuh_tempo'] : null;
        $tipe_hutang_baru = $_POST['tipe_hutang'] ?? 'owner_vanisa';
        $id_periode = null;

        try {
            if ($jumlah_baru <= 0) {
                throw new Exception('Jumlah hutang harus lebih dari nol.');
            }

            $hutang = ambilHutangById($pdo, $id_hutang);
            if (!$hutang) {
                throw new Exception('Data hutang tidak ditemukan.');
            }
            if ($hutang['tipe'] !== 'manual') {
                throw new Exception('Hutang dari pembelian tidak bisa diedit dari halaman ini.');
            }
            if ((float) $hutang['dibayar'] > $jumlah_baru) {
                throw new Exception('Jumlah hutang baru tidak boleh lebih kecil dari total yang sudah dibayar.');
            }

            $rekening_ops = ambilRekeningOperasional($pdo);
            if (!$rekening_ops) {
                throw new Exception('Rekening Kas Operasional belum tersedia.');
            }

            $pdo->beginTransaction();

            $old_effect = effectFloatHutang($hutang['tipe_hutang'], $hutang['jumlah'], $hutang['dibayar']);
            $new_effect = effectFloatHutang($tipe_hutang_baru, $jumlah_baru, $hutang['dibayar']);
            buatRiwayatFloatKasir($pdo, $new_effect - $old_effect);

            $status = (float) $hutang['dibayar'] >= $jumlah_baru ? 'lunas' : 'belum_lunas';
            $stmt = $pdo->prepare("UPDATE hutang_owner SET id_periode = ?, jumlah = ?, keterangan = ?, tanggal = ?, tanggal_jatuh_tempo = ?, tipe_hutang = ?, status = ? WHERE id = ?");
            $stmt->execute([$id_periode, $jumlah_baru, $keterangan, $tanggal, $tanggal_jatuh_tempo, $tipe_hutang_baru, $status, $id_hutang]);

            $delta = $jumlah_baru - (float) $hutang['jumlah'];
            if ($delta != 0) {
                $ket_sync = 'Edit Utang Manual: ' . labelTipeHutang($tipe_hutang_baru) . ' (' . ($delta > 0 ? '+' : '') . 'Rp ' . number_format($delta, 0, ',', '.') . ')';
                $tipe_trans = $delta > 0 ? 'debit' : 'kredit';
                $nominal_sync = abs($delta);
                financeInsertSaldoTransaksi($pdo, (int) $rekening_ops['id'], $id_periode, $tipe_trans, $nominal_sync, $ket_sync, $tanggal);
                financeAdjustSaldoRekening($pdo, (int) $rekening_ops['id'], $delta);
            }

            $pdo->commit();
            tampilkanAlertHutang('success', 'Berhasil', 'Hutang berhasil diperbarui. Kas Operasional disesuaikan delta Rp ' . number_format($delta ?? 0, 0, ',', '.') . '.', $redirect_url);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            tampilkanAlertHutang('error', 'Gagal', $e->getMessage(), null, 3000);
        }
    }

    if ($action === 'hapus') {
        $id_hutang = (int) ($_POST['id_hutang'] ?? 0);

        try {
            $hutang = ambilHutangById($pdo, $id_hutang);
            if (!$hutang) {
                throw new Exception('Data hutang tidak ditemukan.');
            }
            if ($hutang['tipe'] !== 'manual') {
                throw new Exception('Hutang dari pembelian tidak bisa dihapus dari halaman ini.');
            }

            $rekening_ops = ambilRekeningOperasional($pdo);
            if (!$rekening_ops) {
                throw new Exception('Rekening Kas Operasional belum tersedia.');
            }

            $pdo->beginTransaction();

            $current_effect = effectFloatHutang($hutang['tipe_hutang'], $hutang['jumlah'], $hutang['dibayar']);
            buatRiwayatFloatKasir($pdo, -1 * $current_effect);

            $sisa_awal = (float) $hutang['jumlah'] - (float) $hutang['dibayar'];
            if ($sisa_awal > 0) {
                $ket_sync = 'Hapus Utang Manual (' . labelTipeHutang($hutang['tipe_hutang']) . ') - batalkan sisa Rp ' . number_format($sisa_awal, 0, ',', '.');
                financeInsertSaldoTransaksi($pdo, (int) $rekening_ops['id'], $hutang['id_periode'] ?: null, 'kredit', $sisa_awal, $ket_sync, $hutang['tanggal']);
                financeAdjustSaldoRekening($pdo, (int) $rekening_ops['id'], -1 * $sisa_awal);
            }

            $stmt = $pdo->prepare("DELETE FROM hutang_owner_pembayaran WHERE id_hutang = ?");
            $stmt->execute([$id_hutang]);

            $stmt = $pdo->prepare("DELETE FROM hutang_owner WHERE id = ?");
            $stmt->execute([$id_hutang]);

            $pdo->commit();
            tampilkanAlertHutang('success', 'Berhasil', 'Hutang berhasil dihapus. Kas Operasional dikurangi sisa Rp ' . number_format($sisa_awal ?? 0, 0, ',', '.') . '.', $redirect_url);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            tampilkanAlertHutang('error', 'Gagal', $e->getMessage(), null, 3000);
        }
    }

    if ($action === 'bayar') {
        $id_hutang = (int) ($_POST['id_hutang'] ?? 0);
        $jumlah_bayar = (float) ($_POST['jumlah_bayar'] ?? 0);
        $tanggal_bayar = $_POST['tanggal_bayar'] ?? date('Y-m-d');
        $bayar_tanpa_kas = ($_POST['bayar_tanpa_kas'] ?? '') === 'on';

        try {
            if ($jumlah_bayar <= 0) {
                throw new Exception('Jumlah bayar harus lebih dari nol.');
            }

            $hutang = ambilHutangById($pdo, $id_hutang);
            if (!$hutang) {
                throw new Exception('Data hutang tidak ditemukan.');
            }
            if ((float) $hutang['sisa'] <= 0) {
                throw new Exception('Hutang ini sudah lunas.');
            }
            if ($jumlah_bayar > (float) $hutang['sisa']) {
                throw new Exception('Jumlah bayar melebihi sisa hutang.');
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("INSERT INTO hutang_owner_pembayaran (id_hutang, jumlah, tanggal) VALUES (?, ?, ?)");
            $stmt->execute([$id_hutang, $jumlah_bayar, $tanggal_bayar]);

            if ($hutang['tipe_hutang'] === 'kasir') {
                buatRiwayatFloatKasir($pdo, $jumlah_bayar);
            }

            $dibayar_baru = (float) $hutang['dibayar'] + $jumlah_bayar;
            $status = $dibayar_baru >= (float) $hutang['jumlah'] ? 'lunas' : 'belum_lunas';

            $stmt = $pdo->prepare("UPDATE hutang_owner SET status = ? WHERE id = ?");
            $stmt->execute([$status, $id_hutang]);

            if (!$bayar_tanpa_kas) {
                $rekening_ops = ambilRekeningOperasional($pdo);
                if (!$rekening_ops) {
                    throw new Exception('Rekening Kas Operasional belum tersedia.');
                }
                if ((float) $rekening_ops['saldo'] < $jumlah_bayar) {
                    throw new Exception('Saldo Kas Operasional (Rp ' . number_format($rekening_ops['saldo'], 0, ',', '.') . ') tidak cukup untuk bayar hutang Rp ' . number_format($jumlah_bayar, 0, ',', '.') . '. Centang opsi "Bayar tanpa potong Kas Operasional" jika dibayar diluar sistem.');
                }
                $ket_sync = 'Bayar Utang Manual (' . labelTipeHutang($hutang['tipe_hutang']) . ') - Rp ' . number_format($jumlah_bayar, 0, ',', '.');
                financeInsertSaldoTransaksi($pdo, (int) $rekening_ops['id'], null, 'kredit', $jumlah_bayar, $ket_sync, $tanggal_bayar);
                financeAdjustSaldoRekening($pdo, (int) $rekening_ops['id'], -1 * $jumlah_bayar);
            }

            $pdo->commit();
            $pesan_ok = $bayar_tanpa_kas
                ? ('Pembayaran hutang Rp ' . number_format($jumlah_bayar, 0, ',', '.') . ' berhasil disimpan (Tanpa potong Kas Operasional). Hutang berkurang & Status otomatis update.')
                : ('Pembayaran hutang berhasil disimpan. Kas Operasional berkurang Rp ' . number_format($jumlah_bayar, 0, ',', '.') . '.');
            tampilkanAlertHutang('success', 'Berhasil', $pesan_ok, $redirect_url, 2200);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            tampilkanAlertHutang('error', 'Gagal Bayar Hutang', $e->getMessage(), null, 6500);
        }
    }
}

$search = trim($_GET['search'] ?? '');
if ($search !== '') {
    $stmt = $pdo->prepare("
        SELECT h.*, COALESCE(SUM(p.jumlah), 0) AS dibayar, (h.jumlah - COALESCE(SUM(p.jumlah), 0)) AS sisa
        FROM hutang_owner h
        LEFT JOIN hutang_owner_pembayaran p ON h.id = p.id_hutang
        WHERE h.keterangan LIKE ? OR h.tipe_hutang LIKE ?
        GROUP BY h.id
        ORDER BY h.tanggal DESC, h.id DESC
    ");
    $keyword = '%' . $search . '%';
    $stmt->execute([$keyword, $keyword]);
    $hutang_list = $stmt->fetchAll();
} else {
    $hutang_list = $pdo->query("
        SELECT h.*, COALESCE(SUM(p.jumlah), 0) AS dibayar, (h.jumlah - COALESCE(SUM(p.jumlah), 0)) AS sisa
        FROM hutang_owner h
        LEFT JOIN hutang_owner_pembayaran p ON h.id = p.id_hutang
        GROUP BY h.id
        ORDER BY h.tanggal DESC, h.id DESC
    ")->fetchAll();
}

$total_hutang = 0;
$total_sisa = 0;
$total_dibayar = 0;
$hutang_groups = [];
foreach ($hutang_list as $item_hutang) {
    $total_hutang += (float) $item_hutang['jumlah'];
    $total_sisa += (float) $item_hutang['sisa'];
    $total_dibayar += (float) $item_hutang['dibayar'];

    $group_key = $item_hutang['tipe_hutang'];
    if (!isset($hutang_groups[$group_key])) {
        $hutang_groups[$group_key] = [
            'key' => $group_key,
            'modal_key' => modalKeyTipeHutang($group_key),
            'label' => labelTipeHutang($group_key),
            'badge' => badgeTipeHutang($group_key),
            'total_jumlah' => 0,
            'total_dibayar' => 0,
            'total_sisa' => 0,
            'item_count' => 0,
            'latest_tanggal' => $item_hutang['tanggal'],
            'items' => [],
        ];
    }

    $hutang_groups[$group_key]['total_jumlah'] += (float) $item_hutang['jumlah'];
    $hutang_groups[$group_key]['total_dibayar'] += (float) $item_hutang['dibayar'];
    $hutang_groups[$group_key]['total_sisa'] += (float) $item_hutang['sisa'];
    $hutang_groups[$group_key]['item_count']++;
    $hutang_groups[$group_key]['items'][] = $item_hutang;

    if (strtotime($item_hutang['tanggal']) > strtotime($hutang_groups[$group_key]['latest_tanggal'])) {
        $hutang_groups[$group_key]['latest_tanggal'] = $item_hutang['tanggal'];
    }
}

foreach ($hutang_groups as &$group) {
    usort($group['items'], function ($a, $b) {
        $date_compare = strtotime($b['tanggal']) <=> strtotime($a['tanggal']);
        if ($date_compare !== 0) {
            return $date_compare;
        }

        return (int) $b['id'] <=> (int) $a['id'];
    });

    $group['status'] = $group['total_sisa'] <= 0 ? 'lunas' : 'belum_lunas';
}
unset($group);
?>

<div class="page-header">
    <a href="<?php echo $base_url; ?>/admin_dashboard.php" class="text-white text-decoration-none mb-2 d-inline-block"><i class="bi bi-arrow-left"></i> Kembali</a>
    <h4 class="mb-0"><i class="bi bi-exclamation-triangle me-2"></i>Utang Owner &amp; Kas Bon</h4>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-body">
                <small class="text-muted">Total Utang</small>
                <h4 class="mb-0 text-primary">Rp <?php echo number_format($total_hutang, 0, ',', '.'); ?></h4>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-body">
                <small class="text-muted">Sudah Dibayar (Sudah Lunas)</small>
                <h4 class="mb-0 text-success">Rp <?php echo number_format($total_dibayar, 0, ',', '.'); ?></h4>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-body">
                <small class="text-muted">Sisa Utang (Belum Lunas)</small>
                <h4 class="mb-0 text-danger">Rp <?php echo number_format($total_sisa, 0, ',', '.'); ?></h4>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-12 col-md-6">
        <div class="alert alert-warning" role="alert">
            <i class="bi bi-wallet2 me-2"></i>
            <b>Kas Bon Owner</b> = Owner kasih pinjaman DULU ke Kas Operasional (misal hari pertama modal belanja beli bahan).
            <hr class="my-2">
            Nanti setelah Kas Operasional keisi dari alokasi penjualan, owner bisa TARIK KEMBALI uangnya = LUNAS.
        </div>
    </div>
    <div class="col-12 col-md-6">
        <div class="alert alert-primary" role="alert">
            <i class="bi bi-info-circle me-2"></i>
            <b>Aliran uang:</b><br>
            • Owner kasih pinjaman → Kas Operasional bertambah + tercatat Utang Owner<br>
            • Owner ambil kembali → Kas Operasional berkurang + Utang berkurang (lunas)
        </div>
    </div>
</div>

<div class="d-flex flex-wrap justify-content-end gap-2 mb-3">
    <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#kasBonOwnerModal">
        <i class="bi bi-cash-stack me-1"></i>Owner Kas Bon (Kas Operasional +)
    </button>
    <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#ownerTarikPilihModal">
        <i class="bi bi-arrow-down-left-circle me-1"></i>Owner Tarik / Bayar Utang
    </button>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#tambahHutangModal">
        <i class="bi bi-plus-lg me-1"></i>Tambah Utang Manual
    </button>
</div>

<div class="mb-3">
    <form method="GET" class="input-group">
        <input type="text" class="form-control" name="search" placeholder="Cari utang..." value="<?php echo htmlspecialchars($search); ?>">
        <button class="btn btn-primary" type="submit"><i class="bi bi-search"></i></button>
    </form>
</div>

<div class="row g-3">
    <?php if (empty($hutang_list)): ?>
    <div class="col-12">
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-body text-center text-muted">
                Belum ada data hutang.
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php foreach ($hutang_groups as $group): ?>
        <div class="col-12">
            <div class="card border-0 shadow-sm rounded-4" style="cursor:pointer" data-bs-toggle="modal" data-bs-target="#detailHutangModal<?php echo $group['modal_key']; ?>">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="rounded-circle bg-<?php echo $group['badge']; ?> bg-opacity-10 p-3 me-3">
                            <i class="bi bi-cash-stack text-<?php echo $group['badge']; ?> fs-4"></i>
                        </div>

                        <div class="flex-grow-1">
                            <h5 class="mb-1 fw-bold text-dark"><?php echo htmlspecialchars($group['label']); ?></h5>
                            <div class="text-muted small">
                                <i class="bi bi-calendar3 me-1"></i>Terakhir dipakai <?php echo date('d M Y', strtotime($group['latest_tanggal'])); ?>
                            </div>
                            <div class="text-secondary small mt-1">
                                <i class="bi bi-collection me-1"></i><?php echo $group['item_count']; ?> transaksi hutang
                            </div>
                            <div class="text-secondary small">
                                <i class="bi bi-wallet2 me-1"></i>Total Hutang Rp <?php echo number_format($group['total_jumlah'], 0, ',', '.'); ?>
                            </div>
                            <div class="text-secondary small">
                                <i class="bi bi-check-circle me-1"></i>Sudah Dibayar Rp <?php echo number_format($group['total_dibayar'], 0, ',', '.'); ?>
                            </div>
                        </div>

                        <div class="text-end">
                            <div class="fw-bold text-danger mb-2">
                                Sisa Rp <?php echo number_format($group['total_sisa'], 0, ',', '.'); ?>
                            </div>
                            <span class="badge bg-<?php echo $group['status'] === 'lunas' ? 'success' : 'danger'; ?>">
                                <?php echo $group['status'] === 'lunas' ? 'Lunas' : 'Belum Lunas'; ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal fade" id="detailHutangModal<?php echo $group['modal_key']; ?>" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content rounded-4">
                    <div class="modal-header border-0">
                        <div>
                            <h5 class="modal-title mb-1"><?php echo htmlspecialchars($group['label']); ?></h5>
                            <small class="text-muted"><?php echo $group['item_count']; ?> transaksi dalam satu kategori</small>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="text-muted small">Kategori</label>
                                <p class="mb-0"><?php echo htmlspecialchars($group['label']); ?></p>
                            </div>
                            <div class="col-md-6">
                                <label class="text-muted small">Status</label>
                                <p class="mb-0"><?php echo $group['status'] === 'lunas' ? 'Lunas' : 'Belum Lunas'; ?></p>
                            </div>
                            <div class="col-md-6">
                                <label class="text-muted small">Transaksi</label>
                                <p class="mb-0"><?php echo $group['item_count']; ?> data hutang</p>
                            </div>
                            <div class="col-md-6">
                                <label class="text-muted small">Tanggal Terbaru</label>
                                <p class="mb-0"><?php echo date('d/m/Y', strtotime($group['latest_tanggal'])); ?></p>
                            </div>
                            <div class="col-md-4">
                                <label class="text-muted small">Total Hutang</label>
                                <p class="mb-0 fw-bold">Rp <?php echo number_format($group['total_jumlah'], 0, ',', '.'); ?></p>
                            </div>
                            <div class="col-md-4">
                                <label class="text-muted small">Sudah Dibayar</label>
                                <p class="mb-0 fw-bold text-success">Rp <?php echo number_format($group['total_dibayar'], 0, ',', '.'); ?></p>
                            </div>
                            <div class="col-md-4">
                                <label class="text-muted small">Sisa</label>
                                <p class="mb-0 fw-bold text-danger">Rp <?php echo number_format($group['total_sisa'], 0, ',', '.'); ?></p>
                            </div>
                            <div class="col-12">
                                <label class="text-muted small">Detail Pemakaian Hutang</label>
                                <div class="mt-2 d-grid gap-3">
                                    <?php foreach ($group['items'] as $h): ?>
                                        <?php $pembayaran_list = ambilPembayaranHutang($pdo, $h['id']); ?>
                                        <div class="border rounded-4 p-3">
                                            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                                                <div>
                                                    <div class="fw-semibold"><?php echo date('d/m/Y', strtotime($h['tanggal'])); ?></div>
                                                    <div class="text-muted small"><?php echo $h['keterangan'] ? htmlspecialchars($h['keterangan']) : 'Tanpa keterangan'; ?></div>
                                                </div>
                                                <div class="text-end">
                                                    <span class="badge bg-<?php echo $h['status'] === 'lunas' ? 'success' : 'danger'; ?>">
                                                        <?php echo $h['status'] === 'lunas' ? 'Lunas' : 'Belum Lunas'; ?>
                                                    </span>
                                                    <div class="mt-2">
                                                        <span class="badge bg-<?php echo $h['tipe'] === 'pembelian' ? 'info' : 'secondary'; ?>">
                                                            <?php echo $h['tipe'] === 'pembelian' ? 'Dari Pembelian' : 'Manual'; ?>
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="row g-3 mt-1">
                                                <div class="col-md-4">
                                                    <small class="text-muted d-block">Jumlah Hutang</small>
                                                    <span class="fw-bold">Rp <?php echo number_format($h['jumlah'], 0, ',', '.'); ?></span>
                                                </div>
                                                <div class="col-md-4">
                                                    <small class="text-muted d-block">Sudah Dibayar</small>
                                                    <span class="fw-bold text-success">Rp <?php echo number_format($h['dibayar'], 0, ',', '.'); ?></span>
                                                </div>
                                                <div class="col-md-4">
                                                    <small class="text-muted d-block">Sisa</small>
                                                    <span class="fw-bold text-danger">Rp <?php echo number_format($h['sisa'], 0, ',', '.'); ?></span>
                                                </div>
                                                <div class="col-md-6">
                                                    <small class="text-muted d-block">Jatuh Tempo</small>
                                                    <span><?php echo $h['tanggal_jatuh_tempo'] ? date('d/m/Y', strtotime($h['tanggal_jatuh_tempo'])) : '-'; ?></span>
                                                </div>
                                            </div>

                                            <div class="mt-3">
                                                <small class="text-muted d-block mb-2">Riwayat Pembayaran</small>
                                                <div class="border rounded-3">
                                                    <?php if (empty($pembayaran_list)): ?>
                                                    <div class="p-3 text-muted text-center">Belum ada pembayaran.</div>
                                                    <?php else: ?>
                                                        <?php foreach ($pembayaran_list as $bayar): ?>
                                                        <div class="d-flex justify-content-between align-items-center p-3 border-bottom">
                                                            <div>
                                                                <div class="fw-semibold"><?php echo date('d/m/Y', strtotime($bayar['tanggal'])); ?></div>
                                                                <small class="text-muted">Pembayaran hutang</small>
                                                            </div>
                                                            <div class="fw-bold text-success">Rp <?php echo number_format($bayar['jumlah'], 0, ',', '.'); ?></div>
                                                        </div>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                </div>
                                            </div>

                                            <div class="d-flex flex-wrap justify-content-end gap-2 mt-3">
                                                <?php if ($h['tipe'] === 'manual'): ?>
                                                <button type="button" class="btn btn-warning" onclick="bukaEditHutang(<?php echo $h['id']; ?>, 'detailHutangModal<?php echo $group['modal_key']; ?>')">
                                                    <i class="bi bi-pencil"></i> Edit
                                                </button>
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="action" value="hapus">
                                                    <input type="hidden" name="id_hutang" value="<?php echo $h['id']; ?>">
                                                    <button type="submit" class="btn btn-danger">
                                                        <i class="bi bi-trash"></i> Hapus
                                                    </button>
                                                </form>
                                                <?php endif; ?>
                                                <?php if ($h['status'] !== 'lunas'): ?>
                                                <button type="button" class="btn btn-primary" onclick="bukaBayarHutang(<?php echo $h['id']; ?>, 'detailHutangModal<?php echo $group['modal_key']; ?>')">
                                                    <i class="bi bi-cash-coin"></i> Bayar
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-0">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <?php foreach ($hutang_list as $h): ?>
        <?php if ($h['tipe'] === 'manual'): ?>
        <div class="modal fade" id="editHutangModal<?php echo $h['id']; ?>" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content rounded-4">
                    <form method="POST">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="id_hutang" value="<?php echo $h['id']; ?>">
                        <div class="modal-header border-0">
                            <h5 class="modal-title">Edit Hutang</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">Tipe Hutang</label>
                                <select class="form-select" name="tipe_hutang" required>
                                    <option value="owner_vanisa" <?php echo $h['tipe_hutang'] === 'owner_vanisa' ? 'selected' : ''; ?>>Hutang Owner Vanisa</option>
                                    <option value="owner_dimas" <?php echo $h['tipe_hutang'] === 'owner_dimas' ? 'selected' : ''; ?>>Hutang Owner Dimas</option>
                                    <option value="kasir" <?php echo $h['tipe_hutang'] === 'kasir' ? 'selected' : ''; ?>>Hutang Kasir</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Tanggal</label>
                                <input type="date" class="form-control" name="tanggal" value="<?php echo htmlspecialchars($h['tanggal']); ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Tanggal Jatuh Tempo</label>
                                <input type="date" class="form-control" name="tanggal_jatuh_tempo" value="<?php echo htmlspecialchars($h['tanggal_jatuh_tempo']); ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Jumlah</label>
                                <input type="number" class="form-control" name="jumlah" min="1" step="0.01" value="<?php echo htmlspecialchars($h['jumlah']); ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Keterangan</label>
                                <textarea class="form-control" name="keterangan" rows="3"><?php echo htmlspecialchars($h['keterangan']); ?></textarea>
                            </div>
                        </div>
                        <div class="modal-footer border-0">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                            <button type="submit" class="btn btn-primary">Update</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($h['status'] !== 'lunas'): ?>
        <div class="modal fade" id="bayarModal<?php echo $h['id']; ?>" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content rounded-4">
                    <form method="POST">
                        <input type="hidden" name="action" value="bayar">
                        <input type="hidden" name="id_hutang" value="<?php echo $h['id']; ?>">
                        <div class="modal-header border-0">
                            <h5 class="modal-title">Bayar Hutang</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">Tanggal</label>
                                <input type="date" class="form-control" name="tanggal_bayar" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Jumlah Bayar</label>
                                <input type="number" class="form-control" name="jumlah_bayar" min="1" step="0.01" max="<?php echo htmlspecialchars($h['sisa']); ?>" required>
                            </div>
                            <div class="alert alert-light mb-3">
                                Sisa hutang saat ini: <strong>Rp <?php echo number_format($h['sisa'], 0, ',', '.'); ?></strong>
                            </div>
                            <div class="form-check border rounded-4 p-3 bg-success bg-opacity-10 border-success mb-0">
                                <input class="form-check-input form-check-input-lg" type="checkbox" name="bayar_tanpa_kas" id="bayarTanpaKas<?php echo $h['id']; ?>" checked>
                                <label class="form-check-label fw-bold text-success" for="bayarTanpaKas<?php echo $h['id']; ?>">
                                    <i class="bi bi-wallet2 me-1"></i>Bayar TANPA memotong Kas Operasional
                                </label>
                                <div class="small text-success mt-1 ms-4">
                                    Centang jika dibayar pakai uang pribadi / diluar sistem (hanya ubah status hutang).
                                    <br>Hilangkan centang jika diambil dari Kas Operasional (saldo rekening berkurang).
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer border-0">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                            <button type="submit" class="btn btn-primary">Bayar</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>
    <?php endforeach; ?>
</div>

<div class="modal fade" id="tambahHutangModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4">
            <form method="POST">
                <input type="hidden" name="action" value="tambah">
                <div class="modal-header border-0">
                    <h5 class="modal-title">Tambah Utang Manual</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Tipe Utang</label>
                        <select class="form-select" name="tipe_hutang" required>
                            <option value="owner_vanisa">Utang Owner Vanisa</option>
                            <option value="owner_dimas">Utang Owner Dimas</option>
                            <option value="kasir">Utang Kasir</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Tanggal</label>
                        <input type="date" class="form-control" name="tanggal" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Tanggal Jatuh Tempo</label>
                        <input type="date" class="form-control" name="tanggal_jatuh_tempo">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Jumlah</label>
                        <input type="number" class="form-control" name="jumlah" min="1" step="0.01" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Keterangan</label>
                        <textarea class="form-control" name="keterangan" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="kasBonOwnerModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4">
            <form method="POST">
                <input type="hidden" name="action" value="kas_bon_owner">
                <input type="hidden" name="mode" id="kasBonMode" value="manual">
                <div class="modal-header border-0">
                    <h5 class="modal-title">Owner Kas Bon (Top Up Kas Operasional Awal)</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-success">
                        Ini untuk hari PERTAMA (atau kapanpun) jika Kas Operasional masih kosong dan butuh uang belanja.
                        <br>Owner kasih pinjaman DULU → <b>Kas Operasional bertambah</b> + tercatat sebagai <b>Utang Owner</b>.
                        <br>Nanti setelah Kas Operasional keisi dari alokasi penjualan, owner bisa tarik kembali = LUNAS.
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Mode Input</label>
                        <div class="d-flex flex-wrap gap-3 p-3 border rounded-4 bg-light">
                            <label class="d-flex align-items-center gap-2 mb-0 cursor-pointer">
                                <input class="form-check-input" type="radio" name="mode_pilih" value="manual" id="modeManual" checked onchange="gantiModeKasBon('manual')">
                                <span class="fw-semibold">Manual (per owner)</span>
                            </label>
                            <label class="d-flex align-items-center gap-2 mb-0 cursor-pointer">
                                <input class="form-check-input" type="radio" name="mode_pilih" value="auto" id="modeAuto" onchange="gantiModeKasBon('auto')">
                                <span class="fw-semibold text-success">Otomatis Bagi 2 Owner</span>
                            </label>
                        </div>
                    </div>
                    <div class="mb-3" id="wrapOwnerManual">
                        <label class="form-label">Owner Yang Kas Bon</label>
                        <select class="form-select" name="owner" id="ownerSelect" required>
                            <option value="owner_vanisa">Owner Vanisa</option>
                            <option value="owner_dimas">Owner Dimas</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Tanggal</label>
                        <input type="date" class="form-control" name="tanggal" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Jumlah Pinjaman (Top Up)</label>
                        <input type="number" class="form-control" name="jumlah" id="jumlahKasBon" min="1" step="0.01" required placeholder="Contoh: 500000" oninput="previewBagiKasBon()">
                    </div>
                    <div class="mb-3 d-none" id="wrapPreviewBagi">
                        <div class="p-3 bg-success bg-opacity-10 border border-success rounded-4">
                            <div class="small text-success fw-semibold mb-2"><i class="bi bi-magic me-1"></i>Preview Pembagian Otomatis</div>
                            <div class="row g-2">
                                <div class="col-6">
                                    <div class="small text-muted mb-1">Owner Vanisa</div>
                                    <div class="fw-bold">Rp <span id="previewVanisa">0</span></div>
                                </div>
                                <div class="col-6">
                                    <div class="small text-muted mb-1">Owner Dimas</div>
                                    <div class="fw-bold">Rp <span id="previewDimas">0</span></div>
                                </div>
                            </div>
                            <small class="text-muted d-block mt-2">Vanisa dapat setengah dibulatkan ke bawah, Dimas dapat sisanya.</small>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Keterangan (opsional)</label>
                        <textarea class="form-control" name="keterangan" rows="2" placeholder="Contoh: Modal awal belanja tanggal 5"></textarea>
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-success" id="btnKasBonSubmit">Simpan &amp; Tambah ke Kas Operasional</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="ownerTarikPilihModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content rounded-4">
            <div class="modal-header border-0">
                <div>
                    <h5 class="modal-title mb-1">Owner Tarik Uang / Bayar Utang</h5>
                    <small class="text-muted">Pilih utang mana yang mau dibayar lunas / sebagian. Otomatis diambil dari Kas Operasional.</small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <?php
                    $rekening_ops = ambilRekeningOperasional($pdo);
                    if ($rekening_ops):
                ?>
                <div class="alert alert-info mb-3">
                    <b>Saldo Kas Operasional Saat Ini: Rp <?php echo number_format((float) $rekening_ops['saldo'], 0, ',', '.'); ?></b><br>
                    Tidak boleh tarik melebihi angka ini.
                </div>
                <?php endif; ?>
                <div class="d-grid gap-2">
                    <?php
                        $ada_belum_lunas = false;
                        foreach ($hutang_list as $h):
                            if ((float) $h['sisa'] <= 0) continue;
                            $ada_belum_lunas = true;
                    ?>
                        <button type="button" class="btn btn-outline-danger text-start p-3 rounded-4" onclick="bukaOwnerTarikBayar(<?php echo (int) $h['id']; ?>, 'ownerTarikPilihModal')">
                            <div class="d-flex justify-content-between align-items-center gap-2">
                                <div>
                                    <div class="fw-semibold"><?php echo htmlspecialchars(labelTipeHutang($h['tipe_hutang'])); ?></div>
                                    <div class="text-muted small">
                                        <i class="bi bi-calendar3 me-1"></i><?php echo date('d M Y', strtotime($h['tanggal'])); ?>
                                        <?php if ($h['keterangan']) { echo ' • ' . htmlspecialchars($h['keterangan']); } ?>
                                    </div>
                                </div>
                                <div class="text-end">
                                    <div class="text-danger fw-bold mb-1">Sisa Rp <?php echo number_format($h['sisa'], 0, ',', '.'); ?></div>
                                    <div class="text-muted small">Jumlah awal Rp <?php echo number_format($h['jumlah'], 0, ',', '.'); ?></div>
                                </div>
                            </div>
                        </button>
                    <?php endforeach; ?>
                    <?php if (!$ada_belum_lunas): ?>
                        <div class="text-center text-muted p-4 border rounded-4">
                            Belum ada utang yang belum lunas 😊
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
            </div>
        </div>
    </div>
</div>

<?php foreach ($hutang_list as $h): ?>
    <?php if ((float) $h['sisa'] <= 0) continue; ?>
<div class="modal fade" id="ownerTarikBayarModal<?php echo (int) $h['id']; ?>" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4">
            <form method="POST">
                <input type="hidden" name="action" value="owner_tarik_bayar_hutang">
                <input type="hidden" name="id_hutang" value="<?php echo (int) $h['id']; ?>">
                <div class="modal-header border-0">
                    <h5 class="modal-title">Bayar &amp; Tarik ke Owner</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-danger mb-3">
                        Owner mengambil uang dari <b>Kas Operasional</b> + otomatis mengurangi Utang Owner.
                    </div>
                    <div class="mb-3">
                        <label class="text-muted small d-block mb-1">Rincian Utang</label>
                        <div class="border rounded-4 p-3">
                            <div class="small mb-1"><b><?php echo htmlspecialchars(labelTipeHutang($h['tipe_hutang'])); ?></b></div>
                            <div class="small text-muted">Tanggal: <?php echo date('d/m/Y', strtotime($h['tanggal'])); ?></div>
                            <?php if ($h['keterangan']) { echo '<div class="small text-muted mt-1">' . htmlspecialchars($h['keterangan']) . '</div>'; } ?>
                            <hr class="my-2">
                            <div class="row g-2">
                                <div class="col-6"><small class="text-muted">Awal</small><div class="fw-bold">Rp <?php echo number_format($h['jumlah'], 0, ',', '.'); ?></div></div>
                                <div class="col-6"><small class="text-muted">Sudah Dibayar</small><div class="fw-bold text-success">Rp <?php echo number_format($h['dibayar'], 0, ',', '.'); ?></div></div>
                                <div class="col-12"><small class="text-muted">Sisa Utang Saat Ini</small><div class="fw-bold text-danger">Rp <?php echo number_format($h['sisa'], 0, ',', '.'); ?></div></div>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Tanggal</label>
                        <input type="date" class="form-control" name="tanggal_bayar" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Jumlah yang Dibayar (Diambil Owner)</label>
                        <input type="number" class="form-control" name="jumlah_bayar" min="1" step="0.01" max="<?php echo htmlspecialchars((float) $h['sisa']); ?>" required>
                        <div class="form-text">Maksimal Rp <?php echo number_format((float) $h['sisa'], 0, ',', '.'); ?> (sisa utang).</div>
                    </div>
                    <div class="form-check border rounded-4 p-3 bg-success bg-opacity-10 border-success mb-0">
                        <input class="form-check-input form-check-input-lg" type="checkbox" name="bayar_tanpa_kas" id="ownerTarikTanpaKas<?php echo (int) $h['id']; ?>" checked>
                        <label class="form-check-label fw-bold text-success" for="ownerTarikTanpaKas<?php echo (int) $h['id']; ?>">
                            <i class="bi bi-wallet2 me-1"></i>Bayar TANPA potong Kas Operasional
                        </label>
                        <div class="small text-success mt-1 ms-4">
                            ✅ Default = Hanya catat pembayaran &amp; ubah status hutang (uang pribadi owner / dibayar diluar).
                            <br>❌ Jika hapus centang = Otomatis KURANGI Saldo Kas Operasional sesuai jumlah bayar.
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-danger">Konfirmasi Simpan Pembayaran</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<script>
function transisiModal(sourceModalId, targetModalId) {
    const sourceEl = document.getElementById(sourceModalId);
    const targetEl = document.getElementById(targetModalId);

    if (!sourceEl || !targetEl || typeof bootstrap === 'undefined') {
        return;
    }

    sourceEl.addEventListener('hidden.bs.modal', function handler() {
        const targetModal = bootstrap.Modal.getInstance(targetEl) || new bootstrap.Modal(targetEl);
        targetModal.show();
    }, { once: true });

    const sourceModal = bootstrap.Modal.getInstance(sourceEl) || new bootstrap.Modal(sourceEl);
    sourceModal.hide();
}

function bukaEditHutang(id, sourceModalId) {
    transisiModal(sourceModalId, 'editHutangModal' + id);
}

function bukaBayarHutang(id, sourceModalId) {
    transisiModal(sourceModalId, 'bayarModal' + id);
}

function bukaOwnerTarikBayar(id, sourceModalId) {
    transisiModal(sourceModalId, 'ownerTarikBayarModal' + id);
}

function formatRp(value) {
    return new Intl.NumberFormat('id-ID').format(Math.round(value || 0));
}

function gantiModeKasBon(mode) {
    const kasBonMode = document.getElementById('kasBonMode');
    const wrapOwner = document.getElementById('wrapOwnerManual');
    const ownerSelect = document.getElementById('ownerSelect');
    const wrapPreview = document.getElementById('wrapPreviewBagi');
    const btnSubmit = document.getElementById('btnKasBonSubmit');

    if (!kasBonMode) return;
    kasBonMode.value = mode;

    if (mode === 'auto') {
        if (wrapOwner) wrapOwner.classList.add('d-none');
        if (ownerSelect) ownerSelect.removeAttribute('required');
        if (wrapPreview) wrapPreview.classList.remove('d-none');
        if (btnSubmit) btnSubmit.innerHTML = '<i class="bi bi-magic me-1"></i>Simpan &amp; Bagi Otomatis ke 2 Owner';
        previewBagiKasBon();
    } else {
        if (wrapOwner) wrapOwner.classList.remove('d-none');
        if (ownerSelect) ownerSelect.setAttribute('required', 'required');
        if (wrapPreview) wrapPreview.classList.add('d-none');
        if (btnSubmit) btnSubmit.textContent = 'Simpan & Tambah ke Kas Operasional';
    }
}

function previewBagiKasBon() {
    const mode = document.getElementById('kasBonMode')?.value || 'manual';
    if (mode !== 'auto') return;

    const jumlah = parseFloat(document.getElementById('jumlahKasBon')?.value || '0') || 0;
    const vanisa = Math.floor(jumlah / 2);
    const dimas = jumlah - vanisa;
    const elV = document.getElementById('previewVanisa');
    const elD = document.getElementById('previewDimas');
    if (elV) elV.textContent = formatRp(vanisa);
    if (elD) elD.textContent = formatRp(dimas);
}
</script>

<?php include '../includes/footer.php'; ?>

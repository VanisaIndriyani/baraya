<?php

function financeColumnExists($pdo, $table, $column)
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function financeNormalisasiNominal($value)
{
    if (is_int($value) || is_float($value)) {
        return (float) $value;
    }

    $value = trim((string) $value);
    if ($value === '') {
        return 0.0;
    }

    $value = preg_replace('/[^\d,.\-]/', '', $value);

    if (strpos($value, ',') !== false && strpos($value, '.') !== false) {
        if (strrpos($value, ',') > strrpos($value, '.')) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } else {
            $value = str_replace(',', '', $value);
        }
    } elseif (strpos($value, ',') !== false) {
        $value = str_replace(',', '.', $value);
    }

    return is_numeric($value) ? (float) $value : 0.0;
}

function financeEnsureBiayaWajibTable($pdo)
{
    static $sudah_dicek = false;
    if ($sudah_dicek) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS biaya_wajib (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nama_biaya VARCHAR(100) NOT NULL,
        nominal_bulanan DECIMAL(15,2) NOT NULL DEFAULT 0,
        nominal_per_hari DECIMAL(15,2) NOT NULL DEFAULT 0,
        frekuensi ENUM('bulanan','mingguan','setiap_n_hari','harian') NOT NULL DEFAULT 'bulanan',
        setiap_n_hari INT NULL DEFAULT NULL,
        urutan INT NOT NULL DEFAULT 0,
        keterangan TEXT,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");

    if (!financeColumnExists($pdo, 'biaya_wajib', 'nominal_per_hari')) {
        $pdo->exec("ALTER TABLE biaya_wajib ADD COLUMN nominal_per_hari DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER nominal_bulanan");
    }

    if (!financeColumnExists($pdo, 'biaya_wajib', 'frekuensi')) {
        $pdo->exec("ALTER TABLE biaya_wajib ADD COLUMN frekuensi ENUM('bulanan','mingguan','setiap_n_hari','harian') NOT NULL DEFAULT 'bulanan' AFTER nominal_per_hari");
    }

    if (!financeColumnExists($pdo, 'biaya_wajib', 'setiap_n_hari')) {
        $pdo->exec("ALTER TABLE biaya_wajib ADD COLUMN setiap_n_hari INT NULL DEFAULT NULL AFTER frekuensi");
    }

    if (!financeColumnExists($pdo, 'biaya_wajib', 'urutan')) {
        $pdo->exec("ALTER TABLE biaya_wajib ADD COLUMN urutan INT NOT NULL DEFAULT 0 AFTER setiap_n_hari");
    }

    if (!financeColumnExists($pdo, 'periode', 'target_laba_bulanan')) {
        try {
            $pdo->exec("ALTER TABLE periode ADD COLUMN target_laba_bulanan DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER saldo_rekening_awal");
        } catch (Throwable $e) {
            // ignore jika gagal / sudah ada dari proses lain
        }
    }

    $sudah_dicek = true;
}

function financeHitungTargetHarian($pdo, $tanggal = null)
{
    return financeGetBiayaWajibSummary($pdo, $tanggal ?: date('Y-m-d'));
}

function financeGetFrekuensiLabel($item)
{
    $frekuensi = $item['frekuensi'] ?? 'bulanan';
    $setiap_n_hari = isset($item['setiap_n_hari']) ? (int) $item['setiap_n_hari'] : null;

    switch ($frekuensi) {
        case 'bulanan':
            return '/ bulan';
        case 'mingguan':
            return '/ minggu';
        case 'harian':
            return '/ hari';
        case 'setiap_n_hari':
            return '/ ' . max(1, $setiap_n_hari ?: 1) . ' hari';
        default:
            return '/ bulan';
    }
}

function financeHitungNominalPerHari($item, $jumlah_hari_bulan = 30)
{
    if (is_array($item)) {
        $nominal = financeNormalisasiNominal($item['nominal_bulanan'] ?? 0);
        $frekuensi = (string) ($item['frekuensi'] ?? 'bulanan');
        $setiap_n_hari = isset($item['setiap_n_hari']) ? (int) $item['setiap_n_hari'] : null;
    } else {
        $nominal = financeNormalisasiNominal($item);
        $frekuensi = is_string($jumlah_hari_bulan) ? $jumlah_hari_bulan : 'bulanan';
        $setiap_n_hari = (func_num_args() >= 3) ? (int) func_get_arg(2) : null;
        $jumlah_hari_bulan = (func_num_args() >= 4) ? (int) func_get_arg(3) : 30;
    }

    $nominal_f = (float) $nominal;
    $hari_bulan = max(1, (int) $jumlah_hari_bulan);
    $n_hari = max(1, (int) ($setiap_n_hari ?? 1));

    switch ($frekuensi) {
        case 'bulanan':
            return $nominal_f / (float) $hari_bulan;
        case 'mingguan':
            return $nominal_f / 7.0;
        case 'harian':
            return $nominal_f;
        case 'setiap_n_hari':
            return $nominal_f / (float) $n_hari;
        default:
            return $nominal_f / (float) $hari_bulan;
    }
}

function financeHitungNominalBulanan($item, $jumlah_hari_bulan = 30)
{
    if (is_array($item)) {
        $nominal = financeNormalisasiNominal($item['nominal_bulanan'] ?? 0);
        $frekuensi = (string) ($item['frekuensi'] ?? 'bulanan');
        $setiap_n_hari = isset($item['setiap_n_hari']) ? (int) $item['setiap_n_hari'] : null;
    } else {
        $nominal = financeNormalisasiNominal($item);
        $frekuensi = is_string($jumlah_hari_bulan) ? $jumlah_hari_bulan : 'bulanan';
        $setiap_n_hari = (func_num_args() >= 3) ? (int) func_get_arg(2) : null;
        $jumlah_hari_bulan = (func_num_args() >= 4) ? (int) func_get_arg(3) : 30;
    }

    $nominal_f = (float) $nominal;
    $hari_bulan = max(1, (int) $jumlah_hari_bulan);
    $n_hari = max(1, (int) ($setiap_n_hari ?? 1));

    switch ($frekuensi) {
        case 'bulanan':
            return $nominal_f;
        case 'mingguan':
            return (float) $nominal_f * ((float) $hari_bulan / 7.0);
        case 'harian':
            return (float) $nominal_f * (float) $hari_bulan;
        case 'setiap_n_hari':
            return (float) $nominal_f * ((float) $hari_bulan / (float) $n_hari);
        default:
            return (float) $nominal_f;
    }
}

function financeEnsureAccountSystem($pdo)
{
    static $sudah_dicek = false;
    if ($sudah_dicek) {
        return;
    }

    if (!financeColumnExists($pdo, 'saldo_rekening', 'kode_rekening')) {
        $pdo->exec("ALTER TABLE saldo_rekening ADD COLUMN kode_rekening VARCHAR(50) NULL AFTER nama_rekening");
    }

    $stmt = $pdo->prepare("SELECT * FROM saldo_rekening WHERE kode_rekening = ? ORDER BY id ASC LIMIT 1");
    $stmt->execute(['penjualan']);
    $rekening_penjualan = $stmt->fetch();

    if (!$rekening_penjualan) {
        $rekening_pertama = $pdo->query("SELECT * FROM saldo_rekening ORDER BY id ASC LIMIT 1")->fetch();

        if ($rekening_pertama) {
            $stmt = $pdo->prepare("UPDATE saldo_rekening SET kode_rekening = ?, nama_rekening = ? WHERE id = ?");
            $nama_rekening = in_array($rekening_pertama['nama_rekening'], ['Rekening BRI', 'Rekening Penjualan', ''], true)
                ? 'Rekening Penjualan'
                : $rekening_pertama['nama_rekening'];
            $stmt->execute(['penjualan', $nama_rekening, $rekening_pertama['id']]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO saldo_rekening (nama_rekening, kode_rekening, saldo) VALUES (?, ?, 0)");
            $stmt->execute(['Rekening Penjualan', 'penjualan']);
        }
    }

    $stmt = $pdo->prepare("SELECT * FROM saldo_rekening WHERE kode_rekening = ? ORDER BY id ASC LIMIT 1");
    $stmt->execute(['operasional']);
    $rekening_operasional = $stmt->fetch();

    if (!$rekening_operasional) {
        $stmt = $pdo->prepare("INSERT INTO saldo_rekening (nama_rekening, kode_rekening, saldo) VALUES (?, ?, 0)");
        $stmt->execute(['Kas Operasional', 'operasional']);
    }

    $stmt = $pdo->prepare("SELECT * FROM saldo_rekening WHERE kode_rekening = ? ORDER BY id ASC LIMIT 1");
    $stmt->execute(['uang_ruko']);
    $rekening_ruko = $stmt->fetch();

    if (!$rekening_ruko) {
        $stmt = $pdo->prepare("INSERT INTO saldo_rekening (nama_rekening, kode_rekening, saldo) VALUES (?, ?, 0)");
        $stmt->execute(['Uang Ruko', 'uang_ruko']);
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS finance_setting (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value TEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS float_kasir_shift (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tanggal DATE NOT NULL,
        shift ENUM('pagi','sore') NOT NULL DEFAULT 'pagi',
        nominal_sistem DECIMAL(15,2) NOT NULL DEFAULT 0,
        nominal_fisik DECIMAL(15,2) NOT NULL DEFAULT 0,
        selisih DECIMAL(15,2) NOT NULL DEFAULT 0,
        keter VARCHAR(255) NULL,
        id_user INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_tanggal (tanggal),
        INDEX idx_shift (shift)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    try {
        $pdo->exec("ALTER TABLE float_kasir_shift MODIFY COLUMN shift VARCHAR(50) NULL DEFAULT NULL");
    } catch (Throwable $e) {
    }

    if (!financeColumnExists($pdo, 'float_kasir_shift', 'keter')) {
        try { $pdo->exec("ALTER TABLE float_kasir_shift ADD COLUMN keter VARCHAR(255) NULL AFTER selisih"); } catch (Throwable $e) {}
    }
    if (!financeColumnExists($pdo, 'float_kasir_shift', 'id_user')) {
        try { $pdo->exec("ALTER TABLE float_kasir_shift ADD COLUMN id_user INT NULL AFTER keter"); } catch (Throwable $e) {}
    }

    $sudah_dicek = true;
}

function financeGetAccountByCode($pdo, $kode_rekening)
{
    financeEnsureAccountSystem($pdo);

    $stmt = $pdo->prepare("SELECT * FROM saldo_rekening WHERE kode_rekening = ? ORDER BY id ASC LIMIT 1");
    $stmt->execute([$kode_rekening]);
    return $stmt->fetch();
}

function financeAdjustSaldoRekening($pdo, $id_rekening, $delta)
{
    $stmt = $pdo->prepare("UPDATE saldo_rekening SET saldo = saldo + ? WHERE id = ?");
    $stmt->execute([(float) $delta, (int) $id_rekening]);
}

function financeInsertSaldoTransaksi($pdo, $id_rekening, $id_periode, $tipe_transaksi, $jumlah, $keterangan, $tanggal, $id_penjualan = null, $id_pengeluaran = null)
{
    $stmt = $pdo->prepare("INSERT INTO saldo_rekening_transaksi (id_rekening, id_periode, tipe_transaksi, jumlah, keterangan, tanggal, id_penjualan, id_pengeluaran) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        (int) $id_rekening,
        $id_periode ?: null,
        $tipe_transaksi,
        (float) $jumlah,
        $keterangan,
        $tanggal,
        $id_penjualan ?: null,
        $id_pengeluaran ?: null
    ]);
}

function financeRollbackSaldoTransaksi($pdo, $transaksi)
{
    $delta = $transaksi['tipe_transaksi'] === 'debit'
        ? -1 * (float) $transaksi['jumlah']
        : (float) $transaksi['jumlah'];

    financeAdjustSaldoRekening($pdo, $transaksi['id_rekening'], $delta);
}

function financeDeleteLinkedPenjualanTransactions($pdo, $id_penjualan)
{
    $stmt = $pdo->prepare("SELECT * FROM saldo_rekening_transaksi WHERE id_penjualan = ? ORDER BY id ASC");
    $stmt->execute([(int) $id_penjualan]);
    $transaksi_list = $stmt->fetchAll();

    foreach ($transaksi_list as $transaksi) {
        financeRollbackSaldoTransaksi($pdo, $transaksi);
    }

    $stmt = $pdo->prepare("DELETE FROM saldo_rekening_transaksi WHERE id_penjualan = ?");
    $stmt->execute([(int) $id_penjualan]);
}

function financeGetSetting($pdo, $key, $default = null)
{
    financeEnsureAccountSystem($pdo);
    $stmt = $pdo->prepare("SELECT setting_value FROM finance_setting WHERE setting_key = ? LIMIT 1");
    $stmt->execute([(string) $key]);
    $row = $stmt->fetch();
    if (!$row || !isset($row['setting_value'])) {
        return $default;
    }
    return $row['setting_value'];
}

function financeSetSetting($pdo, $key, $value)
{
    financeEnsureAccountSystem($pdo);
    $stmt = $pdo->prepare("
        INSERT INTO finance_setting (setting_key, setting_value)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");
    $stmt->execute([(string) $key, (string) $value]);
}

function financeGetUangRukoSetting($pdo)
{
    return [
        'nominal_per_hari' => (float) financeGetSetting($pdo, 'uang_ruko_nominal_per_hari', 0),
        'is_active' => (int) financeGetSetting($pdo, 'uang_ruko_is_active', 1) === 1,
    ];
}

function financeSaveUangRukoSetting($pdo, $nominal_per_hari, $is_active)
{
    financeSetSetting($pdo, 'uang_ruko_nominal_per_hari', (float) $nominal_per_hari);
    financeSetSetting($pdo, 'uang_ruko_is_active', $is_active ? '1' : '0');
}

function financeGetUangRukoDailySummary($pdo, $tanggal)
{
    $setting = financeGetUangRukoSetting($pdo);
    $rekening = financeGetAccountByCode($pdo, 'uang_ruko');
    $target_harian = $setting['is_active'] ? (float) $setting['nominal_per_hari'] : 0.0;

    $teralokasi = 0.0;
    if ($rekening) {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(jumlah), 0)
            FROM saldo_rekening_transaksi
            WHERE id_rekening = ? AND tanggal = ? AND tipe_transaksi = 'debit'
        ");
        $stmt->execute([(int) $rekening['id'], $tanggal]);
        $teralokasi = (float) $stmt->fetchColumn();
    }

    $sisa = max(0, $target_harian - $teralokasi);
    return [
        'setting' => $setting,
        'rekening' => $rekening,
        'target_harian' => $target_harian,
        'teralokasi_hari_ini' => $teralokasi,
        'sisa_target_hari_ini' => $sisa,
        'saldo_rekening' => (float) ($rekening['saldo'] ?? 0),
    ];
}

function financeGetBiayaWajibSummary($pdo, $tanggal)
{
    financeEnsureBiayaWajibTable($pdo);
    financeEnsureAccountSystem($pdo);

    $tanggal_obj = new DateTime($tanggal);
    $jumlah_hari = (int) $tanggal_obj->format('t');

    $biaya_wajib = $pdo->query("SELECT * FROM biaya_wajib WHERE is_active = 1 ORDER BY nama_biaya ASC")->fetchAll();
    $total_bulanan = 0.0;
    $target_harian = 0.0;

    foreach ($biaya_wajib as $item) {
        $total_bulanan += financeHitungNominalBulanan($item, $jumlah_hari);
        $target_harian += financeHitungNominalPerHari($item, $jumlah_hari);
    }

    $rekening_operasional = financeGetAccountByCode($pdo, 'operasional');
    $teralokasi_hari_ini = 0.0;

    if ($rekening_operasional) {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(jumlah), 0)
            FROM saldo_rekening_transaksi
            WHERE id_rekening = ?
              AND id_penjualan IS NOT NULL
              AND tipe_transaksi = 'debit'
              AND tanggal = ?
              AND keterangan LIKE 'Alokasi Kas Wajib Harian%'
        ");
        $stmt->execute([(int) $rekening_operasional['id'], $tanggal]);
        $teralokasi_hari_ini = financeNormalisasiNominal($stmt->fetchColumn());
    }

    return [
        'tanggal' => $tanggal,
        'jumlah_hari_bulan' => $jumlah_hari,
        'biaya_wajib' => $biaya_wajib,
        'jumlah_item' => count($biaya_wajib),
        'jumlah_biaya' => count($biaya_wajib),
        'total_bulanan' => $total_bulanan,
        'target_harian' => $target_harian,
        'teralokasi_hari_ini' => $teralokasi_hari_ini,
        'sisa_target_hari_ini' => max(0, $target_harian - $teralokasi_hari_ini)
    ];
}

function financeGetBiayaWajibSummaryByMonth($pdo, $bulan)
{
    financeEnsureBiayaWajibTable($pdo);

    if (!preg_match('/^\d{4}-\d{2}$/', (string) $bulan)) {
        $bulan = date('Y-m');
    }

    $tanggal_awal = new DateTime($bulan . '-01');
    $jumlah_hari = (int) $tanggal_awal->format('t');
    $biaya_wajib = $pdo->query("SELECT * FROM biaya_wajib WHERE is_active = 1 ORDER BY nama_biaya ASC")->fetchAll();
    $total_bulanan = 0.0;
    $target_harian = 0.0;

    foreach ($biaya_wajib as $item) {
        $total_bulanan += financeHitungNominalBulanan($item, $jumlah_hari);
        $target_harian += financeHitungNominalPerHari($item, $jumlah_hari);
    }

    return [
        'bulan' => $bulan,
        'tanggal_awal' => $tanggal_awal->format('Y-m-01'),
        'tanggal_akhir' => $tanggal_awal->format('Y-m-t'),
        'jumlah_hari_bulan' => $jumlah_hari,
        'biaya_wajib' => $biaya_wajib,
        'jumlah_item' => count($biaya_wajib),
        'total_bulanan' => $total_bulanan,
        'target_harian' => $target_harian
    ];
}

function financeRebuildPenjualanAllocationsByDate($pdo, $tanggal)
{
    financeEnsureAccountSystem($pdo);
    financeEnsureBiayaWajibTable($pdo);

    $tanggal = date('Y-m-d', strtotime($tanggal));

    $stmt = $pdo->prepare("SELECT * FROM penjualan WHERE tanggal = ? ORDER BY created_at ASC, id ASC");
    $stmt->execute([$tanggal]);
    $penjualan_harian = $stmt->fetchAll();

    if (empty($penjualan_harian)) {
        return;
    }

    $id_penjualan_list = array_map(static function ($item) {
        return (int) $item['id'];
    }, $penjualan_harian);

    $placeholder = implode(',', array_fill(0, count($id_penjualan_list), '?'));
    $stmt = $pdo->prepare("SELECT * FROM saldo_rekening_transaksi WHERE id_penjualan IN ($placeholder) ORDER BY id ASC");
    $stmt->execute($id_penjualan_list);
    $transaksi_lama = $stmt->fetchAll();

    foreach ($transaksi_lama as $transaksi) {
        financeRollbackSaldoTransaksi($pdo, $transaksi);
    }

    $stmt = $pdo->prepare("DELETE FROM saldo_rekening_transaksi WHERE id_penjualan IN ($placeholder)");
    $stmt->execute($id_penjualan_list);

    $rekening_operasional = financeGetAccountByCode($pdo, 'operasional');
    $rekening_penjualan = financeGetAccountByCode($pdo, 'penjualan');
    $rekening_ruko = financeGetAccountByCode($pdo, 'uang_ruko');
    $summary = financeGetBiayaWajibSummary($pdo, $tanggal);
    $summary_ruko = financeGetUangRukoDailySummary($pdo, $tanggal);
    $target_harian = $summary['target_harian'];
    $target_ruko_harian = $summary_ruko['target_harian'];
    $total_alokasi = 0.0;
    $total_alokasi_ruko = 0.0;

    foreach ($penjualan_harian as $penjualan) {
        $total_penjualan = financeNormalisasiNominal($penjualan['total_pendapatan'] ?? 0);
        $catatan = trim((string) ($penjualan['catatan'] ?? ''));
        $sisa_penjualan = $total_penjualan;

        if ($rekening_ruko && $target_ruko_harian > 0) {
            $alokasi_ruko = min($sisa_penjualan, max(0, $target_ruko_harian - $total_alokasi_ruko));
            if ($alokasi_ruko > 0) {
                $keterangan_ruko = 'Alokasi Uang Ruko Wajib Harian';
                if ($catatan !== '') {
                    $keterangan_ruko .= ' - ' . $catatan;
                }

                financeInsertSaldoTransaksi(
                    $pdo,
                    $rekening_ruko['id'],
                    $penjualan['id_periode'] ?? null,
                    'debit',
                    $alokasi_ruko,
                    $keterangan_ruko,
                    $tanggal,
                    $penjualan['id'],
                    null
                );
                financeAdjustSaldoRekening($pdo, $rekening_ruko['id'], $alokasi_ruko);
                $total_alokasi_ruko += $alokasi_ruko;
                $sisa_penjualan -= $alokasi_ruko;
            }
        }

        $alokasi_operasional = 0.0;
        if ($rekening_operasional && $sisa_penjualan > 0 && $target_harian > 0) {
            $alokasi_operasional = min($sisa_penjualan, max(0, $target_harian - $total_alokasi));
        }
        $alokasi_penjualan = $sisa_penjualan - $alokasi_operasional;

        if ($rekening_operasional && $alokasi_operasional > 0) {
            $keterangan_operasional = 'Alokasi Kas Wajib Harian';
            if ($catatan !== '') {
                $keterangan_operasional .= ' - ' . $catatan;
            }

            financeInsertSaldoTransaksi(
                $pdo,
                $rekening_operasional['id'],
                $penjualan['id_periode'] ?? null,
                'debit',
                $alokasi_operasional,
                $keterangan_operasional,
                $tanggal,
                $penjualan['id'],
                null
            );
            financeAdjustSaldoRekening($pdo, $rekening_operasional['id'], $alokasi_operasional);
            $total_alokasi += $alokasi_operasional;
        }

        if ($rekening_penjualan && $alokasi_penjualan > 0) {
            financeInsertSaldoTransaksi(
                $pdo,
                $rekening_penjualan['id'],
                $penjualan['id_periode'] ?? null,
                'debit',
                $alokasi_penjualan,
                $catatan !== '' ? $catatan : 'Penjualan',
                $tanggal,
                $penjualan['id'],
                null
            );
            financeAdjustSaldoRekening($pdo, $rekening_penjualan['id'], $alokasi_penjualan);
        }
    }
}

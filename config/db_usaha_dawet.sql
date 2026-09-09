-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Aug 01, 2026 at 07:21 AM
-- Server version: 8.4.3
-- PHP Version: 8.3.26

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `db_usaha_dawet`
--

-- --------------------------------------------------------

--
-- Table structure for table `barang`
--

CREATE TABLE `barang` (
  `id` int NOT NULL,
  `nama` varchar(100) NOT NULL,
  `kategori` varchar(50) NOT NULL,
  `id_supplier` int DEFAULT NULL,
  `harga_beli` decimal(15,2) NOT NULL,
  `satuan` varchar(20) NOT NULL,
  `stok` int DEFAULT '0',
  `min_stok` int DEFAULT '0',
  `foto` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `beli_harian`
--

CREATE TABLE `beli_harian` (
  `id` int NOT NULL,
  `id_periode` int DEFAULT NULL,
  `nama_barang` varchar(200) NOT NULL,
  `qty` decimal(10,2) NOT NULL DEFAULT '0.00',
  `satuan` varchar(20) NOT NULL DEFAULT 'kg',
  `harga` decimal(15,2) NOT NULL DEFAULT '0.00',
  `subtotal` decimal(15,2) NOT NULL DEFAULT '0.00',
  `keterangan` varchar(255) DEFAULT NULL,
  `tanggal` date NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `biaya_wajib`
--

CREATE TABLE `biaya_wajib` (
  `id` int NOT NULL,
  `nama_biaya` varchar(100) NOT NULL,
  `nominal_bulanan` decimal(15,2) NOT NULL DEFAULT '0.00',
  `nominal_per_hari` decimal(15,2) NOT NULL DEFAULT '0.00',
  `frekuensi` enum('bulanan','mingguan','setiap_n_hari','harian') NOT NULL DEFAULT 'bulanan',
  `setiap_n_hari` int DEFAULT NULL,
  `urutan` int NOT NULL DEFAULT '0',
  `keterangan` text,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `biaya_wajib`
--

INSERT INTO `biaya_wajib` (`id`, `nama_biaya`, `nominal_bulanan`, `nominal_per_hari`, `frekuensi`, `setiap_n_hari`, `urutan`, `keterangan`, `is_active`, `created_at`, `updated_at`) VALUES
(15, 'skm', 287500.00, 0.00, 'bulanan', NULL, 0, '25 kaleng skm selma sebulan', 1, '2026-07-31 15:39:01', '2026-07-31 15:39:01'),
(16, 'wifi', 200000.00, 0.00, 'bulanan', NULL, 0, '', 1, '2026-07-31 15:39:19', '2026-07-31 15:39:19'),
(17, 'alpukat', 69000.00, 0.00, 'mingguan', NULL, 0, 'alpukat 3 kg per minggu 23.000', 1, '2026-07-31 15:41:04', '2026-07-31 15:42:07'),
(18, 'RUKO PERTAHUN', 1250000.00, 0.00, 'bulanan', NULL, 0, 'RUKO PERBULAN 1.250.000', 1, '2026-07-31 15:43:57', '2026-07-31 15:43:57'),
(19, 'LISTRIK', 200000.00, 0.00, 'bulanan', NULL, 0, 'LISTRIK PERBULAN', 1, '2026-07-31 15:44:45', '2026-07-31 15:44:45'),
(20, 'DAGING DURIAN', 150000.00, 0.00, 'mingguan', NULL, 0, 'DURIAN PERMINGGU 3 KG', 1, '2026-07-31 15:45:45', '2026-07-31 15:45:45'),
(21, 'NANGKA', 70000.00, 0.00, 'setiap_n_hari', 10, 0, 'NANGKA PER 10 HARI 2 KG', 1, '2026-07-31 15:47:20', '2026-07-31 15:47:20'),
(22, 'SUSU UHT', 373000.00, 0.00, 'bulanan', NULL, 0, 'SUSU UHT PER BULAN 20 LITER', 1, '2026-07-31 15:48:31', '2026-08-01 06:56:46'),
(23, 'AGER AGER', 5000.00, 0.00, 'setiap_n_hari', 2, 0, '', 1, '2026-07-31 15:49:35', '2026-07-31 15:49:35'),
(24, 'CREAMER', 420000.00, 0.00, 'bulanan', NULL, 0, 'CREAMER 15 KG DALAM SEBULAN PER  KG 28.000', 1, '2026-07-31 15:52:20', '2026-07-31 15:52:20'),
(25, 'PISANG', 20000.00, 0.00, 'mingguan', NULL, 0, '', 1, '2026-07-31 15:53:16', '2026-07-31 15:53:16'),
(26, 'KEJU', 12000.00, 0.00, 'mingguan', NULL, 0, '', 1, '2026-07-31 15:54:19', '2026-07-31 15:54:19'),
(27, 'TOPPING PISANG', 10000.00, 0.00, 'setiap_n_hari', 10, 0, 'COKLAT TIRAMIISUE CARAMEL PERHARI 10.000', 1, '2026-07-31 15:56:24', '2026-07-31 15:56:24'),
(28, 'GULA PASIR', 34000.00, 0.00, 'setiap_n_hari', 10, 0, '2 KG GULA PASIR', 1, '2026-07-31 15:57:20', '2026-07-31 15:57:20'),
(29, 'GULA MERAH', 51000.00, 0.00, 'bulanan', NULL, 0, 'GULA MERAH 3 KG', 1, '2026-07-31 15:58:01', '2026-07-31 15:58:01'),
(30, 'es batu', 10000.00, 0.00, 'harian', NULL, 0, 'wajib beli es batu', 1, '2026-08-01 06:57:06', '2026-08-01 06:57:06'),
(31, 'sabun cuci piring', 17500.00, 0.00, 'bulanan', NULL, 0, '', 1, '2026-08-01 06:57:44', '2026-08-01 06:57:44'),
(32, 'sabun cuci piring', 17500.00, 0.00, 'bulanan', NULL, 0, '', 1, '2026-08-01 06:57:52', '2026-08-01 06:57:52');

-- --------------------------------------------------------

--
-- Table structure for table `catatan_bulanan`
--

CREATE TABLE `catatan_bulanan` (
  `id` int NOT NULL,
  `id_periode` int DEFAULT NULL,
  `periode_bulan` char(7) NOT NULL,
  `tanggal` date NOT NULL,
  `kategori` varchar(50) NOT NULL,
  `judul` varchar(150) NOT NULL,
  `nominal` decimal(15,2) NOT NULL DEFAULT '0.00',
  `status_bayar` enum('belum_bayar','sudah_bayar') NOT NULL DEFAULT 'sudah_bayar',
  `catatan` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `float_kasir`
--

CREATE TABLE `float_kasir` (
  `id` int NOT NULL,
  `saldo_awal` decimal(15,2) NOT NULL,
  `saldo_sekarang` decimal(15,2) NOT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `float_kasir_shift`
--

CREATE TABLE `float_kasir_shift` (
  `id` int NOT NULL,
  `tanggal` date NOT NULL,
  `shift` varchar(50) DEFAULT NULL,
  `nominal_sistem` decimal(15,2) NOT NULL DEFAULT '0.00',
  `nominal_fisik` decimal(15,2) NOT NULL DEFAULT '0.00',
  `selisih` decimal(15,2) NOT NULL DEFAULT '0.00',
  `keter` varchar(255) DEFAULT NULL,
  `id_user` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `gaji_owner`
--

CREATE TABLE `gaji_owner` (
  `id` int NOT NULL,
  `owner_key` enum('vanisa','dimas') NOT NULL,
  `bulan` char(7) NOT NULL COMMENT 'Format YYYY-MM',
  `nominal` decimal(15,2) NOT NULL DEFAULT '0.00',
  `keterangan` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `hutang_owner`
--

CREATE TABLE `hutang_owner` (
  `id` int NOT NULL,
  `id_periode` int DEFAULT NULL,
  `jumlah` decimal(15,2) NOT NULL,
  `keterangan` text,
  `tanggal` date NOT NULL,
  `tanggal_jatuh_tempo` date DEFAULT NULL,
  `status` enum('belum_lunas','lunas') DEFAULT 'belum_lunas',
  `tipe` enum('manual','pembelian') DEFAULT 'manual',
  `tipe_hutang` enum('owner','owner_vanisa','owner_dimas','kasir') DEFAULT 'owner',
  `id_pembelian` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `hutang_owner_pembayaran`
--

CREATE TABLE `hutang_owner_pembayaran` (
  `id` int NOT NULL,
  `id_hutang` int NOT NULL,
  `jumlah` decimal(15,2) NOT NULL,
  `tanggal` date NOT NULL,
  `keterangan` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `modal_owner`
--

CREATE TABLE `modal_owner` (
  `id` int NOT NULL,
  `id_periode` int DEFAULT NULL,
  `owner_key` enum('vanisa','dimas') NOT NULL,
  `periode_bulan` char(7) NOT NULL,
  `modal_awal` decimal(15,2) NOT NULL DEFAULT '0.00',
  `penghasilan` decimal(15,2) NOT NULL DEFAULT '0.00',
  `gaji` decimal(15,2) NOT NULL DEFAULT '0.00',
  `keterangan` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pemakaian`
--

CREATE TABLE `pemakaian` (
  `id` int NOT NULL,
  `id_barang` int NOT NULL,
  `qty` int NOT NULL,
  `catatan` text,
  `tanggal` date NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `pembelian`
--

CREATE TABLE `pembelian` (
  `id` int NOT NULL,
  `id_periode` int DEFAULT NULL,
  `id_supplier` int DEFAULT NULL,
  `total` decimal(15,2) NOT NULL,
  `metode_pembayaran` enum('kas_operasional','bri','owner_kredit','owner_cash') NOT NULL,
  `nota` varchar(255) DEFAULT NULL,
  `tanggal` date NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `pembelian_detail`
--

CREATE TABLE `pembelian_detail` (
  `id` int NOT NULL,
  `id_pembelian` int NOT NULL,
  `id_barang` int NOT NULL,
  `qty` int NOT NULL,
  `harga` decimal(15,2) NOT NULL,
  `subtotal` decimal(15,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `pengeluaran`
--

CREATE TABLE `pengeluaran` (
  `id` int NOT NULL,
  `id_periode` int DEFAULT NULL,
  `kategori` varchar(50) NOT NULL,
  `jumlah` decimal(15,2) NOT NULL,
  `keterangan` text,
  `bukti` varchar(255) DEFAULT NULL,
  `tanggal` date NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `penjualan`
--

CREATE TABLE `penjualan` (
  `id` int NOT NULL,
  `id_periode` int DEFAULT NULL,
  `total_pendapatan` decimal(15,2) NOT NULL,
  `catatan` text,
  `foto` varchar(255) DEFAULT NULL,
  `tanggal` date NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `periode`
--

CREATE TABLE `periode` (
  `id` int NOT NULL,
  `nama_periode` varchar(100) NOT NULL,
  `tanggal_mulai` date NOT NULL,
  `tanggal_berakhir` date DEFAULT NULL,
  `saldo_owner_awal` decimal(15,2) NOT NULL DEFAULT '0.00',
  `saldo_kasir_awal` decimal(15,2) NOT NULL DEFAULT '0.00',
  `saldo_rekening_awal` decimal(15,2) NOT NULL DEFAULT '0.00',
  `target_laba_bulanan` decimal(15,2) NOT NULL DEFAULT '0.00',
  `is_active` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

--
-- Dumping data for table `periode`
--

INSERT INTO `periode` (`id`, `nama_periode`, `tanggal_mulai`, `tanggal_berakhir`, `saldo_owner_awal`, `saldo_kasir_awal`, `saldo_rekening_awal`, `target_laba_bulanan`, `is_active`, `created_at`) VALUES
(1, 'Periode Juli 2026', '2026-07-01', '2026-07-31', 10000000.00, 500000.00, 0.00, 5000000.00, 1, '2026-07-31 07:20:50');

-- --------------------------------------------------------

--
-- Table structure for table `saldo_rekening`
--

CREATE TABLE `saldo_rekening` (
  `id` int NOT NULL,
  `nama_rekening` varchar(100) NOT NULL,
  `kode_rekening` varchar(50) DEFAULT NULL,
  `saldo` decimal(15,2) NOT NULL DEFAULT '0.00',
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

--
-- Dumping data for table `saldo_rekening`
--

INSERT INTO `saldo_rekening` (`id`, `nama_rekening`, `kode_rekening`, `saldo`, `updated_at`) VALUES
(5, 'Rekening Penjualan', 'penjualan', 0.00, '2026-08-01 06:58:30'),
(6, 'Kas Operasional', 'operasional', 0.00, '2026-08-01 06:58:30');

-- --------------------------------------------------------

--
-- Table structure for table `saldo_rekening_transaksi`
--

CREATE TABLE `saldo_rekening_transaksi` (
  `id` int NOT NULL,
  `id_rekening` int NOT NULL,
  `id_periode` int DEFAULT NULL,
  `tipe_transaksi` enum('debit','kredit') NOT NULL,
  `jumlah` decimal(15,2) NOT NULL,
  `keterangan` text,
  `tanggal` date NOT NULL,
  `id_penjualan` int DEFAULT NULL,
  `id_pengeluaran` int DEFAULT NULL,
  `id_pembelian` int DEFAULT NULL,
  `id_beli_harian` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `supplier`
--

CREATE TABLE `supplier` (
  `id` int NOT NULL,
  `nama` varchar(100) NOT NULL,
  `no_hp` varchar(20) DEFAULT NULL,
  `alamat` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `nama` varchar(100) NOT NULL,
  `role` enum('admin','owner') DEFAULT 'admin',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `nama`, `role`, `created_at`) VALUES
(2, 'admin', '$2y$10$ic3qBrWqyqcVcUZKUbbyfOg0eDp2qhGRinlJn85o4XXFBxnx0GrfW', 'Admin Baraya Dawet', 'admin', '2026-07-31 07:01:50');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `barang`
--
ALTER TABLE `barang`
  ADD PRIMARY KEY (`id`),
  ADD KEY `id_supplier` (`id_supplier`);

--
-- Indexes for table `beli_harian`
--
ALTER TABLE `beli_harian`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_tanggal` (`tanggal`),
  ADD KEY `idx_periode` (`id_periode`);

--
-- Indexes for table `biaya_wajib`
--
ALTER TABLE `biaya_wajib`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `catatan_bulanan`
--
ALTER TABLE `catatan_bulanan`
  ADD PRIMARY KEY (`id`),
  ADD KEY `id_periode` (`id_periode`);

--
-- Indexes for table `float_kasir`
--
ALTER TABLE `float_kasir`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `float_kasir_shift`
--
ALTER TABLE `float_kasir_shift`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_tanggal` (`tanggal`),
  ADD KEY `idx_shift` (`shift`);

--
-- Indexes for table `gaji_owner`
--
ALTER TABLE `gaji_owner`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_gaji_owner` (`owner_key`,`bulan`);

--
-- Indexes for table `hutang_owner`
--
ALTER TABLE `hutang_owner`
  ADD PRIMARY KEY (`id`),
  ADD KEY `id_periode` (`id_periode`),
  ADD KEY `id_pembelian` (`id_pembelian`);

--
-- Indexes for table `hutang_owner_pembayaran`
--
ALTER TABLE `hutang_owner_pembayaran`
  ADD PRIMARY KEY (`id`),
  ADD KEY `id_hutang` (`id_hutang`);

--
-- Indexes for table `modal_owner`
--
ALTER TABLE `modal_owner`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_owner_periode` (`owner_key`,`periode_bulan`),
  ADD KEY `fk_modal_owner_periode` (`id_periode`);

--
-- Indexes for table `pemakaian`
--
ALTER TABLE `pemakaian`
  ADD PRIMARY KEY (`id`),
  ADD KEY `id_barang` (`id_barang`);

--
-- Indexes for table `pembelian`
--
ALTER TABLE `pembelian`
  ADD PRIMARY KEY (`id`),
  ADD KEY `id_periode` (`id_periode`),
  ADD KEY `id_supplier` (`id_supplier`);

--
-- Indexes for table `pembelian_detail`
--
ALTER TABLE `pembelian_detail`
  ADD PRIMARY KEY (`id`),
  ADD KEY `id_pembelian` (`id_pembelian`),
  ADD KEY `id_barang` (`id_barang`);

--
-- Indexes for table `pengeluaran`
--
ALTER TABLE `pengeluaran`
  ADD PRIMARY KEY (`id`),
  ADD KEY `id_periode` (`id_periode`);

--
-- Indexes for table `penjualan`
--
ALTER TABLE `penjualan`
  ADD PRIMARY KEY (`id`),
  ADD KEY `id_periode` (`id_periode`);

--
-- Indexes for table `periode`
--
ALTER TABLE `periode`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `saldo_rekening`
--
ALTER TABLE `saldo_rekening`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `saldo_rekening_transaksi`
--
ALTER TABLE `saldo_rekening_transaksi`
  ADD PRIMARY KEY (`id`),
  ADD KEY `id_rekening` (`id_rekening`),
  ADD KEY `id_periode` (`id_periode`),
  ADD KEY `id_penjualan` (`id_penjualan`),
  ADD KEY `id_pengeluaran` (`id_pengeluaran`);

--
-- Indexes for table `supplier`
--
ALTER TABLE `supplier`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `barang`
--
ALTER TABLE `barang`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `beli_harian`
--
ALTER TABLE `beli_harian`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `biaya_wajib`
--
ALTER TABLE `biaya_wajib`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=33;

--
-- AUTO_INCREMENT for table `catatan_bulanan`
--
ALTER TABLE `catatan_bulanan`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `float_kasir`
--
ALTER TABLE `float_kasir`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `float_kasir_shift`
--
ALTER TABLE `float_kasir_shift`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `gaji_owner`
--
ALTER TABLE `gaji_owner`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `hutang_owner`
--
ALTER TABLE `hutang_owner`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `hutang_owner_pembayaran`
--
ALTER TABLE `hutang_owner_pembayaran`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `modal_owner`
--
ALTER TABLE `modal_owner`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pemakaian`
--
ALTER TABLE `pemakaian`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pembelian`
--
ALTER TABLE `pembelian`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pembelian_detail`
--
ALTER TABLE `pembelian_detail`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pengeluaran`
--
ALTER TABLE `pengeluaran`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `penjualan`
--
ALTER TABLE `penjualan`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `periode`
--
ALTER TABLE `periode`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `saldo_rekening`
--
ALTER TABLE `saldo_rekening`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `saldo_rekening_transaksi`
--
ALTER TABLE `saldo_rekening_transaksi`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `supplier`
--
ALTER TABLE `supplier`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `barang`
--
ALTER TABLE `barang`
  ADD CONSTRAINT `barang_ibfk_1` FOREIGN KEY (`id_supplier`) REFERENCES `supplier` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `catatan_bulanan`
--
ALTER TABLE `catatan_bulanan`
  ADD CONSTRAINT `catatan_bulanan_ibfk_1` FOREIGN KEY (`id_periode`) REFERENCES `periode` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `hutang_owner`
--
ALTER TABLE `hutang_owner`
  ADD CONSTRAINT `hutang_owner_ibfk_1` FOREIGN KEY (`id_periode`) REFERENCES `periode` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `hutang_owner_ibfk_2` FOREIGN KEY (`id_pembelian`) REFERENCES `pembelian` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `hutang_owner_pembayaran`
--
ALTER TABLE `hutang_owner_pembayaran`
  ADD CONSTRAINT `hutang_owner_pembayaran_ibfk_1` FOREIGN KEY (`id_hutang`) REFERENCES `hutang_owner` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `modal_owner`
--
ALTER TABLE `modal_owner`
  ADD CONSTRAINT `fk_modal_owner_periode` FOREIGN KEY (`id_periode`) REFERENCES `periode` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `pemakaian`
--
ALTER TABLE `pemakaian`
  ADD CONSTRAINT `pemakaian_ibfk_1` FOREIGN KEY (`id_barang`) REFERENCES `barang` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `pembelian`
--
ALTER TABLE `pembelian`
  ADD CONSTRAINT `pembelian_ibfk_1` FOREIGN KEY (`id_periode`) REFERENCES `periode` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `pembelian_ibfk_2` FOREIGN KEY (`id_supplier`) REFERENCES `supplier` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `pembelian_detail`
--
ALTER TABLE `pembelian_detail`
  ADD CONSTRAINT `pembelian_detail_ibfk_1` FOREIGN KEY (`id_pembelian`) REFERENCES `pembelian` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `pembelian_detail_ibfk_2` FOREIGN KEY (`id_barang`) REFERENCES `barang` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `pengeluaran`
--
ALTER TABLE `pengeluaran`
  ADD CONSTRAINT `pengeluaran_ibfk_1` FOREIGN KEY (`id_periode`) REFERENCES `periode` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `penjualan`
--
ALTER TABLE `penjualan`
  ADD CONSTRAINT `penjualan_ibfk_1` FOREIGN KEY (`id_periode`) REFERENCES `periode` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `saldo_rekening_transaksi`
--
ALTER TABLE `saldo_rekening_transaksi`
  ADD CONSTRAINT `saldo_rekening_transaksi_ibfk_1` FOREIGN KEY (`id_rekening`) REFERENCES `saldo_rekening` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `saldo_rekening_transaksi_ibfk_2` FOREIGN KEY (`id_periode`) REFERENCES `periode` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `saldo_rekening_transaksi_ibfk_3` FOREIGN KEY (`id_penjualan`) REFERENCES `penjualan` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `saldo_rekening_transaksi_ibfk_4` FOREIGN KEY (`id_pengeluaran`) REFERENCES `pengeluaran` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

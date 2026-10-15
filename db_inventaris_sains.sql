-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 14, 2026 at 02:39 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `db_inventaris_sains`
--

DELIMITER $$
--
-- Procedures
--
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_create_borrowing` (IN `p_borrower_id` INT, IN `p_user_id` INT, IN `p_borrow_type` ENUM('internal_lab','eksternal'), IN `p_due_date` DATETIME, IN `p_notes` TEXT, IN `p_item_id` INT, IN `p_quantity` INT, IN `p_condition` ENUM('baik','rusak_ringan','rusak_berat'))   BEGIN
    DECLARE v_borrowing_id INT;
    DECLARE v_available_qty INT;

    START TRANSACTION;

    -- Cek ketersediaan stok barang
    SELECT CASE p_condition
      WHEN 'baik' THEN good_quantity
      WHEN 'rusak_ringan' THEN light_damage_quantity
      ELSE heavy_damage_quantity
    END INTO v_available_qty
    FROM items WHERE id = p_item_id FOR UPDATE;

    IF v_available_qty < p_quantity THEN
        ROLLBACK;
        SIGNAL SQLSTATE '45000' 
        SET MESSAGE_TEXT = 'Gagal: Stok barang tersedia tidak mencukupi!';
    ELSE
        -- 1. Insert ke tabel borrowings
        INSERT INTO borrowings (borrower_id, user_id, borrow_type, borrow_date, due_date, status, notes)
        VALUES (p_borrower_id, p_user_id, p_borrow_type, NOW(), p_due_date, 'dipinjam', p_notes);

        SET v_borrowing_id = LAST_INSERT_ID();

        -- 2. Insert ke tabel borrowing_details (Menyimpan kondisi awal)
        INSERT INTO borrowing_details (borrowing_id, item_id, quantity, item_condition_before, status_item)
        VALUES (v_borrowing_id, p_item_id, p_quantity, p_condition, 'dipinjam');

        -- 3. Update stok tersedia di tabel items
        UPDATE items
        SET good_quantity = good_quantity - IF(p_condition = 'baik', p_quantity, 0),
          light_damage_quantity = light_damage_quantity - IF(p_condition = 'rusak_ringan', p_quantity, 0),
          heavy_damage_quantity = heavy_damage_quantity - IF(p_condition = 'rusak_berat', p_quantity, 0),
          available_quantity = available_quantity - p_quantity,
          status = CASE WHEN (available_quantity - p_quantity) = 0 THEN 'dipinjam' ELSE 'tersedia' END
        WHERE id = p_item_id;

        -- 4. Catat ke tabel item_logs
        INSERT INTO item_logs (item_id, user_id, action, description)
        VALUES (p_item_id, p_user_id, 'perubahan_kondisi', CONCAT('Dipinjam sebanyak ', p_quantity, ' unit (Kondisi Awal: ', p_condition, ')'));

        COMMIT;
    END IF;
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `borrowers`
--

CREATE TABLE `borrowers` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `identity_number` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `type` enum('siswa','mahasiswa','peneliti','guru_dosen','umum') NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `department` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `borrowers`
--

INSERT INTO `borrowers` (`id`, `user_id`, `identity_number`, `name`, `type`, `phone`, `department`, `created_at`, `updated_at`) VALUES
(1, 3, 'M0321001', 'Budi Santoso', 'mahasiswa', '081234567801', 'Teknik Kimia', '2026-10-14 01:14:51', '2026-10-14 01:14:51'),
(2, 4, 'M0321002', 'Dewi Lestari', 'mahasiswa', '081234567802', 'Biologi', '2026-10-14 01:14:51', '2026-10-14 01:14:51'),
(3, 5, 'M0321003', 'Eko Prasetyo', 'mahasiswa', '081234567803', 'Teknik Informatika', '2026-10-14 01:14:51', '2026-10-14 01:14:51'),
(4, 6, 'M0321004', 'Fajar Nugroho', 'mahasiswa', '081234567804', 'Fisika', '2026-10-14 01:14:51', '2026-10-14 01:14:51'),
(5, 7, 'M0321005', 'Gita Gutawa', 'mahasiswa', '081234567805', 'Farmasi', '2026-10-14 01:14:51', '2026-10-14 01:14:51'),
(6, 8, 'S123001', 'Hadi Wijaya', 'siswa', '081234567806', 'Kelas XII MIPA 1', '2026-10-14 01:14:51', '2026-10-14 01:14:51'),
(7, 9, 'S123002', 'Indah Permata', 'siswa', '081234567807', 'Kelas XII MIPA 2', '2026-10-14 01:14:51', '2026-10-14 01:14:51'),
(8, 10, 'S123003', 'Joko Anwar', 'siswa', '081234567808', 'Kelas XII MIPA 3', '2026-10-14 01:14:51', '2026-10-14 01:14:51'),
(9, NULL, 'EXT001', 'Dr. Hendra Peneliti Luar', 'peneliti', '081234567809', 'Lembaga Riset Nasional', '2026-10-14 01:14:51', '2026-10-14 01:14:51'),
(10, NULL, 'EXT002', 'Prof. Retno Tamu', 'guru_dosen', '081234567810', 'Universitas Mitra', '2026-10-14 01:14:51', '2026-10-14 01:14:51');

-- --------------------------------------------------------

--
-- Table structure for table `borrowings`
--

CREATE TABLE `borrowings` (
  `id` int(11) NOT NULL,
  `borrower_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `borrow_type` enum('internal_lab','eksternal') NOT NULL,
  `borrow_date` datetime NOT NULL,
  `due_date` datetime NOT NULL,
  `return_date` datetime DEFAULT NULL,
  `status` enum('pending','dipinjam','dikembalikan','terlambat','hilang') DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `borrowings`
--

INSERT INTO `borrowings` (`id`, `borrower_id`, `user_id`, `borrow_type`, `borrow_date`, `due_date`, `return_date`, `status`, `notes`, `created_at`, `updated_at`) VALUES
(1, 1, 2, 'internal_lab', '2026-10-01 08:00:00', '2026-10-03 15:00:00', '2026-10-03 10:00:00', 'dikembalikan', 'Praktikum Kimia Dasar', '2026-10-14 01:14:51', '2026-10-14 01:14:51'),
(2, 2, 2, 'internal_lab', '2026-10-02 09:00:00', '2026-10-04 15:00:00', '2026-10-04 14:00:00', 'dikembalikan', 'Praktikum Biologi Sel (Mikroskop)', '2026-10-14 01:14:51', '2026-10-14 01:14:51'),
(3, 3, 2, 'internal_lab', '2026-10-05 10:00:00', '2026-10-07 15:00:00', '2026-10-14 11:10:31', 'dikembalikan', 'Pengujian alat elektronik sensor', '2026-10-14 01:14:51', '2026-10-14 04:10:31'),
(4, 4, 2, 'internal_lab', '2026-10-06 08:30:00', '2026-10-08 15:00:00', '2026-10-14 08:52:09', 'dikembalikan', 'Praktikum Optik & Lensa', '2026-10-14 01:14:51', '2026-10-14 01:52:09'),
(5, 5, 2, 'internal_lab', '2026-10-07 11:00:00', '2026-10-09 15:00:00', '2026-10-14 12:58:53', 'dikembalikan', 'Meracik resep farmasi & bahan HCl', '2026-10-14 01:14:51', '2026-10-14 05:58:53'),
(6, 6, 2, 'internal_lab', '2026-10-08 13:00:00', '2026-10-10 15:00:00', '2026-10-14 12:58:56', 'dikembalikan', 'Tugas proyek akhir sekolah', '2026-10-14 01:14:51', '2026-10-14 05:58:56'),
(7, 7, 2, 'internal_lab', '2026-10-09 08:00:00', '2026-10-11 15:00:00', '2026-10-14 12:57:03', 'dikembalikan', 'Eksperimen titrasi asam basa', '2026-10-14 01:14:51', '2026-10-14 05:57:03'),
(8, 8, 2, 'internal_lab', '2026-10-10 09:30:00', '2026-10-12 15:00:00', '2026-10-14 11:14:11', 'dikembalikan', 'Demonstrasi sains kelas & APD', '2026-10-14 01:14:51', '2026-10-14 04:14:11'),
(9, 9, 2, 'eksternal', '2026-09-20 10:00:00', '2026-09-25 15:00:00', '2026-09-24 16:00:00', 'dikembalikan', 'Penelitian sampel lapangan luar', '2026-10-14 01:14:51', '2026-10-14 01:14:51'),
(10, 10, 2, 'eksternal', '2026-09-22 13:00:00', '2026-09-28 15:00:00', '2026-10-14 08:48:50', 'dikembalikan', 'Uji coba alat pemanas lab', '2026-10-14 01:14:51', '2026-10-14 01:48:50'),
(11, 9, 1, 'eksternal', '2026-10-14 08:42:31', '2026-10-15 08:42:00', '2026-10-14 08:54:59', 'dikembalikan', '0', '2026-10-14 01:42:31', '2026-10-14 01:54:59'),
(12, 9, 1, 'eksternal', '2026-10-14 08:48:38', '2026-10-15 08:42:00', '2026-10-14 08:55:09', 'dikembalikan', '0', '2026-10-14 01:48:38', '2026-10-14 01:55:09'),
(13, 2, 1, 'internal_lab', '2026-10-14 08:52:35', '2026-10-16 08:52:00', '2026-10-14 12:56:54', 'dikembalikan', '0', '2026-10-14 01:52:35', '2026-10-14 05:56:54'),
(14, 1, 1, 'eksternal', '2026-10-14 08:53:02', '2026-10-16 08:52:00', '2026-10-14 12:56:50', 'dikembalikan', '0', '2026-10-14 01:53:02', '2026-10-14 05:56:50'),
(15, 1, 1, 'internal_lab', '2026-10-14 08:53:13', '2026-10-15 08:53:00', '2026-10-14 12:56:37', 'dikembalikan', '0', '2026-10-14 01:53:13', '2026-10-14 05:56:37'),
(16, 1, 1, 'internal_lab', '2026-10-14 08:54:16', '2026-10-15 08:53:00', '2026-10-14 12:56:46', 'dikembalikan', '0', '2026-10-14 01:54:16', '2026-10-14 05:56:46'),
(17, 1, 1, 'eksternal', '2026-10-14 08:54:27', '2026-10-16 08:54:00', '2026-10-14 12:56:57', 'dikembalikan', '0', '2026-10-14 01:54:27', '2026-10-14 05:56:57'),
(18, 1, 1, 'eksternal', '2026-10-14 08:54:33', '2026-10-16 08:54:00', '2026-10-14 12:57:48', 'dikembalikan', '0', '2026-10-14 01:54:33', '2026-10-14 05:57:48'),
(19, 1, 1, 'internal_lab', '2026-10-14 08:57:57', '2026-10-15 08:57:00', '2026-10-14 12:56:41', 'dikembalikan', '0', '2026-10-14 01:57:57', '2026-10-14 05:56:41'),
(20, 4, 1, 'internal_lab', '2026-10-14 09:17:15', '2026-10-17 09:17:00', '2026-10-14 13:06:02', 'dikembalikan', '', '2026-10-14 02:17:15', '2026-10-14 06:06:02'),
(21, 4, 1, 'internal_lab', '2026-10-14 12:36:07', '2026-10-16 12:35:00', '2026-10-14 12:58:59', 'dikembalikan', 'kuntuk praktek', '2026-10-14 05:36:07', '2026-10-14 05:58:59'),
(22, 4, 1, 'internal_lab', '2026-10-14 12:37:04', '2026-10-16 12:35:00', '2026-10-14 12:59:01', 'dikembalikan', 'kuntuk praktek', '2026-10-14 05:37:04', '2026-10-14 05:59:01'),
(23, 1, 1, 'eksternal', '2026-10-14 12:40:29', '2026-10-16 12:40:00', '2026-10-14 12:59:03', 'dikembalikan', 'Manusia', '2026-10-14 05:40:29', '2026-10-14 05:59:03'),
(24, 4, 1, 'internal_lab', '2026-10-14 12:50:50', '2026-10-16 12:50:00', '2026-10-14 12:59:06', 'dikembalikan', 'asdfghj;kd', '2026-10-14 05:50:50', '2026-10-14 05:59:06'),
(25, 1, 1, 'eksternal', '2026-10-14 13:13:24', '2026-10-16 13:11:00', NULL, 'dipinjam', 'semua sama', '2026-10-14 06:13:24', '2026-10-14 06:13:24');

-- --------------------------------------------------------

--
-- Table structure for table `borrowing_details`
--

CREATE TABLE `borrowing_details` (
  `id` int(11) NOT NULL,
  `borrowing_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `item_condition_before` enum('baik','rusak_ringan','rusak_berat') DEFAULT 'baik',
  `item_condition_after` enum('baik','rusak_ringan','rusak_berat') DEFAULT NULL,
  `status_item` enum('dipinjam','dikembalikan','hilang','rusak') DEFAULT 'dipinjam',
  `returned_good_quantity` int(11) NOT NULL DEFAULT 0,
  `returned_light_damage_quantity` int(11) NOT NULL DEFAULT 0,
  `returned_heavy_damage_quantity` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `borrowing_details`
--

INSERT INTO `borrowing_details` (`id`, `borrowing_id`, `item_id`, `quantity`, `item_condition_before`, `item_condition_after`, `status_item`) VALUES
(1, 1, 1, 2, 'baik', 'baik', 'dikembalikan'),
(2, 1, 7, 1, 'baik', 'baik', 'dikembalikan'),
(3, 2, 22, 2, 'baik', 'baik', 'dikembalikan'),
(4, 3, 14, 2, 'baik', 'baik', 'dikembalikan'),
(5, 4, 25, 2, 'baik', 'rusak_ringan', 'dikembalikan'),
(6, 5, 8, 1, 'baik', 'baik', 'dikembalikan'),
(7, 6, 18, 2, 'baik', 'baik', 'dikembalikan'),
(8, 7, 5, 3, 'baik', 'baik', 'dikembalikan'),
(9, 8, 27, 4, 'baik', 'rusak_berat', 'dikembalikan'),
(10, 9, 12, 1, 'baik', 'baik', 'dikembalikan'),
(11, 10, 17, 1, 'baik', 'baik', 'dikembalikan'),
(12, 11, 27, 1, '', 'baik', 'dikembalikan'),
(13, 12, 27, 1, '', 'rusak_berat', 'dikembalikan'),
(14, 13, 7, 1, '', 'baik', 'dikembalikan'),
(15, 14, 7, 1, '', 'baik', 'dikembalikan'),
(16, 15, 7, 1, '', 'baik', 'dikembalikan'),
(17, 16, 7, 1, '', 'baik', 'dikembalikan'),
(18, 17, 7, 1, '', 'baik', 'dikembalikan'),
(19, 18, 7, 1, '', 'baik', 'dikembalikan'),
(20, 19, 7, 1, '', 'baik', 'dikembalikan'),
(21, 20, 6, 1, 'rusak_ringan', 'baik', 'dikembalikan'),
(22, 21, 28, 3, 'baik', 'baik', 'dikembalikan'),
(23, 22, 28, 3, 'baik', 'baik', 'dikembalikan'),
(24, 23, 31, 3, 'baik', 'baik', 'dikembalikan'),
(25, 24, 1, 2, 'baik', 'baik', 'dikembalikan'),
(26, 25, 12, 2, 'baik', NULL, 'dipinjam');

-- --------------------------------------------------------

--
-- Table structure for table `categories`
--

CREATE TABLE `categories` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `categories`
--

INSERT INTO `categories` (`id`, `name`, `description`, `created_at`, `updated_at`) VALUES
(1, 'Alat Gelas Lab', 'Peralatan laboratorium berbahan kaca tahan panas.', '2026-10-14 01:14:51', '2026-10-14 01:14:51'),
(2, 'Bahan Kimia & Reagen', 'Cairan, serbuk, dan zat kimia untuk eksperimen', '2026-10-14 01:14:51', '2026-10-14 01:14:51'),
(3, 'Alat Ukur Elektronik', 'Peralatan ukur digital dan instrumen presisi', '2026-10-14 01:14:51', '2026-10-14 01:14:51'),
(4, 'Alat Pemanas & Bak Mandi', 'Peralatan pemanasan dan sterilisasi suhu', '2026-10-14 01:14:51', '2026-10-14 01:14:51'),
(5, 'Alat Optik & Mikroskop', 'Instrumen pembesaran dan pengamatan struktur halus', '2026-10-14 01:14:51', '2026-10-14 01:14:51'),
(6, 'Alat Keselamatan & Perlengkapan', 'Alat pelindung diri dan penunjang keselamatan kerja', '2026-10-14 01:14:51', '2026-10-14 01:14:51');

-- --------------------------------------------------------

--
-- Table structure for table `items`
--

CREATE TABLE `items` (
  `id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL,
  `code` varchar(50) NOT NULL,
  `name` varchar(150) NOT NULL,
  `total_quantity` int(11) NOT NULL,
  `available_quantity` int(11) NOT NULL,
  `condition` enum('baik','rusak_ringan','rusak_berat') DEFAULT 'baik',
  `good_quantity` int(11) NOT NULL DEFAULT 0,
  `light_damage_quantity` int(11) NOT NULL DEFAULT 0,
  `heavy_damage_quantity` int(11) NOT NULL DEFAULT 0,
  `status` enum('tersedia','dipinjam','hilang','pemeliharaan') DEFAULT 'tersedia',
  `location` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `items`
--

INSERT INTO `items` (`id`, `category_id`, `code`, `name`, `total_quantity`, `available_quantity`, `condition`, `status`, `location`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 1, 'GLS-001', 'Gelas Beaker 250ml Pyrex', 25, 20, 'baik', 'tersedia', 'Rak A-1', '2026-10-14 01:14:51', '2026-10-14 05:59:06', NULL),
(2, 1, 'GLS-002', 'Gelas Beaker 500ml Pyrex', 20, 15, 'baik', 'tersedia', 'Rak A-2', '2026-10-14 01:14:51', '2026-10-14 01:14:51', NULL),
(3, 1, 'GLS-003', 'Tabung Reaksi 15cm (Pack isi 10)', 50, 42, 'baik', 'tersedia', 'Rak A-3', '2026-10-14 01:14:51', '2026-10-14 01:14:51', NULL),
(4, 1, 'GLS-004', 'Labu Ukur (Volumetric Flask) 100ml', 15, 12, 'baik', 'tersedia', 'Rak A-4', '2026-10-14 01:14:51', '2026-10-14 01:14:51', NULL),
(5, 1, 'GLS-005', 'Erlenmeyer 250ml', 30, 28, 'baik', 'tersedia', 'Rak A-5', '2026-10-14 01:14:51', '2026-10-14 05:57:03', NULL),
(6, 1, 'GLS-006', 'Buret Straight Glass 50ml', 10, 9, 'baik', 'tersedia', 'Rak A-6', '2026-10-14 01:14:51', '2026-10-14 06:06:02', NULL),
(7, 2, 'KIM-001', 'Asam Klorida (HCl) 37% (500ml)', 10, 8, 'baik', 'tersedia', 'Lemari B-1', '2026-10-14 01:14:51', '2026-10-14 05:58:02', '2026-10-14 12:58:02'),
(8, 2, 'KIM-002', 'Asam Sulfat (H2SO4) 98% (500ml)', 10, 8, 'baik', 'tersedia', 'Lemari B-2', '2026-10-14 01:14:51', '2026-10-14 06:07:36', '2026-10-14 13:07:36'),
(9, 2, 'KIM-003', 'Natrium Hidroksida (NaOH) Pelet (500g)', 12, 10, 'baik', 'tersedia', 'Lemari B-3', '2026-10-14 01:14:51', '2026-10-14 06:07:43', '2026-10-14 13:07:43'),
(10, 2, 'KIM-004', 'Etanol 96% Teknis (1 Liter)', 15, 12, 'baik', 'tersedia', 'Lemari B-4', '2026-10-14 01:14:51', '2026-10-14 06:07:53', '2026-10-14 13:07:53'),
(11, 2, 'KIM-005', 'Indikator Universal Paper (Box)', 20, 18, 'baik', 'tersedia', 'Lemari B-5', '2026-10-14 01:14:51', '2026-10-14 06:08:01', '2026-10-14 13:08:01'),
(12, 3, 'ELK-001', 'Neraca Analitik Digital (Ohaus)', 5, 1, 'baik', 'tersedia', 'Meja Kalibrasi C-1', '2026-10-14 01:14:51', '2026-10-14 06:13:24', NULL),
(13, 3, 'ELK-002', 'pH Meter Digital Benchtop', 8, 6, 'baik', 'tersedia', 'Meja Kalibrasi C-2', '2026-10-14 01:14:51', '2026-10-14 01:14:51', NULL),
(14, 3, 'ELK-003', 'Multimeter Digital Sanwa', 10, 12, 'baik', 'tersedia', 'Rak C-3', '2026-10-14 01:14:51', '2026-10-14 04:10:31', NULL),
(15, 3, 'ELK-004', 'Konduktometer Digital', 6, 5, 'baik', 'tersedia', 'Rak C-4', '2026-10-14 01:14:51', '2026-10-14 01:14:51', NULL),
(16, 3, 'ELK-005', 'Termometer Batang Digital', 15, 14, 'baik', 'tersedia', 'Rak C-5', '2026-10-14 01:14:51', '2026-10-14 01:14:51', NULL),
(17, 4, 'PMN-001', 'Hotplate Stirrer with Magnetic Bar', 6, 5, 'baik', 'tersedia', 'Rak D-1', '2026-10-14 01:14:51', '2026-10-14 01:48:50', NULL),
(18, 4, 'PMN-002', 'Pembakar Bunsen Kaca Spiritus', 15, 14, 'baik', 'tersedia', 'Rak D-2', '2026-10-14 01:14:51', '2026-10-14 05:58:56', NULL),
(19, 4, 'PMN-003', 'Water Bath Digital 6 Holes', 3, 2, 'baik', 'tersedia', 'Rak D-3', '2026-10-14 01:14:51', '2026-10-14 01:14:51', NULL),
(20, 4, 'PMN-004', 'Mantel Pemanas Lab (Heating Mantle 500ml)', 4, 3, 'baik', 'tersedia', 'Rak D-4', '2026-10-14 01:14:51', '2026-10-14 01:14:51', NULL),
(21, 4, 'PMN-005', 'Oven Sterilisasi Laboratorium', 2, 2, 'baik', 'tersedia', 'Sudut Ruang Pemanas', '2026-10-14 01:14:51', '2026-10-14 01:14:51', NULL),
(22, 5, 'OPT-001', 'Mikroskop Binokuler Olympus', 10, 7, 'baik', 'tersedia', 'Meja Mikroskop E-1', '2026-10-14 01:14:51', '2026-10-14 01:14:51', NULL),
(23, 5, 'OPT-002', 'Mikroskop Monokuler Siswa', 15, 12, 'baik', 'tersedia', 'Meja Mikroskop E-2', '2026-10-14 01:14:51', '2026-10-14 01:14:51', NULL),
(24, 5, 'OPT-003', 'Kaca Pembesar (Magnifying Glass) 3x', 20, 18, 'baik', 'tersedia', 'Rak E-3', '2026-10-14 01:14:51', '2026-10-14 01:14:51', NULL),
(25, 5, 'OPT-004', 'Lensa Optik Set (Konkaf/Konveks)', 10, 10, 'rusak_ringan', 'tersedia', 'Rak E-4', '2026-10-14 01:14:51', '2026-10-14 01:52:09', NULL),
(26, 5, 'OPT-005', 'Spektrofotometer UV-Vis', 2, 1, 'baik', 'tersedia', 'Meja Instrumen Utama', '2026-10-14 01:14:51', '2026-10-14 01:14:51', NULL),
(27, 6, 'KSL-001', 'Kacamata Safety Lab (Goggles)', 30, 24, 'rusak_berat', 'tersedia', 'Rak F-1', '2026-10-14 01:14:51', '2026-10-14 01:55:09', NULL),
(28, 6, 'KSL-002', 'Jas Laboratorium Putih Ukuran L', 20, 18, 'baik', 'tersedia', 'Rak F-2', '2026-10-14 01:14:51', '2026-10-14 05:59:01', NULL),
(29, 6, 'KSL-003', 'Sarung Tangan Lateks Non-Steril (Box)', 10, 8, 'baik', 'tersedia', 'Rak F-3', '2026-10-14 01:14:51', '2026-10-14 01:14:51', NULL),
(30, 6, 'KSL-004', 'Masker Medis 3 Ply (Box)', 15, 13, 'baik', 'tersedia', 'Rak F-4', '2026-10-14 01:14:51', '2026-10-14 01:14:51', NULL),
(31, 6, 'KSL-005', 'Kotak P3K Lengkap Laboratorium', 4, 4, 'baik', 'tersedia', 'Dinding Utama Lab', '2026-10-14 01:14:51', '2026-10-14 05:59:03', NULL),
(32, 5, 'OPT-007', 'kacamata hitam', 9, 9, 'baik', 'tersedia', 'Rak E - 5', '2026-10-14 03:15:06', '2026-10-14 03:15:06', NULL),
(33, 6, 'KSL-0091', 'Masker Pengaman', 250, 250, 'baik', 'tersedia', 'Rak F - 4', '2026-10-14 04:19:09', '2026-10-14 04:19:09', NULL),
(34, 1, 'AGL-C01-001', 'gelas', 12, 12, 'baik', 'tersedia', 'Rak A-7', '2026-10-14 06:18:35', '2026-10-14 06:18:35', NULL),
(35, 1, 'AGL-C01-002', 'gelas', 12, 12, 'baik', 'tersedia', 'Rak A-7', '2026-10-14 06:27:04', '2026-10-14 06:27:04', NULL),
(36, 1, 'AGL-C01-003', 'gelas', 12, 12, 'baik', 'tersedia', 'Rak A-7', '2026-10-14 06:27:09', '2026-10-14 06:27:09', NULL);

-- Per-condition stock counts are initialized by the compatibility migration in
-- koneksi.php after all inventory and active-loan tables have been imported.

-- --------------------------------------------------------

--
-- Table structure for table `item_logs`
--

CREATE TABLE `item_logs` (
  `id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `action` enum('tambah','update','perubahan_kondisi','penghapusan') NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `item_logs`
--

INSERT INTO `item_logs` (`id`, `item_id`, `user_id`, `action`, `description`, `created_at`) VALUES
(1, 22, 1, 'tambah', 'Pengadaan mikroskop binokuler baru.', '2026-09-01 01:00:00'),
(2, 7, 1, 'tambah', 'Stok cairan HCl baru masuk.', '2026-09-02 02:30:00'),
(3, 12, 2, 'perubahan_kondisi', 'Neraca analitik dikalibrasi.', '2026-09-10 03:00:00'),
(4, 17, 2, 'perubahan_kondisi', 'Hotplate tombol panas mengalami kendala.', '2026-09-28 07:00:00'),
(5, 3, 1, 'tambah', 'Penambahan stok tabung reaksi.', '2026-10-01 00:30:00'),
(6, 27, 1, 'perubahan_kondisi', 'Barang dipinjam sebanyak 1 unit.', '2026-10-14 01:42:31'),
(7, 27, 1, 'perubahan_kondisi', 'Barang dipinjam sebanyak 1 unit.', '2026-10-14 01:48:38'),
(8, 17, 1, 'perubahan_kondisi', 'Barang dikembalikan ke inventaris laboratorium.', '2026-10-14 01:48:50'),
(9, 25, 1, 'perubahan_kondisi', 'Barang dikembalikan dengan kondisi:rusak_ringan', '2026-10-14 01:52:09'),
(10, 7, 1, 'perubahan_kondisi', 'Dipinjam sebanyak 1 unit (Kondisi: )', '2026-10-14 01:52:35'),
(11, 7, 1, 'perubahan_kondisi', 'Dipinjam sebanyak 1 unit (Kondisi: )', '2026-10-14 01:53:02'),
(12, 7, 1, 'perubahan_kondisi', 'Dipinjam sebanyak 1 unit (Kondisi: )', '2026-10-14 01:53:13'),
(13, 7, 1, 'perubahan_kondisi', 'Dipinjam sebanyak 1 unit (Kondisi Awal: )', '2026-10-14 01:54:16'),
(14, 7, 1, 'perubahan_kondisi', 'Dipinjam sebanyak 1 unit (Kondisi Awal: )', '2026-10-14 01:54:27'),
(15, 7, 1, 'perubahan_kondisi', 'Dipinjam sebanyak 1 unit (Kondisi Awal: )', '2026-10-14 01:54:33'),
(16, 27, 1, 'perubahan_kondisi', 'Barang dikembalikan dengan kondisi:baik', '2026-10-14 01:54:59'),
(17, 27, 1, 'perubahan_kondisi', 'Barang dikembalikan dengan kondisi:rusak_berat', '2026-10-14 01:55:09'),
(18, 7, 1, 'perubahan_kondisi', 'Dipinjam sebanyak 1 unit (Kondisi Awal: )', '2026-10-14 01:57:57'),
(19, 6, 1, 'perubahan_kondisi', 'Dipinjam sebanyak 1 unit (Kondisi Awal: rusak_ringan)', '2026-10-14 02:17:15'),
(20, 32, 1, 'tambah', 'Barang baru \'kacamata hitam\' dengan kode \'OPT-007\' ditambahkan sebanyak 9 unit.', '2026-10-14 03:15:06'),
(21, 14, 1, 'perubahan_kondisi', 'Barang dikembalikan dengan kondisi:baik', '2026-10-14 04:10:31'),
(22, 27, 1, 'perubahan_kondisi', 'Barang dikembalikan dengan kondisi:rusak_berat', '2026-10-14 04:14:11'),
(23, 33, 1, 'tambah', 'Barang baru \'Masker Pengaman\' dengan kode \'KSL-0091\' ditambahkan sebanyak 250 unit.', '2026-10-14 04:19:09'),
(24, 28, 1, 'perubahan_kondisi', 'Dipinjam sebanyak 3 unit (Kondisi Awal: baik)', '2026-10-14 05:36:07'),
(25, 28, 1, 'perubahan_kondisi', 'Dipinjam sebanyak 3 unit (Kondisi Awal: baik)', '2026-10-14 05:37:04'),
(26, 31, 1, 'perubahan_kondisi', 'Dipinjam sebanyak 3 unit (Kondisi Awal: baik)', '2026-10-14 05:40:29'),
(27, 1, 1, 'perubahan_kondisi', 'Dipinjam sebanyak 2 unit (Kondisi Awal: baik)', '2026-10-14 05:50:50'),
(28, 7, 1, 'perubahan_kondisi', 'Barang dikembalikan dengan kondisi:baik', '2026-10-14 05:56:37'),
(29, 7, 1, 'perubahan_kondisi', 'Barang dikembalikan dengan kondisi:baik', '2026-10-14 05:56:41'),
(30, 7, 1, 'perubahan_kondisi', 'Barang dikembalikan dengan kondisi:baik', '2026-10-14 05:56:46'),
(31, 7, 1, 'perubahan_kondisi', 'Barang dikembalikan dengan kondisi:baik', '2026-10-14 05:56:50'),
(32, 7, 1, 'perubahan_kondisi', 'Barang dikembalikan dengan kondisi:baik', '2026-10-14 05:56:54'),
(33, 7, 1, 'perubahan_kondisi', 'Barang dikembalikan dengan kondisi:baik', '2026-10-14 05:56:57'),
(34, 5, 1, 'perubahan_kondisi', 'Barang dikembalikan dengan kondisi:baik', '2026-10-14 05:57:03'),
(35, 7, 1, 'perubahan_kondisi', 'Barang dikembalikan dengan kondisi:baik', '2026-10-14 05:57:48'),
(36, 7, 1, '', 'Barang \'Asam Klorida (HCl) 37% (500ml)\' (kode \'KIM-001\') dihapus secara soft delete.', '2026-10-14 05:58:02'),
(37, 8, 1, 'perubahan_kondisi', 'Barang dikembalikan dengan kondisi:baik', '2026-10-14 05:58:53'),
(38, 18, 1, 'perubahan_kondisi', 'Barang dikembalikan dengan kondisi:baik', '2026-10-14 05:58:56'),
(39, 28, 1, 'perubahan_kondisi', 'Barang dikembalikan dengan kondisi:baik', '2026-10-14 05:58:59'),
(40, 28, 1, 'perubahan_kondisi', 'Barang dikembalikan dengan kondisi:baik', '2026-10-14 05:59:01'),
(41, 31, 1, 'perubahan_kondisi', 'Barang dikembalikan dengan kondisi:baik', '2026-10-14 05:59:03'),
(42, 1, 1, 'perubahan_kondisi', 'Barang dikembalikan dengan kondisi:baik', '2026-10-14 05:59:06'),
(43, 6, 1, 'perubahan_kondisi', 'Barang dikembalikan dengan kondisi:baik', '2026-10-14 05:59:08'),
(44, 6, 1, 'perubahan_kondisi', 'Barang dikembalikan dengan kondisi:baik', '2026-10-14 06:06:02'),
(45, 8, 1, '', 'Barang \'Asam Sulfat (H2SO4) 98% (500ml)\' (kode \'KIM-002\') dihapus secara soft delete.', '2026-10-14 06:07:36'),
(46, 9, 1, '', 'Barang \'Natrium Hidroksida (NaOH) Pelet (500g)\' (kode \'KIM-003\') dihapus secara soft delete.', '2026-10-14 06:07:43'),
(47, 10, 1, '', 'Barang \'Etanol 96% Teknis (1 Liter)\' (kode \'KIM-004\') dihapus secara soft delete.', '2026-10-14 06:07:53'),
(48, 11, 1, '', 'Barang \'Indikator Universal Paper (Box)\' (kode \'KIM-005\') dihapus secara soft delete.', '2026-10-14 06:08:01'),
(49, 12, 1, 'perubahan_kondisi', 'Dipinjam sebanyak 2 unit (Kondisi Awal: baik)', '2026-10-14 06:13:24'),
(50, 34, 1, 'tambah', 'Barang baru \'gelas\' dengan kode \'AGL-C01-001\' ditambahkan sebanyak 12 unit.', '2026-10-14 06:18:35'),
(51, 35, 1, 'tambah', 'Barang baru \'gelas\' dengan kode \'AGL-C01-002\' ditambahkan sebanyak 12 unit.', '2026-10-14 06:27:04'),
(52, 36, 1, 'tambah', 'Barang baru \'gelas\' dengan kode \'AGL-C01-003\' ditambahkan sebanyak 12 unit.', '2026-10-14 06:27:09');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `nik` varchar(16) DEFAULT NULL,
  `alamat` text DEFAULT NULL,
  `role` enum('admin','peminjam') NOT NULL,
  `status` enum('aktif','nonaktif') DEFAULT 'aktif',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `email`, `password`, `role`, `status`, `created_at`, `updated_at`) VALUES
(1, 'Dr. Ahmad Sucipto', 'ahmad.sucipto@lab.ac.id', 'password123', 'admin', 'aktif', '2026-10-14 01:14:51', '2026-10-14 01:14:51'),
(2, 'Siti Rahma, S.Si.', 'siti.rahma@lab.ac.id', 'password123', '', 'aktif', '2026-10-14 01:14:51', '2026-10-14 01:14:51'),
(3, 'Budi Santoso', 'budi.santoso@student.ac.id', 'password123', 'peminjam', 'aktif', '2026-10-14 01:14:51', '2026-10-14 01:14:51'),
(4, 'Dewi Lestari', 'dewi.lestari@student.ac.id', 'password123', 'peminjam', 'aktif', '2026-10-14 01:14:51', '2026-10-14 01:14:51'),
(5, 'Eko Prasetyo', 'eko.prasetyo@student.ac.id', 'password123', 'peminjam', 'aktif', '2026-10-14 01:14:51', '2026-10-14 01:14:51'),
(6, 'Fajar Nugroho', 'fajar.nugroho@student.ac.id', 'password123', 'peminjam', 'aktif', '2026-10-14 01:14:51', '2026-10-14 01:14:51'),
(7, 'Gita Gutawa', 'gita.gutawa@student.ac.id', 'password123', 'peminjam', 'aktif', '2026-10-14 01:14:51', '2026-10-14 01:14:51'),
(8, 'Hadi Wijaya', 'hadi.wijaya@student.ac.id', 'password123', 'peminjam', 'aktif', '2026-10-14 01:14:51', '2026-10-14 01:14:51'),
(9, 'Indah Permata', 'indah.permata@student.ac.id', 'password123', 'peminjam', 'aktif', '2026-10-14 01:14:51', '2026-10-14 01:14:51'),
(10, 'Joko Anwar', 'joko.anwar@student.ac.id', 'password123', 'peminjam', 'aktif', '2026-10-14 01:14:51', '2026-10-14 01:14:51');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `borrowers`
--
ALTER TABLE `borrowers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `borrowings`
--
ALTER TABLE `borrowings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `borrower_id` (`borrower_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `borrowing_details`
--
ALTER TABLE `borrowing_details`
  ADD PRIMARY KEY (`id`),
  ADD KEY `borrowing_id` (`borrowing_id`),
  ADD KEY `item_id` (`item_id`);

--
-- Indexes for table `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `items`
--
ALTER TABLE `items`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `category_id` (`category_id`);

--
-- Indexes for table `item_logs`
--
ALTER TABLE `item_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `item_id` (`item_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `uniq_users_nik` (`nik`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `borrowers`
--
ALTER TABLE `borrowers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `borrowings`
--
ALTER TABLE `borrowings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

--
-- AUTO_INCREMENT for table `borrowing_details`
--
ALTER TABLE `borrowing_details`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `items`
--
ALTER TABLE `items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=37;

--
-- AUTO_INCREMENT for table `item_logs`
--
ALTER TABLE `item_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=53;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `borrowers`
--
ALTER TABLE `borrowers`
  ADD CONSTRAINT `borrowers_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `borrowings`
--
ALTER TABLE `borrowings`
  ADD CONSTRAINT `borrowings_ibfk_1` FOREIGN KEY (`borrower_id`) REFERENCES `borrowers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `borrowings_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `borrowing_details`
--
ALTER TABLE `borrowing_details`
  ADD CONSTRAINT `borrowing_details_ibfk_1` FOREIGN KEY (`borrowing_id`) REFERENCES `borrowings` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `borrowing_details_ibfk_2` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`);

--
-- Constraints for table `items`
--
ALTER TABLE `items`
  ADD CONSTRAINT `items_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `item_logs`
--
ALTER TABLE `item_logs`
  ADD CONSTRAINT `item_logs_ibfk_1` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `item_logs_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

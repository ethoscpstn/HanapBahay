-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: May 14, 2025 at 04:01 PM
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
-- Database: `dbhanapbahay`
--

-- --------------------------------------------------------

--
-- Table structure for table `rental_requests`
--

CREATE TABLE `rental_requests` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `listing_id` int(11) NOT NULL,
  `payment_method` varchar(50) NOT NULL,
  `status` varchar(20) DEFAULT 'pending',
  `requested_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `rental_requests`
--

INSERT INTO `rental_requests` (`id`, `tenant_id`, `listing_id`, `payment_method`, `status`, `requested_at`) VALUES
(1, 14, 7, 'gcash', 'pending', '2025-05-12 17:52:38');

-- --------------------------------------------------------

--
-- Table structure for table `tbadmin`
--

CREATE TABLE `tbadmin` (
  `id` int(11) NOT NULL,
  `username` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('tenant','unit_owner') NOT NULL DEFAULT 'tenant',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `verification_token` varchar(255) DEFAULT NULL,
  `is_verified` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbadmin`
--

INSERT INTO `tbadmin` (`id`, `username`, `email`, `password`, `role`, `created_at`, `verification_token`, `is_verified`) VALUES
(2, 'owner_user', 'owner@example.com', '$2y$10$DUMMYHASHEDPASSWORD2', 'unit_owner', '2025-04-25 17:01:25', NULL, 0),
(14, 'dogshow', 'testuser@mailtrap.io', '$2y$10$qCM5lar1dos4ERXDTFOlEOwFbYv5jGS6hXDIb1/iakT.T7nr7gVGG', 'tenant', '2025-04-25 18:45:11', NULL, 1),
(18, 'owner1', 'owner1@samplemail.com', '$2y$10$Q056oHF7TcY4TNHf1AtPT.3R.05GvINU0pgJ/.KDFwq07wOp66Tgy', 'unit_owner', '2025-05-11 09:54:06', NULL, 1),
(19, 'owner2', 'owner2@samplemail.com', '$2y$10$95au7TX/o2KOWkoVKq01x./Z2OHvLkhPB8e0BGfFcKo.XNVyrMM92', 'unit_owner', '2025-05-11 09:55:20', NULL, 1),
(20, 'owner3', 'owner3@samplemail.com', '$2y$10$ZpzcMZi9PBZ3ppkfbar/9u566Q0OPc1PrHbPy9x3wi15tSS9/xNxm', 'unit_owner', '2025-05-11 10:04:34', NULL, 1),
(21, 'owner4', 'owner4@samplemail.com', '$2y$10$JmlQbEfveB1wmdkE.a/xH.QCXeQSyapBuM2FTEEk5VERWUAjxWaJu', 'unit_owner', '2025-05-11 10:23:10', NULL, 1);

-- --------------------------------------------------------

--
-- Table structure for table `tblistings`
--

CREATE TABLE `tblistings` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `address` varchar(255) NOT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `price` decimal(10,2) DEFAULT NULL,
  `capacity` int(11) DEFAULT 1,
  `owner_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tblistings`
--

INSERT INTO `tblistings` (`id`, `title`, `description`, `address`, `latitude`, `longitude`, `price`, `capacity`, `owner_id`, `created_at`) VALUES
(5, 'Apartment Type', '1 Bedroom, no pets allowed', 'San Jose, Pasig', 14.56130330, 121.07364520, 12000.00, 4, 18, '2025-05-11 09:55:00'),
(7, 'Studio Type', '30~ sqm space, no pets allowed.', '2217 Pedro Gil St, Santa Ana, Manila, ', 14.58126230, 121.00972210, 15000.00, 3, 19, '2025-05-11 09:58:45'),
(9, 'Studio Type', '18~sqm space, Pets allowed (Limited 1)', '2379 Oro-B, San Andres Bukid, Manila, ', 14.57333530, 121.00915790, 8000.00, 4, 20, '2025-05-11 10:09:53'),
(10, 'Studio Type', '20~ sqm space, Pets allowed (Limited 1)', 'Pinalad Rd, Pasig', 14.54557980, 121.10154380, 3000.00, 3, 21, '2025-05-11 10:25:09');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `rental_requests`
--
ALTER TABLE `rental_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tenant_id` (`tenant_id`),
  ADD KEY `listing_id` (`listing_id`);

--
-- Indexes for table `tbadmin`
--
ALTER TABLE `tbadmin`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `tblistings`
--
ALTER TABLE `tblistings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `owner_id` (`owner_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `rental_requests`
--
ALTER TABLE `rental_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `tbadmin`
--
ALTER TABLE `tbadmin`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `tblistings`
--
ALTER TABLE `tblistings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `rental_requests`
--
ALTER TABLE `rental_requests`
  ADD CONSTRAINT `rental_requests_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tbadmin` (`id`),
  ADD CONSTRAINT `rental_requests_ibfk_2` FOREIGN KEY (`listing_id`) REFERENCES `tblistings` (`id`);

--
-- Constraints for table `tblistings`
--
ALTER TABLE `tblistings`
  ADD CONSTRAINT `tblistings_ibfk_1` FOREIGN KEY (`owner_id`) REFERENCES `tbadmin` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

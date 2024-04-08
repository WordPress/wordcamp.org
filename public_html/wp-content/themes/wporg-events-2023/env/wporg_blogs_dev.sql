-- phpMyAdmin SQL Dump
-- version 5.0.2
-- https://www.phpmyadmin.net/
--
-- Generation Time: Dec 06, 2023 at 04:01 AM
-- Server version: 10.2.44-MariaDB-log
-- PHP Version: 7.4.33

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `wordpress`
--

-- --------------------------------------------------------

--
-- Table structure for table `wporg_blogs`
--

CREATE TABLE `wporg_blogs` (
  `blog_id` bigint(20) NOT NULL,
  `site_id` bigint(20) NOT NULL DEFAULT 0,
  `domain` varchar(200) NOT NULL DEFAULT '',
  `path` varchar(100) NOT NULL DEFAULT '',
  `registered` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `last_updated` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `public` tinyint(2) NOT NULL DEFAULT 1,
  `archived` tinyint(2) NOT NULL DEFAULT 0,
  `mature` tinyint(2) NOT NULL DEFAULT 0,
  `spam` tinyint(2) NOT NULL DEFAULT 0,
  `deleted` tinyint(2) NOT NULL DEFAULT 0,
  `lang_id` int(11) NOT NULL DEFAULT 0
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

--
-- Dumping data for table `wporg_blogs`
--

INSERT INTO `wporg_blogs` (`blog_id`, `site_id`, `domain`, `path`, `registered`, `last_updated`, `public`, `archived`, `mature`, `spam`, `deleted`, `lang_id`) VALUES
(1, 1, 'localhost', '/', '2010-07-07 18:06:22', '2023-11-15 19:05:40', 1, 0, 0, 0, 0, 0);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `wporg_blogs`
--
ALTER TABLE `wporg_blogs`
  ADD PRIMARY KEY (`blog_id`),
  ADD KEY `domain` (`domain`(50),`path`(5)),
  ADD KEY `lang_id` (`lang_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `wporg_blogs`
--
ALTER TABLE `wporg_blogs`
  MODIFY `blog_id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=731;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

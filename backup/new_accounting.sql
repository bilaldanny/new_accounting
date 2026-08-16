-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Aug 16, 2026 at 10:18 PM
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
-- Database: `new_accounting`
--

-- --------------------------------------------------------

--
-- Table structure for table `account_balances`
--

CREATE TABLE `account_balances` (
  `id` bigint UNSIGNED NOT NULL,
  `company_id` bigint UNSIGNED DEFAULT NULL,
  `branch_id` bigint UNSIGNED DEFAULT NULL,
  `financial_id` bigint UNSIGNED DEFAULT NULL,
  `coa_id` bigint UNSIGNED DEFAULT NULL,
  `opening_balance` int NOT NULL,
  `acc_nature` enum('cr','dr') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cr',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `banks`
--

CREATE TABLE `banks` (
  `id` bigint UNSIGNED NOT NULL,
  `company_id` bigint UNSIGNED DEFAULT NULL,
  `branch_id` bigint UNSIGNED DEFAULT NULL,
  `first_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `bank_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `prefix` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `middle_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gl_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mobile` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `alternate_no` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `landline` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `landmark` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `country_id` bigint UNSIGNED DEFAULT NULL,
  `state_id` bigint UNSIGNED DEFAULT NULL,
  `city_id` bigint UNSIGNED DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `link_account` tinyint(1) NOT NULL DEFAULT '1',
  `address` text COLLATE utf8mb4_unicode_ci,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` enum('export','local') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'local',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `branches`
--

CREATE TABLE `branches` (
  `id` bigint UNSIGNED NOT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `company_id` bigint UNSIGNED DEFAULT NULL,
  `country_id` bigint UNSIGNED DEFAULT NULL,
  `state_id` bigint UNSIGNED DEFAULT NULL,
  `city_id` bigint UNSIGNED DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `phone` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mobile` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` text COLLATE utf8mb4_unicode_ci,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `branches`
--

INSERT INTO `branches` (`id`, `code`, `company_id`, `country_id`, `state_id`, `city_id`, `name`, `description`, `phone`, `mobile`, `email`, `address`, `is_active`, `is_default`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 'BR-00001', 1, NULL, NULL, NULL, 'headoffice', NULL, NULL, NULL, NULL, NULL, 1, 1, '2026-08-15 15:34:38', '2026-08-15 15:34:38', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `brands`
--

CREATE TABLE `brands` (
  `id` bigint UNSIGNED NOT NULL,
  `company_id` bigint UNSIGNED DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cache`
--

CREATE TABLE `cache` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` bigint NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `cache`
--

INSERT INTO `cache` (`key`, `value`, `expiration`) VALUES
('laravel-cache-app.settings', 'N;', 1786919608);
INSERT INTO `cache` (`key`, `value`, `expiration`) VALUES
('laravel-cache-user_menu_permissions_tree:1', 'a:12:{i:0;a:21:{s:8:\"my_route\";s:5:\"/menu\";s:6:\"status\";i:1;s:2:\"id\";i:2;s:9:\"parent_id\";s:0:\"\";s:4:\"name\";s:5:\"Menus\";s:4:\"icon\";s:11:\"fal fa-bars\";s:10:\"route_name\";s:5:\"/menu\";s:10:\"route_path\";s:5:\"/menu\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:1;s:13:\"is_permission\";b:1;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-01-26T16:09:33.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:24:{i:0;a:21:{s:8:\"my_route\";s:12:\"/menu/delete\";s:6:\"status\";i:1;s:2:\"id\";i:7;s:9:\"parent_id\";i:2;s:4:\"name\";s:11:\"Delete Menu\";s:4:\"icon\";N;s:10:\"route_name\";s:13:\"menus.destroy\";s:10:\"route_path\";s:12:\"/menu/delete\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:1;s:13:\"is_permission\";b:1;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-01-29T15:23:11.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-05T04:42:55.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:1;a:21:{s:8:\"my_route\";s:16:\"/currency/delete\";s:6:\"status\";i:1;s:2:\"id\";i:168;s:9:\"parent_id\";i:2;s:4:\"name\";s:15:\"Delete Currency\";s:4:\"icon\";N;s:10:\"route_name\";s:18:\"currencies.destroy\";s:10:\"route_path\";s:16:\"/currency/delete\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:1;s:13:\"is_permission\";b:1;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-01-29T15:23:11.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-05T04:42:55.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:2;a:21:{s:8:\"my_route\";s:16:\"/timezone/delete\";s:6:\"status\";i:1;s:2:\"id\";i:175;s:9:\"parent_id\";i:2;s:4:\"name\";s:15:\"Delete Timezone\";s:4:\"icon\";N;s:10:\"route_name\";s:17:\"timezones.destroy\";s:10:\"route_path\";s:16:\"/timezone/delete\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:1;s:13:\"is_permission\";b:1;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-01-29T15:23:11.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-05T04:42:55.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:3;a:21:{s:8:\"my_route\";s:9:\"/menu/add\";s:6:\"status\";i:1;s:2:\"id\";i:3;s:9:\"parent_id\";i:2;s:4:\"name\";s:8:\"Add Menu\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:7:\"addmenu\";s:10:\"route_path\";s:9:\"/menu/add\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:1;s:13:\"is_permission\";b:1;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:4;a:21:{s:8:\"my_route\";s:14:\"/menu/:id/edit\";s:6:\"status\";i:1;s:2:\"id\";i:4;s:9:\"parent_id\";i:2;s:4:\"name\";s:9:\"Edit Menu\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:8:\"editmenu\";s:10:\"route_path\";s:14:\"/menu/:id/edit\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:1;s:13:\"is_permission\";b:1;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:5;a:21:{s:8:\"my_route\";s:12:\"/menu/export\";s:6:\"status\";i:1;s:2:\"id\";i:153;s:9:\"parent_id\";i:2;s:4:\"name\";s:11:\"Menu Export\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:11:\"menu.export\";s:10:\"route_path\";s:12:\"/menu/export\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:1;s:13:\"is_permission\";b:1;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:6;a:21:{s:8:\"my_route\";s:12:\"/menu/import\";s:6:\"status\";i:1;s:2:\"id\";i:154;s:9:\"parent_id\";i:2;s:4:\"name\";s:11:\"Menu Import\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:11:\"menu.import\";s:10:\"route_path\";s:12:\"/menu/import\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:1;s:13:\"is_permission\";b:1;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:7;a:21:{s:8:\"my_route\";s:11:\"/menu/trash\";s:6:\"status\";i:1;s:2:\"id\";i:155;s:9:\"parent_id\";i:2;s:4:\"name\";s:10:\"Menu Trash\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:10:\"menu.trash\";s:10:\"route_path\";s:11:\"/menu/trash\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:1;s:13:\"is_permission\";b:1;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:8;a:21:{s:8:\"my_route\";s:18:\"/department/export\";s:6:\"status\";i:1;s:2:\"id\";i:159;s:9:\"parent_id\";i:2;s:4:\"name\";s:17:\"Department Export\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:17:\"department.export\";s:10:\"route_path\";s:18:\"/department/export\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:9;a:21:{s:8:\"my_route\";s:18:\"/department/import\";s:6:\"status\";i:1;s:2:\"id\";i:160;s:9:\"parent_id\";i:2;s:4:\"name\";s:17:\"Department Import\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:17:\"department.import\";s:10:\"route_path\";s:18:\"/department/import\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:10;a:21:{s:8:\"my_route\";s:17:\"/department/trash\";s:6:\"status\";i:1;s:2:\"id\";i:161;s:9:\"parent_id\";i:2;s:4:\"name\";s:16:\"Department Trash\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:16:\"department.trash\";s:10:\"route_path\";s:17:\"/department/trash\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:11;a:21:{s:8:\"my_route\";s:15:\"/company/export\";s:6:\"status\";i:1;s:2:\"id\";i:162;s:9:\"parent_id\";i:2;s:4:\"name\";s:14:\"Company Export\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:14:\"company.export\";s:10:\"route_path\";s:15:\"/company/export\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:1;s:13:\"is_permission\";b:1;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:12;a:21:{s:8:\"my_route\";s:15:\"/company/import\";s:6:\"status\";i:1;s:2:\"id\";i:163;s:9:\"parent_id\";i:2;s:4:\"name\";s:14:\"Company Import\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:14:\"company.import\";s:10:\"route_path\";s:15:\"/company/import\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:1;s:13:\"is_permission\";b:1;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:13;a:21:{s:8:\"my_route\";s:14:\"/company/trash\";s:6:\"status\";i:1;s:2:\"id\";i:164;s:9:\"parent_id\";i:2;s:4:\"name\";s:13:\"Company Trash\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:13:\"company.trash\";s:10:\"route_path\";s:14:\"/company/trash\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:1;s:13:\"is_permission\";b:1;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:14;a:21:{s:8:\"my_route\";s:13:\"/currency/add\";s:6:\"status\";i:1;s:2:\"id\";i:166;s:9:\"parent_id\";i:2;s:4:\"name\";s:12:\"Add Currency\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:11:\"addcurrency\";s:10:\"route_path\";s:13:\"/currency/add\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:1;s:13:\"is_permission\";b:1;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:15;a:21:{s:8:\"my_route\";s:18:\"/currency/:id/edit\";s:6:\"status\";i:1;s:2:\"id\";i:167;s:9:\"parent_id\";i:2;s:4:\"name\";s:13:\"Edit Currency\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:12:\"editcurrency\";s:10:\"route_path\";s:18:\"/currency/:id/edit\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:1;s:13:\"is_permission\";b:1;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:16;a:21:{s:8:\"my_route\";s:16:\"/currency/export\";s:6:\"status\";i:1;s:2:\"id\";i:169;s:9:\"parent_id\";i:2;s:4:\"name\";s:15:\"Currency Export\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:15:\"currency.export\";s:10:\"route_path\";s:16:\"/currency/export\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:1;s:13:\"is_permission\";b:1;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:17;a:21:{s:8:\"my_route\";s:16:\"/currency/import\";s:6:\"status\";i:1;s:2:\"id\";i:170;s:9:\"parent_id\";i:2;s:4:\"name\";s:15:\"Currency Import\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:15:\"currency.import\";s:10:\"route_path\";s:16:\"/currency/import\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:1;s:13:\"is_permission\";b:1;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:18;a:21:{s:8:\"my_route\";s:15:\"/currency/trash\";s:6:\"status\";i:1;s:2:\"id\";i:171;s:9:\"parent_id\";i:2;s:4:\"name\";s:14:\"Currency Trash\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:14:\"currency.trash\";s:10:\"route_path\";s:15:\"/currency/trash\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:1;s:13:\"is_permission\";b:1;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:19;a:21:{s:8:\"my_route\";s:13:\"/timezone/add\";s:6:\"status\";i:1;s:2:\"id\";i:173;s:9:\"parent_id\";i:2;s:4:\"name\";s:12:\"Add Timezone\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:11:\"addtimezone\";s:10:\"route_path\";s:13:\"/timezone/add\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:1;s:13:\"is_permission\";b:1;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:20;a:21:{s:8:\"my_route\";s:18:\"/timezone/:id/edit\";s:6:\"status\";i:1;s:2:\"id\";i:174;s:9:\"parent_id\";i:2;s:4:\"name\";s:13:\"Edit Timezone\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:12:\"edittimezone\";s:10:\"route_path\";s:18:\"/timezone/:id/edit\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:1;s:13:\"is_permission\";b:1;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:21;a:21:{s:8:\"my_route\";s:16:\"/timezone/export\";s:6:\"status\";i:1;s:2:\"id\";i:176;s:9:\"parent_id\";i:2;s:4:\"name\";s:15:\"Timezone Export\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:15:\"timezone.export\";s:10:\"route_path\";s:16:\"/timezone/export\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:1;s:13:\"is_permission\";b:1;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:22;a:21:{s:8:\"my_route\";s:16:\"/timezone/import\";s:6:\"status\";i:1;s:2:\"id\";i:177;s:9:\"parent_id\";i:2;s:4:\"name\";s:15:\"Timezone Import\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:15:\"timezone.import\";s:10:\"route_path\";s:16:\"/timezone/import\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:1;s:13:\"is_permission\";b:1;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:23;a:21:{s:8:\"my_route\";s:15:\"/timezone/trash\";s:6:\"status\";i:1;s:2:\"id\";i:178;s:9:\"parent_id\";i:2;s:4:\"name\";s:14:\"Timezone Trash\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:14:\"timezone.trash\";s:10:\"route_path\";s:15:\"/timezone/trash\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:1;s:13:\"is_permission\";b:1;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}}}i:1;a:21:{s:8:\"my_route\";s:8:\"/company\";s:6:\"status\";i:1;s:2:\"id\";i:17;s:9:\"parent_id\";s:0:\"\";s:4:\"name\";s:7:\"Company\";s:4:\"icon\";s:15:\"fal fa-building\";s:10:\"route_name\";s:8:\"/company\";s:10:\"route_path\";s:8:\"/company\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:1;s:13:\"is_permission\";b:1;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-01-26T16:09:33.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:3:{i:0;a:21:{s:8:\"my_route\";s:15:\"/company/delete\";s:6:\"status\";i:1;s:2:\"id\";i:20;s:9:\"parent_id\";i:17;s:4:\"name\";s:14:\"Delete Company\";s:4:\"icon\";N;s:10:\"route_name\";s:17:\"companies.destroy\";s:10:\"route_path\";s:15:\"/company/delete\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:1;s:13:\"is_permission\";b:1;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-01-29T15:23:11.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-05T04:42:55.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:1;a:21:{s:8:\"my_route\";s:12:\"/company/add\";s:6:\"status\";i:1;s:2:\"id\";i:18;s:9:\"parent_id\";i:17;s:4:\"name\";s:11:\"Add Company\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:10:\"addcompany\";s:10:\"route_path\";s:12:\"/company/add\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:1;s:13:\"is_permission\";b:1;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-07T13:55:05.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:2;a:21:{s:8:\"my_route\";s:17:\"/company/:id/edit\";s:6:\"status\";i:1;s:2:\"id\";i:19;s:9:\"parent_id\";i:17;s:4:\"name\";s:12:\"Edit Company\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:11:\"editcompany\";s:10:\"route_path\";s:17:\"/company/:id/edit\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:1;s:13:\"is_permission\";b:1;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}}}i:2;a:21:{s:8:\"my_route\";s:9:\"/currency\";s:6:\"status\";i:1;s:2:\"id\";i:165;s:9:\"parent_id\";s:0:\"\";s:4:\"name\";s:10:\"Currencies\";s:4:\"icon\";s:17:\"fas fa-money-bill\";s:10:\"route_name\";s:9:\"/currency\";s:10:\"route_path\";s:9:\"/currency\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:1;s:13:\"is_permission\";b:1;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-01-26T16:09:33.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:3;a:21:{s:8:\"my_route\";s:9:\"/timezone\";s:6:\"status\";i:1;s:2:\"id\";i:172;s:9:\"parent_id\";s:0:\"\";s:4:\"name\";s:9:\"Timezones\";s:4:\"icon\";s:16:\"far fa-hourglass\";s:10:\"route_name\";s:9:\"/timezone\";s:10:\"route_path\";s:9:\"/timezone\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:1;s:13:\"is_permission\";b:1;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-01-26T16:09:33.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:4;a:21:{s:8:\"my_route\";N;s:6:\"status\";i:1;s:2:\"id\";i:26;s:9:\"parent_id\";s:0:\"\";s:4:\"name\";s:15:\"User Management\";s:4:\"icon\";s:11:\"fal fa-user\";s:10:\"route_name\";s:0:\"\";s:10:\"route_path\";N;s:10:\"menu_color\";N;s:10:\"sort_order\";i:5;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:2;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-02-12T05:58:52.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-12T05:58:52.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:4:{i:0;a:21:{s:8:\"my_route\";s:7:\"/branch\";s:6:\"status\";i:1;s:2:\"id\";i:22;s:9:\"parent_id\";i:26;s:4:\"name\";s:6:\"Branch\";s:4:\"icon\";s:15:\"bx bx-buildings\";s:10:\"route_name\";s:6:\"branch\";s:10:\"route_path\";s:7:\"/branch\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-09-13T05:04:16.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:3:{i:0;a:21:{s:8:\"my_route\";s:14:\"/branch/delete\";s:6:\"status\";i:1;s:2:\"id\";i:25;s:9:\"parent_id\";i:22;s:4:\"name\";s:13:\"Delete Branch\";s:4:\"icon\";N;s:10:\"route_name\";s:16:\"branches.destroy\";s:10:\"route_path\";s:14:\"/branch/delete\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-01-29T15:23:11.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-05T04:42:55.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:1;a:21:{s:8:\"my_route\";s:16:\"/branch/:id/edit\";s:6:\"status\";i:1;s:2:\"id\";i:24;s:9:\"parent_id\";i:22;s:4:\"name\";s:11:\"Edit Branch\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:10:\"editbranch\";s:10:\"route_path\";s:16:\"/branch/:id/edit\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:2;a:21:{s:8:\"my_route\";s:11:\"/branch/add\";s:6:\"status\";i:1;s:2:\"id\";i:23;s:9:\"parent_id\";i:22;s:4:\"name\";s:10:\"Add Branch\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:9:\"addbranch\";s:10:\"route_path\";s:11:\"/branch/add\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-07T13:55:05.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}}}i:1;a:21:{s:8:\"my_route\";s:11:\"/department\";s:6:\"status\";i:1;s:2:\"id\";i:27;s:9:\"parent_id\";i:26;s:4:\"name\";s:10:\"Department\";s:4:\"icon\";s:15:\"bx bx-buildings\";s:10:\"route_name\";s:10:\"department\";s:10:\"route_path\";s:11:\"/department\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-12T06:54:03.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:3:{i:0;a:21:{s:8:\"my_route\";s:18:\"/department/delete\";s:6:\"status\";i:1;s:2:\"id\";i:30;s:9:\"parent_id\";i:27;s:4:\"name\";s:17:\"Delete Department\";s:4:\"icon\";N;s:10:\"route_name\";s:19:\"departments.destroy\";s:10:\"route_path\";s:18:\"/department/delete\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-01-29T15:23:11.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-05T04:42:55.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:1;a:21:{s:8:\"my_route\";s:15:\"/department/add\";s:6:\"status\";i:1;s:2:\"id\";i:28;s:9:\"parent_id\";i:27;s:4:\"name\";s:14:\"Add Department\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:13:\"adddepartment\";s:10:\"route_path\";s:15:\"/department/add\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-07T13:55:05.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:2;a:21:{s:8:\"my_route\";s:20:\"/department/:id/edit\";s:6:\"status\";i:1;s:2:\"id\";i:29;s:9:\"parent_id\";i:27;s:4:\"name\";s:15:\"Edit Department\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:14:\"editdepartment\";s:10:\"route_path\";s:20:\"/department/:id/edit\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}}}i:2;a:21:{s:8:\"my_route\";s:5:\"/role\";s:6:\"status\";i:1;s:2:\"id\";i:31;s:9:\"parent_id\";i:26;s:4:\"name\";s:4:\"Role\";s:4:\"icon\";s:15:\"bx bx-buildings\";s:10:\"route_name\";s:4:\"role\";s:10:\"route_path\";s:5:\"/role\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-12T06:54:03.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:7:{i:0;a:21:{s:8:\"my_route\";s:12:\"/role/delete\";s:6:\"status\";i:1;s:2:\"id\";i:34;s:9:\"parent_id\";i:31;s:4:\"name\";s:11:\"Delete Role\";s:4:\"icon\";N;s:10:\"route_name\";s:13:\"roles.destroy\";s:10:\"route_path\";s:12:\"/role/delete\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-01-29T15:23:11.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-05T04:42:55.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:1;a:21:{s:8:\"my_route\";s:9:\"/role/add\";s:6:\"status\";i:1;s:2:\"id\";i:32;s:9:\"parent_id\";i:31;s:4:\"name\";s:8:\"Add Role\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:7:\"addrole\";s:10:\"route_path\";s:9:\"/role/add\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-07T13:55:05.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:2;a:21:{s:8:\"my_route\";s:14:\"/role/:id/edit\";s:6:\"status\";i:1;s:2:\"id\";i:33;s:9:\"parent_id\";i:31;s:4:\"name\";s:9:\"Edit Role\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:8:\"editrole\";s:10:\"route_path\";s:14:\"/role/:id/edit\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:3;a:21:{s:8:\"my_route\";s:12:\"/role/export\";s:6:\"status\";i:1;s:2:\"id\";i:156;s:9:\"parent_id\";i:31;s:4:\"name\";s:11:\"Role Export\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:11:\"role.export\";s:10:\"route_path\";s:12:\"/role/export\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:4;a:21:{s:8:\"my_route\";s:12:\"/role/import\";s:6:\"status\";i:1;s:2:\"id\";i:157;s:9:\"parent_id\";i:31;s:4:\"name\";s:11:\"Role Import\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:11:\"role.import\";s:10:\"route_path\";s:12:\"/role/import\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:5;a:21:{s:8:\"my_route\";s:11:\"/role/trash\";s:6:\"status\";i:1;s:2:\"id\";i:158;s:9:\"parent_id\";i:31;s:4:\"name\";s:10:\"Role Trash\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:10:\"role.trash\";s:10:\"route_path\";s:11:\"/role/trash\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:6;a:21:{s:8:\"my_route\";s:20:\"/role/:id/permission\";s:6:\"status\";i:1;s:2:\"id\";i:35;s:9:\"parent_id\";i:31;s:4:\"name\";s:15:\"Role Permission\";s:4:\"icon\";N;s:10:\"route_name\";s:14:\"permissionrole\";s:10:\"route_path\";s:20:\"/role/:id/permission\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:12;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}}}i:3;a:21:{s:8:\"my_route\";s:5:\"/user\";s:6:\"status\";i:1;s:2:\"id\";i:36;s:9:\"parent_id\";i:26;s:4:\"name\";s:4:\"User\";s:4:\"icon\";s:10:\"bx bx-user\";s:10:\"route_name\";s:4:\"user\";s:10:\"route_path\";s:5:\"/user\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-12T06:54:03.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:3:{i:0;a:21:{s:8:\"my_route\";s:12:\"/user/delete\";s:6:\"status\";i:1;s:2:\"id\";i:39;s:9:\"parent_id\";i:36;s:4:\"name\";s:11:\"Delete User\";s:4:\"icon\";N;s:10:\"route_name\";s:13:\"users.destroy\";s:10:\"route_path\";s:12:\"/user/delete\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-01-29T15:23:11.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-05T04:42:55.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:1;a:21:{s:8:\"my_route\";s:14:\"/user/:id/edit\";s:6:\"status\";i:1;s:2:\"id\";i:38;s:9:\"parent_id\";i:36;s:4:\"name\";s:9:\"Edit User\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:8:\"edituser\";s:10:\"route_path\";s:14:\"/user/:id/edit\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:2;a:21:{s:8:\"my_route\";s:9:\"/user/add\";s:6:\"status\";i:1;s:2:\"id\";i:37;s:9:\"parent_id\";i:36;s:4:\"name\";s:8:\"Add User\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:7:\"adduser\";s:10:\"route_path\";s:9:\"/user/add\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-07T13:55:05.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}}}}}i:5;a:21:{s:8:\"my_route\";s:16:\"/company/setting\";s:6:\"status\";i:1;s:2:\"id\";i:40;s:9:\"parent_id\";s:0:\"\";s:4:\"name\";s:16:\"Company Settings\";s:4:\"icon\";s:10:\"fal fa-cog\";s:10:\"route_name\";s:15:\"businesssetting\";s:10:\"route_path\";s:16:\"/company/setting\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:5;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-02-12T05:58:52.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-12T05:58:52.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:3:{i:0;a:21:{s:8:\"my_route\";s:18:\"/business/settings\";s:6:\"status\";i:1;s:2:\"id\";i:41;s:9:\"parent_id\";i:40;s:4:\"name\";s:17:\"Business Settings\";s:4:\"icon\";N;s:10:\"route_name\";s:15:\"businesssetting\";s:10:\"route_path\";s:18:\"/business/settings\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-01-29T15:23:11.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-05T04:42:55.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:1;a:21:{s:8:\"my_route\";s:4:\"/tax\";s:6:\"status\";i:1;s:2:\"id\";i:42;s:9:\"parent_id\";i:40;s:4:\"name\";s:3:\"Tax\";s:4:\"icon\";s:15:\"bx bx-buildings\";s:10:\"route_name\";s:3:\"tax\";s:10:\"route_path\";s:4:\"/tax\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-12T06:54:03.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:3:{i:0;a:21:{s:8:\"my_route\";s:11:\"/tax/delete\";s:6:\"status\";i:1;s:2:\"id\";i:45;s:9:\"parent_id\";i:42;s:4:\"name\";s:10:\"Delete Tax\";s:4:\"icon\";N;s:10:\"route_name\";s:13:\"taxes.destroy\";s:10:\"route_path\";s:11:\"/tax/delete\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-01-29T15:23:11.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-05T04:42:55.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:1;a:21:{s:8:\"my_route\";s:8:\"/tax/add\";s:6:\"status\";i:1;s:2:\"id\";i:43;s:9:\"parent_id\";i:42;s:4:\"name\";s:7:\"Add Tax\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:6:\"addtax\";s:10:\"route_path\";s:8:\"/tax/add\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-07T13:55:05.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:2;a:21:{s:8:\"my_route\";s:13:\"/tax/:id/edit\";s:6:\"status\";i:1;s:2:\"id\";i:44;s:9:\"parent_id\";i:42;s:4:\"name\";s:8:\"Edit Tax\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:7:\"edittax\";s:10:\"route_path\";s:13:\"/tax/:id/edit\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}}}i:2;a:21:{s:8:\"my_route\";s:14:\"/financialyear\";s:6:\"status\";i:1;s:2:\"id\";i:46;s:9:\"parent_id\";i:40;s:4:\"name\";s:14:\"Financial Year\";s:4:\"icon\";s:15:\"bx bx-buildings\";s:10:\"route_name\";s:13:\"financialyear\";s:10:\"route_path\";s:14:\"/financialyear\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-12T06:54:03.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:1:{i:0;a:21:{s:8:\"my_route\";s:18:\"/financialyear/add\";s:6:\"status\";i:1;s:2:\"id\";i:47;s:9:\"parent_id\";i:46;s:4:\"name\";s:18:\"Add Financial Year\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:16:\"addfinancialyear\";s:10:\"route_path\";s:18:\"/financialyear/add\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-07T13:55:05.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}}}}}i:6;a:21:{s:8:\"my_route\";N;s:6:\"status\";i:1;s:2:\"id\";i:48;s:9:\"parent_id\";s:0:\"\";s:4:\"name\";s:8:\"Accounts\";s:4:\"icon\";s:19:\"fal fa-file-invoice\";s:10:\"route_name\";s:0:\"\";s:10:\"route_path\";N;s:10:\"menu_color\";N;s:10:\"sort_order\";i:5;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:2;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-02-12T05:58:52.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-12T05:58:52.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:4:{i:0;a:21:{s:8:\"my_route\";s:15:\"/openingbalance\";s:6:\"status\";i:1;s:2:\"id\";i:138;s:9:\"parent_id\";i:48;s:4:\"name\";s:15:\"Opening Balance\";s:4:\"icon\";N;s:10:\"route_name\";s:0:\"\";s:10:\"route_path\";s:15:\"/openingbalance\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-11-29T13:55:14.000000Z\";s:10:\"updated_at\";s:27:\"2022-11-29T13:55:14.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:1;a:21:{s:8:\"my_route\";s:13:\"/journalentry\";s:6:\"status\";i:1;s:2:\"id\";i:140;s:9:\"parent_id\";i:48;s:4:\"name\";s:13:\"Journal Entry\";s:4:\"icon\";N;s:10:\"route_name\";s:0:\"\";s:10:\"route_path\";s:13:\"/journalentry\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2023-09-26T02:40:40.000000Z\";s:10:\"updated_at\";s:27:\"2023-09-26T02:40:40.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:8:{i:0;a:21:{s:8:\"my_route\";s:20:\"/journalentry/delete\";s:6:\"status\";i:1;s:2:\"id\";i:143;s:9:\"parent_id\";i:140;s:4:\"name\";s:20:\"Delete Journal Entry\";s:4:\"icon\";N;s:10:\"route_name\";s:22:\"journalentries.destroy\";s:10:\"route_path\";s:20:\"/journalentry/delete\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-01-29T15:23:11.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-05T04:42:55.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:1;a:21:{s:8:\"my_route\";s:17:\"/journalentry/add\";s:6:\"status\";i:1;s:2:\"id\";i:141;s:9:\"parent_id\";i:140;s:4:\"name\";s:17:\"Add Journal Entry\";s:4:\"icon\";N;s:10:\"route_name\";s:0:\"\";s:10:\"route_path\";s:17:\"/journalentry/add\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2023-09-26T05:57:16.000000Z\";s:10:\"updated_at\";s:27:\"2023-09-26T05:57:16.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:2;a:21:{s:8:\"my_route\";s:22:\"/journalentry/:id/edit\";s:6:\"status\";i:1;s:2:\"id\";i:142;s:9:\"parent_id\";i:140;s:4:\"name\";s:18:\"Edit Journal Entry\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:16:\"editjournalentry\";s:10:\"route_path\";s:22:\"/journalentry/:id/edit\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:3;a:21:{s:8:\"my_route\";s:22:\"/journalentry/:id/view\";s:6:\"status\";i:1;s:2:\"id\";i:144;s:9:\"parent_id\";i:140;s:4:\"name\";s:18:\"View Journal Entry\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:16:\"viewjournalentry\";s:10:\"route_path\";s:22:\"/journalentry/:id/view\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:4;a:21:{s:8:\"my_route\";s:25:\"/journalentry/:id/approve\";s:6:\"status\";i:1;s:2:\"id\";i:145;s:9:\"parent_id\";i:140;s:4:\"name\";s:21:\"Journal Entry Approve\";s:4:\"icon\";s:15:\"bx bx-buildings\";s:10:\"route_name\";s:20:\"journalentryapproval\";s:10:\"route_path\";s:25:\"/journalentry/:id/approve\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-12T06:54:03.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:5;a:21:{s:8:\"my_route\";s:24:\"/journalentry/:id/reject\";s:6:\"status\";i:1;s:2:\"id\";i:146;s:9:\"parent_id\";i:140;s:4:\"name\";s:20:\"Journal Entry Reject\";s:4:\"icon\";s:15:\"bx bx-buildings\";s:10:\"route_name\";s:18:\"journalentryreject\";s:10:\"route_path\";s:24:\"/journalentry/:id/reject\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-12T06:54:03.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:6;a:21:{s:8:\"my_route\";s:23:\"/journalentry/:id/print\";s:6:\"status\";i:1;s:2:\"id\";i:147;s:9:\"parent_id\";i:140;s:4:\"name\";s:19:\"Journal Entry Print\";s:4:\"icon\";s:15:\"bx bx-buildings\";s:10:\"route_name\";s:17:\"journalentryprint\";s:10:\"route_path\";s:23:\"/journalentry/:id/print\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-12T06:54:03.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:7;a:21:{s:8:\"my_route\";s:22:\"/journalentry/approval\";s:6:\"status\";i:1;s:2:\"id\";i:148;s:9:\"parent_id\";i:140;s:4:\"name\";s:13:\"Journal Entry\";s:4:\"icon\";s:15:\"bx bx-buildings\";s:10:\"route_name\";s:20:\"journalentryapproval\";s:10:\"route_path\";s:22:\"/journalentry/approval\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-12T06:54:03.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}}}i:2;a:21:{s:8:\"my_route\";s:10:\"/acpayment\";s:6:\"status\";i:1;s:2:\"id\";i:149;s:9:\"parent_id\";i:48;s:4:\"name\";s:7:\"Payment\";s:4:\"icon\";N;s:10:\"route_name\";s:0:\"\";s:10:\"route_path\";s:10:\"/acpayment\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2023-09-26T02:40:40.000000Z\";s:10:\"updated_at\";s:27:\"2023-09-26T02:40:40.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:3:{i:0;a:21:{s:8:\"my_route\";s:14:\"/acpayment/add\";s:6:\"status\";i:1;s:2:\"id\";i:150;s:9:\"parent_id\";i:149;s:4:\"name\";s:11:\"Add Payment\";s:4:\"icon\";N;s:10:\"route_name\";s:0:\"\";s:10:\"route_path\";s:14:\"/acpayment/add\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2023-09-26T05:57:16.000000Z\";s:10:\"updated_at\";s:27:\"2023-09-26T05:57:16.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:1;a:21:{s:8:\"my_route\";s:17:\"/acpayment/delete\";s:6:\"status\";i:1;s:2:\"id\";i:152;s:9:\"parent_id\";i:149;s:4:\"name\";s:14:\"Delete Payment\";s:4:\"icon\";N;s:10:\"route_name\";s:18:\"acpayments.destroy\";s:10:\"route_path\";s:17:\"/acpayment/delete\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-01-29T15:23:11.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-05T04:42:55.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:2;a:21:{s:8:\"my_route\";s:19:\"/acpayment/:id/edit\";s:6:\"status\";i:1;s:2:\"id\";i:151;s:9:\"parent_id\";i:149;s:4:\"name\";s:12:\"Edit Payment\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:13:\"editacpayment\";s:10:\"route_path\";s:19:\"/acpayment/:id/edit\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}}}i:3;a:21:{s:8:\"my_route\";s:17:\"/chart-of-account\";s:6:\"status\";i:1;s:2:\"id\";i:49;s:9:\"parent_id\";i:48;s:4:\"name\";s:16:\"Chart Of Account\";s:4:\"icon\";s:15:\"bx bx-buildings\";s:10:\"route_name\";s:14:\"chartofaccount\";s:10:\"route_path\";s:17:\"/chart-of-account\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-12T06:54:03.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:3:{i:0;a:21:{s:8:\"my_route\";s:24:\"/chart-of-account/delete\";s:6:\"status\";i:1;s:2:\"id\";i:52;s:9:\"parent_id\";i:49;s:4:\"name\";s:23:\"Delete Chart Of Account\";s:4:\"icon\";N;s:10:\"route_name\";s:23:\"chartofaccounts.destroy\";s:10:\"route_path\";s:24:\"/chart-of-account/delete\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-01-29T15:23:11.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-05T04:42:55.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:1;a:21:{s:8:\"my_route\";s:21:\"/chart-of-account/add\";s:6:\"status\";i:1;s:2:\"id\";i:50;s:9:\"parent_id\";i:49;s:4:\"name\";s:20:\"Add Chart Of Account\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:17:\"addchartofaccount\";s:10:\"route_path\";s:21:\"/chart-of-account/add\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-07T13:55:05.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:2;a:21:{s:8:\"my_route\";s:26:\"/chart-of-account/:id/edit\";s:6:\"status\";i:1;s:2:\"id\";i:51;s:9:\"parent_id\";i:49;s:4:\"name\";s:21:\"Edit Chart Of Account\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:18:\"editchartofaccount\";s:10:\"route_path\";s:26:\"/chart-of-account/:id/edit\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}}}}}i:7;a:21:{s:8:\"my_route\";N;s:6:\"status\";i:1;s:2:\"id\";i:53;s:9:\"parent_id\";s:0:\"\";s:4:\"name\";s:8:\"Contacts\";s:4:\"icon\";s:15:\"fal fa-id-badge\";s:10:\"route_name\";s:0:\"\";s:10:\"route_path\";N;s:10:\"menu_color\";N;s:10:\"sort_order\";i:5;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:2;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-02-12T05:58:52.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-12T05:58:52.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:3:{i:0;a:21:{s:8:\"my_route\";s:9:\"/supplier\";s:6:\"status\";i:1;s:2:\"id\";i:54;s:9:\"parent_id\";i:53;s:4:\"name\";s:8:\"Supplier\";s:4:\"icon\";s:15:\"bx bx-buildings\";s:10:\"route_name\";s:8:\"supplier\";s:10:\"route_path\";s:9:\"/supplier\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-12T06:54:03.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:3:{i:0;a:21:{s:8:\"my_route\";s:16:\"/supplier/delete\";s:6:\"status\";i:1;s:2:\"id\";i:57;s:9:\"parent_id\";i:54;s:4:\"name\";s:15:\"Delete Supplier\";s:4:\"icon\";N;s:10:\"route_name\";s:17:\"suppliers.destroy\";s:10:\"route_path\";s:16:\"/supplier/delete\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-01-29T15:23:11.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-05T04:42:55.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:1;a:21:{s:8:\"my_route\";s:13:\"/supplier/add\";s:6:\"status\";i:1;s:2:\"id\";i:55;s:9:\"parent_id\";i:54;s:4:\"name\";s:12:\"Add Supplier\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:11:\"addsupplier\";s:10:\"route_path\";s:13:\"/supplier/add\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-07T13:55:05.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:2;a:21:{s:8:\"my_route\";s:18:\"/supplier/:id/edit\";s:6:\"status\";i:1;s:2:\"id\";i:56;s:9:\"parent_id\";i:54;s:4:\"name\";s:13:\"Edit Supplier\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:12:\"editsupplier\";s:10:\"route_path\";s:18:\"/supplier/:id/edit\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}}}i:1;a:21:{s:8:\"my_route\";s:9:\"/customer\";s:6:\"status\";i:1;s:2:\"id\";i:58;s:9:\"parent_id\";i:53;s:4:\"name\";s:8:\"Customer\";s:4:\"icon\";s:15:\"bx bx-buildings\";s:10:\"route_name\";s:8:\"customer\";s:10:\"route_path\";s:9:\"/customer\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-12T06:54:03.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:5:{i:0;a:21:{s:8:\"my_route\";s:16:\"/customer/delete\";s:6:\"status\";i:1;s:2:\"id\";i:61;s:9:\"parent_id\";i:58;s:4:\"name\";s:15:\"Delete Customer\";s:4:\"icon\";N;s:10:\"route_name\";s:17:\"customers.destroy\";s:10:\"route_path\";s:16:\"/customer/delete\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-01-29T15:23:11.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-05T04:42:55.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:1;a:21:{s:8:\"my_route\";s:18:\"/supplier/:id/view\";s:6:\"status\";i:1;s:2:\"id\";i:137;s:9:\"parent_id\";i:58;s:4:\"name\";s:13:\"View Supplier\";s:4:\"icon\";N;s:10:\"route_name\";s:0:\"\";s:10:\"route_path\";s:18:\"/supplier/:id/view\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-11-26T06:35:01.000000Z\";s:10:\"updated_at\";s:27:\"2022-11-26T06:35:01.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:2;a:21:{s:8:\"my_route\";s:18:\"/customer/:id/view\";s:6:\"status\";i:1;s:2:\"id\";i:139;s:9:\"parent_id\";i:58;s:4:\"name\";s:13:\"View Customer\";s:4:\"icon\";N;s:10:\"route_name\";s:0:\"\";s:10:\"route_path\";s:18:\"/customer/:id/view\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-11-26T06:35:01.000000Z\";s:10:\"updated_at\";s:27:\"2022-11-26T06:35:01.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:3;a:21:{s:8:\"my_route\";s:13:\"/customer/add\";s:6:\"status\";i:1;s:2:\"id\";i:59;s:9:\"parent_id\";i:58;s:4:\"name\";s:12:\"Add Customer\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:11:\"addcustomer\";s:10:\"route_path\";s:13:\"/customer/add\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-07T13:55:05.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:4;a:21:{s:8:\"my_route\";s:18:\"/customer/:id/edit\";s:6:\"status\";i:1;s:2:\"id\";i:60;s:9:\"parent_id\";i:58;s:4:\"name\";s:13:\"Edit Customer\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:12:\"editcustomer\";s:10:\"route_path\";s:18:\"/customer/:id/edit\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}}}i:2;a:21:{s:8:\"my_route\";s:5:\"/bank\";s:6:\"status\";i:1;s:2:\"id\";i:62;s:9:\"parent_id\";i:53;s:4:\"name\";s:4:\"Bank\";s:4:\"icon\";s:15:\"bx bx-buildings\";s:10:\"route_name\";s:4:\"bank\";s:10:\"route_path\";s:5:\"/bank\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-12T06:54:03.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:3:{i:0;a:21:{s:8:\"my_route\";s:12:\"/bank/delete\";s:6:\"status\";i:1;s:2:\"id\";i:65;s:9:\"parent_id\";i:62;s:4:\"name\";s:11:\"Delete Bank\";s:4:\"icon\";N;s:10:\"route_name\";s:13:\"banks.destroy\";s:10:\"route_path\";s:12:\"/bank/delete\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-01-29T15:23:11.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-05T04:42:55.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:1;a:21:{s:8:\"my_route\";s:9:\"/bank/add\";s:6:\"status\";i:1;s:2:\"id\";i:63;s:9:\"parent_id\";i:62;s:4:\"name\";s:8:\"Add Bank\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:7:\"addbank\";s:10:\"route_path\";s:9:\"/bank/add\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-07T13:55:05.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:2;a:21:{s:8:\"my_route\";s:14:\"/bank/:id/edit\";s:6:\"status\";i:1;s:2:\"id\";i:64;s:9:\"parent_id\";i:62;s:4:\"name\";s:9:\"Edit Bank\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:8:\"editbank\";s:10:\"route_path\";s:14:\"/bank/:id/edit\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}}}}}i:8;a:21:{s:8:\"my_route\";N;s:6:\"status\";i:1;s:2:\"id\";i:66;s:9:\"parent_id\";s:0:\"\";s:4:\"name\";s:8:\"Products\";s:4:\"icon\";s:19:\"fab fa-product-hunt\";s:10:\"route_name\";s:0:\"\";s:10:\"route_path\";N;s:10:\"menu_color\";N;s:10:\"sort_order\";i:5;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:2;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-02-12T05:58:52.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-12T05:58:52.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:8:{i:0;a:21:{s:8:\"my_route\";s:5:\"/unit\";s:6:\"status\";i:1;s:2:\"id\";i:67;s:9:\"parent_id\";i:66;s:4:\"name\";s:4:\"Unit\";s:4:\"icon\";s:15:\"bx bx-buildings\";s:10:\"route_name\";s:4:\"unit\";s:10:\"route_path\";s:5:\"/unit\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-12T06:54:03.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:3:{i:0;a:21:{s:8:\"my_route\";s:12:\"/unit/delete\";s:6:\"status\";i:1;s:2:\"id\";i:70;s:9:\"parent_id\";i:67;s:4:\"name\";s:11:\"Delete Unit\";s:4:\"icon\";N;s:10:\"route_name\";s:13:\"units.destroy\";s:10:\"route_path\";s:12:\"/unit/delete\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-01-29T15:23:11.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-05T04:42:55.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:1;a:21:{s:8:\"my_route\";s:9:\"/unit/add\";s:6:\"status\";i:1;s:2:\"id\";i:68;s:9:\"parent_id\";i:67;s:4:\"name\";s:8:\"Add Unit\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:7:\"addunit\";s:10:\"route_path\";s:9:\"/unit/add\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-07T13:55:05.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:2;a:21:{s:8:\"my_route\";s:14:\"/unit/:id/edit\";s:6:\"status\";i:1;s:2:\"id\";i:69;s:9:\"parent_id\";i:67;s:4:\"name\";s:9:\"Edit Unit\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:8:\"editunit\";s:10:\"route_path\";s:14:\"/unit/:id/edit\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}}}i:1;a:21:{s:8:\"my_route\";s:9:\"/category\";s:6:\"status\";i:1;s:2:\"id\";i:71;s:9:\"parent_id\";i:66;s:4:\"name\";s:8:\"Category\";s:4:\"icon\";s:15:\"bx bx-buildings\";s:10:\"route_name\";s:8:\"category\";s:10:\"route_path\";s:9:\"/category\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-12T06:54:03.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:3:{i:0;a:21:{s:8:\"my_route\";s:16:\"/category/delete\";s:6:\"status\";i:1;s:2:\"id\";i:74;s:9:\"parent_id\";i:71;s:4:\"name\";s:15:\"Delete Category\";s:4:\"icon\";N;s:10:\"route_name\";s:18:\"categories.destroy\";s:10:\"route_path\";s:16:\"/category/delete\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-01-29T15:23:11.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-05T04:42:55.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:1;a:21:{s:8:\"my_route\";s:13:\"/category/add\";s:6:\"status\";i:1;s:2:\"id\";i:72;s:9:\"parent_id\";i:71;s:4:\"name\";s:12:\"Add Category\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:11:\"addcategory\";s:10:\"route_path\";s:13:\"/category/add\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-07T13:55:05.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:2;a:21:{s:8:\"my_route\";s:18:\"/category/:id/edit\";s:6:\"status\";i:1;s:2:\"id\";i:73;s:9:\"parent_id\";i:71;s:4:\"name\";s:13:\"Edit Category\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:12:\"editcategory\";s:10:\"route_path\";s:18:\"/category/:id/edit\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}}}i:2;a:21:{s:8:\"my_route\";s:6:\"/brand\";s:6:\"status\";i:1;s:2:\"id\";i:75;s:9:\"parent_id\";i:66;s:4:\"name\";s:5:\"Brand\";s:4:\"icon\";s:15:\"bx bx-buildings\";s:10:\"route_name\";s:5:\"brand\";s:10:\"route_path\";s:6:\"/brand\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-12T06:54:03.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:3:{i:0;a:21:{s:8:\"my_route\";s:13:\"/brand/delete\";s:6:\"status\";i:1;s:2:\"id\";i:78;s:9:\"parent_id\";i:75;s:4:\"name\";s:12:\"Delete Brand\";s:4:\"icon\";N;s:10:\"route_name\";s:14:\"brands.destroy\";s:10:\"route_path\";s:13:\"/brand/delete\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-01-29T15:23:11.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-05T04:42:55.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:1;a:21:{s:8:\"my_route\";s:10:\"/brand/add\";s:6:\"status\";i:1;s:2:\"id\";i:76;s:9:\"parent_id\";i:75;s:4:\"name\";s:9:\"Add Brand\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:8:\"addbrand\";s:10:\"route_path\";s:10:\"/brand/add\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-07T13:55:05.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:2;a:21:{s:8:\"my_route\";s:15:\"/brand/:id/edit\";s:6:\"status\";i:1;s:2:\"id\";i:77;s:9:\"parent_id\";i:75;s:4:\"name\";s:10:\"Edit Brand\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:9:\"editbrand\";s:10:\"route_path\";s:15:\"/brand/:id/edit\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}}}i:3;a:21:{s:8:\"my_route\";s:9:\"/warranty\";s:6:\"status\";i:1;s:2:\"id\";i:79;s:9:\"parent_id\";i:66;s:4:\"name\";s:8:\"Warranty\";s:4:\"icon\";s:15:\"bx bx-buildings\";s:10:\"route_name\";s:8:\"warranty\";s:10:\"route_path\";s:9:\"/warranty\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-12T06:54:03.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:3:{i:0;a:21:{s:8:\"my_route\";s:16:\"/warranty/delete\";s:6:\"status\";i:1;s:2:\"id\";i:82;s:9:\"parent_id\";i:79;s:4:\"name\";s:15:\"Delete Warranty\";s:4:\"icon\";N;s:10:\"route_name\";s:18:\"warranties.destroy\";s:10:\"route_path\";s:16:\"/warranty/delete\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-01-29T15:23:11.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-05T04:42:55.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:1;a:21:{s:8:\"my_route\";s:13:\"/warranty/add\";s:6:\"status\";i:1;s:2:\"id\";i:80;s:9:\"parent_id\";i:79;s:4:\"name\";s:12:\"Add Warranty\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:11:\"addwarranty\";s:10:\"route_path\";s:13:\"/warranty/add\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-07T13:55:05.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:2;a:21:{s:8:\"my_route\";s:18:\"/warranty/:id/edit\";s:6:\"status\";i:1;s:2:\"id\";i:81;s:9:\"parent_id\";i:79;s:4:\"name\";s:13:\"Edit Warranty\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:12:\"editwarranty\";s:10:\"route_path\";s:18:\"/warranty/:id/edit\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}}}i:4;a:21:{s:8:\"my_route\";s:9:\"/itemtype\";s:6:\"status\";i:1;s:2:\"id\";i:83;s:9:\"parent_id\";i:66;s:4:\"name\";s:9:\"Item Type\";s:4:\"icon\";s:15:\"bx bx-buildings\";s:10:\"route_name\";s:8:\"itemtype\";s:10:\"route_path\";s:9:\"/itemtype\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-12T06:54:03.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:3:{i:0;a:21:{s:8:\"my_route\";s:16:\"/itemtype/delete\";s:6:\"status\";i:1;s:2:\"id\";i:86;s:9:\"parent_id\";i:83;s:4:\"name\";s:16:\"Delete Item Type\";s:4:\"icon\";N;s:10:\"route_name\";s:17:\"itemtypes.destroy\";s:10:\"route_path\";s:16:\"/itemtype/delete\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-01-29T15:23:11.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-05T04:42:55.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:1;a:21:{s:8:\"my_route\";s:13:\"/itemtype/add\";s:6:\"status\";i:1;s:2:\"id\";i:84;s:9:\"parent_id\";i:83;s:4:\"name\";s:13:\"Add Item Type\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:11:\"additemtype\";s:10:\"route_path\";s:13:\"/itemtype/add\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-07T13:55:05.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:2;a:21:{s:8:\"my_route\";s:18:\"/itemtype/:id/edit\";s:6:\"status\";i:1;s:2:\"id\";i:85;s:9:\"parent_id\";i:83;s:4:\"name\";s:14:\"Edit Item Type\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:12:\"edititemtype\";s:10:\"route_path\";s:18:\"/itemtype/:id/edit\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}}}i:5;a:21:{s:8:\"my_route\";s:10:\"/variation\";s:6:\"status\";i:1;s:2:\"id\";i:87;s:9:\"parent_id\";i:66;s:4:\"name\";s:9:\"Variation\";s:4:\"icon\";s:15:\"bx bx-buildings\";s:10:\"route_name\";s:9:\"variation\";s:10:\"route_path\";s:10:\"/variation\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-12T06:54:03.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:3:{i:0;a:21:{s:8:\"my_route\";s:17:\"/variation/delete\";s:6:\"status\";i:1;s:2:\"id\";i:90;s:9:\"parent_id\";i:87;s:4:\"name\";s:16:\"Delete Variation\";s:4:\"icon\";N;s:10:\"route_name\";s:18:\"variations.destroy\";s:10:\"route_path\";s:17:\"/variation/delete\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-01-29T15:23:11.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-05T04:42:55.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:1;a:21:{s:8:\"my_route\";s:14:\"/variation/add\";s:6:\"status\";i:1;s:2:\"id\";i:88;s:9:\"parent_id\";i:87;s:4:\"name\";s:13:\"Add Variation\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:12:\"addvariation\";s:10:\"route_path\";s:14:\"/variation/add\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-07T13:55:05.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:2;a:21:{s:8:\"my_route\";s:19:\"/variation/:id/edit\";s:6:\"status\";i:1;s:2:\"id\";i:89;s:9:\"parent_id\";i:87;s:4:\"name\";s:14:\"Edit Variation\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:13:\"editvariation\";s:10:\"route_path\";s:19:\"/variation/:id/edit\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}}}i:6;a:21:{s:8:\"my_route\";s:8:\"/product\";s:6:\"status\";i:1;s:2:\"id\";i:91;s:9:\"parent_id\";i:66;s:4:\"name\";s:7:\"Product\";s:4:\"icon\";s:15:\"bx bx-buildings\";s:10:\"route_name\";s:7:\"product\";s:10:\"route_path\";s:8:\"/product\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-12T06:54:03.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:3:{i:0;a:21:{s:8:\"my_route\";s:15:\"/product/delete\";s:6:\"status\";i:1;s:2:\"id\";i:94;s:9:\"parent_id\";i:91;s:4:\"name\";s:14:\"Delete Product\";s:4:\"icon\";N;s:10:\"route_name\";s:16:\"products.destroy\";s:10:\"route_path\";s:15:\"/product/delete\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-01-29T15:23:11.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-05T04:42:55.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:1;a:21:{s:8:\"my_route\";s:12:\"/product/add\";s:6:\"status\";i:1;s:2:\"id\";i:92;s:9:\"parent_id\";i:91;s:4:\"name\";s:11:\"Add Product\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:10:\"addproduct\";s:10:\"route_path\";s:12:\"/product/add\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-07T13:55:05.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:2;a:21:{s:8:\"my_route\";s:17:\"/product/:id/edit\";s:6:\"status\";i:1;s:2:\"id\";i:93;s:9:\"parent_id\";i:91;s:4:\"name\";s:12:\"Edit Product\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:11:\"editproduct\";s:10:\"route_path\";s:17:\"/product/:id/edit\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}}}i:7;a:21:{s:8:\"my_route\";s:13:\"/openingstock\";s:6:\"status\";i:1;s:2:\"id\";i:95;s:9:\"parent_id\";i:66;s:4:\"name\";s:13:\"Opening Stock\";s:4:\"icon\";s:10:\"bx bx-data\";s:10:\"route_name\";s:12:\"openingstock\";s:10:\"route_path\";s:13:\"/openingstock\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-12T06:54:03.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:1:{i:0;a:21:{s:8:\"my_route\";s:17:\"/openingstock/add\";s:6:\"status\";i:1;s:2:\"id\";i:96;s:9:\"parent_id\";i:95;s:4:\"name\";s:24:\"Add / Edit Opening Stock\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:15:\"addopeningstock\";s:10:\"route_path\";s:17:\"/openingstock/add\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-07T13:55:05.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}}}}}i:9;a:21:{s:8:\"my_route\";N;s:6:\"status\";i:1;s:2:\"id\";i:99;s:9:\"parent_id\";s:0:\"\";s:4:\"name\";s:8:\"Purchase\";s:4:\"icon\";s:11:\"fal fa-tags\";s:10:\"route_name\";s:0:\"\";s:10:\"route_path\";N;s:10:\"menu_color\";N;s:10:\"sort_order\";i:5;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:2;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-02-12T05:58:52.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-12T05:58:52.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:7:{i:0;a:21:{s:8:\"my_route\";s:15:\"/payment/delete\";s:6:\"status\";i:1;s:2:\"id\";i:115;s:9:\"parent_id\";i:99;s:4:\"name\";s:14:\"Delete Payment\";s:4:\"icon\";N;s:10:\"route_name\";s:16:\"payments.destroy\";s:10:\"route_path\";s:15:\"/payment/delete\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-01-29T15:23:11.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-05T04:42:55.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:1;a:21:{s:8:\"my_route\";s:9:\"/purchase\";s:6:\"status\";i:1;s:2:\"id\";i:100;s:9:\"parent_id\";i:99;s:4:\"name\";s:8:\"Purchase\";s:4:\"icon\";s:15:\"bx bx-buildings\";s:10:\"route_name\";s:8:\"purchase\";s:10:\"route_path\";s:9:\"/purchase\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-12T06:54:03.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:5:{i:0;a:21:{s:8:\"my_route\";s:16:\"/purchase/delete\";s:6:\"status\";i:1;s:2:\"id\";i:103;s:9:\"parent_id\";i:100;s:4:\"name\";s:15:\"Delete Purchase\";s:4:\"icon\";N;s:10:\"route_name\";s:17:\"purchases.destroy\";s:10:\"route_path\";s:16:\"/purchase/delete\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-01-29T15:23:11.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-05T04:42:55.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:1;a:21:{s:8:\"my_route\";s:13:\"/purchase/add\";s:6:\"status\";i:1;s:2:\"id\";i:101;s:9:\"parent_id\";i:100;s:4:\"name\";s:12:\"Add Purchase\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:11:\"addpurchase\";s:10:\"route_path\";s:13:\"/purchase/add\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-07T13:55:05.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:2;a:21:{s:8:\"my_route\";s:18:\"/purchase/:id/edit\";s:6:\"status\";i:1;s:2:\"id\";i:102;s:9:\"parent_id\";i:100;s:4:\"name\";s:13:\"Edit Purchase\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:12:\"editpurchase\";s:10:\"route_path\";s:18:\"/purchase/:id/edit\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:3;a:21:{s:8:\"my_route\";s:18:\"/purchase/:id/view\";s:6:\"status\";i:1;s:2:\"id\";i:104;s:9:\"parent_id\";i:100;s:4:\"name\";s:13:\"View Purchase\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:12:\"viewpurchase\";s:10:\"route_path\";s:18:\"/purchase/:id/view\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:4;a:21:{s:8:\"my_route\";s:18:\"/purchase/approval\";s:6:\"status\";i:1;s:2:\"id\";i:106;s:9:\"parent_id\";i:100;s:4:\"name\";s:8:\"Purchase\";s:4:\"icon\";s:15:\"bx bx-buildings\";s:10:\"route_name\";s:16:\"purchaseapproval\";s:10:\"route_path\";s:18:\"/purchase/approval\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-12T06:54:03.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}}}i:2;a:21:{s:8:\"my_route\";s:14:\"/receivingnote\";s:6:\"status\";i:1;s:2:\"id\";i:107;s:9:\"parent_id\";i:99;s:4:\"name\";s:14:\"Receiving Note\";s:4:\"icon\";s:15:\"bx bx-buildings\";s:10:\"route_name\";s:13:\"receivingnote\";s:10:\"route_path\";s:14:\"/receivingnote\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-12T06:54:03.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:4:{i:0;a:21:{s:8:\"my_route\";s:21:\"/receivingnote/delete\";s:6:\"status\";i:1;s:2:\"id\";i:110;s:9:\"parent_id\";i:107;s:4:\"name\";s:21:\"Delete Receiving Note\";s:4:\"icon\";N;s:10:\"route_name\";s:22:\"receivingnotes.destroy\";s:10:\"route_path\";s:21:\"/receivingnote/delete\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-01-29T15:23:11.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-05T04:42:55.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:1;a:21:{s:8:\"my_route\";s:18:\"/receivingnote/add\";s:6:\"status\";i:1;s:2:\"id\";i:108;s:9:\"parent_id\";i:107;s:4:\"name\";s:18:\"Add Receiving Note\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:16:\"addreceivingnote\";s:10:\"route_path\";s:18:\"/receivingnote/add\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-07T13:55:05.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:2;a:21:{s:8:\"my_route\";s:23:\"/receivingnote/:id/edit\";s:6:\"status\";i:1;s:2:\"id\";i:109;s:9:\"parent_id\";i:107;s:4:\"name\";s:19:\"Edit Receiving Note\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:17:\"editreceivingnote\";s:10:\"route_path\";s:23:\"/receivingnote/:id/edit\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:3;a:21:{s:8:\"my_route\";s:23:\"/receivingnote/:id/view\";s:6:\"status\";i:1;s:2:\"id\";i:111;s:9:\"parent_id\";i:107;s:4:\"name\";s:19:\"View Receiving Note\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:17:\"viewreceivingnote\";s:10:\"route_path\";s:23:\"/receivingnote/:id/view\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}}}i:3;a:21:{s:8:\"my_route\";s:12:\"/payment/add\";s:6:\"status\";i:1;s:2:\"id\";i:112;s:9:\"parent_id\";i:99;s:4:\"name\";s:19:\"Add Payment Receive\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:10:\"addpayment\";s:10:\"route_path\";s:12:\"/payment/add\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-07T13:55:05.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:4;a:21:{s:8:\"my_route\";s:8:\"/payment\";s:6:\"status\";i:1;s:2:\"id\";i:113;s:9:\"parent_id\";i:99;s:4:\"name\";s:15:\"Payment Receive\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:7:\"payment\";s:10:\"route_path\";s:8:\"/payment\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-07T13:55:05.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:5;a:21:{s:8:\"my_route\";s:17:\"/payment/:id/edit\";s:6:\"status\";i:1;s:2:\"id\";i:114;s:9:\"parent_id\";i:99;s:4:\"name\";s:12:\"Edit Payment\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:11:\"editpayment\";s:10:\"route_path\";s:17:\"/payment/:id/edit\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:6;a:21:{s:8:\"my_route\";s:16:\"/purchase/return\";s:6:\"status\";i:1;s:2:\"id\";i:128;s:9:\"parent_id\";i:99;s:4:\"name\";s:15:\"Purchase Return\";s:4:\"icon\";s:15:\"bx bx-buildings\";s:10:\"route_name\";s:14:\"purchasereturn\";s:10:\"route_path\";s:16:\"/purchase/return\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-12T06:54:03.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:4:{i:0;a:21:{s:8:\"my_route\";s:23:\"/purchase/return/delete\";s:6:\"status\";i:1;s:2:\"id\";i:131;s:9:\"parent_id\";i:128;s:4:\"name\";s:22:\"Delete Purchase Return\";s:4:\"icon\";N;s:10:\"route_name\";s:23:\"purchasereturns.destroy\";s:10:\"route_path\";s:23:\"/purchase/return/delete\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-01-29T15:23:11.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-05T04:42:55.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:1;a:21:{s:8:\"my_route\";s:20:\"/purchase/return/add\";s:6:\"status\";i:1;s:2:\"id\";i:129;s:9:\"parent_id\";i:128;s:4:\"name\";s:19:\"Add Purchase Return\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:17:\"addpurchasereturn\";s:10:\"route_path\";s:20:\"/purchase/return/add\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-07T13:55:05.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:2;a:21:{s:8:\"my_route\";s:25:\"/purchase/return/:id/edit\";s:6:\"status\";i:1;s:2:\"id\";i:130;s:9:\"parent_id\";i:128;s:4:\"name\";s:20:\"Edit Purchase Return\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:18:\"editpurchasereturn\";s:10:\"route_path\";s:25:\"/purchase/return/:id/edit\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:3;a:21:{s:8:\"my_route\";s:25:\"/purchase/return/:id/view\";s:6:\"status\";i:1;s:2:\"id\";i:132;s:9:\"parent_id\";i:128;s:4:\"name\";s:20:\"View Purchase Return\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:18:\"viewpurchasereturn\";s:10:\"route_path\";s:25:\"/purchase/return/:id/view\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}}}}}i:10;a:21:{s:8:\"my_route\";N;s:6:\"status\";i:1;s:2:\"id\";i:105;s:9:\"parent_id\";s:0:\"\";s:4:\"name\";s:8:\"Approval\";s:4:\"icon\";s:16:\"fal fa-thumbs-up\";s:10:\"route_name\";s:0:\"\";s:10:\"route_path\";N;s:10:\"menu_color\";N;s:10:\"sort_order\";i:5;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:2;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-02-12T05:58:52.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-12T05:58:52.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:11;a:21:{s:8:\"my_route\";N;s:6:\"status\";i:1;s:2:\"id\";i:116;s:9:\"parent_id\";s:0:\"\";s:4:\"name\";s:4:\"Sell\";s:4:\"icon\";s:19:\"far fa-badge-dollar\";s:10:\"route_name\";s:0:\"\";s:10:\"route_path\";N;s:10:\"menu_color\";N;s:10:\"sort_order\";i:5;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:2;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-02-12T05:58:52.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-12T05:58:52.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:6:{i:0;a:21:{s:8:\"my_route\";s:10:\"/issuenote\";s:6:\"status\";i:1;s:2:\"id\";i:123;s:9:\"parent_id\";i:116;s:4:\"name\";s:10:\"Issue Note\";s:4:\"icon\";s:15:\"bx bx-buildings\";s:10:\"route_name\";s:9:\"issuenote\";s:10:\"route_path\";s:10:\"/issuenote\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-12T06:54:03.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:4:{i:0;a:21:{s:8:\"my_route\";s:17:\"/issuenote/delete\";s:6:\"status\";i:1;s:2:\"id\";i:126;s:9:\"parent_id\";i:123;s:4:\"name\";s:17:\"Delete Issue Note\";s:4:\"icon\";N;s:10:\"route_name\";s:18:\"issuenotes.destroy\";s:10:\"route_path\";s:17:\"/issuenote/delete\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-01-29T15:23:11.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-05T04:42:55.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:1;a:21:{s:8:\"my_route\";s:14:\"/issuenote/add\";s:6:\"status\";i:1;s:2:\"id\";i:124;s:9:\"parent_id\";i:123;s:4:\"name\";s:14:\"Add Issue Note\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:12:\"addissuenote\";s:10:\"route_path\";s:14:\"/issuenote/add\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-07T13:55:05.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:2;a:21:{s:8:\"my_route\";s:19:\"/issuenote/:id/view\";s:6:\"status\";i:1;s:2:\"id\";i:127;s:9:\"parent_id\";i:123;s:4:\"name\";s:15:\"View Issue Note\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:13:\"viewissuenote\";s:10:\"route_path\";s:19:\"/issuenote/:id/view\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:3;a:21:{s:8:\"my_route\";s:19:\"/issuenote/:id/edit\";s:6:\"status\";i:1;s:2:\"id\";i:125;s:9:\"parent_id\";i:123;s:4:\"name\";s:15:\"Edit Issue Note\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:13:\"editissuenote\";s:10:\"route_path\";s:19:\"/issuenote/:id/edit\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}}}i:1;a:21:{s:8:\"my_route\";s:11:\"/sell/draft\";s:6:\"status\";i:1;s:2:\"id\";i:133;s:9:\"parent_id\";i:116;s:4:\"name\";s:10:\"List Draft\";s:4:\"icon\";s:15:\"bx bx-buildings\";s:10:\"route_name\";s:5:\"draft\";s:10:\"route_path\";s:11:\"/sell/draft\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-12T06:54:03.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:2;a:21:{s:8:\"my_route\";s:15:\"/sell/quotation\";s:6:\"status\";i:1;s:2:\"id\";i:134;s:9:\"parent_id\";i:116;s:4:\"name\";s:14:\"List Quotation\";s:4:\"icon\";s:15:\"bx bx-buildings\";s:10:\"route_name\";s:9:\"quotation\";s:10:\"route_path\";s:15:\"/sell/quotation\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-12T06:54:03.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:3;a:21:{s:8:\"my_route\";s:14:\"/sell/shipment\";s:6:\"status\";i:1;s:2:\"id\";i:135;s:9:\"parent_id\";i:116;s:4:\"name\";s:9:\"Shipments\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:8:\"shipment\";s:10:\"route_path\";s:14:\"/sell/shipment\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-07T13:55:05.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:4;a:21:{s:8:\"my_route\";s:9:\"/sell/pos\";s:6:\"status\";i:1;s:2:\"id\";i:136;s:9:\"parent_id\";i:116;s:4:\"name\";s:3:\"POS\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:3:\"pos\";s:10:\"route_path\";s:9:\"/sell/pos\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-07T13:55:05.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:5;a:21:{s:8:\"my_route\";s:5:\"/sell\";s:6:\"status\";i:1;s:2:\"id\";i:117;s:9:\"parent_id\";i:116;s:4:\"name\";s:4:\"Sell\";s:4:\"icon\";s:15:\"bx bx-buildings\";s:10:\"route_name\";s:4:\"sell\";s:10:\"route_path\";s:5:\"/sell\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-12T06:54:03.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:5:{i:0;a:21:{s:8:\"my_route\";s:12:\"/sell/delete\";s:6:\"status\";i:1;s:2:\"id\";i:120;s:9:\"parent_id\";i:117;s:4:\"name\";s:11:\"Delete Sell\";s:4:\"icon\";N;s:10:\"route_name\";s:13:\"sells.destroy\";s:10:\"route_path\";s:12:\"/sell/delete\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-01-29T15:23:11.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-05T04:42:55.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:1;a:21:{s:8:\"my_route\";s:9:\"/sell/add\";s:6:\"status\";i:1;s:2:\"id\";i:118;s:9:\"parent_id\";i:117;s:4:\"name\";s:8:\"Add Sell\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:7:\"addsell\";s:10:\"route_path\";s:9:\"/sell/add\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-07T13:55:05.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:2;a:21:{s:8:\"my_route\";s:14:\"/sell/:id/edit\";s:6:\"status\";i:1;s:2:\"id\";i:119;s:9:\"parent_id\";i:117;s:4:\"name\";s:9:\"Edit Sell\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:8:\"editsell\";s:10:\"route_path\";s:14:\"/sell/:id/edit\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:3;a:21:{s:8:\"my_route\";s:14:\"/sell/:id/view\";s:6:\"status\";i:1;s:2:\"id\";i:121;s:9:\"parent_id\";i:117;s:4:\"name\";s:9:\"View Sell\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:8:\"viewsell\";s:10:\"route_path\";s:14:\"/sell/:id/view\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:4;a:21:{s:8:\"my_route\";s:14:\"/sell/approval\";s:6:\"status\";i:1;s:2:\"id\";i:122;s:9:\"parent_id\";i:117;s:4:\"name\";s:4:\"Sell\";s:4:\"icon\";s:15:\"bx bx-buildings\";s:10:\"route_name\";s:12:\"sellapproval\";s:10:\"route_path\";s:14:\"/sell/approval\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-12T06:54:03.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}}}}}}', 1786919587);
INSERT INTO `cache` (`key`, `value`, `expiration`) VALUES
('laravel-cache-user_menu_permissions_tree:2', 'a:7:{i:0;a:21:{s:8:\"my_route\";N;s:6:\"status\";i:1;s:2:\"id\";i:26;s:9:\"parent_id\";s:0:\"\";s:4:\"name\";s:15:\"User Management\";s:4:\"icon\";s:11:\"fal fa-user\";s:10:\"route_name\";s:0:\"\";s:10:\"route_path\";N;s:10:\"menu_color\";N;s:10:\"sort_order\";i:5;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:2;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-02-12T05:58:52.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-12T05:58:52.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:4:{i:0;a:21:{s:8:\"my_route\";s:7:\"/branch\";s:6:\"status\";i:1;s:2:\"id\";i:22;s:9:\"parent_id\";i:26;s:4:\"name\";s:6:\"Branch\";s:4:\"icon\";s:15:\"bx bx-buildings\";s:10:\"route_name\";s:6:\"branch\";s:10:\"route_path\";s:7:\"/branch\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-09-13T05:04:16.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:3:{i:0;a:21:{s:8:\"my_route\";s:14:\"/branch/delete\";s:6:\"status\";i:1;s:2:\"id\";i:25;s:9:\"parent_id\";i:22;s:4:\"name\";s:13:\"Delete Branch\";s:4:\"icon\";N;s:10:\"route_name\";s:16:\"branches.destroy\";s:10:\"route_path\";s:14:\"/branch/delete\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-01-29T15:23:11.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-05T04:42:55.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:1;a:21:{s:8:\"my_route\";s:16:\"/branch/:id/edit\";s:6:\"status\";i:1;s:2:\"id\";i:24;s:9:\"parent_id\";i:22;s:4:\"name\";s:11:\"Edit Branch\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:10:\"editbranch\";s:10:\"route_path\";s:16:\"/branch/:id/edit\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:2;a:21:{s:8:\"my_route\";s:11:\"/branch/add\";s:6:\"status\";i:1;s:2:\"id\";i:23;s:9:\"parent_id\";i:22;s:4:\"name\";s:10:\"Add Branch\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:9:\"addbranch\";s:10:\"route_path\";s:11:\"/branch/add\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-07T13:55:05.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}}}i:1;a:21:{s:8:\"my_route\";s:11:\"/department\";s:6:\"status\";i:1;s:2:\"id\";i:27;s:9:\"parent_id\";i:26;s:4:\"name\";s:10:\"Department\";s:4:\"icon\";s:15:\"bx bx-buildings\";s:10:\"route_name\";s:10:\"department\";s:10:\"route_path\";s:11:\"/department\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-12T06:54:03.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:3:{i:0;a:21:{s:8:\"my_route\";s:18:\"/department/delete\";s:6:\"status\";i:1;s:2:\"id\";i:30;s:9:\"parent_id\";i:27;s:4:\"name\";s:17:\"Delete Department\";s:4:\"icon\";N;s:10:\"route_name\";s:19:\"departments.destroy\";s:10:\"route_path\";s:18:\"/department/delete\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-01-29T15:23:11.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-05T04:42:55.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:1;a:21:{s:8:\"my_route\";s:15:\"/department/add\";s:6:\"status\";i:1;s:2:\"id\";i:28;s:9:\"parent_id\";i:27;s:4:\"name\";s:14:\"Add Department\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:13:\"adddepartment\";s:10:\"route_path\";s:15:\"/department/add\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-07T13:55:05.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:2;a:21:{s:8:\"my_route\";s:20:\"/department/:id/edit\";s:6:\"status\";i:1;s:2:\"id\";i:29;s:9:\"parent_id\";i:27;s:4:\"name\";s:15:\"Edit Department\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:14:\"editdepartment\";s:10:\"route_path\";s:20:\"/department/:id/edit\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}}}i:2;a:21:{s:8:\"my_route\";s:5:\"/role\";s:6:\"status\";i:1;s:2:\"id\";i:31;s:9:\"parent_id\";i:26;s:4:\"name\";s:4:\"Role\";s:4:\"icon\";s:15:\"bx bx-buildings\";s:10:\"route_name\";s:4:\"role\";s:10:\"route_path\";s:5:\"/role\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-12T06:54:03.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:4:{i:0;a:21:{s:8:\"my_route\";s:12:\"/role/delete\";s:6:\"status\";i:1;s:2:\"id\";i:34;s:9:\"parent_id\";i:31;s:4:\"name\";s:11:\"Delete Role\";s:4:\"icon\";N;s:10:\"route_name\";s:13:\"roles.destroy\";s:10:\"route_path\";s:12:\"/role/delete\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-01-29T15:23:11.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-05T04:42:55.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:1;a:21:{s:8:\"my_route\";s:9:\"/role/add\";s:6:\"status\";i:1;s:2:\"id\";i:32;s:9:\"parent_id\";i:31;s:4:\"name\";s:8:\"Add Role\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:7:\"addrole\";s:10:\"route_path\";s:9:\"/role/add\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-07T13:55:05.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:2;a:21:{s:8:\"my_route\";s:14:\"/role/:id/edit\";s:6:\"status\";i:1;s:2:\"id\";i:33;s:9:\"parent_id\";i:31;s:4:\"name\";s:9:\"Edit Role\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:8:\"editrole\";s:10:\"route_path\";s:14:\"/role/:id/edit\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:3;a:21:{s:8:\"my_route\";s:20:\"/role/:id/permission\";s:6:\"status\";i:1;s:2:\"id\";i:35;s:9:\"parent_id\";i:31;s:4:\"name\";s:15:\"Role Permission\";s:4:\"icon\";N;s:10:\"route_name\";s:14:\"permissionrole\";s:10:\"route_path\";s:20:\"/role/:id/permission\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:12;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}}}i:3;a:21:{s:8:\"my_route\";s:5:\"/user\";s:6:\"status\";i:1;s:2:\"id\";i:36;s:9:\"parent_id\";i:26;s:4:\"name\";s:4:\"User\";s:4:\"icon\";s:10:\"bx bx-user\";s:10:\"route_name\";s:4:\"user\";s:10:\"route_path\";s:5:\"/user\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-12T06:54:03.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:3:{i:0;a:21:{s:8:\"my_route\";s:12:\"/user/delete\";s:6:\"status\";i:1;s:2:\"id\";i:39;s:9:\"parent_id\";i:36;s:4:\"name\";s:11:\"Delete User\";s:4:\"icon\";N;s:10:\"route_name\";s:13:\"users.destroy\";s:10:\"route_path\";s:12:\"/user/delete\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-01-29T15:23:11.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-05T04:42:55.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:1;a:21:{s:8:\"my_route\";s:14:\"/user/:id/edit\";s:6:\"status\";i:1;s:2:\"id\";i:38;s:9:\"parent_id\";i:36;s:4:\"name\";s:9:\"Edit User\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:8:\"edituser\";s:10:\"route_path\";s:14:\"/user/:id/edit\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:2;a:21:{s:8:\"my_route\";s:9:\"/user/add\";s:6:\"status\";i:1;s:2:\"id\";i:37;s:9:\"parent_id\";i:36;s:4:\"name\";s:8:\"Add User\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:7:\"adduser\";s:10:\"route_path\";s:9:\"/user/add\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-07T13:55:05.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}}}}}i:1;a:21:{s:8:\"my_route\";s:16:\"/company/setting\";s:6:\"status\";i:1;s:2:\"id\";i:40;s:9:\"parent_id\";s:0:\"\";s:4:\"name\";s:16:\"Company Settings\";s:4:\"icon\";s:10:\"fal fa-cog\";s:10:\"route_name\";s:15:\"businesssetting\";s:10:\"route_path\";s:16:\"/company/setting\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:5;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-02-12T05:58:52.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-12T05:58:52.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:3:{i:0;a:21:{s:8:\"my_route\";s:18:\"/business/settings\";s:6:\"status\";i:1;s:2:\"id\";i:41;s:9:\"parent_id\";i:40;s:4:\"name\";s:17:\"Business Settings\";s:4:\"icon\";N;s:10:\"route_name\";s:15:\"businesssetting\";s:10:\"route_path\";s:18:\"/business/settings\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-01-29T15:23:11.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-05T04:42:55.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:1;a:21:{s:8:\"my_route\";s:4:\"/tax\";s:6:\"status\";i:1;s:2:\"id\";i:42;s:9:\"parent_id\";i:40;s:4:\"name\";s:3:\"Tax\";s:4:\"icon\";s:15:\"bx bx-buildings\";s:10:\"route_name\";s:3:\"tax\";s:10:\"route_path\";s:4:\"/tax\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-12T06:54:03.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:3:{i:0;a:21:{s:8:\"my_route\";s:11:\"/tax/delete\";s:6:\"status\";i:1;s:2:\"id\";i:45;s:9:\"parent_id\";i:42;s:4:\"name\";s:10:\"Delete Tax\";s:4:\"icon\";N;s:10:\"route_name\";s:13:\"taxes.destroy\";s:10:\"route_path\";s:11:\"/tax/delete\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-01-29T15:23:11.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-05T04:42:55.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:1;a:21:{s:8:\"my_route\";s:8:\"/tax/add\";s:6:\"status\";i:1;s:2:\"id\";i:43;s:9:\"parent_id\";i:42;s:4:\"name\";s:7:\"Add Tax\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:6:\"addtax\";s:10:\"route_path\";s:8:\"/tax/add\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-07T13:55:05.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:2;a:21:{s:8:\"my_route\";s:13:\"/tax/:id/edit\";s:6:\"status\";i:1;s:2:\"id\";i:44;s:9:\"parent_id\";i:42;s:4:\"name\";s:8:\"Edit Tax\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:7:\"edittax\";s:10:\"route_path\";s:13:\"/tax/:id/edit\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}}}i:2;a:21:{s:8:\"my_route\";s:14:\"/financialyear\";s:6:\"status\";i:1;s:2:\"id\";i:46;s:9:\"parent_id\";i:40;s:4:\"name\";s:14:\"Financial Year\";s:4:\"icon\";s:15:\"bx bx-buildings\";s:10:\"route_name\";s:13:\"financialyear\";s:10:\"route_path\";s:14:\"/financialyear\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-12T06:54:03.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:1:{i:0;a:21:{s:8:\"my_route\";s:18:\"/financialyear/add\";s:6:\"status\";i:1;s:2:\"id\";i:47;s:9:\"parent_id\";i:46;s:4:\"name\";s:18:\"Add Financial Year\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:16:\"addfinancialyear\";s:10:\"route_path\";s:18:\"/financialyear/add\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-07T13:55:05.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}}}}}i:2;a:21:{s:8:\"my_route\";N;s:6:\"status\";i:1;s:2:\"id\";i:48;s:9:\"parent_id\";s:0:\"\";s:4:\"name\";s:8:\"Accounts\";s:4:\"icon\";s:19:\"fal fa-file-invoice\";s:10:\"route_name\";s:0:\"\";s:10:\"route_path\";N;s:10:\"menu_color\";N;s:10:\"sort_order\";i:5;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:2;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-02-12T05:58:52.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-12T05:58:52.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:4:{i:0;a:21:{s:8:\"my_route\";s:15:\"/openingbalance\";s:6:\"status\";i:1;s:2:\"id\";i:138;s:9:\"parent_id\";i:48;s:4:\"name\";s:15:\"Opening Balance\";s:4:\"icon\";N;s:10:\"route_name\";s:0:\"\";s:10:\"route_path\";s:15:\"/openingbalance\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-11-29T13:55:14.000000Z\";s:10:\"updated_at\";s:27:\"2022-11-29T13:55:14.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:1;a:21:{s:8:\"my_route\";s:13:\"/journalentry\";s:6:\"status\";i:1;s:2:\"id\";i:140;s:9:\"parent_id\";i:48;s:4:\"name\";s:13:\"Journal Entry\";s:4:\"icon\";N;s:10:\"route_name\";s:0:\"\";s:10:\"route_path\";s:13:\"/journalentry\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2023-09-26T02:40:40.000000Z\";s:10:\"updated_at\";s:27:\"2023-09-26T02:40:40.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:8:{i:0;a:21:{s:8:\"my_route\";s:20:\"/journalentry/delete\";s:6:\"status\";i:1;s:2:\"id\";i:143;s:9:\"parent_id\";i:140;s:4:\"name\";s:20:\"Delete Journal Entry\";s:4:\"icon\";N;s:10:\"route_name\";s:22:\"journalentries.destroy\";s:10:\"route_path\";s:20:\"/journalentry/delete\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-01-29T15:23:11.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-05T04:42:55.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:1;a:21:{s:8:\"my_route\";s:17:\"/journalentry/add\";s:6:\"status\";i:1;s:2:\"id\";i:141;s:9:\"parent_id\";i:140;s:4:\"name\";s:17:\"Add Journal Entry\";s:4:\"icon\";N;s:10:\"route_name\";s:0:\"\";s:10:\"route_path\";s:17:\"/journalentry/add\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2023-09-26T05:57:16.000000Z\";s:10:\"updated_at\";s:27:\"2023-09-26T05:57:16.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:2;a:21:{s:8:\"my_route\";s:22:\"/journalentry/:id/edit\";s:6:\"status\";i:1;s:2:\"id\";i:142;s:9:\"parent_id\";i:140;s:4:\"name\";s:18:\"Edit Journal Entry\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:16:\"editjournalentry\";s:10:\"route_path\";s:22:\"/journalentry/:id/edit\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:3;a:21:{s:8:\"my_route\";s:22:\"/journalentry/:id/view\";s:6:\"status\";i:1;s:2:\"id\";i:144;s:9:\"parent_id\";i:140;s:4:\"name\";s:18:\"View Journal Entry\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:16:\"viewjournalentry\";s:10:\"route_path\";s:22:\"/journalentry/:id/view\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:4;a:21:{s:8:\"my_route\";s:25:\"/journalentry/:id/approve\";s:6:\"status\";i:1;s:2:\"id\";i:145;s:9:\"parent_id\";i:140;s:4:\"name\";s:21:\"Journal Entry Approve\";s:4:\"icon\";s:15:\"bx bx-buildings\";s:10:\"route_name\";s:20:\"journalentryapproval\";s:10:\"route_path\";s:25:\"/journalentry/:id/approve\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-12T06:54:03.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:5;a:21:{s:8:\"my_route\";s:24:\"/journalentry/:id/reject\";s:6:\"status\";i:1;s:2:\"id\";i:146;s:9:\"parent_id\";i:140;s:4:\"name\";s:20:\"Journal Entry Reject\";s:4:\"icon\";s:15:\"bx bx-buildings\";s:10:\"route_name\";s:18:\"journalentryreject\";s:10:\"route_path\";s:24:\"/journalentry/:id/reject\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-12T06:54:03.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:6;a:21:{s:8:\"my_route\";s:23:\"/journalentry/:id/print\";s:6:\"status\";i:1;s:2:\"id\";i:147;s:9:\"parent_id\";i:140;s:4:\"name\";s:19:\"Journal Entry Print\";s:4:\"icon\";s:15:\"bx bx-buildings\";s:10:\"route_name\";s:17:\"journalentryprint\";s:10:\"route_path\";s:23:\"/journalentry/:id/print\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-12T06:54:03.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:7;a:21:{s:8:\"my_route\";s:22:\"/journalentry/approval\";s:6:\"status\";i:1;s:2:\"id\";i:148;s:9:\"parent_id\";i:140;s:4:\"name\";s:13:\"Journal Entry\";s:4:\"icon\";s:15:\"bx bx-buildings\";s:10:\"route_name\";s:20:\"journalentryapproval\";s:10:\"route_path\";s:22:\"/journalentry/approval\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-12T06:54:03.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}}}i:2;a:21:{s:8:\"my_route\";s:10:\"/acpayment\";s:6:\"status\";i:1;s:2:\"id\";i:149;s:9:\"parent_id\";i:48;s:4:\"name\";s:7:\"Payment\";s:4:\"icon\";N;s:10:\"route_name\";s:0:\"\";s:10:\"route_path\";s:10:\"/acpayment\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2023-09-26T02:40:40.000000Z\";s:10:\"updated_at\";s:27:\"2023-09-26T02:40:40.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:3:{i:0;a:21:{s:8:\"my_route\";s:14:\"/acpayment/add\";s:6:\"status\";i:1;s:2:\"id\";i:150;s:9:\"parent_id\";i:149;s:4:\"name\";s:11:\"Add Payment\";s:4:\"icon\";N;s:10:\"route_name\";s:0:\"\";s:10:\"route_path\";s:14:\"/acpayment/add\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2023-09-26T05:57:16.000000Z\";s:10:\"updated_at\";s:27:\"2023-09-26T05:57:16.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:1;a:21:{s:8:\"my_route\";s:17:\"/acpayment/delete\";s:6:\"status\";i:1;s:2:\"id\";i:152;s:9:\"parent_id\";i:149;s:4:\"name\";s:14:\"Delete Payment\";s:4:\"icon\";N;s:10:\"route_name\";s:18:\"acpayments.destroy\";s:10:\"route_path\";s:17:\"/acpayment/delete\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-01-29T15:23:11.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-05T04:42:55.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:2;a:21:{s:8:\"my_route\";s:19:\"/acpayment/:id/edit\";s:6:\"status\";i:1;s:2:\"id\";i:151;s:9:\"parent_id\";i:149;s:4:\"name\";s:12:\"Edit Payment\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:13:\"editacpayment\";s:10:\"route_path\";s:19:\"/acpayment/:id/edit\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}}}i:3;a:21:{s:8:\"my_route\";s:17:\"/chart-of-account\";s:6:\"status\";i:1;s:2:\"id\";i:49;s:9:\"parent_id\";i:48;s:4:\"name\";s:16:\"Chart Of Account\";s:4:\"icon\";s:15:\"bx bx-buildings\";s:10:\"route_name\";s:14:\"chartofaccount\";s:10:\"route_path\";s:17:\"/chart-of-account\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-12T06:54:03.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:3:{i:0;a:21:{s:8:\"my_route\";s:24:\"/chart-of-account/delete\";s:6:\"status\";i:1;s:2:\"id\";i:52;s:9:\"parent_id\";i:49;s:4:\"name\";s:23:\"Delete Chart Of Account\";s:4:\"icon\";N;s:10:\"route_name\";s:23:\"chartofaccounts.destroy\";s:10:\"route_path\";s:24:\"/chart-of-account/delete\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-01-29T15:23:11.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-05T04:42:55.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:1;a:21:{s:8:\"my_route\";s:21:\"/chart-of-account/add\";s:6:\"status\";i:1;s:2:\"id\";i:50;s:9:\"parent_id\";i:49;s:4:\"name\";s:20:\"Add Chart Of Account\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:17:\"addchartofaccount\";s:10:\"route_path\";s:21:\"/chart-of-account/add\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-07T13:55:05.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:2;a:21:{s:8:\"my_route\";s:26:\"/chart-of-account/:id/edit\";s:6:\"status\";i:1;s:2:\"id\";i:51;s:9:\"parent_id\";i:49;s:4:\"name\";s:21:\"Edit Chart Of Account\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:18:\"editchartofaccount\";s:10:\"route_path\";s:26:\"/chart-of-account/:id/edit\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}}}}}i:3;a:21:{s:8:\"my_route\";N;s:6:\"status\";i:1;s:2:\"id\";i:53;s:9:\"parent_id\";s:0:\"\";s:4:\"name\";s:8:\"Contacts\";s:4:\"icon\";s:15:\"fal fa-id-badge\";s:10:\"route_name\";s:0:\"\";s:10:\"route_path\";N;s:10:\"menu_color\";N;s:10:\"sort_order\";i:5;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:2;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-02-12T05:58:52.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-12T05:58:52.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:3:{i:0;a:21:{s:8:\"my_route\";s:9:\"/supplier\";s:6:\"status\";i:1;s:2:\"id\";i:54;s:9:\"parent_id\";i:53;s:4:\"name\";s:8:\"Supplier\";s:4:\"icon\";s:15:\"bx bx-buildings\";s:10:\"route_name\";s:8:\"supplier\";s:10:\"route_path\";s:9:\"/supplier\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-12T06:54:03.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:3:{i:0;a:21:{s:8:\"my_route\";s:16:\"/supplier/delete\";s:6:\"status\";i:1;s:2:\"id\";i:57;s:9:\"parent_id\";i:54;s:4:\"name\";s:15:\"Delete Supplier\";s:4:\"icon\";N;s:10:\"route_name\";s:17:\"suppliers.destroy\";s:10:\"route_path\";s:16:\"/supplier/delete\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-01-29T15:23:11.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-05T04:42:55.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:1;a:21:{s:8:\"my_route\";s:13:\"/supplier/add\";s:6:\"status\";i:1;s:2:\"id\";i:55;s:9:\"parent_id\";i:54;s:4:\"name\";s:12:\"Add Supplier\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:11:\"addsupplier\";s:10:\"route_path\";s:13:\"/supplier/add\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-07T13:55:05.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:2;a:21:{s:8:\"my_route\";s:18:\"/supplier/:id/edit\";s:6:\"status\";i:1;s:2:\"id\";i:56;s:9:\"parent_id\";i:54;s:4:\"name\";s:13:\"Edit Supplier\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:12:\"editsupplier\";s:10:\"route_path\";s:18:\"/supplier/:id/edit\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}}}i:1;a:21:{s:8:\"my_route\";s:9:\"/customer\";s:6:\"status\";i:1;s:2:\"id\";i:58;s:9:\"parent_id\";i:53;s:4:\"name\";s:8:\"Customer\";s:4:\"icon\";s:15:\"bx bx-buildings\";s:10:\"route_name\";s:8:\"customer\";s:10:\"route_path\";s:9:\"/customer\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-12T06:54:03.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:5:{i:0;a:21:{s:8:\"my_route\";s:16:\"/customer/delete\";s:6:\"status\";i:1;s:2:\"id\";i:61;s:9:\"parent_id\";i:58;s:4:\"name\";s:15:\"Delete Customer\";s:4:\"icon\";N;s:10:\"route_name\";s:17:\"customers.destroy\";s:10:\"route_path\";s:16:\"/customer/delete\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-01-29T15:23:11.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-05T04:42:55.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:1;a:21:{s:8:\"my_route\";s:18:\"/supplier/:id/view\";s:6:\"status\";i:1;s:2:\"id\";i:137;s:9:\"parent_id\";i:58;s:4:\"name\";s:13:\"View Supplier\";s:4:\"icon\";N;s:10:\"route_name\";s:0:\"\";s:10:\"route_path\";s:18:\"/supplier/:id/view\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-11-26T06:35:01.000000Z\";s:10:\"updated_at\";s:27:\"2022-11-26T06:35:01.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:2;a:21:{s:8:\"my_route\";s:18:\"/customer/:id/view\";s:6:\"status\";i:1;s:2:\"id\";i:139;s:9:\"parent_id\";i:58;s:4:\"name\";s:13:\"View Customer\";s:4:\"icon\";N;s:10:\"route_name\";s:0:\"\";s:10:\"route_path\";s:18:\"/customer/:id/view\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-11-26T06:35:01.000000Z\";s:10:\"updated_at\";s:27:\"2022-11-26T06:35:01.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:3;a:21:{s:8:\"my_route\";s:13:\"/customer/add\";s:6:\"status\";i:1;s:2:\"id\";i:59;s:9:\"parent_id\";i:58;s:4:\"name\";s:12:\"Add Customer\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:11:\"addcustomer\";s:10:\"route_path\";s:13:\"/customer/add\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-07T13:55:05.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:4;a:21:{s:8:\"my_route\";s:18:\"/customer/:id/edit\";s:6:\"status\";i:1;s:2:\"id\";i:60;s:9:\"parent_id\";i:58;s:4:\"name\";s:13:\"Edit Customer\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:12:\"editcustomer\";s:10:\"route_path\";s:18:\"/customer/:id/edit\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}}}i:2;a:21:{s:8:\"my_route\";s:5:\"/bank\";s:6:\"status\";i:1;s:2:\"id\";i:62;s:9:\"parent_id\";i:53;s:4:\"name\";s:4:\"Bank\";s:4:\"icon\";s:15:\"bx bx-buildings\";s:10:\"route_name\";s:4:\"bank\";s:10:\"route_path\";s:5:\"/bank\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-12T06:54:03.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:3:{i:0;a:21:{s:8:\"my_route\";s:12:\"/bank/delete\";s:6:\"status\";i:1;s:2:\"id\";i:65;s:9:\"parent_id\";i:62;s:4:\"name\";s:11:\"Delete Bank\";s:4:\"icon\";N;s:10:\"route_name\";s:13:\"banks.destroy\";s:10:\"route_path\";s:12:\"/bank/delete\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-01-29T15:23:11.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-05T04:42:55.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:1;a:21:{s:8:\"my_route\";s:9:\"/bank/add\";s:6:\"status\";i:1;s:2:\"id\";i:63;s:9:\"parent_id\";i:62;s:4:\"name\";s:8:\"Add Bank\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:7:\"addbank\";s:10:\"route_path\";s:9:\"/bank/add\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-07T13:55:05.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:2;a:21:{s:8:\"my_route\";s:14:\"/bank/:id/edit\";s:6:\"status\";i:1;s:2:\"id\";i:64;s:9:\"parent_id\";i:62;s:4:\"name\";s:9:\"Edit Bank\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:8:\"editbank\";s:10:\"route_path\";s:14:\"/bank/:id/edit\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}}}}}i:4;a:21:{s:8:\"my_route\";N;s:6:\"status\";i:1;s:2:\"id\";i:66;s:9:\"parent_id\";s:0:\"\";s:4:\"name\";s:8:\"Products\";s:4:\"icon\";s:19:\"fab fa-product-hunt\";s:10:\"route_name\";s:0:\"\";s:10:\"route_path\";N;s:10:\"menu_color\";N;s:10:\"sort_order\";i:5;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:2;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-02-12T05:58:52.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-12T05:58:52.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:8:{i:0;a:21:{s:8:\"my_route\";s:5:\"/unit\";s:6:\"status\";i:1;s:2:\"id\";i:67;s:9:\"parent_id\";i:66;s:4:\"name\";s:4:\"Unit\";s:4:\"icon\";s:15:\"bx bx-buildings\";s:10:\"route_name\";s:4:\"unit\";s:10:\"route_path\";s:5:\"/unit\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-12T06:54:03.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:3:{i:0;a:21:{s:8:\"my_route\";s:12:\"/unit/delete\";s:6:\"status\";i:1;s:2:\"id\";i:70;s:9:\"parent_id\";i:67;s:4:\"name\";s:11:\"Delete Unit\";s:4:\"icon\";N;s:10:\"route_name\";s:13:\"units.destroy\";s:10:\"route_path\";s:12:\"/unit/delete\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-01-29T15:23:11.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-05T04:42:55.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:1;a:21:{s:8:\"my_route\";s:9:\"/unit/add\";s:6:\"status\";i:1;s:2:\"id\";i:68;s:9:\"parent_id\";i:67;s:4:\"name\";s:8:\"Add Unit\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:7:\"addunit\";s:10:\"route_path\";s:9:\"/unit/add\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-07T13:55:05.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:2;a:21:{s:8:\"my_route\";s:14:\"/unit/:id/edit\";s:6:\"status\";i:1;s:2:\"id\";i:69;s:9:\"parent_id\";i:67;s:4:\"name\";s:9:\"Edit Unit\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:8:\"editunit\";s:10:\"route_path\";s:14:\"/unit/:id/edit\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}}}i:1;a:21:{s:8:\"my_route\";s:9:\"/category\";s:6:\"status\";i:1;s:2:\"id\";i:71;s:9:\"parent_id\";i:66;s:4:\"name\";s:8:\"Category\";s:4:\"icon\";s:15:\"bx bx-buildings\";s:10:\"route_name\";s:8:\"category\";s:10:\"route_path\";s:9:\"/category\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-12T06:54:03.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:3:{i:0;a:21:{s:8:\"my_route\";s:16:\"/category/delete\";s:6:\"status\";i:1;s:2:\"id\";i:74;s:9:\"parent_id\";i:71;s:4:\"name\";s:15:\"Delete Category\";s:4:\"icon\";N;s:10:\"route_name\";s:18:\"categories.destroy\";s:10:\"route_path\";s:16:\"/category/delete\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-01-29T15:23:11.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-05T04:42:55.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:1;a:21:{s:8:\"my_route\";s:13:\"/category/add\";s:6:\"status\";i:1;s:2:\"id\";i:72;s:9:\"parent_id\";i:71;s:4:\"name\";s:12:\"Add Category\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:11:\"addcategory\";s:10:\"route_path\";s:13:\"/category/add\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-07T13:55:05.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:2;a:21:{s:8:\"my_route\";s:18:\"/category/:id/edit\";s:6:\"status\";i:1;s:2:\"id\";i:73;s:9:\"parent_id\";i:71;s:4:\"name\";s:13:\"Edit Category\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:12:\"editcategory\";s:10:\"route_path\";s:18:\"/category/:id/edit\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}}}i:2;a:21:{s:8:\"my_route\";s:6:\"/brand\";s:6:\"status\";i:1;s:2:\"id\";i:75;s:9:\"parent_id\";i:66;s:4:\"name\";s:5:\"Brand\";s:4:\"icon\";s:15:\"bx bx-buildings\";s:10:\"route_name\";s:5:\"brand\";s:10:\"route_path\";s:6:\"/brand\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-12T06:54:03.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:3:{i:0;a:21:{s:8:\"my_route\";s:13:\"/brand/delete\";s:6:\"status\";i:1;s:2:\"id\";i:78;s:9:\"parent_id\";i:75;s:4:\"name\";s:12:\"Delete Brand\";s:4:\"icon\";N;s:10:\"route_name\";s:14:\"brands.destroy\";s:10:\"route_path\";s:13:\"/brand/delete\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-01-29T15:23:11.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-05T04:42:55.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:1;a:21:{s:8:\"my_route\";s:10:\"/brand/add\";s:6:\"status\";i:1;s:2:\"id\";i:76;s:9:\"parent_id\";i:75;s:4:\"name\";s:9:\"Add Brand\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:8:\"addbrand\";s:10:\"route_path\";s:10:\"/brand/add\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-07T13:55:05.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:2;a:21:{s:8:\"my_route\";s:15:\"/brand/:id/edit\";s:6:\"status\";i:1;s:2:\"id\";i:77;s:9:\"parent_id\";i:75;s:4:\"name\";s:10:\"Edit Brand\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:9:\"editbrand\";s:10:\"route_path\";s:15:\"/brand/:id/edit\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}}}i:3;a:21:{s:8:\"my_route\";s:9:\"/warranty\";s:6:\"status\";i:1;s:2:\"id\";i:79;s:9:\"parent_id\";i:66;s:4:\"name\";s:8:\"Warranty\";s:4:\"icon\";s:15:\"bx bx-buildings\";s:10:\"route_name\";s:8:\"warranty\";s:10:\"route_path\";s:9:\"/warranty\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-12T06:54:03.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:3:{i:0;a:21:{s:8:\"my_route\";s:16:\"/warranty/delete\";s:6:\"status\";i:1;s:2:\"id\";i:82;s:9:\"parent_id\";i:79;s:4:\"name\";s:15:\"Delete Warranty\";s:4:\"icon\";N;s:10:\"route_name\";s:18:\"warranties.destroy\";s:10:\"route_path\";s:16:\"/warranty/delete\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-01-29T15:23:11.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-05T04:42:55.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:1;a:21:{s:8:\"my_route\";s:13:\"/warranty/add\";s:6:\"status\";i:1;s:2:\"id\";i:80;s:9:\"parent_id\";i:79;s:4:\"name\";s:12:\"Add Warranty\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:11:\"addwarranty\";s:10:\"route_path\";s:13:\"/warranty/add\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-07T13:55:05.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:2;a:21:{s:8:\"my_route\";s:18:\"/warranty/:id/edit\";s:6:\"status\";i:1;s:2:\"id\";i:81;s:9:\"parent_id\";i:79;s:4:\"name\";s:13:\"Edit Warranty\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:12:\"editwarranty\";s:10:\"route_path\";s:18:\"/warranty/:id/edit\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}}}i:4;a:21:{s:8:\"my_route\";s:9:\"/itemtype\";s:6:\"status\";i:1;s:2:\"id\";i:83;s:9:\"parent_id\";i:66;s:4:\"name\";s:9:\"Item Type\";s:4:\"icon\";s:15:\"bx bx-buildings\";s:10:\"route_name\";s:8:\"itemtype\";s:10:\"route_path\";s:9:\"/itemtype\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-12T06:54:03.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:3:{i:0;a:21:{s:8:\"my_route\";s:16:\"/itemtype/delete\";s:6:\"status\";i:1;s:2:\"id\";i:86;s:9:\"parent_id\";i:83;s:4:\"name\";s:16:\"Delete Item Type\";s:4:\"icon\";N;s:10:\"route_name\";s:17:\"itemtypes.destroy\";s:10:\"route_path\";s:16:\"/itemtype/delete\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-01-29T15:23:11.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-05T04:42:55.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:1;a:21:{s:8:\"my_route\";s:13:\"/itemtype/add\";s:6:\"status\";i:1;s:2:\"id\";i:84;s:9:\"parent_id\";i:83;s:4:\"name\";s:13:\"Add Item Type\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:11:\"additemtype\";s:10:\"route_path\";s:13:\"/itemtype/add\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-07T13:55:05.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:2;a:21:{s:8:\"my_route\";s:18:\"/itemtype/:id/edit\";s:6:\"status\";i:1;s:2:\"id\";i:85;s:9:\"parent_id\";i:83;s:4:\"name\";s:14:\"Edit Item Type\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:12:\"edititemtype\";s:10:\"route_path\";s:18:\"/itemtype/:id/edit\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}}}i:5;a:21:{s:8:\"my_route\";s:10:\"/variation\";s:6:\"status\";i:1;s:2:\"id\";i:87;s:9:\"parent_id\";i:66;s:4:\"name\";s:9:\"Variation\";s:4:\"icon\";s:15:\"bx bx-buildings\";s:10:\"route_name\";s:9:\"variation\";s:10:\"route_path\";s:10:\"/variation\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-12T06:54:03.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:3:{i:0;a:21:{s:8:\"my_route\";s:17:\"/variation/delete\";s:6:\"status\";i:1;s:2:\"id\";i:90;s:9:\"parent_id\";i:87;s:4:\"name\";s:16:\"Delete Variation\";s:4:\"icon\";N;s:10:\"route_name\";s:18:\"variations.destroy\";s:10:\"route_path\";s:17:\"/variation/delete\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-01-29T15:23:11.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-05T04:42:55.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:1;a:21:{s:8:\"my_route\";s:14:\"/variation/add\";s:6:\"status\";i:1;s:2:\"id\";i:88;s:9:\"parent_id\";i:87;s:4:\"name\";s:13:\"Add Variation\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:12:\"addvariation\";s:10:\"route_path\";s:14:\"/variation/add\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-07T13:55:05.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:2;a:21:{s:8:\"my_route\";s:19:\"/variation/:id/edit\";s:6:\"status\";i:1;s:2:\"id\";i:89;s:9:\"parent_id\";i:87;s:4:\"name\";s:14:\"Edit Variation\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:13:\"editvariation\";s:10:\"route_path\";s:19:\"/variation/:id/edit\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}}}i:6;a:21:{s:8:\"my_route\";s:8:\"/product\";s:6:\"status\";i:1;s:2:\"id\";i:91;s:9:\"parent_id\";i:66;s:4:\"name\";s:7:\"Product\";s:4:\"icon\";s:15:\"bx bx-buildings\";s:10:\"route_name\";s:7:\"product\";s:10:\"route_path\";s:8:\"/product\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-12T06:54:03.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:3:{i:0;a:21:{s:8:\"my_route\";s:15:\"/product/delete\";s:6:\"status\";i:1;s:2:\"id\";i:94;s:9:\"parent_id\";i:91;s:4:\"name\";s:14:\"Delete Product\";s:4:\"icon\";N;s:10:\"route_name\";s:16:\"products.destroy\";s:10:\"route_path\";s:15:\"/product/delete\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-01-29T15:23:11.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-05T04:42:55.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:1;a:21:{s:8:\"my_route\";s:12:\"/product/add\";s:6:\"status\";i:1;s:2:\"id\";i:92;s:9:\"parent_id\";i:91;s:4:\"name\";s:11:\"Add Product\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:10:\"addproduct\";s:10:\"route_path\";s:12:\"/product/add\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-07T13:55:05.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:2;a:21:{s:8:\"my_route\";s:17:\"/product/:id/edit\";s:6:\"status\";i:1;s:2:\"id\";i:93;s:9:\"parent_id\";i:91;s:4:\"name\";s:12:\"Edit Product\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:11:\"editproduct\";s:10:\"route_path\";s:17:\"/product/:id/edit\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}}}i:7;a:21:{s:8:\"my_route\";s:13:\"/openingstock\";s:6:\"status\";i:1;s:2:\"id\";i:95;s:9:\"parent_id\";i:66;s:4:\"name\";s:13:\"Opening Stock\";s:4:\"icon\";s:10:\"bx bx-data\";s:10:\"route_name\";s:12:\"openingstock\";s:10:\"route_path\";s:13:\"/openingstock\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-12T06:54:03.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:1:{i:0;a:21:{s:8:\"my_route\";s:17:\"/openingstock/add\";s:6:\"status\";i:1;s:2:\"id\";i:96;s:9:\"parent_id\";i:95;s:4:\"name\";s:24:\"Add / Edit Opening Stock\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:15:\"addopeningstock\";s:10:\"route_path\";s:17:\"/openingstock/add\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-07T13:55:05.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}}}}}i:5;a:21:{s:8:\"my_route\";N;s:6:\"status\";i:1;s:2:\"id\";i:99;s:9:\"parent_id\";s:0:\"\";s:4:\"name\";s:8:\"Purchase\";s:4:\"icon\";s:11:\"fal fa-tags\";s:10:\"route_name\";s:0:\"\";s:10:\"route_path\";N;s:10:\"menu_color\";N;s:10:\"sort_order\";i:5;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:2;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-02-12T05:58:52.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-12T05:58:52.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:7:{i:0;a:21:{s:8:\"my_route\";s:15:\"/payment/delete\";s:6:\"status\";i:1;s:2:\"id\";i:115;s:9:\"parent_id\";i:99;s:4:\"name\";s:14:\"Delete Payment\";s:4:\"icon\";N;s:10:\"route_name\";s:16:\"payments.destroy\";s:10:\"route_path\";s:15:\"/payment/delete\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-01-29T15:23:11.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-05T04:42:55.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:1;a:21:{s:8:\"my_route\";s:9:\"/purchase\";s:6:\"status\";i:1;s:2:\"id\";i:100;s:9:\"parent_id\";i:99;s:4:\"name\";s:8:\"Purchase\";s:4:\"icon\";s:15:\"bx bx-buildings\";s:10:\"route_name\";s:8:\"purchase\";s:10:\"route_path\";s:9:\"/purchase\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-12T06:54:03.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:5:{i:0;a:21:{s:8:\"my_route\";s:16:\"/purchase/delete\";s:6:\"status\";i:1;s:2:\"id\";i:103;s:9:\"parent_id\";i:100;s:4:\"name\";s:15:\"Delete Purchase\";s:4:\"icon\";N;s:10:\"route_name\";s:17:\"purchases.destroy\";s:10:\"route_path\";s:16:\"/purchase/delete\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-01-29T15:23:11.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-05T04:42:55.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:1;a:21:{s:8:\"my_route\";s:13:\"/purchase/add\";s:6:\"status\";i:1;s:2:\"id\";i:101;s:9:\"parent_id\";i:100;s:4:\"name\";s:12:\"Add Purchase\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:11:\"addpurchase\";s:10:\"route_path\";s:13:\"/purchase/add\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-07T13:55:05.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:2;a:21:{s:8:\"my_route\";s:18:\"/purchase/:id/edit\";s:6:\"status\";i:1;s:2:\"id\";i:102;s:9:\"parent_id\";i:100;s:4:\"name\";s:13:\"Edit Purchase\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:12:\"editpurchase\";s:10:\"route_path\";s:18:\"/purchase/:id/edit\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:3;a:21:{s:8:\"my_route\";s:18:\"/purchase/:id/view\";s:6:\"status\";i:1;s:2:\"id\";i:104;s:9:\"parent_id\";i:100;s:4:\"name\";s:13:\"View Purchase\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:12:\"viewpurchase\";s:10:\"route_path\";s:18:\"/purchase/:id/view\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:4;a:21:{s:8:\"my_route\";s:18:\"/purchase/approval\";s:6:\"status\";i:1;s:2:\"id\";i:106;s:9:\"parent_id\";i:100;s:4:\"name\";s:8:\"Purchase\";s:4:\"icon\";s:15:\"bx bx-buildings\";s:10:\"route_name\";s:16:\"purchaseapproval\";s:10:\"route_path\";s:18:\"/purchase/approval\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-12T06:54:03.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}}}i:2;a:21:{s:8:\"my_route\";s:14:\"/receivingnote\";s:6:\"status\";i:1;s:2:\"id\";i:107;s:9:\"parent_id\";i:99;s:4:\"name\";s:14:\"Receiving Note\";s:4:\"icon\";s:15:\"bx bx-buildings\";s:10:\"route_name\";s:13:\"receivingnote\";s:10:\"route_path\";s:14:\"/receivingnote\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-12T06:54:03.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:4:{i:0;a:21:{s:8:\"my_route\";s:21:\"/receivingnote/delete\";s:6:\"status\";i:1;s:2:\"id\";i:110;s:9:\"parent_id\";i:107;s:4:\"name\";s:21:\"Delete Receiving Note\";s:4:\"icon\";N;s:10:\"route_name\";s:22:\"receivingnotes.destroy\";s:10:\"route_path\";s:21:\"/receivingnote/delete\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-01-29T15:23:11.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-05T04:42:55.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:1;a:21:{s:8:\"my_route\";s:18:\"/receivingnote/add\";s:6:\"status\";i:1;s:2:\"id\";i:108;s:9:\"parent_id\";i:107;s:4:\"name\";s:18:\"Add Receiving Note\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:16:\"addreceivingnote\";s:10:\"route_path\";s:18:\"/receivingnote/add\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-07T13:55:05.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:2;a:21:{s:8:\"my_route\";s:23:\"/receivingnote/:id/edit\";s:6:\"status\";i:1;s:2:\"id\";i:109;s:9:\"parent_id\";i:107;s:4:\"name\";s:19:\"Edit Receiving Note\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:17:\"editreceivingnote\";s:10:\"route_path\";s:23:\"/receivingnote/:id/edit\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:3;a:21:{s:8:\"my_route\";s:23:\"/receivingnote/:id/view\";s:6:\"status\";i:1;s:2:\"id\";i:111;s:9:\"parent_id\";i:107;s:4:\"name\";s:19:\"View Receiving Note\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:17:\"viewreceivingnote\";s:10:\"route_path\";s:23:\"/receivingnote/:id/view\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}}}i:3;a:21:{s:8:\"my_route\";s:12:\"/payment/add\";s:6:\"status\";i:1;s:2:\"id\";i:112;s:9:\"parent_id\";i:99;s:4:\"name\";s:19:\"Add Payment Receive\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:10:\"addpayment\";s:10:\"route_path\";s:12:\"/payment/add\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-07T13:55:05.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:4;a:21:{s:8:\"my_route\";s:8:\"/payment\";s:6:\"status\";i:1;s:2:\"id\";i:113;s:9:\"parent_id\";i:99;s:4:\"name\";s:15:\"Payment Receive\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:7:\"payment\";s:10:\"route_path\";s:8:\"/payment\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-07T13:55:05.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:5;a:21:{s:8:\"my_route\";s:17:\"/payment/:id/edit\";s:6:\"status\";i:1;s:2:\"id\";i:114;s:9:\"parent_id\";i:99;s:4:\"name\";s:12:\"Edit Payment\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:11:\"editpayment\";s:10:\"route_path\";s:17:\"/payment/:id/edit\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:6;a:21:{s:8:\"my_route\";s:16:\"/purchase/return\";s:6:\"status\";i:1;s:2:\"id\";i:128;s:9:\"parent_id\";i:99;s:4:\"name\";s:15:\"Purchase Return\";s:4:\"icon\";s:15:\"bx bx-buildings\";s:10:\"route_name\";s:14:\"purchasereturn\";s:10:\"route_path\";s:16:\"/purchase/return\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-12T06:54:03.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:4:{i:0;a:21:{s:8:\"my_route\";s:23:\"/purchase/return/delete\";s:6:\"status\";i:1;s:2:\"id\";i:131;s:9:\"parent_id\";i:128;s:4:\"name\";s:22:\"Delete Purchase Return\";s:4:\"icon\";N;s:10:\"route_name\";s:23:\"purchasereturns.destroy\";s:10:\"route_path\";s:23:\"/purchase/return/delete\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-01-29T15:23:11.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-05T04:42:55.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:1;a:21:{s:8:\"my_route\";s:20:\"/purchase/return/add\";s:6:\"status\";i:1;s:2:\"id\";i:129;s:9:\"parent_id\";i:128;s:4:\"name\";s:19:\"Add Purchase Return\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:17:\"addpurchasereturn\";s:10:\"route_path\";s:20:\"/purchase/return/add\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-07T13:55:05.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:2;a:21:{s:8:\"my_route\";s:25:\"/purchase/return/:id/edit\";s:6:\"status\";i:1;s:2:\"id\";i:130;s:9:\"parent_id\";i:128;s:4:\"name\";s:20:\"Edit Purchase Return\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:18:\"editpurchasereturn\";s:10:\"route_path\";s:25:\"/purchase/return/:id/edit\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:3;a:21:{s:8:\"my_route\";s:25:\"/purchase/return/:id/view\";s:6:\"status\";i:1;s:2:\"id\";i:132;s:9:\"parent_id\";i:128;s:4:\"name\";s:20:\"View Purchase Return\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:18:\"viewpurchasereturn\";s:10:\"route_path\";s:25:\"/purchase/return/:id/view\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}}}}}i:6;a:21:{s:8:\"my_route\";N;s:6:\"status\";i:1;s:2:\"id\";i:116;s:9:\"parent_id\";s:0:\"\";s:4:\"name\";s:4:\"Sell\";s:4:\"icon\";s:19:\"far fa-badge-dollar\";s:10:\"route_name\";s:0:\"\";s:10:\"route_path\";N;s:10:\"menu_color\";N;s:10:\"sort_order\";i:5;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:2;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-02-12T05:58:52.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-12T05:58:52.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:6:{i:0;a:21:{s:8:\"my_route\";s:10:\"/issuenote\";s:6:\"status\";i:1;s:2:\"id\";i:123;s:9:\"parent_id\";i:116;s:4:\"name\";s:10:\"Issue Note\";s:4:\"icon\";s:15:\"bx bx-buildings\";s:10:\"route_name\";s:9:\"issuenote\";s:10:\"route_path\";s:10:\"/issuenote\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-12T06:54:03.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:4:{i:0;a:21:{s:8:\"my_route\";s:17:\"/issuenote/delete\";s:6:\"status\";i:1;s:2:\"id\";i:126;s:9:\"parent_id\";i:123;s:4:\"name\";s:17:\"Delete Issue Note\";s:4:\"icon\";N;s:10:\"route_name\";s:18:\"issuenotes.destroy\";s:10:\"route_path\";s:17:\"/issuenote/delete\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-01-29T15:23:11.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-05T04:42:55.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:1;a:21:{s:8:\"my_route\";s:14:\"/issuenote/add\";s:6:\"status\";i:1;s:2:\"id\";i:124;s:9:\"parent_id\";i:123;s:4:\"name\";s:14:\"Add Issue Note\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:12:\"addissuenote\";s:10:\"route_path\";s:14:\"/issuenote/add\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-07T13:55:05.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:2;a:21:{s:8:\"my_route\";s:19:\"/issuenote/:id/view\";s:6:\"status\";i:1;s:2:\"id\";i:127;s:9:\"parent_id\";i:123;s:4:\"name\";s:15:\"View Issue Note\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:13:\"viewissuenote\";s:10:\"route_path\";s:19:\"/issuenote/:id/view\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:3;a:21:{s:8:\"my_route\";s:19:\"/issuenote/:id/edit\";s:6:\"status\";i:1;s:2:\"id\";i:125;s:9:\"parent_id\";i:123;s:4:\"name\";s:15:\"Edit Issue Note\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:13:\"editissuenote\";s:10:\"route_path\";s:19:\"/issuenote/:id/edit\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}}}i:1;a:21:{s:8:\"my_route\";s:11:\"/sell/draft\";s:6:\"status\";i:1;s:2:\"id\";i:133;s:9:\"parent_id\";i:116;s:4:\"name\";s:10:\"List Draft\";s:4:\"icon\";s:15:\"bx bx-buildings\";s:10:\"route_name\";s:5:\"draft\";s:10:\"route_path\";s:11:\"/sell/draft\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-12T06:54:03.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:2;a:21:{s:8:\"my_route\";s:15:\"/sell/quotation\";s:6:\"status\";i:1;s:2:\"id\";i:134;s:9:\"parent_id\";i:116;s:4:\"name\";s:14:\"List Quotation\";s:4:\"icon\";s:15:\"bx bx-buildings\";s:10:\"route_name\";s:9:\"quotation\";s:10:\"route_path\";s:15:\"/sell/quotation\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-12T06:54:03.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:3;a:21:{s:8:\"my_route\";s:14:\"/sell/shipment\";s:6:\"status\";i:1;s:2:\"id\";i:135;s:9:\"parent_id\";i:116;s:4:\"name\";s:9:\"Shipments\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:8:\"shipment\";s:10:\"route_path\";s:14:\"/sell/shipment\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-07T13:55:05.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:4;a:21:{s:8:\"my_route\";s:9:\"/sell/pos\";s:6:\"status\";i:1;s:2:\"id\";i:136;s:9:\"parent_id\";i:116;s:4:\"name\";s:3:\"POS\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:3:\"pos\";s:10:\"route_path\";s:9:\"/sell/pos\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-07T13:55:05.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:5;a:21:{s:8:\"my_route\";s:5:\"/sell\";s:6:\"status\";i:1;s:2:\"id\";i:117;s:9:\"parent_id\";i:116;s:4:\"name\";s:4:\"Sell\";s:4:\"icon\";s:15:\"bx bx-buildings\";s:10:\"route_name\";s:4:\"sell\";s:10:\"route_path\";s:5:\"/sell\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-12T06:54:03.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:5:{i:0;a:21:{s:8:\"my_route\";s:12:\"/sell/delete\";s:6:\"status\";i:1;s:2:\"id\";i:120;s:9:\"parent_id\";i:117;s:4:\"name\";s:11:\"Delete Sell\";s:4:\"icon\";N;s:10:\"route_name\";s:13:\"sells.destroy\";s:10:\"route_path\";s:12:\"/sell/delete\";s:10:\"menu_color\";N;s:10:\"sort_order\";i:0;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";s:27:\"2022-01-29T15:23:11.000000Z\";s:10:\"updated_at\";s:27:\"2022-02-05T04:42:55.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:1;a:21:{s:8:\"my_route\";s:9:\"/sell/add\";s:6:\"status\";i:1;s:2:\"id\";i:118;s:9:\"parent_id\";i:117;s:4:\"name\";s:8:\"Add Sell\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:7:\"addsell\";s:10:\"route_path\";s:9:\"/sell/add\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-07T13:55:05.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:2;a:21:{s:8:\"my_route\";s:14:\"/sell/:id/edit\";s:6:\"status\";i:1;s:2:\"id\";i:119;s:9:\"parent_id\";i:117;s:4:\"name\";s:9:\"Edit Sell\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:8:\"editsell\";s:10:\"route_path\";s:14:\"/sell/:id/edit\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:3;a:21:{s:8:\"my_route\";s:14:\"/sell/:id/view\";s:6:\"status\";i:1;s:2:\"id\";i:121;s:9:\"parent_id\";i:117;s:4:\"name\";s:9:\"View Sell\";s:4:\"icon\";s:0:\"\";s:10:\"route_name\";s:8:\"viewsell\";s:10:\"route_path\";s:14:\"/sell/:id/view\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:1;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";N;s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}i:4;a:21:{s:8:\"my_route\";s:14:\"/sell/approval\";s:6:\"status\";i:1;s:2:\"id\";i:122;s:9:\"parent_id\";i:117;s:4:\"name\";s:4:\"Sell\";s:4:\"icon\";s:15:\"bx bx-buildings\";s:10:\"route_name\";s:12:\"sellapproval\";s:10:\"route_path\";s:14:\"/sell/approval\";s:10:\"menu_color\";s:7:\"#6a0dad\";s:10:\"sort_order\";i:1;s:9:\"is_hidden\";b:0;s:9:\"is_active\";b:1;s:8:\"is_admin\";b:0;s:13:\"is_permission\";b:0;s:4:\"type\";i:1;s:10:\"created_by\";N;s:10:\"updated_by\";N;s:10:\"created_at\";N;s:10:\"updated_at\";s:27:\"2022-02-12T06:54:03.000000Z\";s:10:\"deleted_at\";N;s:8:\"children\";a:0:{}}}}}}}', 1786919589);
INSERT INTO `cache` (`key`, `value`, `expiration`) VALUES
('laravel-cache-user_permission_paths:1', 'a:156:{i:0;s:5:\"/menu\";i:1;s:9:\"/menu/add\";i:2;s:14:\"/menu/:id/edit\";i:3;s:12:\"/menu/delete\";i:4;s:8:\"/company\";i:5;s:12:\"/company/add\";i:6;s:17:\"/company/:id/edit\";i:7;s:15:\"/company/delete\";i:8;s:7:\"/branch\";i:9;s:11:\"/branch/add\";i:10;s:16:\"/branch/:id/edit\";i:11;s:14:\"/branch/delete\";i:12;s:11:\"/department\";i:13;s:15:\"/department/add\";i:14;s:20:\"/department/:id/edit\";i:15;s:18:\"/department/delete\";i:16;s:5:\"/role\";i:17;s:9:\"/role/add\";i:18;s:14:\"/role/:id/edit\";i:19;s:12:\"/role/delete\";i:20;s:20:\"/role/:id/permission\";i:21;s:5:\"/user\";i:22;s:9:\"/user/add\";i:23;s:14:\"/user/:id/edit\";i:24;s:12:\"/user/delete\";i:25;s:16:\"/company/setting\";i:26;s:18:\"/business/settings\";i:27;s:4:\"/tax\";i:28;s:8:\"/tax/add\";i:29;s:13:\"/tax/:id/edit\";i:30;s:11:\"/tax/delete\";i:31;s:14:\"/financialyear\";i:32;s:18:\"/financialyear/add\";i:33;s:17:\"/chart-of-account\";i:34;s:21:\"/chart-of-account/add\";i:35;s:26:\"/chart-of-account/:id/edit\";i:36;s:24:\"/chart-of-account/delete\";i:37;s:9:\"/supplier\";i:38;s:13:\"/supplier/add\";i:39;s:18:\"/supplier/:id/edit\";i:40;s:16:\"/supplier/delete\";i:41;s:9:\"/customer\";i:42;s:13:\"/customer/add\";i:43;s:18:\"/customer/:id/edit\";i:44;s:16:\"/customer/delete\";i:45;s:5:\"/bank\";i:46;s:9:\"/bank/add\";i:47;s:14:\"/bank/:id/edit\";i:48;s:12:\"/bank/delete\";i:49;s:5:\"/unit\";i:50;s:9:\"/unit/add\";i:51;s:14:\"/unit/:id/edit\";i:52;s:12:\"/unit/delete\";i:53;s:9:\"/category\";i:54;s:13:\"/category/add\";i:55;s:18:\"/category/:id/edit\";i:56;s:16:\"/category/delete\";i:57;s:6:\"/brand\";i:58;s:10:\"/brand/add\";i:59;s:15:\"/brand/:id/edit\";i:60;s:13:\"/brand/delete\";i:61;s:9:\"/warranty\";i:62;s:13:\"/warranty/add\";i:63;s:18:\"/warranty/:id/edit\";i:64;s:16:\"/warranty/delete\";i:65;s:9:\"/itemtype\";i:66;s:13:\"/itemtype/add\";i:67;s:18:\"/itemtype/:id/edit\";i:68;s:16:\"/itemtype/delete\";i:69;s:10:\"/variation\";i:70;s:14:\"/variation/add\";i:71;s:19:\"/variation/:id/edit\";i:72;s:17:\"/variation/delete\";i:73;s:8:\"/product\";i:74;s:12:\"/product/add\";i:75;s:17:\"/product/:id/edit\";i:76;s:15:\"/product/delete\";i:77;s:13:\"/openingstock\";i:78;s:17:\"/openingstock/add\";i:79;s:9:\"/purchase\";i:80;s:13:\"/purchase/add\";i:81;s:18:\"/purchase/:id/edit\";i:82;s:16:\"/purchase/delete\";i:83;s:18:\"/purchase/:id/view\";i:84;s:18:\"/purchase/approval\";i:85;s:14:\"/receivingnote\";i:86;s:18:\"/receivingnote/add\";i:87;s:23:\"/receivingnote/:id/edit\";i:88;s:21:\"/receivingnote/delete\";i:89;s:23:\"/receivingnote/:id/view\";i:90;s:12:\"/payment/add\";i:91;s:8:\"/payment\";i:92;s:17:\"/payment/:id/edit\";i:93;s:15:\"/payment/delete\";i:94;s:5:\"/sell\";i:95;s:9:\"/sell/add\";i:96;s:14:\"/sell/:id/edit\";i:97;s:12:\"/sell/delete\";i:98;s:14:\"/sell/:id/view\";i:99;s:14:\"/sell/approval\";i:100;s:10:\"/issuenote\";i:101;s:14:\"/issuenote/add\";i:102;s:19:\"/issuenote/:id/edit\";i:103;s:17:\"/issuenote/delete\";i:104;s:19:\"/issuenote/:id/view\";i:105;s:16:\"/purchase/return\";i:106;s:20:\"/purchase/return/add\";i:107;s:25:\"/purchase/return/:id/edit\";i:108;s:23:\"/purchase/return/delete\";i:109;s:25:\"/purchase/return/:id/view\";i:110;s:11:\"/sell/draft\";i:111;s:15:\"/sell/quotation\";i:112;s:14:\"/sell/shipment\";i:113;s:9:\"/sell/pos\";i:114;s:18:\"/supplier/:id/view\";i:115;s:15:\"/openingbalance\";i:116;s:18:\"/customer/:id/view\";i:117;s:13:\"/journalentry\";i:118;s:17:\"/journalentry/add\";i:119;s:22:\"/journalentry/:id/edit\";i:120;s:20:\"/journalentry/delete\";i:121;s:22:\"/journalentry/:id/view\";i:122;s:25:\"/journalentry/:id/approve\";i:123;s:24:\"/journalentry/:id/reject\";i:124;s:23:\"/journalentry/:id/print\";i:125;s:22:\"/journalentry/approval\";i:126;s:10:\"/acpayment\";i:127;s:14:\"/acpayment/add\";i:128;s:19:\"/acpayment/:id/edit\";i:129;s:17:\"/acpayment/delete\";i:130;s:12:\"/menu/export\";i:131;s:12:\"/menu/import\";i:132;s:11:\"/menu/trash\";i:133;s:12:\"/role/export\";i:134;s:12:\"/role/import\";i:135;s:11:\"/role/trash\";i:136;s:18:\"/department/export\";i:137;s:18:\"/department/import\";i:138;s:17:\"/department/trash\";i:139;s:15:\"/company/export\";i:140;s:15:\"/company/import\";i:141;s:14:\"/company/trash\";i:142;s:9:\"/currency\";i:143;s:13:\"/currency/add\";i:144;s:18:\"/currency/:id/edit\";i:145;s:16:\"/currency/delete\";i:146;s:16:\"/currency/export\";i:147;s:16:\"/currency/import\";i:148;s:15:\"/currency/trash\";i:149;s:9:\"/timezone\";i:150;s:13:\"/timezone/add\";i:151;s:18:\"/timezone/:id/edit\";i:152;s:16:\"/timezone/delete\";i:153;s:16:\"/timezone/export\";i:154;s:16:\"/timezone/import\";i:155;s:15:\"/timezone/trash\";}', 1786919587),
('laravel-cache-user_permission_paths:2', 'a:121:{i:0;s:7:\"/branch\";i:1;s:11:\"/branch/add\";i:2;s:16:\"/branch/:id/edit\";i:3;s:14:\"/branch/delete\";i:4;s:11:\"/department\";i:5;s:15:\"/department/add\";i:6;s:20:\"/department/:id/edit\";i:7;s:18:\"/department/delete\";i:8;s:5:\"/role\";i:9;s:9:\"/role/add\";i:10;s:14:\"/role/:id/edit\";i:11;s:12:\"/role/delete\";i:12;s:20:\"/role/:id/permission\";i:13;s:5:\"/user\";i:14;s:9:\"/user/add\";i:15;s:14:\"/user/:id/edit\";i:16;s:12:\"/user/delete\";i:17;s:18:\"/business/settings\";i:18;s:4:\"/tax\";i:19;s:8:\"/tax/add\";i:20;s:13:\"/tax/:id/edit\";i:21;s:11:\"/tax/delete\";i:22;s:14:\"/financialyear\";i:23;s:18:\"/financialyear/add\";i:24;s:17:\"/chart-of-account\";i:25;s:21:\"/chart-of-account/add\";i:26;s:26:\"/chart-of-account/:id/edit\";i:27;s:24:\"/chart-of-account/delete\";i:28;s:9:\"/supplier\";i:29;s:13:\"/supplier/add\";i:30;s:18:\"/supplier/:id/edit\";i:31;s:16:\"/supplier/delete\";i:32;s:9:\"/customer\";i:33;s:13:\"/customer/add\";i:34;s:18:\"/customer/:id/edit\";i:35;s:16:\"/customer/delete\";i:36;s:5:\"/bank\";i:37;s:9:\"/bank/add\";i:38;s:14:\"/bank/:id/edit\";i:39;s:12:\"/bank/delete\";i:40;s:5:\"/unit\";i:41;s:9:\"/unit/add\";i:42;s:14:\"/unit/:id/edit\";i:43;s:12:\"/unit/delete\";i:44;s:9:\"/category\";i:45;s:13:\"/category/add\";i:46;s:18:\"/category/:id/edit\";i:47;s:16:\"/category/delete\";i:48;s:6:\"/brand\";i:49;s:10:\"/brand/add\";i:50;s:15:\"/brand/:id/edit\";i:51;s:13:\"/brand/delete\";i:52;s:9:\"/warranty\";i:53;s:13:\"/warranty/add\";i:54;s:18:\"/warranty/:id/edit\";i:55;s:16:\"/warranty/delete\";i:56;s:9:\"/itemtype\";i:57;s:13:\"/itemtype/add\";i:58;s:18:\"/itemtype/:id/edit\";i:59;s:16:\"/itemtype/delete\";i:60;s:10:\"/variation\";i:61;s:14:\"/variation/add\";i:62;s:19:\"/variation/:id/edit\";i:63;s:17:\"/variation/delete\";i:64;s:8:\"/product\";i:65;s:12:\"/product/add\";i:66;s:17:\"/product/:id/edit\";i:67;s:15:\"/product/delete\";i:68;s:13:\"/openingstock\";i:69;s:17:\"/openingstock/add\";i:70;s:9:\"/purchase\";i:71;s:13:\"/purchase/add\";i:72;s:18:\"/purchase/:id/edit\";i:73;s:16:\"/purchase/delete\";i:74;s:18:\"/purchase/:id/view\";i:75;s:18:\"/purchase/approval\";i:76;s:14:\"/receivingnote\";i:77;s:18:\"/receivingnote/add\";i:78;s:23:\"/receivingnote/:id/edit\";i:79;s:21:\"/receivingnote/delete\";i:80;s:23:\"/receivingnote/:id/view\";i:81;s:12:\"/payment/add\";i:82;s:8:\"/payment\";i:83;s:17:\"/payment/:id/edit\";i:84;s:15:\"/payment/delete\";i:85;s:5:\"/sell\";i:86;s:9:\"/sell/add\";i:87;s:14:\"/sell/:id/edit\";i:88;s:12:\"/sell/delete\";i:89;s:14:\"/sell/:id/view\";i:90;s:14:\"/sell/approval\";i:91;s:10:\"/issuenote\";i:92;s:14:\"/issuenote/add\";i:93;s:19:\"/issuenote/:id/edit\";i:94;s:17:\"/issuenote/delete\";i:95;s:19:\"/issuenote/:id/view\";i:96;s:16:\"/purchase/return\";i:97;s:20:\"/purchase/return/add\";i:98;s:25:\"/purchase/return/:id/edit\";i:99;s:23:\"/purchase/return/delete\";i:100;s:25:\"/purchase/return/:id/view\";i:101;s:11:\"/sell/draft\";i:102;s:15:\"/sell/quotation\";i:103;s:14:\"/sell/shipment\";i:104;s:9:\"/sell/pos\";i:105;s:18:\"/supplier/:id/view\";i:106;s:15:\"/openingbalance\";i:107;s:18:\"/customer/:id/view\";i:108;s:13:\"/journalentry\";i:109;s:17:\"/journalentry/add\";i:110;s:22:\"/journalentry/:id/edit\";i:111;s:20:\"/journalentry/delete\";i:112;s:22:\"/journalentry/:id/view\";i:113;s:25:\"/journalentry/:id/approve\";i:114;s:24:\"/journalentry/:id/reject\";i:115;s:23:\"/journalentry/:id/print\";i:116;s:22:\"/journalentry/approval\";i:117;s:10:\"/acpayment\";i:118;s:14:\"/acpayment/add\";i:119;s:19:\"/acpayment/:id/edit\";i:120;s:17:\"/acpayment/delete\";}', 1786919589);

-- --------------------------------------------------------

--
-- Table structure for table `cache_locks`
--

CREATE TABLE `cache_locks` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `owner` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` bigint NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `categories`
--

CREATE TABLE `categories` (
  `id` bigint UNSIGNED NOT NULL,
  `company_id` bigint UNSIGNED DEFAULT NULL,
  `parent_id` bigint UNSIGNED DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chart_of_accounts`
--

CREATE TABLE `chart_of_accounts` (
  `id` bigint UNSIGNED NOT NULL,
  `company_id` bigint UNSIGNED DEFAULT NULL,
  `branch_id` bigint UNSIGNED DEFAULT NULL,
  `parent_id` bigint UNSIGNED DEFAULT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `acc_type` enum('t','c') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 't',
  `acc_nature` enum('cr','dr') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cr',
  `pl` tinyint(1) NOT NULL DEFAULT '0',
  `bs` tinyint(1) NOT NULL DEFAULT '0',
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `branches` text COLLATE utf8mb4_unicode_ci,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `chart_of_accounts`
--

INSERT INTO `chart_of_accounts` (`id`, `company_id`, `branch_id`, `parent_id`, `code`, `name`, `acc_type`, `acc_nature`, `pl`, `bs`, `active`, `branches`, `deleted_at`, `created_at`, `updated_at`) VALUES
(1, 1, 1, NULL, '100-00000', 'Equity', 'c', 'cr', 0, 1, 1, NULL, NULL, '2026-08-15 15:34:38', '2026-08-15 15:34:38'),
(2, 1, 1, NULL, '200-00000', 'Assets', 'c', 'dr', 0, 1, 1, NULL, NULL, '2026-08-15 15:34:38', '2026-08-15 15:34:38'),
(3, 1, 1, NULL, '300-00000', 'Liabilities', 'c', 'cr', 0, 1, 1, NULL, NULL, '2026-08-15 15:34:38', '2026-08-15 15:34:38'),
(4, 1, 1, NULL, '400-00000', 'Expenses', 'c', 'dr', 0, 0, 1, NULL, NULL, '2026-08-15 15:34:38', '2026-08-15 15:34:38'),
(5, 1, 1, NULL, '500-00000', 'Revenue', 'c', 'cr', 0, 0, 1, NULL, NULL, '2026-08-15 15:34:38', '2026-08-15 15:34:38'),
(6, 1, 1, NULL, '600-00000', 'Cost of Goods Sold', 'c', 'dr', 0, 0, 1, NULL, NULL, '2026-08-15 15:34:38', '2026-08-15 15:34:38');

-- --------------------------------------------------------

--
-- Table structure for table `chart_of_account_mappings`
--

CREATE TABLE `chart_of_account_mappings` (
  `id` bigint UNSIGNED NOT NULL,
  `company_id` bigint UNSIGNED DEFAULT NULL,
  `branch_id` bigint UNSIGNED DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `chart_of_account_mappings`
--

INSERT INTO `chart_of_account_mappings` (`id`, `company_id`, `branch_id`, `name`, `key`, `value`, `deleted_at`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 'Equity', 'equity', NULL, NULL, '2026-08-15 15:34:38', '2026-08-15 15:34:38'),
(2, 1, 1, 'Assets', 'assets', NULL, NULL, '2026-08-15 15:34:38', '2026-08-15 15:34:38'),
(3, 1, 1, 'Liabilities', 'liability', NULL, NULL, '2026-08-15 15:34:38', '2026-08-15 15:34:38'),
(4, 1, 1, 'Expenses', 'expenses', NULL, NULL, '2026-08-15 15:34:38', '2026-08-15 15:34:38'),
(5, 1, 1, 'Revenue', 'revenue', NULL, NULL, '2026-08-15 15:34:38', '2026-08-15 15:34:38'),
(6, 1, 1, 'Customer', 'customer', NULL, NULL, '2026-08-15 15:34:38', '2026-08-15 15:34:38'),
(7, 1, 1, 'Supplier', 'supplier', NULL, NULL, '2026-08-15 15:34:38', '2026-08-15 15:34:38'),
(8, 1, 1, 'Customer Advance', 'customeradvance', NULL, NULL, '2026-08-15 15:34:38', '2026-08-15 15:34:38'),
(9, 1, 1, 'Bank', 'bank', NULL, NULL, '2026-08-15 15:34:38', '2026-08-15 15:34:38'),
(10, 1, 1, 'Cash', 'cash', NULL, NULL, '2026-08-15 15:34:38', '2026-08-15 15:34:38'),
(11, 1, 1, 'Purchase', 'purchase', NULL, NULL, '2026-08-15 15:34:38', '2026-08-15 15:34:38'),
(12, 1, 1, 'Import Purchase', 'importpurchase', NULL, NULL, '2026-08-15 15:34:38', '2026-08-15 15:34:38'),
(13, 1, 1, 'Local Purchase', 'localpurchase', NULL, NULL, '2026-08-15 15:34:38', '2026-08-15 15:34:38'),
(14, 1, 1, 'Sales', 'sale', NULL, NULL, '2026-08-15 15:34:38', '2026-08-15 15:34:38'),
(15, 1, 1, 'Local Sales', 'localsales', NULL, NULL, '2026-08-15 15:34:38', '2026-08-15 15:34:38'),
(16, 1, 1, 'Export Sales', 'exportsale', NULL, NULL, '2026-08-15 15:34:38', '2026-08-15 15:34:38'),
(17, 1, 1, 'Profit And Loss', 'pnl', NULL, NULL, '2026-08-15 15:34:38', '2026-08-15 15:34:38');

-- --------------------------------------------------------

--
-- Table structure for table `cities`
--

CREATE TABLE `cities` (
  `id` mediumint UNSIGNED NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `state_id` mediumint UNSIGNED NOT NULL,
  `state_code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `country_id` mediumint UNSIGNED NOT NULL,
  `country_code` char(2) COLLATE utf8mb4_unicode_ci NOT NULL,
  `latitude` decimal(10,8) NOT NULL,
  `longitude` decimal(11,8) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT '2013-12-31 21:01:01',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `flag` tinyint(1) NOT NULL DEFAULT '1',
  `wikiDataId` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `companies`
--

CREATE TABLE `companies` (
  `id` bigint UNSIGNED NOT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `logo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cell` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `whatsapp_no` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fb_link` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ntn_no` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `strn_no` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gst_no` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `registration_no` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` text COLLATE utf8mb4_unicode_ci,
  `country_id` bigint UNSIGNED DEFAULT NULL,
  `state_id` bigint UNSIGNED DEFAULT NULL,
  `city_id` bigint UNSIGNED DEFAULT NULL,
  `zipcode` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `max_users` int NOT NULL DEFAULT '10',
  `max_branches` int NOT NULL DEFAULT '2',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `companies`
--

INSERT INTO `companies` (`id`, `code`, `name`, `description`, `logo`, `phone`, `cell`, `whatsapp_no`, `fb_link`, `email`, `ntn_no`, `strn_no`, `gst_no`, `registration_no`, `address`, `country_id`, `state_id`, `city_id`, `zipcode`, `is_active`, `max_users`, `max_branches`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 'CO-00001', 'Abstract Connoisseurs', NULL, '/storage/photos/1/logo-img.png', '+923337034390', NULL, NULL, NULL, 'bdanny1996@gmail.com', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, 10, 2, '2026-08-15 15:34:38', '2026-08-15 15:34:38', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `company_settings`
--

CREATE TABLE `company_settings` (
  `id` bigint UNSIGNED NOT NULL,
  `company_id` bigint UNSIGNED DEFAULT NULL,
  `business_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `start_date` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `currency_placement` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `currency_id` bigint UNSIGNED DEFAULT NULL,
  `profit_percent` double DEFAULT NULL,
  `logo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `timezone_id` bigint UNSIGNED DEFAULT NULL,
  `financial_start_month` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date_format` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `time_format` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `search_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `accounting_method` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `default_customer` bigint UNSIGNED DEFAULT NULL,
  `default_pos_unit` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `update_packing_qty` tinyint(1) NOT NULL DEFAULT '0',
  `purchase_column` json DEFAULT NULL,
  `transaction_edit_days` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `purchase_order` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `purchase_return` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `stock_transfer` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `stock_adjustment` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sell_return` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `invoice` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `expenses` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `supplier` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `customer` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bank` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `product` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `purchase_payment` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sell_payment` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `expense_payment` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `business_location` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subscription_no` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `draft` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `opening_stock` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `grn` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gin` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `purchase_approval` tinyint(1) NOT NULL DEFAULT '0',
  `sell_approval` tinyint(1) NOT NULL DEFAULT '0',
  `journal_entry` tinyint(1) NOT NULL DEFAULT '0',
  `show_sku` tinyint(1) NOT NULL DEFAULT '0',
  `cash_collection` tinyint(1) NOT NULL DEFAULT '0',
  `payment` tinyint(1) NOT NULL DEFAULT '0',
  `limit_account` tinyint(1) NOT NULL DEFAULT '0',
  `auto_grn` tinyint(1) NOT NULL DEFAULT '0',
  `auto_gin` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `company_settings`
--

INSERT INTO `company_settings` (`id`, `company_id`, `business_name`, `start_date`, `currency_placement`, `currency_id`, `profit_percent`, `logo`, `timezone_id`, `financial_start_month`, `date_format`, `time_format`, `search_type`, `accounting_method`, `default_customer`, `default_pos_unit`, `update_packing_qty`, `purchase_column`, `transaction_edit_days`, `purchase_order`, `purchase_return`, `stock_transfer`, `stock_adjustment`, `sell_return`, `invoice`, `expenses`, `supplier`, `customer`, `bank`, `product`, `purchase_payment`, `sell_payment`, `expense_payment`, `business_location`, `subscription_no`, `draft`, `opening_stock`, `grn`, `gin`, `purchase_approval`, `sell_approval`, `journal_entry`, `show_sku`, `cash_collection`, `payment`, `limit_account`, `auto_grn`, `auto_gin`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 1, 'Abstract Connoisseurs', NULL, NULL, NULL, NULL, '/storage/photos/1/logo-img.png', NULL, NULL, NULL, NULL, 'searchbox', 'lifo', NULL, '0', 0, '[{\"name\": \"Packing Quantity\", \"show\": true}, {\"name\": \"Unit Cost (Before Discount)\", \"show\": true}, {\"name\": \"Discount %\", \"show\": true}, {\"name\": \"Unit Cost (Before Tax)\", \"show\": true}, {\"name\": \"Subtotal (Before Tax)\", \"show\": true}, {\"name\": \"Product Tax\", \"show\": true}, {\"name\": \"Net Cost\", \"show\": true}, {\"name\": \"Line Total\", \"show\": true}, {\"name\": \"Profit Margin %\", \"show\": true}, {\"name\": \"Unit Selling Price (Inc. tax)\", \"show\": true}]', NULL, 'PO', 'PR', 'ST', 'SA', 'SR', 'INV', 'EXP', 'SU', 'CU', 'BA', 'PRO', 'PP', 'SP', 'EP', 'BL', 'SN', 'DRA', 'OS', 'GRN', 'GIN', 0, 0, 0, 0, 0, 0, 0, 0, 0, '2026-08-15 15:34:38', '2026-08-16 13:47:14', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `contacts`
--

CREATE TABLE `contacts` (
  `id` bigint UNSIGNED NOT NULL,
  `company_id` bigint UNSIGNED DEFAULT NULL,
  `branch_id` bigint UNSIGNED DEFAULT NULL,
  `currency_id` bigint UNSIGNED DEFAULT NULL,
  `country_id` bigint UNSIGNED DEFAULT NULL,
  `state_id` bigint UNSIGNED DEFAULT NULL,
  `city_id` bigint UNSIGNED DEFAULT NULL,
  `link_id` int DEFAULT NULL,
  `prefix` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `first_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `middle_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `business_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `gl_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pay_term` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pay_type` enum('month','day','year') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'day',
  `credit_limit` int NOT NULL DEFAULT '0',
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mobile` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `alternate_no` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `landline` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `landmark` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `link_account` tinyint(1) NOT NULL DEFAULT '1',
  `address` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_type` enum('customer','supplier','both') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'customer',
  `type` enum('export','local') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'local',
  `ntn_number` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `countries`
--

CREATE TABLE `countries` (
  `id` mediumint UNSIGNED NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `iso3` char(3) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `numeric_code` char(3) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `iso2` char(2) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phonecode` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `capital` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `currency` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `currency_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `currency_symbol` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tld` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `native` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `region` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subregion` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nationality` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `timezones` text COLLATE utf8mb4_unicode_ci,
  `translations` text COLLATE utf8mb4_unicode_ci,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `emoji` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `emojiU` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `flag` tinyint(1) NOT NULL DEFAULT '1',
  `wikiDataId` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `currencies`
--

CREATE TABLE `currencies` (
  `id` bigint UNSIGNED NOT NULL,
  `currency_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `symbol` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `deleted_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `id` bigint UNSIGNED NOT NULL,
  `company_id` bigint UNSIGNED DEFAULT NULL,
  `branch_id` bigint UNSIGNED DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `active` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '1',
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `departments`
--

INSERT INTO `departments` (`id`, `company_id`, `branch_id`, `name`, `active`, `created_by`, `updated_by`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 1, 1, 'test department', '1', 2, 2, '2026-08-15 15:57:54', '2026-08-15 15:57:54', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `failed_jobs`
--

CREATE TABLE `failed_jobs` (
  `id` bigint UNSIGNED NOT NULL,
  `uuid` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `connection` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `queue` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `exception` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `financial_years`
--

CREATE TABLE `financial_years` (
  `id` bigint UNSIGNED NOT NULL,
  `company_id` bigint UNSIGNED DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `status` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `item_types`
--

CREATE TABLE `item_types` (
  `id` bigint UNSIGNED NOT NULL,
  `company_id` bigint UNSIGNED DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `jobs`
--

CREATE TABLE `jobs` (
  `id` bigint UNSIGNED NOT NULL,
  `queue` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts` smallint UNSIGNED NOT NULL,
  `reserved_at` int UNSIGNED DEFAULT NULL,
  `available_at` int UNSIGNED NOT NULL,
  `created_at` int UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `job_batches`
--

CREATE TABLE `job_batches` (
  `id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_jobs` int NOT NULL,
  `pending_jobs` int NOT NULL,
  `failed_jobs` int NOT NULL,
  `failed_job_ids` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `options` mediumtext COLLATE utf8mb4_unicode_ci,
  `cancelled_at` int DEFAULT NULL,
  `created_at` int NOT NULL,
  `finished_at` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `menus`
--

CREATE TABLE `menus` (
  `id` bigint UNSIGNED NOT NULL,
  `parent_id` bigint UNSIGNED DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `icon` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `route_name` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `route_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `menu_color` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sort_order` int NOT NULL,
  `is_hidden` tinyint(1) NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `is_admin` tinyint(1) NOT NULL DEFAULT '0',
  `is_permission` tinyint(1) NOT NULL DEFAULT '0',
  `type` int NOT NULL DEFAULT '1',
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `menus`
--

INSERT INTO `menus` (`id`, `parent_id`, `name`, `icon`, `route_name`, `route_path`, `menu_color`, `sort_order`, `is_hidden`, `is_active`, `is_admin`, `is_permission`, `type`, `created_by`, `updated_by`, `created_at`, `updated_at`, `deleted_at`) VALUES
(2, NULL, 'Menus', 'fal fa-bars', '/menu', '/menu', '#6a0dad', 1, 0, 1, 1, 1, 1, NULL, NULL, NULL, '2022-01-26 11:09:33', NULL),
(3, 2, 'Add Menu', '', 'addmenu', '/menu/add', '#6a0dad', 1, 1, 1, 1, 1, 1, NULL, NULL, NULL, NULL, NULL),
(4, 2, 'Edit Menu', '', 'editmenu', '/menu/:id/edit', '#6a0dad', 1, 1, 1, 1, 1, 1, NULL, NULL, NULL, NULL, NULL),
(7, 2, 'Delete Menu', NULL, 'menus.destroy', '/menu/delete', NULL, 0, 1, 1, 1, 1, 1, NULL, NULL, '2022-01-29 10:23:11', '2022-02-04 23:42:55', NULL),
(17, NULL, 'Company', 'fal fa-building', '/company', '/company', '#6a0dad', 1, 0, 1, 1, 1, 1, NULL, NULL, NULL, '2022-01-26 11:09:33', NULL),
(18, 17, 'Add Company', '', 'addcompany', '/company/add', '#6a0dad', 1, 1, 1, 1, 1, 1, NULL, NULL, NULL, '2022-02-07 08:55:05', NULL),
(19, 17, 'Edit Company', '', 'editcompany', '/company/:id/edit', '#6a0dad', 1, 1, 1, 1, 1, 1, NULL, NULL, NULL, NULL, NULL),
(20, 17, 'Delete Company', NULL, 'companies.destroy', '/company/delete', NULL, 0, 1, 1, 1, 1, 1, NULL, NULL, '2022-01-29 10:23:11', '2022-02-04 23:42:55', NULL),
(22, 26, 'Branch', 'bx bx-buildings', 'branch', '/branch', '#6a0dad', 1, 0, 1, 0, 0, 1, NULL, NULL, NULL, '2022-09-13 00:04:16', NULL),
(23, 22, 'Add Branch', '', 'addbranch', '/branch/add', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, '2022-02-07 08:55:05', NULL),
(24, 22, 'Edit Branch', '', 'editbranch', '/branch/:id/edit', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, NULL, NULL),
(25, 22, 'Delete Branch', NULL, 'branches.destroy', '/branch/delete', NULL, 0, 1, 1, 0, 0, 1, NULL, NULL, '2022-01-29 10:23:11', '2022-02-04 23:42:55', NULL),
(26, NULL, 'User Management', 'fal fa-user', '', NULL, NULL, 5, 0, 1, 0, 0, 2, NULL, NULL, '2022-02-12 00:58:52', '2022-02-12 00:58:52', NULL),
(27, 26, 'Department', 'bx bx-buildings', 'department', '/department', '#6a0dad', 1, 0, 1, 0, 0, 1, NULL, NULL, NULL, '2022-02-12 01:54:03', NULL),
(28, 27, 'Add Department', '', 'adddepartment', '/department/add', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, '2022-02-07 08:55:05', NULL),
(29, 27, 'Edit Department', '', 'editdepartment', '/department/:id/edit', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, NULL, NULL),
(30, 27, 'Delete Department', NULL, 'departments.destroy', '/department/delete', NULL, 0, 1, 1, 0, 0, 1, NULL, NULL, '2022-01-29 10:23:11', '2022-02-04 23:42:55', NULL),
(31, 26, 'Role', 'bx bx-buildings', 'role', '/role', '#6a0dad', 1, 0, 1, 0, 0, 1, NULL, NULL, NULL, '2022-02-12 01:54:03', NULL),
(32, 31, 'Add Role', '', 'addrole', '/role/add', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, '2022-02-07 08:55:05', NULL),
(33, 31, 'Edit Role', '', 'editrole', '/role/:id/edit', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, NULL, NULL),
(34, 31, 'Delete Role', NULL, 'roles.destroy', '/role/delete', NULL, 0, 1, 1, 0, 0, 1, NULL, NULL, '2022-01-29 10:23:11', '2022-02-04 23:42:55', NULL),
(35, 31, 'Role Permission', NULL, 'permissionrole', '/role/:id/permission', NULL, 12, 1, 1, 0, 0, 1, NULL, NULL, NULL, NULL, NULL),
(36, 26, 'User', 'bx bx-user', 'user', '/user', '#6a0dad', 1, 0, 1, 0, 0, 1, NULL, NULL, NULL, '2022-02-12 01:54:03', NULL),
(37, 36, 'Add User', '', 'adduser', '/user/add', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, '2022-02-07 08:55:05', NULL),
(38, 36, 'Edit User', '', 'edituser', '/user/:id/edit', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, NULL, NULL),
(39, 36, 'Delete User', NULL, 'users.destroy', '/user/delete', NULL, 0, 1, 1, 0, 0, 1, NULL, NULL, '2022-01-29 10:23:11', '2022-02-04 23:42:55', NULL),
(40, NULL, 'Company Settings', 'fal fa-cog', 'businesssetting', '/company/setting', NULL, 5, 0, 1, 0, 0, 1, NULL, NULL, '2022-02-12 00:58:52', '2022-02-12 00:58:52', NULL),
(41, 40, 'Business Settings', NULL, 'businesssetting', '/business/settings', NULL, 0, 1, 1, 0, 0, 1, NULL, NULL, '2022-01-29 10:23:11', '2022-02-04 23:42:55', NULL),
(42, 40, 'Tax', 'bx bx-buildings', 'tax', '/tax', '#6a0dad', 1, 0, 1, 0, 0, 1, NULL, NULL, NULL, '2022-02-12 01:54:03', NULL),
(43, 42, 'Add Tax', '', 'addtax', '/tax/add', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, '2022-02-07 08:55:05', NULL),
(44, 42, 'Edit Tax', '', 'edittax', '/tax/:id/edit', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, NULL, NULL),
(45, 42, 'Delete Tax', NULL, 'taxes.destroy', '/tax/delete', NULL, 0, 1, 1, 0, 0, 1, NULL, NULL, '2022-01-29 10:23:11', '2022-02-04 23:42:55', NULL),
(46, 40, 'Financial Year', 'bx bx-buildings', 'financialyear', '/financialyear', '#6a0dad', 1, 0, 1, 0, 0, 1, NULL, NULL, NULL, '2022-02-12 01:54:03', NULL),
(47, 46, 'Add Financial Year', '', 'addfinancialyear', '/financialyear/add', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, '2022-02-07 08:55:05', NULL),
(48, NULL, 'Accounts', 'fal fa-file-invoice', '', NULL, NULL, 5, 0, 1, 0, 0, 2, NULL, NULL, '2022-02-12 00:58:52', '2022-02-12 00:58:52', NULL),
(49, 48, 'Chart Of Account', 'bx bx-buildings', 'chartofaccount', '/chart-of-account', '#6a0dad', 1, 0, 1, 0, 0, 1, NULL, NULL, NULL, '2022-02-12 01:54:03', NULL),
(50, 49, 'Add Chart Of Account', '', 'addchartofaccount', '/chart-of-account/add', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, '2022-02-07 08:55:05', NULL),
(51, 49, 'Edit Chart Of Account', '', 'editchartofaccount', '/chart-of-account/:id/edit', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, NULL, NULL),
(52, 49, 'Delete Chart Of Account', NULL, 'chartofaccounts.destroy', '/chart-of-account/delete', NULL, 0, 1, 1, 0, 0, 1, NULL, NULL, '2022-01-29 10:23:11', '2022-02-04 23:42:55', NULL),
(53, NULL, 'Contacts', 'fal fa-id-badge', '', NULL, NULL, 5, 0, 1, 0, 0, 2, NULL, NULL, '2022-02-12 00:58:52', '2022-02-12 00:58:52', NULL),
(54, 53, 'Supplier', 'bx bx-buildings', 'supplier', '/supplier', '#6a0dad', 1, 0, 1, 0, 0, 1, NULL, NULL, NULL, '2022-02-12 01:54:03', NULL),
(55, 54, 'Add Supplier', '', 'addsupplier', '/supplier/add', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, '2022-02-07 08:55:05', NULL),
(56, 54, 'Edit Supplier', '', 'editsupplier', '/supplier/:id/edit', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, NULL, NULL),
(57, 54, 'Delete Supplier', NULL, 'suppliers.destroy', '/supplier/delete', NULL, 0, 1, 1, 0, 0, 1, NULL, NULL, '2022-01-29 10:23:11', '2022-02-04 23:42:55', NULL),
(58, 53, 'Customer', 'bx bx-buildings', 'customer', '/customer', '#6a0dad', 1, 0, 1, 0, 0, 1, NULL, NULL, NULL, '2022-02-12 01:54:03', NULL),
(59, 58, 'Add Customer', '', 'addcustomer', '/customer/add', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, '2022-02-07 08:55:05', NULL),
(60, 58, 'Edit Customer', '', 'editcustomer', '/customer/:id/edit', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, NULL, NULL),
(61, 58, 'Delete Customer', NULL, 'customers.destroy', '/customer/delete', NULL, 0, 1, 1, 0, 0, 1, NULL, NULL, '2022-01-29 10:23:11', '2022-02-04 23:42:55', NULL),
(62, 53, 'Bank', 'bx bx-buildings', 'bank', '/bank', '#6a0dad', 1, 0, 1, 0, 0, 1, NULL, NULL, NULL, '2022-02-12 01:54:03', NULL),
(63, 62, 'Add Bank', '', 'addbank', '/bank/add', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, '2022-02-07 08:55:05', NULL),
(64, 62, 'Edit Bank', '', 'editbank', '/bank/:id/edit', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, NULL, NULL),
(65, 62, 'Delete Bank', NULL, 'banks.destroy', '/bank/delete', NULL, 0, 1, 1, 0, 0, 1, NULL, NULL, '2022-01-29 10:23:11', '2022-02-04 23:42:55', NULL),
(66, NULL, 'Products', 'fab fa-product-hunt', '', NULL, NULL, 5, 0, 1, 0, 0, 2, NULL, NULL, '2022-02-12 00:58:52', '2022-02-12 00:58:52', NULL),
(67, 66, 'Unit', 'bx bx-buildings', 'unit', '/unit', '#6a0dad', 1, 0, 1, 0, 0, 1, NULL, NULL, NULL, '2022-02-12 01:54:03', NULL),
(68, 67, 'Add Unit', '', 'addunit', '/unit/add', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, '2022-02-07 08:55:05', NULL),
(69, 67, 'Edit Unit', '', 'editunit', '/unit/:id/edit', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, NULL, NULL),
(70, 67, 'Delete Unit', NULL, 'units.destroy', '/unit/delete', NULL, 0, 1, 1, 0, 0, 1, NULL, NULL, '2022-01-29 10:23:11', '2022-02-04 23:42:55', NULL),
(71, 66, 'Category', 'bx bx-buildings', 'category', '/category', '#6a0dad', 1, 0, 1, 0, 0, 1, NULL, NULL, NULL, '2022-02-12 01:54:03', NULL),
(72, 71, 'Add Category', '', 'addcategory', '/category/add', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, '2022-02-07 08:55:05', NULL),
(73, 71, 'Edit Category', '', 'editcategory', '/category/:id/edit', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, NULL, NULL),
(74, 71, 'Delete Category', NULL, 'categories.destroy', '/category/delete', NULL, 0, 1, 1, 0, 0, 1, NULL, NULL, '2022-01-29 10:23:11', '2022-02-04 23:42:55', NULL),
(75, 66, 'Brand', 'bx bx-buildings', 'brand', '/brand', '#6a0dad', 1, 0, 1, 0, 0, 1, NULL, NULL, NULL, '2022-02-12 01:54:03', NULL),
(76, 75, 'Add Brand', '', 'addbrand', '/brand/add', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, '2022-02-07 08:55:05', NULL),
(77, 75, 'Edit Brand', '', 'editbrand', '/brand/:id/edit', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, NULL, NULL),
(78, 75, 'Delete Brand', NULL, 'brands.destroy', '/brand/delete', NULL, 0, 1, 1, 0, 0, 1, NULL, NULL, '2022-01-29 10:23:11', '2022-02-04 23:42:55', NULL),
(79, 66, 'Warranty', 'bx bx-buildings', 'warranty', '/warranty', '#6a0dad', 1, 0, 1, 0, 0, 1, NULL, NULL, NULL, '2022-02-12 01:54:03', NULL),
(80, 79, 'Add Warranty', '', 'addwarranty', '/warranty/add', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, '2022-02-07 08:55:05', NULL),
(81, 79, 'Edit Warranty', '', 'editwarranty', '/warranty/:id/edit', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, NULL, NULL),
(82, 79, 'Delete Warranty', NULL, 'warranties.destroy', '/warranty/delete', NULL, 0, 1, 1, 0, 0, 1, NULL, NULL, '2022-01-29 10:23:11', '2022-02-04 23:42:55', NULL),
(83, 66, 'Item Type', 'bx bx-buildings', 'itemtype', '/itemtype', '#6a0dad', 1, 0, 1, 0, 0, 1, NULL, NULL, NULL, '2022-02-12 01:54:03', NULL),
(84, 83, 'Add Item Type', '', 'additemtype', '/itemtype/add', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, '2022-02-07 08:55:05', NULL),
(85, 83, 'Edit Item Type', '', 'edititemtype', '/itemtype/:id/edit', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, NULL, NULL),
(86, 83, 'Delete Item Type', NULL, 'itemtypes.destroy', '/itemtype/delete', NULL, 0, 1, 1, 0, 0, 1, NULL, NULL, '2022-01-29 10:23:11', '2022-02-04 23:42:55', NULL),
(87, 66, 'Variation', 'bx bx-buildings', 'variation', '/variation', '#6a0dad', 1, 0, 1, 0, 0, 1, NULL, NULL, NULL, '2022-02-12 01:54:03', NULL),
(88, 87, 'Add Variation', '', 'addvariation', '/variation/add', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, '2022-02-07 08:55:05', NULL),
(89, 87, 'Edit Variation', '', 'editvariation', '/variation/:id/edit', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, NULL, NULL),
(90, 87, 'Delete Variation', NULL, 'variations.destroy', '/variation/delete', NULL, 0, 1, 1, 0, 0, 1, NULL, NULL, '2022-01-29 10:23:11', '2022-02-04 23:42:55', NULL),
(91, 66, 'Product', 'bx bx-buildings', 'product', '/product', '#6a0dad', 1, 0, 1, 0, 0, 1, NULL, NULL, NULL, '2022-02-12 01:54:03', NULL),
(92, 91, 'Add Product', '', 'addproduct', '/product/add', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, '2022-02-07 08:55:05', NULL),
(93, 91, 'Edit Product', '', 'editproduct', '/product/:id/edit', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, NULL, NULL),
(94, 91, 'Delete Product', NULL, 'products.destroy', '/product/delete', NULL, 0, 1, 1, 0, 0, 1, NULL, NULL, '2022-01-29 10:23:11', '2022-02-04 23:42:55', NULL),
(95, 66, 'Opening Stock', 'bx bx-data', 'openingstock', '/openingstock', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, '2022-02-12 01:54:03', NULL),
(96, 95, 'Add / Edit Opening Stock', '', 'addopeningstock', '/openingstock/add', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, '2022-02-07 08:55:05', NULL),
(99, NULL, 'Purchase', 'fal fa-tags', '', NULL, NULL, 5, 0, 1, 0, 0, 2, NULL, NULL, '2022-02-12 00:58:52', '2022-02-12 00:58:52', NULL),
(100, 99, 'Purchase', 'bx bx-buildings', 'purchase', '/purchase', '#6a0dad', 1, 0, 1, 0, 0, 1, NULL, NULL, NULL, '2022-02-12 01:54:03', NULL),
(101, 100, 'Add Purchase', '', 'addpurchase', '/purchase/add', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, '2022-02-07 08:55:05', NULL),
(102, 100, 'Edit Purchase', '', 'editpurchase', '/purchase/:id/edit', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, NULL, NULL),
(103, 100, 'Delete Purchase', NULL, 'purchases.destroy', '/purchase/delete', NULL, 0, 1, 1, 0, 0, 1, NULL, NULL, '2022-01-29 10:23:11', '2022-02-04 23:42:55', NULL),
(104, 100, 'View Purchase', '', 'viewpurchase', '/purchase/:id/view', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, NULL, NULL),
(105, NULL, 'Approval', 'fal fa-thumbs-up', '', NULL, NULL, 5, 0, 1, 0, 0, 2, NULL, NULL, '2022-02-12 00:58:52', '2022-02-12 00:58:52', NULL),
(106, 100, 'Purchase', 'bx bx-buildings', 'purchaseapproval', '/purchase/approval', '#6a0dad', 1, 0, 1, 0, 0, 1, NULL, NULL, NULL, '2022-02-12 01:54:03', NULL),
(107, 99, 'Receiving Note', 'bx bx-buildings', 'receivingnote', '/receivingnote', '#6a0dad', 1, 0, 1, 0, 0, 1, NULL, NULL, NULL, '2022-02-12 01:54:03', NULL),
(108, 107, 'Add Receiving Note', '', 'addreceivingnote', '/receivingnote/add', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, '2022-02-07 08:55:05', NULL),
(109, 107, 'Edit Receiving Note', '', 'editreceivingnote', '/receivingnote/:id/edit', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, NULL, NULL),
(110, 107, 'Delete Receiving Note', NULL, 'receivingnotes.destroy', '/receivingnote/delete', NULL, 0, 1, 1, 0, 0, 1, NULL, NULL, '2022-01-29 10:23:11', '2022-02-04 23:42:55', NULL),
(111, 107, 'View Receiving Note', '', 'viewreceivingnote', '/receivingnote/:id/view', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, NULL, NULL),
(112, 99, 'Add Payment Receive', '', 'addpayment', '/payment/add', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, '2022-02-07 08:55:05', NULL),
(113, 99, 'Payment Receive', '', 'payment', '/payment', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, '2022-02-07 08:55:05', NULL),
(114, 99, 'Edit Payment', '', 'editpayment', '/payment/:id/edit', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, NULL, NULL),
(115, 99, 'Delete Payment', NULL, 'payments.destroy', '/payment/delete', NULL, 0, 1, 1, 0, 0, 1, NULL, NULL, '2022-01-29 10:23:11', '2022-02-04 23:42:55', NULL),
(116, NULL, 'Sell', 'far fa-badge-dollar', '', NULL, NULL, 5, 0, 1, 0, 0, 2, NULL, NULL, '2022-02-12 00:58:52', '2022-02-12 00:58:52', NULL),
(117, 116, 'Sell', 'bx bx-buildings', 'sell', '/sell', '#6a0dad', 1, 0, 1, 0, 0, 1, NULL, NULL, NULL, '2022-02-12 01:54:03', NULL),
(118, 117, 'Add Sell', '', 'addsell', '/sell/add', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, '2022-02-07 08:55:05', NULL),
(119, 117, 'Edit Sell', '', 'editsell', '/sell/:id/edit', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, NULL, NULL),
(120, 117, 'Delete Sell', NULL, 'sells.destroy', '/sell/delete', NULL, 0, 1, 1, 0, 0, 1, NULL, NULL, '2022-01-29 10:23:11', '2022-02-04 23:42:55', NULL),
(121, 117, 'View Sell', '', 'viewsell', '/sell/:id/view', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, NULL, NULL),
(122, 117, 'Sell', 'bx bx-buildings', 'sellapproval', '/sell/approval', '#6a0dad', 1, 0, 1, 0, 0, 1, NULL, NULL, NULL, '2022-02-12 01:54:03', NULL),
(123, 116, 'Issue Note', 'bx bx-buildings', 'issuenote', '/issuenote', '#6a0dad', 1, 0, 1, 0, 0, 1, NULL, NULL, NULL, '2022-02-12 01:54:03', NULL),
(124, 123, 'Add Issue Note', '', 'addissuenote', '/issuenote/add', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, '2022-02-07 08:55:05', NULL),
(125, 123, 'Edit Issue Note', '', 'editissuenote', '/issuenote/:id/edit', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, NULL, NULL),
(126, 123, 'Delete Issue Note', NULL, 'issuenotes.destroy', '/issuenote/delete', NULL, 0, 1, 1, 0, 0, 1, NULL, NULL, '2022-01-29 10:23:11', '2022-02-04 23:42:55', NULL),
(127, 123, 'View Issue Note', '', 'viewissuenote', '/issuenote/:id/view', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, NULL, NULL),
(128, 99, 'Purchase Return', 'bx bx-buildings', 'purchasereturn', '/purchase/return', '#6a0dad', 1, 0, 1, 0, 0, 1, NULL, NULL, NULL, '2022-02-12 01:54:03', NULL),
(129, 128, 'Add Purchase Return', '', 'addpurchasereturn', '/purchase/return/add', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, '2022-02-07 08:55:05', NULL),
(130, 128, 'Edit Purchase Return', '', 'editpurchasereturn', '/purchase/return/:id/edit', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, NULL, NULL),
(131, 128, 'Delete Purchase Return', NULL, 'purchasereturns.destroy', '/purchase/return/delete', NULL, 0, 1, 1, 0, 0, 1, NULL, NULL, '2022-01-29 10:23:11', '2022-02-04 23:42:55', NULL),
(132, 128, 'View Purchase Return', '', 'viewpurchasereturn', '/purchase/return/:id/view', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, NULL, NULL),
(133, 116, 'List Draft', 'bx bx-buildings', 'draft', '/sell/draft', '#6a0dad', 1, 0, 1, 0, 0, 1, NULL, NULL, NULL, '2022-02-12 01:54:03', NULL),
(134, 116, 'List Quotation', 'bx bx-buildings', 'quotation', '/sell/quotation', '#6a0dad', 1, 0, 1, 0, 0, 1, NULL, NULL, NULL, '2022-02-12 01:54:03', NULL),
(135, 116, 'Shipments', '', 'shipment', '/sell/shipment', '#6a0dad', 1, 0, 1, 0, 0, 1, NULL, NULL, NULL, '2022-02-07 08:55:05', NULL),
(136, 116, 'POS', '', 'pos', '/sell/pos', '#6a0dad', 1, 0, 1, 0, 0, 1, NULL, NULL, NULL, '2022-02-07 08:55:05', NULL),
(137, 58, 'View Supplier', NULL, '', '/supplier/:id/view', NULL, 0, 0, 1, 0, 0, 1, NULL, NULL, '2022-11-26 01:35:01', '2022-11-26 01:35:01', NULL),
(138, 48, 'Opening Balance', NULL, '', '/openingbalance', NULL, 0, 0, 1, 0, 0, 1, NULL, NULL, '2022-11-29 08:55:14', '2022-11-29 08:55:14', NULL),
(139, 58, 'View Customer', NULL, '', '/customer/:id/view', NULL, 0, 0, 1, 0, 0, 1, NULL, NULL, '2022-11-26 01:35:01', '2022-11-26 01:35:01', NULL),
(140, 48, 'Journal Entry', NULL, '', '/journalentry', NULL, 0, 0, 1, 0, 0, 1, NULL, NULL, '2023-09-25 21:40:40', '2023-09-25 21:40:40', NULL),
(141, 140, 'Add Journal Entry', NULL, '', '/journalentry/add', NULL, 0, 0, 1, 0, 0, 1, NULL, NULL, '2023-09-26 00:57:16', '2023-09-26 00:57:16', NULL),
(142, 140, 'Edit Journal Entry', '', 'editjournalentry', '/journalentry/:id/edit', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, NULL, NULL),
(143, 140, 'Delete Journal Entry', NULL, 'journalentries.destroy', '/journalentry/delete', NULL, 0, 1, 1, 0, 0, 1, NULL, NULL, '2022-01-29 10:23:11', '2022-02-04 23:42:55', NULL),
(144, 140, 'View Journal Entry', '', 'viewjournalentry', '/journalentry/:id/view', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, NULL, NULL),
(145, 140, 'Journal Entry Approve', 'bx bx-buildings', 'journalentryapproval', '/journalentry/:id/approve', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, '2022-02-12 01:54:03', NULL),
(146, 140, 'Journal Entry Reject', 'bx bx-buildings', 'journalentryreject', '/journalentry/:id/reject', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, '2022-02-12 01:54:03', NULL),
(147, 140, 'Journal Entry Print', 'bx bx-buildings', 'journalentryprint', '/journalentry/:id/print', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, '2022-02-12 01:54:03', NULL),
(148, 140, 'Journal Entry', 'bx bx-buildings', 'journalentryapproval', '/journalentry/approval', '#6a0dad', 1, 0, 1, 0, 0, 1, NULL, NULL, NULL, '2022-02-12 01:54:03', NULL),
(149, 48, 'Payment', NULL, '', '/acpayment', NULL, 0, 0, 1, 0, 0, 1, NULL, NULL, '2023-09-25 21:40:40', '2023-09-25 21:40:40', NULL),
(150, 149, 'Add Payment', NULL, '', '/acpayment/add', NULL, 0, 0, 1, 0, 0, 1, NULL, NULL, '2023-09-26 00:57:16', '2023-09-26 00:57:16', NULL),
(151, 149, 'Edit Payment', '', 'editacpayment', '/acpayment/:id/edit', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, NULL, NULL),
(152, 149, 'Delete Payment', NULL, 'acpayments.destroy', '/acpayment/delete', NULL, 0, 1, 1, 0, 0, 1, NULL, NULL, '2022-01-29 10:23:11', '2022-02-04 23:42:55', NULL),
(153, 2, 'Menu Export', '', 'menu.export', '/menu/export', '#6a0dad', 1, 1, 1, 1, 1, 1, NULL, NULL, NULL, NULL, NULL),
(154, 2, 'Menu Import', '', 'menu.import', '/menu/import', '#6a0dad', 1, 1, 1, 1, 1, 1, NULL, NULL, NULL, NULL, NULL),
(155, 2, 'Menu Trash', '', 'menu.trash', '/menu/trash', '#6a0dad', 1, 1, 1, 1, 1, 1, NULL, NULL, NULL, NULL, NULL),
(156, 31, 'Role Export', '', 'role.export', '/role/export', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, NULL, NULL),
(157, 31, 'Role Import', '', 'role.import', '/role/import', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, NULL, NULL),
(158, 31, 'Role Trash', '', 'role.trash', '/role/trash', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, NULL, NULL),
(159, 2, 'Department Export', '', 'department.export', '/department/export', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, NULL, NULL),
(160, 2, 'Department Import', '', 'department.import', '/department/import', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, NULL, NULL),
(161, 2, 'Department Trash', '', 'department.trash', '/department/trash', '#6a0dad', 1, 1, 1, 0, 0, 1, NULL, NULL, NULL, NULL, NULL),
(162, 2, 'Company Export', '', 'company.export', '/company/export', '#6a0dad', 1, 1, 1, 1, 1, 1, NULL, NULL, NULL, NULL, NULL),
(163, 2, 'Company Import', '', 'company.import', '/company/import', '#6a0dad', 1, 1, 1, 1, 1, 1, NULL, NULL, NULL, NULL, NULL),
(164, 2, 'Company Trash', '', 'company.trash', '/company/trash', '#6a0dad', 1, 1, 1, 1, 1, 1, NULL, NULL, NULL, NULL, NULL),
(165, NULL, 'Currencies', 'fas fa-money-bill', '/currency', '/currency', '#6a0dad', 1, 0, 1, 1, 1, 1, NULL, NULL, NULL, '2022-01-26 11:09:33', NULL),
(166, 2, 'Add Currency', '', 'addcurrency', '/currency/add', '#6a0dad', 1, 1, 1, 1, 1, 1, NULL, NULL, NULL, NULL, NULL),
(167, 2, 'Edit Currency', '', 'editcurrency', '/currency/:id/edit', '#6a0dad', 1, 1, 1, 1, 1, 1, NULL, NULL, NULL, NULL, NULL),
(168, 2, 'Delete Currency', NULL, 'currencies.destroy', '/currency/delete', NULL, 0, 1, 1, 1, 1, 1, NULL, NULL, '2022-01-29 10:23:11', '2022-02-04 23:42:55', NULL),
(169, 2, 'Currency Export', '', 'currency.export', '/currency/export', '#6a0dad', 1, 1, 1, 1, 1, 1, NULL, NULL, NULL, NULL, NULL),
(170, 2, 'Currency Import', '', 'currency.import', '/currency/import', '#6a0dad', 1, 1, 1, 1, 1, 1, NULL, NULL, NULL, NULL, NULL),
(171, 2, 'Currency Trash', '', 'currency.trash', '/currency/trash', '#6a0dad', 1, 1, 1, 1, 1, 1, NULL, NULL, NULL, NULL, NULL),
(172, NULL, 'Timezones', 'far fa-hourglass', '/timezone', '/timezone', '#6a0dad', 1, 0, 1, 1, 1, 1, NULL, NULL, NULL, '2022-01-26 11:09:33', NULL),
(173, 2, 'Add Timezone', '', 'addtimezone', '/timezone/add', '#6a0dad', 1, 1, 1, 1, 1, 1, NULL, NULL, NULL, NULL, NULL),
(174, 2, 'Edit Timezone', '', 'edittimezone', '/timezone/:id/edit', '#6a0dad', 1, 1, 1, 1, 1, 1, NULL, NULL, NULL, NULL, NULL),
(175, 2, 'Delete Timezone', NULL, 'timezones.destroy', '/timezone/delete', NULL, 0, 1, 1, 1, 1, 1, NULL, NULL, '2022-01-29 10:23:11', '2022-02-04 23:42:55', NULL),
(176, 2, 'Timezone Export', '', 'timezone.export', '/timezone/export', '#6a0dad', 1, 1, 1, 1, 1, 1, NULL, NULL, NULL, NULL, NULL),
(177, 2, 'Timezone Import', '', 'timezone.import', '/timezone/import', '#6a0dad', 1, 1, 1, 1, 1, 1, NULL, NULL, NULL, NULL, NULL),
(178, 2, 'Timezone Trash', '', 'timezone.trash', '/timezone/trash', '#6a0dad', 1, 1, 1, 1, 1, 1, NULL, NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `migrations`
--

CREATE TABLE `migrations` (
  `id` int UNSIGNED NOT NULL,
  `migration` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `migrations`
--

INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES
(1, '0001_01_01_000001_create_cache_table', 1),
(2, '0001_01_01_000002_create_jobs_table', 1),
(3, '2014_10_12_100000_create_password_resets_table', 1),
(4, '2019_12_14_000001_create_personal_access_tokens_table', 1),
(5, '2019_12_14_000002_create_currencies_table', 1),
(6, '2019_12_14_000003_create_timezones_table', 1),
(7, '2019_12_14_000004_create_countries_table', 1),
(8, '2019_12_14_000005_create_states_table', 1),
(9, '2019_12_14_000006_create_cities_table', 1),
(10, '2022_01_19_134856_create_companies_table', 1),
(11, '2022_01_19_134913_create_branches_table', 1),
(12, '2022_01_19_134929_create_roles_table', 1),
(13, '2022_01_19_135009_create_menus_table', 1),
(14, '2022_01_19_152050_create_departments_table', 1),
(15, '2022_01_19_152550_create_company_settings_table', 1),
(16, '2022_01_19_154255_create_users_table', 1),
(17, '2022_02_07_173706_create_chart_of_accounts_table', 1),
(18, '2022_02_07_173752_create_chart_of_account_mappings_table', 1),
(19, '2022_02_14_185212_create_permissions_table', 1),
(20, '2022_03_05_175720_create_taxes_table', 1),
(21, '2022_03_06_170145_create_financial_years_table', 1),
(22, '2022_03_14_172715_create_contacts_table', 1),
(23, '2022_03_19_160119_create_account_balances_table', 1),
(24, '2022_03_20_191552_create_banks_table', 1),
(25, '2022_03_22_191720_create_units_table', 1),
(26, '2022_03_23_133426_create_categories_table', 1),
(27, '2022_03_23_155810_create_brands_table', 1),
(28, '2022_03_24_162449_create_warranties_table', 1),
(29, '2022_03_25_171953_create_item_types_table', 1),
(30, '2022_03_25_183118_create_variations_table', 1),
(31, '2022_03_26_154529_create_products_table', 1),
(32, '2022_03_27_155631_create_product_details_table', 1),
(33, '2022_04_30_150550_create_transactions_table', 1),
(34, '2022_04_30_150827_create_purchase_lines_table', 1),
(35, '2022_04_30_151043_create_sell_lines_table', 1),
(36, '2022_04_30_151116_create_purchase_sell_lines_table', 1),
(37, '2022_05_01_154244_create_opening_stocks_table', 1),
(38, '2022_05_21_183635_create_t_accounts_table', 1),
(39, '2022_05_21_183853_create_t_account_details_table', 1),
(40, '2022_05_29_153221_create_payments_table', 1),
(41, '2024_01_01_000000_create_passkeys_table', 1),
(42, '2025_08_14_170933_add_two_factor_columns_to_users_table', 1),
(43, '2026_08_06_131633_create_settings_table', 1),
(44, '2026_08_06_181354_update_menu_icons_to_boxicons', 1),
(45, '2026_08_14_115821_add_phone_to_users_table', 1),
(46, '2026_08_14_180553_add_is_admin_to_menus_table', 2),
(47, '2026_08_15_214217_add_extended_fields_to_company_settings_table', 2),
(48, '2026_08_16_193409_add_whatsapp_no_to_companies_table', 3);

-- --------------------------------------------------------

--
-- Table structure for table `opening_stocks`
--

CREATE TABLE `opening_stocks` (
  `id` bigint UNSIGNED NOT NULL,
  `transaction_id` bigint UNSIGNED DEFAULT NULL,
  `product_id` bigint UNSIGNED DEFAULT NULL,
  `variation_id` bigint UNSIGNED DEFAULT NULL,
  `branch_id` bigint UNSIGNED DEFAULT NULL,
  `unit_id` bigint UNSIGNED DEFAULT NULL,
  `qty_available` int NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `passkeys`
--

CREATE TABLE `passkeys` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` bigint UNSIGNED NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `credential_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `credential` json NOT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE `password_resets` (
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `password_reset_tokens`
--

CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `id` bigint UNSIGNED NOT NULL,
  `company_id` bigint UNSIGNED DEFAULT NULL,
  `branch_id` bigint UNSIGNED DEFAULT NULL,
  `transaction_id` bigint UNSIGNED DEFAULT NULL,
  `contact_id` bigint UNSIGNED DEFAULT NULL,
  `payment_account` bigint UNSIGNED DEFAULT NULL,
  `is_return` tinyint(1) NOT NULL DEFAULT '0',
  `amount` double DEFAULT NULL,
  `method` enum('cash','card','cheque','bank_transfer','other') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cash',
  `card_transaction_number` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `card_number` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `card_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `card_holder_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `card_month` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `card_year` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `card_security` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cheque_number` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bank_account_number` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `paid_on` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `note` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `document` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payment_ref_no` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `permissions`
--

CREATE TABLE `permissions` (
  `id` bigint UNSIGNED NOT NULL,
  `company_id` bigint UNSIGNED DEFAULT NULL,
  `branch_id` bigint UNSIGNED DEFAULT NULL,
  `department_id` bigint UNSIGNED DEFAULT NULL,
  `role_id` bigint UNSIGNED DEFAULT NULL,
  `menu_id` bigint UNSIGNED DEFAULT NULL,
  `status` tinyint(1) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `permissions`
--

INSERT INTO `permissions` (`id`, `company_id`, `branch_id`, `department_id`, `role_id`, `menu_id`, `status`, `created_at`, `updated_at`) VALUES
(1, NULL, NULL, NULL, 2, 2, 0, '2026-08-15 15:33:00', '2026-08-15 15:33:00'),
(2, NULL, NULL, NULL, 2, 3, 0, '2026-08-15 15:33:00', '2026-08-15 15:33:00'),
(3, NULL, NULL, NULL, 2, 4, 0, '2026-08-15 15:33:00', '2026-08-15 15:33:00'),
(4, NULL, NULL, NULL, 2, 7, 0, '2026-08-15 15:33:00', '2026-08-15 15:33:00'),
(5, NULL, NULL, NULL, 2, 17, 0, '2026-08-15 15:33:00', '2026-08-15 15:33:00'),
(6, NULL, NULL, NULL, 2, 18, 0, '2026-08-15 15:33:00', '2026-08-15 15:33:00'),
(7, NULL, NULL, NULL, 2, 31, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:00'),
(8, NULL, NULL, NULL, 2, 19, 0, '2026-08-15 15:33:00', '2026-08-15 15:33:00'),
(9, NULL, NULL, NULL, 2, 20, 0, '2026-08-15 15:33:00', '2026-08-15 15:33:00'),
(10, NULL, NULL, NULL, 2, 22, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:01'),
(11, NULL, NULL, NULL, 2, 32, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:00'),
(12, NULL, NULL, NULL, 2, 23, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:01'),
(13, NULL, NULL, NULL, 2, 33, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:00'),
(14, NULL, NULL, NULL, 2, 24, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:01'),
(15, NULL, NULL, NULL, 2, 34, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:00'),
(16, NULL, NULL, NULL, 2, 25, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:01'),
(17, NULL, NULL, NULL, 2, 26, 0, '2026-08-15 15:33:00', '2026-08-15 15:33:00'),
(18, NULL, NULL, NULL, 2, 35, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:00'),
(19, NULL, NULL, NULL, 2, 27, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:00'),
(20, NULL, NULL, NULL, 2, 28, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:00'),
(21, NULL, NULL, NULL, 2, 29, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:00'),
(22, NULL, NULL, NULL, 2, 30, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:00'),
(23, NULL, NULL, NULL, 2, 31, 0, '2026-08-15 15:33:00', '2026-08-15 15:33:00'),
(24, NULL, NULL, NULL, 2, 32, 0, '2026-08-15 15:33:00', '2026-08-15 15:33:00'),
(25, NULL, NULL, NULL, 2, 33, 0, '2026-08-15 15:33:00', '2026-08-15 15:33:00'),
(26, NULL, NULL, NULL, 2, 34, 0, '2026-08-15 15:33:00', '2026-08-15 15:33:00'),
(27, NULL, NULL, NULL, 2, 35, 0, '2026-08-15 15:33:00', '2026-08-15 15:33:00'),
(28, NULL, NULL, NULL, 2, 36, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:00'),
(29, NULL, NULL, NULL, 2, 37, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:00'),
(30, NULL, NULL, NULL, 2, 38, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:00'),
(31, NULL, NULL, NULL, 2, 39, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:00'),
(32, NULL, NULL, NULL, 2, 40, 0, '2026-08-15 15:33:00', '2026-08-15 15:33:00'),
(33, NULL, NULL, NULL, 2, 41, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:01'),
(34, NULL, NULL, NULL, 2, 42, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:01'),
(35, NULL, NULL, NULL, 2, 43, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:01'),
(36, NULL, NULL, NULL, 2, 44, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:01'),
(37, NULL, NULL, NULL, 2, 45, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:01'),
(38, NULL, NULL, NULL, 2, 46, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:01'),
(39, NULL, NULL, NULL, 2, 47, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:01'),
(40, NULL, NULL, NULL, 2, 48, 0, '2026-08-15 15:33:00', '2026-08-15 15:33:00'),
(41, NULL, NULL, NULL, 2, 49, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:02'),
(42, NULL, NULL, NULL, 2, 50, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:02'),
(43, NULL, NULL, NULL, 2, 51, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:02'),
(44, NULL, NULL, NULL, 2, 52, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:02'),
(45, NULL, NULL, NULL, 2, 53, 0, '2026-08-15 15:33:00', '2026-08-15 15:33:00'),
(46, NULL, NULL, NULL, 2, 54, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:05'),
(47, NULL, NULL, NULL, 2, 55, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:05'),
(48, NULL, NULL, NULL, 2, 56, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:05'),
(49, NULL, NULL, NULL, 2, 57, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:05'),
(50, NULL, NULL, NULL, 2, 58, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:05'),
(51, NULL, NULL, NULL, 2, 59, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:05'),
(52, NULL, NULL, NULL, 2, 60, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:05'),
(53, NULL, NULL, NULL, 2, 61, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:05'),
(54, NULL, NULL, NULL, 2, 62, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:05'),
(55, NULL, NULL, NULL, 2, 63, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:05'),
(56, NULL, NULL, NULL, 2, 64, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:05'),
(57, NULL, NULL, NULL, 2, 65, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:05'),
(58, NULL, NULL, NULL, 2, 66, 0, '2026-08-15 15:33:00', '2026-08-15 15:33:00'),
(59, NULL, NULL, NULL, 2, 67, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:06'),
(60, NULL, NULL, NULL, 2, 68, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:06'),
(61, NULL, NULL, NULL, 2, 69, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:06'),
(62, NULL, NULL, NULL, 2, 70, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:06'),
(63, NULL, NULL, NULL, 2, 71, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:06'),
(64, NULL, NULL, NULL, 2, 72, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:06'),
(65, NULL, NULL, NULL, 2, 73, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:06'),
(66, NULL, NULL, NULL, 2, 74, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:06'),
(67, NULL, NULL, NULL, 2, 75, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:06'),
(68, NULL, NULL, NULL, 2, 76, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:06'),
(69, NULL, NULL, NULL, 2, 77, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:06'),
(70, NULL, NULL, NULL, 2, 78, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:06'),
(71, NULL, NULL, NULL, 2, 79, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:06'),
(72, NULL, NULL, NULL, 2, 80, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:06'),
(73, NULL, NULL, NULL, 2, 81, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:06'),
(74, NULL, NULL, NULL, 2, 82, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:06'),
(75, NULL, NULL, NULL, 2, 83, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:06'),
(76, NULL, NULL, NULL, 2, 84, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:06'),
(77, NULL, NULL, NULL, 2, 85, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:06'),
(78, NULL, NULL, NULL, 2, 86, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:06'),
(79, NULL, NULL, NULL, 2, 87, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:07'),
(80, NULL, NULL, NULL, 2, 88, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:07'),
(81, NULL, NULL, NULL, 2, 89, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:07'),
(82, NULL, NULL, NULL, 2, 90, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:07'),
(83, NULL, NULL, NULL, 2, 91, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:07'),
(84, NULL, NULL, NULL, 2, 92, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:07'),
(85, NULL, NULL, NULL, 2, 93, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:07'),
(86, NULL, NULL, NULL, 2, 94, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:07'),
(87, NULL, NULL, NULL, 2, 95, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:07'),
(88, NULL, NULL, NULL, 2, 96, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:07'),
(89, NULL, NULL, NULL, 2, 99, 0, '2026-08-15 15:33:00', '2026-08-15 15:33:00'),
(90, NULL, NULL, NULL, 2, 100, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:08'),
(91, NULL, NULL, NULL, 2, 101, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:08'),
(92, NULL, NULL, NULL, 2, 102, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:08'),
(93, NULL, NULL, NULL, 2, 103, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:08'),
(94, NULL, NULL, NULL, 2, 104, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:08'),
(95, NULL, NULL, NULL, 2, 105, 0, '2026-08-15 15:33:00', '2026-08-15 15:33:00'),
(96, NULL, NULL, NULL, 2, 106, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:08'),
(97, NULL, NULL, NULL, 2, 107, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:08'),
(98, NULL, NULL, NULL, 2, 108, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:08'),
(99, NULL, NULL, NULL, 2, 109, 1, '2026-08-15 15:33:00', '2026-08-15 15:33:08'),
(100, NULL, NULL, NULL, 2, 110, 1, '2026-08-15 15:33:01', '2026-08-15 15:33:08'),
(101, NULL, NULL, NULL, 2, 111, 1, '2026-08-15 15:33:01', '2026-08-15 15:33:08'),
(102, NULL, NULL, NULL, 2, 112, 1, '2026-08-15 15:33:01', '2026-08-15 15:33:08'),
(103, NULL, NULL, NULL, 2, 113, 1, '2026-08-15 15:33:01', '2026-08-15 15:33:08'),
(104, NULL, NULL, NULL, 2, 114, 1, '2026-08-15 15:33:01', '2026-08-15 15:33:08'),
(105, NULL, NULL, NULL, 2, 115, 1, '2026-08-15 15:33:01', '2026-08-15 15:33:08'),
(106, NULL, NULL, NULL, 2, 116, 0, '2026-08-15 15:33:01', '2026-08-15 15:33:01'),
(107, NULL, NULL, NULL, 2, 117, 1, '2026-08-15 15:33:01', '2026-08-15 15:33:20'),
(108, NULL, NULL, NULL, 2, 118, 1, '2026-08-15 15:33:01', '2026-08-15 15:33:20'),
(109, NULL, NULL, NULL, 2, 119, 1, '2026-08-15 15:33:01', '2026-08-15 15:33:20'),
(110, NULL, NULL, NULL, 2, 120, 1, '2026-08-15 15:33:01', '2026-08-15 15:33:20'),
(111, NULL, NULL, NULL, 2, 121, 1, '2026-08-15 15:33:01', '2026-08-15 15:33:20'),
(112, NULL, NULL, NULL, 2, 122, 1, '2026-08-15 15:33:01', '2026-08-15 15:33:20'),
(113, NULL, NULL, NULL, 2, 123, 1, '2026-08-15 15:33:01', '2026-08-15 15:33:20'),
(114, NULL, NULL, NULL, 2, 124, 1, '2026-08-15 15:33:01', '2026-08-15 15:33:20'),
(115, NULL, NULL, NULL, 2, 125, 1, '2026-08-15 15:33:01', '2026-08-15 15:33:20'),
(116, NULL, NULL, NULL, 2, 126, 1, '2026-08-15 15:33:01', '2026-08-15 15:33:20'),
(117, NULL, NULL, NULL, 2, 127, 1, '2026-08-15 15:33:01', '2026-08-15 15:33:20'),
(118, NULL, NULL, NULL, 2, 128, 1, '2026-08-15 15:33:01', '2026-08-15 15:33:08'),
(119, NULL, NULL, NULL, 2, 129, 1, '2026-08-15 15:33:01', '2026-08-15 15:33:08'),
(120, NULL, NULL, NULL, 2, 130, 1, '2026-08-15 15:33:01', '2026-08-15 15:33:08'),
(121, NULL, NULL, NULL, 2, 131, 1, '2026-08-15 15:33:01', '2026-08-15 15:33:08'),
(122, NULL, NULL, NULL, 2, 132, 1, '2026-08-15 15:33:01', '2026-08-15 15:33:08'),
(123, NULL, NULL, NULL, 2, 133, 1, '2026-08-15 15:33:01', '2026-08-15 15:33:20'),
(124, NULL, NULL, NULL, 2, 134, 1, '2026-08-15 15:33:01', '2026-08-15 15:33:20'),
(125, NULL, NULL, NULL, 2, 135, 1, '2026-08-15 15:33:01', '2026-08-15 15:33:20'),
(126, NULL, NULL, NULL, 2, 136, 1, '2026-08-15 15:33:01', '2026-08-15 15:33:20'),
(127, NULL, NULL, NULL, 2, 137, 1, '2026-08-15 15:33:01', '2026-08-15 15:33:05'),
(128, NULL, NULL, NULL, 2, 138, 1, '2026-08-15 15:33:01', '2026-08-15 15:33:02'),
(129, NULL, NULL, NULL, 2, 139, 1, '2026-08-15 15:33:01', '2026-08-15 15:33:05'),
(130, NULL, NULL, NULL, 2, 140, 1, '2026-08-15 15:33:01', '2026-08-15 15:33:02'),
(131, NULL, NULL, NULL, 2, 141, 1, '2026-08-15 15:33:01', '2026-08-15 15:33:02'),
(132, NULL, NULL, NULL, 2, 142, 1, '2026-08-15 15:33:01', '2026-08-15 15:33:02'),
(133, NULL, NULL, NULL, 2, 143, 1, '2026-08-15 15:33:01', '2026-08-15 15:33:02'),
(134, NULL, NULL, NULL, 2, 144, 1, '2026-08-15 15:33:01', '2026-08-15 15:33:02'),
(135, NULL, NULL, NULL, 2, 145, 1, '2026-08-15 15:33:01', '2026-08-15 15:33:02'),
(136, NULL, NULL, NULL, 2, 146, 1, '2026-08-15 15:33:01', '2026-08-15 15:33:02'),
(137, NULL, NULL, NULL, 2, 147, 1, '2026-08-15 15:33:01', '2026-08-15 15:33:02'),
(138, NULL, NULL, NULL, 2, 148, 1, '2026-08-15 15:33:01', '2026-08-15 15:33:02'),
(139, NULL, NULL, NULL, 2, 149, 1, '2026-08-15 15:33:01', '2026-08-15 15:33:02'),
(140, NULL, NULL, NULL, 2, 150, 1, '2026-08-15 15:33:01', '2026-08-15 15:33:02'),
(141, NULL, NULL, NULL, 2, 151, 1, '2026-08-15 15:33:01', '2026-08-15 15:33:02'),
(142, NULL, NULL, NULL, 2, 152, 1, '2026-08-15 15:33:01', '2026-08-15 15:33:02'),
(143, NULL, NULL, NULL, 2, 156, 0, '2026-08-15 15:55:18', '2026-08-15 15:58:25'),
(144, NULL, NULL, NULL, 2, 157, 0, '2026-08-15 15:55:20', '2026-08-15 15:58:22'),
(145, NULL, NULL, NULL, 2, 158, 0, '2026-08-15 15:55:23', '2026-08-15 15:58:19'),
(146, 1, 1, NULL, 3, 2, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(147, 1, 1, NULL, 3, 3, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(148, 1, 1, NULL, 3, 4, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(149, 1, 1, NULL, 3, 7, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(150, 1, 1, NULL, 3, 17, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(151, 1, 1, NULL, 3, 18, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(152, 1, 1, NULL, 3, 19, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(153, 1, 1, NULL, 3, 20, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(154, 1, 1, NULL, 3, 22, 1, '2026-08-15 16:12:05', '2026-08-15 16:12:06'),
(155, 1, 1, NULL, 3, 23, 1, '2026-08-15 16:12:05', '2026-08-15 16:12:06'),
(156, 1, 1, NULL, 3, 24, 1, '2026-08-15 16:12:05', '2026-08-15 16:12:06'),
(157, 1, 1, NULL, 3, 25, 1, '2026-08-15 16:12:05', '2026-08-15 16:12:06'),
(158, 1, 1, NULL, 3, 26, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(159, 1, 1, NULL, 3, 27, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(160, 1, 1, NULL, 3, 28, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(161, 1, 1, NULL, 3, 29, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(162, 1, 1, NULL, 3, 30, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(163, 1, 1, NULL, 3, 31, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(164, 1, 1, NULL, 3, 32, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(165, 1, 1, NULL, 3, 33, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(166, 1, 1, NULL, 3, 34, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(167, 1, 1, NULL, 3, 35, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(168, 1, 1, NULL, 3, 36, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(169, 1, 1, NULL, 3, 37, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(170, 1, 1, NULL, 3, 38, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(171, 1, 1, NULL, 3, 39, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(172, 1, 1, NULL, 3, 40, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(173, 1, 1, NULL, 3, 41, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(174, 1, 1, NULL, 3, 42, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(175, 1, 1, NULL, 3, 43, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(176, 1, 1, NULL, 3, 44, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(177, 1, 1, NULL, 3, 45, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(178, 1, 1, NULL, 3, 46, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(179, 1, 1, NULL, 3, 47, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(180, 1, 1, NULL, 3, 48, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(181, 1, 1, NULL, 3, 49, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(182, 1, 1, NULL, 3, 50, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(183, 1, 1, NULL, 3, 51, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(184, 1, 1, NULL, 3, 52, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(185, 1, 1, NULL, 3, 53, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(186, 1, 1, NULL, 3, 54, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(187, 1, 1, NULL, 3, 55, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(188, 1, 1, NULL, 3, 56, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(189, 1, 1, NULL, 3, 57, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(190, 1, 1, NULL, 3, 58, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(191, 1, 1, NULL, 3, 59, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(192, 1, 1, NULL, 3, 60, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(193, 1, 1, NULL, 3, 61, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(194, 1, 1, NULL, 3, 62, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(195, 1, 1, NULL, 3, 63, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(196, 1, 1, NULL, 3, 64, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(197, 1, 1, NULL, 3, 65, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(198, 1, 1, NULL, 3, 66, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(199, 1, 1, NULL, 3, 67, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(200, 1, 1, NULL, 3, 68, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(201, 1, 1, NULL, 3, 69, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(202, 1, 1, NULL, 3, 70, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(203, 1, 1, NULL, 3, 71, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(204, 1, 1, NULL, 3, 72, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(205, 1, 1, NULL, 3, 73, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(206, 1, 1, NULL, 3, 74, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(207, 1, 1, NULL, 3, 75, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(208, 1, 1, NULL, 3, 76, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(209, 1, 1, NULL, 3, 77, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(210, 1, 1, NULL, 3, 78, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(211, 1, 1, NULL, 3, 79, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(212, 1, 1, NULL, 3, 80, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(213, 1, 1, NULL, 3, 81, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(214, 1, 1, NULL, 3, 82, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(215, 1, 1, NULL, 3, 83, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(216, 1, 1, NULL, 3, 84, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(217, 1, 1, NULL, 3, 85, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(218, 1, 1, NULL, 3, 86, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(219, 1, 1, NULL, 3, 87, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(220, 1, 1, NULL, 3, 88, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(221, 1, 1, NULL, 3, 89, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(222, 1, 1, NULL, 3, 90, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(223, 1, 1, NULL, 3, 91, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(224, 1, 1, NULL, 3, 92, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(225, 1, 1, NULL, 3, 93, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(226, 1, 1, NULL, 3, 94, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(227, 1, 1, NULL, 3, 95, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(228, 1, 1, NULL, 3, 96, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(229, 1, 1, NULL, 3, 99, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(230, 1, 1, NULL, 3, 100, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(231, 1, 1, NULL, 3, 101, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(232, 1, 1, NULL, 3, 102, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(233, 1, 1, NULL, 3, 103, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(234, 1, 1, NULL, 3, 104, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(235, 1, 1, NULL, 3, 105, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(236, 1, 1, NULL, 3, 106, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(237, 1, 1, NULL, 3, 107, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(238, 1, 1, NULL, 3, 108, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(239, 1, 1, NULL, 3, 109, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(240, 1, 1, NULL, 3, 110, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(241, 1, 1, NULL, 3, 111, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(242, 1, 1, NULL, 3, 112, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(243, 1, 1, NULL, 3, 113, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(244, 1, 1, NULL, 3, 114, 0, '2026-08-15 16:12:05', '2026-08-15 16:12:05'),
(245, 1, 1, NULL, 3, 115, 0, '2026-08-15 16:12:06', '2026-08-15 16:12:06'),
(246, 1, 1, NULL, 3, 116, 0, '2026-08-15 16:12:06', '2026-08-15 16:12:06'),
(247, 1, 1, NULL, 3, 117, 0, '2026-08-15 16:12:06', '2026-08-15 16:12:06'),
(248, 1, 1, NULL, 3, 118, 0, '2026-08-15 16:12:06', '2026-08-15 16:12:06'),
(249, 1, 1, NULL, 3, 119, 0, '2026-08-15 16:12:06', '2026-08-15 16:12:06'),
(250, 1, 1, NULL, 3, 120, 0, '2026-08-15 16:12:06', '2026-08-15 16:12:06'),
(251, 1, 1, NULL, 3, 121, 0, '2026-08-15 16:12:06', '2026-08-15 16:12:06'),
(252, 1, 1, NULL, 3, 122, 0, '2026-08-15 16:12:06', '2026-08-15 16:12:06'),
(253, 1, 1, NULL, 3, 123, 0, '2026-08-15 16:12:06', '2026-08-15 16:12:06'),
(254, 1, 1, NULL, 3, 124, 0, '2026-08-15 16:12:06', '2026-08-15 16:12:06'),
(255, 1, 1, NULL, 3, 125, 0, '2026-08-15 16:12:06', '2026-08-15 16:12:06'),
(256, 1, 1, NULL, 3, 126, 0, '2026-08-15 16:12:06', '2026-08-15 16:12:06'),
(257, 1, 1, NULL, 3, 127, 0, '2026-08-15 16:12:06', '2026-08-15 16:12:06'),
(258, 1, 1, NULL, 3, 128, 0, '2026-08-15 16:12:06', '2026-08-15 16:12:06'),
(259, 1, 1, NULL, 3, 129, 0, '2026-08-15 16:12:06', '2026-08-15 16:12:06'),
(260, 1, 1, NULL, 3, 130, 0, '2026-08-15 16:12:06', '2026-08-15 16:12:06'),
(261, 1, 1, NULL, 3, 131, 0, '2026-08-15 16:12:06', '2026-08-15 16:12:06'),
(262, 1, 1, NULL, 3, 132, 0, '2026-08-15 16:12:06', '2026-08-15 16:12:06'),
(263, 1, 1, NULL, 3, 133, 0, '2026-08-15 16:12:06', '2026-08-15 16:12:06'),
(264, 1, 1, NULL, 3, 134, 0, '2026-08-15 16:12:06', '2026-08-15 16:12:06'),
(265, 1, 1, NULL, 3, 135, 0, '2026-08-15 16:12:06', '2026-08-15 16:12:06'),
(266, 1, 1, NULL, 3, 136, 0, '2026-08-15 16:12:06', '2026-08-15 16:12:06'),
(267, 1, 1, NULL, 3, 137, 0, '2026-08-15 16:12:06', '2026-08-15 16:12:06'),
(268, 1, 1, NULL, 3, 138, 0, '2026-08-15 16:12:06', '2026-08-15 16:12:06'),
(269, 1, 1, NULL, 3, 139, 0, '2026-08-15 16:12:06', '2026-08-15 16:12:06'),
(270, 1, 1, NULL, 3, 140, 0, '2026-08-15 16:12:06', '2026-08-15 16:12:06'),
(271, 1, 1, NULL, 3, 141, 0, '2026-08-15 16:12:06', '2026-08-15 16:12:06'),
(272, 1, 1, NULL, 3, 142, 0, '2026-08-15 16:12:06', '2026-08-15 16:12:06'),
(273, 1, 1, NULL, 3, 143, 0, '2026-08-15 16:12:06', '2026-08-15 16:12:06'),
(274, 1, 1, NULL, 3, 144, 0, '2026-08-15 16:12:06', '2026-08-15 16:12:06'),
(275, 1, 1, NULL, 3, 145, 0, '2026-08-15 16:12:06', '2026-08-15 16:12:06'),
(276, 1, 1, NULL, 3, 146, 0, '2026-08-15 16:12:06', '2026-08-15 16:12:06'),
(277, 1, 1, NULL, 3, 147, 0, '2026-08-15 16:12:06', '2026-08-15 16:12:06'),
(278, 1, 1, NULL, 3, 148, 0, '2026-08-15 16:12:06', '2026-08-15 16:12:06'),
(279, 1, 1, NULL, 3, 149, 0, '2026-08-15 16:12:06', '2026-08-15 16:12:06'),
(280, 1, 1, NULL, 3, 150, 0, '2026-08-15 16:12:06', '2026-08-15 16:12:06'),
(281, 1, 1, NULL, 3, 151, 0, '2026-08-15 16:12:06', '2026-08-15 16:12:06'),
(282, 1, 1, NULL, 3, 152, 0, '2026-08-15 16:12:06', '2026-08-15 16:12:06'),
(283, 1, 1, NULL, 3, 153, 0, '2026-08-15 16:12:06', '2026-08-15 16:12:06'),
(284, 1, 1, NULL, 3, 154, 0, '2026-08-15 16:12:06', '2026-08-15 16:12:06'),
(285, 1, 1, NULL, 3, 155, 0, '2026-08-15 16:12:06', '2026-08-15 16:12:06'),
(286, 1, 1, NULL, 3, 156, 0, '2026-08-15 16:12:06', '2026-08-15 16:12:06'),
(287, 1, 1, NULL, 3, 157, 0, '2026-08-15 16:12:06', '2026-08-15 16:12:06'),
(288, 1, 1, NULL, 3, 158, 0, '2026-08-15 16:12:06', '2026-08-15 16:12:06');

-- --------------------------------------------------------

--
-- Table structure for table `personal_access_tokens`
--

CREATE TABLE `personal_access_tokens` (
  `id` bigint UNSIGNED NOT NULL,
  `tokenable_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tokenable_id` bigint UNSIGNED NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `abilities` text COLLATE utf8mb4_unicode_ci,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `id` bigint UNSIGNED NOT NULL,
  `company_id` bigint UNSIGNED DEFAULT NULL,
  `unit_id` bigint UNSIGNED DEFAULT NULL,
  `brand_id` bigint UNSIGNED DEFAULT NULL,
  `category_id` bigint UNSIGNED DEFAULT NULL,
  `subcategory_id` bigint UNSIGNED DEFAULT NULL,
  `itemtype_id` bigint UNSIGNED DEFAULT NULL,
  `warranty_id` bigint UNSIGNED DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `alert_qty` int DEFAULT NULL,
  `sku` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `weight` int DEFAULT NULL,
  `product_desc` text COLLATE utf8mb4_unicode_ci,
  `product_image` text COLLATE utf8mb4_unicode_ci,
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `type` enum('single','variable') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'single',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `product_details`
--

CREATE TABLE `product_details` (
  `id` bigint UNSIGNED NOT NULL,
  `product_id` bigint UNSIGNED DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sku` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `variation_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `default_purchase_price` double NOT NULL,
  `dpp_unit_price` double NOT NULL,
  `largequantity` int NOT NULL,
  `smallquantity` int NOT NULL,
  `profit_percent` double NOT NULL,
  `default_sell_price` double NOT NULL,
  `variation_image` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_lines`
--

CREATE TABLE `purchase_lines` (
  `id` bigint UNSIGNED NOT NULL,
  `transaction_id` bigint UNSIGNED DEFAULT NULL,
  `product_id` bigint UNSIGNED DEFAULT NULL,
  `variation_id` bigint UNSIGNED DEFAULT NULL,
  `itemtype_id` bigint UNSIGNED DEFAULT NULL,
  `unit_id` bigint UNSIGNED DEFAULT NULL,
  `quantity` double NOT NULL DEFAULT '0',
  `quantity_received` double NOT NULL DEFAULT '0',
  `qunatity_sold` double NOT NULL DEFAULT '0',
  `quantity_returned` double NOT NULL DEFAULT '0',
  `purchase_rate` double NOT NULL DEFAULT '0',
  `default_sell_price` double NOT NULL DEFAULT '0',
  `discount_percent` double NOT NULL DEFAULT '0',
  `margin` double NOT NULL DEFAULT '0',
  `quantity_adjustment` double NOT NULL DEFAULT '0',
  `pp_without_discount` double NOT NULL DEFAULT '0',
  `packing_qty` int NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_sell_lines`
--

CREATE TABLE `purchase_sell_lines` (
  `id` bigint UNSIGNED NOT NULL,
  `purchaseline_id` bigint UNSIGNED DEFAULT NULL,
  `sellline_id` bigint UNSIGNED DEFAULT NULL,
  `quantity` double NOT NULL DEFAULT '0',
  `quantity_returned` double NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `id` bigint UNSIGNED NOT NULL,
  `company_id` bigint UNSIGNED DEFAULT NULL,
  `branch_id` bigint UNSIGNED DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `is_admin` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`id`, `company_id`, `branch_id`, `name`, `is_active`, `is_admin`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, NULL, NULL, 'superadmin', 1, 1, '2026-08-14 13:05:31', '2026-08-14 13:05:31', NULL),
(2, NULL, NULL, 'companyadmin', 1, 0, '2022-11-02 12:19:16', '2022-11-02 12:19:16', NULL),
(3, 1, 1, 'test role', 1, 0, '2026-08-15 15:55:57', '2026-08-15 15:55:57', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `sell_lines`
--

CREATE TABLE `sell_lines` (
  `id` bigint UNSIGNED NOT NULL,
  `transaction_id` bigint UNSIGNED DEFAULT NULL,
  `product_id` bigint UNSIGNED DEFAULT NULL,
  `variation_id` bigint UNSIGNED DEFAULT NULL,
  `itemtype_id` bigint UNSIGNED DEFAULT NULL,
  `unit_id` bigint UNSIGNED DEFAULT NULL,
  `quantity` double NOT NULL DEFAULT '0',
  `quantity_issue` double NOT NULL DEFAULT '0',
  `quantity_returned` double NOT NULL DEFAULT '0',
  `unit_price` double NOT NULL DEFAULT '0',
  `discount_percent` double NOT NULL DEFAULT '0',
  `unit_price_after_discount` double NOT NULL DEFAULT '0',
  `subtotal` double NOT NULL DEFAULT '0',
  `packing_qty` int NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sessions`
--

CREATE TABLE `sessions` (
  `id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint UNSIGNED DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_activity` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sessions`
--

INSERT INTO `sessions` (`id`, `user_id`, `ip_address`, `user_agent`, `payload`, `last_activity`) VALUES
('RKZT2ndOyAvSyWRdpKkUrxzW6DddgTaddDSkOCCa', 1, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36', 'eyJfdG9rZW4iOiJNVVdlQmE0c0tycXlYakNBVGVnTlU3QXJTelE4cUhyWTJEVjdXMGFUIiwiX3ByZXZpb3VzIjp7InVybCI6Imh0dHBzOlwvXC9uZXdfYWNjb3VudGluZy50ZXN0XC9kYXNoYm9hcmQiLCJyb3V0ZSI6ImRhc2hib2FyZCJ9LCJfZmxhc2giOnsib2xkIjpbXSwibmV3IjpbXX0sImxvZ2luX3dlYl81OWJhMzZhZGRjMmIyZjk0MDE1ODBmMDE0YzdmNThlYTRlMzA5ODlkIjoxLCJwYXNzd29yZF9oYXNoX3dlYiI6Ijg4NDMyZGQ4MTcyMDA0NjM4NDQ1YTRkY2Y1OWUzNWRiM2RmZTliMDI1NzY2NzZlMjQxMzcyMTEwYjUxOGJhNzkifQ==', 1786918704),
('sORVXYL3YnE0YvcjridS1v5sl81SjbsOJmLQqfbH', 2, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36', 'eyJfdG9rZW4iOiJCRTl4dlI2Z0VwSW93Q292TDFiWWVXWE91Y3p5ajRlZzB5SnVvTWVEIiwidXJsIjp7ImludGVuZGVkIjoiaHR0cHM6XC9cL25ld19hY2NvdW50aW5nLnRlc3RcL2Rhc2hib2FyZCJ9LCJfcHJldmlvdXMiOnsidXJsIjoiaHR0cHM6XC9cL25ld19hY2NvdW50aW5nLnRlc3RcL2Rhc2hib2FyZCIsInJvdXRlIjoiZGFzaGJvYXJkIn0sIl9mbGFzaCI6eyJvbGQiOltdLCJuZXciOltdfSwibG9naW5fd2ViXzU5YmEzNmFkZGMyYjJmOTQwMTU4MGYwMTRjN2Y1OGVhNGUzMDk4OWQiOjIsInBhc3N3b3JkX2hhc2hfd2ViIjoiNjc2ZmRlNGE5ZTZkMzc5ZGY4ZWNhMjRjYWZmMTlkMzcyOTgyMjhiMWExNWVhZTIzZTkxOGY2NWQ1NGIxNjU4MiJ9', 1786918708);

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `id` bigint UNSIGNED NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_no` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` text COLLATE utf8mb4_unicode_ci,
  `smtp_host` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `smtp_port` int DEFAULT NULL,
  `smtp_username` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `smtp_password` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `smtp_encryption` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `smtp_from_address` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `smtp_from_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `system_logo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `states`
--

CREATE TABLE `states` (
  `id` mediumint UNSIGNED NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `country_id` mediumint UNSIGNED NOT NULL,
  `country_code` char(2) COLLATE utf8mb4_unicode_ci NOT NULL,
  `fips_code` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `iso2` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `type` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `level` int DEFAULT NULL,
  `parent_id` int DEFAULT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `flag` tinyint(1) NOT NULL DEFAULT '1',
  `wikiDataId` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `taxes`
--

CREATE TABLE `taxes` (
  `id` bigint UNSIGNED NOT NULL,
  `company_id` bigint UNSIGNED DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `percentage` int NOT NULL,
  `sub_tax` text COLLATE utf8mb4_unicode_ci,
  `type` int NOT NULL DEFAULT '0',
  `status` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `timezones`
--

CREATE TABLE `timezones` (
  `id` bigint UNSIGNED NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `deleted_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `transactions`
--

CREATE TABLE `transactions` (
  `id` bigint UNSIGNED NOT NULL,
  `company_id` bigint UNSIGNED DEFAULT NULL,
  `branch_id` bigint UNSIGNED DEFAULT NULL,
  `tobranch_id` bigint UNSIGNED DEFAULT NULL,
  `contact_id` bigint UNSIGNED DEFAULT NULL,
  `opening_stock_product_id` bigint UNSIGNED DEFAULT NULL,
  `parent_id` bigint UNSIGNED DEFAULT NULL,
  `tax_id` bigint UNSIGNED DEFAULT NULL,
  `transporter_id` bigint UNSIGNED DEFAULT NULL,
  `created_by` bigint UNSIGNED DEFAULT NULL,
  `approved_by` bigint UNSIGNED DEFAULT NULL,
  `direct_contact_id` bigint UNSIGNED DEFAULT NULL,
  `total_item` int DEFAULT NULL,
  `pay_term` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `invoice_no` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `shipping_details` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `delivered_to` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `billty_no` text COLLATE utf8mb4_unicode_ci,
  `billty_image` text COLLATE utf8mb4_unicode_ci,
  `shipping_address` text COLLATE utf8mb4_unicode_ci,
  `additional_note` text COLLATE utf8mb4_unicode_ci,
  `packing` text COLLATE utf8mb4_unicode_ci,
  `attachment` text COLLATE utf8mb4_unicode_ci,
  `transaction_date` datetime DEFAULT NULL,
  `approved_date` date DEFAULT NULL,
  `pay_type` enum('month','day','year') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'day',
  `status` enum('received','pending','ordered','draft','final','issue','approved','in_transit','completed','quotation') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `payment_status` enum('paid','due','partial') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'due',
  `adjustment_type` enum('normal','abnormal','unboxing','opening') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'normal',
  `discount_type` enum('fixed','percentage','none') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `type` enum('opening_stock','purchaseorder','sell','issue_note','adjustment','transfer','purchasereturn','recieving_note','salereturn') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `shipping_status` enum('ordered','packed','shipped','delivered','cancelled') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `total_before_tax` double DEFAULT NULL,
  `tax_amount` double DEFAULT NULL,
  `discount_amount` double DEFAULT NULL,
  `shipping_charges` double DEFAULT NULL,
  `final_amount` double DEFAULT NULL,
  `link_account` tinyint(1) NOT NULL DEFAULT '0',
  `is_direct` tinyint(1) NOT NULL DEFAULT '0',
  `is_print` tinyint(1) NOT NULL DEFAULT '0',
  `is_edit` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `t_accounts`
--

CREATE TABLE `t_accounts` (
  `id` bigint UNSIGNED NOT NULL,
  `company_id` bigint UNSIGNED DEFAULT NULL,
  `branch_id` bigint UNSIGNED DEFAULT NULL,
  `coa_id` bigint UNSIGNED DEFAULT NULL,
  `received_id` bigint UNSIGNED DEFAULT NULL,
  `created_by` bigint UNSIGNED DEFAULT NULL,
  `approved_by` bigint UNSIGNED DEFAULT NULL,
  `cancelled_by` bigint UNSIGNED DEFAULT NULL,
  `printed_by` bigint UNSIGNED DEFAULT NULL,
  `issuer_id` bigint UNSIGNED DEFAULT NULL,
  `account_code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `voucher_no` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ref_no` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `cheque_no` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `cheque_post_date` date DEFAULT NULL,
  `voucher_date` date DEFAULT NULL,
  `total_amount` double DEFAULT NULL,
  `total_tax` double DEFAULT NULL,
  `net_total` double DEFAULT NULL,
  `is_print` tinyint(1) NOT NULL DEFAULT '0',
  `comments` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('pending','approved','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `type` enum('online','bank','cash') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'online',
  `printed_at` datetime DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `t_account_details`
--

CREATE TABLE `t_account_details` (
  `id` bigint UNSIGNED NOT NULL,
  `t_account_id` bigint UNSIGNED DEFAULT NULL,
  `branch_id` bigint UNSIGNED DEFAULT NULL,
  `coa_id` bigint UNSIGNED DEFAULT NULL,
  `contact_id` bigint UNSIGNED DEFAULT NULL,
  `account_code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `acc_nature` enum('cr','dr') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cr',
  `credit` double DEFAULT NULL,
  `debit` double DEFAULT NULL,
  `highlight` tinyint(1) NOT NULL DEFAULT '0',
  `amount` double DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `units`
--

CREATE TABLE `units` (
  `id` bigint UNSIGNED NOT NULL,
  `company_id` bigint UNSIGNED DEFAULT NULL,
  `parent_id` bigint UNSIGNED DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `short_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` enum('large','small') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'large',
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `auto_adjustment` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` bigint UNSIGNED NOT NULL,
  `company_id` bigint UNSIGNED DEFAULT NULL,
  `branch_id` bigint UNSIGNED DEFAULT NULL,
  `department_id` bigint UNSIGNED DEFAULT NULL,
  `role_id` bigint UNSIGNED DEFAULT NULL,
  `first_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `username` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `two_factor_secret` text COLLATE utf8mb4_unicode_ci,
  `two_factor_recovery_codes` text COLLATE utf8mb4_unicode_ci,
  `two_factor_confirmed_at` timestamp NULL DEFAULT NULL,
  `pass` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `user_image` text COLLATE utf8mb4_unicode_ci,
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `company_id`, `branch_id`, `department_id`, `role_id`, `first_name`, `last_name`, `username`, `email`, `phone`, `email_verified_at`, `password`, `two_factor_secret`, `two_factor_recovery_codes`, `two_factor_confirmed_at`, `pass`, `is_active`, `user_image`, `created_by`, `updated_by`, `remember_token`, `deleted_at`, `created_at`, `updated_at`) VALUES
(1, NULL, NULL, NULL, 1, 'super', 'admin', 'superadmin', 'admin@yoursite.com', NULL, NULL, '$2y$12$9Rci74lxaul0EzTZJ0I.j.K8Nzh3dYFOdp/dzsDvyF5EFtM3FABVO', NULL, NULL, NULL, 'admin123', 1, NULL, NULL, NULL, NULL, NULL, '2026-08-14 13:05:33', '2026-08-14 13:05:33'),
(2, 1, NULL, NULL, 2, 'Bilal', 'Younus', 'bilalyounus', 'bilalyounus1974@gmail.com', '+923337034390', NULL, '$2y$12$f4OuX0951sawqlwTJ6TjheOrrmizHB3i6IJkok8MVqAoncL8FkOwK', NULL, NULL, NULL, 'Bilal@1996', 1, NULL, 1, 1, NULL, NULL, '2026-08-15 15:34:38', '2026-08-15 15:34:38'),
(3, 1, 1, 1, 3, 'test', 'user', 'testuser', 'test@example.com', '+926666666666', NULL, '$2y$12$N30MXAz06EBdSvrf/Op7X.nwnPF89vaBTj.QCyKjAxrQTRZBE0hFe', NULL, NULL, NULL, 'f6GNn<7tD9\"6TV', 1, NULL, 2, 2, NULL, NULL, '2026-08-15 16:36:01', '2026-08-15 16:36:01');

-- --------------------------------------------------------

--
-- Table structure for table `variations`
--

CREATE TABLE `variations` (
  `id` bigint UNSIGNED NOT NULL,
  `company_id` bigint UNSIGNED DEFAULT NULL,
  `category_id` bigint UNSIGNED DEFAULT NULL,
  `subcategory_id` bigint UNSIGNED DEFAULT NULL,
  `itemtype_id` bigint UNSIGNED DEFAULT NULL,
  `values` text COLLATE utf8mb4_unicode_ci,
  `priority` int NOT NULL DEFAULT '0',
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `warranties`
--

CREATE TABLE `warranties` (
  `id` bigint UNSIGNED NOT NULL,
  `company_id` bigint UNSIGNED DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `duration` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` enum('month','day','year') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'day',
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `account_balances`
--
ALTER TABLE `account_balances`
  ADD PRIMARY KEY (`id`),
  ADD KEY `account_balances_company_id_index` (`company_id`),
  ADD KEY `account_balances_branch_id_index` (`branch_id`),
  ADD KEY `account_balances_financial_id_index` (`financial_id`),
  ADD KEY `account_balances_coa_id_index` (`coa_id`);

--
-- Indexes for table `banks`
--
ALTER TABLE `banks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `banks_company_id_index` (`company_id`),
  ADD KEY `banks_branch_id_index` (`branch_id`),
  ADD KEY `banks_country_id_index` (`country_id`),
  ADD KEY `banks_state_id_index` (`state_id`),
  ADD KEY `banks_city_id_index` (`city_id`);

--
-- Indexes for table `branches`
--
ALTER TABLE `branches`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `branches_code_unique` (`code`),
  ADD KEY `branches_company_id_index` (`company_id`),
  ADD KEY `branches_country_id_index` (`country_id`),
  ADD KEY `branches_state_id_index` (`state_id`),
  ADD KEY `branches_city_id_index` (`city_id`);

--
-- Indexes for table `brands`
--
ALTER TABLE `brands`
  ADD PRIMARY KEY (`id`),
  ADD KEY `brands_company_id_index` (`company_id`);

--
-- Indexes for table `cache`
--
ALTER TABLE `cache`
  ADD PRIMARY KEY (`key`),
  ADD KEY `cache_expiration_index` (`expiration`);

--
-- Indexes for table `cache_locks`
--
ALTER TABLE `cache_locks`
  ADD PRIMARY KEY (`key`),
  ADD KEY `cache_locks_expiration_index` (`expiration`);

--
-- Indexes for table `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`),
  ADD KEY `categories_company_id_index` (`company_id`),
  ADD KEY `categories_parent_id_index` (`parent_id`);

--
-- Indexes for table `chart_of_accounts`
--
ALTER TABLE `chart_of_accounts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `chart_of_accounts_company_id_index` (`company_id`),
  ADD KEY `chart_of_accounts_branch_id_index` (`branch_id`),
  ADD KEY `chart_of_accounts_parent_id_index` (`parent_id`);

--
-- Indexes for table `chart_of_account_mappings`
--
ALTER TABLE `chart_of_account_mappings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `chart_of_account_mappings_company_id_index` (`company_id`),
  ADD KEY `chart_of_account_mappings_branch_id_index` (`branch_id`);

--
-- Indexes for table `cities`
--
ALTER TABLE `cities`
  ADD PRIMARY KEY (`id`),
  ADD KEY `cities_state_id_index` (`state_id`),
  ADD KEY `cities_country_id_index` (`country_id`);

--
-- Indexes for table `companies`
--
ALTER TABLE `companies`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `companies_code_unique` (`code`),
  ADD KEY `companies_ntn_no_index` (`ntn_no`),
  ADD KEY `companies_strn_no_index` (`strn_no`),
  ADD KEY `companies_gst_no_index` (`gst_no`),
  ADD KEY `companies_registration_no_index` (`registration_no`),
  ADD KEY `companies_country_id_index` (`country_id`),
  ADD KEY `companies_state_id_index` (`state_id`),
  ADD KEY `companies_city_id_index` (`city_id`);

--
-- Indexes for table `company_settings`
--
ALTER TABLE `company_settings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `company_settings_company_id_index` (`company_id`),
  ADD KEY `company_settings_currency_id_index` (`currency_id`),
  ADD KEY `company_settings_timezone_id_index` (`timezone_id`);

--
-- Indexes for table `contacts`
--
ALTER TABLE `contacts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `contacts_company_id_index` (`company_id`),
  ADD KEY `contacts_branch_id_index` (`branch_id`),
  ADD KEY `contacts_currency_id_index` (`currency_id`),
  ADD KEY `contacts_country_id_index` (`country_id`),
  ADD KEY `contacts_state_id_index` (`state_id`),
  ADD KEY `contacts_city_id_index` (`city_id`);

--
-- Indexes for table `countries`
--
ALTER TABLE `countries`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `currencies`
--
ALTER TABLE `currencies`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `departments_company_id_index` (`company_id`),
  ADD KEY `departments_branch_id_index` (`branch_id`);

--
-- Indexes for table `failed_jobs`
--
ALTER TABLE `failed_jobs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`),
  ADD KEY `failed_jobs_connection_queue_failed_at_index` (`connection`,`queue`,`failed_at`);

--
-- Indexes for table `financial_years`
--
ALTER TABLE `financial_years`
  ADD PRIMARY KEY (`id`),
  ADD KEY `financial_years_company_id_index` (`company_id`);

--
-- Indexes for table `item_types`
--
ALTER TABLE `item_types`
  ADD PRIMARY KEY (`id`),
  ADD KEY `item_types_company_id_index` (`company_id`);

--
-- Indexes for table `jobs`
--
ALTER TABLE `jobs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `jobs_queue_index` (`queue`);

--
-- Indexes for table `job_batches`
--
ALTER TABLE `job_batches`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `menus`
--
ALTER TABLE `menus`
  ADD PRIMARY KEY (`id`),
  ADD KEY `menus_parent_id_index` (`parent_id`);

--
-- Indexes for table `migrations`
--
ALTER TABLE `migrations`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `opening_stocks`
--
ALTER TABLE `opening_stocks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `opening_stocks_transaction_id_index` (`transaction_id`),
  ADD KEY `opening_stocks_product_id_index` (`product_id`),
  ADD KEY `opening_stocks_variation_id_index` (`variation_id`),
  ADD KEY `opening_stocks_branch_id_index` (`branch_id`),
  ADD KEY `opening_stocks_unit_id_index` (`unit_id`);

--
-- Indexes for table `passkeys`
--
ALTER TABLE `passkeys`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `passkeys_credential_id_unique` (`credential_id`),
  ADD KEY `passkeys_user_id_index` (`user_id`);

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD KEY `password_resets_email_index` (`email`);

--
-- Indexes for table `password_reset_tokens`
--
ALTER TABLE `password_reset_tokens`
  ADD PRIMARY KEY (`email`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `payments_company_id_index` (`company_id`),
  ADD KEY `payments_branch_id_index` (`branch_id`),
  ADD KEY `payments_transaction_id_index` (`transaction_id`),
  ADD KEY `payments_contact_id_index` (`contact_id`),
  ADD KEY `payments_payment_account_index` (`payment_account`);

--
-- Indexes for table `permissions`
--
ALTER TABLE `permissions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `permissions_company_id_index` (`company_id`),
  ADD KEY `permissions_branch_id_index` (`branch_id`),
  ADD KEY `permissions_department_id_index` (`department_id`),
  ADD KEY `permissions_role_id_index` (`role_id`),
  ADD KEY `permissions_menu_id_index` (`menu_id`);

--
-- Indexes for table `personal_access_tokens`
--
ALTER TABLE `personal_access_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  ADD KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD KEY `products_company_id_index` (`company_id`),
  ADD KEY `products_unit_id_index` (`unit_id`),
  ADD KEY `products_brand_id_index` (`brand_id`),
  ADD KEY `products_category_id_index` (`category_id`),
  ADD KEY `products_subcategory_id_index` (`subcategory_id`),
  ADD KEY `products_itemtype_id_index` (`itemtype_id`),
  ADD KEY `products_warranty_id_index` (`warranty_id`);

--
-- Indexes for table `product_details`
--
ALTER TABLE `product_details`
  ADD PRIMARY KEY (`id`),
  ADD KEY `product_details_product_id_index` (`product_id`);

--
-- Indexes for table `purchase_lines`
--
ALTER TABLE `purchase_lines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `purchase_lines_transaction_id_index` (`transaction_id`),
  ADD KEY `purchase_lines_product_id_index` (`product_id`),
  ADD KEY `purchase_lines_variation_id_index` (`variation_id`),
  ADD KEY `purchase_lines_itemtype_id_index` (`itemtype_id`),
  ADD KEY `purchase_lines_unit_id_index` (`unit_id`);

--
-- Indexes for table `purchase_sell_lines`
--
ALTER TABLE `purchase_sell_lines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `purchase_sell_lines_purchaseline_id_index` (`purchaseline_id`),
  ADD KEY `purchase_sell_lines_sellline_id_index` (`sellline_id`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD KEY `roles_company_id_index` (`company_id`),
  ADD KEY `roles_branch_id_index` (`branch_id`);

--
-- Indexes for table `sell_lines`
--
ALTER TABLE `sell_lines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sell_lines_transaction_id_index` (`transaction_id`),
  ADD KEY `sell_lines_product_id_index` (`product_id`),
  ADD KEY `sell_lines_variation_id_index` (`variation_id`),
  ADD KEY `sell_lines_itemtype_id_index` (`itemtype_id`),
  ADD KEY `sell_lines_unit_id_index` (`unit_id`);

--
-- Indexes for table `sessions`
--
ALTER TABLE `sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sessions_user_id_index` (`user_id`),
  ADD KEY `sessions_last_activity_index` (`last_activity`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `states`
--
ALTER TABLE `states`
  ADD PRIMARY KEY (`id`),
  ADD KEY `states_country_id_index` (`country_id`);

--
-- Indexes for table `taxes`
--
ALTER TABLE `taxes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `taxes_company_id_index` (`company_id`);

--
-- Indexes for table `timezones`
--
ALTER TABLE `timezones`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `transactions`
--
ALTER TABLE `transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `transactions_company_id_index` (`company_id`),
  ADD KEY `transactions_branch_id_index` (`branch_id`),
  ADD KEY `transactions_tobranch_id_index` (`tobranch_id`),
  ADD KEY `transactions_contact_id_index` (`contact_id`),
  ADD KEY `transactions_opening_stock_product_id_index` (`opening_stock_product_id`),
  ADD KEY `transactions_parent_id_index` (`parent_id`),
  ADD KEY `transactions_tax_id_index` (`tax_id`),
  ADD KEY `transactions_transporter_id_index` (`transporter_id`),
  ADD KEY `transactions_created_by_index` (`created_by`),
  ADD KEY `transactions_approved_by_index` (`approved_by`),
  ADD KEY `transactions_direct_contact_id_index` (`direct_contact_id`);

--
-- Indexes for table `t_accounts`
--
ALTER TABLE `t_accounts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `t_accounts_company_id_index` (`company_id`),
  ADD KEY `t_accounts_branch_id_index` (`branch_id`),
  ADD KEY `t_accounts_coa_id_index` (`coa_id`),
  ADD KEY `t_accounts_received_id_index` (`received_id`),
  ADD KEY `t_accounts_created_by_index` (`created_by`),
  ADD KEY `t_accounts_approved_by_index` (`approved_by`),
  ADD KEY `t_accounts_cancelled_by_index` (`cancelled_by`),
  ADD KEY `t_accounts_printed_by_index` (`printed_by`),
  ADD KEY `t_accounts_issuer_id_index` (`issuer_id`);

--
-- Indexes for table `t_account_details`
--
ALTER TABLE `t_account_details`
  ADD PRIMARY KEY (`id`),
  ADD KEY `t_account_details_t_account_id_index` (`t_account_id`),
  ADD KEY `t_account_details_branch_id_index` (`branch_id`),
  ADD KEY `t_account_details_coa_id_index` (`coa_id`),
  ADD KEY `t_account_details_contact_id_index` (`contact_id`);

--
-- Indexes for table `units`
--
ALTER TABLE `units`
  ADD PRIMARY KEY (`id`),
  ADD KEY `units_company_id_index` (`company_id`),
  ADD KEY `units_parent_id_index` (`parent_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `users_email_unique` (`email`),
  ADD KEY `users_company_id_index` (`company_id`),
  ADD KEY `users_branch_id_index` (`branch_id`),
  ADD KEY `users_department_id_index` (`department_id`),
  ADD KEY `users_role_id_index` (`role_id`);

--
-- Indexes for table `variations`
--
ALTER TABLE `variations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `variations_company_id_index` (`company_id`),
  ADD KEY `variations_category_id_index` (`category_id`),
  ADD KEY `variations_subcategory_id_index` (`subcategory_id`),
  ADD KEY `variations_itemtype_id_index` (`itemtype_id`);

--
-- Indexes for table `warranties`
--
ALTER TABLE `warranties`
  ADD PRIMARY KEY (`id`),
  ADD KEY `warranties_company_id_index` (`company_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `account_balances`
--
ALTER TABLE `account_balances`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `banks`
--
ALTER TABLE `banks`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `branches`
--
ALTER TABLE `branches`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `brands`
--
ALTER TABLE `brands`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `chart_of_accounts`
--
ALTER TABLE `chart_of_accounts`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `chart_of_account_mappings`
--
ALTER TABLE `chart_of_account_mappings`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `cities`
--
ALTER TABLE `cities`
  MODIFY `id` mediumint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `companies`
--
ALTER TABLE `companies`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `company_settings`
--
ALTER TABLE `company_settings`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `contacts`
--
ALTER TABLE `contacts`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `countries`
--
ALTER TABLE `countries`
  MODIFY `id` mediumint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `currencies`
--
ALTER TABLE `currencies`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `failed_jobs`
--
ALTER TABLE `failed_jobs`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `financial_years`
--
ALTER TABLE `financial_years`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `item_types`
--
ALTER TABLE `item_types`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `jobs`
--
ALTER TABLE `jobs`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `menus`
--
ALTER TABLE `menus`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=179;

--
-- AUTO_INCREMENT for table `migrations`
--
ALTER TABLE `migrations`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=49;

--
-- AUTO_INCREMENT for table `opening_stocks`
--
ALTER TABLE `opening_stocks`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `passkeys`
--
ALTER TABLE `passkeys`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `permissions`
--
ALTER TABLE `permissions`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=289;

--
-- AUTO_INCREMENT for table `personal_access_tokens`
--
ALTER TABLE `personal_access_tokens`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `product_details`
--
ALTER TABLE `product_details`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_lines`
--
ALTER TABLE `purchase_lines`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_sell_lines`
--
ALTER TABLE `purchase_sell_lines`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `sell_lines`
--
ALTER TABLE `sell_lines`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `settings`
--
ALTER TABLE `settings`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `states`
--
ALTER TABLE `states`
  MODIFY `id` mediumint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `taxes`
--
ALTER TABLE `taxes`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `timezones`
--
ALTER TABLE `timezones`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `transactions`
--
ALTER TABLE `transactions`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `t_accounts`
--
ALTER TABLE `t_accounts`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `t_account_details`
--
ALTER TABLE `t_account_details`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `units`
--
ALTER TABLE `units`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `variations`
--
ALTER TABLE `variations`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `warranties`
--
ALTER TABLE `warranties`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `account_balances`
--
ALTER TABLE `account_balances`
  ADD CONSTRAINT `account_balances_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `account_balances_coa_id_foreign` FOREIGN KEY (`coa_id`) REFERENCES `chart_of_accounts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `account_balances_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `account_balances_financial_id_foreign` FOREIGN KEY (`financial_id`) REFERENCES `financial_years` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `banks`
--
ALTER TABLE `banks`
  ADD CONSTRAINT `banks_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `banks_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `branches`
--
ALTER TABLE `branches`
  ADD CONSTRAINT `branches_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `brands`
--
ALTER TABLE `brands`
  ADD CONSTRAINT `brands_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `categories`
--
ALTER TABLE `categories`
  ADD CONSTRAINT `categories_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `categories_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `chart_of_accounts`
--
ALTER TABLE `chart_of_accounts`
  ADD CONSTRAINT `chart_of_accounts_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `chart_of_accounts_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `chart_of_accounts_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `chart_of_accounts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `chart_of_account_mappings`
--
ALTER TABLE `chart_of_account_mappings`
  ADD CONSTRAINT `chart_of_account_mappings_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `chart_of_account_mappings_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `cities`
--
ALTER TABLE `cities`
  ADD CONSTRAINT `cities_country_id_foreign` FOREIGN KEY (`country_id`) REFERENCES `countries` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `cities_state_id_foreign` FOREIGN KEY (`state_id`) REFERENCES `states` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `company_settings`
--
ALTER TABLE `company_settings`
  ADD CONSTRAINT `company_settings_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `company_settings_currency_id_foreign` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `company_settings_timezone_id_foreign` FOREIGN KEY (`timezone_id`) REFERENCES `timezones` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `contacts`
--
ALTER TABLE `contacts`
  ADD CONSTRAINT `contacts_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `contacts_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `contacts_currency_id_foreign` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `departments`
--
ALTER TABLE `departments`
  ADD CONSTRAINT `departments_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `departments_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `financial_years`
--
ALTER TABLE `financial_years`
  ADD CONSTRAINT `financial_years_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `item_types`
--
ALTER TABLE `item_types`
  ADD CONSTRAINT `item_types_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `menus`
--
ALTER TABLE `menus`
  ADD CONSTRAINT `menus_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `menus` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `opening_stocks`
--
ALTER TABLE `opening_stocks`
  ADD CONSTRAINT `opening_stocks_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `opening_stocks_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `opening_stocks_transaction_id_foreign` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `opening_stocks_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `opening_stocks_variation_id_foreign` FOREIGN KEY (`variation_id`) REFERENCES `product_details` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `passkeys`
--
ALTER TABLE `passkeys`
  ADD CONSTRAINT `passkeys_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `payments_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `payments_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `payments_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `payments_payment_account_foreign` FOREIGN KEY (`payment_account`) REFERENCES `chart_of_accounts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `payments_transaction_id_foreign` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `permissions`
--
ALTER TABLE `permissions`
  ADD CONSTRAINT `permissions_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `permissions_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `permissions_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `permissions_menu_id_foreign` FOREIGN KEY (`menu_id`) REFERENCES `menus` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `permissions_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `products`
--
ALTER TABLE `products`
  ADD CONSTRAINT `products_brand_id_foreign` FOREIGN KEY (`brand_id`) REFERENCES `brands` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `products_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `products_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `products_itemtype_id_foreign` FOREIGN KEY (`itemtype_id`) REFERENCES `item_types` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `products_subcategory_id_foreign` FOREIGN KEY (`subcategory_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `products_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `products_warranty_id_foreign` FOREIGN KEY (`warranty_id`) REFERENCES `warranties` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `product_details`
--
ALTER TABLE `product_details`
  ADD CONSTRAINT `product_details_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `purchase_lines`
--
ALTER TABLE `purchase_lines`
  ADD CONSTRAINT `purchase_lines_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `purchase_lines_transaction_id_foreign` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `purchase_lines_variation_id_foreign` FOREIGN KEY (`variation_id`) REFERENCES `product_details` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `purchase_sell_lines`
--
ALTER TABLE `purchase_sell_lines`
  ADD CONSTRAINT `purchase_sell_lines_purchaseline_id_foreign` FOREIGN KEY (`purchaseline_id`) REFERENCES `purchase_lines` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `purchase_sell_lines_sellline_id_foreign` FOREIGN KEY (`sellline_id`) REFERENCES `sell_lines` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `roles`
--
ALTER TABLE `roles`
  ADD CONSTRAINT `roles_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `roles_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `sell_lines`
--
ALTER TABLE `sell_lines`
  ADD CONSTRAINT `sell_lines_itemtype_id_foreign` FOREIGN KEY (`itemtype_id`) REFERENCES `item_types` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `sell_lines_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `sell_lines_transaction_id_foreign` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `sell_lines_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `sell_lines_variation_id_foreign` FOREIGN KEY (`variation_id`) REFERENCES `product_details` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `states`
--
ALTER TABLE `states`
  ADD CONSTRAINT `states_country_id_foreign` FOREIGN KEY (`country_id`) REFERENCES `countries` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `taxes`
--
ALTER TABLE `taxes`
  ADD CONSTRAINT `taxes_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `transactions`
--
ALTER TABLE `transactions`
  ADD CONSTRAINT `transactions_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `transactions_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `transactions_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `transactions_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `transactions_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `transactions_direct_contact_id_foreign` FOREIGN KEY (`direct_contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `transactions_opening_stock_product_id_foreign` FOREIGN KEY (`opening_stock_product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `transactions_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `transactions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `transactions_tax_id_foreign` FOREIGN KEY (`tax_id`) REFERENCES `taxes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `transactions_tobranch_id_foreign` FOREIGN KEY (`tobranch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `t_accounts`
--
ALTER TABLE `t_accounts`
  ADD CONSTRAINT `t_accounts_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `t_accounts_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `t_accounts_cancelled_by_foreign` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `t_accounts_coa_id_foreign` FOREIGN KEY (`coa_id`) REFERENCES `chart_of_accounts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `t_accounts_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `t_accounts_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `t_accounts_printed_by_foreign` FOREIGN KEY (`printed_by`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `t_account_details`
--
ALTER TABLE `t_account_details`
  ADD CONSTRAINT `t_account_details_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `t_account_details_coa_id_foreign` FOREIGN KEY (`coa_id`) REFERENCES `chart_of_accounts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `t_account_details_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `t_account_details_t_account_id_foreign` FOREIGN KEY (`t_account_id`) REFERENCES `t_accounts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `units`
--
ALTER TABLE `units`
  ADD CONSTRAINT `units_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `units_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `units` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `users_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `users_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `users_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `variations`
--
ALTER TABLE `variations`
  ADD CONSTRAINT `variations_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `variations_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `variations_itemtype_id_foreign` FOREIGN KEY (`itemtype_id`) REFERENCES `item_types` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `variations_subcategory_id_foreign` FOREIGN KEY (`subcategory_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `warranties`
--
ALTER TABLE `warranties`
  ADD CONSTRAINT `warranties_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

CREATE DATABASE IF NOT EXISTS `qbo_pco_sync` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `qbo_pco_sync`;

CREATE TABLE `app_users` (
  `id` int UNSIGNED NOT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `is_admin` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `fund_mappings` (
  `id` int UNSIGNED NOT NULL,
  `pco_fund_id` varchar(32) COLLATE utf8mb4_general_ci NOT NULL,
  `pco_fund_name` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `qbo_class_name` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `qbo_location_name` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `qbo_income_account_name` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `qbo_tokens` (
  `id` int UNSIGNED NOT NULL,
  `realm_id` varchar(64) NOT NULL,
  `access_token` text NOT NULL,
  `refresh_token` text NOT NULL,
  `token_type` varchar(32) DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `synced_batches` (
  `id` int NOT NULL,
  `batch_id` varchar(191) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `synced_deposits` (
  `id` int NOT NULL,
  `type` varchar(32) NOT NULL,
  `fingerprint` varchar(191) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `synced_items` (
  `id` int NOT NULL,
  `item_type` varchar(64) NOT NULL,
  `item_id` varchar(191) NOT NULL,
  `meta` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `sync_logs` (
  `id` int UNSIGNED NOT NULL,
  `sync_type` varchar(32) NOT NULL,
  `started_at` datetime NOT NULL,
  `finished_at` datetime DEFAULT NULL,
  `status` enum('success','error','partial') NOT NULL DEFAULT 'success',
  `summary` varchar(255) DEFAULT NULL,
  `details` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `sync_runs` (
  `id` bigint UNSIGNED NOT NULL,
  `started_at` datetime NOT NULL,
  `finished_at` datetime DEFAULT NULL,
  `status` enum('running','success','warning','error') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'running',
  `summary` text COLLATE utf8mb4_general_ci
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `sync_settings` (
  `id` int UNSIGNED NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


ALTER TABLE `app_users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

ALTER TABLE `fund_mappings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_pco_fund` (`pco_fund_id`);

ALTER TABLE `qbo_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_realm` (`realm_id`);

ALTER TABLE `synced_batches`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_batch` (`batch_id`);

ALTER TABLE `synced_deposits`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_type_fingerprint` (`type`,`fingerprint`);

ALTER TABLE `synced_items`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_type_item` (`item_type`,`item_id`);

ALTER TABLE `sync_logs`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `sync_runs`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `sync_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_setting_key` (`setting_key`);


ALTER TABLE `app_users`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `fund_mappings`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `qbo_tokens`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `synced_batches`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `synced_deposits`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `synced_items`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
ALTER TABLE `sync_logs`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `sync_runs`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `sync_settings`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

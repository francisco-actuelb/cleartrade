-- Export de la structure de la base de données : cleartrade
-- Généré le : 2026-07-01 15:47:19

SET FOREIGN_KEY_CHECKS=0;

-- --------------------------------------------------------
-- Structure de la table `ai_signals`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `ai_signals`;
CREATE TABLE `ai_signals` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `insider_id` int(11) NOT NULL,
  `signal_date` date NOT NULL,
  `signal_type` enum('BULLISH','BEARISH','NEUTRAL') NOT NULL,
  `confidence_score` tinyint(3) unsigned NOT NULL,
  `rationale` text NOT NULL,
  `analysis_metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`analysis_metadata`)),
  `model_version` varchar(50) NOT NULL,
  `is_alert_sent` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `insider_id` (`insider_id`),
  KEY `idx_company_signal` (`company_id`,`signal_date`),
  CONSTRAINT `ai_signals_ibfk_1` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ai_signals_ibfk_2` FOREIGN KEY (`insider_id`) REFERENCES `insiders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Structure de la table `companies`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `companies`;
CREATE TABLE `companies` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `cik` varchar(10) NOT NULL,
  `name` varchar(255) NOT NULL,
  `ticker` varchar(12) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `cik` (`cik`),
  KEY `idx_companies_ticker` (`ticker`)
) ENGINE=InnoDB AUTO_INCREMENT=4065 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Structure de la table `insider_stats`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `insider_stats`;
CREATE TABLE `insider_stats` (
  `insider_id` int(11) NOT NULL,
  `win_rate_3m` tinyint(3) unsigned DEFAULT NULL,
  `total_trades_3m` int(10) unsigned DEFAULT 0,
  `avg_return_3m` decimal(10,2) DEFAULT NULL,
  `win_rate_6m` tinyint(3) unsigned DEFAULT NULL,
  `total_trades_6m` int(10) unsigned DEFAULT 0,
  `avg_return_6m` decimal(10,2) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`insider_id`),
  CONSTRAINT `insider_stats_ibfk_1` FOREIGN KEY (`insider_id`) REFERENCES `insiders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Structure de la table `insiders`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `insiders`;
CREATE TABLE `insiders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `cik` varchar(10) NOT NULL,
  `name` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `cik` (`cik`),
  KEY `idx_insiders_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=14591 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Structure de la table `stock_prices`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `stock_prices`;
CREATE TABLE `stock_prices` (
  `ticker` varchar(12) NOT NULL,
  `price_date` date NOT NULL,
  `open_price` decimal(20,4) DEFAULT NULL,
  `high_price` decimal(20,4) DEFAULT NULL,
  `low_price` decimal(20,4) DEFAULT NULL,
  `close_price` decimal(20,4) NOT NULL,
  `volume` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`ticker`,`price_date`),
  KEY `idx_date` (`price_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Structure de la table `system_settings`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `system_settings`;
CREATE TABLE `system_settings` (
  `key_name` varchar(50) NOT NULL,
  `value` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`key_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Structure de la table `transactions`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `transactions`;
CREATE TABLE `transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `accession_number` varchar(20) NOT NULL,
  `line_index` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `insider_id` int(11) NOT NULL,
  `transaction_date` date NOT NULL,
  `transaction_code` char(1) NOT NULL,
  `shares` int(11) NOT NULL,
  `price_per_share` decimal(10,4) NOT NULL,
  `officer_title` varchar(100) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `footnotes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_tx_line` (`accession_number`,`line_index`),
  KEY `company_id` (`company_id`),
  KEY `insider_id` (`insider_id`),
  KEY `idx_tx_date` (`transaction_date`),
  KEY `idx_tx_code` (`transaction_code`),
  CONSTRAINT `transactions_ibfk_1` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `transactions_ibfk_2` FOREIGN KEY (`insider_id`) REFERENCES `insiders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=136788 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Structure de la table `transactions_jour`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `transactions_jour`;
CREATE TABLE `transactions_jour` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transaction_date` date NOT NULL,
  `company_id` int(11) NOT NULL,
  `insider_id` int(11) NOT NULL,
  `transaction_code` char(1) NOT NULL,
  `total_shares` int(11) NOT NULL,
  `avg_price_per_share` decimal(10,4) NOT NULL,
  `transaction_count` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_daily_tx` (`transaction_date`,`company_id`,`insider_id`,`transaction_code`),
  KEY `company_id` (`company_id`),
  KEY `insider_id` (`insider_id`),
  KEY `idx_tx_jour_date` (`transaction_date`),
  KEY `idx_tx_jour_code` (`transaction_code`),
  CONSTRAINT `transactions_jour_ibfk_1` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `transactions_jour_ibfk_2` FOREIGN KEY (`insider_id`) REFERENCES `insiders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=32638 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS=1;

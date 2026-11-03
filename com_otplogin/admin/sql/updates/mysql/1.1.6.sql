CREATE TABLE IF NOT EXISTS `#__otplogin_codes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `phone` varchar(11) NOT NULL,
  `code_hash` char(64) NOT NULL,
  `attempts` smallint unsigned NOT NULL DEFAULT 0,
  `used` tinyint unsigned NOT NULL DEFAULT 0,
  `expires_at` int unsigned NOT NULL,
  `ip` varchar(45) NOT NULL DEFAULT '',
  `created_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_phone_used` (`phone`, `used`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__otplogin_events` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `kind` varchar(24) NOT NULL,
  `subject` varchar(64) DEFAULT NULL,
  `ip` varchar(45) NOT NULL DEFAULT '',
  `created_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_kind_subject` (`kind`, `subject`, `created_at`),
  KEY `idx_kind_ip` (`kind`, `ip`, `created_at`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__otplogin_blocks` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `scope` varchar(10) NOT NULL,
  `target` varchar(64) NOT NULL,
  `blocked_until` int unsigned NOT NULL,
  `reason` varchar(100) NOT NULL DEFAULT '',
  `created_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_scope_target` (`scope`, `target`),
  KEY `idx_until` (`blocked_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__otplogin_qr` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `token` char(64) NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'pending',
  `user_id` int unsigned DEFAULT NULL,
  `ip` varchar(45) NOT NULL DEFAULT '',
  `ua_hash` char(64) NOT NULL DEFAULT '',
  `confirm_ip` varchar(45) NOT NULL DEFAULT '',
  `expires_at` int unsigned NOT NULL,
  `created_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_token` (`token`),
  KEY `idx_status_exp` (`status`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__otplogin_webauthn` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `credential_id` varchar(512) NOT NULL,
  `public_key` text NOT NULL,
  `sign_count` int unsigned NOT NULL DEFAULT 0,
  `transports` varchar(128) NOT NULL DEFAULT '',
  `label` varchar(100) NOT NULL DEFAULT '',
  `created_at` int unsigned NOT NULL,
  `last_used_at` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_credential` (`credential_id`(191)),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

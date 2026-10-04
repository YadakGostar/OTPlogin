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

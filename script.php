<?php
/**
 * @package     Otplogin
 * @license     GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\Database\DatabaseInterface;

class Pkg_OtploginInstallerScript
{
    private string $minPhp = '8.3.0';
    private string $minJoomla = '6.0.0';
    private string $schemaVersion = '1.1.16';

    public function preflight(string $type, InstallerAdapter $parent): bool
    {
        if (version_compare(PHP_VERSION, $this->minPhp, '<')) {
            Factory::getApplication()->enqueueMessage(
                'OTP Login requires PHP ' . $this->minPhp . '+ (current: ' . PHP_VERSION . ').',
                'error'
            );

            return false;
        }

        if (version_compare(JVERSION, $this->minJoomla, '<')) {
            Factory::getApplication()->enqueueMessage(
                'OTP Login requires Joomla ' . $this->minJoomla . '+ (current: ' . JVERSION . ').',
                'error'
            );

            return false;
        }

        return true;
    }

    public function postflight(string $type, InstallerAdapter $parent): bool
    {
        $this->mergeParams();
        $this->ensureDatabase();
        $this->ensureUpdateSite();

        return true;
    }

    /**
     * Register the remote update server so Joomla can find and install new versions
     * automatically (System → Update → Extensions), regardless of the ZIP filename.
     * The live download URL always comes from the hosted update feed XML.
     */
    private function ensureUpdateSite(): void
    {
        $location = 'https://www.yadakgostar.co/updates/pkg_otplogin.xml';
        $name     = 'OTP Login Updates';

        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
        } catch (\Throwable $e) {
            return;
        }

        try {
            // Package row in #__extensions
            $pkgId = (int) $db->setQuery(
                'SELECT `extension_id` FROM ' . $db->quoteName('#__extensions')
                . ' WHERE `type` = ' . $db->quote('package')
                . ' AND `element` = ' . $db->quote('pkg_otplogin')
                . ' LIMIT 1'
            )->loadResult();

            // Component as fallback link target
            $comId = (int) $db->setQuery(
                'SELECT `extension_id` FROM ' . $db->quoteName('#__extensions')
                . ' WHERE `type` = ' . $db->quote('component')
                . ' AND `element` = ' . $db->quote('com_otplogin')
                . ' LIMIT 1'
            )->loadResult();

            $siteId = (int) $db->setQuery(
                'SELECT `update_site_id` FROM ' . $db->quoteName('#__update_sites')
                . ' WHERE `location` = ' . $db->quote($location)
                . ' LIMIT 1'
            )->loadResult();

            if ($siteId <= 0) {
                $db->setQuery(
                    'INSERT INTO ' . $db->quoteName('#__update_sites')
                    . ' (`name`, `type`, `location`, `enabled`, `last_check_timestamp`)'
                    . ' VALUES ('
                    . $db->quote($name) . ', '
                    . $db->quote('extension') . ', '
                    . $db->quote($location) . ', 1, 0)'
                )->execute();
                $siteId = (int) $db->insertid();
            } else {
                $db->setQuery(
                    'UPDATE ' . $db->quoteName('#__update_sites')
                    . ' SET `name` = ' . $db->quote($name)
                    . ', `type` = ' . $db->quote('extension')
                    . ', `enabled` = 1'
                    . ' WHERE `update_site_id` = ' . $siteId
                )->execute();
            }

            if ($siteId <= 0) {
                return;
            }

            foreach (array_filter([$pkgId, $comId]) as $extId) {
                $exists = (int) $db->setQuery(
                    'SELECT COUNT(*) FROM ' . $db->quoteName('#__update_sites_extensions')
                    . ' WHERE `update_site_id` = ' . $siteId
                    . ' AND `extension_id` = ' . (int) $extId
                )->loadResult();

                if ($exists === 0) {
                    $db->setQuery(
                        'INSERT INTO ' . $db->quoteName('#__update_sites_extensions')
                        . ' (`update_site_id`, `extension_id`) VALUES ('
                        . $siteId . ', ' . (int) $extId . ')'
                    )->execute();
                }
            }
        } catch (\Throwable $e) {
            // Never fail install because of update-site registration
        }
    }

    /** Create missing tables and align #__schemas so Joomla no longer reports outdated tables. */
    private function ensureDatabase(): void
    {
        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
        } catch (\Throwable $e) {
            return;
        }

        $statements = [
            "CREATE TABLE IF NOT EXISTS `#__otplogin_codes` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS `#__otplogin_events` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `kind` varchar(24) NOT NULL,
  `subject` varchar(64) DEFAULT NULL,
  `ip` varchar(45) NOT NULL DEFAULT '',
  `created_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_kind_subject` (`kind`, `subject`, `created_at`),
  KEY `idx_kind_ip` (`kind`, `ip`, `created_at`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS `#__otplogin_blocks` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `scope` varchar(10) NOT NULL,
  `target` varchar(64) NOT NULL,
  `blocked_until` int unsigned NOT NULL,
  `reason` varchar(100) NOT NULL DEFAULT '',
  `created_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_scope_target` (`scope`, `target`),
  KEY `idx_until` (`blocked_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS `#__otplogin_qr` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS `#__otplogin_webauthn` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];

        foreach ($statements as $sql) {
            try {
                $db->setQuery($sql)->execute();
            } catch (\Throwable $e) {
                // continue — table may already exist with compatible structure
            }
        }

        try {
            $extId = (int) $db->setQuery(
                'SELECT `extension_id` FROM ' . $db->quoteName('#__extensions')
                . ' WHERE `type` = ' . $db->quote('component')
                . ' AND `element` = ' . $db->quote('com_otplogin')
                . ' LIMIT 1'
            )->loadResult();

            if ($extId > 0) {
                // Remove duplicate schema rows then set a single matching version
                $db->setQuery(
                    'DELETE FROM ' . $db->quoteName('#__schemas')
                    . ' WHERE ' . $db->quoteName('extension_id') . ' = ' . $extId
                )->execute();

                $db->setQuery(
                    'INSERT INTO ' . $db->quoteName('#__schemas')
                    . ' (' . $db->quoteName('extension_id') . ', ' . $db->quoteName('version_id') . ')'
                    . ' VALUES (' . $extId . ', ' . $db->quote($this->schemaVersion) . ')'
                )->execute();
            }
        } catch (\Throwable $e) {
            // ignore
        }
    }

    private function mergeParams(): void
    {
        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
        } catch (\Throwable $e) {
            return;
        }

        try {
            $row = $db->setQuery(
                'SELECT `extension_id`, `params` FROM ' . $db->quoteName('#__extensions')
                . ' WHERE `type` = ' . $db->quote('component') . ' AND `element` = ' . $db->quote('com_otplogin')
            )->loadObject();
        } catch (\Throwable $e) {
            return;
        }

        if (!$row) {
            return;
        }

        $current = json_decode((string) $row->params, true);
        $current = is_array($current) ? $current : [];

        $defaults = [
            'sms_provider' => 'ippanel',
            'code_length' => 6,
            'code_ttl' => 120,
            'max_verify_attempts' => 5,
            'resend_cooldown' => 120,
            'phone_window_minutes' => 10,
            'phone_window_max' => 3,
            'phone_daily_max' => 10,
            'ip_hourly_max' => 15,
            'ip_daily_max' => 40,
            'global_daily_max' => 500,
            'phone_fail_max' => 8,
            'ip_fail_max' => 20,
            'block_minutes' => 30,
            'ip_register_max' => 5,
            'min_form_seconds' => 0,
            'ip_header' => 'HTTP_X_FORWARDED_FOR',
            'registration_enabled' => 1,
            'new_user_group' => 2,
            'ask_name' => 'required',
            'ask_email' => 'off',
            'link_enabled' => 1,
            'block_superusers' => 0,
            'password_login' => 1,
            'password_set' => 'optional',
            'password_min_length' => 8,
            'avatar_enabled' => 1,
            'passkey_enabled' => 1,
            'qr_login_enabled' => 1,
        ];

        $merged = array_merge($defaults, $current);
        $json = json_encode($merged, JSON_UNESCAPED_UNICODE);

        try {
            $db->setQuery(
                'UPDATE ' . $db->quoteName('#__extensions')
                . ' SET `params` = ' . $db->quote($json)
                . ' WHERE `extension_id` = ' . (int) $row->extension_id
            )->execute();
        } catch (\Throwable $e) {
        }
    }
}

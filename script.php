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

    public function preflight(string $type, InstallerAdapter $parent): bool
    {
        $app = Factory::getApplication();

        if (version_compare(PHP_VERSION, $this->minPhp, '<')) {
            $app->enqueueMessage(
                'OTP Login requires PHP ' . $this->minPhp . ' or newer (current: ' . PHP_VERSION . ').',
                'error'
            );

            return false;
        }

        if (version_compare(JVERSION, $this->minJoomla, '<')) {
            $app->enqueueMessage(
                'OTP Login requires Joomla ' . $this->minJoomla . ' or newer (current: ' . JVERSION . ').',
                'error'
            );

            return false;
        }

        if (!\function_exists('curl_init')) {
            $app->enqueueMessage('The PHP cURL extension is required to reach IPPanel.', 'warning');
        }

        return true;
    }

    /** Restore all non-IPPanel component settings while preserving IPPanel credentials. */
    private function resetNonIppanelDefaults(): void
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $row = $db->setQuery(
            'SELECT `extension_id`, `params` FROM ' . $db->quoteName('#__extensions')
            . ' WHERE `type` = ' . $db->quote('component') . ' AND `element` = ' . $db->quote('com_otplogin')
        )->loadObject();

        if (!$row) {
            return;
        }

        $current = json_decode((string) $row->params, true);
        $current = is_array($current) ? $current : [];

        $ippanel = [];
        foreach ([
            'api_key', 'base_url', 'from_number', 'pattern_code', 'pattern_var',
            'request_timeout', 'dry_run',
        ] as $key) {
            if (array_key_exists($key, $current)) {
                $ippanel[$key] = $current[$key];
            }
        }

        $defaults = [
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
            'ask_email' => 'optional',
            'fake_email_domain' => '',
            'link_enabled' => 1,
            'block_superusers' => 1,
            'lookup_username' => 1,
            'profile_key' => 'otplogin.phone',
            'lookup_field_id' => 0,
            'redirect_url' => '',
            'password_login' => 1,
            'password_set' => 'optional',
            'password_min_length' => 8,
            'password_fail_max' => 5,
            'password_ip_fail_max' => 15,
            'lookup_hourly_max' => 30,
            'telegram_enabled' => 0,
            'telegram_bot_token' => '',
            'telegram_chat_id' => '',
            'telegram_proxy' => '',
            'telegram_timeout' => 15,
            'telegram_on_sms_error' => 1,
            'telegram_on_login' => 0,
            'telegram_on_register' => 1,
            'telegram_on_verify_fail' => 0,
        ];

        $merged = array_merge($defaults, $ippanel);
        if (isset($current['rules'])) {
            $merged['rules'] = $current['rules'];
        }

        $db->setQuery(
            'UPDATE ' . $db->quoteName('#__extensions') . ' SET `params` = '
            . $db->quote(json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
            . ' WHERE `extension_id` = ' . (int) $row->extension_id
        )->execute();
    }


    /** Fill only missing/unsafe security defaults without wiping admin customisation. */
    private function applyRecommendedDefaults(): void
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $row = $db->setQuery(
            'SELECT `extension_id`, `params` FROM ' . $db->quoteName('#__extensions')
            . ' WHERE `type` = ' . $db->quote('component') . ' AND `element` = ' . $db->quote('com_otplogin')
        )->loadObject();

        if (!$row) {
            return;
        }

        $params = json_decode((string) $row->params, true);
        $params = is_array($params) ? $params : [];
        $changed = false;

        // Unlimited global SMS cap is unsafe for SMS-pumping; seed 500 when still 0/empty.
        if (!isset($params['global_daily_max']) || (int) $params['global_daily_max'] === 0) {
            $params['global_daily_max'] = 500;
            $changed = true;
        }

        if (!isset($params['ip_header']) || trim((string) $params['ip_header']) === '') {
            $params['ip_header'] = 'HTTP_X_FORWARDED_FOR';
            $changed = true;
        }

        if (!isset($params['session_hourly_max'])) {
            $params['session_hourly_max'] = 5;
            $changed = true;
        }

        if (!isset($params['pow_enabled'])) {
            $params['pow_enabled'] = 1;
            $changed = true;
        }

        if (!isset($params['pow_difficulty'])) {
            $params['pow_difficulty'] = 16;
            $changed = true;
        }

        if (!$changed) {
            return;
        }

        $db->setQuery(
            'UPDATE ' . $db->quoteName('#__extensions') . ' SET `params` = '
            . $db->quote(json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
            . ' WHERE `extension_id` = ' . (int) $row->extension_id
        )->execute();
    }

    public function postflight(string $type, InstallerAdapter $parent): bool
    {
        if ($type === 'uninstall') {
            return true;
        }

        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $db->setQuery(
            'UPDATE ' . $db->quoteName('#__extensions') . ' SET `enabled` = 1'
            . ' WHERE `type` = ' . $db->quote('plugin') . ' AND `folder` = ' . $db->quote('authentication')
            . ' AND `element` = ' . $db->quote('otplogin')
        )->execute();

        $db->setQuery(
            'UPDATE ' . $db->quoteName('#__extensions') . ' SET `enabled` = 1, `ordering` = 99'
            . ' WHERE `type` = ' . $db->quote('plugin') . ' AND `folder` = ' . $db->quote('user')
            . ' AND `element` = ' . $db->quote('otploginavatar')
        )->execute();

        // Do NOT wipe admin settings on update. Only fill unsafe/missing security defaults.
        if ($type === 'install') {
            $this->resetNonIppanelDefaults();
            Factory::getApplication()->enqueueMessage(
                'OTP Login installed. Open Components → OTP Login → Options for IPPanel settings, then publish the OTP Login module.',
                'message'
            );
        } else {
            $this->applyRecommendedDefaults();
        }

        return true;
    }
}

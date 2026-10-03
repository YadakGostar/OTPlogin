<?php
/**
 * @package     Otplogin
 * @license     GNU General Public License version 2 or later
 */

namespace Otplogin\Component\Otplogin\Administrator\View\Dashboard;

\defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\Database\DatabaseInterface;
use Otplogin\Component\Otplogin\Site\Service\ErrorLogger;

class HtmlView extends BaseHtmlView
{
    public array $stats = [];
    public array $blocks = [];
    public array $events = [];
    public array $checks = [];
    public array $log = [];
    public int $onlineUsers = 0;
    public array $onlineList = [];
    public int $activeUsers = 0;
    public int $logins24h = 0;
    public int $logins7d = 0;
    public array $hourly = [];
    public array $monthly = [];
    public array $cards = [];
    public array $health = [];
    public string $webotpLine = '';

    public function display($tpl = null)
    {
        $db     = Factory::getContainer()->get(DatabaseInterface::class);
        $params = ComponentHelper::getParams('com_otplogin');
        $since  = time() - 86400;
        $since7 = time() - 7 * 86400;

        foreach (['send', 'sms_error', 'verify_ok', 'verify_fail', 'login', 'login_pass', 'pass_fail', 'register', 'link'] as $kind) {
            $this->stats[$kind] = (int) $db->setQuery(
                'SELECT COUNT(*) FROM ' . $db->quoteName('#__otplogin_events')
                . ' WHERE `kind` = ' . $db->quote($kind) . ' AND `created_at` >= ' . $since
            )->loadResult();
        }

        $this->logins24h = $this->stats['login'] + $this->stats['login_pass'] + $this->stats['register'];
        $this->logins7d  = (int) $db->setQuery(
            'SELECT COUNT(*) FROM ' . $db->quoteName('#__otplogin_events')
            . ' WHERE `kind` IN (' . $db->quote('login') . ',' . $db->quote('login_pass') . ',' . $db->quote('register') . ')'
            . ' AND `created_at` >= ' . $since7
        )->loadResult();

        $sendOk   = $this->stats['send'];
        $sendFail = $this->stats['sms_error'];
        $sendAll  = $sendOk + $sendFail;
        $rate     = $sendAll > 0 ? (int) round(100 * $sendOk / $sendAll) : 100;

        $this->cards = [
            'fail' => [
                'value' => $sendFail,
                'label' => 'COM_OTPLOGIN_CARD_SMS_FAIL',
                'hint'  => 'COM_OTPLOGIN_CARD_SMS_FAIL_HINT',
                'tone'  => 'danger',
                'icon'  => 'times',
            ],
            'ok' => [
                'value' => $sendOk,
                'label' => 'COM_OTPLOGIN_CARD_SMS_OK',
                'hint'  => 'COM_OTPLOGIN_CARD_SMS_OK_HINT',
                'tone'  => 'info',
                'icon'  => 'check',
            ],
            'total' => [
                'value' => $sendAll,
                'label' => 'COM_OTPLOGIN_CARD_SMS_TOTAL',
                'hint'  => Text::sprintf('COM_OTPLOGIN_CARD_SMS_TOTAL_HINT', $rate),
                'tone'  => 'success',
                'icon'  => 'comments',
                'raw_hint' => true,
            ],
            'email' => [
                'value' => 0,
                'label' => 'COM_OTPLOGIN_CARD_EMAIL_LOGIN',
                'hint'  => 'COM_OTPLOGIN_CARD_EMAIL_LOGIN_HINT',
                'tone'  => 'primary',
                'icon'  => 'envelope',
            ],
            'sms_login' => [
                'value' => $this->stats['login'] + $this->stats['login_pass'],
                'label' => 'COM_OTPLOGIN_CARD_SMS_LOGIN',
                'hint'  => Text::sprintf('COM_OTPLOGIN_CARD_SMS_LOGIN_HINT', $sendOk),
                'tone'  => 'teal',
                'icon'  => 'mobile',
                'raw_hint' => true,
            ],
            'users' => [
                'value' => 0,
                'label' => 'COM_OTPLOGIN_CARD_ACTIVE_USERS',
                'hint'  => 'COM_OTPLOGIN_CARD_ACTIVE_USERS_HINT',
                'tone'  => 'warning',
                'icon'  => 'users',
            ],
        ];

        try {
            $this->activeUsers = (int) $db->setQuery(
                'SELECT COUNT(*) FROM ' . $db->quoteName('#__users') . ' WHERE `block` = 0'
            )->loadResult();
        } catch (\Throwable $e) {
            $this->activeUsers = 0;
        }
        $this->cards['users']['value'] = $this->activeUsers;

        // Only users with an active front-end session in the last 5 minutes count as online.
        // Do NOT fall back to lastvisitDate (that shows people who left hours ago).
        $cutoff = time() - 300;
        $this->onlineList  = [];
        $this->onlineUsers = 0;

        try {
            // Joomla stores session time as unix timestamp (string/int).
            $sessUsers = $db->setQuery(
                'SELECT s.userid AS id, u.name, u.username, u.email, MAX(s.time) AS last_seen'
                . ' FROM ' . $db->quoteName('#__session') . ' AS s'
                . ' INNER JOIN ' . $db->quoteName('#__users') . ' AS u ON u.id = s.userid'
                . ' WHERE s.guest = 0'
                . ' AND s.client_id = 0'
                . ' AND s.userid > 0'
                . ' AND CAST(s.time AS UNSIGNED) > ' . (int) $cutoff
                . ' GROUP BY s.userid, u.name, u.username, u.email'
                . ' ORDER BY last_seen DESC LIMIT 30'
            )->loadObjectList() ?: [];

            foreach ($sessUsers as $u) {
                $uid = (int) $u->id;
                if ($uid < 1) {
                    continue;
                }
                $seen = (int) $u->last_seen;
                if ($seen < $cutoff) {
                    continue;
                }
                $this->onlineList[$uid] = $u;
            }
        } catch (\Throwable $e) {
            $this->onlineList = [];
        }

        $this->onlineUsers = count($this->onlineList);
        $this->onlineList  = array_values($this->onlineList);

        // SMS monthly chart: bucket in PHP (avoids MySQL timezone vs gmdate mismatch)
        $rawEvents = $db->setQuery(
            'SELECT `created_at`, `kind` FROM ' . $db->quoteName('#__otplogin_events')
            . ' WHERE `kind` IN (' . $db->quote('send') . ',' . $db->quote('sms_error') . ')'
            . ' AND `created_at` >= ' . (time() - 370 * 86400)
        )->loadObjectList() ?: [];

        $bucket = [];
        foreach ($rawEvents as $r) {
            $ym = date('Y-m', (int) $r->created_at);
            if (!isset($bucket[$ym])) {
                $bucket[$ym] = ['send' => 0, 'sms_error' => 0];
            }
            $k = (string) $r->kind;
            if (isset($bucket[$ym][$k])) {
                $bucket[$ym][$k]++;
            }
        }

        $this->monthly = [];
        for ($i = 11; $i >= 0; $i--) {
            $ts = strtotime(date('Y-m-01') . ' -' . $i . ' months');
            $ym = date('Y-m', $ts);
            $this->monthly[] = [
                'ym'    => $ym,
                'label' => $this->jalaliMonthLabel($ts),
                'ok'    => (int) ($bucket[$ym]['send'] ?? 0),
                'fail'  => (int) ($bucket[$ym]['sms_error'] ?? 0),
            ];
        }

        $rows = $db->setQuery(
            'SELECT FLOOR(`created_at` / 3600) AS slot, COUNT(*) AS c FROM ' . $db->quoteName('#__otplogin_events')
            . ' WHERE `kind` IN (' . $db->quote('login') . ',' . $db->quote('login_pass') . ',' . $db->quote('register') . ')'
            . ' AND `created_at` >= ' . $since
            . ' GROUP BY slot ORDER BY slot ASC'
        )->loadObjectList() ?: [];
        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r->slot] = (int) $r->c;
        }
        $nowSlot = (int) floor(time() / 3600);
        $this->hourly = [];
        for ($i = 23; $i >= 0; $i--) {
            $slot = $nowSlot - $i;
            $this->hourly[] = [
                'slot'  => $slot,
                'label' => gmdate('H:i', $slot * 3600),
                'count' => $map[$slot] ?? 0,
            ];
        }

        $this->blocks = $db->setQuery(
            'SELECT * FROM ' . $db->quoteName('#__otplogin_blocks')
            . ' WHERE `blocked_until` > ' . time() . ' ORDER BY `blocked_until` DESC LIMIT 100'
        )->loadObjectList() ?: [];

        $this->events = $db->setQuery(
            'SELECT e.*, u.name AS user_name, u.username AS user_username FROM ' . $db->quoteName('#__otplogin_events') . ' AS e'
            . ' LEFT JOIN ' . $db->quoteName('#__users') . ' AS u ON u.id = CAST(e.subject AS UNSIGNED)'
            . ' ORDER BY e.id DESC LIMIT 5'
        )->loadObjectList() ?: [];

        $modulePublished = (int) $db->setQuery(
            'SELECT COUNT(*) FROM ' . $db->quoteName('#__modules')
            . ' WHERE `module` = ' . $db->quote('mod_otplogin') . ' AND `published` = 1'
        )->loadResult() > 0;

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');

        $keySource = 'none';
        $envKey = getenv('OTPLOGIN_IPPANEL_KEY');
        if (\is_string($envKey) && trim($envKey) !== '') {
            $keySource = 'env';
        } elseif (\defined('OTPLOGIN_IPPANEL_KEY') && OTPLOGIN_IPPANEL_KEY) {
            $keySource = 'config';
        } elseif (trim((string) $params->get('api_key', '')) !== '') {
            $keySource = 'params';
        }

        $codeLen = (int) $params->get('code_length', 5);
        $globalCap = (int) $params->get('global_daily_max', 0);
        $powOn = (int) $params->get('pow_enabled', 1) === 1;
        $ipHdr = trim((string) $params->get('ip_header', ''));

        $this->checks = [
            'COM_OTPLOGIN_CHECK_API_KEY'  => $keySource !== 'none',
            'COM_OTPLOGIN_CHECK_PATTERN'  => trim((string) $params->get('pattern_code', '')) !== ''
                && trim((string) $params->get('from_number', '')) !== '',
            'COM_OTPLOGIN_CHECK_PLUGIN'   => PluginHelper::isEnabled('authentication', 'otplogin'),
            'COM_OTPLOGIN_CHECK_MODULE'   => $modulePublished,
            'COM_OTPLOGIN_CHECK_NO_DRY'   => (int) $params->get('dry_run', 0) !== 1,
        ];

        $host = (string) (\parse_url(\Joomla\CMS\Uri\Uri::root(), PHP_URL_HOST) ?: 'example.com');
        $this->webotpLine = '@' . $host . ' #{{code}}';
        // IPPanel pattern vars differ; show both generic forms.
        $var = trim((string) $params->get('pattern_var', 'code')) ?: 'code';
        $this->webotpLine = '@' . $host . ' #' . '%' . $var . '%';

        $ipDetail = 'پیش‌فرض سرور';
        if ($ipHdr !== '') {
            $hdr = strtoupper($ipHdr);
            if (str_contains($hdr, 'CF_CONNECTING')) {
                $ipDetail = 'کلودفلر';
            } elseif (str_contains($hdr, 'X_FORWARDED') || str_contains($hdr, 'FORWARDED')) {
                $ipDetail = 'پشت پروکسی';
            } elseif (str_contains($hdr, 'X_REAL')) {
                $ipDetail = 'nginx';
            } else {
                $ipDetail = 'تنظیم‌شده';
            }
        }

        $keyDetail = match ($keySource) {
            'env'    => 'متغیر سرور',
            'config' => 'فایل پیکربندی',
            'params' => 'تنظیمات افزونه',
            default  => 'تعریف نشده',
        };

        $this->health = [
            ['label' => 'COM_OTPLOGIN_HEALTH_HTTPS', 'ok' => $https, 'detail' => $https ? 'فعال' : 'غیرفعال', 'icon' => $https ? '🔒' : '⚠️'],
            ['label' => 'COM_OTPLOGIN_HEALTH_POW', 'ok' => $powOn, 'detail' => $powOn ? 'فعال' : 'خاموش', 'icon' => $powOn ? '🛡️' : '⚠️'],
            ['label' => 'COM_OTPLOGIN_HEALTH_CODELEN', 'ok' => $codeLen >= 6, 'detail' => $codeLen . ' رقم', 'icon' => $codeLen >= 6 ? '🔢' : '⚠️'],
            ['label' => 'COM_OTPLOGIN_HEALTH_GLOBAL', 'ok' => $globalCap > 0, 'detail' => $globalCap > 0 ? (string) $globalCap : 'بدون سقف', 'icon' => $globalCap > 0 ? '📨' : '⚠️'],
            ['label' => 'COM_OTPLOGIN_HEALTH_IPHDR', 'ok' => $ipHdr !== '', 'detail' => $ipDetail, 'icon' => '🌐'],
            ['label' => 'COM_OTPLOGIN_HEALTH_KEY', 'ok' => $keySource !== 'none', 'detail' => $keyDetail, 'icon' => $keySource !== 'none' ? '🔑' : '⚠️'],
        ];

        // Read the component's own diagnostic file. This does not depend on
        // Joomla's global logger configuration and is therefore reliable even when
        // the site logger is disabled or uses a different filename.
        $logFile = ErrorLogger::path();

        if (is_file($logFile) && is_readable($logFile)) {
            $size = (int) filesize($logFile);
            $fh   = fopen($logFile, 'rb');

            if ($fh) {
                if ($size > 50000) {
                    fseek($fh, -50000, SEEK_END);
                }

                $lines = preg_split('/\R/', (string) stream_get_contents($fh)) ?: [];
                fclose($fh);
                $lines = array_values(array_filter($lines, static fn ($l) => $l !== '' && !str_starts_with($l, '<?php')));
                $this->log = array_slice($lines, -5);
            }
        }

        ToolbarHelper::title(Text::_('COM_OTPLOGIN'), 'lock');

        if ($this->getCurrentUser()->authorise('core.admin', 'com_otplogin')) {
            ToolbarHelper::preferences('com_otplogin');
        }

        parent::display($tpl);
    }

    private function jalaliMonthLabel(int $ts): string
    {
        $names = [
            1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد', 4 => 'تیر',
            5 => 'مرداد', 6 => 'شهریور', 7 => 'مهر', 8 => 'آبان',
            9 => 'آذر', 10 => 'دی', 11 => 'بهمن', 12 => 'اسفند',
        ];

        $gy = (int) gmdate('Y', $ts);
        $gm = (int) gmdate('n', $ts);
        $gd = (int) gmdate('j', $ts);
        $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $gy2 = $gy - 1600;
        $days = 365 * $gy2 + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400) - 80 + $gd + $g_d_m[$gm - 1];
        if ($gm > 2 && (($gy % 4 === 0 && $gy % 100 !== 0) || $gy % 400 === 0)) {
            $days++;
        }
        $jy = 979 + 33 * intdiv($days, 12053);
        $days %= 12053;
        $jy += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) {
            $jy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }
        if ($days < 186) {
            $jm = 1 + intdiv($days, 31);
        } else {
            $jm = 7 + intdiv($days - 186, 30);
        }

        return $names[$jm] ?? gmdate('M', $ts);
    }
}

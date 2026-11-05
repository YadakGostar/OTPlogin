<?php
/**
 * @package     Otplogin
 * @license     GNU General Public License version 2 or later
 
 * @copyright (C) 2026 Reza Esfandiari
 * @author    Reza Esfandiari
 * @link      https://www.yadakgostar.co*/

namespace Otplogin\Component\Otplogin\Administrator\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Otplogin\Component\Otplogin\Site\Service\BaleClient;
use Otplogin\Component\Otplogin\Site\Service\PhoneHelper;
use Otplogin\Component\Otplogin\Site\Service\SmsGateway;
use Otplogin\Component\Otplogin\Site\Service\TelegramClient;

/** Admin diagnostics: SMS, Telegram and Bale connectivity tests. */
class TestController extends BaseController
{
    public function send(): void
    {
        $this->checkToken();

        if (!$this->app->getIdentity()->authorise('core.manage', 'com_otplogin')) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $phone = PhoneHelper::normalize($this->input->post->getString('phone', ''));
        $url   = Route::_('index.php?option=com_otplogin', false);

        if ($phone === null) {
            $this->setRedirect($url, Text::_('COM_OTPLOGIN_ERR_PHONE'), 'error');

            return;
        }

        $client = new SmsGateway(ComponentHelper::getParams('com_otplogin'));
        $result = $client->sendCode($phone, '12345');

        if ($result['ok']) {
            $this->setRedirect($url, Text::sprintf('COM_OTPLOGIN_TEST_OK', PhoneHelper::mask($phone), (string) ($result['id'] ?? '-')));

            return;
        }

        $this->setRedirect(
            $url,
            Text::sprintf(
                'COM_OTPLOGIN_TEST_FAIL',
                (string) ($result['error'] ?? '?'),
                (string) ($result['http'] ?? '-'),
                trim((string) ($result['detail'] ?? '') . ' ' . (string) ($result['body'] ?? ''))
            ),
            'error'
        );
    }

    /**
     * Real Telegram test — does not depend on telegram_on_* toggles.
     */
    public function telegram(): void
    {
        $this->checkToken();

        if (!$this->app->getIdentity()->authorise('core.manage', 'com_otplogin')) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $url    = Route::_('index.php?option=com_otplogin', false);
        $params = ComponentHelper::getParams('com_otplogin');
        $client = new TelegramClient($params);

        if (!$client->isEnabled()) {
            $this->setRedirect($url, Text::_('COM_OTPLOGIN_TG_TEST_DISABLED'), 'warning');

            return;
        }

        $site = rtrim(Uri::root(), '/');
        $host = (string) (parse_url($site, PHP_URL_HOST) ?: $site);
        $name = trim((string) $this->app->get('sitename', '')) ?: $host;
        $when = gmdate('Y-m-d H:i:s') . ' UTC';

        $text = "🧪 <b>تست اعلان OTP Login</b>\n"
            . "──────────────\n"
            . '🌐 <b>سایت:</b> ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . "\n"
            . '🔗 ' . htmlspecialchars($site, ENT_QUOTES, 'UTF-8') . "\n"
            . '🕒 ' . htmlspecialchars($when, ENT_QUOTES, 'UTF-8') . "\n"
            . '✅ اگر این پیام را می‌بینید، اتصال تلگرام برقرار است.';

        $result = $client->send($text);

        if (!empty($result['ok'])) {
            $this->setRedirect($url, Text::_('COM_OTPLOGIN_TG_TEST_OK'), 'message');

            return;
        }

        $err  = (string) ($result['error'] ?? 'unknown');
        $http = (string) ($result['http'] ?? '-');
        $body = trim((string) ($result['body'] ?? $result['detail'] ?? ''));
        if (mb_strlen($body) > 200) {
            $body = mb_substr($body, 0, 200) . '…';
        }

        $this->setRedirect(
            $url,
            Text::sprintf('COM_OTPLOGIN_TG_TEST_FAIL', $err, $http, $body !== '' ? $body : '—'),
            'error'
        );
    }

    /**
     * Real Bale test (tapi.bale.ai) — plain text, no proxy needed in Iran.
     */
    public function bale(): void
    {
        $this->checkToken();

        if (!$this->app->getIdentity()->authorise('core.manage', 'com_otplogin')) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $url    = Route::_('index.php?option=com_otplogin', false);
        $params = ComponentHelper::getParams('com_otplogin');
        $client = new BaleClient($params);

        if (!$client->isEnabled()) {
            $this->setRedirect($url, Text::_('COM_OTPLOGIN_BALE_TEST_DISABLED'), 'warning');

            return;
        }

        $site = rtrim(Uri::root(), '/');
        $host = (string) (parse_url($site, PHP_URL_HOST) ?: $site);
        $name = trim((string) $this->app->get('sitename', '')) ?: $host;
        $when = gmdate('Y-m-d H:i:s') . ' UTC';

        $text = "🧪 تست اعلان OTP Login (بله)\n"
            . "──────────────\n"
            . '🌐 سایت: ' . $name . "\n"
            . '🔗 ' . $site . "\n"
            . '🕒 ' . $when . "\n"
            . '✅ اگر این پیام را می‌بینید، اتصال بله برقرار است.';

        $result = $client->send($text);

        if (!empty($result['ok'])) {
            $this->setRedirect($url, Text::_('COM_OTPLOGIN_BALE_TEST_OK'), 'message');

            return;
        }

        $err  = (string) ($result['error'] ?? 'unknown');
        $http = (string) ($result['http'] ?? '-');
        $body = trim((string) ($result['body'] ?? $result['detail'] ?? ''));
        if (mb_strlen($body) > 200) {
            $body = mb_substr($body, 0, 200) . '…';
        }

        $this->setRedirect(
            $url,
            Text::sprintf('COM_OTPLOGIN_BALE_TEST_FAIL', $err, $http, $body !== '' ? $body : '—'),
            'error'
        );
    }
}

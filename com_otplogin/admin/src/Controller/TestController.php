<?php
/**
 * @package     Otplogin
 * @license     GNU General Public License version 2 or later
 */

namespace Otplogin\Component\Otplogin\Administrator\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Otplogin\Component\Otplogin\Site\Service\SmsGateway;
use Otplogin\Component\Otplogin\Site\Service\PhoneHelper;

/** Sends one real test SMS and reports exactly what IPPanel answered. */
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
}

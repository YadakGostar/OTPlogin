<?php
/**
 * @package     Otplogin
 * @license     GNU General Public License version 2 or later
 */

namespace Otplogin\Component\Otplogin\Administrator\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Otplogin\Component\Otplogin\Site\Service\ErrorLogger;

class DisplayController extends BaseController
{
    protected $default_view = 'dashboard';

    /** Admin: wipe diagnostic error log. */
    public function clearlog(): void
    {
        if (!Session::checkToken() && !Session::checkToken('get')) {
            $this->app->enqueueMessage(Text::_('JINVALID_TOKEN'), 'error');
            $this->setRedirect(Route::_('index.php?option=com_otplogin', false));

            return;
        }

        $user = $this->app->getIdentity();

        if (!$user->authorise('core.admin', 'com_otplogin') && !$user->authorise('core.manage', 'com_otplogin')) {
            $this->app->enqueueMessage(Text::_('JERROR_ALERTNOAUTHOR'), 'error');
            $this->setRedirect(Route::_('index.php?option=com_otplogin', false));

            return;
        }

        if (ErrorLogger::clear()) {
            $this->app->enqueueMessage(Text::_('COM_OTPLOGIN_LOG_CLEARED'), 'message');
        } else {
            $this->app->enqueueMessage(Text::_('COM_OTPLOGIN_LOG_CLEAR_FAIL'), 'error');
        }

        $this->setRedirect(Route::_('index.php?option=com_otplogin', false));
    }
}

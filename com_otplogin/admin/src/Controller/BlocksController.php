<?php
/**
 * @package     Otplogin
 * @license     GNU General Public License version 2 or later
 */

namespace Otplogin\Component\Otplogin\Administrator\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\Database\DatabaseInterface;

/** Lifts temporary phone / IP blocks from the dashboard. */
class BlocksController extends BaseController
{
    /** Removes one block (id) or every active block (id = 0). */
    public function unblock(): void
    {
        $this->checkToken();

        if (!$this->app->getIdentity()->authorise('core.manage', 'com_otplogin')) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $id = $this->input->getInt('id', 0);

        $db->setQuery(
            'DELETE FROM ' . $db->quoteName('#__otplogin_blocks') . ($id > 0 ? ' WHERE `id` = ' . $id : '')
        )->execute();

        $this->setRedirect(Route::_('index.php?option=com_otplogin', false), Text::_('COM_OTPLOGIN_UNBLOCKED'));
    }
}

<?php
/**
 * @package     Otplogin
 * @license     GNU General Public License version 2 or later
 */

namespace Otplogin\Plugin\Authentication\Otplogin\Extension;

\defined('_JEXEC') or die;

use Joomla\CMS\Authentication\Authentication;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;

/**
 * Shared logic. A login is accepted only when ALL of these hold:
 *  - the request carries the server-side option "otplogin" (cannot be set from a login form),
 *  - the front-end client is used (never the administrator),
 *  - the password is a one-time ticket that com_otplogin stored in this very session
 *    a few seconds earlier, after a correct SMS code. The ticket is deleted on first use.
 */
trait TicketAuthTrait
{
    /** @return bool true when this plugin handled the request (success or failure) */
    protected function authenticateTicket(array $credentials, array $options, object $response): bool
    {
        if (empty($options['otplogin'])) {
            return false;
        }

        $app = Factory::getApplication();

        try {
            $lang = $app->getLanguage();
            $lang->load('plg_authentication_otplogin', JPATH_ADMINISTRATOR, $lang->getTag(), true);
            $lang->load('plg_authentication_otplogin', JPATH_PLUGINS . '/authentication/otplogin', $lang->getTag(), true);
            $lang->load('com_otplogin', JPATH_SITE, $lang->getTag(), true);
            $lang->load('com_otplogin', JPATH_SITE, 'fa-IR', true);
        } catch (\Throwable $e) {
        }

        $response->type   = 'OTPLogin';
        $response->status = Authentication::STATUS_FAILURE;

        if (!$app->isClient('site')) {
            $response->error_message = Text::_('PLG_AUTHENTICATION_OTPLOGIN_INVALID');

            return true;
        }

        $session = $app->getSession();
        $ticket  = $session->get('otplogin.ticket');
        $session->clear('otplogin.ticket'); // single use, whatever happens next

        $given = (string) ($credentials['password'] ?? '');
        $valid = \is_array($ticket)
            && $given !== ''
            && (int) ($ticket['exp'] ?? 0) >= time()
            && hash_equals((string) ($ticket['hash'] ?? ''), hash('sha256', $given))
            && (string) ($ticket['username'] ?? '') === (string) ($credentials['username'] ?? '');

        if (!$valid) {
            $response->error_message = Text::_('PLG_AUTHENTICATION_OTPLOGIN_INVALID');

            return true;
        }

        $db     = Factory::getContainer()->get(DatabaseInterface::class);
        $userId = (int) $db->setQuery(
            'SELECT `id` FROM ' . $db->quoteName('#__users')
            . ' WHERE `username` = ' . $db->quote((string) $credentials['username'])
            . ' LIMIT 1'
        )->loadResult();

        $user = $userId ? Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById($userId) : null;

        if (!$user || !(int) $user->id) {
            $response->error_message = Text::_('PLG_AUTHENTICATION_OTPLOGIN_INVALID');

            return true;
        }

        // Required for Joomla login pipeline (missing username causes downstream errors)
        $response->username      = (string) $user->username;
        $response->email         = (string) $user->email;
        $response->fullname      = (string) $user->name;
        $response->password      = $given;
        $response->password_clear = '';

        try {
            $response->language = (string) $user->getParam('language', '');
        } catch (\Throwable $e) {
            $response->language = '';
        }

        if ($app->get('offline') && !$user->authorise('core.login.offline')) {
            $response->error_message = Text::_('PLG_AUTHENTICATION_OTPLOGIN_INVALID');

            return true;
        }

        $response->status        = Authentication::STATUS_SUCCESS;
        $response->error_message = '';

        return true;
    }
}

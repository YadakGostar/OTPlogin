<?php
/**
 * @package     Otplogin
 * @license     GNU General Public License version 2 or later
 */

namespace Otplogin\Plugin\Authentication\Otplogin\Extension;

\defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;

/** Joomla 4.x: classic positional-argument plugin method. */
final class OtploginLegacy extends CMSPlugin
{
    use TicketAuthTrait;

    public function onUserAuthenticate($credentials, $options, &$response)
    {
        $this->authenticateTicket((array) $credentials, (array) $options, $response);
    }
}

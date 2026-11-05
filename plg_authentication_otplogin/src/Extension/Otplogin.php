<?php
/**
 * @package     Otplogin
 * @license     GNU General Public License version 2 or later
 
 * @copyright (C) 2026 Reza Esfandiari
 * @author    Reza Esfandiari
 * @link      https://www.yadakgostar.co*/

namespace Otplogin\Plugin\Authentication\Otplogin\Extension;

\defined('_JEXEC') or die;

use Joomla\CMS\Authentication\Authentication;
use Joomla\CMS\Event\User\AuthenticationEvent;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\SubscriberInterface;

/** Joomla 5 / 6: event-object based plugin. */
final class Otplogin extends CMSPlugin implements SubscriberInterface
{
    use TicketAuthTrait;

    public static function getSubscribedEvents(): array
    {
        return ['onUserAuthenticate' => 'onUserAuthenticate'];
    }

    public function onUserAuthenticate(AuthenticationEvent $event): void
    {
        $response = $event->getAuthenticationResponse();

        if (
            $this->authenticateTicket($event->getCredentials(), $event->getOptions(), $response)
            && $response->status === Authentication::STATUS_SUCCESS
        ) {
            $event->stopPropagation();
        }
    }
}

<?php
/**
 * @package     Otplogin
 * @license     GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Version;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use Otplogin\Plugin\User\Otploginavatar\Extension\Otploginavatar;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                $config = (array) PluginHelper::getPlugin('user', 'otploginavatar');
                $major  = (int) Version::MAJOR_VERSION;

                if ($major >= 6) {
                    $plugin = new Otploginavatar($config);
                } else {
                    $plugin = new Otploginavatar(
                        $container->get(DispatcherInterface::class),
                        $config
                    );
                }

                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            }
        );
    }
};

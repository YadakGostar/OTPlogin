<?php
/**
 * @package     Otplogin
 * @license     GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Helper\ModuleHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;

/** @var \Joomla\Registry\Registry $params */
/** @var \stdClass $module */

$app  = Factory::getApplication();
$user = $app->getIdentity();

// The component owns the settings; the module only renders the form.
$config = ComponentHelper::getParams('com_otplogin');

// Load the component's front-end strings (error messages are produced there).
$app->getLanguage()->load('com_otplogin', JPATH_SITE);

$wa = $app->getDocument()->getWebAssetManager();
$wa->registerAndUseStyle('mod_otplogin', 'media/mod_otplogin/css/otplogin.css', ['version' => '1.0.0']);
$wa->registerAndUseScript('mod_otplogin', 'media/mod_otplogin/js/otplogin.js', ['version' => '1.0.0'], ['defer' => true]);

$mode        = $params->get('mode', 'inline') === 'modal' ? 'modal' : 'inline';
$moduleId    = (int) $module->id;
$tokenName   = Session::getFormToken();
$endpoint    = Uri::root(true) . '/index.php?option=com_otplogin&format=json';
$codeLength  = max(4, min(8, (int) $config->get('code_length', 5)));
$timestamp   = time();
$menuId      = (int) $params->get('redirect_menu', 0);
$returnUrl   = $menuId
    ? Route::_('index.php?Itemid=' . $menuId, false, Route::TLS_IGNORE, true)
    : Uri::getInstance()->toString(['scheme', 'host', 'port', 'path', 'query']);
$heading     = trim((string) $params->get('heading', Text::_('MOD_OTPLOGIN_HEADING')));
$buttonLabel = trim((string) $params->get('button_label', '')) ?: Text::_('MOD_OTPLOGIN_OPEN');
$theme       = $params->get('theme', 'default') === 'style3' ? 'style3' : 'default';
$moduleClass = htmlspecialchars(trim((string) $params->get('moduleclass_sfx', '') . ($theme === 'style3' ? ' style3' : '')), ENT_QUOTES, 'UTF-8');

// Template overrides in templates/<template>/html/mod_otplogin/default.php are still picked up by Joomla.
require ModuleHelper::getLayoutPath('mod_otplogin', 'default');

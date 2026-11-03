<?php
/**
 * @package     Otplogin
 * @license     GNU General Public License version 2 or later
 * @copyright (C) 2026 Reza Esfandiari.
 * @author    Reza Esfandiari
 * @link      https://www.yadakgostar.co
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
$wa->registerAndUseStyle('mod_otplogin', 'media/mod_otplogin/css/otplogin.css', ['version' => '1.1.18']);
$wa->registerAndUseScript('mod_otplogin', 'media/mod_otplogin/js/otplogin.js', ['version' => '1.1.18'], ['defer' => true]);

$mode        = $params->get('mode', 'inline') === 'modal' ? 'modal' : 'inline';
$moduleId    = (int) $module->id;
$tokenName   = Session::getFormToken();
$endpoint    = Uri::root(true) . '/index.php?option=com_otplogin&format=json';
$codeLength  = max(4, min(8, (int) $config->get('code_length', 5)));
$timestamp   = time();
$menuId = (int) $params->get('redirect_menu', 0);
// Prefer Joomla ?return= (base64) when present, e.g. /login?return=aHR0cHM6Ly9...
$returnUrl = '';
$rawReturn = (string) $app->getInput()->get('return', '', 'BASE64');
if ($rawReturn === '') {
    $rawReturn = (string) $app->getInput()->get('return', '', 'STRING');
}
if ($rawReturn !== '') {
    $decoded = base64_decode(strtr($rawReturn, '-_', '+/'), true);
    if ($decoded === false) {
        $decoded = base64_decode($rawReturn, true);
    }
    if (is_string($decoded) && $decoded !== '' && Uri::isInternal($decoded)) {
        $returnUrl = $decoded;
    }
}
if ($returnUrl === '' && $menuId > 0) {
    $returnUrl = Route::_('index.php?Itemid=' . $menuId, false, Route::TLS_IGNORE, true);
}
// Empty $returnUrl = stay on the same page after login (no forced home redirect)

// Avoid 4SEO / crawler issues on ?return= URLs (duplicate / soft parameters)
if ($rawReturn !== '' || $app->getInput()->get('return', '', 'STRING') !== '') {
    try {
        $doc = $app->getDocument();
        if (method_exists($doc, 'setMetaData')) {
            $doc->setMetaData('robots', 'noindex, nofollow');
        }
        // Canonical = same path without return (and without other junk query if only return mattered)
        $canon = Uri::getInstance();
        $canon->delVar('return');
        $canonUrl = $canon->toString(['scheme', 'host', 'port', 'path', 'query']);
        // strip trailing ? if query empty
        $canonUrl = rtrim($canonUrl, '?');
        if (method_exists($doc, 'addCustomTag')) {
            $doc->addCustomTag('<link rel="canonical" href="' . htmlspecialchars($canonUrl, ENT_QUOTES, 'UTF-8') . '" />');
        }
        // Hint for some SEO extensions that respect X-Robots-Tag via headers is optional; meta is enough for 4SEO
    } catch (\Throwable $e) {
        // ignore
    }
}


$heading     = trim((string) $params->get('heading', Text::_('MOD_OTPLOGIN_HEADING')));
$buttonLabel = trim((string) $params->get('button_label', '')) ?: Text::_('MOD_OTPLOGIN_OPEN');
$theme       = $params->get('theme', 'default') === 'style3' ? 'style3' : 'default';
$moduleClass = htmlspecialchars(trim((string) $params->get('moduleclass_sfx', '') . ($theme === 'style3' ? ' style3' : '')), ENT_QUOTES, 'UTF-8');

// Template overrides in templates/<template>/html/mod_otplogin/default.php are still picked up by Joomla.
require ModuleHelper::getLayoutPath('mod_otplogin', 'default');

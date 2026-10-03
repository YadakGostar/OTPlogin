<?php
/**
 * @package     Otplogin
 * @license     GNU General Public License version 2 or later
 */

namespace Otplogin\Plugin\User\Otploginavatar\Extension;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\EventInterface;
use Joomla\Event\SubscriberInterface;

/**
 * Shows OTP Login personnel photo on the Joomla site user profile page.
 * Uses onAfterRender (reliable on Joomla 6) instead of onContentPrepare.
 */
final class Otploginavatar extends CMSPlugin implements SubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            'onAfterRender' => 'handleAfterRender',
        ];
    }

    /** @param  EventInterface|null  $event */
    public function handleAfterRender($event = null): void
    {
        $app = Factory::getApplication();

        if (!$app->isClient('site')) {
            return;
        }

        $input = $app->getInput();

        if ($input->getCmd('option') !== 'com_users') {
            return;
        }

        $view   = $input->getCmd('view', '');
        $layout = $input->getCmd('layout', 'default');
        $task   = $input->getCmd('task', '');

        $isProfile = ($view === 'profile')
            || ($task === 'profile.edit')
            || ($view === '' && $layout === 'edit');

        if (!$isProfile) {
            return;
        }

        $userId = $input->getInt('id', 0);

        if ($userId < 1) {
            $userId = $input->getInt('user_id', 0);
        }

        if ($userId < 1) {
            $userId = (int) $app->getIdentity()->id;
        }

        if ($userId < 1) {
            return;
        }

        $url = self::avatarUrl($userId);

        if ($url === '') {
            return;
        }

        $body = $app->getBody();

        if ($body === '' || str_contains($body, 'otplogin-profile-avatar')) {
            return;
        }

        $alt  = htmlspecialchars(Text::_('PLG_USER_OTPLOGINAVATAR_ALT'), ENT_QUOTES, 'UTF-8');
        $src  = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $html = "\n"
            . '<div class="otplogin-profile-avatar" style="text-align:center;margin:1rem auto 1.5rem;max-width:100%;">'
            . '<img src="' . $src . '" alt="' . $alt . '" width="128" height="128" loading="lazy" decoding="async"'
            . ' style="width:128px;height:128px;object-fit:cover;border-radius:50%;'
            . 'box-shadow:0 8px 24px rgba(102,126,234,.35);border:3px solid #fff;background:#eef2ff;" />'
            . '</div>'
            . "\n";

        $injected = false;
        $needles  = [
            '<div class="com-users-profile',
            'class="com-users-profile"',
            '<div class="profile"',
            'id="users-profile-custom"',
            'class="page-header"',
            '<h1',
            '<main',
            '<div class="item-page',
        ];

        foreach ($needles as $needle) {
            $pos = stripos($body, $needle);
            if ($pos === false) {
                continue;
            }
            $gt = strpos($body, '>', $pos);
            if ($gt === false) {
                continue;
            }
            $body     = substr($body, 0, $gt + 1) . $html . substr($body, $gt + 1);
            $injected = true;
            break;
        }

        if (!$injected) {
            $count = 0;
            $body  = preg_replace('/<\/body>/i', $html . '</body>', $body, 1, $count);
            $injected = $count > 0;
        }

        if ($injected) {
            $app->setBody($body);
        }
    }

    /** Public helper for templates (Hikashop overrides, etc.). */
    public static function avatarUrl(int $userId): string
    {
        if ($userId < 1) {
            return '';
        }

        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);

            $keys = [
                'otplogin.avatar',
                'profile.otplogin_avatar',
                'profile.avatar',
            ];

            $quoted = implode(',', array_map(static fn ($k) => $db->quote($k), $keys));

            $rows = $db->setQuery(
                'SELECT `profile_key`, `profile_value` FROM ' . $db->quoteName('#__user_profiles')
                . ' WHERE `user_id` = ' . $userId
                . ' AND `profile_key` IN (' . $quoted . ')'
            )->loadObjectList() ?: [];

            $map = [];
            foreach ($rows as $row) {
                $map[(string) $row->profile_key] = trim((string) $row->profile_value, "\" \t\n\r");
            }

            $path = '';
            foreach ($keys as $key) {
                if (!empty($map[$key])) {
                    $path = $map[$key];
                    break;
                }
            }

            if ($path !== '' && ($path[0] ?? '') === '"') {
                $decoded = json_decode($path);
                if (\is_string($decoded)) {
                    $path = $decoded;
                }
            }

            $rel = ltrim(str_replace('\\', '/', $path), '/');

            if ($rel === '' || !is_file(JPATH_ROOT . '/' . $rel)) {
                return '';
            }

            $ver = @filemtime(JPATH_ROOT . '/' . $rel) ?: time();

            return Uri::root(true) . '/' . $rel . '?v=' . $ver;
        } catch (\Throwable $e) {
            return '';
        }
    }
}

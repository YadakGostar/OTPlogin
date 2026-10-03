<?php
/**
 * @package     Otplogin
 * @license     GNU General Public License version 2 or later
 */

namespace Otplogin\Component\Otplogin\Administrator\Field;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;

/**
 * Small "restore defaults" icon for one box (tab) of the component options.
 * It only fills the form; nothing is stored until the administrator presses Save.
 * IPPanel credentials and the Telegram token / chat id are deliberately never part of it.
 */
final class DefaultresetField extends FormField
{
    protected $type = 'Defaultreset';

    /** One delegated click handler for every reset icon on the page. */
    public static function script(): string
    {
        return <<<'JS'
(function () {
  if (window.OtpLoginReset) { return; }
  window.OtpLoginReset = true;

  function setValue(form, name, value) {
    var changed = false;
    var nodes = form.querySelectorAll('[name="jform[' + name + ']"], [name="jform[' + name + '][]"]');
    if (!nodes.length) {
      var byId = form.querySelector('#jform_' + name);
      nodes = byId ? [byId] : [];
    }
    Array.prototype.forEach.call(nodes, function (el) {
      var type = (el.type || '').toLowerCase();
      if (type === 'radio' || type === 'checkbox') {
        var on = String(el.value) === value;
        if (el.checked !== on) { el.checked = on; changed = true; }
        if (on) { el.dispatchEvent(new Event('change', { bubbles: true })); }
      } else if (el.value !== value) {
        el.value = value;
        el.dispatchEvent(new Event('input', { bubbles: true }));
        el.dispatchEvent(new Event('change', { bubbles: true }));
        changed = true;
      }
    });
    return nodes.length > 0;
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('[data-otp-reset]') : null;
    if (!btn) { return; }
    e.preventDefault();

    if (!window.confirm(btn.getAttribute('data-confirm') || '')) { return; }

    var form = btn.closest('form');
    var data = {};
    try { data = JSON.parse(btn.getAttribute('data-defaults') || '{}'); } catch (err) { return; }
    if (!form) { return; }

    Object.keys(data).forEach(function (name) { setValue(form, name, String(data[name])); });

    var icon = btn.querySelector('span');
    if (icon) {
      var old = icon.className;
      icon.className = 'icon-check';
      setTimeout(function () { icon.className = old; }, 1400);
    }
  });
})();
JS;
    }

    protected function getLabel()
    {
        return '';
    }

    protected function getInput(): string
    {
        $section  = (string) ($this->element['section'] ?? '');
        $defaults = self::defaults()[$section] ?? [];

        if ($section === '' || !$defaults) {
            return '';
        }

        $wa = Factory::getApplication()->getDocument()->getWebAssetManager();
        $wa->addInlineStyle(
            '.otp-reset{display:inline-flex;align-items:center;justify-content:center;width:2.2rem;height:2.2rem;padding:0;'
            . 'border:1px solid #cbd5e1;border-radius:50%;background:#fff;color:#475569;cursor:pointer;line-height:1}'
            . '.otp-reset:hover{background:#f1f5f9;border-color:#64748b;color:#0f172a}'
            . '.otp-reset:focus-visible{outline:2px solid rgba(37,99,235,.35);outline-offset:2px}'
        );
        $wa->addInlineScript(self::script());

        $label   = Text::_('COM_OTPLOGIN_RESET_DEFAULTS');
        $confirm = Text::_('COM_OTPLOGIN_RESET_DEFAULTS_CONFIRM');
        $json    = json_encode($defaults, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return '<button type="button" class="otp-reset" data-otp-reset'
            . ' data-defaults="' . htmlspecialchars((string) $json, ENT_QUOTES, 'UTF-8') . '"'
            . ' data-confirm="' . htmlspecialchars($confirm, ENT_QUOTES, 'UTF-8') . '"'
            . ' title="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '"'
            . ' aria-label="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '">'
            . '<span class="icon-undo" aria-hidden="true"></span>'
            . '</button>';
    }

    private static function defaults(): array
    {
        return [
            'otp' => [
                'code_length'         => 5,
                'code_ttl'            => 120,
                'max_verify_attempts' => 5,
            ],
            'limits' => [
                'resend_cooldown'      => 120,
                'phone_window_minutes' => 10,
                'phone_window_max'     => 3,
                'phone_daily_max'      => 10,
                'ip_hourly_max'        => 15,
                'ip_daily_max'         => 40,
                'global_daily_max'     => 0,
                'phone_fail_max'       => 8,
                'ip_fail_max'          => 20,
                'block_minutes'        => 30,
                'ip_register_max'      => 5,
                'min_form_seconds'     => 0,
                'ip_header'            => '',
            ],
            'users' => [
                'registration_enabled' => 1,
                'new_user_group'       => 2,
                'ask_name'             => 'required',
                'ask_email'            => 'optional',
                'fake_email_domain'    => '',
                'link_enabled'         => 1,
                'block_superusers'     => 1,
                'lookup_username'      => 1,
                'profile_key'          => 'otplogin.phone',
                'lookup_field_id'      => 0,
                'redirect_url'         => '',
            ],
            'password' => [
                'password_login'       => 1,
                'password_set'         => 'optional',
                'password_min_length'  => 8,
                'password_fail_max'    => 5,
                'password_ip_fail_max' => 15,
                'lookup_hourly_max'    => 30,
            ],
            'telegram' => [
                'telegram_enabled'        => 0,
                'telegram_timeout'        => 15,
                'telegram_on_sms_error'   => 1,
                'telegram_on_login'       => 0,
                'telegram_on_register'    => 1,
                'telegram_on_verify_fail' => 0,
            ],
        ];
    }
}

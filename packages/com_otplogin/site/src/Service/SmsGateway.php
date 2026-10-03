<?php
/**
 * @package     Otplogin
 * @license     GNU General Public License version 2 or later
 */

namespace Otplogin\Component\Otplogin\Site\Service;

\defined('_JEXEC') or die;

use Joomla\Registry\Registry;

/** Routes OTP SMS to IPPanel (Iran) or Twilio (international). */
final class SmsGateway
{
    private IppanelClient $ippanel;
    private TwilioClient $twilio;

    public function __construct(private Registry $params)
    {
        $this->ippanel = new IppanelClient($params);
        $this->twilio  = new TwilioClient($params);
    }

    public function provider(): string
    {
        $p = strtolower(trim((string) $this->params->get('sms_provider', 'ippanel')));

        return \in_array($p, ['ippanel', 'twilio'], true) ? $p : 'ippanel';
    }

    public function isConfigured(): bool
    {
        return $this->provider() === 'twilio'
            ? $this->twilio->isConfigured()
            : $this->ippanel->isConfigured();
    }

    public function apiKeySource(): string
    {
        return $this->provider() === 'twilio'
            ? ($this->twilio->isConfigured() ? 'twilio' : 'none')
            : $this->ippanel->apiKeySource();
    }

    /**
     * @return array{ok:bool, id?:int|string|null, error?:string, http?:int, detail?:string, body?:string}
     */
    public function sendCode(string $phone, string $code): array
    {
        if ((int) $this->params->get('dry_run', 0) === 1) {
            return ['ok' => true, 'id' => 'dry-run', 'http' => 200, 'detail' => 'dry_run'];
        }

        return $this->provider() === 'twilio'
            ? $this->twilio->sendCode($phone, $code)
            : $this->ippanel->sendCode($phone, $code);
    }
}

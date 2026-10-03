<?php
/**
 * @package     Otplogin
 * @license     GNU General Public License version 2 or later
 */

namespace Otplogin\Component\Otplogin\Site\Service;

\defined('_JEXEC') or die;

use Joomla\Registry\Registry;

/**
 * Twilio Programmable Messaging API (international SMS).
 * POST https://api.twilio.com/2010-04-01/Accounts/{Sid}/Messages.json
 */
final class TwilioClient
{
    public function __construct(private Registry $params)
    {
    }

    public function isConfigured(): bool
    {
        return $this->accountSid() !== ''
            && $this->authToken() !== ''
            && trim((string) $this->params->get('twilio_from', '')) !== '';
    }

    public function accountSid(): string
    {
        $env = getenv('OTPLOGIN_TWILIO_SID');
        if (\is_string($env) && trim($env) !== '') {
            return trim($env);
        }

        return trim((string) $this->params->get('twilio_sid', ''));
    }

    public function authToken(): string
    {
        $env = getenv('OTPLOGIN_TWILIO_TOKEN');
        if (\is_string($env) && trim($env) !== '') {
            return trim($env);
        }

        return trim((string) $this->params->get('twilio_token', ''));
    }

    /**
     * @return array{ok:bool, id?:string|null, error?:string, http?:int, detail?:string, body?:string}
     */
    public function sendCode(string $phone, string $code): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'not_configured'];
        }

        if (!\function_exists('curl_init')) {
            return ['ok' => false, 'error' => 'curl_missing'];
        }

        $to = $this->e164($phone);
        if ($to === '') {
            return ['ok' => false, 'error' => 'phone'];
        }

        $from = trim((string) $this->params->get('twilio_from', ''));
        $body = trim((string) $this->params->get('twilio_body', ''));
        if ($body === '') {
            $body = 'Your verification code is: {code}';
        }
        $body = str_replace(['{code}', '{CODE}'], $code, $body);

        // WebOTP-friendly optional second line
        $domain = trim((string) $this->params->get('twilio_domain', ''));
        if ($domain !== '') {
            $body .= "\n@{$domain} #{$code}";
        }

        $sid  = $this->accountSid();
        $url  = 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($sid) . '/Messages.json';
        $post = http_build_query([
            'To'   => $to,
            'From' => $from,
            'Body' => $body,
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $post,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => max(3, min(30, (int) $this->params->get('request_timeout', 10))),
            CURLOPT_USERPWD        => $sid . ':' . $this->authToken(),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $raw  = (string) curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === '' && $err !== '') {
            return ['ok' => false, 'error' => 'sms_failed', 'http' => $http, 'detail' => $err];
        }

        $json = json_decode($raw, true);
        if ($http >= 200 && $http < 300 && \is_array($json) && !empty($json['sid'])) {
            return ['ok' => true, 'id' => (string) $json['sid'], 'http' => $http, 'body' => $raw];
        }

        $detail = \is_array($json) ? (string) ($json['message'] ?? $json['error_message'] ?? $raw) : $raw;

        return ['ok' => false, 'error' => 'sms_failed', 'http' => $http, 'detail' => $detail, 'body' => $raw];
    }

    /** Convert 09xxxxxxxxx / 989xxxxxxxxx to +989xxxxxxxxx when possible. */
    private function e164(string $phone): string
    {
        $d = preg_replace('/\D+/', '', $phone) ?: '';
        if ($d === '') {
            return '';
        }
        if (str_starts_with($d, '00')) {
            $d = substr($d, 2);
        }
        if (str_starts_with($d, '09') && \strlen($d) === 11) {
            $d = '98' . substr($d, 1);
        }
        if (str_starts_with($d, '9') && \strlen($d) === 10) {
            $d = '98' . $d;
        }

        return '+' . $d;
    }
}

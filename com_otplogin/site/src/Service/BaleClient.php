<?php
/**
 * @package     Otplogin
 * @license     GNU General Public License version 2 or later
 
 * @copyright (C) 2026 Reza Esfandiari
 * @author    Reza Esfandiari
 * @link      https://www.yadakgostar.co*/

namespace Otplogin\Component\Otplogin\Site\Service;

\defined('_JEXEC') or die;

use Joomla\CMS\Log\Log;
use Joomla\Registry\Registry;

/**
 * Bale Bot API client (Telegram-compatible API at tapi.bale.ai).
 * Works from Iranian servers without a proxy.
 */
final class BaleClient
{
    private const API_BASE = 'https://tapi.bale.ai/bot';

    public function __construct(private Registry $params)
    {
    }

    public function isEnabled(): bool
    {
        return (int) $this->params->get('bale_enabled', 0) === 1
            && trim((string) $this->params->get('bale_bot_token', '')) !== ''
            && trim((string) $this->params->get('bale_chat_id', '')) !== '';
    }

    /** Whether this event kind should produce a Bale message. */
    public function wants(string $kind): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        $map = [
            'sms_error'   => 'bale_on_sms_error',
            'login'       => 'bale_on_login',
            'login_pass'  => 'bale_on_login',
            'register'    => 'bale_on_register',
            'link'        => 'bale_on_login',
            'verify_fail' => 'bale_on_verify_fail',
            'pass_fail'   => 'bale_on_verify_fail',
        ];

        $param = $map[$kind] ?? null;

        if ($param === null) {
            return false;
        }

        return (int) $this->params->get($param, $kind === 'sms_error' ? 1 : 0) === 1;
    }

    /**
     * @return array{ok:bool, error?:string, http?:int, body?:string, detail?:string}
     */
    public function notify(string $kind, string $text): array
    {
        if (!$this->wants($kind)) {
            return ['ok' => false, 'error' => 'disabled'];
        }

        return $this->send($text);
    }

    /**
     * @return array{ok:bool, error?:string, http?:int, body?:string, detail?:string}
     */
    public function send(string $text): array
    {
        if (!$this->isEnabled()) {
            return ['ok' => false, 'error' => 'not_configured'];
        }

        if (!\function_exists('curl_init')) {
            return ['ok' => false, 'error' => 'no_curl'];
        }

        $token  = trim((string) $this->params->get('bale_bot_token'));
        $chatId = trim((string) $this->params->get('bale_chat_id'));
        $url    = self::API_BASE . $token . '/sendMessage';

        // Plain text by default: Bale HTML often collapses newlines into one line.
        $payload = [
            'chat_id'                  => $chatId,
            'text'                     => $text,
            'disable_web_page_preview' => true,
        ];

        $ch = curl_init($url);
        $opts = [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => max(3, min(12, (int) $this->params->get('bale_timeout', 5))),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        curl_setopt_array($ch, $opts);

        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        $json = \is_string($raw) ? json_decode($raw, true) : null;

        if ($status >= 200 && $status < 300 && \is_array($json) && ($json['ok'] ?? false) === true) {
            return ['ok' => true];
        }

        $detail = \is_array($json) ? (string) ($json['description'] ?? '') : $err;
        if ($detail === '' && $status === 0) {
            $detail = $err !== '' ? $err : 'connection failed (timeout, DNS, SSL or blocked)';
        }

        Log::add(
            'Bale notify failed (http ' . $status . '): ' . $detail,
            Log::WARNING,
            'com_otplogin'
        );

        return [
            'ok'     => false,
            'error'  => $status === 0 ? 'connection' : 'api',
            'http'   => $status,
            'body'   => $detail !== '' ? substr($detail, 0, 300) : (\is_string($raw) ? substr($raw, 0, 300) : ''),
            'detail' => $detail,
        ];
    }
}

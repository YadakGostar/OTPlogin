<?php
/**
 * @package     Otplogin
 * @license     GNU General Public License version 2 or later
 */

namespace Otplogin\Component\Otplogin\Site\Service;

\defined('_JEXEC') or die;

use Joomla\CMS\Log\Log;
use Joomla\Registry\Registry;

/**
 * Telegram Bot API client with optional HTTP/SOCKS proxy
 * (useful when api.telegram.org is filtered).
 */
final class TelegramClient
{
    public function __construct(private Registry $params)
    {
    }

    public function isEnabled(): bool
    {
        return (int) $this->params->get('telegram_enabled', 0) === 1
            && trim((string) $this->params->get('telegram_bot_token', '')) !== ''
            && trim((string) $this->params->get('telegram_chat_id', '')) !== '';
    }

    /** Whether this event kind should produce a Telegram message. */
    public function wants(string $kind): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        $map = [
            'sms_error'   => 'telegram_on_sms_error',
            'login'       => 'telegram_on_login',
            'login_pass'  => 'telegram_on_login',
            'register'    => 'telegram_on_register',
            'link'        => 'telegram_on_login',
            'verify_fail' => 'telegram_on_verify_fail',
            'pass_fail'   => 'telegram_on_verify_fail',
        ];

        $param = $map[$kind] ?? null;

        if ($param === null) {
            return false;
        }

        return (int) $this->params->get($param, $kind === 'sms_error' ? 1 : 0) === 1;
    }

    /**
     * Fire-and-forget notification. Failures are logged; they never break login flow.
     *
     * @return array{ok:bool, error?:string, http?:int}
     */
    public function notify(string $kind, string $text): array
    {
        if (!$this->wants($kind)) {
            return ['ok' => false, 'error' => 'disabled'];
        }

        return $this->send($text);
    }

    /**
     * @return array{ok:bool, error?:string, http?:int, body?:string}
     */
    public function send(string $text): array
    {
        if (!$this->isEnabled()) {
            return ['ok' => false, 'error' => 'not_configured'];
        }

        if (!\function_exists('curl_init')) {
            return ['ok' => false, 'error' => 'no_curl'];
        }

        $token  = trim((string) $this->params->get('telegram_bot_token'));
        $chatId = trim((string) $this->params->get('telegram_chat_id'));
        $url    = 'https://api.telegram.org/bot' . $token . '/sendMessage';

        $payload = [
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ];

        $ch = curl_init($url);
        $opts = [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => max(5, (int) $this->params->get('telegram_timeout', 15)),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            // Help on filtered networks: try HTTP/1.1, follow redirects, verify TLS.
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        $proxy = trim((string) $this->params->get('telegram_proxy', ''));

        if ($proxy !== '') {
            $this->applyProxy($opts, $proxy);
        }

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
        Log::add(
            'Telegram notify failed (http ' . $status . '): ' . $detail,
            Log::WARNING,
            'com_otplogin'
        );

        return [
            'ok'     => false,
            'error'  => 'api',
            'http'   => $status,
            'body'   => \is_string($raw) ? substr($raw, 0, 300) : '',
        ];
    }

    /** @param array<int, mixed> $opts */
    private function applyProxy(array &$opts, string $proxy): void
    {
        // Accept: http://user:pass@host:port | https://host:port | socks5://host:port | host:port
        $proxy = trim($proxy);

        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $proxy)) {
            $proxy = 'http://' . $proxy;
        }

        $parts = parse_url($proxy);

        if ($parts === false || empty($parts['host'])) {
            Log::add('Invalid telegram_proxy URL', Log::WARNING, 'com_otplogin');

            return;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'http'));
        $host   = $parts['host'];
        $port   = (int) ($parts['port'] ?? 0);

        if (str_starts_with($scheme, 'socks5')) {
            $opts[CURLOPT_PROXYTYPE] = defined('CURLPROXY_SOCKS5_HOSTNAME')
                ? CURLPROXY_SOCKS5_HOSTNAME
                : CURLPROXY_SOCKS5;
            $opts[CURLOPT_PROXY] = $host . ($port ? ':' . $port : '');
        } elseif ($scheme === 'socks4') {
            $opts[CURLOPT_PROXYTYPE] = CURLPROXY_SOCKS4;
            $opts[CURLOPT_PROXY] = $host . ($port ? ':' . $port : '');
        } else {
            $opts[CURLOPT_PROXYTYPE] = CURLPROXY_HTTP;
            $opts[CURLOPT_PROXY] = $host . ($port ? ':' . $port : '');
        }

        if (!empty($parts['user'])) {
            $auth = rawurldecode($parts['user']);

            if (isset($parts['pass'])) {
                $auth .= ':' . rawurldecode($parts['pass']);
            }

            $opts[CURLOPT_PROXYUSERPWD] = $auth;
        }
    }
}

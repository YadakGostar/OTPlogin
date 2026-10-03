<?php
/**
 * @package     Otplogin
 * @license     GNU General Public License version 2 or later
 */

namespace Otplogin\Component\Otplogin\Site\Service;

\defined('_JEXEC') or die;

use Joomla\Registry\Registry;

/**
 * IPPanel Edge API client (pattern based sending).
 * POST {base_url}/api/send with "Authorization: <API key>".
 */
final class IppanelClient
{
    public function __construct(private Registry $params)
    {
    }

    public function isConfigured(): bool
    {
        return $this->apiKey() !== ''
            && trim((string) $this->params->get('pattern_code', '')) !== ''
            && trim((string) $this->params->get('from_number', '')) !== '';
    }

    /**
     * Resolve API key: env OTPLOGIN_IPPANEL_KEY, then configuration.php constant, then params.
     */
    public function apiKey(): string
    {
        $env = getenv('OTPLOGIN_IPPANEL_KEY');
        if (\is_string($env) && trim($env) !== '') {
            return trim($env);
        }
        if (\defined('OTPLOGIN_IPPANEL_KEY') && OTPLOGIN_IPPANEL_KEY) {
            return trim((string) OTPLOGIN_IPPANEL_KEY);
        }
        $key = trim((string) $this->params->get('api_key', ''));
        return preg_replace('/^(?:Bearer|API\s*TOKEN)\s*[:=]?\s+/i', '', $key) ?: $key;
    }

    public function apiKeySource(): string
    {
        $env = getenv('OTPLOGIN_IPPANEL_KEY');
        if (\is_string($env) && trim($env) !== '') {
            return 'env';
        }
        if (\defined('OTPLOGIN_IPPANEL_KEY') && OTPLOGIN_IPPANEL_KEY) {
            return 'config';
        }
        return trim((string) $this->params->get('api_key', '')) !== '' ? 'params' : 'none';
    }

    /**
     * @return array{ok:bool, id?:int|null, error?:string, http?:int, detail?:string, body?:string}
     */
    public function sendCode(string $phone, string $code): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'not_configured'];
        }

        // IPPanel Edge uses https://edge.ippanel.com/v1 as the API base.
        // The actual SMS endpoint is /v1/api/send; the client appends /api/send below.
        $base = rtrim(trim((string) $this->params->get('base_url', 'https://edge.ippanel.com/v1')), '/');
        if ($base === 'https://edge.ippanel.com' || $base === 'https://edge.ippanel.com/v1/api/send') {
            $base = 'https://edge.ippanel.com/v1';
        }
        $base = preg_replace('~/api/send$~i', '', $base) ?: $base;

        // The API key travels in a header, so never allow plain HTTP.
        if (!str_starts_with($base, 'https://')) {
            return ['ok' => false, 'error' => 'insecure_url'];
        }

        if (!\function_exists('curl_init')) {
            return ['ok' => false, 'error' => 'no_curl'];
        }

        $var  = trim((string) $this->params->get('pattern_var', 'code')) ?: 'code';
        // The Edge API expects the raw API token in Authorization. Do not send
        // "Bearer " or "API TOKEN " prefixes if an administrator pasted one.
        $apiKey = $this->apiKey();
        $body = [
            'sending_type' => 'pattern',
            'from_number'  => trim((string) $this->params->get('from_number')),
            'code'         => trim((string) $this->params->get('pattern_code')),
            'recipients'   => [PhoneHelper::e164($phone)],
            'params'       => [$var => $code],
        ];

        $endpoint = $base . '/api/send';
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => max(3, (int) $this->params->get('request_timeout', 10)),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: ' . $apiKey,
            ],
        ]);

        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        $json = \is_string($raw) ? json_decode($raw, true) : null;

        if ($status >= 200 && $status < 300 && \is_array($json) && ($json['meta']['status'] ?? false) === true) {
            return ['ok' => true, 'id' => $json['data']['message_outbox_ids'][0] ?? null];
        }

        $detail = $json['meta']['message_code'] ?? $json['meta']['message'] ?? $err;

        return [
            'ok'     => false,
            'error'  => 'api',
            'http'   => $status,
            'detail' => (string) $detail,
            'body'   => \is_string($raw) ? substr($raw, 0, 500) : '',
        ];
    }
}

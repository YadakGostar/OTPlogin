<?php
/**
 * @package     Otplogin
 * @license     GNU General Public License version 2 or later
 */

namespace Otplogin\Component\Otplogin\Site\Service;

\defined('_JEXEC') or die;

use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;

/**
 * WebAuthn / passkey helpers (fingerprint, Face ID, Windows Hello).
 *
 * Biometric data never leaves the device. The server only stores a public key
 * and verifies cryptographic signatures. Requires HTTPS in production.
 *
 * This implementation supports platform authenticators (ES256 / P-256) which
 * cover the vast majority of phones and modern laptops.
 */
final class PasskeyService
{
    public function __construct(
        private DatabaseInterface $db,
        private Registry $params
    ) {
    }

    public function enabled(): bool
    {
        return (int) $this->params->get('passkey_enabled', 1) === 1;
    }

    /**
     * WebAuthn RP ID must equal the page hostname or be a registrable domain
     * suffix of it (e.g. example.com for www.example.com). Never a full URL.
     */
    public function rpId(?string $requestHost = null): string
    {
        $host = $this->normalizeHost($requestHost ?: $this->requestHost());

        $custom = $this->normalizeHost((string) $this->params->get('passkey_rp_id', ''));

        // Only honour a custom RP ID when it is valid for this host.
        if ($custom !== '' && $this->isValidRpIdForHost($custom, $host)) {
            return $custom;
        }

        // Prefer eTLD+1-style parent when on www. so passkeys work across www/non-www.
        // Still must be a suffix of the current host — www.example.com → example.com is OK.
        if (str_starts_with($host, 'www.') && substr_count($host, '.') >= 2) {
            $parent = substr($host, 4);

            if ($parent !== '' && $this->isValidRpIdForHost($parent, $host)) {
                return $parent;
            }
        }

        return $host !== '' ? $host : 'localhost';
    }

    public function rpName(): string
    {
        $name = trim((string) $this->params->get('passkey_rp_name', ''));

        return $name !== '' ? $name : 'OTP Login';
    }

    /**
     * Exact browser origin (scheme://host[:port]) — must match clientDataJSON.origin.
     */
    public function origin(?string $requestHost = null): string
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');

        $host = $this->normalizeHost($requestHost ?: $this->requestHost());

        if ($host === '') {
            $host = $this->normalizeHost((string) parse_url(Uri::root(), PHP_URL_HOST));
        }

        $port = '';
        if (!empty($_SERVER['SERVER_PORT'])) {
            $p = (int) $_SERVER['SERVER_PORT'];
            if (($https && $p !== 443 && $p !== 80) || (!$https && $p !== 80 && $p !== 443)) {
                // Only append non-default ports that appear in window.location.origin
                if (!str_contains($host, ':')) {
                    $port = ':' . $p;
                }
            }
        }

        return ($https ? 'https' : 'http') . '://' . $host . $port;
    }

    private function requestHost(): string
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '');

        return $this->normalizeHost($host);
    }

    private function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));
        $host = preg_replace('#^https?://#', '', $host) ?? $host;
        $host = explode('/', $host)[0];
        // Strip port for RP ID (ports are not part of RP ID).
        if (str_contains($host, ':') && !str_starts_with($host, '[')) {
            $host = explode(':', $host)[0];
        }

        return rtrim($host, '.');
    }

    /** RP ID equals host, or host ends with "." + rpId (and rpId has a dot or is localhost). */
    private function isValidRpIdForHost(string $rpId, string $host): bool
    {
        if ($rpId === '' || $host === '') {
            return false;
        }

        if ($rpId === $host) {
            return true;
        }

        // localhost and *.localhost
        if ($rpId === 'localhost' && ($host === 'localhost' || str_ends_with($host, '.localhost'))) {
            return true;
        }

        return str_ends_with($host, '.' . $rpId);
    }

    /** Compare clientDataJSON.origin with the current request origin (ignore trailing slash). */
    private function originMatches(string $clientOrigin): bool
    {
        $clientOrigin = rtrim(strtolower(trim($clientOrigin)), '/');
        $expected     = rtrim(strtolower($this->origin()), '/');

        if ($clientOrigin === $expected) {
            return true;
        }

        // localhost any port
        if (preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#', $clientOrigin)) {
            return true;
        }

        // Same host, scheme may differ only in misconfigured proxy cases — still require https match when not local
        $cHost = $this->normalizeHost((string) parse_url($clientOrigin, PHP_URL_HOST));
        $eHost = $this->requestHost();

        if ($cHost !== '' && $eHost !== '' && ($cHost === $eHost || str_ends_with($cHost, '.' . $eHost) || str_ends_with($eHost, '.' . $cHost))) {
            $cScheme = strtolower((string) parse_url($clientOrigin, PHP_URL_SCHEME));
            $eScheme = strtolower((string) parse_url($expected, PHP_URL_SCHEME));

            return $cScheme === $eScheme || $cScheme === 'https';
        }

        return false;
    }

    /** @return list<array{id:string,transports?:list<string>}> */
    public function credentialsForUser(int $userId): array
    {
        $rows = $this->db->setQuery(
            'SELECT `credential_id`, `transports` FROM ' . $this->db->quoteName('#__otplogin_webauthn')
            . ' WHERE `user_id` = ' . $userId
        )->loadObjectList() ?: [];

        $out = [];

        foreach ($rows as $row) {
            $item = ['id' => (string) $row->credential_id];
            $t = array_filter(array_map('trim', explode(',', (string) $row->transports)));

            if ($t) {
                $item['transports'] = array_values($t);
            }

            $out[] = $item;
        }

        return $out;
    }

    public function countForUser(int $userId): int
    {
        return (int) $this->db->setQuery(
            'SELECT COUNT(*) FROM ' . $this->db->quoteName('#__otplogin_webauthn')
            . ' WHERE `user_id` = ' . $userId
        )->loadResult();
    }

    /**
     * Options for navigator.credentials.create().
     *
     * @return array<string,mixed>
     */
    public function registrationOptions(int $userId, string $userName, string $displayName): array
    {
        $challenge = random_bytes(32);
        $exclude   = [];

        foreach ($this->credentialsForUser($userId) as $c) {
            // id must stay base64url string in JSON; the browser client converts to ArrayBuffer.
            $item = [
                'type' => 'public-key',
                'id'   => $c['id'],
            ];
            if (!empty($c['transports'])) {
                $item['transports'] = $c['transports'];
            }
            $exclude[] = $item;
        }

        return [
            'challenge'              => $this->b64url($challenge),
            'rp'                     => ['name' => $this->rpName(), 'id' => $this->rpId()],
            'user'                   => [
                'id'          => $this->b64url(pack('N', $userId) . random_bytes(12)),
                'name'        => $userName,
                'displayName' => $displayName !== '' ? $displayName : $userName,
            ],
            'pubKeyCredParams'       => [
                ['type' => 'public-key', 'alg' => -7],  // ES256
                ['type' => 'public-key', 'alg' => -257], // RS256
            ],
            'timeout'                => 120000,
            'attestation'            => 'none',
            'excludeCredentials'     => $exclude,
            'authenticatorSelection' => [
                // Prefer platform (fingerprint / Face ID) but allow security keys too.
                'residentKey'             => 'preferred',
                'requireResidentKey'      => false,
                'userVerification'        => 'required',
            ],
            '_challenge_raw'         => $this->b64url($challenge),
        ];
    }

    /**
     * Options for navigator.credentials.get().
     *
     * @return array<string,mixed>
     */
    public function authenticationOptions(?int $userId = null): array
    {
        $challenge = random_bytes(32);
        $allow     = [];

        if ($userId) {
            foreach ($this->credentialsForUser($userId) as $c) {
                $item = [
                    'type' => 'public-key',
                    'id'   => $c['id'],
                ];

                if (!empty($c['transports'])) {
                    $item['transports'] = $c['transports'];
                }

                $allow[] = $item;
            }
        }

        $opts = [
            'challenge'        => $this->b64url($challenge),
            'timeout'          => 120000,
            'rpId'             => $this->rpId(),
            'userVerification' => 'preferred',
            '_challenge_raw'   => $this->b64url($challenge),
        ];

        if ($allow) {
            $opts['allowCredentials'] = $allow;
        }

        return $opts;
    }

    /**
     * Persist a new credential after client registration.
     * Public key is expected as base64url of the SPKI DER (client converts COSE → SPKI).
     *
     * @return array{ok?:bool,error?:string}
     */
    public function storeCredential(int $userId, string $credentialId, string $publicKeySpkiB64, string $transports = '', string $label = ''): array
    {
        $credentialId = trim($credentialId);
        $publicKeySpkiB64 = trim($publicKeySpkiB64);

        if ($credentialId === '' || $publicKeySpkiB64 === '') {
            return ['error' => 'passkey_invalid'];
        }

        if (strlen($credentialId) > 512) {
            return ['error' => 'passkey_invalid'];
        }

        // Ensure it is unique.
        $exists = (int) $this->db->setQuery(
            'SELECT COUNT(*) FROM ' . $this->db->quoteName('#__otplogin_webauthn')
            . ' WHERE `credential_id` = ' . $this->db->quote($credentialId)
        )->loadResult();

        if ($exists > 0) {
            return ['error' => 'passkey_exists'];
        }

        $this->db->setQuery(
            'INSERT INTO ' . $this->db->quoteName('#__otplogin_webauthn')
            . ' (`user_id`, `credential_id`, `public_key`, `sign_count`, `transports`, `label`, `created_at`) VALUES ('
            . $userId . ', '
            . $this->db->quote($credentialId) . ', '
            . $this->db->quote($publicKeySpkiB64) . ', 0, '
            . $this->db->quote(substr($transports, 0, 128)) . ', '
            . $this->db->quote(substr($label !== '' ? $label : 'Device', 0, 100)) . ', '
            . time() . ')'
        )->execute();

        return ['ok' => true];
    }

    /**
     * Verify an assertion and return the owning user id, or null.
     *
     * Client must send:
     *  - credentialId (base64url)
     *  - authenticatorData (base64url)
     *  - clientDataJSON (base64url)
     *  - signature (base64url)
     *  - challenge (the one we issued, base64url)
     */
    public function verifyAssertion(
        string $credentialId,
        string $authenticatorDataB64,
        string $clientDataJSONB64,
        string $signatureB64,
        string $expectedChallengeB64
    ): ?int {
        $row = $this->db->setQuery(
            'SELECT * FROM ' . $this->db->quoteName('#__otplogin_webauthn')
            . ' WHERE `credential_id` = ' . $this->db->quote($credentialId) . ' LIMIT 1'
        )->loadObject();

        if (!$row) {
            return null;
        }

        $clientDataJSON = $this->b64urlDecode($clientDataJSONB64);
        $authData       = $this->b64urlDecode($authenticatorDataB64);
        $signature      = $this->b64urlDecode($signatureB64);
        $clientData     = json_decode($clientDataJSON, true);

        if (!is_array($clientData) || ($clientData['type'] ?? '') !== 'webauthn.get') {
            return null;
        }

        $chal = $clientData['challenge'] ?? '';

        if (!is_string($chal) || !hash_equals($expectedChallengeB64, $chal)) {
            return null;
        }

        $origin = (string) ($clientData['origin'] ?? '');

        if (!$this->originMatches($origin)) {
            return null;
        }

        if (strlen($authData) < 37) {
            return null;
        }

        // RP ID hash (first 32 bytes) must match sha256(rpId)
        $rpHash = substr($authData, 0, 32);
        $rpId   = $this->rpId();

        if (!hash_equals(hash('sha256', $rpId, true), $rpHash)) {
            // Also accept hash of the full request host (in case credential was created before www stripping).
            $host = $this->requestHost();
            if ($host === '' || !hash_equals(hash('sha256', $host, true), $rpHash)) {
                return null;
            }
        }

        $flags = ord($authData[32]);

        // User present (bit 0) is mandatory. UV (bit 2) is preferred but not required
        // because authenticatorSelection.userVerification is "preferred".
        if (($flags & 0x01) === 0) {
            return null;
        }

        $publicKeyPem = $this->spkiToPem($this->b64urlDecode((string) $row->public_key));

        if ($publicKeyPem === '') {
            // Fallback: treat stored value as already PEM or raw base64 DER.
            $raw = base64_decode((string) $row->public_key, true);

            if ($raw !== false) {
                $publicKeyPem = $this->spkiToPem($raw);
            }
        }

        if ($publicKeyPem === '') {
            return null;
        }

        $data = $authData . hash('sha256', $clientDataJSON, true);
        $ok   = openssl_verify($data, $signature, $publicKeyPem, OPENSSL_ALGO_SHA256);

        if ($ok !== 1) {
            // Some authenticators produce IEEE P1363 signatures; try converting to DER.
            $der = $this->p1363ToDer($signature);

            if ($der !== null) {
                $ok = openssl_verify($data, $der, $publicKeyPem, OPENSSL_ALGO_SHA256);
            }
        }

        if ($ok !== 1) {
            return null;
        }

        $this->db->setQuery(
            'UPDATE ' . $this->db->quoteName('#__otplogin_webauthn')
            . ' SET `last_used_at` = ' . time()
            . ' WHERE `id` = ' . (int) $row->id
        )->execute();

        return (int) $row->user_id;
    }

    public function deleteCredential(int $userId, string $credentialId): bool
    {
        $this->db->setQuery(
            'DELETE FROM ' . $this->db->quoteName('#__otplogin_webauthn')
            . ' WHERE `user_id` = ' . $userId
            . ' AND `credential_id` = ' . $this->db->quote($credentialId)
        )->execute();

        return $this->db->getAffectedRows() > 0;
    }

    public function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public function b64urlDecode(string $b64): string
    {
        $b64 = strtr($b64, '-_', '+/');
        $pad = strlen($b64) % 4;

        if ($pad) {
            $b64 .= str_repeat('=', 4 - $pad);
        }

        $raw = base64_decode($b64, true);

        return $raw === false ? '' : $raw;
    }

    private function spkiToPem(string $der): string
    {
        if ($der === '') {
            return '';
        }

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    /** Convert raw P-256 signature (r||s, 64 bytes) to ASN.1 DER. */
    private function p1363ToDer(string $sig): ?string
    {
        if (strlen($sig) !== 64) {
            return null;
        }

        $r = ltrim(substr($sig, 0, 32), "\x00");
        $s = ltrim(substr($sig, 32, 32), "\x00");

        if ($r === '' || $s === '') {
            return null;
        }

        if ((ord($r[0]) & 0x80) !== 0) {
            $r = "\x00" . $r;
        }

        if ((ord($s[0]) & 0x80) !== 0) {
            $s = "\x00" . $s;
        }

        $inner = "\x02" . chr(strlen($r)) . $r . "\x02" . chr(strlen($s)) . $s;

        return "\x30" . chr(strlen($inner)) . $inner;
    }
}

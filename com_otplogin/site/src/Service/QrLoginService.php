<?php
/**
 * @package     Otplogin
 * @license     GNU General Public License version 2 or later
 */

namespace Otplogin\Component\Otplogin\Site\Service;

\defined('_JEXEC') or die;

use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;

/**
 * Cross-device QR login (WhatsApp-style).
 *
 * Desktop creates a short-lived token bound to its IP + UA hash.
 * A logged-in phone confirms the token (after OTP/password on that device).
 * Desktop polls until status=approved, then signs in that user — only if the
 * polling client still matches the original IP (optional strict mode) and UA hash.
 *
 * MAC addresses are intentionally not used: browsers never expose them.
 */
final class QrLoginService
{
    public function __construct(
        private DatabaseInterface $db,
        private Registry $params
    ) {
    }

    public function enabled(): bool
    {
        return (int) $this->params->get('qr_login_enabled', 1) === 1;
    }

    public function ttl(): int
    {
        return max(30, min(300, (int) $this->params->get('qr_ttl', 90)));
    }

    public function bindIp(): bool
    {
        return (int) $this->params->get('qr_bind_ip', 1) === 1;
    }

    public function uaHash(?string $ua): string
    {
        return hash('sha256', (string) $ua);
    }

    /** @return array{token:string,expires_in:int} */
    public function create(string $ip, string $ua): array
    {
        $this->purge();
        $token = bin2hex(random_bytes(32));
        $ttl   = $this->ttl();
        $now   = time();

        $this->db->setQuery(
            'INSERT INTO ' . $this->db->quoteName('#__otplogin_qr')
            . ' (`token`, `status`, `ip`, `ua_hash`, `expires_at`, `created_at`) VALUES ('
            . $this->db->quote($token) . ', ' . $this->db->quote('pending') . ', '
            . $this->db->quote($ip) . ', ' . $this->db->quote($this->uaHash($ua)) . ', '
            . ($now + $ttl) . ', ' . $now . ')'
        )->execute();

        return ['token' => $token, 'expires_in' => $ttl];
    }

    /**
     * @return array{status:string,user_id?:int,error?:string}
     */
    public function status(string $token, string $ip, string $ua): array
    {
        $row = $this->row($token);

        if (!$row) {
            return ['status' => 'expired', 'error' => 'qr_expired'];
        }

        if ((int) $row->expires_at < time()) {
            $this->setStatus($token, 'expired');

            return ['status' => 'expired', 'error' => 'qr_expired'];
        }

        if ($this->bindIp() && $row->ip !== $ip) {
            return ['status' => 'denied', 'error' => 'qr_ip'];
        }

        if ($row->ua_hash !== '' && $row->ua_hash !== $this->uaHash($ua)) {
            return ['status' => 'denied', 'error' => 'qr_device'];
        }

        if ($row->status === 'approved' && (int) $row->user_id > 0) {
            // Consume: one-time use.
            $this->setStatus($token, 'used');

            return ['status' => 'approved', 'user_id' => (int) $row->user_id];
        }

        return ['status' => (string) $row->status];
    }

    /**
     * Phone (already authenticated) approves the desktop session.
     *
     * @return array{ok?:bool,error?:string}
     */
    public function confirm(string $token, int $userId, string $confirmIp): array
    {
        $row = $this->row($token);

        if (!$row || (int) $row->expires_at < time()) {
            return ['error' => 'qr_expired'];
        }

        if ($row->status !== 'pending') {
            return ['error' => 'qr_used'];
        }

        $db = $this->db;
        $db->setQuery(
            'UPDATE ' . $db->quoteName('#__otplogin_qr')
            . ' SET `status` = ' . $db->quote('approved')
            . ', `user_id` = ' . $userId
            . ', `confirm_ip` = ' . $db->quote($confirmIp)
            . ' WHERE `token` = ' . $db->quote($token) . ' AND `status` = ' . $db->quote('pending')
        )->execute();

        if ($db->getAffectedRows() < 1) {
            return ['error' => 'qr_used'];
        }

        return ['ok' => true];
    }

    public function cancel(string $token): void
    {
        $this->setStatus($token, 'cancelled');
    }

    private function row(string $token): ?object
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }

        $row = $this->db->setQuery(
            'SELECT * FROM ' . $this->db->quoteName('#__otplogin_qr')
            . ' WHERE `token` = ' . $this->db->quote($token) . ' LIMIT 1'
        )->loadObject();

        return $row ?: null;
    }

    private function setStatus(string $token, string $status): void
    {
        $this->db->setQuery(
            'UPDATE ' . $this->db->quoteName('#__otplogin_qr')
            . ' SET `status` = ' . $this->db->quote($status)
            . ' WHERE `token` = ' . $this->db->quote($token)
        )->execute();
    }

    private function purge(): void
    {
        $this->db->setQuery(
            'DELETE FROM ' . $this->db->quoteName('#__otplogin_qr')
            . ' WHERE `expires_at` < ' . (time() - 3600)
        )->execute();
    }
}

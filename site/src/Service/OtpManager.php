<?php
/**
 * @package     Otplogin
 * @license     GNU General Public License version 2 or later
 */

namespace Otplogin\Component\Otplogin\Site\Service;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;

/**
 * Issues and verifies one-time codes and enforces every limit around them.
 * All methods return plain arrays: ['ok' => bool, 'error' => key, ...extras].
 */
final class OtpManager
{
    public function __construct(
        private DatabaseInterface $db,
        private Registry $params,
        private RateLimiter $limiter,
        private SmsGateway $sms,
        private AccountService $accounts
    ) {
        Log::addLogger(['text_file' => 'com_otplogin.log.php'], Log::ALL, ['com_otplogin']);
    }

    private function int(string $key, int $default): int
    {
        return (int) $this->params->get($key, $default);
    }

    private function fail(string $error, array $extra = []): array
    {
        return ['ok' => false, 'error' => $error] + $extra;
    }

    private function dryRun(): bool
    {
        return (int) $this->params->get('dry_run', 0) === 1;
    }

    private function hash(string $phone, string $code): string
    {
        return hash_hmac('sha256', $phone . '|' . $code, (string) Factory::getApplication()->get('secret'));
    }

    /** Step 1: validate limits, create a code, send it. */
    public function request(string $phone, string $ip): array
    {
        $now = time();

        if (random_int(1, 50) === 1) {
            $this->limiter->purge();
        }

        foreach ([['phone', $phone], ['ip', $ip]] as [$scope, $target]) {
            if ($left = $this->limiter->blockedFor($scope, $target)) {
                return $this->fail('blocked', ['retry_after' => $left]);
            }
        }

        if (!$this->dryRun() && !$this->sms->isConfigured()) {
            return $this->fail('not_configured');
        }

        if (!$this->accounts->registrationEnabled() && !$this->accounts->linkEnabled() && !$this->accounts->findByPhone($phone)) {
            return $this->fail('not_registered');
        }

        // Resend cooldown (per phone)
        $cooldown = max(0, $this->int('resend_cooldown', 120));
        $last     = $this->limiter->lastEvent('send', $phone);

        if ($cooldown > 0 && $last > 0 && $now - $last < $cooldown) {
            return $this->fail('cooldown', ['retry_after' => $cooldown - ($now - $last)]);
        }

        // Reserve a slot, then check every window. The slot is released again when a limit trips.
        $eventId = $this->limiter->record('send', $phone, $ip);

        // Per-browser-session hourly SMS ceiling (SMS pumping mitigation).
        $sessionMax = $this->int('session_hourly_max', 5);
        if ($sessionMax > 0) {
            $sid = (string) Factory::getApplication()->getSession()->getId();
            if ($sid !== '') {
                $sessCount = $this->limiter->count('send', 'subject', 'sess:' . $sid, $now - 3600);
                // The event we just recorded used the phone as subject, so count session separately.
                $sessSends = (int) $this->db->setQuery(
                    'SELECT COUNT(*) FROM ' . $this->db->quoteName('#__otplogin_events')
                    . ' WHERE `kind` = ' . $this->db->quote('send')
                    . ' AND `created_at` >= ' . ($now - 3600)
                    . ' AND `ip` = ' . $this->db->quote($ip)
                    . ' AND `subject` LIKE ' . $this->db->quote('sess:%')
                )->loadResult();
                // Track session via a dedicated event kind subject prefix on a side record.
                $this->limiter->record('send_sess', 'sess:' . substr(hash('sha256', $sid), 0, 24), $ip);
                if ($this->limiter->count('send_sess', 'subject', 'sess:' . substr(hash('sha256', $sid), 0, 24), $now - 3600) > $sessionMax) {
                    $this->limiter->forget($eventId);
                    return $this->fail('session_limit', ['retry_after' => 3600]);
                }
            }
        }

        $limits = [
            ['phone_limit', 'subject', $phone, max(1, $this->int('phone_window_minutes', 10)) * 60, $this->int('phone_window_max', 3)],
            ['phone_daily', 'subject', $phone, 86400, $this->int('phone_daily_max', 10)],
            ['ip_limit', 'ip', $ip, 3600, $this->int('ip_hourly_max', 15)],
            ['ip_daily', 'ip', $ip, 86400, $this->int('ip_daily_max', 40)],
            ['global_limit', null, null, 86400, $this->int('global_daily_max', 0)],
        ];

        foreach ($limits as [$error, $field, $value, $window, $max]) {
            if ($max < 1) {
                continue; // 0 = unlimited
            }

            if ($this->limiter->count('send', $field, $value, $now - $window) > $max) {
                $this->limiter->forget($eventId);

                return $this->fail($error, [
                    'retry_after' => $this->limiter->retryAfter('send', $field, $value, $window, $max),
                ]);
            }
        }

        // Create the code
        $length = max(4, min(8, $this->int('code_length', 5)));
        $code   = str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
        $ttl    = max(30, $this->int('code_ttl', 120));
        $db     = $this->db;

        $db->setQuery(
            'UPDATE ' . $db->quoteName('#__otplogin_codes') . ' SET `used` = 1 WHERE `phone` = ' . $db->quote($phone) . ' AND `used` = 0'
        )->execute();

        $db->setQuery(
            'INSERT INTO ' . $db->quoteName('#__otplogin_codes')
            . ' (`phone`, `code_hash`, `attempts`, `used`, `expires_at`, `ip`, `created_at`) VALUES ('
            . $db->quote($phone) . ', ' . $db->quote($this->hash($phone, $code)) . ', 0, 0, ' . ($now + $ttl) . ', '
            . $db->quote($ip) . ', ' . $now . ')'
        )->execute();
        $codeId = (int) $db->insertid();

        if ($this->dryRun()) {
            Log::add('DRY RUN: code for ' . PhoneHelper::mask($phone) . ' is ' . $code, Log::DEBUG, 'com_otplogin');
        } else {
            $result = $this->sms->sendCode($phone, $code);

            if (!$result['ok']) {
                $db->setQuery(
                    'UPDATE ' . $db->quoteName('#__otplogin_codes') . ' SET `used` = 1 WHERE `id` = ' . $codeId
                )->execute();
                $this->limiter->forget($eventId); // a failed delivery must not eat the user's quota
                $this->limiter->record('sms_error', $phone, $ip);
                $logDetail = 'IPPanel send failed for ' . PhoneHelper::mask($phone) . ' (' . ($result['error'] ?? '?')
                    . ', http ' . ($result['http'] ?? '-') . '): ' . ($result['detail'] ?? '') . ' | ' . ($result['body'] ?? '');
                Log::add($logDetail, Log::ERROR, 'com_otplogin');
                ErrorLogger::write('IPPANEL_SEND_FAILED', [
                    'ip' => $ip,
                    'phone' => PhoneHelper::mask($phone),
                    'http' => (string) ($result['http'] ?? '-'),
                    'detail' => ($result['detail'] ?? '') . ' | ' . ($result['body'] ?? ''),
                ]);
                try {
                    $masked = PhoneHelper::mask($phone);
                    (new TelegramClient($this->params))->notify(
                        'sms_error',
                        "❌ <b>خطای ارسال پیامک</b>\n"
                        . "──────────────\n"
                        . '📱 <b>شماره:</b> <code>' . "\u{2066}" . htmlspecialchars($masked, ENT_QUOTES, 'UTF-8') . "\u{2069}" . "</code>\n"
                        . '⚠️ <b>خطا:</b> ' . htmlspecialchars((string) ($result['detail'] ?? $result['error'] ?? '?'), ENT_QUOTES, 'UTF-8')
                        . ' (HTTP ' . (int) ($result['http'] ?? 0) . ')'
                    );
                } catch (\Throwable $e) {
                    // never break SMS flow
                }

                return $this->fail(($result['error'] ?? '') === 'not_configured' ? 'not_configured' : 'sms_failed');
            }
        }

        return ['ok' => true, 'ttl' => $ttl, 'cooldown' => $cooldown, 'length' => $length];
    }

    /** Step 2: check the code the user typed. */
    public function verify(string $phone, string $code, string $ip): array
    {
        $now = time();
        $db  = $this->db;

        foreach ([['phone', $phone], ['ip', $ip]] as [$scope, $target]) {
            if ($left = $this->limiter->blockedFor($scope, $target)) {
                return $this->fail('blocked', ['retry_after' => $left]);
            }
        }

        $row = $db->setQuery(
            'SELECT * FROM ' . $db->quoteName('#__otplogin_codes')
            . ' WHERE `phone` = ' . $db->quote($phone) . ' AND `used` = 0 ORDER BY `id` DESC LIMIT 1'
        )->loadObject();

        if (!$row || (int) $row->expires_at < $now) {
            $this->recordFailure($phone, $ip);

            return $this->fail('expired');
        }

        $maxAttempts = max(1, $this->int('max_verify_attempts', 5));

        // Atomic attempt increment: concurrent requests cannot both slip under the ceiling.
        $db->setQuery(
            'UPDATE ' . $db->quoteName('#__otplogin_codes')
            . ' SET `attempts` = `attempts` + 1'
            . ' WHERE `id` = ' . (int) $row->id
            . ' AND `used` = 0'
            . ' AND `attempts` < ' . $maxAttempts
            . ' AND `expires_at` >= ' . $now
        )->execute();

        if ($db->getAffectedRows() < 1) {
            // Either exhausted, expired, or already used.
            $fresh = $db->setQuery(
                'SELECT `attempts`, `used`, `expires_at` FROM ' . $db->quoteName('#__otplogin_codes')
                . ' WHERE `id` = ' . (int) $row->id
            )->loadObject();

            if (!$fresh || (int) $fresh->used === 1 || (int) $fresh->expires_at < $now) {
                return $this->fail('expired');
            }

            $this->consume((int) $row->id);

            return $this->fail('too_many');
        }

        $attemptsNow = (int) $row->attempts + 1;

        if (!hash_equals((string) $row->code_hash, $this->hash($phone, $code))) {
            $remaining = $maxAttempts - $attemptsNow;

            if ($remaining <= 0) {
                $this->consume((int) $row->id);
            }

            $this->recordFailure($phone, $ip);

            return $remaining > 0 ? $this->fail('wrong_code', ['remaining' => $remaining]) : $this->fail('too_many');
        }

        // Atomically consume: two parallel requests can never both succeed with one code.
        if (!$this->consume((int) $row->id)) {
            return $this->fail('expired');
        }

        $this->limiter->record('verify_ok', $phone, $ip);

        return ['ok' => true];
    }

    /** Marks a code as used; returns false when someone else already did. */
    private function consume(int $codeId): bool
    {
        $db = $this->db;
        $db->setQuery(
            'UPDATE ' . $db->quoteName('#__otplogin_codes') . ' SET `used` = 1 WHERE `id` = ' . $codeId . ' AND `used` = 0'
        )->execute();

        return $db->getAffectedRows() > 0;
    }

    /** Records a failed verification and blocks the phone / IP when the thresholds are crossed. */
    private function recordFailure(string $phone, string $ip): void
    {
        $now = time();
        $this->limiter->record('verify_fail', $phone, $ip);

        $blockSeconds = max(1, $this->int('block_minutes', 30)) * 60;
        $phoneMax     = $this->int('phone_fail_max', 8);
        $ipMax        = $this->int('ip_fail_max', 20);

        if ($phoneMax > 0 && $this->limiter->count('verify_fail', 'subject', $phone, $now - 1800) >= $phoneMax) {
            $this->limiter->block('phone', $phone, $blockSeconds, 'too many wrong codes');
        }

        if ($ipMax > 0 && $this->limiter->count('verify_fail', 'ip', $ip, $now - 3600) >= $ipMax) {
            $this->limiter->block('ip', $ip, $blockSeconds, 'too many wrong codes');
        }
    }
}

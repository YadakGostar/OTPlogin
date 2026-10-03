<?php
/**
 * @package     Otplogin
 * @license     GNU General Public License version 2 or later
 */

namespace Otplogin\Component\Otplogin\Site\Service;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Registry\Registry;

/**
 * Lightweight proof-of-work challenge (no external CAPTCHA).
 * Client finds nonce such that sha256(challenge|nonce) starts with N zero bits.
 * Challenge is single-use and bound to the session + IP.
 */
final class PowService
{
    private const SESSION_KEY = 'otplogin.pow';

    public function __construct(private Registry $params)
    {
    }

    public function enabled(): bool
    {
        return (int) $this->params->get('pow_enabled', 1) === 1;
    }

    /** Difficulty in leading zero bits (default 16 ≈ ~65k hashes ≈ under 1s on modern phones). */
    public function difficulty(): int
    {
        return max(12, min(22, (int) $this->params->get('pow_difficulty', 16)));
    }

    /** @return array{challenge:string,difficulty:int,expires:int} */
    public function issue(string $ip): array
    {
        $challenge = bin2hex(random_bytes(16));
        $expires   = time() + 300;
        $session   = Factory::getApplication()->getSession();
        $session->set(self::SESSION_KEY, [
            'c' => $challenge,
            'd' => $this->difficulty(),
            'e' => $expires,
            'i' => $this->ipKey($ip),
            'u' => 0,
        ]);

        return [
            'challenge'  => $challenge,
            'difficulty' => $this->difficulty(),
            'expires'    => $expires,
        ];
    }

    public function verify(string $challenge, string $nonce, string $ip): bool
    {
        if (!$this->enabled()) {
            return true;
        }

        $session = Factory::getApplication()->getSession();
        $stored  = $session->get(self::SESSION_KEY);

        if (!\is_array($stored)) {
            return false;
        }

        if ((int) ($stored['u'] ?? 1) !== 0) {
            return false; // already used
        }

        if ((string) ($stored['c'] ?? '') !== $challenge) {
            return false;
        }

        if ((int) ($stored['e'] ?? 0) < time()) {
            return false;
        }

        if ((string) ($stored['i'] ?? '') !== $this->ipKey($ip)) {
            return false;
        }

        $diff = (int) ($stored['d'] ?? $this->difficulty());
        $hash = hash('sha256', $challenge . '|' . $nonce);

        if (!$this->hasLeadingZeroBits($hash, $diff)) {
            return false;
        }

        // Mark single-use.
        $stored['u'] = 1;
        $session->set(self::SESSION_KEY, $stored);

        return true;
    }

    public function ipKey(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            // Collapse to /64 network for rate limits and POW binding.
            $bin = @inet_pton($ip);

            if ($bin !== false && \strlen($bin) === 16) {
                $prefix = substr($bin, 0, 8) . str_repeat("\0", 8);

                return inet_ntop($prefix) ?: $ip;
            }
        }

        return $ip;
    }

    private function hasLeadingZeroBits(string $hexHash, int $bits): bool
    {
        $fullBytes = intdiv($bits, 8);
        $remBits   = $bits % 8;
        $neededHex = $fullBytes * 2;

        if ($neededHex > 0 && !str_starts_with($hexHash, str_repeat('0', $neededHex))) {
            return false;
        }

        if ($remBits === 0) {
            return true;
        }

        $nextNibble = hexdec($hexHash[$neededHex] ?? 'f');

        // Each hex digit is 4 bits; check high bits of this nibble.
        $shift = 4 - $remBits;

        return ($nextNibble >> $shift) === 0;
    }
}

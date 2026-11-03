<?php
/**
 * @package     Otplogin
 * @license     GNU General Public License version 2 or later
 * @copyright (C) 2026 Reza Esfandiari.
 */

namespace Otplogin\Component\Otplogin\Site\Service;

\defined('_JEXEC') or die;

/**
 * Iranian mobile number helpers (normalisation, variants, masking).
 * User input must be 10 digits starting with 9 (no leading zero), e.g. 9123456789.
 * Stored / compared internally as 09XXXXXXXXX.
 */
final class PhoneHelper
{
    private const DIGITS = [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ];

    /**
     * Accept only 9XXXXXXXXX (10 digits, no leading zero).
     * Returns canonical 09XXXXXXXXX for storage, or null when invalid.
     */
    public static function normalize(string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', strtr(trim($raw), self::DIGITS)) ?? '';

        // Leading zero is not allowed (0912… → error)
        if ($digits !== '' && $digits[0] === '0') {
            return null;
        }

        // Optional international forms typed without a leading 0: 98912… / 009812… already handled above if start with 0
        if (str_starts_with($digits, '98') && \strlen($digits) === 12) {
            $digits = substr($digits, 2); // → 9XXXXXXXXX
        }

        if (!preg_match('/^9\d{9}$/', $digits)) {
            return null;
        }

        return '0' . $digits;
    }

    /** ASCII digits only (accepts Persian / Arabic-Indic digits), used for the typed code. */
    public static function digits(string $raw): string
    {
        return preg_replace('/\D+/', '', strtr(trim($raw), self::DIGITS)) ?? '';
    }

    /** E.164 format required by IPPanel (+98912...). */
    public static function e164(string $phone): string
    {
        return '+98' . substr($phone, 1);
    }

    /** Every format an older site may have stored the same number in. */
    public static function variants(string $phone): array
    {
        $core = substr($phone, 1);

        return [$phone, $core, '98' . $core, '+98' . $core, '0098' . $core];
    }

    public static function mask(string $phone): string
    {
        return substr($phone, 0, 4) . '***' . substr($phone, -4);
    }
}

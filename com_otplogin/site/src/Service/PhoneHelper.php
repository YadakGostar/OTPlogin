<?php
/**
 * @package     Otplogin
 * @license     GNU General Public License version 2 or later
 */

namespace Otplogin\Component\Otplogin\Site\Service;

\defined('_JEXEC') or die;

/**
 * Iranian mobile number helpers (normalisation, variants, masking).
 */
final class PhoneHelper
{
    private const DIGITS = [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ];

    /** Returns the canonical 09XXXXXXXXX form, or null when the input is not an Iranian mobile number. */
    public static function normalize(string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', strtr(trim($raw), self::DIGITS)) ?? '';

        if (str_starts_with($digits, '0098')) {
            $digits = '0' . substr($digits, 4);
        } elseif (str_starts_with($digits, '98') && \strlen($digits) === 12) {
            $digits = '0' . substr($digits, 2);
        } elseif (\strlen($digits) === 10 && $digits[0] === '9') {
            $digits = '0' . $digits;
        }

        return preg_match('/^09\d{9}$/', $digits) ? $digits : null;
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

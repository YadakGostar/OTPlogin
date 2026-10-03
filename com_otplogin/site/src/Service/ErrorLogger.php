<?php
/**
 * @package     Otplogin
 * @license     GNU General Public License version 2 or later
 */

namespace Otplogin\Component\Otplogin\Site\Service;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;

/** Small, dependency-free fallback logger for OTP Login diagnostics. */
final class ErrorLogger
{
    public static function write(string $event, array $context = []): void
    {
        try {
            $file = self::path();
            $dir  = dirname($file);

            if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
                return;
            }

            if (!is_file($file)) {
                @file_put_contents($file, "<?php die(); ?>\n", LOCK_EX);
            }

            $parts = [
                gmdate('c'),
                $event,
                (string) ($context['ip'] ?? ''),
                (string) ($context['phone'] ?? ''),
                (string) ($context['http'] ?? ''),
                (string) ($context['detail'] ?? ''),
            ];

            $line = implode("\t", array_map(static function ($value): string {
                return str_replace(["\r", "\n", "\t"], [' ', ' ', ' '], (string) $value);
            }, $parts)) . "\n";

            @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            // Diagnostics must never break login/SMS flow.
        }
    }

    public static function path(): string
    {
        $app  = Factory::getApplication();
        $path = trim((string) $app->get('log_path', ''));
        $path = $path !== '' ? $path : JPATH_ADMINISTRATOR . '/logs';

        return rtrim($path, '/\\') . '/com_otplogin_errors.php';
    }

    /** Truncate the diagnostic log (keeps the PHP die header). */
    public static function clear(): bool
    {
        try {
            $file = self::path();
            $dir  = dirname($file);

            if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
                return false;
            }

            return @file_put_contents($file, "<?php die(); ?>\n", LOCK_EX) !== false;
        } catch (\Throwable $e) {
            return false;
        }
    }
}

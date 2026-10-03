<?php
/**
 * @package     Otplogin
 * @license     GNU General Public License version 2 or later
 */

namespace Otplogin\Component\Otplogin\Site\Service;

\defined('_JEXEC') or die;

use Joomla\Database\DatabaseInterface;

/**
 * Sliding-window counters and temporary blocks, stored in the database.
 * An event is inserted first and counted afterwards, so concurrent
 * requests can only over-count, never slip under a limit.
 */
final class RateLimiter
{
    public function __construct(private DatabaseInterface $db)
    {
    }

    public function record(string $kind, ?string $subject, string $ip): int
    {
        $db = $this->db;
        $db->setQuery(
            'INSERT INTO ' . $db->quoteName('#__otplogin_events')
            . ' (`kind`, `subject`, `ip`, `created_at`) VALUES ('
            . $db->quote($kind) . ', ' . ($subject === null ? 'NULL' : $db->quote($subject)) . ', '
            . $db->quote($ip) . ', ' . time() . ')'
        )->execute();

        return (int) $db->insertid();
    }

    public function forget(int $eventId): void
    {
        $this->db->setQuery('DELETE FROM ' . $this->db->quoteName('#__otplogin_events') . ' WHERE `id` = ' . $eventId)->execute();
    }

    /** Counts events of a kind since a timestamp; $field is 'subject', 'ip' or null (all). */
    public function count(string $kind, ?string $field, ?string $value, int $since): int
    {
        $sql = 'SELECT COUNT(*) FROM ' . $this->db->quoteName('#__otplogin_events')
            . ' WHERE `kind` = ' . $this->db->quote($kind) . ' AND `created_at` >= ' . $since;

        if ($field !== null && $value !== null) {
            $col = $field === 'ip' ? '`ip`' : '`subject`';
            $sql .= ' AND ' . $col . ' = ' . $this->db->quote($value);
        }

        return (int) $this->db->setQuery($sql)->loadResult();
    }

    public function lastEvent(string $kind, string $subject): int
    {
        $sql = 'SELECT MAX(`created_at`) FROM ' . $this->db->quoteName('#__otplogin_events')
            . ' WHERE `kind` = ' . $this->db->quote($kind) . ' AND `subject` = ' . $this->db->quote($subject);

        return (int) $this->db->setQuery($sql)->loadResult();
    }

    /** Seconds until one more event of this kind would be allowed (0 = allowed now). */
    public function retryAfter(string $kind, ?string $field, ?string $value, int $window, int $max): int
    {
        $now = time();
        $sql = 'SELECT `created_at` FROM ' . $this->db->quoteName('#__otplogin_events')
            . ' WHERE `kind` = ' . $this->db->quote($kind) . ' AND `created_at` >= ' . ($now - $window);

        if ($field !== null && $value !== null) {
            $col = $field === 'ip' ? '`ip`' : '`subject`';
            $sql .= ' AND ' . $col . ' = ' . $this->db->quote($value);
        }

        $times = array_map('intval', $this->db->setQuery($sql . ' ORDER BY `created_at` ASC')->loadColumn() ?: []);
        $n     = \count($times);

        if ($n < $max) {
            return 0;
        }

        return max(1, $times[$n - $max] + $window - $now);
    }

    /** Seconds left on an active block, or 0. */
    public function blockedFor(string $scope, string $target): int
    {
        $sql = 'SELECT `blocked_until` FROM ' . $this->db->quoteName('#__otplogin_blocks')
            . ' WHERE `scope` = ' . $this->db->quote($scope) . ' AND `target` = ' . $this->db->quote($target);
        $until = (int) $this->db->setQuery($sql)->loadResult();

        return $until > time() ? $until - time() : 0;
    }

    public function block(string $scope, string $target, int $seconds, string $reason): void
    {
        $db    = $this->db;
        $until = time() + max(60, $seconds);
        $db->setQuery(
            'INSERT INTO ' . $db->quoteName('#__otplogin_blocks')
            . ' (`scope`, `target`, `blocked_until`, `reason`, `created_at`) VALUES ('
            . $db->quote($scope) . ', ' . $db->quote($target) . ', ' . $until . ', '
            . $db->quote(substr($reason, 0, 100)) . ', ' . time() . ')'
            . ' ON DUPLICATE KEY UPDATE `blocked_until` = VALUES(`blocked_until`), `reason` = VALUES(`reason`)'
        )->execute();
    }

    /** Housekeeping, called occasionally from the request flow. */
    public function purge(): void
    {
        $db  = $this->db;
        $now = time();
        $db->setQuery('DELETE FROM ' . $db->quoteName('#__otplogin_events') . ' WHERE `created_at` < ' . ($now - 3 * 86400))->execute();
        $db->setQuery('DELETE FROM ' . $db->quoteName('#__otplogin_codes') . ' WHERE `created_at` < ' . ($now - 86400))->execute();
        $db->setQuery('DELETE FROM ' . $db->quoteName('#__otplogin_blocks') . ' WHERE `blocked_until` < ' . $now)->execute();
    }
}

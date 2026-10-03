<?php
/**
 * @package     Otplogin
 * @license     GNU General Public License version 2 or later
 */

namespace Otplogin\Component\Otplogin\Site\Service;

\defined('_JEXEC') or die;

use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\User;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\CMS\User\UserHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;

/**
 * Everything that touches Joomla user accounts: phone lookup (new vs. existing
 * users), registration, linking an old account to a phone, and the final login.
 */
final class AccountService
{
    public function __construct(
        private DatabaseInterface $db,
        private Registry $params,
        private CMSApplication $app
    ) {
    }

    public function registrationEnabled(): bool
    {
        return (int) $this->params->get('registration_enabled', 1) === 1;
    }

    public function linkEnabled(): bool
    {
        return (int) $this->params->get('link_enabled', 1) === 1;
    }

    public function profileKey(): string
    {
        return trim((string) $this->params->get('profile_key', 'otplogin.phone')) ?: 'otplogin.phone';
    }

    /**
     * Finds an existing account for a phone number. Looks in the username
     * (optional), the user profile table and an optional Joomla custom field,
     * and understands every common way of writing the same number.
     */
    public function findByPhone(string $phone): ?int
    {
        $db   = $this->db;
        $vals = [];

        foreach (PhoneHelper::variants($phone) as $v) {
            $vals[] = $db->quote($v);
            $vals[] = $db->quote(json_encode($v)); // com_users "profile" plugin stores JSON strings
        }

        $in = implode(',', $vals);

        // Priority 1: SMS-verified profile phone (strongest claim).
        $verified = array_map('intval', $db->setQuery(
            'SELECT `user_id` FROM ' . $db->quoteName('#__user_profiles')
            . ' WHERE `profile_key` = ' . $db->quote($this->profileKey())
            . ' AND `profile_value` IN (' . $in . ')'
        )->loadColumn() ?: []);
        $verified = array_values(array_unique(array_filter($verified)));

        if ($verified) {
            return $this->pickBestUserId($verified, $phone, 'profile');
        }

        // Priority 2: custom field
        $fieldId = (int) $this->params->get('lookup_field_id', 0);
        if ($fieldId > 0) {
            $ids = array_map('intval', $db->setQuery(
                'SELECT `item_id` FROM ' . $db->quoteName('#__fields_values')
                . ' WHERE `field_id` = ' . $fieldId . ' AND `value` IN (' . $in . ')'
            )->loadColumn() ?: []);
            $ids = array_values(array_unique(array_filter($ids)));
            if ($ids) {
                return $this->pickBestUserId($ids, $phone, 'field');
            }
        }

        // Priority 3: Hikashop customer / address telephone (legacy shop users)
        if ((int) $this->params->get('hikashop_lookup', 1) === 1) {
            $hk = $this->findHikashopUserIds($phone);
            if ($hk) {
                return $this->pickBestUserId($hk, $phone, 'hikashop');
            }
        }

        // Priority 4: username equals phone (weakest — can be pre-registered by an attacker)
        if ((int) $this->params->get('lookup_username', 1) === 1) {
            $ids = array_map('intval', $db->setQuery(
                'SELECT `id` FROM ' . $db->quoteName('#__users') . ' WHERE `username` IN (' . $in . ')'
            )->loadColumn() ?: []);
            $ids = array_values(array_unique(array_filter($ids)));
            if ($ids) {
                return $this->pickBestUserId($ids, $phone, 'username');
            }
        }

        return null;
    }

    /** Prefer last-visited account when several share the same number. */
    private function pickBestUserId(array $ids, string $phone, string $source): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        if (\count($ids) === 1) {
            return $ids[0];
        }

        Log::add(
            'Several accounts share phone ' . PhoneHelper::mask($phone)
            . ' via ' . $source . ': ' . implode(',', $ids),
            Log::WARNING,
            'com_otplogin'
        );

        $list = implode(',', $ids);
        $best = (int) $this->db->setQuery(
            'SELECT `id` FROM ' . $this->db->quoteName('#__users')
            . ' WHERE `id` IN (' . $list . ') ORDER BY `lastvisitDate` DESC, `id` DESC LIMIT 1'
        )->loadResult();

        return $best > 0 ? $best : $ids[0];
    }

    /**
     * Locate Joomla user ids via Hikashop address / customer tables.
     * Guest addresses (user_id = 0) are ignored.
     *
     * @return list<int>
     */
    private function findHikashopUserIds(string $phone): array
    {
        $db = $this->db;
        $prefix = $db->getPrefix();

        // Detect Hikashop tables without hard-failing if the extension is absent.
        $tables = $db->setQuery('SHOW TABLES')->loadColumn() ?: [];
        $tables = array_map('strtolower', $tables);

        $addrTable = $prefix . 'hikashop_address';
        $userTable = $prefix . 'hikashop_user';

        if (!\in_array(strtolower($addrTable), $tables, true)) {
            return [];
        }

        $colsCfg = trim((string) $this->params->get(
            'hikashop_phone_columns',
            'address_telephone,address_telephone2,address_mobile,address_fax'
        ));
        $wanted = array_values(array_filter(array_map('trim', explode(',', $colsCfg))));

        // Only columns that actually exist.
        $existing = $db->setQuery('SHOW COLUMNS FROM ' . $db->quoteName($addrTable))->loadColumn() ?: [];
        $existing = array_map('strtolower', $existing);
        $cols = [];
        foreach ($wanted as $c) {
            if (\in_array(strtolower($c), $existing, true)) {
                $cols[] = $c;
            }
        }
        if (!$cols) {
            return [];
        }

        $variants = PhoneHelper::variants($phone);
        $fuzzy = (int) $this->params->get('hikashop_fuzzy_match', 1) === 1;

        $userIds = [];

        // Fast path: exact match on stored variants.
        $ors = [];
        foreach ($cols as $c) {
            foreach ($variants as $v) {
                $ors[] = $db->quoteName($c) . ' = ' . $db->quote($v);
            }
        }
        if ($ors) {
            $sql = 'SELECT DISTINCT `address_user_id` FROM ' . $db->quoteName($addrTable)
                . ' WHERE `address_user_id` > 0 AND (' . implode(' OR ', $ors) . ')';
            $hkIds = array_map('intval', $db->setQuery($sql)->loadColumn() ?: []);
            $userIds = array_merge($userIds, $this->hikashopToJoomla($hkIds, $userTable, $tables));
        }

        // Slow path: normalize messy formats only when nothing found yet.
        if (!$userIds && $fuzzy) {
            $selectCols = implode(', ', array_map(static fn ($c) => $db->quoteName($c), $cols));
            $rows = $db->setQuery(
                'SELECT `address_user_id`, ' . $selectCols
                . ' FROM ' . $db->quoteName($addrTable)
                . ' WHERE `address_user_id` > 0'
                . ' AND (' . implode(' OR ', array_map(static fn ($c) => $db->quoteName($c) . " <> ''", $cols)) . ')'
            )->loadObjectList() ?: [];

            $core = substr($phone, 1); // 9xxxxxxxxx
            $matchedHk = [];
            foreach ($rows as $row) {
                foreach ($cols as $c) {
                    $raw = (string) ($row->$c ?? '');
                    if ($raw === '') {
                        continue;
                    }
                    $norm = PhoneHelper::normalize($raw);
                    if ($norm === $phone || ($norm && substr($norm, 1) === $core)) {
                        $matchedHk[] = (int) $row->address_user_id;
                        break;
                    }
                }
            }
            $userIds = array_merge($userIds, $this->hikashopToJoomla($matchedHk, $userTable, $tables));
        }

        return array_values(array_unique(array_filter(array_map('intval', $userIds))));
    }

    /**
     * @param  list<int> $hkUserIds  Hikashop user_id values (address_user_id)
     * @param  list<string> $tables
     * @return list<int>
     */
    private function hikashopToJoomla(array $hkUserIds, string $userTable, array $tables): array
    {
        $hkUserIds = array_values(array_unique(array_filter(array_map('intval', $hkUserIds))));
        if (!$hkUserIds) {
            return [];
        }

        $db = $this->db;

        // Hikashop user table maps user_id -> user_cms_id (Joomla id).
        if (\in_array(strtolower($userTable), $tables, true)) {
            $list = implode(',', $hkUserIds);
            $cms = array_map('intval', $db->setQuery(
                'SELECT `user_cms_id` FROM ' . $db->quoteName($userTable)
                . ' WHERE `user_id` IN (' . $list . ') AND `user_cms_id` > 0'
            )->loadColumn() ?: []);
            if ($cms) {
                return array_values(array_unique(array_filter($cms)));
            }
        }

        // Fallback: some installs store Joomla id directly as address_user_id.
        return $hkUserIds;
    }

    public function user(int $id): ?User
    {
        $user = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById($id);

        return $user && $user->id ? $user : null;
    }

    /** Returns an error key when this account must not sign in with an SMS code. */
    public function loginBlockReason(User $user): ?string
    {
        if ((int) $user->block === 1) {
            return 'user_blocked';
        }

        if ((int) $this->params->get('block_superusers', 1) === 1 && $user->authorise('core.admin')) {
            return 'not_allowed';
        }

        return null;
    }

    public function emailExists(string $email): bool
    {
        $db = $this->db;

        return (int) $db->setQuery(
            'SELECT COUNT(*) FROM ' . $db->quoteName('#__users') . ' WHERE LOWER(`email`) = ' . $db->quote(strtolower($email))
        )->loadResult() > 0;
    }

    /** @return array{id?:int, error?:string} */
    public function createUser(string $phone, string $name, string $email): array
    {
        if ($email !== '' && $this->emailExists($email)) {
            return ['error' => 'email_taken'];
        }

        $groupId = (int) $this->params->get('new_user_group', 0)
            ?: (int) ComponentHelper::getParams('com_users')->get('new_usertype', 2);

        $password = UserHelper::genRandomPassword(32);
        $user     = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById(0);

        $data = [
            'name'         => $name !== '' ? $name : $phone,
            'username'     => $phone,
            'email'        => $email !== '' ? $email : $this->placeholderEmail($phone),
            'password'     => $password,
            'password2'    => $password,
            'groups'       => [$groupId],
            'block'        => 0,
            'activation'   => '',
            'sendEmail'    => 0,
            'registerDate' => Factory::getDate()->toSql(),
        ];

        if (!$user->bind($data) || !$user->save()) {
            Log::add('User creation failed for ' . PhoneHelper::mask($phone) . ': ' . $user->getError(), Log::ERROR, 'com_otplogin');

            return ['error' => 'create_failed'];
        }

        $this->setPhone((int) $user->id, $phone);
        $this->markAutopass((int) $user->id);

        return ['id' => (int) $user->id];
    }

    private function placeholderEmail(string $phone): string
    {
        $domain = strtolower(trim((string) $this->params->get('fake_email_domain', '')));

        if ($domain === '') {
            $domain = preg_replace('/^www\./', '', Uri::getInstance()->getHost()) ?: '';
        }

        if (!str_contains($domain, '.')) {
            $domain = 'phone.invalid';
        }

        return $phone . '@' . $domain;
    }

    /** Attaches the (verified) phone number to an account via the user profile table. */
    public function setPhone(int $userId, string $phone): void
    {
        $key = $this->profileKey();

        $this->setProfile($userId, $key, str_starts_with($key, 'profile.') ? json_encode($phone) : $phone);
    }

    private function setProfile(int $userId, string $key, string $value): void
    {
        $db = $this->db;

        $db->setQuery(
            'DELETE FROM ' . $db->quoteName('#__user_profiles')
            . ' WHERE `user_id` = ' . $userId . ' AND `profile_key` = ' . $db->quote($key)
        )->execute();

        $db->setQuery(
            'INSERT INTO ' . $db->quoteName('#__user_profiles')
            . ' (`user_id`, `profile_key`, `profile_value`, `ordering`) VALUES ('
            . $userId . ', ' . $db->quote($key) . ', ' . $db->quote($value) . ', 0)'
        )->execute();
    }

    // ------------------------------------------------------------ password login

    private const AUTOPASS_KEY = 'otplogin.autopass';

    public function passwordLoginEnabled(): bool
    {
        return (int) $this->params->get('password_login', 1) === 1;
    }

    public function passwordSetMode(): string
    {
        $mode = (string) $this->params->get('password_set', 'optional');

        return \in_array($mode, ['required', 'optional', 'off'], true) ? $mode : 'optional';
    }

    public function passwordMinLength(): int
    {
        return max(6, min(64, (int) $this->params->get('password_min_length', 8)));
    }

    /**
     * NIST-inspired password checks (no composition rules, reject weak patterns).
     * Optional Have I Been Pwned k-anonymity check (off by default for Iran connectivity).
     *
     * @return string|null  error key or null when acceptable
     */
    public function validatePasswordPolicy(string $password, string $phone = ''): ?string
    {
        $pw = $password;
        $min = $this->passwordMinLength();

        if (\strlen($pw) < $min) {
            return 'pass_short';
        }

        if (\strlen($pw) > 128) {
            return 'pass_long';
        }

        $lower = strtolower($pw);
        $common = [
            '123456', '12345678', '123456789', '1234567890', 'password', 'password1',
            'qwerty', 'qwerty123', '111111', '000000', 'abc123', 'iloveyou',
            'admin', 'welcome', 'monkey', 'dragon', 'master', 'login', 'passw0rd',
            '654321', '121212', '112233', '123123', '123321', 'qwertyuiop',
            'iran', 'tehran', '0912', '09123456789',
        ];
        if (\in_array($lower, $common, true)) {
            return 'pass_common';
        }

        // All same character or simple runs
        if (preg_match('/^(.)\1{5,}$/', $pw)) {
            return 'pass_common';
        }
        if (preg_match('/^(?:012345|123456|234567|345678|456789|567890|678901|789012|890123|901234)+$/', $pw)) {
            return 'pass_common';
        }
        if (preg_match('/^(?:098765|987654|876543|765432|654321|543210)+$/', $pw)) {
            return 'pass_common';
        }

        // Must not contain the phone (or core digits)
        if ($phone !== '') {
            $core = substr($phone, 1);
            if ($core !== '' && (str_contains($pw, $phone) || str_contains($pw, $core))) {
                return 'pass_contains_phone';
            }
        }

        // Optional HIBP (default off)
        if ((int) $this->params->get('password_hibp', 0) === 1 && $this->isPwnedPassword($pw)) {
            return 'pass_pwned';
        }

        return null;
    }

    private function isPwnedPassword(string $password): bool
    {
        $sha1 = strtoupper(sha1($password));
        $prefix = substr($sha1, 0, 5);
        $suffix = substr($sha1, 5);

        if (!\function_exists('curl_init')) {
            return false;
        }

        $ch = curl_init('https://api.pwnedpasswords.com/range/' . $prefix);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 3,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Add-Padding: true'],
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200 || !\is_string($raw)) {
            return false; // fail open if unreachable
        }

        foreach (explode("\n", $raw) as $line) {
            $parts = explode(':', trim($line));
            if (isset($parts[0]) && hash_equals($suffix, strtoupper($parts[0]))) {
                return true;
            }
        }

        return false;
    }

    /** True while the account still has the random password this plugin generated at sign-up. */
    public function isAutopass(int $userId): bool
    {
        $db = $this->db;

        return (int) $db->setQuery(
            'SELECT COUNT(*) FROM ' . $db->quoteName('#__user_profiles')
            . ' WHERE `user_id` = ' . $userId . ' AND `profile_key` = ' . $db->quote(self::AUTOPASS_KEY)
        )->loadResult() > 0;
    }

    /** Old accounts (no flag) are assumed to know their password; accounts created here by SMS do not, until they set one. */
    public function hasKnownPassword(int $userId): bool
    {
        return !$this->isAutopass($userId);
    }

    public function markAutopass(int $userId): void
    {
        $this->setProfile($userId, self::AUTOPASS_KEY, '1');
    }

    public function clearAutopass(int $userId): void
    {
        $db = $this->db;
        $db->setQuery(
            'DELETE FROM ' . $db->quoteName('#__user_profiles')
            . ' WHERE `user_id` = ' . $userId . ' AND `profile_key` = ' . $db->quote(self::AUTOPASS_KEY)
        )->execute();
    }

    /** Sets a password the user chose. Goes through Joomla's User object so hashing and user plugins run. */
    public function setPassword(int $userId, string $password): bool
    {
        $user = $this->user($userId);

        if (!$user) {
            return false;
        }

        $data = [
            'password'  => $password,
            'password2' => $password,
        ];

        if (!$user->bind($data) || !$user->save()) {
            Log::add('Setting password failed for user ' . $userId . ': ' . $user->getError(), Log::ERROR, 'com_otplogin');

            return false;
        }

        $this->clearAutopass($userId);

        return true;
    }

    /** Finds an old account by username or e-mail, for the "I already have an account" flow. */
    public function findForLink(string $identifier): ?int
    {
        $db = $this->db;
        $id = (int) $db->setQuery(
            'SELECT `id` FROM ' . $db->quoteName('#__users')
            . ' WHERE `username` = ' . $db->quote($identifier) . ' OR LOWER(`email`) = ' . $db->quote(strtolower($identifier))
            . ' ORDER BY `id` ASC LIMIT 1'
        )->loadResult();

        return $id > 0 ? $id : null;
    }

    public function checkPassword(?int $userId, string $password): bool
    {
        if (!$userId) {
            UserHelper::hashPassword($password); // keep timing similar for unknown accounts

            return false;
        }

        $hash = (string) $this->db->setQuery(
            'SELECT `password` FROM ' . $this->db->quoteName('#__users') . ' WHERE `id` = ' . $userId
        )->loadResult();

        return $hash !== '' && UserHelper::verifyPassword($password, $hash, $userId) === true;
    }

    /**
     * Signs the user in through Joomla's normal login pipeline (so MFA, user
     * plugins and lastvisit tracking all run) using a one-time server ticket
     * that plg_authentication_otplogin recognises.
     */
    public function login(int $userId): bool
    {
        $user = $this->user($userId);

        if (!$user) {
            return false;
        }

        $session = $this->app->getSession();
        $ticket  = bin2hex(random_bytes(24));

        $session->set('otplogin.ticket', [
            'hash'     => hash('sha256', $ticket),
            'username' => $user->username,
            'exp'      => time() + 60,
        ]);

        try {
            $result = $this->app->login(
                ['username' => $user->username, 'password' => $ticket],
                ['silent' => true, 'remember' => false, 'action' => 'core.login.site', 'otplogin' => true]
            );
        } catch (\Throwable $e) {
            // Never surface English ArgumentCountError etc. to the visitor
            try {
                \Joomla\CMS\Log\Log::add(
                    'OTP login pipeline: ' . $e->getMessage(),
                    \Joomla\CMS\Log\Log::ERROR,
                    'com_otplogin'
                );
            } catch (\Throwable $ignore) {
            }
            $session->clear('otplogin.ticket');

            return false;
        }

        $session->clear('otplogin.ticket');

        return $result === true;
    }

    /** Only same-site URLs are allowed as post-login destination. */
    public function safeRedirect(string $url): string
    {
        $url = trim($url);

        if ($url !== '' && Uri::isInternal($url)) {
            return $url;
        }

        $custom = trim((string) $this->params->get('redirect_url', ''));

        return $custom !== '' && Uri::isInternal($custom) ? $custom : Uri::root();
    }
}

<?php
/**
 * @package     Otplogin
 * @license     GNU General Public License version 2 or later
 */

namespace Otplogin\Component\Otplogin\Site\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Mail\MailHelper;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;
use Otplogin\Component\Otplogin\Site\Service\AccountService;
use Otplogin\Component\Otplogin\Site\Service\AvatarService;
use Otplogin\Component\Otplogin\Site\Service\PowService;
use Otplogin\Component\Otplogin\Site\Service\ErrorLogger;
use Otplogin\Component\Otplogin\Site\Service\SmsGateway;
use Otplogin\Component\Otplogin\Site\Service\OtpManager;
use Otplogin\Component\Otplogin\Site\Service\PasskeyService;
use Otplogin\Component\Otplogin\Site\Service\PhoneHelper;
use Otplogin\Component\Otplogin\Site\Service\QrLoginService;
use Otplogin\Component\Otplogin\Site\Service\RateLimiter;
use Otplogin\Component\Otplogin\Site\Service\TelegramClient;

/**
 * JSON endpoints used by mod_otplogin:
 *   index.php?option=com_otplogin&task=ajax.lookup|send|verify|password|setpassword|register|link&format=json  (POST + Joomla token)
 */
class AjaxController extends BaseController
{
    private ?Registry $params = null;
    private ?RateLimiter $limiter = null;
    private ?AccountService $accounts = null;
    private ?OtpManager $manager = null;

    /** error key => [HTTP status, language key, takes a duration, takes remaining attempts] */
    private const ERRORS = [
        'method'         => [405, 'COM_OTPLOGIN_ERR_METHOD'],
        'token'          => [403, 'COM_OTPLOGIN_ERR_TOKEN'],
        'bot'            => [400, 'COM_OTPLOGIN_ERR_BOT'],
        'phone'          => [422, 'COM_OTPLOGIN_ERR_PHONE'],
        'code_format'    => [422, 'COM_OTPLOGIN_ERR_CODE_FORMAT'],
        'blocked'        => [429, 'COM_OTPLOGIN_ERR_BLOCKED', 'duration'],
        'cooldown'       => [429, 'COM_OTPLOGIN_ERR_COOLDOWN', 'duration'],
        'phone_limit'    => [429, 'COM_OTPLOGIN_ERR_PHONE_LIMIT', 'duration'],
        'phone_daily'    => [429, 'COM_OTPLOGIN_ERR_PHONE_DAILY', 'duration'],
        'ip_limit'       => [429, 'COM_OTPLOGIN_ERR_IP_LIMIT', 'duration'],
        'ip_daily'       => [429, 'COM_OTPLOGIN_ERR_IP_LIMIT', 'duration'],
        'global_limit'   => [503, 'COM_OTPLOGIN_ERR_GLOBAL'],
        'reg_limit'      => [429, 'COM_OTPLOGIN_ERR_REG_LIMIT', 'duration'],
        'not_registered' => [403, 'COM_OTPLOGIN_ERR_NOT_REGISTERED'],
        'not_configured' => [503, 'COM_OTPLOGIN_ERR_NOT_CONFIGURED'],
        'sms_failed'     => [502, 'COM_OTPLOGIN_ERR_SMS_FAILED'],
        'expired'        => [422, 'COM_OTPLOGIN_ERR_EXPIRED'],
        'too_many'       => [429, 'COM_OTPLOGIN_ERR_TOO_MANY'],
        'wrong_code'     => [422, 'COM_OTPLOGIN_ERR_WRONG_CODE', 'remaining'],
        'user_blocked'   => [403, 'COM_OTPLOGIN_ERR_USER_BLOCKED'],
        'not_allowed'    => [403, 'COM_OTPLOGIN_ERR_NOT_ALLOWED'],
        'session'        => [403, 'COM_OTPLOGIN_ERR_SESSION'],
        'name'           => [422, 'COM_OTPLOGIN_ERR_NAME'],
        'email'          => [422, 'COM_OTPLOGIN_ERR_EMAIL'],
        'email_taken'    => [409, 'COM_OTPLOGIN_ERR_EMAIL_TAKEN'],
        'reg_disabled'   => [403, 'COM_OTPLOGIN_ERR_REG_DISABLED'],
        'link_disabled'  => [403, 'COM_OTPLOGIN_ERR_LINK_DISABLED'],
        'link_invalid'   => [422, 'COM_OTPLOGIN_ERR_LINK_INVALID'],
        'phone_in_use'   => [409, 'COM_OTPLOGIN_ERR_PHONE_IN_USE'],
        'create_failed'  => [500, 'COM_OTPLOGIN_ERR_CREATE_FAILED'],
        'login_failed'   => [500, 'COM_OTPLOGIN_ERR_LOGIN_FAILED'],
        'pass_invalid'   => [422, 'COM_OTPLOGIN_ERR_PASS_INVALID'],
        'pass_short'     => [422, 'COM_OTPLOGIN_ERR_PASS_SHORT', 'min'],
        'pass_mismatch'  => [422, 'COM_OTPLOGIN_ERR_PASS_MISMATCH'],
        'pass_save'      => [500, 'COM_OTPLOGIN_ERR_PASS_SAVE'],
        'avatar_disabled'=> [403, 'COM_OTPLOGIN_ERR_AVATAR_DISABLED'],
        'avatar_upload'  => [400, 'COM_OTPLOGIN_ERR_AVATAR_UPLOAD'],
        'avatar_size'    => [422, 'COM_OTPLOGIN_ERR_AVATAR_SIZE'],
        'avatar_type'    => [422, 'COM_OTPLOGIN_ERR_AVATAR_TYPE'],
        'avatar_save'    => [500, 'COM_OTPLOGIN_ERR_AVATAR_SAVE'],
        'session_limit'  => [429, 'COM_OTPLOGIN_ERR_SESSION_LIMIT'],
        'pow_required'   => [428, 'COM_OTPLOGIN_ERR_POW'],
        'pow_invalid'    => [403, 'COM_OTPLOGIN_ERR_POW_INVALID'],
        'pass_common'    => [422, 'COM_OTPLOGIN_ERR_PASS_COMMON'],
        'pass_contains_phone' => [422, 'COM_OTPLOGIN_ERR_PASS_PHONE'],
        'pass_pwned'     => [422, 'COM_OTPLOGIN_ERR_PASS_PWNED'],
        'pass_long'      => [422, 'COM_OTPLOGIN_ERR_PASS_LONG'],
        'qr_disabled'    => [403, 'COM_OTPLOGIN_ERR_QR_DISABLED'],
        'qr_expired'     => [410, 'COM_OTPLOGIN_ERR_QR_EXPIRED'],
        'qr_ip'          => [403, 'COM_OTPLOGIN_ERR_QR_IP'],
        'qr_device'      => [403, 'COM_OTPLOGIN_ERR_QR_DEVICE'],
        'qr_used'        => [409, 'COM_OTPLOGIN_ERR_QR_USED'],
        'passkey_disabled'=> [403, 'COM_OTPLOGIN_ERR_PASSKEY_DISABLED'],
        'passkey_invalid'=> [422, 'COM_OTPLOGIN_ERR_PASSKEY_INVALID'],
        'passkey_exists' => [409, 'COM_OTPLOGIN_ERR_PASSKEY_EXISTS'],
        'passkey_failed' => [401, 'COM_OTPLOGIN_ERR_PASSKEY_FAILED'],
        'auth_required'  => [401, 'COM_OTPLOGIN_ERR_AUTH_REQUIRED'],
    ];

    // ---------------------------------------------------------------- endpoints

    /** Returns a fresh Joomla form token for pages served from a cache. */
    public function token(): void
    {
        if (strtoupper((string) $this->input->getMethod()) !== 'GET') {
            $this->fail('method');
        }

        $this->respond(['ok' => true, 'token' => Session::getFormToken(true)]);
    }

    /**
     * Step 1: the phone number was submitted.
     * A returning user who already has a password is asked for it (no SMS is sent);
     * everyone else (new numbers, accounts without a password) gets a code.
     */
    public function lookup(): void
    {
        $this->start();
        $ip    = $this->clientIp();
        $phone = $this->phoneFromPost($ip);
        $limit = (int) $this->params()->get('lookup_hourly_max', 30);

        // Anyone can ask "is this number registered?", so the question itself is rate limited.
        $rateIp = $this->rateIp($ip);
        if ($limit > 0) {
            $this->limiter()->record('lookup', null, $rateIp);

            if ($this->limiter()->count('lookup', 'ip', $rateIp, time() - 3600) > $limit) {
                $this->fail('ip_limit', ['retry_after' => $this->limiter()->retryAfter('lookup', 'ip', $rateIp, 3600, $limit)]);
            }
        }

        $accounts = $this->accounts();

        if ($accounts->passwordLoginEnabled() && !$this->limiter()->blockedFor('pwd', $phone)) {
            $userId = $accounts->findByPhone($phone);

            if ($userId && $accounts->hasKnownPassword($userId)) {
                $this->respond(['ok' => true, 'action' => 'password', 'phone' => PhoneHelper::mask($phone)]);
            }
        }

        $this->sendCodeResponse($phone, $ip);
    }

    /** Request (or re-request) an SMS code. */
    public function send(): void
    {
        $this->start();
        $ip = $this->clientIp();

        $this->sendCodeResponse($this->phoneFromPost($ip), $ip);
    }

    /** Sign in with the password the user chose earlier. */
    public function password(): void
    {
        $this->start();
        $ip       = $this->clientIp();
        $phone    = $this->phoneFromPost($ip);
        $accounts = $this->accounts();
        $limiter  = $this->limiter();
        $password = (string) $this->input->post->get('password', '', 'raw');

        if (!$accounts->passwordLoginEnabled()) {
            $this->fail('pass_invalid');
        }

        // Separate block scopes: a locked password never locks the SMS route (and vice versa),
        // so an attacker cannot lock a victim out by guessing passwords.
        foreach ([['pwd', $phone], ['pwdip', $ip]] as [$scope, $target]) {
            if ($left = $limiter->blockedFor($scope, $target)) {
                $this->fail('blocked', ['retry_after' => $left]);
            }
        }

        $userId = $accounts->findByPhone($phone);

        if ($userId && !$accounts->hasKnownPassword($userId)) {
            $userId = null; // such an account only has a random password
        }

        if (!$accounts->checkPassword($userId, $password)) {
            $now   = time();
            $block = max(1, (int) $this->params()->get('block_minutes', 30)) * 60;
            $pMax  = (int) $this->params()->get('password_fail_max', 5);
            $iMax  = (int) $this->params()->get('password_ip_fail_max', 15);

            $limiter->record('pass_fail', $phone, $ip);

            if ($pMax > 0 && $limiter->count('pass_fail', 'subject', $phone, $now - 900) >= $pMax) {
                $limiter->block('pwd', $phone, $block, 'too many wrong passwords');
            }

            if ($iMax > 0 && $limiter->count('pass_fail', 'ip', $ip, $now - 900) >= $iMax) {
                $limiter->block('pwdip', $ip, $block, 'too many wrong passwords');
            }

            if ($left = $limiter->blockedFor('pwd', $phone)) {
                $this->fail('blocked', ['retry_after' => $left]);
            }

            $this->fail('pass_invalid');
        }

        $this->loginExisting((int) $userId, $phone, $ip, 'login_pass');
    }

    /** After an SMS sign-in the user may choose a password for next time. */
    public function setpassword(): void
    {
        $this->start();
        $accounts = $this->accounts();
        $id       = (int) $this->app->getIdentity()->id;
        $flag     = $this->app->getSession()->get('otplogin.setpw');

        if (!$id || !\is_array($flag) || (int) ($flag['uid'] ?? 0) !== $id || (int) ($flag['exp'] ?? 0) < time()) {
            $this->fail('session');
        }

        $p1  = (string) $this->input->post->get('password', '', 'raw');
        $p2  = (string) $this->input->post->get('password2', '', 'raw');
        $min = $accounts->passwordMinLength();

        if (mb_strlen($p1) < $min) {
            $this->fail('pass_short', ['min' => $min]);
        }

        if (!hash_equals($p1, $p2)) {
            $this->fail('pass_mismatch');
        }

        $v = $this->app->getSession()->get('otplogin.verified');
        $userPhone = (\is_array($v) ? (string) ($v['phone'] ?? '') : '');
        if ($userPhone === '') {
            $u = $accounts->user($id);
            if ($u && preg_match('/^09\d{9}$/', (string) $u->username)) {
                $userPhone = (string) $u->username;
            }
        }
        if ($err = $accounts->validatePasswordPolicy($p1, $userPhone)) {
            $this->fail($err, ['min' => $min]);
        }

        if (!$accounts->setPassword($id, $p1)) {
            $this->fail('pass_save');
        }

        $this->app->getSession()->clear('otplogin.setpw');

        $this->respond([
            'ok'       => true,
            'action'   => 'done',
            'kind'     => 'setpassword',
            'redirect' => $accounts->safeRedirect((string) $this->input->post->get('return', '', 'raw')),
        ]);
    }

    /** Step 2: check the code, then log in (existing user) or move on (new phone). */
    public function verify(): void
    {
        $this->start();
        $post  = $this->input->post;
        $ip    = $this->clientIp();
        $phone = PhoneHelper::normalize($post->getString('phone', ''));

        if ($phone === null) {
            $this->fail('phone');
        }

        $code = PhoneHelper::digits($post->getString('code', ''));

        if (\strlen($code) < 4 || \strlen($code) > 8) {
            $this->fail('code_format');
        }

        $result = $this->manager()->verify($phone, $code, $ip);

        if (!$result['ok']) {
            $this->fail($result['error'], $result);
        }

        $accounts = $this->accounts();
        $userId   = $accounts->findByPhone($phone);

        // Existing (old) user: sign in.
        if ($userId) {
            $this->loginExisting($userId, $phone, $ip, 'login', $this->input->post->getInt('reset', 0) === 1);
        }

        // New phone number.
        if (!$accounts->registrationEnabled() && !$accounts->linkEnabled()) {
            $this->fail('not_registered');
        }

        $this->app->getSession()->set('otplogin.verified', ['phone' => $phone, 'exp' => time() + 900]);

        $askName  = (string) $this->params()->get('ask_name', 'required');
        $askEmail = (string) $this->params()->get('ask_email', 'optional');

        if ($accounts->registrationEnabled() && !$accounts->linkEnabled() && $askName === 'off' && $askEmail === 'off') {
            $this->doRegister($phone, '', '', $ip);
        }

        $this->respond([
            'ok'           => true,
            'action'       => 'new',
            'phone'        => PhoneHelper::mask($phone),
            'can_register' => $accounts->registrationEnabled(),
            'can_link'     => $accounts->linkEnabled(),
            'ask_name'     => $askName,
            'ask_email'    => $askEmail,
        ]);
    }

    /** Step 3a: create a brand-new account for a verified phone. */
    public function register(): void
    {
        $this->start();
        $post  = $this->input->post;
        $ip    = $this->clientIp();
        $phone = $this->verifiedPhone();

        if (!$this->accounts()->registrationEnabled()) {
            $this->fail('reg_disabled');
        }

        $name  = trim($post->getString('name', ''));
        $name  = function_exists('mb_substr') ? mb_substr($name, 0, 100) : substr($name, 0, 100);
        $email = strtolower(trim($post->getString('email', '')));

        if ($name === '' && (string) $this->params()->get('ask_name', 'required') === 'required') {
            $this->fail('name');
        }

        $askEmail = (string) $this->params()->get('ask_email', 'optional');

        if (($email === '' && $askEmail === 'required') || ($email !== '' && !MailHelper::isEmailAddress($email))) {
            $this->fail('email');
        }

        $this->doRegister($phone, $name, $email, $ip);
    }

    /** Step 3b: an old account owner proves the account with its password and attaches the phone. */
    public function link(): void
    {
        $this->start();
        $post  = $this->input->post;
        $ip    = $this->clientIp();
        $phone = $this->verifiedPhone();

        if (!$this->accounts()->linkEnabled()) {
            $this->fail('link_disabled');
        }

        $identifier = trim($post->getString('identifier', ''));
        $password   = (string) $post->get('password', '', 'raw');
        $subject    = substr(sha1(strtolower($identifier)), 0, 32);
        $limiter    = $this->limiter();

        foreach ([['ip', $ip], ['link', $subject]] as [$scope, $target]) {
            if ($left = $limiter->blockedFor($scope, $target)) {
                $this->fail('blocked', ['retry_after' => $left]);
            }
        }

        $accounts = $this->accounts();
        $userId   = $identifier !== '' ? $accounts->findForLink($identifier) : null;

        if (!$accounts->checkPassword($userId, $password)) {
            $now   = time();
            $block = max(1, (int) $this->params()->get('block_minutes', 30)) * 60;

            $limiter->record('link_fail', $subject, $ip);

            if ($limiter->count('link_fail', 'subject', $subject, $now - 900) >= 5) {
                $limiter->block('link', $subject, $block, 'too many link attempts');
            }

            if ($limiter->count('link_fail', 'ip', $ip, $now - 900) >= 10) {
                $limiter->block('ip', $ip, $block, 'too many link attempts');
            }

            $this->fail('link_invalid');
        }

        $user = $accounts->user((int) $userId);

        if (!$user) {
            $this->fail('link_invalid');
        }

        if ($reason = $accounts->loginBlockReason($user)) {
            $this->fail($reason);
        }

        $owner = $accounts->findByPhone($phone);

        if ($owner && $owner !== (int) $userId) {
            $this->fail('phone_in_use');
        }

        $accounts->setPhone((int) $userId, $phone);
        $this->loginExisting((int) $userId, $phone, $ip, 'link');
    }


    // ---------------------------------------------------------------- proof-of-work (anti-bot, no third party)

    public function powchallenge(): void
    {
        $this->start();
        $pow = $this->pow();
        if (!$pow->enabled()) {
            $this->respond(['ok' => true, 'enabled' => false]);
        }
        $issued = $pow->issue($this->clientIp());
        $this->respond(['ok' => true, 'enabled' => true] + $issued);
    }

    // ---------------------------------------------------------------- avatar

    public function avatarupload(): void
    {
        $this->start();
        $user = $this->app->getIdentity();

        if (!$user || !$user->id) {
            $this->fail('auth_required');
        }

        $avatars = $this->avatars();

        if (!$avatars->enabled()) {
            $this->fail('avatar_disabled');
        }

        // Camera-only policy: reject pure file-picker uploads without camera source flag.
        $source = strtolower((string) $this->input->post->get('source', '', 'cmd'));
        if ($source !== 'camera') {
            $this->fail('avatar_upload');
        }

        $file = $this->input->files->get('avatar', null, 'raw');

        // Fallback for hosts where InputFilter strips the files bag.
        if (!is_array($file) || empty($file['tmp_name'])) {
            $file = $_FILES['avatar'] ?? null;
        }

        // Unwrap single nested file structure if present.
        if (is_array($file) && isset($file['tmp_name']) && is_array($file['tmp_name'])) {
            $file = [
                'name'     => $file['name'][0] ?? '',
                'type'     => $file['type'][0] ?? '',
                'tmp_name' => $file['tmp_name'][0] ?? '',
                'error'    => $file['error'][0] ?? UPLOAD_ERR_NO_FILE,
                'size'     => $file['size'][0] ?? 0,
            ];
        }

        if (!is_array($file) || empty($file['tmp_name'])) {
            $this->fail('avatar_upload');
        }

        $result = $avatars->upload((int) $user->id, $file);

        if (isset($result['error'])) {
            $this->fail($result['error']);
        }

        $this->app->getSession()->clear('otplogin.welcome_avatar');
        $this->respond(['ok' => true, 'url' => $result['url'], 'path' => $result['path']]);
    }

    public function skipwelcome(): void
    {
        $this->start();
        $user = $this->app->getIdentity();
        if (!$user || !$user->id) {
            $this->fail('session');
        }
        $this->app->getSession()->clear('otplogin.welcome_avatar');
        $this->respond(['ok' => true]);
    }

    public function avatarremove(): void
    {
        $this->start();
        $user = $this->app->getIdentity();

        if (!$user || !$user->id) {
            $this->fail('auth_required');
        }

        $this->avatars()->remove((int) $user->id);
        $this->respond(['ok' => true]);
    }

    // ---------------------------------------------------------------- QR login

    /** Desktop: create a pending QR session bound to this IP + UA. */
    public function qrcreate(): void
    {
        $this->start();
        $qr = $this->qr();

        if (!$qr->enabled()) {
            $this->fail('qr_disabled');
        }

        $ip = $this->clientIp();
        $this->limiter()->record('qr_create', null, $ip);

        if ($this->limiter()->count('qr_create', 'ip', $ip, time() - 3600) > 20) {
            $this->fail('ip_limit', ['retry_after' => 600]);
        }

        $ua  = (string) $this->input->server->get('HTTP_USER_AGENT', '', 'string');
        $out = $qr->create($ip, $ua);
        $url = Uri::root() . 'index.php?option=com_otplogin&task=ajax.qrconfirm&token=' . $out['token'];

        $this->respond([
            'ok'         => true,
            'token'      => $out['token'],
            'expires_in' => $out['expires_in'],
            'payload'    => $out['token'],
            'confirm_hint' => $url,
        ]);
    }

    /** Desktop polls until approved / expired. */
    public function qrstatus(): void
    {
        $this->start();
        $qr = $this->qr();

        if (!$qr->enabled()) {
            $this->fail('qr_disabled');
        }

        $token = (string) $this->input->post->get('qr_token', '', 'alnum');
        $ip    = $this->clientIp();
        $ua    = (string) $this->input->server->get('HTTP_USER_AGENT', '', 'string');
        $st    = $qr->status($token, $ip, $ua);

        if (($st['status'] ?? '') === 'approved' && !empty($st['user_id'])) {
            $userId = (int) $st['user_id'];
            $user   = $this->accounts()->user($userId);

            if (!$user || ($reason = $this->accounts()->loginBlockReason($user))) {
                $this->fail($reason ?? 'login_failed');
            }

            if (!$this->accounts()->login($userId)) {
                $this->fail('login_failed');
            }

            $this->respond([
                'ok'       => true,
                'status'   => 'approved',
                'redirect' => $this->accounts()->safeRedirect((string) $this->input->post->get('return', '', 'raw')),
            ]);
        }

        if (isset($st['error'])) {
            $this->fail($st['error']);
        }

        $this->respond(['ok' => true, 'status' => $st['status'] ?? 'pending']);
    }

    /** Phone (already logged in) confirms the desktop QR. */
    public function qrconfirm(): void
    {
        $this->start();
        $qr = $this->qr();

        if (!$qr->enabled()) {
            $this->fail('qr_disabled');
        }

        $user = $this->app->getIdentity();

        if (!$user || !$user->id) {
            $this->fail('auth_required');
        }

        $token  = (string) $this->input->post->get('qr_token', '', 'alnum');
        $result = $qr->confirm($token, (int) $user->id, $this->clientIp());

        if (isset($result['error'])) {
            $this->fail($result['error']);
        }

        $this->respond(['ok' => true]);
    }

    // ---------------------------------------------------------------- passkeys (WebAuthn)

    public function passkeyregisteroptions(): void
    {
        $this->start();
        $pk = $this->passkeys();

        if (!$pk->enabled()) {
            $this->fail('passkey_disabled');
        }

        $user = $this->app->getIdentity();

        if (!$user || !$user->id) {
            $this->fail('auth_required');
        }

        $opts = $pk->registrationOptions((int) $user->id, (string) $user->username, (string) $user->name);
        $this->app->getSession()->set('otplogin.passkey.reg_challenge', $opts['_challenge_raw']);
        unset($opts['_challenge_raw']);

        $this->respond(['ok' => true, 'options' => $opts]);
    }

    public function passkeyregister(): void
    {
        $this->start();
        $pk = $this->passkeys();

        if (!$pk->enabled()) {
            $this->fail('passkey_disabled');
        }

        $user = $this->app->getIdentity();

        if (!$user || !$user->id) {
            $this->fail('auth_required');
        }

        $post = $this->input->post;
        $credId = (string) $post->get('credentialId', '', 'raw');
        $pubKey = (string) $post->get('publicKey', '', 'raw');
        $transports = (string) $post->get('transports', '', 'string');
        $label = (string) $post->get('label', '', 'string');

        $result = $pk->storeCredential((int) $user->id, $credId, $pubKey, $transports, $label);

        if (isset($result['error'])) {
            $this->fail($result['error']);
        }

        $this->app->getSession()->clear('otplogin.passkey.reg_challenge');
        $this->respond(['ok' => true, 'count' => $pk->countForUser((int) $user->id)]);
    }

    public function passkeyauthoptions(): void
    {
        $this->start();
        $pk = $this->passkeys();

        if (!$pk->enabled()) {
            $this->fail('passkey_disabled');
        }

        $opts = $pk->authenticationOptions(null);
        $this->app->getSession()->set('otplogin.passkey.auth_challenge', $opts['_challenge_raw']);
        unset($opts['_challenge_raw']);

        $this->respond(['ok' => true, 'options' => $opts]);
    }

    public function passkeyauth(): void
    {
        $this->start();
        $pk = $this->passkeys();

        if (!$pk->enabled()) {
            $this->fail('passkey_disabled');
        }

        $challenge = (string) $this->app->getSession()->get('otplogin.passkey.auth_challenge', '');

        if ($challenge === '') {
            $this->fail('passkey_invalid');
        }

        $post = $this->input->post;
        $userId = $pk->verifyAssertion(
            (string) $post->get('credentialId', '', 'raw'),
            (string) $post->get('authenticatorData', '', 'raw'),
            (string) $post->get('clientDataJSON', '', 'raw'),
            (string) $post->get('signature', '', 'raw'),
            $challenge
        );

        $this->app->getSession()->clear('otplogin.passkey.auth_challenge');

        if (!$userId) {
            $ip = $this->clientIp();
            $this->limiter()->record('passkey_fail', null, $ip);

            if ($this->limiter()->count('passkey_fail', 'ip', $ip, time() - 900) >= 15) {
                $this->limiter()->block('ip', $ip, 1800, 'passkey failures');
            }

            $this->fail('passkey_failed');
        }

        $user = $this->accounts()->user($userId);

        if (!$user || ($reason = $this->accounts()->loginBlockReason($user))) {
            $this->fail($reason ?? 'login_failed');
        }

        if (!$this->accounts()->login($userId)) {
            $this->fail('login_failed');
        }

        $this->respond([
            'ok'       => true,
            'redirect' => $this->accounts()->safeRedirect((string) $this->input->post->get('return', '', 'raw')),
        ]);
    }

    public function passkeydelete(): void
    {
        $this->start();
        $pk = $this->passkeys();
        $user = $this->app->getIdentity();

        if (!$user || !$user->id) {
            $this->fail('auth_required');
        }

        $credId = (string) $this->input->post->get('credentialId', '', 'raw');
        $pk->deleteCredential((int) $user->id, $credId);
        $this->respond(['ok' => true, 'count' => $pk->countForUser((int) $user->id)]);
    }

    // ---------------------------------------------------------------- helpers

    private function doRegister(string $phone, string $name, string $email, string $ip): never
    {
        $accounts = $this->accounts();

        // The number may have been claimed in the meantime; it is verified, so just sign that account in.
        if ($existing = $accounts->findByPhone($phone)) {
            $this->loginExisting($existing, $phone, $ip, 'login');
        }

        $limit = (int) $this->params()->get('ip_register_max', 5);

        if ($limit > 0 && $this->limiter()->count('register', 'ip', $ip, time() - 3600) >= $limit) {
            $this->fail('reg_limit', ['retry_after' => $this->limiter()->retryAfter('register', 'ip', $ip, 3600, $limit)]);
        }

        $created = $accounts->createUser($phone, $name, $email);

        if (isset($created['error'])) {
            $this->fail($created['error']);
        }

        $this->loginExisting($created['id'], $phone, $ip, 'register');
    }

    private function loginExisting(int $userId, string $phone, string $ip, string $kind, bool $reset = false): never
    {
        $accounts = $this->accounts();
        $user     = $accounts->user($userId);

        if (!$user) {
            $this->fail('login_failed');
        }

        if ($reason = $accounts->loginBlockReason($user)) {
            $this->fail($reason);
        }

        try {
            $loggedIn = $accounts->login($userId);
        } catch (\Throwable $e) {
            $loggedIn = false;
        }

        if (!$loggedIn) {
            $this->fail('login_failed');
        }

        $this->limiter()->record($kind, $phone, $ip);
        try {
            $titles = [
                'login'      => 'ورود موفق با پیامک',
                'login_pass' => 'ورود موفق با رمز عبور',
                'register'   => 'کاربر جدید ثبت‌نام کرد',
                'link'       => 'اتصال شماره به حساب',
            ];
            $titleIcons = [
                'login'      => '✅',
                'login_pass' => '🔑',
                'register'   => '👤',
                'link'       => '🔗',
            ];
            $methods = [
                'login'      => 'پیامک (OTP)',
                'login_pass' => 'رمز عبور',
                'register'   => 'پیامک (OTP)',
                'link'       => 'اتصال حساب',
            ];
            $title     = $titles[$kind] ?? $kind;
            $titleIcon = $titleIcons[$kind] ?? 'ℹ️';
            $method    = $methods[$kind] ?? $kind;
            $esc       = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
            // LTR isolate so numbers / emails / URLs stay left-to-right inside RTL Telegram text
            $ltr  = static fn (string $v): string => "\u{2066}" . $v . "\u{2069}";
            $site = rtrim(Uri::root(), '/');
            $siteHost = (string) (parse_url($site, PHP_URL_HOST) ?: $site);
            $siteName = trim((string) $this->params()->get('passkey_rp_name', ''))
                ?: trim((string) \Joomla\CMS\Factory::getApplication()->get('sitename', ''))
                ?: $siteHost;

            $name     = trim((string) ($user->name ?? ''));
            $username = trim((string) ($user->username ?? ''));
            $email    = trim((string) ($user->email ?? ''));
            $phoneShow = $phone !== '' ? PhoneHelper::mask($phone) : '';

            $lines = [
                $titleIcon . ' <b>' . $esc($title) . '</b>',
                '──────────────',
                '🏷 <b>نام:</b> ' . $esc($name !== '' ? $name : '—'),
                '👤 <b>نام کاربری:</b> ' . $ltr('<code>' . $esc($username !== '' ? $username : '—') . '</code>'),
            ];

            if ($email !== '') {
                $lines[] = '📧 <b>ایمیل:</b> ' . $ltr($esc($email));
            }

            if ($phoneShow !== '') {
                $lines[] = '📱 <b>شماره:</b> ' . $ltr('<code>' . $esc($phoneShow) . '</code>');
            }

            $lines[] = '🔐 <b>روش ثبت‌نام:</b> ' . $esc($method);
            $lines[] = '🌐 <b>سایت:</b> ' . $esc($siteName);
            $lines[] = $ltr('<a href="' . $esc($site) . '">' . $esc($site) . '</a>');
            $lines[] = '🖥 <b>IP:</b> ' . $ltr('<code>' . $esc($ip) . '</code>');

            (new TelegramClient($this->params()))->notify($kind, implode("\n", $lines));
        } catch (\Throwable $e) {
            // ignore
        }
        $session = $this->app->getSession();
        $session->clear('otplogin.verified');

        $redirect = $accounts->safeRedirect((string) $this->input->post->get('return', '', 'raw'));
        $needAvatar = false;
        try {
            if ((int) $this->params()->get('avatar_enabled', 1) === 1) {
                $needAvatar = $this->avatars()->pathFor($userId) === '';
            }
        } catch (\Throwable $e) {
            $needAvatar = false;
        }

        if ($needAvatar) {
            $session->set('otplogin.welcome_avatar', 1);
        } else {
            $session->clear('otplogin.welcome_avatar');
        }

        $response = [
            'ok'          => true,
            'action'      => 'done',
            'kind'        => $kind,
            'redirect'    => $redirect,
            'need_avatar' => $needAvatar,
        ];

        // Offer to choose a password: after a first SMS sign-in / sign-up, or after "forgot password".
        $mode = $accounts->passwordSetMode();

        if (
            $accounts->passwordLoginEnabled() && $mode !== 'off'
            && \in_array($kind, ['login', 'register'], true)
            && ($reset || $accounts->isAutopass($userId))
        ) {
            $session->set('otplogin.setpw', ['uid' => $userId, 'exp' => time() + 900]);

            $response['action']   = 'setpassword';
            $response['required'] = $mode === 'required';
            $response['min']      = $accounts->passwordMinLength();
            // The form token contains the user id, which just changed: hand over the new one.
            $response['token']    = Session::getFormToken(true);
        }

        $this->respond($response);
    }

    /** Bot checks + phone normalisation shared by lookup and send. */
    private function phoneFromPost(string $ip): string
    {
        $post = $this->input->post;
        $min  = (int) $this->params()->get('min_form_seconds', 0);
        $ts   = $post->getInt('ts', 0);

        // Optional form-age check. Disabled by default because cached pages and
        // fast legitimate submissions can otherwise be rejected before IPPanel is reached.
        // The honeypot + rate limits remain active protection.
        if ($min > 0 && $ts > 0) {
            $age = time() - $ts;
            if ($age < 0 || $age < $min || $age > 86400) {
                $this->fail('bot');
            }
        }

        $phone = PhoneHelper::normalize($post->getString('phone', ''));

        if ($phone === null) {
            // Garbage input is cheap to send, so it is rate limited too.
            $this->limiter()->record('bad_input', null, $ip);

            if ($this->limiter()->count('bad_input', 'ip', $ip, time() - 3600) > 30) {
                $this->limiter()->block('ip', $ip, 1800, 'too much invalid input');
            }

            $this->fail('phone');
        }

        return $phone;
    }

    private function sendCodeResponse(string $phone, string $ip): never
    {
        // Proof-of-work required before any SMS is issued (anti SMS-pumping).
        $pow = $this->pow();
        if ($pow->enabled()) {
            $challenge = (string) $this->input->post->get('pow_challenge', '', 'raw');
            $nonce     = (string) $this->input->post->get('pow_nonce', '', 'raw');
            if ($challenge === '' || $nonce === '') {
                $this->fail('pow_required');
            }
            if (!$pow->verify($challenge, $nonce, $ip)) {
                $this->fail('pow_invalid');
            }
        }

        $result = $this->manager()->request($phone, $this->rateIp($ip));

        if (!$result['ok']) {
            $this->fail($result['error'], $result);
        }

        $this->respond([
            'ok'       => true,
            'action'   => 'code',
            'phone'    => PhoneHelper::mask($phone),
            'ttl'      => $result['ttl'],
            'cooldown' => $result['cooldown'],
            'length'   => $result['length'],
        ]);
    }

    /** Phone number proven earlier in this session through a correct SMS code. */
    private function verifiedPhone(): string
    {
        $data = $this->app->getSession()->get('otplogin.verified');

        if (!\is_array($data) || empty($data['phone']) || (int) ($data['exp'] ?? 0) < time()) {
            $this->fail('session');
        }

        return (string) $data['phone'];
    }

    /** Common guard: POST only, valid Joomla token, honeypot empty. */
    private function start(): void
    {
        // Always load component language so AJAX errors are not English leftovers.
        try {
            $lang = $this->app->getLanguage();
            $lang->load('com_otplogin', JPATH_SITE, $lang->getTag(), true);
            $lang->load('com_otplogin', JPATH_SITE, 'fa-IR', true);
            $lang->load('mod_otplogin', JPATH_SITE, $lang->getTag(), true);
        } catch (\Throwable $e) {
        }

        if (strtoupper((string) $this->input->getMethod()) !== 'POST') {
            $this->fail('method');
        }

        if (!$this->tokenValid()) {
            $this->fail('token');
        }

        if ($this->input->post->getString('website', '') !== '') {
            $this->fail('bot');
        }
    }

    /**
     * Accept the classic form field (name = token hash, value = 1) or the
     * X-CSRF-Token header used by modern Joomla front-end scripts.
     */
    private function tokenValid(): bool
    {
        $session = $this->app->getSession();

        if ($session->checkToken('post')) {
            return true;
        }

        // Header path (Joomla web components / fetch helpers often send this).
        $header = (string) $this->input->server->get('HTTP_X_CSRF_TOKEN', '', 'raw');

        if ($header !== '' && hash_equals($session->getFormToken(), $header)) {
            return true;
        }

        // Body field named "csrf_token" or "_token" with the hash as value (alternate clients).
        foreach (['csrf_token', '_token', 'token'] as $key) {
            $val = (string) $this->input->post->get($key, '', 'alnum');

            if ($val !== '' && hash_equals($session->getFormToken(), $val)) {
                return true;
            }
        }

        return false;
    }

    /** Collapse IPv6 to /64 so rotating addresses in one network share limits. */
    private function rateIp(string $ip): string
    {
        return $this->pow()->ipKey($ip);
    }

    private function clientIp(): string
    {
        $server = $this->input->server;
        $header = strtoupper(trim((string) $this->params()->get('ip_header', '')));
        $ip     = '';

        // Only trust a forwarding header when the administrator explicitly named one.
        if ($header !== '' && preg_match('/^[A-Z0-9_]+$/', $header)) {
            $value = (string) $server->get($header, '', 'string');
            $ip    = trim(explode(',', $value)[0]);
        }

        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $ip = (string) $server->get('REMOTE_ADDR', '', 'string');
        }

        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }

    /** vsprintf that never throws on placeholder mismatch. */
    private function safeFormat(string $langKey, ...$args): string
    {
        $tpl = Text::_($langKey);
        if ($tpl === '' || $tpl === $langKey) {
            // Fallback Persian if language file missing
            $fallbacks = [
                'COM_OTPLOGIN_ERR_GENERIC' => 'خطایی رخ داد. دوباره تلاش کنید.',
                'COM_OTPLOGIN_ERR_TOKEN'   => 'نشست منقضی شده است. صفحه را تازه کنید.',
                'COM_OTPLOGIN_ERR_LOGIN_FAILED' => 'ورود انجام نشد. دوباره تلاش کنید.',
            ];
            $tpl = $fallbacks[$langKey] ?? $langKey;
        }
        if ($args === []) {
            return $tpl;
        }
        try {
            return vsprintf($tpl, $args);
        } catch (\Throwable $e) {
            return $tpl;
        }
    }

    private function duration(int $seconds): string
    {
        if ($seconds < 90) {
            return $this->safeFormat('COM_OTPLOGIN_SECONDS', max(1, $seconds));
        }

        if ($seconds < 5400) {
            return $this->safeFormat('COM_OTPLOGIN_MINUTES', (int) ceil($seconds / 60));
        }

        return $this->safeFormat('COM_OTPLOGIN_HOURS', (int) ceil($seconds / 3600));
    }

    private function fail(string $error, array $extra = []): never
    {
        [$status, $key, $arg] = (self::ERRORS[$error] ?? [400, 'COM_OTPLOGIN_ERR_GENERIC']) + [2 => null];
        $retry = (int) ($extra['retry_after'] ?? 0);

        $message = match ($arg) {
            'duration'  => $this->safeFormat($key, $this->duration($retry)),
            'remaining' => $this->safeFormat($key, (int) ($extra['remaining'] ?? 0)),
            'min'       => $this->safeFormat($key, (int) ($extra['min'] ?? 0)),
            default     => $this->safeFormat($key),
        };

        $payload = ['ok' => false, 'error' => $error, 'message' => $message];

        ErrorLogger::write('AJAX_FAIL:' . $error, [
            'ip' => $this->clientIp(),
            'detail' => $message,
        ]);

        if ($retry > 0) {
            $payload['retry_after'] = $retry;
        }

        if (isset($extra['remaining'])) {
            $payload['remaining'] = (int) $extra['remaining'];
        }

        $this->respond($payload, $status);
    }

    private function respond(array $data, int $status = 200): never
    {
        $app = $this->app;
        $app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        $app->setHeader('Cache-Control', 'no-store, max-age=0', true);
        $app->setHeader('X-Content-Type-Options', 'nosniff', true);
        // SiteApplication has no setResponseCode(); Joomla reads the 'status' header instead.
        http_response_code($status);
        $app->setHeader('status', (string) $status, true);
        $app->sendHeaders();

        if (!isset($data['token'])) {
            $data['token'] = Session::getFormToken(true);
        }

        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $app->close();
        exit;
    }

    // ---------------------------------------------------------------- services

    private function params(): Registry
    {
        return $this->params ??= ComponentHelper::getParams('com_otplogin');
    }

    private function limiter(): RateLimiter
    {
        return $this->limiter ??= new RateLimiter(Factory::getContainer()->get(DatabaseInterface::class));
    }

    private function accounts(): AccountService
    {
        return $this->accounts ??= new AccountService(
            Factory::getContainer()->get(DatabaseInterface::class),
            $this->params(),
            $this->app
        );
    }

    private function pow(): PowService
    {
        return new PowService($this->params());
    }

    private function avatars(): AvatarService
    {
        return new AvatarService(
            Factory::getContainer()->get(DatabaseInterface::class),
            $this->params()
        );
    }

    private function qr(): QrLoginService
    {
        return new QrLoginService(
            Factory::getContainer()->get(DatabaseInterface::class),
            $this->params()
        );
    }

    private function passkeys(): PasskeyService
    {
        return new PasskeyService(
            Factory::getContainer()->get(DatabaseInterface::class),
            $this->params()
        );
    }

    private function manager(): OtpManager
    {
        return $this->manager ??= new OtpManager(
            Factory::getContainer()->get(DatabaseInterface::class),
            $this->params(),
            $this->limiter(),
            new SmsGateway($this->params()),
            $this->accounts()
        );
    }
}

<?php
/**
 * @package     Otplogin
 * @license     GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;

/** @var \Joomla\CMS\User\User $user */

$uid = 'otp' . $moduleId;
$e   = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

// ---------------------------------------------------------------- signed-in view
if ($user && $user->id) :
    $profileUrl = Route::_('index.php?option=com_users&view=profile&layout=edit');
    ?>
    <?php
    $avatarUrl = '';
    $avatarOn  = (int) $config->get('avatar_enabled', 1) === 1;
    $welcomeAvatar = (int) Factory::getApplication()->getSession()->get('otplogin.welcome_avatar', 0) === 1;
    $passkeyOn = (int) $config->get('passkey_enabled', 1) === 1;
    $qrOn      = (int) $config->get('qr_login_enabled', 1) === 1;
    if ($avatarOn) {
        $db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
        $avatarUrl = (string) $db->setQuery(
            'SELECT profile_value FROM #__user_profiles WHERE user_id = ' . (int) $user->id
            . ' AND profile_key = ' . $db->quote('otplogin.avatar') . ' LIMIT 1'
        )->loadResult();
        if ($avatarUrl !== '' && is_file(JPATH_ROOT . '/' . $avatarUrl)) {
            $avatarUrl = Uri::root(true) . '/' . ltrim(str_replace('\\', '/', $avatarUrl), '/');
        } else {
            $avatarUrl = '';
        }
    }
    ?>
    <div class="otp otp--signed-in <?php echo $moduleClass; ?>"
         data-otplogin-signed<?php echo !empty($welcomeAvatar) ? ' data-welcome-avatar="1"' : ''; ?>
         data-endpoint="<?php echo $e($endpoint); ?>"
         data-avatar="<?php echo $avatarOn ? '1' : '0'; ?>"
         data-passkey="<?php echo $passkeyOn ? '1' : '0'; ?>"
         data-qr="<?php echo $qrOn ? '1' : '0'; ?>">
        <input type="hidden" name="<?php echo $e($tokenName); ?>" value="1" data-token>
        <div class="otp-user">
            <div class="otp-user__info">
                <div class="otp-user__avatar" data-avatar-wrap>
                    <?php if ($avatarUrl !== '') : ?>
                        <img src="<?php echo $e($avatarUrl); ?>" alt="" width="96" height="96" data-avatar-img decoding="async">
                    <?php else : ?>
                        <svg viewBox="0 0 24 24" width="1em" height="1em" fill="currentColor" focusable="false" data-avatar-fallback><path d="M12 12a5 5 0 1 0 0-10 5 5 0 0 0 0 10Zm0 2c-4.4 0-8 2.2-8 5v1a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-1c0-2.8-3.6-5-8-5Z"/></svg>
                    <?php endif; ?>
                    <div class="otp-user__avatar-busy" data-avatar-busy hidden>
                        <span class="otp-user__spinner" aria-hidden="true"></span>
                    </div>
                </div>
                <div class="otp-user__details">
                    <h4 class="otp-user__name"><?php echo $e($user->name); ?></h4>
                    <small class="otp-user__welcome"><?php echo Text::_('MOD_OTPLOGIN_WELCOME'); ?></small>
                </div>
            </div>
            <div class="otp-user__actions">
                <?php if ($avatarOn) : ?>
                    <div class="otp-user__photo-actions" data-avatar-actions>
                        <!-- capture=user forces the device camera (not gallery) on mobile OS pickers -->
                        <input type="file" accept="image/jpeg,image/png,image/webp,image/*"
                               capture="user" hidden data-avatar-camera-file>
                        <button type="button" class="otp-user__btn otp-user__btn--camera" data-avatar-camera-btn type="button">
                            <span data-avatar-label><?php echo Text::_($avatarUrl ? 'MOD_OTPLOGIN_AVATAR_CHANGE' : 'MOD_OTPLOGIN_AVATAR_CAMERA'); ?></span>
                        </button>
                        <p class="otp-user__photo-hint"><?php echo Text::_('MOD_OTPLOGIN_AVATAR_CAMERA_ONLY'); ?></p>
                    </div>
                    <div class="otp-cam" data-avatar-cam-modal hidden>
                        <div class="otp-cam__dialog" role="dialog" aria-modal="true" aria-label="<?php echo $e(Text::_('MOD_OTPLOGIN_AVATAR_CAMERA')); ?>">
                            <video class="otp-cam__video" data-avatar-video playsinline autoplay muted></video>
                            <canvas class="otp-cam__canvas" data-avatar-canvas hidden></canvas>
                            <div class="otp-cam__bar">
                                <button type="button" class="otp-user__btn otp-user__btn--camera" data-avatar-snap><?php echo Text::_('MOD_OTPLOGIN_AVATAR_CAPTURE'); ?></button>
                                <button type="button" class="otp-user__btn otp-user__btn--avatar" data-avatar-cam-close><?php echo Text::_('MOD_OTPLOGIN_AVATAR_CANCEL'); ?></button>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <p class="otp__msg otp-user__msg" data-msg role="status" aria-live="polite"></p>
                <p class="otp-user__progress" data-avatar-progress hidden></p>

                <?php if ($passkeyOn) : ?>
                    <button type="button" class="otp-user__btn otp-user__btn--passkey" data-passkey-register>
                        <span><?php echo Text::_('MOD_OTPLOGIN_PASSKEY_REGISTER'); ?></span>
                    </button>
                <?php endif; ?>
                <?php if ($qrOn) : ?>
                    <button type="button" class="otp-user__btn otp-user__btn--qr" data-qr-confirm-open hidden>
                        <span><?php echo Text::_('MOD_OTPLOGIN_QR_CONFIRM'); ?></span>
                    </button>
                <?php endif; ?>
                <a class="otp-user__btn otp-user__btn--profile" href="<?php echo $e($profileUrl); ?>">
                    <svg viewBox="0 0 24 24" width="1.2em" height="1.2em" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                    <span><?php echo Text::_('MOD_OTPLOGIN_EDIT_PROFILE'); ?></span>
                </a>
                <form class="otp-user__form" action="<?php echo Route::_('index.php?option=com_users&task=user.logout'); ?>" method="post">
                    <button type="submit" class="otp-user__btn otp-user__btn--logout">
                        <svg viewBox="0 0 24 24" width="1.2em" height="1.2em" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/></svg>
                        <span><?php echo Text::_('MOD_OTPLOGIN_LOGOUT'); ?></span>
                    </button>
                    <input type="hidden" name="return" value="<?php echo base64_encode(Uri::base()); ?>">
                    <?php echo HTMLHelper::_('form.token'); ?>
                </form>
            </div>
        </div>
        <p class="otp__msg" data-msg role="status" aria-live="polite"></p>
    </div>
    <?php return;
endif;

// Strings the script needs. {placeholders} are filled in by the script.
$i18n = [
    'phoneInvalid' => Text::_('MOD_OTPLOGIN_ERR_PHONE'),
    'codeShort'    => Text::_('MOD_OTPLOGIN_ERR_CODE'),
    'network'      => Text::_('MOD_OTPLOGIN_ERR_NETWORK'),
    'powWorking'   => Text::_('MOD_OTPLOGIN_POW_WORKING'),
    'powFail'      => Text::_('MOD_OTPLOGIN_POW_FAIL'),
    'sending'      => Text::_('MOD_OTPLOGIN_SENDING'),
    'verifying'    => Text::_('MOD_OTPLOGIN_VERIFYING'),
    'working'      => Text::_('MOD_OTPLOGIN_WORKING'),
    'done'         => Text::_('MOD_OTPLOGIN_DONE'),
    'codeSent'     => Text::_('MOD_OTPLOGIN_CODE_SENT'),
    'resend'       => Text::_('MOD_OTPLOGIN_RESEND'),
    'resendIn'     => Text::_('MOD_OTPLOGIN_RESEND_IN'),
    'expired'      => Text::_('MOD_OTPLOGIN_EXPIRED'),
    'passIntro'    => Text::_('MOD_OTPLOGIN_PASS_INTRO'),
    'passEmpty'    => Text::_('MOD_OTPLOGIN_PASS_EMPTY'),
    'passShort'    => Text::_('MOD_OTPLOGIN_ERR_PASS_SHORT'),
    'passMismatch' => Text::_('MOD_OTPLOGIN_ERR_PASS_MISMATCH'),
    'setpwHint'    => Text::_('MOD_OTPLOGIN_SETPW_HINT'),
];

ob_start();
?>
<div class="otp <?php echo $moduleClass; ?>"
     data-otplogin
     data-endpoint="<?php echo $e($endpoint); ?>"
     data-return="<?php echo $e($returnUrl); ?>"
     data-ts="<?php echo (int) $timestamp; ?>"
     data-length="<?php echo (int) $codeLength; ?>"
     data-i18n="<?php echo $e(json_encode($i18n, JSON_UNESCAPED_UNICODE)); ?>">

    <input type="hidden" name="<?php echo $e($tokenName); ?>" value="1" data-token>

    <?php if ($heading !== '') : ?>
        <h3 class="otp__title" id="<?php echo $uid; ?>-title" tabindex="-1" data-title><?php echo $e($heading); ?></h3>
    <?php else : ?>
        <h3 class="otp__title" id="<?php echo $uid; ?>-title" tabindex="-1" data-title hidden></h3>
    <?php endif; ?>

    <p class="otp__msg" data-msg role="status" aria-live="polite"></p>

    <?php /* ---- step 1: phone ---- */ ?>
    <form class="otp__step" data-step="phone" novalidate>
        <div class="otp__phone-row">
            <span class="otp__phone-cc" aria-hidden="true">+۹۸</span>
            <input class="otp__input otp__input--phone" type="tel" id="<?php echo $uid; ?>-phone" name="phone"
                   dir="ltr" inputmode="numeric" autocomplete="tel-national"
                   placeholder="<?php echo $e(Text::_('MOD_OTPLOGIN_PHONE_PLACEHOLDER')); ?>"
                   maxlength="20" required
                   aria-label="<?php echo $e(Text::_('MOD_OTPLOGIN_PHONE_LABEL')); ?>">
        </div>
        <div class="otp__hp" aria-hidden="true"><input type="text" name="website" tabindex="-1" autocomplete="off"></div>
        <button type="submit" class="otp__btn otp__btn--primary">
            <?php echo Text::_('MOD_OTPLOGIN_SEND'); ?>
            <span class="otp__btn-ico" aria-hidden="true">✈</span>
        </button>
        <div class="otp__row">
            <button type="button" class="otp__link" data-goto="password" hidden data-has-password-link>
                <span aria-hidden="true">🔑</span> <?php echo Text::_('MOD_OTPLOGIN_GO_PASSWORD'); ?>
            </button>
        </div>
        <div class="otp__row otp__row--alt" data-alt-login>
            <?php if ((int) $config->get('passkey_enabled', 1) === 1) : ?>
            <button type="button" class="otp__link" data-passkey-login>
                <span aria-hidden="true">🔐</span> <?php echo Text::_('MOD_OTPLOGIN_PASSKEY_LOGIN'); ?>
            </button>
            <?php endif; ?>
            <?php if ((int) $config->get('qr_login_enabled', 1) === 1) : ?>
            <button type="button" class="otp__link" data-qr-login>
                <span aria-hidden="true">▦</span> <?php echo Text::_('MOD_OTPLOGIN_QR_TITLE'); ?>
            </button>
            <?php endif; ?>
        </div>
    </form>

    <?php /* QR step */ ?>
    <div class="otp__step" data-step="qr" hidden>
        <p class="otp__label"><?php echo Text::_('MOD_OTPLOGIN_QR_TITLE'); ?></p>
        <p class="otp__hint"><?php echo Text::_('MOD_OTPLOGIN_QR_HINT'); ?></p>
        <div class="otp__qr" data-qr-box>
            <canvas data-qr-canvas width="200" height="200" aria-hidden="true"></canvas>
            <p class="otp__qr-token" data-qr-token dir="ltr"></p>
        </div>
        <p class="otp__hint" data-qr-wait><?php echo Text::_('MOD_OTPLOGIN_QR_WAITING'); ?></p>
        <div class="otp__row">
            <button type="button" class="otp__link" data-goto="phone"><?php echo Text::_('MOD_OTPLOGIN_BACK'); ?></button>
        </div>
    </div>

    <?php /* ---- returning user: password ---- */ ?>
    <form class="otp__step" data-step="password" hidden novalidate>
        <p class="otp__label" data-pass-intro></p>
        <label class="otp__label otp__label--sub" for="<?php echo $uid; ?>-pw">
            <?php echo Text::_('MOD_OTPLOGIN_PASS_LABEL'); ?>
            <span class="otp__label-ico" aria-hidden="true">🔒</span>
        </label>
        <input class="otp__input" type="password" dir="ltr" id="<?php echo $uid; ?>-pw" name="password"
               autocomplete="current-password" placeholder="<?php echo $e(Text::_('MOD_OTPLOGIN_PASS_PLACEHOLDER')); ?>">
        <button type="submit" class="otp__btn otp__btn--primary">
            <?php echo Text::_('MOD_OTPLOGIN_PASS_SUBMIT'); ?>
            <span class="otp__btn-ico" aria-hidden="true">↪</span>
        </button>
        <div class="otp__row">
            <button type="button" class="otp__link" data-sms-login>
                <span aria-hidden="true">📱</span> <?php echo Text::_('MOD_OTPLOGIN_PASS_SMS'); ?>
            </button>
            <button type="button" class="otp__link" data-goto="phone"><?php echo Text::_('MOD_OTPLOGIN_CHANGE_PHONE'); ?></button>
        </div>
    </form>

    <?php /* ---- after SMS sign-in: choose a password ---- */ ?>
    <form class="otp__step" data-step="setpassword" hidden novalidate>
        <p class="otp__label"><?php echo Text::_('MOD_OTPLOGIN_SETPW_TITLE'); ?></p>
        <p class="otp__hint" data-setpw-hint></p>
        <label class="otp__label otp__label--sub" for="<?php echo $uid; ?>-np1"><?php echo Text::_('MOD_OTPLOGIN_SETPW_NEW'); ?></label>
        <input class="otp__input" type="password" dir="ltr" id="<?php echo $uid; ?>-np1" name="password" autocomplete="new-password">
        <label class="otp__label otp__label--sub" for="<?php echo $uid; ?>-np2"><?php echo Text::_('MOD_OTPLOGIN_SETPW_REPEAT'); ?></label>
        <input class="otp__input" type="password" dir="ltr" id="<?php echo $uid; ?>-np2" name="password2" autocomplete="new-password">
        <button type="submit" class="otp__btn otp__btn--primary"><?php echo Text::_('MOD_OTPLOGIN_SETPW_SUBMIT'); ?></button>
        <div class="otp__row"><button type="button" class="otp__link" data-skip-setpw><?php echo Text::_('MOD_OTPLOGIN_SETPW_LATER'); ?></button></div>
    </form>

    <?php /* ---- step 2: code ---- */ ?>
    <form class="otp__step" data-step="code" hidden novalidate>
        <p class="otp__label" data-code-intro></p>
        <div class="otp__code" dir="ltr">
            <div class="otp__cells">
                <?php for ($i = 0; $i < $codeLength; $i++) : ?>
                    <input class="otp__cell" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" autocomplete="one-time-code"
                           data-cell autocomplete="<?php echo $i === 0 ? 'one-time-code' : 'off'; ?>"
                           aria-label="<?php echo $e('رقم ' . ($i + 1) . ' از ' . (int) $codeLength); ?>">
                <?php endfor; ?>
            </div>
            <div class="otp__life" data-life aria-hidden="true"><span class="otp__life-bar" data-life-bar></span></div>
        </div>
        <button type="submit" class="otp__btn otp__btn--primary">
            <?php echo Text::_('MOD_OTPLOGIN_VERIFY'); ?>
            <span class="otp__btn-ico" aria-hidden="true">✓</span>
        </button>
        <div class="otp__row">
            <button type="button" class="otp__link" data-resend disabled><?php echo Text::_('MOD_OTPLOGIN_RESEND'); ?></button>
            <button type="button" class="otp__link" data-goto="phone"><?php echo Text::_('MOD_OTPLOGIN_CHANGE_PHONE'); ?></button>
        </div>
    </form>

    <?php /* ---- step 3: new phone, choose ---- */ ?>
    <div class="otp__step" data-step="choice" hidden>
        <p class="otp__label"><?php echo Text::_('MOD_OTPLOGIN_CHOICE_TITLE'); ?></p>
        <button type="button" class="otp__choice" data-goto="register" data-choice="register">
            <strong><?php echo Text::_('MOD_OTPLOGIN_CHOICE_NEW'); ?></strong>
            <span><?php echo Text::_('MOD_OTPLOGIN_CHOICE_NEW_DESC'); ?></span>
        </button>
        <button type="button" class="otp__choice" data-goto="link" data-choice="link">
            <strong><?php echo Text::_('MOD_OTPLOGIN_CHOICE_OLD'); ?></strong>
            <span><?php echo Text::_('MOD_OTPLOGIN_CHOICE_OLD_DESC'); ?></span>
        </button>
    </div>

    <?php /* ---- step 4a: register ---- */ ?>
    <form class="otp__step" data-step="register" hidden novalidate>
        <p class="otp__label"><?php echo Text::_('MOD_OTPLOGIN_REGISTER_TITLE'); ?></p>
        <div data-field="name">
            <label class="otp__label otp__label--sub" for="<?php echo $uid; ?>-name"><?php echo Text::_('MOD_OTPLOGIN_NAME'); ?> <small data-optional hidden>(<?php echo Text::_('MOD_OTPLOGIN_OPTIONAL'); ?>)</small></label>
            <input class="otp__input" type="text" id="<?php echo $uid; ?>-name" name="name" autocomplete="name" maxlength="100">
        </div>
        <div data-field="email">
            <label class="otp__label otp__label--sub" for="<?php echo $uid; ?>-email"><?php echo Text::_('MOD_OTPLOGIN_EMAIL'); ?> <small data-optional hidden>(<?php echo Text::_('MOD_OTPLOGIN_OPTIONAL'); ?>)</small></label>
            <input class="otp__input" type="email" dir="ltr" id="<?php echo $uid; ?>-email" name="email" autocomplete="email">
        </div>
        <button type="submit" class="otp__btn otp__btn--primary"><?php echo Text::_('MOD_OTPLOGIN_REGISTER_SUBMIT'); ?></button>
        <div class="otp__row"><button type="button" class="otp__link" data-back-choice><?php echo Text::_('MOD_OTPLOGIN_BACK'); ?></button></div>
    </form>

    <?php /* ---- step 4b: link an old account ---- */ ?>
    <form class="otp__step" data-step="link" hidden novalidate>
        <p class="otp__label"><?php echo Text::_('MOD_OTPLOGIN_LINK_TITLE'); ?></p>
        <label class="otp__label otp__label--sub" for="<?php echo $uid; ?>-ident"><?php echo Text::_('MOD_OTPLOGIN_LINK_ID'); ?></label>
        <input class="otp__input" type="text" dir="ltr" id="<?php echo $uid; ?>-ident" name="identifier" autocomplete="username">
        <label class="otp__label otp__label--sub" for="<?php echo $uid; ?>-pass"><?php echo Text::_('MOD_OTPLOGIN_LINK_PASSWORD'); ?></label>
        <input class="otp__input" type="password" dir="ltr" id="<?php echo $uid; ?>-pass" name="password" autocomplete="current-password">
        <button type="submit" class="otp__btn otp__btn--primary"><?php echo Text::_('MOD_OTPLOGIN_LINK_SUBMIT'); ?></button>
        <div class="otp__row"><button type="button" class="otp__link" data-back-choice><?php echo Text::_('MOD_OTPLOGIN_BACK'); ?></button></div>
    </form>
</div>
<?php
$formHtml = ob_get_clean();

if ($mode === 'modal') : ?>
    <button type="button" class="otp-open <?php echo $moduleClass; ?>" data-otp-open="<?php echo $uid; ?>">
        <?php echo $e($buttonLabel); ?>
    </button>
    <?php /* UIkit modal markup. With UIkit loaded, UIkit moves it to <body> and runs it;
             without UIkit, otplogin.js provides a small fallback. */ ?>
    <div id="<?php echo $uid; ?>-modal" class="otp-modal" uk-modal aria-labelledby="<?php echo $uid; ?>-title" data-otp-modal>
        <div class="uk-modal-dialog otp-modal__dialog">
            <button class="uk-modal-close-outside" type="button" uk-close data-otp-close aria-label="<?php echo $e(Text::_('JCLOSE')); ?>"></button>
            <?php echo $formHtml; ?>
        </div>
    </div>
<?php else :
    echo $formHtml;
endif;

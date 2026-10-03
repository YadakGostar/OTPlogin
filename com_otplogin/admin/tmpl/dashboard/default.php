<?php
/**
 * @package     Otplogin
 * @license     GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/** @var \Otplogin\Component\Otplogin\Administrator\View\Dashboard\HtmlView $this */

$fmt = static fn (int $ts): string => HTMLHelper::_('date', gmdate('Y-m-d H:i:s', $ts), 'Y/m/d - H:i');
$ok  = !\in_array(false, $this->checks, true);

$isSuccessKind = static fn (string $k): bool => \in_array($k, ['login', 'login_pass', 'register', 'link', 'verify_ok', 'send'], true);

$maxMonth = 1;
foreach ($this->monthly as $m) {
    $maxMonth = max($maxMonth, $m['ok'], $m['fail']);
}

$logClass = static function (string $line): string {
    $u = strtoupper($line);
    if (str_contains($u, 'ERROR') || str_contains($u, '401') || str_contains($u, 'INVALID') || str_contains($u, 'FAIL')) {
        return 'otp-log--error';
    }
    if (str_contains($u, 'WARNING') || str_contains($u, 'WARN')) {
        return 'otp-log--warn';
    }
    if (str_contains($u, 'INFO') || str_contains($u, 'SUCCESS') || str_contains($u, 'OK')) {
        return 'otp-log--ok';
    }
    return 'otp-log--neutral';
};
?>
<style>
#otplogin-dashboard { --otp-gap: 1rem; font-size: 0.95rem; direction: rtl; text-align: right; }
#otplogin-dashboard .otp-panel, #otplogin-dashboard .otp-scard { direction: rtl; }
#otplogin-dashboard .otp-panel__head { direction: rtl; }
#otplogin-dashboard .otp-legend { justify-content: flex-start; direction: rtl; }
#otplogin-dashboard .otp-bars { direction: rtl !important; flex-direction: row-reverse !important; justify-content: flex-start; text-align: right; }
#otplogin-dashboard .otp-bars__col { direction: rtl !important; text-align: center; }
#otplogin-dashboard .otp-recent { direction: rtl; }
#otplogin-dashboard .otp-recent table { direction: rtl; width: 100%; }
#otplogin-dashboard .otp-log-wrap { direction: rtl; text-align: right; }

#otplogin-dashboard .otp-grid-cards {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: var(--otp-gap);
  margin-bottom: 1.25rem;
}
@media (max-width: 992px) {
  #otplogin-dashboard .otp-grid-cards { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 576px) {
  #otplogin-dashboard .otp-grid-cards { grid-template-columns: 1fr; }
}
#otplogin-dashboard .otp-scard {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 1.15rem 1.25rem;
  border-radius: 1.1rem;
  border: 0;
  box-shadow: 0 4px 18px rgba(15, 23, 42, 0.05);
  background: #fff;
  min-height: 5.5rem;
}
#otplogin-dashboard .otp-scard__icon {
  flex: 0 0 3rem;
  width: 3rem;
  height: 3rem;
  border-radius: 0.9rem;
  display: grid;
  place-items: center;
  color: #fff;
  font-size: 1.15rem;
}
#otplogin-dashboard .otp-scard__body { flex: 1; min-width: 0; text-align: end; }
#otplogin-dashboard .otp-scard__label {
  font-size: 0.88rem;
  font-weight: 600;
  color: #64748b;
  margin-bottom: 0.15rem;
}
#otplogin-dashboard .otp-scard__value {
  font-size: 1.75rem;
  font-weight: 800;
  line-height: 1.1;
  color: #0f172a;
  letter-spacing: -0.02em;
}
#otplogin-dashboard .otp-scard__hint {
  margin-top: 0.2rem;
  font-size: 0.78rem;
  font-weight: 600;
}
#otplogin-dashboard .otp-scard--danger { background: linear-gradient(135deg, #fff1f2, #ffe4e6); }
#otplogin-dashboard .otp-scard--danger .otp-scard__icon { background: #ef4444; }
#otplogin-dashboard .otp-scard--danger .otp-scard__hint { color: #e11d48; }
#otplogin-dashboard .otp-scard--info { background: linear-gradient(135deg, #eff6ff, #e0e7ff); }
#otplogin-dashboard .otp-scard--info .otp-scard__icon { background: #6366f1; }
#otplogin-dashboard .otp-scard--info .otp-scard__hint { color: #4f46e5; }
#otplogin-dashboard .otp-scard--success { background: linear-gradient(135deg, #ecfdf5, #d1fae5); }
#otplogin-dashboard .otp-scard--success .otp-scard__icon { background: #10b981; }
#otplogin-dashboard .otp-scard--success .otp-scard__hint { color: #059669; }
#otplogin-dashboard .otp-scard--primary { background: linear-gradient(135deg, #eff6ff, #dbeafe); }
#otplogin-dashboard .otp-scard--primary .otp-scard__icon { background: #2563eb; }
#otplogin-dashboard .otp-scard--primary .otp-scard__hint { color: #2563eb; }
#otplogin-dashboard .otp-scard--teal { background: linear-gradient(135deg, #ecfdf5, #d1fae5); }
#otplogin-dashboard .otp-scard--teal .otp-scard__icon { background: #0d9488; }
#otplogin-dashboard .otp-scard--teal .otp-scard__hint { color: #0f766e; }
#otplogin-dashboard .otp-scard--warning { background: linear-gradient(135deg, #fff7ed, #ffedd5); }
#otplogin-dashboard .otp-scard--warning .otp-scard__icon { background: #f59e0b; }
#otplogin-dashboard .otp-scard--warning .otp-scard__hint { color: #d97706; }

#otplogin-dashboard .otp-main-row {
  display: grid;
  grid-template-columns: 1.4fr 1fr;
  gap: var(--otp-gap);
  margin-bottom: 1.5rem;
}
@media (max-width: 992px) {
  #otplogin-dashboard .otp-main-row { grid-template-columns: 1fr; }
}
#otplogin-dashboard .otp-panel {
  background: #fff;
  border-radius: 1.1rem;
  box-shadow: 0 4px 18px rgba(15, 23, 42, 0.05);
  padding: 1.15rem 1.25rem 1rem;
}
#otplogin-dashboard .otp-panel__head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 0.85rem;
  gap: 0.75rem;
  flex-wrap: wrap;
}
#otplogin-dashboard .otp-panel__title {
  margin: 0;
  font-size: 1rem;
  font-weight: 700;
  color: #0f172a;
}
#otplogin-dashboard .otp-panel__sub {
  font-size: 0.8rem;
  color: #94a3b8;
}
#otplogin-dashboard .otp-legend {
  display: flex;
  gap: 0.85rem;
  font-size: 0.8rem;
  color: #64748b;
}
#otplogin-dashboard .otp-legend span::before {
  content: '';
  display: inline-block;
  width: 0.55rem;
  height: 0.55rem;
  border-radius: 50%;
  margin-inline-end: 0.3rem;
  vertical-align: middle;
}
#otplogin-dashboard .otp-legend .ok::before { background: #10b981; }
#otplogin-dashboard .otp-legend .fail::before { background: #f59e0b; }

#otplogin-dashboard .otp-bars {
  display: flex;
  align-items: flex-end;
  gap: 0.45rem;
  height: 12rem;
  padding: 0.25rem 0.15rem 0;
}
#otplogin-dashboard .otp-bars__col {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  height: 100%;
  min-width: 0;
}
#otplogin-dashboard .otp-bars__stack {
  flex: 1;
  width: 100%;
  display: flex;
  align-items: flex-end;
  justify-content: center;
  gap: 3px;
}
#otplogin-dashboard .otp-bars__bar {
  width: 42%;
  max-width: 14px;
  min-height: 3px;
  border-radius: 5px 5px 2px 2px;
}
#otplogin-dashboard .otp-bars__bar--ok { background: linear-gradient(180deg, #34d399, #059669); }
#otplogin-dashboard .otp-bars__bar--fail { background: linear-gradient(180deg, #fbbf24, #d97706); }
#otplogin-dashboard .otp-bars__bar[data-empty] { background: #e2e8f0; min-height: 2px; }
#otplogin-dashboard .otp-bars__lbl {
  margin-top: 0.4rem;
  font-size: 0.68rem;
  color: #94a3b8;
  white-space: nowrap;
}

#otplogin-dashboard .otp-recent { max-height: 18rem; overflow: auto; }
#otplogin-dashboard .otp-recent table { margin: 0; }
#otplogin-dashboard .otp-recent th {
  font-size: 0.75rem;
  font-weight: 600;
  color: #94a3b8;
  border-top: 0;
  background: transparent;
}
#otplogin-dashboard .otp-badge {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  padding: 0.2rem 0.55rem;
  font-size: 0.75rem;
  font-weight: 700;
  border-radius: 999px;
  white-space: nowrap;
}
#otplogin-dashboard .otp-badge--ok { background: #d1fae5; color: #065f46; }
#otplogin-dashboard .otp-badge--fail { background: #fee2e2; color: #991b1b; }

#otplogin-dashboard .otp-log {
  margin: 0;
  padding: 0;
  list-style: none;
  border-radius: 0.9rem;
  overflow: hidden;
  background: #0f172a;
}
#otplogin-dashboard .otp-log li {
  padding: 0.5rem 0.85rem;
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 0.75rem;
  line-height: 1.45;
  border-bottom: 1px solid rgba(148,163,184,.12);
  white-space: pre-wrap;
  word-break: break-word;
  direction: rtl;
  text-align: right;
  unicode-bidi: plaintext;
}
#otplogin-dashboard .otp-log li:last-child { border-bottom: 0; }
#otplogin-dashboard .otp-log--error   { color: #fda4af; background: rgba(244,63,94,.08); }
#otplogin-dashboard .otp-log--warn    { color: #fcd34d; background: rgba(245,158,11,.08); }
#otplogin-dashboard .otp-log--ok      { color: #6ee7b7; background: rgba(16,185,129,.08); }
#otplogin-dashboard .otp-log--neutral { color: #cbd5e1; }

#otplogin-dashboard .otp-online-pill {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.35rem 0.75rem;
  border-radius: 999px;
  background: #ecfdf5;
  color: #065f46;
  font-weight: 700;
  font-size: 0.85rem;
  margin-bottom: 1rem;
}
#otplogin-dashboard .otp-online-pill i {
  width: 0.55rem;
  height: 0.55rem;
  border-radius: 50%;
  background: #10b981;
  box-shadow: 0 0 0 3px rgba(16,185,129,.25);
}

#otplogin-dashboard, #otplogin-dashboard * { text-align: right; }
#otplogin-dashboard table th, #otplogin-dashboard table td { text-align: right !important; }
#otplogin-dashboard .otp-bars__val {
  font-size: 0.7rem;
  font-weight: 700;
  color: #64748b;
  min-height: 1rem;
  line-height: 1;
  margin-bottom: 0.2rem;
}
#otplogin-dashboard .otp-bars__bar--ok:not([data-empty]) { background: linear-gradient(180deg, #34d399, #10b981); }
#otplogin-dashboard .otp-bars__bar--fail:not([data-empty]) { background: linear-gradient(180deg, #fbbf24, #f59e0b); }
#otplogin-dashboard .otp-bars__bar[data-empty] { opacity: 0.25; background: #e2e8f0; }
#otplogin-dashboard .otp-online-list {
  list-style: none;
  margin: 0;
  padding: 0;
}
#otplogin-dashboard .otp-online-list li {
  display: flex;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.55rem 0.75rem;
  border-bottom: 1px solid #f1f5f9;
  align-items: center;
}
#otplogin-dashboard .otp-online-list li:last-child { border-bottom: 0; }
#otplogin-dashboard .otp-online-list .name { font-weight: 700; color: #0f172a; }
#otplogin-dashboard .otp-online-list .meta { font-size: 0.8rem; color: #64748b; }
#otplogin-dashboard .otp-online-list .dot {
  width: 0.55rem; height: 0.55rem; border-radius: 50%;
  background: #22c55e; flex-shrink: 0;
  box-shadow: 0 0 0 3px rgba(34,197,94,.2);
}
</style>

<div id="otplogin-dashboard">
<div class="otp-panel otp-health" style="margin-bottom:1.25rem;padding:1.25rem;border-radius:1.1rem;background:#fff;box-shadow:0 4px 18px rgba(15,23,42,.05);">
  <h3 style="margin:0 0 .75rem;font-size:1.05rem;font-weight:800;"><?php echo Text::_('COM_OTPLOGIN_HEALTH'); ?></h3>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(10rem,1fr));gap:.75rem;">
    <?php foreach (($this->health ?? []) as $h) : ?>
      <div style="padding:.75rem;border-radius:.75rem;background:<?php echo !empty($h['ok']) ? '#ecfdf5' : '#fff7ed'; ?>;border:1px solid <?php echo !empty($h['ok']) ? '#a7f3d0' : '#fed7aa'; ?>;">
        <div style="font-size:.8rem;color:#64748b;font-weight:600;"><?php echo !empty($h['icon']) ? $h['icon'] . ' ' : ''; ?><?php echo Text::_($h['label']); ?></div>
        <div style="font-weight:800;margin-top:.2rem;"><?php echo !empty($h['ok']) ? '✓' : '⚠'; ?> <?php echo htmlspecialchars((string) ($h['detail'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
      </div>
    <?php endforeach; ?>
  </div>
  <p style="margin:.9rem 0 .35rem;font-size:.85rem;color:#64748b;"><?php echo Text::_('COM_OTPLOGIN_HEALTH_WEBOTP'); ?></p>
  <p style="margin:0;font-size:.8rem;color:#94a3b8;"><?php echo Text::_('COM_OTPLOGIN_HEALTH_WEBOTP_HINT'); ?></p>
  <code style="display:block;margin-top:.35rem;padding:.6rem .75rem;background:#0f172a;color:#e2e8f0;border-radius:.5rem;direction:ltr;text-align:left;"><?php echo htmlspecialchars((string) ($this->webotpLine ?? ''), ENT_QUOTES, 'UTF-8'); ?></code>
  <p style="margin:.5rem 0 0;font-size:.78rem;color:#94a3b8;direction:ltr;text-align:left;">Example full SMS body last lines:<br>کد شما: %code%<br><?php echo htmlspecialchars((string) ($this->webotpLine ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
</div>


    <?php if (!$ok) : ?>
        <div class="alert alert-warning">
            <h2 class="alert-heading h5"><?php echo Text::_('COM_OTPLOGIN_SETUP_TITLE'); ?></h2>
            <ul class="mb-0">
                <?php foreach ($this->checks as $key => $pass) : ?>
                    <li>
                        <span class="icon-<?php echo $pass ? 'check' : 'times'; ?>" aria-hidden="true"></span>
                        <?php echo Text::_($key); ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="otp-online-pill">
        <i aria-hidden="true"></i>
        <?php echo Text::sprintf('COM_OTPLOGIN_ONLINE_NOW', (int) $this->onlineUsers); ?>
        <span class="text-muted fw-normal">· <?php echo Text::sprintf('COM_OTPLOGIN_LOGINS_TODAY', (int) $this->logins24h); ?></span>
    </div>

    <div class="otp-grid-cards">
        <?php foreach ($this->cards as $card) :
            $hint = !empty($card['raw_hint']) ? $card['hint'] : Text::_($card['hint']);
            ?>
            <div class="otp-scard otp-scard--<?php echo $this->escape($card['tone']); ?>">
                <div class="otp-scard__icon">
                    <span class="icon-<?php echo $this->escape($card['icon']); ?>" aria-hidden="true"></span>
                </div>
                <div class="otp-scard__body">
                    <div class="otp-scard__label"><?php echo Text::_($card['label']); ?></div>
                    <div class="otp-scard__value"><?php echo number_format((int) $card['value']); ?></div>
                    <div class="otp-scard__hint"><?php echo $hint; ?></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="otp-main-row">
        <div class="otp-panel">
            <div class="otp-panel__head">
                <div>
                    <h2 class="otp-panel__title"><?php echo Text::_('COM_OTPLOGIN_CHART_SMS'); ?></h2>
                    <div class="otp-panel__sub"><?php echo Text::_('COM_OTPLOGIN_CHART_SMS_SUB'); ?></div>
                </div>
                <div class="otp-legend">
                    <span class="ok"><?php echo Text::_('COM_OTPLOGIN_LEGEND_OK'); ?></span>
                    <span class="fail"><?php echo Text::_('COM_OTPLOGIN_LEGEND_FAIL'); ?></span>
                </div>
            </div>
            <div class="otp-bars" role="img" aria-label="<?php echo $this->escape(Text::_('COM_OTPLOGIN_CHART_SMS')); ?>">
                <?php foreach ($this->monthly as $m) :
                    $okH   = $m['ok'] > 0 ? max(12, (int) round(($m['ok'] / $maxMonth) * 100)) : 3;
                    $failH = $m['fail'] > 0 ? max(12, (int) round(($m['fail'] / $maxMonth) * 100)) : 3;
                    $total = (int) $m['ok'] + (int) $m['fail'];
                    ?>
                    <div class="otp-bars__col" title="<?php echo (int) $m['ok']; ?> موفق / <?php echo (int) $m['fail']; ?> ناموفق">
                        <div class="otp-bars__val"><?php echo $total > 0 ? $total : ''; ?></div>
                        <div class="otp-bars__stack">
                            <div class="otp-bars__bar otp-bars__bar--ok" style="height:<?php echo $okH; ?>%"<?php echo $m['ok'] === 0 ? ' data-empty' : ''; ?>></div>
                            <div class="otp-bars__bar otp-bars__bar--fail" style="height:<?php echo $failH; ?>%"<?php echo $m['fail'] === 0 ? ' data-empty' : ''; ?>></div>
                        </div>
                        <div class="otp-bars__lbl"><?php echo $this->escape($m['label']); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="otp-panel">
            <div class="otp-panel__head">
                <h2 class="otp-panel__title"><?php echo Text::_('COM_OTPLOGIN_RECENT_LOGS'); ?> <span class="otp-panel__sub">(۵ مورد آخر)</span></h2>
            </div>
            <div class="otp-recent">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th><?php echo Text::_('COM_OTPLOGIN_COL_USER'); ?></th>
                            <th><?php echo Text::_('COM_OTPLOGIN_COL_STATUS'); ?></th>
                            <th><?php echo Text::_('COM_OTPLOGIN_COL_TIME'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$this->events) : ?>
                            <tr><td colspan="3" class="text-muted"><?php echo Text::_('COM_OTPLOGIN_LOG_EMPTY'); ?></td></tr>
                        <?php else : ?>
                            <?php foreach ($this->events as $e) :
                                $success = $isSuccessKind((string) $e->kind);
                                $who = trim((string) ($e->user_name ?: $e->user_username ?: $e->subject ?: '—'));
                                ?>
                                <tr>
                                    <td dir="auto"><?php echo $this->escape($who); ?></td>
                                    <td>
                                        <span class="otp-badge otp-badge--<?php echo $success ? 'ok' : 'fail'; ?>">
                                            <?php echo $success ? '✓ ' . Text::_('COM_OTPLOGIN_STATUS_OK') : '× ' . Text::_('COM_OTPLOGIN_STATUS_FAIL'); ?>
                                        </span>
                                    </td>
                                    <td class="text-nowrap small text-muted"><?php echo $fmt((int) $e->created_at); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <h2 class="h5 mt-2"><?php echo Text::_('COM_OTPLOGIN_TEST_TITLE'); ?></h2>
    <form action="<?php echo Route::_('index.php?option=com_otplogin&task=test.send'); ?>" method="post" class="row g-2 align-items-end mb-4">
        <div class="col-auto">
            <label class="form-label" for="otp-test-phone"><?php echo Text::_('COM_OTPLOGIN_TEST_PHONE'); ?></label>
            <input type="tel" dir="ltr" class="form-control" id="otp-test-phone" name="phone" placeholder="09123456789" required>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-primary"><?php echo Text::_('COM_OTPLOGIN_TEST_SEND'); ?></button>
        </div>
        <div class="col-12 form-text"><?php echo Text::_('COM_OTPLOGIN_TEST_DESC'); ?></div>
        <?php echo HTMLHelper::_('form.token'); ?>
    </form>

    <h2 class="h5"><?php echo Text::_('COM_OTPLOGIN_BLOCKS'); ?></h2>
    <?php if (!$this->blocks) : ?>
        <p class="text-muted"><?php echo Text::_('COM_OTPLOGIN_NO_BLOCKS'); ?></p>
    <?php else : ?>
        <form action="<?php echo Route::_('index.php?option=com_otplogin&task=blocks.unblock'); ?>" method="post">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th><?php echo Text::_('COM_OTPLOGIN_COL_TYPE'); ?></th>
                        <th><?php echo Text::_('COM_OTPLOGIN_COL_TARGET'); ?></th>
                        <th><?php echo Text::_('COM_OTPLOGIN_COL_UNTIL'); ?></th>
                        <th><?php echo Text::_('COM_OTPLOGIN_COL_REASON'); ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($this->blocks as $b) : ?>
                        <tr>
                            <td><?php echo $this->escape($b->scope); ?></td>
                            <td dir="ltr"><?php echo $this->escape($b->target); ?></td>
                            <td><?php echo $fmt((int) $b->blocked_until); ?></td>
                            <td><?php echo $this->escape($b->reason); ?></td>
                            <td>
                                <button type="submit" name="id" value="<?php echo (int) $b->id; ?>" class="btn btn-sm btn-outline-danger">
                                    <?php echo Text::_('COM_OTPLOGIN_UNBLOCK'); ?>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <button type="submit" name="id" value="0" class="btn btn-danger"><?php echo Text::_('COM_OTPLOGIN_UNBLOCK_ALL'); ?></button>
            <?php echo HTMLHelper::_('form.token'); ?>
        </form>
    <?php endif; ?>


    <div class="otp-panel mt-3">
        <div class="otp-panel__head">
            <h2 class="otp-panel__title"><?php echo Text::_('COM_OTPLOGIN_ONLINE_LIST'); ?>
                <span class="otp-panel__sub">(<?php echo (int) $this->onlineUsers; ?>)</span>
            </h2>
        </div>
        <?php if (empty($this->onlineList)) : ?>
            <p class="text-muted mb-0"><?php echo Text::_('COM_OTPLOGIN_ONLINE_EMPTY'); ?></p>
        <?php else : ?>
            <ul class="otp-online-list">
                <?php foreach ($this->onlineList as $ou) :
                    $seen = isset($ou->last_seen) ? (int) $ou->last_seen : time();
                    $seenLabel = HTMLHelper::_('date', gmdate('Y-m-d H:i:s', $seen), 'H:i');
                    ?>
                    <li>
                        <span class="dot" aria-hidden="true"></span>
                        <div style="flex:1;min-width:0;">
                            <div class="name"><?php echo $this->escape($ou->name ?: $ou->username); ?></div>
                            <div class="meta"><?php echo $this->escape($ou->username); ?><?php echo !empty($ou->email) ? ' · ' . $this->escape($ou->email) : ''; ?></div>
                        </div>
                        <div class="meta"><?php echo $this->escape($seenLabel); ?></div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <div class="d-flex align-items-center justify-content-between gap-2 mt-4 mb-2 flex-wrap">
        <h2 class="h5 mb-0"><?php echo Text::_('COM_OTPLOGIN_LOG_TITLE'); ?></h2>
        <div class="d-flex align-items-center gap-2">
            <span class="small text-muted"><?php echo count($this->log); ?>/5</span>
            <form action="<?php echo Route::_('index.php?option=com_otplogin&task=display.clearlog'); ?>" method="post" class="m-0"
                  onsubmit="return confirm('<?php echo $this->escape(Text::_('COM_OTPLOGIN_LOG_CLEAR_CONFIRM')); ?>');">
                <button type="submit" class="btn btn-sm btn-outline-danger"><?php echo Text::_('COM_OTPLOGIN_LOG_CLEAR'); ?></button>
                <?php echo HTMLHelper::_('form.token'); ?>
            </form>
        </div>
    </div>
    <?php if (!$this->log) : ?>
        <p class="text-muted"><?php echo Text::_('COM_OTPLOGIN_LOG_EMPTY'); ?></p>
    <?php else : ?>
        <ul class="otp-log mb-3 otp-log-wrap">
            <?php
            $eventLabels = [
                'token' => 'نشست منقضی',
                'bot' => 'مشکوک به ربات',
                'method' => 'درخواست نامعتبر',
                'phone' => 'شماره نامعتبر',
                'blocked' => 'مسدود شده',
                'cooldown' => 'زمان انتظار',
                'sms_failed' => 'خطای پیامک',
                'wrong_code' => 'کد نادرست',
                'expired' => 'کد منقضی',
                'too_many' => 'تلاش بیش از حد',
                'pass_invalid' => 'رمز نادرست',
                'session_limit' => 'سقف نشست',
                'pow_required' => 'ضدربات',
                'pow_invalid' => 'ضدربات ناموفق',
                'avatar_upload' => 'آپلود عکس',
            ];
            foreach ($this->log as $line) :
                $parts = explode("	", $line);
                $when  = $parts[0] ?? '';
                $event = $parts[1] ?? '';
                $ip    = $parts[2] ?? '';
                $detail = $parts[5] ?? ($parts[3] ?? '');
                // AJAX_FAIL:token → token
                $code = $event;
                if (str_starts_with($event, 'AJAX_FAIL:')) {
                    $code = substr($event, 10);
                }
                $label = $eventLabels[$code] ?? $code;
                // Soften date display
                $whenShow = $when;
                if (preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})/', $when, $m)) {
                    $whenShow = $m[1] . ' ' . $m[2];
                }
                // Clean detail: strip leading dots/spaces from RTL glitches
                $detail = trim($detail, " 	

 .");
                $row = trim($label . ($detail !== '' ? ' — ' . $detail : '') . ($ip !== '' ? ' | IP: ' . $ip : '') . ($whenShow !== '' ? ' | ' . $whenShow : ''));
                ?>
                <li class="<?php echo $logClass($line); ?>"><?php echo $this->escape($row); ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

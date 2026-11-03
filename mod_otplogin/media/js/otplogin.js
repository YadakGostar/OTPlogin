/* Copyright (C) 2026 Reza Esfandiari; Author: Reza Esfandiari; Website: https://www.yadakgostar.co; licensed under GNU General Public License version 2 or later. */
/**
 * mod_otplogin front-end. Vanilla JS, no dependencies.
 * Steps: phone -> code -> (choice -> register | link) -> redirect
 */
(() => {
  'use strict';

  const FA = '۰۱۲۳۴۵۶۷۸۹';
  const AR = '٠١٢٣٤٥٦٧٨٩';
  const toLatin = (s) =>
    String(s).replace(/[۰-۹]/g, (d) => FA.indexOf(d)).replace(/[٠-٩]/g, (d) => AR.indexOf(d));
  const fill = (tpl, vars) => tpl.replace(/\{(\w+)\}/g, (_, k) => (k in vars ? vars[k] : ''));
  const mmss = (sec) => {
    const s = Math.max(0, Math.ceil(sec));
    return String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0');
  };
  const bufToB64url = (buf) => {
    const bytes = new Uint8Array(buf);
    let s = '';
    bytes.forEach((b) => { s += String.fromCharCode(b); });
    return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  };
  const b64urlToBuf = (b64) => {
    const pad = '='.repeat((4 - (b64.length % 4)) % 4);
    const raw = atob((b64 + pad).replace(/-/g, '+').replace(/_/g, '/'));
    const out = new Uint8Array(raw.length);
    for (let i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
    return out.buffer;
  };
  /** Ensure RP ID is valid for window.location.hostname */
  const safeRpId = (serverRpId) => {
    const host = (location.hostname || '').toLowerCase();
    let rp = String(serverRpId || '').toLowerCase().trim()
      .replace(/^https?:\/\//, '').split('/')[0].split(':')[0];
    if (!rp || rp === host) return host;
    if (host === rp || host.endsWith('.' + rp)) return rp;
    // Fall back to current hostname — never send a foreign RP ID to WebAuthn.
    return host;
  };
  const passkeyErr = (err, isAdmin) => {
    const name = err && err.name ? err.name : '';
    const msg = err && err.message ? String(err.message) : '';
    if (name === 'NotAllowedError' || /timed out|not allowed/i.test(msg)) {
      // Technical RP ID hint only for Super User / admin
      if (isAdmin) {
        return 'عملیات لغو شد یا زمان آن تمام شد. اگر خطا درباره RP ID بود، فیلد «شناسه دامنه» را در تنظیمات خالی بگذارید.';
      }
      return 'عملیات لغو شد یا زمان آن تمام شد.';
    }
    if (/relying party|rp id|registrable domain/i.test(msg)) {
      if (isAdmin) {
        return 'دامنه Passkey با آدرس سایت یکی نیست. در تنظیمات، «شناسه دامنه (RP ID)» را خالی بگذارید یا دقیقاً دامنه سایت را وارد کنید (مثلا example.com).';
      }
      return 'ورود با این دستگاه ممکن نشد. دوباره تلاش کنید.';
    }
    if (!window.isSecureContext && location.hostname !== 'localhost') {
      return 'Passkey فقط روی HTTPS کار می‌کند.';
    }
    return isAdmin ? (msg || 'خطای Passkey') : 'ورود با Passkey ناموفق بود.';
  };

  class OtpLogin {
    constructor(root) {
      this.root = root;
      this.endpoint = root.dataset.endpoint;
      this.returnUrl = root.dataset.return || '';
      // Joomla login links: /login?return=base64(url)
      try {
        const q = new URLSearchParams(window.location.search || '');
        const raw = q.get('return') || '';
        if (raw) {
          let decoded = '';
          try {
            decoded = atob(raw.replace(/-/g, '+').replace(/_/g, '/'));
          } catch (e1) {
            try { decoded = decodeURIComponent(escape(atob(raw))); } catch (e2) { decoded = ''; }
          }
          if (decoded && (decoded.indexOf(location.origin) === 0 || decoded.charAt(0) === '/')) {
            this.returnUrl = decoded;
          }
        }
      } catch (e) {}
      this.ts = root.dataset.ts || '';
      this.formStartedAt = Math.floor(Date.now() / 1000);
      this.length = parseInt(root.dataset.length, 10) || 5;
      this.t = JSON.parse(root.dataset.i18n || '{}');
      const tokEl = root.querySelector('[data-token]');
      this.tokenName = tokEl ? tokEl.name : '';
      // Prefer live token from Joomla core options when present (avoids stale cached HTML).
      try {
        if (window.Joomla && typeof Joomla.getOptions === 'function') {
          const live = Joomla.getOptions('csrf.token');
          if (live) this.tokenName = live;
        }
      } catch (e) { /* ignore */ }
      this.msg = root.querySelector('[data-msg]');
      this.title = root.querySelector('[data-title]');
      this.steps = {};
      root.querySelectorAll('[data-step]').forEach((el) => (this.steps[el.dataset.step] = el));
      this.cells = [...root.querySelectorAll('[data-cell]')];
      this.cellBox = root.querySelector('.otp__cells');
      this.life = root.querySelector('[data-life]');
      this.lifeBar = root.querySelector('[data-life-bar]');
      this.resendBtn = root.querySelector('[data-resend]');
      this.phone = '';
      this.maskedPhone = '';
      this.expireAt = 0;
      this.ttl = 0;
      this.resendAt = 0;
      this.timer = null;
      this.choice = { register: false, link: false };
      this.reset = false; // the user chose "forgot password / SMS code"
      this.minLen = 8;
      this.redirectTo = '';
      this.bind();
    }

    bind() {
      const on = (el, ev, fn) => { if (el) el.addEventListener(ev, fn); };
      on(this.steps.phone, 'submit', (e) => { e.preventDefault(); this.sendCode(); });
      if (this.steps.userpass) on(this.steps.userpass, 'submit', (e) => { e.preventDefault(); this.userPassLogin(); });
      on(this.steps.code, 'submit', (e) => { e.preventDefault(); this.verify(); });
      on(this.steps.register, 'submit', (e) => { e.preventDefault(); this.register(); });
      on(this.steps.link, 'submit', (e) => { e.preventDefault(); this.link(); });
      on(this.steps.password, 'submit', (e) => { e.preventDefault(); this.loginWithPassword(); });
      on(this.steps.setpassword, 'submit', (e) => { e.preventDefault(); this.setPassword(); });
      on(this.root.querySelector('[data-sms-login]'), 'click', () => { this.reset = true; this.sendCode(false, true); });
      on(this.root.querySelector('[data-skip-setpw]'), 'click', () => { window.location.href = this.redirectTo || window.location.href; });
      on(this.resendBtn, 'click', () => this.sendCode(true));

      this.root.querySelectorAll('[data-goto]').forEach((el) =>
        el.addEventListener('click', () => {
          const target = el.dataset.goto;
          if (target === 'phone') this.stopTimer();
          this.show(target);
        })
      );
      this.root.querySelectorAll('[data-back-choice]').forEach((el) =>
        el.addEventListener('click', () => this.show(this.choice.register && this.choice.link ? 'choice' : 'code'))
      );

      this.cells.forEach((cell, i) => {
        cell.addEventListener('input', () => this.onCellInput(i));
        cell.addEventListener('keydown', (e) => this.onCellKey(e, i));
        cell.addEventListener('focus', () => cell.select());
        cell.addEventListener('paste', (e) => {
          const text = toLatin((e.clipboardData || window.clipboardData).getData('text')).replace(/\D/g, '');
          if (!text) return;
          e.preventDefault();
          this.spread(text, i);
        });
      });

      const pkBtn = this.root.querySelector('[data-passkey-login]');
      if (pkBtn) pkBtn.addEventListener('click', () => this.passkeyLogin());
      const qrBtn = this.root.querySelector('[data-qr-login]');
      if (qrBtn) qrBtn.addEventListener('click', () => this.qrStart());
    }

    async passkeyLogin() {
      if (!window.PublicKeyCredential) {
        this.say(this.t.passkeyUnsupported || 'Passkeys not supported', 'error');
        return;
      }
      if (!window.isSecureContext && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') {
        this.say('Passkey نیاز به HTTPS دارد', 'error');
        return;
      }
      try {
        const optRes = await this.post('passkeyauthoptions', {});
        if (!optRes.ok) { this.say(optRes.message || this.t.network, 'error'); return; }
        const o = optRes.options || {};
        const rpId = safeRpId(o.rpId);
        const publicKey = {
          challenge: b64urlToBuf(o.challenge),
          rpId,
          timeout: o.timeout || 30000,
          userVerification: o.userVerification || 'preferred',
        };
        if (o.allowCredentials && o.allowCredentials.length) {
          publicKey.allowCredentials = o.allowCredentials.map((c) => ({
            type: 'public-key',
            id: typeof c.id === 'string' ? b64urlToBuf(c.id) : c.id,
            transports: c.transports,
          }));
        }
        const cred = await navigator.credentials.get({ publicKey });
        if (!cred) return;
        const r = await this.post('passkeyauth', {
          credentialId: bufToB64url(cred.rawId),
          authenticatorData: bufToB64url(cred.response.authenticatorData),
          clientDataJSON: bufToB64url(cred.response.clientDataJSON),
          signature: bufToB64url(cred.response.signature),
          return: this.returnUrl,
        });
        if (r.ok) this.finish(r);
        else this.say(r.message || this.t.network, 'error');
      } catch (err) {
        this.say(passkeyErr(err, this.root && this.root.getAttribute('data-admin') === '1'), 'error');
      }
    }

    async qrStart() {
      try {
        const r = await this.post('qrcreate', {});
        if (!r.ok) {
          this.say(r.message || this.t.network, 'error');
          return;
        }
        this.qrToken = r.token || '';
        // Must be a full HTTPS URL — raw token opens as a fake domain on phones
        this.qrUrl = r.confirm_url || r.payload || r.confirm_hint || '';
        if (!this.qrUrl || this.qrUrl.indexOf('http') !== 0) {
          this.qrUrl = (window.location.origin || '') + '/index.php?option=com_otplogin&task=ajax.qrscan&token=' + encodeURIComponent(this.qrToken);
        }
        if (this.steps.qr) this.show('qr');
        const tokenEl = this.root.querySelector('[data-qr-token]');
        if (tokenEl) tokenEl.textContent = (this.qrToken || '').slice(0, 12) + '…';
        const canvas = this.root.querySelector('[data-qr-canvas]');
        if (canvas && this.qrUrl) {
          const ctx = canvas.getContext('2d');
          ctx.fillStyle = '#fff';
          ctx.fillRect(0, 0, 200, 200);
          const img = new Image();
          img.crossOrigin = 'anonymous';
          img.onload = () => { ctx.drawImage(img, 0, 0, 200, 200); };
          img.onerror = () => {
            ctx.fillStyle = '#111';
            ctx.font = '10px monospace';
            ctx.fillText('QR URL', 70, 100);
          };
          img.src = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' + encodeURIComponent(this.qrUrl);
        }
        this.qrPoll();
      } catch (err) {
        this.say(this.t.network, 'error');
      }
    }

    async qrPoll() {
      if (!this.qrToken) return;
      clearTimeout(this._qrTimer);
      try {
        const r = await this.post('qrstatus', { qr_token: this.qrToken, return: this.returnUrl });
        if (r.ok && r.status === 'approved') {
          this.finish(r);
          return;
        }
        if (r.ok && (r.status === 'pending' || r.status === 'scanned')) {
          this._qrTimer = setTimeout(() => this.qrPoll(), 2000);
          return;
        }
        if (!r.ok) this.say(r.message || this.t.network, 'error');
      } catch (e) {
        this._qrTimer = setTimeout(() => this.qrPoll(), 3000);
      }
    }

    // ------------------------------------------------------------ UI helpers

    show(step) {
      Object.entries(this.steps).forEach(([name, el]) => { el.hidden = name !== step; });
      this.say('');
      const first = this.steps[step].querySelector('input:not([type=hidden]):not([tabindex="-1"]), button');
      if (first) setTimeout(() => first.focus(), 30);
    }

    /** Keep phone-like tokens readable in RTL without splitting digits. */
    ltrPhone(s) {
      // Leading/trailing LRM (U+200E) — does not break the number mid-string
      return '\u200E' + String(s || '') + '\u200E';
    }

    say(text, kind = '') {
      // Do not inject bidi isolates into full 10-digit numbers (they were splitting 91234567|89).
      // CSS unicode-bidi:plaintext on .otp__msg handles mixed RTL/LTR correctly.
      this.msg.textContent = String(text || '');
      if (kind) this.msg.dataset.kind = kind; else delete this.msg.dataset.kind;
    }

    busy(form, on, label) {
      const btn = form.querySelector('button[type=submit]');
      form.querySelectorAll('input, button').forEach((el) => { if (on) el.dataset.wasDisabled = el.disabled ? '1' : ''; });
      form.querySelectorAll('input').forEach((el) => { el.readOnly = on; });
      if (btn) {
        if (on) { btn.dataset.label = btn.textContent; btn.textContent = label || this.t.working; btn.disabled = true; }
        else { if (btn.dataset.label) btn.textContent = btn.dataset.label; btn.disabled = false; }
      }
    }

    async refreshToken() {
      try {
        const res = await fetch(`${this.endpoint}&task=ajax.token&_=${Date.now()}`, {
          method: 'GET',
          credentials: 'same-origin',
          cache: 'no-store',
          headers: { Accept: 'application/json' },
        });
        const json = await res.json();
        if (json && json.ok && json.token) {
          this.tokenName = json.token;
          return true;
        }
      } catch (e) { /* keep the token already rendered in the page */ }
      return false;
    }


    tryWebOtp(length) {
      if (!('OTPCredential' in window) || !navigator.credentials) return;
      const ac = new AbortController();
      this._otpAbort = ac;
      navigator.credentials.get({
        otp: { transport: ['sms'] },
        signal: ac.signal,
      }).then((otp) => {
        if (!otp || !otp.code) return;
        const digits = String(otp.code).replace(/\D+/g, '').slice(0, length);
        const cells = this.steps.code.querySelectorAll('.otp__cell');
        if (cells.length) {
          digits.split('').forEach((d, i) => { if (cells[i]) cells[i].value = d; });
          if (digits.length >= length) {
            const form = this.steps.code;
            if (form && form.requestSubmit) form.requestSubmit();
            else if (form) form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
          }
        }
      }).catch(() => {});
    }

    async post(task, data) {
      // A cached Joomla page can contain an old token. Fetch a fresh token from
      // the live session immediately before every POST.
      await this.refreshToken();

      const body = new FormData();
      if (this.tokenName) {
        body.append(this.tokenName, '1');
        body.append('csrf_token', this.tokenName);
      }
      body.append('return', this.returnUrl);
      // Never trust a cached server-rendered timestamp for the anti-bot check.
      body.append('ts', String(this.formStartedAt));
      Object.entries(data).forEach(([k, v]) => body.append(k, v));
      try {
        const headers = { Accept: 'application/json' };
        if (this.tokenName) headers['X-CSRF-Token'] = this.tokenName;
        const res = await fetch(`${this.endpoint}&task=ajax.${task}&_=${Date.now()}`, {
          method: 'POST',
          body,
          credentials: 'same-origin',
          cache: 'no-store',
          headers,
        });
        const json = await res.json();
        if (json && json.token) this.tokenName = json.token;
        return json;
      } catch (err) {
        return { ok: false, message: this.t.network };
      }
    }

    // ------------------------------------------------------------ step 1: phone

    /**
     * isResend:     "resend code" button on the code step
     * fromPassword: "forgot password / SMS code" on the password step
     * otherwise:    first submit of the phone form, which asks the server whether to
     *               request a password or to send a code ("lookup").
     */
    async userPassLogin() {
      const form = this.steps.userpass;
      if (!form) return;
      const username = (form.elements.username && form.elements.username.value || '').trim();
      const password = form.elements.password ? form.elements.password.value : '';
      if (!username || !password) {
        this.say(this.t.userPassEmpty || this.t.passEmpty, 'error');
        return;
      }
      this.busy(form, true);
      const r = await this.post('passworduser', {
        username,
        password,
        return: this.root.dataset.return || '',
      });
      this.busy(form, false);
      if (!r.ok) {
        this.say(r.message || this.t.network, 'error');
        return;
      }
      if (r.action === 'done' || r.action === 'setpassword') this.finish(r);
    }

    async sendCode(isResend = false, fromPassword = false) {
      const form = this.steps.phone;
      const direct = isResend || fromPassword;
      const raw = direct ? this.phone : form.elements.phone.value;
      const digits = toLatin(raw).replace(/\D/g, '');

      // Only 10 digits starting with 9 — leading zero is rejected
      if (digits.charAt(0) === '0' || !/^9\d{9}$/.test(digits)) {
        this.say(this.t.phoneInvalid, 'error');
        form.elements.phone.focus();
        return;
      }

      if (!direct) this.reset = false;
      this.phone = digits;
      this.say('');

      const busyForm = fromPassword ? this.steps.password : form;
      if (isResend) this.resendBtn.disabled = true; else this.busy(busyForm, true, this.t.sending);
      let powPayload = {};
      try {
        const ch = await this.post('powchallenge', {});
        if (ch && ch.ok && ch.enabled && ch.challenge) {
          this.say(this.t.powWorking || 'در حال بررسی امنیتی…', 'info');
          const nonce = await solvePow(ch.challenge, ch.difficulty || 13);
          powPayload = { pow_challenge: ch.challenge, pow_nonce: nonce };
        }
      } catch (powErr) {
        if (!isResend) this.busy(busyForm, false);
        if (isResend) this.resendBtn.disabled = false;
        this.say(this.t.powFail || 'بررسی امنیتی ناموفق بود. دوباره تلاش کنید.', 'error');
        return;
      }
      const r = await this.post(direct ? 'send' : 'lookup', Object.assign({ phone: digits, website: form.elements.website.value }, powPayload));
      if (!isResend) this.busy(busyForm, false);

      if (r.ok && r.action === 'password') {
        this.steps.password.querySelector('[data-pass-intro]').textContent =
          fill(this.t.passIntro, { phone: this.ltrPhone(r.phone) });
        this.steps.password.elements.password.value = '';
        this.show('password');
        return;
      }

      if (r.ok) {
        this.maskedPhone = r.phone;
        this.enterCodeStep(r.ttl, r.cooldown);
        return;
      }

      // A code was already sent a moment ago: let the user type it instead of blocking them.
      if (r.error === 'cooldown') {
        this.maskedPhone = ('0' + digits).replace(/^(\d{4})\d{3}(\d{4})$/, '$1***$2');
        this.enterCodeStep(0, r.retry_after);
        this.say(r.message, 'error');
        return;
      }

      this.say(r.message || this.t.network, 'error');
      if (isResend) this.resendBtn.disabled = false;
    }

    // ------------------------------------------------------------ step 2: code

    enterCodeStep(ttl, cooldown) {
      this.cells.forEach((c) => { c.value = ''; });
      this.cellBox.removeAttribute('data-error');
      this.steps.code.querySelector('[data-code-intro]').textContent =
        fill(this.t.codeSent, { phone: this.ltrPhone(this.maskedPhone) });
      this.ttl = ttl;
      this.expireAt = ttl ? Date.now() + ttl * 1000 : 0;
      this.resendAt = Date.now() + (cooldown || 0) * 1000;
      this.tryWebOtp(length || 5);
      this.life.hidden = !ttl;
      this.startTimer();
      this.show('code');
      this.cells[0].focus();
    }

    startTimer() {
      this.stopTimer();
      const tick = () => {
        const now = Date.now();
        const wait = (this.resendAt - now) / 1000;
        if (wait > 0) {
          this.resendBtn.disabled = true;
          this.resendBtn.textContent = fill(this.t.resendIn, { time: mmss(wait) });
        } else {
          this.resendBtn.disabled = false;
          this.resendBtn.textContent = this.t.resend;
        }
        if (this.expireAt) {
          const left = (this.expireAt - now) / 1000;
          this.lifeBar.style.transform = `scaleX(${Math.max(0, left / this.ttl)})`;
          if (left <= 15) this.life.setAttribute('data-low', ''); else this.life.removeAttribute('data-low');
          if (left <= 0 && !this.expiredShown) { this.expiredShown = true; this.say(this.t.expired, 'error'); }
        }
        if (wait <= 0 && (!this.expireAt || this.expireAt <= now)) this.stopTimer();
      };
      this.expiredShown = false;
      tick();
      this.timer = setInterval(tick, 1000);
    }

    stopTimer() {
      if (this.timer) clearInterval(this.timer);
      this.timer = null;
    }

    onCellInput(i) {
      const cell = this.cells[i];
      const text = toLatin(cell.value).replace(/\D/g, '');
      cell.value = '';
      if (text) this.spread(text, i);
    }

    spread(text, start) {
      let i = start;
      for (const ch of text) {
        if (i >= this.cells.length) break;
        this.cells[i++].value = ch;
      }
      const next = Math.min(i, this.cells.length - 1);
      this.cells[next].focus();
      this.cellBox.removeAttribute('data-error');
      if (this.codeValue().length === this.cells.length) this.verify();
    }

    onCellKey(e, i) {
      if (e.key === 'Backspace' && !this.cells[i].value && i > 0) {
        this.cells[i - 1].value = '';
        this.cells[i - 1].focus();
        e.preventDefault();
      } else if (e.key === 'ArrowLeft' && i > 0) {
        this.cells[i - 1].focus();
        e.preventDefault();
      } else if (e.key === 'ArrowRight' && i < this.cells.length - 1) {
        this.cells[i + 1].focus();
        e.preventDefault();
      }
    }

    codeValue() { return this.cells.map((c) => c.value).join(''); }

    async verify() {
      if (this.verifying) return;
      const code = this.codeValue();
      if (code.length < this.cells.length) { this.say(this.t.codeShort, 'error'); return; }

      this.verifying = true;
      const form = this.steps.code;
      this.busy(form, true, this.t.verifying);
      const r = await this.post('verify', { phone: this.phone, code, reset: this.reset ? 1 : 0 });
      this.busy(form, false);
      this.verifying = false;

      if (!r.ok) {
        this.say(r.message || this.t.network, 'error');
        this.cellBox.setAttribute('data-error', '');
        this.cells.forEach((c) => { c.value = ''; });
        this.cells[0].focus();
        return;
      }

      this.stopTimer();

      if (r.action === 'done' || r.action === 'setpassword') { this.finish(r); return; }

      // New phone number
      this.choice = { register: !!r.can_register, link: !!r.can_link };
      this.configureRegisterForm(r);
      if (this.choice.register && this.choice.link) this.show('choice');
      else this.show(this.choice.register ? 'register' : 'link');
    }

    // ------------------------------------------------------------ steps 3-4

    configureRegisterForm(r) {
      [['name', r.ask_name], ['email', r.ask_email]].forEach(([field, mode]) => {
        const wrap = this.steps.register.querySelector(`[data-field="${field}"]`);
        const input = wrap.querySelector('input');
        wrap.hidden = mode === 'off';
        input.required = mode === 'required';
        wrap.querySelector('[data-optional]').hidden = mode !== 'optional';
      });
    }

    async register() {
      const form = this.steps.register;
      this.busy(form, true);
      const r = await this.post('register', { name: form.elements.name.value, email: form.elements.email.value });
      this.busy(form, false);
      this.handleResult(r);
    }

    async link() {
      const form = this.steps.link;
      this.busy(form, true);
      const r = await this.post('link', { identifier: form.elements.identifier.value, password: form.elements.password.value });
      this.busy(form, false);
      if (!r.ok) form.elements.password.value = '';
      this.handleResult(r);
    }

    handleResult(r) {
      if (r.ok && (r.action === 'done' || r.action === 'setpassword')) { this.finish(r); return; }
      this.say(r.message || this.t.network, 'error');
    }

    async loginWithPassword() {
      const form = this.steps.password;
      const password = form.elements.password.value;
      if (!password) { this.say(this.t.passEmpty, 'error'); form.elements.password.focus(); return; }

      this.busy(form, true, this.t.verifying);
      const r = await this.post('password', { phone: this.phone, password });
      this.busy(form, false);
      if (!r.ok) form.elements.password.value = '';
      this.handleResult(r);
    }

    async setPassword() {
      const form = this.steps.setpassword;
      const p1 = form.elements.password.value;
      const p2 = form.elements.password2.value;

      if (p1.length < this.minLen) { this.say(fill(this.t.passShort, { min: this.minLen }), 'error'); form.elements.password.focus(); return; }
      if (p1 !== p2) { this.say(this.t.passMismatch, 'error'); form.elements.password2.focus(); return; }

      this.busy(form, true);
      const r = await this.post('setpassword', { password: p1, password2: p2 });
      this.busy(form, false);
      if (r.ok) { this.finish(r); return; }
      this.say(r.message || this.t.network, 'error');
    }

    finish(r) {
      this.redirectTo = r.redirect || '';

      try {
        sessionStorage.removeItem('otp_welcome_gate');
        sessionStorage.removeItem('otp_welcome_avatar');
        sessionStorage.removeItem('otp_welcome_sec');
        if (r.redirect) sessionStorage.setItem('otp_redirect', r.redirect);
      } catch (e) { /* private mode */ }

      if (r.action === 'setpassword') {
        if (r.token) this.tokenName = r.token;
        this.minLen = r.min || this.minLen;
        const form = this.steps.setpassword;
        form.querySelector('[data-setpw-hint]').textContent = fill(this.t.setpwHint, { min: this.minLen });
        form.querySelector('[data-skip-setpw]').hidden = !!r.required;
        form.elements.password.value = '';
        form.elements.password2.value = '';
        this.show('setpassword');
        return;
      }

      this.say(this.t.done, 'ok');
      const dest = (r.redirect || this.returnUrl || '').trim();
      if (dest) {
        window.location.href = dest;
      } else {
        // No return target → stay / soft reload so signed-in panel appears
        window.location.reload();
      }
    }
  }


  /** Proof-of-work: find nonce so sha256(challenge|nonce) has leading zero bits. */
  const solvePow = async (challenge, difficulty, timeoutMs = 5000) => {
    const enc = new TextEncoder();
    const start = Date.now();
    let nonce = 0;
    const zeroBytes = Math.floor(difficulty / 8);
    const remBits = difficulty % 8;
    while (Date.now() - start < timeoutMs) {
      // batch to keep UI responsive
      for (let i = 0; i < 500; i++, nonce++) {
        const data = enc.encode(challenge + '|' + String(nonce));
        const buf = await crypto.subtle.digest('SHA-256', data);
        const view = new Uint8Array(buf);
        let ok = true;
        for (let b = 0; b < zeroBytes; b++) {
          if (view[b] !== 0) { ok = false; break; }
        }
        if (ok && remBits > 0) {
          const mask = 0xff << (8 - remBits) & 0xff;
          if ((view[zeroBytes] & mask) !== 0) ok = false;
        }
        if (ok) return String(nonce);
      }
      await new Promise((r) => setTimeout(r, 0));
    }
    throw new Error('pow_timeout');
  };

  // ------------------------------------------------------------ boot

  const initSignedIn = () => {
    document.querySelectorAll('[data-otplogin-signed]').forEach((root) => {
      if (root.__otpSigned) return;
      root.__otpSigned = true;
      const endpoint = root.dataset.endpoint;
      const tokEl = root.querySelector('[data-token]');
      let tokenName = tokEl ? tokEl.name : '';
      try {
        if (window.Joomla && Joomla.getOptions) {
          const live = Joomla.getOptions('csrf.token');
          if (live) tokenName = live;
        }
      } catch (e) { /* ignore */ }
      const msg = root.querySelector('[data-msg]');
      const say = (t, kind) => {
        if (!msg) return;
        msg.textContent = t || '';
        msg.className = 'otp__msg' + (kind ? ' otp__msg--' + kind : '');
      };
      const post = async (task, data, isFile) => {
        const body = isFile ? data : new FormData();
        if (!isFile) Object.entries(data || {}).forEach(([k, v]) => body.append(k, v == null ? '' : v));
        if (tokenName) {
          body.append(tokenName, '1');
          body.append('csrf_token', tokenName);
        }
        const headers = { Accept: 'application/json' };
        if (tokenName) headers['X-CSRF-Token'] = tokenName;
        let res;
        try {
          res = await fetch(`${endpoint}&task=ajax.${task}&_=${Date.now()}`, {
            method: 'POST', body, credentials: 'same-origin', cache: 'no-store', headers,
          });
        } catch (netErr) {
          return { ok: false, message: 'خطای شبکه. دوباره تلاش کنید.' };
        }
        let json = null;
        const text = await res.text();
        try { json = text ? JSON.parse(text) : null; } catch (e) {
          return { ok: false, message: 'پاسخ نامعتبر از سرور (کد ' + res.status + ')' };
        }
        if (!json) return { ok: false, message: 'پاسخ خالی از سرور' };
        if (json.token) tokenName = json.token;
        return json;
      };


      const setAvatarImg = (url) => {
        const wrap = root.querySelector('[data-avatar-wrap]');
        if (!wrap) return;
        wrap.innerHTML = '';
        const img = document.createElement('img');
        img.src = url + (url.indexOf('?') >= 0 ? '&' : '?') + 't=' + Date.now();
        img.width = 96;
        img.height = 96;
        img.alt = '';
        img.decoding = 'async';
        img.setAttribute('data-avatar-img', '');
        wrap.appendChild(img);
        const label = root.querySelector('[data-avatar-label]');
        if (label) label.textContent = label.getAttribute('data-change') || label.textContent;
      };

      const setBusy = (on, text) => {
        const busy = root.querySelector('[data-avatar-busy]');
        const prog = root.querySelector('[data-avatar-progress]');
        const modal = root.querySelector('[data-avatar-cam-modal]');
        if (busy) busy.hidden = !on;
        if (prog) {
          prog.hidden = !on;
          prog.textContent = on ? (text || 'در حال بارگذاری عکس…') : '';
        }
        // Only lock outer card actions — NEVER disable buttons inside the camera modal
        const card = root.querySelector('.otp-user') || root;
        card.querySelectorAll('button, a.otp-user__btn').forEach((el) => {
          if (modal && modal.contains(el)) return;
          if (on) {
            if (el.dataset.busyLock == null) {
              el.dataset.busyLock = el.disabled ? '1' : '0';
              if (el.tagName === 'A') {
                el.dataset.busyHref = el.getAttribute('href') || '';
                el.setAttribute('href', '#');
                el.setAttribute('aria-disabled', 'true');
                el.classList.add('is-disabled');
              } else {
                el.disabled = true;
              }
            }
          } else if (el.dataset.busyLock != null) {
            if (el.tagName === 'A') {
              if (el.dataset.busyHref) el.setAttribute('href', el.dataset.busyHref);
              el.removeAttribute('aria-disabled');
              el.classList.remove('is-disabled');
              delete el.dataset.busyHref;
            } else {
              el.disabled = el.dataset.busyLock === '1';
            }
            delete el.dataset.busyLock;
          }
        });
        if (on) say(text || 'در حال بارگذاری عکس…', 'info');
        else if (msg && msg.className.indexOf('otp__msg--info') >= 0) say('', '');
      };

      const uploadAvatarBlob = async (blob, filename) => {
        if (!blob) {
          say('فایلی انتخاب نشد', 'error');
          return { ok: false };
        }
        // Ensure MIME for phone gallery / canvas
        let file = blob;
        const name = filename || 'photo.jpg';
        if (blob instanceof Blob && !(blob instanceof File)) {
          const type = blob.type || 'image/jpeg';
          file = new File([blob], name, { type });
        } else if (blob instanceof File && !blob.type) {
          file = new File([blob], name, { type: 'image/jpeg' });
        }
        setBusy(true, 'در حال بارگذاری عکس…');
        try {
          const fd = new FormData();
          fd.append('avatar', file, name);
          fd.append('source', 'camera');
          const r = await post('avatarupload', fd, true);
          if (r && r.ok && r.url) {
            setAvatarImg(r.url);
            setBusy(false);
            say('عکس پرسنلی ذخیره شد', 'ok');
            const wel = root.querySelector('[data-otp-welcome]');
            if (wel) wel.hidden = true;
            try { sessionStorage.removeItem('otp_welcome_avatar'); } catch (e) {}
            const label = root.querySelector('[data-avatar-label]');
            if (label) label.textContent = 'تغییر عکس پرسنلی';
          } else {
            setBusy(false);
            say((r && r.message) || 'بارگذاری ناموفق بود', 'error');
          }
          return r || { ok: false };
        } catch (err) {
          setBusy(false);
          say(err && err.message ? err.message : 'خطا در بارگذاری', 'error');
          return { ok: false };
        }
      };


      const cameraFile = root.querySelector('[data-avatar-camera-file]');
      const cameraBtn = root.querySelector('[data-avatar-camera-btn]');
      const camModal = root.querySelector('[data-avatar-cam-modal]');
      const camVideo = root.querySelector('[data-avatar-video]');
      const camCanvas = root.querySelector('[data-avatar-canvas]');
      const camSnap = root.querySelector('[data-avatar-snap]');
      const camClose = root.querySelector('[data-avatar-cam-close]');
      let camStream = null;

      // Ensure camera button is never left disabled from a previous stuck state
      if (cameraBtn) {
        cameraBtn.disabled = false;
        cameraBtn.removeAttribute('aria-disabled');
        delete cameraBtn.dataset.busyLock;
      }

      const isMobile = () => /Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent)
        || (navigator.maxTouchPoints > 1 && window.matchMedia('(max-width: 900px)').matches);

      const stopCam = (keepBusy = false) => {
        if (camStream) {
          camStream.getTracks().forEach((tr) => tr.stop());
          camStream = null;
        }
        if (camVideo) camVideo.srcObject = null;
        if (camModal) camModal.hidden = true;
        if (!keepBusy) setBusy(false);
      };

      const openNativeCamera = () => {
        if (!cameraFile) {
          say('دوربین در این دستگاه در دسترس نیست', 'error');
          return;
        }
        cameraFile.value = '';
        cameraFile.click();
      };

      let camOpening = false;
      const openCam = async () => {
        // Prevent dozens of parallel getUserMedia / native pickers
        if (camOpening) return;
        if (camStream && camModal && !camModal.hidden) return;
        camOpening = true;
        try {
          // Close any previous stream before opening a new one
          if (camStream) {
            camStream.getTracks().forEach((tr) => tr.stop());
            camStream = null;
          }
          // Mobile: OS camera via capture=user is more reliable than getUserMedia
          if (isMobile() && cameraFile) {
            openNativeCamera();
            return;
          }
          if (!window.isSecureContext && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') {
            say('برای استفاده از دوربین به HTTPS نیاز است', 'error');
            return;
          }
          if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            openNativeCamera();
            return;
          }
          try {
            camStream = await navigator.mediaDevices.getUserMedia({
              audio: false,
              video: {
                facingMode: { ideal: 'user' },
                width: { ideal: 1280 },
                height: { ideal: 1280 },
              },
            });
            if (camVideo) {
              camVideo.setAttribute('playsinline', 'true');
              camVideo.setAttribute('muted', 'true');
              camVideo.srcObject = camStream;
              await camVideo.play().catch(() => {});
            }
            if (camModal) camModal.hidden = false;
          } catch (err) {
            try {
              camStream = await navigator.mediaDevices.getUserMedia({
                audio: false,
                video: true,
              });
              if (camVideo) {
                camVideo.srcObject = camStream;
                await camVideo.play().catch(() => {});
              }
              if (camModal) camModal.hidden = false;
            } catch (err2) {
              openNativeCamera();
            }
          }
        } finally {
          camOpening = false;
        }
      };

      if (cameraBtn) {
        cameraBtn.addEventListener('click', (e) => {
          e.preventDefault();
          e.stopPropagation();
          openCam();
        });
      }


      // Inline profile edit (name + username)
      const profileOpen = root.querySelector('[data-profile-open]');
      const profilePanel = root.querySelector('[data-profile-panel]');
      const profileSave = root.querySelector('[data-profile-save]');
      const profileCancel = root.querySelector('[data-profile-cancel]');
      const profileMsg = root.querySelector('[data-profile-msg]');
      const nameEl = root.querySelector('.otp-user__name');

      if (profileOpen && profilePanel) {
        profileOpen.addEventListener('click', (e) => {
          e.preventDefault();
          profilePanel.hidden = false;
          profileOpen.hidden = true;
          if (profileMsg) profileMsg.textContent = '';
          const first = root.querySelector('[data-profile-first]');
          if (first) first.focus();
        });
      }
      if (profileCancel && profilePanel) {
        profileCancel.addEventListener('click', (e) => {
          e.preventDefault();
          profilePanel.hidden = true;
          if (profileOpen) profileOpen.hidden = false;
        });
      }
      if (profileSave) {
        profileSave.addEventListener('click', async (e) => {
          e.preventDefault();
          const first = (root.querySelector('[data-profile-first]') || {}).value || '';
          const last = (root.querySelector('[data-profile-last]') || {}).value || '';
          const uname = (root.querySelector('[data-profile-user]') || {}).value || '';
          if (!String(first).trim() && !String(last).trim()) {
            if (profileMsg) profileMsg.textContent = 'لطفاً نام و نام خانوادگی را وارد کنید.';
            return;
          }
          profileSave.disabled = true;
          if (profileMsg) profileMsg.textContent = '…';
          const r = await post('saveprofile', {
            firstname: String(first).trim(),
            lastname: String(last).trim(),
            username: String(uname).trim(),
          });
          profileSave.disabled = false;
          if (!r.ok) {
            if (profileMsg) profileMsg.textContent = r.message || 'خطا در ذخیره';
            return;
          }
          if (nameEl && r.name) nameEl.textContent = r.name;
          if (profileMsg) profileMsg.textContent = r.message || 'پروفایل ذخیره شد.';
          setTimeout(() => {
            if (profilePanel) profilePanel.hidden = true;
            if (profileOpen) profileOpen.hidden = false;
          }, 800);
        });
      }

      // Welcome panel after login/register: keep open for N seconds (photo + profile)
      const welcome = root.querySelector('[data-otp-welcome]');
      const welcomeCam = root.querySelector('[data-welcome-camera]');
      const welcomeSkip = root.querySelector('[data-welcome-skip]');
      const timerEl = root.querySelector('[data-welcome-timer]');
      let welcomeTimer = null;

      const closeWelcomeGate = async (skipServer) => {
        root.classList.remove('otp--welcome-open');
        // Restore root to original place if we moved it into a body portal
        if (root._otpWelcomePortal && root._otpWelcomePlaceholder) {
          try {
            root._otpWelcomePlaceholder.parentNode.insertBefore(root, root._otpWelcomePlaceholder);
            root._otpWelcomePlaceholder.remove();
            root._otpWelcomePortal.remove();
          } catch (e) {}
          root._otpWelcomePortal = null;
          root._otpWelcomePlaceholder = null;
        }
        if (welcome) welcome.hidden = true;
        if (timerEl) timerEl.hidden = true;
        if (welcomeTimer) { clearInterval(welcomeTimer); welcomeTimer = null; }
        try {
          sessionStorage.removeItem('otp_welcome_avatar');
          sessionStorage.removeItem('otp_welcome_gate');
          sessionStorage.removeItem('otp_welcome_sec');
          localStorage.setItem(welcomeUserKey, '1');
        } catch (e) {}
        if (!skipServer) {
          try { await post('skipwelcome', {}); } catch (err) {}
        }
      };

      if (welcomeCam) {
        welcomeCam.addEventListener('click', (e) => {
          e.preventDefault();
          openCam();
        });
      }
      if (welcomeSkip) {
        welcomeSkip.addEventListener('click', async (e) => {
          e.preventDefault();
          await closeWelcomeGate(false);
        });
      }

      const welcomeUserKey = 'otp_welcome_done_' + (root.getAttribute('data-user-id') || '0');
      // Auto welcome disabled — panel only when user taps profile / camera
      let forceWelcome = false;
      let leftSec = 0;
      try {
        sessionStorage.removeItem('otp_welcome_gate');
        sessionStorage.removeItem('otp_welcome_avatar');
        sessionStorage.removeItem('otp_welcome_sec');
        localStorage.setItem(welcomeUserKey, '1');
      } catch (e) {}

      const openWelcomeGate = () => {
        // Move module into a full-screen portal on body so parent modals/overflow cannot clip it
        if (!root._otpWelcomePortal) {
          // Only one welcome portal on the page
          document.querySelectorAll('[data-otp-welcome-portal]').forEach((p) => {
            try { p.remove(); } catch (e) {}
          });
          const placeholder = document.createComment('otp-welcome-placeholder');
          if (root.parentNode) root.parentNode.insertBefore(placeholder, root);
          const portal = document.createElement('div');
          portal.className = 'otp-welcome-portal';
          portal.setAttribute('data-otp-welcome-portal', '1');
          document.body.appendChild(portal);
          portal.appendChild(root);
          root._otpWelcomePortal = portal;
          root._otpWelcomePlaceholder = placeholder;
        }
        try { localStorage.setItem(welcomeUserKey, '1'); } catch (e) {}
        root.classList.add('otp--welcome-open');
        if (welcome) welcome.hidden = false;
        if (timerEl) {
          timerEl.hidden = false;
          const tick = () => {
            timerEl.textContent = leftSec > 0
              ? ('این پنجره ' + leftSec + ' ثانیه باز می‌ماند')
              : '';
          };
          tick();
          if (welcomeTimer) clearInterval(welcomeTimer);
          welcomeTimer = setInterval(() => {
            leftSec -= 1;
            tick();
            if (leftSec <= 0) {
              closeWelcomeGate(false); // mark first-login welcome as seen
            }
          }, 1000);
        } else {
          setTimeout(() => closeWelcomeGate(false), leftSec * 1000);
        }
        // Do not auto-open camera (especially on desktop it spawns many permission dialogs).
        // User taps «گرفتن عکس زنده» when ready.
      };

      if (forceWelcome) {
        // Defer one frame so layout/CSS is ready
        requestAnimationFrame(() => setTimeout(openWelcomeGate, 50));
      }

      if (camClose) {
        camClose.addEventListener('click', (e) => {
          e.preventDefault();
          stopCam(false);
        });
      }
      if (camModal) {
        camModal.addEventListener('click', (e) => {
          if (e.target === camModal) stopCam(false);
        });
      }
      /** Draw square crop upright (identity photo — no mirror). */
      const snapFromVideo = () => {
        const w = camVideo.videoWidth || 0;
        const h = camVideo.videoHeight || 0;
        if (!w || !h) return null;
        const side = Math.min(w, h);
        const sx = Math.floor((w - side) / 2);
        const sy = Math.floor((h - side) / 2);
        camCanvas.width = 640;
        camCanvas.height = 640;
        const ctx = camCanvas.getContext('2d');
        ctx.setTransform(1, 0, 0, 1, 0, 0);
        ctx.drawImage(camVideo, sx, sy, side, side, 0, 0, 640, 640);
        return true;
      };

      /**
       * Normalize phone photo orientation (EXIF) before upload so the image is upright.
       * createImageBitmap applies EXIF in modern browsers; fallback draws via Image.
       */
      const normalizePhotoFile = async (file) => {
        try {
          if (typeof createImageBitmap === 'function') {
            const bmp = await createImageBitmap(file, { imageOrientation: 'from-image' });
            const side = Math.min(bmp.width, bmp.height);
            const sx = Math.floor((bmp.width - side) / 2);
            const sy = Math.floor((bmp.height - side) / 2);
            const c = document.createElement('canvas');
            c.width = 640;
            c.height = 640;
            const ctx = c.getContext('2d');
            ctx.drawImage(bmp, sx, sy, side, side, 0, 0, 640, 640);
            try { bmp.close(); } catch (e) {}
            const blob = await new Promise((res) => c.toBlob(res, 'image/jpeg', 0.92));
            if (blob) return blob;
          }
        } catch (e1) { /* fall through */ }

        try {
          const url = URL.createObjectURL(file);
          const img = await new Promise((resolve, reject) => {
            const i = new Image();
            i.onload = () => resolve(i);
            i.onerror = reject;
            i.src = url;
          });
          URL.revokeObjectURL(url);
          const side = Math.min(img.naturalWidth || img.width, img.naturalHeight || img.height);
          const sx = Math.floor(((img.naturalWidth || img.width) - side) / 2);
          const sy = Math.floor(((img.naturalHeight || img.height) - side) / 2);
          const c = document.createElement('canvas');
          c.width = 640;
          c.height = 640;
          c.getContext('2d').drawImage(img, sx, sy, side, side, 0, 0, 640, 640);
          const blob = await new Promise((res) => c.toBlob(res, 'image/jpeg', 0.92));
          if (blob) return blob;
        } catch (e2) { /* fall through */ }

        return file;
      };

      if (camSnap && camVideo && camCanvas) {
        camSnap.addEventListener('click', async (e) => {
          e.preventDefault();
          if (!snapFromVideo()) {
            say('تصویر دوربین هنوز آماده نیست', 'error');
            return;
          }
          stopCam(true);
          camCanvas.toBlob(async (blob) => {
            if (!blob) {
              setBusy(false);
              say('ثبت تصویر ناموفق بود', 'error');
              return;
            }
            await uploadAvatarBlob(blob, 'camera.jpg');
          }, 'image/jpeg', 0.92);
        });
      }

      if (cameraFile) {
        cameraFile.addEventListener('change', async () => {
          const f = cameraFile.files && cameraFile.files[0];
          if (!f) return;
          if (f.type && f.type.indexOf('image/') !== 0) {
            say('فقط تصویر دوربین پذیرفته می‌شود', 'error');
            cameraFile.value = '';
            return;
          }
          const normalized = await normalizePhotoFile(f);
          await uploadAvatarBlob(normalized, 'camera.jpg');
          cameraFile.value = '';
        });
      }

      const pkReg = root.querySelector('[data-passkey-register]');
      if (pkReg) {
        pkReg.addEventListener('click', async () => {
          if (!window.PublicKeyCredential) {
            say('unsupported', 'error');
            return;
          }
          try {
            const optRes = await post('passkeyregisteroptions', {});
            if (!optRes.ok) { say(optRes.message || 'error', 'error'); return; }
            const o = optRes.options || {};
            if (!window.isSecureContext && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') {
              say('Passkey نیاز به HTTPS دارد', 'error');
              return;
            }
            const publicKey = {
              challenge: b64urlToBuf(o.challenge),
              rp: { name: (o.rp && o.rp.name) || document.title || 'OTP Login', id: safeRpId(o.rp && o.rp.id) },
              user: {
                id: b64urlToBuf(o.user.id),
                name: o.user.name,
                displayName: o.user.displayName,
              },
              pubKeyCredParams: o.pubKeyCredParams,
              timeout: o.timeout || 30000,
              attestation: o.attestation || 'none',
              authenticatorSelection: o.authenticatorSelection || {
                residentKey: 'preferred',
                requireResidentKey: false,
                userVerification: 'preferred',
              },
            };
            if (o.excludeCredentials) {
              publicKey.excludeCredentials = o.excludeCredentials.map((c) => ({
                type: 'public-key',
                id: typeof c.id === 'string' ? b64urlToBuf(c.id) : c.id,
              }));
            }
            const cred = await navigator.credentials.create({ publicKey });
            if (!cred) return;
            let publicKeyB64 = '';
            try {
              if (cred.response && typeof cred.response.getPublicKey === 'function') {
                publicKeyB64 = bufToB64url(cred.response.getPublicKey());
              }
            } catch (ePk) { /* ignore */ }
            if (!publicKeyB64) {
              say('Passkey public key unavailable', 'error');
              return;
            }
            const transports = (cred.response.getTransports && cred.response.getTransports()) || [];
            const r = await post('passkeyregister', {
              credentialId: bufToB64url(cred.rawId),
              publicKey: publicKeyB64,
              transports: transports.join(','),
              label: navigator.userAgent.slice(0, 40),
            });
            if (r.ok) say('OK', 'ok');
            else say(r.message || 'error', 'error');
          } catch (err) {
            say(passkeyErr(err, root.getAttribute('data-admin') === '1'), 'error');
          }
        });
      }

      // Phone can confirm a desktop QR by pasting token via query ?otp_qr=
      const params = new URLSearchParams(window.location.search);
      const qrTok = params.get('otp_qr');
      const qrBtn = root.querySelector('[data-qr-confirm-open]');
      if (qrTok && qrBtn) {
        qrBtn.hidden = false;
        qrBtn.addEventListener('click', async () => {
          const r = await post('qrconfirm', { qr_token: qrTok });
          if (r.ok) say('OK', 'ok');
          else say(r.message || 'error', 'error');
        });
      }
    });
  };

  const init = () => {
    document.querySelectorAll('[data-otplogin]').forEach((root) => {
      if (!root.__otp) root.__otp = new OtpLogin(root);
    });
    initSignedIn();
    document.querySelectorAll("[data-pass-toggle]").forEach((btn) => {
      if (btn.__otpPassToggle) return;
      btn.__otpPassToggle = true;
      btn.addEventListener("click", (e) => {
        e.preventDefault();
        const wrap = btn.closest(".otp-passwrap");
        const input = wrap && wrap.querySelector("input");
        if (!input) return;
        const show = input.type === "password";
        input.type = show ? "text" : "password";
        btn.textContent = show ? "🙈" : "👁";
      });
    });

    initModals();
  };

  // ------------------------------------------------------------ modal

  const hasUIkit = () => !!(window.UIkit && typeof window.UIkit.modal === 'function');

  const focusPhone = (modal) => {
    const input = modal.querySelector('input[name=phone]');
    if (input && !input.closest('[hidden]')) setTimeout(() => input.focus(), 60);
  };

  /** Minimal stand-in used only when the site does not load UIkit. */
  const nativeModal = (modal, opener) => {
    if (!modal.__otpNative) {
      modal.__otpNative = true;
      document.body.appendChild(modal); // out of the footer / any transformed container
      modal.classList.add('otp-modal--native');
      modal.removeAttribute('uk-modal');

      const closeBtn = modal.querySelector('[data-otp-close]');
      if (closeBtn) { closeBtn.removeAttribute('uk-close'); closeBtn.textContent = '\u00d7'; }

      const close = () => {
        modal.classList.remove('is-open');
        document.documentElement.style.overflow = modal.__prevOverflow || '';
        if (modal.__opener) modal.__opener.focus();
      };
      modal.__close = close;

      modal.addEventListener('click', (e) => {
        if (e.target === modal || e.target.closest('[data-otp-close]')) close();
      });
      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && modal.classList.contains('is-open')) close();
      });
    }

    modal.__opener = opener;
    modal.__prevOverflow = document.documentElement.style.overflow;
    document.documentElement.style.overflow = 'hidden';
    modal.classList.add('is-open');
    focusPhone(modal);
  };

  const initModals = () => {
    document.querySelectorAll('[data-otp-open]').forEach((btn) => {
      const modal = document.getElementById(btn.dataset.otpOpen + '-modal');
      if (!modal || btn.__otpBound) return;
      btn.__otpBound = true;

      // UIkit may finish loading after this script, so decide on click, not at start-up.
      btn.addEventListener('click', () => {
        if (hasUIkit() && !modal.classList.contains('otp-modal--native')) {
          window.UIkit.modal(modal).show();
          modal.addEventListener('shown', () => focusPhone(modal), { once: true });
        } else {
          nativeModal(modal, btn);
        }
      });
    });
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();

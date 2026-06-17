// SCHOOL: i18n — all strings translated to German
import { api, state, setApp } from '../app.js';
import { navigate } from '../router.js';
import { mountNav } from '../components/nav.js';
import { t } from '../i18n.js';

export function loginView() {
  if (state.user) { navigate('/'); return; }

  const redirectTo = new URLSearchParams(location.search).get('redirect') ?? '/';

  setApp(`
    <div class="login-wrap">
      <div class="login-card">
        <h1>${t('login.title')}</h1>
        <div id="login-error" class="alert alert-error" style="display:none"
          role="alert" aria-live="assertive"></div>
        <form id="login-form" novalidate>
          <div class="form-group">
            <label for="username">${t('login.username')}</label>
            <input id="username" type="text" name="username"
                   autocomplete="username" autocorrect="off" autocapitalize="none" autofocus
                   aria-required="true">
          </div>
          <div class="form-group">
            <label for="password">${t('login.password')}</label>
            <div class="pw-wrap">
              <input id="password" type="password" name="password" autocomplete="current-password"
                     aria-required="true">
              <button type="button" class="pw-toggle" id="pw-toggle" aria-label="${t('login.showAriaLabel')}">${t('login.show')}</button>
            </div>
          </div>
          <button type="submit" class="btn btn-primary" style="width:100%;margin-top:8px" id="login-btn">
            ${t('login.submit')}
          </button>
        </form>
        <!-- SCHOOL: registration disabled — remove comment to re-enable sign-up link -->
      </div>
    </div>
  `);

  document.getElementById('pw-toggle').addEventListener('click', () => {
    const input = document.getElementById('password');
    const btn   = document.getElementById('pw-toggle');
    const show  = input.type === 'password';
    input.type      = show ? 'text' : 'password';
    btn.textContent = show ? t('login.hide') : t('login.show');
    btn.setAttribute('aria-label', show ? t('login.hideAriaLabel') : t('login.showAriaLabel'));
  });

  document.getElementById('login-form').addEventListener('submit', async e => {
    e.preventDefault();
    const btn      = document.getElementById('login-btn');
    const errEl    = document.getElementById('login-error');
    const username = document.getElementById('username').value.trim();
    const password = document.getElementById('password').value;

    errEl.style.display = 'none';
    btn.disabled        = true;
    btn.textContent     = t('login.signingIn');

    try {
      const data = await api('POST', '/login', { username, password });
      state.user = data.user;
      state.csrf = data.csrf;
      mountNav();
      btn.textContent = '✓';
      await new Promise(r => setTimeout(r, 600));
      navigate(redirectTo);
    } catch (e) {
      errEl.textContent   = e.message === 'Invalid credentials.' ? t('login.invalidCredentials') : e.message;
      errEl.style.display = 'block';
      btn.disabled        = false;
      btn.textContent     = t('login.submit');
    }
  });
}

// Disabled for school deployment — re-enable by restoring the /register route and nav link
import { api, state, setApp } from '../app.js';
import { navigate } from '../router.js';
import { mountNav } from '../components/nav.js';

export function registerView() {
  if (state.user) { navigate('/'); return; }

  setApp(`
    <div class="login-wrap">
      <div class="login-card">
        <h1>Create account</h1>
        <div id="reg-server-error" class="alert alert-error" style="display:none"
          role="alert" aria-live="assertive"></div>

        <form id="reg-form" novalidate>
          <div class="form-group">
            <label for="reg-username">Username</label>
            <input id="reg-username" type="text" name="username"
                   autocomplete="username" autocorrect="off" autocapitalize="none" spellcheck="false"
                   aria-required="true" aria-describedby="hint-username err-username">
            <div class="form-error" id="err-username" aria-live="polite"></div>
            <div class="text-muted mt-8" style="font-size:11px" id="hint-username">Lowercase, numbers, - and _ only</div>
          </div>

          <div class="form-group">
            <label for="reg-display_name">Display name</label>
            <input id="reg-display_name" type="text" name="display_name" autocomplete="name"
                   aria-required="true" aria-describedby="err-display_name">
            <div class="form-error" id="err-display_name" aria-live="polite"></div>
          </div>

          <div class="form-group">
            <label for="reg-password">Password</label>
            <div class="pw-wrap">
              <input id="reg-password" type="password" name="password" autocomplete="new-password"
                     aria-required="true" aria-describedby="pw-strength err-password">
              <button type="button" class="pw-toggle" id="pw-toggle-reg" aria-label="Show password">show</button>
            </div>
            <div id="pw-strength" class="text-muted mt-8" style="font-size:11px" aria-live="polite"></div>
            <div class="form-error" id="err-password" aria-live="polite"></div>
          </div>

          <div class="form-group">
            <label for="reg-confirm">Confirm password</label>
            <div class="pw-wrap">
              <input id="reg-confirm" type="password" name="confirm_password" autocomplete="new-password"
                     aria-required="true" aria-describedby="err-confirm">
              <button type="button" class="pw-toggle" id="pw-toggle-confirm" aria-label="Show password">show</button>
            </div>
            <div class="form-error" id="err-confirm" aria-live="polite"></div>
          </div>

          <button type="submit" class="btn btn-primary" style="width:100%;margin-top:8px" id="reg-btn">
            Create account
          </button>
        </form>

        <p class="text-muted mt-16" style="text-align:center;font-size:12px">
          Already have an account? <a href="/login" data-link>Sign in</a>
        </p>
      </div>
    </div>
  `);

  // POLISHED: show/hide toggles for both password fields
  document.getElementById('pw-toggle-reg').addEventListener('click', () => {
    const input = document.getElementById('reg-password');
    const btn   = document.getElementById('pw-toggle-reg');
    const show  = input.type === 'password';
    input.type      = show ? 'text' : 'password';
    btn.textContent = show ? 'hide' : 'show';
    btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
  });

  document.getElementById('pw-toggle-confirm').addEventListener('click', () => {
    const input = document.getElementById('reg-confirm');
    const btn   = document.getElementById('pw-toggle-confirm');
    const show  = input.type === 'password';
    input.type      = show ? 'text' : 'password';
    btn.textContent = show ? 'hide' : 'show';
    btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
  });

  document.getElementById('reg-password').addEventListener('input', e => {
    renderPwStrength(e.target.value);
  });

  document.getElementById('reg-confirm').addEventListener('blur', e => {
    const pw  = document.getElementById('reg-password').value;
    const err = document.getElementById('err-confirm');
    err.textContent = (e.target.value && e.target.value !== pw)
      ? 'Passwords do not match.'
      : '';
  });

  document.getElementById('reg-form').addEventListener('submit', handleSubmit);
}

// POLISHED: updated strength display — "5 / 8 ✓" format with colour transitions
function renderPwStrength(pw) {
  const el = document.getElementById('pw-strength');
  if (!el) return;

  const len    = pw.length;
  const hasNum = /\d/.test(pw);
  const lenOk  = len >= 8;
  const allOk  = lenOk && hasNum;

  const color = allOk ? 'var(--success)' : 'var(--text-muted)';
  const check = allOk ? ' ✓' : '';
  const lenLabel = lenOk ? '8' : `${len}`;

  el.style.color      = color;
  el.style.transition = 'color 200ms ease';
  el.textContent      = `${lenLabel} / 8${check}${!hasNum && len > 0 ? ' · must contain a number' : ''}`;
}

async function handleSubmit(e) {
  e.preventDefault();

  const btn         = document.getElementById('reg-btn');
  const serverErr   = document.getElementById('reg-server-error');
  const username    = document.getElementById('reg-username').value.trim();
  const displayName = document.getElementById('reg-display_name').value.trim();
  const password    = document.getElementById('reg-password').value;
  const confirm     = document.getElementById('reg-confirm').value;

  // Clear all error surfaces
  serverErr.style.display = 'none';
  ['username', 'display_name', 'password', 'confirm'].forEach(f => {
    const el = document.getElementById('err-' + f);
    if (el) el.textContent = '';
  });

  // Client-side pre-validation
  let hasClientErrors = false;

  if (!/^[a-z0-9_-]{3,32}$/.test(username)) {
    document.getElementById('err-username').textContent =
      'Must be 3–32 characters: a–z, 0–9, hyphens and underscores only.';
    hasClientErrors = true;
  }
  if (password !== confirm) {
    document.getElementById('err-confirm').textContent = 'Passwords do not match.';
    hasClientErrors = true;
  }
  if (hasClientErrors) return;

  btn.disabled    = true;
  btn.textContent = 'Registering...';

  try {
    const data = await api('POST', '/register', { username, display_name: displayName, password });
    state.user = data.user;
    state.csrf = data.csrf;
    mountNav();
    // POLISHED: brief success state before navigation
    btn.textContent = '✓';
    await new Promise(r => setTimeout(r, 600));
    navigate('/');
  } catch (err) {
    btn.disabled    = false;
    btn.textContent = 'Create account';

    if (err.fields) {
      Object.entries(err.fields).forEach(([field, msg]) => {
        const el = document.getElementById('err-' + field);
        if (el) el.textContent = msg;
      });
    } else {
      const msg = err.message === 'username_taken'
        ? 'That username is already taken.'
        : err.message;
      serverErr.textContent   = msg;
      serverErr.style.display = 'block';
    }
  }
}

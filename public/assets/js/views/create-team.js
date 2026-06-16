// SCHOOL: i18n — all strings translated to German
import { api, state, setApp } from '../app.js';
import { navigate } from '../router.js';
import { toast } from '../components/toast.js';
import { t } from '../i18n.js';

export function createTeamView() {
  if (!state.user) {
    navigate('/login?redirect=/create-team');
    return;
  }

  setApp(`
    <div class="login-wrap">
      <div class="login-card">
        <h1>${t('createTeam.title')}</h1>

        <form id="create-team-form" novalidate>
          <div class="form-group">
            <label for="ct-name">${t('createTeam.teamName')}</label>
            <input id="ct-name" type="text" name="name" autocomplete="off" autofocus>
            <div class="form-error" id="err-name"></div>
          </div>

          <div class="form-group">
            <label for="ct-sport">${t('createTeam.sport')}</label>
            <select class="input" id="ct-sport" aria-required="true">
              <option value="">Sportart wählen...</option>
              <option value="Fußball">Fußball</option>
              <option value="Volleyball">Volleyball</option>
              <option value="Hockey">Hockey</option>
            </select>
            <div class="form-error" id="err-sport"></div>
          </div>

          <button type="submit" class="btn btn-primary" style="width:100%;margin-top:8px" id="ct-btn">
            ${t('createTeam.submit')}
          </button>
        </form>
      </div>
    </div>
  `);

  document.getElementById('create-team-form').addEventListener('submit', async e => {
    e.preventDefault();

    const btn  = document.getElementById('ct-btn');
    const name  = document.getElementById('ct-name').value.trim();
    const sport = document.getElementById('ct-sport').value;

    ['name', 'sport'].forEach(f => {
      document.getElementById('err-' + f).textContent = '';
    });

    btn.disabled    = true;
    btn.textContent = t('createTeam.creating');

    try {
      const data = await api('POST', '/teams/create', { name, sport });
      btn.textContent = '✓';
      await new Promise(r => setTimeout(r, 600));
      toast(t('createTeam.created'), 'success');
      navigate('/team/' + data.id);
    } catch (err) {
      btn.disabled    = false;
      btn.textContent = t('createTeam.submit');

      if (err.fields) {
        Object.entries(err.fields).forEach(([field, msg]) => {
          const el = document.getElementById('err-' + field);
          if (el) el.textContent = msg;
        });
      } else {
        const msg = err.message === 'team_name_claimed'
          ? t('createTeam.nameTaken')
          : err.message;
        toast(msg, 'error');
      }
    }
  });
}

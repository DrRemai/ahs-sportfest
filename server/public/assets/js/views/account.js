// SCHOOL: i18n — all strings translated to German
import { api, state, setApp } from '../app.js';
import { navigate, link } from '../router.js';
import { badge } from '../components/badge.js';
import { toast } from '../components/toast.js';
import { mountNav } from '../components/nav.js';
import { Skeleton } from '../components/skeleton.js';
import { Empty } from '../components/empty.js';
import { t } from '../i18n.js';

export async function accountView() {
  if (!state.user) { navigate('/login?redirect=/account'); return; }

  setApp(Skeleton.accountPage());

  const teamsData = await api('GET', '/me/teams');
  render(teamsData.teams);
}

function render(teams) {
  const user = state.user;

  const active   = teams.filter(t_ => t_.status !== 'archived');
  const archived = teams.filter(t_ => t_.status === 'archived');
  const sorted   = [...active, ...archived];

  const teamRows = sorted.length === 0
    ? `<tr><td colspan="5">${Empty.state(t('account.noTeams'))}</td></tr>`
    : sorted.map(t_ => {
        const dimmed = t_.status === 'archived' ? ' style="opacity:0.55"' : '';
        return `<tr${dimmed}>
          <td>${link('/team/' + t_.id, esc(t_.name))}</td>
          <td>${t_.sport ? esc(t_.sport) : '<span class="text-muted">—</span>'}</td>
          <td>${t_.member_count}</td>
          <td>${badge(t_.status)}</td>
          <td>
            <button class="btn btn-ghost btn-sm" data-manage-team="${t_.id}">${t('account.manage')}</button>
          </td>
        </tr>`;
      }).join('');

  setApp(`
    <div class="page-header">
      <h1 class="page-title">${t('account.title')}</h1>
    </div>

    <div class="two-col" style="max-width:900px">

      <div class="card">
        <div class="section-title">${t('account.profile')}</div>
        <form id="profile-form" style="margin-top:12px">

          <div class="form-group">
            <label for="prof-username">${t('account.username')}</label>
            <input id="prof-username" type="text" value="${esc(user.username ?? '')}" disabled
                   aria-disabled="true" style="color:var(--text-muted);cursor:not-allowed;opacity:0.7">
            <div class="text-muted mt-8" style="font-size:11px">${t('account.usernameNote')}</div>
          </div>

          <div class="form-group">
            <label for="prof-display_name">${t('account.displayName')}</label>
            <input id="prof-display_name" type="text" name="display_name"
                   value="${esc(user.display_name ?? '')}"
                   aria-required="true" aria-describedby="err-display_name">
            <div class="form-error" id="err-display_name" aria-live="polite"></div>
          </div>

          <button type="submit" class="btn btn-primary btn-sm" id="prof-btn">${t('account.save')}</button>
          <div id="prof-notice" style="display:none;margin-top:8px"></div>
        </form>
      </div>

      <div class="card">
        <div class="section-title">${t('account.yourTeams')}</div>
        <div class="table-wrap" style="margin-top:12px">
          <table>
            <thead>
              <tr>
                <th scope="col">${t('account.name')}</th>
                <th scope="col">${t('account.sport')}</th>
                <th scope="col">${t('account.members')}</th>
                <th scope="col">${t('account.status')}</th>
                <th scope="col"><span class="visually-hidden">${t('account.actionsLabel')}</span></th>
              </tr>
            </thead>
            <tbody>${teamRows}</tbody>
          </table>
        </div>
        <div class="mt-16">
          ${link('/create-team', t('account.newTeam'), 'btn btn-secondary btn-sm')}
        </div>
      </div>

    </div>
  `);

  document.querySelectorAll('[data-manage-team]').forEach(btn => {
    btn.addEventListener('click', () => navigate('/team/' + btn.dataset.manageTeam));
  });

  document.getElementById('profile-form').addEventListener('submit', async e => {
    e.preventDefault();

    const btn         = document.getElementById('prof-btn');
    const noticeEl    = document.getElementById('prof-notice');
    const errEl       = document.getElementById('err-display_name');
    const displayName = document.getElementById('prof-display_name').value.trim();

    errEl.textContent      = '';
    noticeEl.style.display = 'none';
    btn.disabled           = true;
    btn.textContent        = t('account.saving');

    try {
      const data = await api('PATCH', '/me', { display_name: displayName });
      state.user.display_name = data.display_name;
      mountNav();
      btn.textContent = t('account.savedBtn');
      setTimeout(() => {
        btn.disabled    = false;
        btn.textContent = t('account.save');
        toast(t('account.profileUpdated'), 'success');
      }, 600);
    } catch (err) {
      btn.disabled    = false;
      btn.textContent = t('account.save');
      if (err.fields?.display_name) {
        errEl.textContent = err.fields.display_name;
      } else {
        toast(err.message, 'error');
      }
    }
  });
}

function esc(s) {
  return String(s ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

// SCHOOL: i18n — all strings translated to German
import { api, state, setApp } from '../app.js';
import { navigate, link } from '../router.js';
import { badge } from '../components/badge.js';
import { toast } from '../components/toast.js';
import { confirmInline } from '../components/confirm.js';
import { Skeleton } from '../components/skeleton.js';
import { Empty } from '../components/empty.js';
import { t } from '../i18n.js';

let _data = null;
let _activeTab = 'users';

export async function adminView() {
  if (!state.user?.is_admin) { navigate('/'); return; }

  _activeTab = new URLSearchParams(location.search).get('tab') ?? 'users';

  setApp(Skeleton.adminPage());

  _data = await loadAdminData();
  render();
}

async function loadAdminData() {
  const [users, tournaments, reevaluations, teams] = await Promise.all([
    api('GET', '/admin/users'),
    api('GET', '/admin/tournaments'),
    api('GET', '/admin/reevaluations'),
    api('GET', '/admin/teams'),
  ]);
  return {
    users:         users.users,
    tournaments:   tournaments.tournaments,
    reevaluations: reevaluations.reevaluations,
    teams:         teams.teams,
  };
}

function render() {
  const tabs = [
    { key: 'users',         label: t('admin.tabUsers') },
    { key: 'tournaments',   label: t('admin.tabTournaments') },
    { key: 'teams',         label: t('admin.tabTeams') },
    { key: 'reevaluations', label: t('admin.tabReevaluations') },
    { key: 'roles',         label: t('admin.tabRoles') },
  ];

  const tabHtml = tabs.map(({ key, label }) => {
    const active = _activeTab === key;
    return `<button class="tab-btn${active ? ' active' : ''}"
      role="tab"
      aria-selected="${active}"
      aria-controls="admin-tab-panel"
      id="admin-tab-${key}"
      data-tab="${key}">${label}</button>`;
  }).join('');

  setApp(`
    <div class="page-header">
      <h1 class="page-title">${t('admin.title')}</h1>
    </div>
    <div class="tabs" id="admin-tabs" role="tablist" aria-label="${t('admin.title')}">
      ${tabHtml}
      <span class="tab-indicator" id="admin-tab-indicator" aria-hidden="true"></span>
    </div>
    <div id="admin-tab-content" role="tabpanel" aria-labelledby="admin-tab-${_activeTab}"></div>
  `);

  updateTabIndicator('admin-tabs', 'admin-tab-indicator', true);

  const tabsEl = document.getElementById('admin-tabs');

  tabsEl.addEventListener('click', e => {
    const btn = e.target.closest('[data-tab]');
    if (!btn) return;
    _activeTab = btn.dataset.tab;
    tabsEl.querySelectorAll('.tab-btn').forEach(b => {
      const active = b.dataset.tab === _activeTab;
      b.classList.toggle('active', active);
      b.setAttribute('aria-selected', active);
    });
    updateTabIndicator('admin-tabs', 'admin-tab-indicator');
    document.getElementById('admin-tab-content')?.setAttribute('aria-labelledby', `admin-tab-${_activeTab}`);
    renderTab(_activeTab);
  });

  tabsEl.addEventListener('keydown', e => {
    const allTabs = [...tabsEl.querySelectorAll('[role="tab"]')];
    const idx = allTabs.indexOf(document.activeElement);
    if (idx === -1) return;
    let next = idx;
    if (e.key === 'ArrowRight') next = (idx + 1) % allTabs.length;
    else if (e.key === 'ArrowLeft') next = (idx - 1 + allTabs.length) % allTabs.length;
    else if (e.key === 'Home') next = 0;
    else if (e.key === 'End') next = allTabs.length - 1;
    else return;
    e.preventDefault();
    allTabs[next].focus();
  });

  renderTab(_activeTab);
}

function updateTabIndicator(tabsId, indicatorId, instant = false) {
  const tabs      = document.getElementById(tabsId);
  const indicator = document.getElementById(indicatorId);
  if (!tabs || !indicator) return;
  const active = tabs.querySelector('.tab-btn.active');
  if (!active) return;
  if (instant) {
    indicator.style.transition = 'none';
    requestAnimationFrame(() => { indicator.style.transition = ''; });
  }
  indicator.style.height    = active.offsetHeight + 'px';
  indicator.style.top       = active.offsetTop + 'px';
  indicator.style.width     = active.offsetWidth + 'px';
  indicator.style.transform = `translateX(${active.offsetLeft}px)`;
}

function renderTab(tab) {
  const el = document.getElementById('admin-tab-content');
  if (!el) return;
  switch (tab) {
    case 'users':         renderUsers(el); break;
    case 'tournaments':   renderTournaments(el); break;
    case 'teams':         renderTeams(el); break;
    case 'reevaluations': renderReevaluations(el); break;
    case 'roles':         renderRoles(el); break;
  }
}

/* ── Users ────────────────────────────────────────────────────────────── */
function renderUsers(el) {
  const rows = _data.users.map(u => `
    <tr>
      <td>${u.id}</td>
      <td>${esc(u.username)}</td>
      <td>${esc(u.display_name)}</td>
      <td>${u.is_admin ? `<span class="text-accent">${t('admin.tabRoles').slice(0,5)}</span>` : '—'}</td>
      <td>
        <button class="btn btn-ghost btn-sm" data-edit-user="${u.id}">${t('admin.edit')}</button>
        ${u.id !== state.user.uid
          ? `<button class="btn btn-danger btn-sm" data-delete-user="${u.id}">${t('admin.delete')}</button>`
          : ''}
      </td>
    </tr>
    <tr id="edit-row-${u.id}" style="display:none">
      <td colspan="5">
        <form class="card" id="edit-form-${u.id}" style="max-width:400px">
          <div class="form-group">
            <label>${t('admin.username')}</label>
            <input type="text" name="username" value="${esc(u.username)}" required>
          </div>
          <div class="form-group">
            <label>${t('admin.displayName')}</label>
            <input type="text" name="display_name" value="${esc(u.display_name)}" required>
          </div>
          <div class="flex gap-8">
            <button type="submit" class="btn btn-primary btn-sm">${t('admin.save')}</button>
            <button type="button" class="btn btn-ghost btn-sm" data-cancel-edit="${u.id}">${t('admin.cancel')}</button>
          </div>
        </form>
      </td>
    </tr>`).join('');

  el.innerHTML = `
    <div class="table-wrap">
      <table>
        <thead><tr>
          <th>${t('admin.id')}</th>
          <th>${t('admin.username')}</th>
          <th>${t('admin.displayName')}</th>
          <th>${t('admin.roleCol')}</th>
          <th></th>
        </tr></thead>
        <tbody>${rows}</tbody>
      </table>
    </div>`;

  el.querySelectorAll('[data-edit-user]').forEach(btn => {
    const id = parseInt(btn.dataset.editUser);
    btn.addEventListener('click', () => {
      document.getElementById(`edit-row-${id}`).style.display = '';
    });
  });

  el.querySelectorAll('[data-cancel-edit]').forEach(btn => {
    const id = parseInt(btn.dataset.cancelEdit);
    btn.addEventListener('click', () => {
      document.getElementById(`edit-row-${id}`).style.display = 'none';
    });
  });

  el.querySelectorAll('[id^="edit-form-"]').forEach(form => {
    const id = parseInt(form.id.replace('edit-form-', ''));
    form.addEventListener('submit', async e => {
      e.preventDefault();
      const fd = new FormData(form);
      try {
        await api('POST', `/admin/users/${id}/edit`, Object.fromEntries(fd.entries()));
        toast(t('admin.userUpdated'), 'success');
        _data = await loadAdminData();
        renderUsers(el);
      } catch (e) {
        toast(e.message, 'error');
      }
    });
  });

  el.querySelectorAll('[data-delete-user]').forEach(btn => {
    btn.addEventListener('click', () => {
      confirmInline(btn, t('admin.deleteUserConfirm'), async () => {
        try {
          await api('POST', `/admin/users/${btn.dataset.deleteUser}/delete`, {});
          toast(t('admin.userDeleted'), 'success');
          _data = await loadAdminData();
          renderUsers(el);
        } catch (e) {
          toast(e.detail ?? e.message, 'error');
        }
      });
    });
  });
}

/* ── Tournaments ──────────────────────────────────────────────────────── */
function renderTournaments(el) {
  // is_featured control hidden for school deployment — re-enable if needed
  const rows = _data.tournaments.map(t_ => `
    <tr>
      <td>${link('/tournament/' + t_.id, esc(t_.name))}</td>
      <td>${esc(t_.sport)}</td>
      <td>${badge(t_.status)}</td>
      <td>${esc(t_.organiser_name)}</td>
      <td>
        <button class="btn btn-danger btn-sm" data-delete-tournament="${t_.id}">${t('admin.delete2')}</button>
      </td>
    </tr>`).join('');

  el.innerHTML = `
    <div class="table-wrap">
      <table>
        <thead><tr>
          <th>${t('admin.name')}</th>
          <th>${t('admin.sport')}</th>
          <th>${t('admin.status')}</th>
          <th>${t('admin.organiser')}</th>
          <th></th>
        </tr></thead>
        <tbody>${rows}</tbody>
      </table>
    </div>

    <div class="card mt-24" style="max-width:540px">
      <div class="section-title">${t('admin.createTournamentTitle')}</div>
      <form id="admin-create-tournament" style="margin-top:12px">
        <div class="two-col">
          <div class="form-group">
            <label>${t('admin.name')}</label>
            <input type="text" name="name" required>
          </div>
          <div class="form-group">
            <label>${t('admin.sport')}</label>
            <input type="text" name="sport" required>
          </div>
        </div>
        <div class="two-col">
          <div class="form-group">
            <label>${t('admin.format')}</label>
            <select name="format">
              <option value="single_elimination">Einfaches K.-o.-System</option>
              <option value="double_elimination">Doppeltes K.-o.-System</option>
              <option value="round_robin">Rundenturnier</option>
              <option value="swiss">Schweizer System</option>
            </select>
          </div>
          <div class="form-group">
            <label>${t('admin.visibility')}</label>
            <select name="visibility">
              <option value="open">Offen</option>
              <option value="invite_only">Nur auf Einladung</option>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label>${t('admin.organiser')}</label>
          <select name="organiser_id" required>
            <option value="">${t('admin.selectPlaceholder')}</option>
            ${_data.users.map(u => `<option value="${u.id}">${esc(u.display_name)} (${esc(u.username)})</option>`).join('')}
          </select>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">${t('admin.create')}</button>
      </form>
    </div>
  `;

  el.querySelectorAll('[data-delete-tournament]').forEach(btn => {
    btn.addEventListener('click', () => {
      confirmInline(btn, t('admin.deleteTournamentConfirm'), async () => {
        try {
          await api('POST', `/admin/tournaments/${btn.dataset.deleteTournament}/delete`, {});
          toast(t('admin.tournamentDeleted'), 'success');
          _data = await loadAdminData();
          renderTournaments(el);
        } catch (e) {
          toast(e.message, 'error');
        }
      });
    });
  });

  el.querySelector('#admin-create-tournament').addEventListener('submit', async e => {
    e.preventDefault();
    const fd = new FormData(e.target);
    try {
      await api('POST', '/admin/tournaments/create', Object.fromEntries(fd.entries()));
      toast(t('admin.tournamentCreated'), 'success');
      e.target.reset();
      _data = await loadAdminData();
      renderTournaments(el);
    } catch (e) {
      toast(e.message, 'error');
    }
  });
}

/* ── Teams ──────────────────────────────────────────────────────────────── */
function renderTeams(el) {
  if (_data.teams.length === 0) {
    el.innerHTML = Empty.state(t('admin.noTeams'));
    return;
  }

  const rows = _data.teams.map(t_ => `
    <tr>
      <td>${t_.id}</td>
      <td>${link('/team/' + t_.id, esc(t_.name))}</td>
      <td>${t_.sport ? esc(t_.sport) : '<span class="text-muted">—</span>'}</td>
      <td>${esc(t_.owner_name)}</td>
      <td>${t_.member_count}</td>
      <td>${badge(t_.status)}</td>
      <td>${t_.tournament_count}</td>
      <td>
        <span class="flex gap-8">
          <button class="btn btn-${t_.status === 'archived' ? 'secondary' : 'danger'} btn-sm"
                  data-team-status="${t_.id}"
                  data-current-status="${t_.status}">
            ${t_.status === 'archived' ? t('admin.unarchive') : t('admin.archive')}
          </button>
          <button class="btn btn-danger btn-sm" data-delete-team="${t_.id}">
            ${t('admin.team.delete')}
          </button>
        </span>
      </td>
    </tr>`).join('');

  el.innerHTML = `
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>${t('admin.id')}</th>
            <th>${t('admin.name')}</th>
            <th>${t('admin.sport')}</th>
            <th>${t('admin.owner')}</th>
            <th>${t('admin.members')}</th>
            <th>${t('admin.status')}</th>
            <th>${t('admin.tournaments')}</th>
            <th></th>
          </tr>
        </thead>
        <tbody>${rows}</tbody>
      </table>
    </div>`;

  el.querySelectorAll('[data-team-status]').forEach(btn => {
    btn.addEventListener('click', async () => {
      const id        = parseInt(btn.dataset.teamStatus);
      const newStatus = btn.dataset.currentStatus === 'archived' ? 'active' : 'archived';
      try {
        await api('POST', `/admin/team/${id}/status`, { status: newStatus });
        toast(`Team ${newStatus === 'archived' ? t('admin.archive').toLowerCase() : t('admin.unarchive').toLowerCase()}.`, 'success');
        _data = await loadAdminData();
        renderTeams(el);
      } catch (e) {
        toast(e.message, 'error');
      }
    });
  });

  el.querySelectorAll('[data-delete-team]').forEach(btn => {
    btn.addEventListener('click', () => {
      const id = parseInt(btn.dataset.deleteTeam);
      confirmInline(btn, t('admin.team.delete_confirm'), async () => {
        try {
          await api('POST', `/admin/team/${id}/delete`, {});
          toast(t('admin.team.delete_success'), 'success');
          btn.closest('tr')?.remove();
          _data.teams = _data.teams.filter(t_ => t_.id !== id);
        } catch (e) {
          toast(e.message, 'error');
        }
      });
    });
  });
}

/* ── Reevaluations ────────────────────────────────────────────────────── */
function renderReevaluations(el) {
  if (_data.reevaluations.length === 0) {
    el.innerHTML = Empty.state(t('admin.noReevals'));
    return;
  }

  const rows = _data.reevaluations.map(r => `
    <tr>
      <td>${link('/tournament/' + r.tournament_id, esc(r.tournament_name))}</td>
      <td>R${r.round} M${r.match_number}</td>
      <td>${esc(r.home_team_name)} vs ${esc(r.away_team_name)}</td>
      <td>${r.requested_home_score} – ${r.requested_away_score}</td>
      <td>${esc(r.requester_name)}</td>
      <td>
        <span class="flex gap-8">
          <button class="btn btn-secondary btn-sm" data-force-approve="${r.id}">${t('admin.forceApprove')}</button>
          <button class="btn btn-danger btn-sm" data-reeval-reject="${r.id}">${t('admin.reject')}</button>
        </span>
      </td>
    </tr>`).join('');

  el.innerHTML = `
    <div class="table-wrap">
      <table>
        <thead><tr>
          <th>${t('admin.tournament')}</th>
          <th>${t('admin.match')}</th>
          <th>${t('admin.teamsCol')}</th>
          <th>${t('admin.requestedScore')}</th>
          <th>${t('admin.requester')}</th>
          <th></th>
        </tr></thead>
        <tbody>${rows}</tbody>
      </table>
    </div>`;

  el.querySelectorAll('[data-force-approve]').forEach(btn => {
    btn.addEventListener('click', async () => {
      try {
        await api('POST', `/reevaluation/${btn.dataset.forceApprove}/force-approve`, {});
        toast(t('admin.forceApproved'), 'success');
        _data = await loadAdminData();
        renderReevaluations(el);
      } catch (e) {
        toast(e.message, 'error');
      }
    });
  });

  el.querySelectorAll('[data-reeval-reject]').forEach(btn => {
    btn.addEventListener('click', async () => {
      try {
        await api('POST', `/reevaluation/${btn.dataset.reevalReject}/resolve`, { action: 'reject', review_note: '' });
        toast(t('admin.reevalRejected'), 'success');
        _data = await loadAdminData();
        renderReevaluations(el);
      } catch (e) {
        toast(e.message, 'error');
      }
    });
  });
}

/* ── Roles ────────────────────────────────────────────────────────────── */
function renderRoles(el) {
  el.innerHTML = `
    <div class="two-col" style="max-width:800px">
      <div class="card">
        <div class="section-title">${t('admin.assignRole')}</div>
        <form id="assign-role-form" style="margin-top:12px">
          <div class="form-group">
            <label>${t('admin.tournament')}</label>
            <select name="tournament_id" required>
              <option value="">${t('admin.selectPlaceholder')}</option>
              ${_data.tournaments.map(t_ => `<option value="${t_.id}">${esc(t_.name)}</option>`).join('')}
            </select>
          </div>
          <div class="form-group">
            <label>${t('admin.user')}</label>
            <select name="user_id" required>
              <option value="">${t('admin.selectPlaceholder')}</option>
              ${_data.users.filter(u => !u.is_admin).map(u =>
                `<option value="${u.id}">${esc(u.display_name)} (${esc(u.username)})</option>`).join('')}
            </select>
          </div>
          <div class="form-group">
            <label>${t('admin.roleCol')}</label>
            <select name="role">
              <option value="organiser">Organisator</option>
              <option value="staff">Mitarbeiter</option>
            </select>
          </div>
          <button type="submit" class="btn btn-primary btn-sm">${t('admin.assign')}</button>
        </form>
      </div>

      <div class="card">
        <div class="section-title">${t('admin.revokeRole')}</div>
        <form id="revoke-role-form" style="margin-top:12px">
          <div class="form-group">
            <label>${t('admin.tournament')}</label>
            <select name="tournament_id" required>
              <option value="">${t('admin.selectPlaceholder')}</option>
              ${_data.tournaments.map(t_ => `<option value="${t_.id}">${esc(t_.name)}</option>`).join('')}
            </select>
          </div>
          <div class="form-group">
            <label>${t('admin.user')}</label>
            <select name="user_id" required>
              <option value="">${t('admin.selectPlaceholder')}</option>
              ${_data.users.filter(u => !u.is_admin).map(u =>
                `<option value="${u.id}">${esc(u.display_name)} (${esc(u.username)})</option>`).join('')}
            </select>
          </div>
          <button type="submit" class="btn btn-danger btn-sm">${t('admin.revoke')}</button>
        </form>
      </div>
    </div>`;

  el.querySelector('#assign-role-form').addEventListener('submit', async e => {
    e.preventDefault();
    const fd = new FormData(e.target);
    try {
      await api('POST', '/admin/roles/assign', Object.fromEntries(fd.entries()));
      toast(t('admin.roleAssigned'), 'success');
      e.target.reset();
    } catch (e) {
      toast(e.message, 'error');
    }
  });

  el.querySelector('#revoke-role-form').addEventListener('submit', async e => {
    e.preventDefault();
    const fd = new FormData(e.target);
    try {
      await api('POST', '/admin/roles/revoke', Object.fromEntries(fd.entries()));
      toast(t('admin.roleRevoked'), 'success');
      e.target.reset();
    } catch (e) {
      toast(e.message, 'error');
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

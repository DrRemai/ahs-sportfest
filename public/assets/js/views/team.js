// SCHOOL: i18n — all strings translated to German
import { api, state, setApp } from '../app.js';
import { navigate } from '../router.js';
import { badge } from '../components/badge.js';
import { toast } from '../components/toast.js';
import { confirmInline } from '../components/confirm.js';
import { Skeleton } from '../components/skeleton.js';
import { Empty } from '../components/empty.js';
import { t, ordinal } from '../i18n.js';

let _team    = null;
let _members = [];
let _history = [];
let _tab     = 'history';
let _teamId  = null;

export async function teamView(id) {
  _teamId = id;
  _tab    = 'history';
  setApp(Skeleton.teamPage());

  const data = await api('GET', `/team/${id}`);
  _team    = data.team;
  _members = data.members;
  _history = data.history;

  render();
}

function render() {
  const isOwner = _team.is_owner;

  setApp(`
    ${heroHtml(isOwner)}
    ${isOwner ? managePanelHtml() : ''}
    <div class="tabs" id="team-tabs" role="tablist" aria-label="Team-Bereiche">
      <button class="tab${_tab === 'history' ? ' active' : ''}"
        role="tab" aria-selected="${_tab === 'history'}"
        aria-controls="team-tab-content" id="team-tab-history"
        data-tab="history">${t('team.historyTab')}</button>
      <button class="tab${_tab === 'roster' ? ' active' : ''}"
        role="tab" aria-selected="${_tab === 'roster'}"
        aria-controls="team-tab-content" id="team-tab-roster"
        data-tab="roster">${t('team.rosterTab', { n: _members.length })}</button>
    </div>
    <div id="team-tab-content" role="tabpanel" aria-labelledby="team-tab-${_tab}"></div>
  `);

  const teamTabsEl = document.getElementById('team-tabs');

  teamTabsEl.addEventListener('click', e => {
    const btn = e.target.closest('[data-tab]');
    if (!btn) return;
    _tab = btn.dataset.tab;
    teamTabsEl.querySelectorAll('.tab').forEach(b => {
      const active = b.dataset.tab === _tab;
      b.classList.toggle('active', active);
      b.setAttribute('aria-selected', active);
    });
    document.getElementById('team-tab-content')?.setAttribute('aria-labelledby', `team-tab-${_tab}`);
    renderTab();
  });

  teamTabsEl.addEventListener('keydown', e => {
    const allTabs = [...teamTabsEl.querySelectorAll('[role="tab"]')];
    const idx = allTabs.indexOf(document.activeElement);
    if (idx === -1) return;
    let next = idx;
    if (e.key === 'ArrowRight') next = (idx + 1) % allTabs.length;
    else if (e.key === 'ArrowLeft') next = (idx - 1 + allTabs.length) % allTabs.length;
    else return;
    e.preventDefault();
    allTabs[next].focus();
  });

  if (isOwner) {
    const toggle = document.getElementById('manage-toggle');
    const panel  = document.getElementById('manage-panel');
    let open = false;

    toggle.addEventListener('click', () => {
      open = !open;
      panel.classList.toggle('open', open);
      toggle.textContent = open ? t('team.hide') : t('team.manage');
    });

    document.getElementById('cancel-btn').addEventListener('click', () => {
      open = false;
      panel.classList.remove('open');
      toggle.textContent = t('team.manage');
    });

    document.getElementById('edit-desc').addEventListener('input', e => {
      document.getElementById('desc-counter').textContent = `${e.target.value.length}/280`;
    });

    document.getElementById('manage-form').addEventListener('submit', async e => {
      e.preventDefault();
      const btn   = document.getElementById('save-btn');
      const name  = document.getElementById('edit-name').value.trim();
      const sport = document.getElementById('edit-sport').value.trim();
      const desc  = document.getElementById('edit-desc').value.trim();

      document.getElementById('err-name').textContent  = '';
      document.getElementById('err-sport').textContent = '';

      btn.disabled    = true;
      btn.textContent = t('team.saving');

      try {
        await api('PATCH', `/team/${_teamId}`, { name, sport, description: desc });
        if (name)  _team.name        = name;
        if (sport) _team.sport       = sport;
        _team.description = desc || null;
        btn.textContent = t('team.savedBtn');
        setTimeout(() => {
          toast(t('team.updatedToast'), 'success');
          render();
        }, 600);
      } catch (err) {
        btn.disabled    = false;
        btn.textContent = t('team.saveChanges');
        if (err.fields?.name)  document.getElementById('err-name').textContent  = err.fields.name;
        if (err.fields?.sport) document.getElementById('err-sport').textContent = err.fields.sport;
        if (!err.fields) {
          const msg = err.message === 'team_name_claimed'
            ? t('team.nameTaken')
            : err.message;
          toast(msg, 'error');
        }
      }
    });

    if (_team.status !== 'archived') {
      const archiveBtn = document.getElementById('archive-btn');
      const archiveErr = document.getElementById('archive-error');

      archiveBtn.addEventListener('click', () => {
        confirmInline(archiveBtn, t('team.archiveConfirm'), async () => {
          try {
            await api('POST', `/team/${_teamId}/archive`, {});
            toast(t('team.archivedToast'), 'success');
            _team.status = 'archived';
            render();
          } catch (err) {
            archiveErr.textContent   = err.statusCode === 409
              ? t('team.activeRegistrations')
              : err.message;
            archiveErr.style.display = 'block';
          }
        });
      });
    }
  }

  renderTab();
}

// ─── Hero card ───────────────────────────────────────────────────────────────

function heroHtml(isOwner) {
  const sportBadgeHtml = _team.sport
    ? `<span class="badge badge-open" style="font-size:10px;margin-left:10px">${esc(_team.sport)}</span>`
    : '';
  const archivedHtml = _team.status === 'archived' ? badge('archived') : '';
  const descHtml = _team.description
    ? `<p style="font-size:13px;color:var(--text-2);max-width:600px;margin-top:10px;line-height:1.65">${esc(_team.description)}</p>`
    : '';

  return `
    <div class="card" style="border-left:3px solid var(--amber);border-top-left-radius:var(--radius-sm);border-bottom-left-radius:var(--radius-sm);margin-bottom:16px">
      <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap">
        <div>
          <h1 style="font-size:28px;font-weight:800;color:var(--text-1);letter-spacing:-0.02em;line-height:1.2">
            ${esc(_team.name)}${_team.short_name
              ? ` <span style="font-size:16px;font-weight:400;color:var(--text-3)">(${esc(_team.short_name)})</span>`
              : ''}
            ${sportBadgeHtml}
          </h1>
          <p style="font-size:12px;color:var(--text-2);margin-top:6px">
            ${t('team.ownedBy')} ${esc(_team.owner.display_name)}
          </p>
          ${descHtml}
        </div>
        <div style="display:flex;align-items:center;gap:8px;flex-shrink:0">
          ${archivedHtml}
          ${isOwner
            ? `<button class="btn btn-ghost btn-sm" id="manage-toggle">${t('team.manage')}</button>`
            : ''}
        </div>
      </div>
    </div>`;
}

// ─── Manage panel ────────────────────────────────────────────────────────────

function managePanelHtml() {
  return `
    <div id="manage-panel" class="slide-down" style="margin-bottom:8px">
      <div class="card" style="margin-bottom:16px">
        <div class="section-title" style="margin-bottom:16px">${t('team.manageTitle')}</div>
        <form id="manage-form">
          <div class="form-group">
            <label>${t('team.name')}</label>
            <input type="text" id="edit-name" value="${esc(_team.name)}" maxlength="48">
            <div class="form-error" id="err-name"></div>
          </div>
          <div class="form-group">
            <label>${t('team.sport')}</label>
            <input type="text" id="edit-sport" value="${esc(_team.sport ?? '')}" maxlength="32">
            <div class="form-error" id="err-sport"></div>
          </div>
          <div class="form-group">
            <label>
              ${t('team.description')}
              <span style="color:var(--text-3);font-size:11px;margin-left:4px;font-weight:400">
                <span id="desc-counter">${(_team.description ?? '').length}</span>/280
              </span>
            </label>
            <textarea id="edit-desc" rows="3" maxlength="280" style="resize:vertical">${esc(_team.description ?? '')}</textarea>
          </div>
          <div class="flex gap-8" style="margin-bottom:16px">
            <button type="submit" class="btn btn-primary btn-sm" id="save-btn">${t('team.saveChanges')}</button>
            <button type="button" class="btn btn-ghost btn-sm" id="cancel-btn">${t('team.cancel')}</button>
          </div>
        </form>
        ${_team.status !== 'archived' ? `
          <div style="padding-top:16px;border-top:1px solid rgba(255,255,255,0.06)">
            <button class="btn btn-danger btn-sm" id="archive-btn">${t('team.archive')}</button>
            <div class="form-error" id="archive-error" style="display:none;margin-top:8px"></div>
          </div>` : `
          <div style="padding-top:16px;border-top:1px solid rgba(255,255,255,0.06)">
            <span style="font-size:12px;color:var(--text-3)">${t('team.isArchived')}</span>
          </div>`}
      </div>
    </div>`;
}

// ─── Tab content ─────────────────────────────────────────────────────────────

function renderTab() {
  const el = document.getElementById('team-tab-content');
  if (!el) return;
  if (_tab === 'history') renderHistory(el);
  else                    renderRoster(el);
}

function renderHistory(el) {
  if (_history.length === 0) {
    el.innerHTML = `<div style="padding:24px 0">${Empty.state(t('team.noHistory'))}</div>`;
    return;
  }

  const rows = _history.map(h => {
    const standingCell = h.status === 'finalised' && h.standing !== null
      ? `<strong style="color:var(--amber)">${ordinal(h.standing)}</strong>`
      : h.status === 'in_progress'
        ? `<span style="color:var(--text-3)">${t('team.ongoing')}</span>`
        : `<span style="color:var(--text-3)">${t('team.pendingStanding')}</span>`;

    return `<tr style="cursor:pointer" data-nav="/tournament/${h.tournament_id}">
      <td${h.is_featured ? ' style="border-left:2px solid var(--amber);padding-left:14px"' : ''}>${esc(h.tournament_name)}</td>
      <td>${h.sport ? esc(h.sport) : '<span style="color:var(--text-3)">—</span>'}</td>
      <td>${badge(h.status)}</td>
      <td>${standingCell}</td>
    </tr>`;
  }).join('');

  el.innerHTML = `
    <div class="table-wrap" style="margin-top:16px">
      <table>
        <thead><tr>
          <th>${t('team.tournament')}</th>
          <th>${t('team.sportCol')}</th>
          <th>${t('team.statusCol')}</th>
          <th>${t('team.standingCol')}</th>
        </tr></thead>
        <tbody>${rows}</tbody>
      </table>
    </div>`;

  el.querySelectorAll('tr[data-nav]').forEach(tr => {
    tr.addEventListener('click', () => navigate(tr.dataset.nav));
  });
}

function renderRoster(el) {
  const isOwner = _team.is_owner;

  const chipsHtml = _members.length === 0
    ? `<p style="color:var(--text-3);font-size:13px;padding:16px 0">${Empty.state(t('team.noRoster'))}</p>`
    : `<div style="display:flex;flex-wrap:wrap;gap:8px;padding:16px 0">${_members.map(m => `
        <span class="member-chip">
          ${esc(m.name)}
          ${isOwner ? `<button data-remove-member="${m.id}"
            style="background:none;border:none;color:var(--text-3);cursor:pointer;font-size:11px;padding:0;line-height:1"
            title="${t('team.removeMemberConfirm')}">✕</button>` : ''}
        </span>`).join('')}</div>`;

  const addForm = isOwner ? `
    <form id="add-member-form" style="margin-top:4px;display:flex;gap:8px;align-items:flex-start">
      <div class="form-group" style="margin:0;flex:1">
        <input type="text" id="new-member-name" placeholder="${t('team.memberNamePH')}" maxlength="80">
      </div>
      <button type="submit" class="btn btn-secondary btn-sm" style="margin-top:0">${t('team.add')}</button>
    </form>` : '';

  el.innerHTML = `<div style="margin-top:8px">${chipsHtml}${addForm}</div>`;

  if (isOwner) {
    el.querySelectorAll('[data-remove-member]').forEach(btn => {
      const memberId = parseInt(btn.dataset.removeMember);
      btn.addEventListener('click', () => {
        confirmInline(btn, t('team.removeMemberConfirm'), async () => {
          try {
            await api('DELETE', `/team/${_teamId}/members/${memberId}`, {});
            _members = _members.filter(m => m.id !== memberId);
            const rosterTab = document.querySelector('#team-tabs [data-tab="roster"]');
            if (rosterTab) rosterTab.textContent = t('team.rosterTab', { n: _members.length });
            renderRoster(el);
          } catch (err) {
            toast(err.message, 'error');
          }
        });
      });
    });

    document.getElementById('add-member-form').addEventListener('submit', async e => {
      e.preventDefault();
      const nameInput = document.getElementById('new-member-name');
      const name = nameInput.value.trim();
      if (!name) return;
      try {
        const data = await api('POST', `/team/${_teamId}/members/add`, { name });
        _members.push(data.member);
        nameInput.value = '';
        const rosterTab = document.querySelector('#team-tabs [data-tab="roster"]');
        if (rosterTab) rosterTab.textContent = t('team.rosterTab', { n: _members.length });
        renderRoster(el);
      } catch (err) {
        toast(err.message, 'error');
      }
    });
  }
}

function esc(s) {
  return String(s ?? '')
    .replace(/&/g, '&amp;').replace(/</g, '&lt;')
    .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

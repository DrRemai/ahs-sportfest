// SCHOOL: i18n — all strings translated to German
import { api, state, setApp } from '../app.js';
import { navigate, link } from '../router.js';
import { badge } from '../components/badge.js';
import { toast } from '../components/toast.js';
import { confirmInline } from '../components/confirm.js';
import { Skeleton } from '../components/skeleton.js';
import { Empty } from '../components/empty.js';
import { trapFocus, releaseFocus } from '../utils/focus-trap.js';
import { sseSubscribeTournament, sseUnsubscribeTournament, sseOn } from '../utils/sse-client.js';
import { t, formatLabel, roleLabel, statusLabel } from '../i18n.js';

let _tid          = null;
let _data         = null;
let _activeTab    = 'overview';
let _flashMatchId = null;
let _unsubs       = [];

export async function tournamentView(id) {
  _unsubs.forEach(fn => fn());
  _unsubs = [];
  if (_tid && _tid !== id) sseUnsubscribeTournament(_tid);

  _tid       = id;
  _activeTab = new URLSearchParams(location.search).get('tab') ?? 'overview';

  setApp(Skeleton.tournamentOverview());

  _data = await api('GET', `/tournament/${id}`);

  sseSubscribeTournament(_tid);

  _unsubs.push(
    sseOn('match_update', payload => {
      if (payload.tournament_id !== _tid) return;
      updateMatchNode(payload.match_id, payload);
      refreshStandings();
      if (payload.is_live !== undefined) {
        updateLiveMatches(payload);
        refreshLiveCard();
      }
    }),
    sseOn('match_created', payload => {
      if (payload.tournament_id !== _tid) return;
      refreshBracket();
    }),
  );

  render();
}

// Tab key → German label mapping
const TAB_LABELS = {
  overview:  t('tab.overview'),
  bracket:   t('tab.bracket'),
  schedule:  t('tab.schedule'),
  standings: t('tab.standings'),
  teams:     t('tab.teams'),
  settings:  t('tab.settings'),
};

function render() {
  const { tournament, role } = _data;

  const tabKeys = ['overview', 'bracket', 'schedule', 'standings', 'teams'];
  if (role === 'organiser' || role === 'admin') tabKeys.push('settings');

  const tabHtml = tabKeys.map(key => {
    const active = _activeTab === key;
    return `<button class="tab-btn${active ? ' active' : ''}"
      role="tab"
      aria-selected="${active}"
      aria-controls="tab-panel"
      id="tour-tab-${key}"
      data-tab="${key}">${TAB_LABELS[key]}</button>`;
  }).join('');

  const selectHtml = tabKeys.map(key =>
    `<option value="${key}"${_activeTab === key ? ' selected' : ''}>${TAB_LABELS[key]}</option>`
  ).join('');

  const visLabel = tournament.visibility === 'open' ? t('overview.visOpen') : t('overview.visInvite');

  setApp(`
    <div class="tournament-hero">
      <div class="tournament-hero-name">
        ${esc(tournament.name)}
      </div>
      <div class="tournament-hero-meta">
        ${badge(tournament.status)}
        <span style="color:var(--text-2)">${formatLabel(tournament.format)}</span>
        <span style="color:var(--text-2)">${visLabel}</span>
        <span>${esc(tournament.sport)}</span>
        <span>by ${esc(tournament.organiser_name)}</span>
        ${role ? `<span class="text-accent" style="font-size:11px;text-transform:uppercase;letter-spacing:0.06em">${roleLabel(role)}</span>` : ''}
      </div>
    </div>
    <div class="tab-select-wrap">
      <select class="tab-select" aria-label="Turnierbereich wählen">${selectHtml}</select>
    </div>
    <div class="tabs-container">
      <div class="tabs" id="tour-tabs" role="tablist" aria-label="Turnier-Bereiche">
        ${tabHtml}
        <span class="tab-indicator" id="tour-tab-indicator" aria-hidden="true"></span>
      </div>
    </div>
    <div id="tab-content" role="tabpanel" aria-labelledby="tour-tab-${_activeTab}"></div>
  `);

  updateTabIndicator('tour-tabs', 'tour-tab-indicator', true);

  const tabsEl = document.getElementById('tour-tabs');

  tabsEl.addEventListener('click', e => {
    const btn = e.target.closest('[data-tab]');
    if (!btn) return;
    switchTab(btn.dataset.tab);
  });

  document.querySelector('.tab-select').addEventListener('change', e => {
    switchTab(e.target.value);
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

  loadTab(_activeTab);
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

function switchTab(key) {
  _activeTab = key;
  const tabsEl = document.getElementById('tour-tabs');
  if (tabsEl) {
    tabsEl.querySelectorAll('.tab-btn').forEach(b => {
      const active = b.dataset.tab === key;
      b.classList.toggle('active', active);
      b.setAttribute('aria-selected', active);
    });
    updateTabIndicator('tour-tabs', 'tour-tab-indicator');
  }
  document.getElementById('tab-content')?.setAttribute('aria-labelledby', `tour-tab-${key}`);
  const sel = document.querySelector('.tab-select');
  if (sel && sel.value !== key) sel.value = key;
  loadTab(key);
}

async function loadTab(tab) {
  const el = document.getElementById('tab-content');
  if (!el) return;
  switch (tab) {
    case 'overview':   renderOverview(el);          break;
    case 'bracket':    await renderBracket(el);     break;
    case 'schedule':   renderSchedule(el);          break;
    case 'standings':  await renderStandings(el);   break;
    case 'teams':      renderTeams(el);             break;
    case 'settings':   renderSettings(el);          break;
  }
}

// ---------------------------------------------------------------------------
// Overview
// ---------------------------------------------------------------------------

function renderOverview(el) {
  const tv      = _data.tournament;
  const role    = _data.role;
  const reevals = _data.reevaluations ?? [];

  let reevalHtml = '';
  if (reevals.length > 0) {
    const rows = reevals.map(r => `
      <tr>
        <td>R${r.round} M${r.match_number}</td>
        <td>${esc(r.home_team_name)} vs ${esc(r.away_team_name)}</td>
        <td>${r.requested_home_score} – ${r.requested_away_score}</td>
        <td>${esc(r.requester_name)}</td>
        <td>${esc(r.reason ?? '—')}</td>
        <td>
          <span class="flex gap-8">
            <button class="btn btn-secondary btn-sm" data-reeval-approve="${r.id}">${t('overview.approve')}</button>
            <button class="btn btn-danger btn-sm" data-reeval-reject="${r.id}">${t('overview.reject')}</button>
          </span>
        </td>
      </tr>`).join('');

    reevalHtml = `
      <div class="section-title mt-24">${t('overview.pendingReevals')}</div>
      <div class="table-wrap">
        <table>
          <thead><tr>
            <th>${t('overview.matchCol')}</th>
            <th>${t('overview.teamsCol')}</th>
            <th>${t('overview.scoreCol')}</th>
            <th>${t('overview.requester')}</th>
            <th>${t('overview.reason')}</th>
            <th></th>
          </tr></thead>
          <tbody>${rows}</tbody>
        </table>
      </div>`;
  }

  el.innerHTML = `
    <div class="overview-grid">
      <div class="card">
        <div class="section-title">${t('overview.details')}</div>
        <table style="width:auto">
          <tbody>
            <tr><td class="text-muted" style="padding:4px 16px 4px 0">${t('overview.sport')}</td>
                <td>${esc(tv.sport)}</td></tr>
            <tr><td class="text-muted" style="padding:4px 16px 4px 0">${t('overview.format')}</td>
                <td>${formatLabel(tv.format)}</td></tr>
            <tr><td class="text-muted" style="padding:4px 16px 4px 0">${t('overview.status')}</td>
                <td>${badge(tv.status)}</td></tr>
            <tr><td class="text-muted" style="padding:4px 16px 4px 0">${t('overview.visibility')}</td>
                <td>${badge(tv.visibility)}</td></tr>
            <tr><td class="text-muted" style="padding:4px 16px 4px 0;vertical-align:top">${t('overview.description')}</td>
                <td style="color:${tv.description ? 'var(--text-2)' : 'var(--text-3)'};line-height:1.6;max-width:260px;white-space:normal">
                  ${tv.description ? esc(tv.description) : t('overview.noDescription')}
                </td></tr>
          </tbody>
        </table>
      </div>
      <div class="card" id="live-card">
        ${liveCardContent()}
      </div>
    </div>
    <div id="join-section">${joinSectionHtml()}</div>
    ${reevalHtml}
  `;

  el.querySelectorAll('[data-reeval-approve]').forEach(btn =>
    btn.addEventListener('click', () => resolveReeval(parseInt(btn.dataset.reevalApprove), 'approve')));
  el.querySelectorAll('[data-reeval-reject]').forEach(btn =>
    btn.addEventListener('click', () => resolveReeval(parseInt(btn.dataset.reevalReject), 'reject')));

  const joinBtn = el.querySelector('#join-btn');
  if (joinBtn) {
    joinBtn.addEventListener('click', () =>
      handleJoinClick(document.getElementById('join-section')));
  }
}

function joinSectionHtml() {
  const tv     = _data.tournament;
  const role   = _data.role;
  const myTeam = _data.my_team;

  const eligible =
    tv.visibility === 'open' &&
    !['finalised', 'archived'].includes(tv.status) &&
    !!state.user &&
    !role;

  if (!eligible) return '';

  if (myTeam?.status === 'approved') {
    return `<p class="text-success mt-16" style="font-size:13px">${t('join.registered')}</p>`;
  }
  if (myTeam?.status === 'pending') {
    return `<p class="text-muted mt-16" style="font-size:13px">${t('join.pending')}</p>`;
  }

  return `
    <div class="mt-24">
      <button class="btn btn-secondary" id="join-btn">${t('join.joinBtn')}</button>
    </div>`;
}

async function handleJoinClick(section) {
  const btn = section.querySelector('#join-btn');
  if (btn) { btn.disabled = true; btn.textContent = t('join.loading'); }

  let teams;
  try {
    const data = await api('GET', '/me/teams');
    teams = data.teams;
  } catch (e) {
    toast(e.message, 'error');
    if (btn) { btn.disabled = false; btn.textContent = t('join.joinBtn'); }
    return;
  }

  if (teams.length === 0) {
    section.innerHTML = `
      <p class="text-muted mt-16" style="font-size:13px">
        ${t('join.needTeam')} ${link('/create-team', t('join.createOne'))}
      </p>`;
    return;
  }

  const options = teams.map(tv =>
    `<option value="${tv.id}">${esc(tv.name)}${tv.sport ? ' (' + esc(tv.sport) + ')' : ''}</option>`
  ).join('');

  section.innerHTML = `
    <div class="flex-center gap-8 mt-16">
      <select id="join-team-select" style="width:210px">${options}</select>
      <button class="btn btn-primary btn-sm" id="join-confirm">${t('join.requestToJoin')}</button>
      <button class="btn btn-ghost btn-sm" id="join-cancel">${t('join.cancel')}</button>
    </div>`;

  section.querySelector('#join-cancel').addEventListener('click', () => {
    section.innerHTML = `
      <div class="mt-24">
        <button class="btn btn-secondary" id="join-btn">${t('join.joinBtn')}</button>
      </div>`;
    section.querySelector('#join-btn').addEventListener('click', () => handleJoinClick(section));
  });

  section.querySelector('#join-confirm').addEventListener('click', async () => {
    const teamId    = parseInt(section.querySelector('#join-team-select').value);
    const confirmBtn = section.querySelector('#join-confirm');
    confirmBtn.disabled    = true;
    confirmBtn.textContent = t('join.sending');
    try {
      await api('POST', `/tournament/${_tid}/teams/request`, { team_id: teamId });
      section.innerHTML = `<p class="text-muted mt-16" style="font-size:13px">${t('join.pending')}</p>`;
      _data.my_team = { status: 'pending', team_id: teamId };
      toast(t('join.pending'), 'success');
    } catch (e) {
      if (e.message === 'already_registered') {
        section.innerHTML = `<p class="text-muted mt-16" style="font-size:13px">${t('join.pending')}</p>`;
        _data.my_team = { status: 'pending' };
      } else {
        toast(e.message, 'error');
        confirmBtn.disabled    = false;
        confirmBtn.textContent = t('join.requestToJoin');
      }
    }
  });
}

async function resolveReeval(rrId, action) {
  try {
    await api('POST', `/reevaluation/${rrId}/resolve`, { action, review_note: '' });
    toast(action === 'approve' ? `${t('overview.approve')}d.` : `${t('overview.reject')}d.`, 'success');
    _data = await api('GET', `/tournament/${_tid}`);
    render();
  } catch (e) {
    toast(e.message, 'error');
  }
}

// ---------------------------------------------------------------------------
// Bracket
// ---------------------------------------------------------------------------

async function renderBracket(el) {
  el.innerHTML = Skeleton.bracketTab();

  let bracketData;
  try {
    bracketData = await api('GET', `/tournament/${_tid}/bracket`);
  } catch (e) {
    el.innerHTML = Empty.state(e.message);
    return;
  }

  const fmt     = bracketData.format ?? _data.tournament?.format ?? 'single_elim';
  const canEdit = _data.role === 'organiser' || _data.role === 'admin' || _data.role === 'staff';

  if (window.innerWidth <= 640) {
    renderMobileBracket(el, bracketData, canEdit);
    return;
  }

  if (fmt === 'double_elim') {
    renderDoubleElimBracket(el, bracketData, canEdit);
  } else if (fmt === 'round_robin' || fmt === 'swiss') {
    renderRoundList(el, bracketData, canEdit);
  } else if (fmt === 'multi_stage') {
    renderMultiStageBracket(el, bracketData, canEdit);
  } else {
    renderSingleElimBracket(el, bracketData, canEdit);
  }
}

function renderSingleElimBracket(el, bracketData, canEdit) {
  const { rounds } = bracketData;
  if (!rounds || rounds.length === 0) {
    el.innerHTML = Empty.state(t('bracket.notGenerated'));
    return;
  }

  const roundsHtml = rounds.map((round, ri) => {
    const matchesHtml = round.matches.map(m => matchCard(m, canEdit)).join('');
    return `
      <div class="bracket-round">
        <div class="bracket-round-label">${t('bracket.round')} ${ri + 1}</div>
        ${matchesHtml}
      </div>`;
  }).join('');

  el.innerHTML = `<div class="bracket-outer"><div class="bracket">${roundsHtml}</div></div>`;
  _attachMatchHandlers(el);
}

function renderMultiStageBracket(el, bracketData, canEdit) {
  const { groups = {}, knockout_rounds = [] } = bracketData;
  const groupLetters = Object.keys(groups).sort();
  const hasGroups    = groupLetters.length > 0;
  const hasKnockout  = knockout_rounds.length > 0;

  if (!hasGroups && !hasKnockout) {
    el.innerHTML = Empty.state(t('bracket.notGenerated'));
    return;
  }

  let html = '';

  if (hasGroups) {
    html += groupLetters.map(letter => {
      const rounds = groups[letter] ?? [];
      if (rounds.length === 0) return '';
      const roundsHtml = rounds.map((round, ri) => `
        <div class="bracket-round">
          <div class="bracket-round-label">${t('bracket.round')} ${ri + 1}</div>
          ${round.matches.map(m => matchCard(m, canEdit)).join('')}
        </div>`).join('');
      return `
        <div class="ms-group-section">
          <div class="ms-group-label">Gruppe ${letter}</div>
          <div class="bracket-outer"><div class="bracket">${roundsHtml}</div></div>
        </div>`;
    }).join('');
  }

  if (hasKnockout) {
    const total      = knockout_rounds.length;
    const roundsHtml = knockout_rounds.map((round, ri) => `
      <div class="bracket-round">
        <div class="bracket-round-label">${elim_round_name(ri, total)}</div>
        ${round.matches.map(m => matchCard(m, canEdit)).join('')}
      </div>`).join('');
    html += `
      <div class="ms-group-section">
        <div class="ms-group-label">K.-o.-Runde</div>
        <div class="bracket-outer"><div class="bracket">${roundsHtml}</div></div>
      </div>`;
  }

  el.innerHTML = html;
  _attachMatchHandlers(el);
}

function renderDoubleElimBracket(el, bracketData, canEdit) {
  const { winners_rounds, losers_rounds, grand_final } = bracketData;

  const hasWB = winners_rounds && winners_rounds.length > 0;
  const hasLB = losers_rounds  && losers_rounds.length  > 0;

  if (!hasWB && !hasLB && !grand_final) {
    el.innerHTML = Empty.state(t('bracket.notGenerated'));
    return;
  }

  function bracketSection(rounds, label) {
    if (!rounds || rounds.length === 0) return '';
    const roundsHtml = rounds.map((round, ri) => `
      <div class="bracket-round">
        <div class="bracket-round-label">R${ri + 1}</div>
        ${round.matches.map(m => matchCard(m, canEdit)).join('')}
      </div>`).join('');
    return `
      <div class="de-section">
        <div class="de-section-label">${label}</div>
        <div class="bracket-outer"><div class="bracket">${roundsHtml}</div></div>
      </div>`;
  }

  const gfHtml = grand_final ? `
    <div class="de-section mt-24">
      <div class="de-section-label">${t('bracket.grandFinal')}</div>
      <div class="bracket-outer">
        <div class="bracket">
          <div class="bracket-round">
            ${matchCard(grand_final, canEdit)}
          </div>
        </div>
      </div>
    </div>` : '';

  el.innerHTML = `
    <div class="de-layout">
      ${bracketSection(winners_rounds, t('bracket.winners'))}
      ${bracketSection(losers_rounds, t('bracket.losers'))}
    </div>
    ${gfHtml}`;

  _attachMatchHandlers(el);
}

function renderRoundList(el, bracketData, canEdit) {
  const { rounds, format } = bracketData;

  if (!rounds || rounds.length === 0) {
    el.innerHTML = Empty.state(t('bracket.scheduleNotGenerated'));
    return;
  }

  const fmt   = format ?? '';
  const label = fmt === 'swiss' ? t('schedule.swissRound') : t('schedule.round');

  const roundsHtml = rounds.map((round, ri) => {
    const rows = round.matches.map(m => {
      const liveToggle = (canEdit && ['pending', 'accepted'].includes(m.status) && (m.home_team_id || m.away_team_id))
        ? `<button class="live-toggle${m.is_live ? ' active' : ''}" data-live-toggle="${m.id}">
             ${m.is_live ? `${t('match.live')} ●` : t('match.markLive')}
           </button>`
        : '';
      return `
      <tr>
        <td>${esc(m.home_team_name ?? t('bracket.tbd'))}</td>
        <td style="text-align:center;font-weight:700">
          ${m.status === 'accepted' ? `${m.home_score} – ${m.away_score}` : '–'}
        </td>
        <td>${esc(m.away_team_name ?? t('bracket.bye'))}</td>
        <td>
          <div class="match-actions">
            ${badge(m.status)}
            ${canEdit && m.status !== 'bye'
              ? `<button class="btn btn-ghost btn-sm" data-score-toggle="${m.id}">${t('schedule.scoreBtn')}</button>`
              : ''}
            ${liveToggle}
          </div>
        </td>
      </tr>
      <tr id="sched-form-row-${m.id}" style="display:none">
        <td colspan="4" id="sched-score-${m.id}"></td>
      </tr>`;
    }).join('');

    return `
      <div class="section-title mt-${ri === 0 ? '0' : '24'}">${label} ${ri + 1}</div>
      <div class="table-wrap">
        <table>
          <thead><tr>
            <th>${t('schedule.home')}</th>
            <th>${t('schedule.score')}</th>
            <th>${t('schedule.away')}</th>
            <th></th>
          </tr></thead>
          <tbody>${rows}</tbody>
        </table>
      </div>`;
  }).join('');

  el.innerHTML = roundsHtml;

  el.querySelectorAll('[data-score-toggle]').forEach(btn => {
    btn.addEventListener('click', () =>
      toggleScheduleScore(btn, parseInt(btn.dataset.scoreToggle), el));
  });
  el.querySelectorAll('[data-live-toggle]').forEach(btn => {
    btn.addEventListener('click', () => {
      const matchId = parseInt(btn.dataset.liveToggle);
      const m = _data.matches?.find(m => m.id === matchId);
      if (!m) return;
      toggleMatchLive(matchId, !!m.is_live, btn);
    });
  });
}

function _attachMatchHandlers(el) {
  if (_flashMatchId !== null) {
    setTimeout(() => { _flashMatchId = null; }, 800);
  }
  el.querySelectorAll('[data-score-toggle]').forEach(btn => {
    btn.addEventListener('click', () =>
      toggleScoreEntry(btn, parseInt(btn.dataset.scoreToggle)));
  });
  el.querySelectorAll('[data-live-toggle]').forEach(btn => {
    btn.addEventListener('click', () => {
      const matchId = parseInt(btn.dataset.liveToggle);
      const m = _data.matches?.find(m => m.id === matchId);
      if (!m) return;
      toggleMatchLive(matchId, !!m.is_live, btn);
    });
  });
}

function matchCard(m, canEdit) {
  const homeClass  = m.winner_id && m.winner_id === m.home_team_id ? ' winner' : '';
  const awayClass  = m.winner_id && m.winner_id === m.away_team_id ? ' winner' : '';
  const flashClass = _flashMatchId === m.id ? ' match-flash' : '';

  const scoreEntry = (canEdit && m.status !== 'bye' && (m.home_team_id || m.away_team_id))
    ? `<button class="btn btn-ghost btn-sm" data-score-toggle="${m.id}">${t('bracket.enterScore')}</button>`
    : '';

  // Live toggle: organiser/staff/admin, only for pending or accepted matches with at least one team
  const liveToggle = (canEdit && ['pending', 'accepted'].includes(m.status) && (m.home_team_id || m.away_team_id))
    ? `<button class="live-toggle${m.is_live ? ' active' : ''}" data-live-toggle="${m.id}">
         ${m.is_live ? `${t('match.live')} ●` : t('match.markLive')}
       </button>`
    : '';

  return `
    <div class="bracket-match-slot">
      <div class="bracket-match${flashClass}" data-match-id="${m.id}">
        <div class="bracket-team${homeClass}">
          <span class="bracket-team-name">${
            m.status === 'bye' ? `<em class="bye">${t('bracket.bye')}</em>` : esc(m.home_team_name ?? t('bracket.tbd'))
          }</span>
          <span class="bracket-score">${m.home_score ?? ''}</span>
        </div>
        <div class="bracket-team${awayClass}">
          <span class="bracket-team-name">${esc(m.away_team_name ?? t('bracket.tbd'))}</span>
          <span class="bracket-score">${m.away_score ?? ''}</span>
        </div>
        <div class="bracket-match-status">
          ${badge(m.status)}
          ${scoreEntry}
          ${liveToggle}
        </div>
      </div>
      <div id="score-form-${m.id}"></div>
    </div>`;
}

// ── Mobile score sheet ────────────────────────────────────────────────────

function showScoreSheet(matchId) {
  const match = findMatch(matchId);

  const overlay = document.createElement('div');
  overlay.id = 'score-sheet-overlay';
  overlay.className = 'score-sheet-overlay';
  overlay.setAttribute('aria-hidden', 'true');

  const sheet = document.createElement('div');
  sheet.id = 'score-sheet';
  sheet.className = 'score-sheet';
  sheet.setAttribute('role', 'dialog');
  sheet.setAttribute('aria-modal', 'true');
  sheet.setAttribute('aria-label', `${esc(match?.home_team_name ?? 'Heim')} vs ${esc(match?.away_team_name ?? 'Auswärts')}`);

  const needsReason = match?.status === 'accepted' && _data.role === 'staff';

  sheet.innerHTML = `
    <div class="score-sheet-handle" aria-hidden="true"></div>
    <div class="score-sheet-title">${esc(match?.home_team_name ?? 'Heim')} vs ${esc(match?.away_team_name ?? 'Auswärts')}</div>
    <div class="score-entry">
      ${needsReason
        ? `<p class="text-muted" style="font-size:11px;margin-bottom:8px">
             ${t('score.reevalWarning')}
           </p>`
        : ''}
      <div class="score-row">
        <label for="hs-${matchId}">${esc(match?.home_team_name ?? 'Heim')}</label>
        <input type="number" id="hs-${matchId}" value="${match?.home_score ?? 0}" min="0"
               style="width:64px;text-align:center" aria-required="true">
      </div>
      <div class="score-row">
        <label for="as-${matchId}">${esc(match?.away_team_name ?? 'Auswärts')}</label>
        <input type="number" id="as-${matchId}" value="${match?.away_score ?? 0}" min="0"
               style="width:64px;text-align:center" aria-required="true">
      </div>
      ${needsReason ? `<div class="form-group" style="margin-top:8px">
        <label for="reason-${matchId}">${t('score.reason')}</label>
        <textarea id="reason-${matchId}"></textarea>
      </div>` : ''}
      <div class="flex gap-8" style="margin-top:16px">
        <button class="btn btn-primary" id="sheet-submit" aria-busy="false">${t('score.submit')}</button>
        <button class="btn btn-ghost" id="sheet-cancel">${t('score.cancel')}</button>
      </div>
    </div>`;

  document.body.appendChild(overlay);
  document.body.appendChild(sheet);

  requestAnimationFrame(() => {
    overlay.classList.add('open');
    sheet.classList.add('open');
  });

  trapFocus(sheet);

  sheet.querySelector('#sheet-submit').addEventListener('click', async () => {
    const submitBtn = sheet.querySelector('#sheet-submit');
    submitBtn.setAttribute('aria-busy', 'true');
    submitBtn.disabled = true;
    submitBtn.textContent = t('score.submitting');
    await submitScore(matchId);
    closeScoreSheet();
  });

  sheet.querySelector('#sheet-cancel').addEventListener('click', closeScoreSheet);
  overlay.addEventListener('click', closeScoreSheet);

  let startY = 0;
  sheet.addEventListener('touchstart', e => { startY = e.touches[0].clientY; }, { passive: true });
  sheet.addEventListener('touchend', e => {
    if (e.changedTouches[0].clientY - startY > 80) closeScoreSheet();
  }, { passive: true });

  document.addEventListener('keydown', closeSheetOnEsc);
}

function closeScoreSheet() {
  const sheet   = document.getElementById('score-sheet');
  const overlay = document.getElementById('score-sheet-overlay');
  if (!sheet) return;
  releaseFocus(sheet);
  sheet.classList.remove('open');
  overlay?.classList.remove('open');
  document.removeEventListener('keydown', closeSheetOnEsc);
  setTimeout(() => { sheet.remove(); overlay?.remove(); }, 400);
}

function closeSheetOnEsc(e) {
  if (e.key === 'Escape') closeScoreSheet();
}

// ─────────────────────────────────────────────────────────────────────────────

function toggleScoreEntry(btn, matchId) {
  if (window.innerWidth < 640) {
    showScoreSheet(matchId);
    return;
  }

  const container = document.getElementById(`score-form-${matchId}`);
  if (!container) return;
  if (container.innerHTML) { container.innerHTML = ''; return; }

  const match = findMatch(matchId);
  const isStaffReeval = match?.status === 'accepted' && _data.role === 'staff';

  container.innerHTML = `
    <div class="score-entry">
      ${isStaffReeval
        ? `<p class="text-muted" style="font-size:11px;margin-bottom:8px">
             ${t('score.reevalWarning')}
           </p>`
        : ''}
      <div class="score-row">
        <label>${esc(match?.home_team_name ?? 'Heim')}</label>
        <input type="number" id="hs-${matchId}" value="${match?.home_score ?? 0}" min="0"
               style="width:64px;text-align:center">
      </div>
      <div class="score-row">
        <label>${esc(match?.away_team_name ?? 'Auswärts')}</label>
        <input type="number" id="as-${matchId}" value="${match?.away_score ?? 0}" min="0"
               style="width:64px;text-align:center">
      </div>
      ${isStaffReeval
        ? `<div class="form-group mt-8"><label>${t('score.reason')}</label>
           <textarea id="reason-${matchId}"></textarea></div>`
        : ''}
      <div class="flex gap-8 mt-8">
        <button class="btn btn-primary btn-sm" data-submit-score="${matchId}">${t('score.submit')}</button>
        <button class="btn btn-ghost btn-sm" data-cancel-score="${matchId}">${t('score.cancel')}</button>
      </div>
    </div>`;

  container.querySelector('[data-submit-score]').addEventListener('click', () => submitScore(matchId));
  container.querySelector('[data-cancel-score]').addEventListener('click', () => { container.innerHTML = ''; });
}

async function submitScore(matchId) {
  const hs = parseInt(document.getElementById(`hs-${matchId}`)?.value ?? 0);
  const as = parseInt(document.getElementById(`as-${matchId}`)?.value ?? 0);
  const reasonEl = document.getElementById(`reason-${matchId}`);
  const body = { home_score: hs, away_score: as };
  if (reasonEl) body.reason = reasonEl.value.trim();

  try {
    await api('POST', `/match/${matchId}/result`, body);
    toast(t('score.submitted'), 'success');
    _flashMatchId = matchId;
    _data = await api('GET', `/tournament/${_tid}`);
    render();
    _activeTab = 'bracket';
    loadTab('bracket');
  } catch (e) {
    toast(e.message, 'error');
  }
}

function findMatch(id) {
  return _data.matches?.find(m => m.id === id);
}

// ---------------------------------------------------------------------------
// Schedule
// ---------------------------------------------------------------------------

function renderSchedule(el) {
  const matches = (_data.matches ?? []).filter(m => m.status !== 'bye');
  const canEdit = _data.role === 'organiser' || _data.role === 'admin' || _data.role === 'staff';

  if (matches.length === 0) {
    el.innerHTML = Empty.state(t('schedule.noMatches'));
    return;
  }

  const rows = matches.map(m => {
    const liveToggle = (canEdit && ['pending', 'accepted'].includes(m.status) && (m.home_team_id || m.away_team_id))
      ? `<button class="live-toggle${m.is_live ? ' active' : ''}" data-live-toggle="${m.id}">
           ${m.is_live ? `${t('match.live')} ●` : t('match.markLive')}
         </button>`
      : '';
    return `
    <tr>
      <td>R${m.round} M${m.match_number}</td>
      <td>${m.home_team_id ? link('/team/' + m.home_team_id, esc(m.home_team_name ?? t('bracket.tbd'))) : t('bracket.tbd')}</td>
      <td style="text-align:center;font-weight:700">
        ${['accepted', 'disputed'].includes(m.status)
          ? `${m.home_score} – ${m.away_score}`
          : '–'}
      </td>
      <td>${m.away_team_id ? link('/team/' + m.away_team_id, esc(m.away_team_name ?? t('bracket.tbd'))) : t('bracket.tbd')}</td>
      <td>
        <div class="match-actions">
          ${badge(m.status)}
          ${canEdit
            ? `<button class="btn btn-ghost btn-sm" data-score-toggle="${m.id}">${t('schedule.scoreBtn')}</button>`
            : ''}
          ${liveToggle}
        </div>
      </td>
    </tr>
    <tr id="sched-form-row-${m.id}" style="display:none">
      <td colspan="5" id="sched-score-${m.id}"></td>
    </tr>`;
  }).join('');

  el.innerHTML = `
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>${t('schedule.matchCol')}</th>
            <th>${t('schedule.home')}</th>
            <th>${t('schedule.score')}</th>
            <th>${t('schedule.away')}</th>
            <th></th>
          </tr>
        </thead>
        <tbody>${rows}</tbody>
      </table>
    </div>`;

  el.querySelectorAll('[data-score-toggle]').forEach(btn => {
    btn.addEventListener('click', () =>
      toggleScheduleScore(btn, parseInt(btn.dataset.scoreToggle), el));
  });
  el.querySelectorAll('[data-live-toggle]').forEach(btn => {
    btn.addEventListener('click', () => {
      const matchId = parseInt(btn.dataset.liveToggle);
      const m = _data.matches?.find(m => m.id === matchId);
      if (!m) return;
      toggleMatchLive(matchId, !!m.is_live, btn);
    });
  });
}

function toggleScheduleScore(btn, matchId, el) {
  if (window.innerWidth < 640) {
    showScoreSheet(matchId);
    return;
  }

  const row       = document.getElementById(`sched-form-row-${matchId}`);
  const container = document.getElementById(`sched-score-${matchId}`);
  if (!row || !container) return;
  if (row.style.display !== 'none') { row.style.display = 'none'; container.innerHTML = ''; return; }

  const match = findMatch(matchId);
  const isStaffReeval = match?.status === 'accepted' && _data.role === 'staff';

  row.style.display = '';
  container.innerHTML = `
    <div class="score-entry">
      ${isStaffReeval
        ? `<p class="text-muted" style="font-size:11px;margin-bottom:8px">
             ${t('score.reevalWarning')}
           </p>`
        : ''}
      <div class="score-row">
        <label>${esc(match?.home_team_name ?? 'Heim')}</label>
        <input type="number" id="hs-${matchId}" value="${match?.home_score ?? 0}" min="0"
               style="width:64px;text-align:center">
      </div>
      <div class="score-row">
        <label>${esc(match?.away_team_name ?? 'Auswärts')}</label>
        <input type="number" id="as-${matchId}" value="${match?.away_score ?? 0}" min="0"
               style="width:64px;text-align:center">
      </div>
      ${isStaffReeval
        ? `<div class="form-group mt-8"><label>${t('score.reason')}</label>
           <textarea id="reason-${matchId}"></textarea></div>`
        : ''}
      <div class="flex gap-8 mt-8">
        <button class="btn btn-primary btn-sm" data-submit-sched="${matchId}">${t('score.submit')}</button>
        <button class="btn btn-ghost btn-sm" data-cancel-sched="${matchId}">${t('score.cancel')}</button>
      </div>
    </div>`;

  container.querySelector('[data-submit-sched]').addEventListener('click', async () => {
    const hs = parseInt(document.getElementById(`hs-${matchId}`)?.value ?? 0);
    const as = parseInt(document.getElementById(`as-${matchId}`)?.value ?? 0);
    const reasonEl = document.getElementById(`reason-${matchId}`);
    const body = { home_score: hs, away_score: as };
    if (reasonEl) body.reason = reasonEl.value.trim();
    try {
      await api('POST', `/match/${matchId}/result`, body);
      toast(t('score.submitted'), 'success');
      _data = await api('GET', `/tournament/${_tid}`);
      renderSchedule(el);
    } catch (e) { toast(e.message, 'error'); }
  });
  container.querySelector('[data-cancel-sched]').addEventListener('click', () => {
    row.style.display = 'none'; container.innerHTML = '';
  });
}

// ---------------------------------------------------------------------------
// Standings
// ---------------------------------------------------------------------------

async function renderStandings(el) {
  el.innerHTML = Skeleton.tableTab(8, 4);

  let data;
  try {
    data = await api('GET', `/tournament/${_tid}/standings`);
  } catch (e) {
    el.innerHTML = Empty.state(e.message);
    return;
  }

  const { standings, format, groups } = data;

  if (format === 'multi_stage' && (groups || (data.knockout_standings?.length > 0))) {
    el.innerHTML = renderMultiStageStandings(data);
    return;
  }

  if (!standings || standings.length === 0) {
    el.innerHTML = Empty.state(t('standings.noResults'));
    return;
  }

  if (format === 'round_robin') {
    el.innerHTML = renderRRStandings(standings);
  } else if (format === 'swiss') {
    el.innerHTML = renderSwissStandings(standings);
  } else {
    el.innerHTML = renderPlacementStandings(standings);
  }
}

function renderMultiStageStandings(data) {
  const { groups = {}, knockout_standings = [] } = data;
  const letters = Object.keys(groups).sort();
  let html = '';

  if (letters.length > 0) {
    const cols = letters.map(letter => `
      <div class="ms-standings-group">
        <div class="ms-group-label">${t('bracket.gruppe')} ${letter}</div>
        ${renderRRStandings(groups[letter])}
      </div>`).join('');
    html += `<div class="ms-standings-grid">${cols}</div>`;
  }

  if (knockout_standings.length > 0) {
    if (html) html += '<div class="ms-standings-divider"></div>';
    html += renderKnockoutStandings(knockout_standings);
  }

  return html || Empty.state(t('standings.noResults'));
}

function renderKnockoutStandings(rows) {
  const tRows = rows.map(r => `
    <tr>
      <td>${r.place}</td>
      <td>${r.team_id ? link('/team/' + r.team_id, esc(r.team_name)) : '—'}</td>
      <td>${r.round_name ?? '—'}</td>
    </tr>`).join('');

  return `
    <div class="ms-standings-ko">
      <div class="ms-group-label">${t('standings.knockout')}</div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>${t('standings.place')}</th>
              <th>${t('standings.team')}</th>
              <th>${t('standings.knockout.round')}</th>
            </tr>
          </thead>
          <tbody>${tRows}</tbody>
        </table>
      </div>
    </div>`;
}

function renderRRStandings(standings) {
  const rows = standings.map((s, i) => `
    <tr${i < 3 ? ` class="rank-${i + 1}"` : ''}>
      <td>${i + 1}</td>
      <td>${link('/team/' + s.team_id, esc(s.name))}</td>
      <td>${s.p}</td>
      <td>${s.w}</td>
      <td>${s.d}</td>
      <td>${s.l}</td>
      <td>${s.goals_ratio === null ? t('standings.ratio.infinity') : Number(s.goals_ratio).toFixed(2)}</td>
      <td><strong>${s.pts}</strong></td>
    </tr>`).join('');

  return `
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>${t('standings.team')}</th>
            <th>${t('standings.played')}</th>
            <th>${t('standings.wins')}</th>
            <th>${t('standings.draws')}</th>
            <th>${t('standings.losses')}</th>
            <th>${t('standings.ratio')}</th>
            <th>${t('standings.pts')}</th>
          </tr>
        </thead>
        <tbody>${rows}</tbody>
      </table>
    </div>`;
}

function renderSwissStandings(standings) {
  const rows = standings.map((s, i) => `
    <tr${i < 3 ? ` class="rank-${i + 1}"` : ''}>
      <td>${i + 1}</td>
      <td>${link('/team/' + s.team_id, esc(s.name))}</td>
      <td><strong>${s.points}</strong></td>
      <td class="text-muted">${s.buchholz}</td>
    </tr>`).join('');

  return `
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>${t('standings.team')}</th>
            <th>${t('standings.points')}</th>
            <th>${t('standings.buchholz')}</th>
          </tr>
        </thead>
        <tbody>${rows}</tbody>
      </table>
    </div>`;
}

function renderPlacementStandings(standings) {
  const sorted = [...standings].sort((a, b) => (a.place ?? 99) - (b.place ?? 99));
  const rows = sorted.map((s, i) => `
    <tr${i < 3 ? ` class="rank-${i + 1}"` : ''}>
      <td>${s.place ?? '—'}</td>
      <td>${s.team_id ? link('/team/' + s.team_id, esc(s.name ?? '?')) : esc(s.name ?? '?')}</td>
    </tr>`).join('');

  return `
    <div class="table-wrap">
      <table>
        <thead><tr><th>${t('standings.place')}</th><th>${t('standings.team')}</th></tr></thead>
        <tbody>${rows}</tbody>
      </table>
    </div>`;
}

// ---------------------------------------------------------------------------
// Teams
// ---------------------------------------------------------------------------

function renderTeams(el) {
  const teams     = _data.teams ?? [];
  const role      = _data.role;
  const canManage = role === 'organiser' || role === 'admin';

  if (teams.length === 0) {
    el.innerHTML = `
      ${Empty.state(t('teams.noTeams'))}
      ${canManage ? addTeamForm() : ''}`;
  } else {
    const rows = teams.map(tm => `
      <tr>
        <td>${tm.seed ?? '—'}</td>
        <td>${link('/team/' + tm.id, esc(tm.name))}</td>
        <td>${esc(tm.short_name ?? '—')}</td>
        <td>${badge(tm.reg_status)}</td>
        ${canManage ? `<td>
          ${tm.reg_status === 'pending'
            ? `<span class="flex gap-8">
                <button class="btn btn-secondary btn-sm" data-team-approve="${tm.id}">${t('teams.approve')}</button>
                <button class="btn btn-danger btn-sm" data-team-reject="${tm.id}">${t('teams.reject')}</button>
               </span>`
            : ''}
        </td>` : ''}
      </tr>`).join('');

    el.innerHTML = `
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>${t('teams.seed')}</th>
              <th>${t('teams.team')}</th>
              <th>${t('teams.code')}</th>
              <th>${t('teams.status')}</th>
              ${canManage ? '<th></th>' : ''}
            </tr>
          </thead>
          <tbody>${rows}</tbody>
        </table>
      </div>
      ${canManage ? addTeamForm() : ''}`;
  }

  el.querySelectorAll('[data-team-approve]').forEach(btn =>
    btn.addEventListener('click', () =>
      approveTeam(parseInt(btn.dataset.teamApprove), 'approve', el)));
  el.querySelectorAll('[data-team-reject]').forEach(btn =>
    btn.addEventListener('click', () =>
      approveTeam(parseInt(btn.dataset.teamReject), 'reject', el)));

  const addForm = el.querySelector('#add-team-form');
  if (addForm) {
    const select = addForm.querySelector('#add-team-select');
    api('GET', `/tournament/${_tid}/eligible-teams`).then(teams => {
      if (!teams || teams.length === 0) {
        select.innerHTML = `<option value="">${t('teams.noEligible')}</option>`;
      } else {
        select.innerHTML = `<option value="">— ${t('teams.team')} —</option>` +
          teams.map(tm =>
            `<option value="${tm.id}">${esc(tm.name)} (${t('admin.owner')}: ${esc(tm.owner_display_name)})</option>`
          ).join('');
      }
    }).catch(() => {
      select.innerHTML = `<option value="">${t('teams.failedLoad')}</option>`;
    });

    addForm.addEventListener('submit', async e => {
      e.preventDefault();
      const teamId = parseInt(e.target.querySelector('[name="team_id"]').value);
      if (!teamId) return;
      try {
        await api('POST', `/tournament/${_tid}/teams/add`, { team_id: teamId });
        toast(t('teams.added'), 'success');
        _data = await api('GET', `/tournament/${_tid}`);
        renderTeams(el);
      } catch (e) { toast(e.message, 'error'); }
    });
  }
}

function addTeamForm() {
  return `
    <form class="card mt-24" id="add-team-form">
      <div class="section-title">${t('teams.addTeamTitle')}</div>
      <div class="flex gap-8 mt-8" style="align-items:flex-end">
        <div class="form-group" style="margin:0;flex:1">
          <label for="add-team-select" style="font-size:11px;color:var(--text-3)">
            ${t('teams.addLabel')}
          </label>
          <select id="add-team-select" name="team_id" style="width:100%">
            <option value="">${t('teams.loading')}</option>
          </select>
        </div>
        <button type="submit" class="btn btn-secondary btn-sm">${t('teams.add')}</button>
      </div>
    </form>`;
}

async function approveTeam(teamId, action, el) {
  try {
    await api('POST', `/tournament/${_tid}/teams/approve`, { team_id: teamId, action });
    toast(action === 'approve' ? t('teams.approved') : t('teams.rejected'), 'success');
    _data = await api('GET', `/tournament/${_tid}`);
    renderTeams(el);
  } catch (e) { toast(e.message, 'error'); }
}

// ---------------------------------------------------------------------------
// Settings
// ---------------------------------------------------------------------------

function renderSettings(el) {
  const tv = _data.tournament;

  el.innerHTML = `
    <div class="settings-grid">
    <div class="card settings-main">
      <div class="section-title">${t('settings.title')}</div>
      <form id="settings-form">
        <div class="form-group">
          <label>${t('settings.name')}</label>
          <input type="text" name="name" value="${esc(tv.name)}" required>
        </div>
        <div class="form-group">
          <label>${t('settings.sport')}</label>
          <input type="text" name="sport" value="${esc(tv.sport)}" required>
        </div>
        <div class="form-group">
          <label>${t('settings.status')}</label>
          <select name="status">
            ${['draft','in_progress','finalised','archived'].map(s =>
              `<option value="${s}"${tv.status === s ? ' selected' : ''}>${statusLabel(s)}</option>`
            ).join('')}
          </select>
        </div>
        <div class="form-group">
          <label>${t('settings.visibility')}</label>
          <select name="visibility">
            <option value="open"${tv.visibility === 'open' ? ' selected' : ''}>${t('settings.visOpen')}</option>
            <option value="invite_only"${tv.visibility === 'invite_only' ? ' selected' : ''}>${t('settings.visInvite')}</option>
          </select>
        </div>
        <div class="form-group">
          <label>${t('settings.description')}</label>
          <textarea name="description">${esc(tv.description ?? '')}</textarea>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">${t('settings.save')}</button>
      </form>
    </div>

    <div class="card">
      <div class="section-title">${t('settings.generateBracket')}</div>
      <p class="text-muted mb-16" style="font-size:12px">
        ${t('settings.bracketNote')}
      </p>
      <form id="seed-form">
        <div class="form-group">
          <label>${t('settings.seedingMode')}</label>
          <select name="mode">
            <option value="manual">${t('settings.seedManual')}</option>
            <option value="random">${t('settings.seedRandom')}</option>
          </select>
        </div>
        <button type="submit" class="btn btn-secondary btn-sm">${t('settings.generate')}</button>
      </form>
    </div>

    <div class="card">
      <div class="section-title">${t('settings.staff')}</div>
      <div id="staff-list"><p class="text-muted" style="font-size:13px">${t('settings.staffLoading')}</p></div>
      <div class="mt-16">
        <div class="form-group" style="margin-bottom:0;position:relative">
          <label style="font-size:11px;color:var(--text-3)">${t('settings.staffSearchLabel')}</label>
          <input type="text" id="staff-search" placeholder="${t('settings.staffSearchPH')}"
                 autocomplete="off" style="width:100%">
          <div id="staff-results"
               style="display:none;position:absolute;left:0;right:0;z-index:20;
                      border:1px solid var(--border);background:var(--surface);
                      border-radius:var(--radius-sm);box-shadow:var(--shadow-md);
                      max-height:200px;overflow-y:auto">
          </div>
        </div>
      </div>
    </div>
    </div>
  `;

  el.querySelector('#settings-form').addEventListener('submit', async e => {
    e.preventDefault();
    const fd   = new FormData(e.target);
    const body = Object.fromEntries(fd.entries());
    try {
      await api('POST', `/tournament/${_tid}/settings`, body);
      toast(t('settings.saved'), 'success');
      _data = await api('GET', `/tournament/${_tid}`);
      render();
    } catch (e) { toast(e.message, 'error'); }
  });

  el.querySelector('#seed-form').addEventListener('submit', async e => {
    e.preventDefault();
    const mode        = e.target.querySelector('[name="mode"]').value;
    const approvedIds = (_data.teams ?? [])
      .filter(tm => tm.reg_status === 'approved')
      .map(tm => tm.id);
    try {
      await api('POST', `/tournament/${_tid}/seed`, { mode, team_ids: approvedIds });
      toast(t('settings.generated'), 'success');
      _data = await api('GET', `/tournament/${_tid}`);
      render();
      _activeTab = 'bracket';
      loadTab('bracket');
    } catch (e) { toast(e.message, 'error'); }
  });

  loadStaff(el);
  initStaffSearch(el);
}

// ---------------------------------------------------------------------------
// Settings — Staff management helpers
// ---------------------------------------------------------------------------

async function loadStaff(el) {
  try {
    const { roles } = await api('GET', `/tournament/${_tid}/roles`);
    renderStaffList(el, (roles ?? []).filter(r => r.role === 'staff'));
  } catch (_) {
    const c = el.querySelector('#staff-list');
    if (c) c.innerHTML = `<p class="text-muted" style="font-size:13px">${t('settings.staffFailed')}</p>`;
  }
}

function renderStaffList(el, staffRows) {
  const container = el.querySelector('#staff-list');
  if (!container) return;

  if (staffRows.length === 0) {
    container.innerHTML = `<p class="text-muted" style="font-size:13px">${t('settings.noStaff')}</p>`;
    return;
  }

  container.innerHTML = staffRows.map(r => `
    <div class="flex gap-8" style="align-items:center;padding:6px 0;border-bottom:1px solid var(--border)">
      <span style="flex:1">
        ${esc(r.display_name)}
        <span class="text-muted" style="font-size:11px">(${esc(r.username)})</span>
      </span>
      <button class="btn btn-danger btn-sm"
              data-revoke-uid="${r.user_uid}"
              data-revoke-name="${esc(r.display_name)}">${t('settings.remove')}</button>
    </div>`).join('');

  container.querySelectorAll('[data-revoke-uid]').forEach(btn => {
    const uid  = parseInt(btn.dataset.revokeUid);
    const name = btn.dataset.revokeName;
    btn.addEventListener('click', () => {
      confirmInline(btn, t('settings.removeConfirm'), async () => {
        try {
          const { roles } = await api('POST', `/tournament/${_tid}/roles/revoke`, { user_uid: uid });
          renderStaffList(el, (roles ?? []).filter(r => r.role === 'staff'));
          toast(t('settings.removedMsg', { name }), 'success');
        } catch (e) { toast(e.message, 'error'); }
      });
    });
  });
}

function initStaffSearch(el) {
  const input   = el.querySelector('#staff-search');
  const results = el.querySelector('#staff-results');
  if (!input || !results) return;

  let debounceTimer;

  input.addEventListener('input', () => {
    clearTimeout(debounceTimer);
    const q = input.value.trim();
    if (q.length < 2) { results.style.display = 'none'; return; }

    debounceTimer = setTimeout(async () => {
      try {
        const users = await api(
          'GET',
          `/users/search?q=${encodeURIComponent(q)}&exclude_tournament=${_tid}`
        );
        if (!users || users.length === 0) {
          results.innerHTML =
            `<div style="padding:8px 12px;color:var(--text-3);font-size:13px">${t('settings.noUsersFound')}</div>`;
        } else {
          results.innerHTML = users.map(u => `
            <div class="staff-search-item" tabindex="0"
                 data-uid="${u.uid}" data-name="${esc(u.display_name)}"
                 style="padding:8px 12px;cursor:pointer;font-size:13px">
              ${esc(u.display_name)}
              <span class="text-muted" style="font-size:11px">(${esc(u.username)})</span>
            </div>`).join('');

          results.querySelectorAll('.staff-search-item').forEach(item => {
            item.addEventListener('mouseenter', () => { item.style.background = 'var(--surface-2)'; });
            item.addEventListener('mouseleave', () => { item.style.background = ''; });
            const pickUser = async () => {
              const uid  = parseInt(item.dataset.uid);
              const name = item.dataset.name;
              results.style.display = 'none';
              input.value = '';
              try {
                const { roles } = await api(
                  'POST', `/tournament/${_tid}/roles/assign`, { user_uid: uid, role: 'staff' }
                );
                renderStaffList(el, (roles ?? []).filter(r => r.role === 'staff'));
                toast(t('settings.addedMsg', { name }), 'success');
              } catch (e) { toast(e.message, 'error'); }
            };
            item.addEventListener('click', pickUser);
            item.addEventListener('keydown', e => { if (e.key === 'Enter') pickUser(); });
          });
        }
        results.style.display = 'block';
      } catch (_) {
        results.style.display = 'none';
      }
    }, 300);
  });

  document.addEventListener('click', e => {
    if (!input.contains(e.target) && !results.contains(e.target)) {
      results.style.display = 'none';
    }
  });
}

// ---------------------------------------------------------------------------
// Mobile bracket accordion (≤ 640 px)
// ---------------------------------------------------------------------------

/*
 * DIAGNOSIS — Bracket tab mobile overflow (before changes)
 *
 * Root cause 1 — .bracket-outer owns BOTH overflow-x:auto AND display:flex +
 *   justify-content:center. The comment in the code correctly warns this is wrong
 *   but the fix was never applied: flex-centering pushes overflow equally left and
 *   right, and overflow-x:auto can only scroll rightward, so the left portion of
 *   round-1 cards is permanently clipped and unreachable on phones.
 *
 * Root cause 2 — Fixed pixel widths at max-width:768px:
 *   .bracket-round { min-width: 180px }
 *   .bracket-match-slot .bracket-match { width: 160px; min-width: 160px }
 *   At 375px, a 2-round tree is 180+32+180=392px — wider than the viewport.
 *   Scroll is the intended recovery, but root cause 1 clips the left side.
 *
 * Fix: below 640px, JS renders a vertical accordion instead of .bracket-outer.
 * The desktop tree is unchanged above 640px.
 */

function elim_round_name(ri, total) {
  const dist = total - 1 - ri;
  if (dist === 0) return t('bracket.final');
  if (dist === 1) return t('bracket.semi');
  if (dist === 2) return t('bracket.quarter');
  if (dist === 3) return t('bracket.eighth');
  return `${t('bracket.round')} ${ri + 1}`;
}

function defaultOpenRound(rounds) {
  for (let i = 0; i < rounds.length; i++) {
    if (rounds[i].matches.some(m => m.status === 'pending')) return i;
  }
  return rounds.length - 1;
}

function renderMobileBracket(el, bracketData, canEdit) {
  const fmt = bracketData.format ?? _data.tournament?.format ?? 'single_elim';
  if (fmt === 'double_elim') {
    renderMobileDoubleElim(el, bracketData, canEdit);
  } else if (fmt === 'round_robin' || fmt === 'swiss') {
    renderRoundList(el, bracketData, canEdit);
  } else if (fmt === 'multi_stage') {
    renderMobileMultiStage(el, bracketData, canEdit);
  } else {
    renderMobileSingleElim(el, bracketData, canEdit);
  }
}

function renderMobileSingleElim(el, bracketData, canEdit) {
  const { rounds } = bracketData;
  if (!rounds || rounds.length === 0) {
    el.innerHTML = Empty.state(t('bracket.notGenerated'));
    return;
  }
  const open = defaultOpenRound(rounds);
  const html = rounds.map((round, ri) => {
    const name = elim_round_name(ri, rounds.length);
    const cnt  = round.matches.filter(m => m.status !== 'bye').length;
    const isOpen = ri === open;
    return `
      <div class="round-accordion${isOpen ? ' open' : ''}">
        <button class="round-header" aria-expanded="${isOpen}" aria-controls="racc-${ri}">
          <span class="round-name">${name}</span>
          <span class="round-meta">${cnt} ${cnt === 1 ? t('bracket.matchOne') : t('bracket.matchMany')}</span>
          <span class="round-chevron">▾</span>
        </button>
        <div class="round-matches" id="racc-${ri}">
          ${round.matches.map(m => matchCard(m, canEdit)).join('')}
        </div>
      </div>`;
  }).join('');
  el.innerHTML = `<div class="mobile-bracket">${html}</div>`;
  _attachMatchHandlers(el);
  _attachAccordionHandlers(el);
}

function renderMobileDoubleElim(el, bracketData, canEdit) {
  const { winners_rounds, losers_rounds, grand_final } = bracketData;
  const hasWB = winners_rounds?.length > 0;
  const hasLB = losers_rounds?.length  > 0;
  if (!hasWB && !hasLB && !grand_final) {
    el.innerHTML = Empty.state(t('bracket.notGenerated'));
    return;
  }

  function section(rounds, label, prefix) {
    if (!rounds || rounds.length === 0) return '';
    const open = defaultOpenRound(rounds);
    return `
      <div class="mobile-bracket-section">
        <div class="mobile-bracket-section-label">${label}</div>
        ${rounds.map((round, ri) => {
          const cnt = round.matches.filter(m => m.status !== 'bye').length;
          const isOpen = ri === open;
          return `
            <div class="round-accordion${isOpen ? ' open' : ''}">
              <button class="round-header" aria-expanded="${isOpen}" aria-controls="${prefix}-${ri}">
                <span class="round-name">R${ri + 1}</span>
                <span class="round-meta">${cnt} ${cnt === 1 ? t('bracket.matchOne') : t('bracket.matchMany')}</span>
                <span class="round-chevron">▾</span>
              </button>
              <div class="round-matches" id="${prefix}-${ri}">
                ${round.matches.map(m => matchCard(m, canEdit)).join('')}
              </div>
            </div>`;
        }).join('')}
      </div>`;
  }

  const gfHtml = grand_final ? `
    <div class="mobile-bracket-section">
      <div class="mobile-bracket-section-label">${t('bracket.grandFinal')}</div>
      <div class="round-accordion open">
        <button class="round-header" aria-expanded="true" aria-controls="gf-acc">
          <span class="round-name">${t('bracket.final')}</span>
          <span class="round-meta">1 ${t('bracket.matchOne')}</span>
          <span class="round-chevron">▾</span>
        </button>
        <div class="round-matches" id="gf-acc">
          ${matchCard(grand_final, canEdit)}
        </div>
      </div>
    </div>` : '';

  el.innerHTML = `
    <div class="mobile-bracket">
      ${section(winners_rounds, t('bracket.winners'), 'wb')}
      ${section(losers_rounds,  t('bracket.losers'),  'lb')}
      ${gfHtml}
    </div>`;
  _attachMatchHandlers(el);
  _attachAccordionHandlers(el);
}

function renderMobileMultiStage(el, bracketData, canEdit) {
  const { groups = {}, knockout_rounds = [] } = bracketData;
  const groupLetters = Object.keys(groups).sort();
  const hasGroups    = groupLetters.length > 0;
  const hasKnockout  = knockout_rounds.length > 0;

  if (!hasGroups && !hasKnockout) {
    el.innerHTML = Empty.state(t('bracket.notGenerated'));
    return;
  }

  // Find which group has the next pending match (for default-open round)
  let activeGroup = null;
  for (const letter of groupLetters) {
    if ((groups[letter] ?? []).some(r => r.matches.some(m => m.status === 'pending'))) {
      activeGroup = letter;
      break;
    }
  }

  function groupSection(letter, rounds) {
    if (!rounds || rounds.length === 0) return '';
    const open = defaultOpenRound(rounds);
    const roundsHtml = rounds.map((round, ri) => {
      const cnt    = round.matches.filter(m => m.status !== 'bye').length;
      const isOpen = ri === open && activeGroup === letter;
      return `
        <div class="round-accordion${isOpen ? ' open' : ''}">
          <button class="round-header" aria-expanded="${isOpen}"
                  aria-controls="ms-grp-${letter}-${ri}">
            <span class="round-name">${t('bracket.round')} ${ri + 1}</span>
            <span class="round-meta">${cnt} ${cnt === 1 ? t('bracket.matchOne') : t('bracket.matchMany')}</span>
            <span class="round-chevron">▾</span>
          </button>
          <div class="round-matches" id="ms-grp-${letter}-${ri}">
            ${round.matches.map(m => matchCard(m, canEdit)).join('')}
          </div>
        </div>`;
    }).join('');

    return `
      <div class="mobile-bracket-section">
        <div class="mobile-bracket-section-label">${t('bracket.gruppe')} ${letter}</div>
        ${roundsHtml}
      </div>`;
  }

  function koSection(rounds) {
    if (!rounds || rounds.length === 0) return '';
    const total = rounds.length;
    const open  = defaultOpenRound(rounds);
    const roundsHtml = rounds.map((round, ri) => {
      const name   = elim_round_name(ri, total);
      const cnt    = round.matches.filter(m => m.status !== 'bye').length;
      const isOpen = ri === open && !activeGroup;
      return `
        <div class="round-accordion${isOpen ? ' open' : ''}">
          <button class="round-header" aria-expanded="${isOpen}"
                  aria-controls="ms-ko-${ri}">
            <span class="round-name">${name}</span>
            <span class="round-meta">${cnt} ${cnt === 1 ? t('bracket.matchOne') : t('bracket.matchMany')}</span>
            <span class="round-chevron">▾</span>
          </button>
          <div class="round-matches" id="ms-ko-${ri}">
            ${round.matches.map(m => matchCard(m, canEdit)).join('')}
          </div>
        </div>`;
    }).join('');

    return `<div class="ms-ko-divider">${t('bracket.ko_runde')}</div>${roundsHtml}`;
  }

  el.innerHTML = `
    <div class="mobile-bracket">
      ${groupLetters.map(l => groupSection(l, groups[l] ?? [])).join('')}
      ${hasKnockout ? koSection(knockout_rounds) : ''}
    </div>`;
  _attachMatchHandlers(el);
  _attachAccordionHandlers(el);
}

function _attachAccordionHandlers(el) {
  el.querySelectorAll('.round-header').forEach(btn => {
    btn.addEventListener('click', () => {
      const acc = btn.closest('.round-accordion');
      if (!acc) return;
      const isOpen = acc.classList.toggle('open');
      btn.setAttribute('aria-expanded', String(isOpen));
    });
  });
}

// ---------------------------------------------------------------------------
// Live match helpers
// ---------------------------------------------------------------------------

function liveCardContent() {
  const live = _data.live_matches ?? [];
  if (live.length === 0) {
    return `<div class="card-title">${t('overview.liveMatches')}</div>
            <p class="text-muted" style="font-size:13px;margin:0">${t('overview.noLive')}</p>`;
  }
  const rows = live.map(m => `
    <div class="live-match-row">
      <span class="live-match-teams">${esc(m.home_team_name ?? '?')} vs ${esc(m.away_team_name ?? '?')}</span>
      <span class="live-match-score">${m.home_score ?? 0} – ${m.away_score ?? 0}</span>
      <span class="text-muted" style="font-size:11px">${t('match.round')} ${m.round}</span>
    </div>`).join('');
  return `
    <div class="card-title" style="display:flex;align-items:center;gap:8px">
      ${t('overview.liveMatches')}
      <span class="live-badge">${t('match.live')}</span>
    </div>
    <div class="live-matches-list">${rows}</div>`;
}

function refreshLiveCard() {
  if (_activeTab !== 'overview') return;
  const card = document.getElementById('live-card');
  if (card) card.innerHTML = liveCardContent();
}

function updateLiveMatches(payload) {
  if (!_data.live_matches) _data.live_matches = [];
  const idx = _data.live_matches.findIndex(lm => lm.id === payload.match_id);
  if (payload.is_live) {
    const src = _data.matches?.find(m => m.id === payload.match_id);
    const entry = {
      id:             payload.match_id,
      round:          payload.round,
      home_score:     payload.home_score,
      away_score:     payload.away_score,
      home_team_name: src?.home_team_name ?? null,
      away_team_name: src?.away_team_name ?? null,
    };
    if (idx >= 0) {
      _data.live_matches[idx] = entry;
    } else {
      _data.live_matches.push(entry);
      _data.live_matches.sort((a, b) => a.round - b.round);
    }
  } else {
    if (idx >= 0) _data.live_matches.splice(idx, 1);
  }
}

async function toggleMatchLive(matchId, currentIsLive, btn) {
  const newState = !currentIsLive;
  // Optimistic UI
  btn.classList.toggle('active', newState);
  btn.textContent = newState ? `${t('match.live')} ●` : t('match.markLive');
  btn.disabled = true;

  const m = _data.matches?.find(m => m.id === matchId);
  if (m) m.is_live = newState;

  try {
    await api('POST', `/match/${matchId}/toggle-live`, { is_live: newState });
    // SSE will fire and call updateLiveMatches + refreshLiveCard
  } catch (e) {
    // Revert on error
    btn.classList.toggle('active', currentIsLive);
    btn.textContent = currentIsLive ? `${t('match.live')} ●` : t('match.markLive');
    if (m) m.is_live = currentIsLive;
    toast(e.message, 'error');
  } finally {
    btn.disabled = false;
  }
}

// ---------------------------------------------------------------------------
// SSE helpers
// ---------------------------------------------------------------------------

function updateMatchNode(matchId, payload) {
  const m = _data.matches?.find(m => m.id === matchId);
  if (m) {
    m.status     = payload.status;
    m.home_score = payload.home_score;
    m.away_score = payload.away_score;
    m.winner_id  = payload.winner_id;
    if (payload.is_live !== undefined) m.is_live = payload.is_live;
  }

  const card = document.querySelector(`.bracket-match[data-match-id="${matchId}"]`);
  if (!card) return;

  const teams = card.querySelectorAll('.bracket-team');
  if (teams[0]) {
    const scoreEl = teams[0].querySelector('.bracket-score');
    if (scoreEl) scoreEl.textContent = payload.home_score ?? '';
    teams[0].classList.toggle('winner', !!payload.winner_id && payload.winner_id === m?.home_team_id);
  }
  if (teams[1]) {
    const scoreEl = teams[1].querySelector('.bracket-score');
    if (scoreEl) scoreEl.textContent = payload.away_score ?? '';
    teams[1].classList.toggle('winner', !!payload.winner_id && payload.winner_id === m?.away_team_id);
  }

  const statusDiv = card.querySelector('.bracket-match-status');
  if (statusDiv) {
    const b = statusDiv.querySelector('.badge');
    if (b) {
      const tmp = document.createElement('span');
      tmp.innerHTML = badge(payload.status);
      b.replaceWith(tmp.firstElementChild);
    }
  }

  // Sync live toggle button if visible in bracket card
  if (payload.is_live !== undefined) {
    const toggleBtn = document.querySelector(`[data-live-toggle="${matchId}"]`);
    if (toggleBtn) {
      toggleBtn.classList.toggle('active', payload.is_live);
      toggleBtn.textContent = payload.is_live ? `${t('match.live')} ●` : t('match.markLive');
    }
  }

  card.classList.add('match-flash');
  card.addEventListener('animationend', () => card.classList.remove('match-flash'), { once: true });
}

async function refreshStandings() {
  if (_activeTab !== 'standings') return;
  const el = document.getElementById('tab-content');
  if (el) await renderStandings(el);
}

async function refreshBracket() {
  if (_activeTab !== 'bracket') return;
  try {
    const fresh = await api('GET', `/tournament/${_tid}`);
    _data = fresh;
  } catch (_) {}
  const el = document.getElementById('tab-content');
  if (el) await renderBracket(el);
}

function esc(s) {
  return String(s ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

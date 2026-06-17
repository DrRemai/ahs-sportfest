// SCHOOL: i18n — all strings translated to German
import { api, state as appState, setApp } from '../app.js';
import { navigate } from '../router.js';
import { toast } from '../components/toast.js';
import { t, formatLabel } from '../i18n.js';

// ─── Constants ───────────────────────────────────────────────────────────────

const SPORT_OPTIONS = ['Fußball', 'Volleyball', 'Hockey'];
const GROUP_MAX     = 6;
const TOTAL_STEPS   = 6;

// Locked multi-stage config for AHS Sportfest:
// Stage 1: round-robin in 2 groups of 6, top 8 advance (4 per group)
// Stage 2: single-elim with cross-bracket seeding + sequential pairing
const LOCKED_CONFIG = {
  stages: [
    {
      format: 'round_robin',
      config: { groups: 2, advance_count: 8, win_pts: 3, draw_pts: 1, loss_pts: 0 },
    },
    {
      format: 'single_elim',
      config: { seeding: 'cross_bracket', pairing: 'sequential' },
    },
  ],
};

const STEPS = [
  t('wiz.step1'),
  t('wiz.step2'),
  t('wiz.step3'),
  t('wiz.step4'),
  t('wiz.step5'),
  t('wiz.step6'),
];

// ─── Wizard state (module-level, reset on each entry) ────────────────────────

let wiz = null;

function initWiz() {
  wiz = {
    step:      1,
    completed: [],
    data: {
      name:                 '',
      sport:                '',
      format:               'multi_stage',
      formatConfig:         LOCKED_CONFIG,
      visibility:           'invite_only',
      registrationDeadline: null,
      groupA:               [],  // team IDs assigned to group A (in seed order)
      groupB:               [],  // team IDs assigned to group B (in seed order)
      teams:                [],  // fetched team objects for name lookup
    },
  };
}

// ─── Entry point ─────────────────────────────────────────────────────────────

export function createTournamentView() {
  if (!appState.user) {
    navigate('/login?redirect=/tournament/create');
    return;
  }
  initWiz();
  renderWizard();
}

// ─── Shell ───────────────────────────────────────────────────────────────────

function renderWizard() {
  setApp(`
    <div class="wizard-layout" id="wizard">
      <aside class="wizard-sidebar">
        <div class="wizard-sidebar-mobile" id="wiz-mobile-counter"></div>
        <div class="wizard-sidebar-full">
          <div class="wizard-sidebar-label">${t('wiz.title')}</div>
          <ul class="wizard-step-list" id="wiz-step-list"></ul>
        </div>
      </aside>
      <main class="wizard-content" id="wiz-content"></main>
    </div>
  `);
  updateSidebar();
  renderContent();
}

function updateSidebar() {
  const listEl = document.getElementById('wiz-step-list');
  if (!listEl) return;

  listEl.innerHTML = STEPS.map((label, i) => {
    const n           = i + 1;
    const isAuto      = n === 2; // step 2 is always auto-set; user cannot navigate to it
    const isCurrent   = n === wiz.step;
    const isCompleted = wiz.completed.includes(n);
    let cls = 'wizard-step-item';
    if (isCurrent)                    cls += ' is-current';
    if (isCompleted && !isAuto)       cls += ' is-completed';
    if (isAuto && isCompleted)        cls += ' is-auto-done';
    const indicator = (isAuto && isCompleted) ? '✓'
      : isCurrent   ? '→'
      : isCompleted  ? '✓'
      : ' ';
    return `<li class="${cls}" data-step="${n}">
      <span class="wizard-step-indicator">${indicator}</span>
      <span>${n}. ${label}</span>
    </li>`;
  }).join('');

  listEl.querySelectorAll('[data-step]').forEach(item => {
    item.addEventListener('click', () => {
      const n = parseInt(item.dataset.step);
      if (n === 2) return; // auto step — not manually navigable
      if (wiz.completed.includes(n) && n !== wiz.step) goToStep(n);
    });
  });

  const counter = document.getElementById('wiz-mobile-counter');
  if (counter) counter.textContent = t('wiz.stepOf', { n: wiz.step, total: TOTAL_STEPS });
}

function renderContent() {
  const el = document.getElementById('wiz-content');
  if (!el) return;
  el.innerHTML = '<div class="wizard-content-inner" id="wiz-inner"></div>';
  const inner    = document.getElementById('wiz-inner');
  const renderers = [renderStep1, renderStep2, renderStep3, renderStep4, renderStep5, renderStep6];
  renderers[wiz.step - 1](inner);
}

// ─── Navigation ──────────────────────────────────────────────────────────────

function goNext() {
  if (!wiz.completed.includes(wiz.step)) wiz.completed.push(wiz.step);

  if (wiz.step === 1) {
    // Auto-complete step 2: format is locked to multi_stage, skip directly to step 3
    if (!wiz.completed.includes(2)) wiz.completed.push(2);
    wiz.step = 3;
  } else {
    wiz.step++;
  }

  updateSidebar();
  renderContent();
}

function goBack() {
  // Step 2 is auto — stepping back from step 3 returns to step 1
  if (wiz.step === 3) {
    wiz.step = 1;
  } else if (wiz.step > 1) {
    wiz.step--;
  }
  updateSidebar();
  renderContent();
}

function goToStep(n) {
  wiz.step = n;
  updateSidebar();
  renderContent();
}

function attachNav(el, validator, saveData) {
  el.querySelector('#wiz-back')?.addEventListener('click', () => goBack());
  el.querySelector('#wiz-next')?.addEventListener('click', () => {
    el.querySelectorAll('.form-error').forEach(e => { e.textContent = ''; });
    const fields    = validator ? validator() : {};
    const hasErrors = Object.values(fields).some(v => v !== '');
    if (hasErrors) {
      Object.entries(fields).forEach(([k, v]) => {
        if (v) { const e = document.getElementById('err-' + k); if (e) e.textContent = v; }
      });
      return;
    }
    if (saveData) saveData();
    goNext();
  });
}

function navHtml(showBack = true) {
  return `<div class="wizard-nav">
    ${showBack ? `<button class="btn btn-ghost btn-sm" id="wiz-back">${t('wiz.back')}</button>` : ''}
    <button class="btn btn-primary btn-sm" id="wiz-next">${t('wiz.next')}</button>
  </div>`;
}

// ─── Step 1 — Name + Sport ───────────────────────────────────────────────────

function renderStep1(el) {
  const { name, sport } = wiz.data;
  const sportOptions = SPORT_OPTIONS.map(s =>
    `<option value="${esc(s)}"${s === sport ? ' selected' : ''}>${esc(s)}</option>`
  ).join('');

  el.innerHTML = `
    <div class="wizard-step-title">${t('wiz.step1')}</div>
    <div class="form-group">
      <label style="font-size:11px;color:var(--text-muted)">${t('wiz.tournamentNameLabel')}</label>
      <input type="text" id="f-name" value="${esc(name)}" maxlength="80" placeholder="AHS Sportfest 2026">
      <div class="form-error" id="err-name"></div>
    </div>
    <div class="form-group">
      <label style="font-size:11px;color:var(--text-muted)">${t('wiz.sportLabel')}</label>
      <select id="f-sport">
        <option value="" disabled${!sport ? ' selected' : ''}>${t('wiz.sport.select')}</option>
        ${sportOptions}
      </select>
      <div class="form-error" id="err-sport"></div>
    </div>
    ${navHtml(false)}
  `;

  attachNav(el, validateStep1, () => {
    wiz.data.name  = document.getElementById('f-name').value.trim();
    wiz.data.sport = document.getElementById('f-sport').value;
  });
}

function validateStep1() {
  const name  = document.getElementById('f-name').value.trim();
  const sport = document.getElementById('f-sport').value;
  const f = {};
  if (!name)                f.name  = t('wiz.nameRequired');
  else if (name.length < 2) f.name  = t('wiz.nameMin');
  else if (name.length > 80) f.name = t('wiz.nameMax');
  if (!sport)               f.sport = t('wiz.sportRequired');
  return f;
}

// ─── Step 2 — Auto-skipped (format locked to multi_stage) ───────────────────
// Navigation from step 1 jumps directly to step 3; this function is unreachable
// via normal flow but exists as a fallback if goToStep(2) is called.

function renderStep2(el) {
  el.innerHTML = `
    <div class="wizard-step-title">${t('wiz.step2')}</div>
    <p class="text-muted" style="font-size:13px">${t('wiz.format.preset')}</p>
    ${navHtml(true)}
  `;
  attachNav(el, null, null);
}

// ─── Step 3 — Read-only locked config ───────────────────────────────────────

function renderStep3(el) {
  el.innerHTML = `
    <div class="wizard-step-title">${t('wiz.step3')}</div>
    <div class="wizard-review-section" style="margin-bottom:0">
      <div class="wizard-review-label">${t('wiz.format.preset')}</div>
      <div class="wizard-review-value" style="display:block;margin-top:10px;font-size:13px;color:var(--text-secondary)">
        <div style="margin-bottom:6px">
          <strong>${t('wiz.stageLabel', { n: 1 })}:</strong>
          ${formatLabel('round_robin')} — 2 Gruppen à ${GROUP_MAX} Teams, Punkte 3/1/0
        </div>
        <div>
          <strong>${t('wiz.stageLabel', { n: 2 })}:</strong>
          ${formatLabel('single_elim')} — Kreuz-Seeding (1A/4B, 2A/3B, 2B/3A, 1B/4A)
        </div>
      </div>
    </div>
    ${navHtml(true)}
  `;
  attachNav(el, null, null);
}

// ─── Step 4 — Read-only locked visibility ───────────────────────────────────

function renderStep4(el) {
  el.innerHTML = `
    <div class="wizard-step-title">${t('wiz.step4')}</div>
    <div class="wizard-review-section" style="margin-bottom:0">
      <div class="wizard-review-label">${t('wiz.visibility')}</div>
      <div class="wizard-review-value">${t('wiz.visibility.preset')}</div>
    </div>
    ${navHtml(true)}
  `;
  attachNav(el, null, null);
}

// ─── Step 5 — Group assignment ───────────────────────────────────────────────

function renderStep5(el) {
  el.innerHTML = `
    <div class="wizard-step-title">${t('wiz.step5')}</div>

    <div class="form-group">
      <label style="font-size:11px;color:var(--text-muted)">${t('wiz.searchTeams')}</label>
      <input type="text" id="wiz-team-search" placeholder="${t('wiz.searchPH')}" autocomplete="off">
    </div>
    <div id="wiz-team-results"></div>

    <div class="wiz-groups-row">
      <div class="wiz-group-col">
        <div class="wiz-group-header">
          <span>Gruppe A</span>
          <span id="slot-a" class="wiz-group-slot">${wiz.data.groupA.length}/${GROUP_MAX}</span>
        </div>
        <div id="wiz-group-a" class="wiz-group-list"></div>
      </div>
      <div class="wiz-group-col">
        <div class="wiz-group-header">
          <span>Gruppe B</span>
          <span id="slot-b" class="wiz-group-slot">${wiz.data.groupB.length}/${GROUP_MAX}</span>
        </div>
        <div id="wiz-group-b" class="wiz-group-list"></div>
      </div>
    </div>

    <div id="err-groups" class="form-error" style="margin-top:8px"></div>

    <div class="wizard-nav">
      <button class="btn btn-ghost btn-sm" id="wiz-back">${t('wiz.back')}</button>
      <button class="btn btn-primary btn-sm" id="wiz-next">${t('wiz.next')}</button>
    </div>
  `;

  renderGroupLists();

  document.getElementById('wiz-back')?.addEventListener('click', () => goBack());
  document.getElementById('wiz-next')?.addEventListener('click', () => {
    const err = validateStep5();
    document.getElementById('err-groups').textContent = err;
    if (!err) goNext();
  });

  loadTeamsForStep5();
}

function validateStep5() {
  if (wiz.data.groupA.length < 4) return t('wiz.group.error');
  if (wiz.data.groupB.length < 4) return t('wiz.group.error');
  return '';
}

let _searchTimer = null;

function loadTeamsForStep5() {
  const searchEl = document.getElementById('wiz-team-search');
  if (!searchEl) return;
  fetchTeamResults('');
  searchEl.addEventListener('input', () => {
    clearTimeout(_searchTimer);
    _searchTimer = setTimeout(() => fetchTeamResults(searchEl.value.trim()), 250);
  });
}

async function fetchTeamResults(query) {
  const resultsEl = document.getElementById('wiz-team-results');
  if (!resultsEl) return;

  resultsEl.innerHTML = `<div class="text-muted" style="font-size:12px;padding:4px 0">${t('wiz.loading')}</div>`;

  let teams;
  try {
    const sport = wiz.data.sport;
    const url   = `/teams/search?sport=${encodeURIComponent(sport)}&q=${encodeURIComponent(query)}`;
    teams = await api('GET', url);
  } catch {
    resultsEl.innerHTML = `<div class="text-muted">${t('wiz.noTeamsFound')}</div>`;
    return;
  }

  // Merge returned teams into the local lookup map used for name display
  for (const tm_ of teams) {
    if (!wiz.data.teams.find(x => x.id === tm_.id)) wiz.data.teams.push(tm_);
  }

  renderTeamResults(teams);
}

function renderTeamResults(teams) {
  const resultsEl = document.getElementById('wiz-team-results');
  if (!resultsEl) return;

  const inUse    = new Set([...wiz.data.groupA, ...wiz.data.groupB]);
  const filtered = teams.filter(tm_ => !inUse.has(tm_.id));

  if (filtered.length === 0) {
    resultsEl.innerHTML = `<div class="text-muted" style="font-size:12px;padding:6px 0">${t('wiz.noTeamsFound')}</div>`;
    return;
  }

  const fullA = wiz.data.groupA.length >= GROUP_MAX;
  const fullB = wiz.data.groupB.length >= GROUP_MAX;

  resultsEl.innerHTML = filtered.map(tm_ => `
    <div class="wiz-team-result-row">
      <span class="wiz-team-result-name">${esc(tm_.name)}</span>
      <span class="wiz-team-result-actions">
        <button class="btn btn-ghost btn-sm" data-add-a="${tm_.id}"
          ${fullA ? `disabled title="${t('wiz.group.full')}"` : ''}>${t('wiz.group.addA')}</button>
        <button class="btn btn-ghost btn-sm" data-add-b="${tm_.id}"
          ${fullB ? `disabled title="${t('wiz.group.full')}"` : ''}>${t('wiz.group.addB')}</button>
      </span>
    </div>`).join('');

  resultsEl.querySelectorAll('[data-add-a]').forEach(btn => {
    btn.addEventListener('click', () => {
      const id = parseInt(btn.dataset.addA);
      if (wiz.data.groupA.length < GROUP_MAX && !wiz.data.groupA.includes(id)) {
        wiz.data.groupA.push(id);
        updateSlot('a');
        renderGroupList('a', wiz.data.groupA);
        fetchTeamResults(document.getElementById('wiz-team-search')?.value.trim() || '');
      }
    });
  });

  resultsEl.querySelectorAll('[data-add-b]').forEach(btn => {
    btn.addEventListener('click', () => {
      const id = parseInt(btn.dataset.addB);
      if (wiz.data.groupB.length < GROUP_MAX && !wiz.data.groupB.includes(id)) {
        wiz.data.groupB.push(id);
        updateSlot('b');
        renderGroupList('b', wiz.data.groupB);
        fetchTeamResults(document.getElementById('wiz-team-search')?.value.trim() || '');
      }
    });
  });
}

function updateSlot(group) {
  const n  = group === 'a' ? wiz.data.groupA.length : wiz.data.groupB.length;
  const el = document.getElementById(`slot-${group}`);
  if (el) el.textContent = `${n}/${GROUP_MAX}`;
}

function renderGroupLists() {
  renderGroupList('a', wiz.data.groupA);
  renderGroupList('b', wiz.data.groupB);
}

function renderGroupList(group, ids) {
  const el = document.getElementById(`wiz-group-${group}`);
  if (!el) return;
  const map = Object.fromEntries((wiz.data.teams || []).map(tm_ => [tm_.id, tm_]));

  if (ids.length === 0) {
    el.innerHTML = `<div class="wiz-group-empty">${group === 'a' ? 'Noch keine Teams in Gruppe A' : 'Noch keine Teams in Gruppe B'}</div>`;
    return;
  }

  el.innerHTML = ids.map((id, idx) => `
    <div class="wiz-group-team-row" draggable="true" data-gpos="${idx}" data-gid="${id}">
      <span class="wiz-group-num">${idx + 1}</span>
      <span class="wiz-group-name">${esc(map[id]?.name ?? '?')}</span>
      <button class="btn btn-ghost btn-sm" style="flex-shrink:0" data-remove="${group}-${idx}">${t('wiz.group.remove')}</button>
    </div>`).join('');

  el.querySelectorAll('[data-remove]').forEach(btn => {
    btn.addEventListener('click', () => {
      const parts = btn.dataset.remove.split('-');
      const g     = parts[0];
      const i     = parseInt(parts[1]);
      if (g === 'a') wiz.data.groupA.splice(i, 1);
      else           wiz.data.groupB.splice(i, 1);
      updateSlot(g);
      renderGroupList(g, g === 'a' ? wiz.data.groupA : wiz.data.groupB);
      renderTeamResults(document.getElementById('wiz-team-search')?.value.trim().toLowerCase() || '');
    });
  });

  attachDragDrop(el, '.wiz-group-team-row', 'gpos', (from, to) => {
    const arr = group === 'a' ? wiz.data.groupA : wiz.data.groupB;
    const [moved] = arr.splice(from, 1);
    arr.splice(to, 0, moved);
    renderGroupList(group, arr);
  });
}

// ─── Step 6 — Review & publish ───────────────────────────────────────────────

function renderStep6(el) {
  const { name, sport, groupA, groupB, teams } = wiz.data;
  const map = Object.fromEntries(teams.map(tm_ => [tm_.id, tm_]));

  const teamNames = ids => ids.length
    ? ids.map(id => esc(map[id]?.name ?? '?')).join(' · ')
    : `<span style="color:var(--text-muted)">—</span>`;

  const qfHtml = buildQFPreview(groupA, groupB, map);

  el.innerHTML = `
    <div class="wizard-step-title">${t('wiz.reviewTitle')}</div>

    <div class="wizard-review-section">
      <div class="wizard-review-label">${t('wiz.reviewName')}</div>
      <div class="wizard-review-value">${esc(name)}<button class="wizard-review-edit" data-goto="1">${t('wiz.editBtn')}</button></div>
    </div>
    <div class="wizard-review-section">
      <div class="wizard-review-label">${t('wiz.reviewSport')}</div>
      <div class="wizard-review-value">${esc(sport)}<button class="wizard-review-edit" data-goto="1">${t('wiz.editBtn')}</button></div>
    </div>
    <div class="wizard-review-section">
      <div class="wizard-review-label">${t('wiz.reviewFormat')}</div>
      <div class="wizard-review-value">${formatLabel('multi_stage')}</div>
    </div>
    <div class="wizard-review-section">
      <div class="wizard-review-label">Gruppe A (${groupA.length})</div>
      <div class="wizard-review-value" style="font-size:13px">
        ${teamNames(groupA)}<button class="wizard-review-edit" data-goto="5">${t('wiz.editBtn')}</button>
      </div>
    </div>
    <div class="wizard-review-section">
      <div class="wizard-review-label">Gruppe B (${groupB.length})</div>
      <div class="wizard-review-value" style="font-size:13px">
        ${teamNames(groupB)}<button class="wizard-review-edit" data-goto="5">${t('wiz.editBtn')}</button>
      </div>
    </div>
    ${qfHtml ? `
    <div class="wizard-review-section">
      <div class="wizard-review-label">${t('wiz.qf.preview')}</div>
      <div class="wizard-review-value" style="font-size:13px">${qfHtml}</div>
    </div>` : ''}

    <div id="pub-error" class="alert-error" style="display:none;margin-top:16px"></div>

    <div class="wizard-nav">
      <button class="btn btn-ghost btn-sm" id="wiz-back">${t('wiz.back')}</button>
      <button class="btn btn-primary" id="wiz-publish">${t('wiz.publish')}</button>
    </div>
  `;

  el.querySelectorAll('[data-goto]').forEach(btn => {
    btn.addEventListener('click', () => goToStep(parseInt(btn.dataset.goto)));
  });
  document.getElementById('wiz-back').addEventListener('click', () => goBack());
  document.getElementById('wiz-publish').addEventListener('click', () => doPublish(el));
}

// Cross-bracket QF preview: 1A vs 4B · 2A vs 3B · 2B vs 3A · 1B vs 4A
function buildQFPreview(groupA, groupB, map) {
  if (groupA.length < 4 || groupB.length < 4) return null;
  const n = id => esc(map[id]?.name ?? '?');
  return [
    [n(groupA[0]), n(groupB[3])],
    [n(groupA[1]), n(groupB[2])],
    [n(groupB[1]), n(groupA[2])],
    [n(groupB[0]), n(groupA[3])],
  ].map(([h, a]) =>
    `<span class="wiz-qf-match">${h} <span class="text-muted">vs</span> ${a}</span>`
  ).join('<span class="text-muted"> · </span>');
}

// ─── Publish sequence ────────────────────────────────────────────────────────

async function doPublish(el) {
  const publishBtn = document.getElementById('wiz-publish');
  const backBtn    = document.getElementById('wiz-back');
  const errorEl    = document.getElementById('pub-error');

  publishBtn.disabled    = true;
  publishBtn.textContent = t('wiz.publishing');
  if (backBtn) backBtn.disabled = true;
  errorEl.style.display  = 'none';

  const navEl = el.querySelector('.wizard-nav');
  if (navEl) {
    const prog = document.createElement('div');
    prog.id = 'pub-prog-wrap';
    prog.innerHTML = `<div class="wizard-pub-progress"><div class="wizard-pub-progress-fill" id="pub-fill"></div></div>`;
    navEl.parentNode.insertBefore(prog, navEl);
  }

  const setProg = pct => {
    const fill = document.getElementById('pub-fill');
    if (fill) fill.style.width = pct + '%';
  };

  const { name, sport, format, formatConfig, visibility, groupA, groupB } = wiz.data;
  const seeding    = [...groupA, ...groupB];
  const hasTeams   = seeding.length > 0;
  // 1 create + 1 config + N add-team + 1 seed
  const totalSteps = 2 + (hasTeams ? seeding.length + 1 : 0);

  let done = 0;
  const tick = () => setProg(Math.round((++done / totalSteps) * 100));

  let tournamentId;

  try {
    const created = await api('POST', '/tournaments/create', {
      name, sport,
      description: null,
      format, visibility,
    });
    tournamentId = created.tournament_id;
    tick();

    await api('POST', `/tournament/${tournamentId}/config`, {
      format,
      format_config: formatConfig,
    });
    tick();

    if (hasTeams) {
      for (const id of seeding) {
        await api('POST', `/tournament/${tournamentId}/teams/add`, { team_id: id });
        tick();
      }

      await api('POST', `/tournament/${tournamentId}/seed`, {
        mode:     'manual',
        team_ids: seeding,
      });
      tick();
    }

    setProg(100);
    toast(t('wiz.created'), 'success');
    publishBtn.textContent = '✓';
    await new Promise(r => setTimeout(r, 600));
    navigate('/tournament/' + tournamentId);

  } catch (err) {
    document.getElementById('pub-prog-wrap')?.remove();
    publishBtn.disabled    = false;
    publishBtn.textContent = t('wiz.publish');
    if (backBtn) backBtn.disabled = false;

    let msg = err.message || t('wiz.publishFailed');
    if (tournamentId) {
      msg += ` &nbsp;<span style="color:var(--text-muted)">—</span>&nbsp;`;
    }
    errorEl.innerHTML     = msg;
    errorEl.style.display = 'block';

    if (tournamentId) {
      const goBtn = document.createElement('button');
      goBtn.className        = 'btn btn-ghost btn-sm';
      goBtn.style.marginLeft = '4px';
      goBtn.textContent      = t('wiz.goToTournament');
      goBtn.addEventListener('click', () => navigate('/tournament/' + tournamentId));
      errorEl.appendChild(goBtn);
    }
  }
}

// ─── Drag-and-drop helper ────────────────────────────────────────────────────

function attachDragDrop(listEl, selector, attrKey, onReorder) {
  if (!listEl) return;
  let dragFrom = null;

  listEl.querySelectorAll(selector).forEach(row => {
    row.addEventListener('dragstart', e => {
      dragFrom = parseInt(row.dataset[attrKey] ?? row.getAttribute(`data-${attrKey}`));
      e.dataTransfer.effectAllowed = 'move';
      setTimeout(() => row.style.opacity = '0.4', 0);
    });
    row.addEventListener('dragend', () => {
      row.style.opacity = '';
      listEl.querySelectorAll(selector).forEach(r => r.classList.remove('is-drag-over'));
    });
    row.addEventListener('dragover', e => {
      e.preventDefault();
      listEl.querySelectorAll(selector).forEach(r => r.classList.remove('is-drag-over'));
      row.classList.add('is-drag-over');
    });
    row.addEventListener('dragleave', () => row.classList.remove('is-drag-over'));
    row.addEventListener('drop', e => {
      e.preventDefault();
      row.classList.remove('is-drag-over');
      const dragTo = parseInt(row.dataset[attrKey] ?? row.getAttribute(`data-${attrKey}`));
      if (dragFrom !== null && dragTo !== null && dragFrom !== dragTo) {
        onReorder(dragFrom, dragTo);
      }
      dragFrom = null;
    });
  });
}

// ─── Utilities ───────────────────────────────────────────────────────────────

function isPow2(n)   { return n > 0 && (n & (n - 1)) === 0; }
function nextPow2(n) { let p = 1; while (p < n) p <<= 1; return p; }
function log2ceil(n) { return Math.ceil(Math.log2(Math.max(n, 1))); }

function esc(s) {
  return String(s ?? '')
    .replace(/&/g, '&amp;').replace(/</g, '&lt;')
    .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

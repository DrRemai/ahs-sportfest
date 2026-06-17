// SCHOOL: i18n — all strings translated to German
import { api, setApp, state, animateCounter } from '../app.js';
import { navigate, link } from '../router.js';
import { badge } from '../components/badge.js';
import { Skeleton } from '../components/skeleton.js';
import { Empty } from '../components/empty.js';
import { sseOn } from '../utils/sse-client.js';
import { t, tn, statusLabel, formatLabel } from '../i18n.js';

export async function homeView() {
  setApp(Skeleton.homeList());

  const params = new URLSearchParams(location.search);
  const q     = params.get('q')     ?? '';
  const sport = params.get('sport') ?? '';

  const qs = new URLSearchParams();
  if (q)     qs.set('q', q);
  if (sport) qs.set('sport', sport);

  const data = await api('GET', '/tournaments?' + qs.toString());
  const tournaments = data.tournaments;

  const canCreate = !!state.user;

  setApp(`
    <div class="page-header">
      <h1 class="page-title">${t('home.title')}</h1>
      ${canCreate ? link('/tournament/create', t('home.newTournament'), 'btn btn-primary btn-sm') : ''}
    </div>

    <form class="filter-bar" id="home-filter">
      <input type="text" name="q" value="${esc(q)}" placeholder="${t('home.searchPlaceholder')}" style="flex:1;min-width:160px">
      <input type="text" name="sport" value="${esc(sport)}" placeholder="${t('home.sportPlaceholder')}" style="width:140px">
      <button type="submit" class="btn btn-secondary btn-sm">${t('home.filter')}</button>
      ${q || sport ? `<a href="/" data-link class="btn btn-ghost btn-sm">${t('home.clear')}</a>` : ''}
    </form>

    ${tournaments.length === 0
      ? Empty.state(t('home.noTournaments'))
      : `<p class="tournament-count">
           <span id="stat-total">0</span>&nbsp;${tn('home.countSingular', 'home.countPlural', tournaments.length).replace(/^\d+\s*/, '')}
         </p>
         <div class="tournament-grid">${tournaments.map(tournamentCard).join('')}</div>`
    }
  `);

  if (tournaments.length > 0) {
    animateCounter(document.getElementById('stat-total'), tournaments.length);
  }

  document.getElementById('home-filter')?.addEventListener('submit', e => {
    e.preventDefault();
    const fd = new FormData(e.target);
    const qs = new URLSearchParams();
    const qv = fd.get('q')?.trim();
    const sv = fd.get('sport')?.trim();
    if (qv) qs.set('q', qv);
    if (sv) qs.set('sport', sv);
    navigate('/?' + qs.toString());
  });
}

function tournamentCard(t_) {
  // is_featured badge removed for school deployment — re-enable if needed
  return `
    <div class="tournament-card"
      data-link-card="${t_.id}"
      role="article"
      tabindex="0"
      aria-label="${esc(t_.name)} — ${esc(t_.sport)}, ${statusLabel(t_.status)}">
      <div>
        <span class="tc-name">${esc(t_.name)}</span>
      </div>
      <div class="tc-meta">
        <span>${esc(t_.sport)}</span>
        <span>${formatLabel(t_.format)}</span>
      </div>
      <div class="tc-footer">
        <span class="tc-organiser">by ${esc(t_.organiser_name)}</span>
        ${badge(t_.status)}
      </div>
    </div>`;
}

document.addEventListener('click', e => {
  const card = e.target.closest('[data-link-card]');
  if (!card) return;
  navigate('/tournament/' + card.dataset.linkCard);
});

document.addEventListener('keydown', e => {
  if (e.key !== 'Enter' && e.key !== ' ') return;
  const card = e.target.closest('[data-link-card]');
  if (!card) return;
  e.preventDefault();
  navigate('/tournament/' + card.dataset.linkCard);
});

function esc(s) {
  return String(s ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

// ---------------------------------------------------------------------------
// SSE — update tournament card badge when status changes
// ---------------------------------------------------------------------------
sseOn('tournament_update', payload => {
  const card = document.querySelector(`[data-link-card="${payload.id}"]`);
  if (!card) return;

  const footer = card.querySelector('.tc-footer');
  if (footer) {
    const b = footer.querySelector('.badge');
    if (b) {
      const tmp = document.createElement('span');
      tmp.innerHTML = badge(payload.status);
      b.replaceWith(tmp.firstElementChild);
    }
  }

  // is_featured control removed for school deployment — re-enable if needed
});

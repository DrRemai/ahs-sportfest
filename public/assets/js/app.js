import { initRouter, addRoute, navigate } from './router.js';
import { mountNav } from './components/nav.js';
import { sseConnect, sseOn } from './utils/sse-client.js';
import { homeView }       from './views/home.js';
import { loginView }      from './views/login.js';
import { registerView }   from './views/register.js';
import { tournamentView } from './views/tournament.js';
import { teamView }       from './views/team.js';
import { adminView }      from './views/admin.js';
import { createTeamView }        from './views/create-team.js';
import { createTournamentView }   from './views/create-tournament.js';
import { accountView }    from './views/account.js';

export let state = {
  user: null,
  csrf: null,
};

export async function api(method, path, body = null) {
  const opts = {
    method,
    credentials: 'same-origin',
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  };
  if (body !== null) {
    opts.headers['Content-Type'] = 'application/json';
    opts.headers['X-CSRF-Token'] = state.csrf ?? '';
    opts.body = JSON.stringify(body);
  }
  let res;
  try {
    res = await fetch('/api' + path, opts);
  } catch (_) {
    const err = new Error('Network error');
    err.statusCode = 0;
    throw err;
  }

  const data = await res.json();
  if (!data.ok) {
    const err = new Error(data.error || 'Request failed');
    err.statusCode = res.status;
    if (data.fields)  err.fields  = data.fields;
    if (data.detail)  err.detail  = data.detail;
    throw err;
  }
  return data.data;
}

export function setApp(html) {
  document.getElementById('app').innerHTML = html;
}

export function animateCounter(el, target, duration = 800) {
  const observer = new IntersectionObserver(([entry]) => {
    if (!entry.isIntersecting) return;
    observer.disconnect();
    const start = performance.now();
    function step(now) {
      const t = Math.min((now - start) / duration, 1);
      const eased = 1 - Math.pow(1 - t, 3);
      el.textContent = Math.round(eased * target);
      if (t < 1) requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
  });
  observer.observe(el);
}

async function boot() {
  try {
    const me = await api('GET', '/me');
    state.user = me.user;
    state.csrf = me.csrf;
  } catch (_) {
    state.user = null;
    state.csrf = null;
  }

  mountNav();
  initSSE();

  addRoute('/',                                  () => homeView());
  addRoute('/login',                             () => loginView());
  // SCHOOL: registration disabled — re-enable by restoring this route and the nav link
  // addRoute('/register',                       () => registerView());
  addRoute('/create-team',                       () => createTeamView());
  addRoute('/tournament/create',                 () => createTournamentView());
  addRoute('/account',                           () => accountView());
  addRoute('/admin',                             () => adminView());
  addRoute(/^\/tournament\/(?<id>\d+)$/,         ({ id }) => tournamentView(parseInt(id)));
  addRoute(/^\/team\/(?<id>\d+)$/,               ({ id }) => teamView(parseInt(id)));

  initRouter();
}

function initSSE() {
  if (!state.user) return;
  sseConnect();
  sseOn('connected', () => console.log('[SSE] connected'));
}

const savedTheme = localStorage.getItem('theme');
if (savedTheme) document.documentElement.dataset.theme = savedTheme;

boot();

import { Progress } from './components/progress.js';
import { AppError } from './components/error.js';

const _routes = [];
let _currentPath = null;
let _onNavigate  = null;
let _cancelPrev  = null;

export function addRoute(pattern, handler) {
  _routes.push({ pattern, handler });
}

export function onNavigate(fn) {
  _onNavigate = fn;
}

export function navigate(path, push = true) {
  if (push && path !== location.pathname + location.search) {
    history.pushState(null, '', path);
  }
  _currentPath = path;
  if (_onNavigate) _onNavigate(path);
  _dispatch(path);
}

export function link(path, label, cls = '') {
  const c = cls ? ` class="${cls}"` : '';
  return `<a href="${path}" data-link${c}>${label}</a>`;
}

function _dispatch(fullPath) {
  const [path] = fullPath.split('?');
  for (const { pattern, handler } of _routes) {
    if (typeof pattern === 'string') {
      if (pattern === path) { _runWithEffects(() => handler({}), fullPath); return; }
    } else {
      const m = path.match(pattern);
      if (m) { _runWithEffects(() => handler(m.groups ?? {}), fullPath); return; }
    }
  }
  // Catch-all: no route matched — run through transition wrapper for consistency
  _runWithEffects(() => {
    _setApp(AppError.page(404, 'page not found'));
  }, fullPath);
}

function _setApp(html) {
  const el = document.getElementById('app');
  if (el) el.innerHTML = html;
}

async function _runWithEffects(viewFn, path) {
  // Cancel any in-flight navigation
  if (_cancelPrev) _cancelPrev();
  let cancelled = false;
  _cancelPrev = () => { cancelled = true; };

  Progress.start();

  const app = document.getElementById('app');
  if (!app) {
    // DOM not ready — run plain
    try {
      await viewFn();
      if (!cancelled) Progress.finish();
    } catch (e) {
      if (!cancelled) { Progress.fail(); _handleRouteError(e, path); }
    }
    return;
  }

  // 1. Fade out existing content
  app.classList.add('page-leaving');
  await _waitTransition(app, 140);
  if (cancelled) return;
  app.classList.remove('page-leaving');

  // 2. Mark entering — view's synchronous setApp() lands here at opacity:0
  app.classList.add('page-entering');

  // 3. Call view (synchronous part runs now, e.g. setApp(skeleton))
  let viewPromise;
  try {
    viewPromise = viewFn();
  } catch (e) {
    app.classList.remove('page-entering');
    Progress.fail();
    _handleRouteError(e, path);
    return;
  }

  // 4. Trigger fade-in on next paint
  requestAnimationFrame(() => requestAnimationFrame(() => {
    if (!cancelled) app.classList.remove('page-entering');
  }));

  // 5. Await async fetch/render
  if (viewPromise && typeof viewPromise.then === 'function') {
    try {
      await viewPromise;
      if (!cancelled) Progress.finish();
    } catch (e) {
      if (cancelled) return;
      Progress.fail();
      _handleRouteError(e, path);
    }
  } else {
    if (!cancelled) Progress.finish();
  }

  if (!cancelled) _cancelPrev = null;
}

function _waitTransition(el, maxMs) {
  return new Promise(resolve => {
    const timer = setTimeout(resolve, maxMs);
    el.addEventListener('transitionend', () => {
      clearTimeout(timer);
      resolve();
    }, { once: true });
  });
}

function _handleRouteError(e, path) {
  const code = e.statusCode ?? 500;
  if (code === 401) {
    navigate('/login?redirect=' + encodeURIComponent(path));
    return;
  }
  const msg = code === 403 ? 'access denied'
            : code === 404 ? 'not found'
            : 'something went wrong';
  _setApp(AppError.page(code === 403 ? 403 : code === 404 ? 404 : 500, msg));
}

export function initRouter() {
  document.addEventListener('click', e => {
    const a = e.target.closest('[data-link]');
    if (!a) return;
    e.preventDefault();
    navigate(a.getAttribute('href'));
  });

  window.addEventListener('popstate', () => {
    navigate(location.pathname + location.search, false);
  });

  navigate(location.pathname + location.search, false);
}

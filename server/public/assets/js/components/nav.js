// SCHOOL: i18n — rebranded to AHS Sportfest, all strings translated to German
import { state, api } from '../app.js';
import { navigate, link } from '../router.js';
import { toast } from './toast.js';
import { trapFocus, releaseFocus } from '../utils/focus-trap.js';
import { sseOn, registerReconnectIndicator } from '../utils/sse-client.js';
import { t } from '../i18n.js';

window.addEventListener('scroll', () => {
  const tval = Math.min(window.scrollY / 80, 1);
  document.getElementById('nav-bar')?.style.setProperty('--nav-scroll', tval);
}, { passive: true });

function toggleTheme() {
  const root = document.documentElement;
  const next = root.dataset.theme === 'light' ? 'dark' : 'light';
  root.dataset.theme = next;
  localStorage.setItem('theme', next);
  renderNav();
}

let _unreadCount   = 0;
let _notifications = [];
let _prevCount     = 0;

export function mountNav() {
  renderNav();
}

function renderNav() {
  const nav = document.getElementById('nav-bar');
  if (!nav) return;

  const isAdmin = state.user?.is_admin;
  const isLight = document.documentElement.dataset.theme === 'light';

  nav.innerHTML = `
    <a href="/" data-link class="nav-brand">${t('app.brand')}</a>
    <div class="nav-links" role="list">
      ${link('/', t('nav.tournaments'))}
      ${isAdmin ? link('/admin', t('nav.admin')) : ''}
    </div>
    <div class="nav-right">
      ${state.user ? loggedInRight() : guestRight()}
      <button class="btn btn-ghost btn-sm nav-theme-toggle" id="nav-theme-toggle"
        aria-label="${isLight ? t('nav.switchDark') : t('nav.switchLight')}">
        ${isLight ? '🌙' : '☀'}
      </button>
      <span id="nav-sse-status" class="nav-sse-status" aria-live="polite"></span>
    </div>
    <button class="nav-hamburger" id="nav-hamburger"
      aria-label="${t('nav.openMenu')}"
      aria-expanded="false"
      aria-controls="nav-mobile-menu">☰</button>
  `;

  let mobileMenu = document.getElementById('nav-mobile-menu');
  if (!mobileMenu) {
    mobileMenu = document.createElement('div');
    mobileMenu.id = 'nav-mobile-menu';
    mobileMenu.className = 'nav-mobile-menu';
    mobileMenu.setAttribute('role', 'dialog');
    mobileMenu.setAttribute('aria-modal', 'true');
    mobileMenu.setAttribute('aria-label', 'Navigationsmenü');
    mobileMenu.setAttribute('aria-hidden', 'true');
    document.getElementById('app').insertAdjacentElement('beforebegin', mobileMenu);
  }
  mobileMenu.innerHTML = mobileMenuHtml(isAdmin);

  nav.querySelector('#nav-logout')?.addEventListener('click', logout);
  nav.querySelector('#nav-notif-btn')?.addEventListener('click', openDrawer);
  nav.querySelector('#nav-theme-toggle')?.addEventListener('click', toggleTheme);

  const hamburger = nav.querySelector('#nav-hamburger');
  hamburger?.addEventListener('click', () => {
    const isOpen = mobileMenu.classList.contains('open');
    if (isOpen) closeMobileMenu(mobileMenu, hamburger);
    else        openMobileMenu(mobileMenu, hamburger);
  });

  mobileMenu.querySelectorAll('a[data-link]').forEach(a => {
    a.addEventListener('click', () => closeMobileMenu(mobileMenu, hamburger));
  });
  mobileMenu.querySelector('#mob-nav-logout')?.addEventListener('click', () => {
    closeMobileMenu(mobileMenu, hamburger);
    logout();
  });
  mobileMenu.querySelector('#mob-nav-notif')?.addEventListener('click', () => {
    closeMobileMenu(mobileMenu, hamburger);
    openDrawer();
  });

  document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && mobileMenu.classList.contains('open')) {
      closeMobileMenu(mobileMenu, hamburger);
    }
  });
}

function openMobileMenu(mobileMenu, hamburger) {
  mobileMenu.classList.add('open');
  mobileMenu.setAttribute('aria-hidden', 'false');
  hamburger.setAttribute('aria-expanded', 'true');
  hamburger.setAttribute('aria-label', t('nav.closeMenu'));
  hamburger.textContent = '✕';
  trapFocus(mobileMenu);
}

function closeMobileMenu(mobileMenu, hamburger) {
  releaseFocus(mobileMenu);
  mobileMenu.classList.remove('open');
  mobileMenu.setAttribute('aria-hidden', 'true');
  hamburger.setAttribute('aria-expanded', 'false');
  hamburger.setAttribute('aria-label', t('nav.openMenu'));
  hamburger.textContent = '☰';
}

function mobileMenuHtml(isAdmin) {
  const tourLink  = `<a href="/" data-link>${t('nav.tournaments')}</a>`;
  const adminLink = isAdmin ? `<a href="/admin" data-link>${t('nav.admin')}</a>` : '';
  if (!state.user) {
    // SCHOOL: Register link hidden for school deployment — re-enable if needed
    return `${tourLink}${adminLink}<hr>
      <a href="/login" data-link>${t('nav.signIn')}</a>`;
  }
  return `${tourLink}${adminLink}<hr>
    <a href="/account" data-link>${esc(state.user.display_name)}</a>
    <a href="/create-team" data-link>${t('nav.newTeam')}</a>
    <button id="mob-nav-notif">${t('nav.notifBtn')}${_unreadCount > 0 ? ` (${_unreadCount})` : ''}</button>
    <hr>
    <button id="mob-nav-logout">${t('nav.signOut')}</button>`;
}

function guestRight() {
  // SCHOOL: Register link hidden for school deployment — re-enable if needed
  return `${link('/login', t('nav.signIn'), 'btn btn-ghost btn-sm')}`;
}

function loggedInRight() {
  return `
    ${link('/account', esc(state.user.display_name), 'nav-user')}
    ${link('/create-team', t('nav.newTeam'), 'btn btn-ghost btn-sm')}
    ${bellHtml()}
    <button class="btn btn-ghost btn-sm" id="nav-logout">${t('nav.signOut')}</button>
  `;
}

function bellHtml() {
  const count = _unreadCount;
  const ariaLabel = count > 0
    ? (count === 1
        ? t('nav.notifUnread', { n: count })
        : t('nav.notifUnreadPlural', { n: count > 99 ? '99+' : count }))
    : t('nav.notifBtn');
  const badgeHtml = count > 0
    ? `<span class="notif-badge" aria-label="${ariaLabel}">${count > 99 ? '99+' : count}</span>`
    : '';
  return `
    <button class="notif-btn" id="nav-notif-btn" aria-label="${ariaLabel}">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="2" aria-hidden="true">
        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
        <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
      </svg>
      ${badgeHtml}
    </button>`;
}

async function logout() {
  try { await api('POST', '/logout'); } catch (_) {}
  state.user     = null;
  state.csrf     = null;
  _notifications = [];
  _unreadCount   = 0;
  _prevCount     = 0;
  renderNav();
  navigate('/');
}

function updateBell(shake = false, scaleBadge = false) {
  const btn = document.getElementById('nav-notif-btn');
  if (!btn) return;
  const tmp = document.createElement('div');
  tmp.innerHTML = bellHtml();
  const newBtn = tmp.firstElementChild;
  newBtn.addEventListener('click', openDrawer);
  btn.replaceWith(newBtn);

  if (shake) {
    newBtn.classList.add('bell-shake');
    newBtn.addEventListener('animationend', () => newBtn.classList.remove('bell-shake'), { once: true });
  }
  if (scaleBadge) {
    const badge = newBtn.querySelector('.notif-badge');
    if (badge) {
      badge.classList.add('badge-enter');
      badge.addEventListener('animationend', () => badge.classList.remove('badge-enter'), { once: true });
    }
  }
}

function updateNotifBadge(payload) {
  if (_notifications.some(n => n.id === payload.id)) return;

  _notifications.push({
    id:         payload.id,
    type:       payload.type,
    payload:    payload.payload ?? {},
    created_at: new Date().toISOString(),
  });

  const prev   = _unreadCount;
  _unreadCount = _notifications.length;
  _prevCount   = _unreadCount;

  updateBell(_unreadCount > prev, prev === 0 && _unreadCount > 0);

  const mobBtn = document.getElementById('mob-nav-notif');
  if (mobBtn) mobBtn.textContent = `${t('nav.notifBtn')}${_unreadCount > 0 ? ` (${_unreadCount})` : ''}`;
}

async function openDrawer() {
  const drawer  = document.getElementById('notif-drawer');
  const overlay = document.getElementById('notif-overlay');

  drawer.innerHTML = `
    <div class="notif-header">
      <h3>${t('notif.title')}</h3>
      <button class="btn-ghost" id="notif-close" aria-label="${t('notif.close')}">✕</button>
    </div>
    <div class="notif-list" aria-live="polite" aria-label="${t('notif.title')}">
      <div class="notif-empty" role="status">${t('notif.loading')}</div>
    </div>`;
  drawer.classList.add('open');
  drawer.removeAttribute('aria-hidden');
  overlay.classList.add('open');

  drawer.querySelector('#notif-close').addEventListener('click', closeDrawer);
  overlay.addEventListener('click', closeDrawer, { once: true });
  document.addEventListener('keydown', onEsc);
  trapFocus(drawer);

  try {
    const data   = await api('GET', '/notifications');
    _notifications = data.notifications ?? [];
    _unreadCount   = _notifications.length;
    _prevCount     = _unreadCount;

    releaseFocus(drawer);
    drawer.innerHTML = drawerHtml();
    trapFocus(drawer);
    drawer.querySelector('#notif-close')?.addEventListener('click', closeDrawer);
    drawer.querySelector('#notif-mark-all')?.addEventListener('click', markAllRead);
  } catch (_) {
    const list = drawer.querySelector('.notif-list');
    if (list) list.innerHTML = `<div class="notif-empty">${t('notif.failed')}</div>`;
  }
}

function closeDrawer() {
  const drawer  = document.getElementById('notif-drawer');
  const overlay = document.getElementById('notif-overlay');
  releaseFocus(drawer);
  drawer.classList.remove('open');
  drawer.setAttribute('aria-hidden', 'true');
  overlay.classList.remove('open');
  document.removeEventListener('keydown', onEsc);
}

function onEsc(e) {
  if (e.key === 'Escape') closeDrawer();
}

function drawerHtml() {
  const items = _notifications.length === 0
    ? `<div class="notif-empty" role="status">${t('notif.empty')}</div>`
    : _notifications.map(n => `
        <div class="notif-item" tabindex="0">
          <div class="notif-item-type">${notifTypeLabel(n.type)}</div>
          <div class="notif-item-msg">${notifMessage(n)}</div>
          <div class="notif-item-time"><time datetime="${n.created_at}">${relativeTime(n.created_at)}</time></div>
        </div>`).join('');

  return `
    <div class="notif-header">
      <h3>${t('notif.title')}</h3>
      <div style="display:flex;gap:8px;align-items:center">
        ${_notifications.length > 0
          ? `<button class="btn-ghost btn-sm" id="notif-mark-all">${t('notif.markAllRead')}</button>`
          : ''}
        <button class="btn-ghost" id="notif-close" aria-label="${t('notif.close')}">✕</button>
      </div>
    </div>
    <div class="notif-list" aria-live="polite" aria-label="${t('notif.title')}">${items}</div>
  `;
}

async function markAllRead() {
  const ids = _notifications.map(n => n.id);
  try {
    await api('POST', '/notifications/read', { ids });
    _notifications = [];
    _unreadCount   = 0;
    _prevCount     = 0;
    updateBell();
    closeDrawer();
    toast(t('notif.cleared'), 'success');
  } catch (e) {
    toast(e.message, 'error');
  }
}

function notifTypeLabel(type) {
  return {
    reevaluation_submitted:     t('notif.type.reevaluation'),
    reevaluation_resolved:      t('notif.type.reevaluation'),
    match_result_posted:        t('notif.type.matchResult'),
    team_registration_approved: t('notif.type.registration'),
    tournament_status_changed:  t('notif.type.tournament'),
    staff_assigned:             t('notif.type.staff'),
  }[type] ?? type;
}

function notifMessage(n) {
  const p = n.payload ?? {};
  switch (n.type) {
    case 'reevaluation_submitted':
      return t('notif.msg.reevalSubmitted', { match_id: p.match_id, tournament_id: p.tournament_id });
    case 'reevaluation_resolved':
      return t('notif.msg.reevalResolved', { status: p.status });
    case 'match_result_posted':
      return t('notif.msg.matchResult', { home_score: p.home_score, away_score: p.away_score });
    case 'team_registration_approved':
      return t('notif.msg.teamApproved', { tournament_id: p.tournament_id });
    case 'tournament_status_changed':
      return t('notif.msg.statusChanged', { new_status: p.new_status });
    case 'staff_assigned':
      return t('notif.msg.staffAssigned', { tournament_id: p.tournament_id });
    default:
      return JSON.stringify(p);
  }
}

function relativeTime(iso) {
  const diff = Date.now() - new Date(iso).getTime();
  const m    = Math.floor(diff / 60_000);
  if (m < 1)  return t('notif.justNow');
  if (m < 60) return t('notif.mAgo', { m });
  const h = Math.floor(m / 60);
  if (h < 24) return t('notif.hAgo', { h });
  return t('notif.dAgo', { d: Math.floor(h / 24) });
}

function esc(s) {
  return String(s ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

// ---------------------------------------------------------------------------
// SSE subscriptions
// ---------------------------------------------------------------------------

sseOn('notification', updateNotifBadge);

registerReconnectIndicator(status => {
  const el = document.getElementById('nav-sse-status');
  if (!el) return;
  if (status === 'connected') {
    el.textContent   = '';
    el.style.opacity = '0';
  } else {
    el.textContent   = t('nav.reconnecting');
    el.style.opacity = '1';
  }
});

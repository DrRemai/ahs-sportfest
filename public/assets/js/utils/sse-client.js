// SSE client — multiplexed stream for authenticated users
//
// Exported API:
//   sseConnect(tournamentIds)        — open (or reopen) the stream
//   sseSubscribeTournament(tid)      — add a tournament channel; reconnects
//   sseUnsubscribeTournament(tid)    — remove a tournament channel; reconnects
//   sseOn(eventType, handler)        — returns an unsubscribe function
//   sseOff(eventType, handler)
//   registerReconnectIndicator(fn)   — fn(status) called with 'connected' | 'reconnecting'

const BASE_DELAY  = 1000;  // ms
const BACKOFF_MULT = 1.5;
const MAX_DELAY   = 30_000; // ms

let _es           = null;   // current EventSource
let _tids         = new Set();
let _handlers     = {};     // eventType → Set<fn>
let _retryDelay   = BASE_DELAY;
let _retryTimer   = null;
let _indicators   = [];

const NAMED_EVENTS = [
  'connected', 'notification', 'match_update', 'match_created',
  'tournament_update', 'timeout', 'error',
];

function buildUrl() {
  const t = [..._tids].join(',');
  return '/sse.php' + (t ? '?t=' + encodeURIComponent(t) : '');
}

function connect() {
  if (_es) {
    _es.close();
    _es = null;
  }
  clearTimeout(_retryTimer);

  const es = new EventSource(buildUrl());
  _es = es;

  es.addEventListener('connected', e => {
    if (_es !== es) return; // stale — a newer connection was opened
    try {
      const data = JSON.parse(e.data);
      _retryDelay = BASE_DELAY;
      _notifyIndicators('connected');
      _dispatch('connected', data);
    } catch (_) {}
  });

  es.addEventListener('timeout', e => {
    if (_es !== es) return;
    // Server signaled max lifetime; reconnect immediately at base delay
    es.close();
    _es = null;
    _retryDelay = BASE_DELAY;
    _scheduleReconnect(0);
  });

  NAMED_EVENTS.filter(t => t !== 'connected' && t !== 'timeout').forEach(type => {
    es.addEventListener(type, e => {
      if (_es !== es) return;
      try { _dispatch(type, JSON.parse(e.data)); }
      catch (_) { _dispatch(type, { raw: e.data }); }
    });
  });

  es.onerror = () => {
    if (_es !== es) return; // stale reference — a fresh connect() replaced us
    es.close();
    _es = null;
    _notifyIndicators('reconnecting');
    _scheduleReconnect(_retryDelay);
    _retryDelay = Math.min(_retryDelay * BACKOFF_MULT, MAX_DELAY);
  };
}

function _scheduleReconnect(delay) {
  clearTimeout(_retryTimer);
  _retryTimer = setTimeout(connect, delay);
}

function _dispatch(type, data) {
  (_handlers[type] ?? new Set()).forEach(fn => {
    try { fn(data); } catch (_) {}
  });
}

function _notifyIndicators(status) {
  _indicators.forEach(fn => { try { fn(status); } catch (_) {} });
}

export function sseConnect(tournamentIds = []) {
  _tids = new Set(tournamentIds.map(Number).filter(Boolean));
  connect();
}

export function sseSubscribeTournament(tid) {
  if (_tids.has(tid)) return;
  _tids.add(tid);
  connect(); // reconnect to include the new tournament channel
}

export function sseUnsubscribeTournament(tid) {
  if (!_tids.has(tid)) return;
  _tids.delete(tid);
  connect(); // reconnect without this channel
}

export function sseOn(eventType, handler) {
  if (!_handlers[eventType]) _handlers[eventType] = new Set();
  _handlers[eventType].add(handler);
  return () => sseOff(eventType, handler);
}

export function sseOff(eventType, handler) {
  _handlers[eventType]?.delete(handler);
}

export function registerReconnectIndicator(fn) {
  _indicators.push(fn);
  return () => { _indicators = _indicators.filter(f => f !== fn); };
}

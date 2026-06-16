let _el = null;
let _hideTimer = null;

function bar() {
  if (!_el) _el = document.getElementById('progress-bar');
  return _el;
}

export const Progress = {
  start() {
    clearTimeout(_hideTimer);
    const b = bar();
    if (!b) return;
    b.style.transition = 'none';
    b.style.width = '0%';
    b.style.opacity = '1';
    b.style.background = 'var(--accent)';
    b.offsetWidth; // force reflow so transition reset takes effect
    b.style.transition = 'width 300ms ease-out';
    b.style.width = '70%';
  },

  finish() {
    const b = bar();
    if (!b) return;
    b.style.transition = 'width 120ms ease-out';
    b.style.width = '100%';
    _hideTimer = setTimeout(() => {
      b.style.transition = 'opacity 200ms ease';
      b.style.opacity = '0';
      setTimeout(() => { b.style.width = '0%'; }, 220);
    }, 120);
  },

  fail() {
    const b = bar();
    if (!b) return;
    b.style.background = 'var(--danger)';
    this.finish();
  },
};

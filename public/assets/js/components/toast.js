export function toast(msg, type = 'info') {
  const container = document.getElementById('toast-container');

  // A11Y: errors get assertive live region so screen readers interrupt immediately
  container.setAttribute('aria-live', type === 'error' ? 'assertive' : 'polite');

  const el = document.createElement('div');
  el.className = `toast toast-${type}`;
  el.textContent = msg;
  // A11Y: role="alert" for errors (implies assertive); role="status" for others
  el.setAttribute('role', type === 'error' ? 'alert' : 'status');
  container.appendChild(el);

  setTimeout(() => {
    el.classList.add('toast-out');
    el.addEventListener('animationend', () => el.remove(), { once: true });
  }, 3500);
}

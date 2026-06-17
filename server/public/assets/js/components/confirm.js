/**
 * Replace `triggerEl` with an inline confirm prompt.
 * Calls onConfirm() if confirmed, restores the button if cancelled.
 */
export function confirmInline(triggerEl, message, onConfirm) {
  const original = triggerEl.outerHTML;
  const wrap = document.createElement('span');
  wrap.className = 'confirm-inline';
  wrap.innerHTML =
    `<span>${message}</span>` +
    `<button class="btn btn-danger btn-sm" data-confirm-yes>Yes</button>` +
    `<button class="btn btn-ghost btn-sm" data-confirm-no>No</button>`;

  triggerEl.replaceWith(wrap);

  wrap.querySelector('[data-confirm-yes]').addEventListener('click', () => {
    wrap.replaceWith(triggerEl);
    onConfirm();
  });

  wrap.querySelector('[data-confirm-no]').addEventListener('click', () => {
    const restored = document.createElement('span');
    restored.innerHTML = original;
    wrap.replaceWith(restored.firstChild);
  });
}

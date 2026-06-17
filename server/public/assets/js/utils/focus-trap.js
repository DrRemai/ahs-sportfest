const FOCUSABLE = 'a[href],button:not([disabled]),input,select,textarea,[tabindex]:not([tabindex="-1"])';

export function trapFocus(el) {
  const nodes = el.querySelectorAll(FOCUSABLE);
  const first = nodes[0];
  const last  = nodes[nodes.length - 1];

  el._trapHandler = e => {
    if (e.key !== 'Tab') return;
    if (e.shiftKey) {
      if (document.activeElement === first) { e.preventDefault(); last?.focus(); }
    } else {
      if (document.activeElement === last)  { e.preventDefault(); first?.focus(); }
    }
  };

  el.addEventListener('keydown', el._trapHandler);
  first?.focus();
}

export function releaseFocus(el) {
  if (el._trapHandler) {
    el.removeEventListener('keydown', el._trapHandler);
    delete el._trapHandler;
  }
}

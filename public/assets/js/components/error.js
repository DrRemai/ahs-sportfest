export const AppError = {
  page(code, message) {
    return `
      <div class="error-page">
        <div class="error-code">${code}</div>
        <div class="error-message">// ${esc(message)}</div>
        <button class="btn btn-ghost" onclick="history.back()">← go back</button>
      </div>`;
  },

  inline(message) {
    return `<div class="error-inline">// error: ${esc(message)}</div>`;
  },
};

function esc(s) {
  return String(s ?? '')
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

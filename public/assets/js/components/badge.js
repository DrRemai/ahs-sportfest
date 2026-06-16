// SCHOOL: i18n — badge labels translated to German
import { statusLabel } from '../i18n.js';

export function badge(value, extra = '') {
  const label = statusLabel(value) ?? value;
  return `<span class="badge badge-${value}${extra ? ' ' + extra : ''}">${label}</span>`;
}

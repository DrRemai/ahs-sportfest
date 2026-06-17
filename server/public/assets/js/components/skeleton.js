export const Skeleton = {
  line(width = '60%', height = '12px') {
    return `<div class="skeleton-el" style="height:${height};width:${width};margin-bottom:8px;border-radius:var(--radius-md)"></div>`;
  },

  block(height = '100px') {
    return `<div class="skeleton-el" style="height:${height};width:100%;margin-bottom:8px;border-radius:var(--radius-md)"></div>`;
  },

  row(cols = 3) {
    const cells = Array.from({ length: cols }, () =>
      `<div class="skeleton-el" style="flex:1;height:12px;border-radius:var(--radius-md)"></div>`
    ).join('');
    return `<div style="display:flex;gap:12px;margin-bottom:10px">${cells}</div>`;
  },

  card() {
    return `
      <div style="background:var(--surface-1);border:1px solid rgba(255,255,255,0.06);border-radius:var(--radius-lg);padding:20px 24px;margin-bottom:16px">
        ${this.line('40%')}
        ${this.line('70%')}
        ${this.block('32px')}
      </div>`;
  },

  homeList() {
    return `<div style="padding:8px 0">
      ${this.line('22%', '20px')}
      <div style="height:28px"></div>
      <div class="card-grid">${Array.from({ length: 6 }, () => this.card()).join('')}</div>
    </div>`;
  },

  tournamentOverview() {
    return `
      <div style="margin-bottom:32px">
        ${this.line('50%', '26px')}
        ${this.line('70%', '14px')}
      </div>
      <div class="skeleton-el" style="height:40px;width:380px;margin-bottom:32px;border-radius:var(--radius-xl)"></div>
      ${this.card()}`;
  },

  bracketTab() {
    return `<div style="display:flex;gap:32px;padding-bottom:16px;overflow-x:auto">${
      Array.from({ length: 3 }, () =>
        `<div style="min-width:220px">${Array.from({ length: 4 }, () =>
          `<div style="margin:6px 0">${this.block('72px')}</div>`
        ).join('')}</div>`
      ).join('')
    }</div>`;
  },

  tableTab(rows = 6, cols = 4) {
    return `<div style="background:var(--surface-1);border:1px solid rgba(255,255,255,0.06);border-radius:var(--radius-lg);overflow:hidden">
      <div style="display:flex;gap:12px;padding:12px 16px;border-bottom:1px solid rgba(255,255,255,0.06)">
        ${Array.from({ length: cols }, () =>
          `<div class="skeleton-el" style="flex:1;height:10px;border-radius:var(--radius-md)"></div>`
        ).join('')}
      </div>
      <div style="padding:8px 0">
        ${Array.from({ length: rows }, () =>
          `<div style="padding:4px 16px">${this.row(cols)}</div>`
        ).join('')}
      </div>
    </div>`;
  },

  accountPage() {
    return `
      <div class="page-header">
        ${this.line('18%', '22px')}
      </div>
      <div class="two-col" style="max-width:900px">
        <div class="card">${this.line('28%')}${this.block('140px')}</div>
        <div class="card">${this.line('28%')}${this.tableTab(3, 3)}</div>
      </div>`;
  },

  teamPage() {
    return `
      <div class="card" style="margin-bottom:24px">
        ${this.line('40%', '28px')}
        ${this.line('18%', '14px')}
        ${this.line('80%', '13px')}
      </div>
      <div style="display:flex;justify-content:center;gap:8px;margin-bottom:24px">
        ${Array.from({ length: 2 }, () =>
          `<div class="skeleton-el" style="width:120px;height:36px;border-radius:var(--radius-xl)"></div>`
        ).join('')}
      </div>
      ${this.tableTab(5, 4)}`;
  },

  adminPage() {
    return `
      <div class="page-header">${this.line('18%', '20px')}</div>
      <div class="skeleton-el" style="height:40px;width:440px;margin-bottom:32px;border-radius:var(--radius-xl)"></div>
      ${this.tableTab(8, 5)}`;
  },
};

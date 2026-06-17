import { state } from '../app.js';
import { navigate } from '../router.js';
import { t } from '../i18n.js';

export async function showAboutPage() {
  state.aboutPageOpen = true;

  const app = document.getElementById('app');

  // Fade out existing content (same timing as router._runWithEffects)
  app.classList.add('page-leaving');
  await new Promise(resolve => {
    const timer = setTimeout(resolve, 140);
    app.addEventListener('transitionend', () => { clearTimeout(timer); resolve(); }, { once: true });
  });
  app.classList.remove('page-leaving');

  // Inject — .page-container triggers the pageIn animation automatically
  app.innerHTML = renderAboutPage();

  app.querySelector('#about-back').addEventListener('click', () => {
    state.aboutPageOpen = false;
    navigate('/');
  });
}

function renderAboutPage() {
  return `
    <div class="page-container">

      <div class="page-header" style="flex-direction:column;align-items:flex-start;gap:var(--sp-2)">
        <h1 class="page-title">${t('about.title')}</h1>
        <p style="margin:0;color:var(--text-2);font-size:15px">${t('about.subtitle')}</p>
      </div>

      <div class="card" style="margin-bottom:var(--sp-4)">
        <div class="section-title">${t('about.project.title')}</div>
        <p style="margin:0;line-height:1.65">
          Diese Plattform wurde von <strong>Rémai-Sleisz Domonkos</strong> (7BSc&nbsp;25–26)
          entwickelt, um die Organisation des jährlichen Sportfests zu vereinfachen —
          von der Gruppenphase bis zum Finale, alles live und zentral verwaltet.
          Einsehbar für die Spieler, einfacher für die Orga.
        </p>
      </div>

      <div class="card" style="margin-bottom:var(--sp-6)">
        <div class="section-title">${t('about.tech.title')}</div>
        <p style="margin-bottom:var(--sp-3);line-height:1.65">
          Gebaut mit PHP, PostgreSQL, Standard-JS und CSS — ohne externe Frameworks,
          um die Übernahme und Weiterentwicklung für die nächsten Generationen
          zu vereinfachen.
        </p>
        <p style="margin-bottom:var(--sp-3);line-height:1.65">
          Der Quellcode ist offen einsehbar:
        </p>
        <a class="about-github-link"
           href="https://github.com/DrRemai/ahs-sportfest"
           target="_blank" rel="noopener noreferrer">
          → github.com/DrRemai/ahs-sportfest
        </a>
        <p style="margin-top:var(--sp-3);margin-bottom:0;color:var(--text-2);font-size:13px">
          Künftige Änderungen bitte zum Repository beitragen!
        </p>
      </div>

      <div style="text-align:center;padding-bottom:var(--sp-8)">
        <button id="about-back" class="btn btn-primary">${t('about.back')}</button>
      </div>

    </div>`;
}

/* Mobilní menu */
(() => {
  const btn = document.querySelector('.burger'), menu = document.getElementById('menu');
  if (!btn || !menu) return;
  const set = open => { btn.setAttribute('aria-expanded', open); menu.classList.toggle('open', open); };
  btn.addEventListener('click', () => set(btn.getAttribute('aria-expanded') !== 'true'));
  menu.addEventListener('click', e => { if (e.target.closest('a')) set(false); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && menu.classList.contains('open')) { set(false); btn.focus(); } });
  // klik mimo hlavičku zavře rozbalené menu
  document.addEventListener('click', e => { if (menu.classList.contains('open') && !e.target.closest('.top')) set(false); });

  /* Přilepená hlavička: po odscrollování pod horní pruhy se zmenší (na počítači menu pod hamburgerem). */
  const top = btn.closest('.b-top');
  if (!top) return;
  const desk = matchMedia('(min-width:1001px)');
  let start = 0, stuck = false, ticking = false;
  const measure = () => {
    const prev = top.previousElementSibling;
    start = prev ? prev.getBoundingClientRect().bottom + scrollY - (parseFloat(getComputedStyle(top).top) || 0) : 0;
  };
  const upd = () => {
    ticking = false;
    const s = scrollY > start + 1;
    if (s === stuck) return;
    stuck = s;
    top.classList.toggle('is-stuck', s);
    if (!s && desk.matches && menu.classList.contains('open')) set(false);
  };
  measure(); upd();
  addEventListener('scroll', () => { if (!ticking) { ticking = true; requestAnimationFrame(upd); } }, { passive: true });
  addEventListener('resize', () => { measure(); upd(); });
  addEventListener('load', () => { measure(); upd(); });
})();

/* Odpočet do konce akce v pruhu nahoře */
(() => {
  const box = document.querySelector('[data-promo-end]'), out = box && box.querySelector('[data-promo-count]');
  if (!out) return;
  const end = Date.parse(box.dataset.promoEnd);
  if (!end) return;
  const pad = n => String(n).padStart(2, '0');
  const tick = () => {
    const left = Math.floor((end - Date.now()) / 1000);
    if (left <= 0) {
      box.hidden = true; document.querySelectorAll('.top-promo').forEach(a => a.remove());
      // Akce skončila při otevřené stránce: ceny v kalkulačce už neplatí → načíst čerstvou stránku (jen jednou).
      try { if (left > -86400 && !sessionStorage.getItem('samPromoEnd')) { sessionStorage.setItem('samPromoEnd', '1'); setTimeout(() => location.reload(), 90000 + left * 1000 > 0 ? 90000 + left * 1000 : 0); } } catch (e) {}
      return;
    }
    const d = Math.floor(left / 86400), h = Math.floor(left % 86400 / 3600), m = Math.floor(left % 3600 / 60), s = left % 60;
    out.textContent = out.dataset.label + ' ' + (d ? d + ' d ' : '') + pad(h) + ':' + pad(m) + ':' + pad(s);
    out.hidden = false;
    setTimeout(tick, 1000 - Date.now() % 1000);
  };
  tick();
})();

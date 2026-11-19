/* Mobilní menu */
(() => {
  const btn = document.querySelector('.burger'), menu = document.getElementById('menu');
  if (!btn || !menu) return;
  const set = open => { btn.setAttribute('aria-expanded', open); menu.classList.toggle('open', open); };
  btn.addEventListener('click', () => set(btn.getAttribute('aria-expanded') !== 'true'));
  menu.addEventListener('click', e => { if (e.target.closest('a')) set(false); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && menu.classList.contains('open')) { set(false); btn.focus(); } });
})();

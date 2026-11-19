/* Domovská stránka: kalkulačka vstupenek, odpočet akce, „co zkusíte“, mobilní lišta. Data: window.SAM_TICKETS (inc/tickets.php). */
(() => {
  const D = window.SAM_TICKETS, $ = id => document.getElementById(id);
  const rowsEl = $('rows');
  if (!D || !rowsEl || !D.rows.length) return;
  const T = D.t, MAX = D.max;
  const money = v => new Intl.NumberFormat(D.locale, { style: 'currency', currency: D.currency, maximumFractionDigits: v % 1 ? 2 : 0, minimumFractionDigits: v % 1 ? 2 : 0 }).format(v);
  const esc = s => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const word = n => D.lang === 'en' ? (n === 1 ? T.tickets[0] : T.tickets[1]) : (n === 1 ? T.tickets[0] : n >= 2 && n <= 4 ? T.tickets[1] : T.tickets[2]);
  const fill = (tpl, n, s) => tpl.replace('%n', n).replace('vstupenky', word(n)).replace('tickets', word(n)).replace('%s', s);

  const q = {};
  D.rows.forEach(r => { q[r.k] = r.k === 'adult' ? 2 : 0; });
  rowsEl.innerHTML = D.rows.map(r => `<li class="row"><div><b>${esc(r.n)}</b><small>${esc(r.d)}</small></div>` +
    (D.boxOffice
      ? `<span class="price">${money(r.p)}${r.r > r.p ? `<small class="door">${esc(T.door)} ${money(r.r)}</small>` : ''}</span>`
      : `<span class="price">${!D.promo && r.r > r.p ? `<s>${money(r.r)}</s>` : ''}${money(r.p)}</span>`) +
    `<div class="step"><button type="button" data-k="${r.k}" data-d="-1" aria-label="${esc(T.minus + r.n)}">−</button>` +
    `<output id="q-${r.k}" aria-label="${esc(T.count + r.n)}">0</output>` +
    `<button type="button" data-k="${r.k}" data-d="1" aria-label="${esc(T.plus + r.n)}">+</button></div></li>`).join('');
  rowsEl.addEventListener('click', e => {
    const b = e.target.closest('button'); if (!b) return;
    q[b.dataset.k] = Math.max(0, Math.min(MAX, q[b.dataset.k] + +b.dataset.d)); calc();
  });

  const buy = $('buy'), bar = $('bar-txt');
  let pay = 0;
  function calc() {
    const units = [];
    let full = 0;
    D.rows.forEach(r => {
      for (let i = 0; i < q[r.k]; i++) units.push(r.p);
      full += r.r * q[r.k];
      $('q-' + r.k).textContent = q[r.k];
      rowsEl.querySelector(`[data-k="${r.k}"][data-d="-1"]`).disabled = q[r.k] === 0;
      rowsEl.querySelector(`[data-k="${r.k}"][data-d="1"]`).disabled = q[r.k] === MAX;
    });
    units.sort((a, b) => a - b);
    const n = units.length, online = units.reduce((a, b) => a + b, 0);
    const free = D.promo ? units.slice(0, Math.floor(n / 2)).reduce((a, b) => a + b, 0) : 0;
    pay = Math.round((online - free) * 100) / 100;
    const saved = Math.round((full - pay) * 100) / 100;
    $('total').textContent = money(pay);
    $('was').textContent = saved > 0 ? money(full) : '';
    let msg;
    if (n === 0) msg = T.empty;
    else if (D.promo && n === 1) msg = T.one;
    else if (D.promo) msg = fill(T.save, n, money(saved)) + (n % 2 ? ' ' + T.more : '');
    else msg = saved > 0 ? fill(n === 1 ? T.online1 : T.online, n, money(saved)) : fill(T.n1, n, '');
    if (n > 10) msg += T.groups;
    $('msg').textContent = msg;
    buy.disabled = n === 0;
    if (bar) bar.innerHTML = n ? `${n} ${esc(word(n))}<b>${money(pay)}</b>` : esc(D.promo ? T.bar : T.bar0);
  }
  calc();

  buy.addEventListener('click', async () => {
    const label = buy.textContent, body = new URLSearchParams({ action: 'sam_add_tickets', lang: D.lang });
    D.rows.forEach(r => { if (q[r.k]) body.append(`items[${r.id}]`, q[r.k]); });
    buy.disabled = true; buy.textContent = T.busy;
    try {
      const res = await fetch(D.ajax, { method: 'POST', body, credentials: 'same-origin' });
      const json = await res.json();
      if (json && json.success && json.data.redirect) { location.href = json.data.redirect; return; }
      throw new Error('cart');
    } catch (e) {
      $('msg').textContent = T.err; buy.textContent = label; buy.disabled = false;
    }
  });

  /* odpočet do konce akce */
  const clock = document.querySelector('.clock[data-end]');
  if (clock) {
    const end = new Date(clock.dataset.end), pad = v => String(v).padStart(2, '0');
    const part = k => clock.querySelector(`[data-cd="${k}"]`);
    const tick = () => {
      const s = Math.max(0, (end - new Date()) / 1000);
      part('d').textContent = Math.floor(s / 86400);
      part('h').textContent = pad(Math.floor(s % 86400 / 3600));
      part('m').textContent = pad(Math.floor(s % 3600 / 60));
    };
    tick(); setInterval(tick, 30000);
  }
})();

/* co zkusíte jako první */
(() => {
  const btns = document.querySelectorAll('.choices button'), img = document.getElementById('try-img'), txt = document.getElementById('try-text');
  if (!btns.length || !img) return;
  const still = matchMedia('(prefers-reduced-motion: reduce)').matches;
  btns.forEach(b => b.addEventListener('click', () => {
    btns.forEach(x => x.setAttribute('aria-pressed', x === b));
    txt.textContent = b.dataset.text;
    const set = () => { img.removeAttribute('srcset'); img.src = b.dataset.img; img.alt = b.dataset.alt; img.style.opacity = 1; };
    if (still) set(); else { img.style.opacity = 0; setTimeout(set, 160); }
  }));
  new IntersectionObserver((es, o) => {
    if (es[0].isIntersecting) { btns.forEach(b => { new Image().src = b.dataset.img; }); o.disconnect(); }
  }, { rootMargin: '400px' }).observe(document.querySelector('.try'));
})();

/* mobilní lišta: jen když není vidět úvod ani kalkulačka */
(() => {
  const bar = document.getElementById('bar'), watch = [document.querySelector('.hero'), document.getElementById('vstupenky')].filter(Boolean);
  if (!bar) return;
  let ticking = false;
  const upd = () => {
    ticking = false;
    const vis = watch.some(el => { const r = el.getBoundingClientRect(); return r.bottom > 0 && r.top < innerHeight; });
    bar.classList.toggle('on', !vis);
  };
  addEventListener('scroll', () => { if (!ticking) { ticking = true; requestAnimationFrame(upd); } }, { passive: true });
  addEventListener('resize', upd); upd();
})();

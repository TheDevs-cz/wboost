
(() => {
  const $ = (s, r = document) => r.querySelector(s);
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const store = { get(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }, set(k, v) { try { localStorage.setItem(k, v); } catch (e) {} } };

  const S = { projects: [], loaded: false, dbOk: true, assets: null, query: '', qn: '', menu: null, edit: null, highlight: null, route: 'projekty', logoFor: null, delFor: null, newLogo: null, pendingLogo: null };
  let db = null;
  const app = $('#app');

  /* ---------- helpers ---------- */
  const initials = (name) => {
    const caps = name.split(/\s+/).filter((w) => /^[A-ZÁČĎÉĚÍŇÓŘŠŤÚŮÝŽ0-9]/.test(w));
    const w = caps.length ? caps : [name || '?'];
    return (w[0][0] + (w[1] ? w[1][0] : '')).toUpperCase();
  };
  const lum = (hex) => {
    const n = parseInt(hex.slice(1), 16);
    const c = [(n >> 16) & 255, (n >> 8) & 255, n & 255].map((v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); });
    return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
  };
  const symbol = (p, cls = '') => {
    if (p.logo) return `<span class="sym ${cls}"><img src="/_blob/${esc(p.logo)}" alt="" loading="lazy"></span>`;
    return `<span class="sym mono ${cls}" aria-hidden="true">${esc(initials(p.name))}</span>`;
  };
  const usersText = (n) => { n = Number(n) || 0; return n + ' ' + (n === 1 ? 'uživatel' : n >= 2 && n <= 4 ? 'uživatelé' : 'uživatelů'); };
  const templatesText = (n) => { n = Number(n) || 0; return n + ' ' + (n === 1 ? 'šablona' : n >= 2 && n <= 4 ? 'šablony' : 'šablon'); };
  const sorted = () => S.projects.slice().sort((a, b) => a.name.localeCompare(b.name, 'cs'));
  const byId = (id) => S.projects.find((p) => p.id === id);
  const slug = (s) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 40) || 'projekt';
  const isHex = (v) => /^#[0-9a-fA-F]{6}$/.test(v);

  function toast(msg, err) {
    const t = document.createElement('div');
    t.className = 'toast' + (err ? ' err' : '');
    t.innerHTML = `<span class="i ${err ? "i-alert" : "i-check-circle"}" aria-hidden="true"></span><span></span>`;
    t.querySelector('span').textContent = msg;
    $('#toasts').append(t);
    setTimeout(() => t.remove(), 3600);
  }
  async function guard(promise, okMsg) {
    try { await promise; if (okMsg) toast(okMsg); return true; }
    catch (e) {
      const code = e && e.code;
      toast(code === 'quota_exceeded' ? 'Úložiště je plné. Odstraňte nepotřebné projekty.' : code === 'revoked' || code === 'not_granted' ? 'Na tuto změnu nemáte oprávnění.' : 'Změnu se nepodařilo uložit. Zkuste to znovu.', true);
      return false;
    }
  }

  /* ---------- sidebar ---------- */
  const mq = window.matchMedia('(max-width: 860px)');
  let wantCollapsed = store.get('wb-collapsed') === '1';
  function applyCollapsed() {
    const c = wantCollapsed && !mq.matches;
    app.classList.toggle('collapsed', c);
    const b = $('#collapseBtn');
    b.setAttribute('aria-label', c ? 'Rozbalit menu' : 'Sbalit menu');
    b.setAttribute('aria-expanded', String(!c));
  }
  applyCollapsed();
  mq.addEventListener('change', applyCollapsed);
  $('#collapseBtn').addEventListener('click', () => { wantCollapsed = !wantCollapsed; store.set('wb-collapsed', wantCollapsed ? '1' : '0'); applyCollapsed(); });
  const openDrawer = (o) => { app.classList.toggle('drawer', o); $('#scrim').hidden = !o; };
  $('#drawerOpen').addEventListener('click', () => openDrawer(true));
  $('#drawerClose').addEventListener('click', () => openDrawer(false));
  $('#scrim').addEventListener('click', () => openDrawer(false));
  /* quick navigation: dropdown picker */
  const pop = $('#qnPop'), qnBtn = $('#qnBtn'), qnSearch = $('#qnSearch');
  let qnOpts = [], qnAct = 0;
  const recents = () => { try { return JSON.parse(store.get('wb-recent') || '[]'); } catch (e) { return []; } };
  const pushRecent = (id) => { const r = recents().filter((x) => x !== id); r.unshift(id); store.set('wb-recent', JSON.stringify(r.slice(0, 3))); };
  const currentId = () => (S.route.startsWith('projekt-') ? S.route.slice(8).split('/')[0] : null);

  function placePop() {
    const r = qnBtn.getBoundingClientRect();
    const w = Math.min(300, window.innerWidth - 32);
    let left, top;
    if (app.classList.contains('collapsed')) { left = r.right + 10; top = r.top; }
    else { left = r.left; top = r.bottom + 6; }
    left = Math.max(16, Math.min(left, window.innerWidth - w - 16));
    pop.style.left = left + 'px'; pop.style.top = Math.max(16, top) + 'px';
    const h = pop.offsetHeight;
    if (top + h > window.innerHeight - 16) pop.style.top = Math.max(16, window.innerHeight - h - 16) + 'px';
  }
  function renderPopList() {
    const q = S.qn.trim().toLowerCase();
    const rec = q ? [] : recents().map(byId).filter(Boolean);
    const recIds = new Set(rec.map((p) => p.id));
    const all = sorted().filter((p) => (!q || p.name.toLowerCase().includes(q)) && !recIds.has(p.id));
    qnOpts = [...rec, ...all];
    if (qnAct >= qnOpts.length) qnAct = Math.max(0, qnOpts.length - 1);
    const cur = currentId();
    const opt = (p, i) => `<button type="button" role="option" id="qo-${i}" class="qn${i === qnAct ? ' act' : ''}" aria-selected="${p.id === cur}" data-go="${esc(p.id)}">${symbol(p, 'sm')}<span class="nm">${esc(p.name)}</span>${p.id === cur ? '<span class="chk" aria-hidden="true"><span class="i i-check"></span></span>' : ''}</button>`;
    let html = '';
    if (rec.length) html += `<div class="qn-sec lbl" role="presentation">Naposledy otevřené</div>` + rec.map((p, i) => opt(p, i)).join('') + `<div class="qn-sec lbl" role="presentation">Ostatní projekty</div>`;
    html += all.map((p, i) => opt(p, i + rec.length)).join('');
    $('#qnList').innerHTML = html || `<p class="cap" style="padding:10px">Žádný projekt neodpovídá hledání.</p>`;
    qnSearch.setAttribute('aria-activedescendant', qnOpts.length ? 'qo-' + qnAct : '');
    const a = document.getElementById('qo-' + qnAct); if (a) a.scrollIntoView({ block: 'nearest' });
  }
  function openPop(o) {
    pop.hidden = !o;
    qnBtn.setAttribute('aria-expanded', String(o));
    if (o) { S.qn = ''; qnSearch.value = ''; qnAct = 0; renderPopList(); placePop(); qnSearch.focus(); }
  }
  qnBtn.addEventListener('click', () => openPop(pop.hidden));
  qnSearch.addEventListener('input', (e) => { S.qn = e.target.value; qnAct = 0; renderPopList(); });
  qnSearch.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowDown') { e.preventDefault(); qnAct = Math.min(qnOpts.length - 1, qnAct + 1); renderPopList(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); qnAct = Math.max(0, qnAct - 1); renderPopList(); }
    else if (e.key === 'Enter') { e.preventDefault(); const p = qnOpts[qnAct]; if (p) goProject(p.id); }
    else if (e.key === 'Escape') { e.stopPropagation(); openPop(false); qnBtn.focus(); }
  });
  $('#qnList').addEventListener('click', (e) => { const b = e.target.closest('[data-go]'); if (b) goProject(b.dataset.go); });
  document.addEventListener('pointerdown', (e) => { if (!pop.hidden && !pop.contains(e.target) && !qnBtn.contains(e.target)) openPop(false); });
  window.addEventListener('resize', () => { if (!pop.hidden) placePop(); });
  function goProject(id) { openPop(false); location.hash = 'projekt-' + id; }

  function renderQuick() {
    $('#countAll').textContent = S.projects.length;
    const p = currentId() && byId(currentId());
    qnBtn.innerHTML = p
      ? `${symbol(p, 'sm')}<span class="nm hide-c">${esc(p.name)}</span><span class="i i-chev-down s16 chev hide-c" aria-hidden="true"></span>`
      : `<span class="i i-sort" style="color:var(--lav-ink)" aria-hidden="true"></span><span class="ph hide-c">Vybrat projekt…</span><span class="i i-chev-down s16 chev hide-c" aria-hidden="true"></span>`;
    qnBtn.setAttribute('aria-label', 'Rychlá navigace: ' + (p ? p.name : 'vybrat projekt'));
    qnBtn.title = p ? p.name : 'Rychlá navigace';
    if (S.route === 'projekty') $('#navAll').setAttribute('aria-current', 'page'); else $('#navAll').removeAttribute('aria-current');
    if (!pop.hidden) renderPopList();
  }

  /* ---------- dashboard ---------- */
  const ME = 'Lukáš Rejda';
  const missingOf = (p) => {
    const m = [];
    if (!p.colors || !p.colors.length) m.push('barevná paleta');
    if (!p.fonts || !p.fonts.length) m.push('písma');
    return m;
  };
  /* filters: combinable (AND); manual is either 'yes', 'no' or null */
  S.f = { paid: false, mine: false, owner: null, manual: null };
  S.ownerMenu = false;
  const anyFilter = () => S.f.paid || S.f.mine || S.f.owner || S.f.manual;
  const passes = (p, f = S.f) =>
    (!f.paid || p.paid) &&
    (!f.mine || p.owner === ME) &&
    (!f.owner || p.owner === f.owner) &&
    (!f.manual || (f.manual === 'yes' ? !!p.manual : !p.manual));

  function renderFilters() {
    const el = $('#fbar'); if (!el) return;
    const all = S.projects;
    const n = (pred) => all.filter(pred).length;
    const owners = [...new Set(all.map((p) => p.owner).filter(Boolean))].sort((a, b) => a.localeCompare(b, 'cs'));
    const chip = (act, on, label, count, extra = '') => `<button type="button" class="chip${on ? ' on' : ''}" data-action="${act}" aria-pressed="${on}"${extra}>${label}${count != null ? ` <span class="n">${count}</span>` : ''}</button>`;
    el.innerHTML =
      chip('f-all', !anyFilter(), 'Vše', all.length) +
      `<span class="sep" aria-hidden="true"></span>` +
      chip('f-paid', S.f.paid, `<span class="i i-star-f s14" aria-hidden="true" style="color:var(--pch)"></span>Placené`, n((p) => p.paid)) +
      chip('f-mine', S.f.mine, 'Moje projekty', n((p) => p.owner === ME)) +
      `<div class="fdrop">${chip('f-owner-menu', !!S.f.owner, `<span class="i i-users s14" aria-hidden="true"></span>${S.f.owner ? esc(S.f.owner) : 'Grafik'}<span class="i i-chev-down s14" aria-hidden="true"></span>`, null, ` aria-haspopup="menu" aria-expanded="${S.ownerMenu}"`)}
        ${S.ownerMenu ? `<div class="menu" role="dialog" aria-label="Vybrat grafika">
          <label class="inp sm" for="ownerSearch" style="margin-bottom:4px"><span class="i i-search s14" aria-hidden="true"></span><input type="search" id="ownerSearch" placeholder="Najít grafika" autocomplete="off" aria-controls="ownerList"></label>
          <div id="ownerList" role="listbox" aria-label="Grafici" style="display:flex;flex-direction:column;gap:2px;max-height:280px;overflow-y:auto"></div>
        </div>` : ''}</div>` +
      `<span class="sep" aria-hidden="true"></span>` +
      chip('f-manual-yes', S.f.manual === 'yes', 'S manuálem', n((p) => !!p.manual)) +
      chip('f-manual-no', S.f.manual === 'no', 'Bez manuálu', n((p) => !p.manual));
    if (S.ownerMenu) {
      const inp = $('#ownerSearch');
      inp.value = S.ownerQ || '';
      inp.addEventListener('input', (e) => { S.ownerQ = e.target.value; renderOwnerList(owners, n); });
      inp.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') { e.stopPropagation(); S.ownerMenu = false; S.ownerQ = ''; renderFilters(); const b = document.querySelector('[data-action="f-owner-menu"]'); if (b) b.focus(); }
        if (e.key === 'Enter') { e.preventDefault(); const first = $('#ownerList [data-action="f-owner"]'); if (first) first.click(); }
      });
      renderOwnerList(owners, n);
      inp.focus({ preventScroll: true });
    }
  }
  function renderOwnerList(owners, n) {
    const q = (S.ownerQ || '').trim().toLowerCase();
    const list = owners.filter((o) => !q || o.toLowerCase().includes(q));
    const tick = '<span class="chk"><span class="i i-check"></span></span>';
    const allRow = q ? '' : `<button type="button" role="option" aria-selected="${!S.f.owner}" class="mi" data-action="f-owner" data-owner="">Všichni grafici<span class="end">${!S.f.owner ? tick : S.projects.length}</span></button><div class="menu-sep"></div>`;
    $('#ownerList').innerHTML = allRow + (list.map((o) => `<button type="button" role="option" aria-selected="${S.f.owner === o}" class="mi" data-action="f-owner" data-owner="${esc(o)}"><span class="av" style="width:26px;height:26px;font-size:10px">${esc(initials(o))}</span>${esc(o)}${o === ME ? ' <span class="cap">(já)</span>' : ''}<span class="end">${S.f.owner === o ? tick : n((p) => p.owner === o)}</span></button>`).join('') || `<p class="cap" style="padding:10px">Žádný grafik neodpovídá hledání.</p>`);
  }

  function cardHTML(p) {
    const menuOpen = S.menu === p.id;
    const miss = missingOf(p);
    const missText = miss.length ? 'Chybí: ' + miss.join(', ') : '';
    return `<article class="pcard${S.highlight === p.id ? ' hl' : ''}${menuOpen ? ' menu-open' : ''}" data-id="${esc(p.id)}">
      ${miss.length ? `<span class="pwarn" title="${esc(missText)}" role="img" aria-label="${esc(missText)}"><span class="i i-warn-f s16" aria-hidden="true"></span></span>` : ''}
      <div class="card-top">${symbol(p)}
        <div class="card-acts">
          ${p.paid ? `<span class="pstar" title="Placený projekt" role="img" aria-label="Placený projekt"><span class="i i-star-f s16" aria-hidden="true"></span></span>` : ''}
          <button type="button" class="btn icon xs ghost card-menu-btn" data-action="menu" aria-label="Možnosti projektu ${esc(p.name)}" aria-haspopup="menu" aria-expanded="${menuOpen}"><span class="i i-more s16" aria-hidden="true"></span></button>
          ${menuOpen ? `<div class="menu" role="menu">
            <button type="button" role="menuitem" class="mi" data-action="rename"><span class="i i-edit s16" aria-hidden="true"></span>Změnit název</button>
            <button type="button" role="menuitem" class="mi" data-action="logo"><span class="i i-image s16" aria-hidden="true"></span>Změnit logo</button>
            <button type="button" role="menuitem" class="mi" data-action="share"><span class="i i-share s16" aria-hidden="true"></span>Sdílet</button>
            <button type="button" role="menuitemcheckbox" aria-checked="${!!p.paid}" class="mi" data-action="paid"><span class="i ${p.paid ? 'i-star-f' : 'i-star'} s16" aria-hidden="true"${p.paid ? ' style="color:var(--pch)"' : ''}></span>${p.paid ? 'Zrušit označení placený' : 'Označit jako placený'}</button>
            <div class="menu-sep"></div>
            <button type="button" role="menuitem" class="mi danger" data-action="delete"><span class="i i-trash s16" aria-hidden="true"></span>Odstranit projekt</button>
          </div>` : ''}
        </div>
      </div>
      <a class="pname" href="#projekt-${esc(p.id)}">${esc(p.name)}</a>
      <button type="button" class="meta meta-btn" data-action="share" title="Kdo má přístup" aria-label="${usersText(1 + (p.collaborators || []).length)} · zobrazit sdílení"><span class="i i-users s16" aria-hidden="true"></span>${usersText(1 + (p.collaborators || []).length)}</button>
    </article>`;
  }

  function renderDashboard() {
    const v = $('#view');
    const q = S.query.trim().toLowerCase();
    const list = sorted().filter((p) => (!q || p.name.toLowerCase().includes(q)) && passes(p));
    if (!v.querySelector('[data-view="dash"]')) {
      v.innerHTML = `<div data-view="dash" style="display:contents">
        <div class="head">
          <div style="display:flex;flex-direction:column;gap:8px"><span class="lbl" id="greet"></span><h1>Všechny projekty <small id="dashCount"></small></h1></div>
          <div class="head-tools">
            <label class="inp" for="dashSearch"><span class="i i-search s16" aria-hidden="true"></span><input type="search" id="dashSearch" placeholder="Hledat v projektech" autocomplete="off"></label>
            <button type="button" class="btn" data-action="new"><span class="i i-plus s16" aria-hidden="true"></span>Nový projekt</button>
          </div>
        </div>
        <div class="fbar" id="fbar" role="toolbar" aria-label="Filtry projektů"></div>
        <div id="dashNotice"></div>
        <div id="dashBody"></div>
      </div>`;
      $('#dashSearch').value = S.query;
      $('#dashSearch').addEventListener('input', (e) => { S.query = e.target.value; renderDashboard(); });
      const h = new Date().getHours();
      $('#greet').textContent = (h < 10 ? 'Dobré ráno' : h < 18 ? 'Dobrý den' : 'Dobrý večer') + ', Lukáši';
    }
    $('#dashCount').textContent = S.loaded ? list.length : '';
    if (S.loaded) renderFilters();
    $('#dashNotice').innerHTML = S.dbOk ? '' : `<div class="notice"><span class="i i-alert" aria-hidden="true"></span><span>Ukládání není v tomto zobrazení dostupné. Změny zůstanou jen do obnovení stránky.</span></div>`;
    const body = $('#dashBody');
    if (!S.loaded) { body.innerHTML = `<div class="grid" aria-busy="true">${'<div class="sk"></div>'.repeat(8)}</div>`; return; }
    if (!S.projects.length) {
      body.innerHTML = `<div class="empty"><span class="ico-c"><span class="i i-grid" aria-hidden="true"></span></span><h2 style="font-size:18px;font-weight:600">Zatím tu nejsou žádné projekty</h2><p style="color:var(--muted);max-width:340px;line-height:1.55">Projekt sdružuje šablony, logomanuál a e-mailové podpisy jednoho klienta.</p><button type="button" class="btn sm" data-action="new"><span class="i i-plus s16" aria-hidden="true"></span>Vytvořit první projekt</button></div>`;
      return;
    }
    if (!list.length) {
      const hasQ = !!S.query.trim();
      body.innerHTML = `<div class="empty"><span class="ico-c"><span class="i ${hasQ ? 'i-search' : 'i-filter'}" aria-hidden="true"></span></span><h2 style="font-size:18px;font-weight:600"></h2><div class="dlg-f" style="justify-content:center">${hasQ ? '<button type="button" class="btn sm tertiary" data-action="clear-search">Zrušit hledání</button>' : ''}${anyFilter() ? '<button type="button" class="btn sm tertiary" data-action="f-all">Zrušit filtry</button>' : ''}</div></div>`;
      body.querySelector('h2').textContent = hasQ ? `Žádný projekt neodpovídá „${S.query}“` : 'Žádný projekt neodpovídá vybraným filtrům';
      return;
    }
    body.innerHTML = `<div class="grid">${list.map(cardHTML).join('')}</div>`;
    if (S.scrollTo) { const el = body.querySelector(`[data-id="${CSS.escape(S.scrollTo)}"]`); if (el) { el.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); S.scrollTo = null; } }
  }

  /* re-render one card in place, so opening a menu never rebuilds the grid */
  function updateCard(id) {
    const el = document.querySelector(`.pcard[data-id="${CSS.escape(id)}"]`);
    const p = byId(id);
    if (!el || !p) { render(); return; }
    const tmp = document.createElement('div');
    tmp.innerHTML = cardHTML(p);
    el.replaceWith(tmp.firstElementChild);
  }
  function setMenu(id) {
    const prev = S.menu;
    S.menu = id;
    if (prev && prev !== id) updateCard(prev);
    if (id) updateCard(id); else if (prev) updateCard(prev);
  }

  /* ---------- project detail (next step) ---------- */

  /* ---------- sections (content built in later steps) ---------- */
  const SECTIONS = {
    uzivatele:  { group: 'Administrace', title: 'Uživatelé', icon: 'i-users' },
    zadosti:    { group: 'Administrace', title: 'Žádosti o registraci', icon: 'i-user-plus' },
    poptavky:   { group: 'Administrace', title: 'Poptávky z webu', icon: 'i-inbox-in' },
    statistiky: { group: 'Administrace', title: 'Statistiky', icon: 'i-trend' },
    aplikace:   { group: 'Propojení', title: 'Propojené aplikace', icon: 'i-plug' },
    'ai-mcp':   { group: 'Propojení', title: 'AI (MCP server)', icon: 'i-bot' },
    ucet:       { group: 'Profil', title: 'Můj účet', icon: 'i-user' }
  };

  /* profile menu */
  const meBtn = $('#meBtn'), meMenu = $('#meMenu');
  function openMe(o) {
    meMenu.hidden = !o;
    meBtn.setAttribute('aria-expanded', String(o));
    if (o) { const f = meMenu.querySelector('.mi'); if (f) f.focus({ preventScroll: true }); }
  }
  meBtn.addEventListener('click', () => openMe(meMenu.hidden));
  meMenu.addEventListener('click', (e) => { if (e.target.closest('.mi')) openMe(false); });
  meMenu.addEventListener('keydown', (e) => {
    const items = [...meMenu.querySelectorAll('.mi')];
    const i = items.indexOf(document.activeElement);
    if (e.key === 'Escape') { e.stopPropagation(); openMe(false); meBtn.focus(); }
    else if (e.key === 'ArrowDown') { e.preventDefault(); items[(i + 1) % items.length].focus(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); items[(i - 1 + items.length) % items.length].focus(); }
  });
  document.addEventListener('pointerdown', (e) => { if (!meMenu.hidden && !meMenu.contains(e.target) && !meBtn.contains(e.target)) openMe(false); });
  $('#logoutBtn').addEventListener('click', () => {
    openMe(false); openDrawer(false);
    $('#signedOut').hidden = false; $('#loginBtn').focus();
  });
  $('#loginBtn').addEventListener('click', () => {
    $('#signedOut').hidden = true;
    if (location.hash !== '#projekty') location.hash = 'projekty';
    toast('Vítejte zpět, Lukáši');
  });
  function renderSection(key) {
    const s = SECTIONS[key];
    $('#view').innerHTML = `<div class="head"><div style="display:flex;flex-direction:column;gap:8px"><span class="lbl">${esc(s.group)}</span><h1>${esc(s.title)}</h1></div></div>
      <div class="empty"><span class="ico-c"><span class="i ${s.icon}" aria-hidden="true"></span></span><h2 style="font-size:18px;font-weight:600">Tuto sekci teprve postavíme</h2><p style="color:var(--muted);max-width:360px;line-height:1.55">Obsah stránky ${esc(s.title)} doplníme v dalším kroku prototypu.</p></div>`;
  }

  /* ---------- routing ---------- */
  function route() {
    const h = location.hash.replace(/^#/, '');
    S.route = h.startsWith('projekt-') || h.startsWith('uzivatel-') || h.startsWith('statistiky-') || SECTIONS[h] ? h : 'projekty';
    S.menu = null;
    openDrawer(false);
    if (meMenu && !meMenu.hidden) openMe(false);
    if (S.route.startsWith('projekt-')) pushRecent(S.route.slice(8).split('/')[0]);
    render();
    $('#main').scrollTop = 0;
  }
  function render() {
    if (S.route === 'zadosti') { S.uf = 'requests'; location.replace('#uzivatele'); return; }
    if (typeof viewGuard === 'function' && viewGuard()) return;
    renderProjNav();
    if (S.route === 'projekty') renderDashboard();
    else if (S.route === 'uzivatele') renderUsers();
    else if (S.route === 'statistiky') { if ($('#dlgStat').open) $('#dlgStat').close(); renderStats(); }
    else if (S.route.startsWith('statistiky-')) { renderStats(); openStatClient(S.route.slice(11)); }
    else if (S.route.startsWith('uzivatel-')) { renderUsers(); openProfile(S.route.slice(9)); }
    else if (SECTIONS[S.route]) renderSection(S.route);
    else { if ($('#view').querySelector('[data-view="dash"]')) $('#view').innerHTML = ''; renderDetail(S.route.slice(8)); }
    renderQuick();
    document.querySelectorAll('[data-nav]').forEach((a) => { if (a.dataset.nav === S.route || (a.dataset.nav === 'uzivatele' && S.route.startsWith('uzivatel-')) || (a.dataset.nav === 'statistiky' && S.route.startsWith('statistiky-'))) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current'); });
  }
  window.addEventListener('hashchange', route);

  /* ---------- actions ---------- */
  document.addEventListener('click', (e) => {
    const a = e.target.closest('[data-action]');
    if (!a) {
      if (S.menu && !e.target.closest('.menu')) setMenu(null);
      if (S.ownerMenu && !e.target.closest('.fdrop')) { S.ownerMenu = false; renderFilters(); }
      return;
    }
    const card = a.closest('.pcard');
    const id = card && card.dataset.id;
    const act = a.dataset.action;
    if (act.startsWith('f-')) {
      if (act === 'f-owner-menu') { S.ownerMenu = !S.ownerMenu; S.ownerQ = ''; renderFilters(); return; }
      if (act === 'f-all') S.f = { paid: false, mine: false, owner: null, manual: null };
      else if (act === 'f-paid') S.f.paid = !S.f.paid;
      else if (act === 'f-mine') S.f.mine = !S.f.mine;
      else if (act === 'f-owner') S.f.owner = a.dataset.owner || null;
      else if (act === 'f-manual-yes') S.f.manual = S.f.manual === 'yes' ? null : 'yes';
      else if (act === 'f-manual-no') S.f.manual = S.f.manual === 'no' ? null : 'no';
      S.ownerMenu = false; S.ownerQ = '';
      renderDashboard();
      return;
    }
    if (S.ownerMenu) { S.ownerMenu = false; renderFilters(); }
    if (act === 'menu') { setMenu(S.menu === id ? null : id); if (S.menu) { const f = document.querySelector('.menu .mi'); if (f) f.focus({ preventScroll: true }); } }
    else if (act === 'rename') { setMenu(null); openRename(id); }
    else if (act === 'logo') { setMenu(null); openLogo(id); }
    else if (act === 'share') { setMenu(null); openShare(id); }
    else if (act === 'paid') {
      setMenu(null);
      const p = byId(id); if (!p) return;
      const v = !p.paid;
      if (db) guard(db.doc('projects/' + id).update({ paid: v }), v ? 'Projekt označen jako placený' : 'Označení placený zrušeno');
      else { p.paid = v; updateCard(id); toast(v ? 'Projekt označen jako placený' : 'Označení placený zrušeno'); }
    }
    else if (act === 'delete') { setMenu(null); openDelete(id); }
    else if (act === 'new') { openNew(); }
    else if (act === 'clear-search') { S.query = ''; $('#dashSearch').value = ''; renderDashboard(); }
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && S.menu) { const id = S.menu; setMenu(null); const b = document.querySelector(`[data-id="${CSS.escape(id)}"] .card-menu-btn`); if (b) b.focus({ preventScroll: true }); }
  });

  /* dialogs */
  document.querySelectorAll('dialog').forEach((d) => {
    d.addEventListener('click', (e) => { if (e.target === d || e.target.closest('[data-close]')) d.close(); });
  });

  // New project
  /* shared logo upload helpers (used by "Nový projekt" and "Změnit logo") */
  const LOGO_TYPES = { svg: 'image/svg+xml', png: 'image/png', jpg: 'image/jpeg', jpeg: 'image/jpeg', webp: 'image/webp' };
  async function uploadLogo(f) {
    const ext = (f.name.split('.').pop() || '').toLowerCase();
    const type = Object.values(LOGO_TYPES).includes(f.type) ? f.type : LOGO_TYPES[ext];
    const res = await S.assets.upload(f, type ? { type } : undefined);
    return res.id;
  }
  const logoErrMsg = (e) => ({ too_large: 'Soubor je příliš velký. SVG může mít nejvýše 2 MB, obrázky 20 MB.', unsupported_type: 'Nahrajte SVG, PNG, JPG nebo WebP.', rate_limited: 'Moc pokusů za sebou. Zkuste to za chvíli.', quota_or_state: 'Úložiště pro soubory je plné.' }[e && e.code] || 'Logo se nepodařilo nahrát. Zkuste to znovu.');

  function setNewLogo(file) {
    S.newLogo = file || null;
    const img = $('#newPrev');
    $('#newLogoErr').hidden = true;
    if (file) { img.src = URL.createObjectURL(file); img.hidden = false; $('#newDropEmpty').style.display = 'none'; }
    else { img.hidden = true; img.removeAttribute('src'); $('#newDropEmpty').style.display = 'contents'; $('#newLogoFile').value = ''; }
    $('#newLogoRemove').hidden = !file;
    $('#newLogoHint').hidden = !!file || !S.assets;
  }
  function openNew() {
    $('#newName').value = ''; $('#newNameErr').hidden = true; $('#newNameBox').classList.remove('err');
    $('#newSubmit').disabled = true;
    setNewLogo(null);
    $('#newDrop').style.display = S.assets ? '' : 'none';
    if (!S.assets) { $('#newLogoHint').hidden = false; $('#newLogoHint').textContent = 'Logo půjde nahrát jen uživatelům s právem úprav.'; }
    $('#dlgNew').showModal(); $('#newName').focus();
  }
  $('#newName').addEventListener('input', (e) => {
    const v = e.target.value.trim();
    $('#newSubmit').disabled = !v;
    if (v) { $('#newNameErr').hidden = true; $('#newNameBox').classList.remove('err'); }
  });
  $('#newLogoFile').addEventListener('change', (e) => { const f = e.target.files[0]; if (f) setNewLogo(f); });
  $('#newLogoRemove').addEventListener('click', () => setNewLogo(null));
  const newDrop = $('#newDrop');
  newDrop.addEventListener('dragover', (e) => { e.preventDefault(); newDrop.classList.add('on'); });
  newDrop.addEventListener('dragleave', () => newDrop.classList.remove('on'));
  newDrop.addEventListener('drop', (e) => { e.preventDefault(); newDrop.classList.remove('on'); const f = e.dataTransfer.files[0]; if (f) setNewLogo(f); });
  $('#formNew').addEventListener('submit', async (e) => {
    e.preventDefault();
    const name = $('#newName').value.trim();
    if (!name) { $('#newNameErr').hidden = false; $('#newNameBox').classList.add('err'); $('#newName').focus(); return; }
    const id = slug(name) + '-' + Math.random().toString(36).slice(2, 6);
    const btn = $('#newSubmit'); btn.classList.add('is-loading');
    let logo = null;
    if (S.newLogo && S.assets) {
      try { logo = await uploadLogo(S.newLogo); }
      catch (err) { const el = $('#newLogoErr'); el.textContent = logoErrMsg(err); el.hidden = false; btn.classList.remove('is-loading'); return; }
    }
    const doc = { name, colors: [], fonts: [], owner: ME, manual: false, paid: false, users: 1, templates: 0, logo, createdAt: new Date().toISOString() };
    let ok = true;
    if (db) ok = await guard(db.doc('projects/' + id).set(doc));
    else S.projects.push({ id, ...doc });
    btn.classList.remove('is-loading');
    if (!ok) { if (logo) S.assets.delete(logo).catch(() => {}); return; }
    $('#dlgNew').close();
    toast('Projekt vytvořen');
    S.highlight = id; S.scrollTo = id; S.query = ''; const ds = $('#dashSearch'); if (ds) ds.value = '';
    if (!db) render();
    if (location.hash !== '#projekty' && location.hash !== '') location.hash = 'projekty';
  });

  // Share
  S.users = [];
  const isEmail = (v) => /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v);
  const personOf = (id) => {
    if (id.startsWith('inv:')) return { id, name: '', email: id.slice(4), invite: true };
    return S.users.find((u) => u.id === id) || { id, name: 'Neznámý uživatel', email: '' };
  };
  const personRow = (u) => {
    const label = u.name || u.email;
    return `<span class="av" aria-hidden="true">${esc(initials(label.replace(/@.*/, '').replace(/[._-]+/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())))}</span><span class="who"><b>${esc(label)}</b>${u.name && u.email ? `<span>${esc(u.email)}</span>` : ''}</span>`;
  };
  function shareOptions() {
    const sh = S.share, q = sh.q.trim().toLowerCase();
    const taken = new Set(sh.list); const own0 = (byId(sh.pid) || {}).owner;
    const list = S.users
      .filter((u) => u.status !== 'request' && u.status !== 'rejected' && !taken.has(u.id) && !(own0 && (u.name === own0 || u.email === own0)) && (!q || (u.name || '').toLowerCase().includes(q) || (u.email || '').toLowerCase().includes(q)))
      .sort((a, b) => (a.name || a.email).localeCompare(b.name || b.email, 'cs'));
    const known = S.users.some((u) => (u.email || '').toLowerCase() === q) || sh.list.includes('inv:' + q);
    const opts = list.map((u) => ({ id: u.id, u }));
    if (isEmail(q) && !known) opts.push({ id: 'inv:' + q, invite: q });
    return opts;
  }
  function renderShare() {
    const sh = S.share; if (!sh) return;
    const sug = $('#shareSug'), inp = $('#shareSearch');
    const opts = sh.open ? shareOptions() : [];
    if (sh.act >= opts.length) sh.act = Math.max(0, opts.length - 1);
    sh.opts = opts;
    sug.hidden = !sh.open;
    inp.setAttribute('aria-expanded', String(sh.open));
    if (sh.open) {
      sug.innerHTML = opts.map((o, i) => o.invite
        ? `<button type="button" role="option" id="so-${i}" class="mi${i === sh.act ? ' act' : ''}" data-add="${esc(o.id)}"><span class="ico-c" style="width:34px;height:34px"><span class="i i-mail s16" aria-hidden="true"></span></span><span class="who"><b>Pozvat ${esc(o.invite)}</b><span>Pošleme pozvánku e-mailem</span></span></button>`
        : `<button type="button" role="option" id="so-${i}" class="mi${i === sh.act ? ' act' : ''}" data-add="${esc(o.id)}">${personRow(o.u)}<span class="i i-plus s16" aria-hidden="true"></span></button>`
      ).join('') || `<p class="share-empty">${sh.q.trim() ? 'Nikdo neodpovídá. Zadejte celý e-mail a pošlete pozvánku.' : 'Všichni uživatelé už k projektu mají přístup.'}</p>`;
      inp.setAttribute('aria-activedescendant', opts.length ? 'so-' + sh.act : '');
      const a = document.getElementById('so-' + sh.act); if (a) a.scrollIntoView({ block: 'nearest' });
    }
    const p0 = byId(sh.pid) || {}; const ownerName = p0.owner || ME;
    const rows = [`<li class="share-row"><span class="av" aria-hidden="true">${esc(initials(ownerName.replace(/@.*/, '').replace(/[._-]+/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())))}</span><span class="who"><b>${esc(ownerName)}${ownerName === ME ? ' <span style="display:inline;font-weight:500;color:var(--subtle)">(vy)</span>' : ''}</b></span><span class="pill pch">Vlastník</span></li>`]
      .concat(sh.list.map((id) => {
        const u = personOf(id); const isNew = !sh.orig.includes(id);
        return `<li class="share-row${sh.flash === id ? ' flash' : ''}">${personRow(u)}${u.invite ? '<span class="pill">Pozvánka</span>' : isNew ? '<span class="pill mint">Nový</span>' : ''}<button type="button" class="btn icon xs ghost" data-remove="${esc(id)}" aria-label="Odebrat přístup: ${esc(u.name || u.email)}" title="Odebrat přístup"><span class="i i-x s16" aria-hidden="true"></span></button></li>`;
      }));
    $('#shareList').innerHTML = rows.join('') + (sh.list.length ? '' : `<li class="share-empty">Projekt zatím nikdo další nevidí. Přidejte spolupracovníka výše.</li>`);
    const n = sh.list.length + 1;
    $('#shareCount').textContent = n + ' ' + (n === 1 ? 'osoba' : n <= 4 ? 'osoby' : 'osob');
    const same = sh.list.length === sh.orig.length && sh.list.every((x) => sh.orig.includes(x));
    $('#shareSave').disabled = same;
  }
  function openShare(id) {
    const p = byId(id); if (!p) return;
    const orig = (p.collaborators || []).slice();
    S.share = { pid: id, orig, list: orig.slice(), q: '', act: 0, open: false };
    $('#dlgShareT').textContent = `Sdílet projekt ${p.name}`;
    $('#shareSearch').value = '';
    renderShare();
    $('#dlgShare').showModal();
    $('#shareSearch').focus();
  }
  function shareAdd(id) {
    const sh = S.share; if (!sh || sh.list.includes(id)) return;
    sh.list.push(id); sh.q = ''; sh.act = 0; sh.open = false; sh.flash = id;
    $('#shareSearch').value = '';
    renderShare(); $('#shareSearch').focus();
    const row = $('#shareList').querySelector('.share-row.flash');
    if (row) row.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    sh.flash = null;
  }
  /* the list opens on a click, typing or arrow-down — never on focus alone, so returning focus after an add keeps it closed */
  $('#shareSearch').addEventListener('click', () => { if (S.share && !S.share.open) { S.share.open = true; renderShare(); } });
  $('#shareRoles').addEventListener('click', () => toast('Správu rolí projektu navrhneme v dalším kroku'));
  $('#shareSearch').addEventListener('input', (e) => { const sh = S.share; sh.q = e.target.value; sh.act = 0; sh.open = true; renderShare(); });
  $('#shareSearch').addEventListener('keydown', (e) => {
    const sh = S.share; if (!sh) return;
    if (e.key === 'ArrowDown') { e.preventDefault(); sh.open = true; sh.act = Math.min((sh.opts || []).length - 1, sh.act + 1); renderShare(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); sh.act = Math.max(0, sh.act - 1); renderShare(); }
    else if (e.key === 'Enter') { e.preventDefault(); const o = (sh.opts || [])[sh.act]; if (sh.open && o) shareAdd(o.id); }
    else if (e.key === 'Escape' && sh.open) { e.preventDefault(); e.stopPropagation(); sh.open = false; renderShare(); }
  });
  $('#shareSug').addEventListener('click', (e) => { const b = e.target.closest('[data-add]'); if (b) shareAdd(b.dataset.add); });
  $('#shareList').addEventListener('click', (e) => {
    const b = e.target.closest('[data-remove]'); if (!b || !S.share) return;
    S.share.list = S.share.list.filter((x) => x !== b.dataset.remove);
    renderShare();
  });
  $('#dlgShare').addEventListener('pointerdown', (e) => {
    if (S.share && S.share.open && !$('#shareField').contains(e.target)) { S.share.open = false; renderShare(); }
  });
  $('#dlgShare').addEventListener('close', () => { S.share = null; });
  $('#formShare').addEventListener('submit', async (e) => {
    e.preventDefault();
    const sh = S.share; if (!sh) return;
    const btn = $('#shareSave'); btn.classList.add('is-loading');
    const ids = [];
    let ok = true;
    for (const id of sh.list) {
      if (!id.startsWith('inv:')) { ids.push(id); continue; }
      const email = id.slice(4);
      const uid = slug(email) + '-' + Math.random().toString(36).slice(2, 6);
      if (db) ok = ok && await guard(db.doc('users/' + uid).set({ name: '', email, invited: true, status: 'invited', role: 'user', invitedAt: isoToday(), ai: AI_DEFAULT.user.slice() }));
      else S.users.push({ id: uid, name: '', email, invited: true, status: 'invited', role: 'user', invitedAt: isoToday(), ai: AI_DEFAULT.user.slice() });
      ids.push(uid);
    }
    if (ok) {
      if (db) ok = await guard(db.doc('projects/' + sh.pid).update({ collaborators: ids }));
      else { const p = byId(sh.pid); if (p) p.collaborators = ids; }
    }
    btn.classList.remove('is-loading');
    if (!ok) return;
    const inv = sh.list.filter((x) => x.startsWith('inv:')).length;
    $('#dlgShare').close();
    toast(inv ? `Sdílení uloženo · ${inv === 1 ? 'pozvánka odeslána' : 'pozvánky odeslány'}` : 'Sdílení uloženo');
  });

  // Rename
  function openRename(id) {
    const p = byId(id); if (!p) return;
    S.renameFor = id;
    $('#dlgRenameT').textContent = 'Název projektu ' + p.name;
    const inp = $('#renameInput');
    inp.value = p.name;
    $('#renameErr').hidden = true; $('#renameBox').classList.remove('err');
    $('#renameSave').disabled = true;
    $('#dlgRename').showModal();
    inp.focus(); inp.select();
  }
  $('#renameInput').addEventListener('input', (e) => {
    const p = byId(S.renameFor); const v = e.target.value.trim();
    $('#renameSave').disabled = !p || !v || v === p.name;
    if (v) { $('#renameErr').hidden = true; $('#renameBox').classList.remove('err'); }
  });
  $('#formRename').addEventListener('submit', async (e) => {
    e.preventDefault();
    const p = byId(S.renameFor); if (!p) return;
    const val = $('#renameInput').value.trim();
    if (!val) { $('#renameErr').hidden = false; $('#renameBox').classList.add('err'); return; }
    if (val === p.name) { $('#dlgRename').close(); return; }
    const btn = $('#renameSave'); btn.classList.add('is-loading');
    let ok = true;
    if (db) ok = await guard(db.doc('projects/' + p.id).update({ name: val }));
    else p.name = val;
    btn.classList.remove('is-loading');
    if (!ok) return;
    $('#dlgRename').close(); toast('Název uložen');
    if (!db) render();
  });

  // Logo
  function setLogoPreview(file) {
    S.pendingLogo = file;
    const img = $('#dropPrev');
    $('#logoErr').hidden = true;
    if (file) { img.src = URL.createObjectURL(file); img.hidden = false; $('#dropEmpty').style.display = 'none'; }
    else { img.hidden = true; img.removeAttribute('src'); $('#dropEmpty').style.display = 'contents'; }
    $('#logoSave').disabled = !file || !S.assets;
  }
  function openLogo(id) {
    const p = byId(id); if (!p) return;
    S.logoFor = id;
    $('#dlgLogoT').textContent = 'Logo projektu ' + p.name;
    $('#logoFile').value = '';
    setLogoPreview(null);
    if (p.logo) { const img = $('#dropPrev'); img.src = '/_blob/' + p.logo; img.hidden = false; $('#dropEmpty').style.display = 'none'; }
    $('#logoRemove').hidden = !p.logo || !db;
    $('#logoNoUpload').hidden = !!S.assets;
    $('#dlgLogo').showModal();
  }
  $('#logoFile').addEventListener('change', (e) => { const f = e.target.files[0]; if (f) setLogoPreview(f); });
  const drop = $('#drop');
  drop.addEventListener('dragover', (e) => { e.preventDefault(); drop.classList.add('on'); });
  drop.addEventListener('dragleave', () => drop.classList.remove('on'));
  drop.addEventListener('drop', (e) => { e.preventDefault(); drop.classList.remove('on'); const f = e.dataTransfer.files[0]; if (f) setLogoPreview(f); });
  $('#logoSave').addEventListener('click', async () => {
    const f = S.pendingLogo; const p = byId(S.logoFor);
    if (!f || !p || !S.assets) return;
    const btn = $('#logoSave'); btn.classList.add('is-loading');
    try {
      const newId = await uploadLogo(f);
      const old = p.logo;
      const ok = db ? await guard(db.doc('projects/' + p.id).update({ logo: newId })) : (p.logo = newId, true);
      if (ok) {
        if (old) S.assets.delete(old).catch(() => {});
        $('#dlgLogo').close(); toast('Logo uloženo');
        if (!db) render();
      }
    } catch (e) {
      const el = $('#logoErr'); el.textContent = logoErrMsg(e); el.hidden = false;
    }
    btn.classList.remove('is-loading');
  });
  $('#logoRemove').addEventListener('click', async () => {
    const p = byId(S.logoFor); if (!p || !p.logo) return;
    const old = p.logo;
    const ok = await guard(db.doc('projects/' + p.id).update({ logo: null }), 'Logo odebráno');
    if (ok && S.assets) S.assets.delete(old).catch(() => {});
    if (ok) $('#dlgLogo').close();
  });

  // Delete
  function openDelete(id) {
    const p = byId(id); if (!p) return;
    S.delFor = id;
    $('#dlgDelT').textContent = `Odstranit projekt ${p.name}?`;
    const t = Number(p.templates) || 0;
    $('#dlgDelText').textContent = t ? `Spolu s projektem zmizí i ${templatesText(t)} a jeho galerie. Tuto akci nelze vrátit.` : 'Projekt zmizí ze seznamu pro všechny uživatele. Tuto akci nelze vrátit.';
    $('#dlgDel').showModal();
  }
  $('#delConfirm').addEventListener('click', async () => {
    const p = byId(S.delFor); if (!p) return;
    const btn = $('#delConfirm'); btn.classList.add('is-loading');
    let ok = true;
    if (db) ok = await guard(db.doc('projects/' + p.id).delete());
    else S.projects = S.projects.filter((x) => x.id !== p.id);
    btn.classList.remove('is-loading');
    if (!ok) return;
    if (p.logo && S.assets) S.assets.delete(p.logo).catch(() => {});
    $('#dlgDel').close(); toast(`Projekt ${p.name} odstraněn`);
    if (S.route === 'projekt-' + p.id) location.hash = 'projekty'; else if (!db) render();
  });

  /* ---------- users admin (Administrace › Uživatelé) ---------- */
  const ROLES = {
    user:     { t: 'Uživatel',      s: 'Vidí jen projekty sdílené s ním. Vyplňuje šablony a stahuje exporty.' },
    designer: { t: 'Designer',      s: 'Zakládá vlastní projekty, navrhuje šablony a sdílí je klientům.' },
    admin:    { t: 'Administrátor', s: 'Spravuje uživatele, žádosti a celý systém.' }
  };
  const SCOPES = [
    { k: 'templates:read',   t: 'Prohlížet projekty a šablony',  s: 'Základ pro všechno ostatní. Bez něj AI aplikace nic neuvidí.' },
    { k: 'templates:export', t: 'Stahovat hotové exporty',       s: 'Vyplní šablonu a stáhne výsledný obrázek.', need: true },
    { k: 'templates:design', t: 'Vytvářet a přepisovat šablony', s: 'Mění design šablon.', need: true, roles: ['designer', 'admin'] },
    { k: 'gallery:write',    t: 'Nahrávat obrázky do galerie',   s: 'Přidává obrázky do galerie projektu.', need: true }
  ];
  const AI_DEFAULT = { user: ['templates:read', 'templates:export'], designer: SCOPES.map((x) => x.k), admin: SCOPES.map((x) => x.k) };
  const MEU = { id: '__me', name: ME, email: '', role: 'admin', status: 'active', registeredAt: '2024-09-01', me: true };
  S.uq = ''; S.uf = 'all'; S.pf = null; S.pfOpen = false; S.pfq = ''; S.umenu = null; S.uhl = null; S.prof = null;

  const isoToday = () => { const d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); };
  const fmtDate = (s) => { if (!s) return '—'; const [y, m, d] = s.slice(0, 10).split('-').map(Number); return `${d}. ${m}. ${y}`; };
  const ago = (s) => {
    if (!s) return '';
    const [y, m, d] = s.slice(0, 10).split('-').map(Number);
    const t = new Date(); const n = Math.round((Date.UTC(t.getFullYear(), t.getMonth(), t.getDate()) - Date.UTC(y, m - 1, d)) / 864e5);
    return n <= 0 ? 'dnes' : n === 1 ? 'včera' : n < 30 ? `před ${n} dny` : fmtDate(s);
  };
  const uLabel = (u) => u.name || u.email || 'Bez jména';
  const uInit = (u) => initials(uLabel(u).replace(/@.*/, '').replace(/[._-]+/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase()));
  const uAv = (u) => u.status === 'invited' ? `<span class="av inv" aria-hidden="true"><span class="i i-mail" aria-hidden="true"></span></span>` : `<span class="av" aria-hidden="true">${esc(uInit(u))}</span>`;
  const isOwner = (u, p) => !!p.owner && (p.owner === u.name || (!!u.email && p.owner === u.email));
  const ownOf = (u) => S.projects.filter((p) => isOwner(u, p));
  const sharedOf = (u) => S.projects.filter((p) => (p.collaborators || []).includes(u.id));
  const projWord = (n) => n === 1 ? 'projekt' : n >= 2 && n <= 4 ? 'projekty' : 'projektů';
  const projCount = (n) => n + ' ' + projWord(n);
  const allUsers = () => [MEU].concat(S.users.map((u) => ({ role: 'user', status: u.invited ? 'invited' : 'active', ...u })));
  const uById = (id) => allUsers().find((u) => u.id === id);
  const ownerOf = (p) => p.owner === ME ? 'vy' : p.owner || '—';
  const accessOf = (p) => allUsers().filter((u) => isOwner(u, p) || (p.collaborators || []).includes(u.id));
  const roleHTML = (r) => r === 'admin' ? `<span class="u-role"><span class="i i-shield" aria-hidden="true"></span>Administrátor</span>`
    : r === 'designer' ? `<span class="u-role"><span class="i i-edit" aria-hidden="true"></span>Designer</span>` : `<span class="u-role">Uživatel</span>`;
  const rolePill = (r) => r === 'admin' ? `<span class="pill r-admin"><span class="i i-shield" aria-hidden="true"></span>Administrátor</span>` : r === 'designer' ? `<span class="pill r-designer"><span class="i i-edit" aria-hidden="true"></span>Designer</span>` : `<span class="pill r-user">Uživatel</span>`;
  const stHTML = (u) => u.status === 'invited' ? `<span class="u-st"><span class="pill">Pozvánka</span><span class="cap">${esc(ago(u.invitedAt))}</span></span>`
    : u.status === 'disabled' ? `<span class="u-st off"><span class="dot"></span>Pozastavený</span>` : `<span class="u-st"><span class="dot"></span>Aktivní</span>`;

  /* ---- list ---- */
  function uPasses(u) {
    const q = S.uq.trim().toLowerCase();
    if (q && !(u.name || '').toLowerCase().includes(q) && !(u.email || '').toLowerCase().includes(q)) return false;
    const isReq = u.status === 'request', isRej = u.status === 'rejected';
    if (S.uf === 'rejected') return isRej;
    if (isRej) return false;
    if (S.uf === 'requests') return isReq;
    if (S.pf) { if (isReq) return false; const p = byId(S.pf); if (p && !(isOwner(u, p) || (p.collaborators || []).includes(u.id))) return false; }
    if (S.uf === 'invited') return u.status === 'invited';
    if (S.uf !== 'all') return !isReq && u.role === S.uf;
    return true;
  }
  function renderUsers() {
    const v = $('#view');
    if (!v.querySelector('#uRows')) {
      v.innerHTML = `<div class="head"><div style="display:flex;flex-direction:column;gap:8px"><span class="lbl">Administrace</span><h1>Uživatelé <small id="uTotal"></small></h1></div>
        <div class="head-tools"><label class="inp" for="uSearch"><span class="i i-search s16" aria-hidden="true"></span><input type="search" id="uSearch" placeholder="Hledat jméno nebo e-mail" autocomplete="off"></label><button type="button" class="btn" id="uInviteBtn"><span class="i i-user-plus s16" aria-hidden="true"></span>Pozvat uživatele</button></div></div>
        <div class="fbar" id="ufbar" role="toolbar" aria-label="Filtr uživatelů"></div>
        <div class="u-card"><div class="u-lh" aria-hidden="true"><span>Uživatel</span><span class="u-tags"><span>Role</span><span>Stav</span></span><span></span></div><ul class="u-list" id="uRows" aria-label="Uživatelé"></ul><div class="u-foot" id="uFoot"></div></div>`;
      $('#uSearch').value = S.uq;
      $('#uSearch').addEventListener('input', (e) => { S.uq = e.target.value; renderUserRows(); });
      $('#uInviteBtn').addEventListener('click', () => openInvite());
    }
    renderUserRows();
  }
  function renderUserFilters(all) {
    const n = (pred) => all.filter(pred).length;
    const chip = (k, label, c) => `<button type="button" class="chip${S.uf === k ? ' on' : ''}" data-uf="${k}" aria-pressed="${S.uf === k}">${label} <span class="n">${c}</span></button>`;
    const p = S.pf && byId(S.pf);
    const mem = (u) => u.status !== 'request' && u.status !== 'rejected';
    const nReq = n((u) => u.status === 'request');
    $('#ufbar').innerHTML = chip('all', 'Vše', n((u) => u.status !== 'rejected')) +
      `<button type="button" class="chip req-chip${S.uf === 'requests' ? ' on' : ''}${nReq ? ' has' : ''}" data-uf="requests" aria-pressed="${S.uf === 'requests'}"><span class="i i-user-plus s14" aria-hidden="true"></span>Žádosti <span class="n">${nReq}</span></button>` +
      chip('rejected', 'Zamítnuté', n((u) => u.status === 'rejected')) + '<span class="sep" aria-hidden="true"></span>' +
      chip('admin', 'Administrátoři', n((u) => mem(u) && u.role === 'admin')) + chip('designer', 'Designeři', n((u) => mem(u) && u.role === 'designer')) + chip('user', 'Uživatelé', n((u) => mem(u) && u.role === 'user')) +
      '<span class="sep" aria-hidden="true"></span>' + chip('invited', '<span class="i i-mail s14" aria-hidden="true"></span>Čekají na přijetí', n((u) => u.status === 'invited')) +
      '<span class="sep" aria-hidden="true"></span>' +
      `<div class="fdrop"><button type="button" class="chip pf-chip${p ? ' on' : ''}" id="pfBtn" aria-haspopup="dialog" aria-expanded="${S.pfOpen}" aria-pressed="${!!p}">${p ? symbol(p, 'sm') + `<span class="nm">${esc(p.name)}</span>` : '<span class="i i-grid s14" aria-hidden="true"></span>Projekt'}<span class="i i-chev-down s14" aria-hidden="true"></span></button>
        ${S.pfOpen ? `<div class="menu pf-menu" role="dialog" aria-label="Vybrat projekt"><label class="inp sm" for="pfSearch" style="margin-bottom:4px"><span class="i i-search s14" aria-hidden="true"></span><input type="search" id="pfSearch" placeholder="Najít projekt" autocomplete="off" aria-controls="pfList"></label><div class="pf-list" id="pfList" role="listbox" aria-label="Projekty"></div></div>` : ''}</div>`;
    if (S.pfOpen) {
      const inp = $('#pfSearch'); inp.value = S.pfq;
      inp.addEventListener('input', (e) => { S.pfq = e.target.value; renderPfList(); });
      inp.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') { e.stopPropagation(); S.pfOpen = false; renderUserRows(); $('#pfBtn').focus(); }
        if (e.key === 'Enter') { e.preventDefault(); const f = $('#pfList [data-pf]:not([data-pf=""])') || $('#pfList [data-pf]'); if (f) f.click(); }
      });
      renderPfList(); inp.focus({ preventScroll: true });
    }
  }
  function renderPfList() {
    const q = S.pfq.trim().toLowerCase();
    const list = sorted().filter((p) => !q || p.name.toLowerCase().includes(q));
    const tick = '<span class="chk" aria-hidden="true"><span class="i i-check"></span></span>';
    const all = q ? '' : `<button type="button" role="option" class="qn" aria-selected="${!S.pf}" data-pf=""><span class="sym sm mono" aria-hidden="true"><span class="i i-grid s14"></span></span><span class="nm">Všechny projekty</span><span class="end">${!S.pf ? tick : ''}</span></button><div class="menu-sep"></div>`;
    $('#pfList').innerHTML = all + (list.map((p) => `<button type="button" role="option" class="qn" aria-selected="${S.pf === p.id}" data-pf="${esc(p.id)}">${symbol(p, 'sm')}<span class="nm">${esc(p.name)}</span><span class="end">${S.pf === p.id ? tick : accessOf(p).length}</span></button>`).join('') || `<p class="cap" style="padding:10px">Žádný projekt neodpovídá hledání.</p>`);
  }
  function renderUserRows() {
    const tb = $('#uRows'); if (!tb) return;
    const all = allUsers();
    $('#uTotal').textContent = all.length;
    renderUserFilters(all);
    const pfp = S.pf && byId(S.pf);
    const rank = (u) => u.status === 'request' ? 0 : u.me ? 1 : u.status === 'invited' ? 2 : 3;
    const list = all.filter(uPasses).sort((a, b) => rank(a) - rank(b) || uLabel(a).localeCompare(uLabel(b), 'cs'));
    tb.innerHTML = list.map((u) => {
      if (u.status === 'request' || u.status === 'rejected') return reqRowHTML(u);
      const open = S.umenu === u.id;
      const acts = u.me ? '<span></span>' : `<div class="u-acts"><button type="button" class="btn icon xs ghost" data-umenu="${esc(u.id)}" aria-haspopup="menu" aria-expanded="${open}" aria-label="Akce: ${esc(uLabel(u))}"><span class="i i-more s16" aria-hidden="true"></span></button>${open ? `<div class="menu" role="menu">
          <a href="#uzivatel-${esc(u.id)}" role="menuitem" class="mi"><span class="i i-edit s16" aria-hidden="true"></span>Upravit profil</a>
          ${u.status === 'invited' ? `<button type="button" role="menuitem" class="mi" data-uact="copy"><span class="i i-link s16" aria-hidden="true"></span>URL pozvánky</button><button type="button" role="menuitem" class="mi" data-uact="resend"><span class="i i-mail s16" aria-hidden="true"></span>Odeslat pozvánku</button>`
            : `<div class="menu-sep"></div><button type="button" role="menuitem" class="mi danger" data-uact="delete"><span class="i i-trash s16" aria-hidden="true"></span>Odebrat uživatele</button>`}</div>` : ''}</div>`;
      const ownTag = pfp && isOwner(u, pfp) ? '<span class="pill pch">Vlastník</span>' : '';
      const name = `${esc(uLabel(u))}${u.me ? ' <span style="font-weight:500;color:var(--subtle)">(vy)</span>' : ''}`;
      return `<li class="u-row${S.uhl === u.id ? ' hl' : ''}${open ? ' menu-open' : ''}" data-uid="${esc(u.id)}">
        <div class="u-who">${uAv(u)}<span class="who" style="flex-direction:row;align-items:center">${u.me ? `<a class="u-name" href="#ucet">${name}</a>` : `<a class="u-name" href="#uzivatel-${esc(u.id)}">${name}</a>`}${ownTag}</span></div>
        <div class="u-tags">${roleHTML(u.role)}${stHTML(u)}</div>${acts}</li>`;
    }).join('') || `<li class="u-empty">${pfp ? `K projektu ${esc(pfp.name)} nemá přístup nikdo, kdo odpovídá filtru.` : 'Nikdo neodpovídá hledání ani filtru.'}</li>`;
    updateReqCount();
    $('#uFoot').innerHTML = `<span>Zobrazeno ${list.length} z ${all.filter((u) => u.status !== 'rejected').length}</span>${pfp ? `<span>Přístup k projektu ${esc(pfp.name)}</span>` : ''}`;
  }
  /* registration requests live in the same list: pinned to the top with inline Zamítnout / Povolit; rejected ones move to the Zamítnuté filter */
  function reqRowHTML(u) {
    const rej = u.status === 'rejected';
    const sub = [u.email, u.note].filter(Boolean).map(esc).join(' · ');
    return `<li class="u-row ${rej ? 'rej' : 'req'}${S.uhl === u.id ? ' hl' : ''}" data-uid="${esc(u.id)}">
      <div class="u-who"><span class="av ${rej ? 'inv' : 'req'}" aria-hidden="true"><span class="i i-user-plus" aria-hidden="true"></span></span><span class="who"><span class="u-nm">${esc(uLabel(u))}</span>${u.name && sub ? `<span class="u-sub">${sub}</span>` : u.note ? `<span class="u-sub">${esc(u.note)}</span>` : ''}</span></div>
      <div class="u-tags"><span class="u-role" style="color:var(--subtle)">Žádost o registraci</span>${rej ? `<span class="u-st off"><span class="dot"></span>Zamítnuto ${esc(fmtDate(u.rejectedAt))}</span>` : `<span class="u-st"><span class="pill mint">Nová žádost</span><span class="cap">${esc(ago(u.requestedAt))}</span></span>`}</div>
      <div class="u-acts req-acts">${rej ? '' : `<button type="button" class="btn xs tertiary" data-uact="reject">Zamítnout</button>`}<button type="button" class="btn xs" data-uact="approve"><span class="i i-check s14" aria-hidden="true"></span>Povolit</button></div></li>`;
  }
  function updateReqCount() {
    const el = $('#countReq'); if (!el) return;
    const n = S.users.filter((u) => u.status === 'request').length;
    el.textContent = n; el.hidden = !n;
    const a = el.closest('a'); if (a) a.setAttribute('aria-label', n ? `Uživatelé, ${n} ${n === 1 ? 'nová žádost' : n <= 4 ? 'nové žádosti' : 'nových žádostí'}` : 'Uživatelé');
  }
  async function approveRequest(id) {
    const u = uById(id); if (!u) return;
    const upd = { status: 'active', registeredAt: isoToday(), role: u.role || 'user', ai: AI_DEFAULT[u.role || 'user'].slice(), invited: false };
    const ok = db ? await guard(db.doc('users/' + id).update(upd)) : (Object.assign(S.users.find((x) => x.id === id), upd), true);
    if (!ok) return;
    toast(`${uLabel(u)} má přístup do Wboostu`);
    S.uhl = id; renderUserRows(); setTimeout(() => { if (S.uhl === id) { S.uhl = null; renderUserRows(); } }, 2400);
  }
  async function rejectRequest(id) {
    const u = uById(id); if (!u) return;
    const upd = { status: 'rejected', rejectedAt: isoToday() };
    const ok = db ? await guard(db.doc('users/' + id).update(upd)) : (Object.assign(S.users.find((x) => x.id === id), upd), true);
    if (ok) { toast(`Žádost ${uLabel(u)} zamítnuta · najdete ji ve filtru Zamítnuté`); renderUserRows(); }
  }
  function setUMenu(id) { S.umenu = id; renderUserRows(); if (id) { const f = document.querySelector('.u-acts .menu .mi'); if (f) f.focus({ preventScroll: true }); } }

  $('#view').addEventListener('click', (e) => {
    if (S.route !== 'uzivatele') return;
    const f = e.target.closest('[data-uf]'); if (f) { S.uf = f.dataset.uf; S.umenu = null; renderUserRows(); return; }
    if (e.target.closest('#pfBtn')) { S.pfOpen = !S.pfOpen; S.pfq = ''; renderUserRows(); return; }
    const pf = e.target.closest('[data-pf]'); if (pf) { S.pf = pf.dataset.pf || null; S.pfOpen = false; S.pfq = ''; renderUserRows(); $('#pfBtn').focus(); return; }
    const m = e.target.closest('[data-umenu]'); if (m) { setUMenu(S.umenu === m.dataset.umenu ? null : m.dataset.umenu); return; }
    const a = e.target.closest('[data-uact]');
    if (a) { const id = a.closest('[data-uid]').dataset.uid; S.umenu = null; renderUserRows(); const k = a.dataset.uact; if (k === 'resend') resendInvite(id); else if (k === 'copy') copyInvite(id); else if (k === 'approve') approveRequest(id); else if (k === 'reject') rejectRequest(id); else openUserDel(id); }
  });
  $('#view').addEventListener('keydown', (e) => {
    if (S.route !== 'uzivatele' || e.key !== 'Escape' || !S.umenu) return;
    const id = S.umenu; setUMenu(null); const b = document.querySelector(`[data-umenu="${CSS.escape(id)}"]`); if (b) b.focus();
  });
  document.addEventListener('pointerdown', (e) => {
    if (S.umenu && !e.target.closest('.u-acts')) setUMenu(null);
    if (S.pfOpen && !e.target.closest('.fdrop')) { S.pfOpen = false; renderUserRows(); }
  });

  /* ---- role picker — radio cards (invite + profile); updates in place so a click is never swallowed by a re-render ---- */
  function renderRoles(el, val, onPick) {
    if (el.children.length) {
      el.querySelectorAll('[data-role]').forEach((b) => { const on = b.dataset.role === val; b.setAttribute('aria-checked', String(on)); b.tabIndex = on ? 0 : -1; b.querySelector('.rd').classList.toggle('on', on); });
    } else el.innerHTML = Object.entries(ROLES).map(([k, r]) => `<button type="button" role="radio" class="rcard" data-role="${k}" aria-checked="${k === val}" tabindex="${k === val ? 0 : -1}"><span class="rd${k === val ? ' on' : ''}" aria-hidden="true"></span><span class="rtx"><span class="rt">${r.t}</span><span class="rsub">${r.s}</span></span></button>`).join('');
    el.onclick = (e) => { const b = e.target.closest('[data-role]'); if (b) { onPick(b.dataset.role); const n = el.querySelector('[aria-checked="true"]'); if (n) n.focus(); } };
    el.onkeydown = (e) => {
      const keys = Object.keys(ROLES), i = keys.indexOf(val);
      if (e.key === 'ArrowRight' || e.key === 'ArrowDown') { e.preventDefault(); onPick(keys[(i + 1) % keys.length]); el.querySelector('[aria-checked="true"]').focus(); }
      else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') { e.preventDefault(); onPick(keys[(i + keys.length - 1) % keys.length]); el.querySelector('[aria-checked="true"]').focus(); }
    };
  }

  /* ---- project picker — same pattern as the share dialog (combobox + list) ---- */
  function projPicker(o) {
    const st = { q: '', act: 0, open: false, opts: [] };
    const inp = $(o.input), sug = $(o.sug), field = $(o.field);
    const options = () => {
      const q = st.q.trim().toLowerCase(), taken = new Set(o.get()), owned = new Set(o.owned ? o.owned() : []);
      return sorted().filter((p) => !taken.has(p.id) && !owned.has(p.id) && (!q || p.name.toLowerCase().includes(q) || (p.owner || '').toLowerCase().includes(q)));
    };
    function draw() {
      const opts = st.open ? options() : [];
      if (st.act >= opts.length) st.act = Math.max(0, opts.length - 1);
      st.opts = opts; sug.hidden = !st.open; inp.setAttribute('aria-expanded', String(st.open));
      if (st.open) {
        sug.innerHTML = opts.map((p, i) => `<button type="button" role="option" id="${o.sug.slice(1)}-${i}" class="mi${i === st.act ? ' act' : ''}" data-pid="${esc(p.id)}">${symbol(p, 'sm')}<span class="who"><b>${esc(p.name)}</b><span>Vlastník: ${esc(ownerOf(p))}</span></span><span class="i i-plus s16" aria-hidden="true"></span></button>`).join('')
          || `<p class="share-empty">${st.q.trim() ? 'Žádný projekt neodpovídá.' : 'Všechny projekty už jsou vybrané.'}</p>`;
        inp.setAttribute('aria-activedescendant', opts.length ? `${o.sug.slice(1)}-${st.act}` : '');
        const a = document.getElementById(`${o.sug.slice(1)}-${st.act}`); if (a) a.scrollIntoView({ block: 'nearest' });
      }
      o.onChange();
    }
    const add = (id) => { o.set(o.get().concat(id)); st.q = ''; inp.value = ''; st.open = false; st.act = 0; S.pflash = id; draw(); S.pflash = null; inp.focus(); };
    inp.addEventListener('click', () => { if (!st.open) { st.open = true; draw(); } });
    inp.addEventListener('input', () => { st.q = inp.value; st.act = 0; st.open = true; draw(); });
    inp.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowDown') { e.preventDefault(); st.open = true; st.act = Math.min(Math.max(0, options().length - 1), st.act + (sug.hidden ? 0 : 1)); draw(); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); st.act = Math.max(0, st.act - 1); draw(); }
      else if (e.key === 'Enter') { e.preventDefault(); const p = st.opts[st.act]; if (st.open && p) add(p.id); }
      else if (e.key === 'Escape' && st.open) { e.preventDefault(); e.stopPropagation(); st.open = false; draw(); }
    });
    sug.addEventListener('click', (e) => { const b = e.target.closest('[data-pid]'); if (b) add(b.dataset.pid); });
    const outside = (e) => { if (!field.isConnected) { document.removeEventListener('pointerdown', outside); return; } if (st.open && !field.contains(e.target)) { st.open = false; draw(); } };
    document.addEventListener('pointerdown', outside);
    return { reset() { st.q = ''; st.open = false; st.act = 0; inp.value = ''; draw(); }, draw };
  }
  const projRow = (p, { owner, isNew, removable }) => `<li class="share-row${isNew === 'flash' ? ' flash' : ''}">${symbol(p, 'sm')}<span class="who"><a href="#projekt-${esc(p.id)}" style="all:unset;cursor:pointer"><b>${esc(p.name)}</b></a><span>${owner ? 'Vlastní projekt' : 'Vlastník: ' + esc(ownerOf(p))}</span></span>${owner ? '<span class="pill pch">Vlastník</span>' : isNew ? '<span class="pill mint">Nový</span>' : ''}${removable ? `<button type="button" class="btn icon xs ghost" data-unpid="${esc(p.id)}" aria-label="Odebrat přístup k projektu ${esc(p.name)}" title="Odebrat přístup"><span class="i i-x s16" aria-hidden="true"></span></button>` : ''}</li>`;

  /* ---- Invite dialog ---- */
  S.inv = null;
  const invPick = projPicker({
    input: '#invProjSearch', sug: '#invProjSug', field: '#invProjField',
    get: () => (S.inv ? S.inv.projects : []), set: (v) => { S.inv.projects = v; },
    onChange: () => renderInvite()
  });
  function openInvite(prefill) {
    S.inv = { role: 'user', projects: S.pf ? [S.pf] : [], touched: false };
    $('#invEmail').value = prefill || ''; $('#invName').value = '';
    invPick.reset(); renderInvite();
    $('#dlgInvite').showModal(); $('#invEmail').focus();
  }
  function invEmailState() {
    const v = $('#invEmail').value.trim().toLowerCase();
    if (!v) return { ok: false, msg: 'Zadejte e-mail.' };
    if (!isEmail(v)) return { ok: false, msg: 'Tohle nevypadá jako e-mail.' };
    const dup = S.users.find((u) => (u.email || '').toLowerCase() === v);
    if (dup) return { ok: false, dup: dup.status === 'request' || dup.status === 'rejected' ? null : dup, msg: dup.status === 'request' ? 'Tento e-mail už požádal o registraci — schvalte ji v seznamu.' : dup.status === 'rejected' ? 'Žádost tohoto e-mailu byla zamítnuta — povolte ji ve filtru Zamítnuté.' : dup.status === 'invited' || dup.invited ? 'Na tento e-mail už pozvánka odešla.' : 'Uživatel s tímto e-mailem už existuje.' };
    return { ok: true, v };
  }
  function renderInvite() {
    const iv = S.inv; if (!iv) return;
    renderRoles($('#invRoles'), iv.role, (r) => { iv.role = r; renderInvite(); });
    const list = $('#invProjList');
    list.hidden = !iv.projects.length;
    list.innerHTML = iv.projects.map((id) => byId(id)).filter(Boolean).map((p) => projRow(p, { removable: true, isNew: S.pflash === p.id ? 'flash' : false })).join('');
    $('#invProjCount').textContent = iv.projects.length ? projCount(iv.projects.length) : '';
    const es = invEmailState();
    const show = iv.touched && !es.ok;
    $('#invEmailBox').classList.toggle('err', show);
    const err = $('#invEmailErr'); err.hidden = !show;
    if (show) err.innerHTML = esc(es.msg) + (es.dup ? ` <button type="button" class="btn xs ghost" id="invOpenDup" style="height:24px;padding:0 8px;margin-left:4px">Otevřít profil</button>` : '');
    $('#invSubmit').disabled = !es.ok;
  }
  $('#invEmail').addEventListener('input', () => { if (S.inv) { if (invEmailState().dup) S.inv.touched = true; renderInvite(); } });
  $('#invEmail').addEventListener('blur', () => { if (S.inv && $('#invEmail').value.trim()) { S.inv.touched = true; renderInvite(); } });
  $('#invEmailErr').addEventListener('click', (e) => { if (e.target.closest('#invOpenDup')) { const d = invEmailState().dup; $('#dlgInvite').close(); if (d) location.hash = 'uzivatel-' + d.id; } });
  $('#invProjList').addEventListener('click', (e) => { const b = e.target.closest('[data-unpid]'); if (b && S.inv) { S.inv.projects = S.inv.projects.filter((x) => x !== b.dataset.unpid); renderInvite(); } });
  $('#dlgInvite').addEventListener('close', () => { S.inv = null; });
  $('#formInvite').addEventListener('submit', async (e) => {
    e.preventDefault();
    const iv = S.inv; if (!iv) return;
    iv.touched = true;
    const es = invEmailState(); if (!es.ok) { renderInvite(); $('#invEmail').focus(); return; }
    const btn = $('#invSubmit'); btn.classList.add('is-loading');
    const id = slug(es.v) + '-' + Math.random().toString(36).slice(2, 6);
    const doc = { name: $('#invName').value.trim(), email: es.v, role: iv.role, status: 'invited', invited: true, invitedAt: isoToday(), ai: AI_DEFAULT[iv.role].slice() };
    let ok = true;
    if (db) {
      ok = await guard(db.doc('users/' + id).set(doc));
      for (const pid of iv.projects) { if (!ok) break; const p = byId(pid); ok = await guard(db.doc('projects/' + pid).update({ collaborators: (p.collaborators || []).concat(id) })); }
    } else { S.users.push({ id, ...doc }); iv.projects.forEach((pid) => { const p = byId(pid); p.collaborators = (p.collaborators || []).concat(id); }); }
    btn.classList.remove('is-loading');
    if (!ok) return;
    $('#dlgInvite').close();
    toast(`Pozvánka odeslána na ${es.v}`);
    S.uhl = id; S.uf = 'all'; S.uq = ''; const us = $('#uSearch'); if (us) us.value = '';
    renderUserRows();
    setTimeout(() => { if (S.uhl === id) { S.uhl = null; renderUserRows(); } }, 2400);
  });

  /* ---- User detail — full-window popup (#uzivatel-<id> opens it over the list) ---- */
  const sameSet = (a, b) => a.length === b.length && a.every((x) => b.includes(x));
  function profBase(u) {
    const shared = sharedOf(u).map((p) => p.id);
    return { id: u.id, u, name: u.name || '', role: u.role || 'user', ai: (u.ai || AI_DEFAULT[u.role || 'user']).slice(), shared: shared.slice(), origShared: shared, own: ownOf(u).map((p) => p.id) };
  }
  function profDirty() {
    const e = S.prof; if (!e) return false; const u = e.u;
    return e.name.trim() !== (u.name || '') || e.role !== (u.role || 'user') || !sameSet(e.shared, e.origShared) || !sameSet(e.ai, u.ai || AI_DEFAULT[u.role || 'user']);
  }
  const profPick = projPicker({
    input: '#upProjSearch', sug: '#upProjSug', field: '#upProjField',
    get: () => (S.prof ? S.prof.shared : []), set: (val) => { S.prof.shared = val; },
    owned: () => (S.prof ? S.prof.own : []),
    onChange: () => renderProfileParts()
  });
  function openProfile(id) {
    const u = uById(id), dlg = $('#dlgProf');
    if (!u || u.me) { if (S.loaded) { toast('Uživatel neexistuje, mohl být odebrán.', true); location.hash = 'uzivatele'; } return; }
    if (dlg.open && S.prof && S.prof.id === id) {
      if (!profDirty()) { S.prof = profBase(u); $('#upName').value = S.prof.name; } else S.prof.u = u;
      renderProfileParts(); return;
    }
    S.prof = profBase(u);
    $('#upName').value = S.prof.name;
    profPick.reset();
    $('#formProf .dlg-scroll').scrollTop = 0;
    if (!dlg.open) dlg.showModal();
    $('#upName').focus({ preventScroll: true });
  }
  function renderProfileParts() {
    const e = S.prof; if (!e) return;
    const u = e.u, inv = u.status === 'invited';
    $('#upHead').innerHTML = `${uAv(u)}<div class="up-id"><span class="lbl">Uživatel</span><h2 id="upTitle">${esc(uLabel(u))}</h2>
      <div class="up-meta">${rolePill(u.role)}${stHTML(u)}${u.name && u.email ? `<span>${esc(u.email)}</span>` : ''}<span>${inv ? 'Pozvánka odeslána ' + esc(fmtDate(u.invitedAt)) : 'Registrován ' + esc(fmtDate(u.registeredAt))}</span></div></div>`;
    $('#upEmail').textContent = u.email || 'E-mail chybí';
    renderRoles($('#upRoles'), e.role, (r) => {
      e.role = r;
      SCOPES.forEach((s) => { if (s.roles && !s.roles.includes(r)) e.ai = e.ai.filter((x) => x !== s.k); });
      renderProfileParts();
    });
    $('#upProjList').innerHTML = e.own.map((pid) => byId(pid)).filter(Boolean).map((p) => projRow(p, { owner: true }))
      .concat(e.shared.map((pid) => byId(pid)).filter(Boolean).map((p) => projRow(p, { removable: true, isNew: S.pflash === p.id ? 'flash' : !e.origShared.includes(p.id) })))
      .join('') || `<li class="share-empty">Uživatel zatím nemá přístup k žádnému projektu. Přidejte ho výše.</li>`;
    $('#upProjCount').textContent = `${e.own.length} vlastní · ${e.shared.length} sdílené`;
    const read = e.ai.includes('templates:read');
    $('#upScopes').innerHTML = SCOPES.map((s) => {
      const roleOk = !s.roles || s.roles.includes(e.role);
      const dis = !roleOk || (s.need && !read);
      const on = e.ai.includes(s.k) && !dis;
      const why = !roleOk ? 'Jen pro role Designer a Administrátor.' : s.need && !read ? 'Nejdřív povolte prohlížení projektů.' : s.s;
      return `<button type="button" class="scope" role="checkbox" aria-checked="${on}" aria-disabled="${dis}" data-scope="${s.k}"><span class="cb${on ? ' on' : ''}" aria-hidden="true"><span class="i i-check"></span></span><span class="who"><b>${s.t}</b><span>${esc(why)} <code>${s.k}</code></span></span></button>`;
    }).join('');
    $('#upCopy').hidden = !inv; $('#upResend').hidden = !inv; $('#upRemove').hidden = inv;
    $('#upSaveBtn').disabled = !profDirty();
  }
  $('#upName').addEventListener('input', (ev) => { if (S.prof) { S.prof.name = ev.target.value; renderProfileParts(); } });
  $('#upScopes').addEventListener('click', (ev) => {
    const sc = ev.target.closest('[data-scope]'); const e = S.prof;
    if (!sc || !e || sc.getAttribute('aria-disabled') === 'true') return;
    const k = sc.dataset.scope;
    if (e.ai.includes(k)) e.ai = k === 'templates:read' ? [] : e.ai.filter((x) => x !== k); else e.ai = e.ai.concat(k);
    renderProfileParts(); const nb = $(`[data-scope="${k}"]`); if (nb) nb.focus();
  });
  $('#upProjList').addEventListener('click', (ev) => { const un = ev.target.closest('[data-unpid]'); if (un && S.prof) { S.prof.shared = S.prof.shared.filter((x) => x !== un.dataset.unpid); renderProfileParts(); } });
  $('#upResend').addEventListener('click', () => { if (S.prof) resendInvite(S.prof.id); });
  $('#upCopy').addEventListener('click', () => { if (S.prof) copyInvite(S.prof.id); });
  $('#upRemove').addEventListener('click', () => { if (S.prof) openUserDel(S.prof.id); });
  /* closing the popup returns the address to the list, so Back and reload behave */
  $('#dlgProf').addEventListener('close', () => { S.prof = null; if (S.route.startsWith('uzivatel-')) location.hash = 'uzivatele'; });
  $('#formProf').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const e = S.prof; if (!e || !profDirty()) return;
    const btn = $('#upSaveBtn'); btn.classList.add('is-loading');
    const upd = { name: e.name.trim(), role: e.role, ai: e.ai.slice() };
    const added = e.shared.filter((x) => !e.origShared.includes(x)), removed = e.origShared.filter((x) => !e.shared.includes(x));
    let ok = true;
    if (db) {
      ok = await guard(db.doc('users/' + e.id).update(upd));
      for (const pid of added.concat(removed)) {
        if (!ok) break; const p = byId(pid); if (!p) continue;
        const c = p.collaborators || [];
        ok = await guard(db.doc('projects/' + pid).update({ collaborators: added.includes(pid) ? c.concat(e.id) : c.filter((x) => x !== e.id) }));
      }
    } else {
      Object.assign(S.users.find((x) => x.id === e.id), upd);
      added.forEach((pid) => { const p = byId(pid); p.collaborators = (p.collaborators || []).concat(e.id); });
      removed.forEach((pid) => { const p = byId(pid); p.collaborators = (p.collaborators || []).filter((x) => x !== e.id); });
    }
    btn.classList.remove('is-loading');
    if (!ok) return;
    const name = uLabel({ ...e.u, ...upd });
    $('#dlgProf').close(); toast(`Změny u ${name} uloženy`);
  });

  /* invitation link — in the prototype a stand-in URL; the backend issues the real token */
  async function copyInvite(id) {
    const u = uById(id); if (!u) return;
    const url = 'https://wboost.cz/pozvanka/' + encodeURIComponent(id);
    try { await navigator.clipboard.writeText(url); toast('URL pozvánky zkopírována'); }
    catch (e) { toast('URL nejde zkopírovat automaticky: ' + url, true); }
  }
  async function resendInvite(id) {
    const u = uById(id); if (!u) return;
    const ok = db ? await guard(db.doc('users/' + id).update({ invitedAt: isoToday() })) : (Object.assign(S.users.find((x) => x.id === id), { invitedAt: isoToday() }), true);
    if (ok) toast(`Pozvánka odeslána na ${u.email}`);
  }

  /* ---- Remove user / withdraw invitation ---- */
  function openUserDel(id) {
    const u = uById(id); if (!u) return;
    S.udel = id;
    const inv = u.status === 'invited', own = ownOf(u).length, sh = sharedOf(u).length;
    $('#dlgUserDelT').textContent = inv ? `Stáhnout pozvánku pro ${uLabel(u)}?` : `Odebrat uživatele ${uLabel(u)}?`;
    $('#dlgUserDelText').textContent = inv ? 'Odkaz v e-mailu přestane platit. Pozvat ho můžete později znovu.'
      : [sh ? `Ztratí přístup ke ${sh} ${sh === 1 ? 'sdílenému projektu' : 'sdíleným projektům'}.` : 'Nemá přístup k žádnému sdílenému projektu.', own ? `Jeho vlastní ${projWord(own)} (${own}) převezmete vy.` : '', 'Tuto akci nelze vrátit.'].filter(Boolean).join(' ');
    $('#userDelConfirm').textContent = inv ? 'Stáhnout pozvánku' : 'Odebrat';
    $('#dlgUserDel').showModal();
  }
  $('#userDelConfirm').addEventListener('click', async () => {
    const u = uById(S.udel); if (!u) return;
    const btn = $('#userDelConfirm'); btn.classList.add('is-loading');
    const sh = sharedOf(u), own = ownOf(u);
    let ok = true;
    if (db) {
      for (const p of sh) { if (!ok) break; ok = await guard(db.doc('projects/' + p.id).update({ collaborators: (p.collaborators || []).filter((x) => x !== u.id) })); }
      for (const p of own) { if (!ok) break; ok = await guard(db.doc('projects/' + p.id).update({ owner: ME })); }
      if (ok) ok = await guard(db.doc('users/' + u.id).delete());
    } else {
      sh.forEach((p) => { p.collaborators = p.collaborators.filter((x) => x !== u.id); });
      own.forEach((p) => { p.owner = ME; });
      S.users = S.users.filter((x) => x.id !== u.id);
    }
    btn.classList.remove('is-loading');
    if (!ok) return;
    $('#dlgUserDel').close();
    toast(u.status === 'invited' ? `Pozvánka pro ${uLabel(u)} stažena` : `Uživatel ${uLabel(u)} odebrán`);
    if ($('#dlgProf').open && S.prof && S.prof.id === u.id) $('#dlgProf').close(); else renderUserRows();
  });

  /* ---------- statistics (Administrace › Statistiky) — a sales tool per client ----------
     Tab Klienti (default): every client × last 12 months as a heatmap, with alerts; click → client detail popup (#statistiky-<id>).
     Tab Celý systém: downloads trend, output types, top templates, storage.
     Charts are inline SVG built at the container's real width (re-drawn on resize), hover = guide line + tooltip.
     The prototype generates repeatable sample data (seeded); the backend supplies real aggregates:
     downloads per client × month × template (+ template type), last download date, nightly storage scan. */
  S.st = { tab: 'kl', period: 12, f: 'all', scanned: '2026-10-30T02:30', open: null };
  const ST_NOW = new Date(2026, 9, 1); // last month in the data = říjen 2026 (current, still running)
  const MONTHS_CS = ['led', 'úno', 'bře', 'dub', 'kvě', 'čvn', 'čvc', 'srp', 'zář', 'říj', 'lis', 'pro'];
  const MONTHS_LONG = ['leden', 'únor', 'březen', 'duben', 'květen', 'červen', 'červenec', 'srpen', 'září', 'říjen', 'listopad', 'prosinec'];
  const MONTHS_IN = ['v lednu', 'v únoru', 'v březnu', 'v dubnu', 'v květnu', 'v červnu', 'v červenci', 'v srpnu', 'v září', 'v říjnu', 'v listopadu', 'v prosinci'];
  const MONTHS_OD = ['od ledna', 'od února', 'od března', 'od dubna', 'od května', 'od června', 'od července', 'od srpna', 'od září', 'od října', 'od listopadu', 'od prosince'];
  const TYPES = ['Sociální sítě', 'Tisk', 'Prezentace', 'Web a e-mail'];
  const TYPE_ICON = { 'Sociální sítě': 'i-share', Tisk: 'i-file', Prezentace: 'i-template', 'Web a e-mail': 'i-mail' };
  const TEMPLATES = [['Příspěvek 1:1', 'Sociální sítě'], ['Story 9:16', 'Sociální sítě'], ['Facebook cover', 'Sociální sítě'], ['Plakát A4', 'Tisk'], ['Leták A5', 'Tisk'], ['Pozvánka', 'Tisk'], ['Vizitka', 'Tisk'], ['Prezentace 16:9', 'Prezentace'], ['E-mailový podpis', 'Web a e-mail'], ['Banner na web', 'Web a e-mail']];
  const ST_EXTRA = { ilichnova: ['frydlant-nad-ostravici'], 'nikola-heidrich': ['has'], 'podatelna-komorni-lhotka': ['komorni-lhotka'], faceceladna: ['celadna'], 'karla-hrivova': ['terlicko'], ndbrusperk: ['brusperk'], 'jan-mikes': ['mikes'] };
  const ST_WEIGHT = { 'adrian-hutyra': .9, 'marek-adamec': .7, 'zuzana-pavliskova': .55, 'roman-skuplik': .5, ilichnova: .45, 'daniel-kajfos': .4, 'marcel-sikora': .35, ndbrusperk: .35, 'katerina-peluchova': .3, faceceladna: .3, 'nikola-heidrich': .28, karin: .25, 'reditelka-msjanackova': .25, 'andrej-nagy': .2, 'projekty-sssfno': .2, 'martin-bohm': .12, 'podatelna-komorni-lhotka': .1, 'karla-hrivova': .08, 'jan-mikes': .06 };
  /* sample shapes so every alert type shows up */
  const ST_SHAPE = { 'martin-bohm': (m, i) => (i >= 7 ? 0 : m), 'karla-hrivova': (m, i) => (i >= 9 ? 0 : m), 'andrej-nagy': (m, i) => (i === 10 ? 0 : m), 'reditelka-msjanackova': (m, i) => (i >= 10 ? 0 : m), 'nikola-heidrich': (m, i) => (i >= 8 ? m * .25 : m * 1.4), karin: (m, i) => (i >= 8 ? m * 2.2 : m * .5), 'marek-adamec': (m, i) => (i >= 9 ? m * 1.6 : m) };
  const ME_SERIES = [3, 2, 1, 4, 6, 4, 6, 5, 9, 14, 19, 15];
  const seeded = (str) => { let h = 2166136261; for (const c of str) { h ^= c.charCodeAt(0); h = Math.imul(h, 16777619); } return () => { h ^= h << 13; h ^= h >>> 17; h ^= h << 5; return ((h >>> 0) % 10000) / 10000; }; };
  const monthKeys = () => Array.from({ length: 12 }, (_, i) => { const d = new Date(ST_NOW.getFullYear(), ST_NOW.getMonth() - 11 + i, 1); return { y: d.getFullYear(), m: d.getMonth() }; });
  const fmtN = (n) => Math.round(n).toLocaleString('cs-CZ');
  const fmtMB = (n) => (n >= 100 ? Math.round(n) : Math.round(n * 10) / 10).toLocaleString('cs-CZ') + ' MB';
  const sum = (a) => a.reduce((x, y) => x + y, 0);
  const pct = (a, b) => (b ? Math.round((a - b) / b * 100) : null);
  const dayStr = (d) => (d ? fmtDate(d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0')) : '—');
  const tplWord = (n) => n === 1 ? 'šablona' : n >= 2 && n <= 4 ? 'šablony' : 'šablon';
  let ST_CACHE = null;
  function statsData() {
    const key = S.projects.map((p) => p.id + (p.collaborators || []).join()).join('|') + S.users.map((u) => u.id + u.status).join();
    if (ST_CACHE && ST_CACHE.key === key) return ST_CACHE;
    const months = monthKeys();
    const clients = allUsers().filter((u) => u.status === 'active' && (u.me || ST_WEIGHT[u.id] != null));
    const tplOf = (pid) => { const r = seeded('tpl' + pid); return TEMPLATES.filter(() => r() < .45).slice(0, 5).concat([TEMPLATES[Math.floor(r() * 3)]]).filter((t, i, a) => a.indexOf(t) === i); };
    const data = clients.map((u) => {
      const r = seeded('dl' + u.id);
      let pids = (u.me ? S.projects.filter((p) => p.owner === ME) : S.projects.filter((p) => isOwner(u, p) || (p.collaborators || []).includes(u.id))).map((p) => p.id);
      pids = pids.concat((ST_EXTRA[u.id] || []).filter((x) => byId(x) && !pids.includes(x)));
      if (!pids.length) pids = [sorted()[Math.floor(r() * S.projects.length)]?.id].filter(Boolean);
      let mm = u.me ? ME_SERIES.slice() : Array.from({ length: 12 }, (_, i) => Math.max(0, Math.round((ST_WEIGHT[u.id] || .1) * (8 + 14 * r()) * (.55 + i / 18) * (r() < .1 ? 0 : 1))));
      if (ST_SHAPE[u.id]) mm = mm.map((m, i) => Math.round(ST_SHAPE[u.id](m, i)));
      const slots = pids.flatMap((pid) => tplOf(pid).map(([t, type]) => ({ pid, t, type, w: Math.pow(r(), 2.2) + .02 })));
      const tw = sum(slots.map((s) => s.w));
      const byTpl = slots.map((s) => ({ ...s, m: mm.map(() => 0) }));
      mm.forEach((n, i) => { let left = n; byTpl.forEach((s, j) => { const k = j === byTpl.length - 1 ? left : Math.min(left, Math.round(n * s.w / tw)); s.m[i] += k; left -= k; }); });
      const lastI = mm.reduce((a, n, i) => (n > 0 ? i : a), -1);
      const lastDay = lastI < 0 ? null : (() => { const mk = months[lastI]; const max = lastI === 11 ? 29 : new Date(mk.y, mk.m + 1, 0).getDate(); return new Date(mk.y, mk.m, 1 + Math.floor(r() * max)); })();
      return { u, months: mm, byTpl: byTpl.filter((t) => sum(t.m) > 0), lastDay };
    });
    data.forEach((d) => { d.ins = insightsOf(d, months); d.alert = d.ins.find((x) => x.tone === 'bad') || null; d.good = d.ins.find((x) => x.tone === 'good') || null; });
    ST_CACHE = { key, data, months };
    return ST_CACHE;
  }
  /* talking points for a sales call, most urgent first */
  function insightsOf(d, months) {
    const m = d.months, L = m.length - 1, out = [];
    const prevM = months[L - 1], curM = months[L];
    let streak = 0; for (let i = L - 1; i >= 0 && m[i] === 0; i--) streak++;
    if (!sum(m)) { out.push({ tone: 'bad', k: 'none', short: 'Nestahuje', text: 'Za posledních 12 měsíců nestáhli nic.' }); return out; }
    if (streak >= 2) out.push({ tone: 'bad', k: 'streak', short: `${streak} měs. bez stažení`, text: `Už ${streak} ${streak <= 4 ? 'měsíce' : 'měsíců'} nestáhli nic (${MONTHS_OD[months[L - streak].m]}).${m[L] ? ` Tento měsíc zase ${fmtN(m[L])}.` : ''}` });
    else if (streak === 1) out.push({ tone: 'bad', k: 'prev0', short: `${MONTHS_LONG[prevM.m][0].toUpperCase() + MONTHS_LONG[prevM.m].slice(1)} bez stažení`, text: `Minulý měsíc (${MONTHS_LONG[prevM.m]}) nestáhli nic.${m[L] ? ` Tento měsíc zatím ${fmtN(m[L])}.` : ''}` });
    const a = sum(m.slice(L - 3, L)), b = sum(m.slice(L - 6, L - 3)), ch = pct(a, b);
    if (!streak && ch != null && ch <= -40 && b >= 6) out.push({ tone: 'bad', k: 'drop', short: `Pokles ${Math.abs(ch)} %`, text: `Za poslední 3 měsíce stáhli o ${Math.abs(ch)} % méně než 3 měsíce předtím (${fmtN(a)} proti ${fmtN(b)}).` });
    if (ch != null && ch >= 40 && a >= 6) out.push({ tone: 'good', k: 'rise', short: `Roste ${ch} %`, text: `Za poslední 3 měsíce stáhli o ${ch} % víc než předtím (${fmtN(a)} proti ${fmtN(b)}).` });
    if (!m[L] && !streak) out.push({ tone: 'info', k: 'cur0', text: `Tento měsíc (${MONTHS_LONG[curM.m]}) zatím nic nestáhli.` });
    const tpl = d.byTpl.map((t) => ({ ...t, n: sum(t.m) })).sort((x, y) => y.n - x.n);
    if (tpl[0]) out.push({ tone: 'info', k: 'top', text: `Nejčastěji stahují ${tpl[0].t} (${fmtN(tpl[0].n)}× za rok)${tpl[1] ? `, pak ${tpl[1].t} (${fmtN(tpl[1].n)}×)` : ''}.` });
    tpl.filter((t) => t.n >= 4 && sum(t.m.slice(L - 2)) === 0).slice(0, 2).forEach((t) => { const li = t.m.reduce((x, n, i) => (n ? i : x), -1); out.push({ tone: 'info', k: 'dorm', text: `Šablonu ${t.t} naposledy stáhli ${MONTHS_IN[months[li].m]} — přestali ji používat?` }); });
    const used = new Set(tpl.map((t) => t.type)), miss = TYPES.filter((t) => !used.has(t));
    if (miss.length) out.push({ tone: 'offer', k: 'miss', text: `Nepoužívají šablony pro ${miss.join(' a ').toLowerCase()} — prostor pro nabídku.` });
    return out;
  }
  const lvl = (n, max) => (!n ? 0 : n / max > .66 ? 4 : n / max > .4 ? 3 : n / max > .18 ? 2 : 1);

  /* ---- SVG charts: specs are stored, drawn at the container's width, re-drawn on resize ---- */
  const CHARTS = {};
  let chartSeq = 0;
  const niceTop = (mx) => { const raw = Math.max(1, mx) / 4, p = Math.pow(10, Math.floor(Math.log10(raw))), f = raw / p, st = (f <= 1 ? 1 : f <= 2 ? 2 : f <= 2.5 ? 2.5 : f <= 5 ? 5 : 10) * p; return { step: st, top: Math.max(st, Math.ceil(Math.max(1, mx) / st) * st) }; };
  const chartSlot = (spec) => { const id = 'ch' + (++chartSeq); CHARTS[id] = spec; return `<div class="chart" data-chart="${id}" style="height:${spec.h || 240}px" role="img" aria-label="${esc(spec.label || '')}"></div>`; };
  function drawCharts(root = document) {
    root.querySelectorAll('[data-chart]').forEach((el) => {
      const spec = CHARTS[el.dataset.chart]; if (!spec) return;
      const W = Math.max(240, el.clientWidth), H = spec.h || 240;
      el.innerHTML = spec.kind === 'bars' ? svgBars(spec, W, H) : svgArea(spec, W, H);
    });
  }
  let rzT; window.addEventListener('resize', () => { clearTimeout(rzT); rzT = setTimeout(() => drawCharts(), 120); });
  function axes(spec, W, H, pad, top, step) {
    const y = (v) => pad.t + (1 - v / top) * (H - pad.t - pad.b);
    let g = '';
    for (let v = 0; v <= top + 1e-9; v += step) g += `<line class="gl" x1="${pad.l}" x2="${W - pad.r}" y1="${y(v).toFixed(1)}" y2="${y(v).toFixed(1)}"/><text class="tk" x="${pad.l - 8}" y="${(y(v) + 4).toFixed(1)}" text-anchor="end">${fmtN(v)}</text>`;
    return { g, y };
  }
  function svgArea(spec, W, H) {
    const v = spec.values, n = v.length, pad = { t: 18, r: 16, b: 30, l: 40 };
    const { step, top } = niceTop(Math.max(...v)), { g, y } = axes(spec, W, H, pad, top, step);
    const x = (i) => pad.l + (n === 1 ? (W - pad.l - pad.r) / 2 : i * (W - pad.l - pad.r) / (n - 1));
    const pts = v.map((val, i) => [x(i), y(val)]);
    /* smooth curve (Catmull-Rom → Bézier, clamped so it never overshoots below zero) */
    let d = `M${pts[0][0].toFixed(1)},${pts[0][1].toFixed(1)}`;
    for (let i = 0; i < n - 1; i++) { const p0 = pts[i - 1] || pts[i], p1 = pts[i], p2 = pts[i + 1], p3 = pts[i + 2] || p2; const c1 = [p1[0] + (p2[0] - p0[0]) / 6, Math.min(y(0), p1[1] + (p2[1] - p0[1]) / 6)], c2 = [p2[0] - (p3[0] - p1[0]) / 6, Math.min(y(0), p2[1] - (p3[1] - p1[1]) / 6)]; d += ` C${c1[0].toFixed(1)},${c1[1].toFixed(1)} ${c2[0].toFixed(1)},${c2[1].toFixed(1)} ${p2[0].toFixed(1)},${p2[1].toFixed(1)}`; }
    const gid = 'grad' + (++chartSeq), base = y(0).toFixed(1);
    const area = `${d} L${pts[n - 1][0].toFixed(1)},${base} L${pts[0][0].toFixed(1)},${base} Z`;
    const half = n > 1 ? (W - pad.l - pad.r) / (n - 1) / 2 : 40;
    const hv = v.map((val, i) => `<g class="hv" data-tip="${esc(spec.tips[i])}"><rect x="${(x(i) - half).toFixed(1)}" y="${pad.t}" width="${(half * 2).toFixed(1)}" height="${H - pad.t - pad.b}" fill="transparent"/><line class="guide" x1="${x(i).toFixed(1)}" x2="${x(i).toFixed(1)}" y1="${pad.t}" y2="${base}"/><circle class="dot" cx="${x(i).toFixed(1)}" cy="${y(val).toFixed(1)}" r="5"/></g>`).join('');
    const lab = v.map((_, i) => `<text class="xl${spec.now === i ? ' now' : ''}" x="${x(i).toFixed(1)}" y="${H - 8}" text-anchor="middle">${esc(spec.labels[i])}</text>`).join('');
    const last = pts[n - 1];
    return `<svg width="${W}" height="${H}" viewBox="0 0 ${W} ${H}" class="svgc"><defs><linearGradient id="${gid}" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="var(--ch-1)" stop-opacity=".38"/><stop offset="1" stop-color="var(--ch-1)" stop-opacity="0"/></linearGradient></defs>${g}<path class="area" d="${area}" fill="url(#${gid})"/><path class="line" d="${d}" pathLength="1"/><circle class="end" cx="${last[0].toFixed(1)}" cy="${last[1].toFixed(1)}" r="5"/><text class="endv" x="${Math.min(last[0], W - pad.r - 4).toFixed(1)}" y="${(last[1] - 12).toFixed(1)}" text-anchor="end">${fmtN(v[n - 1])}</text>${lab}${hv}</svg>`;
  }
  function svgBars(spec, W, H) {
    const v = spec.values, n = v.length, pad = { t: 22, r: 8, b: 30, l: 40 };
    const { step, top } = niceTop(Math.max(...v, ...(spec.ghost || [0]))), { g, y } = axes(spec, W, H, pad, top, step);
    const slot = (W - pad.l - pad.r) / n, bw = Math.min(40, slot * .58), base = y(0);
    const bar = (cx, val, cls) => { const h = base - y(val); if (h <= 0) return ''; const r = Math.min(4, h, bw / 2), x0 = cx - bw / 2; return `<path class="${cls}" d="M${x0.toFixed(1)},${base.toFixed(1)} V${(base - h + r).toFixed(1)} Q${x0.toFixed(1)},${(base - h).toFixed(1)} ${(x0 + r).toFixed(1)},${(base - h).toFixed(1)} H${(x0 + bw - r).toFixed(1)} Q${(x0 + bw).toFixed(1)},${(base - h).toFixed(1)} ${(x0 + bw).toFixed(1)},${(base - h + r).toFixed(1)} V${base.toFixed(1)} Z"/>`; };
    const cols = v.map((val, i) => { const cx = pad.l + slot * (i + .5); return `<g class="hv${val ? '' : ' zero'}${spec.now === i ? ' now' : ''}" data-tip="${esc(spec.tips[i])}" style="--d:${i * 30}ms"><rect x="${(cx - slot / 2).toFixed(1)}" y="${pad.t}" width="${slot.toFixed(1)}" height="${(base - pad.t).toFixed(1)}" class="hit"/>${bar(cx, val, 'b')}${val ? `<text class="bv" x="${cx.toFixed(1)}" y="${(y(val) - 7).toFixed(1)}" text-anchor="middle">${fmtN(val)}</text>` : `<text class="bz" x="${cx.toFixed(1)}" y="${(base - 8).toFixed(1)}" text-anchor="middle">0</text>`}<text class="xl" x="${cx.toFixed(1)}" y="${H - 8}" text-anchor="middle">${esc(spec.labels[i])}</text></g>`; }).join('');
    return `<svg width="${W}" height="${H}" viewBox="0 0 ${W} ${H}" class="svgc">${g}${cols}</svg>`;
  }
  const spark = (arr, cls = '') => { const mx = Math.max(1, ...arr), n = arr.length; const pts = arr.map((v, i) => `${(i / (n - 1) * 100).toFixed(2)},${(34 - v / mx * 30).toFixed(2)}`).join(' '); return `<svg class="spark ${cls}" viewBox="0 0 100 36" preserveAspectRatio="none" aria-hidden="true"><polygon points="0,36 ${pts} 100,36" class="sa"/><polyline points="${pts}" class="sl" vector-effect="non-scaling-stroke"/></svg>`; };
  const ring = (p, label, sub, icon) => { const C = 2 * Math.PI * 34, off = C * (1 - p); return `<div class="ring"><svg viewBox="0 0 84 84" aria-hidden="true"><circle cx="42" cy="42" r="34" class="rt"/><circle cx="42" cy="42" r="34" class="rv" style="stroke-dasharray:${C.toFixed(1)};--off:${off.toFixed(1)};stroke-dashoffset:${off.toFixed(1)}" transform="rotate(-90 42 42)"/></svg><span class="rp">${Math.round(p * 100)} %</span><span class="rl2"><span class="i ${icon} s14" aria-hidden="true"></span>${esc(label)}</span><span class="cap">${sub}</span></div>`; };
  const kpi = (icon, k, b, s, sp, cls = '') => `<div class="st-kpi ${cls}"><span class="k"><span class="i ${icon}" aria-hidden="true"></span>${k}</span><b>${b}</b><span class="s">${s}</span>${sp || ''}</div>`;
  const delta = (ch, txt) => (ch == null ? txt : `<span class="${ch >= 0 ? 'up' : 'dn'}">${ch >= 0 ? '▲' : '▼'} ${Math.abs(ch)} %</span>${txt}`);

  function renderStats() {
    const v = $('#view');
    if (!v.querySelector('#stRoot')) {
      v.innerHTML = `<div id="stRoot" style="display:contents">
        <div class="head"><div style="display:flex;flex-direction:column;gap:8px"><span class="lbl">Administrace</span><h1>Statistiky</h1></div>
          <div class="head-tools" id="stTools"></div></div>
        <div class="tabs-u" role="tablist" aria-label="Statistiky">
          <button type="button" role="tab" id="stt-kl" data-sttab="kl" aria-controls="stp-kl"><span class="i i-users s16" aria-hidden="true"></span>Klienti</button>
          <button type="button" role="tab" id="stt-sys" data-sttab="sys" aria-controls="stp-sys"><span class="i i-trend s16" aria-hidden="true"></span>Celý systém</button>
          <button type="button" role="tab" id="stt-us" data-sttab="us" aria-controls="stp-us"><span class="i i-sparkle s16" aria-hidden="true"></span>Úspory</button></div>
        <div class="st-pane" id="stp-kl" role="tabpanel" aria-labelledby="stt-kl"></div>
        <div class="st-pane" id="stp-sys" role="tabpanel" aria-labelledby="stt-sys"></div>
        <div class="st-pane" id="stp-us" role="tabpanel" aria-labelledby="stt-us"></div></div>`;
    }
    document.querySelectorAll('[data-sttab]').forEach((b) => { const on = b.dataset.sttab === S.st.tab; b.classList.toggle('on', on); b.setAttribute('aria-selected', String(on)); b.tabIndex = on ? 0 : -1; });
    $('#stp-kl').hidden = S.st.tab !== 'kl'; $('#stp-sys').hidden = S.st.tab !== 'sys'; $('#stp-us').hidden = S.st.tab !== 'us';
    $('#stTools').innerHTML = S.st.tab === 'sys' ? `<div class="seg soft" role="group" aria-label="Období">${[3, 6, 12].map((p) => `<button type="button" data-stp="${p}" class="${S.st.period === p ? 'on' : ''}" aria-pressed="${S.st.period === p}">${p} ${p === 3 ? 'měsíce' : 'měsíců'}</button>`).join('')}</div>` : '';
    if (!S.loaded) { $('#stp-kl').innerHTML = '<div class="sk" style="height:320px"></div>'; return; }
    if (S.st.tab === 'kl') renderStClients(); else if (S.st.tab === 'us') renderMyStats(); else renderStSystem();
  }

  /* ---- clients = projects: downloads of every user in the project, expandable per user ---- */
  let CL_CACHE = null;
  function clientsData() {
    const D = statsData();
    if (CL_CACHE && CL_CACHE.key === D.key) return CL_CACHE;
    const rows = S.projects.map((p) => {
      const users = D.data.map((d) => { const t = d.byTpl.filter((x) => x.pid === p.id); return { u: d.u, months: D.months.map((_, i) => sum(t.map((x) => x.m[i]))), lastDay: d.lastDay }; })
        .filter((x) => sum(x.months) > 0).sort((a, b) => sum(b.months) - sum(a.months));
      const byTpl = D.data.flatMap((d) => d.byTpl.filter((x) => x.pid === p.id)).reduce((acc, t) => { const k = t.t; if (!acc[k]) acc[k] = { ...t, m: t.m.slice() }; else t.m.forEach((v, i) => { acc[k].m[i] += v; }); return acc; }, {});
      const months = D.months.map((_, i) => sum(users.map((x) => x.months[i])));
      const r = seeded('cl' + p.id), li = months.reduce((a, n, i) => (n > 0 ? i : a), -1);
      const lastDay = li < 0 ? null : (() => { const mk = D.months[li]; const max = li === 11 ? 29 : new Date(mk.y, mk.m + 1, 0).getDate(); return new Date(mk.y, mk.m, 1 + Math.floor(r() * max)); })();
      const c = { p, users, months, byTpl: Object.values(byTpl), lastDay, ss: projModules(p).includes('socialni'), ai: projModules(p).includes('ai') };
      c.ins = insightsOf(c, D.months);
      if (!c.ss) c.ins.push({ tone: 'offer', k: 'mod-ss', text: 'Nemají modul Sociální sítě — nabídněte jim ho.' });
      if (!c.ai) c.ins.push({ tone: 'offer', k: 'mod-ai', text: 'Nemají AI modul — nabídněte jim ho.' });
      c.alert = c.ins.find((x) => x.tone === 'bad') || null; c.good = c.ins.find((x) => x.tone === 'good') || null;
      return c;
    });
    CL_CACHE = { key: D.key, rows, months: D.months };
    return CL_CACHE;
  }
  const stKind = (c) => (c.alert ? (c.alert.k === 'drop' ? 'drop' : 'idle') : c.good ? 'rise' : 'ok');
  const usersN = (n) => n + ' ' + (n === 1 ? 'uživatel' : n >= 2 && n <= 4 ? 'uživatelé' : 'uživatelů');
  const ST_SORTS = { most: 'Nejvíc stažení', least: 'Nejméně stažení', az: 'Podle abecedy' };
  S.st.sort = 'most'; S.st.pick = null; S.st.exp = new Set(); S.st.menu = null; S.st.pq = '';
  function renderStClients() {
    const C = clientsData(), M = C.months, L = 11, pane = $('#stp-kl');
    const n = (k) => C.rows.filter((c) => stKind(c) === k).length;
    if (!pane.querySelector('#stkTable')) {
      pane.innerHTML = `<div class="st-kpis" id="stkKpis"></div>
        <div class="st-bar"><div class="fbar" id="stkTools"></div></div>
        <div class="st-card hm-card"><div class="st-card-h"><div><h2>Stažení klientů po měsících</h2><span class="cap">Klikněte na klienta pro rozpad po uživatelích · na název pro detail a podněty k hovoru</span></div><span class="hm-scale" aria-hidden="true"><span>0</span><i class="lv0"></i><i class="lv1"></i><i class="lv2"></i><i class="lv3"></i><i class="lv4"></i><span>víc</span><i class="now"></i><span>probíhá</span></span></div>
        <div class="hm-scroll"><div class="clg" id="stkTable" role="table" aria-label="Stažení klientů po měsících"></div></div></div>`;
    }
    const allM = M.map((_, i) => sum(C.rows.map((c) => c.months[i])));
    const act = (i) => C.rows.filter((c) => c.months[i] > 0).length;
    $('#stkKpis').innerHTML =
      kpi('i-users', 'Aktivní klienti tento měsíc', `${act(L)} <small>z ${C.rows.length}</small>`, `minulý měsíc ${act(L - 1)}`, spark(M.map((_, i) => act(i)))) +
      kpi('i-alert', 'Bez stažení', n('idle'), 'minulý měsíc nebo déle — ozvěte se', '', n('idle') ? 'bad' : '') +
      kpi('i-trend', 'Pokles / růst', `${n('drop')} <small>/ ${n('rise')}</small>`, 'poslední 3 měsíce proti předchozím 3') +
      kpi('i-download', 'Stažení tento měsíc', fmtN(allM[L]), delta(pct(allM[L - 1], allM[L - 2]), `minulý měsíc ${fmtN(allM[L - 1])}`), spark(allM));
    const pk = S.st.pick && C.rows.find((c) => c.p.id === S.st.pick);
    $('#stkTools').innerHTML = `<div class="fdrop"><button type="button" class="chip pf-chip${pk ? ' on' : ''}" data-stmenu="pick" aria-haspopup="dialog" aria-expanded="${S.st.menu === 'pick'}" aria-pressed="${!!pk}">${pk ? symbol(pk.p, 'sm') + `<span class="nm">${esc(pk.p.name)}</span>` : '<span class="i i-grid s14" aria-hidden="true"></span>Klient'}<span class="i i-chev-down s14" aria-hidden="true"></span></button>
        ${S.st.menu === 'pick' ? `<div class="menu pf-menu" role="dialog" aria-label="Vybrat klienta"><label class="inp sm" for="stpSearch" style="margin-bottom:4px"><span class="i i-search s14" aria-hidden="true"></span><input type="search" id="stpSearch" placeholder="Najít klienta" autocomplete="off" aria-controls="stpList"></label><div class="pf-list" id="stpList" role="listbox" aria-label="Klienti"></div></div>` : ''}</div>
      <div class="fdrop"><button type="button" class="chip" data-stmenu="sort" aria-haspopup="menu" aria-expanded="${S.st.menu === 'sort'}"><span class="i i-sort s14" aria-hidden="true"></span>${ST_SORTS[S.st.sort]}<span class="i i-chev-down s14" aria-hidden="true"></span></button>
        ${S.st.menu === 'sort' ? `<div class="menu st-sort" role="menu" aria-label="Seřadit">${Object.entries(ST_SORTS).map(([k, t]) => `<button type="button" role="menuitemradio" aria-checked="${S.st.sort === k}" class="mi" data-stsort="${k}">${t}<span class="end">${S.st.sort === k ? '<span class="chk" aria-hidden="true"><span class="i i-check"></span></span>' : ''}</span></button>`).join('')}</div>` : ''}</div>`;
    if (S.st.menu === 'pick') {
      const inp = $('#stpSearch'); inp.value = S.st.pq;
      inp.addEventListener('input', (e) => { S.st.pq = e.target.value; renderStPick(); });
      inp.addEventListener('keydown', (e) => { if (e.key === 'Escape') { e.stopPropagation(); S.st.menu = null; renderStClients(); } if (e.key === 'Enter') { e.preventDefault(); const f = $('#stpList [data-stpick]:not([data-stpick=""])'); if (f) f.click(); } });
      renderStPick(); inp.focus({ preventScroll: true });
    }
    const cmp = { most: (a, b) => sum(b.months) - sum(a.months), least: (a, b) => sum(a.months) - sum(b.months), az: (a, b) => a.p.name.localeCompare(b.p.name, 'cs') }[S.st.sort];
    const list = C.rows.filter((c) => (pk ? c === pk : true)).sort(cmp);
    const max = Math.max(1, ...C.rows.flatMap((c) => c.months));
    const cell = (v, i, who) => `<span class="hm-c lv${lvl(v, max)}${i === L ? ' now' : ''}${i < 6 ? ' old' : ''}" role="cell" data-m="${MONTHS_CS[M[i].m]}" data-tip="${esc(`<b>${esc(who)}</b><br>${MONTHS_LONG[M[i].m]} ${M[i].y}: <b>${fmtN(v)}</b> stažení`)}"><span>${v ? fmtN(v) : ''}</span></span>`;
    const yn = (on, k) => `<span class="yn ${k}${on ? ' on' : ''}" role="cell" title="${k === 'c-ss' ? 'Modul Sociální sítě' : 'AI modul'}: ${on ? 'aktivní' : 'neaktivní'}"><em>${k === 'c-ss' ? 'SS ' : 'AI '}</em>${on ? 'Ano' : 'Ne'}</span>`;
    $('#stkTable').innerHTML = `<div class="clg-row clg-h" role="row"><span class="clg-name" role="columnheader">Klient</span>${M.map((mk, i) => `<span class="clg-m${i === L ? ' now' : ''}${i < 6 ? ' old' : ''}" role="columnheader">${MONTHS_CS[mk.m]}${mk.m === 0 || i === 0 ? `<small>${String(mk.y).slice(2)}</small>` : ''}</span>`).join('')}<span class="clg-yn" role="columnheader" title="Modul Sociální sítě">SS</span><span class="clg-yn" role="columnheader" title="AI modul">AI</span><span class="clg-num" role="columnheader">Celkem</span></div>
      ${list.map((c) => {
        const open = S.st.exp.has(c.p.id) || !!pk, uMx = Math.max(1, ...c.users.map((x) => sum(x.months)));
        return `<div class="cl-box${open ? ' open' : ''}" role="rowgroup">
          <div class="clg-row cl-row" role="row" data-stc="${esc(c.p.id)}" tabindex="0" aria-expanded="${open}" aria-label="${esc(c.p.name)}: ${fmtN(sum(c.months))} stažení za 12 měsíců, ${usersN(c.users.length)}. Rozbalit uživatele.">
            <span class="clg-name" role="rowheader"><span class="hm-chev" aria-hidden="true"><span class="i i-chev-right s14"></span></span>${symbol(c.p, 'sm')}<span class="who"><a class="hm-link" href="#statistiky-${esc(c.p.id)}">${esc(c.p.name)}</a><span>${c.users.length ? usersN(c.users.length) : 'nikdo nestahuje'}</span></span></span>
            ${c.months.map((v, i) => cell(v, i, c.p.name)).join('')}${yn(c.ss, 'c-ss')}${yn(c.ai, 'c-ai')}<span class="clg-num c-tot" role="cell"><b>${fmtN(sum(c.months))}</b></span></div>
          <div class="cl-more" aria-hidden="${!open}"><div class="cl-more-in">${c.users.length ? c.users.map((x) => `<div class="clg-row cl-user" role="row"><span class="clg-name" role="rowheader"><span class="av" aria-hidden="true">${esc(uInit(x.u))}</span><span class="who"><b>${esc(uLabel(x.u))}${x.u.me ? ' <span style="font-weight:500;color:var(--subtle)">(vy)</span>' : ''}</b></span></span><span class="cl-bar" role="cell" data-tip="${esc(`<b>${esc(uLabel(x.u))}</b><br>${MONTHS_CS[M[0].m]} ${M[0].y} – ${MONTHS_CS[M[L].m]} ${M[L].y}: <b>${fmtN(sum(x.months))}</b> stažení<br>Minulý měsíc: ${fmtN(x.months[L - 1])} · tento: ${fmtN(x.months[L])}`)}"><i style="width:${sum(x.months) / uMx * 100}%"></i></span><span class="clg-num c-tot" role="cell">${fmtN(sum(x.months))}</span></div>`).join('') : '<p class="cl-empty">Z tohoto klienta za posledních 12 měsíců nikdo nestahoval.</p>'}</div></div>
        </div>`;
      }).join('') || '<p class="u-empty">Žádný klient neodpovídá filtru.</p>'}`;
  }
  function renderStPick() {
    const C = clientsData(), q = S.st.pq.trim().toLowerCase();
    const list = C.rows.filter((c) => !q || c.p.name.toLowerCase().includes(q)).sort((a, b) => a.p.name.localeCompare(b.p.name, 'cs'));
    const tick = '<span class="chk" aria-hidden="true"><span class="i i-check"></span></span>';
    $('#stpList').innerHTML = (q ? '' : `<button type="button" role="option" class="qn" aria-selected="${!S.st.pick}" data-stpick=""><span class="sym sm mono" aria-hidden="true"><span class="i i-grid s14"></span></span><span class="nm">Všichni klienti</span><span class="end">${!S.st.pick ? tick : ''}</span></button><div class="menu-sep"></div>`)
      + (list.map((c) => `<button type="button" role="option" class="qn" aria-selected="${S.st.pick === c.p.id}" data-stpick="${esc(c.p.id)}">${symbol(c.p, 'sm')}<span class="nm">${esc(c.p.name)}</span><span class="end">${S.st.pick === c.p.id ? tick : fmtN(sum(c.months))}</span></button>`).join('') || '<p class="cap" style="padding:10px">Žádný klient neodpovídá.</p>');
  }

  /* ---- client detail popup (#statistiky-<projectId>) ---- */
  function openStatClient(id) {
    const C = clientsData(), c = C.rows.find((x) => x.p.id === id), dlg = $('#dlgStat');
    if (!c) { if (S.loaded) { toast('Klient ve statistikách není.', true); location.hash = 'statistiky'; } return; }
    const M = C.months, L = 11, p = c.p;
    const tot = sum(c.months), last6 = sum(c.months.slice(6)), prev6 = sum(c.months.slice(0, 6));
    const tpl = c.byTpl.map((t) => ({ ...t, n: sum(t.m) })).filter((t) => t.n).sort((a, b) => b.n - a.n);
    const types = TYPES.map((ty) => [ty, sum(tpl.filter((t) => t.type === ty).map((t) => t.n)), tpl.filter((t) => t.type === ty).length]);
    const tySum = Math.max(1, sum(types.map((t) => t[1])));
    const tMax = Math.max(1, ...tpl.flatMap((t) => t.m)), uMax = Math.max(1, ...c.users.flatMap((x) => x.months));
    const mx = Math.max(...c.months);
    const icon = { bad: 'i-alert', good: 'i-trend', info: 'i-info', offer: 'i-star' };
    const hmHead = (first) => `<thead><tr><th scope="col" class="hm-name">${first}</th>${M.map((mk, i) => `<th scope="col" class="hm-m${i === L ? ' now' : ''}">${MONTHS_CS[mk.m]}</th>`).join('')}<th scope="col" class="hm-num">Celkem</th></tr></thead>`;
    const hmCells = (arr, who, m) => arr.map((v, i) => `<td class="hm-c lv${lvl(v, m)}${i === L ? ' now' : ''}" data-tip="${esc(`<b>${esc(who)}</b><br>${MONTHS_LONG[M[i].m]} ${M[i].y}: <b>${fmtN(v)}</b>`)}"><span>${v ? fmtN(v) : ''}</span></td>`).join('');
    S.st.open = id;
    $('#sdHead').innerHTML = `${symbol(p)}<div class="up-id"><span class="lbl">Statistiky klienta</span><h2 id="sdTitle">${esc(p.name)}</h2><div class="up-meta"><span>Vlastník: ${esc(ownerOf(p))}</span><span>${usersN(c.users.length)} stahuje</span><span>Poslední stažení ${dayStr(c.lastDay)}</span></div></div>`;
    $('#sdProfile').dataset.pid = p.id;
    $('#sdBody').innerHTML = `<div class="sd-body">
      <section class="sd-ins-card ${c.alert ? 'bad' : ''}"><h3><span class="i i-bot s16" aria-hidden="true"></span>Podněty k hovoru</h3><ul class="sd-ins">${c.ins.map((x) => `<li class="${x.tone}"><span class="ic"><span class="i ${icon[x.tone]} s14" aria-hidden="true"></span></span><span>${esc(x.text)}</span></li>`).join('')}</ul></section>
      <div class="st-kpis">
        ${kpi('i-download', 'Stažení za 12 měsíců', fmtN(tot), delta(pct(last6, prev6), 'posledních 6 proti předchozím 6'), spark(c.months))}
        ${kpi('i-calendar', 'Minulý měsíc', fmtN(c.months[L - 1]), `${MONTHS_LONG[M[L - 1].m]} · tento měsíc zatím ${fmtN(c.months[L])}`, '', c.months[L - 1] ? '' : 'bad')}
        ${kpi('i-template', 'Použité šablony', tpl.length, `${types.filter((t) => t[1]).length} ze 4 typů výstupu`)}
        ${kpi('i-users', 'Uživatelé', c.users.length, c.users[0] ? `nejvíc ${esc(uLabel(c.users[0].u))}` : 'nikdo nestahuje')}
      </div>
      <section class="st-card"><div class="st-card-h"><h3>Stažení po měsících</h3><span class="cap">Posledních 12 měsíců · prázdné měsíce jsou označené</span></div>
        ${chartSlot({ kind: 'bars', h: 240, values: c.months, now: L, labels: M.map((mk) => MONTHS_CS[mk.m]), tips: c.months.map((v, i) => `<b>${MONTHS_LONG[M[i].m]} ${M[i].y}</b><br>Stažení: <b>${fmtN(v)}</b>${i === L ? '<br><span style="color:var(--subtle)">měsíc ještě běží</span>' : ''}`), label: `Stažení klienta ${p.name} po měsících` })}</section>
      ${c.users.length ? `<section class="st-card"><div class="st-card-h"><h3>Uživatelé po měsících</h3><span class="cap">Kdo z klienta stahuje</span></div>
        <div class="hm-scroll"><table class="hm sm">${hmHead('Uživatel')}<tbody>${c.users.map((x) => `<tr><th scope="row" class="hm-name"><span class="u-who"><span class="av" aria-hidden="true">${esc(uInit(x.u))}</span><span class="who"><b>${esc(uLabel(x.u))}</b><span>poslední ${dayStr(x.lastDay)}</span></span></span></th>${hmCells(x.months, uLabel(x.u), uMax)}<td class="hm-num"><b>${fmtN(sum(x.months))}</b></td></tr>`).join('')}</tbody></table></div></section>` : ''}
      <div class="st-grid sd-grid">
        <section class="st-card"><div class="st-card-h"><h3>Šablony po měsících</h3><span class="cap">${tpl.length} ${tplWord(tpl.length)} · podle počtu stažení</span></div>
          <div class="hm-scroll"><table class="hm sm">${hmHead('Šablona')}<tbody>${tpl.map((t) => `<tr><th scope="row" class="hm-name"><span class="who"><b>${esc(t.t)}</b><span>${esc(t.type)}</span></span></th>${hmCells(t.m, t.t, tMax)}<td class="hm-num"><b>${fmtN(t.n)}</b></td></tr>`).join('') || '<tr><td colspan="14" class="u-empty">Za posledních 12 měsíců nic nestáhli.</td></tr>'}</tbody></table></div></section>
        <section class="st-card"><div class="st-card-h"><h3>Typy výstupu</h3><span class="cap">12 měsíců</span></div>
          <div class="rings">${types.map(([ty, nn, k]) => ring(nn / tySum, ty, nn ? `${fmtN(nn)} stažení · ${k} ${tplWord(k)}` : '<span class="pill">Nepoužívají</span>', TYPE_ICON[ty])).join('')}</div></section>
      </div></div>`;
    $('#dlgStat .dlg-scroll').scrollTop = 0;
    if (!dlg.open) dlg.showModal();
    drawCharts($('#dlgStat'));
    $('#dlgStat [data-close]').focus({ preventScroll: true });
  }
  $('#dlgStat').addEventListener('close', () => { S.st.open = null; if (S.route.startsWith('statistiky-')) location.hash = 'statistiky'; });
  $('#sdProfile').addEventListener('click', (e) => { const id = e.currentTarget.dataset.pid; $('#dlgStat').close(); setTimeout(() => { location.hash = 'projekt-' + id; }, 0); });

  /* ---- tab Celý systém ---- */
  const STORAGE = [['lukasrejda@lukasrejda.cz', 195, 102], ['adrianhutyra@post.cz', 102, 6.4], ['ondrej.frommel@email.cz', 72.4, 33], ['ndbrusperk@gmail.com', 30.4, 25.4], ['ilichnova@frydlantno.cz', 38.2, 17.6], ['pavliskova@masfb.cz', 27.1, 12.5], ['nikola.heidrich@has-cs.cz', 19, 16.2], ['faceceladna@seznam.cz', 20.9, 13.4], ['renata.slahorkova@zaben.cz', 8.8, 0], ['projekty@sssfno.cz', 7.9, 0], ['j.mikes@me.com', 2.1, 1.3], ['podatelna@komorni-lhotka.cz', 1.7, .3], ['Ostatní', .5, .3]];
  function renderStSystem() {
    const D = statsData(), P = S.st.period, M = D.months.slice(-P), L = P - 1;
    const allM = D.months.map((_, i) => sum(D.data.map((d) => d.months[i])));
    const cur = allM.slice(-P), prev = allM.slice(-2 * P, -P);
    const total = sum(cur), ch = prev.length === P ? pct(total, sum(prev)) : null;
    const tpls = Object.values(D.data.flatMap((d) => d.byTpl.map((t) => ({ ...t, n: sum(t.m.slice(-P)) }))).reduce((acc, t) => { const k = t.pid + '|' + t.t; (acc[k] = acc[k] || { ...t, n: 0 }).n += t.n; return acc; }, {})).filter((t) => t.n > 0).sort((a, b) => b.n - a.n);
    const types = TYPES.map((ty) => [ty, sum(tpls.filter((t) => t.type === ty).map((t) => t.n)), tpls.filter((t) => t.type === ty).length]);
    const tySum = Math.max(1, sum(types.map((t) => t[1])));
    const best = cur.indexOf(Math.max(...cur));
    const actM = D.months.map((_, i) => D.data.filter((d) => d.months[i] > 0).length);
    const topT = tpls.slice(0, 8), tMax = Math.max(1, ...topT.map((t) => t.n));
    const nameOf = (email) => { if (email === 'lukasrejda@lukasrejda.cz') return ME + ' (vy)'; const u = S.users.find((x) => x.email === email); return u ? uLabel(u) : email; };
    const sMax = Math.max(...STORAGE.map((r) => r[1] + r[2]));
    $('#stp-sys').innerHTML = `
      <div class="st-kpis">
        ${kpi('i-download', 'Stažení celkem', fmtN(total), delta(ch, `proti předchozím ${P} měsícům`), spark(cur))}
        ${kpi('i-users', 'Aktivní klienti', actM[11], `tento měsíc · z ${D.data.length}`, spark(actM.slice(-P)))}
        ${kpi('i-template', 'Použité šablony', tpls.length, topT[0] ? 'nejvíc ' + esc(topT[0].t) : '—')}
        ${kpi('i-trend', 'Nejsilnější měsíc', MONTHS_LONG[M[best].m], `${fmtN(cur[best])} stažení · ${M[best].y}`)}
      </div>
      <div class="st-card"><div class="st-card-h"><div><h2>Stažení po měsících</h2><span class="cap">Všichni klienti · posledních ${P} ${P === 3 ? 'měsíce' : 'měsíců'}</span></div><div class="st-legend"><span><i></i>Stažení</span></div></div>
        ${chartSlot({ kind: 'area', h: 260, values: cur, now: L, labels: M.map((mk) => MONTHS_CS[mk.m]), tips: cur.map((v, i) => `<b>${MONTHS_LONG[M[i].m]} ${M[i].y}</b><br>Stažení: <b>${fmtN(v)}</b><br>Aktivních klientů: ${actM[12 - P + i]}`), label: 'Stažení všech klientů po měsících' })}</div>
      <div class="st-card"><div class="st-card-h"><h2>Typy výstupu</h2><span class="cap">${P} ${P === 3 ? 'měsíce' : 'měsíců'} · podíl na všech staženích</span></div>
        <div class="rings row4">${types.map(([ty, nn, k]) => ring(nn / tySum, ty, `${fmtN(nn)} stažení · ${k} ${tplWord(k)}`, TYPE_ICON[ty])).join('')}</div></div>
      <div class="st-card"><div class="st-card-h"><h2>Nejstahovanější šablony</h2><span class="cap">${P} ${P === 3 ? 'měsíce' : 'měsíců'}</span></div>
        <ol class="st-rank cols2">${topT.map((t, i) => { const p = byId(t.pid); return `<li style="--d:${i * 40}ms"><span class="n">${i + 1}</span><span class="rl">${p ? symbol(p, 'sm') : ''}<span class="who"><b>${esc(t.t)}</b><span>${esc(t.type)}${p ? ' · ' + esc(p.name) : ''}</span></span></span><span class="val">${fmtN(t.n)}</span><span></span><span class="trk"><i style="width:${t.n / tMax * 100}%"></i></span></li>`; }).join('')}</ol></div>
      <div class="st-card"><div class="st-card-h"><div><h2>Úložiště</h2><span class="cap">Nepoužívané = soubory bez odkazu v databázi (zbytky po smazaných projektech nebo přepsaných obrázcích)</span></div><span style="display:flex;align-items:center;gap:10px"><span class="cap">Načteno ${esc(fmtDate(S.st.scanned))} ${esc(S.st.scanned.slice(11, 16))}</span><button type="button" class="btn xs ghost" id="stRescan"><span class="i i-refresh s14" aria-hidden="true"></span>Načíst znovu</button></span></div>
        <div class="st-store"><div class="rings one">${ring(251 / 776, 'Nepoužívané', '251 MB z 776 MB · 711 souborů', 'i-trash').replace('class="ring"', 'class="ring c2"')}</div>
          <div class="st-kpis mini">${kpi('i-database', 'Celkem', '776 MB', '1 911 souborů')}${kpi('i-info', 'Bez projektu', '22,2 MB', '29 souborů')}</div></div>
        <div class="st-card-h"><h3 style="font-size:14px;font-weight:600">Zabrané místo podle klienta</h3><div class="st-legend"><span><i></i>Používané</span><span><i class="c2"></i>Nepoužívané</span></div></div>
        <ul class="st-stack">${STORAGE.map(([em, uu, x], i) => { const nm = nameOf(em); return `<li style="--d:${i * 30}ms" data-tip="${esc(`<b>${esc(nm)}</b><br>Používané: <b>${fmtMB(uu)}</b><br>Nepoužívané: <b>${fmtMB(x)}</b>`)}" tabindex="0" aria-label="${esc(nm)}: používané ${fmtMB(uu)}, nepoužívané ${fmtMB(x)}"><span class="nm">${esc(nm)}</span><span class="bar"><i class="u" style="width:${uu / sMax * 100}%"></i>${x ? `<i class="x" style="width:${x / sMax * 100}%"></i>` : ''}</span><span class="val">${fmtMB(uu + x)}</span></li>`; }).join('')}</ul>
      </div>`;
    drawCharts($('#stp-sys'));
  }

  /* events */
  $('#view').addEventListener('click', (e) => {
    if (S.route !== 'statistiky' && !S.route.startsWith('statistiky-')) return;
    const t = e.target.closest('[data-sttab]'); if (t) { S.st.tab = t.dataset.sttab; renderStats(); return; }
    const p = e.target.closest('[data-stp]'); if (p) { S.st.period = Number(p.dataset.stp); renderStats(); return; }
    const f = e.target.closest('[data-stf]'); if (f) { S.st.f = f.dataset.stf; renderStClients(); return; }
    const m = e.target.closest('[data-stmenu]'); if (m) { S.st.menu = S.st.menu === m.dataset.stmenu ? null : m.dataset.stmenu; S.st.pq = ''; renderStClients(); return; }
    const so = e.target.closest('[data-stsort]'); if (so) { S.st.sort = so.dataset.stsort; S.st.menu = null; renderStClients(); return; }
    const pk = e.target.closest('[data-stpick]'); if (pk) { S.st.pick = pk.dataset.stpick || null; S.st.menu = null; renderStClients(); return; }
    if (e.target.closest('.hm-link')) return;
    const c = e.target.closest('.cl-row[data-stc]'); if (c) { stRowAct(c); return; }
    const r = e.target.closest('#stRescan');
    if (r) { r.classList.add('is-loading'); setTimeout(() => { const d = new Date(); S.st.scanned = isoToday() + 'T' + String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0'); renderStSystem(); toast('Úložiště načteno znovu'); }, 900); }
  });
  $('#view').addEventListener('keydown', (e) => {
    if (S.route !== 'statistiky' && !S.route.startsWith('statistiky-')) return;
    if ((e.key === 'Enter' || e.key === ' ') && e.target.matches('.cl-row[data-stc]')) { e.preventDefault(); stRowAct(e.target); return; }
    if (e.target.matches('[data-sttab]') && ['ArrowLeft', 'ArrowRight'].includes(e.key)) { { const T = ['kl', 'sys', 'us'], k = T.indexOf(S.st.tab); S.st.tab = T[(k + (e.key === 'ArrowRight' ? 1 : 2)) % 3]; } renderStats(); $(`[data-sttab="${S.st.tab}"]`).focus(); }
  });
  /* expand / collapse in place, so the height animates and the list never re-renders */
  function stRowAct(row) {
    const id = row.dataset.stc, box = row.closest('.cl-box'), open = !box.classList.contains('open');
    if (open) S.st.exp.add(id); else S.st.exp.delete(id);
    box.classList.toggle('open', open); row.setAttribute('aria-expanded', String(open));
    box.querySelector('.cl-more').setAttribute('aria-hidden', String(!open));
  }
  document.addEventListener('pointerdown', (e) => { if (S.st.menu && !e.target.closest('.fdrop')) { S.st.menu = null; if (S.route === 'statistiky') renderStClients(); } });
  /* one tooltip for every [data-tip] mark, on hover and keyboard focus; lives inside an open dialog so it stays on top */
  const tipEl = document.createElement('div'); tipEl.className = 'st-tip'; tipEl.hidden = true; tipEl.setAttribute('role', 'tooltip'); document.body.append(tipEl);
  const showTip = (el, x, y) => { const host = el.closest('dialog') || document.body; if (tipEl.parentNode !== host) host.append(tipEl); tipEl.innerHTML = el.dataset.tip; tipEl.hidden = false; const w = tipEl.offsetWidth, h = tipEl.offsetHeight; tipEl.style.left = Math.max(8, Math.min(x + 14, innerWidth - w - 8)) + 'px'; tipEl.style.top = (y - h - 12 < 8 ? y + 18 : y - h - 12) + 'px'; };
  document.addEventListener('pointermove', (e) => { const el = e.target.closest && e.target.closest('[data-tip]'); if (el) showTip(el, e.clientX, e.clientY); else if (!tipEl.hidden) tipEl.hidden = true; });
  document.addEventListener('focusin', (e) => { const el = e.target.closest && e.target.closest('[data-tip]'); if (el) { const r = el.getBoundingClientRect(); showTip(el, r.left + r.width / 2, r.top); } else tipEl.hidden = true; });
  window.addEventListener('scroll', () => { tipEl.hidden = true; }, true);

  /* ---------- Statistiky › tab Úspory — what a client made with Wboost and what it would cost at a designer ----------
     The signed-in account's own numbers (no project filter): month select only (default = current month, still running).
     Staženo = exports; z toho synchronizované = extra formats generated from the same design by template sync.
     Price at a designer: new design 1 000–1 500 Kč (midpoint 1 250 Kč used for "Ušetřeno"), each extra format 500 Kč.
     Backend supplies: exports per user × project × template × month with a flag "synced" (format created by sync). */
  const MS_PRICE = { min: 1000, max: 1500, mid: 1250, sync: 500 };
  S.ms = { month: 11, menu: null };
  const fmtKc = (n) => fmtN(n) + ' Kč';
  function myStatsData() {
    const D = statsData(), me = D.data.find((d) => d.u.me); // the signed-in account's own downloads, across all its projects
    const rows = (me ? me.byTpl : []).map((t) => {
      const r = seeded('sync' + t.pid + t.t), share = .25 + r() * .3;
      const sync = t.m.map((n) => Math.min(n, Math.round(n * share)));
      return { ...t, sync };
    });
    const months = D.months.map((_, i) => {
      const dl = sum(rows.map((t) => t.m[i])), sy = sum(rows.map((t) => t.sync[i])), nw = dl - sy;
      return { dl, sy, nw, min: nw * MS_PRICE.min + sy * MS_PRICE.sync, max: nw * MS_PRICE.max + sy * MS_PRICE.sync, saved: nw * MS_PRICE.mid + sy * MS_PRICE.sync };
    });
    return { keys: D.months, months, rows };
  }
  const monthName = (mk) => MONTHS_LONG[mk.m][0].toUpperCase() + MONTHS_LONG[mk.m].slice(1) + ' ' + mk.y;
  function renderMyStats() {
    const v = $('#stp-us');
    if (!v.querySelector('#msKpis')) {
      v.innerHTML = `<div class="st-bar"><div class="fbar" id="msTools"></div><span class="cap" id="msNote"></span></div>
          <div class="st-kpis ms-kpis" id="msKpis"></div>
          <div class="st-card ms-chart"><div class="st-card-h"><div><h2>Ušetřeno po měsících</h2><span class="cap">V Kč, kolik byste za stejnou práci zaplatili grafikovi · klikněte na měsíc pro detail</span></div><span class="st-legend"><span><i class="ms-sw now"></i>probíhá</span></span></div><div id="msChart"></div></div>
          <div class="st-card"><div class="st-card-h"><div><h2 id="msListH">Co jste stáhli</h2><span class="cap">Počet stažení · z toho synchronizované formáty · cena u grafika</span></div></div><div class="ms-list" id="msList"></div></div>
`;
    }
    if (!S.loaded) { $('#msKpis').innerHTML = '<div class="sk" style="height:140px;grid-column:1/-1"></div>'; return; }
    const M = myStatsData(), i = S.ms.month, cur = M.months[i], prev = M.months[i - 1], mk = M.keys[i], L = M.keys.length - 1;
    const tick = '<span class="chk" aria-hidden="true"><span class="i i-check"></span></span>';
    $('#msTools').innerHTML = `<div class="fdrop"><button type="button" class="chip on" data-msmenu="month" aria-haspopup="menu" aria-expanded="${S.ms.menu === 'month'}"><span class="i i-calendar s14" aria-hidden="true"></span>${monthName(mk)}${i === L ? ' <span class="ms-run">probíhá</span>' : ''}<span class="i i-chev-down s14" aria-hidden="true"></span></button>
        ${S.ms.menu === 'month' ? `<div class="menu ms-menu" role="menu" aria-label="Měsíc">${M.keys.map((k, j) => j).reverse().map((j) => `<button type="button" role="menuitemradio" aria-checked="${j === i}" class="mi" data-msmonth="${j}">${monthName(M.keys[j])}<span class="end">${j === i ? tick : `<span class="cap">${fmtN(M.months[j].dl)}×</span>`}</span></button>`).join('')}</div>` : ''}</div>`;
    $('#msNote').textContent = i === L ? 'Měsíc ještě běží, čísla se průběžně doplňují.' : '';
    const ytd = M.months.filter((_, j) => M.keys[j].y === mk.y && j <= i), ytdSaved = sum(ytd.map((x) => x.saved));
    const ch = prev ? pct(cur.dl, prev.dl) : null;
    $('#msKpis').innerHTML =
      kpi('i-download', 'Staženo', fmtN(cur.dl), !prev ? '' : i === L ? `${MONTHS_LONG[M.keys[i - 1].m]} ${fmtN(prev.dl)} · měsíc ještě běží` : delta(ch, `${MONTHS_LONG[M.keys[i - 1].m]} ${fmtN(prev.dl)}`), spark(M.months.slice(0, i + 1).map((x) => x.dl))) +
      kpi('i-layers', 'Z toho navíc', `${fmtN(cur.sy)} <small>synchronizované</small>`, 'další formáty ze stejného návrhu') +
      kpi('i-palette', 'Cena u grafika', cur.dl ? `${fmtN(cur.min)}–${fmtN(cur.max)} <small>Kč</small>` : '0 <small>Kč</small>', 'návrh 1 000–1 500 Kč · další formát 500 Kč', '', 'price') +
      kpi('i-sparkle', 'Ušetřeno', `≈ ${fmtN(cur.saved)} <small>Kč</small>`, `od začátku roku ≈ ${fmtKc(ytdSaved)}`, '', 'good');
    const from = $('#msChart').clientWidth && $('#msChart').clientWidth < 560 ? Math.min(6, i) : 0, idx = M.months.map((_, j) => j).slice(from, from ? from + 6 : 12); // narrow: last 6 months (incl. the selected one)
    const ck = [from, statsData().key].join('|'), rerender = $('#msChart').dataset.ck !== ck; // opening a menu or picking a month must not replay the bar animation
    if (rerender) { $('#msChart').dataset.ck = ck; $('#msChart').innerHTML = chartSlot({ kind: 'bars', h: 240, values: idx.map((j) => M.months[j].saved), now: L - from, labels: idx.map((j) => MONTHS_CS[M.keys[j].m]),
      tips: idx.map((j) => { const x = M.months[j]; return `<b>${monthName(M.keys[j])}</b>${j === L ? ' · probíhá' : ''}<br>Staženo ${fmtN(x.dl)}, z toho synchronizované ${fmtN(x.sy)}<br>Ušetřeno ≈ <b>${fmtKc(x.saved)}</b>`; }) });
    drawCharts($('#msChart')); }
    $('#msChart').querySelectorAll('.hv').forEach((g, k) => { const j = k + from; g.classList.toggle('sel', j === i); g.dataset.msbar = j; g.setAttribute('tabindex', '0'); g.setAttribute('role', 'button'); g.setAttribute('aria-label', `${monthName(M.keys[j])}: ušetřeno ${fmtKc(M.months[j].saved)}`); });
    $('#msListH').textContent = `Co jste stáhli ${MONTHS_IN[mk.m]} ${mk.y}`;
    const rows = M.rows.map((t) => ({ t, n: t.m[i], s: t.sync[i] })).filter((x) => x.n).sort((a, b) => b.n - a.n), mx = Math.max(1, ...rows.map((x) => x.n));
    $('#msList').innerHTML = rows.length ? rows.map(({ t, n, s }) => { const p = byId(t.pid); return `<div class="ms-row"><span class="ms-name"><span class="tico" aria-hidden="true"><span class="i ${TYPE_ICON[t.type]} s16"></span></span><span class="who"><b>${esc(t.t)}</b><span>${esc(t.type)}${p ? ' · ' + esc(p.name) : ''}</span></span></span><span class="ms-bar" aria-hidden="true"><i style="width:${n / mx * 100}%"></i><i class="sy" style="left:${(n - s) / mx * 100}%;width:${s / mx * 100}%"></i></span><span class="ms-n"><b>${fmtN(n)}×</b>${s ? `<span>z toho ${fmtN(s)} sync.</span>` : ''}</span><span class="ms-kc">≈ ${fmtKc((n - s) * MS_PRICE.mid + s * MS_PRICE.sync)}</span></div>`; }).join('')
      : `<p class="u-empty">${MONTHS_IN[mk.m][0].toUpperCase() + MONTHS_IN[mk.m].slice(1)} ${mk.y} jste nic nestáhli.</p>`;
  }
  $('#view').addEventListener('click', (e) => {
    if (S.route !== 'statistiky' || S.st.tab !== 'us') return;
    const m = e.target.closest('[data-msmenu]'); if (m) { S.ms.menu = S.ms.menu === m.dataset.msmenu ? null : m.dataset.msmenu; renderMyStats(); return; }
    const mo = e.target.closest('[data-msmonth]'); if (mo) { S.ms.month = Number(mo.dataset.msmonth); S.ms.menu = null; renderMyStats(); return; }
    const b = e.target.closest('[data-msbar]'); if (b) { S.ms.month = Number(b.dataset.msbar); renderMyStats(); }
  });
  $('#view').addEventListener('keydown', (e) => {
    if (S.route !== 'statistiky' || S.st.tab !== 'us') return;
    if ((e.key === 'Enter' || e.key === ' ') && e.target.matches('[data-msbar]')) { e.preventDefault(); S.ms.month = Number(e.target.dataset.msbar); renderMyStats(); $(`[data-msbar="${S.ms.month}"]`)?.focus(); }
  });
  document.addEventListener('pointerdown', (e) => { if (S.ms.menu && !e.target.closest('.fdrop')) { S.ms.menu = null; if (S.route === 'statistiky' && S.st.tab === 'us') renderMyStats(); } });
  /* ---------- project dashboard (#projekt-<id>) ----------
     Header + colour palette (unchanged), content tiles (enabled content modules + Galerie + Fonty, always on),
     box Uživatelé (owner + collaborators, "Přidat kolegu" opens the share dialog),
     box Moduly projektu — admin only, switch per module (an enabled module appears in the sidebar project menu #projNav); a switched-off module disappears from the project menu, data stay.
     projects.modules = array of module keys; the prototype seeds it until a project has one saved. */
  const MODULES = [
    { k: 'manualy', name: 'Manuály', icon: 'i-copy', desc: 'Online logo manuál — logotypy a stránky s mockupy', tile: true },
    { k: 'sablony', name: 'Šablony', icon: 'i-template', desc: 'Šablony příspěvků, tiskovin a grafik', tile: true },
    { k: 'emaily', name: 'E-maily', icon: 'i-mail', desc: 'E-mailové podpisy pro tým klienta', tile: true },
    { k: 'jidelnicky', name: 'Jídelníčky', icon: 'i-utensils', desc: 'Jídelní lístky a menu ze šablon', tile: true },
    { k: 'socialni', name: 'Sociální sítě', nav: 'Facebook a Instagram', icon: 'i-facebook', desc: 'Napojení Facebooku a Instagramu' },
    { k: 'ai', name: 'AI modul', icon: 'i-bot', desc: 'Práce se šablonami přes AI aplikace (MCP)', noNav: true }
  ];
  function projModules(p) {
    if (Array.isArray(p.modules)) return p.modules.includes('sablony') ? p.modules : ['sablony'].concat(p.modules); // Šablony are core — always on
    const r = seeded('mod' + p.id);
    return MODULES.filter((m) => (m.k === 'sablony' ? true : m.k === 'manualy' ? p.manual || r() < .6 : m.k === 'emaily' ? r() < .4 : m.k === 'jidelnicky' ? r() < .12 : m.k === 'socialni' ? r() < .55 : r() < .35)).map((m) => m.k);
  }
  const modCount = (p, k) => {
    const r = seeded('cnt' + k + p.id);
    return k === 'sablony' ? Number(p.templates) || 0 : k === 'fonty' ? (p.fonts || []).length : k === 'barvy' ? (p.colors || []).length : k === 'galerie' ? Math.floor(r() * 40) : k === 'manualy' ? manOf(p.id).pages.length : Math.floor(r() * 12);
  };
  const cntWord = { barvy: ['barva', 'barvy', 'barev'], manualy: ['stránka s mockupy', 'stránky s mockupy', 'stránek s mockupy'], sablony: ['šablona', 'šablony', 'šablon'], emaily: ['podpis', 'podpisy', 'podpisů'], jidelnicky: ['jídelníček', 'jídelníčky', 'jídelníčků'], galerie: ['obrázek', 'obrázky', 'obrázků'], fonty: ['písmo', 'písma', 'písem'] };
  const projUsers = (p) => {
    const A = allUsers(), owner = A.find((u) => isOwner(u, p)) || (p.owner ? { id: null, name: p.owner, role: 'user', status: 'active' } : null);
    const col = (p.collaborators || []).map((id) => A.find((u) => u.id === id)).filter(Boolean).filter((u) => !owner || u.id !== owner.id);
    return { owner, col };
  };
  // personal tile order (per viewer, same in every project) — drag a tile onto another to move it there
  const tileOrder = () => { try { return JSON.parse(localStorage.getItem('wb-tiles') || '[]'); } catch (e) { return []; } };
  let pdDrag = null;
  $('#view').addEventListener('dragstart', (e) => { const t = e.target.closest?.('[data-pdk]'); if (!t) return; pdDrag = t.dataset.pdk; t.classList.add('pd-dragging'); e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', pdDrag); });
  $('#view').addEventListener('dragend', () => { pdDrag = null; $$('.pd-dragging, .pd-over').forEach((x) => x.classList.remove('pd-dragging', 'pd-over')); });
  $('#view').addEventListener('dragover', (e) => { if (!pdDrag) return; const t = e.target.closest('[data-pdk]'); if (!t) return; e.preventDefault(); $$('.pd-over').forEach((x) => { if (x !== t) x.classList.remove('pd-over'); }); if (t.dataset.pdk !== pdDrag) t.classList.add('pd-over'); });
  $('#view').addEventListener('drop', (e) => {
    if (!pdDrag) return; const t = e.target.closest('[data-pdk]'); e.preventDefault(); if (!t || t.dataset.pdk === pdDrag) return;
    const box = t.parentElement, el = box.querySelector(`[data-pdk="${pdDrag}"]`), all = [...box.children], from = all.indexOf(el), to = all.indexOf(t);
    box.insertBefore(el, from < to ? t.nextSibling : t);
    const keys = [...box.children].map((x) => x.dataset.pdk), old = tileOrder().filter((k) => !keys.includes(k));
    try { localStorage.setItem('wb-tiles', JSON.stringify(keys.concat(old))); } catch (err) {}
    pdDrag = null; $$('.pd-dragging, .pd-over').forEach((x) => x.classList.remove('pd-dragging', 'pd-over')); toast('Pořadí dlaždic uloženo');
  });
  function renderDetail(id) {
    if (id.endsWith('/galerie')) { renderGallery(id.slice(0, -8)); return; }
    if (id.endsWith('/fonty')) { renderFonts(id.slice(0, -6)); return; }
    if (id.endsWith('/barvy')) { renderPalette(id.slice(0, -6)); return; }
    if (id.endsWith('/sablony/import')) { renderTpAi(id.slice(0, -15)); return; }
    { const m = id.match(/^(.+)\/sablony\/([^/]+)\/vyplnit$/); if (m) { renderTpFill(m[1], m[2]); return; } }
    { const m = id.match(/^(.+)\/sablony\/([^/]+)$/); if (m && m[2] !== 'import') { renderTpEd(m[1], m[2]); return; } }
    if (id.endsWith('/sablony')) { renderTemplates(id.slice(0, -8)); return; }
    if (id.includes('/manualy')) { const [pid, , ...sub] = id.split('/'); renderManuals(pid, sub); return; }
    const p = byId(id);
    const v = $('#view');
    if (!p) {
      v.innerHTML = S.loaded
        ? `<a href="#projekty" class="back"><span class="i i-arrow-left s16" aria-hidden="true"></span>Všechny projekty</a><div class="empty"><span class="ico-c"><span class="i i-info" aria-hidden="true"></span></span><h2 style="font-size:18px;font-weight:600">Projekt neexistuje</h2><p style="color:var(--muted)">Mohl být odstraněn.</p></div>`
        : `<div class="sk" style="height:120px"></div>`;
      return;
    }
    const has = p.colors && p.colors.length, on = projModules(p), { owner, col } = projUsers(p), n = (owner ? 1 : 0) + col.length;
    const tiles = MODULES.filter((m) => m.tile && on.includes(m.k)).concat([{ k: 'galerie', name: 'Galerie', icon: 'i-image', desc: 'Sdílená knihovna obrázků projektu' }, { k: 'fonty', name: 'Fonty', icon: 'i-font', desc: 'Písma používaná napříč projektem' }, { k: 'barvy', name: 'Paleta barev', icon: 'i-palette', desc: 'Barvy značky pro šablony a tisk' }]);
    const ord = tileOrder(); tiles.sort((x, y) => { const i = ord.indexOf(x.k), j = ord.indexOf(y.k); return (i < 0 ? 99 : i) - (j < 0 ? 99 : j); });
    const word = (k, c) => { const w = cntWord[k]; return w[c === 1 ? 0 : c >= 2 && c <= 4 ? 1 : 2]; };
    const urow = (u, isOwn) => `<li class="pu-row">${u.id ? `<a class="pu-who" href="#uzivatel-${esc(u.id)}">` : '<span class="pu-who">'}<span class="av" aria-hidden="true">${esc(uInit(u))}</span><span class="who"><b>${esc(uLabel(u))}${u.me ? ' <span class="cap">(vy)</span>' : ''}</b>${u.status === 'invited' ? '<span class="cap">Pozvánka odeslána</span>' : ''}</span>${u.id ? '</a>' : '</span>'}<span class="pu-tags">${isOwn ? '<span class="pill pch">Vlastník</span>' : ''}${u.status === 'invited' ? '<span class="pill">Pozvánka</span>' : ''}${rolePill(u.role)}</span></li>`;
    v.innerHTML = `<a href="#projekty" class="back"><span class="i i-arrow-left s16" aria-hidden="true"></span>Všechny projekty</a>
      <div class="dhead">${symbol(p)}<div style="display:flex;flex-direction:column;gap:8px;min-width:0"><span class="lbl">Projekt</span><h1>${esc(p.name)}</h1><div class="meta"><span class="i i-users s16" aria-hidden="true"></span>${usersText(n)} · ${templatesText(p.templates)}</div></div></div>
      <div style="display:flex;flex-direction:column;gap:12px"><span class="pd-palh"><span class="lbl">Barevná paleta</span></span>${has ? `<div class="swatches">${p.colors.map((c) => `<span class="pd-sw"><i style="background:${esc(c)}"></i>${esc(c.toUpperCase())}</span>`).join('')}<a class="pd-pall" href="#projekt-${esc(p.id)}/barvy" aria-label="${viewAs() === 'user' ? 'Zobrazit paletu barev' : 'Upravit paletu barev'}" title="${viewAs() === 'user' ? 'Zobrazit paletu' : 'Upravit paletu'}"><span class="i ${viewAs() === 'user' ? 'i-eye' : 'i-edit'} s16" aria-hidden="true"></span></a></div>` : `<span class="miss" style="margin:0"><span class="i i-info s14" aria-hidden="true"></span>Chybí barevná paleta</span>`}</div>
      <div class="tiles pd-tiles" data-pdtiles>${tiles.map((m) => { const c = modCount(p, m.k); const tag = ['galerie', 'fonty', 'barvy', 'manualy', 'sablony'].includes(m.k) ? `a href="#projekt-${esc(p.id)}/${m.k}"` : 'div'; return `<${tag} draggable="true" data-pdk="${m.k}" class="tile pd-tile${['galerie', 'fonty', 'barvy', 'manualy', 'sablony'].includes(m.k) ? ' link' : ''}"><span class="pd-grip" aria-hidden="true" title="Přetažením přeskládáte"><span class="i i-grip s14"></span></span><div class="pd-tile-h"><span class="pd-ico" aria-hidden="true"><span class="i ${m.icon}"></span></span></div><span class="pd-t">${m.name}<span class="pd-n" aria-label="${c} ${word(m.k, c)}">${fmtN(c)}</span></span><span class="cap">${m.desc}</span></${tag.split(' ')[0]}>`; }).join('')}</div>
      <section class="pd-box" aria-labelledby="pdUsersH">
        <div class="pd-box-h"><div><h2 id="pdUsersH">Uživatelé <span class="pd-n">${n}</span></h2></div><button type="button" class="btn sm" id="pdAdd"><span class="i i-user-plus s16" aria-hidden="true"></span>Přidat kolegu</button></div>
        <ul class="pu-list">${owner ? urow(owner, true) : ''}${col.map((u) => urow(u, false)).join('')}</ul>
        ${col.length ? '' : '<p class="cap pd-empty">Projekt zatím s nikým nesdílíte.</p>'}
      </section>
      ${viewAs() === 'admin' ? `<section class="pd-box" aria-labelledby="pdModH">
        <div class="pd-box-h"><div><h2 id="pdModH">Moduly projektu <span class="pill pd-admin"><span class="i i-shield" aria-hidden="true"></span>Jen administrátor</span></h2><span class="cap">Vypnuté moduly uživatelé nevidí v menu — najdou je v sekci „Objevte další moduly“, kde si je mohou sami zapnout. O každém takovém zapnutí přijde administrátorům e-mail. Vypnutím se žádná data nesmažou.</span></div></div>
        <div class="pd-mods" role="group" aria-label="Moduly projektu">${MODULES.filter((m) => m.k !== 'sablony').map((m) => { const o = on.includes(m.k); return `<label class="pd-mod${o ? ' on' : ''}"><span class="pd-ico" aria-hidden="true"><span class="i ${m.icon}"></span></span><span class="who"><b>${m.name}</b><span class="cap">${m.desc}${m.nav ? ` · v menu jako ${m.nav}` : ''}</span></span><span class="pd-st">${o ? 'Zapnuto' : 'Vypnuto'}</span><input type="checkbox" role="switch" class="sr" data-pdmod="${m.k}"${o ? ' checked' : ''} aria-label="${m.name}"><span class="sw${o ? ' on' : ''}" aria-hidden="true"></span></label>`; }).join('')}</div>
        <p class="cap pd-foot"><span class="i i-info s14" aria-hidden="true"></span>Galerie a Fonty jsou vždy zapnuté — čerpají z nich ostatní moduly.</p>
      </section>` : ''}`;
  }
  $('#view').addEventListener('click', (e) => {
    if (!S.route.startsWith('projekt-')) return;
    if (e.target.closest('#pdAdd')) openShare(S.route.slice(8));
  });
  $('#view').addEventListener('change', async (e) => {
    const c = e.target.closest('[data-pdmod]'); if (!c || !S.route.startsWith('projekt-')) return;
    const p = byId(S.route.slice(8)); if (!p) return;
    const m = MODULES.find((x) => x.k === c.dataset.pdmod), cur = projModules(p);
    const modules = c.checked ? MODULES.map((x) => x.k).filter((k) => cur.includes(k) || k === m.k) : cur.filter((k) => k !== m.k);
    let ok = true;
    if (db) ok = await guard(db.doc('projects/' + p.id).update({ modules }));
    if (!ok) { c.checked = !c.checked; return; }
    p.modules = modules; CL_CACHE = null;
    toast(`Modul ${m.name} ${c.checked ? 'zapnut' : 'vypnut'}`);
    renderDetail(p.id); renderProjNav();
    const el = $(`[data-pdmod="${m.k}"]`); if (el) el.focus({ preventScroll: true });
  });
  /* sidebar: menu of the open project — Přehled + enabled modules (AI modul has no page of its own) + Galerie + Fonty, always on.
     Module pages come later; until then every item leads to the project dashboard. */
  const PNAV_ALWAYS = [{ k: 'galerie', name: 'Galerie', icon: 'i-image' }, { k: 'fonty', name: 'Fonty', icon: 'i-font' }, { k: 'barvy', name: 'Paleta barev', icon: 'i-palette' }];
  function renderProjNav() {
    const nav = $('#projNav'); if (!nav) return;
    const sub = S.route.startsWith('projekt-') ? S.route.slice(8).split('/') : [], id = sub[0] || null, page = sub[1] || '', p = id && byId(id);
    if (!p) { nav.hidden = true; nav.innerHTML = ''; delete nav.dataset.pid; return; }
    const on = projModules(p), mods = MODULES.filter((m) => !m.noNav && on.includes(m.k));
    const head = ['manualy', 'sablony'], items = mods.filter((m) => head.includes(m.k)).concat(PNAV_ALWAYS, mods.filter((m) => !head.includes(m.k))); // Manuály, Šablony, Galerie, Fonty, then the rest
    const same = nav.dataset.pid === p.id, prev = new Set([...nav.querySelectorAll('[data-pnav]')].map((a) => a.dataset.pnav));
    nav.hidden = false; nav.dataset.pid = p.id;
    nav.innerHTML = `<div class="sb-sec lbl hide-c">Projekt</div><span class="sb-div" aria-hidden="true"></span>
      <a href="#projekt-${esc(p.id)}" class="nav-i"${page ? '' : ' aria-current="page"'} title="Přehled" aria-label="Přehled projektu"><span class="i i-grid" aria-hidden="true"></span><span class="hide-c">Přehled</span></a>
      ${items.map((m) => `<a href="#projekt-${esc(p.id)}${['galerie', 'fonty', 'barvy', 'manualy', 'sablony'].includes(m.k) ? '/' + m.k : ''}"${page === m.k ? ' aria-current="page"' : ''} class="nav-i${same && !prev.has(m.k) ? ' pnav-new' : ''}" data-pnav="${m.k}" title="${m.nav || m.name}" aria-label="${m.nav || m.name}"><span class="i ${m.icon}" aria-hidden="true"></span><span class="hide-c">${m.nav || m.name}</span></a>`).join('')}`;
  }
  /* ---------- Galerie projektu (#projekt-<id>/galerie) ----------
     Shared image library of a project, used by the template editors (buttons "Pozadí" and "Přidat obrázek").
     Folders as cards (drop images onto them to move), upload by dragging files anywhere on the page or with "Nahrát obrázky",
     search + type chips, multi-select with a bulk bar, preview with details and rename, Koš with restore / delete forever.
     Prototype: sample images are generated from the project colours, uploads live in memory only (object URLs). */
  const GAL_MAX = 10 * 1024 * 1024, GAL_TYPES = ['PNG', 'JPG', 'SVG', 'WebP'];
  S.gal = {};
  const galSvg = (bg, fg, kind, w, h) => {
    const shapes = kind === 0 ? `<circle cx='${w * .72}' cy='${h * .35}' r='${h * .28}' fill='${fg}' opacity='.9'/><rect x='${w * .08}' y='${h * .62}' width='${w * .5}' height='${h * .12}' rx='${h * .06}' fill='${fg}' opacity='.55'/>`
      : kind === 1 ? `<path d='M0 ${h * .75} Q ${w * .3} ${h * .45} ${w * .55} ${h * .7} T ${w} ${h * .55} V ${h} H 0 Z' fill='${fg}' opacity='.8'/><circle cx='${w * .25}' cy='${h * .3}' r='${h * .12}' fill='${fg}'/>`
      : kind === 2 ? `<rect x='${w * .3}' y='${h * .2}' width='${w * .4}' height='${h * .6}' rx='${h * .08}' fill='none' stroke='${fg}' stroke-width='${h * .04}'/><path d='M${w * .38} ${h * .62} l${w * .08} -${h * .16} l${w * .07} ${h * .1} l${w * .05} -${h * .06} l${w * .05} ${h * .12}' fill='none' stroke='${fg}' stroke-width='${h * .03}' stroke-linejoin='round'/>`
      : `<g fill='${fg}'>${Array.from({ length: 12 }, (_, i) => `<circle cx='${(i % 4 + .5) * w / 4}' cy='${(Math.floor(i / 4) + .5) * h / 3}' r='${h * .06}' opacity='${.35 + (i % 3) * .25}'/>`).join('')}</g>`;
    return 'data:image/svg+xml;utf8,' + encodeURIComponent(`<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 ${w} ${h}' width='${w}' height='${h}'>${bg ? `<rect width='100%' height='100%' fill='${bg}'/>` : ''}${shapes}</svg>`);
  };
  function galOf(pid) {
    if (S.gal[pid]) return S.gal[pid];
    const p = byId(pid) || {}, r = seeded('gal' + pid), col = (p.colors && p.colors.length ? p.colors : ['#2B5D57', '#A8C256', '#F4C09A', '#E9E4F0']);
    const c = (i) => col[i % col.length];
    const folders = [{ id: 'f1', name: 'Pozadí' }, { id: 'f2', name: 'Loga' }, { id: 'f3', name: 'Fotky z akcí' }].slice(0, 1 + Math.floor(r() * 3));
    const names = ['Pozadí příspěvku', 'Banner web', 'Plakát podklad', 'Logo barevné', 'Logo bílé', 'Ikona', 'Vzor', 'Story pozadí', 'Titulka prezentace', 'Leták podklad', 'Facebook cover', 'Motiv'];
    const dims = [[1080, 1080], [1080, 1920], [1920, 1080], [1080, 190], [820, 312], [2480, 3508], [512, 512]];
    const n = 7 + Math.floor(r() * 6), DAY = 864e5, now = new Date(2026, 9, 30).getTime();
    const by = [ME].concat(allUsers().filter((u) => (p.collaborators || []).includes(u.id)).map(uLabel));
    const items = Array.from({ length: n }, (_, i) => {
      const fmt = i % 5 === 3 ? 'SVG' : i % 4 === 1 ? 'JPG' : 'PNG', d = fmt === 'SVG' ? [512, 512] : dims[Math.floor(r() * dims.length)];
      const scale = 240 / Math.max(d[0], d[1]), tw = Math.round(d[0] * scale), th = Math.round(d[1] * scale);
      const logo = names[i % names.length].startsWith('Logo') || fmt === 'SVG';
      return { id: 'g' + i, name: i === 2 || i === 6 ? '' : names[i % names.length], fmt, w: d[0], h: d[1], vec: fmt === 'SVG',
        size: fmt === 'SVG' ? 4000 + Math.floor(r() * 20000) : Math.floor((d[0] * d[1]) * (fmt === 'JPG' ? .35 : .9) * (.4 + r() * .6)),
        src: galSvg(logo ? null : c(i), logo ? c(i + 1) : c(i + 1), Math.floor(r() * 4), tw, th),
        folder: r() < .45 && folders.length ? folders[Math.floor(r() * folders.length)].id : null,
        at: new Date(now - Math.floor(r() * 120) * DAY), by: by[Math.floor(r() * by.length)], used: Math.floor(r() * 5), trash: i === n - 1 };
    });
    return (S.gal[pid] = { folders, items, cwd: null, sel: new Set(), q: '', type: 'all', drag: false, up: [] });
  }
  const galName = (it) => it.name || 'Bez názvu';
  const fmtSize = (b) => (b >= 1048576 ? (Math.round(b / 104857.6) / 10).toLocaleString('cs-CZ') + ' MB' : Math.max(1, Math.round(b / 1024)) + ' kB');
  const imgWord = (n) => n + ' ' + (n === 1 ? 'obrázek' : n >= 2 && n <= 4 ? 'obrázky' : 'obrázků');
  const galPid = () => (S.route.startsWith('projekt-') && S.route.endsWith('/galerie') ? S.route.slice(8, -8) : null);
  const galVisible = (G) => G.items.filter((it) => (G.cwd === 'trash' ? it.trash : !it.trash && (G.q ? true : it.folder === G.cwd)) && (G.type === 'all' || it.fmt === G.type) && (!G.q || galName(it).toLowerCase().includes(G.q.toLowerCase())));
  /* thumbnail size slider (like Finder's icon size) — shared by Galerie and Šablony; remembered per viewer, cards reflow live */
  const SZ = { gal: { min: 100, max: 360, def: 168 }, tp: { min: 170, max: 480, def: 250 }, fi: { min: 240, max: 820, def: 440 } };
  const szGet = (k) => { const d = SZ[k], dv = Math.round((d.def - d.min) / (d.max - d.min) * 100); try { const raw = localStorage.getItem('wb-sz2-' + k); const v = Number(raw); return raw !== null && Number.isFinite(v) ? Math.max(0, Math.min(100, v)) : dv; } catch (e) { return dv; } };
  // continuous: the slider sets the card min-width (and template preview height) as CSS variables on #view, the grid reflows smoothly
  const szApply = (k, v) => { const d = SZ[k], px = Math.round(d.min + (d.max - d.min) * v / 100), V = $('#view');
    V.style.setProperty('--' + k + 'min', px + 'px'); if (k === 'tp') { V.style.setProperty('--tph', Math.round(px * 1.12) + 'px'); V.style.setProperty('--tpfs', (13 + v * .05).toFixed(1) + 'px'); } V.classList.toggle(k + '-sm', v < 22); };
  const szCtl = (k) => { const v = szGet(k); szApply(k, v); return `<label class="sz-ctl" title="Velikost náhledů"><span class="i i-image s12" aria-hidden="true"></span><input type="range" min="0" max="100" step="1" value="${v}" style="--p:${v}%" data-szk="${k}" aria-label="Velikost náhledů" aria-valuetext="${v} %"><span class="i i-image s18" aria-hidden="true"></span></label>`; };
  $('#view').addEventListener('input', (e) => { const r = e.target.closest?.('[data-szk]'); if (!r) return; const k = r.dataset.szk, v = Number(r.value); szApply(k, v); r.style.setProperty('--p', v + '%'); r.setAttribute('aria-valuetext', v + ' %'); try { localStorage.setItem('wb-sz2-' + k, v); } catch (err) {} });
  function renderGallery(pid) {
    const p = byId(pid), v = $('#view');
    if (!p) { renderDetail(pid); return; }
    const G = galOf(pid), live = G.items.filter((x) => !x.trash), trashN = G.items.length - live.length, fold = G.folders.find((f) => f.id === G.cwd);
    const list = galVisible(G), inTrash = G.cwd === 'trash';
    const here = inTrash ? 'Koš' : fold ? fold.name : 'Galerie';
    const scope = live.filter((x) => (G.q ? galName(x).toLowerCase().includes(G.q.toLowerCase()) : x.folder === G.cwd)), typeN = (t) => scope.filter((x) => t === 'all' || x.fmt === t).length;
    v.innerHTML = `<a href="#projekt-${esc(p.id)}" class="back"><span class="i i-arrow-left s16" aria-hidden="true"></span>${esc(p.name)}</a>
      <div class="head"><div style="display:flex;flex-direction:column;gap:8px;min-width:0"><span class="lbl">Projekt · ${esc(p.name)}</span><h1>Galerie</h1><span class="cap gal-lead">Sdílené obrázky projektu. Použijete je v editorech šablon a sociálních sítí přes tlačítka „Pozadí“ a „Přidat obrázek“.</span></div>
</div>
      <div class="gal-nav" role="toolbar" aria-label="Galerie">
        <button type="button" class="btn sm" data-gal="upload"><span class="i i-upload s16" aria-hidden="true"></span>Nahrát obrázky</button>
        <button type="button" class="btn sm tertiary" data-gal="newfolder"><span class="i i-folder s16" aria-hidden="true"></span>Nová složka</button>
        <span class="gal-nav-sp" aria-hidden="true"></span>
        <button type="button" class="gal-trash-b${inTrash ? ' on' : ''}" data-galcd="${inTrash ? '' : 'trash'}" data-drop="trash" aria-pressed="${inTrash}"><span class="i ${inTrash ? 'i-arrow-left' : 'i-trash'} s16" aria-hidden="true"></span>${inTrash ? 'Zpět do galerie' : 'Koš'}${trashN && !inTrash ? `<span class="n">${trashN}</span>` : ''}</button>
      </div>
      <input type="file" id="galFile" accept="image/png,image/jpeg,image/svg+xml,image/webp" multiple hidden>
      ${inTrash ? '' : `<div class="gal-folders" role="list" aria-label="Složky">
        <button type="button" role="listitem" class="gal-fold${G.cwd === null && !G.q ? ' on' : ''}" data-galcd="" data-drop=""><span class="gal-fi" aria-hidden="true"><span class="i i-image"></span></span><span class="who"><b>Galerie</b><span class="cap">${imgWord(live.filter((x) => !x.folder).length)}</span></span></button>
        ${G.folders.map((f) => `<div role="listitem" class="gal-fold${G.cwd === f.id ? ' on' : ''}" data-drop="${f.id}"><button type="button" class="gal-fold-b" data-galcd="${f.id}"><span class="gal-fi" aria-hidden="true"><span class="i i-folder"></span></span><span class="who"><b>${esc(f.name)}</b><span class="cap">${imgWord(live.filter((x) => x.folder === f.id).length)}</span></span></button><button type="button" class="btn icon xs ghost gal-fold-m" data-galfm="${f.id}" aria-label="Možnosti složky ${esc(f.name)}" aria-haspopup="menu"><span class="i i-more s16" aria-hidden="true"></span></button></div>`).join('')}
      </div>`}
      <div class="gal-bar">
        <nav class="gal-path" aria-label="Umístění">${G.cwd === null && !G.q ? '<b>Galerie</b>' : `<button type="button" class="gal-crumb" data-galcd="">Galerie</button><span class="i i-chev-right s14" aria-hidden="true"></span><b>${G.q ? 'Výsledky hledání' : esc(here)}</b>`}<span class="cap">· ${imgWord(list.length)}</span></nav>
        <div class="gal-tools">${inTrash ? '' : `<div class="fbar" role="group" aria-label="Typ souboru">${['all'].concat(GAL_TYPES).filter((t) => t === 'all' || typeN(t) || G.type === t).map((t) => `<button type="button" class="chip sm${G.type === t ? ' on' : ''}" data-galtype="${t}" aria-pressed="${G.type === t}">${t === 'all' ? 'Vše' : t}<span class="n">${typeN(t)}</span></button>`).join('')}</div>${szCtl('gal')}`}
          <label class="inp sm gal-search" for="galQ"><span class="i i-search s14" aria-hidden="true"></span><input type="search" id="galQ" placeholder="Hledat podle názvu" autocomplete="off" value="${esc(G.q)}"></label></div>
      </div>
      ${inTrash ? `<div class="gal-note"><span class="i i-info s16" aria-hidden="true"></span><span>Obrázky v koši se v editorech nenabízejí. Obnovte je, nebo je smažte trvale.</span>${trashN ? '<button type="button" class="btn sm ghost danger" data-gal="empty">Vysypat koš</button>' : ''}</div>`
        : `<label class="gal-drop${G.drag ? ' on' : ''}" for="galFile" id="galDrop"><span class="i i-upload" aria-hidden="true"></span><span><b>Přetáhněte obrázky kamkoli na stránku</b> nebo <u>vyberte z počítače</u></span><span class="cap">Nahraje se do: <b>${esc(here)}</b> · PNG, JPG, SVG, WebP · víc najednou · max. 10 MB na soubor</span></label>`}
      <div class="gal-grid" role="list" aria-label="Obrázky">${G.up.map((u) => `<div role="listitem" class="gal-card up"><div class="gal-th"><span class="gal-prog"><i style="width:${u.p}%"></i></span></div><div class="gal-meta"><b>${esc(u.name)}</b><span class="cap">Nahrávám…</span></div></div>`).join('')}${list.map((it) => galCard(it, G, inTrash)).join('')}</div>
      ${!list.length && !G.up.length ? `<div class="empty gal-empty"><span class="ico-c"><span class="i ${inTrash ? 'i-trash' : G.q ? 'i-search' : 'i-image'}" aria-hidden="true"></span></span><h2 style="font-size:17px;font-weight:600">${inTrash ? 'Koš je prázdný' : G.q || G.type !== 'all' ? 'Nic neodpovídá hledání' : 'Zatím tu nejsou žádné obrázky'}</h2><p class="cap" style="max-width:360px">${inTrash ? 'Smazané obrázky se sem přesunou a můžete je obnovit.' : G.q || G.type !== 'all' ? 'Zkuste jiný název nebo typ souboru.' : 'Přetáhněte sem obrázky z počítače, nebo klikněte na Nahrát obrázky.'}</p></div>` : ''}
      <div class="gal-bulk" id="galBulk" ${G.sel.size ? '' : 'hidden'} role="toolbar" aria-label="Hromadné akce"><b>${G.sel.size} ${G.sel.size === 1 ? 'vybraný' : G.sel.size <= 4 ? 'vybrané' : 'vybraných'}</b><span class="sep" aria-hidden="true"></span>
        ${inTrash ? `<button type="button" class="btn sm ghost" data-gal="restore"><span class="i i-history s16" aria-hidden="true"></span>Obnovit</button><button type="button" class="btn sm ghost danger" data-gal="purge"><span class="i i-trash s16" aria-hidden="true"></span>Smazat trvale</button>`
        : `<button type="button" class="btn sm ghost" data-gal="move"><span class="i i-folder s16" aria-hidden="true"></span>Přesunout</button><button type="button" class="btn sm ghost" data-gal="download"><span class="i i-download s16" aria-hidden="true"></span>Stáhnout</button><button type="button" class="btn sm ghost danger" data-gal="trash"><span class="i i-trash s16" aria-hidden="true"></span>Do koše</button>`}
        <button type="button" class="btn icon xs ghost" data-gal="unselect" aria-label="Zrušit výběr"><span class="i i-x s16" aria-hidden="true"></span></button></div>`;
    const q = $('#galQ');
    q.addEventListener('input', (e) => { G.q = e.target.value; const pos = e.target.selectionStart; renderGallery(pid); const n = $('#galQ'); n.focus(); n.setSelectionRange(pos, pos); });
    $('#galFile').addEventListener('change', (e) => { galUpload(pid, [...e.target.files]); e.target.value = ''; });
  }
  function galCard(it, G, inTrash) {
    const on = G.sel.has(it.id);
    return `<div role="listitem" class="gal-card${on ? ' sel' : ''}${G.sel.size ? ' picking' : ''}" data-gid="${it.id}" ${inTrash ? '' : 'draggable="true"'}>
      <div class="gal-th${it.vec || it.fmt === 'PNG' ? ' gal-chk' : ''}"><img src="${it.src}" alt="" loading="lazy">
        <button type="button" class="gal-open" data-galopen="${it.id}" aria-label="Otevřít náhled: ${esc(galName(it))}"></button>
        <span class="gal-fmt">${it.fmt}</span>${it.used && !inTrash ? `<span class="gal-used" title="Použito v ${it.used} ${it.used === 1 ? 'šabloně' : 'šablonách'}"><span class="i i-template" aria-hidden="true"></span>${it.used}×</span>` : ''}</div>
      <div class="gal-meta"><label class="gal-pick" title="Vybrat"><input type="checkbox" class="sr" data-galsel="${it.id}"${on ? ' checked' : ''} aria-label="Vybrat ${esc(galName(it))}"><span class="cb${on ? ' on' : ''}" aria-hidden="true">${on ? '<span class="i i-check"></span>' : ''}</span></label><span class="gal-mt"><b class="${it.name ? '' : 'none'}">${esc(galName(it))}</b><span class="cap">${it.vec ? 'Vektor' : `${it.w} × ${it.h} px`} · ${fmtSize(it.size)}</span></span>${inTrash ? '' : `<button type="button" class="btn icon xs gal-more" data-galm="${it.id}" aria-label="Možnosti: ${esc(galName(it))}" aria-haspopup="menu"><span class="i i-more s16" aria-hidden="true"></span></button>`}</div>
      ${inTrash ? `<div class="gal-trash-a"><button type="button" class="btn sm ghost" data-galrestore="${it.id}"><span class="i i-history s16" aria-hidden="true"></span>Obnovit</button><button type="button" class="btn icon sm ghost danger" data-galpurge="${it.id}" aria-label="Smazat trvale"><span class="i i-trash s16" aria-hidden="true"></span></button></div>` : ''}
    </div>`;
  }
  /* uploads: validate, show a progress tile, then the image (object URL — prototype only) */
  function galUpload(pid, files) {
    const G = galOf(pid), ok = [], bad = [];
    files.forEach((f) => { const t = (f.type.split('/')[1] || '').toLowerCase(); if (!/^(png|jpeg|svg\+xml|webp)$/.test(t)) bad.push(`${f.name}: nepodporovaný formát`); else if (f.size > GAL_MAX) bad.push(`${f.name}: víc než 10 MB`); else ok.push(f); });
    if (bad.length) toast(bad.length === 1 ? `Nenahráno — ${bad[0]}` : `${bad.length} soubory nenahrány (formát nebo víc než 10 MB)`, true);
    if (!ok.length) return;
    const ups = ok.map((f) => ({ name: f.name.replace(/\.[^.]+$/, ''), p: 8, f })); G.up.push(...ups); renderGallery(pid);
    let step = 0; const tick = setInterval(() => { step++; ups.forEach((u) => { u.p = Math.min(100, 8 + step * 23); }); if (galPid() === pid) renderGallery(pid); if (step < 4) return; clearInterval(tick);
      Promise.all(ups.map((u) => new Promise((res) => { const src = URL.createObjectURL(u.f), im = new Image(); const fmt = u.f.type.includes('svg') ? 'SVG' : u.f.type.includes('jpeg') ? 'JPG' : u.f.type.includes('webp') ? 'WebP' : 'PNG';
        im.onload = im.onerror = () => res({ id: 'u' + Date.now().toString(36) + Math.random().toString(36).slice(2, 5), name: u.name, fmt, vec: fmt === 'SVG', w: im.naturalWidth || 0, h: im.naturalHeight || 0, size: u.f.size, src, folder: G.cwd === 'trash' ? null : G.cwd, at: new Date(), by: ME, used: 0, trash: false }); im.src = src; })))
        .then((news) => { G.up = G.up.filter((u) => !ups.includes(u)); G.items.unshift(...news); if (galPid() === pid) renderGallery(pid); toast(news.length === 1 ? 'Obrázek nahrán' : `Nahráno: ${imgWord(news.length)}`); });
    }, 160);
  }
  /* floating menu for an image or a folder */
  let galMenuEl = null;
  function galMenu(btn, items) {
    galCloseMenu(); const r = btn.getBoundingClientRect();
    galMenuEl = document.createElement('div'); galMenuEl.className = 'menu gal-menu'; galMenuEl.setAttribute('role', 'menu');
    galMenuEl.innerHTML = items.map((x) => x === '-' ? '<div class="menu-sep"></div>' : `<button type="button" role="menuitem" class="mi${x.danger ? ' danger' : ''}" data-galact="${x.a}"><span class="i ${x.i} s16" aria-hidden="true"></span>${x.t}</button>`).join('');
    document.body.append(galMenuEl);
    const w = galMenuEl.offsetWidth, h = galMenuEl.offsetHeight;
    galMenuEl.style.left = Math.max(8, Math.min(r.right - w, innerWidth - w - 8)) + 'px';
    galMenuEl.style.top = (r.bottom + 6 + h > innerHeight - 8 ? r.top - h - 6 : r.bottom + 6) + 'px';
    galMenuEl.querySelector('.mi').focus({ preventScroll: true });
    galMenuEl.ret = btn; return galMenuEl;
  }
  function galCloseMenu(focus) { if (galMenuEl) { const b = galMenuEl.ret; galMenuEl.remove(); galMenuEl = null; if (focus && b) b.focus({ preventScroll: true }); } }
  /* actions */
  const galSelOr = (G, id) => (id ? [id] : [...G.sel]);
  function galTrash(pid, ids) { const G = galOf(pid); ids.forEach((id) => { const it = G.items.find((x) => x.id === id); if (it) it.trash = true; G.sel.delete(id); }); renderGallery(pid); toast(`${ids.length === 1 ? 'Obrázek přesunut' : imgWord(ids.length) + ' přesunuto'} do koše`); }
  function galRestore(pid, ids) { const G = galOf(pid); ids.forEach((id) => { const it = G.items.find((x) => x.id === id); if (it) { it.trash = false; if (it.folder && !G.folders.some((f) => f.id === it.folder)) it.folder = null; } G.sel.delete(id); }); renderGallery(pid); toast(ids.length === 1 ? 'Obrázek obnoven' : `Obnoveno: ${imgWord(ids.length)}`); }
  function galMoveTo(pid, ids, fid) { const G = galOf(pid); ids.forEach((id) => { const it = G.items.find((x) => x.id === id); if (it) it.folder = fid || null; G.sel.delete(id); }); renderGallery(pid); const f = G.folders.find((x) => x.id === fid); toast(`${ids.length === 1 ? 'Obrázek přesunut' : imgWord(ids.length) + ' přesunuto'} do: ${f ? f.name : 'Galerie'}`); }
  function galDownload(pid, ids) { const G = galOf(pid); ids.forEach((id) => { const it = G.items.find((x) => x.id === id); if (!it) return; const a = document.createElement('a'); a.href = it.src; a.download = (it.name || 'obrazek') + '.' + (it.fmt === 'JPG' ? 'jpg' : it.fmt.toLowerCase()); document.body.append(a); a.click(); a.remove(); }); toast(ids.length === 1 ? 'Stahuji obrázek' : `Stahuji ${imgWord(ids.length)}`); }
  function galConfirm(title, text, okLabel, fn) {
    const d = $('#dlgGalConfirm'); $('#galConfT').textContent = title; $('#galConfP').textContent = text; $('#galConfOk').textContent = okLabel;
    $('#galConfOk').onclick = () => { d.close(); fn(); }; d.showModal(); $('#galConfOk').focus();
  }
  function galFolderDlg(pid, fid) {
    const G = galOf(pid), f = G.folders.find((x) => x.id === fid), d = $('#dlgGalFolder'), inp = $('#galFolderName'), save = $('#galFolderSave');
    $('#galFolderT').textContent = f ? 'Přejmenovat složku' : 'Nová složka'; save.textContent = f ? 'Uložit název' : 'Vytvořit složku';
    inp.value = f ? f.name : ''; $('#galFolderErr').hidden = true; $('#galFolderBox').classList.remove('err');
    const sync = () => { save.disabled = !inp.value.trim() || (f && inp.value.trim() === f.name); }; sync(); inp.oninput = () => { $('#galFolderErr').hidden = true; $('#galFolderBox').classList.remove('err'); sync(); };
    $('#formGalFolder').onsubmit = (e) => { e.preventDefault(); const n = inp.value.trim(); if (!n) return;
      if (G.folders.some((x) => x.id !== fid && x.name.toLowerCase() === n.toLowerCase())) { $('#galFolderErr').hidden = false; $('#galFolderBox').classList.add('err'); return; }
      if (f) f.name = n; else { const id = 'f' + Date.now().toString(36); G.folders.push({ id, name: n }); }
      d.close(); renderGallery(pid); toast(f ? 'Složka přejmenována' : `Složka ${n} vytvořena`); };
    d.showModal(); inp.focus(); inp.select();
  }
  function galMoveDlg(pid, ids) {
    const G = galOf(pid), d = $('#dlgGalMove'), cur = ids.length === 1 ? (G.items.find((x) => x.id === ids[0]) || {}).folder || '' : null;
    $('#galMoveT').textContent = ids.length === 1 ? 'Přesunout obrázek' : `Přesunout ${imgWord(ids.length)}`;
    const opts = [{ id: '', name: 'Galerie', root: true }].concat(G.folders);
    let pick = cur == null ? null : cur;
    const draw = () => { $('#galMoveList').innerHTML = opts.map((f) => `<label class="gal-mv${pick === f.id ? ' on' : ''}"><input type="radio" class="sr" name="galmv" value="${f.id}"${pick === f.id ? ' checked' : ''}><span class="rd${pick === f.id ? ' on' : ''}" aria-hidden="true"></span><span class="i ${f.root ? 'i-image' : 'i-folder'} s16" aria-hidden="true"></span><b>${esc(f.name)}</b>${f.id === cur ? '<span class="cap">teď tady</span>' : ''}</label>`).join(''); $('#galMoveOk').disabled = pick == null || pick === cur; };
    $('#galMoveList').onchange = (e) => { pick = e.target.value; draw(); };
    $('#galMoveOk').onclick = () => { d.close(); galMoveTo(pid, ids, pick); };
    draw(); d.showModal(); ($('#galMoveList input:checked') || $('#galMoveList input')).focus();
  }
  /* preview */
  function galPreview(pid, id) {
    const G = galOf(pid), list = galVisible(G), i = list.findIndex((x) => x.id === id), it = list[i]; if (!it) return;
    const d = $('#dlgGal'), f = G.folders.find((x) => x.id === it.folder), inTrash = it.trash;
    d.dataset.pid = pid; d.dataset.gid = id;
    $('#galPvT').textContent = galName(it); $('#galPvN').textContent = `${i + 1} / ${list.length}`;
    $('#galPvPrev').disabled = i <= 0; $('#galPvNext').disabled = i >= list.length - 1;
    $('#galPvImg').innerHTML = `<img src="${it.src}" alt="${esc(galName(it))}">`; $('#galPvImg').classList.toggle('gal-chk', it.vec || it.fmt === 'PNG');
    $('#galPvSide').innerHTML = `<label class="field" for="galPvName"><span>Název</span><span class="inp"><input type="text" id="galPvName" maxlength="80" value="${esc(it.name)}" placeholder="Bez názvu" autocomplete="off"${inTrash ? ' disabled' : ''}></span><span class="cap">Podle názvu obrázek najdete v editoru šablon.</span></label>
      <dl class="gal-dl"><div><dt>Formát</dt><dd>${it.fmt}${it.vec ? ' · vektor' : ''}</dd></div><div><dt>Rozměry</dt><dd>${it.vec ? 'libovolné (vektor)' : `${it.w} × ${it.h} px`}</dd></div><div><dt>Velikost</dt><dd>${fmtSize(it.size)}</dd></div><div><dt>Složka</dt><dd>${inTrash ? 'Koš' : esc(f ? f.name : 'Galerie')}</dd></div><div><dt>Nahráno</dt><dd>${fmtDate(it.at.toISOString().slice(0, 10))} · ${esc(it.by)}</dd></div><div><dt>Použito</dt><dd>${it.used ? `v ${it.used} ${it.used === 1 ? 'šabloně' : 'šablonách'}` : 'zatím nikde'}</dd></div></dl>
      ${it.used && !inTrash ? '<p class="gal-warn"><span class="i i-info s14" aria-hidden="true"></span>Když obrázek smažete, v hotových šablonách zůstane, jen ho už nepůjde znovu vybrat.</p>' : ''}`;
    $('#galPvActs').innerHTML = inTrash ? `<button type="button" class="btn sm ghost" data-galpv="restore"><span class="i i-history s16" aria-hidden="true"></span>Obnovit</button>`
      : `<button type="button" class="btn sm ghost" data-galpv="download"><span class="i i-download s16" aria-hidden="true"></span>Stáhnout</button><button type="button" class="btn sm ghost" data-galpv="move"><span class="i i-folder s16" aria-hidden="true"></span>Přesunout</button><button type="button" class="btn sm ghost danger" data-galpv="trash"><span class="i i-trash s16" aria-hidden="true"></span>Do koše</button>`;
    const nm = $('#galPvName'); const saveName = () => { const n = nm.value.trim(); if (n === it.name) return; it.name = n; $('#galPvT').textContent = galName(it); renderGallery(pid); toast('Název uložen'); };
    nm.addEventListener('change', saveName); nm.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); nm.blur(); } });
    if (!d.open) d.showModal();
  }
  /* events */
  $('#view').addEventListener('click', (e) => {
    const pid = galPid(); if (!pid) return; const G = galOf(pid);
    const a = e.target.closest('[data-gal]');
    if (a) { const k = a.dataset.gal;
      if (k === 'upload') $('#galFile').click();
      else if (k === 'newfolder') galFolderDlg(pid, null);
      else if (k === 'unselect') { G.sel.clear(); renderGallery(pid); }
      else if (k === 'move') galMoveDlg(pid, [...G.sel]);
      else if (k === 'download') galDownload(pid, [...G.sel]);
      else if (k === 'trash') galTrash(pid, [...G.sel]);
      else if (k === 'restore') galRestore(pid, [...G.sel]);
      else if (k === 'purge') { const ids = [...G.sel]; galConfirm(`Smazat trvale ${imgWord(ids.length)}?`, 'Obrázky zmizí z galerie natrvalo. Šablony, ve kterých už jsou použité, zůstanou beze změny.', 'Smazat trvale', () => { G.items = G.items.filter((x) => !ids.includes(x.id)); G.sel.clear(); renderGallery(pid); toast('Smazáno trvale'); }); }
      else if (k === 'empty') { const n = G.items.filter((x) => x.trash).length; galConfirm('Vysypat koš?', `${imgWord(n)} zmizí natrvalo. Šablony, ve kterých už jsou použité, zůstanou beze změny.`, 'Vysypat koš', () => { G.items = G.items.filter((x) => !x.trash); G.sel.clear(); renderGallery(pid); toast('Koš vysypán'); }); }
      return; }
    const cd = e.target.closest('[data-galcd]'); if (cd) { G.cwd = cd.dataset.galcd || null; G.q = ''; G.sel.clear(); if (G.cwd === 'trash') G.type = 'all'; renderGallery(pid); return; }
    const ty = e.target.closest('[data-galtype]'); if (ty) { G.type = ty.dataset.galtype; renderGallery(pid); return; }
    const sel = e.target.closest('[data-galsel]'); if (sel) { e.stopPropagation(); return; }
    const op = e.target.closest('[data-galopen]');
    if (op) { if (G.sel.size) { const id = op.dataset.galopen; if (G.sel.has(id)) G.sel.delete(id); else G.sel.add(id); renderGallery(pid); } else galPreview(pid, op.dataset.galopen); return; }
    const m = e.target.closest('[data-galm]');
    if (m) { const id = m.dataset.galm; const menu = galMenu(m, [{ a: 'open', i: 'i-eye', t: 'Náhled a detail' }, { a: 'rename', i: 'i-edit', t: 'Přejmenovat' }, { a: 'move', i: 'i-folder', t: 'Přesunout do složky' }, { a: 'download', i: 'i-download', t: 'Stáhnout' }, '-', { a: 'trash', i: 'i-trash', t: 'Přesunout do koše', danger: true }]);
      menu.onclick = (ev) => { const b = ev.target.closest('[data-galact]'); if (!b) return; galCloseMenu(); const k = b.dataset.galact;
        if (k === 'open') galPreview(pid, id); else if (k === 'rename') { galPreview(pid, id); $('#galPvName').focus(); $('#galPvName').select(); } else if (k === 'move') galMoveDlg(pid, [id]); else if (k === 'download') galDownload(pid, [id]); else galTrash(pid, [id]); };
      return; }
    const fm = e.target.closest('[data-galfm]');
    if (fm) { const fid = fm.dataset.galfm, f = G.folders.find((x) => x.id === fid); const menu = galMenu(fm, [{ a: 'rename', i: 'i-edit', t: 'Přejmenovat' }, '-', { a: 'del', i: 'i-trash', t: 'Smazat složku', danger: true }]);
      menu.onclick = (ev) => { const b = ev.target.closest('[data-galact]'); if (!b) return; galCloseMenu();
        if (b.dataset.galact === 'rename') galFolderDlg(pid, fid);
        else { const n = G.items.filter((x) => x.folder === fid && !x.trash).length; galConfirm(`Smazat složku ${f.name}?`, n ? `${imgWord(n)} ze složky se přesune do hlavní Galerie, nic se nesmaže.` : 'Složka je prázdná.', 'Smazat složku', () => { G.items.forEach((x) => { if (x.folder === fid) x.folder = null; }); G.folders = G.folders.filter((x) => x.id !== fid); if (G.cwd === fid) G.cwd = null; renderGallery(pid); toast('Složka smazána'); }); } };
      return; }
    const rs = e.target.closest('[data-galrestore]'); if (rs) { galRestore(pid, [rs.dataset.galrestore]); return; }
    const pg = e.target.closest('[data-galpurge]'); if (pg) { const id = pg.dataset.galpurge, it = G.items.find((x) => x.id === id); galConfirm(`Smazat trvale ${galName(it)}?`, 'Obrázek zmizí z galerie natrvalo. Šablony, ve kterých už je použitý, zůstanou beze změny.', 'Smazat trvale', () => { G.items = G.items.filter((x) => x.id !== id); renderGallery(pid); toast('Smazáno trvale'); }); }
  });
  $('#view').addEventListener('change', (e) => {
    const pid = galPid(); if (!pid) return; const c = e.target.closest('[data-galsel]'); if (!c) return;
    const G = galOf(pid); if (c.checked) G.sel.add(c.dataset.galsel); else G.sel.delete(c.dataset.galsel);
    renderGallery(pid); const n = $(`[data-galsel="${c.dataset.galsel}"]`); if (n) n.focus({ preventScroll: true });
  });
  /* drag: files from the computer anywhere on the page; images onto a folder card */
  let galDragDepth = 0;
  const isFiles = (e) => [...(e.dataTransfer?.types || [])].includes('Files');
  document.addEventListener('dragenter', (e) => { const pid = galPid(); if (!pid || !isFiles(e)) return; galDragDepth++; const G = galOf(pid); if (!G.drag && G.cwd !== 'trash') { G.drag = true; document.body.classList.add('gal-dragging'); $('#galDrop')?.classList.add('on'); } });
  document.addEventListener('dragleave', (e) => { const pid = galPid(); if (!pid || !isFiles(e)) return; if (--galDragDepth <= 0) { galDragDepth = 0; galOf(pid).drag = false; document.body.classList.remove('gal-dragging'); $('#galDrop')?.classList.remove('on'); } });
  document.addEventListener('dragover', (e) => { const pid = galPid(); if (!pid) return; if (isFiles(e) || e.target.closest?.('[data-drop]')) e.preventDefault(); const f = e.target.closest?.('[data-drop]'); document.querySelectorAll('.drop-on').forEach((x) => { if (x !== f) x.classList.remove('drop-on'); }); if (f && !isFiles(e)) f.classList.add('drop-on'); });
  document.addEventListener('drop', (e) => {
    const pid = galPid(); if (!pid) return; e.preventDefault(); const G = galOf(pid);
    galDragDepth = 0; G.drag = false; document.body.classList.remove('gal-dragging');
    if (isFiles(e)) { if (G.cwd !== 'trash') galUpload(pid, [...e.dataTransfer.files]); else renderGallery(pid); return; }
    const f = e.target.closest('[data-drop]'), id = e.dataTransfer.getData('text/x-wb-img');
    if (f && id) { const ids = G.sel.has(id) ? [...G.sel] : [id]; if (f.dataset.drop === 'trash') galTrash(pid, ids); else galMoveTo(pid, ids, f.dataset.drop || null); }
  });
  $('#view').addEventListener('dragstart', (e) => { const c = e.target.closest?.('.gal-card[data-gid]'); if (!c || !galPid()) return; e.dataTransfer.setData('text/x-wb-img', c.dataset.gid); e.dataTransfer.effectAllowed = 'move'; c.classList.add('dragging'); document.body.classList.add('gal-moving'); });
  $('#view').addEventListener('dragend', () => { document.body.classList.remove('gal-moving'); document.querySelectorAll('.gal-card.dragging, .drop-on').forEach((x) => x.classList.remove('dragging', 'drop-on')); });
  document.addEventListener('pointerdown', (e) => { if (galMenuEl && !e.target.closest('.gal-menu')) galCloseMenu(); });
  document.addEventListener('keydown', (e) => {
    if (galMenuEl && e.key === 'Escape') { e.preventDefault(); galCloseMenu(true); return; }
    if (galMenuEl && ['ArrowDown', 'ArrowUp'].includes(e.key)) { e.preventDefault(); const it = [...galMenuEl.querySelectorAll('.mi')], k = it.indexOf(document.activeElement); it[(k + (e.key === 'ArrowDown' ? 1 : it.length - 1)) % it.length].focus(); return; }
    const d = $('#dlgGal'); if (d && d.open && ['ArrowLeft', 'ArrowRight'].includes(e.key) && !e.target.matches('input')) { e.preventDefault(); (e.key === 'ArrowLeft' ? $('#galPvPrev') : $('#galPvNext')).click(); }
  });
  /* preview dialog buttons */
  $('#dlgGal').addEventListener('click', (e) => {
    const d = $('#dlgGal'), pid = d.dataset.pid, id = d.dataset.gid, G = galOf(pid), list = galVisible(G), i = list.findIndex((x) => x.id === id);
    if (e.target.closest('#galPvPrev') && i > 0) galPreview(pid, list[i - 1].id);
    else if (e.target.closest('#galPvNext') && i < list.length - 1) galPreview(pid, list[i + 1].id);
    const b = e.target.closest('[data-galpv]'); if (!b) return; const k = b.dataset.galpv;
    if (k === 'download') galDownload(pid, [id]);
    else if (k === 'move') { d.close(); galMoveDlg(pid, [id]); }
    else if (k === 'trash') { d.close(); galTrash(pid, [id]); }
    else if (k === 'restore') { d.close(); galRestore(pid, [id]); }
  });
  /* ---------- Fonty projektu (#projekt-<id>/fonty) ----------
     Families as cards: name set in the family itself,
     cuts sorted by weight, each with a live sample, chips (weight, style, format, usage) and copy-CSS.
     One shared sample text + size for all previews. Upload by dragging files anywhere or "Nahrát písma";
     a cut is filed into its family by the name inside the file (prototype: parsed from the file name, loaded with FontFace so the preview is real). */
  const FN_W = [[100, 'Thin'], [200, 'ExtraLight'], [300, 'Light'], [400, 'Regular'], [500, 'Medium'], [600, 'SemiBold'], [700, 'Bold'], [800, 'ExtraBold'], [900, 'Black']];
  const FN_ALIAS = { hairline: 100, thin: 100, extralight: 200, ultralight: 200, light: 300, book: 400, regular: 400, normal: 400, roman: 400, medium: 500, semibold: 600, demibold: 600, demi: 600, bold: 700, extrabold: 800, ultrabold: 800, heavy: 900, black: 900 };
  const FN_SAMPLES = [['Pangram', 'Příliš žluťoučký kůň úpěl ďábelské ódy'], ['Abeceda', 'AaBbCcČčDdĎdEeĚěFfGgHhIiJjKkLl'], ['Číslice', '0123456789 · 1 299 Kč · 30. 10. 2026']];
  const wName = (w) => (FN_W.find((x) => x[0] === w) || [0, String(w)])[1];
  const cutName = (c) => (c.w === 400 && c.it ? 'Italic' : wName(c.w) + (c.it ? ' Italic' : ''));
  const cutWord = (n) => n + ' ' + (n === 1 ? 'řez' : n >= 2 && n <= 4 ? 'řezy' : 'řezů');
  const tplUse = (n) => (n ? `${n} ${n === 1 ? 'šablona' : n >= 2 && n <= 4 ? 'šablony' : 'šablon'}` : 'nepoužito');
  S.fnt = {};
  function fntOf(pid) {
    if (S.fnt[pid]) return S.fnt[pid];
    const p = byId(pid) || {}, r = seeded('fnt' + pid);
    const fam = (p.fonts || []).map((name, i) => {
      const n = 2 + Math.floor(r() * 6), pool = [400, 700, 500, 300, 600, 200, 800, 900, 100];
      const ws = pool.slice(0, n), cuts = ws.map((w) => ({ w, it: false })).concat(ws.filter(() => r() < .3).map((w) => ({ w, it: true })));
      const fmt = r() < .7 ? 'OTF' : 'TTF';
      return { id: 'f' + i, name, cuts: cuts.map((c, j) => ({ ...c, id: 'c' + i + '-' + j, fmt, size: 30000 + Math.floor(r() * 90000), used: r() < .3 ? 0 : Math.floor(r() * 16) })) };
    });
    /* sample real font (Montserrat, SIL OFL) embedded in the prototype: used wherever a project lists it, and added to Čeladná as a demo */
    const real = (f) => Object.assign(f, { css: 'wb-Montserrat', real: true, cuts: [300, 400, 500, 600, 700, 800].map((w) => ({ w, it: false })).concat([{ w: 400, it: true }, { w: 700, it: true }]).map((c, j) => ({ ...c, id: f.id + '-m' + j, fmt: 'WOFF2', size: 14000 + Math.floor(r() * 6000), used: [3, 12, 6, 4, 9, 1, 0, 2][j] })) });
    fam.forEach((f) => { if (/^montserrat$/i.test(f.name)) real(f); });
    if (pid === 'celadna' && !fam.some((f) => f.real)) fam.unshift(real({ id: 'fm', name: 'Montserrat' }));
    return (S.fnt[pid] = { fam, text: FN_SAMPLES[0][1], size: 28, drag: false, up: [], flash: null });
  }
  const famStack = (f) => `'${f.css || f.name}', ${/serif|aleo|chaparral|amasis/i.test(f.name) ? 'Georgia, serif' : 'var(--font)'}`;
  const fntPid = () => (S.route.startsWith('projekt-') && S.route.endsWith('/fonty') ? S.route.slice(8, -6) : null);
  function renderFonts(pid) {
    const p = byId(pid), v = $('#view'); if (!p) { renderDetail(pid); return; }
    const F = fntOf(pid), cuts = F.fam.reduce((a, f) => a + f.cuts.length, 0);
    v.innerHTML = `<a href="#projekt-${esc(p.id)}" class="back"><span class="i i-arrow-left s16" aria-hidden="true"></span>${esc(p.name)}</a>
      <div class="head"><div style="display:flex;flex-direction:column;gap:8px;min-width:0"><span class="lbl">Projekt · ${esc(p.name)}</span><h1>Fonty</h1><span class="cap gal-lead">Písma značky. Šablony nabízejí jen řezy nahrané tady${viewAs() === 'user' ? ' (specifikuje je grafik)' : ''}${F.fam.length ? ` — teď ${F.fam.length === 1 ? '1 rodina' : F.fam.length + (F.fam.length <= 4 ? ' rodiny' : ' rodin')}, ${cutWord(cuts)}` : ''}.</span></div></div>
      <input type="file" id="fnFile" accept=".ttf,.otf,.woff,.woff2,font/ttf,font/otf,font/woff,font/woff2" multiple hidden>
      <div class="gal-nav fn-nav" role="toolbar" aria-label="Fonty">
        <button type="button" class="btn sm" data-fn="upload"><span class="i i-upload s16" aria-hidden="true"></span>Nahrát písma</button>
        <label class="inp fn-sample" for="fnText" title="Ukázkový text pro všechny náhledy"><span class="i i-font s16" aria-hidden="true"></span><input type="text" id="fnText" value="${esc(F.text)}" placeholder="Napište ukázkový text" autocomplete="off" aria-label="Ukázkový text"></label>
        <label class="fn-size" for="fnSize"><span class="cap">Velikost</span><input type="range" id="fnSize" min="14" max="72" step="2" value="${F.size}" aria-valuetext="${F.size} px"><b id="fnSizeV">${F.size} px</b></label>
      </div>
      <label class="gal-drop${F.drag ? ' on' : ''}" for="fnFile" id="fnDrop"><span class="i i-font" aria-hidden="true"></span><span><b>Přetáhněte soubory písem kamkoli na stránku</b> nebo <u>vyberte z počítače</u></span><span class="cap">TTF, OTF, WOFF, WOFF2 · víc souborů najednou · max. 10 MB na soubor · řezy se zařadí do rodiny podle názvu uvnitř souboru</span></label>
      ${F.up.length ? `<div class="fn-ups">${F.up.map((u) => `<div class="fn-up"><span class="i i-font s16" aria-hidden="true"></span><b>${esc(u.name)}</b><span class="gal-prog"><i style="width:${u.p}%"></i></span></div>`).join('')}</div>` : ''}
      ${F.fam.length ? `<div class="fn-grid">${F.fam.map((f) => fnFamily(f, F)).join('')}</div>`
        : `<div class="empty"><span class="ico-c"><span class="i i-font" aria-hidden="true"></span></span><h2 style="font-size:17px;font-weight:600">Projekt zatím nemá žádná písma</h2><p class="cap" style="max-width:380px">${viewAs() === 'user' ? 'Písma značky specifikuje grafik.' : 'Bez písem se šablony vysází náhradním písmem a projekt má na přehledu vykřičník. Nahrajte soubory písem značky.'}</p><button type="button" class="btn sm" data-fn="upload"><span class="i i-upload s16" aria-hidden="true"></span>Nahrát písma</button></div>`}`;
    const t = $('#fnText'); t.addEventListener('input', (e) => { F.text = e.target.value; fnApply(F); $$('[data-fnsample]').forEach((b) => { const on = b.dataset.fnsample === F.text; b.classList.toggle('on', on); b.setAttribute('aria-pressed', on); }); });
    const s = $('#fnSize'); s.addEventListener('input', (e) => { F.size = Number(e.target.value); $('#fnSizeV').textContent = F.size + ' px'; e.target.setAttribute('aria-valuetext', F.size + ' px'); fnApply(F); });
    $('#fnFile').addEventListener('change', (e) => { fnUpload(pid, [...e.target.files]); e.target.value = ''; });
    if (F.flash) { const el = $(`[data-fncut="${F.flash}"]`); if (el) { el.classList.add('flash'); el.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); } F.flash = null; }
  }
  const $$ = (q) => [...document.querySelectorAll(q)];
  function fnApply(F) { $$('.fn-prev').forEach((el) => { el.textContent = F.text || 'Ukázkový text'; el.style.fontSize = F.size + 'px'; }); }
  function fnFamily(f, F) {
    const cuts = f.cuts.slice().sort((a, b) => a.w - b.w || a.it - b.it), used = cuts.reduce((a, c) => a + c.used, 0);
    const has = (w, it) => cuts.find((c) => c.w === w && c.it === it);
    return `<section class="fn-fam" aria-labelledby="fnh-${f.id}">
      <div class="fn-fam-h"><div class="fn-fam-t"><h2 id="fnh-${f.id}" style="font-family:${esc(famStack(f))}">${esc(f.name)}</h2><span class="cap">${cutWord(cuts.length)} · ${used ? `použito v ${tplUse(used)}` : 'zatím nepoužito v šablonách'}</span></div>
        <button type="button" class="btn icon xs ghost" data-fnfm="${f.id}" aria-label="Možnosti rodiny ${esc(f.name)}" aria-haspopup="menu"><span class="i i-more s16" aria-hidden="true"></span></button></div>
      <ul class="fn-cuts">${cuts.map((c) => `<li class="fn-cut" data-fncut="${c.id}">
        <div class="fn-cut-b"><p class="fn-prev" style="font-family:${esc(famStack(f))};font-weight:${c.w};font-style:${c.it ? 'italic' : 'normal'};font-size:${F.size}px">${esc(F.text || 'Ukázkový text')}</p>
          <div class="fn-meta"><b>${esc(f.name)} ${cutName(c)}</b><span class="fn-tag">${c.w}</span>${c.it ? '<span class="fn-tag">Italic</span>' : ''}<span class="fn-tag">${c.fmt}</span><span class="fn-use${c.used ? '' : ' none'}">${c.used ? `<span class="i i-template" aria-hidden="true"></span>${tplUse(c.used)}` : 'nepoužito'}</span></div></div>
        <div class="fn-cut-a"><button type="button" class="btn icon xs ghost fn-del" data-fncm="${c.id}" title="Smazat řez" aria-label="Smazat řez ${esc(f.name)} ${cutName(c)}"><span class="i i-trash s16" aria-hidden="true"></span></button></div></li>`).join('')}</ul>
    </section>`;
  }
  const fnFind = (F, cid) => { for (const f of F.fam) { const c = f.cuts.find((x) => x.id === cid); if (c) return { f, c }; } return {}; };
  /* uploads — family + weight + italic from the file name (in the app: from the name table inside the font) */
  function fnParse(file) {
    const base = file.name.replace(/\.[^.]+$/, ''), parts = base.split(/[-_ ]+/), last = parts.length > 1 ? parts.pop() : 'Regular';
    const lw = last.toLowerCase(), it = /italic|oblique/.test(lw), key = lw.replace(/italic|oblique/g, '') || (it ? 'regular' : lw);
    const w = FN_ALIAS[key] || (/^\d{3}$/.test(key) ? Number(key) : 400);
    const ext = (file.name.split('.').pop() || '').toUpperCase();
    return { family: parts.join(' ').replace(/([a-z])([A-Z])/g, '$1 $2') || base, w, it, fmt: ext === 'WOFF2' ? 'WOFF2' : ext };
  }
  function fnUpload(pid, files) {
    const F = fntOf(pid), ok = [], bad = [];
    files.forEach((f) => { const ext = (f.name.split('.').pop() || '').toLowerCase(); if (!['ttf', 'otf', 'woff', 'woff2'].includes(ext)) bad.push(f.name + ': není soubor písma'); else if (f.size > GAL_MAX) bad.push(f.name + ': víc než 10 MB'); else ok.push(f); });
    if (bad.length) toast(bad.length === 1 ? `Nenahráno — ${bad[0]}` : `${bad.length} soubory nenahrány (formát nebo víc než 10 MB)`, true);
    if (!ok.length) return;
    const ups = ok.map((f) => ({ name: f.name, p: 10, f })); F.up.push(...ups); renderFonts(pid);
    let step = 0; const tick = setInterval(() => { step++; ups.forEach((u) => { u.p = Math.min(100, 10 + step * 30); }); if (fntPid() === pid) renderFonts(pid); if (step < 3) return; clearInterval(tick);
      Promise.all(ups.map(async (u) => { const m = fnParse(u.f), css = 'wbup-' + m.family.replace(/\s+/g, '-');
        try { const face = new FontFace(css, await u.f.arrayBuffer(), { weight: String(m.w), style: m.it ? 'italic' : 'normal' }); await face.load(); document.fonts.add(face); } catch (e) { /* preview falls back */ }
        return { ...m, css, size: u.f.size }; }))
      .then((news) => { F.up = F.up.filter((u) => !ups.includes(u)); let added = 0, last = null;
        news.forEach((m) => { let f = F.fam.find((x) => x.name.toLowerCase() === m.family.toLowerCase()); if (!f) { f = { id: 'f' + Date.now().toString(36) + Math.random().toString(36).slice(2, 4), name: m.family, css: m.css, cuts: [] }; F.fam.push(f); } else if (!f.css) f.css = m.css;
          const ex = f.cuts.find((c) => c.w === m.w && c.it === m.it); if (ex) { Object.assign(ex, { fmt: m.fmt, size: m.size }); last = ex.id; } else { const c = { id: 'u' + Date.now().toString(36) + Math.random().toString(36).slice(2, 5), w: m.w, it: m.it, fmt: m.fmt, size: m.size, used: 0 }; f.cuts.push(c); last = c.id; } added++; });
        const p = byId(pid); if (p) p.fonts = F.fam.map((f) => f.name); F.flash = last;
        if (fntPid() === pid) renderFonts(pid); toast(added === 1 ? 'Řez nahrán' : `Nahráno: ${cutWord(added)}`); });
    }, 160);
  }
  /* events */
  $('#view').addEventListener('click', (e) => {
    const pid = fntPid(); if (!pid) return; const F = fntOf(pid);
    if (e.target.closest('[data-fn="upload"]')) { $('#fnFile').click(); return; }
    const sm = e.target.closest('[data-fnsample]'); if (sm) { F.text = sm.dataset.fnsample; $('#fnText').value = F.text; fnApply(F); $$('[data-fnsample]').forEach((b) => { const on = b === sm; b.classList.toggle('on', on); b.setAttribute('aria-pressed', on); }); return; }
    const cm = e.target.closest('[data-fncm]');
    if (cm) { const { f, c } = fnFind(F, cm.dataset.fncm);
      galConfirm(`Smazat řez ${f.name} ${cutName(c)}?`, c.used ? `Řez je použitý v ${tplUse(c.used).replace('šablona', 'šabloně').replace(/šablony|šablon$/, 'šablonách')}. Tyto šablony ho pak nebudou mít k dispozici a text v nich se vysází náhradním řezem.` : 'Řez zatím není použitý v žádné šabloně.', 'Smazat řez', () => { f.cuts = f.cuts.filter((x) => x.id !== c.id); if (!f.cuts.length) F.fam = F.fam.filter((x) => x !== f); const p = byId(pid); if (p) p.fonts = F.fam.map((x) => x.name); renderFonts(pid); toast('Řez smazán'); });
      return; }
    const fm = e.target.closest('[data-fnfm]');
    if (fm) { const f = F.fam.find((x) => x.id === fm.dataset.fnfm), used = f.cuts.reduce((a, c) => a + c.used, 0); const menu = galMenu(fm, [{ a: 'add', i: 'i-upload', t: 'Nahrát další řezy' }, '-', { a: 'del', i: 'i-trash', t: 'Smazat rodinu', danger: true }]);
      menu.onclick = (ev) => { const b = ev.target.closest('[data-galact]'); if (!b) return; galCloseMenu(); const k = b.dataset.galact;
        if (k === 'add') $('#fnFile').click();
        else galConfirm(`Smazat rodinu ${f.name}?`, `Smaže se ${cutWord(f.cuts.length)}.${used ? ` Řezy jsou použité celkem v ${used} šablonách — text v nich se vysází náhradním písmem.` : ''}`, 'Smazat rodinu', () => { F.fam = F.fam.filter((x) => x !== f); const p = byId(pid); if (p) p.fonts = F.fam.map((x) => x.name); renderFonts(pid); toast('Rodina smazána'); }); };
    }
  });
  /* drag files anywhere on the page */
  let fnDepth = 0;
  document.addEventListener('dragenter', (e) => { const pid = fntPid(); if (!pid || !isFiles(e) || viewAs() === 'user') return; fnDepth++; fntOf(pid).drag = true; document.body.classList.add('gal-dragging', 'fn-dragging'); $('#fnDrop')?.classList.add('on'); });
  document.addEventListener('dragleave', (e) => { const pid = fntPid(); if (!pid || !isFiles(e)) return; if (--fnDepth <= 0) { fnDepth = 0; fntOf(pid).drag = false; document.body.classList.remove('gal-dragging', 'fn-dragging'); $('#fnDrop')?.classList.remove('on'); } });
  document.addEventListener('dragover', (e) => { if (fntPid() && isFiles(e)) e.preventDefault(); });
  document.addEventListener('drop', (e) => { const pid = fntPid(); if (!pid) return; e.preventDefault(); fnDepth = 0; fntOf(pid).drag = false; document.body.classList.remove('gal-dragging', 'fn-dragging'); if (isFiles(e) && viewAs() !== 'user') fnUpload(pid, [...e.dataTransfer.files]); });
  /* ---------- Paleta barev projektu (#projekt-<id>/barvy) ----------
     One palette, two groups: colours found in the logo (Z loga) and colours added by hand (Vlastní).
     Every colour: swatch (click = copy HEX), HEX, RGB (computed), role, CMYK (with "Dopočítat z HEX"), Pantone, RAL.
     Changes save on their own (toast "Uloženo"); the dashboard swatches and the completeness warning read projects.colors.
     Data in the app: projects.palette = [{ hex, source: logo|own, role, cmyk: [c,m,y,k] | null, pantone, ral }]. */
  const PAL_ROLES = [['primary', 'Primární'], ['secondary', 'Sekundární'], ['accent', 'Doplňková'], ['text', 'Text'], ['bg', 'Pozadí'], ['', 'Nepřiřazeno']];
  S.pal = {};
  const hexOk = (h) => /^#?[0-9a-f]{6}$/i.test(h.trim());
  const hexNorm = (h) => '#' + h.trim().replace('#', '').toUpperCase();
  const hexRgb = (h) => { const n = parseInt(h.slice(1), 16); return [n >> 16 & 255, n >> 8 & 255, n & 255]; };
  const rgbCmyk = ([r, g, b]) => { const R = r / 255, G = g / 255, B = b / 255, k = 1 - Math.max(R, G, B); if (k >= 1) return [0, 0, 0, 100]; return [(1 - R - k) / (1 - k), (1 - G - k) / (1 - k), (1 - B - k) / (1 - k), k].map((x) => Math.round(x * 100)); };
  const palLum = (h) => { const [r, g, b] = hexRgb(h); return (0.299 * r + 0.587 * g + 0.114 * b) / 255; };
  function palOf(pid) {
    if (S.pal[pid]) return S.pal[pid];
    const p = byId(pid) || {}, r = seeded('pal' + pid), cols = (p.colors || []).filter(hexOk).map(hexNorm);
    const nLogo = Math.max(1, Math.ceil(cols.length * .6));
    const list = cols.map((hex, i) => ({ id: 'c' + i, hex, src: i < nLogo ? 'logo' : 'own', role: i === 0 ? 'primary' : i === 1 ? 'secondary' : i === 2 && r() < .6 ? 'accent' : r() < .3 ? 'text' : '',
      cmyk: r() < .65 ? rgbCmyk(hexRgb(hex)) : null, pantone: r() < .35 ? ['534 C', '7741 C', '2955 C', '376 C', '7546 C', 'Cool Gray 9 C'][Math.floor(r() * 6)] : '', ral: r() < .2 ? ['RAL 5003', 'RAL 6018', 'RAL 9016', 'RAL 3020'][Math.floor(r() * 4)] : '' }));
    return (S.pal[pid] = { list, flash: null });
  }
  function palSave(pid, msg) {
    const P = palOf(pid), p = byId(pid); if (!p) return;
    const colors = P.list.map((c) => c.hex); p.colors = colors;
    if (db) guard(db.doc('projects/' + pid).update({ colors }));
    toast(msg || 'Uloženo');
  }
  const palPid = () => (S.route.startsWith('projekt-') && S.route.endsWith('/barvy') ? S.route.slice(8, -6) : null);
  function renderPalette(pid) {
    const p = byId(pid), v = $('#view'); if (!p) { renderDetail(pid); return; }
    const P = palOf(pid), logo = P.list.filter((c) => c.src === 'logo'), own = P.list.filter((c) => c.src === 'own');
    const noCmyk = P.list.filter((c) => !c.cmyk).length, noRole = P.list.filter((c) => !c.role).length;
    const miss = [['CMYK', P.list.filter((c) => !c.cmyk).length], ['Pantone', P.list.filter((c) => !c.pantone).length], ['RAL', P.list.filter((c) => !c.ral).length], ['role', noRole]].filter((x) => x[1]);
    const missN = miss.reduce((a, x) => a + x[1], 0), missTxt = miss.map(([k, n]) => `${k} u ${n} ${n === 1 ? 'barvy' : 'barev'}`).join(', ');
    v.innerHTML = `<a href="#projekt-${esc(p.id)}" class="back"><span class="i i-arrow-left s16" aria-hidden="true"></span>${esc(p.name)}</a>
      <div class="head"><div style="display:flex;flex-direction:column;gap:8px;min-width:0"><span class="lbl">Projekt · ${esc(p.name)}</span><h1>Paleta barev</h1><span class="cap gal-lead">Barvy značky pro šablony a tisk.${viewAs() === 'user' ? ' Specifikuje je grafik.' : ' Změny se ukládají samy.'}</span></div></div>
      <div class="gal-nav pal-nav" role="toolbar" aria-label="Paleta barev">
        <button type="button" class="btn sm" data-pal="add"><span class="i i-plus s16" aria-hidden="true"></span>Přidat barvu</button>
        <span class="gal-nav-sp" aria-hidden="true"></span>
        ${P.list.length ? `<div class="pal-strip" aria-label="Náhled palety">${P.list.map((c) => `<span style="background:${c.hex};color:${palLum(c.hex) < .55 ? '#fff' : '#111'}" title="${c.hex}${c.role ? ' · ' + PAL_ROLES.find((x) => x[0] === c.role)[1] : ''}"><b>${c.hex}</b></span>`).join('')}</div>` : ''}
      </div>
      ${viewAs() === 'user' && missN ? `<div class="alert c-warning" role="status"><span class="i i-alert" aria-hidden="true"></span><div style="flex:1"><div class="alert-t">Některé hodnoty u barev nejsou specifikované</div><div class="alert-d">Chybí: ${missTxt}. Požádejte grafika o doplnění, aby barvy seděly i v tisku.</div></div></div>` : ''}
      ${noCmyk || noRole ? `<div class="alert c-info pal-note"><span class="i i-info" aria-hidden="true"></span><span class="alert-d" style="flex:1;margin:0">${[noCmyk ? `${noCmyk} ${noCmyk === 1 ? 'barvě chybí' : 'barvám chybí'} CMYK pro tisk` : '', noRole ? `${noRole} ${noRole === 1 ? 'barva nemá' : 'barvy nemají'} roli` : ''].filter(Boolean).join(' · ')}. Bez role šablona neví, kam barvu použít.</span></div>` : ''}
      ${P.list.length ? `${palGroup('Detekováno z logotypu', '', logo, 'logo')}${palGroup('Přidané barvy', '', own, 'own')}`
        : `<div class="empty"><span class="ico-c"><span class="i i-palette" aria-hidden="true"></span></span><h2 style="font-size:17px;font-weight:600">Paleta je prázdná</h2><p class="cap" style="max-width:380px">Nahrajte logo projektu — barvy z něj se doplní samy — nebo přidejte barvu ručně.</p><button type="button" class="btn sm" data-pal="add"><span class="i i-plus s16" aria-hidden="true"></span>Přidat barvu</button></div>`}`;
    if (viewAs() === 'user') v.querySelectorAll('[data-palf]').forEach((el) => { el.disabled = true; if (el.tagName === 'INPUT') el.placeholder = ''; if (!el.value) el.closest('.inp')?.classList.add('ro-miss'); });
    if (P.flash) { const el = $(`[data-palc="${P.flash}"]`); if (el) { el.classList.add('flash'); el.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); el.querySelector('[data-palf="hex"]')?.focus(); } P.flash = null; }
  }
  function palGroup(title, cap, list, src) {
    if (!list.length && src === 'logo') return '';
    return `<section class="pal-group" aria-label="${esc(title)}"><div class="pal-gh"><h2>${esc(title)} <span class="pd-n">${list.length}</span></h2>${cap ? `<span class="cap">${esc(cap)}</span>` : ''}</div>
      ${list.length ? `<div class="pal-grid">${list.map(palCard).join('')}</div>` : '<p class="cap pal-none">Zatím žádné. Přidejte barvu tlačítkem Přidat barvu.</p>'}</section>`;
  }
  function palCard(c) {
    const rgb = hexRgb(c.hex), dark = palLum(c.hex) < .55, k = c.cmyk || ['', '', '', ''];
    return `<article class="pal-card" data-palc="${c.id}">
      <button type="button" class="pal-sw" style="background:${c.hex};color:${dark ? '#fff' : '#111'}" data-palcopy="${c.id}" title="Kopírovat ${c.hex}" aria-label="Kopírovat HEX ${c.hex}"><span class="i i-copy s16" aria-hidden="true"></span><span class="pal-src">${c.src === 'logo' ? 'Z logotypu' : 'Přidaná'}</span></button>
      <div class="pal-body">
        <div class="pal-row1">
          <label class="pal-f hex"><span>HEX</span><span class="inp sm"><input type="text" value="${c.hex}" maxlength="7" spellcheck="false" autocomplete="off" data-palf="hex" aria-label="HEX"></span></label>
          <label class="pal-f role"><span>Role</span><span class="inp sm sel"><select data-palf="role" aria-label="Role">${PAL_ROLES.map(([v, t]) => `<option value="${v}"${c.role === v ? ' selected' : ''}>${t}</option>`).join('')}</select><span class="i i-chev-down s14" aria-hidden="true"></span></span></label>
          <button type="button" class="btn icon xs ghost" data-palm="${c.id}" aria-label="Možnosti barvy ${c.hex}" aria-haspopup="menu"><span class="i i-more s16" aria-hidden="true"></span></button>
        </div>
        <span class="cap pal-rgb">RGB ${rgb.join(', ')}</span>
        <div class="pal-cmyk${c.cmyk ? '' : ' miss'}" role="group" aria-label="CMYK">${['C', 'M', 'Y', 'K'].map((l, i) => `<label class="pal-f"><span>${l}</span><span class="inp sm"><input type="number" min="0" max="100" inputmode="numeric" value="${k[i]}" data-palf="cmyk${i}" aria-label="${l}"></span></label>`).join('')}
          ${c.cmyk ? '' : `<button type="button" class="btn sm ghost pal-calc" data-palcalc="${c.id}" title="Převod z HEX je jen orientační — pro tisk ověřte">Dopočítat z HEX</button>`}</div>
        <div class="pal-row3"><label class="pal-f"><span>Pantone</span><span class="inp sm"><input type="text" value="${esc(c.pantone)}" placeholder="např. 534 C" autocomplete="off" data-palf="pantone"></span></label><label class="pal-f"><span>RAL</span><span class="inp sm"><input type="text" value="${esc(c.ral)}" placeholder="např. RAL 5003" autocomplete="off" data-palf="ral"></span></label></div>
      </div>
    </article>`;
  }
  const palFind = (pid, el) => { const card = el.closest('[data-palc]'); return card && palOf(pid).list.find((x) => x.id === card.dataset.palc); };
  $('#view').addEventListener('change', (e) => {
    const pid = palPid(); if (!pid) return; const f = e.target.closest('[data-palf]'); if (!f) return; const c = palFind(pid, f); if (!c) return;
    const k = f.dataset.palf, val = f.value.trim();
    if (k === 'hex') { if (!hexOk(val)) { f.closest('.inp').classList.add('err'); toast('Zadejte HEX ve tvaru #1A2B3C', true); return; } c.hex = hexNorm(val); palSave(pid); renderPalette(pid); return; }
    if (k === 'role') { c.role = val; palSave(pid, val ? `Role: ${PAL_ROLES.find((x) => x[0] === val)[1]}` : 'Role odebrána'); renderPalette(pid); return; }
    if (k.startsWith('cmyk')) { const i = Number(k.slice(4)); const n = Math.max(0, Math.min(100, Math.round(Number(val) || 0))); f.value = val === '' ? '' : n; const cur = c.cmyk ? c.cmyk.slice() : [null, null, null, null]; cur[i] = val === '' ? null : n; c.cmyk = cur.every((x) => x == null) ? null : cur.map((x) => x ?? 0); palSave(pid); if (cur.filter((x) => x != null).length === 1 || !c.cmyk) renderPalette(pid); return; }
    c[k] = val; palSave(pid);
  });
  $('#view').addEventListener('input', (e) => { const f = e.target.closest('[data-palf="hex"]'); if (!f || !palPid()) return; f.closest('.inp').classList.remove('err'); if (hexOk(f.value)) { const sw = f.closest('.pal-card').querySelector('.pal-sw'); sw.style.background = hexNorm(f.value); } });
  $('#view').addEventListener('keydown', (e) => { if (palPid() && e.key === 'Enter' && e.target.matches('[data-palf]')) { e.preventDefault(); e.target.blur(); } });
  $('#view').addEventListener('click', (e) => {
    const pid = palPid(); if (!pid) return; const P = palOf(pid);
    if (e.target.closest('[data-pal="add"]')) { const used = new Set(P.list.map((c) => c.hex)); const hex = ['#1F3B5F', '#E8B04B', '#F2EFE9', '#2D2D2D', '#7A5CC4'].find((h) => !used.has(h)) || '#808080'; const c = { id: 'n' + Date.now().toString(36), hex, src: 'own', role: '', cmyk: null, pantone: '', ral: '' }; P.list.push(c); P.flash = c.id; palSave(pid, 'Barva přidána — upravte HEX'); renderPalette(pid); return; }
    const cp = e.target.closest('[data-palcopy]'); if (cp) { const c = P.list.find((x) => x.id === cp.dataset.palcopy); (navigator.clipboard ? navigator.clipboard.writeText(c.hex) : Promise.reject()).then(() => toast(`${c.hex} zkopírováno`), () => toast(c.hex)); return; }
    const cc = e.target.closest('[data-palcalc]'); if (cc) { const c = P.list.find((x) => x.id === cc.dataset.palcalc); c.cmyk = rgbCmyk(hexRgb(c.hex)); palSave(pid, 'CMYK dopočítáno — pro tisk ho ověřte'); renderPalette(pid); return; }
    const m = e.target.closest('[data-palm]');
    if (m) { const c = P.list.find((x) => x.id === m.dataset.palm), i = P.list.indexOf(c);
      const menu = galMenu(m, [{ a: 'copy', i: 'i-copy', t: 'Kopírovat HEX' }, { a: 'up', i: 'i-arrow-up', t: 'Posunout výš' }, { a: 'down', i: 'i-arrow-down', t: 'Posunout níž' }, '-', { a: 'del', i: 'i-trash', t: 'Smazat barvu', danger: true }]);
      menu.onclick = (ev) => { const b = ev.target.closest('[data-galact]'); if (!b) return; galCloseMenu(); const k = b.dataset.galact;
        if (k === 'copy') $(`[data-palcopy="${c.id}"]`).click();
        else if (k === 'up' || k === 'down') { const same = P.list.filter((x) => x.src === c.src), j = same.indexOf(c), t = same[j + (k === 'up' ? -1 : 1)]; if (!t) return; const a = P.list.indexOf(c), z = P.list.indexOf(t); [P.list[a], P.list[z]] = [P.list[z], P.list[a]]; palSave(pid, 'Pořadí uloženo'); renderPalette(pid); }
        else galConfirm(`Smazat barvu ${c.hex}?`, c.role ? `Barva má roli ${PAL_ROLES.find((x) => x[0] === c.role)[1].toLowerCase()} — šablony, které ji používají, ji pak nenajdou v paletě.` : 'Barva se z palety odebere.', 'Smazat barvu', () => { P.list = P.list.filter((x) => x !== c); palSave(pid, 'Barva smazána'); renderPalette(pid); }); };
    }
  });
  /* ---------- Manuál projektu (#projekt-<id>/manualy) — one online logo manual per project ----------
     Tabs Logotypy / Stránky s mockupy, "Zobrazit manuál" in the head. Fonty and Barvy are project-wide (left menu), so the editor only links to them.
     Logotypy: 5 variant cards — preview, upload (drop or button), Šířka/Výška, Šířka loga v manuálu (%) as a slider (0 = výchozí),
     switch "Zobrazovat variantu na samostatné stránce", and the colours the system detected in the file (read-only dots — the backend
     detects colourfulness and builds the colour versions of the manual by itself; the prototype only parses SVG fills / samples PNG).
     Stránky s mockupy: view switch Mřížka (sortable cards) / Náhled stránek (PDF-reader style: all pages stacked to scroll on the left, small thumbnail rail right that follows the scroll,
     click / arrows ←→ ↑↓ jump to a page, drag thumbnails to reorder; choice remembered per viewer). "…" menu Upravit / Duplikovat / Smazat.
     Page editor (#projekt-<id>/manualy/stranka/<pageId> or /manualy/nova): sticky top box Název stránky + status + Přidat stránku / Uložit (saves and returns to the list), then
     Rozložení stránky (11 layouts) → Obrázky (one drop slot per layout cell with recommended size, per-image Soubor ke stažení) → Soubor pro celou stránku.
     New page: "Přidat stránku" button. Existing page: autosave. Klient: cover + Zobrazit manuál only. */
  const MAN_VARS = [['h', 'Logo horizontální'], ['v', 'Logo vertikální'], ['hs', 'Logo horizontální se sloganem'], ['vs', 'Logo vertikální se sloganem'], ['sym', 'Symbol']];
  // layout = CSS grid areas on a 3 × 2 grid (one letter per image); cell = 800 × 600 px for the recommended size
  const MAN_LAYOUTS = [null, ['abb', 'ccd'], ['abb', 'acc'], ['abc', 'abc'], ['abc', 'ade'], ['abc', 'add'], ['abc', 'def'], ['aaa', 'aaa'], ['abb', 'abb'], ['aab', 'aab'], ['aab', 'aac'], ['abb', 'cbb']];
  const manSlots = (n) => { const L = MAN_LAYOUTS[n], out = []; 'abcdef'.split('').forEach((ch) => { const cells = []; L.forEach((row, y) => row.split('').forEach((c, x) => { if (c === ch) cells.push([x, y]); })); if (cells.length) { const xs = cells.map((c) => c[0]), ys = cells.map((c) => c[1]); out.push({ ch, w: (Math.max(...xs) - Math.min(...xs) + 1) * 800, h: (Math.max(...ys) - Math.min(...ys) + 1) * 600 }); } }); return out; };
  const manAreas = (n) => MAN_LAYOUTS[n].map((r) => `'${r.split('').join(' ')}'`).join(' ');
  const imgCount = (n) => n + ' ' + (n === 1 ? 'obrázek' : n >= 2 && n <= 4 ? 'obrázky' : 'obrázků');
  S.man = {};
  const manLogo = (p, kind) => {
    const c1 = (p.colors && p.colors[0]) || '#7A5CC4', c2 = (p.colors && p.colors[1]) || '#2B2533', name = esc(p.name), sl = 'Brand manuál';
    const sym = (x, y, s) => `<circle cx='${x + s / 2}' cy='${y + s / 2}' r='${s / 2}' fill='${c1}'/><path d='M${x + s * .3} ${y + s * .68} L${x + s * .5} ${y + s * .28} L${x + s * .7} ${y + s * .68}' fill='none' stroke='#fff' stroke-width='${s * .09}' stroke-linecap='round' stroke-linejoin='round'/>`;
    const F = "font-family='Outfit, Arial, sans-serif' font-weight='700' fill='" + c2 + "'", L = p.name.length;
    const svg = kind === 'sym' ? `<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 120 120'>${sym(10, 10, 100)}</svg>`
      : kind === 'h' || kind === 'hs' ? (() => { const tw = Math.round(L * 23); return `<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 ${130 + tw} 120'>${sym(10, 20, 80)}<text x='108' y='${kind === 'hs' ? 68 : 76}' ${F} font-size='40' textLength='${tw}' lengthAdjust='spacingAndGlyphs'>${name}</text>${kind === 'hs' ? `<text x='110' y='96' font-family='Outfit, Arial, sans-serif' font-size='17' fill='${c2}' opacity='.7'>${sl}</text>` : ''}</svg>`; })()
      : (() => { const tw = Math.round(L * 19), W = Math.max(200, tw + 40); return `<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 ${W} ${kind === 'vs' ? 190 : 165}'>${sym(W / 2 - 45, 10, 90)}<text x='${W / 2}' y='145' text-anchor='middle' ${F} font-size='34' textLength='${tw}' lengthAdjust='spacingAndGlyphs'>${name}</text>${kind === 'vs' ? `<text x='${W / 2}' y='176' text-anchor='middle' font-family='Outfit, Arial, sans-serif' font-size='16' fill='${c2}' opacity='.7'>${sl}</text>` : ''}</svg>`; })();
    return 'data:image/svg+xml;utf8,' + encodeURIComponent(svg);
  };
  // prototype mockup picture: brand-coloured ground with a chevron texture and the wordmark
  const manMockImg = (p, i, w, h) => {
    const cs = (p.colors && p.colors.length ? p.colors : ['#17375F', '#C79A5B']), bg = cs[(i + 1) % cs.length], fg = cs[i % cs.length], light = palLum(bg) > .5;
    const ink = light ? '#1B1A20' : '#FFFFFF', y = h * .72;
    const chev = Array.from({ length: Math.ceil(w / 60) + 1 }, (_, k) => `<path d='M${k * 60} ${y} l30 -22 l30 22' fill='none' stroke='${fg}' stroke-width='6' opacity='.45'/>`).join('');
    const svg = `<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 ${w} ${h}'><rect width='${w}' height='${h}' fill='${bg}'/>${i % 3 === 1 ? `<rect x='${w * .18}' y='${h * .2}' width='${w * .64}' height='${h * .5}' rx='14' fill='${light ? '#fff' : 'rgba(255,255,255,.08)'}'/>` : ''}${chev}<circle cx='${w * .5 - Math.min(w, h) * .2}' cy='${h * .42}' r='${Math.min(w, h) * .07}' fill='${fg}'/><text x='${w * .5 - Math.min(w, h) * .1}' y='${h * .42 + Math.min(w, h) * .035}' font-family='Outfit, Arial, sans-serif' font-weight='700' font-size='${Math.min(w, h) * .1}' fill='${ink}'>${esc(p.name)}</text></svg>`;
    return 'data:image/svg+xml;utf8,' + encodeURIComponent(svg);
  };
  const MAN_SEED = [['Práce se sloganem', 7], ['Práce se slogany', 7], ['Spolky', 7], ['Instituce města', 6], ['Využití textur', 9], ['Prezentace', 7], ['Vizitky', 10], ['Razítko', 7], ['Zpravodaj', 7], ['Hlavičkový papír', 8], ['Obálky', 3], ['Billboard', 7], ['Roll-up', 2], ['Web', 1], ['Sociální sítě', 6], ['Polep auta', 5], ['Trička', 4], ['Hrnek', 9], ['Vlajka', 11], ['Merch', 4]];
  function manOf(pid) {
    if (S.man[pid]) return S.man[pid];
    const p = byId(pid) || { name: '', colors: [] }, r = seeded('man' + pid), logos = {};
    MAN_VARS.forEach(([k], j) => { const has = j === 0 || j === 4 || r() < .35; logos[k] = has ? { src: (j === 0 && p.logo) ? p.logo : manLogo(p, k), fmt: 'SVG', w: '', h: '', scale: 0, page: false, cols: (p.colors || []).slice(0, j === 4 ? 1 : 2) } : null; });
    const n = 8 + Math.floor(r() * 13);
    const pages = MAN_SEED.slice(0, n).map(([name, lay], i) => ({ id: 'pg' + i, name, layout: lay, file: i % 5 === 2 ? `${name.toLowerCase().replace(/\s+/g, '-')}-tisk.pdf` : null,
      imgs: manSlots(lay).map((sl, j) => ({ src: manMockImg(p, i + j, sl.w / 4, sl.h / 4), file: i === 6 && j === 0 ? 'vizitky-tiskova-data.zip' : null })) }));
    return (S.man[pid] = { logos, pages, tab: 'logo', draft: null });
  }
  const manPath = () => { if (!S.route.startsWith('projekt-')) return null; const [pid, a, ...sub] = S.route.slice(8).split('/'); return a === 'manualy' ? { pid, sub } : null; };
  const manSave = (msg) => toast(msg || 'Uloženo');
  const manBack = (p) => `<a href="#projekt-${esc(p.id)}/manualy" class="back"><span class="i i-arrow-left s16" aria-hidden="true"></span>Manuál</a>`;
  function renderManuals(pid, sub) {
    const p = byId(pid); if (!p) { renderDetail(pid); return; }
    const M = manOf(pid), ro = viewAs() === 'user';
    sub = sub || [];
    if (!ro && sub[0] === 'nova') { if (!M.draft) M.draft = { id: null, name: '', layout: 0, imgs: [], file: null }; renderManPage(p, M, M.draft); return; }
    if (!ro && sub[0] === 'stranka') { const pg = M.pages.find((x) => x.id === sub[1]); if (pg) { renderManPage(p, M, pg); return; } }
    if (ro) { renderManClient(p, M); return; }
    const done = MAN_VARS.filter(([k]) => M.logos[k]).length;
    $('#view').innerHTML = `<a href="#projekt-${esc(p.id)}" class="back"><span class="i i-arrow-left s16" aria-hidden="true"></span>${esc(p.name)}</a>
      <div class="head"><div style="display:flex;flex-direction:column;gap:8px;min-width:0"><span class="lbl">Projekt · ${esc(p.name)}</span><h1>Manuál</h1><span class="cap gal-lead">Nahrajte logotypy — barevné verze manuálu se z nich vytvoří samy. Změny se ukládají samy.</span></div>
        <div class="head-tools"><button type="button" class="btn sm" data-manview><span class="i i-eye s16" aria-hidden="true"></span>Zobrazit manuál</button></div></div>
      <div class="tabs-u man-tabs" role="tablist" aria-label="Části manuálu">
        <button type="button" role="tab" class="${M.tab === 'logo' ? 'on' : ''}" aria-selected="${M.tab === 'logo'}" data-mantab="logo"><span class="i i-image s16" aria-hidden="true"></span>Logotypy <span class="pd-n">${done}/${MAN_VARS.length}</span></button>
        <button type="button" role="tab" class="${M.tab === 'mock' ? 'on' : ''}" aria-selected="${M.tab === 'mock'}" data-mantab="mock"><span class="i i-book s16" aria-hidden="true"></span>Stránky s mockupy <span class="pd-n">${M.pages.length}</span></button>
      </div>
      ${M.tab === 'logo' ? `${manMissAlert(M)}
      <div class="man-vars">${MAN_VARS.map(([k, t]) => manVar(M, k, t)).join('')}</div>` : manMockList(p, M)}
      <input type="file" id="manFile" accept=".svg,.png,image/svg+xml,image/png" hidden>`;
  }
  // missing logo variants → warning with actions (contact the designer / report), for the designer and the client alike
  const manMissAlert = (M) => { const miss = MAN_VARS.filter(([k]) => !M.logos[k]).map(([, t]) => t); if (!miss.length) return '';
    return `<div class="alert c-warning man-miss"><span class="i i-alert" aria-hidden="true"></span><div style="flex:1;min-width:0"><div class="alert-t">V manuálu chybí ${miss.length === 1 ? '1 varianta' : miss.length + (miss.length < 5 ? ' varianty' : ' variant')} loga</div><div class="alert-d">${miss.join(', ')}. Bez nich nebude manuál kompletní.</div></div><div class="man-miss-a"><button type="button" class="btn sm" data-mancontact><span class="i i-mail s16" aria-hidden="true"></span>Kontaktovat grafika</button><button type="button" class="btn sm tertiary" data-manreport>Nahlásit</button></div></div>`; };
  function renderManClient(p, M) {
    const L = M.logos.h || M.logos.v || M.logos.sym;
    $('#view').innerHTML = `<div class="head"><div style="display:flex;flex-direction:column;gap:8px;min-width:0"><span class="lbl">Projekt · ${esc(p.name)}</span><h1>Manuál</h1><span class="cap gal-lead">Online logo manuál vaší značky. Spravuje ho váš grafik.</span></div></div>
      <article class="man-cl"><div class="man-cover">${L ? `<img src="${L.src}" alt="">` : ''}</div>
        <div class="man-cl-b"><h2>${esc(p.name)} — logo manuál</h2><span class="cap">${MAN_VARS.filter(([k]) => M.logos[k]).length} varianty loga · ${M.pages.length} stránek s mockupy</span>
        <button type="button" class="btn sm" data-manview><span class="i i-eye s16" aria-hidden="true"></span>Zobrazit manuál</button></div></article>`;
  }
  function manVar(M, k, title) {
    const L = M.logos[k];
    if (!L) return `<section class="man-var man-empty" data-manvar="${k}"><div class="man-vh"><h3>${title}</h3><span class="man-st miss">Chybí</span></div>
      <label class="man-drop" data-mandrop="${k}"><span class="i i-upload" aria-hidden="true"></span><b>Přetáhněte SVG nebo PNG</b><span class="cap">nebo <u>vyberte soubor</u> · ideálně SVG, PNG s průhledností</span></label></section>`;
    const sc = Number(L.scale) || 0, cols = L.cols || [];
    return `<section class="man-var" data-manvar="${k}">
      <div class="man-vh"><h3>${title}</h3><span class="man-st">${L.fmt}</span><button type="button" class="btn icon xs ghost" data-manm="${k}" aria-label="Možnosti: ${title}" aria-haspopup="menu"><span class="i i-more s16" aria-hidden="true"></span></button></div>
      <div class="man-prev" data-mandrop="${k}"><img src="${L.src}" alt="${title}" style="${sc ? `width:${sc}%` : ''}"></div>
      <div class="man-cols"><span class="cap">Rozpoznané barvy</span>${cols.length ? cols.map((c) => `<span class="man-col" style="background:${c}" title="${c}"></span>`).join('') + `<span class="cap man-cols-n">${cols.map((c) => c.toUpperCase()).join(' · ')}</span>` : '<span class="cap">Rozpoznávají se…</span>'}</div>
      <div class="man-fields">
        <div class="man-wh"><label class="pal-f"><span>Šířka (px)</span><span class="inp sm"><input type="number" min="0" value="${L.w}" placeholder="auto" data-manf="w"></span></label><label class="pal-f"><span>Výška (px)</span><span class="inp sm"><input type="number" min="0" value="${L.h}" placeholder="auto" data-manf="h"></span></label></div>
        <label class="pal-f man-scale"><span>Šířka loga v manuálu <b>${sc ? sc + ' %' : 'Výchozí'}</b></span><input type="range" min="0" max="100" step="5" value="${sc}" style="--p:${sc}%" data-manf="scale" aria-valuetext="${sc ? sc + ' % rámečku' : 'výchozí velikost'}"></label>
        <label class="man-page"><input type="checkbox" role="switch" class="sr" data-manf="page"${L.page ? ' checked' : ''}><span class="sw${L.page ? ' on' : ''}" aria-hidden="true"></span><span><b>Zobrazovat variantu na samostatné stránce</b><span class="cap">Varianta dostane v manuálu vlastní stránku se všemi svými barevnými variantami a zmizí ze společných stránek.</span></span></label>
      </div>
    </section>`;
  }
  // one page drawn with its layout (used in the list cards)
  const manGrid = (pg, cls) => `<div class="man-lay ${cls || ''}" style="grid-template-areas:${manAreas(pg.layout)}">${manSlots(pg.layout).map((sl, j) => { const im = pg.imgs[j]; return `<span style="grid-area:${sl.ch}">${im && im.src ? `<img src="${im.src}" alt="" loading="lazy">` : ''}</span>`; }).join('')}</div>`;
  const manViewGet = () => { try { return localStorage.getItem('wb-manview') === 'pdf' ? 'pdf' : 'grid'; } catch (e) { return 'grid'; } };
  function manMockList(p, M) {
    const view = M.view || (M.view = manViewGet());
    const att = (pg) => pg.file || pg.imgs.some((x) => x && x.file);
    const bar = `<div class="gal-nav"><a class="btn sm" href="#projekt-${esc(p.id)}/manualy/nova"><span class="i i-plus s16" aria-hidden="true"></span>Přidat stránku</a>
      <span class="cap man-hint"><span class="i i-grip s16" aria-hidden="true"></span>Pořadí změníte přetažením</span>
      <div class="seg soft man-viewsw" role="group" aria-label="Zobrazení stránek"><button type="button" data-manview-set="grid" class="${view === 'grid' ? 'on' : ''}" aria-pressed="${view === 'grid'}"><span class="i i-grid s16" aria-hidden="true"></span>Mřížka</button><button type="button" data-manview-set="pdf" class="${view === 'pdf' ? 'on' : ''}" aria-pressed="${view === 'pdf'}"><span class="i i-book s16" aria-hidden="true"></span>Náhled<span class="man-vs-x"> stránek</span></button></div></div>`;
    if (!M.pages.length) return bar + `<div class="empty"><span class="i i-book" aria-hidden="true"></span><b>Zatím žádné stránky s mockupy</b><span class="cap">Přidejte první stránku — vyberete rozložení a nahrajete obrázky.</span></div>`;
    if (view === 'pdf') {
      if (!M.pages.some((x) => x.id === M.sel)) M.sel = M.pages[0].id;
      return bar + `<div class="man-pdf">
        <div class="man-pdf-list">${M.pages.map((pg, i) => `<section class="man-pdf-main" id="manp-${pg.id}" data-manpv="${pg.id}" aria-label="Stránka ${i + 1}: ${esc(pg.name)}">
          <div class="man-pgh"><span class="man-pgn" aria-hidden="true">${i + 1}</span><h3>${esc(pg.name)}</h3>${att(pg) ? '<span class="i i-paperclip s16 man-att" title="Obsahuje soubory ke stažení" aria-label="Obsahuje soubory ke stažení"></span>' : ''}<a class="btn sm tertiary" href="#projekt-${esc(p.id)}/manualy/stranka/${pg.id}"><span class="i i-edit s16" aria-hidden="true"></span>Upravit</a><button type="button" class="btn icon xs ghost" data-manpm="${pg.id}" aria-label="Možnosti stránky ${esc(pg.name)}" aria-haspopup="menu"><span class="i i-more s16" aria-hidden="true"></span></button></div>
          <div class="man-pdf-page">${manGrid(pg, 'page')}</div>
        </section>`).join('')}</div>
        <aside class="man-pdf-side" aria-label="Stránky"><div class="man-pdf-sh"><b>Stránky</b><span class="pd-n">${M.pages.length}</span></div>
          <ol class="man-thumbs">${M.pages.map((x, j) => `<li class="man-th${x.id === M.sel ? ' on' : ''}" draggable="true" data-manpg="${x.id}"><button type="button" data-mansel="${x.id}"${x.id === M.sel ? ' aria-current="true"' : ''} aria-label="Stránka ${j + 1}: ${esc(x.name)}" title="${esc(x.name)}">${manGrid(x, 'thumb')}</button><span class="man-th-n" aria-hidden="true">${j + 1}</span></li>`).join('')}</ol>
        </aside></div>`;
    }
    return bar + `<ol class="man-pages" aria-label="Stránky s mockupy">${M.pages.map((pg, i) => `<li class="man-pg" draggable="true" data-manpg="${pg.id}">
        <div class="man-pgh"><span class="i i-grip s16 man-grip" aria-hidden="true"></span><span class="man-pgn" aria-hidden="true">${i + 1}</span><h3>${esc(pg.name)}</h3>${att(pg) ? '<span class="i i-paperclip s16 man-att" title="Obsahuje soubory ke stažení" aria-label="Obsahuje soubory ke stažení"></span>' : ''}<button type="button" class="btn icon xs ghost" data-manpm="${pg.id}" aria-label="Možnosti stránky ${esc(pg.name)}" aria-haspopup="menu"><span class="i i-more s16" aria-hidden="true"></span></button></div>
        <a class="man-pgv" href="#projekt-${esc(p.id)}/manualy/stranka/${pg.id}" aria-label="Upravit stránku ${esc(pg.name)}" draggable="false">${manGrid(pg)}</a>
      </li>`).join('')}</ol>`;
  }
  /* ---------- page editor ---------- */
  function renderManPage(p, M, pg) {
    const isNew = !pg.id, slots = pg.layout ? manSlots(pg.layout) : [], filled = slots.filter((_, j) => pg.imgs[j] && pg.imgs[j].src).length;
    const status = !pg.layout ? 'Vyberte rozložení stránky' : !pg.name.trim() ? 'Doplňte název stránky' : filled < slots.length ? `Nahráno ${filled} z ${imgCount(slots.length)}` : 'Všechny obrázky jsou nahrané';
    $('#view').innerHTML = `<a href="#projekt-${esc(p.id)}/manualy" class="back" data-mantab-go="mock"><span class="i i-arrow-left s16" aria-hidden="true"></span>Stránky s mockupy</a>
      <div class="head"><div style="display:flex;flex-direction:column;gap:8px;min-width:0"><span class="lbl">Manuál · ${esc(p.name)}</span><h1>${isNew ? 'Nová stránka' : esc(pg.name || 'Stránka')}</h1><span class="cap gal-lead">${isNew ? 'Pojmenujte stránku, vyberte rozložení a nahrajte obrázky.' : 'Změny se ukládají samy a v online manuálu se projeví hned.'}</span></div></div>
      <section class="man-sec man-top" role="region" aria-label="Název a uložení stránky"><label class="field man-name" for="manPgName"><span>Název stránky</span><span class="inp"><input type="text" id="manPgName" value="${esc(pg.name)}" placeholder="Např. Vizitky a hlavičkový papír" maxlength="60" autocomplete="off"></span></label>
        <div class="man-top-a"><span class="man-bar-s-w"><span class="man-bar-s">${pg.layout && pg.name.trim() && filled === slots.length ? '<span class="i i-check-circle s16" aria-hidden="true"></span>' : ''}${status}</span></span>
        <span class="man-top-b">${isNew ? `<a class="btn sm tertiary" href="#projekt-${esc(p.id)}/manualy" data-mancancel>Zrušit</a><button type="button" class="btn sm" data-manadd${pg.layout && pg.name.trim() ? '' : ' disabled'}><span class="i i-plus s16" aria-hidden="true"></span>Přidat stránku</button>` : `<a class="btn sm" href="#projekt-${esc(p.id)}/manualy" data-mantab-go="mock" data-mansave><span class="i i-check s16" aria-hidden="true"></span>Uložit</a>`}</span></div></section>
      <section class="man-sec" aria-labelledby="manLayT"><div class="man-sech"><h2 id="manLayT">Rozložení stránky</h2><span class="cap">Jak budou obrázky na stránce uspořádané. Rozložení můžete změnit i po nahrání — obrázky zůstanou zachované.</span></div>
        <div class="man-lays" role="radiogroup" aria-labelledby="manLayT">${MAN_LAYOUTS.map((L, n) => n ? `<button type="button" role="radio" class="man-lc${pg.layout === n ? ' on' : ''}" aria-checked="${pg.layout === n}" data-manlay="${n}"><span class="man-lay mini" style="grid-template-areas:${manAreas(n)}">${manSlots(n).map((sl) => `<span style="grid-area:${sl.ch}"></span>`).join('')}</span><span class="cap">${imgCount(manSlots(n).length)}</span></button>` : '').join('')}</div></section>
      <section class="man-sec" aria-labelledby="manImgT"><div class="man-sech"><h2 id="manImgT">Obrázky</h2><span class="cap">Klikněte na pole nebo do něj přetáhněte obrázek. Obrázek v jiném poměru stran se při zobrazení ořízne.</span></div>
        ${pg.layout ? `<div class="man-lay big" style="grid-template-areas:${manAreas(pg.layout)}">${slots.map((sl, j) => manSlot(pg, sl, j)).join('')}</div>` : `<div class="man-noly"><span class="i i-grid" aria-hidden="true"></span><span>Nejdříve vyberte rozložení stránky výše.</span></div>`}</section>
      <section class="man-sec" aria-labelledby="manFileT"><div class="man-sech"><h2 id="manFileT">Soubor ke stažení <span class="pill">Nepovinné</span></h2><span class="cap">Podklad, který si čtenář manuálu stáhne — tiskové PDF, zdrojová data, balíček. U obrázku ho přidáte tlačítkem v jeho poli, pro celou stránku tady.</span></div>
        <div class="man-att-row"><span class="i i-file s16" aria-hidden="true"></span><b>Soubor pro celou stránku</b><span class="cap man-att-n">${pg.file ? esc(pg.file) : 'Bez souboru'}</span>${pg.file ? `<button type="button" class="btn xs ghost" data-manatt="page-del"><span class="i i-x s16" aria-hidden="true"></span>Odebrat</button>` : ''}<button type="button" class="btn sm tertiary" data-manatt="page"><span class="i i-paperclip s16" aria-hidden="true"></span>${pg.file ? 'Nahradit' : 'Vybrat soubor'}</button></div></section>
      <input type="file" id="manImgFile" accept="image/*" hidden><input type="file" id="manAttFile" hidden>`;
  }
  const manSlot = (pg, sl, j) => {
    const im = pg.imgs[j], size = `${sl.w} × ${sl.h} px`;
    if (!im || !im.src) return `<label class="man-slot empty" style="grid-area:${sl.ch}" data-manslot="${j}"><span class="i i-upload" aria-hidden="true"></span><b>Obrázek ${j + 1}</b><span class="cap">Doporučeno ${size}</span></label>`;
    return `<div class="man-slot" style="grid-area:${sl.ch}" data-manslot="${j}"><img src="${im.src}" alt="Obrázek ${j + 1}">
      <div class="man-slot-b"><button type="button" class="man-slot-f${im.file ? ' on' : ''}" data-manatt="img" title="${im.file ? esc(im.file) : 'Soubor ke stažení k tomuto obrázku'}"><span class="i i-paperclip s16" aria-hidden="true"></span><span>${im.file ? esc(im.file) : 'Soubor ke stažení'}</span></button>
      <button type="button" class="btn icon xs man-slot-m" data-manim="${j}" aria-label="Možnosti obrázku ${j + 1}" aria-haspopup="menu"><span class="i i-more s16" aria-hidden="true"></span></button></div></div>`;
  };
  const manCur = () => { const pp = manPath(); if (!pp) return {}; const M = manOf(pp.pid), p = byId(pp.pid); const pg = pp.sub[0] === 'nova' ? M.draft : pp.sub[0] === 'stranka' ? M.pages.find((x) => x.id === pp.sub[1]) : null; return { pp, M, p, pg }; };
  const manRe = () => { const pp = manPath(); if (pp) renderManuals(pp.pid, pp.sub); };
  const manAuto = (pg, msg) => { if (pg && pg.id) manSave(msg); };
  // colours detected in an uploaded logo (prototype only: SVG fills, PNG sampling — the real detection runs on the server)
  function manDetect(file, src, done) {
    const skip = (h) => /^#(fff(fff)?|000(000)?)$/i.test(h);
    if (/svg/i.test(file.type) || /\.svg$/i.test(file.name)) { file.text().then((t) => { const set = [...new Set((t.match(/#[0-9a-f]{6}\b|#[0-9a-f]{3}\b/gi) || []).map((h) => h.length === 4 ? '#' + h.slice(1).split('').map((c) => c + c).join('') : h).map((h) => h.toUpperCase()))].filter((h) => !skip(h)); done(set.slice(0, 4)); }).catch(() => done([])); return; }
    const im = new Image(); im.onload = () => { try { const c = document.createElement('canvas'), x = c.getContext('2d'); c.width = c.height = 32; x.drawImage(im, 0, 0, 32, 32); const d = x.getImageData(0, 0, 32, 32).data, cnt = {};
      for (let i = 0; i < d.length; i += 4) { if (d[i + 3] < 128) continue; const q = [d[i], d[i + 1], d[i + 2]].map((v) => Math.round(v / 32) * 32 > 255 ? 255 : Math.round(v / 32) * 32); const h = '#' + q.map((v) => v.toString(16).padStart(2, '0')).join('').toUpperCase(); if (!skip(h) && !/^#(F0F0F0|E0E0E0|202020)$/.test(h)) cnt[h] = (cnt[h] || 0) + 1; }
      done(Object.entries(cnt).sort((a, b) => b[1] - a[1]).slice(0, 3).map((e) => e[0])); } catch (e) { done([]); } }; im.onerror = () => done([]); im.src = src;
  }
  function manUpload(k, file) {
    const { M } = manCur(); if (!M) return;
    const ok = /svg|png/i.test(file.type) || /\.(svg|png)$/i.test(file.name); if (!ok) { toast('Nahrajte SVG nebo PNG', true); return; }
    if (file.size > GAL_MAX) { toast('Soubor je větší než 10 MB', true); return; }
    const src = URL.createObjectURL(file), im = new Image();
    im.onload = im.onerror = () => { const prev = M.logos[k]; M.logos[k] = { src, fmt: /svg/i.test(file.type) || /\.svg$/i.test(file.name) ? 'SVG' : 'PNG', w: prev?.w || (im.naturalWidth || ''), h: prev?.h || (im.naturalHeight || ''), scale: prev?.scale || 0, page: prev?.page || false, cols: [] };
      manRe(); manSave(prev ? 'Logo nahrazeno — barvy se rozpoznávají' : 'Logo nahráno — barvy se rozpoznávají');
      manDetect(file, src, (cols) => { if (M.logos[k] && M.logos[k].src === src) { M.logos[k].cols = cols; if (manPath() && !manPath().sub.length) manRe(); } }); };
    im.src = src;
  }
  function manImg(j, file) {
    const { pg } = manCur(); if (!pg) return;
    if (!/^image\//.test(file.type)) { toast('Nahrajte obrázek (JPG, PNG, WebP)', true); return; }
    if (file.size > GAL_MAX) { toast('Obrázek je větší než 10 MB', true); return; }
    const prev = pg.imgs[j]; pg.imgs[j] = { src: URL.createObjectURL(file), file: prev ? prev.file : null }; manRe(); manAuto(pg, prev && prev.src ? 'Obrázek nahrazen' : 'Obrázek nahrán');
  }
  let manTarget = null, manImgT = null, manAttT = null, manDragId = null;
  const manPageMenu = (btn, M, pg) => {
    const i = M.pages.indexOf(pg), pp = manPath();
    const menu = galMenu(btn, [{ a: 'edit', i: 'i-edit', t: 'Upravit stránku' }, { a: 'dup', i: 'i-copy', t: 'Duplikovat' }, '-', { a: 'del', i: 'i-trash', t: 'Smazat stránku', danger: true }]);
    menu.onclick = (ev) => { const b = ev.target.closest('[data-galact]'); if (!b) return; galCloseMenu(); const a = b.dataset.galact;
      if (a === 'edit') location.hash = `projekt-${pp.pid}/manualy/stranka/${pg.id}`;
      else if (a === 'dup') { const c = { ...pg, id: 'pg' + Date.now().toString(36), name: pg.name + ' (kopie)', imgs: pg.imgs.map((x) => x && { ...x }) }; M.pages.splice(i + 1, 0, c); manRe(); manSave('Stránka zduplikována'); }
      else galConfirm(`Smazat stránku „${pg.name}“?`, 'Stránka i její obrázky zmizí z online manuálu.', 'Smazat stránku', () => { M.pages.splice(M.pages.indexOf(pg), 1); manRe(); manSave('Stránka smazána'); }); };
  };
  $('#view').addEventListener('click', (e) => {
    const pp = manPath(); if (!pp) return; const M = manOf(pp.pid);
    if (e.target.closest('[data-manview]')) { e.preventDefault(); toast('Online manuál se otevře v nové záložce'); return; }
    if (e.target.closest('[data-mancontact]')) { toast('Grafikovi jsme poslali žádost o doplnění chybějících variant'); return; }
    if (e.target.closest('[data-manreport]')) { toast('Díky — problém s manuálem jsme nahlásili'); return; }
    const tb = e.target.closest('[data-mantab]'); if (tb) { M.tab = tb.dataset.mantab; manRe(); return; }
    const tg = e.target.closest('[data-mantab-go]'); if (tg) { M.tab = tg.dataset.mantabGo; }
    if (e.target.closest('[data-mansave]')) { const n = $('#manPgName'), { pg } = manCur(); if (pg && n && n.value.trim()) pg.name = n.value.trim(); toast('Stránka uložena'); }
    if (e.target.closest('[data-mancancel]')) { M.draft = null; M.tab = 'mock'; return; }
    const dr = e.target.closest('label.man-drop'); if (dr) { e.preventDefault(); manTarget = dr.dataset.mandrop; $('#manFile').click(); return; }
    const mm = e.target.closest('[data-manm]');
    if (mm) { const k = mm.dataset.manm, title = MAN_VARS.find((x) => x[0] === k)[1];
      const menu = galMenu(mm, [{ a: 'rep', i: 'i-upload', t: 'Nahradit soubor' }, '-', { a: 'del', i: 'i-trash', t: 'Odebrat logo', danger: true }]);
      menu.onclick = (ev) => { const b = ev.target.closest('[data-galact]'); if (!b) return; galCloseMenu();
        if (b.dataset.galact === 'rep') { manTarget = k; $('#manFile').click(); }
        else galConfirm(`Odebrat ${title.toLowerCase()}?`, 'Varianta zmizí z online manuálu. Soubor můžete kdykoli nahrát znovu.', 'Odebrat logo', () => { M.logos[k] = null; manRe(); manSave('Logo odebráno'); }); };
      return; }
    const vs = e.target.closest('[data-manview-set]'); if (vs) { M.view = vs.dataset.manviewSet; try { localStorage.setItem('wb-manview', M.view); } catch (err) {} manRe(); document.querySelector(`[data-manview-set="${M.view}"]`)?.focus(); return; }
    const se = e.target.closest('[data-mansel]'); if (se && se.dataset.mansel) { manGo(M, se.dataset.mansel); return; }
    const pm = e.target.closest('[data-manpm]'); if (pm) { manPageMenu(pm, M, M.pages.find((x) => x.id === pm.dataset.manpm)); return; }
    // page editor
    const { pg } = manCur(); if (!pg) return;
    const ly = e.target.closest('[data-manlay]'); if (ly) { pg.layout = Number(ly.dataset.manlay); manRe(); manAuto(pg, 'Rozložení změněno'); document.querySelector(`[data-manlay="${pg.layout}"]`)?.focus(); return; }
    const sl = e.target.closest('label.man-slot'); if (sl) { e.preventDefault(); manImgT = Number(sl.dataset.manslot); $('#manImgFile').click(); return; }
    const at = e.target.closest('[data-manatt]');
    if (at) { const a = at.dataset.manatt;
      if (a === 'page-del') { pg.file = null; manRe(); manAuto(pg, 'Soubor odebrán'); return; }
      if (a === 'img') { const j = Number(at.closest('[data-manslot]').dataset.manslot), im = pg.imgs[j];
        if (im.file) { const menu = galMenu(at, [{ a: 'rep', i: 'i-paperclip', t: 'Nahradit soubor' }, { a: 'del', i: 'i-x', t: 'Odebrat soubor', danger: true }]); menu.onclick = (ev) => { const b = ev.target.closest('[data-galact]'); if (!b) return; galCloseMenu(); if (b.dataset.galact === 'rep') { manAttT = j; $('#manAttFile').click(); } else { im.file = null; manRe(); manAuto(pg, 'Soubor odebrán'); } }; return; }
        manAttT = j; } else manAttT = 'page';
      $('#manAttFile').click(); return; }
    const imm = e.target.closest('[data-manim]');
    if (imm) { const j = Number(imm.dataset.manim);
      const menu = galMenu(imm, [{ a: 'rep', i: 'i-upload', t: 'Nahradit obrázek' }, '-', { a: 'del', i: 'i-trash', t: 'Odebrat obrázek', danger: true }]);
      menu.onclick = (ev) => { const b = ev.target.closest('[data-galact]'); if (!b) return; galCloseMenu(); if (b.dataset.galact === 'rep') { manImgT = j; $('#manImgFile').click(); } else { pg.imgs[j] = null; manRe(); manAuto(pg, 'Obrázek odebrán'); } };
      return; }
    if (e.target.closest('[data-manadd]')) { if (!pg.layout || !pg.name.trim()) return; const n = manSlots(pg.layout).length; pg.imgs = pg.imgs.slice(0, Math.max(n, pg.imgs.length)); pg.id = 'pg' + Date.now().toString(36); pg.name = pg.name.trim(); M.pages.push(pg); M.draft = null; M.tab = 'mock'; location.hash = `projekt-${pp.pid}/manualy`; toast('Stránka přidána do manuálu'); }
  });
  $('#view').addEventListener('change', (e) => {
    const pp = manPath(); if (!pp) return;
    if (e.target.id === 'manFile') { const f = e.target.files[0]; if (f && manTarget) manUpload(manTarget, f); e.target.value = ''; return; }
    if (e.target.id === 'manImgFile') { const f = e.target.files[0]; if (f && manImgT != null) manImg(manImgT, f); e.target.value = ''; return; }
    if (e.target.id === 'manAttFile') { const f = e.target.files[0], { pg } = manCur(); e.target.value = ''; if (!f || !pg) return; if (f.size > 200 * 1024 * 1024) { toast('Soubor je větší než 200 MB', true); return; }
      if (manAttT === 'page') pg.file = f.name; else if (pg.imgs[manAttT]) pg.imgs[manAttT].file = f.name; manRe(); manAuto(pg, 'Soubor ke stažení přidán'); return; }
    if (e.target.id === 'manPgName') { const { pg } = manCur(); if (pg && pg.id && e.target.value.trim()) { pg.name = e.target.value.trim(); manSave('Název uložen'); } return; }
    const f = e.target.closest('[data-manf]'); if (!f) return; const M = manOf(pp.pid), k = f.closest('[data-manvar]').dataset.manvar, L = M.logos[k]; if (!L) return; const key = f.dataset.manf;
    if (key === 'page') { L.page = f.checked; manRe(); manSave(f.checked ? 'Varianta dostane vlastní stránku' : 'Vlastní stránka vypnuta'); return; }
    L[key] = f.value === '' ? '' : Math.max(0, Number(f.value) || 0); manSave();
  });
  $('#view').addEventListener('input', (e) => {
    if (!manPath()) return;
    if (e.target.id === 'manPgName') { const { pg } = manCur(); if (!pg) return; pg.name = e.target.value; if (!pg.id) { const b = $('[data-manadd]'); if (b) b.disabled = !(pg.layout && pg.name.trim()); const s = $('.man-bar-s'); if (s && pg.layout) s.textContent = pg.name.trim() ? (() => { const n = manSlots(pg.layout).length, f = manSlots(pg.layout).filter((_, j) => pg.imgs[j] && pg.imgs[j].src).length; return f < n ? `Nahráno ${f} z ${imgCount(n)}` : 'Všechny obrázky jsou nahrané'; })() : 'Doplňte název stránky'; } return; }
    const f = e.target.closest('[data-manf="scale"]'); if (!f) return; const card = f.closest('.man-var'), v = Number(f.value); card.querySelector('.man-scale b').textContent = v ? v + ' %' : 'Výchozí'; card.querySelector('.man-prev img').style.width = v ? v + '%' : ''; f.style.setProperty('--p', v + '%');
  });
  // Náhled stránek: all pages stacked for scrolling; the rail highlights the page in view, a click scrolls to it
  const manMark = (M, id) => { M.sel = id; $$('.man-th').forEach((li) => { const on = li.dataset.manpg === id; li.classList.toggle('on', on); const b = li.querySelector('button'); if (on) { b.setAttribute('aria-current', 'true'); const ol = li.parentElement; if (li.offsetTop < ol.scrollTop || li.offsetTop + li.offsetHeight > ol.scrollTop + ol.clientHeight) ol.scrollTop = li.offsetTop - ol.clientHeight / 2 + li.offsetHeight / 2; } else b.removeAttribute('aria-current'); }); };
  const manGo = (M, id) => { manMark(M, id); document.querySelector(`.man-thumbs [data-mansel="${id}"]`)?.focus({ preventScroll: true }); const t = document.getElementById('manp-' + id), sc = t && t.closest('.main'); if (t && sc) sc.scrollTo({ top: sc.scrollTop + t.getBoundingClientRect().top - sc.getBoundingClientRect().top - 12, behavior: 'smooth' }); else t?.scrollIntoView({ behavior: 'smooth', block: 'start' }); };
  let manIO = null;
  new MutationObserver(() => { const list = document.querySelector('.man-pdf-list'); if (!list || list.dataset.io) return; list.dataset.io = '1'; manIO?.disconnect(); const pp = manPath(); if (!pp) return; const M = manOf(pp.pid);
    manIO = new IntersectionObserver((en) => { const vis = en.filter((x) => x.isIntersecting).sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top)[0]; if (vis) manMark(M, vis.target.dataset.manpv); }, { rootMargin: '-25% 0px -60% 0px' });
    list.querySelectorAll('[data-manpv]').forEach((x) => manIO.observe(x));
    const t = document.getElementById('manp-' + M.sel); if (t && M.sel !== M.pages[0].id) t.scrollIntoView({ block: 'start' }); }).observe($('#view'), { childList: true });
  const manThumbFocus = (M) => { const b = document.querySelector(`.man-thumbs [data-mansel="${M.sel}"]`); if (b) { b.focus({ preventScroll: true }); b.scrollIntoView({ block: 'nearest' }); } };
  // Náhled stránek: arrow keys move between pages when focus is in the thumbnail rail
  document.addEventListener('keydown', (e) => {
    const pp = manPath(); if (!pp || pp.sub.length || !e.target.closest?.('.man-thumbs')) return; const M = manOf(pp.pid);
    if (!['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(e.key)) return; e.preventDefault();
    const i = M.pages.findIndex((x) => x.id === M.sel), n = e.key === 'Home' ? 0 : e.key === 'End' ? M.pages.length - 1 : Math.max(0, Math.min(M.pages.length - 1, i + (/Up|Left/.test(e.key) ? -1 : 1)));
    if (n !== i) manGo(M, M.pages[n].id);
  });
  // reorder pages by dragging the cards (grid) or the thumbnails (Náhled stránek)
  $('#view').addEventListener('dragstart', (e) => { const li = e.target.closest?.('[data-manpg][draggable]'); if (!li || !manPath()) return; manDragId = li.dataset.manpg; li.classList.add('man-dragging'); e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', manDragId); });
  $('#view').addEventListener('dragend', () => { manDragId = null; $$('.man-dragging, .man-over').forEach((x) => x.classList.remove('man-dragging', 'man-over')); });
  document.addEventListener('dragover', (e) => {
    const pp = manPath(); if (!pp) return;
    if (manDragId) { const li = e.target.closest?.('[data-manpg][draggable]'); if (li) { e.preventDefault(); $$('.man-over').forEach((x) => { if (x !== li) x.classList.remove('man-over'); }); if (li.dataset.manpg !== manDragId) li.classList.add('man-over'); } return; }
    if (isFiles(e)) { e.preventDefault(); const t = e.target.closest?.('[data-mandrop], label.man-slot, .man-slot'); $$('.man-drop-on').forEach((x) => { if (x !== t) x.classList.remove('man-drop-on'); }); t?.classList.add('man-drop-on'); }
  });
  document.addEventListener('drop', (e) => {
    const pp = manPath(); if (!pp) return; e.preventDefault(); const M = manOf(pp.pid);
    if (manDragId) { const li = e.target.closest?.('[data-manpg][draggable]'); if (li && li.dataset.manpg !== manDragId) { const a = M.pages.findIndex((x) => x.id === manDragId), pg = M.pages[a]; M.pages.splice(a, 1); M.pages.splice(M.pages.findIndex((x) => x.id === li.dataset.manpg) + (a <= M.pages.findIndex((x) => x.id === li.dataset.manpg) ? 1 : 0), 0, pg); manRe(); manSave('Pořadí uloženo'); } manDragId = null; return; }
    $$('.man-drop-on').forEach((x) => x.classList.remove('man-drop-on'));
    const f = isFiles(e) && e.dataTransfer.files[0]; if (!f) return;
    const t = e.target.closest?.('[data-mandrop]'); if (t) { manUpload(t.dataset.mandrop, f); return; }
    const s = e.target.closest?.('[data-manslot]'); if (s) manImg(Number(s.dataset.manslot), f);
  });
  /* ---------- Šablony projektu (#projekt-<id>/sablony) ----------
     Toolbar: "Nová šablona" opens a dialog with three type cards — wireframe, name, description (Prázdná šablona / Synchronizovaná šablona / Vícestránkový dokument / Šablona z obrázku — premium: upload JPG/PNG, the system builds an editable template; a copy of an existing one = Duplikovat in its menu), Kategorie (manage dialog),
     search. Galerie is not repeated here — it is in the Projekt menu. Templates are grouped by category (collapsible, state per viewer; drag the category header to move it with all its templates); each card: grip (drag to reorder or onto
     another category), name, "Synchronizováno" badge, "…" menu (Upravit design / Duplikovat / Přesunout do kategorie / Smazat), preview of the
     selected variant, last export, variant tags (ratio only, informational). Klient: no create / grip / menu, card opens the
     template to fill and export. The template editor itself is out of scope (toast). */
  const TP_RATIOS = { '4:5': [4, 5], '9:16': [9, 16], '1:1': [1, 1], '16:9': [16, 9], 'A4': [210, 297] };
  const TP_CATS = ['Příspěvky na sociální sítě', 'Výsledky a pozvánky', 'Foto s popiskem', 'Tiskoviny'];
  const TP_NAMES = [['Úvodní strana', 'Kategorie', 'Novinka', 'Citát', 'Akce týdne', 'Poděkování'], ['Výsledky zápasu', 'Pozvánka na akci', 'Program', 'Soupiska', 'Rozpis zápasů'], ['Foto s popiskem', 'Galerie z akce', 'Portrét'], ['Plakát A4', 'Leták', 'Vizitka', 'Roll-up']];
  S.tp = {};
  const tpImg = (p, r, i, title) => {
    const [w0, h0] = TP_RATIOS[r], k = 300 / Math.max(w0, h0), w = Math.round(w0 * k), h = Math.round(h0 * k);
    const cs = p.colors && p.colors.length ? p.colors : ['#17375F', '#C79A5B'], bg = cs[i % cs.length], fg = cs[(i + 1) % cs.length], light = palLum(bg) > .5, ink = light ? '#1B1A20' : '#FFFFFF';
    const fs = Math.min(w, h) * .13, lines = String(title).toUpperCase().split(' ').slice(0, 2);
    const svg = `<svg xmlns='http://www.w3.org/2000/svg' width='${w}' height='${h}' viewBox='0 0 ${w} ${h}'><rect width='${w}' height='${h}' fill='${bg}'/>${Array.from({ length: 7 }, (_, j) => `<rect x='${-w + j * w * .36}' y='${h * .1}' width='${w * .14}' height='${h * 1.6}' fill='${fg}' opacity='.10' transform='rotate(28 ${w / 2} ${h / 2})'/>`).join('')}<circle cx='${w / 2}' cy='${h * .22}' r='${Math.min(w, h) * .08}' fill='${fg}'/>${lines.map((t, j) => `<text x='${w / 2}' y='${h * .52 + j * fs * 1.05}' text-anchor='middle' font-family='Outfit, Arial, sans-serif' font-weight='800' font-size='${fs}' fill='${ink}'>${esc(t)}</text>`).join('')}<rect x='${w * .3}' y='${h * .52 + lines.length * fs * 1.05 - fs * .4}' width='${w * .4}' height='${fs * .55}' rx='${fs * .12}' fill='${fg}'/></svg>`;
    return 'data:image/svg+xml;utf8,' + encodeURIComponent(svg);
  };
  function tpOf(pid) {
    if (S.tp[pid]) return S.tp[pid];
    const p = byId(pid) || { name: '', colors: [] }, r = seeded('tp' + pid), n = Math.max(3, Math.min(18, Number(p.templates) || 6));
    const cats = TP_CATS.map((name, i) => ({ id: 'c' + i, name })), items = [];
    for (let i = 0; i < n; i++) {
      const ci = i < 4 ? i % 4 : Math.floor(r() * 4), names = TP_NAMES[ci], used = items.filter((x) => x.cat === 'c' + ci).length, name = names[used % names.length] + (used >= names.length ? ' ' + (Math.floor(used / names.length) + 1) : '');
      const rs = ci === 3 ? ['A4'] : ['4:5', '9:16', '1:1', '16:9'].filter((_, j) => j === 0 || r() < .4);
      const d = new Date(2026, 9, 30 - Math.floor(r() * 70), 8 + Math.floor(r() * 11), Math.floor(r() * 60));
      items.push({ id: 't' + i, uses: Math.floor(r() * r() * 60), name, cat: 'c' + ci, sync: r() < .35, doc: ci === 3 && r() < .5, v: 0, exported: r() < .85 ? d : null, vars: rs.map((x) => ({ r: x, src: tpImg(p, x, i, name) })) });
    }
    let closed = []; try { closed = JSON.parse(localStorage.getItem('wb-tpclosed-' + pid) || '[]'); } catch (e) {}
    return (S.tp[pid] = { cats, items, q: '', closed: new Set(closed) });
  }
  const tpPid = () => { if (!S.route.startsWith('projekt-')) return null; const [pid, a] = S.route.slice(8).split('/'); return a === 'sablony' ? pid : null; };
  const tpWhen = (d) => d ? `${d.getDate()}. ${d.getMonth() + 1}. ${d.getFullYear()} ${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}` : null;
  const tpWord = (n) => n + ' ' + (n === 1 ? 'šablona' : n >= 2 && n <= 4 ? 'šablony' : 'šablon');
  function renderTemplates(pid) {
    const p = byId(pid); if (!p) { renderDetail(pid); return; }
    const T = tpOf(pid), ro = viewAs() === 'user', q = T.q.trim().toLowerCase();
    const list = (c) => T.items.filter((t) => t.cat === c.id && (!q || t.name.toLowerCase().includes(q)));
    const groups = T.cats.map((c) => ({ c, items: list(c) })).filter((g) => g.items.length || (!q && !ro));
    const v = $('#view'), hadFocus = document.activeElement && document.activeElement.id === 'tpSearch';
    v.innerHTML = `<a href="#projekt-${esc(p.id)}" class="back"><span class="i i-arrow-left s16" aria-hidden="true"></span>${esc(p.name)}</a>
      <div class="head"><div style="display:flex;flex-direction:column;gap:8px;min-width:0"><span class="lbl">Projekt · ${esc(p.name)}</span><h1>Šablony <span class="pd-n tp-count">${T.items.length}</span></h1><span class="cap gal-lead">${ro ? 'Vyberte šablonu, doplňte texty a obrázky a exportujte.' : 'Šablony příspěvků, tiskovin a grafik. Kategorie sbalíte klikem na název, šablony i celé kategorie přeskládáte přetažením.'}</span></div></div>
      <div class="gal-nav tp-nav">${ro ? '' : `<button type="button" class="btn sm" data-tpnew aria-haspopup="dialog"><span class="i i-plus s16" aria-hidden="true"></span>Nová šablona</button><button type="button" class="btn sm tertiary" data-tpcats><span class="i i-folder s16" aria-hidden="true"></span>Kategorie</button>`}
        <span class="gal-nav-sp" aria-hidden="true"></span>
        ${groups.length > 1 && !q ? `<button type="button" class="btn sm ghost" data-tpall>${T.closed.size ? '<span class="i i-chev-down s16" aria-hidden="true"></span>Rozbalit vše' : '<span class="i i-chev-up s16" aria-hidden="true"></span>Sbalit vše'}</button>` : ''}
        ${szCtl('tp')}
        <label class="inp sm tp-search"><span class="i i-search s16" aria-hidden="true"></span><input type="search" id="tpSearch" placeholder="Hledat šablonu" value="${esc(T.q)}" aria-label="Hledat šablonu" autocomplete="off"></label></div>
      ${tpTop(T, q, ro)}
      ${groups.length ? '<div class="tp-groups">' + groups.map(({ c, items }) => { const open = !!q || !T.closed.has(c.id); return `<section class="tp-group${open ? '' : ' closed'}" data-tpcat="${c.id}" aria-labelledby="tpc-${c.id}"><div class="tp-gh"${ro ? '' : ` draggable="true" data-tpcg="${c.id}"`}>${ro ? '' : '<span class="i i-grip s16 man-grip" aria-hidden="true" title="Přetažením přesunete celou kategorii"></span>'}<button type="button" class="tp-tg" data-tptg="${c.id}" aria-expanded="${open}" aria-controls="tpb-${c.id}"><h2 id="tpc-${c.id}">${esc(c.name)}</h2><span class="pd-n">${items.length}</span><span class="tp-chev" aria-hidden="true"><span class="i i-chev-down s16"></span></span></button></div>
        <div class="tp-body" id="tpb-${c.id}"${open ? '' : ' hidden'}>${items.length ? `<ul class="tp-grid">${items.map((t) => tpCard(t, ro)).join('')}</ul>` : `<div class="tp-empty" data-tpcat="${c.id}">Zatím žádná šablona — přetáhněte sem kartu nebo vytvořte novou.</div>`}</div></section>`; }).join('') + '</div>'
      : `<div class="empty"><span class="i i-search" aria-hidden="true"></span><b>Nic jsme nenašli</b><span class="cap">Zkuste jiný název šablony.</span></div>`}`;
    if (hadFocus) { const s = $('#tpSearch'); s.focus(); s.setSelectionRange(s.value.length, s.value.length); }
  }
  // "Nejpoužívanější": automatic quick-pick of the most exported templates (last 30 days), highlighted, not editable / not a drop target
  const TP_TOP = 4;
  const tpTop = (T, q, ro) => {
    if (q) return ''; const top = T.items.filter((t) => t.uses > 0).sort((a, b) => b.uses - a.uses).slice(0, TP_TOP); if (top.length < 2) return '';
    const open = !T.closed.has('top');
    return `<section class="tp-top${open ? '' : ' closed'}" aria-labelledby="tpc-top"><div class="tp-gh"><button type="button" class="tp-tg" data-tptg="top" aria-expanded="${open}" aria-controls="tpb-top"><span class="tp-top-ico" aria-hidden="true"><span class="i i-star-f s16"></span></span><span class="tp-top-t"><h2 id="tpc-top" title="Systém sem sám řadí šablony s nejvíce exporty za posledních 30 dní">Nejpoužívanější</h2></span><span class="tp-chev" aria-hidden="true"><span class="i i-chev-down s16"></span></span></button></div>
      <div class="tp-body" id="tpb-top"${open ? '' : ' hidden'}><ul class="tp-grid">${top.map((t) => tpCard(t, ro, true)).join('')}</ul></div></section>`;
  };
  const tpCard = (t, ro, top) => {
    const V = t.vars[0], [w, h] = TP_RATIOS[V.r], when = tpWhen(t.exported);
    return `<li class="tp-card" data-tp="${t.id}"${ro || top ? '' : ' draggable="true"'}>
      <div class="tp-h">${ro || top ? '' : '<span class="i i-grip s16 man-grip" aria-hidden="true"></span>'}<h3 title="${esc(t.name)}">${esc(t.name)}</h3>${ro ? '' : `<button type="button" class="btn icon xs ghost tp-mb" data-tpm="${t.id}" aria-label="Možnosti šablony ${esc(t.name)}" aria-haspopup="menu"><span class="i i-more s16" aria-hidden="true"></span></button>`}</div>
      <button type="button" class="tp-prev" data-tpopen="${t.id}" aria-label="${ro ? 'Vyplnit a exportovat' : 'Upravit'} šablonu ${esc(t.name)}"><img src="${V.src}" alt="" style="aspect-ratio:${w} / ${h}" loading="lazy"></button>
      <span class="cap tp-exp"><span class="i i-history s14" aria-hidden="true"></span>${top ? `${t.uses}× exportováno za 30 dní` : when ? `Naposledy exportováno ${when}` : 'Zatím neexportováno'}</span>
      <div class="tp-vars" aria-label="Varianty: ${t.vars.map((x) => x.r).join(', ')}">${t.doc ? '<span class="tp-var tp-var-doc"><span class="i i-book s12" aria-hidden="true"></span>Dokument</span>' : ''}${t.vars.map((x) => `<span class="tp-var">${x.r}</span>`).join('')}</div>
    </li>`;
  };
  const tpSaveClosed = (pid, T) => { try { localStorage.setItem('wb-tpclosed-' + pid, JSON.stringify([...T.closed])); } catch (e) {} };
  const tpRe = () => { const pid = tpPid(); if (pid) renderTemplates(pid); };
  // pick dialog (Přesunout do kategorie, kam přesunout při mazání kategorie) and category manager
  function tpPick(title, okText, opts, cur, fn) {
    const d = $('#dlgTpPick'); let pick = cur;
    $('#tpPickT').textContent = title; $('#tpPickOk').textContent = okText;
    const draw = () => { $('#tpPickList').innerHTML = opts.map((o) => `<label class="gal-mv${pick === o.id ? ' on' : ''}"><input type="radio" class="sr" name="tppick" value="${o.id}"${pick === o.id ? ' checked' : ''}><span class="rd${pick === o.id ? ' on' : ''}" aria-hidden="true"></span><span class="i ${o.icon} s16" aria-hidden="true"></span><b>${esc(o.name)}</b>${o.sub ? `<span class="cap" style="margin-left:auto">${esc(o.sub)}</span>` : ''}</label>`).join(''); $('#tpPickOk').disabled = pick == null || pick === cur; };
    $('#tpPickList').onchange = (e) => { pick = e.target.value; draw(); };
    $('#tpPickOk').onclick = () => { d.close(); fn(pick); };
    draw(); d.showModal(); ($('#tpPickList input:checked') || $('#tpPickList input'))?.focus();
  }
  // "Nová šablona": dialog with three type cards (wireframe illustration, name, description); a click creates it
  const TP_TYPES = [
    { a: 'blank', t: 'Prázdná šablona', d: 'Jeden design s variantami formátů (4:5, 9:16, 1:1…). Texty a obrázky doplní uživatel před exportem.',
      wf: '<rect class="wf-card" x="62" y="14" width="76" height="96" rx="6"/><rect class="wf-ph" x="68" y="20" width="64" height="46" rx="3"/><circle class="wf-b" cx="84" cy="34" r="5"/><path class="wf-b" d="M74 60l14-14 10 10 6-6 12 10z"/><rect class="wf-acc" x="68" y="74" width="34" height="5" rx="2.5"/><rect class="wf-b" x="68" y="84" width="58" height="3.5" rx="1.75"/><rect class="wf-b" x="68" y="92" width="46" height="3.5" rx="1.75"/><rect class="wf-card" x="146" y="44" width="30" height="38" rx="4"/><rect class="wf-ph" x="149" y="47" width="24" height="18" rx="2"/><rect class="wf-card" x="24" y="52" width="30" height="30" rx="4"/><rect class="wf-ph" x="27" y="55" width="24" height="14" rx="2"/>' },
    { a: 'sync', t: 'Synchronizovaná šablona', d: 'Varianty pro různé formáty jsou propojené — změna textu nebo obrázku v jedné se sama propíše do všech ostatních.',
      wf: '<rect class="wf-card" x="22" y="18" width="62" height="78" rx="6"/><rect class="wf-ph" x="28" y="24" width="50" height="36" rx="3"/><rect class="wf-acc" x="28" y="66" width="30" height="5" rx="2.5"/><rect class="wf-b" x="28" y="76" width="44" height="3.5" rx="1.75"/><rect class="wf-card" x="128" y="10" width="50" height="94" rx="6"/><rect class="wf-ph" x="134" y="16" width="38" height="50" rx="3"/><rect class="wf-acc" x="134" y="72" width="26" height="5" rx="2.5"/><rect class="wf-b" x="134" y="82" width="34" height="3.5" rx="1.75"/><path class="wf-line" d="M92 48c8-8 20-8 28 0M120 66c-8 8-20 8-28 0"/><path class="wf-arrow" d="M116 42l5 6-7 2M96 72l-5-6 7-2"/>' },
    { a: 'doc', t: 'Vícestránkový dokument', d: 'Více stránek v jednom souboru — prezentace, katalog, menu nebo leták. Exportuje se do PDF.',
      wf: '<rect class="wf-card op" x="78" y="10" width="64" height="88" rx="5"/><rect class="wf-card op2" x="70" y="16" width="64" height="88" rx="5"/><rect class="wf-card" x="62" y="22" width="64" height="88" rx="5"/><rect class="wf-ph" x="68" y="28" width="52" height="30" rx="3"/><rect class="wf-acc" x="68" y="64" width="28" height="5" rx="2.5"/><rect class="wf-b" x="68" y="74" width="50" height="3.5" rx="1.75"/><rect class="wf-b" x="68" y="82" width="42" height="3.5" rx="1.75"/><rect class="wf-b" x="68" y="90" width="46" height="3.5" rx="1.75"/>' },
    { a: 'ai', t: 'Šablona z obrázku', d: 'Nahrajte hotovou grafiku (JPG nebo PNG) a systém z ní sám vytvoří upravitelnou šablonu v editoru — texty, obrázky i barvy.', premium: true, btn: 'Nahrát obrázek', icon: 'i-upload',
      wf: '<rect class="wf-card" x="16" y="18" width="62" height="80" rx="5"/><rect class="wf-ph" x="21" y="23" width="52" height="70" rx="3"/><circle class="wf-b" cx="35" cy="38" r="5"/><path class="wf-b" d="M24 86l18-20 12 12 8-8 11 16z"/><path class="wf-line" d="M88 58h22"/><path class="wf-arrow" d="M104 52l7 6-7 6"/><path class="wf-spark" d="M99 36l2 5 5 2-5 2-2 5-2-5-5-2 5-2z"/><rect class="wf-card" x="122" y="18" width="62" height="80" rx="5"/><rect class="wf-dash" x="128" y="24" width="50" height="34" rx="3"/><rect class="wf-dash" x="128" y="64" width="34" height="9" rx="2"/><rect class="wf-dash" x="128" y="78" width="46" height="12" rx="2"/>' }];
  function tpNew() {
    const pid = tpPid(), T = tpOf(pid), p = byId(pid), d = $('#dlgTpNew');
    $('#tpNewList').innerHTML = TP_TYPES.map((x) => `<div class="tp-type${x.premium ? ' prem' : ''}" data-tptype="${x.a}"><span class="tp-type-wf"><svg viewBox="0 0 200 120" aria-hidden="true">${x.wf}</svg></span><span class="tp-type-b"><span class="tp-type-t"><b>${x.t}</b>${x.premium ? '<span class="tp-prem"><span class="i i-sparkle s14" aria-hidden="true"></span>Premium</span>' : ''}</span><span class="cap">${x.d}</span><button type="button" class="btn sm tertiary tp-type-go" data-tpgo="${x.a}"><span class="i ${x.icon || 'i-plus'} s16" aria-hidden="true"></span>${x.btn || 'Vytvořit'}<span class="sr"> — ${x.t}</span></button></span></div>`).join('');
    $('#tpNewList').onclick = (e) => { const b = e.target.closest('[data-tptype]'); if (!b) return; const a = b.dataset.tptype;
      if (a === 'ai') { $('#tpAiFile').click(); return; }
      d.close();
      const o = a === 'sync' ? { name: 'Nová synchronizovaná šablona', sync: true } : a === 'doc' ? { name: 'Nový dokument', doc: true, vars: [{ r: 'A4', src: tpImg(p, 'A4', T.items.length, 'Nový dokument') }] } : {};
      const nid = 't' + Date.now().toString(36); T.items.unshift({ id: nid, blank: true, name: 'Nová šablona', cat: T.cats[0].id, sync: false, doc: false, v: 0, exported: null, vars: [{ r: '4:5', src: tpImg(p, '4:5', T.items.length, 'Nová šablona') }], ...o });
      T.q = ''; location.hash = `projekt-${pid}/sablony/${nid}`; toast('Šablona vytvořena'); };
    $('#tpAiFile').onchange = (e) => { const f = e.target.files[0]; e.target.value = ''; if (!f) return;
      if (!/^image\/(jpeg|png)$/.test(f.type)) { toast('Nahrajte JPG nebo PNG', true); return; }
      if (f.size > GAL_MAX) { toast('Obrázek je větší než 10 MB', true); return; }
      d.close(); aiStart(pid, f); };
    d.showModal(); $('#tpNewList .tp-type-go').focus();
  }
  function tpCats() {
    const pid = tpPid(), T = tpOf(pid), d = $('#dlgTpCats');
    const draw = () => { $('#tpCatList').innerHTML = T.cats.map((c) => { const n = T.items.filter((t) => t.cat === c.id).length; return `<li class="tp-cat" data-tpc="${c.id}" draggable="true"><span class="i i-grip s14 tp-cat-grip" aria-hidden="true" title="Přetažením změníte pořadí"></span><input type="text" value="${esc(c.name)}" size="${Math.max(3, Math.round(c.name.length * .8))}" maxlength="50" aria-label="Název kategorie" title="Kliknutím přejmenujete"><span class="pd-n" aria-label="${tpWord(n)}">${n}</span><button type="button" class="tp-cat-del" data-tpcdel aria-label="Smazat kategorii ${esc(c.name)}"${T.cats.length < 2 ? ' disabled' : ''}><span class="i i-trash s14" aria-hidden="true"></span></button></li>`; }).join('') + '<li class="tp-cat-add-li"><button type="button" class="tp-cat-add" id="tpCatAddTag"><span class="i i-plus s14" aria-hidden="true"></span>Přidat kategorii</button></li>'; };
    $('#tpCatList').oninput = (e) => { if (e.target.matches('input')) e.target.size = Math.max(3, Math.round(e.target.value.length * .8)); };
    $('#tpCatList').onkeydown = (e) => { if (e.target.matches('input') && e.key === 'Enter') { e.preventDefault(); e.target.blur(); } };
    // drag tags to reorder categories (same order as on the page)
    let cDrag = null;
    $('#tpCatList').ondragstart = (e) => { const li = e.target.closest?.('[data-tpc]'); if (!li || e.target.matches('input')) return; cDrag = li.dataset.tpc; li.classList.add('man-dragging'); e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', cDrag); };
    $('#tpCatList').ondragover = (e) => { if (!cDrag) return; const li = e.target.closest('[data-tpc]'); if (!li) return; e.preventDefault(); $$('#tpCatList .man-over').forEach((x) => { if (x !== li) x.classList.remove('man-over'); }); if (li.dataset.tpc !== cDrag) li.classList.add('man-over'); };
    $('#tpCatList').ondrop = (e) => { if (!cDrag) return; e.preventDefault(); const li = e.target.closest('[data-tpc]'); if (li && li.dataset.tpc !== cDrag) { const a = T.cats.findIndex((x) => x.id === cDrag), c = T.cats.splice(a, 1)[0], b = T.cats.findIndex((x) => x.id === li.dataset.tpc); T.cats.splice(a <= b ? b + 1 : b, 0, c); draw(); tpRe(); toast('Pořadí kategorií uloženo'); } cDrag = null; };
    $('#tpCatList').ondragend = () => { cDrag = null; $$('#tpCatList .man-dragging, #tpCatList .man-over').forEach((x) => x.classList.remove('man-dragging', 'man-over')); };
    $('#tpCatList').onchange = (e) => { const li = e.target.closest('[data-tpc]'); if (!li) return; const c = T.cats.find((x) => x.id === li.dataset.tpc), val = e.target.value.trim(); if (val) { c.name = val; toast('Kategorie přejmenována'); } else e.target.value = c.name; tpRe(); };
    $('#tpCatList').onclick = (e) => { if (e.target.closest('#tpCatAddTag')) { add(); return; } const b = e.target.closest('[data-tpcdel]'); if (!b) return; const id = b.closest('[data-tpc]').dataset.tpc, c = T.cats.find((x) => x.id === id), n = T.items.filter((t) => t.cat === id).length;
      const del = (to) => { if (to) T.items.forEach((t) => { if (t.cat === id) t.cat = to; }); T.cats.splice(T.cats.indexOf(c), 1); draw(); tpRe(); toast(to ? `Kategorie smazána — ${tpWord(n)} přesunuto do „${T.cats.find((x) => x.id === to).name}“` : 'Kategorie smazána'); $('#tpCatAddTag')?.focus(); };
      if (!n) { del(null); return; }
      // non-empty: ask where the templates should go
      tpPick(`Kam přesunout ${tpWord(n)} z „${c.name}“?`, 'Smazat a přesunout', T.cats.filter((x) => x.id !== id).map((x) => ({ id: x.id, name: x.name, icon: 'i-folder', sub: tpWord(T.items.filter((t) => t.cat === x.id).length) })), null, (to) => del(to)); };
    const add = () => { T.cats.push({ id: 'c' + Date.now().toString(36), name: 'Nová kategorie' }); draw(); tpRe(); const ins = $$('#tpCatList input'); ins[ins.length - 1].focus(); ins[ins.length - 1].select(); };
    draw(); d.showModal();
  }
  $('#view').addEventListener('input', (e) => { if (e.target.id !== 'tpSearch' || !tpPid()) return; tpOf(tpPid()).q = e.target.value; tpRe(); });
  $('#view').addEventListener('click', (e) => {
    const pid = tpPid(); if (!pid) return; const T = tpOf(pid), p = byId(pid);
    const tg = e.target.closest('[data-tptg]'); if (tg) { const id = tg.dataset.tptg; if (T.closed.has(id)) T.closed.delete(id); else T.closed.add(id); tpSaveClosed(pid, T); tpRe(); document.querySelector(`[data-tptg="${id}"]`)?.focus(); return; }
    if (e.target.closest('[data-tpall]')) { if (T.closed.size) T.closed.clear(); else T.cats.forEach((c) => T.closed.add(c.id)); tpSaveClosed(pid, T); tpRe(); $('[data-tpall]')?.focus(); return; }
    const op = e.target.closest('[data-tpopen]'); if (op) { if (viewAs() === 'user') location.hash = `projekt-${pid}/sablony/${op.dataset.tpopen}/vyplnit`; else location.hash = `projekt-${pid}/sablony/${op.dataset.tpopen}`; return; }
    if (e.target.closest('[data-tpcats]')) { tpCats(); return; }
    if (e.target.closest('[data-tpnew]')) { tpNew(); return; }
    const mb = e.target.closest('[data-tpm]');
    if (mb) { const t = T.items.find((x) => x.id === mb.dataset.tpm);
      const menu = galMenu(mb, [{ a: 'edit', i: 'i-edit', t: 'Upravit design' }, { a: 'dup', i: 'i-copy', t: 'Duplikovat' }, { a: 'move', i: 'i-folder', t: 'Přesunout do kategorie' }, '-', { a: 'del', i: 'i-trash', t: 'Smazat šablonu', danger: true }]);
      menu.onclick = (ev) => { const b = ev.target.closest('[data-galact]'); if (!b) return; galCloseMenu(); const a = b.dataset.galact;
        if (a === 'edit') location.hash = `projekt-${pid}/sablony/${t.id}`;
        else if (a === 'dup') { T.items.splice(T.items.indexOf(t) + 1, 0, { ...t, id: 't' + Date.now().toString(36), name: t.name + ' (kopie)', exported: null, vars: t.vars.map((x) => ({ ...x })) }); tpRe(); toast('Šablona zduplikována'); }
        else if (a === 'move') tpPick(`Přesunout „${t.name}“`, 'Přesunout', T.cats.map((c) => ({ id: c.id, name: c.name, icon: 'i-folder', sub: tpWord(T.items.filter((x) => x.cat === c.id).length) })), t.cat, (id) => { t.cat = id; tpRe(); toast(`Přesunuto do „${T.cats.find((c) => c.id === id).name}“`); });
        else galConfirm(`Smazat šablonu „${t.name}“?`, 'Šablona i její varianty zmizí. Už vytvořené exporty zůstanou uživatelům v počítači.', 'Smazat šablonu', () => { T.items.splice(T.items.indexOf(t), 1); tpRe(); toast('Šablona smazána'); }); };
    }
  });
  // drag a card onto another card (reorder) or onto a category (move)
  let tpDrag = null, tpCatDrag = null;
  $('#view').addEventListener('dragstart', (e) => { const h = e.target.closest?.('[data-tpcg]'); if (!h || !tpPid() || e.target.closest('[data-tp]')) return; tpCatDrag = h.dataset.tpcg; h.closest('.tp-group').classList.add('man-dragging'); e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', tpCatDrag); });
  $('#view').addEventListener('dragover', (e) => { if (!tpCatDrag) return; const g = e.target.closest('.tp-group'); if (!g) return; e.preventDefault(); $$('.tp-group.tp-over').forEach((x) => { if (x !== g) x.classList.remove('tp-over'); }); if (g.dataset.tpcat !== tpCatDrag) g.classList.add('tp-over'); });
  $('#view').addEventListener('drop', (e) => { if (!tpCatDrag) return; e.preventDefault(); const g = e.target.closest('.tp-group'), T = tpOf(tpPid());
    if (g && g.dataset.tpcat !== tpCatDrag) { const a = T.cats.findIndex((c) => c.id === tpCatDrag), c = T.cats[a], b = T.cats.findIndex((x) => x.id === g.dataset.tpcat); T.cats.splice(a, 1); T.cats.splice(a < b ? b : b, 0, c); if (a < b) { T.cats.splice(T.cats.indexOf(c), 1); T.cats.splice(T.cats.findIndex((x) => x.id === g.dataset.tpcat) + 1, 0, c); } tpRe(); toast(`Kategorie „${c.name}“ přesunuta i se šablonami`); }
    tpCatDrag = null; $$('.tp-over, .man-dragging').forEach((x) => x.classList.remove('tp-over', 'man-dragging')); });
  $('#view').addEventListener('dragend', () => { tpCatDrag = null; $$('.tp-over').forEach((x) => x.classList.remove('tp-over')); });
  $('#view').addEventListener('dragstart', (e) => { const c = e.target.closest?.('[data-tp][draggable]'); if (!c || !tpPid()) return; e.stopPropagation(); tpDrag = c.dataset.tp; c.classList.add('man-dragging'); e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', tpDrag); });
  $('#view').addEventListener('dragend', () => { if (!tpPid()) return; tpDrag = null; $$('.man-dragging, .man-over').forEach((x) => x.classList.remove('man-dragging', 'man-over')); });
  $('#view').addEventListener('dragover', (e) => { if (!tpDrag) return; const t = e.target.closest('[data-tp], .tp-empty, .tp-group'); if (!t) return; e.preventDefault(); const hl = t.matches('.tp-group') ? null : t; $$('.man-over').forEach((x) => { if (x !== hl) x.classList.remove('man-over'); }); if (hl && hl.dataset.tp !== tpDrag) hl.classList.add('man-over'); });
  $('#view').addEventListener('drop', (e) => {
    if (!tpDrag) return; e.preventDefault(); const T = tpOf(tpPid()), src = T.items.find((x) => x.id === tpDrag), card = e.target.closest('[data-tp]'), grp = e.target.closest('[data-tpcat]');
    if (card && card.dataset.tp !== tpDrag) { const dst = T.items.find((x) => x.id === card.dataset.tp), from = T.items.indexOf(src); T.items.splice(from, 1); const to = T.items.indexOf(dst); T.items.splice(from <= to && src.cat === dst.cat ? to + 1 : to, 0, src); const moved = src.cat !== dst.cat; src.cat = dst.cat; tpRe(); toast(moved ? `Přesunuto do „${T.cats.find((c) => c.id === dst.cat).name}“` : 'Pořadí uloženo'); }
    else if (!card && grp && grp.dataset.tpcat !== src.cat) { src.cat = grp.dataset.tpcat; T.items.splice(T.items.indexOf(src), 1); T.items.push(src); tpRe(); toast(`Přesunuto do „${T.cats.find((c) => c.id === src.cat).name}“`); }
    tpDrag = null;
  });
  /* ---------- Šablona z obrázku (Premium) — #projekt-<id>/sablony/import ----------
     The designer uploads a finished JPG/PNG; AI splits it into segments of four types, each with its own colour drawn over the image:
     Texty (lavender, with transcription) · Obrázky (mint) · Pozadí (apricot) · Tvary (rose). The designer then walks the same image
     step by step, one type at a time: fix a box (drag / resize), fix the transcription, delete a wrong one, draw a missed one, or move a
     segment to another type. Step 5 Shrnutí names the template and builds it in the editor (texts with project fonts/colours, images as
     replaceable slots, background, shapes). Prototype: the detection is simulated on a sample poster with known positions and a few
     deliberate mistakes (missed scorer + sponsor logo, "–" taken for a shape, missing diacritics). */
  const AI_W = 1080, AI_H = 1350;
  const AI_T = [
    { k: 'text', n: 'Texty', one: 'text', c: 'var(--lav)', ink: 'var(--lav-ink)', d: 'Zkontrolujte rámečky a přepis. Z každého textu bude vstup, který klient vyplní.' },
    { k: 'img', n: 'Obrázky', one: 'obrázek', c: 'var(--mnt)', ink: 'var(--mnt-ink)', d: 'Loga a fotky se stanou sloty, do kterých klient vloží vlastní obrázek.' },
    { k: 'bg', n: 'Pozadí', one: 'pozadí', c: 'var(--pch)', ink: 'var(--pch-ink)', d: 'Podklad šablony. AI z něj odstraní texty, loga i tvary, aby šly upravovat zvlášť.' },
    { k: 'shape', n: 'Tvary', one: 'tvar', c: 'var(--rose)', ink: 'var(--rose-ink)', d: 'Pruhy a plochy se převedou na tvary s barvou z palety projektu.' }];
  const aiType = (k) => AI_T.find((t) => t.k === k);
  // sample poster (football result) — positions match the simulated detection below
  const aiPoster = () => {
    const T = (x, y, s, w, t, fill = '#fff', a = 'start') => `<text x='${x}' y='${y}' font-family='Outfit, Arial, sans-serif' font-size='${s}' font-weight='${w}' fill='${fill}' text-anchor='${a}'>${t}</text>`;
    const crest = (cx, cy, c1, c2, t) => `<circle cx='${cx}' cy='${cy}' r='96' fill='${c1}'/><circle cx='${cx}' cy='${cy}' r='78' fill='none' stroke='${c2}' stroke-width='10'/><path d='M${cx - 34} ${cy + 26} L${cx} ${cy - 40} L${cx + 34} ${cy + 26}z' fill='${c2}'/>${T(cx, cy + 62, 22, 800, t, c2, 'middle')}`;
    const stripes = Array.from({ length: 9 }, (_, i) => `<rect x='${-400 + i * 210}' y='-200' width='70' height='1900' fill='#fff' opacity='.035' transform='rotate(24 540 675)'/>`).join('');
    const sc = (x, y, t, a) => T(x, y, 34, 600, t, '#E9EEF7', a);
    const svg = `<svg xmlns='http://www.w3.org/2000/svg' width='${AI_W}' height='${AI_H}' viewBox='0 0 ${AI_W} ${AI_H}'><rect width='${AI_W}' height='${AI_H}' fill='#0E1E3A'/>${stripes}<circle cx='900' cy='160' r='260' fill='#3E8FD8' opacity='.10'/>
      ${T(540, 205, 132, 800, 'DOROST U19', '#3E8FD8', 'middle')}
      ${crest(230, 480, '#F4C430', '#0E1E3A', 'FKS')}${crest(850, 480, '#E9EEF7', '#1A3866', 'FFM')}
      ${T(540, 493, 40, 700, 'KONEC UTKÁNÍ', '#E9EEF7', 'middle')}
      <rect x='0' y='670' width='300' height='120' fill='#13294F'/><rect x='780' y='670' width='300' height='120' fill='#3E8FD8'/>
      ${T(410, 812, 230, 800, '2', '#fff', 'middle')}<rect x='510' y='710' width='60' height='22' rx='4' fill='#fff'/>${T(670, 812, 230, 800, '1', '#fff', 'middle')}
      ${sc(120, 955, "23' Kovalčík")}${sc(120, 1015, "34' Janošec")}${sc(120, 1075, "61' Šigut")}${sc(120, 1135, "88' Poruba")}
      ${sc(960, 955, "12' Mucha", 'end')}${sc(960, 1015, "47' Michna", 'end')}${sc(960, 1075, "70' Vu Minh", 'end')}${sc(960, 1135, "90' Krpec", 'end')}
      <rect x='440' y='1200' width='200' height='80' rx='14' fill='#E9EEF7'/>${T(540, 1252, 30, 800, 'SPONZOR', '#0E1E3A', 'middle')}
      ${T(60, 1300, 28, 600, 'fotbal-fm.cz', '#8FA3C4')}</svg>`;
    return 'data:image/svg+xml;utf8,' + encodeURIComponent(svg);
  };
  // simulated AI result: [type, x, y, w, h, name, transcription, extra]
  const AI_SEED = [
    ['text', 120, 92, 840, 140, 'Kategorie', 'DOROST U19', 'Montserrat ExtraBold · 132 px · #3E8FD8'],
    ['text', 395, 452, 290, 54, 'Konec utkání', 'KONEC UTKANI', 'Montserrat Bold · 40 px · #E9EEF7'],
    ['text', 340, 630, 140, 200, 'Skóre domácí', '2', 'Montserrat ExtraBold · 230 px · #FFFFFF'],
    ['text', 600, 630, 140, 200, 'Skóre hosté', '1', 'Montserrat ExtraBold · 230 px · #FFFFFF'],
    ['text', 112, 924, 300, 44, 'D – Střelec 1', "23' Kovalčík", 'Montserrat SemiBold · 34 px · #E9EEF7'],
    ['text', 112, 984, 300, 44, 'D – Střelec 2', "34' Janošec", 'Montserrat SemiBold · 34 px · #E9EEF7'],
    ['text', 112, 1044, 300, 44, 'D – Střelec 3', "61' Šigut", 'Montserrat SemiBold · 34 px · #E9EEF7'],
    ['text', 112, 1104, 300, 44, 'D – Střelec 4', "88' Poruba", 'Montserrat SemiBold · 34 px · #E9EEF7'],
    ['text', 668, 924, 300, 44, 'H – Střelec 1', "12' Mucha", 'Montserrat SemiBold · 34 px · #E9EEF7'],
    ['text', 668, 984, 300, 44, 'H – Střelec 2', "47' Michna", 'Montserrat SemiBold · 34 px · #E9EEF7'],
    ['text', 668, 1044, 300, 44, 'H – Střelec 3', "70' Vu Minh", 'Montserrat SemiBold · 34 px · #E9EEF7'],
    ['text', 52, 1270, 200, 40, 'Web', 'fotbal-fm.cz', 'Montserrat SemiBold · 28 px · #8FA3C4'],
    ['img', 128, 378, 204, 204, 'Logo domácí', '', 'PNG s průhledností'],
    ['img', 748, 378, 204, 204, 'Logo hosté', '', 'PNG s průhledností'],
    ['bg', 0, 0, 1080, 1350, 'Pozadí', '', 'Texty a loga z pozadí odstraníme'],
    ['shape', 0, 670, 300, 120, 'Pruh domácí', '', '#13294F'],
    ['shape', 780, 670, 300, 120, 'Pruh hosté', '', '#3E8FD8'],
    ['shape', 506, 706, 68, 30, 'Tvar 3', '', '#FFFFFF']];
  S.tpAi = null;
  const aiOf = (pid) => {
    if (!S.tpAi || S.tpAi.pid !== pid) S.tpAi = { pid, src: aiPoster(), file: null, step: -1, sel: null, draw: false, segs: [], name: 'Výsledek zápasu', cat: null, clean: true };
    return S.tpAi;
  };
  const aiRun = (A) => { A.step = -1; A.segs = []; setTimeout(() => { if (S.tpAi !== A) return; A.segs = AI_SEED.map(([k, x, y, w, h, name, tx, info], i) => ({ id: 's' + i, k, x, y, w, h, name, tx, info, ai: true })); A.step = 0; aiRe(); toast(`AI našla ${A.segs.length} prvků — projděte je krok po kroku`); }, 1600); };
  const aiPid = () => { if (!S.route.startsWith('projekt-')) return null; const [pid, a, b] = S.route.slice(8).split('/'); return a === 'sablony' && b === 'import' ? pid : null; };
  const aiRe = () => { const pid = aiPid(); if (pid) renderTpAi(pid); };
  const aiCount = (A, k) => A.segs.filter((s) => s.k === k).length;
  function renderTpAi(pid) {
    const p = byId(pid); if (!p) { renderDetail(pid); return; } if (viewAs() === 'user') { location.replace(`#projekt-${pid}/sablony`); return; }
    const A = aiOf(pid), T = tpOf(pid), st = A.step, cur = AI_T[st];
    if (st === -1 && !A.segs.length && !A.running) { A.running = true; aiRun(A); }
    if (st >= 0) A.running = false;
    const steps = AI_T.map((t, i) => `<li class="ai-st${i === st ? ' on' : ''}${st > i || st === 4 ? ' done' : ''}" style="--c:${t.c}"><button type="button" data-aistep="${i}"${st < 0 ? ' disabled' : ''} aria-current="${i === st ? 'step' : 'false'}"><span class="ai-st-n">${st > i || st === 4 ? '<span class="i i-check s14" aria-hidden="true"></span>' : i + 1}</span><span class="ai-st-t">${t.n}</span>${st >= 0 ? `<span class="pd-n">${aiCount(A, t.k)}</span>` : ''}</button></li>`).join('') +
      `<li class="ai-st${st === 4 ? ' on' : ''}" style="--c:var(--text-2)"><button type="button" data-aistep="4"${st < 0 ? ' disabled' : ''}><span class="ai-st-n">5</span><span class="ai-st-t">Shrnutí</span></button></li>`;
    const sel = A.segs.find((s) => s.id === A.sel);
    $('#view').innerHTML = `<a href="#projekt-${esc(p.id)}/sablony" class="back"><span class="i i-arrow-left s16" aria-hidden="true"></span>Šablony</a>
      <div class="head"><div style="display:flex;flex-direction:column;gap:8px;min-width:0"><span class="lbl">Šablony · ${esc(p.name)}</span><h1 class="ai-h1">Šablona z obrázku <span class="tp-prem"><span class="i i-sparkle s14" aria-hidden="true"></span>Premium</span></h1></div>
        <div class="head-tools"><button type="button" class="btn sm tertiary" data-aiupload><span class="i i-upload s16" aria-hidden="true"></span>Jiný obrázek</button></div></div>
      <div class="alert c-info"><span class="i i-info" aria-hidden="true"></span><div style="flex:1"><div class="alert-d" style="margin:0">Prototyp: detekci ukazujeme na vzorové grafice${A.file ? ` (ne na souboru ${esc(A.file)})` : ''}. Ve skutečné aplikaci AI rozebere obrázek, který nahrajete.</div></div></div>
      <ol class="ai-steps" aria-label="Kroky">${steps}</ol>
      <div class="ai-wrap">
        <div class="ai-stage">
          <div class="ai-canvas${A.draw ? ' drawing' : ''}" style="aspect-ratio:${AI_W} / ${AI_H}" data-aicanvas>
            <img src="${A.src}" alt="Nahraný obrázek" draggable="false">
            ${st < 0 ? '<div class="ai-scan"><span class="ai-scan-bar"></span><span class="ai-scan-t"><span class="i i-sparkle s16" aria-hidden="true"></span>AI rozpoznává texty, obrázky, pozadí a tvary…</span></div>' : A.segs.map((s) => aiBox(s, A, st)).join('')}
          </div>
          ${st >= 0 && st < 4 ? `<div class="ai-legend">${AI_T.map((t) => `<span class="ai-leg${t.k === cur.k ? ' on' : ''}" style="--c:${t.c}"><i></i>${t.n}</span>`).join('')}</div>` : ''}
        </div>
        <aside class="ai-side">${st < 0 ? `<div class="ai-wait"><span class="ai-spin" aria-hidden="true"></span><b>Analyzuji obrázek</b><span class="cap">Obvykle to trvá pár sekund.</span></div>` : st === 4 ? aiSummary(A, T) : aiPanel(A, cur, sel)}</aside>
      </div>
      ${st >= 0 ? `<div class="ai-foot">${st > 0 ? `<button type="button" class="btn sm tertiary" data-aistep="${st - 1}"><span class="i i-arrow-left s16" aria-hidden="true"></span>Zpět</button>` : '<span></span>'}
        ${st < 4 ? `<button type="button" class="btn sm" data-aistep="${st + 1}">${st === 3 ? 'Pokračovat na shrnutí' : `Pokračovat na ${AI_T[st + 1].n.toLowerCase()}`}<span class="i i-arrow-right s16" aria-hidden="true"></span></button>` : `<button type="button" class="btn sm" data-aicreate><span class="i i-sparkle s16" aria-hidden="true"></span>Vytvořit šablonu v editoru</button>`}</div>` : ''}
      <input type="file" id="aiFile" accept="image/jpeg,image/png" hidden>`;
  }
  const aiBox = (s, A, st) => {
    const t = aiType(s.k), active = st === 4 || AI_T[st].k === s.k, on = A.sel === s.id;
    const pos = `left:${s.x / AI_W * 100}%;top:${s.y / AI_H * 100}%;width:${s.w / AI_W * 100}%;height:${s.h / AI_H * 100}%;--c:${t.c}`;
    return `<div class="ai-box ${s.k}${active ? ' act' : ''}${on ? ' sel' : ''}" style="${pos}" data-aiseg="${s.id}"${active && st < 4 ? '' : ' aria-hidden="true"'}>${active && st < 4 ? `<span class="ai-tag">${esc(s.name)}</span>${on ? '<span class="ai-h tl" data-aih="tl"></span><span class="ai-h tr" data-aih="tr"></span><span class="ai-h bl" data-aih="bl"></span><span class="ai-h br" data-aih="br"></span>' : ''}` : ''}</div>`;
  };
  function aiPanel(A, cur, sel) {
    const list = A.segs.filter((s) => s.k === cur.k);
    return `<div class="ai-ph" style="--c:${cur.c}"><span class="ai-ph-dot"></span><div><h2>${cur.n} <span class="pd-n">${list.length}</span></h2><span class="cap">${cur.d}</span></div></div>
      <button type="button" class="btn sm ${A.draw ? '' : 'tertiary'} ai-add" data-aidraw aria-pressed="${A.draw}"><span class="i i-plus s16" aria-hidden="true"></span>${A.draw ? 'Natáhněte rámeček v obrázku…' : `Označit ${cur.k === 'img' ? 'další obrázek' : cur.k === 'bg' ? 'další pozadí' : cur.k === 'shape' ? 'další tvar' : 'další text'}`}</button>
      <ul class="ai-list">${list.map((s) => { const on = s.id === A.sel; return `<li class="ai-it${on ? ' on' : ''}" data-aiit="${s.id}" style="--c:${cur.c}">
        <button type="button" class="ai-it-h" data-aipick="${s.id}" aria-expanded="${on}"><span class="ai-it-dot"></span><b>${esc(s.name)}</b>${s.ai ? '' : '<span class="ai-new">Přidáno ručně</span>'}${cur.k === 'text' ? `<span class="cap ai-it-tx">${esc(s.tx || '—')}</span>` : ''}<span class="i i-chev-down s14 ai-it-chev" aria-hidden="true"></span></button>
        ${on ? `<div class="ai-it-b">
          <label class="pal-f"><span>Název vrstvy</span><span class="inp sm"><input type="text" value="${esc(s.name)}" data-aif="name" maxlength="40"></span></label>
          ${cur.k === 'text' ? `<label class="pal-f"><span>Přepis textu</span><span class="inp sm"><input type="text" value="${esc(s.tx)}" data-aif="tx" maxlength="120"></span></label>` : ''}
          ${s.info ? `<span class="cap ai-info"><span class="i i-sparkle s14" aria-hidden="true"></span>${cur.k === 'text' ? 'Rozpoznáno: ' : cur.k === 'shape' ? 'Barva: ' : ''}${esc(s.info)}</span>` : ''}
          <div class="ai-it-a"><label class="pal-f ai-type"><span>Typ</span><span class="inp sm sel"><select data-aif="k">${AI_T.map((t) => `<option value="${t.k}"${t.k === s.k ? ' selected' : ''}>${t.n.replace(/y$/, 'a').replace('Texta', 'Text').replace('Obrázka', 'Obrázek').replace('Tvara', 'Tvar')}</option>`).join('')}</select><span class="i i-chev-down s14" aria-hidden="true"></span></span></label>
          <button type="button" class="btn sm ghost danger" data-aidel="${s.id}"><span class="i i-trash s16" aria-hidden="true"></span>Odebrat</button></div>
        </div>` : ''}</li>`; }).join('') || `<li class="cap ai-empty">AI tu nic nenašla. Pokud v obrázku ${cur.one} je, označte ho tlačítkem výše.</li>`}</ul>
      ${cur.k === 'bg' ? `<label class="man-page ai-clean"><input type="checkbox" role="switch" class="sr" data-aiclean${A.clean ? ' checked' : ''}><span class="sw${A.clean ? ' on' : ''}" aria-hidden="true"></span><span><b>Vyčistit pozadí</b><span class="cap">AI z pozadí odstraní texty, obrázky a tvary a dopočítá podklad pod nimi.</span></span></label>` : ''}`;
  }
  function aiSummary(A, T) {
    return `<div class="ai-ph" style="--c:var(--lav)"><span class="ai-ph-dot"></span><div><h2>Shrnutí</h2><span class="cap">Ze segmentů vytvoříme šablonu v editoru. Texty dostanou písma a barvy z projektu.</span></div></div>
      <ul class="ai-sum">${AI_T.map((t) => `<li style="--c:${t.c}"><span class="ai-it-dot"></span><b>${t.n}</b><span class="pd-n">${aiCount(A, t.k)}</span><button type="button" class="btn xs ghost" data-aistep="${AI_T.indexOf(t)}">Upravit</button></li>`).join('')}</ul>
      <label class="field"><span>Název šablony</span><span class="inp sm"><input type="text" value="${esc(A.name)}" data-ainame maxlength="60"></span></label>
      <label class="field"><span>Kategorie</span><span class="inp sm sel"><select data-aicat>${T.cats.map((c, i) => `<option value="${c.id}"${(A.cat || T.cats[0].id) === c.id ? ' selected' : ''}>${esc(c.name)}</option>`).join('')}</select><span class="i i-chev-down s14" aria-hidden="true"></span></span></label>
      <span class="cap ai-info"><span class="i i-info s14" aria-hidden="true"></span>Formát ${AI_W} × ${AI_H} px (4:5). Další rozměry přidáte v editoru tlačítkem Přidat rozměr.</span>`;
  }
  // events
  const aiPt = (cv, e) => { const r = cv.getBoundingClientRect(); return { x: Math.max(0, Math.min(AI_W, (e.clientX - r.left) / r.width * AI_W)), y: Math.max(0, Math.min(AI_H, (e.clientY - r.top) / r.height * AI_H)) }; };
  let aiDrag = null;
  $('#view').addEventListener('pointerdown', (e) => {
    const pid = aiPid(); if (!pid) return; const A = aiOf(pid); if (A.step < 0 || A.step > 3) return; const cv = e.target.closest('[data-aicanvas]'); if (!cv) return;
    const k = AI_T[A.step].k, p0 = aiPt(cv, e);
    if (A.draw) { e.preventDefault(); const s = { id: 'n' + Date.now().toString(36), k, x: p0.x, y: p0.y, w: 1, h: 1, name: `${aiType(k).n.replace(/y$/, '')} ${aiCount(A, k) + 1}`.replace('Text', 'Text').replace('Obrázk', 'Obrázek').replace('Tvar', 'Tvar').replace('Pozadí', 'Pozadí'), tx: '', info: '', ai: false };
      if (k === 'text') s.name = `Text ${aiCount(A, k) + 1}`; if (k === 'img') s.name = `Obrázek ${aiCount(A, k) + 1}`; if (k === 'shape') s.name = `Tvar ${aiCount(A, k) + 1}`; if (k === 'bg') s.name = `Pozadí ${aiCount(A, k) + 1}`;
      A.segs.push(s); A.sel = s.id; aiDrag = { mode: 'new', s, p0, cv }; cv.setPointerCapture(e.pointerId); renderBoxes(A); return; }
    const box = e.target.closest('.ai-box.act'); if (!box) { if (A.sel) { A.sel = null; aiRe(); } return; }
    const s = A.segs.find((x) => x.id === box.dataset.aiseg); e.preventDefault();
    if (A.sel !== s.id) { A.sel = s.id; aiRe(); }
    const h = e.target.closest('[data-aih]');
    aiDrag = { mode: h ? h.dataset.aih : 'move', s, p0, o: { x: s.x, y: s.y, w: s.w, h: s.h }, cv: $('[data-aicanvas]') }; aiDrag.cv.setPointerCapture(e.pointerId);
  });
  const renderBoxes = (A) => { const cv = $('[data-aicanvas]'); if (!cv) return; cv.querySelectorAll('.ai-box').forEach((b) => b.remove()); cv.insertAdjacentHTML('beforeend', A.segs.map((s) => aiBox(s, A, A.step)).join('')); };
  $('#view').addEventListener('pointermove', (e) => {
    if (!aiDrag) return; const A = S.tpAi, { s, p0, o, cv } = aiDrag, p = aiPt(cv, e), dx = p.x - p0.x, dy = p.y - p0.y;
    if (aiDrag.mode === 'new') { s.x = Math.min(p0.x, p.x); s.y = Math.min(p0.y, p.y); s.w = Math.abs(dx); s.h = Math.abs(dy); }
    else if (aiDrag.mode === 'move') { s.x = Math.max(0, Math.min(AI_W - o.w, o.x + dx)); s.y = Math.max(0, Math.min(AI_H - o.h, o.y + dy)); }
    else { const m = aiDrag.mode; let { x, y, w, h } = o; if (m.includes('l')) { x = Math.min(o.x + o.w - 10, o.x + dx); w = o.w - (x - o.x); } if (m.includes('r')) w = Math.max(10, o.w + dx); if (m.includes('t')) { y = Math.min(o.y + o.h - 10, o.y + dy); h = o.h - (y - o.y); } if (m.includes('b')) h = Math.max(10, o.h + dy); Object.assign(s, { x, y, w, h }); }
    const b = cv.querySelector(`[data-aiseg="${s.id}"]`); if (b) { b.style.left = s.x / AI_W * 100 + '%'; b.style.top = s.y / AI_H * 100 + '%'; b.style.width = s.w / AI_W * 100 + '%'; b.style.height = s.h / AI_H * 100 + '%'; }
  });
  $('#view').addEventListener('pointerup', () => {
    if (!aiDrag) return; const A = S.tpAi, d = aiDrag; aiDrag = null;
    if (d.mode === 'new') { A.draw = false; if (d.s.w < 12 || d.s.h < 12) { A.segs = A.segs.filter((x) => x !== d.s); A.sel = null; aiRe(); return; } aiRe(); toast(`Přidáno: ${d.s.name}`); document.querySelector(`[data-aiit="${d.s.id}"] input`)?.focus(); }
  });
  $('#view').addEventListener('click', (e) => {
    const pid = aiPid(); if (!pid) return; const A = aiOf(pid), T = tpOf(pid);
    const sb = e.target.closest('[data-aistep]'); if (sb && !sb.disabled) { A.step = Number(sb.dataset.aistep); A.sel = null; A.draw = false; aiRe(); $('.ai-steps [aria-current="step"]')?.focus(); return; }
    if (e.target.closest('[data-aidraw]')) { A.draw = !A.draw; A.sel = null; aiRe(); return; }
    const pk = e.target.closest('[data-aipick]'); if (pk) { A.sel = A.sel === pk.dataset.aipick ? null : pk.dataset.aipick; aiRe(); document.querySelector(`[data-aipick="${pk.dataset.aipick}"]`)?.focus(); return; }
    const dl = e.target.closest('[data-aidel]'); if (dl) { const s = A.segs.find((x) => x.id === dl.dataset.aidel); A.segs = A.segs.filter((x) => x !== s); A.sel = null; aiRe(); toast(`Odebráno: ${s.name}`); return; }
    if (e.target.closest('[data-aiupload]')) { $('#aiFile').click(); return; }
    if (e.target.closest('[data-aicreate]')) {
      const name = (A.name || '').trim() || 'Šablona z obrázku';
      const nid = 't' + Date.now().toString(36); T.items.unshift({ id: nid, uses: 0, name, cat: A.cat || T.cats[0].id, sync: false, doc: false, v: 0, exported: null, vars: [{ r: '4:5', src: A.src }] });
      const n = A.segs.length; S.tpAi = null; T.q = ''; location.hash = `projekt-${pid}/sablony/${nid}`; toast(`Šablona „${name}“ vytvořena z ${n} prvků`);
    }
  });
  $('#view').addEventListener('input', (e) => {
    const pid = aiPid(); if (!pid) return; const A = aiOf(pid);
    if (e.target.matches('[data-ainame]')) { A.name = e.target.value; return; }
    const f = e.target.closest('[data-aif]'); if (!f || f.tagName === 'SELECT') return; const s = A.segs.find((x) => x.id === f.closest('[data-aiit]').dataset.aiit); s[f.dataset.aif] = f.value;
    if (f.dataset.aif === 'name') { const t = document.querySelector(`[data-aiseg="${s.id}"] .ai-tag`); if (t) t.textContent = f.value; const h = f.closest('[data-aiit]').querySelector('.ai-it-h b'); if (h) h.textContent = f.value; }
    if (f.dataset.aif === 'tx') { const h = f.closest('[data-aiit]').querySelector('.ai-it-tx'); if (h) h.textContent = f.value || '—'; }
  });
  $('#view').addEventListener('change', (e) => {
    const pid = aiPid(); if (!pid) return; const A = aiOf(pid);
    if (e.target.matches('[data-aicat]')) { A.cat = e.target.value; return; }
    if (e.target.matches('[data-aiclean]')) { A.clean = e.target.checked; aiRe(); return; }
    if (e.target.matches('[data-aif="k"]')) { const s = A.segs.find((x) => x.id === e.target.closest('[data-aiit]').dataset.aiit); s.k = e.target.value; A.sel = null; aiRe(); toast(`„${s.name}“ přesunuto do kroku ${aiType(s.k).n}`); return; }
    if (e.target.id === 'aiFile') { const f = e.target.files[0]; e.target.value = ''; if (!f) return; if (!/^image\/(jpeg|png)$/.test(f.type)) { toast('Nahrajte JPG nebo PNG', true); return; } aiStart(pid, f); }
  });
  document.addEventListener('keydown', (e) => {
    const pid = aiPid(); if (!pid || !S.tpAi || !S.tpAi.sel || e.target.closest('input, select, textarea')) return;
    if (e.key === 'Delete' || e.key === 'Backspace') { const A = S.tpAi, s = A.segs.find((x) => x.id === A.sel); A.segs = A.segs.filter((x) => x !== s); A.sel = null; aiRe(); toast(`Odebráno: ${s.name}`); }
    if (e.key === 'Escape') { S.tpAi.sel = null; S.tpAi.draw = false; aiRe(); }
  });
  // entry from the "Nová šablona" dialog
  function aiStart(pid, file) { S.tpAi = null; const A = aiOf(pid); A.file = file ? file.name : null; A.name = file ? file.name.replace(/\.[^.]+$/, '').slice(0, 40) : 'Výsledek zápasu'; if (location.hash === `#projekt-${pid}/sablony/import`) aiRe(); else location.hash = `projekt-${pid}/sablony/import`; }
  /* ---------- Editor šablony — #projekt-<id>/sablony/<templateId> (opened by clicking any template) ----------
     Full-width workspace (app menu collapsed to icons): top bar (back, name, undo/redo, Zobrazení, Nápověda, Vyplnit a exportovat, Uložit + save state),
     variant bar (format tabs with "mimo plátno" warning, + Přidat rozměr, switch "Upravovat všechny varianty najednou"), left panel (Přidat:
     Text / Obrázek / Tvar / Seznam / Pozadí; tabs Vrstvy (lock, eye, client-input dot, drag to reorder) and Kontejnery), canvas with rulers,
     client-input labels, selection handles and a floating zoom bar, right panel with properties of the selection (or of the canvas when nothing
     is selected). Keys: Delete, arrows (Shift = 10 px), Ctrl/Cmd+Z / Shift+Z, Esc. Prototype: one sample template (football result). */
  const ED_FONTS = ['Montserrat', 'Outfit', 'Inter'];
  const ED_W = { 400: 'Regular', 600: 'SemiBold', 700: 'Bold', 800: 'ExtraBold' };
  const ED_PRESETS = [['1:1', 1080, 1080], ['16:9', 1920, 1080], ['4:3', 1440, 1080], ['A4', 2480, 3508]];
  S.ted = {};
  const edCrest = (c1, c2, t) => 'data:image/svg+xml;utf8,' + encodeURIComponent(`<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 200 200'><circle cx='100' cy='100' r='96' fill='${c1}'/><circle cx='100' cy='100' r='78' fill='none' stroke='${c2}' stroke-width='10'/><path d='M66 126 L100 60 L134 126z' fill='${c2}'/><text x='100' y='162' text-anchor='middle' font-family='Outfit, Arial, sans-serif' font-size='22' font-weight='800' fill='${c2}'>${t}</text></svg>`);
  const edBg = (w, h) => 'data:image/svg+xml;utf8,' + encodeURIComponent(`<svg xmlns='http://www.w3.org/2000/svg' width='${w}' height='${h}' viewBox='0 0 ${w} ${h}' preserveAspectRatio='xMidYMid slice'><rect width='${w}' height='${h}' fill='#0E1E3A'/>${Array.from({ length: 12 }, (_, i) => `<rect x='${-600 + i * 210}' y='-400' width='70' height='${h + 800}' fill='#fff' opacity='.035' transform='rotate(24 ${w / 2} ${h / 2})'/>`).join('')}<circle cx='${w - 180}' cy='160' r='260' fill='#3E8FD8' opacity='.10'/></svg>`);
  function tedOf(pid, tid) {
    const key = pid + '/' + tid; if (S.ted[key]) return S.ted[key];
    const T = tpOf(pid), t = T.items.find((x) => x.id === tid) || { name: 'Šablona', sync: false }, id = (() => { let n = 0; return () => 'l' + (n++); })();
    const L = (k, name, x, y, w, h, o) => ({ id: id(), k, name, x, y, w, h, lock: false, hide: false, input: false, ...o });
    const txt = (name, x, y, w, h, tx, fs, fw, color, align, input) => L('text', name, x, y, w, h, { tx, fs, fw, color, align, font: 'Montserrat', input });
    const layers = t.blank ? [L('text', 'Nadpis', 120, 560, 840, 140, { tx: 'Nadpis šablony', fs: 96, fw: 800, color: '#FFFFFF', align: 'center', font: 'Montserrat', input: true }), L('bg', 'Pozadí', 0, 0, 1080, 1350, { src: '', lock: true })] : [
      txt('Kategorie', 120, 92, 840, 140, 'DOROST U19', 132, 800, '#3E8FD8', 'center', true),
      txt('Konec utkání', 395, 452, 290, 54, 'KONEC UTKÁNÍ', 40, 700, '#E9EEF7', 'center', false),
      L('img', 'Logo domácí', 128, 378, 204, 204, { src: edCrest('#F4C430', '#0E1E3A', 'FKS'), fit: 'contain', input: true }),
      L('img', 'Logo hosté', 748, 378, 204, 204, { src: edCrest('#E9EEF7', '#1A3866', 'FFM'), fit: 'contain', input: true }),
      txt('Skóre domácí', 330, 630, 160, 200, '2', 230, 800, '#FFFFFF', 'center', true),
      txt('Pomlčka', 505, 690, 70, 60, '–', 80, 800, '#FFFFFF', 'center', false),
      txt('Skóre hosté', 590, 630, 160, 200, '1', 230, 800, '#FFFFFF', 'center', true),
      L('shape', 'Pruh domácí', 0, 670, 300, 120, { color: '#13294F', radius: 0, cc: true }),
      L('shape', 'Pruh hosté', 780, 670, 300, 120, { color: '#3E8FD8', radius: 0, cc: true }),
      ...["23' Kovalčík", "34' Janošec", "61' Šigut", "88' Poruba"].map((s, i) => txt(`D – Střelec ${i + 1}`, 0, 0, 340, 44, s, 34, 600, '#E9EEF7', 'left', true)),
      ...["12' Mucha", "47' Michna", "70' Vu Minh", "90' Krpec"].map((s, i) => txt(`H – Střelec ${i + 1}`, 0, 0, 340, 44, s, 34, 600, '#E9EEF7', 'right', true)),
      txt('Web', 60, 1268, 300, 40, 'fotbal-fm.cz', 28, 600, '#8FA3C4', 'left', false),
      L('bg', 'Pozadí', 0, 0, 1080, 1350, { src: '', lock: true })];
    const ids = (pre) => layers.filter((l) => l.name.startsWith(pre)).map((l) => l.id);
    const conts = [{ id: 'c0', name: 'Střelci domácí', x: 112, y: 924, w: 340, rowH: 44, gap: 16, items: ids('D – Střelec'), grow: true }, { id: 'c1', name: 'Střelci hosté', x: 628, y: 924, w: 340, rowH: 44, gap: 16, items: ids('H – Střelec'), grow: true }].filter((c) => c.items.length);
    layers.forEach((l) => { const c = conts.find((x) => x.items.includes(l.id)); if (c) l.cont = c.id; });
    return (S.ted[key] = { pid, tid, name: t.name, sync: t.sync, vars: t.blank ? [{ r: '4:5', w: 1080, h: 1350 }] : [{ r: '4:5', w: 1080, h: 1350 }, { r: '9:16', w: 1080, h: 1920 }], cur: 0, multi: true, zoom: 0, sel: null, tab: 'layers', layers, conts, saved: true, hist: [], fut: [], view: { labels: true, rulers: true, outside: true, mini: true } });
  }
  const edPath = () => { if (!S.route.startsWith('projekt-')) return null; const [pid, a, tid, x] = S.route.slice(8).split('/'); return a === 'sablony' && tid && tid !== 'import' && !x ? { pid, tid } : null; };
  const edCur = () => { const pp = edPath(); return pp ? tedOf(pp.pid, pp.tid) : null; };
  const edV = (E) => E.vars[E.cur];
  // geometry: base coordinates are for 1080 × 1350; other formats centre the design vertically, per-format offsets when editing one format only
  const edBox = (E, l) => {
    const V = edV(E), dy = (V.h - 1350) / 2, dx = (V.w - 1080) / 2, o = (l.ov && l.ov[V.r]) || { dx: 0, dy: 0 };
    if (l.k === 'bg') return { x: 0, y: 0, w: V.w, h: V.h };
    if (l.cont) { const c = E.conts.find((x) => x.id === l.cont), i = c.items.indexOf(l.id), co = (c.ov && c.ov[V.r]) || { dx: 0, dy: 0 }; return { x: c.x + dx + co.dx, y: c.y + dy + co.dy + i * (c.rowH + c.gap), w: c.w, h: c.rowH }; }
    return { x: l.x + dx + o.dx, y: l.y + dy + o.dy, w: l.w, h: l.h };
  };
  const edContBox = (E, c) => { const V = edV(E), co = (c.ov && c.ov[V.r]) || { dx: 0, dy: 0 }; return { x: c.x + (V.w - 1080) / 2 + co.dx, y: c.y + (V.h - 1350) / 2 + co.dy, w: c.w, h: c.items.length * (c.rowH + c.gap) - c.gap }; };
  const edOut = (E, V) => { const keep = E.cur; E.cur = E.vars.indexOf(V); const n = E.layers.filter((l) => l.k !== 'bg' && !l.hide).filter((l) => { const b = edBox(E, l); return b.x < -2 || b.y < -2 || b.x + b.w > V.w + 2 || b.y + b.h > V.h + 2; }).length; E.cur = keep; return n; };
  const edSnap = (E) => { E.hist.push(JSON.stringify({ layers: E.layers, conts: E.conts, vars: E.vars })); if (E.hist.length > 60) E.hist.shift(); E.fut = []; E.saved = false; };
  const edRestore = (E, s) => { const o = JSON.parse(s); E.layers = o.layers; E.conts = o.conts; E.vars = o.vars; if (E.cur >= E.vars.length) E.cur = 0; };
  const edIco = { text: 'i-type', img: 'i-image', shape: 'i-shape', bg: 'i-layers', list: 'i-list' };
  function renderTpEd(pid, tid) {
    const p = byId(pid); if (!p) { renderDetail(pid); return; }
    if (viewAs() === 'user') { location.replace(`#projekt-${pid}/sablony`); return; }
    const T = tpOf(pid); if (!T.items.some((x) => x.id === tid)) { location.replace(`#projekt-${pid}/sablony`); return; }
    if (!document.body.classList.contains('ed-on')) { const app = $('.app'); edPrevCol = app.classList.contains('collapsed'); app.classList.add('collapsed'); } document.body.classList.add('ed-on');
    const E = tedOf(pid, tid), V = edV(E), sel = E.layers.find((l) => l.id === E.sel) || null, selC = E.conts.find((c) => c.id === E.sel) || null;
    $('#view').innerHTML = `<div class="ed" data-ed>
      <header class="ed-top">
        <a class="btn icon sm tertiary" href="#projekt-${esc(pid)}/sablony" aria-label="Zpět na šablony" title="Zpět na šablony"><span class="i i-arrow-left s16" aria-hidden="true"></span></a>
        <div class="ed-title"><span class="lbl">${esc(p.name)} · Šablony</span><input class="ed-name" value="${esc(E.name)}" aria-label="Název šablony" data-edname maxlength="60"></div>
        ${E.sync ? '<span class="pill ed-sync"><span class="i i-refresh s14" aria-hidden="true"></span>Synchronizovaná</span>' : ''}
        <div class="ed-hist"><button type="button" class="btn icon sm ghost" data-edundo aria-label="Zpět (Ctrl+Z)" title="Zpět (Ctrl+Z)"${E.hist.length ? '' : ' disabled'}><span class="i i-undo s16" aria-hidden="true"></span></button><button type="button" class="btn icon sm ghost" data-edredo aria-label="Znovu (Ctrl+Shift+Z)" title="Znovu"${E.fut.length ? '' : ' disabled'}><span class="i i-redo s16" aria-hidden="true"></span></button><button type="button" class="btn icon sm ed-clean${E.clean ? ' act' : ''}" data-edclean aria-pressed="${!!E.clean}" aria-label="${E.clean ? 'Zobrazit pomocné prvky' : 'Skrýt všechny pomocné prvky'}" data-tip="${E.clean ? 'Zobrazit pomocné prvky' : 'Čistý náhled — skrýt pomocné prvky'}"><span class="i ${E.clean ? 'i-eye-off' : 'i-eye'} s16" aria-hidden="true"></span></button></div>
        <span class="ed-sp"></span>
        <span class="ed-state${E.saved ? '' : ' dirty'}"><span class="i ${E.saved ? 'i-check-circle' : 'i-history'} s14" aria-hidden="true"></span>${E.saved ? 'Vše uloženo' : 'Neuložené změny'}</span>
        <button type="button" class="btn sm ghost" data-edviewm aria-haspopup="menu"><span class="i i-layers s16" aria-hidden="true"></span>Zobrazení</button>
        <button type="button" class="btn sm tertiary" data-edfill><span class="i i-download s16" aria-hidden="true"></span>Vyplnit a exportovat</button>
        <button type="button" class="btn sm ed-save" data-edsave${E.saved ? ' disabled' : ''}>Uložit</button>
      </header>
      <div class="ed-vars" role="tablist" aria-label="Formáty šablony">
        ${E.vars.map((v, i) => { const n = edOut(E, v); return `<button type="button" role="tab" class="ed-var${i === E.cur ? ' on' : ''}" data-edvar="${i}" aria-selected="${i === E.cur}"><b>${v.r}</b><span class="cap">${v.w} × ${v.h}</span>${n ? `<span class="ed-out" title="${n} prvků přesahuje plátno"><span class="i i-alert s12" aria-hidden="true"></span>${n} mimo plátno</span>` : ''}</button>${i === E.cur && E.vars.length > 1 ? `<button type="button" class="ed-var-x" data-eddelvar aria-label="Odebrat formát ${v.r}" title="Odebrat formát ${v.r}"><span class="i i-x s12" aria-hidden="true"></span></button>` : ''}`; }).join('')}
        <button type="button" class="ed-var add" data-edaddvar aria-haspopup="menu"><span class="i i-plus s14" aria-hidden="true"></span>Přidat rozměr</button>
        <span class="ed-sp"></span>
        <label class="ed-multi"><input type="checkbox" role="switch" class="sr" data-edmulti${E.multi ? ' checked' : ''}><span class="sw${E.multi ? ' on' : ''}" aria-hidden="true"></span><span>Upravovat všechny formáty najednou</span></label>
      </div>
      <div class="ed-tools" role="toolbar" aria-label="Nástroje">${[['text', 'i-type', 'Přidat text'], ['img', 'i-image', 'Přidat obrázek'], ['shape', 'i-shape', 'Přidat tvar'], ['list', 'i-list', 'Přidat zaškrtávací seznam'], ['bgset', 'i-layers', 'Pozadí']].map(([k, i, t]) => `<button type="button" class="ed-tool" data-edadd="${k}" aria-label="${t}" data-tip="${t}"><span class="i ${i} s18" aria-hidden="true"></span></button>`).join('')}<span class="ed-sp"></span><div class="ed-zoom" role="group" aria-label="Přiblížení"><button type="button" class="btn icon xs ghost" data-edzoom="-" aria-label="Oddálit"><span class="i i-minus s14" aria-hidden="true"></span></button><button type="button" class="ed-zv" data-edzoom="fit" title="Přizpůsobit oknu">${Math.round(edZ(E) * 100)} %</button><button type="button" class="btn icon xs ghost" data-edzoom="+" aria-label="Přiblížit"><span class="i i-plus s14" aria-hidden="true"></span></button></div></div>
      <div class="ed-body${sel || selC ? '' : ' no-sel'}">
        <aside class="ed-left" aria-label="Prvky a vrstvy">
          <div class="tabs-u ed-tabs" role="tablist"><button type="button" role="tab" class="${E.tab === 'layers' ? 'on' : ''}" aria-selected="${E.tab === 'layers'}" data-edtab="layers">Vrstvy <span class="pd-n">${E.layers.length}</span></button><button type="button" role="tab" class="${E.tab === 'conts' ? 'on' : ''}" aria-selected="${E.tab === 'conts'}" data-edtab="conts">Kontejnery <span class="pd-n">${E.conts.length}</span></button></div>
          ${E.tab === 'layers' ? `<ul class="ed-layers">${E.layers.map((l) => `<li class="ed-ly${l.id === E.sel || edMs(E).includes(l.id) ? ' on' : ''}${l.hide ? ' off' : ''}" data-edly="${l.id}"${l.k === 'bg' ? '' : ' draggable="true"'}>
            <button type="button" class="ed-ly-n" data-edpick="${l.id}" title="Dvojklikem přejmenujete"><span class="i ${edIco[l.k]} s12" aria-hidden="true"></span><span class="ed-ly-t">${esc(l.name)}</span>${l.input ? '<span class="ed-in" title="Vyplňuje klient" aria-label="Vyplňuje klient"></span>' : ''}${l.cont ? '<span class="i i-list s12 ed-ly-c" title="V kontejneru" aria-hidden="true"></span>' : ''}</button>
            <button type="button" class="ed-ly-b${l.lock ? ' act' : ''}" data-edlock="${l.id}" aria-pressed="${l.lock}" aria-label="${l.lock ? 'Odemknout' : 'Zamknout'} ${esc(l.name)}" title="${l.lock ? 'Zamčeno' : 'Zamknout'}"><span class="i ${l.lock ? 'i-lock' : 'i-unlock'} s12" aria-hidden="true"></span></button>
            <button type="button" class="ed-ly-b${l.hide ? ' act' : ''}" data-edhide="${l.id}" aria-pressed="${l.hide}" aria-label="${l.hide ? 'Zobrazit' : 'Skrýt'} ${esc(l.name)}" title="${l.hide ? 'Skryto' : 'Skrýt'}"><span class="i ${l.hide ? 'i-eye-off' : 'i-eye'} s12" aria-hidden="true"></span></button>
          </li>`).join('')}</ul>` : `<ul class="ed-layers">${E.conts.map((c) => `<li class="ed-ly${c.id === E.sel ? ' on' : ''}" data-edly="${c.id}"><button type="button" class="ed-ly-n" data-edpick="${c.id}"><span class="i i-list s14" aria-hidden="true"></span><span class="ed-ly-t">${esc(c.name)}</span><span class="cap">${c.items.length} řádky</span></button></li>`).join('')}</ul><p class="cap ed-hint">Kontejner řadí texty pod sebe s pevnou mezerou. Když klient vyplní méně řádků, ostatní se posunou.</p>`}
        </aside>
        <section class="ed-stage" aria-label="Plátno">
          <div class="ed-scroll" data-edscroll>${edCanvas(E)}</div>
          ${E.view.mini ? edMini(E) : ''}
          <div class="ed-mobile"><span class="i i-info s16" aria-hidden="true"></span>Editor šablon je navržený pro počítač. Na telefonu si šablonu jen prohlédnete.</div>
        </section>
        ${sel || selC ? `<aside class="ed-right" aria-label="Vlastnosti">${sel ? edProps(E, sel, p) : edContProps(E, selC)}</aside>` : ''}
      </div>
      <input type="file" id="edFile" accept="image/*" hidden>
    </div>`;
  }
  // multi-select (Shift + click on the canvas or in Vrstvy) — floating align toolbar above the group
  const edMs = (E) => { E.msel = (E.msel || []).filter((id) => E.layers.some((l) => l.id === id)); return E.msel.length > 1 ? E.msel : []; };
  const edItems = (E) => { const out = [], seen = new Set(); edMs(E).forEach((id) => { const l = E.layers.find((x) => x.id === id); if (!l || l.k === 'bg' || l.hide) return;
    if (l.cont) { if (seen.has(l.cont)) return; seen.add(l.cont); const c = E.conts.find((x) => x.id === l.cont); out.push({ o: c, isC: true, b: edContBox(E, c), lock: false }); } else out.push({ o: l, isC: false, b: edBox(E, l), lock: l.lock }); }); return out; };
  const edGB = (it) => { const x = Math.min(...it.map((i) => i.b.x)), y = Math.min(...it.map((i) => i.b.y)); return { x, y, w: Math.max(...it.map((i) => i.b.x + i.b.w)) - x, h: Math.max(...it.map((i) => i.b.y + i.b.h)) - y }; };
  const ED_AL = [['l', 'i-alh-l', 'Zarovnat vlevo'], ['c', 'i-alh-c', 'Zarovnat na střed vodorovně'], ['r', 'i-alh-r', 'Zarovnat vpravo'], '-', ['t', 'i-alv-t', 'Zarovnat nahoru'], ['m', 'i-alv-m', 'Zarovnat na střed svisle'], ['b', 'i-alv-b', 'Zarovnat dolů'], '-', ['dh', 'i-dist-h', 'Rozmístit vodorovně se stejnými mezerami'], ['dv', 'i-dist-v', 'Rozmístit svisle se stejnými mezerami'], '-', ['front', 'i-front', 'Přenést dopředu'], ['back', 'i-back', 'Poslat dozadu'], '-', ['del', 'i-trash', 'Smazat vybrané']];
  const edGroup = (E, z) => { const it = edItems(E); if (it.length < 2) return ''; const g = edGB(it), n = edMs(E).length, top = g.y * z > 64;
    return `<div class="ed-gbox" style="left:${g.x * z - 4}px;top:${g.y * z - 4}px;width:${g.w * z + 8}px;height:${g.h * z + 8}px"></div><div class="ed-gtb${top ? '' : ' below'}" role="toolbar" aria-label="Zarovnání ${n} vybraných prvků" style="left:${Math.max(240, Math.min(edV(E).w * z - 240, (g.x + g.w / 2) * z))}px;top:${top ? g.y * z - 12 : (g.y + g.h) * z + 12}px"><span class="ed-gtb-n">${n}</span>${ED_AL.map((a) => a === '-' ? '<span class="ed-gtb-d" aria-hidden="true"></span>' : `<button type="button" class="ed-gtb-b${a[0] === 'del' ? ' danger' : ''}" data-edal="${a[0]}" aria-label="${a[2]}" data-tip="${a[2]}"${(a[0] === 'dh' || a[0] === 'dv') && it.length < 3 ? ' disabled' : ''}><span class="i ${a[1]} s16" aria-hidden="true"></span></button>`).join('')}</div>`; };
  function edAlign(E, a) {
    const it = edItems(E).filter((i) => !i.lock); if (!it.length) return; edSnap(E); const g = edGB(it);
    if (a === 'front' || a === 'back') { const ids = edMs(E), moving = E.layers.filter((l) => ids.includes(l.id) && l.k !== 'bg'), rest = E.layers.filter((l) => !moving.includes(l)), bg = rest.filter((l) => l.k === 'bg'), mid = rest.filter((l) => l.k !== 'bg'); E.layers = a === 'front' ? moving.concat(mid, bg) : mid.concat(moving, bg); edRe(); toast(a === 'front' ? 'Přeneseno dopředu' : 'Posláno dozadu'); return; }
    if (a === 'dh' || a === 'dv') { const H = a === 'dh', s = it.slice().sort((p, q) => H ? p.b.x - q.b.x : p.b.y - q.b.y), tot = s.reduce((t, i) => t + (H ? i.b.w : i.b.h), 0), gap = ((H ? g.w : g.h) - tot) / (s.length - 1); let cur = H ? g.x : g.y;
      s.forEach((i) => { edSetBox(E, i.o, i.isC, H ? { ...i.b, x: Math.round(cur) } : { ...i.b, y: Math.round(cur) }); cur += (H ? i.b.w : i.b.h) + gap; }); edRe(); return; }
    it.forEach((i) => { const b = { ...i.b }; if (a === 'l') b.x = g.x; if (a === 'c') b.x = Math.round(g.x + (g.w - b.w) / 2); if (a === 'r') b.x = g.x + g.w - b.w; if (a === 't') b.y = g.y; if (a === 'm') b.y = Math.round(g.y + (g.h - b.h) / 2); if (a === 'b') b.y = g.y + g.h - b.h; edSetBox(E, i.o, i.isC, b); }); edRe();
  }
  const edToggleMs = (E, id) => { const cur = new Set(edMs(E).length ? E.msel : E.sel && E.layers.some((l) => l.id === E.sel) ? [E.sel] : []); if (cur.has(id)) cur.delete(id); else cur.add(id); const a = [...cur]; if (a.length > 1) { E.msel = a; E.sel = null; } else { E.msel = []; E.sel = a[0] || null; } edRe(); };
  let edFitZ = 0.44, edPrevCol = null;
  const edLeave = () => { if (!document.body.classList.contains('ed-on')) return; document.body.classList.remove('ed-on'); if (edPrevCol === false) $('.app').classList.remove('collapsed'); edPrevCol = null; };
  const edZ = (E) => E.zoom || edFitZ;
  const edCanvas = (E) => {
    const V = edV(E), z = edZ(E), ru = E.view.rulers && !E.clean, step = 100;
    const ticksX = Array.from({ length: Math.floor(V.w / step) + 1 }, (_, i) => `<span style="left:${i * step * z}px">${i % 2 ? '' : i * step}</span>`).join('');
    const ticksY = Array.from({ length: Math.floor(V.h / step) + 1 }, (_, i) => `<span style="top:${i * step * z}px">${i % 2 ? '' : i * step}</span>`).join('');
    const els = E.layers.slice().reverse().filter((l) => !l.hide).map((l) => { const b = edBox(E, l), st = `left:${b.x}px;top:${b.y}px;width:${b.w}px;height:${b.h}px`;
      if (l.k === 'bg') return `<img class="ed-el bg" src="${l.src || edBg(V.w, V.h)}" alt="" style="${st}" draggable="false">`;
      if (l.k === 'img') return `<img class="ed-el" src="${l.src}" alt="" style="${st};object-fit:${l.fit}" draggable="false">`;
      if (l.k === 'shape') return `<span class="ed-el" style="${st};background:${l.color};border-radius:${l.radius}px"></span>`;
      if (l.k === 'list') return `<span class="ed-el ed-list" style="${st};font-size:${l.fs}px;color:${l.color}">${l.tx.split('\n').map((r) => `<span><i></i>${esc(r)}</span>`).join('')}</span>`;
      return `<span class="ed-el ed-tx" style="${st};font-family:'${l.font}',Outfit,Arial,sans-serif;font-size:${l.fs}px;font-weight:${l.fw};color:${l.color};justify-content:${l.align === 'left' ? 'flex-start' : l.align === 'right' ? 'flex-end' : 'center'};text-align:${l.align}">${esc(l.tx)}</span>`; }).join('');
    const ms = edMs(E), marks = E.layers.filter((l) => !l.hide && l.k !== 'bg').map((l) => { const b = edBox(E, l), on = l.id === E.sel;
      return `<div class="ed-hit${on ? ' sel' : ''}${ms.includes(l.id) ? ' msel' : ''}${l.input && E.view.labels ? ' inp' : ''}${l.lock ? ' locked' : ''}" data-edhit="${l.id}" style="left:${b.x * z}px;top:${b.y * z}px;width:${b.w * z}px;height:${b.h * z}px">${(l.input && E.view.labels && !l.cont) || on ? `<span class="ed-tag">${l.lock ? '<span class="i i-lock s12" aria-hidden="true"></span>' : ''}${esc(l.name)}</span>` : ''}${on && !l.lock ? '<span class="ed-h tl" data-edh="tl"></span><span class="ed-h tr" data-edh="tr"></span><span class="ed-h bl" data-edh="bl"></span><span class="ed-h br" data-edh="br"></span>' : ''}</div>`; }).join('')
      + E.conts.map((c) => { const b = edContBox(E, c), on = c.id === E.sel; return `<div class="ed-cont${on ? ' sel' : ''}" data-edcont="${c.id}" style="left:${b.x * z - 6}px;top:${b.y * z - 6}px;width:${b.w * z + 12}px;height:${b.h * z + 12}px"><span class="ed-ctag"><span class="i i-list s12" aria-hidden="true"></span>${esc(c.name)} · mezera ${c.gap} px</span></div>`; }).join('') + edGroup(E, z);
    E._els = els;
    return `<div class="ed-canvas${ru ? ' ru' : ''}${E.clean ? ' clean' : ''}" style="--z:${z}">
      ${ru ? `<div class="ed-ru x" style="width:${V.w * z}px">${ticksX}</div><div class="ed-ru y" style="height:${V.h * z}px">${ticksY}</div>` : ''}
      <div class="ed-board-w${E.view.outside ? ' show-out' : ''}" style="width:${V.w * z}px;height:${V.h * z}px" data-edboard>
        <div class="ed-board" style="width:${V.w}px;height:${V.h}px;transform:scale(${z})">${els}</div>
        <div class="ed-marks">${marks}</div>
      </div></div>`;
  };
  const edSw = (p, cur, key) => `<div class="ed-sws">${(p.colors && p.colors.length ? p.colors : ['#0E1E3A', '#3E8FD8', '#FFFFFF']).concat(['#FFFFFF', '#000000']).filter((c, i, a) => a.indexOf(c) === i).map((c) => `<button type="button" class="ed-swb${c.toUpperCase() === String(cur).toUpperCase() ? ' on' : ''}" style="background:${c}" data-edcolor="${c}" data-edkey="${key}" aria-label="Barva ${c}" title="${c}"></button>`).join('')}<label class="inp sm ed-hex"><span class="ed-hex-d" style="background:${cur}"></span><input value="${esc(String(cur).toUpperCase())}" data-edf="${key}" maxlength="7" aria-label="HEX"></label></div>`;
  const edNum = (label, key, v, unit) => `<label class="pal-f ed-num"><span>${label}</span><span class="inp sm"><input type="number" value="${Math.round(v)}" data-edf="${key}">${unit ? `<i>${unit}</i>` : ''}</span></label>`;
  function edProps(E, l, p) {
    const b = edBox(E, l), V = edV(E), inCont = !!l.cont;
    return `<div class="ed-ph"><span class="ed-ph-i"><span class="i ${edIco[l.k]} s16" aria-hidden="true"></span></span><input class="ed-ph-n" value="${esc(l.name)}" data-edf="name" aria-label="Název vrstvy" maxlength="40"><button type="button" class="btn icon xs ghost" data-edpick="" aria-label="Zrušit výběr"><span class="i i-x s14" aria-hidden="true"></span></button></div>
      ${l.k !== 'bg' && l.k !== 'shape' ? `<label class="man-page ed-input"><input type="checkbox" role="switch" class="sr" data-edf="input"${l.input ? ' checked' : ''}><span class="sw${l.input ? ' on' : ''}" aria-hidden="true"></span><span><b>Vyplňuje klient</b><span class="cap">${l.k === 'img' ? 'Klient sem při exportu vloží vlastní obrázek.' : 'Klient tento text při exportu přepíše.'}</span></span></label>` : ''}
      ${l.k === 'text' ? `<section class="ed-sec"><h3>Text</h3><label class="pal-f"><span>Obsah${l.input ? ' (výchozí)' : ''}</span><textarea class="inp ed-ta" rows="2" data-edf="tx">${esc(l.tx)}</textarea></label>
        <div class="ed-2"><label class="pal-f"><span>Písmo</span><span class="inp sm sel"><select data-edf="font">${ED_FONTS.concat((p.fonts || []).map((f) => f.name || f).filter((n) => typeof n === 'string')).filter((x, i, a) => a.indexOf(x) === i).map((f) => `<option${f === l.font ? ' selected' : ''}>${esc(f)}</option>`).join('')}</select><span class="i i-chev-down s14" aria-hidden="true"></span></span></label>
        <label class="pal-f"><span>Řez</span><span class="inp sm sel"><select data-edf="fw">${Object.entries(ED_W).map(([w, n]) => `<option value="${w}"${Number(w) === l.fw ? ' selected' : ''}>${n}</option>`).join('')}</select><span class="i i-chev-down s14" aria-hidden="true"></span></span></label></div>
        <div class="ed-2">${edNum('Velikost', 'fs', l.fs, 'px')}<div class="pal-f"><span>Zarovnání</span><div class="seg soft ed-al" role="group" aria-label="Zarovnání">${[['left', 'Vlevo'], ['center', 'Na střed'], ['right', 'Vpravo']].map(([a, n]) => `<button type="button" class="${l.align === a ? 'on' : ''}" aria-pressed="${l.align === a}" data-edalign="${a}" aria-label="${n}" title="${n}"><span class="i i-al-${a} s14" aria-hidden="true"></span></button>`).join('')}</div></div></div>
        <div class="pal-f"><span>Barva z palety projektu</span>${edSw(p, l.color, 'color')}</div></section>` : ''}
      ${l.k === 'img' ? `<section class="ed-sec"><h3>Obrázek</h3><div class="ed-imgp"><img src="${l.src}" alt=""></div><div class="ed-2"><button type="button" class="btn sm tertiary" data-edimg><span class="i i-upload s16" aria-hidden="true"></span>Nahradit</button><button type="button" class="btn sm tertiary" data-edgal><span class="i i-image s16" aria-hidden="true"></span>Z galerie</button></div>
        <div class="pal-f"><span>Přizpůsobení</span><div class="seg soft ed-fit" role="group">${[['contain', 'Celý obrázek'], ['cover', 'Vyplnit rámeček']].map(([f, n]) => `<button type="button" class="${l.fit === f ? 'on' : ''}" aria-pressed="${l.fit === f}" data-edfit="${f}">${n}</button>`).join('')}</div></div></section>` : ''}
      ${l.k === 'shape' ? `<label class="man-page ed-input"><input type="checkbox" role="switch" class="sr" data-edf="cc"${l.cc ? ' checked' : ''}><span class="sw${l.cc ? ' on' : ''}" aria-hidden="true"></span><span><b>Klient může změnit barvu</b><span class="cap">Při vyplnění vybere barvu z palety projektu.</span></span></label>` : ''}${l.k === 'shape' ? `<section class="ed-sec"><h3>Tvar</h3><div class="pal-f"><span>Výplň z palety projektu</span>${edSw(p, l.color, 'color')}</div>${edNum('Zaoblení rohů', 'radius', l.radius, 'px')}</section>` : ''}
      ${l.k === 'list' ? `<section class="ed-sec"><h3>Zaškrtávací seznam</h3><label class="pal-f"><span>Položky (každá na řádek)</span><textarea class="inp ed-ta" rows="4" data-edf="tx">${esc(l.tx)}</textarea></label><div class="pal-f"><span>Barva</span>${edSw(p, l.color, 'color')}</div></section>` : ''}
      ${l.k === 'bg' ? `<section class="ed-sec"><h3>Pozadí</h3><div class="ed-imgp bg"><img src="${l.src || edBg(V.w, V.h)}" alt=""></div><div class="ed-2"><button type="button" class="btn sm tertiary" data-edimg><span class="i i-upload s16" aria-hidden="true"></span>Nahradit</button><button type="button" class="btn sm tertiary" data-edgal><span class="i i-image s16" aria-hidden="true"></span>Z galerie</button></div><span class="cap">Pozadí se roztáhne přes celý formát a je zamčené, aby se omylem neposunulo.</span></section>` : `
      <section class="ed-sec"><h3>Pozice a velikost${E.multi ? '' : ` <span class="pill">jen ${V.r}</span>`}</h3>${inCont ? `<span class="cap">Pozici řídí kontejner „${esc(E.conts.find((c) => c.id === l.cont).name)}“.</span>` : `<div class="ed-4">${edNum('X', 'x', b.x)}${edNum('Y', 'y', b.y)}${edNum('Šířka', 'w', b.w)}${edNum('Výška', 'h', b.h)}</div>`}</section>`}
      <div class="ed-acts">${l.k !== 'bg' ? `<button type="button" class="btn sm tertiary" data-eddup><span class="i i-copy s16" aria-hidden="true"></span>Duplikovat</button>` : ''}<button type="button" class="btn sm tertiary" data-edlock="${l.id}"><span class="i ${l.lock ? 'i-unlock' : 'i-lock'} s16" aria-hidden="true"></span>${l.lock ? 'Odemknout' : 'Zamknout'}</button>${l.k !== 'bg' ? `<button type="button" class="btn sm ghost danger" data-eddel><span class="i i-trash s16" aria-hidden="true"></span>Smazat</button>` : ''}</div>`;
  }
  const edContProps = (E, c) => `<div class="ed-ph"><span class="ed-ph-i"><span class="i i-list s16" aria-hidden="true"></span></span><input class="ed-ph-n" value="${esc(c.name)}" data-edcf="name" aria-label="Název kontejneru"><button type="button" class="btn icon xs ghost" data-edpick="" aria-label="Zrušit výběr"><span class="i i-x s14" aria-hidden="true"></span></button></div>
      <section class="ed-sec"><h3>Kontejner</h3><div class="ed-2">${edNum('Mezera mezi řádky', 'gap', c.gap, 'px').replace('data-edf', 'data-edcf')}${edNum('Výška řádku', 'rowH', c.rowH, 'px').replace('data-edf', 'data-edcf')}</div>
      <label class="man-page"><input type="checkbox" role="switch" class="sr" data-edcf="grow"${c.grow ? ' checked' : ''}><span class="sw${c.grow ? ' on' : ''}" aria-hidden="true"></span><span><b>Klient může přidávat řádky</b><span class="cap">Při vyplnění si přidá další střelce, body seznamu apod.</span></span></label></section>
      <section class="ed-sec"><h3>Řádky <span class="pd-n">${c.items.length}</span></h3><ul class="ed-rows">${c.items.map((id) => { const l = E.layers.find((x) => x.id === id); return `<li><button type="button" data-edpick="${id}"><span class="i i-type s14" aria-hidden="true"></span>${esc(l.name)}<span class="cap">${esc(l.tx)}</span></button></li>`; }).join('')}</ul>
      <button type="button" class="btn sm tertiary" data-edrow="${c.id}"><span class="i i-plus s16" aria-hidden="true"></span>Přidat řádek</button></section>`;
  const edCanvasProps = (E, V) => `<div class="ed-ph"><span class="ed-ph-i"><span class="i i-template s16" aria-hidden="true"></span></span><div><b>Formát ${V.r}</b><span class="cap" style="display:block">${V.w} × ${V.h} px</span></div></div>
      <section class="ed-sec"><h3>Vstupy pro klienta <span class="pd-n">${E.layers.filter((l) => l.input).length}</span></h3><span class="cap">Tyto vrstvy klient vyplní v „Vyplnit a exportovat“. Ostatní jsou pevný design.</span>
      <ul class="ed-rows">${E.layers.filter((l) => l.input).map((l) => `<li><button type="button" data-edpick="${l.id}"><span class="i ${edIco[l.k]} s14" aria-hidden="true"></span>${esc(l.name)}<span class="cap">${l.k === 'text' ? esc(l.tx) : 'obrázek'}</span></button></li>`).join('')}</ul></section>
      <section class="ed-sec"><h3>Tipy</h3><ul class="ed-tips"><li>Klikněte na prvek v plátně nebo ve vrstvách.</li><li>Šipkami posunete o 1 px, se Shiftem o 10 px.</li><li>Ctrl + Z vrátí poslední krok.</li></ul></section>
      ${E.vars.length > 1 ? `<button type="button" class="btn sm ghost danger" data-eddelvar><span class="i i-trash s16" aria-hidden="true"></span>Odebrat formát ${V.r}</button>` : ''}`;
  // mini navigator — thumbnail of the whole format with the visible area; click or drag to move the view
  const edMini = (E) => { const V = edV(E), m = Math.min(176 / V.w, 200 / V.h);
    if (edMP.hid) return `<button type="button" class="ed-mini-tab" data-edminishow style="bottom:${edMP.b}px" aria-label="Zobrazit navigátor" title="Zobrazit navigátor"><span class="i i-chev-left s14" aria-hidden="true"></span><span class="i i-grid s14" aria-hidden="true"></span></button>`;
    return `<div class="ed-minibox" data-edminibox style="right:${edMP.r}px;bottom:${edMP.b}px"><div class="ed-mini-h" data-edminih title="Přetažením přesunete kamkoli na obrazovku"><span class="i i-grip s14" aria-hidden="true"></span><span>Navigátor</span><button type="button" class="ed-mini-x" data-edminihide aria-label="Schovat navigátor k okraji" title="Schovat k okraji"><span class="i i-chev-right s14" aria-hidden="true"></span></button></div><div class="ed-mini" data-edmini style="width:${Math.round(V.w * m)}px;height:${Math.round(V.h * m)}px" title="Mini navigátor — kliknutím nebo tažením posunete pohled" aria-hidden="true"><div class="ed-mini-b" style="width:${V.w}px;height:${V.h}px;transform:scale(${m})">${E._els || ''}</div><span class="ed-mini-v" data-edminiv></span></div></div>`; };
  const edMiniSync = () => { const v = $('[data-edminiv]'), sc = $('[data-edscroll]'), bw = $('[data-edboard]'); if (!v || !sc || !bw) return; const b = bw.getBoundingClientRect(), s = sc.getBoundingClientRect();
    const x0 = Math.max(0, (s.left - b.left) / b.width), y0 = Math.max(0, (s.top - b.top) / b.height), x1 = Math.min(1, (s.right - b.left) / b.width), y1 = Math.min(1, (s.bottom - b.top) / b.height);
    Object.assign(v.style, { left: x0 * 100 + '%', top: y0 * 100 + '%', width: Math.max(0, x1 - x0) * 100 + '%', height: Math.max(0, y1 - y0) * 100 + '%' }); v.classList.toggle('all', x0 <= 0 && y0 <= 0 && x1 >= 1 && y1 >= 1); };
  const edMiniGo = (e) => { const mi = $('[data-edmini]'), sc = $('[data-edscroll]'), bw = $('[data-edboard]'); if (!mi || !sc || !bw) return; const r = mi.getBoundingClientRect(), fx = Math.min(1, Math.max(0, (e.clientX - r.left) / r.width)), fy = Math.min(1, Math.max(0, (e.clientY - r.top) / r.height)), b = bw.getBoundingClientRect(), s = sc.getBoundingClientRect();
    sc.scrollLeft += b.left + fx * b.width - (s.left + s.width / 2); sc.scrollTop += b.top + fy * b.height - (s.top + s.height / 2); edMiniSync(); };
  let edMiniDrag = false, edMP = (() => { try { return { r: 24, b: 24, hid: false, ...JSON.parse(localStorage.getItem('wb-edmini2') || '{}') }; } catch (_) { return { r: 16, b: 16, hid: false }; } })(), edMPDrag = null;
  const edMPClamp = () => { const bx = $('[data-edminibox]'); if (!bx) return; const W = document.documentElement.clientWidth, H = window.innerHeight; edMP.r = Math.max(8, Math.min(W - bx.offsetWidth - 8, edMP.r)); edMP.b = Math.max(8, Math.min(H - bx.offsetHeight - 8, edMP.b)); bx.style.right = edMP.r + 'px'; bx.style.bottom = edMP.b + 'px'; };
  window.addEventListener('resize', () => edMPClamp());
  const edMPSave = () => { try { localStorage.setItem('wb-edmini2', JSON.stringify(edMP)); } catch (_) {} };
  // move the navigator by its header; hide it to the right edge of the canvas (a small tab brings it back)
  document.addEventListener('pointerdown', (e) => { const h = e.target.closest('[data-edminih]'); if (!h || e.target.closest('button')) return; e.preventDefault(); e.stopPropagation(); const bx = $('[data-edminibox]'); edMPDrag = { sx: e.clientX, sy: e.clientY, r: edMP.r, b: edMP.b, w: bx.offsetWidth, h: bx.offsetHeight }; bx.classList.add('drag'); }, true);
  document.addEventListener('pointermove', (e) => { if (!edMPDrag) return; const bx = $('[data-edminibox]'); if (!bx) return; const d = edMPDrag, W = document.documentElement.clientWidth, H = window.innerHeight;
    edMP.r = Math.round(Math.min(W - d.w - 8, Math.max(8, d.r - (e.clientX - d.sx)))); edMP.b = Math.round(Math.min(H - d.h - 8, Math.max(8, d.b - (e.clientY - d.sy)))); bx.style.right = edMP.r + 'px'; bx.style.bottom = edMP.b + 'px'; });
  document.addEventListener('pointerup', () => { if (!edMPDrag) return; edMPDrag = null; $('[data-edminibox]')?.classList.remove('drag'); edMPSave(); });
  document.addEventListener('click', (e) => { if (e.target.closest('[data-edminihide]')) { edMP.hid = true; edMPSave(); edRe(); } else if (e.target.closest('[data-edminishow]')) { edMP.hid = false; edMPSave(); edRe(); } });
  document.addEventListener('pointerdown', (e) => { if (!e.target.closest('[data-edmini]')) return; e.preventDefault(); e.stopPropagation(); edMiniDrag = true; edMiniGo(e); }, true);
  document.addEventListener('pointermove', (e) => { if (edMiniDrag) edMiniGo(e); });
  document.addEventListener('pointerup', () => { edMiniDrag = false; });
  document.addEventListener('scroll', (e) => { if (e.target.matches?.('[data-edscroll]')) edMiniSync(); }, true);
  const edRe = () => { const pp = edPath(); if (!pp) return; const sc = $('[data-edscroll]'), top = sc ? sc.scrollTop : 0, left = sc ? sc.scrollLeft : 0, rp = $('.ed-right'), rt = rp ? rp.scrollTop : 0; renderTpEd(pp.pid, pp.tid); const s2 = $('[data-edscroll]'); if (s2) { s2.scrollTop = top; s2.scrollLeft = left; } const r2 = $('.ed-right'); if (r2) r2.scrollTop = rt; edMiniSync(); };
  const edFit = (E) => { const sc = $('[data-edscroll]'); if (!sc) return; const V = edV(E), z = Math.min((sc.clientWidth - 130) / V.w, (sc.clientHeight - 170) / V.h); edFitZ = Math.max(.15, Math.min(1, Math.floor(z * 100) / 100)); };
  // move / resize by mouse
  let edDrag = null;
  $('#view').addEventListener('pointerdown', (e) => {
    const E = edCur(); if (!E) return; const hit = e.target.closest('[data-edhit], [data-edcont]');
    if (e.target.closest('.ed-gtb')) return;
    if (!hit) { if (e.target.closest('[data-edboard]') || e.target.closest('.ed-scroll') && !e.target.closest('.ed-zoom')) { if (E.sel || edMs(E).length) { E.sel = null; E.msel = []; edRe(); } } return; }
    const id = hit.dataset.edhit || hit.dataset.edcont, isC = !!hit.dataset.edcont, l = isC ? E.conts.find((c) => c.id === id) : E.layers.find((x) => x.id === id);
    if (e.shiftKey && !isC) { e.preventDefault(); edToggleMs(E, id); return; }
    if (!isC && edMs(E).includes(id)) { e.preventDefault(); const it = edItems(E).filter((i) => !i.lock); edDrag = { group: it.map((i) => ({ ...i, b0: { ...i.b } })), sx: e.clientX, sy: e.clientY, z: edZ(E), snap: false }; return; }
    if (edMs(E).length) E.msel = [];
    if (!isC && l.cont && !e.target.closest('[data-edh]')) { /* click on a row inside a container selects the row */ }
    if (E.sel !== id) { E.sel = id; edRe(); }
    if (!isC && l.lock) return; e.preventDefault();
    const h = e.target.closest('[data-edh]'), V = edV(E), z = edZ(E);
    const base = isC ? edContBox(E, l) : edBox(E, l);
    edDrag = { id, isC, mode: h ? h.dataset.edh : 'move', sx: e.clientX, sy: e.clientY, base, V, z, snap: false };
    if (!isC && l.cont && !h) edDrag = { ...edDrag, id: l.cont, isC: true, base: edContBox(E, E.conts.find((c) => c.id === l.cont)) };
  });
  $('#view').addEventListener('pointermove', (e) => {
    if (!edDrag) return; const E = edCur(); if (!E) return; const d = edDrag, dx = (e.clientX - d.sx) / d.z, dy = (e.clientY - d.sy) / d.z;
    if (!d.snap && Math.abs(dx) + Math.abs(dy) < 2) return; if (!d.snap) { edSnap(E); d.snap = true; }
    if (d.group) { d.group.forEach((i) => edSetBox(E, i.o, i.isC, { ...i.b0, x: Math.round(i.b0.x + dx), y: Math.round(i.b0.y + dy) })); const sc = $('[data-edscroll]'); if (sc) { const t = sc.scrollTop, lft = sc.scrollLeft; sc.innerHTML = edCanvas(E); sc.scrollTop = t; sc.scrollLeft = lft; } return; }
    const o = d.isC ? E.conts.find((c) => c.id === d.id) : E.layers.find((x) => x.id === d.id), V = d.V, offX = (V.w - 1080) / 2, offY = (V.h - 1350) / 2;
    let { x, y, w, h } = d.base;
    if (d.mode === 'move') { x += dx; y += dy; } else { if (d.mode.includes('l')) { x += dx; w -= dx; } if (d.mode.includes('r')) w += dx; if (d.mode.includes('t')) { y += dy; h -= dy; } if (d.mode.includes('b')) h += dy; w = Math.max(10, w); h = Math.max(10, h); }
    edSetBox(E, o, d.isC, { x: Math.round(x), y: Math.round(y), w: Math.round(w), h: Math.round(h) });
    const sc = $('[data-edscroll]'); if (sc) { const t = sc.scrollTop, lft = sc.scrollLeft; sc.innerHTML = edCanvas(E); sc.scrollTop = t; sc.scrollLeft = lft; }
  });
  $('#view').addEventListener('pointerup', () => { if (!edDrag) return; const d = edDrag; edDrag = null; if (d.snap) edRe(); });
  // write a box back: base coords when editing all formats, per-format offset otherwise
  function edSetBox(E, o, isC, b) {
    const V = edV(E), offX = (V.w - 1080) / 2, offY = (V.h - 1350) / 2;
    if (isC) { const cur = (o.ov && o.ov[V.r]) || { dx: 0, dy: 0 };
      if (E.multi || V.r === '4:5') { o.x = b.x - offX - cur.dx; o.y = b.y - offY - cur.dy; } else { o.ov = o.ov || {}; o.ov[V.r] = { dx: b.x - offX - o.x, dy: b.y - offY - o.y }; }
      if (b.w !== edContBox(E, o).w) o.w = b.w; return; }
    if (o.cont) { const c = E.conts.find((x) => x.id === o.cont); c.w = b.w; c.rowH = b.h; return; }
    const cur = (o.ov && o.ov[V.r]) || { dx: 0, dy: 0 };
    if (E.multi || V.r === '4:5') { o.x = b.x - offX - cur.dx; o.y = b.y - offY - cur.dy; } else { o.ov = o.ov || {}; o.ov[V.r] = { dx: b.x - offX - o.x, dy: b.y - offY - o.y }; }
    o.w = b.w; o.h = b.h;
  }
  const edNew = (E, k, p) => {
    const V = edV(E), base = { id: 'l' + Date.now().toString(36), lock: false, hide: false, input: k !== 'shape', x: 340, y: 560, w: 400, h: 80 };
    if (k === 'text') return { ...base, k, name: 'Nový text', tx: 'Váš text', fs: 56, fw: 700, color: '#FFFFFF', align: 'center', font: 'Montserrat' };
    if (k === 'img') return { ...base, k, name: 'Nový obrázek', w: 300, h: 300, x: 390, y: 520, src: edCrest((p.colors || [])[0] || '#3E8FD8', '#fff', 'IMG'), fit: 'contain' };
    if (k === 'shape') return { ...base, k, name: 'Nový tvar', w: 360, h: 120, x: 360, y: 615, color: (p.colors || [])[0] || '#3E8FD8', radius: 12 };
    return { ...base, k: 'list', name: 'Seznam', w: 460, h: 180, x: 310, y: 585, tx: 'První bod\nDruhý bod\nTřetí bod', fs: 34, color: '#FFFFFF' };
  };
  $('#view').addEventListener('click', (e) => {
    const pp = edPath(); if (!pp) return; const E = edCur(), p = byId(pp.pid), T = tpOf(pp.pid), sel = E.layers.find((l) => l.id === E.sel);
    const ga = e.target.closest('[data-edal]'); if (ga) { const a = ga.dataset.edal; if (a === 'del') { const it = E.layers.filter((l) => edMs(E).includes(l.id) && l.k !== 'bg' && !l.lock); if (!it.length) return; edSnap(E); it.forEach((l) => { E.layers = E.layers.filter((x) => x !== l); E.conts.forEach((c) => { c.items = c.items.filter((i) => i !== l.id); }); }); E.conts = E.conts.filter((c) => c.items.length); E.msel = []; edRe(); toast(`Smazáno ${it.length} prvků`); } else edAlign(E, a); return; }
    const pk = e.target.closest('[data-edpick]'); if (pk && e.shiftKey && pk.dataset.edpick && E.layers.some((l) => l.id === pk.dataset.edpick)) { edToggleMs(E, pk.dataset.edpick); return; }
    if (pk && pk.dataset.edpick && pk.dataset.edpick === E.sel && !edMs(E).length) return;
    if (pk) { E.msel = []; E.sel = pk.dataset.edpick || null; if (E.sel) E.clean = false; edRe(); return; }
    const tb = e.target.closest('[data-edtab]'); if (tb) { E.tab = tb.dataset.edtab; edRe(); return; }
    const vr = e.target.closest('[data-edvar]'); if (vr) { E.cur = Number(vr.dataset.edvar); E.zoom = 0; edRe(); edFit(E); edRe(); return; }
    const lk = e.target.closest('[data-edlock]'); if (lk) { const l = E.layers.find((x) => x.id === lk.dataset.edlock); edSnap(E); l.lock = !l.lock; edRe(); return; }
    const hd = e.target.closest('[data-edhide]'); if (hd) { const l = E.layers.find((x) => x.id === hd.dataset.edhide); edSnap(E); l.hide = !l.hide; edRe(); return; }
    const zm = e.target.closest('[data-edzoom]'); if (zm) { const z = edZ(E), v = zm.dataset.edzoom; if (v === 'fit') { E.zoom = 0; edFit(E); } else E.zoom = Math.max(.15, Math.min(2, Math.round((z + (v === '+' ? .1 : -.1)) * 100) / 100)); edRe(); return; }
    const ad = e.target.closest('[data-edadd]');
    if (ad) { const k = ad.dataset.edadd; if (k === 'bgset') { E.sel = E.layers.find((l) => l.k === 'bg').id; edRe(); return; } edSnap(E); const l = edNew(E, k, p); E.layers.unshift(l); E.sel = l.id; E.tab = 'layers'; edRe(); toast(`Přidáno: ${l.name}`); return; }
    const al = e.target.closest('[data-edalign]'); if (al && sel) { edSnap(E); sel.align = al.dataset.edalign; edRe(); return; }
    const ft = e.target.closest('[data-edfit]'); if (ft && sel) { edSnap(E); sel.fit = ft.dataset.edfit; edRe(); return; }
    const cl = e.target.closest('[data-edcolor]'); if (cl && sel) { edSnap(E); sel[cl.dataset.edkey] = cl.dataset.edcolor; edRe(); return; }
    if (e.target.closest('[data-eddup]') && sel) { edSnap(E); const c = JSON.parse(JSON.stringify(sel)); c.id = 'l' + Date.now().toString(36); c.name += ' (kopie)'; delete c.cont; const b = edBox(E, sel); c.x = b.x - (edV(E).w - 1080) / 2 + 24; c.y = b.y - (edV(E).h - 1350) / 2 + 24; c.w = b.w; c.h = b.h; delete c.ov; E.layers.splice(E.layers.indexOf(sel), 0, c); E.sel = c.id; edRe(); return; }
    if (e.target.closest('[data-eddel]') && sel) { edDel(E, sel); return; }
    const rw = e.target.closest('[data-edrow]'); if (rw) { const c = E.conts.find((x) => x.id === rw.dataset.edrow), last = E.layers.find((x) => x.id === c.items[c.items.length - 1]); edSnap(E); const n = { ...JSON.parse(JSON.stringify(last)), id: 'l' + Date.now().toString(36), name: last.name.replace(/\d+$/, (m) => Number(m) + 1), tx: '' }; E.layers.splice(E.layers.indexOf(last) + 1, 0, n); c.items.push(n.id); edRe(); return; }
    if (e.target.closest('[data-edundo]')) { edUndo(E); return; }
    if (e.target.closest('[data-edredo]')) { edRedo(E); return; }
    if (e.target.closest('[data-edsave]')) { E.saved = true; const t = T.items.find((x) => x.id === E.tid); if (t) t.name = E.name; edRe(); toast('Šablona uložena'); return; }
    if (e.target.closest('[data-edfill]')) { location.hash = `projekt-${pp.pid}/sablony/${pp.tid}/vyplnit`; return; }
    if (e.target.closest('[data-edimg]')) { $('#edFile').click(); return; }
    if (e.target.closest('[data-edgal]')) { toast('Otevře se výběr z Galerie projektu'); return; }
    if (e.target.closest('[data-eddelvar]')) { const V = edV(E); galConfirm(`Odebrat formát ${V.r}?`, 'Úpravy, které jste dělali jen pro tento formát, se ztratí.', 'Odebrat formát', () => { edSnap(E); E.vars.splice(E.cur, 1); E.cur = 0; E.zoom = 0; edRe(); edFit(E); edRe(); toast(`Formát ${V.r} odebrán`); }); return; }
    if (e.target.closest('[data-edclean]')) { E.clean = !E.clean; if (E.clean) E.sel = null; edRe(); toast(E.clean ? 'Čistý náhled — pomocné prvky skryté' : 'Pomocné prvky zobrazené'); return; }
    const vm = e.target.closest('[data-edviewm]');
    if (vm) { const m = galMenu(vm, [{ a: 'labels', i: E.view.labels ? 'i-check' : 'i-x', t: 'Popisky vstupů pro klienta' }, { a: 'rulers', i: E.view.rulers ? 'i-check' : 'i-x', t: 'Pravítka' }, { a: 'outside', i: E.view.outside ? 'i-check' : 'i-x', t: 'Prvky mimo plátno' }, { a: 'mini', i: E.view.mini ? 'i-check' : 'i-x', t: 'Mini navigátor' }]);
      m.onclick = (ev) => { const b = ev.target.closest('[data-galact]'); if (!b) return; galCloseMenu(); E.view[b.dataset.galact] = !E.view[b.dataset.galact]; edRe(); }; return; }
    const av = e.target.closest('[data-edaddvar]');
    if (av) { const left = ED_PRESETS.filter(([r]) => !E.vars.some((v) => v.r === r)); if (!left.length) { toast('Všechny nabízené rozměry už šablona má'); return; }
      const m = galMenu(av, left.map(([r, w, h]) => ({ a: r, i: 'i-template', t: `${r} · ${w} × ${h} px` })));
      m.onclick = (ev) => { const b = ev.target.closest('[data-galact]'); if (!b) return; galCloseMenu(); const [r, w, h] = ED_PRESETS.find((x) => x[0] === b.dataset.galact); edSnap(E); E.vars.push({ r, w, h }); E.cur = E.vars.length - 1; E.zoom = 0; edRe(); edFit(E); edRe(); toast(`Přidán formát ${r} — zkontrolujte rozložení`); }; }
  });
  const edDel = (E, l) => { if (l.k === 'bg') return; if (l.lock) { toast('Vrstva je zamčená — nejdřív ji odemkněte', true); return; } edSnap(E); E.layers = E.layers.filter((x) => x !== l); E.conts.forEach((c) => { c.items = c.items.filter((id) => id !== l.id); }); E.conts = E.conts.filter((c) => c.items.length); E.sel = null; edRe(); toast(`Smazáno: ${l.name}`); };
  const edUndo = (E) => { if (!E.hist.length) return; E.fut.push(JSON.stringify({ layers: E.layers, conts: E.conts, vars: E.vars })); edRestore(E, E.hist.pop()); E.saved = false; if (!E.layers.some((l) => l.id === E.sel) && !E.conts.some((c) => c.id === E.sel)) E.sel = null; edRe(); };
  const edRedo = (E) => { if (!E.fut.length) return; E.hist.push(JSON.stringify({ layers: E.layers, conts: E.conts, vars: E.vars })); edRestore(E, E.fut.pop()); E.saved = false; edRe(); };
  $('#view').addEventListener('change', (e) => {
    const E = edCur(); if (!E) return; const sel = E.layers.find((l) => l.id === E.sel), selC = E.conts.find((c) => c.id === E.sel);
    if (e.target.id === 'edFile') { const f = e.target.files[0]; e.target.value = ''; if (!f || !sel) return; edSnap(E); sel.src = URL.createObjectURL(f); edRe(); toast('Obrázek nahrazen'); return; }
    if (e.target.matches('[data-edmulti]')) { E.multi = e.target.checked; edRe(); toast(E.multi ? 'Změny se propíšou do všech formátů' : `Upravujete jen formát ${edV(E).r}`); return; }
    if (e.target.matches('[data-edname]')) { E.name = e.target.value.trim() || E.name; E.saved = false; edRe(); return; }
    const cf = e.target.closest('[data-edcf]'); if (cf && selC) { edSnap(E); const k = cf.dataset.edcf; selC[k] = k === 'grow' ? cf.checked : k === 'name' ? cf.value : Math.max(0, Number(cf.value) || 0); edRe(); return; }
    const f = e.target.closest('[data-edf]'); if (!f || !sel) return; const k = f.dataset.edf; edSnap(E);
    if (k === 'input') sel.input = f.checked;
    else if (k === 'cc') sel.cc = f.checked;
    else if (['x', 'y', 'w', 'h'].includes(k)) { const b = edBox(E, sel); b[k] = Number(f.value) || 0; edSetBox(E, sel, false, b); }
    else if (k === 'color') { if (/^#[0-9a-f]{6}$/i.test(f.value)) sel.color = f.value.toUpperCase(); }
    else if (['fs', 'fw', 'radius'].includes(k)) sel[k] = Math.max(0, Number(f.value) || 0);
    else sel[k] = f.value;
    edRe();
  });
  $('#view').addEventListener('input', (e) => { const E = edCur(); if (!E) return; const sel = E.layers.find((l) => l.id === E.sel); if (sel && e.target.matches('[data-edf="tx"]')) { sel.tx = e.target.value; E.saved = false; const sc = $('[data-edscroll]'); if (sc) { const t = sc.scrollTop; sc.innerHTML = edCanvas(E); sc.scrollTop = t; } } });
  // double-click a layer (or its object on the canvas) to rename it — the same name is shown on the canvas label and in properties
  const edRename = (E, id) => { const isC = E.tab === 'conts'; if (!isC && E.tab !== 'layers') E.tab = 'layers'; const o = E.layers.find((l) => l.id === id) || E.conts.find((c) => c.id === id); if (!o || o.k === 'bg') return;
    if (E.layers.includes(o) && E.tab !== 'layers') { E.tab = 'layers'; edRe(); }
    const btn = document.querySelector(`[data-edly="${id}"] .ed-ly-n`); if (!btn) return; btn.scrollIntoView({ block: 'nearest' }); const box = document.createElement('span'); box.className = 'ed-ly-n editing'; const ic = btn.querySelector('.i'); if (ic) box.appendChild(ic.cloneNode());
    const inp = document.createElement('input'); inp.className = 'ed-ly-in'; inp.value = o.name; inp.maxLength = 40; inp.setAttribute('aria-label', 'Název vrstvy'); box.appendChild(inp); btn.replaceWith(box); inp.focus(); inp.select();
    const row = box.closest('[data-edly]'); if (row) row.draggable = false; let done = false;
    const fin = (ok) => { if (done) return; done = true; inp.onblur = null; const v = inp.value.trim(); if (ok && v && v !== o.name) { edSnap(E); o.name = v; toast(`Přejmenováno na „${v}“`); } edRe(); };
    inp.addEventListener('keydown', (ev) => { ev.stopPropagation(); if (ev.key === 'Enter') fin(true); if (ev.key === 'Escape') fin(false); });
    inp.onblur = () => setTimeout(() => fin(true)); inp.addEventListener('click', (ev) => ev.stopPropagation()); inp.addEventListener('pointerdown', (ev) => ev.stopPropagation()); };
  $('#view').addEventListener('dblclick', (e) => { const E = edCur(); if (!E) return; const t = e.target.closest('[data-edpick], [data-edhit], [data-edcont]'); if (!t || t.closest('.ed-right')) return; const id = t.dataset.edpick || t.dataset.edhit || t.dataset.edcont; if (!id) return; e.preventDefault(); if (E.sel !== id && !t.dataset.edpick) { E.sel = id; E.msel = []; edRe(); } edRename(E, id); });
  // reorder layers by dragging rows (top of the list = in front)
  let edLyDrag = null;
  $('#view').addEventListener('dragstart', (e) => { const li = e.target.closest?.('[data-edly][draggable]'); if (!li || !edPath()) return; edLyDrag = li.dataset.edly; li.classList.add('man-dragging'); e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', edLyDrag); });
  $('#view').addEventListener('dragover', (e) => { if (!edLyDrag) return; const li = e.target.closest('[data-edly]'); if (!li) return; e.preventDefault(); $$('.ed-ly.man-over').forEach((x) => { if (x !== li) x.classList.remove('man-over'); }); if (li.dataset.edly !== edLyDrag) li.classList.add('man-over'); });
  $('#view').addEventListener('drop', (e) => { if (!edLyDrag) return; e.preventDefault(); const E = edCur(), li = e.target.closest('[data-edly]'); if (li && li.dataset.edly !== edLyDrag) { edSnap(E); const a = E.layers.findIndex((l) => l.id === edLyDrag), l = E.layers.splice(a, 1)[0], b = E.layers.findIndex((x) => x.id === li.dataset.edly); E.layers.splice(a <= b ? b + 1 : b, 0, l); const bg = E.layers.find((x) => x.k === 'bg'); E.layers = E.layers.filter((x) => x !== bg).concat(bg); edRe(); } edLyDrag = null; });
  $('#view').addEventListener('dragend', () => { edLyDrag = null; $$('.ed-ly.man-dragging, .ed-ly.man-over').forEach((x) => x.classList.remove('man-dragging', 'man-over')); });
  document.addEventListener('keydown', (e) => {
    const E = edCur(); if (!E || e.target.closest('input, textarea, select') || document.querySelector('dialog[open]')) return;
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'z') { e.preventDefault(); if (e.shiftKey) edRedo(E); else edUndo(E); return; }
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); $('[data-edsave]')?.click(); return; }
    const sel = E.layers.find((l) => l.id === E.sel);
    if (e.key === 'Escape') { E.sel = null; E.msel = []; edRe(); return; }
    if (edMs(E).length) { if (e.key === 'Delete' || e.key === 'Backspace') { e.preventDefault(); $('[data-edal="del"]')?.click(); return; }
      const mv2 = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] }[e.key]; if (mv2) { e.preventDefault(); edSnap(E); const n = e.shiftKey ? 10 : 1; edItems(E).filter((i) => !i.lock).forEach((i) => edSetBox(E, i.o, i.isC, { ...i.b, x: i.b.x + mv2[0] * n, y: i.b.y + mv2[1] * n })); edRe(); } return; }
    if (!sel) return;
    if (e.key === 'Delete' || e.key === 'Backspace') { e.preventDefault(); edDel(E, sel); return; }
    const mv = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] }[e.key];
    if (mv && !sel.lock && sel.k !== 'bg') { e.preventDefault(); edSnap(E); const n = e.shiftKey ? 10 : 1; if (sel.cont) { const c = E.conts.find((x) => x.id === sel.cont), b = edContBox(E, c); edSetBox(E, c, true, { ...b, x: b.x + mv[0] * n, y: b.y + mv[1] * n }); } else { const b = edBox(E, sel); edSetBox(E, sel, false, { ...b, x: b.x + mv[0] * n, y: b.y + mv[1] * n }); } edRe(); }
  });
  // leave full-width mode when navigating away; fit the canvas on first open and on resize
  window.addEventListener('hashchange', () => { if (!edPath()) edLeave(); });
  new MutationObserver(() => { const E = edCur(); if (!E) { edLeave(); return; } requestAnimationFrame(() => { edMPClamp(); edMiniSync(); }); if (!E.zoom && !E._fitted && $('[data-edscroll]')) { E._fitted = true; const z0 = edFitZ; edFit(E); if (edFitZ !== z0) edRe(); } }).observe($('#view'), { childList: true });
  window.addEventListener('resize', () => { const E = edCur(); if (E && !E.zoom) { const z0 = edFitZ; edFit(E); if (edFitZ !== z0) edRe(); } });
  /* ---------- Vyplnit a exportovat — #projekt-<id>/sablony/<templateId>/vyplnit (what the client sees when clicking a template) ----------
     All formats of the template live side by side over the full width (format chips choose which are on screen, size slider like Finder),
     each with "ZIP" and PNG download. Every editable area has a small badge right in the design: pencil (text) → small draggable pop-up with
     Uložit / ×, palette (colour) → pop-up with project palette + eyedropper, image → gallery picker dialog (Výchozí + Galerie folders + upload),
     eye on container rows (hide → the rest moves up; hidden rows come back from the container pill). Filled once, applied to all formats.
     "Všechna pole" opens one dialog with every field; "Náhled" hides all helpers. Top: Historie exportů, Upravit design (designer/admin),
     Uložit rozpracované, Stáhnout vše (ZIP). */
  S.tfill = {};
  const fiPath = () => { if (!S.route.startsWith('projekt-')) return null; const [pid, a, tid, x] = S.route.slice(8).split('/'); return a === 'sablony' && tid && x === 'vyplnit' ? { pid, tid } : null; };
  const fiOf = (pid, tid) => { const k = pid + '/' + tid; if (S.tfill[k]) return S.tfill[k]; const E = tedOf(pid, tid);
    return (S.tfill[k] = { vals: {}, hide: [], add: {}, color: {}, off: [], nozip: [], areas: true, labels: true, clean: false, auto: true, act: null, saved: true, pop: null, custom: {},
      hist: Array.from({ length: 21 }, (_, i) => { const d = new Date(2026, 9, 30 - Math.floor(i * 1.4), 18 - (i * 5) % 9, (i * 17) % 60), pad = (n) => String(n).padStart(2, '0'); return { id: 'h' + i, at: `${d.getDate()}. ${d.getMonth() + 1}. ${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}`, who: i % 4 === 2 ? 'Jana Nováková' : 'Lukáš Rejda', name: i === 2 ? 'B – Břidličná' : '', pin: i === 2 }; }), hopen: false, hall: false }); };
  const fiCur = () => { const pp = fiPath(); return pp ? { E: tedOf(pp.pid, pp.tid), F: fiOf(pp.pid, pp.tid), ...pp } : null; };
  // the template with the client's content applied (design itself stays untouched)
  const fiApply = (E, F) => {
    const layers = []; E.layers.forEach((l) => { const o = { ...l }; if (F.vals[l.id] !== undefined) { if (l.k === 'img') o.src = F.vals[l.id]; else o.tx = F.vals[l.id]; } if (F.color[l.id]) o.color = F.color[l.id]; if (F.hide.includes(l.id)) o.hide = true; layers.push(o);
      const c = l.cont && E.conts.find((x) => x.id === l.cont); if (c && c.items[c.items.length - 1] === l.id) (F.add[c.id] || []).forEach((r) => layers.push({ ...l, id: r.id, name: r.name, tx: F.vals[r.id] !== undefined ? F.vals[r.id] : r.tx, cont: c.id, hide: F.hide.includes(r.id) })); });
    const conts = E.conts.map((c) => ({ ...c, items: c.items.concat((F.add[c.id] || []).map((r) => r.id)).filter((id) => !F.hide.includes(id)) }));
    return { ...E, layers, conts, cur: 0 };
  };
  const fiPct = (b, V) => `left:${b.x / V.w * 100}%;top:${b.y / V.h * 100}%;width:${b.w / V.w * 100}%;height:${b.h / V.h * 100}%`;
  const fiBoard = (E2, vi, F, E) => { E2.cur = vi; const V = E2.vars[vi], cq = (px) => `calc(${px / V.w * 100}cqw)`;
    const els = E2.layers.slice().reverse().filter((l) => !l.hide).map((l) => { const b = edBox(E2, l), st = fiPct(b, V);
      if (l.k === 'bg') return `<img class="fi-el bg" src="${l.src || edBg(V.w, V.h)}" alt="" style="${st}">`;
      if (l.k === 'img') return `<img class="fi-el" src="${l.src}" alt="" style="${st};object-fit:${l.fit}">`;
      if (l.k === 'shape') return `<span class="fi-el" style="${st};background:${l.color};border-radius:${cq(l.radius)}"></span>`;
      if (l.k === 'list') return `<span class="fi-el ed-list" style="${st};font-size:${cq(l.fs)};color:${l.color}">${l.tx.split('\n').map((r) => `<span><i></i>${esc(r)}</span>`).join('')}</span>`;
      return `<span class="fi-el ed-tx" style="${st};font-family:'${l.font}',Outfit,Arial,sans-serif;font-size:${cq(l.fs)};font-weight:${l.fw};color:${l.color};justify-content:${l.align === 'left' ? 'flex-start' : l.align === 'right' ? 'flex-end' : 'center'};text-align:${l.align}">${esc(l.tx)}</span>`; }).join('');
    const vis = (l) => !l.hide && (l.input || l.cc) && l.k !== 'bg';
    const areas = E2.layers.filter(vis).map((l) => { const b = edBox(E2, l), k = l.k === 'img' ? 'img' : l.input ? 'text' : 'col', ic = k === 'img' ? 'i-image' : k === 'col' ? 'i-palette' : 'i-edit';
      return `<div class="fi-area${l.id === F.act ? ' on' : ''} k-${k}" style="${fiPct(b, V)}"><button type="button" class="fi-hit" data-fiedit="${l.id}" aria-label="Upravit: ${esc(l.name)}"></button><span class="fi-tag">${esc(l.name)}</span><span class="fi-acts"><button type="button" class="fi-ab" data-fiedit="${l.id}" aria-label="Upravit: ${esc(l.name)}" tabindex="-1"><span class="i ${ic} s12" aria-hidden="true"></span></button>${l.cont ? `<button type="button" class="fi-ab eye" data-fihide="${l.id}" aria-label="Skrýt řádek ${esc(l.name)}" data-tip="Skrýt řádek (ostatní se posunou)" tabindex="-1"><span class="i i-eye s12" aria-hidden="true"></span></button>` : ''}</span></div>`; }).join('')
      + E2.conts.map((c) => { const all = E.conts.find((x) => x.id === c.id), hid = all.items.concat((F.add[c.id] || []).map((r) => r.id)).filter((id) => F.hide.includes(id)).length; if (!hid && !c.grow) return ''; const b = edContBox(E2, c);
        return `<div class="fi-cpills" style="left:${b.x / V.w * 100}%;top:${(b.y + Math.max(0, b.h)) / V.h * 100}%;width:${b.w / V.w * 100}%">${c.grow ? `<button type="button" class="fi-cp" data-fiaddrow="${c.id}" data-tip="Přidat řádek"><span class="i i-plus s12" aria-hidden="true"></span>Řádek</button>` : ''}${hid ? `<button type="button" class="fi-cp off" data-fiunhide="${c.id}" data-tip="Zobrazit skryté řádky"><span class="i i-eye-off s12" aria-hidden="true"></span>${hid} skryt${hid === 1 ? 'ý' : 'é'}</button>` : ''}</div>`; }).join('');
    const out = edOut(E2, V);
    return `<div class="fi-bd${F.areas && !F.clean ? ' areas' : ''}${F.labels && !F.clean ? ' labels' : ''}${F.clean ? ' clean' : ''}" style="aspect-ratio:${V.w} / ${V.h}"><div class="fi-bdc">${els}</div>${areas}</div>${out ? `<span class="fi-warn"><span class="i i-alert s12" aria-hidden="true"></span>Text přesahuje plátno — zkraťte ho</span>` : ''}`; };
  const fiPal = (p) => (p.colors && p.colors.length ? p.colors : ['#0E1E3A', '#3E8FD8', '#FFFFFF']).concat(['#FFFFFF', '#000000']).filter((c, i, a) => a.indexOf(c) === i);
  // colour row: project palette + any colour picked with the eyedropper (EyeDropper API, colour input as fallback)
  const fiSwatches = (l, F, p) => { const cur = F.color[l.id] || l.color, pal = fiPal(p), ext = (F.custom[l.id] || []).filter((c) => !pal.includes(c));
    return `<span class="ed-sws fi-sws">${pal.concat(ext).map((c) => `<button type="button" class="ed-swb${c.toUpperCase() === String(cur).toUpperCase() ? ' on' : ''}" style="background:${c}" data-ficol="${l.id}" data-c="${c}" aria-label="${esc(l.name)}: ${c}" title="${c}"></button>`).join('')}<button type="button" class="fi-drop" data-fidrop="${l.id}" aria-label="Kapátko — nabrat barvu z obrazovky" data-tip="Kapátko — nabrat barvu"><span class="i i-dropper s16" aria-hidden="true"></span></button></span>`; };
  const fiVal = (l, F) => F.vals[l.id] !== undefined ? F.vals[l.id] : l.k === 'img' ? l.src : l.tx;
  const fiField = (l, F, p, extra) => { const v = fiVal(l, F), hid = F.hide.includes(l.id);
    if (l.k === 'img') return `<div class="fi-f fi-img" data-fif="${l.id}"><span class="fi-th"><img src="${v}" alt=""></span><span class="fi-img-b"><b>${esc(l.name)}</b><button type="button" class="btn xs tertiary" data-fiedit="${l.id}"><span class="i i-image s14" aria-hidden="true"></span>Vybrat obrázek</button></span></div>`;
    if (l.cc && !l.input) return `<div class="fi-f" data-fif="${l.id}"><span class="fi-lb">${esc(l.name)}</span>${fiSwatches(l, F, p)}</div>`;
    return `<label class="fi-f${hid ? ' off' : ''}" data-fif="${l.id}"><span class="fi-lb">${esc(l.name)}</span><span class="inp sm"><input value="${esc(v)}" data-fiin="${l.id}" aria-label="${esc(l.name)}"${hid ? ' disabled' : ''}>${l.cont ? `<button type="button" class="fi-eye" data-fihide="${l.id}" aria-pressed="${hid}" aria-label="${hid ? 'Zobrazit' : 'Skrýt'} řádek ${esc(l.name)}" data-tip="${hid ? 'Řádek je skrytý — zobrazit' : 'Skrýt řádek (ostatní se posunou)'}"><span class="i ${hid ? 'i-eye-off' : 'i-eye'} s14" aria-hidden="true"></span></button>` : ''}${extra ? `<button type="button" class="fi-eye" data-fidelrow="${l.id}" aria-label="Odebrat řádek ${esc(l.name)}" data-tip="Odebrat řádek"><span class="i i-x s14" aria-hidden="true"></span></button>` : ''}</span></label>`; };
  function fiForm(E, F, p, cols) {
    const ins = E.layers.filter((l) => l.input && l.k !== 'bg'), loose = ins.filter((l) => !l.cont), txt = loose.filter((l) => l.k !== 'img'), img = loose.filter((l) => l.k === 'img'), cc = E.layers.filter((l) => l.cc && !l.input);
    const T = txt.length ? `<section class="fi-sec">${cols ? '' : '<h3>Texty</h3>'}${txt.map((l) => fiField(l, F, p)).join('')}</section>` : '';
    const C = E.conts.map((c) => { const rows = c.items.map((id) => E.layers.find((l) => l.id === id)).filter((l) => l && l.input); if (!rows.length) return ''; const ex = F.add[c.id] || [];
        return `<section class="fi-sec"><h3>${esc(c.name)} <span class="pd-n">${rows.concat(ex).filter((r) => !F.hide.includes(r.id)).length}</span></h3>${rows.map((l) => fiField(l, F, p)).join('')}${ex.map((r) => fiField({ ...rows[rows.length - 1], ...r, cont: c.id }, F, p, true)).join('')}${c.grow ? `<button type="button" class="fi-addrow" data-fiaddrow="${c.id}"><span class="i i-plus s14" aria-hidden="true"></span>Přidat řádek</button>` : ''}</section>`; }).join('');
    const I = img.length ? `<section class="fi-sec">${cols ? '' : '<h3>Obrázky</h3>'}${img.map((l) => fiField(l, F, p)).join('')}</section>` : '';
    const B = cc.length ? `<section class="fi-sec">${cols ? '' : '<h3>Tvary a barvy</h3>'}${cc.map((l) => fiField(l, F, p)).join('')}</section>` : '';
    if (cols) return [['Texty', 'i-type', T + C], ['Obrázky', 'i-image', I], ['Tvary a barvy', 'i-shape', B]].filter((x) => x[2]).map(([n, i, h]) => `<div class="fi-col" role="region" aria-label="${n}"><h2 class="fi-colh"><span class="i ${i} s16" aria-hidden="true"></span>${n}</h2><div class="fi-form">${h}</div></div>`).join('');
    return T + I + C + B;
  }
  const fiCards = (E, F) => { const E2 = fiApply(E, F), vis = E.vars.map((v, i) => i).filter((i) => !F.off.includes(E.vars[i].r));
    if (!vis.length) return '<p class="cap fi-none">Zapněte nahoře aspoň jeden formát.</p>';
    return vis.map((i) => { const V = E.vars[i]; return `<article class="fi-card" data-ficard="${V.r}"><header class="fi-ch"><b>${V.r}</b><span class="cap">${V.w} × ${V.h} px</span><span class="ed-sp"></span><button type="button" class="btn xs tertiary" data-fipng="${V.r}"><span class="i i-download s14" aria-hidden="true"></span>PNG</button></header>${fiBoard(E2, i, F, E)}</article>`; }).join(''); };
  // small pop-up next to the clicked area (text / colour); draggable by its grip, Uložit keeps, × or Esc reverts
  const fiPopHtml = (E, F, p) => { const P = F.pop; if (!P) return ''; const E2 = fiApply(E, F), l = E2.layers.find((x) => x.id === P.id) || (E.layers.find((x) => x.id === P.id)); if (!l) return ''; const col = l.cc && !l.input;
    return `<div class="fi-pop" role="dialog" aria-label="${esc(l.name)}" style="left:${P.x}px;top:${P.y}px"><div class="fi-pop-h"><span class="fi-grip" data-figrip aria-hidden="true" title="Přetažením přesunete"><span class="i i-grip s14"></span></span><b>${esc(l.name)}</b><span class="ed-sp"></span><button type="button" class="btn xs fi-ok" data-fipopok><span class="i i-check s14" aria-hidden="true"></span>Uložit</button><button type="button" class="btn icon xs ghost" data-fipopx aria-label="Zavřít bez uložení"><span class="i i-x s16" aria-hidden="true"></span></button></div>
      ${col ? fiSwatches(l, F, p) : `<span class="inp"><input value="${esc(fiVal(l, F))}" data-fiin="${l.id}" data-fipopin aria-label="${esc(l.name)}"></span>`}</div>`; };
  const fiOpenPop = (c, id, anchor) => { const { E, F } = c, l = E.layers.find((x) => x.id === id) || fiApply(E, F).layers.find((x) => x.id === id); if (!l) return;
    if (l.k === 'img') { fiGal(c, id); return; }
    const r = anchor ? anchor.getBoundingClientRect() : { left: innerWidth / 2 - 180, bottom: 160, top: 160 }, W = Math.min(380, innerWidth - 24), H = 130;
    let x = Math.max(12, Math.min(innerWidth - W - 12, r.left)), y = r.bottom + 10; if (y + H > innerHeight - 12) y = Math.max(12, r.top - H - 10);
    F.pop = { id, x, y, prev: l.cc && !l.input ? (F.color[id] || null) : F.vals[id], col: l.cc && !l.input }; F.act = id; fiRe(); setTimeout(() => { const i = $('[data-fipopin]'); if (i) { i.focus(); i.select(); } else $('.fi-pop .ed-swb.on')?.focus(); }); };
  const fiClosePop = (F, keep) => { const P = F.pop; if (!P) return; if (!keep) { if (P.col) { if (P.prev) F.color[P.id] = P.prev; else delete F.color[P.id]; } else if (P.prev === undefined) delete F.vals[P.id]; else F.vals[P.id] = P.prev; } else fiDirty(F); F.pop = null; F.act = null; fiRe(); };
  // gallery picker for image fields — Výchozí (from the template) + project Galerie with folders + upload
  function fiGal(c, id) {
    let d = document.getElementById('dlgFiImg'); if (!d) { d = document.createElement('dialog'); d.id = 'dlgFiImg'; d.className = 'fi-galdlg'; d.setAttribute('aria-labelledby', 'fiGalT'); document.body.appendChild(d); d.addEventListener('click', (e) => { if (e.target === d || e.target.closest('[data-close]')) d.close(); }); }
    const { E, F, pid } = c, l = E.layers.find((x) => x.id === id), G = galOf(pid), cur = fiVal(l, F); let fold = null;
    const draw = () => { const items = G.items.filter((x) => !x.trash && (x.folder || null) === fold), fo = G.folders.find((x) => x.id === fold);
      d.innerHTML = `<div class="dlg"><div class="dlg-h"><h2 id="fiGalT">${esc(l.name)}</h2><button type="button" class="btn icon xs ghost" data-close aria-label="Zavřít"><span class="i i-x s16" aria-hidden="true"></span></button></div>
        <nav class="fi-gnav" aria-label="Složky galerie"><button type="button" class="fi-gcrumb${fold === null ? ' on' : ''}" data-gfold=""><span class="i i-image s14" aria-hidden="true"></span>Galerie</button>${fo ? `<span class="fi-gsep" aria-hidden="true">/</span><span class="fi-gcrumb on"><span class="i i-folder s14" aria-hidden="true"></span>${esc(fo.name)}</span>` : ''}</nav>
        <div class="fi-ggrid" role="listbox" aria-label="Obrázky">${fold === null ? `<button type="button" class="fi-gt${cur === l.src ? ' on' : ''}" data-gpick="${esc(l.src)}" role="option" aria-selected="${cur === l.src}"><span class="fi-gi"><img src="${l.src}" alt=""></span><span class="fi-gm"><b>Výchozí</b><span class="cap">Obrázek ze šablony</span></span></button>` : ''}${fold === null ? G.folders.map((x) => `<button type="button" class="fi-gt fold" data-gfold="${x.id}"><span class="fi-gi"><span class="i i-folder s32" aria-hidden="true"></span></span><span class="fi-gm"><b>${esc(x.name)}</b><span class="cap">${imgWord(G.items.filter((y) => !y.trash && y.folder === x.id).length)}</span></span></button>`).join('') : ''}${items.map((x) => `<button type="button" class="fi-gt${cur === x.src ? ' on' : ''}" data-gpick="${esc(x.src)}" role="option" aria-selected="${cur === x.src}"><span class="fi-gi"><img src="${x.src}" alt="" loading="lazy"></span><span class="fi-gm"><b>${esc(x.name || 'Bez názvu')}</b><span class="cap">${x.w} × ${x.h} px</span><span class="pill fi-gf">${x.fmt}</span></span></button>`).join('')}</div>
        <label class="fi-gdrop" data-gdrop><input type="file" accept="image/*" class="sr" data-gfile multiple><span class="i i-upload s24" aria-hidden="true"></span><b>Přetáhněte obrázky sem</b><span class="cap">nebo klikněte pro výběr z počítače · nahraje se do: ${fo ? esc(fo.name) : 'Galerie'} · max. 10 MB / soubor</span></label></div>`; };
    const add = (files) => { [...files].filter((f) => /^image\//.test(f.type) && f.size <= GAL_MAX).forEach((f, i) => G.items.unshift({ id: 'g' + Date.now().toString(36) + i, name: f.name.replace(/\.[^.]+$/, ''), fmt: (f.name.split('.').pop() || 'PNG').toUpperCase(), w: 1080, h: 1080, src: URL.createObjectURL(f), folder: fold, size: f.size, date: Date.now(), by: ME })); draw(); toast('Obrázek nahrán do Galerie — vyberte ho'); };
    d.onclick = (e) => { const fo = e.target.closest('[data-gfold]'); if (fo) { fold = fo.dataset.gfold || null; draw(); return; } const pk = e.target.closest('[data-gpick]'); if (pk) { F.vals[id] = pk.dataset.gpick; fiDirty(F); d.close(); fiRe(); toast(`${l.name}: obrázek vybrán`); } };
    d.onchange = (e) => { if (e.target.matches('[data-gfile]')) add(e.target.files); };
    d.ondragover = (e) => { const z = e.target.closest('[data-gdrop]'); if (z) { e.preventDefault(); z.classList.add('over'); } };
    d.ondragleave = (e) => { e.target.closest('[data-gdrop]')?.classList.remove('over'); };
    d.ondrop = (e) => { const z = e.target.closest('[data-gdrop]'); if (z) { e.preventDefault(); add(e.dataTransfer.files); } };
    draw(); d.showModal(); d.querySelector('.fi-gt.on, .fi-gt')?.focus();
  }
  // "Sdílet na sítě" — dialog: real post preview (Facebook / Instagram, 4:5) + AI chat that writes the post text
  const fiFacts = (c) => { const E2 = fiApply(c.E, c.F), g = (n) => (E2.layers.find((l) => l.name === n) || {}).tx || '', rows = (pre) => E2.layers.filter((l) => l.cont && !l.hide && l.name.startsWith(pre) && l.tx).map((l) => l.tx);
    return { cat: g('Kategorie'), h: g('Skóre domácí'), a: g('Skóre hosté'), d: rows('D'), g: rows('H') }; };
  const fiDraft = (c, ask, prev) => { const x = fiFacts(c), p = byId(c.pid), q = (ask || '').toLowerCase();
    // names the user mentions in the request ("střelci byli Rejda a Kedroň") win over the template content
    const m = (ask || '').match(/st[řr]el(?:ci|ec|ce)?\s+(?:byl[iy]?\s+|jsou\s+|:\s*)?([^.!?\n]+)/i); if (m) x.d = m[1].split(/\s*(?:,|\sa\s|\si\s)\s*/).map((s) => s.trim()).filter(Boolean).map((s) => s.charAt(0).toUpperCase() + s.slice(1));
    const win = /vyhr|výhr|vítěz/.test(q) || (!/prohr|remíz/.test(q) && Number(x.h) > Number(x.a)), draw = /remíz/.test(q) || (!/vyhr|prohr/.test(q) && x.h === x.a);
    const sc = x.d.length ? `\n⚽ Góly: ${x.d.join(', ')}` : '', res = win ? `Vítězství ${x.h}:${x.a}!` : draw ? `Remíza ${x.h}:${x.a}.` : `Prohra ${x.h}:${x.a}, příště to otočíme.`;
    if (/krat|krát|short/.test(q)) return `${x.cat}: ${res}${x.d.length ? ` Góly: ${x.d.join(', ')}.` : ''} 💪 Díky všem fanouškům!`;
    if (prev && /formál|formal|seriózn/.test(q)) return `Tým ${x.cat} odehrál další utkání. Konečný výsledek ${x.h}:${x.a}.${x.d.length ? ` Branky vstřelili ${x.d.join(', ')}.` : ''} Děkujeme hráčům i divákům za podporu.`;
    if (prev && /emoji|emotik/.test(q)) return prev.replace(/\.(\s|$)/g, ' 🔥$1') + ' 🙌⚽️';
    if (prev && /hashtag|#/.test(q)) return `${prev}\n\n#${(p.name || 'klub').replace(/\s+/g, '')} #fotbal #${x.cat.replace(/\s+/g, '')}`;
    return `${win ? '🎉 ' : ''}KONEC UTKÁNÍ – ${x.cat}\n${res}${sc}\n\nDěkujeme všem, kdo přišli fandit! Další zápas už tuto sobotu, přijďte nás podpořit. 💙`; };
  const fiPost = (c, S2) => { const p = byId(c.pid), E2 = fiApply(c.E, c.F), vi = Math.max(0, c.E.vars.findIndex((v) => v.r === '4:5')), V = c.E.vars[vi], txt = S2.text || '', long = txt.length > 150 && !S2.more, shown = long ? txt.slice(0, 140).replace(/\s+\S*$/, '') : txt;
    const img = `<div class="fi-postimg">${fiBoard(E2, vi, { ...c.F, areas: false, labels: false, clean: true }, c.E)}</div>`, av = `<span class="fi-postav" aria-hidden="true">${esc((p.name || '?').slice(0, 1))}</span>`;
    const body = `<p class="fi-posttx">${txt ? esc(shown).replace(/\n/g, '<br>') + (long ? `… <button type="button" class="fi-more" data-shmore>Zobrazit více</button>` : '') : '<span class="fi-postph">Text příspěvku napíše AI vpravo — nebo ho napište sami.</span>'}</p>`;
    const un = esc((p.name || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/\s+/g, '_')), ig2 = txt.split('\n').slice(0, 2).join('\n').slice(0, 90), igLong = !S2.more && ig2.length < txt.length, igShown = igLong ? ig2.replace(/\s+\S*$/, '') : txt;
    return S2.net === 'ig' ? `<div class="fi-post ig"><div class="fi-posth">${av}<b>${un}</b><span class="i i-more s16" aria-hidden="true"></span></div>${img}<div class="fi-igacts" aria-hidden="true"><span>♡</span><span>💬</span><span>➤</span><span class="fi-igbm">🔖</span></div><p class="fi-iglikes">To se líbí 128 lidem</p><p class="fi-posttx"><b>${un}</b> ${txt ? esc(igShown).replace(/\n/g, '<br>') + (igLong ? `… <button type="button" class="fi-more" data-shmore>více</button>` : '') : '<span class="fi-postph">Popisek napíše AI vpravo — nebo ho napište sami.</span>'}</p><p class="fi-igcom">Zobrazit všech 12 komentářů</p><p class="fi-igtime">Před 1 minutou</p></div>`
      : `<div class="fi-post fb"><div class="fi-posth">${av}<span class="fi-postwho"><b>${esc(p.name || '')}</b><span class="cap">Teď · Veřejné</span></span><span class="i i-more s16" aria-hidden="true"></span></div>${body}${img}<div class="fi-fbacts" aria-hidden="true"><span>👍 To se mi líbí</span><span>💬 Komentář</span><span>↗ Sdílet</span></div></div>`; };
  function fiShare(c) {
    let d = document.getElementById('dlgFiShare'); if (!d) { d = document.createElement('dialog'); d.id = 'dlgFiShare'; d.className = 'fi-sharedlg'; d.setAttribute('aria-labelledby', 'fiShT'); document.body.appendChild(d); d.addEventListener('click', (e) => { if (e.target === d || e.target.closest('[data-close]')) d.close(); }); }
    const S2 = { net: 'fb', text: '', more: false, busy: false, msgs: [{ who: 'ai', t: 'Ahoj! Napište mi, o čem má příspěvek být, a text připravím z obsahu šablony. Třeba „napiš příspěvek o výsledku zápasu“.' }] };
    const draw = () => { const V = c.E.vars.find((v) => v.r === '4:5') || c.E.vars[0];
      d.innerHTML = `<div class="dlg"><div class="dlg-h"><div class="fi-allt"><h2 id="fiShT">Sdílet na sítě</h2><span class="cap">${esc(c.E.name)} (${V.w} × ${V.h} px)</span></div><span class="fi-shtop"><button type="button" class="btn sm ghost" data-shcopy${S2.text ? '' : ' disabled'}><span class="i i-copy s16" aria-hidden="true"></span>Kopírovat text</button><button type="button" class="btn sm tertiary" data-shpng><span class="i i-download s16" aria-hidden="true"></span>Stáhnout obrázek</button><button type="button" class="btn sm" data-shpub><span class="i i-share s16" aria-hidden="true"></span>Publikovat na ${S2.net === 'ig' ? 'Instagram' : 'Facebook'}</button></span><button type="button" class="btn icon xs ghost" data-close aria-label="Zavřít"><span class="i i-x s16" aria-hidden="true"></span></button></div>
        <div class="fi-sh"><section class="fi-shai" aria-label="AI asistent"><div class="fi-shaih"><span class="fi-aiav"><span class="i i-sparkle s16" aria-hidden="true"></span></span><b>AI asistent</b><span class="cap">píše text z obsahu šablony</span></div>
          <div class="fi-msgs" data-shmsgs>${S2.msgs.map((m) => `<div class="fi-msg ${m.who}">${esc(m.t).replace(/\n/g, '<br>')}${m.draft ? `<button type="button" class="btn xs tertiary fi-use" data-shuse="${S2.msgs.indexOf(m)}"><span class="i i-check s14" aria-hidden="true"></span>Použít v náhledu</button>` : ''}</div>`).join('')}${S2.busy ? '<div class="fi-msg ai typing"><i></i><i></i><i></i></div>' : ''}</div>
          <form class="fi-ask" data-shform><label class="inp"><input data-shin placeholder="Napište AI, co má příspěvek obsahovat…" aria-label="Zpráva pro AI" autocomplete="off"></label><button type="submit" class="btn icon sm" aria-label="Odeslat"><span class="i i-arrow-up s16" aria-hidden="true"></span></button></form>
          <label class="pal-f fi-shtx"><span>Text příspěvku (můžete upravit)</span><textarea class="inp" data-shtext placeholder="Zatím prázdné — napište AI, co chcete, nebo text napište sami">${esc(S2.text)}</textarea></label>
        </section>
        <section class="fi-shprev" aria-label="Náhled příspěvku">${fiPost(c, S2)}</section>
        <div class="fi-shnets" role="group" aria-label="Síť">${[['fb', 'i-facebook', 'Facebook'], ['ig', 'i-instagram', 'Instagram']].map(([k, i, n]) => `<button type="button" class="fi-net${S2.net === k ? ' on' : ''}" aria-pressed="${S2.net === k}" data-shnet="${k}" aria-label="${n}" data-tip="${n}"><span class="i ${i} s18" aria-hidden="true"></span></button>`).join('')}</div></div></div>`;
      const m = d.querySelector('[data-shmsgs]'); if (m) m.scrollTop = m.scrollHeight; };
    const ask = (q) => { if (!q.trim() || S2.busy) return; S2.msgs.push({ who: 'me', t: q }); S2.busy = true; draw(); d.querySelector('[data-shin]')?.focus();
      setTimeout(() => { const t = fiDraft(c, q, S2.text); S2.busy = false; S2.text = t; S2.more = false; S2.msgs.push({ who: 'ai', t, draft: true }); draw(); d.querySelector('[data-shin]')?.focus(); }, 900); };
    d.onclick = (e) => { const n = e.target.closest('[data-shnet]'); if (n) { S2.net = n.dataset.shnet; draw(); return; }
      const s = e.target.closest('[data-shsug]'); if (s) { ask(s.dataset.shsug); return; }
      const u = e.target.closest('[data-shuse]'); if (u) { S2.text = S2.msgs[Number(u.dataset.shuse)].t; draw(); return; }
      if (e.target.closest('[data-shmore]')) { S2.more = true; draw(); return; }
      if (e.target.closest('[data-shcopy]')) { try { navigator.clipboard.writeText(S2.text); } catch (err) { /* ignore */ } toast('Text příspěvku zkopírován'); return; }
      if (e.target.closest('[data-shpng]')) { fiExport(c, ['4:5']); toast('Stahuje se obrázek 4:5'); return; }
      if (e.target.closest('[data-shpub]')) { toast(`Pro přímé publikování propojte ${S2.net === 'ig' ? 'Instagram' : 'Facebook'} v Propojených aplikacích`, true); } };
    d.onsubmit = (e) => { e.preventDefault(); const i = d.querySelector('[data-shin]'); const q = i.value; i.value = ''; ask(q); };
    d.oninput = (e) => { if (e.target.matches('[data-shtext]')) { S2.text = e.target.value; const pv = d.querySelector('.fi-shprev'); if (pv) pv.innerHTML = fiPost(c, S2); d.querySelector('[data-shcopy]').disabled = !S2.text; } };
    draw(); d.showModal(); d.querySelector('[data-shin]')?.focus();
  }
  // "Všechna pole" — one dialog with every field (same live behaviour)
  const fiThumbs = (c) => { const E2 = fiApply(c.E, c.F); return c.E.vars.map((v, i) => `<figure class="fi-thumb"><div class="fi-thumbb">${fiBoard(E2, i, { ...c.F, areas: false, labels: false, clean: true }, c.E)}</div><figcaption><b>${v.r}</b><span class="cap">${v.w} × ${v.h}</span></figcaption></figure>`).join(''); };
  const fiAllHtml = (c) => `<div class="dlg"><div class="dlg-h"><div class="fi-allt"><h2 id="fiAllT">Všechna pole</h2><span class="cap">Vyplníte jednou, propíše se do všech formátů — vpravo vidíte změny hned.</span></div><button type="button" class="btn sm" data-close>Hotovo</button><button type="button" class="btn icon xs ghost" data-close aria-label="Zavřít"><span class="i i-x s16" aria-hidden="true"></span></button></div>
    <div class="fi-allb" style="--fic:${[1, c.E.layers.some((l) => l.input && l.k === 'img' && !l.cont), c.E.layers.some((l) => l.cc && !l.input)].filter(Boolean).length}">${fiForm(c.E, c.F, byId(c.pid), true)}<aside class="fi-thumbs" aria-label="Náhled formátů" data-fithumbs>${fiThumbs(c)}</aside></div></div>`;
  const fiAll = (c) => { let d = document.getElementById('dlgFiAll'); if (!d) { d = document.createElement('dialog'); d.id = 'dlgFiAll'; d.className = 'fi-alldlg'; d.setAttribute('aria-labelledby', 'fiAllT'); document.body.appendChild(d); d.addEventListener('click', (e) => { if (e.target === d || e.target.closest('[data-close]')) d.close(); }); }
    d.innerHTML = fiAllHtml(c); d.showModal(); d.querySelector('input')?.focus(); };
  const fiReAll = (c) => { const d = document.getElementById('dlgFiAll'); if (d && d.open) { const st = [...d.querySelectorAll('.fi-form, .fi-thumbs')].map((x) => x.scrollTop); d.innerHTML = fiAllHtml(c); d.querySelectorAll('.fi-form, .fi-thumbs').forEach((x, i) => { x.scrollTop = st[i] || 0; }); } };
  // Historie exportů — popover: pinned first, rename (pencil) and pin per export, click a row = load its content; "Zobrazit vše (N)" expands the list
  const fiHist = (F) => { const all = F.hist.filter((h) => h.pin).concat(F.hist.filter((h) => !h.pin)), list = F.hall ? all : all.slice(0, 5);
    return `<div class="fi-hpop" role="dialog" aria-label="Historie exportů"><div class="fi-hh"><b>${F.hall ? 'Všechny exporty' : 'Poslední exporty'}</b><span class="cap">Klikem načtete obsah exportu do formuláře.</span></div>
      <ul class="fi-hl${F.hall ? ' all' : ''}">${list.map((h) => `<li class="fi-hi${h.pin ? ' pin' : ''}" data-fihi="${h.id}"><button type="button" class="fi-hload" data-fiload="${h.id}"><b>${esc(h.name || h.at)}</b><span class="cap">${h.name ? `${esc(h.at)} · ` : ''}${esc(h.who)}</span></button>
        <button type="button" class="fi-hb" data-firen="${h.id}" aria-label="Pojmenovat export" data-tip="Pojmenovat"><span class="i i-edit s14" aria-hidden="true"></span></button>
        <button type="button" class="fi-hb${h.pin ? ' on' : ''}" data-fipin="${h.id}" aria-pressed="${h.pin}" aria-label="${h.pin ? 'Odepnout' : 'Připnout nahoru'}" data-tip="${h.pin ? 'Odepnout' : 'Připnout nahoru'}"><span class="i i-pin s14" aria-hidden="true"></span></button></li>`).join('')}</ul>
      ${F.hist.length > 5 ? `<button type="button" class="fi-hmore" data-fihall><span class="i ${F.hall ? 'i-chev-up' : 'i-menu'} s14" aria-hidden="true"></span>${F.hall ? 'Zobrazit méně' : `Zobrazit vše (${F.hist.length})`}</button>` : ''}</div>`; };
  function renderTpFill(pid, tid) {
    const p = byId(pid); if (!p) { renderDetail(pid); return; }
    const T = tpOf(pid), t = T.items.find((x) => x.id === tid); if (!t) { location.replace(`#projekt-${pid}/sablony`); return; }
    const E = tedOf(pid, tid), F = fiOf(pid, tid), ro = viewAs() === 'user', nz = E.vars.filter((v) => !F.off.includes(v.r)).length;
    const sw = (k, on, t2) => `<label class="fi-sw"><input type="checkbox" role="switch" class="sr" data-fisw="${k}"${on ? ' checked' : ''}${F.clean ? ' disabled' : ''}><span class="sw sm${on ? ' on' : ''}" aria-hidden="true"></span>${t2}</label>`;
    $('#view').innerHTML = `<div class="head fi-head"><div style="display:flex;flex-direction:column;gap:8px;min-width:0"><a class="lbl fi-back" href="#projekt-${esc(pid)}/sablony"><span class="i i-arrow-left s14" aria-hidden="true"></span>${esc(p.name)} · Šablony</a><h1>${esc(E.name)}</h1></div>
        <div class="head-tools"><span class="fi-hw"><button type="button" class="btn sm tertiary fi-hbtn${F.hopen ? ' open' : ''}" data-fihist aria-haspopup="dialog" aria-expanded="${F.hopen}"><span class="i i-history s16" aria-hidden="true"></span>Historie exportů<span class="i i-chev-down s14 fi-hchev" aria-hidden="true"></span></button>${F.hopen ? fiHist(F) : ''}</span>
        ${ro ? '' : `<a class="btn sm ghost" href="#projekt-${esc(pid)}/sablony/${esc(tid)}"><span class="i i-edit s16" aria-hidden="true"></span>Upravit design</a>`}
        <button type="button" class="btn sm tertiary fi-sharebtn" data-fishare><span class="i i-share s16" aria-hidden="true"></span>Sdílet na sítě</button>
        <button type="button" class="btn sm tertiary" data-fisave><span class="i ${F.saved ? 'i-check-circle' : 'i-history'} s16" aria-hidden="true"></span>${F.saved ? 'Rozpracované uloženo' : 'Uložit rozpracované'}</button>
        <button type="button" class="btn sm" data-fizipall${nz ? '' : ' disabled'} data-tip="Stáhne formáty, které máte zapnuté nahoře"><span class="i i-download s16" aria-hidden="true"></span>Stáhnout vše (ZIP)${E.vars.length > 1 ? ` <span class="fi-n">${nz}</span>` : ''}</button></div></div>
      <section class="fi-stage" aria-label="Náhled formátů">
        <div class="fi-bar">${sw('areas', F.areas, 'Oblasti k vyplnění')}${sw('labels', F.labels, 'Popisky prvků')}<button type="button" class="btn sm tertiary" data-fiall><span class="i i-list s16" aria-hidden="true"></span>Všechna pole</button><button type="button" class="btn sm ${F.clean ? '' : 'ghost'} fi-clean" data-ficlean aria-pressed="${F.clean}"><span class="i ${F.clean ? 'i-eye-off' : 'i-eye'} s16" aria-hidden="true"></span>Náhled</button>
          <span class="ed-sp"></span><button type="button" class="btn sm ${F.auto ? 'tertiary' : 'ghost'} fi-autob" data-fiauto aria-pressed="${F.auto}" data-tip="Velikost náhledů podle obrazovky — všechny formáty se vejdou na výšku"><span class="i i-fit s16" aria-hidden="true"></span>Přizpůsobit obrazovce</button>${E.vars.length > 1 ? `<div class="fi-chips" role="group" aria-label="Formáty na obrazovce">${E.vars.map((v) => { const on = !F.off.includes(v.r); return `<button type="button" class="chip${on ? ' on' : ''}" data-fivis="${v.r}" aria-pressed="${on}">${on ? '<span class="i i-check s14" aria-hidden="true"></span>' : ''}${v.r}</button>`; }).join('')}</div>` : ''}${szCtl('fi')}</div>
        <div class="fi-grid" data-figrid>${fiCards(E, F)}</div>
      </section><div data-fipop>${fiPopHtml(E, F, p)}</div>`;
    requestAnimationFrame(() => { fiAuto(); const hp = $('.fi-hpop'); if (hp) { const r = hp.getBoundingClientRect(), vw = document.documentElement.clientWidth; if (r.right > vw - 12) hp.style.left = `${Math.min(0, vw - 12 - r.right)}px`; } });
  }
  // previews sized to the screen: every visible format fits the window height and, if possible, all sit in one row; the size slider switches to manual
  const fiAuto = () => { const c = fiCur(); if (!c || !c.F.auto) return; const g = $('[data-figrid]'), mn = $('.main'); if (!g || !mn) return; const vis = c.E.vars.filter((v) => !c.F.off.includes(v.r)); if (!vis.length) return;
    const top = g.getBoundingClientRect().top - mn.getBoundingClientRect().top, H = Math.max(260, mn.clientHeight - top - 40) - 72, gap = 20, n = vis.length, W = g.clientWidth;
    const byH = Math.min(...vis.map((v) => H * v.w / v.h)) + 26, byRow = (W - gap * (n - 1)) / n, px = Math.round(Math.max(240, Math.min(byH, byRow < 240 ? W : byRow)));
    $('#view').style.setProperty('--fimin', px + 'px'); };
  window.addEventListener('resize', () => fiAuto());
  document.addEventListener('input', (e) => { if (e.target.matches?.('[data-szk="fi"]')) { const c = fiCur(); if (c && c.F.auto) { c.F.auto = false; const b = $('[data-fiauto]'); if (b) { b.classList.replace('tertiary', 'ghost'); b.setAttribute('aria-pressed', 'false'); } } } });
  const fiReGrid = () => { const c = fiCur(); if (!c) return; const g = $('[data-figrid]'); if (g) g.innerHTML = fiCards(c.E, c.F); const t = document.querySelector('#dlgFiAll[open] [data-fithumbs]'); if (t) t.innerHTML = fiThumbs(c); };
  const fiRe = () => { const c = fiCur(); if (!c) return; const sc = $('.main'), top = sc ? sc.scrollTop : 0; renderTpFill(c.pid, c.tid); fiAuto(); if (sc) sc.scrollTop = top; fiReAll(c); };
  const fiDirty = (F) => { if (!F.saved) return; F.saved = false; const b = $('[data-fisave]'); if (b) b.innerHTML = '<span class="i i-history s16" aria-hidden="true"></span>Uložit rozpracované'; };
  const fiExport = (c, list) => { const T = tpOf(c.pid), t = T.items.find((x) => x.id === c.tid); if (t) { t.exported = Date.now(); t.uses = (t.uses || 0) + 1; } const d = new Date(), pad = (n) => String(n).padStart(2, '0');
    c.F.hist.unshift({ id: 'h' + Date.now().toString(36), at: `${d.getDate()}. ${d.getMonth() + 1}. ${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}`, who: 'Lukáš Rejda', name: '', pin: false }); };
  const fiSetColor = (c, id, col) => { const { F } = c; F.color[id] = col.toUpperCase(); const pal = fiPal(byId(c.pid)).map((x) => x.toUpperCase()); if (!pal.includes(F.color[id])) F.custom[id] = (F.custom[id] || []).filter((x) => x !== F.color[id]).concat(F.color[id]).slice(-4); if (!F.pop) fiDirty(F); fiRe(); };
  document.addEventListener('click', async (e) => {
    const c = fiCur(); if (!c) return; const { E, F } = c; if (e.target.closest('#dlgFiImg, #dlgFiShare')) return;
    const ed = e.target.closest('[data-fiedit]'); if (ed) { if (F.pop) fiClosePop(F, true); fiOpenPop(c, ed.dataset.fiedit, ed.closest('.fi-area') || ed); return; }
    if (e.target.closest('[data-fipopok]')) { fiClosePop(F, true); return; }
    if (e.target.closest('[data-fipopx]')) { fiClosePop(F, false); return; }
    const vs = e.target.closest('[data-fivis]'); if (vs) { const r = vs.dataset.fivis; F.off = F.off.includes(r) ? F.off.filter((x) => x !== r) : F.off.concat(r); fiRe(); return; }
    const hd = e.target.closest('[data-fihide]'); if (hd) { e.preventDefault(); const id = hd.dataset.fihide; F.hide = F.hide.includes(id) ? F.hide.filter((x) => x !== id) : F.hide.concat(id); fiDirty(F); fiRe(); return; }
    const uh = e.target.closest('[data-fiunhide]'); if (uh) { const cid = uh.dataset.fiunhide, co = E.conts.find((x) => x.id === cid), ids = co.items.concat((F.add[cid] || []).map((r) => r.id)); F.hide = F.hide.filter((x) => !ids.includes(x)); fiDirty(F); fiRe(); return; }
    const dr = e.target.closest('[data-fidelrow]'); if (dr) { e.preventDefault(); const id = dr.dataset.fidelrow; Object.keys(F.add).forEach((k) => { F.add[k] = F.add[k].filter((r) => r.id !== id); }); fiDirty(F); fiRe(); return; }
    const ad = e.target.closest('[data-fiaddrow]'); if (ad) { const cid = ad.dataset.fiaddrow, co = E.conts.find((x) => x.id === cid), last = E.layers.find((l) => l.id === co.items[co.items.length - 1]), n = co.items.length + (F.add[cid] || []).length + 1, nid = 'x' + Date.now().toString(36); F.add[cid] = (F.add[cid] || []).concat({ id: nid, name: last.name.replace(/\d+$/, n), tx: '' }); fiDirty(F);
      if (ad.closest('#dlgFiAll')) { fiRe(); document.querySelector(`#dlgFiAll [data-fiin="${nid}"]`)?.focus(); } else { fiRe(); fiOpenPop(c, nid, document.querySelector(`[data-fiedit="${nid}"]`)?.closest('.fi-area')); } return; }
    const cl = e.target.closest('[data-ficol]'); if (cl) { fiSetColor(c, cl.dataset.ficol, cl.dataset.c); return; }
    const dp = e.target.closest('[data-fidrop]'); if (dp) { const id = dp.dataset.fidrop;
      if (window.EyeDropper) { try { const r = await new window.EyeDropper().open(); fiSetColor(c, id, r.sRGBHex); toast(`Nabraná barva ${r.sRGBHex.toUpperCase()}`); } catch (err) { /* cancelled */ } }
      else { const inp = document.createElement('input'); inp.type = 'color'; inp.value = F.color[id] || '#000000'; inp.style.cssText = 'position:fixed;opacity:0;pointer-events:none'; document.body.appendChild(inp); inp.oninput = () => fiSetColor(c, id, inp.value); inp.onchange = () => inp.remove(); inp.click(); }
      return; }
    if (e.target.closest('[data-fiauto]')) { F.auto = !F.auto; if (!F.auto) szApply('fi', szGet('fi')); fiRe(); return; }
    if (e.target.closest('[data-fishare]')) { if (F.pop) fiClosePop(F, true); fiShare(c); return; }
    if (e.target.closest('[data-fiall]')) { if (F.pop) fiClosePop(F, true); fiAll(c); return; }
    if (e.target.closest('[data-ficlean]')) { F.clean = !F.clean; if (F.pop) fiClosePop(F, true); fiRe(); return; }
    const pn = e.target.closest('[data-fipng]'); if (pn) { fiExport(c, [pn.dataset.fipng]); toast(`Stahuje se „${E.name} ${pn.dataset.fipng.replace(':', 'x')}.png“`); return; }
    if (e.target.closest('[data-fizipall]')) { const list = E.vars.filter((v) => !F.off.includes(v.r)).map((v) => v.r); fiExport(c, list); toast(`Stahuje se ZIP s ${list.length} ${list.length === 1 ? 'formátem' : 'formáty'}`); return; }
    if (e.target.closest('[data-fisave]')) { F.saved = true; fiRe(); toast('Rozpracovaný obsah uložen — najdete ho tu i příště'); return; }
    if (e.target.closest('[data-fihist]')) { F.hopen = !F.hopen; F.hall = false; fiRe(); return; }
    if (e.target.closest('[data-fihall]')) { F.hall = !F.hall; fiRe(); return; }
    const pi = e.target.closest('[data-fipin]'); if (pi) { const h = F.hist.find((x) => x.id === pi.dataset.fipin); h.pin = !h.pin; fiRe(); toast(h.pin ? 'Export připnut nahoru' : 'Export odepnut'); return; }
    const rn = e.target.closest('[data-firen]'); if (rn) { const h = F.hist.find((x) => x.id === rn.dataset.firen), btn = rn.parentElement.querySelector('.fi-hload'); const inp = document.createElement('input'); inp.className = 'fi-hin'; inp.value = h.name || ''; inp.placeholder = h.at; inp.maxLength = 50; inp.setAttribute('aria-label', 'Název exportu'); btn.replaceWith(inp); inp.focus(); inp.select(); let done = false;
      const fin = (ok) => { if (done) return; done = true; inp.onblur = null; if (ok) { h.name = inp.value.trim(); if (h.name) toast(`Export pojmenován „${h.name}“`); } fiRe(); };
      inp.onkeydown = (ev) => { ev.stopPropagation(); if (ev.key === 'Enter') fin(true); if (ev.key === 'Escape') fin(false); }; inp.onblur = () => setTimeout(() => fin(true)); return; }
    const ld = e.target.closest('[data-fiload]'); if (ld) { const h = F.hist.find((x) => x.id === ld.dataset.fiload); F.hopen = false; fiRe(); toast(`Načten obsah exportu „${h.name || h.at}“`); return; }
    if (F.hopen && !e.target.closest('.fi-hpop')) { F.hopen = false; fiRe(); }
  });
  document.addEventListener('input', (e) => { const c = fiCur(); if (!c) return; const f = e.target.closest('[data-fiin]'); if (!f) return; c.F.vals[f.dataset.fiin] = f.value; if (!c.F.pop) fiDirty(c.F); fiReGrid(); });
  document.addEventListener('change', (e) => { const c = fiCur(); if (!c) return; const { F } = c;
    const s = e.target.closest('[data-fisw]'); if (s) { F[s.dataset.fisw] = s.checked; fiRe(); return; }
  });
  document.addEventListener('keydown', (e) => { const c = fiCur(); if (!c) return; const { F } = c;
    if (F.pop && e.target.matches('[data-fipopin]') && e.key === 'Enter') { e.preventDefault(); fiClosePop(F, true); return; }
    if (e.key !== 'Escape') return; if (F.pop) { e.preventDefault(); fiClosePop(F, false); return; } if (F.hopen) { F.hopen = false; fiRe(); $('[data-fihist]')?.focus(); } });
  // drag the pop-up by its grip
  let fiDrag = null;
  document.addEventListener('pointerdown', (e) => { const g = e.target.closest('[data-figrip]'); if (!g) return; const c = fiCur(); if (!c || !c.F.pop) return; e.preventDefault(); fiDrag = { sx: e.clientX, sy: e.clientY, x: c.F.pop.x, y: c.F.pop.y, el: g.closest('.fi-pop') }; });
  document.addEventListener('pointermove', (e) => { if (!fiDrag) return; const c = fiCur(); if (!c || !c.F.pop) { fiDrag = null; return; } const P = c.F.pop, w = fiDrag.el.offsetWidth, h = fiDrag.el.offsetHeight; P.x = Math.max(8, Math.min(innerWidth - w - 8, fiDrag.x + e.clientX - fiDrag.sx)); P.y = Math.max(8, Math.min(innerHeight - h - 8, fiDrag.y + e.clientY - fiDrag.sy)); fiDrag.el.style.left = P.x + 'px'; fiDrag.el.style.top = P.y + 'px'; });
  document.addEventListener('pointerup', () => { fiDrag = null; });
  /* ---------- Vzhled: Tmavý / Světlý — radio switch in the sidebar above the profile ----------
     For now the choice applies only on the "Všechny projekty" dashboard (#projekty); other pages keep their current look,
     there the switch is shown disabled with a note. The choice is remembered (localStorage wb-theme). */
  (() => {
    const get = () => { try { return localStorage.getItem('wb-theme') === 'light' ? 'light' : 'dark'; } catch (e) { return 'dark'; } };
    const box = document.createElement('div'); box.className = 'sb-theme'; box.setAttribute('role', 'radiogroup'); box.setAttribute('aria-label', 'Vzhled');
    const draw = () => { const t = get(), on = S.route === 'projekty';
      box.classList.toggle('off', !on); box.title = on ? '' : 'Světlý režim zatím zkoušíme jen na přehledu Všechny projekty';
      box.innerHTML = [['dark', 'i-moon', 'Tmavý'], ['light', 'i-sun', 'Světlý']].map(([k, i, n]) => `<label class="sb-th${t === k ? ' on' : ''}" title="${n} režim"><input type="radio" name="wbTheme" class="sr" value="${k}"${t === k ? ' checked' : ''}${on ? '' : ' disabled'}><span class="i ${i} s16" aria-hidden="true"></span><span class="hide-c">${n}</span></label>`).join('')
        + `<button type="button" class="sb-thc" data-thtoggle aria-label="Přepnout na ${t === 'dark' ? 'světlý' : 'tmavý'} režim" title="${on ? `Přepnout na ${t === 'dark' ? 'světlý' : 'tmavý'} režim` : box.title}"${on ? '' : ' disabled'}><span class="i ${t === 'dark' ? 'i-moon' : 'i-sun'} s16" aria-hidden="true"></span></button>`; };
    const apply = () => { if (S.route === 'projekty') document.documentElement.setAttribute('data-theme', get()); else document.documentElement.removeAttribute('data-theme'); draw(); };
    const set = (t) => { try { localStorage.setItem('wb-theme', t); } catch (e) { /* ignore */ } apply(); };
    box.addEventListener('change', (e) => { if (e.target.name === 'wbTheme') set(e.target.value); });
    box.addEventListener('click', (e) => { if (e.target.closest('[data-thtoggle]')) set(get() === 'dark' ? 'light' : 'dark'); });
    const u = document.querySelector('.sb-user'); if (u) u.parentNode.insertBefore(box, u);
    window.addEventListener('hashchange', () => setTimeout(apply)); setTimeout(apply);
  })();
  /* ---------- role preview (prototype only): Administrátor / Grafik / Klient ----------
     Administrátor: everything. Grafik: Všechny projekty + Rychlá navigace + project menu, no Administrace, no Moduly projektu box.
     Klient (role Uživatel): no Všechny projekty, no Rychlá navigace, no Administrace — only the Projekt menu of his project (+ Můj účet in the profile menu).
     In the app the role comes from users.role; here the profile menu switches it. */
  const VIEW_ROLES = { admin: 'Administrátor', designer: 'Grafik', user: 'Klient' };
  let VIEW_AS = VIEW_ROLES[store.get('wb-viewas')] ? store.get('wb-viewas') : 'admin';
  const viewAs = () => VIEW_AS;
  const clientPid = () => (byId('brusperk') ? 'brusperk' : (S.projects[0] || {}).id);
  function applyViewAs() {
    document.body.dataset.as = VIEW_AS;
    const label = VIEW_ROLES[VIEW_AS], btn = $('#meBtn');
    btn.querySelector('.cap').textContent = VIEW_AS === 'admin' ? 'Administrátor' : label + ' · náhled';
    btn.title = `Lukáš · ${label}`; btn.setAttribute('aria-label', `Profil: Lukáš, ${label}`);
    $('#meMenu').querySelectorAll('[data-viewas]').forEach((b) => { const on = b.dataset.viewas === VIEW_AS; b.setAttribute('aria-checked', String(on)); b.querySelector('.end').innerHTML = on ? '<span class="chk" aria-hidden="true"><span class="i i-check"></span></span>' : ''; });
  }
  /* pages a role cannot open send it home */
  function viewGuard() {
    const admin = ['uzivatele', 'statistiky', 'poptavky'].includes(S.route) || S.route.startsWith('uzivatel-') || S.route.startsWith('statistiky-');
    if (VIEW_AS === 'designer' && admin) { location.replace('#projekty'); return true; }
    if (VIEW_AS === 'user') {
      const pid = clientPid(), ok = S.route === 'projekt-' + pid || S.route.startsWith('projekt-' + pid + '/') || S.route === 'ucet';
      if (!ok && pid) { location.replace('#projekt-' + pid); return true; }
    }
    return false;
  }
  $('#logoutBtn').insertAdjacentHTML('beforebegin', `<div class="menu-sep"></div><div class="me-sec lbl">Náhled role · jen prototyp</div>${Object.entries(VIEW_ROLES).map(([k, t]) => `<button type="button" role="menuitemradio" aria-checked="false" class="mi" data-viewas="${k}"><span class="i ${k === 'admin' ? 'i-shield' : k === 'designer' ? 'i-palette' : 'i-user'} s16" aria-hidden="true"></span>${t}<span class="end"></span></button>`).join('')}<div class="menu-sep"></div>`);
  $('#meMenu').addEventListener('click', (e) => {
    const b = e.target.closest('[data-viewas]'); if (!b || b.dataset.viewas === VIEW_AS) return;
    VIEW_AS = b.dataset.viewas; store.set('wb-viewas', VIEW_AS); applyViewAs();
    toast(`Náhled: ${VIEW_ROLES[VIEW_AS]}`);
    if (!viewGuard()) render();
  });
  applyViewAs();
  /* ---------- boot ---------- */
  route();
  (async () => {
    const c = window.claude;
    db = c && c.use ? await c.use('db') : null;
    S.assets = c && c.use ? await c.use('assets') : null;
    if (!db) { S.dbOk = false; S.loaded = true; render(); return; }
    db.collection('users').onSnapshot((snap) => {
      S.users = snap.docs.map((d) => ({ id: d.id, ...d.data() }));
      if (S.share) renderShare();
      if ($('#dlgProf').open && S.prof) openProfile(S.prof.id);
      if (S.route === 'statistiky' || S.route.startsWith('statistiky-')) { renderStats(); if ($('#dlgStat').open && S.st.open) openStatClient(S.st.open); }
      if (S.route === 'uzivatele' || S.route.startsWith('uzivatel-')) renderUserRows(); else updateReqCount();
    }, () => {});
    db.collection('projects').onSnapshot((snap) => {
      S.projects = snap.docs.map((d) => ({ id: d.id, ...d.data() }));
      S.loaded = true;
      if (S.highlight && !byId(S.highlight)) S.highlight = null;
      render();
    }, () => { S.dbOk = false; S.loaded = true; render(); });
  })();
})();

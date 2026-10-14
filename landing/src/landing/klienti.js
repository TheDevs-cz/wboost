/* Wboost landing — `/klienti` only: calculator, comparison, dialogs, tag input, chat. */
(function () {
  'use strict';

  /* ---- Kalkulačka ----
   * Wboost price and formats per output are the design's numbers
   * ("LP - klienti.html": WB_PRICE, WB_VARIANTS), confirmed for launch 2026-10-14. */
  var WB_PRICE = 2500, WB_VARIANTS = 4;
  var kc = function (n) { return Math.round(n).toLocaleString('cs-CZ').replace(/ /g, ' ') + ' Kč'; };
  var calc = document.querySelector('[data-calc]');
  if (calc) {
    var n = calc.querySelector('[data-i=n]'), k = calc.querySelector('[data-i=k]');
    var o = function (s) { return calc.querySelector('[data-o=' + s + ']'); };
    var u = function () {
      o('n').textContent = n.value;
      o('k').textContent = kc(k.value);
      o('tn').textContent = n.value;
      o('tk').textContent = kc(k.value);
      o('tu').textContent = kc(k.value / n.value);
      o('wn').textContent = n.value * WB_VARIANTS;
      o('wk').textContent = kc(WB_PRICE);
      o('wu').textContent = kc(WB_PRICE / (n.value * WB_VARIANTS));
    };
    n.addEventListener('input', u);
    k.addEventListener('input', u);
    u();
  }

  /* ---- "Špatně / Správně" switch ---- */
  document.querySelectorAll('.rz-sw button').forEach(function (b) {
    b.addEventListener('click', function () {
      var card = b.closest('.rz');
      card.setAttribute('data-s', b.getAttribute('data-v'));
      card.querySelectorAll('.rz-sw button').forEach(function (x) {
        x.classList.toggle('on', x === b);
        x.setAttribute('aria-pressed', String(x === b));
      });
    });
  });

  /* ---- Dialogs ---- */
  var lastFocus = null;
  function mdClose() {
    var open = document.querySelector('.md:not([hidden])');
    document.querySelectorAll('.md').forEach(function (m) { m.hidden = true; });
    document.body.style.overflow = '';
    if (open && lastFocus) { lastFocus.focus(); lastFocus = null; }
  }
  function mdOpen(id, opener) {
    mdClose();
    var m = document.getElementById(id);
    if (!m) return;
    lastFocus = opener || document.activeElement;
    m.hidden = false;
    document.body.style.overflow = 'hidden';
    var x = m.querySelector('[data-md-close]');
    if (x) x.focus({ preventScroll: true });
    document.dispatchEvent(new CustomEvent('wb:md-open', { detail: id }));
  }
  window.wbMdOpen = mdOpen;
  document.querySelectorAll('[data-md-open]').forEach(function (b) {
    b.addEventListener('click', function () { mdOpen(b.getAttribute('data-md-open'), b); });
  });
  document.querySelectorAll('.md').forEach(function (m) {
    m.addEventListener('click', function (e) { if (e.target === m) mdClose(); });
    m.querySelectorAll('[data-md-close]').forEach(function (x) { x.addEventListener('click', mdClose); });
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') mdClose(); });

  /* ---- "Máte svého grafika?" reveals the designer fields ---- */
  var designer = document.getElementById('grafF');
  document.querySelectorAll('[data-designer-toggle]').forEach(function (r) {
    r.addEventListener('change', function () { designer.hidden = r.value !== 'Ano'; });
  });

  /* ---- "Na čem byste si to chtěli vyzkoušet?" tag input ---- */
  var OPT = ['Plakát na akci', 'Příspěvek na Facebook', 'Stories', 'E-mailový podpis', 'Leták', 'Zpravodaj', 'Vizitka', 'Jídelníček', 'Poukázka'];
  var PH = 'např. plakát na akci, příspěvek na Facebook, e-mailový podpis';
  document.querySelectorAll('[data-tgi]').forEach(function (w) {
    var box = w.querySelector('.tgi-box'), inp = box.querySelector('input'), dd = w.querySelector('.tgi-dd'),
      hid = w.querySelector('input[type=hidden]'), sel = [], act = 0;
    var lower = function (s) { return s.toLowerCase(); };

    function tags() {
      box.querySelectorAll('.tgi-tag').forEach(function (t) { t.remove(); });
      sel.forEach(function (v, i) {
        var s = document.createElement('span');
        s.className = 'tgi-tag';
        s.appendChild(document.createTextNode(v));
        var x = document.createElement('button');
        x.type = 'button';
        x.setAttribute('aria-label', 'Odebrat ' + v);
        x.textContent = '✕';
        x.addEventListener('click', function (e) { e.stopPropagation(); sel.splice(i, 1); tags(); list(); inp.focus(); });
        s.appendChild(x);
        box.insertBefore(s, inp);
      });
      hid.value = sel.join(', ');
      inp.placeholder = sel.length ? 'Přidat další…' : PH;
    }
    function items() {
      var q = lower(inp.value.trim());
      var r = OPT.filter(function (opt) { return sel.indexOf(opt) < 0 && lower(opt).indexOf(q) > -1; }).map(function (opt) { return { v: opt }; });
      if (q && !OPT.some(function (opt) { return lower(opt) === q; }) && sel.map(lower).indexOf(q) < 0) r.push({ v: inp.value.trim().replace(/,/g, ' '), n: 1 });
      return r;
    }
    function list() {
      var r = items();
      act = Math.min(act, Math.max(r.length - 1, 0));
      dd.innerHTML = '';
      r.forEach(function (opt, i) {
        var li = document.createElement('li');
        li.setAttribute('role', 'option');
        li.id = 'tgi-opt-' + i;
        li.className = (opt.n ? 'new ' : '') + (i === act ? 'act' : '');
        li.textContent = opt.n ? '+ Přidat „' + opt.v + '“' : opt.v;
        li.addEventListener('mousedown', function (e) { e.preventDefault(); add(opt.v); });
        dd.appendChild(li);
      });
      var open = r.length > 0 && document.activeElement === inp;
      dd.hidden = !open;
      w.classList.toggle('open', open);
      inp.setAttribute('aria-expanded', String(open));
      if (open) inp.setAttribute('aria-activedescendant', 'tgi-opt-' + act); else inp.removeAttribute('aria-activedescendant');
    }
    function add(v) {
      if (v && sel.indexOf(v) < 0) sel.push(v);
      inp.value = '';
      act = 0;
      tags();
      list();
    }
    box.addEventListener('click', function () { inp.focus(); });
    inp.addEventListener('focus', list);
    inp.addEventListener('input', function () { act = 0; list(); });
    inp.addEventListener('blur', function () {
      /* Typed but not confirmed — keep it rather than lose it on submit. */
      if (inp.value.trim()) add(inp.value.trim().replace(/,/g, ' '));
      dd.hidden = true;
      w.classList.remove('open');
      inp.setAttribute('aria-expanded', 'false');
    });
    inp.addEventListener('keydown', function (e) {
      var r = items();
      if (e.key === 'ArrowDown') { e.preventDefault(); act = Math.min(act + 1, r.length - 1); list(); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); act = Math.max(act - 1, 0); list(); }
      else if (e.key === 'Enter' || e.key === ',') {
        if (r.length || inp.value.trim()) { e.preventDefault(); add(r[act] ? r[act].v : inp.value.trim()); }
      }
      else if (e.key === 'Backspace' && !inp.value && sel.length) { sel.pop(); tags(); list(); }
      else if (e.key === 'Escape') { e.stopPropagation(); inp.blur(); }
    });
    tags();
  });

  /* ---- "Máte dotaz?" chat → POST /poptavka (source=chat) ----
   * Not real-time: the first message is held until the visitor leaves an
   * e-mail, then delivered like a form lead; later messages follow it. */
  var lc = document.getElementById('lc');
  if (lc) {
    var body = document.getElementById('lcBody'), input = document.getElementById('lcIn'),
      form = lc.querySelector('[data-lc-form]'), sendBtn = form.querySelector('button'),
      toggleBtn = lc.querySelector('[data-lc-toggle]');
    var pending = [], email = null, asked = false;
    var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    var MAIL_LINK = '<a href="mailto:info@wantoo.cz">info@wantoo.cz</a>';

    var lcToggle = function (open) {
      var o = open === undefined ? !lc.classList.contains('open') : open;
      lc.classList.toggle('open', o);
      toggleBtn.setAttribute('aria-expanded', String(o));
      if (o) setTimeout(function () { input.focus(); }, 250);
    };
    toggleBtn.addEventListener('click', function () { lcToggle(); });
    lc.querySelector('[data-lc-close]').addEventListener('click', function () { lcToggle(false); });

    var say = function (text, me, html) {
      var m = document.createElement('div');
      m.className = 'lc-m' + (me ? ' me' : '');
      if (html) m.innerHTML = text; else m.textContent = text;
      body.appendChild(m);
      body.scrollTop = body.scrollHeight;
    };
    var askEmail = function () {
      input.type = 'email';
      input.placeholder = 'vas@email.cz';
      input.setAttribute('aria-label', 'Váš e-mail');
      input.setAttribute('autocomplete', 'email');
    };
    var askMessage = function () {
      input.type = 'text';
      input.placeholder = 'Napište zprávu…';
      input.setAttribute('aria-label', 'Zpráva');
      input.setAttribute('autocomplete', 'off');
    };
    var deliver = function (messages) {
      var fd = new FormData();
      fd.append('source', 'chat');
      fd.append('email', email);
      fd.append('message', messages.join('\n\n'));
      sendBtn.disabled = true;
      return window.wbSendLead(fd).then(function (r) {
        if (!r.ok) throw new Error(String(r.status));
      }).finally(function () { sendBtn.disabled = false; });
    };

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var t = input.value.trim();
      if (!t) return;
      input.value = '';
      say(t, true);

      if (email) {
        deliver([t]).then(function () {
          say('Díky, i tohle jsme dostali.');
        }).catch(function () {
          say('Zprávu se nepodařilo odeslat. Napište nám prosím na ' + MAIL_LINK + '.', false, true);
        });
        return;
      }

      if (!asked) {
        pending.push(t);
        asked = true;
        askEmail();
        setTimeout(function () { say('Díky! Na jaký e-mail vám máme odpovědět?'); input.focus(); }, 400);
        return;
      }

      if (!EMAIL_RE.test(t)) {
        if (input.type === 'email') {
          say('Tohle nevypadá jako e-mailová adresa. Zkuste ji prosím napsat znovu.');
        }
        return;
      }

      email = t;
      deliver(pending).then(function () {
        pending = [];
        askMessage();
        say('Děkujeme, za chvíli se vám ozveme na ' + email + '.');
      }).catch(function () {
        email = null;
        say('Zprávu se nepodařilo odeslat. Zkuste to prosím znovu, nebo nám napište na ' + MAIL_LINK + '.', false, true);
      });
    });
  }
})();

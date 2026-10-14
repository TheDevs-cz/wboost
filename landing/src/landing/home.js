/* Wboost landing — `/` only: calculator, story/team tabs, "Co právě tvoříme". */
(function () {
  'use strict';

  var kc = function (n) { return Number(n).toLocaleString('cs-CZ').replace(/ /g, ' ') + ' Kč'; };

  /* ---- Kalkulačka: clients × monthly price ---- */
  var calc = document.querySelector('[data-calc]');
  if (calc) {
    var c = calc.querySelector('[data-i=c]'), p = calc.querySelector('[data-i=p]');
    var u = function () {
      calc.querySelector('[data-o=c]').textContent = c.value;
      calc.querySelector('[data-o=p]').textContent = kc(p.value);
      calc.querySelector('[data-o=r]').textContent = kc(c.value * p.value);
    };
    c.addEventListener('input', u);
    p.addEventListener('input', u);
    u();
  }

  /* ---- "Jak to začalo?" / "Kdo za tím stojí?" tabs ---- */
  var tabs = Array.prototype.slice.call(document.querySelectorAll('#kontakt .sx-tab'));
  function selectTab(i) {
    tabs.forEach(function (t, k) {
      var on = k === i;
      t.classList.toggle('on', on);
      t.setAttribute('aria-selected', String(on));
      document.getElementById(t.getAttribute('aria-controls')).hidden = !on;
    });
  }
  tabs.forEach(function (t, i) {
    t.addEventListener('click', function () { selectTab(i); });
    t.addEventListener('keydown', function (e) {
      if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
      var n = (i + (e.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length;
      selectTab(n);
      tabs[n].focus();
    });
  });

  /* The footer's "Kdo za tím stojí?" (also linked from /klienti) opens the team tab. */
  function openTeam() {
    selectTab(1);
    var k = document.getElementById('kontakt');
    if (k && window.wbScrollTo) window.wbScrollTo(k);
  }
  document.querySelectorAll('a[href="#kdo-za-tim-stoji"]').forEach(function (a) {
    a.addEventListener('click', function (e) { e.preventDefault(); openTeam(); });
  });
  if (location.hash === '#kdo-za-tim-stoji') {
    selectTab(1);
    addEventListener('load', function () { setTimeout(openTeam, 0); });
  }

  /* ---- "Co právě tvoříme" floating panel (open state remembered per visitor) ---- */
  var wip = document.getElementById('wip');
  if (wip) {
    var btn = wip.querySelector('[data-wip-toggle]');
    var toggle = function (open) {
      var o = open === undefined ? !wip.classList.contains('open') : open;
      wip.classList.toggle('open', o);
      btn.setAttribute('aria-expanded', String(o));
      try { localStorage.setItem('wboost-wip-open', o ? '1' : '0'); } catch (e) { /* storage blocked */ }
    };
    btn.addEventListener('click', function () { toggle(); });
    wip.querySelector('[data-wip-close]').addEventListener('click', function () { toggle(false); });
    wip.querySelectorAll('[data-wip-filter]').forEach(function (b) {
      b.addEventListener('click', function () {
        var cat = b.getAttribute('data-wip-filter');
        wip.querySelectorAll('[data-wip-filter]').forEach(function (x) { x.classList.toggle('on', x === b); });
        wip.querySelectorAll('.wip-item').forEach(function (li) { li.hidden = cat !== 'all' && li.getAttribute('data-cat') !== cat; });
      });
    });
    try { if (localStorage.getItem('wboost-wip-open') === '1') toggle(true); } catch (e) { /* storage blocked */ }
  }
})();

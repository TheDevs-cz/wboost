/* Wboost landing — shared behaviour (nav, anchors, sliders, steps, lead forms). */
(function () {
  'use strict';

  var nav = document.getElementById('nav');

  /* ---- Sticky nav: border once scrolled, burger menu on narrow screens ---- */
  function navToggle(open) {
    if (!nav) return;
    var o = open === undefined ? !nav.classList.contains('open') : open;
    nav.classList.toggle('open', o);
    var b = nav.querySelector('[data-nav-toggle]');
    if (b) b.setAttribute('aria-expanded', String(o));
  }
  if (nav) {
    var burger = nav.querySelector('[data-nav-toggle]');
    if (burger) burger.addEventListener('click', function () { navToggle(); });
    nav.querySelectorAll('nav a').forEach(function (a) { a.addEventListener('click', function () { navToggle(false); }); });
    var onScroll = function () { nav.classList.toggle('sc', window.scrollY > 8); };
    addEventListener('scroll', onScroll, { passive: true });
    onScroll();
  }

  /* ---- In-page anchors scroll below the sticky nav ---- */
  function scrollToEl(t) {
    var off = nav ? nav.offsetHeight : 0;
    scrollTo({ top: t.getBoundingClientRect().top + scrollY - off, behavior: 'smooth' });
  }
  window.wbScrollTo = scrollToEl;
  document.addEventListener('click', function (e) {
    var a = e.target.closest('a[href^="#"]');
    if (!a) return;
    var href = a.getAttribute('href');
    if (href.length < 2) return;
    var t = document.getElementById(href.slice(1));
    if (!t) return;
    e.preventDefault();
    scrollToEl(t);
    if (history.replaceState) history.replaceState(null, '', href);
  });

  /* ---- Range sliders paint their filled part via --v ---- */
  document.querySelectorAll('.field input[type=range]').forEach(function (r) {
    function u() { r.style.setProperty('--v', ((r.value - r.min) / (r.max - r.min) * 100) + '%'); }
    r.addEventListener('input', u);
    u();
  });

  /* ---- "Jak to funguje": swipeable on mobile, dots follow the scroll ---- */
  document.querySelectorAll('[data-steps]').forEach(function (s) {
    var dotsWrap = s.parentNode.querySelector('[data-steps-dots]');
    if (!dotsWrap) return;
    var dots = Array.prototype.slice.call(dotsWrap.children);
    var items = Array.prototype.slice.call(s.querySelectorAll('.step'));
    var off = function () { return items[0].offsetLeft; };
    s.addEventListener('scroll', function () {
      var best = 0, min = 1e9;
      items.forEach(function (c, i) {
        var v = Math.abs(c.offsetLeft - off() - s.scrollLeft);
        if (v < min) { min = v; best = i; }
      });
      if (s.scrollLeft + s.clientWidth >= s.scrollWidth - 4) best = items.length - 1;
      dots.forEach(function (d, i) { d.classList.toggle('on', i === best); });
    }, { passive: true });
    dots.forEach(function (d, i) {
      d.addEventListener('click', function () { s.scrollTo({ left: items[i].offsetLeft - off(), behavior: 'smooth' }); });
    });
  });

  /* ---- Lead forms → POST /poptavka (LandingLeadController in the app) ---- */
  var MAIL = 'info@wantoo.cz';
  var GENERIC = 'Odeslání se nepovedlo. Zkuste to prosím znovu, nebo nám napište na <a href="mailto:' + MAIL + '">' + MAIL + '</a>.';
  var TOO_MANY = 'Odeslali jste toho teď hodně najednou. Zkuste to prosím za chvíli, nebo nám napište na <a href="mailto:' + MAIL + '">' + MAIL + '</a>.';
  var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

  function clearErrors(form) {
    form.querySelectorAll('.field.invalid').forEach(function (f) { f.classList.remove('invalid'); });
    form.querySelectorAll('.err, .fail').forEach(function (el) { el.remove(); });
  }

  function fieldError(form, name, msg) {
    var input = form.querySelector('[name="' + name + '"]');
    var field = input && (input.closest('.field') || input.parentNode);
    if (!field) return false;
    field.classList.add('invalid');
    var err = document.createElement('span');
    err.className = 'err';
    err.textContent = msg;
    field.appendChild(err);
    if (input.type !== 'hidden') input.setAttribute('aria-invalid', 'true');
    return true;
  }

  function formFail(form, html) {
    var box = document.createElement('p');
    box.className = 'fail';
    box.setAttribute('role', 'alert');
    box.innerHTML = html;
    var btn = form.querySelector('[type=submit]');
    form.insertBefore(box, btn);
  }

  function showDone(form) {
    var done = form.parentNode.querySelector('[data-lead-done]');
    if (!done) return;
    form.hidden = true;
    done.hidden = false;
    done.setAttribute('tabindex', '-1');
    done.focus({ preventScroll: true });
  }

  function validate(form) {
    var ok = true;
    form.querySelectorAll('[required]').forEach(function (input) {
      if (!input.value.trim()) {
        fieldError(form, input.name, input.type === 'email' ? 'Vyplňte prosím e-mail.' : 'Vyplňte prosím toto pole.');
        ok = false;
      }
    });
    form.querySelectorAll('input[type=email]').forEach(function (input) {
      var v = input.value.trim();
      if (v && !EMAIL_RE.test(v) && !input.closest('[hidden]')) {
        fieldError(form, input.name, 'Tohle nevypadá jako e-mailová adresa.');
        ok = false;
      }
    });
    return ok;
  }

  /** Shared with the /klienti chat bubble. Resolves {ok, errors?, status}. */
  window.wbSendLead = function (body) {
    return fetch('/poptavka', { method: 'POST', body: body, headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(function (res) {
        return res.json().catch(function () { return {}; }).then(function (data) {
          return { ok: res.ok, status: res.status, errors: data && data.errors };
        });
      });
  };

  document.querySelectorAll('form[data-lead]').forEach(function (form) {
    form.querySelectorAll('input').forEach(function (input) {
      input.addEventListener('input', function () {
        var f = input.closest('.field');
        if (!f || !f.classList.contains('invalid')) return;
        f.classList.remove('invalid');
        input.removeAttribute('aria-invalid');
        var e = f.querySelector('.err');
        if (e) e.remove();
      });
    });

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      clearErrors(form);
      if (!validate(form)) {
        var first = form.querySelector('[aria-invalid=true]');
        if (first) first.focus();
        return;
      }
      var btn = form.querySelector('[type=submit]');
      btn.disabled = true;
      window.wbSendLead(new FormData(form)).then(function (r) {
        if (r.ok) { showDone(form); return; }
        if (r.status === 422 && r.errors) {
          var shown = false;
          Object.keys(r.errors).forEach(function (k) { shown = fieldError(form, k, r.errors[k]) || shown; });
          if (!shown) formFail(form, GENERIC);
          return;
        }
        formFail(form, r.status === 429 ? TOO_MANY : GENERIC);
      }).catch(function () {
        formFail(form, GENERIC);
      }).then(function () { btn.disabled = false; });
    });
  });

  /* No-JS fallback round trip: the endpoint redirects back with ?odeslano / ?chyba. */
  var q = location.search;
  if (/[?&](odeslano|chyba)\b/.test(q)) {
    var form = document.querySelector('form[data-lead]');
    if (form) {
      if (/odeslano/.test(q)) showDone(form); else formFail(form, GENERIC);
    }
    if (history.replaceState) history.replaceState(null, '', location.pathname + location.hash);
  }
})();

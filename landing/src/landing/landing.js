/* Wboost landing — every interactive piece of `/`. No dependencies. */
(function () {
  const $ = (s, r) => (r || document).querySelector(s);
  const $$ = (s, r) => [...(r || document).querySelectorAll(s)];
  const RM = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
  window.WB = { $, $$, RM, sleep };
  // Nav
  const nav = $('#nav'),
    menuBtn = $('#menuBtn'),
    links = $('#navLinks');
  const onScroll = () => nav.classList.toggle('scrolled', scrollY > 8);
  addEventListener('scroll', onScroll, { passive: true });
  onScroll();
  const closeMenu = () => {
    links.classList.remove('open');
    menuBtn.setAttribute('aria-expanded', 'false');
    if (menuBtn.classList.contains('burger')) menuBtn.setAttribute('aria-label', 'Otevřít menu');
    else menuBtn.textContent = 'Menu';
  };
  menuBtn.addEventListener('click', () => {
    const open = links.classList.toggle('open');
    menuBtn.setAttribute('aria-expanded', open);
    if (menuBtn.classList.contains('burger')) menuBtn.setAttribute('aria-label', open ? 'Zavřít menu' : 'Otevřít menu');
    else menuBtn.textContent = open ? 'Zavřít' : 'Menu';
  });
  $$('a', links).forEach((a) => a.addEventListener('click', closeMenu));
  addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeMenu();
  });

  // Reveal on scroll: only elements below the fold start hidden
  const once = (el, fn, th) => {
    const io = new IntersectionObserver(
      (es) =>
        es.forEach((en) => {
          if (en.isIntersecting) {
            io.disconnect();
            fn();
          }
        }),
      { threshold: th || 0.2 },
    );
    io.observe(el);
  };
  window.WB.once = once;
  if (!RM) {
    $$('.rv').forEach((el) => {
      if (el.getBoundingClientRect().top > innerHeight * 0.92) {
        el.classList.add('pre');
        once(el, () => el.classList.remove('pre'), 0.12);
      }
    });
  }

  // Loop: one client message in the centre, the track slides left forever
  const loop = $('#loop');
  if (loop) {
    const trk = $('#loopTrk'),
      orig = $$('.lp', trk),
      N = orig.length;
    // clone the set before and after for a seamless loop
    orig.forEach((li) => trk.appendChild(li.cloneNode(true)));
    orig
      .slice()
      .reverse()
      .forEach((li) => trk.insertBefore(li.cloneNode(true), trk.firstChild));
    const all = $$('.lp', trk),
      count = $('#loopCount'),
      pauseBtn = $('#loopPause');
    let c = N,
      timer = null,
      userPaused = false,
      inView = false;
    const place = (jump) => {
      const w = all[0].getBoundingClientRect().width;
      const tx = loop.clientWidth / 2 - (c + 0.5) * w + (innerWidth <= 600 ? 0 : w * 0.18);
      if (jump) loop.classList.add('jump');
      trk.style.setProperty('--tx', tx + 'px');
      all.forEach((li, k) => {
        li.classList.toggle('on', k === c);
        li.classList.toggle('near', Math.abs(k - c) === 1);
        li.classList.toggle('settled', !!jump && k === c);
        li.setAttribute('aria-hidden', k === c ? 'false' : 'true');
      });
      count.textContent = (c % N) + 1 + ' / ' + N;
      if (jump) {
        void trk.offsetWidth;
        requestAnimationFrame(() => requestAnimationFrame(() => loop.classList.remove('jump')));
      }
    };
    const normalize = () => {
      go.t = null;
      c = N + ((((c - N) % N) + N) % N);
      place(true);
    };
    const go = (d) => {
      if (go.t) {
        clearTimeout(go.t);
        normalize();
      }
      c += d;
      place(false);
      if (c >= 2 * N || c < N) go.t = setTimeout(normalize, 4700);
    };
    const run = () => {
      clearInterval(timer);
      timer = null;
      if (!userPaused && inView && !RM) timer = setInterval(() => go(1), 8000);
    };
    const setPaused = (p) => {
      userPaused = p;
      pauseBtn.setAttribute('aria-pressed', p);
      pauseBtn.textContent = p ? 'Přehrát' : 'Pozastavit';
      run();
    };
    $('#loopNext').addEventListener('click', () => {
      go(1);
      run();
    });
    $('#loopPrev').addEventListener('click', () => {
      go(-1);
      run();
    });
    pauseBtn.addEventListener('click', () => setPaused(!userPaused));
    loop.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowRight') {
        go(1);
        run();
      }
      if (e.key === 'ArrowLeft') {
        go(-1);
        run();
      }
    });
    new IntersectionObserver(
      (es) =>
        es.forEach((e) => {
          inView = e.isIntersecting;
          run();
        }),
      { threshold: 0.3 },
    ).observe(loop);
    addEventListener('resize', () => place(true));
    place(true);
    if (RM) pauseBtn.hidden = true;
  }

  // Like hearts: shared state across loop clones, remembered per viewer
  (function () {
    let saved = {};
    try {
      saved = JSON.parse(localStorage.getItem('wb-likes') || '{}');
    } catch (_) {}
    const sync = (i) => {
      const on = !!saved[i];
      $$('.like[data-i="' + i + '"]').forEach((b) => {
        const n = +b.dataset.n + (on ? 1 : 0);
        b.setAttribute('aria-pressed', on);
        b.querySelector('.like-n').textContent = new Intl.NumberFormat('cs-CZ').format(n);
        b.setAttribute('aria-label', 'To znám, ' + n + ' srdíček' + (on ? ', označeno' : ''));
      });
    };
    document.addEventListener('click', (e) => {
      const b = e.target.closest('.like');
      if (!b) return;
      const i = b.dataset.i;
      saved[i] = !saved[i];
      sync(i);
      try {
        localStorage.setItem('wb-likes', JSON.stringify(saved));
      } catch (_) {}
      if (saved[i] && !RM) {
        b.classList.remove('pop');
        void b.offsetWidth;
        b.classList.add('pop');
        for (let k = 0; k < 8; k++) {
          const d = document.createElement('i'),
            a = (k / 8) * Math.PI * 2;
          d.style.setProperty('--dx', Math.cos(a) * 28 + 'px');
          d.style.setProperty('--dy', Math.sin(a) * 28 + 'px');
          b.appendChild(d);
          setTimeout(() => d.remove(), 650);
        }
      }
    });
    new Set($$('.like').map((b) => b.dataset.i)).forEach(sync);
  })();
  // Roadmap filter
  $$('.filters button').forEach((b) =>
    b.addEventListener('click', () => {
      $$('.filters button').forEach((x) => x.setAttribute('aria-pressed', x === b));
      $$('#road li').forEach((li) => (li.hidden = b.dataset.f !== 'all' && li.dataset.cat !== b.dataset.f));
    }),
  );

  // Lead forms post to the app's /poptavka. `dwell` (ms since page load) feeds the server's time trap.
  const SEND_FAILED = 'Odeslání se nepovedlo. Zkus to znovu, nebo napiš na info@wantoo.cz.';
  window.WB.sendLead = async (form) => {
    const body = new FormData(form);
    body.set('dwell', String(Math.round(performance.now())));
    let res;
    try {
      res = await fetch(form.getAttribute('action'), { method: 'POST', body, headers: { Accept: 'application/json' } });
    } catch (_) {
      return { message: SEND_FAILED };
    }
    if (res.ok) return null;
    if (res.status === 429)
      return { message: 'Odesíláš toho teď hodně najednou. Zkus to prosím za chvíli, nebo napiš na info@wantoo.cz.' };
    const data = await res.json().catch(() => ({}));
    return { message: SEND_FAILED, fields: data.errors || {} };
  };
})();

// Story invite: expand the form, validate, post it as source=historka
(function () {
  const { $, sendLead } = window.WB;
  const btn = $('#jokeOpen'),
    form = $('#jokeForm');
  if (!btn) return;
  btn.addEventListener('click', () => {
    const open = form.hidden;
    form.hidden = !open;
    btn.setAttribute('aria-expanded', open);
    btn.textContent = open ? 'Zavřít' : 'Napsat historku';
    if (open) $('#jText').focus();
  });
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const t = $('#jText'),
      err = $('#jErr'),
      submit = form.querySelector('[type=submit]');
    err.textContent = '';
    if (t.value.trim().length < 10) {
      err.textContent = 'Napiš nám prosím aspoň jednu větu.';
      t.focus();
      return;
    }
    const m = $('#jMail');
    if (m.value.trim() && !/^\S+@\S+\.\S+$/.test(m.value.trim())) {
      err.textContent = 'Zkontroluj e-mail, nebo ho nech prázdný.';
      m.focus();
      return;
    }
    submit.disabled = true;
    const fail = await sendLead(form);
    submit.disabled = false;
    if (fail) {
      err.textContent = Object.values(fail.fields || {})[0] || fail.message;
      return;
    }
    form.hidden = true;
    btn.hidden = true;
    $('#jokeSent').hidden = false;
  });
})();

// Category tabs: show only the questions of the chosen category
(function () {
  const { $, $$ } = window.WB;
  const toc = $('#fxToc');
  if (!toc) return;
  const links = $$('a', toc),
    cats = $$('.fx-cat'),
    grid = $('.fx-grid');
  const show = (k, user) => {
    links.forEach((a) => a.setAttribute('aria-current', a.dataset.cat === k ? 'true' : 'false'));
    cats.forEach((g) => {
      const on = g.id === 'cat-' + k;
      g.hidden = !on;
      if (on && user) {
        g.classList.remove('fxin');
        void g.offsetWidth;
        g.classList.add('fxin');
      }
    });
    if (user && grid.getBoundingClientRect().top < 80)
      grid.scrollIntoView({
        behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
        block: 'start',
      });
  };
  links.forEach((a) => {
    const g = $('#cat-' + a.dataset.cat);
    const n = document.createElement('em');
    n.className = 'fx-cnt';
    n.textContent = '(' + (g ? g.querySelectorAll('details').length : 0) + ')';
    a.appendChild(n);
  });
  links.forEach((a) =>
    a.addEventListener('click', (e) => {
      e.preventDefault();
      show(a.dataset.cat, true);
    }),
  );
  show(links[0].dataset.cat);
})();

// CTA wizard: 3 steps, inline errors, POST to /poptavka only on wboost.cz
(function () {
  const { $, $$, sendLead } = window.WB;
  const form = $('#wiz');
  if (!form) return;
  const wz = $('.wz'),
    steps = $$('.wz-step', form);
  let cur = 0;
  const go = (i) => {
    const dir = i > cur ? 'wz-fwd' : 'wz-bwd';
    steps.forEach((s, k) => {
      s.hidden = k !== i;
      s.classList.remove('wz-fwd', 'wz-bwd');
    });
    void steps[i].offsetWidth;
    steps[i].classList.add(dir);
    cur = i;
    const f = steps[i].querySelector('input:not([type=radio]), input[type=radio]');
    if (f) f.focus({ preventScroll: true });
  };
  const setErr = (inp, msg) => {
    const box = inp.closest('.ol');
    box.classList.toggle('bad', !!msg);
    box.querySelector('.ol-err').textContent = msg || '';
    inp.setAttribute('aria-invalid', msg ? 'true' : 'false');
  };
  const check = (i) => {
    if (i === 0) {
      const n = $('#fName'),
        e = $('#fEmail');
      let ok = true;
      const it = !!form.querySelector('input[name=intent]:checked');
      $('#iErr').textContent = it ? '' : 'Vyber prosím, co tě zajímá.';
      if (!it) ok = false;
      setErr(n, n.value.trim() ? '' : 'Napiš prosím, jak ti máme říkat.');
      if (!n.value.trim()) ok = false;
      const ev = e.value.trim();
      setErr(
        e,
        !ev
          ? 'Bez e-mailu se ti neozveme.'
          : /^\S+@\S+\.\S+$/.test(ev)
            ? ''
            : 'Tohle nevypadá jako e-mail, chybí zavináč nebo doména.',
      );
      if (!/^\S+@\S+\.\S+$/.test(ev)) ok = false;
      if (!ok) {
        const b = form.querySelector('.ol.bad input');
        if (b) b.focus();
      }
      return ok;
    }
    if (i === 1) {
      const ok = !!form.querySelector('input[name=clients]:checked');
      $('#cErr').textContent = ok ? '' : 'Vyber prosím jednu možnost.';
      return ok;
    }
    return true;
  };
  $$('input[name=intent]').forEach((r) =>
    r.addEventListener('change', () => {
      $('#iErr').textContent = '';
    }),
  );
  ['#fName', '#fEmail'].forEach((id) =>
    $(id).addEventListener('input', (e) => {
      if (e.target.closest('.ol').classList.contains('bad')) setErr(e.target, '');
    }),
  );
  $$('input[name=clients]').forEach((r) =>
    r.addEventListener('change', () => {
      $('#cErr').textContent = '';
      setTimeout(() => go(2), 350);
    }),
  );
  $$('[data-next]', form).forEach((b) =>
    b.addEventListener('click', () => {
      if (check(cur)) go(cur + 1);
    }),
  );
  $$('[data-back]', form).forEach((b) => b.addEventListener('click', () => go(cur - 1)));
  form.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && e.target.tagName === 'INPUT' && cur < 2) {
      e.preventDefault();
      if (check(cur)) go(cur + 1);
    }
  });
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const err = $('#formErr'),
      submit = form.querySelector('[type=submit]');
    err.textContent = '';
    submit.disabled = true;
    const fail = await sendLead(form);
    submit.disabled = false;
    if (fail) {
      const fields = fail.fields || {},
        bad = [
          ['name', '#fName'],
          ['email', '#fEmail'],
        ].filter(([k]) => fields[k]);
      if (bad.length) {
        go(0);
        bad.forEach(([k, id]) => setErr($(id), fields[k]));
        return;
      }
      err.textContent = Object.values(fields)[0] || fail.message;
      return;
    }
    wz.classList.add('is-sent');
    const done = $('#formSent');
    done.hidden = false;
    done.classList.add('play');
  });
})();

// How it works: steps and connectors draw in on scroll
(function () {
  const { $, RM, once } = window.WB;
  const f = $('#flow');
  if (!f || RM) return;
  if (f.getBoundingClientRect().top > innerHeight * 0.85) {
    f.classList.add('pre');
    once(f, () => f.classList.remove('pre'), 0.35);
  }
})();

// How it works: plays once — badge "1" pops in at the pen, travels to step 2 and turns "2", then to step 3
(function () {
  const { $, $$, RM } = window.WB;
  const f = $('#flow');
  if (!f) return;
  const items = $$('.fl', f),
    badge = $('.fl-badge', f),
    num = $('b', badge);
  let at = 0;
  const place = (i) => {
    const fr = f.getBoundingClientRect(),
      d = items[i].querySelector('.fl-dot').getBoundingClientRect(),
      m = innerWidth <= 860,
      s = m ? 24 : 28,
      o = m ? 2 : 12;
    badge.style.setProperty('--x', d.right - fr.left - o - s + 'px');
    badge.style.setProperty('--y', d.top - fr.top + o + 'px');
  };
  const mark = (i) => {
    items.forEach((li, k) => li.classList.toggle('act', k === i));
    items[i].classList.add('lit');
    num.textContent = i + 1;
    badge.classList.toggle('mint', i === items.length - 1);
  };
  addEventListener('resize', () => {
    badge.style.transition = 'none';
    place(at);
    void badge.offsetWidth;
    badge.style.transition = '';
  });
  if (RM) {
    items.forEach((li) => li.classList.add('lit'));
    at = items.length - 1;
    place(at);
    mark(at);
    badge.classList.add('show');
    return;
  }
  const go = (i) => {
    at = i;
    place(i);
    setTimeout(
      () => {
        badge.classList.remove('swap');
        mark(i);
      },
      i ? 650 : 0,
    );
    if (i < items.length - 1)
      setTimeout(
        () => {
          badge.classList.add('swap');
          go(i + 1);
        },
        i ? 2200 : 1600,
      );
  };
  // Mobile: full-width swipe slider, the slide in view lights up
  const mq = matchMedia('(max-width:860px)');
  items.forEach((li, k) => {
    const n = document.createElement('button');
    n.type = 'button';
    n.className = 'fl-nb';
    n.setAttribute('aria-label', k < items.length - 1 ? 'Další krok' : 'Zpět na předchozí krok');
    n.innerHTML =
      '<span>' + (k + 1) + '</span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h13M13 6l6 6-6 6"/></svg>';

    li.appendChild(n);
  });
  // direction per slide: forward = circle left, number travels right; back = circle right, number travels left
  const N = items.length;
  items.forEach((li, k) => {
    const n = li.querySelector('.fl-nb');
    n.onclick = () => {
      if (!n.classList.contains('arw')) return;
      const to = li.classList.contains('flip') ? k - 1 : k + 1;
      f.scrollTo({ left: to * f.clientWidth, behavior: RM ? 'auto' : 'smooth' });
    };
  });
  const resetBadge = (n) => {
    clearTimeout(n._t);
    n.style.transition = 'none';
    n.querySelectorAll('span,svg').forEach((x) => (x.style.transition = 'none'));
    n.classList.remove('show', 'arw');
    void n.offsetWidth;
    n.style.transition = '';
    n.querySelectorAll('span,svg').forEach((x) => (x.style.transition = ''));
  };
  const setFlip = (li, on) => {
    li.style.setProperty('--shift', li.clientWidth - 48 - 150 + 'px');
    const els = [li, li.querySelector('.fl-dot')];
    els.forEach((e) => (e.style.transition = 'none'));
    li.classList.toggle('flip', on);
    li.classList.remove('fading');
    void li.offsetWidth;
    els.forEach((e) => (e.style.transition = ''));
  };
  const playBadge = (n) => {
    requestAnimationFrame(() => n.classList.add('show'));
    n._t = setTimeout(() => n.classList.add('arw'), RM ? 0 : 1500);
  };
  // start state of a slide: forward arrivals start left, backward arrivals start right (mirrored)
  const startFlip = (k, back) => (k === N - 1 ? false : k === 0 ? !!back : !!back);
  const prep = (k, back) => {
    const li = items[k];
    if (!li) return;
    [li._f1, li._f2, li._f3].forEach(clearTimeout);
    resetBadge(li.querySelector('.fl-nb'));
    setFlip(li, k === 0 && back ? true : startFlip(k, back));
  };
  const seq = (k, back) => {
    const li = items[k],
      n = li.querySelector('.fl-nb');
    const endSlide = k === N - 1 || (k === 0 && back); // ends re-stack to the other side after 4 s
    prep(k, back);
    if (endSlide) {
      requestAnimationFrame(() => n.classList.add('show'));
      li._f1 = setTimeout(
        () => {
          li.classList.add('fading');
          n.classList.remove('show');
          li._f2 = setTimeout(() => {
            li.classList.toggle('flip');
            li.classList.remove('fading');
            resetBadge(n);
            li._f3 = setTimeout(() => playBadge(n), 950);
          }, 350);
        },
        RM ? 0 : 4000,
      );
      return;
    }
    playBadge(n);
  };
  const dots = document.createElement('div');
  dots.className = 'fl-dots';
  dots.setAttribute('aria-hidden', 'true');
  items.forEach((_, k) => {
    const d = document.createElement('button');
    d.type = 'button';
    d.tabIndex = -1;
    d.onclick = () => f.scrollTo({ left: k * f.clientWidth, behavior: RM ? 'auto' : 'smooth' });
    dots.appendChild(d);
  });
  f.after(dots);
  let mAt = -1;
  const mSet = () => {
    if (!mq.matches) return;
    const k = Math.round(f.scrollLeft / f.clientWidth);
    if (k === mAt) return;
    const back = mAt > k;
    mAt = k;
    items.forEach((li, i) => {
      li.classList.toggle('act', i === k);
      if (i === k) li.classList.add('lit');
    });
    [...dots.children].forEach((d, i) => d.classList.toggle('on', i === k));
    seq(k, back);
  };
  let prepped = -1;
  f.addEventListener(
    'scroll',
    () => {
      if (mq.matches && mAt >= 0) {
        const pos = f.scrollLeft / f.clientWidth;
        const t = pos < mAt ? mAt - 1 : pos > mAt ? mAt + 1 : -1;
        if (t >= 0 && t < N && t !== prepped) {
          prepped = t;
          prep(t, t < mAt);
        }
      }
      clearTimeout(f._t);
      f._t = setTimeout(() => {
        prepped = -1;
        mSet();
      }, 80);
    },
    { passive: true },
  );
  if (mq.matches) {
    const io2 = new IntersectionObserver(
      (es) => {
        if (es[0].isIntersecting) {
          io2.disconnect();
          mAt = -1;
          setTimeout(mSet, 400);
        }
      },
      { threshold: 0.4 },
    );
    io2.observe(f);
    [...dots.children][0].classList.add('on');
    return;
  }
  const io = new IntersectionObserver(
    (es) => {
      if (!es[0].isIntersecting) return;
      io.disconnect();
      badge.style.transition = 'none';
      place(0);
      void badge.offsetWidth;
      badge.style.transition = '';
      setTimeout(() => {
        badge.classList.add('show');
        go(0);
      }, 700);
    },
    { threshold: 0.35 },
  );
  io.observe(f);
})();

// Accordion: clicking anywhere in an open answer closes it (unless the user is selecting text)
document.addEventListener('click', (e) => {
  const a = e.target.closest('.fx-a');
  if (!a) return;
  if (String(getSelection()).length) return;
  const d = a.closest('details');
  if (d && d.open) d.open = false;
});

// Stories loop: swipe left/right on touch screens
(function () {
  const loop = document.querySelector('.loop');
  if (!loop) return;
  let x0 = null,
    y0 = 0;
  loop.addEventListener(
    'touchstart',
    (e) => {
      x0 = e.touches[0].clientX;
      y0 = e.touches[0].clientY;
    },
    { passive: true },
  );
  loop.addEventListener(
    'touchend',
    (e) => {
      if (x0 === null) return;
      const dx = e.changedTouches[0].clientX - x0,
        dy = e.changedTouches[0].clientY - y0;
      x0 = null;
      if (Math.abs(dx) > 40 && Math.abs(dx) > Math.abs(dy))
        document.getElementById(dx < 0 ? 'loopNext' : 'loopPrev').click();
    },
    { passive: true },
  );
})();

// Hero timeline: one axis — CI -> designs -> original -> Canva -> AI -> 2024 (5) -> 2025 (3) -> 2026 (1), loops
(function () {
  const { $, $$, RM, sleep } = window.WB;
  const tl = $('#tl');
  if (!tl) return;
  const hv = $('#hv', tl),
    items = [...hv.children];
  const orig = $('.w-orig', hv),
    cv = $('#hwCanva'),
    ai = $('#hwAi');
  const type = $('#aiType'),
    cur = $('#cvCur');
  const aiT = $('.mp-t', ai),
    aiS = $('.mp-s', ai),
    T0 = aiT.textContent,
    S0 = aiS.textContent;
  const cvT = $('.mp-t', cv),
    CT0 = cvT.textContent;
  let visible = true,
    token = 0,
    at = 0;
  new IntersectionObserver((es) => es.forEach((e) => (visible = e.isIntersecting)), { threshold: 0.15 }).observe(tl);
  const wait = async (ms, t) => {
    let end = Date.now() + ms;
    while (Date.now() < end || !visible) {
      if (t !== token) throw 0;
      if (!visible) end += 100;
      await sleep(100);
    }
  };
  const edgeX = () => $('.hc-head').getBoundingClientRect().left - tl.getBoundingClientRect().left;
  // on wide screens the next item fits next to the active one, so two play at once
  const fits = (i) => {
    const a = items[i],
      b = items[i + 1];
    if (!b) return false;
    return edgeX() + (b.offsetLeft - a.offsetLeft) + b.offsetWidth <= tl.clientWidth - 8;
  };
  const center = (i, two) => {
    at = i;
    const it = items[i];
    hv.style.setProperty('--tx', edgeX() - it.offsetLeft + 'px');
    items.forEach((x, k) => x.classList.toggle('tl-on', k === i || (two && k === i + 1)));
  };
  addEventListener('resize', () => {
    hv.style.transition = 'none';
    center(at);
    void hv.offsetWidth;
    hv.style.transition = '';
  });
  const mv = (x, y) => {
    cur.style.setProperty('--x', x + '%');
    cur.style.setProperty('--y', y + '%');
  };
  const shake = (w) => {
    w.classList.remove('shake');
    void w.offsetWidth;
    w.classList.add('shake');
  };
  const clear = (w) =>
    w.classList.remove(
      'bad',
      'typing',
      'upl',
      'upd',
      'ask',
      'send',
      'gen',
      'done',
      'mood',
      's-font',
      's-logo',
      's-color',
      's-crop',
      's-clip',
      's-bg',
      'shake',
      'hw-in',
      'hw-hl',
    );
  const reset = () => {
    $$('.ch-col', hv).forEach((c) => c.classList.remove('on'));
    [orig, ai, cv].forEach(clear);
    items[0].classList.remove('happy');
    aiT.textContent = T0;
    aiS.textContent = S0;
    cvT.textContent = CT0;
    type.textContent = '';
    cur.classList.remove('on');
    mv(85, 105);
  };
  const hold = (ms) => async (t) => wait(ms, t);
  const playOrig = async (t) => {
    orig.classList.add('hw-in');
    await wait(3400, t);
  };
  const playCanva = async (t) => {
    cv.classList.add('hw-in', 's-bg');
    await wait(800, t);
    cv.classList.add('bad');
    cur.classList.add('on');
    for (const [cls, x, y] of [
      ['s-font', 40, 70],
      ['s-logo', 76, 20],
      ['s-clip', 22, 46],
    ]) {
      mv(x, y);
      await wait(700, t);
      cur.classList.remove('click');
      void cur.offsetWidth;
      cur.classList.add('click');
      cv.classList.add(cls);
      if (cls === 's-font') cvT.innerHTML = 'Na<span class="fb">š</span>e sousedská vlastnost';
      shake(cv);
      await wait(350, t);
    }
    cur.classList.remove('on');
    await wait(600, t);
    cv.classList.add('mood');
    await wait(2200, t);
  };
  const playAi = async (t) => {
    ai.classList.add('hw-in');
    await wait(400, t);
    ai.classList.add('typing', 'upl');
    await wait(800, t);
    ai.classList.add('upd');
    await wait(300, t);
    ai.classList.add('ask');
    const prompt = 'uprav mi text a datum';
    for (let i = 1; i <= prompt.length; i++) {
      type.textContent = prompt.slice(0, i);
      await wait(32, t);
    }
    await wait(300, t);
    ai.classList.add('send');
    await wait(250, t);
    ai.classList.remove('typing');
    ai.classList.add('gen');
    const pct = $('#aiPct');
    for (let p = 0; p <= 100; p += 5) {
      pct.textContent = p + ' %';
      await wait(40, t);
    }
    await wait(250, t);
    aiT.innerHTML = 'Přá<span class="ko1">t</span>elská<br>akce';
    aiS.textContent = '~ Sobota 21. června ~';
    ai.classList.remove('gen');
    ai.classList.add('done', 'bad');
    await wait(800, t);
    ai.classList.add('mood');
    await wait(2000, t);
  };
  const playCI = async (t) => {
    await wait(900, t);
    items[0].classList.add('happy');
    await wait(2400, t);
  };
  const playChart = (el) => async (t) => {
    const cs = $$('.ch-col', el);
    for (const c of cs) {
      c.classList.add('on');
      await wait(1500, t);
    }
    await wait(2600, t);
  };
  const steps = items.map((it, k) =>
    k === 0
      ? playCI
      : it.classList.contains('tn-chart')
        ? playChart(it)
        : it.classList.contains('w-orig')
          ? playOrig
          : it === cv
            ? playCanva
            : it === ai
              ? playAi
              : hold(2600),
  );
  async function run(t, from) {
    for (let k = from; ; k++) {
      if (k >= items.length) {
        tl.classList.add('fade');
        await wait(500, t);
        reset();
        hv.style.transition = 'none';
        center(0);
        void hv.offsetWidth;
        hv.style.transition = '';
        tl.classList.remove('fade');
        k = 0;
        await wait(600, t);
      } else {
        const two = k > 0 && items[k].classList.contains('tn-quote') && fits(k);
        center(k, two);
        await wait(two ? 700 : 1100, t);
        if (two) {
          await Promise.all([
            steps[k](t),
            (async () => {
              await wait(150, t);
              await steps[k + 1](t);
            })(),
          ]);
          k++;
          continue;
        }
      }
      await steps[k](t);
    }
  }
  const prep = (k) => {
    reset();
    items.slice(0, k).forEach((it) => {
      if (it === orig) orig.classList.add('hw-in');
      if (it === items[0]) it.classList.add('happy');
      if (it === cv) {
        cv.classList.add('hw-in', 's-bg', 'bad', 's-font', 's-logo', 's-clip', 'mood');
        cvT.innerHTML = 'Na<span class="fb">š</span>e sousedská vlastnost';
      }
      if (it === ai) {
        ai.classList.add('hw-in', 'bad', 'done', 'mood');
        aiT.innerHTML = 'Přá<span class="ko1">t</span>elská<br>akce';
        aiS.textContent = '~ Sobota 21. června ~';
      }
      if (it.classList.contains('tn-chart')) $$('.ch-col', it).forEach((c) => c.classList.add('on'));
    });
  };
  const start = (k) => {
    const t = ++token;
    prep(k);
    run(t, k).catch(() => {});
  };
  // on wide screens a quote and its card are one stop: arrows jump to the next/previous quote
  const isStop = (k) =>
    k === 0 ||
    items[k].classList.contains('tn-quote') ||
    !(items[k - 1] && items[k - 1].classList.contains('tn-quote') && fits(k - 1));
  const jump = (d) => {
    let k = at;
    do {
      k = (k + d + items.length) % items.length;
    } while (!isStop(k) && k !== at);
    start(k);
  };
  $('#tlNext').addEventListener('click', () => jump(1));
  $('#tlPrev').addEventListener('click', () => jump(-1));
  let x0 = null;
  tl.addEventListener('touchstart', (e) => (x0 = e.touches[0].clientX), { passive: true });
  tl.addEventListener(
    'touchend',
    (e) => {
      if (x0 === null) return;
      const dx = e.changedTouches[0].clientX - x0;
      x0 = null;
      if (Math.abs(dx) > 40) jump(dx < 0 ? 1 : -1);
    },
    { passive: true },
  );
  hv.style.transition = 'none';
  center(0);
  void hv.offsetWidth;
  hv.style.transition = '';
  if (RM) {
    prep(items.length);
    return;
  }
  start(0);
})();

/* Ukázka: the real Wboost prototype, driven like a screen recording */
(function () {
  let fr = document.getElementById('dvFrame');
  if (!fr) return;
  const fit = document.getElementById('dvFit'),
    cover = document.getElementById('dvCover'),
    cap = document.getElementById('dvCap'),
    st = [...document.querySelectorAll('.dv-st')];
  const C = [
    'Nahraješ hotový návrh jako obrázek. Žádné skládání šablony od nuly.',
    'AI skener sám rozdělí návrh na texty, obrázky, pozadí a tvary. U textu rovnou vybereš písmo a barvu z palety projektu.',
    'V editoru odemkneš klientovi jen pole, která má měnit. Písmo, barvy a rozvržení zůstávají zamčené.',
    'Klient vidí jen svá pole. Přepíše nadpis, změní se ve všech formátech a stáhne hotové soubory.',
  ];
  const MQ = matchMedia('(max-width:700px)');
  let mob = MQ.matches;
  function size() {
    const B = mob ? 6 : 0,
      w = fit.clientWidth - 2 * B,
      k = w / (mob ? 375 : 1440);
    fr.style.transform = `scale(${k})`;
    fr.style.left = fr.style.top = B + 'px';
    fr.style.clipPath = '';
    fit.style.height = mob ? Math.round(700 * k) + 2 * B + 'px' : '';
  }
  size();
  addEventListener('resize', size);
  MQ.addEventListener('change', () => {
    mob = MQ.matches;
    size();
    if (started) load(true);
  });
  let url = null,
    vis = false,
    started = false;
  let want = 0,
    hold = false,
    chT = 0;
  const CT = ['Nahraješ návrh', 'AI skener', 'Odemkneš pole', 'Pohled klienta'];
  const ch = document.createElement('div');
  ch.className = 'dv-ch';
  ch.setAttribute('aria-hidden', 'true');
  fit.appendChild(ch);
  function chapter(k) {
    if (!mob) return;
    const cl = k === 3;
    ch.classList.toggle('cl', cl);
    ch.innerHTML = `<div class="dv-ch-in"><span class="dv-ch-role"><i></i>${cl ? 'Tvůj klient' : 'Ty · grafik'}</span><b class="dv-ch-n">0${k + 1}<small> / 04</small></b><strong class="dv-ch-t">${CT[k]}</strong><p class="dv-ch-p">${CM[k]}</p><span class="dv-ch-bar"><i></i></span></div>`;
    hold = true;
    send();
    ch.classList.remove('out');
    ch.classList.add('on');
    clearTimeout(chT);
    chT = setTimeout(chOff, 3600);
  }
  function chOff() {
    if (!ch.classList.contains('on')) return;
    ch.classList.add('out');
    setTimeout(() => {
      ch.classList.remove('on', 'out');
    }, 450);
    hold = false;
    send();
  }
  ch.addEventListener('click', () => {
    clearTimeout(chT);
    chOff();
  });
  function load(fresh, k) {
    want = k || 0;
    clearTimeout(chT);
    ch.classList.remove('on', 'out');
    hold = false;
    if (fresh) {
      const n = fr.cloneNode(false);
      n.removeAttribute('src');
      fr.replaceWith(n);
      fr = n;
      fr.addEventListener('load', onLoad);
      size();
    }
    cover.classList.remove('off');
    demoUrl().then(
      (u) => {
        fr.src = u + '#projekt-kd/sablony';
      },
      () => {},
    );
  }
  /* Loaded as a blob on purpose: the edge sends X-Frame-Options: DENY for every real URL, blobs carry no headers. */
  function demoUrl() {
    return (url =
      url ||
      fetch('/landing/demo/app.html')
        .then((r) => {
          if (!r.ok) throw new Error('demo ' + r.status);
          return r.blob();
        })
        .then((b) => URL.createObjectURL(b.slice(0, b.size, 'text/html'))));
  }
  function onLoad() {
    setTimeout(
      () => {
        if (!want) cover.classList.add('off');
        send();
      },
      want ? 0 : 500,
    );
  }
  fr.addEventListener('load', onLoad);
  function send() {
    try {
      fr.contentWindow.postMessage({ dv: 'vis', on: vis && !document.hidden && !hold, start: want }, '*');
    } catch (e) {}
  }
  addEventListener('focus', () => send());
  addEventListener('blur', () => send());
  document.addEventListener('visibilitychange', () => send());
  setInterval(send, 700);
  const CM = [
    'Nahraješ hotový návrh jako obrázek, klidně rovnou z telefonu.',
    'AI skener rozdělí návrh na texty, obrázky, pozadí a tvary. U textu vybereš písmo a barvu z palety projektu.',
    'Klepneš na prvek a odemkneš ho klientovi. Písmo, barvy a rozvržení zůstávají zamčené.',
    'Klient vidí jen svá pole. Přepíše nadpis, změní se ve všech formátech a stáhne hotové soubory.',
  ];
  function step(k) {
    st.forEach((b, i) => {
      b.classList.toggle('on', i === k);
      b.querySelector('.dv-bar i').style.width = i <= k ? '100%' : '0';
    });
    cap.style.opacity = 0;
    setTimeout(() => {
      cap.textContent = (mob ? CM : C)[k];
      cap.style.opacity = 1;
    }, 200);
  }
  addEventListener('message', (e) => {
    if (e.source !== fr.contentWindow || !e.data || e.data.dv !== 'ev') return;
    if (e.data.step != null) {
      step(e.data.step);
      chapter(e.data.step);
      if (want && e.data.step >= want) {
        want = 0;
        setTimeout(() => cover.classList.add('off'), 250);
      }
    }
    if (e.data.loop) {
      cover.classList.remove('off');
      setTimeout(() => load(true), 650);
    }
  });
  new IntersectionObserver(
    (es) =>
      es.forEach((e) => {
        vis = e.isIntersecting;
        if (vis && !started) {
          started = true;
          load();
        }
        send();
      }),
    { threshold: 0.3, rootMargin: '200px 0px' },
  ).observe(fit);
  st.forEach((b) => {
    b.removeAttribute('tabindex');
    b.addEventListener('click', () => {
      const k = st.indexOf(b);
      started = true;
      cover.classList.remove('off');
      step(k);
      setTimeout(() => load(true, k), 350);
    });
  });
  step(0);
})();

/* Ceník: per-client calculator */
(function () {
  const root = document.getElementById('pc');
  if (!root) return;
  const N = document.getElementById('pcN'),
    P = document.getElementById('pcP'),
    $ = (id) => document.getElementById(id);
  const tiers = [...document.querySelectorAll('#pcTiers li')];
  let beta = false;
  const fmt = (n) => Math.round(n).toLocaleString('cs-CZ').replace(/\s/g, ' ') + ' Kč';
  const tw = new Map();
  function tween(el, to) {
    const from = tw.has(el) ? tw.get(el).v : to;
    const st = { v: from };
    tw.set(el, st);
    const t0 = performance.now(),
      d = RMm ? 0 : 450;
    const step = (now) => {
      if (tw.get(el) !== st) return;
      const k = d ? Math.min(1, (now - t0) / d) : 1,
        e = 1 - Math.pow(1 - k, 3);
      st.v = from + (to - from) * e;
      el.textContent = fmt(st.v);
      if (k < 1) requestAnimationFrame(step);
      else st.v = to;
    };
    requestAnimationFrame(step);
  }
  const RMm = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const rateOf = (n) => (beta ? 99 : n > 30 ? 120 : n > 10 ? 150 : 190);
  const fill = (el) => el.style.setProperty('--p', ((el.value - el.min) / (el.max - el.min)) * 100 + '%');
  function calc() {
    const n = +N.value,
      p = +P.value,
      r = rateOf(n),
      fee = Math.max(0, n - 1) * r;
    $('pcNo').textContent = n;
    $('pcPo').textContent = fmt(p);
    tween($('pcEarn'), n * p - fee);
    tween($('pcFee'), fee);
    $('pcSave').textContent = fmt(3250) + ' měsíčně';
    $('pcRate').textContent = n > 1 ? '(' + fmt(r) + ' za klienta)' : '(první klient zdarma)';
    tiers.forEach((t) => {
      const k = +t.dataset.t;
      t.classList.toggle(
        'on',
        !beta &&
          ((k === 1 && n > 1 && n <= 10) || (k === 2 && n > 10 && n <= 30) || (k === 3 && n > 30) || (!k && n === 1)),
      );
    });
    fill(N);
    fill(P);
  }
  N.addEventListener('input', calc);
  P.addEventListener('input', calc);
  const ov = document.getElementById('pcOv');
  const showOv = (o) => {
    ov.hidden = !o;
    root.classList.toggle('ov', o);
  };
  document.getElementById('pcOvCalc').addEventListener('click', () => showOv(false));
  document.getElementById('pcOvX').addEventListener('click', () => showOv(false));
  root.querySelectorAll('.pc-mode button').forEach((b) =>
    b.addEventListener('click', () => {
      beta = b.dataset.mode === 'beta';
      root.classList.toggle('beta', beta);
      if (beta) {
        showOv(true);
      } else if (!beta) showOv(false);
      root.querySelectorAll('.pc-mode button').forEach((x) => {
        x.classList.toggle('on', x === b);
        x.setAttribute('aria-pressed', x === b);
      });
      calc();
    }),
  );
  calc();
})();

/* stats: orbit icons appear + numbers count up once */
(function () {
  const dl = document.getElementById('stats');
  if (!dl) return;
  const RM = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const vs = [...dl.querySelectorAll('.ks-v')];
  if (RM) {
    dl.classList.add('ks-on');
    return;
  }
  vs.forEach((v) => (v.textContent = (v.dataset.pre || '') + '0'));
  new IntersectionObserver(
    (es, o) =>
      es.forEach((e) => {
        if (!e.isIntersecting) return;
        o.disconnect();
        dl.classList.add('ks-on');
        vs.forEach((v, i) => {
          const to = +v.dataset.to,
            t0 = performance.now() + i * 120,
            d = 1100;
          const step = (t) => {
            const p = Math.max(0, Math.min(1, (t - t0) / d));
            v.textContent = (v.dataset.pre || '') + Math.round(to * (1 - Math.pow(1 - p, 3)));
            if (p < 1) requestAnimationFrame(step);
          };
          requestAnimationFrame(step);
        });
      }),
    { threshold: 0.35 },
  ).observe(dl);
})();

/* mobile: loop height follows the active bubble (no big empty gap above short stories) */
(function () {
  const loop = document.querySelector('.loop');
  if (!loop) return;
  const mq = matchMedia('(max-width:700px)');
  function fit() {
    if (!mq.matches) {
      loop.style.removeProperty('--top');
      return;
    }
    const hs = [...loop.querySelectorAll('.lp-top')].map((t) => t.offsetHeight);
    loop.style.setProperty('--top', Math.ceil(Math.max(...hs) + 11) + 'px');
  }
  addEventListener('resize', fit);
  mq.addEventListener('change', fit);
  fit();
})();

/* mobile: story counter top-right of the carousel, under the lead */
(function () {
  const c = document.getElementById('loopCount'),
    loop = document.querySelector('.loop');
  if (!c || !loop) return;
  const home = c.parentNode,
    next = c.nextSibling,
    mq = matchMedia('(max-width:700px)');
  function place() {
    if (mq.matches) {
      if (c.parentNode !== loop) loop.appendChild(c);
    } else if (c.parentNode !== home) home.insertBefore(c, next);
  }
  mq.addEventListener('change', place);
  place();
})();

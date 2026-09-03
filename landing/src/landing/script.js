/* WBoost landing — four things, vanilla, no library.
   The legal pages load the same file and use none of it. */
(function () {
  "use strict";

  /* 1. The sticky nav gains its hairline on first scroll. */
  var nav = document.querySelector(".lp-nav");
  if (nav) {
    var onScroll = function () {
      nav.classList.toggle("is-scrolled", window.scrollY > 4);
    };
    onScroll();
    window.addEventListener("scroll", onScroll, { passive: true });
  }

  /* 2. Mobile menu toggle. */
  var toggle = document.querySelector("[data-menu-toggle]");
  var drawer = document.getElementById("lp-menu");
  if (toggle && drawer) {
    toggle.addEventListener("click", function () {
      var open = toggle.getAttribute("aria-expanded") === "true";
      toggle.setAttribute("aria-expanded", String(!open));
      drawer.hidden = open;
    });
    drawer.addEventListener("click", function (e) {
      if (e.target.closest("a")) {
        toggle.setAttribute("aria-expanded", "false");
        drawer.hidden = true;
      }
    });
  }

  /* 3. FAQ accordion — height transition, + rotates to −. */
  document.querySelectorAll(".lp-faq__q").forEach(function (button) {
    button.addEventListener("click", function () {
      var panel = document.getElementById(button.getAttribute("aria-controls"));
      if (!panel) return;
      var open = button.getAttribute("aria-expanded") === "true";
      button.setAttribute("aria-expanded", String(!open));
      if (open) panel.removeAttribute("data-open");
      else panel.setAttribute("data-open", "");
    });
  });

  /* 4. The showcase headline nudges once, on all four formats together. */
  var showcase = document.querySelector(".lp-showcase");
  if (showcase && "IntersectionObserver" in window) {
    var io = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            entry.target.classList.add("is-seen");
            io.unobserve(entry.target);
          }
        });
      },
      { threshold: 0.25 }
    );
    io.observe(showcase);
  } else if (showcase) {
    showcase.classList.add("is-seen");
  }
})();

/* ============================================================================
   SQUIRE front page: the squire at work.

   Vanilla, no framework, no network. Everything that moves is gated twice:
   the <head> script only adds .motion when the system has no reduced-motion
   request, and motionOn() checks again before anything starts. With reduced
   motion the buttons still work; they just pack the bag at once, with no
   flying tags. The words are always in the DOM: animations only change
   opacity and position, never the text.

   Sections: 1. setup  2. reveals  3. hero  4. the packing list
             5. OS tabs  6. copy buttons  7. toast
   ========================================================================== */
(function () {
  "use strict";

  /* --------------------------------------------------------------- 1. setup */

  var root = document.documentElement;
  var reduce = window.matchMedia ? window.matchMedia("(prefers-reduced-motion: reduce)") : null;

  function motionOn() {
    return root.classList.contains("motion") && !(reduce && reduce.matches);
  }

  // Flip reduced motion on mid-visit and the whole page settles at once.
  if (reduce && reduce.addEventListener) {
    reduce.addEventListener("change", function (e) {
      if (e.matches) {
        root.classList.remove("motion");
      }
    });
  }

  var hasIO = "IntersectionObserver" in window;

  function onceVisible(els, threshold, fn) {
    if (!hasIO) {
      els.forEach(fn);
      return;
    }
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          io.unobserve(entry.target);
          fn(entry.target);
        }
      });
    }, { threshold: threshold, rootMargin: "0px 0px -6% 0px" });
    els.forEach(function (el) {
      io.observe(el);
    });
  }

  function all(sel, scope) {
    return Array.prototype.slice.call((scope || document).querySelectorAll(sel));
  }

  function wait(ms) {
    return new Promise(function (resolve) {
      setTimeout(resolve, ms);
    });
  }

  // Restart a one-shot CSS animation class.
  function replay(el, cls) {
    el.classList.remove(cls);
    void el.offsetWidth;
    el.classList.add(cls);
  }

  /* ------------------------------------------------------------ 2. reveals */

  if (motionOn()) {
    var groups = [
      [".section__head", false],
      [".item", false],
      [".patch", true],
      [".step", false],
      [".rail__tag", false],
      [".roster__list li", true],
      [".dents li", false],
      [".term--doctor", false],
      [".os", false],
      [".parity__side", false],
      [".field__stop", false]
    ];
    groups.forEach(function (g) {
      all(g[0]).forEach(function (el, n) {
        if (g[1]) {
          el.style.setProperty("--i", n % 8);
        }
        el.classList.add("reveal");
      });
    });
    onceVisible(all(".reveal"), 0.12, function (el) {
      el.classList.add("is-in");
    });
  }

  var field = document.querySelector("[data-field]");
  if (field) {
    onceVisible([field], 0.4, function (el) {
      el.classList.add("is-in");
    });
  }

  /* ---------------------------------------------------------------- 3. hero */

  var art = document.querySelector(".hero__art");
  var heroSatchel = document.querySelector(".hero__satchel");
  var heroBusy = false;
  var heroLine = 0;
  var HERO_LINES = [
    "Packed. Your app may ride for the Citadel.",
    "All four tags in, buckle fastened. Horse not included.",
    "Packed again. A squire's work is never done.",
    "Bag ready. The knight may now dawdle with confidence.",
    "Squire-d away safely. You could pack it yourself, but why would you?"
  ];

  if (art && heroSatchel) {
    if (motionOn()) {
      heroSatchel.addEventListener("animationend", function landed(e) {
        if (e.animationName === "arrive") {
          heroSatchel.classList.add("is-landed");
          heroSatchel.removeEventListener("animationend", landed);
        }
      });
      setTimeout(function () {
        art.classList.add("is-hung");
      }, 1000);
    }

    heroSatchel.addEventListener("click", function () {
      var line = HERO_LINES[heroLine % HERO_LINES.length];
      heroLine += 1;
      if (!motionOn()) {
        toast(line);
        return;
      }
      if (heroBusy) {
        return;
      }
      heroBusy = true;
      art.classList.add("is-packing");
      wait(980).then(function () {
        replay(heroSatchel, "is-squash");
        replay(heroSatchel, "is-glint");
        toast(line);
        return wait(1500);
      }).then(function () {
        // Re-hang the kit: jump the tags above the rail, then drop them.
        art.classList.add("is-rehang");
        art.classList.remove("is-packing", "is-hung");
        void art.offsetWidth;
        art.classList.remove("is-rehang");
        art.classList.add("is-hung");
        heroSatchel.classList.remove("is-squash", "is-glint");
        return wait(900);
      }).then(function () {
        heroBusy = false;
      });
    });
  }

  /* ---------------------------------------------------- 4. the packing list */

  // The files `squire init` writes for a Laravel app, in catalog order, and
  // which packing-list item each one belongs to.
  var FILES = [
    ["build.sh", "build", true],
    ["deployments/001-first-release.sh", "hook", true],
    ["scripts/release.sh", "release", true],
    ["health.php", "health", false],
    ["docker-compose.squire.yml", "parity", false],
    [".squire/Dockerfile", "parity", false],
    [".squire/Dockerfile.dockerignore", "parity", false],
    [".squire/base-fallback.Dockerfile", "parity", false],
    [".squire/fallback/citadel-apache-entrypoint", "parity", true],
    [".squire/fallback/citadel-deploy", "parity", true],
    [".squire/haproxy.cfg", "parity", false],
    [".squire/php.ini", "parity", false],
    [".squire/vhost.conf", "parity", false],
    ["scripts/parity.sh", "parity", true],
    ["scripts/parity.ps1", "parity", false],
    [".env.squire.example", "parity", false]
  ];
  var ITEMS = ["build", "hook", "release", "health", "parity"];
  var NEXT = "Next: git update-index --chmod=+x " + FILES.filter(function (f) {
    return f[2];
  }).map(function (f) {
    return f[0];
  }).join(" ");

  var packer = document.querySelector("[data-packer]");

  if (packer) {
    var bag = packer.querySelector("[data-bag]");
    var stage = packer.querySelector(".bag__stage");
    var satchel = packer.querySelector("[data-satchel]");
    var out = packer.querySelector("[data-term-out]");
    var countEl = packer.querySelector("[data-count]");
    var readyEl = packer.querySelector("[data-ready]");
    var state = { packed: true, edited: false, busy: false };

    var item = function (key) {
      return packer.querySelector('[data-item="' + key + '"]');
    };

    var pad = function (s) {
      while (s.length < 17) {
        s += " ";
      }
      return s;
    };

    var esc = function (s) {
      return s.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
    };

    // One line of `squire init` output, formatted the way InitCommand prints it.
    var fileLine = function (verb, path, detail, cls) {
      return { html: esc(pad(verb) + " " + path) + '  <span class="t-dim">(' + esc(detail) + ")</span>", cls: cls || "", path: path };
    };

    var setCount = function () {
      var n = all(".item.is-packed", packer).length;
      countEl.textContent = String(n);
      var ready = n === ITEMS.length;
      bag.classList.toggle("is-ready", ready);
      readyEl.hidden = !ready;
    };

    var setBusy = function (on) {
      state.busy = on;
      all("[data-run]", packer).forEach(function (b) {
        if (on) {
          b.setAttribute("aria-disabled", "true");
        } else {
          b.removeAttribute("aria-disabled");
        }
      });
    };

    // Write the lines into the terminal. With motion they arrive one by one
    // and onLine runs as each appears; without it they are all there at once.
    var print = function (cmd, lines, onLine) {
      var html = '<p class="term__cmd"><span class="t-dim">$ </span>' + esc(cmd) + "</p>";
      lines.forEach(function (l) {
        html += '<p class="' + l.cls + '">' + l.html + "</p>";
      });
      out.innerHTML = html;
      out.scrollTop = 0;
      var ps = all("p", out).slice(1);
      if (!motionOn()) {
        ps.forEach(function (p, n) {
          if (onLine) {
            onLine(lines[n], n, true);
          }
        });
        return Promise.resolve();
      }
      ps.forEach(function (p) {
        p.classList.add("is-hidden");
      });
      var chain = wait(250);
      ps.forEach(function (p, n) {
        chain = chain.then(function () {
          p.classList.remove("is-hidden");
          p.classList.add("is-shown");
          if (p.offsetTop + p.offsetHeight > out.scrollTop + out.clientHeight) {
            out.scrollTop = p.offsetTop + p.offsetHeight - out.clientHeight;
          }
          var gap = onLine ? onLine(lines[n], n, false) : 60;
          return wait(typeof gap === "number" ? gap : 60);
        });
      });
      return chain;
    };

    // A tag flies from its list item, arcs over the satchel and slides in
    // behind the satchel's top edge.
    var fly = function (key) {
      var el = item(key);
      if (!el || !motionOn() || !el.animate) {
        return Promise.resolve();
      }
      var name = el.querySelector(".item__name");
      var label = key === "parity" ? "parity kit" : name.firstChild.textContent.trim();
      var s = stage.getBoundingClientRect();
      var from = name.getBoundingClientRect();
      var to = satchel.getBoundingClientRect();
      var tag = document.createElement("span");
      tag.className = "flyer";
      tag.setAttribute("aria-hidden", "true");
      tag.textContent = label;
      stage.appendChild(tag);
      var w = tag.offsetWidth;
      var x0 = from.left - s.left;
      var y0 = from.top - s.top;
      var xt = to.left - s.left + to.width / 2 - w / 2;
      var top = to.top - s.top;
      var xm = (x0 + xt) / 2;
      var ym = Math.min(y0, top) - 70;
      var anim = tag.animate([
        { transform: "translate(" + x0 + "px," + y0 + "px) rotate(-4deg)" },
        { transform: "translate(" + xm + "px," + ym + "px) rotate(10deg)", offset: 0.5 },
        { transform: "translate(" + xt + "px," + (top - 34) + "px) rotate(0deg)", offset: 0.78 },
        { transform: "translate(" + xt + "px," + (top + 46) + "px) rotate(0deg) scale(0.85)" }
      ], { duration: 760, easing: "cubic-bezier(0.45, 0, 0.4, 1)", fill: "forwards" });
      return new Promise(function (resolve) {
        anim.onfinish = function () {
          tag.remove();
          replay(satchel, "is-squash");
          resolve();
        };
      });
    };

    var pack = function (key) {
      var el = item(key);
      el.classList.add("is-packed");
      el.classList.remove("is-edited");
      setCount();
    };

    var unpackAll = function () {
      ITEMS.forEach(function (k) {
        item(k).classList.remove("is-packed", "is-edited");
      });
      setCount();
    };

    var fasten = function () {
      satchel.classList.remove("is-open");
      replay(satchel, "is-glint");
    };

    // Pack an empty bag: every file is created and every tag flies in.
    var packFromEmpty = function (cmd) {
      var lines = FILES.map(function (f) {
        return fileLine("created", f[0], "written from the template");
      });
      lines.push({ html: "Target: laravel. Wrote .squire.json.", cls: "" });
      lines.push({ html: esc(NEXT), cls: "" });
      if (motionOn()) {
        satchel.classList.add("is-open");
      }
      var flights = [];
      return print(cmd, lines, function (l, n, instant) {
        var f = FILES[n];
        if (!f) {
          return 120;
        }
        var lastOfItem = !FILES[n + 1] || FILES[n + 1][1] !== f[1];
        if (!lastOfItem) {
          return 90;
        }
        if (instant) {
          pack(f[1]);
          return 0;
        }
        flights.push(fly(f[1]).then(function () {
          pack(f[1]);
        }));
        return 420;
      }).then(function () {
        return Promise.all(flights);
      }).then(function () {
        state.packed = true;
        state.edited = false;
        fasten();
        toast("Packed and buckled for deploy day. Exit code 0.");
      });
    };

    var runs = {
      pack: function () {
        if (!state.packed) {
          return packFromEmpty("vendor/bin/squire init");
        }
        var lines = FILES.map(function (f) {
          if (state.edited && f[1] === "release") {
            return fileLine("skipped-modified", f[0], "edited since SQUIRE wrote it; left alone (use --force to overwrite)", "is-drift");
          }
          return fileLine("unchanged", f[0], "matches the template");
        });
        lines.push({ html: "Target: laravel. Wrote .squire.json.", cls: "" });
        lines.push({ html: esc(NEXT), cls: "" });
        return print("vendor/bin/squire init", lines, function () {
          return 45;
        }).then(function () {
          fasten();
          toast(state.edited
            ? "The squire left your edited release.sh alone. --force repacks it."
            : "Already packed. The squire checked twice anyway.");
        });
      },

      check: function () {
        var drift = 0;
        var lines = FILES.map(function (f) {
          if (!state.packed) {
            drift += 1;
            return fileLine("missing", f[0], "not in the app", "is-drift");
          }
          if (state.edited && f[1] === "release") {
            drift += 1;
            return fileLine("modified", f[0], "edited since SQUIRE wrote it", "is-drift");
          }
          return fileLine("ok", f[0], "matches the template");
        });
        lines.push({
          html: "RESULT: " + (drift ? "drift" : "pass") + " (" + drift + " of " + FILES.length + " files need attention)",
          cls: "term__result"
        });
        return print("vendor/bin/squire init --check", lines, function () {
          return 45;
        }).then(function () {
          toast(drift
            ? (state.packed ? "One strap loose: scripts/release.sh drifted. Exit code 1." : "The bag is empty. Exit code 1.")
            : "Every strap tight. Exit code 0.");
        });
      },

      meddle: function () {
        if (!state.packed) {
          toast("Nothing to edit yet. Pack the bag first.");
          return Promise.resolve();
        }
        if (state.edited) {
          toast("Already edited. Try init --check to see the drift.");
          return Promise.resolve();
        }
        state.edited = true;
        return print("echo '# my tweak' >> scripts/release.sh", []).then(function () {
          item("release").classList.add("is-edited");
          toast("You edited release.sh. SQUIRE will notice, and leave it be.");
        });
      },

      force: function () {
        if (!state.packed) {
          return packFromEmpty("vendor/bin/squire init --force");
        }
        var wasEdited = state.edited;
        var lines = FILES.map(function (f) {
          if (wasEdited && f[1] === "release") {
            return fileLine("overwritten", f[0], "edited since SQUIRE wrote it; overwritten by --force", "is-drift");
          }
          return fileLine("unchanged", f[0], "matches the template");
        });
        lines.push({ html: "Target: laravel. Wrote .squire.json.", cls: "" });
        lines.push({ html: esc(NEXT), cls: "" });
        var flight = Promise.resolve();
        return print("vendor/bin/squire init --force", lines, function (l, n, instant) {
          if (wasEdited && FILES[n] && FILES[n][1] === "release" && !instant) {
            flight = fly("release");
            return 300;
          }
          return 45;
        }).then(function () {
          return flight;
        }).then(function () {
          state.edited = false;
          pack("release");
          fasten();
          toast(wasEdited ? "release.sh repacked from the template." : "Nothing to force. Everything already matches.");
        });
      }
    };

    all("[data-run]", packer).forEach(function (btn) {
      btn.addEventListener("click", function () {
        if (state.busy) {
          return;
        }
        setBusy(true);
        runs[btn.getAttribute("data-run")]().then(function () {
          setBusy(false);
        }, function () {
          setBusy(false);
        });
      });
    });

    // The magic: the first time the bag scrolls into view, it empties and
    // packs itself for deploy day.
    if (motionOn()) {
      onceVisible([bag], 0.55, function () {
        if (state.busy) {
          return;
        }
        setBusy(true);
        state.packed = false;
        unpackAll();
        out.innerHTML = '<p class="term__cmd"><span class="t-dim">$ </span>vendor/bin/squire init</p>';
        wait(450).then(function () {
          return packFromEmpty("vendor/bin/squire init");
        }).then(function () {
          setBusy(false);
        });
      });
    }
  }

  /* -------------------------------------------------------------- 5. OS tabs */

  var os = document.querySelector("[data-os]");
  if (os) {
    var tabs = all('[role="tab"]', os);
    var select = function (tab, focus) {
      tabs.forEach(function (t) {
        var on = t === tab;
        t.setAttribute("aria-selected", on ? "true" : "false");
        t.tabIndex = on ? 0 : -1;
        document.getElementById(t.getAttribute("aria-controls")).hidden = !on;
      });
      if (focus) {
        tab.focus();
      }
    };
    os.classList.add("is-tabbed");
    tabs.forEach(function (t, n) {
      t.addEventListener("click", function () {
        select(t, false);
      });
      t.addEventListener("keydown", function (e) {
        var next = null;
        if (e.key === "ArrowRight") { next = tabs[(n + 1) % tabs.length]; }
        if (e.key === "ArrowLeft") { next = tabs[(n - 1 + tabs.length) % tabs.length]; }
        if (e.key === "Home") { next = tabs[0]; }
        if (e.key === "End") { next = tabs[tabs.length - 1]; }
        if (next) {
          e.preventDefault();
          select(next, true);
        }
      });
    });
    var isWin = /Win/i.test((navigator.userAgentData && navigator.userAgentData.platform) || navigator.platform || "");
    select(isWin ? tabs[1] : tabs[0], false);
  }

  /* --------------------------------------------------------- 6. copy buttons */

  all("[data-copy]").forEach(function (btn) {
    var label = btn.querySelector(".copy-btn__label");
    btn.addEventListener("click", function () {
      var text = btn.getAttribute("data-copy");
      var done = function () {
        label.textContent = "Copied";
        replay(btn, "is-copied");
        setTimeout(function () {
          label.textContent = "Copy";
          btn.classList.remove("is-copied");
        }, 1800);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(done, function () {
          label.textContent = "Select it";
        });
      } else {
        label.textContent = "Select it";
      }
    });
  });

  /* ---------------------------------------------------------------- 7. toast */

  var toastEl = document.querySelector(".toast");
  var toastTimer = 0;

  function toast(msg) {
    if (!toastEl) {
      return;
    }
    toastEl.textContent = msg;
    toastEl.classList.add("is-on");
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () {
      toastEl.classList.remove("is-on");
    }, 3200);
  }
})();

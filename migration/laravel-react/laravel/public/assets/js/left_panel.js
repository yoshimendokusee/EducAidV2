/* ==========================================================
   EducAid – Brand Circles (slightly blurred, clearly visible)
   Drop-in replacement for your previous brand-circles script.
   HTML/CSS (unchanged):
     <div class="col-lg-6 d-none d-lg-flex brand-section">
       <canvas id="brandBubbles" class="brand-bubbles"></canvas>
       <div class="brand-content"> ... </div>
     </div>
     .brand-section { position: relative; overflow: hidden; }
     .brand-bubbles { position:absolute; inset:0; width:100%; height:100%;
                      pointer-events:none; z-index:0; opacity:.45; }
     .brand-content { position:relative; z-index:1; }
   ========================================================== */
(() => {
  const canvas = document.getElementById("brandBubbles");
  if (!canvas) return;

  const parent = canvas.closest(".brand-section") || canvas.parentElement;
  const ctx = canvas.getContext("2d");

  // Runtime state
  let dpr = 1;
  let wCss = 0, hCss = 0;
  let running = true;
  let lastTs = performance.now();
  let spawnAccMs = 0;
  let circles = [];
  let primed = false;

  // Config — fewer circles, slower cadence, gentle blur & higher opacity for visibility
  const CFG = {
    maxCircles: 28,        // subtle density
    spawnEveryMs: 650,     // slower spawning
    sizeMin: 10,
    sizeMax: 44,
    lifeMin: 6.5,          // longer life (slower disappearance)
    lifeMax: 12.0,
    fadeInRatio: 0.40,     // slow fade in
    fadeOutRatio: 0.50,    // slow fade out
    baseOpacity: 0.38,     // a bit stronger than before so circles remain visible
    blurPx: 4,             // reduced blur: soft but still readable
    colorRGB: "255,255,255"
  };

  // ---------- Sizing ----------
  function resizeCanvas() {
    const newDpr = Math.max(1, Math.min(window.devicePixelRatio || 1, 2));
    dpr = newDpr;

    const rect = parent.getBoundingClientRect();
    wCss = Math.max(0, rect.width);
    hCss = Math.max(0, rect.height);

    canvas.width  = Math.round(wCss * dpr);
    canvas.height = Math.round(hCss * dpr);
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

    if (!primed && wCss > 0 && hCss > 0) {
      primeCircles();
    } else {
      while (circles.length < Math.floor(CFG.maxCircles * 0.7)) {
        spawnCircle({ seed: true });
      }
    }
  }

  const ro = new ResizeObserver(resizeCanvas);
  ro.observe(parent);
  window.addEventListener("resize", resizeCanvas);

  const mqLG = matchMedia("(min-width: 992px)");
  if (mqLG.addEventListener) mqLG.addEventListener("change", resizeCanvas);
  else if (mqLG.addListener) mqLG.addListener(resizeCanvas);

  window.addEventListener("load", resizeCanvas);

  // ---------- Circles ----------
  const rand = (a, b) => a + Math.random() * (b - a);

  function spawnCircle(opts = {}) {
    const seed = opts.seed === true;
    if (circles.length >= CFG.maxCircles || wCss === 0 || hCss === 0) return;

    const r = rand(CFG.sizeMin, CFG.sizeMax);
    const life = rand(CFG.lifeMin, CFG.lifeMax);

    circles.push({
      x: rand(0, wCss),
      y: rand(0, hCss),
      r,
      t: seed ? rand(0, life) : 0, // seeded ones start mid-life for full-panel coverage
      life
    });
  }

  function primeCircles() {
    const target = Math.floor(CFG.maxCircles * 0.7); // prefill ~70% for subtle, even coverage
    for (let i = 0; i < target; i++) spawnCircle({ seed: true });
    primed = true;
  }

  // Fade envelope 0..1 across life (slow in & out)
  function fadeAlpha(p) {
    const fi = CFG.fadeInRatio;
    const fo = CFG.fadeOutRatio;
    if (p < fi) {
      return Math.max(0, Math.min(1, p / fi));
    } else if (p > 1 - fo) {
      return Math.max(0, Math.min(1, (1 - p) / fo));
    } else {
      return 1;
    }
  }

  // ---------- Loop ----------
  function update(dt) {
    spawnAccMs += dt * 1000;
    while (spawnAccMs >= CFG.spawnEveryMs) {
      spawnCircle();
      spawnAccMs -= CFG.spawnEveryMs;
    }

    for (let i = circles.length - 1; i >= 0; i--) {
      const c = circles[i];
      c.t += dt;
      if (c.t >= c.life) circles.splice(i, 1);
    }
  }

  function render() {
    if (wCss === 0 || hCss === 0) return;
    ctx.clearRect(0, 0, wCss, hCss);

    for (const c of circles) {
      const p = Math.min(1, Math.max(0, c.t / c.life));
      const a = fadeAlpha(p) * CFG.baseOpacity;
      if (a <= 0) continue;

      ctx.save();
      ctx.filter = `blur(${CFG.blurPx}px)`;   // gentle blur
      ctx.globalAlpha = a;                    // opacity via globalAlpha
      ctx.beginPath();
      ctx.arc(c.x, c.y, c.r, 0, Math.PI * 2);
      ctx.fillStyle = `rgba(${CFG.colorRGB}, 1)`; // color; alpha handled by globalAlpha
      ctx.fill();
      ctx.restore();
    }
  }

  document.addEventListener("visibilitychange", () => {
    running = !document.hidden;
    if (running) {
      lastTs = performance.now();
      requestAnimationFrame(loop);
    }
  });

  function loop(ts) {
    if (!running) return;
    const dt = Math.min(0.05, (ts - lastTs) / 1000);
    lastTs = ts;
    update(dt);
    render();
    requestAnimationFrame(loop);
  }

  // Start
  resizeCanvas();
  requestAnimationFrame(loop);

  // Optional debug helpers
  window.EducAidCircles = {
    resize: resizeCanvas,
    stop() { running = false; },
    start() { if (!running) { running = true; lastTs = performance.now(); requestAnimationFrame(loop); } },
    cfg: CFG
  };
})();

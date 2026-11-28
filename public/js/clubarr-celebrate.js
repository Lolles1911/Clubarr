/*
 * Clubarr: a burst of confetti and a flash of the accent colour when a page
 * has [data-clubarr-celebrate] (an invitation, and just after joining).
 * No library; skipped with "reduce motion" or when the admin turned
 * animations off (html.cb-no-motion).
 */
(() => {
    const go = () => {
        const mark = document.querySelector('[data-clubarr-celebrate]');
        const root = document.documentElement;
        if (!mark || root.classList.contains('cb-no-motion') || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        const once = 'clubarr-celebrated:' + (mark.dataset.clubarrCelebrate || location.pathname);
        try { if (sessionStorage.getItem(once)) return; sessionStorage.setItem(once, '1'); } catch (e) { /* private mode: celebrate anyway */ }

        const accent = getComputedStyle(root).getPropertyValue('--primary-500').trim() || '#e11d48';
        const colours = [accent, '#ffffff', '#facc15', '#38bdf8', '#a78bfa', '#f472b6'];

        const flash = document.createElement('div');
        flash.style.cssText = 'position:fixed;inset:0;pointer-events:none;z-index:9998;background:radial-gradient(circle at 50% 35%,' + accent + ',transparent 60%);opacity:.55;transition:opacity 1.1s ease';
        document.body.appendChild(flash);
        requestAnimationFrame(() => requestAnimationFrame(() => { flash.style.opacity = '0'; }));
        setTimeout(() => flash.remove(), 1300);

        const canvas = document.createElement('canvas');
        canvas.style.cssText = 'position:fixed;inset:0;width:100%;height:100%;pointer-events:none;z-index:9999';
        document.body.appendChild(canvas);
        const ctx = canvas.getContext('2d');
        const dpr = Math.min(window.devicePixelRatio || 1, 2);
        const size = () => { canvas.width = innerWidth * dpr; canvas.height = innerHeight * dpr; };
        size();
        addEventListener('resize', size);

        const pieces = [];
        const burst = (x, y, n, spread) => {
            for (let i = 0; i < n; i++) {
                const a = -Math.PI / 2 + (Math.random() - .5) * spread;
                const v = (6 + Math.random() * 9) * dpr;
                pieces.push({ x: x * dpr, y: y * dpr, vx: Math.cos(a) * v, vy: Math.sin(a) * v, r: Math.random() * Math.PI, vr: (Math.random() - .5) * .3,
                    w: (6 + Math.random() * 6) * dpr, h: (8 + Math.random() * 10) * dpr, c: colours[i % colours.length], life: 0 });
            }
        };
        burst(innerWidth / 2, innerHeight * .38, 140, Math.PI * 1.6);
        setTimeout(() => { burst(innerWidth * .12, innerHeight * .9, 60, Math.PI / 2.2); burst(innerWidth * .88, innerHeight * .9, 60, Math.PI / 2.2); }, 250);

        const start = performance.now();
        const tick = (t) => {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            for (const p of pieces) {
                p.vy += .25 * dpr; p.vx *= .99; p.vy *= .99; p.x += p.vx; p.y += p.vy; p.r += p.vr; p.life++;
                ctx.save(); ctx.translate(p.x, p.y); ctx.rotate(p.r);
                ctx.globalAlpha = Math.max(0, 1 - (t - start) / 3800);
                ctx.fillStyle = p.c; ctx.fillRect(-p.w / 2, -p.h / 2, p.w, p.h * Math.abs(Math.cos(p.r * 2)));
                ctx.restore();
            }
            if (t - start < 3800) requestAnimationFrame(tick); else { canvas.remove(); removeEventListener('resize', size); }
        };
        requestAnimationFrame(tick);
    };
    document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', go) : go();
})();

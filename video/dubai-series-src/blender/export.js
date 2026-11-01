// Usage: node blender/export.js <ep>  ->  blender/data/ep<ep>/{world.json, frames.json, *.png}
// Loads the browser version (mg/index.html), lets gl3d.js build its world, then writes everything
// Blender needs: building parts with rise timing, Burj tiers, islands, trees, water, the painted
// ground textures, and per-frame camera / map opacity / highlight state (same logic as renderAt).
const { chromium } = require('../mg/node_modules/playwright-core');
const fs = require('fs'), path = require('path');
(async () => {
  const ep = process.argv[2] || '2';
  const out = path.join(__dirname, 'data', 'ep' + ep); fs.mkdirSync(out, { recursive: true });
  const b = await chromium.launch({ channel: 'msedge', args: ['--allow-file-access-from-files', '--disable-web-security', '--use-gl=angle', '--use-angle=swiftshader', '--enable-unsafe-swiftshader', '--disable-accelerated-2d-canvas', '--disable-gpu-compositing'] });
  const p = await b.newPage({ viewport: { width: 1080, height: 1920 } });
  p.on('pageerror', e => console.log('pageerror', e.message));
  const url = 'file:///' + path.join(__dirname, '..', 'mg', 'index.html').replace(/\\/g, '/') + '?ep=' + ep;
  await p.goto(url, { timeout: 600000 });
  await p.waitForFunction('window.READY === true && window.__GLX', null, { timeout: 600000 });

  const data = await p.evaluate(() => {
    const G = window.__GLX, W = window.__W;
    const rgb = c => c ? [+c.r.toFixed(4), +c.g.toFixed(4), +c.b.toFixed(4)] : null;
    const bIndex = new Map(), buildings = [], parts = [];
    for (const k in G.parts) for (const o of G.parts[k]) {
      const B = o.B; let bi = bIndex.get(B);
      if (bi === undefined) {
        bi = buildings.length; bIndex.set(B, bi);
        const bb = B.b;
        buildings.push({ cx: B.cx, cy: B.cy, ang: B.ang, at: bb.at + (bb.delay || 0), dur: bb.dur || .9, h: bb.h, z0: bb.z0 || 0,
          group: bb.group, office: !!bb.office, island: bb.island ?? -1 });
      }
      parts.push({ k, bi, lx: o.lx, ly: o.ly, w: o.w, d: o.d, y0: o.y0, hf: o.hf, abs: o.abs || 0, col: rgb(o.col), res: o.res || 0 });
    }
    const burj = G.burj.map((bb, k) => ({ c: bb.c, rad: Math.hypot(bb.poly[0][0] - bb.c[0], bb.poly[0][1] - bb.c[1]), z0: bb.z0 || 0, h: bb.h,
      at: bb.at + (bb.delay || 0), dur: bb.dur || .9, last: k === G.burj.length - 1 }));
    const islands = G.islandObjs.filter(o => !o.spit).map(o => ({ poly: o.I.poly, h: o.I.h, at: o.I.at, dur: o.I.dur || 1.2, rect: o.rect, name: o.I.name }));
    const spitAt = (W.islands && W.islands[0]) ? W.islands[0].at : 0;
    const trees = G.trees ? G.trees.all.map(o => ({ x: o.t[0], y: o.t[1], r: o.t[2], s: o.t[3], col: o.t[4], k: o.k })) : [];
    const water = W.water.map(w => w.poly);
    const grounds = G.grounds.map(g => ({ rect: g.rect, y: g.y }));
    return { kind: W.kind, buildings, parts, burj, islands, spits: W.spits || [], spitAt, trees, water, grounds,
      route: W.route || null, apt: W.apt || null, burjXY: W.burj || null };
  });
  fs.writeFileSync(path.join(out, 'world.json'), JSON.stringify(data));

  // painted textures (ground planes + island tops)
  const n = await p.evaluate(() => window.__GLX.grounds.length + window.__GLX.islandObjs.filter(o => !o.spit).length);
  for (let i = 0; i < n; i++) {
    const url64 = await p.evaluate(i => {
      const G = window.__GLX, gl = G.grounds, isl = G.islandObjs.filter(o => !o.spit);
      return (i < gl.length ? gl[i].c : isl[i - gl.length].c).toDataURL('image/png');
    }, i);
    const name = i < data.grounds.length ? `ground${i}.png` : `island${i - data.grounds.length}.png`;
    fs.writeFileSync(path.join(out, name), Buffer.from(url64.split(',')[1], 'base64'));
  }

  // per-frame camera + map opacity + highlight flags, mirroring renderAt() in index.html
  const frames = await p.evaluate(() => {
    const total = window.TOTAL, N = Math.round(total * 30), res = [];
    for (let f = 0; f < N; f++) {
      const t = f / 30; let ma = 0, call = null;
      for (const s of SC) {
        if (!s.map) continue; const lt = t - s.start; if (lt < -.35 || lt > s.dur + .35) continue;
        const a = Math.min(clamp((lt + .35) / .6), clamp((s.dur + .35 - lt) / .6)) * (s.map.alpha ?? 1);
        if (a <= ma) continue; ma = a;
        const k = MAPUTIL.easeInOut(clamp(lt / s.dur));
        let cam = MAPUTIL.lerpCam(s.map.cam0 || s.map.keys[0][1], s.map.cam1 || s.map.cam0 || s.map.keys[0][1], k);
        if (s.map.keys) {
          const K = s.map.keys; cam = K[0][1];
          for (let i = 0; i < K.length - 1; i++) if (t >= K[i][0]) cam = MAPUTIL.lerpCam(K[i][1], K[i + 1][1], MAPUTIL.easeInOut(clamp((t - K[i][0]) / (K[i + 1][0] - K[i][0]))));
        }
        const hl = s.map.hl;
        call = { scene: s.id, cam, hlOffice: !!(hl && hl({ office: true, group: 'bb' }, t)), hlBurj: !!(hl && hl({ group: 'burj' }, t)) };
      }
      res.push({ f, t, ma: +ma.toFixed(4), ...(call || {}) });
    }
    return res;
  });
  fs.writeFileSync(path.join(out, 'frames.json'), JSON.stringify(frames));
  console.log('ep', ep, 'buildings', data.buildings.length, 'parts', data.parts.length, 'trees', data.trees.length, 'frames', frames.length,
    'map frames', frames.filter(f => f.ma > 0).length);
  await b.close();
})();

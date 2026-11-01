// node blender/paint_ground.js <ep>  ->  blender/real/ep<ep>/ground_z<zoom>.png
// Stylised "architectural model" ground in the look of ref/16.webp: warm tan desert/city texture
// (tone-mapped from the aerial's luminance), deep teal water (detected from the aerial), muted
// greens, and the real road network from OpenStreetMap drawn as clean dark asphalt.
const { chromium } = require('../mg/node_modules/playwright-core');
const fs = require('fs'), path = require('path');
const ep = process.argv[2] || '2';
const dir = path.join(__dirname, 'real', 'ep' + ep);
const M = JSON.parse(fs.readFileSync(path.join(dir, 'img.json')));
const osm2 = JSON.parse(fs.readFileSync(path.join(dir, 'osm2.json')));
// EP5: the Nakheel-plan sea/islands (map units) placed on the real map with the same fit as build_real.py
const K = 4.333764, TH = 0.2365397, MS = [-8.800355, 19.568184], MR = [586.6, 3006.2];
const Mm = ([u, v]) => { u = (u - MS[0]) * K; v = (v - MS[1]) * K; return [MR[0] + u * Math.cos(TH) - v * Math.sin(TH), MR[1] + u * Math.sin(TH) + v * Math.cos(TH)]; };
const W5 = ep === '5' ? JSON.parse(fs.readFileSync(path.join(__dirname, 'data', 'ep5', 'world.json'))) : null;
const plan = W5 ? { sea: W5.water[0].map(Mm), islands: W5.islands.map(I => I.poly.map(Mm)) } : null;
const SAT = process.argv[3] === 'sat';     // "sat": bright satellite-photo look (Google Earth style) instead of the tan model ground
const water = JSON.parse(fs.readFileSync(path.join(dir, 'osm.json'))).filter(e => e.t.natural === 'water' && !/pond|reflecting_pool|drain/.test(e.t.water || '') && e.p.length > 1).map(e => e.p);
(async () => {
  fs.writeFileSync(path.join(dir, 'paint.html'), '<!doctype html><body></body>');
  const b = await chromium.launch({ channel: 'msedge', args: ['--allow-file-access-from-files', '--disable-web-security', '--disable-accelerated-2d-canvas', '--disable-gpu', '--js-flags=--max-old-space-size=6000'] });
  const p = await b.newPage(); p.on('console', m => console.log('page:', m.text())); p.on('pageerror', e => console.log('err', e.message));
  await p.goto('file:///' + path.join(dir, 'paint.html').replace(/\\/g, '/'));
  for (const ring of M.rings) {
    const t0 = Date.now();
    const url = await p.evaluate(async ({ ring, osm2, water, plan, SAT }) => {
      const img = await new Promise((ok, no) => { const i = new Image(); i.onload = () => ok(i); i.onerror = no; i.src = ring.file; });
      const N = ring.px[0], H = ring.px[1], c = document.createElement('canvas'); c.width = N; c.height = H;
      const x = c.getContext('2d', { willReadFrequently: true }); x.drawImage(img, 0, 0);
      const [sx0, sy0] = ring.sw_m, [sx1, sy1] = ring.ne_m, s = N / (sx1 - sx0);
      const P = (mx, my) => [(mx - sx0) * s, (sy1 - my) * (H / (sy1 - sy0))];
      // 1) tone-map the aerial into the tan model palette
      const id = x.getImageData(0, 0, N, H), d = id.data;
      const sm = v => v < 0 ? 0 : v > 1 ? 1 : v * v * (3 - 2 * v);
      // open sea is very dark in the aerial: find large dark areas on a coarse grid (tower shadows are small)
      const GS = 24, gw = Math.ceil(N / GS), gh = Math.ceil(H / GS), dark = new Float32Array(gw * gh);
      for (let gy = 0; gy < gh; gy++) for (let gx = 0; gx < gw; gx++) {
        let n = 0, k = 0;
        for (let yy = gy * GS; yy < Math.min(H, gy * GS + GS); yy += 4) for (let xx = gx * GS; xx < Math.min(N, gx * GS + GS); xx += 4) {
          const o = (yy * N + xx) * 4, lm = (.3 * d[o] + .59 * d[o + 1] + .11 * d[o + 2]) / 255;
          n++; if (lm < .13 && d[o + 2] >= d[o] - 3) k++;
        }
        dark[gy * gw + gx] = k / n;
      }
      const sea = new Float32Array(gw * gh);                  // erode: a cell is sea if it and its neighbours are dark
      for (let gy = 0; gy < gh; gy++) for (let gx = 0; gx < gw; gx++) {
        const ER = plan ? 1 : 2; let m = 1; for (let dy = -ER; dy <= ER; dy++) for (let dx = -ER; dx <= ER; dx++) { const yy = Math.min(gh - 1, Math.max(0, gy + dy)), xx = Math.min(gw - 1, Math.max(0, gx + dx)); m = Math.min(m, dark[yy * gw + xx]); }
        sea[gy * gw + gx] = m;
      }
      const sea0 = sea.slice();                               // dilate back (opening) so the coast is reached; brightness decides the edge
      for (let gy = 0; gy < gh; gy++) for (let gx = 0; gx < gw; gx++) {
        let m = 0; for (let dy = -3; dy <= 3; dy++) for (let dx = -3; dx <= 3; dx++) { const yy = Math.min(gh - 1, Math.max(0, gy + dy)), xx = Math.min(gw - 1, Math.max(0, gx + dx)); m = Math.max(m, sea0[yy * gw + xx]); }
        sea[gy * gw + gx] = m;
      }
      for (let i = 0; i < d.length; i += 4) {
        const r = d[i] / 255, g = d[i + 1] / 255, bl = d[i + 2] / 255;
        const lum = .3 * r + .59 * g + .11 * bl;
        const px = (i / 4) % N, py = Math.floor(i / 4 / N), sv = sea[Math.floor(py / GS) * gw + Math.floor(px / GS)];
        const water = Math.max(sm((bl - r - .05) / .06) * sm((g - r) / .05) * sm((.42 - lum) / .12), sm((sv - .6) / .3) * sm((.2 - lum) / .08));
        const green = sm((g - r - .05) / .06) * sm((g - bl - .03) / .05) * (1 - water);
        const L = Math.pow(sm(lum * 1.15), .85);
        // sand / city tan: dark umber -> warm sand -> pale cream on bright roofs
        if (SAT) {                                            // lift + soften the photo, slight warmth
          const lift = v => 255 * (.09 + .91 * Math.pow(v, .8));
          let R = lift(r), G2 = lift(g), B = lift(bl); const m = .3 * R + .59 * G2 + .11 * B;
          R = m + (R - m) * .78 + 4; G2 = m + (G2 - m) * .78 + 1; B = m + (B - m) * .78 - 3;
          const wl2 = Math.min(1, lum * 2.2), wc = [22 + wl2 * 20, 70 + wl2 * 40, 96 + wl2 * 40];
          d[i] = R * (1 - water) + water * wc[0]; d[i + 1] = G2 * (1 - water) + water * wc[1]; d[i + 2] = B * (1 - water) + water * wc[2]; d[i + 3] = 255; continue;
        }
        let R = 98 + L * 150, G = 70 + L * 118, B = 40 + L * 78;
        if (L > .75) { const k = (L - .75) / .25; R += k * 12; G += k * 18; B += k * 30; }
        R = R * (1 - green * .55) + green * (86 + L * 50); G = G * (1 - green * .55) + green * (100 + L * 60); B = B * (1 - green * .55) + green * (52 + L * 25);
        const wl = Math.min(1, lum * 2.2);
        const wc = plan ? [18 + wl * 20, 63 + wl * 45, 94 + wl * 50] : [14 + wl * 28, 70 + wl * 60, 88 + wl * 60];
        R = R * (1 - water) + water * wc[0]; G = G * (1 - water) + water * wc[1]; B = B * (1 - water) + water * wc[2];
        d[i] = R; d[i + 1] = G; d[i + 2] = B; d[i + 3] = 255;
      }
      x.putImageData(id, 0, 0);
      const path_ = (pts, close) => { x.beginPath(); pts.forEach((q, i) => { const [a, bb] = P(q[0], q[1]); i ? x.lineTo(a, bb) : x.moveTo(a, bb); }); if (close) x.closePath(); };
      if (!SAT) {
      // 2) parks / greens from OSM (soft fill)
      for (const e of osm2) {
        const t = e.t; if (!(t.leisure || t.landuse || t.natural) || e.p.length < 4 || t.natural === 'scrub' || t.natural === 'wood') continue;
        { const xs = e.p.map(q => q[0]), ys = e.p.map(q => q[1]); if ((Math.max(...xs) - Math.min(...xs)) * (Math.max(...ys) - Math.min(...ys)) > 4e5) continue; }
        const golf = t.leisure === 'golf_course' || t.leisure === 'pitch' || t.landuse === 'grass';
        const sand = t.natural === 'sand' || t.natural === 'beach';
        path_(e.p, true); x.globalAlpha = sand ? .35 : .5; x.fillStyle = sand ? '#d8bf92' : golf ? '#7f9a47' : '#5f7a3c'; x.fill(); x.globalAlpha = 1;
      }
      // 2b) canal / lakes from OSM water outlines (fragments of multipolygons joined into rings)
      { const segs = water.map(p => p.slice()), rings = [];
        const key = q => q[0].toFixed(1) + ',' + q[1].toFixed(1);
        while (segs.length) {
          let r = segs.pop(), grew = true;
          while (grew && key(r[0]) !== key(r[r.length - 1])) {
            grew = false;
            for (let i = 0; i < segs.length; i++) {
              const sgm = segs[i], a = key(sgm[0]), z = key(sgm[sgm.length - 1]), e = key(r[r.length - 1]);
              if (a === e) { r = r.concat(sgm.slice(1)); segs.splice(i, 1); grew = true; break; }
              if (z === e) { r = r.concat(sgm.slice().reverse().slice(1)); segs.splice(i, 1); grew = true; break; }
            }
          }
          if (r.length > 3) rings.push(r);
        }
        x.beginPath();
        for (const r of rings) r.forEach((q, i) => { const [a, bb] = P(q[0], q[1]); i ? x.lineTo(a, bb) : x.moveTo(a, bb); });
        x.fillStyle = '#16505c'; x.fill('nonzero');
        x.strokeStyle = 'rgba(205,190,160,.9)'; x.lineWidth = Math.max(1, 2.5 * s); x.stroke(); }
      // 3) roads: casing + asphalt, width by class (metres)
      const W = { motorway: 30, trunk: 26, primary: 18, secondary: 14, tertiary: 11, motorway_link: 9, trunk_link: 9, primary_link: 8, secondary_link: 7, residential: 6.5, unclassified: 6, living_street: 5, service: 3.5 };
      const roads = osm2.filter(e => e.t.highway && W[e.t.highway]).sort((a, b) => W[a.t.highway] - W[b.t.highway]);
      x.lineCap = 'round'; x.lineJoin = 'round';
      for (const pass of [0, 1]) for (const e of roads) {
        const w = W[e.t.highway] * s, major = W[e.t.highway] >= 11;
        if (w < .6 && pass === 0) continue;
        path_(e.p, false);
        if (pass === 0) { x.strokeStyle = major ? '#9a907f' : '#8d826f'; x.lineWidth = Math.max(1, w + (major ? 4 : 2) * s); }
        else { x.strokeStyle = major ? '#45474a' : '#5d5b57'; x.lineWidth = Math.max(.7, w); }
        x.stroke();
      }
      // lane markings on the big roads where resolution allows
      if (s > .5) for (const e of roads) { if (W[e.t.highway] < 18) continue; path_(e.p, false); x.setLineDash([3 * s, 4 * s]); x.strokeStyle = 'rgba(235,232,222,.5)'; x.lineWidth = Math.max(.6, .3 * s); x.stroke(); x.setLineDash([]); }
      }
      if (plan) {                                             // open sea over the island area: the islands rise from it (build_real.py)
        path_(plan.sea, true); x.save(); x.clip();
        x.fillStyle = SAT ? '#1d4e66' : '#16455f'; x.fillRect(0, 0, N, H);
        let rs = 31; const rr = () => (rs = (rs * 1664525 + 1013904223) >>> 0) / 4294967296;
        x.filter = 'blur(' + Math.max(2, Math.round(N / 120)) + 'px)';
        for (let i = 0; i < 160; i++) { x.fillStyle = rr() < .6 ? 'rgba(8,38,70,.25)' : 'rgba(34,96,128,.2)'; x.beginPath(); const [a, bb] = P(-9000 + rr() * 20000, -4000 + rr() * 16000); x.ellipse(a, bb, (300 + rr() * 1500) * s, (200 + rr() * 900) * s, rr() * 3, 0, 6.28); x.fill(); }
        x.filter = 'blur(' + Math.max(1, Math.round(60 * s)) + 'px)';
        for (const I of plan.islands) { path_(I, true); x.lineWidth = 150 * s; x.strokeStyle = 'rgba(52,160,170,.75)'; x.stroke(); x.fillStyle = 'rgba(52,160,170,.75)'; x.fill(); }
        x.filter = 'none'; x.restore();
        path_(plan.sea.slice(0, -2), false); x.strokeStyle = '#e6d6b0'; x.lineWidth = Math.max(1, 25 * s); x.stroke();
      }
      return c.toDataURL('image/png');
    }, { ring, osm2, water, plan, SAT });
    fs.writeFileSync(path.join(dir, 'ground_z' + ring.z + '.png'), Buffer.from(url.split(',')[1], 'base64'));
    console.log('ring', ring.z, ((Date.now() - t0) / 1000).toFixed(1) + 's');
  }
  await b.close();
})();

// Tiny 2.5D city renderer on Canvas2D: perspective camera, extruded footprints that rise from
// marked plots, canal/sea polygons, roads, shadows, lit window floors, labels and pins.
(function () {
  const V = {
    sub: (a, b) => [a[0] - b[0], a[1] - b[1], a[2] - b[2]], add: (a, b) => [a[0] + b[0], a[1] + b[1], a[2] + b[2]],
    mul: (a, s) => [a[0] * s, a[1] * s, a[2] * s], dot: (a, b) => a[0] * b[0] + a[1] * b[1] + a[2] * b[2],
    cross: (a, b) => [a[1] * b[2] - a[2] * b[1], a[2] * b[0] - a[0] * b[2], a[0] * b[1] - a[1] * b[0]],
    norm: a => { const l = Math.hypot(a[0], a[1], a[2]) || 1; return [a[0] / l, a[1] / l, a[2] / l]; },
  };
  const clamp = (x, a = 0, b = 1) => Math.max(a, Math.min(b, x));
  const easeOutBack = t => { const c = 1.25; return 1 + (c + 1) * Math.pow(t - 1, 3) + c * Math.pow(t - 1, 2); };
  const easeInOut = t => t < .5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2;
  const lerp = (a, b, t) => a + (b - a) * t;
  function rng(seed) { let s = seed >>> 0 || 1; return () => { s = (s * 1664525 + 1013904223) >>> 0; return s / 4294967296; }; }

  function camera(c, W, H) {
    const yaw = c.yaw * Math.PI / 180, pit = c.pitch * Math.PI / 180;
    const T = [c.x, c.y, 0];
    const dirH = [Math.sin(yaw), Math.cos(yaw), 0];
    const C = [T[0] - dirH[0] * c.dist * Math.cos(pit), T[1] - dirH[1] * c.dist * Math.cos(pit), c.dist * Math.sin(pit)];
    const fwd = V.norm(V.sub(T, C)), right = V.norm(V.cross(fwd, [0, 0, 1])), up = V.cross(right, fwd);
    const f = c.f || 1100, cx = W / 2, cy = H * (c.cy || .5);
    return {
      C, fwd,
      p(pt) {
        const v = V.sub(pt, C); const z = Math.max(V.dot(v, fwd), 1);
        return [cx + f * V.dot(v, right) / z, cy - f * V.dot(v, up) / z, z];
      },
      scale(pt) { const v = V.sub(pt, C); return f / Math.max(V.dot(v, fwd), 1); },
      // clip a world-space polygon/polyline against the near plane, then project
      clip(pts, closed) {
        const NEAR = 5, d = pts.map(p => V.dot(V.sub(p, C), fwd) - NEAR), out = [];
        const n = pts.length, m = closed ? n : n - 1;
        if (!closed && n && d[0] >= 0) out.push(pts[0]);
        for (let i = 0; i < m; i++) {
          const a = pts[i], b = pts[(i + 1) % n], da = d[i], db = d[(i + 1) % n];
          if (closed && da >= 0) out.push(a);
          if ((da >= 0) !== (db >= 0)) { const t = da / (da - db); out.push([a[0] + (b[0] - a[0]) * t, a[1] + (b[1] - a[1]) * t, a[2] + (b[2] - a[2]) * t]); }
          if (!closed && db >= 0) out.push(b);
        }
        return out.map(p => this.p(p));
      },
    };
  }
  function lerpCam(a, b, t) {
    const o = {}; for (const k in a) o[k] = typeof a[k] === 'number' ? lerp(a[k], b[k] ?? a[k], t) : a[k]; return o;
  }
  // offset a polyline into a ribbon polygon (world space)
  function ribbon(pts, w) {
    const L = [], R = [];
    for (let i = 0; i < pts.length; i++) {
      const a = pts[Math.max(i - 1, 0)], b = pts[Math.min(i + 1, pts.length - 1)];
      let dx = b[0] - a[0], dy = b[1] - a[1]; const l = Math.hypot(dx, dy) || 1; dx /= l; dy /= l;
      const ww = typeof w === 'function' ? w(i / (pts.length - 1)) : w;
      L.push([pts[i][0] - dy * ww / 2, pts[i][1] + dx * ww / 2]); R.push([pts[i][0] + dy * ww / 2, pts[i][1] - dx * ww / 2]);
    }
    return L.concat(R.reverse());
  }
  function catmull(pts, n = 8) {
    const out = [];
    for (let i = 0; i < pts.length - 1; i++) {
      const p0 = pts[Math.max(i - 1, 0)], p1 = pts[i], p2 = pts[i + 1], p3 = pts[Math.min(i + 2, pts.length - 1)];
      for (let k = 0; k < n; k++) {
        const t = k / n, t2 = t * t, t3 = t2 * t;
        out.push([0, 1].map(j => .5 * ((2 * p1[j]) + (-p0[j] + p2[j]) * t + (2 * p0[j] - 5 * p1[j] + 4 * p2[j] - p3[j]) * t2 + (-p0[j] + 3 * p1[j] - 3 * p2[j] + p3[j]) * t3)));
      }
    }
    out.push(pts[pts.length - 1]); return out;
  }
  function blob(cx, cy, rx, ry, seed, rot = 0, n = 40) {
    const r = rot * Math.PI / 180, out = [];
    for (let i = 0; i < n; i++) {
      const a = i / n * Math.PI * 2;
      const w = 1 + .12 * Math.sin(seed * 3.1 + a * 3) + .06 * Math.cos(seed * 1.7 + a * 5) + .03 * Math.sin(seed + a * 9);
      const x = Math.cos(a) * rx * w, y = Math.sin(a) * ry * w;
      out.push([cx + x * Math.cos(r) - y * Math.sin(r), cy + x * Math.sin(r) + y * Math.cos(r)]);
    }
    return out;
  }
  const boxPoly = (x, y, w, d, rot = 0) => {
    const r = rot * Math.PI / 180, c = Math.cos(r), s = Math.sin(r);
    return [[-w / 2, -d / 2], [w / 2, -d / 2], [w / 2, d / 2], [-w / 2, d / 2]].map(([a, b]) => [x + a * c - b * s, y + a * s + b * c]);
  };
  const ngon = (x, y, r, n, rot = 0) => Array.from({ length: n }, (_, i) => { const a = rot + i / n * Math.PI * 2; return [x + Math.cos(a) * r, y + Math.sin(a) * r]; });
  function inPoly(pt, poly) {
    let c = false; for (let i = 0, j = poly.length - 1; i < poly.length; j = i++) {
      const [xi, yi] = poly[i], [xj, yj] = poly[j];
      if (((yi > pt[1]) !== (yj > pt[1])) && (pt[0] < (xj - xi) * (pt[1] - yi) / (yj - yi) + xi)) c = !c;
    } return c;
  }
  function distToPolyline(pt, pl) {
    let m = 1e9; for (let i = 0; i < pl.length - 1; i++) {
      const a = pl[i], b = pl[i + 1], dx = b[0] - a[0], dy = b[1] - a[1], l2 = dx * dx + dy * dy;
      const t = clamp(((pt[0] - a[0]) * dx + (pt[1] - a[1]) * dy) / l2); m = Math.min(m, Math.hypot(pt[0] - a[0] - t * dx, pt[1] - a[1] - t * dy));
    } return m;
  }

  function Map3D(canvas, world) {
    const ctx = canvas.getContext('2d'), W = canvas.width, H = canvas.height;
    const LIGHT = V.norm([-.55, .35, .75]);
    function poly(cam, pts, z = 0) { ctx.beginPath(); cam.clip(pts.map(p => [p[0], p[1], z]), true).forEach((s, i) => i ? ctx.lineTo(s[0], s[1]) : ctx.moveTo(s[0], s[1])); ctx.closePath(); }
    function line(cam, pts, z = 0) { ctx.beginPath(); cam.clip(pts.map(p => [p[0], p[1], z]), false).forEach((s, i) => i ? ctx.lineTo(s[0], s[1]) : ctx.moveTo(s[0], s[1])); }
    function shade(rgb, k) { return `rgb(${rgb.map(v => Math.round(clamp(v * k, 0, 255))).join(',')})`; }
    function prism(cam, b, h, t, hl) {
      const pts = b.poly, n = pts.length, z0 = b.z0 || 0, z1 = z0 + h;
      // shadow on ground
      if (h > 1 && !b.noShadow) {
        const off = [-LIGHT[0] / LIGHT[2] * h * .55, -LIGHT[1] / LIGHT[2] * h * .55];
        ctx.fillStyle = world.clay ? 'rgba(70,60,40,.22)' : 'rgba(0,0,0,.22)';
        const sh = pts.map(p => [p[0] + off[0], p[1] + off[1]]);
        poly(cam, pts.concat(sh.slice().reverse().slice(0)), z0 + .01); ctx.fill();
      }
      const faces = [];
      for (let i = 0; i < n; i++) {
        const a = pts[i], c = pts[(i + 1) % n];
        const nrm = V.norm([c[1] - a[1], -(c[0] - a[0]), 0]);
        const mid = [(a[0] + c[0]) / 2, (a[1] + c[1]) / 2, (z0 + z1) / 2];
        const vis = V.dot(nrm, V.sub(cam.C, mid));
        // winding may be either way: decide by polygon orientation
        faces.push({ a, c, nrm, vis });
      }
      let area = 0; for (let i = 0; i < n; i++) { const a = pts[i], c = pts[(i + 1) % n]; area += a[0] * c[1] - c[0] * a[1]; }
      const flip = area > 0 ? -1 : 1;
      const col = hl ? b.hl || [214, 176, 112] : b.col;
      for (const f of faces) {
        if (f.vis * flip <= 0) continue;
        if (V.dot(V.sub([f.a[0], f.a[1], z0], cam.C), cam.fwd) < 5 || V.dot(V.sub([f.c[0], f.c[1], z0], cam.C), cam.fwd) < 5) continue;
        const k = .55 + .45 * Math.max(0, V.dot(V.mul(f.nrm, flip), LIGHT)) + .1;
        ctx.fillStyle = shade(col, k);
        ctx.beginPath(); const s1 = cam.p([f.a[0], f.a[1], z0]), s2 = cam.p([f.c[0], f.c[1], z0]), s3 = cam.p([f.c[0], f.c[1], z1]), s4 = cam.p([f.a[0], f.a[1], z1]);
        ctx.moveTo(s1[0], s1[1]); ctx.lineTo(s2[0], s2[1]); ctx.lineTo(s3[0], s3[1]); ctx.lineTo(s4[0], s4[1]); ctx.closePath(); ctx.fill();
        // floors + lit windows
        if (b.floors && h > b.floors * 2) {
          const r = rng(b.seed * 31 + (f.a[0] * 7 | 0));
          ctx.lineWidth = Math.max(.6, cam.scale([f.a[0], f.a[1], z1]) * .35);
          for (let z = z0 + b.floors; z < z1 - .5; z += b.floors) {
            const p1 = cam.p([f.a[0], f.a[1], z]), p2 = cam.p([f.c[0], f.c[1], z]);
            ctx.strokeStyle = `rgba(10,16,14,${hl ? .18 : (world.clay ? .10 : .28)})`; ctx.beginPath(); ctx.moveTo(p1[0], p1[1]); ctx.lineTo(p2[0], p2[1]); ctx.stroke();
            if (b.lit && r() < b.lit) {
              const u0 = r() * .7, u1 = u0 + .08 + r() * .2;
              const q1 = cam.p([lerp(f.a[0], f.c[0], u0), lerp(f.a[1], f.c[1], u0), z + b.floors * .45]), q2 = cam.p([lerp(f.a[0], f.c[0], u1), lerp(f.a[1], f.c[1], u1), z + b.floors * .45]);
              ctx.strokeStyle = 'rgba(255,214,150,.75)'; ctx.beginPath(); ctx.moveTo(q1[0], q1[1]); ctx.lineTo(q2[0], q2[1]); ctx.stroke();
            }
          }
        }
        ctx.strokeStyle = `rgba(255,240,210,${hl ? .55 : .08})`; ctx.lineWidth = 1;
        ctx.beginPath(); ctx.moveTo(s3[0], s3[1]); ctx.lineTo(s4[0], s4[1]); ctx.stroke();
      }
      ctx.fillStyle = shade(col, hl ? 1.18 : 1.12); poly(cam, pts, z1); ctx.fill();
      if (hl) { ctx.strokeStyle = 'rgba(255,228,170,.9)'; ctx.lineWidth = 1.5; ctx.stroke(); }
    }

    // ---------------------------------------------------------------- render
    return function render(t, camDef, opt = {}) {
      ctx.clearRect(0, 0, W, H);
      const cam = camera(camDef, W, H);
      const g = world.ground;
      if (!world.gl) {
      // ground plane
      ctx.fillStyle = g.land; poly(cam, g.bounds); ctx.fill();
      // land texture blocks (streets grid)
      if (world.streets) { ctx.strokeStyle = g.street; ctx.lineWidth = 1; for (const s of world.streets) { line(cam, s); ctx.stroke(); } }
      // sea / water bodies
      for (const w of world.water) {
        const grd = ctx.createLinearGradient(0, 0, 0, H); grd.addColorStop(0, w.c0); grd.addColorStop(1, w.c1);
        ctx.fillStyle = grd; poly(cam, w.poly); ctx.fill();
        if (w.edge) { ctx.strokeStyle = w.edge; ctx.lineWidth = 1.6; ctx.stroke(); }
      }
      // shimmer on water
      if (world.shimmer) {
        const r = rng(7); ctx.strokeStyle = 'rgba(190,240,225,.13)'; ctx.lineWidth = 1.2;
        for (let i = 0; i < 120; i++) {
          const x = world.shimmer.x0 + r() * (world.shimmer.x1 - world.shimmer.x0), y = world.shimmer.y0 + r() * (world.shimmer.y1 - world.shimmer.y0);
          if (world.shimmer.test && !world.shimmer.test([x, y])) continue;
          const ph = (t * .6 + r()) % 1, len = 6 + r() * 14; line(cam, [[x + ph * 8, y], [x + ph * 8 + len, y]]); ctx.globalAlpha = Math.sin(ph * Math.PI); ctx.stroke(); ctx.globalAlpha = 1;
        }
      }
      // islands / raised land
      if (world.shallows) for (const isl of world.islands || []) {          // turquoise shallows around each island
        const p = easeInOut(clamp((t - isl.at) / (isl.dur || 1.2))); if (p <= 0) continue;
        const sc = cam.scale([isl.c[0], isl.c[1], 0]);
        for (const [w, a] of [[46, .10], [28, .16], [14, .24]]) { ctx.strokeStyle = `rgba(110,214,214,${a * p})`; ctx.lineWidth = w * sc; ctx.lineJoin = 'round'; poly(cam, isl.poly, 0); ctx.stroke(); }
      }
      for (const isl of world.islands || []) {
        const p = easeInOut(clamp((t - isl.at) / (isl.dur || 1.2)));
        if (p <= 0) { ctx.setLineDash([6, 8]); ctx.strokeStyle = 'rgba(230,200,145,.35)'; ctx.lineWidth = 1.5; if (t > isl.at - 1.2) { poly(cam, isl.poly, 0); ctx.stroke(); } ctx.setLineDash([]); continue; }
        prism(cam, { poly: isl.poly, col: isl.col, noShadow: true }, isl.h * p, t, false);
        ctx.fillStyle = isl.top; poly(cam, isl.inner || isl.poly, isl.h * p + .05); ctx.fill();
        if (world.sandRim) { ctx.fillStyle = 'rgba(0,0,0,0)'; }
        if (isl.trees && p > .6) {                                         // canopy texture
          const tp = clamp((p - .6) / .4), zt = isl.h * p + .1;
          for (const tr of isl.trees) { if (tr[3] > tp) continue; const s2 = cam.p([tr[0], tr[1], zt]), rr = Math.max(.8, cam.scale([tr[0], tr[1], zt]) * tr[2]);
            ctx.fillStyle = tr[4]; ctx.beginPath(); ctx.arc(s2[0], s2[1], rr, 0, 6.283); ctx.fill(); }
        }
        if (opt.beach != null && opt.beach > 0) {
          const q = clamp((t - opt.beach - (isl.k || 0) * .22) / 1.3); if (q > 0) {
            const pts = isl.poly.slice(0, Math.max(2, Math.round(isl.poly.length * q))); ctx.strokeStyle = '#f3dca7'; ctx.lineWidth = 5; ctx.lineCap = 'round';
            line(cam, q >= 1 ? isl.poly.concat([isl.poly[0]]) : pts, isl.h * p + .1); ctx.stroke(); ctx.lineCap = 'butt';
          }
        }
      }
      // roads
      for (const r of world.roads || []) {
        const p = clamp((t - (r.at || -9)) / (r.dur || 1.2)); if (p <= 0) continue;
        const pts = r.pts.slice(0, Math.max(2, Math.ceil(r.pts.length * p)));
        ctx.strokeStyle = r.col; ctx.lineWidth = Math.max(1, Math.min(40, cam.scale([camDef.x, camDef.y, 0]) * r.w)); ctx.lineCap = 'round'; line(cam, pts, .02); ctx.stroke(); ctx.lineCap = 'butt';
      }
      }
      // routes (animated dashed)
      for (const r of opt.routes || []) {
        const p = easeInOut(clamp((t - r.at) / (r.dur || 1.5))); if (p <= 0) continue;
        const pts = r.pts.slice(0, Math.max(2, Math.ceil(r.pts.length * p)));
        ctx.strokeStyle = 'rgba(230,200,145,.35)'; ctx.lineWidth = 12; ctx.lineCap = 'round'; line(cam, pts, .5); ctx.stroke();
        ctx.strokeStyle = '#f3dca7'; ctx.lineWidth = 5; ctx.setLineDash([16, 12]); ctx.lineDashOffset = -t * 60; line(cam, pts, .5); ctx.stroke(); ctx.setLineDash([]); ctx.lineCap = 'butt';
        if (r.plane && p > 0 && p < 1) {
          const e = pts[pts.length - 1], s = cam.p([e[0], e[1], 8 + 18 * Math.sin(p * Math.PI)]); const pr = pts[Math.max(pts.length - 3, 0)], sp = cam.p([pr[0], pr[1], 8]);
          const ang = Math.atan2(s[1] - sp[1], s[0] - sp[0]); ctx.save(); ctx.translate(s[0], s[1]); ctx.rotate(ang); ctx.fillStyle = '#fff';
          ctx.beginPath(); ctx.moveTo(18, 0); ctx.lineTo(-10, -4); ctx.lineTo(-4, -16); ctx.lineTo(-8, -16); ctx.lineTo(-16, -3); ctx.lineTo(-22, -3); ctx.lineTo(-24, -9); ctx.lineTo(-27, -9); ctx.lineTo(-25, 0);
          ctx.lineTo(-27, 9); ctx.lineTo(-24, 9); ctx.lineTo(-22, 3); ctx.lineTo(-16, 3); ctx.lineTo(-8, 16); ctx.lineTo(-4, 16); ctx.lineTo(-10, 4); ctx.closePath(); ctx.fill(); ctx.restore();
        }
      }
      if (!world.gl) {
      // plots + buildings, far to near
      const bl = world.buildings.map(b => { const c = b.c || (b.c = b.poly.reduce((a, p) => [a[0] + p[0] / b.poly.length, a[1] + p[1] / b.poly.length], [0, 0])); const s = cam.p([c[0], c[1], 0]); return { b, z: s[2] - (b.zb || 0) }; });
      bl.sort((a, b) => b.z - a.z);
      for (const { b } of bl) {
        const at = b.at + (b.delay || 0);
        if (t < at - .9) continue;
        if (cam.p([b.c[0], b.c[1], 0])[2] < 30) continue;
        const plotA = clamp((t - (at - .9)) / .5);
        const p = clamp((t - at) / (b.dur || .9));
        if (p <= 0) { // marked plot on bare ground
          ctx.strokeStyle = `rgba(230,200,145,${.55 * plotA})`; ctx.lineWidth = 1.2; poly(cam, b.poly, (b.z0 || 0) + .05); ctx.stroke();
          ctx.fillStyle = `rgba(230,200,145,${.08 * plotA})`; ctx.fill(); continue;
        }
        const hl = opt.hl && opt.hl(b, t);
        prism(cam, b, b.h * easeOutBack(p), t, hl);
        if (p < 1 && b.h > 6) { // dust ring as it breaks ground
          const c = b.c, s = cam.p([c[0], c[1], b.z0 || 0]), sc = cam.scale([c[0], c[1], 0]);
          ctx.strokeStyle = `rgba(243,220,167,${.5 * (1 - p)})`; ctx.lineWidth = 2; ctx.beginPath(); ctx.ellipse(s[0], s[1], sc * (8 + 30 * p), sc * (3 + 12 * p), 0, 0, Math.PI * 2); ctx.stroke();
        }
      }
      }
      for (const L of opt.leaders || []) {                                    // brochure-style title with a hairline leader
        const a = clamp((t - L.at) / .7) * (L.until ? 1 - clamp((t - L.until) / .4) : 1); if (a <= 0) continue;
        const s1 = cam.p([L.x, L.y, L.z || 4]); const top = L.top ?? 300;
        ctx.globalAlpha = a; ctx.strokeStyle = 'rgba(255,255,255,.85)'; ctx.lineWidth = 2;
        ctx.beginPath(); ctx.moveTo(s1[0], s1[1]); ctx.lineTo(s1[0], s1[1] + (top + 70 - s1[1]) * a); ctx.stroke();
        ctx.fillStyle = '#fff'; ctx.textAlign = 'center'; ctx.textBaseline = 'alphabetic'; ctx.shadowColor = 'rgba(0,0,0,.45)'; ctx.shadowBlur = 18;
        ctx.font = '500 92px Corm'; ctx.fillText(L.text, s1[0], top); ctx.font = '500 22px Jost'; ctx.letterSpacing = '9px'; ctx.fillText(L.sub || '', s1[0] + 4, top + 44); ctx.letterSpacing = '0px';
        ctx.shadowBlur = 0; ctx.globalAlpha = 1;
      }
      if (world.clouds) {                                                      // soft clouds drifting at the frame edges
        for (const cl of world.clouds) {
          const rr = rng(cl[3] * 97 + 5);
          for (let k = 0; k < 9; k++) {                                         // a cloud = cluster of soft lobes
            const x = cl[0] + (rr() - .5) * cl[2] * 1.4 + Math.sin(t * .12 + cl[3]) * 20 + t * 5 * cl[4], y = cl[1] + (rr() - .5) * cl[2] * .6, R = cl[2] * (.25 + rr() * .35);
            const g2 = ctx.createRadialGradient(x, y, 0, x, y, R); g2.addColorStop(0, 'rgba(255,255,255,.42)'); g2.addColorStop(.6, 'rgba(255,255,255,.16)'); g2.addColorStop(1, 'rgba(255,255,255,0)');
            ctx.fillStyle = g2; ctx.beginPath(); ctx.arc(x, y, R, 0, 6.283); ctx.fill();
          }
        }
      }
      // pins & labels
      for (const L of opt.labels || []) {
        const a = clamp((t - L.at) / .5) * (L.until ? 1 - clamp((t - L.until) / .4) : 1); if (a <= 0) continue;
        const s = cam.p([L.x, L.y, L.z || 0]); const lift = L.lift ?? 70;
        ctx.globalAlpha = a;
        ctx.strokeStyle = 'rgba(230,200,145,.8)'; ctx.lineWidth = 1.5; ctx.beginPath(); ctx.moveTo(s[0], s[1]); ctx.lineTo(s[0], s[1] - lift * a); ctx.stroke();
        ctx.fillStyle = '#e6c891'; ctx.beginPath(); ctx.arc(s[0], s[1], 5, 0, Math.PI * 2); ctx.fill();
        if (L.pulse) { const ph = ((t - L.at) % 1.4) / 1.4; ctx.strokeStyle = `rgba(230,200,145,${1 - ph})`; ctx.lineWidth = 2; ctx.beginPath(); ctx.arc(s[0], s[1], 6 + ph * 40, 0, Math.PI * 2); ctx.stroke(); }
        ctx.font = L.big ? 'italic 600 40px Corm' : '600 21px Jost'; const txt = L.big ? L.text : L.text.toUpperCase();
        if (!L.big) ctx.letterSpacing = '4px';
        const w = ctx.measureText(txt).width + 36, hgt = L.big ? 58 : 44, x = s[0] - w / 2, y = s[1] - lift * a - hgt;
        ctx.fillStyle = L.gold ? 'rgba(201,164,106,.95)' : 'rgba(12,22,19,.82)'; ctx.strokeStyle = 'rgba(230,200,145,.7)';
        ctx.beginPath(); ctx.roundRect(x, y, w, hgt, hgt / 2); ctx.fill(); if (!L.gold) ctx.stroke();
        ctx.fillStyle = L.gold ? '#14100a' : '#efe8d8'; ctx.textAlign = 'center'; ctx.textBaseline = 'middle'; ctx.fillText(txt, s[0] + (L.big ? 0 : 2), y + hgt / 2 + 1);
        ctx.letterSpacing = '0px'; ctx.globalAlpha = 1;
      }
    };
  }

  // ================================================================ worlds
  function businessBay() {
    const r = rng(42);
    const canal = catmull([[1500, 2650], [1250, 1900], [950, 1150], [620, 640], [430, 380], [260, 170], [150, 40], [40, -40], [-120, -60], [-280, -20], [-430, 90], [-560, 230], [-720, 380]], 10);
    const szr = catmull([[-3800, -2900], [-2000, -1500], [-700, -260], [-300, 60], [60, 330], [420, 620], [1000, 1150], [2200, 2400]], 8);
    const al_khail = catmull([[-3600, -3300], [-1500, -1700], [-700, -560], [-200, -380], [200, -180], [700, 120], [1600, 900], [2600, 1800]], 8);
    const burj = [-120, 160];
    const bounds = [[-4200, -4200], [4200, -4200], [4200, 4200], [-4200, 4200]];
    const coast = catmull([[-4200, -3150], [-2900, -2050], [-1800, -1080], [-1200, -380], [-720, 380], [-150, 1150], [600, 2000], [1500, 2700], [2400, 3300], [4200, 4300]], 8);
    const sea = coast.concat([[-4200, 4300]]);
    const lake = blob(burj[0] + 10, burj[1] - 75, 95, 42, 3, -12, 36);
    const mall = boxPoly(burj[0] + 175, burj[1] + 20, 170, 110, -12);
    const ring = []; for (let k = 0; k <= 64; k++) { const a = k / 64 * Math.PI * 2; ring.push([burj[0] + 20 + Math.cos(a) * 270, burj[1] + Math.sin(a) * 210]); }
    const ring2 = ring.map(p => [burj[0] + 20 + (p[0] - burj[0] - 20) * .62, burj[1] + (p[1] - burj[1]) * .62]);
    const B = [];
    const nearCanal = p => distToPolyline(p, canal);
    const reserved = p => inPoly(p, lake) || inPoly(p, mall) || distToPolyline(p, ring) < 16;
    B.push({ poly: mall, h: 9, floors: 0, seed: 3000, col: [246, 245, 242], group: 'dt' });
    for (let i = 0; i < 46; i++) {                                     // the Sheikh Zayed Road tower wall
      const seg = szr[Math.floor(r() * (szr.length - 1))]; const nx = szr[Math.min(szr.indexOf(seg) + 1, szr.length - 1)];
      if (Math.abs(seg[0]) > 900 || Math.abs(seg[1]) > 900) continue;
      let dx = nx[0] - seg[0], dy = nx[1] - seg[1]; const l = Math.hypot(dx, dy) || 1; dx /= l; dy /= l;
      const sd = r() < .5 ? -1 : 1, p = [seg[0] - dy * 46 * sd + dx * (r() - .5) * 80, seg[1] + dx * 46 * sd + dy * (r() - .5) * 80];
      if (B.some(q => q.c && Math.hypot(q.c[0] - p[0], q.c[1] - p[1]) < 30)) continue;
      B.push({ poly: boxPoly(p[0], p[1], 20 + r() * 10, 18 + r() * 10, Math.atan2(dy, dx) * 57.3), c: p, h: 60 + r() * 95, floors: 3.2, lit: 0, seed: 17000 + i, col: r() < .5 ? [216, 226, 234] : [244, 243, 240], group: 'szr' });
    }
    // Business Bay towers along both banks
    for (let i = 0; i < 240; i++) {
      const u = r(), seg = Math.floor(u * (canal.length - 1)); const a = canal[seg], b = canal[seg + 1];
      if (a[0] < -260 || a[0] > 470) continue;
      const side = r() < .5 ? -1 : 1, off = 40 + r() * 120;
      let dx = b[0] - a[0], dy = b[1] - a[1]; const l = Math.hypot(dx, dy); dx /= l; dy /= l;
      const p = [a[0] - dy * off * side + (r() - .5) * 20, a[1] + dx * off * side + (r() - .5) * 20];
      if (nearCanal(p) < 26 || distToPolyline(p, szr) < 30 || Math.hypot(p[0] - burj[0], p[1] - burj[1]) < 70) continue;
      if (reserved(p) || B.some(q => q.c && Math.hypot(q.c[0] - p[0], q.c[1] - p[1]) < 34)) continue;
      const w = 18 + r() * 12, h = 34 + Math.pow(r(), 1.4) * 70 * (1.25 - nearCanal(p) / 220);
      const glass = r() < .55;
      B.push({ poly: boxPoly(p[0], p[1], w, w * (.7 + r() * .6), Math.atan2(dy, dx) * 57.3), c: p, h, floors: 3.2, lit: 0, seed: i,
        col: glass ? [214, 224, 232] : [242, 241, 237], group: 'bb', office: p[0] > 120 && p[0] < 330 && side > 0 });
    }
    // Downtown cluster around Burj (rises later)
    for (let i = 0; i < 70; i++) {
      const a = r() * Math.PI * 2, d = 135 + r() * 170, p = [burj[0] + Math.cos(a) * d, burj[1] + Math.sin(a) * d];
      if (nearCanal(p) < 30 || distToPolyline(p, szr) < 30 || reserved(p)) continue;
      if (B.some(q => q.c && Math.hypot(q.c[0] - p[0], q.c[1] - p[1]) < 30)) continue;
      const w = 16 + r() * 12; B.push({ poly: boxPoly(p[0], p[1], w, w, 20 + r() * 20), c: p, h: 20 + r() * 50, floors: 3.2, lit: 0, seed: 900 + i, col: r() < .5 ? [216, 226, 234] : [242, 241, 237], group: 'dt' });
    }
    // Burj Khalifa: stacked tiers + spire
    const tiers = [[36, 0, 62], [30, 62, 118], [24, 118, 170], [18, 170, 212], [12, 212, 248], [7, 248, 276], [2.6, 276, 330]];
    tiers.forEach(([rad, z0, z1], k) => B.push({ poly: ngon(burj[0], burj[1], rad, 6, .3), c: [burj[0], burj[1]], zb: k * .5, z0, h: z1 - z0, floors: k < 5 ? 3.2 : 0, lit: 0, seed: 2000 + k, col: [248, 248, 248], hl: [236, 210, 160], group: 'burj', delay: k * .18, noShadow: k > 0 }));
    // low-rise fabric elsewhere
    const occ = new Set(B.filter(q => q.c).map(q => Math.round(q.c[0] / 24) + ',' + Math.round(q.c[1] / 24)));
    for (let gx = -880; gx < 880; gx += 20) for (let gy = -880; gy < 880; gy += 20) {
      const p = [gx + (r() - .5) * 5, gy + (r() - .5) * 5];
      if (inPoly(p, sea) || nearCanal(p) < 30 || distToPolyline(p, szr) < 30 || distToPolyline(p, al_khail) < 22) continue;
      const key = Math.round(p[0] / 24) + ',' + Math.round(p[1] / 24);
      if (reserved(p) || occ.has(key) || B.some(q => q.c && q.h > 20 && Math.hypot(q.c[0] - p[0], q.c[1] - p[1]) < 24)) continue;
      if (r() < .25) continue;
      const mid = nearCanal(p) < 160 && r() < .45;                    // white mid-rise blocks near the canal, villas elsewhere
      B.push({ poly: boxPoly(p[0], p[1], 8 + r() * 7, 7 + r() * 7, 30 + (r() < .3 ? 90 : 0)), c: p, h: mid ? 8 + r() * 10 : 2 + r() * 4, floors: 0, seed: 5000 + gx * 3 + gy, col: [238, 235, 228], group: 'fabric' });
    }

    // wider Dubai: sparse city fabric, Sheikh Zayed Road tower corridor, Palm Jumeirah
    for (let gx = -4000; gx < 4000; gx += 120) for (let gy = -4000; gy < 4000; gy += 120) {
      const p = [gx + (r() - .5) * 40, gy + (r() - .5) * 40];
      if (Math.abs(p[0]) < 880 && Math.abs(p[1]) < 880) continue;
      if (inPoly(p, sea) || nearCanal(p) < 60 || distToPolyline(p, szr) < 60 || r() < .45) continue;
      B.push({ poly: boxPoly(p[0], p[1], 50 + r() * 30, 40 + r() * 30, 38), c: p, h: 3 + r() * 8, floors: 0, seed: 15000 + gx + gy * 7, col: [236, 232, 224], group: 'far', noShadow: true });
    }
    for (let i = 0; i < szr.length; i += 2) {
      const a = szr[i]; if (Math.abs(a[0]) < 700 && Math.abs(a[1]) < 700) continue;
      for (const side of [-1, 1]) { if (r() < .35) continue;
        const b2 = szr[Math.min(i + 1, szr.length - 1)]; let dx = b2[0] - a[0], dy = b2[1] - a[1]; const l = Math.hypot(dx, dy) || 1; dx /= l; dy /= l;
        const p = [a[0] - dy * 90 * side, a[1] + dx * 90 * side]; if (inPoly(p, sea)) continue;
        B.push({ poly: boxPoly(p[0], p[1], 34 + r() * 20, 30 + r() * 20, Math.atan2(dy, dx) * 57.3), c: p, h: 40 + r() * 90, floors: 3.2, lit: 0, seed: 16000 + i * 3 + side, col: r() < .5 ? [216, 226, 234] : [244, 243, 240], group: 'szr' });
      }
    }
    const palm = [], pb = [-2350, -820];                       // trunk base on the coast, heading out to sea (NW)
    const dir = [-0.62, 0.78], nrm = [0.78, 0.62];
    const at = (u, v) => [pb[0] + dir[0] * u + nrm[0] * v, pb[1] + dir[1] * u + nrm[1] * v];
    palm.push([at(0, -45), at(0, 45), at(1150, 45), at(1150, -45)]);
    for (let k = 0; k < 8; k++) { const u = 330 + k * 105;
      for (const sd of [-1, 1]) { const len = 300 + 140 * Math.sin(k / 7 * Math.PI);
        palm.push([at(u - 18, 30 * sd), at(u + 18, 30 * sd), at(u + 18 + len * .35, (30 + len) * sd), at(u - 18 + len * .35, (30 + len) * sd)]); } }
    const cres = []; for (let k = 0; k <= 24; k++) { const a = Math.PI * (1.08 - k / 24 * 1.16); cres.push(at(780 + Math.sin(a) * 640, Math.cos(a) * 640)); }
    const cresI = cres.slice().reverse().map(p => [p[0] + (pb[0] + dir[0] * 780 - p[0]) * .08, p[1] + (pb[1] + dir[1] * 780 - p[1]) * .08]);
    palm.push(cres.concat(cresI));
    const islands = palm.map(poly => ({ poly, h: 1.5, col: [222, 206, 168], top: '#e3d3ae', at: -10 }));
    const parks = [];
    for (let i = 0; i < 26; i++) { const p = [(r() - .5) * 1700, (r() - .5) * 1700]; if (inPoly(p, sea) || nearCanal(p) < 50 || reserved(p)) continue;
      parks.push({ poly: boxPoly(p[0], p[1], 40 + r() * 70, 30 + r() * 50, 30), h: .6, col: [120, 160, 90], top: '#7fae5a', at: -10, c: p }); }
    parks.push({ poly: blob(burj[0] - 40, burj[1] + 40, 60, 40, 7, 10, 24), h: .6, col: [120, 160, 90], top: '#76a957', at: -10, c: burj });
    const streets = [];
    for (let k = -800; k <= 800; k += 72) { streets.push([[k, -880], [k + 300, 880]]); streets.push([[-880, k], [880, k - 260]]); }
    return {
      ground: { land: '#d8c7a1', street: 'rgba(120,112,96,.35)', bounds }, streets, clay: true,
      water: [{ poly: sea, c0: '#2f86b8', c1: '#3b98c6', edge: 'rgba(240,232,210,.9)' }, { poly: ribbon(canal, 36), c0: '#3e98c8', c1: '#3189bb', edge: 'rgba(255,255,255,.55)' }, { poly: lake, c0: '#4aa6cf', c1: '#4aa6cf', edge: 'rgba(255,255,255,.6)' }],
      shimmer: { x0: -4000, x1: 1000, y0: -2000, y1: 4000, test: p => inPoly(p, sea) },
      roads: [{ pts: szr, w: 6, col: 'rgba(130,130,128,.95)' }, { pts: al_khail, w: 4.5, col: 'rgba(140,140,136,.9)' }, { pts: ring, w: 3.5, col: 'rgba(150,150,146,.9)' }, { pts: ring2, w: 3, col: 'rgba(150,150,146,.85)' }],
      buildings: B, canal, burj, islands: islands.concat(parks), palmAt: at(600, 0), kind: 'bb', lake,
    };
  }

  function dubaiIslands() {
    // outlines traced from Nakheel's Dubai Islands aerial render (perspective-corrected to a plan)
    const r = rng(11), T = p => p;
    const closed = pts => catmull(pts.concat([pts[0]]), 6);
    const bounds = [[-1600, -1600], [1600, -1600], [1600, 1600], [-1600, 1600]];
    const coast = catmull([[-1600, -480], [-800, -468], [-520, -448], [-300, -470], [-35.2, -453.8], [24.5, -401.6], [97.5, -349.6], [177.1, -295.8], [259.4, -258.2], [378.5, -228.0], [481.7, -183.8], [574.6, -117.6], [668.6, -54.1], [727.4, -15.2], [795.0, 36.5], [1000, 150], [1600, 330]], 6);
    const sea = coast.concat([[1600, 1600], [-1600, 1600]]);
    const creek = catmull([[-300, -470], [-260, -620], [-180, -800], [-120, -1000], [-60, -1300], [0, -1600]], 8);
    const X = {"marina": [-521.5, -315.3], "mall": [-289.3, -159.7], "brA1": [135.0, -244.0], "brA2": [216.6, -302.3], "brAB1": [-44.3, -158.1], "brAB2": [-21.3, -130.5], "brE1": [570.8, -100.1], "brE2": [613.5, -130.5], "brD1": [-566.0, -159.7], "brD2": [-534.3, -183.8], "brSW1": [-497.2, -412.6], "brSW2": [-519.2, -453.8]};
    const NK = window.NKSEG;
    const defs = [
      { name: 'Central Island', tag: 'A', pts: NK.isl.A, urban: true },
      { name: 'Shore Island', tag: 'B', pts: NK.isl.B },
      { name: 'Oasis Island', tag: 'C', pts: NK.isl.C, golf: true },
      { name: 'Golf Island', tag: 'D', pts: NK.isl.D, golf: true },
      { name: 'Elite Island', tag: 'E', pts: NK.isl.E },
    ];
    const islands = defs.map((d, k) => {
      const poly = d.pts; const c = poly.reduce((a, p) => [a[0] + p[0] / poly.length, a[1] + p[1] / poly.length], [0, 0]);
      const inner = poly.map(p => [c[0] + (p[0] - c[0]) * .93, c[1] + (p[1] - c[1]) * .92]);
      return { poly, inner, trees: [], h: 3, col: [238, 226, 196], top: '#4f8a3f', at: 0, k, c, name: d.name, tag: d.tag, urban: d.urban, golf: d.golf, ang: 0 };
    });
    const B = [];
    const marina = null;
    for (const [x, y, w, d] of NK.blds) {                                // buildings detected in the Nakheel render
      const k = islands.findIndex(I => inPoly([x, y], I.poly)); if (k < 0) continue;
      const I = islands[k], tall = I.urban && r() < .06;
      const h = I.urban ? (tall ? 40 + r() * 45 : 7 + r() * 16) : k === 1 ? 4 + r() * 9 : 2.6 + r() * 2.4;
      B.push({ poly: boxPoly(x, y, Math.min(w, 26), Math.min(d, 26), 0), c: [x, y], z0: 3, h, floors: h > 14 ? 1.6 : 0, seed: B.length, island: k, group: 'isl', res: I.urban && !tall });
    }
    islands.forEach((I, k) => { const tx = I.poly.map(p => p[0]), ty = I.poly.map(p => p[1]);
      for (let i = 0; i < (I.urban ? 300 : 900); i++) { const p = [Math.min(...tx) + r() * (Math.max(...tx) - Math.min(...tx)), Math.min(...ty) + r() * (Math.max(...ty) - Math.min(...ty))];
        if (inPoly(p, I.inner)) I.trees.push([p[0], p[1], 1.4 + r() * 2, r(), ['#2f5f2c', '#3f7a35', '#4c8a3c', '#27512a'][Math.floor(r() * 4)]]); } });
    // sand spits / groynes on the open-sea side of B, C, D (as in the Nakheel render)
    const ctr = islands.reduce((a, I) => [a[0] + I.c[0] / 5, a[1] + I.c[1] / 5], [0, 0]);
    const spits = [];
    for (const k of [1, 2, 3, 4]) {
      const I = islands[k]; const cand = I.poly.map((p, i) => ({ p, i, d: Math.hypot(p[0] - ctr[0], p[1] - ctr[1]) })).sort((a, b) => b.d - a.d);
      const used = [];
      for (const c of cand) {
        if (used.length >= (k === 4 ? 1 : 3)) break;
        if (used.some(u => Math.abs(u - c.i) < I.poly.length / 6)) continue; used.push(c.i);
        const q = I.poly[(c.i + 2) % I.poly.length]; let tx = q[0] - c.p[0], ty = q[1] - c.p[1]; const tl = Math.hypot(tx, ty) || 1; tx /= tl; ty /= tl;
        let nx = c.p[0] - I.c[0], ny = c.p[1] - I.c[1]; const nl = Math.hypot(nx, ny) || 1; nx /= nl; ny /= nl;
        const L = 26 + r() * 18, pts = [];
        for (let j = 0; j <= 8; j++) { const u = j / 8, bend = Math.sin(u * 1.6) * L * .45;
          pts.push([c.p[0] + nx * L * u + tx * bend, c.p[1] + ny * L * u + ty * bend]); }
        spits.push(ribbon(pts, uu => 7 * (1 - uu * .55)));
      }
    }
    // Deira on the mainland (south-east of the coast)
    const apt = [700, -760];
    for (let gx = -1560; gx < 1560; gx += 32) for (let gy = -1560; gy < 400; gy += 32) {
      const p = [gx + (r() - .5) * 8, gy + (r() - .5) * 8];
      if (inPoly(p, sea) || distToPolyline(p, creek) < 34 || distToPolyline(p, coast) < 22 || r() < .3) continue;
      if (Math.hypot((p[0] - apt[0]) * .8, p[1] - apt[1]) < 330) continue;
      if (inPoly(p, NK.valid) && r() < .75) continue;                   // photo already shows Deira here
      const tall = r() < .05;
      B.push({ poly: boxPoly(p[0], p[1], 15 + r() * 8, 13 + r() * 8, 30), c: p, h: tall ? 18 + r() * 34 : 2 + r() * 6, floors: tall ? 1.6 : 0, seed: 7000 + gx + gy * 3, group: 'deira' });
    }
    const runways = [boxPoly(apt[0] - 30, apt[1] - 60, 560, 26, 28), boxPoly(apt[0] + 20, apt[1] + 60, 560, 26, 28)];
    runways.forEach((p, k) => B.push({ poly: p, h: .3, col: [96, 100, 98], group: 'apt', noShadow: true, seed: 9000 + k }));
    B.push({ poly: boxPoly(apt[0] - 10, apt[1], 240, 38, 28), h: 5, col: [214, 210, 196], group: 'apt', floors: 0, seed: 9100 });
    const bridges = [[X.brA1, X.brA2], [X.brAB1, X.brAB2], [X.brE1, X.brE2], [X.brD1, X.brD2], [X.brSW1, X.brSW2]];
    const route = catmull([islands[0].c, X.brA1, X.brA2, [330, -420], [520, -620], [apt[0] - 60, apt[1] + 20]], 10);
    return {
      ground: { land: '#bfae8c', street: 'rgba(90,80,60,.18)', bounds }, shallows: true,
      water: [{ poly: sea, c0: '#173e5a', c1: '#1f5a72', edge: 'rgba(240,230,200,.8)' }, { poly: ribbon(creek, 60), c0: '#2b6f82', c1: '#2b6f82' }],
      shimmer: { x0: -1500, x1: 1500, y0: -400, y1: 1500, test: p => inPoly(p, sea) && !islands.some(I => inPoly(p, I.poly)) },
      roads: bridges.map(p => ({ pts: p, w: 4, col: 'rgba(235,235,230,.9)', at: 0 })).concat([{ pts: catmull([[-1600, -700], [-300, -650], [400, -420], [1600, -150]], 6), w: 3.4, col: 'rgba(120,112,96,.7)' }]),
      islands, buildings: B, route, apt, kind: 'di', coast, marina, spits,
    };
  }

  window.Map3D = Map3D; window.WORLDS = { bb: businessBay, di: dubaiIslands }; window.MAPUTIL = { lerpCam, easeInOut };
})();

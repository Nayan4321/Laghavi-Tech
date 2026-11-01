// node export_world.js <ep> <out.json>
// Runs the original world generator + episode setup() + camera timeline from mg/ (no browser needed)
// and writes everything Blender needs: building footprints with rise timing, water, roads, islands,
// and the per-frame camera (30 fps) + map opacity exactly as mg/index.html computes them.
const fs = require('fs'), vm = require('vm'), path = require('path');
const [ep, out] = process.argv.slice(2);
const MG = path.join(__dirname, '../mg');
const window = {}; const ctx = vm.createContext({ window, Math, console, document: { getElementById: () => null } });
for (const f of ['nk_seg.js', 'map3d.js', 'scenes.js']) vm.runInContext(fs.readFileSync(path.join(MG, f), 'utf8'), ctx, { filename: f });
const EPD = window.EPISODES[ep], W = window.WORLDS[EPD.world](); EPD.setup && EPD.setup(W);
const { lerpCam, easeInOut } = window.MAPUTIL, clamp = (x, a = 0, b = 1) => Math.max(a, Math.min(b, x));
const SC = EPD.scenes.map((s, i, a) => ({ ...s, start: s.at, dur: (i + 1 < a.length ? a[i + 1].at : EPD.total) - s.at }));
const frames = [];
for (let i = 0; i < Math.round(EPD.total * 30); i++) {
  const t = i / 30; let ma = 0, cam = null, sid = null;
  for (const s of SC) {
    if (!s.map) continue; const lt = t - s.start; if (lt < -.35 || lt > s.dur + .35) continue;
    const a = Math.min(clamp((lt + .35) / .6), clamp((s.dur + .35 - lt) / .6)) * (s.map.alpha ?? 1);
    if (a <= ma) continue; ma = a; sid = s.id;
    const k = easeInOut(clamp(lt / s.dur));
    let c = lerpCam(s.map.cam0 || s.map.keys[0][1], s.map.cam1 || s.map.cam0 || s.map.keys[0][1], k);
    if (s.map.keys) { const K = s.map.keys; c = K[0][1];
      for (let j = 0; j < K.length - 1; j++) if (t >= K[j][0]) c = lerpCam(K[j][1], K[j + 1][1], easeInOut(clamp((t - K[j][0]) / (K[j + 1][0] - K[j][0])))); }
    cam = c;
  }
  // highlight set (gold) for this frame
  let hl = [];
  const s = SC.find(s => s.id === sid);
  if (s && s.map && s.map.hl) hl = W.buildings.map((b, k) => s.map.hl(b, t) ? k : -1).filter(k => k >= 0);
  frames.push({ t, alpha: +ma.toFixed(4), scene: sid, cam, hl: hl.length ? hl : undefined });
}
const r = v => Array.isArray(v) ? v.map(r) : typeof v === 'number' ? +v.toFixed(2) : v;
const data = {
  ep, kind: W.kind, total: EPD.total,
  buildings: W.buildings.map(b => ({ poly: r(b.poly), c: r(b.c), h: r(b.h), z0: b.z0 || 0, at: b.at, dur: b.dur || .9, delay: b.delay || 0,
    group: b.group, col: b.col, office: !!b.office, res: !!b.res, island: b.island })),
  water: W.water.map(w => ({ poly: r(w.poly) })), roads: W.roads.map(o => ({ pts: r(o.pts), w: o.w })),
  islands: (W.islands || []).map(I => ({ poly: r(I.poly), h: I.h, at: I.at, dur: I.dur || 1.2, k: I.k, name: I.name, tag: I.tag, golf: !!I.golf, urban: !!I.urban, park: I.top === '#7fae5a' || I.top === '#76a957', trees: r(I.trees || []).map(t => t.slice(0, 4)) })),
  streets: r(W.streets || []), canal: r(W.canal || []), lake: r(W.lake || []), burj: W.burj, apt: W.apt, coast: r(W.coast || []), spits: r(W.spits || []), route: r(W.route || []),
  frames,
};
fs.writeFileSync(out, JSON.stringify(data));
console.log(out, 'buildings', data.buildings.length, 'frames', frames.length, 'map frames', frames.filter(f => f.alpha > 0).length);

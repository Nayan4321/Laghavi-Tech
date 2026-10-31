// Scene definitions for the H&S Dubai Series reels. `say` is the script line (drives timing),
// `cue` is the italic line shown beside the presenter window.
const ICON = {
  brief: '<rect x="10" y="26" width="54" height="36" rx="5"/><path d="M27 26v-8h20v8M10 41h54"/>',
  chart: '<path d="M10 62h56M15 52l14-16 12 10 22-26"/><path d="M53 20h10v10"/>',
  pass: '<rect x="18" y="8" width="38" height="58" rx="5"/><circle cx="37" cy="32" r="9"/><path d="M26 52h22M28 32h18M37 23c-4 5-4 13 0 18M37 23c4 5 4 13 0 18"/>',
  beach: '<path d="M37 12a26 24 0 0 1 26 24H11a26 24 0 0 1 26-24z"/><path d="M37 36v26M8 64c8-4 14-4 22 0s14 4 22 0 10-3 14-1"/>',
  home: '<path d="M10 34 37 12l27 22"/><path d="M18 30v32h38V30M31 62V46h12v16"/>',
  office: '<path d="M16 64V12h28v52M44 28h16v36M10 64h56"/><path d="M23 21h5M33 21h5M23 31h5M33 31h5M23 41h5M33 41h5M23 51h5M33 51h5"/>',
  walk: '<circle cx="38" cy="12" r="6"/><path d="M36 22l-8 18 10 8 2 16M34 30l12 6 8-4M28 40l-8 14"/>',
  key: '<circle cx="24" cy="38" r="12"/><path d="M36 38h28M54 38v10M62 38v8"/>',
  plane: '<path d="M8 40l20-4 10-24h6l-4 22 22-4 6-8h5l-3 14 3 14h-5l-6-8-22-4 4 22h-6L28 46 8 42z"/>',
  wave: '<path d="M6 30c6-6 12-6 18 0s12 6 18 0 12-6 18 0 8 4 12 2M6 44c6-6 12-6 18 0s12 6 18 0 12-6 18 0 8 4 12 2"/>',
};
const icon = k => `<svg viewBox="0 0 74 74">${ICON[k]}</svg>`;
const tile = (ic, h, p, t, extra = '') => `<div class="tile" data-a="left" data-t="${t}" data-d=".7" ${extra}>${icon(ic)}<div><h3>${h}</h3><p>${p}</p></div></div>`;
const ellipse = (x, y, w, h, t, rot = -3) => `<svg class="ell" style="left:${x}px;top:${y}px;width:${w}px;height:${h}px" viewBox="0 0 ${w} ${h}">
  <path data-draw data-t="${t}" data-d="0.9" d="M ${w * .52} ${h * .06} C ${w * .95} ${h * .02} ${w * 1.0} ${h * .9} ${w * .5} ${h * .95} C ${w * .02} ${h * .98} ${w * -.02} ${h * .12} ${w * .56} ${h * .1}"
  fill="none" stroke="#c9a46a" stroke-width="5" stroke-linecap="round" transform="rotate(${rot} ${w / 2} ${h / 2})"/></svg>`;
const hook = (l1, l2, t2) => `<div class="hook"><div class="l1" data-a="up" data-t=".1" data-d=".5">${l1}</div><div class="l2" data-a="pop" data-t="${t2}" data-d=".5">${l2}</div></div>`;
const statEnd = (a) => `<div class="kick" style="top:250px" data-a="up" data-t=".1">On screen · save this</div>
  <div class="stat" style="top:330px">${a.map((s, i) => `<div data-a="scale" data-t="${.25 + i * .22}"><b>${s[0]}</b><span>${s[1]}</span></div>`).join('')}</div>
  <div class="sub" style="top:780px" data-a="up" data-t="1.3">Follow for the next episode of the <b>Dubai Series</b></div>`;

function blob(cx, cy, rx, ry, seed, rot = 0) {
  const n = 9, pts = [];
  for (let i = 0; i < n; i++) {
    const a = i / n * Math.PI * 2, w = 1 + .16 * Math.sin(seed * 3.1 + i * 2.3) + .08 * Math.cos(seed + i * 4.1);
    let x = Math.cos(a) * rx * w, y = Math.sin(a) * ry * w; const r = rot * Math.PI / 180;
    pts.push([cx + x * Math.cos(r) - y * Math.sin(r), cy + x * Math.sin(r) + y * Math.cos(r)]);
  }
  let s = `M ${pts[0][0].toFixed(1)} ${pts[0][1].toFixed(1)} `;
  for (let i = 0; i < n; i++) {
    const p0 = pts[(i - 1 + n) % n], p1 = pts[i], p2 = pts[(i + 1) % n], p3 = pts[(i + 2) % n];
    const c1 = [p1[0] + (p2[0] - p0[0]) / 6, p1[1] + (p2[1] - p0[1]) / 6], c2 = [p2[0] - (p3[0] - p1[0]) / 6, p2[1] - (p3[1] - p1[1]) / 6];
    s += `C ${c1[0].toFixed(1)} ${c1[1].toFixed(1)} ${c2[0].toFixed(1)} ${c2[1].toFixed(1)} ${p2[0].toFixed(1)} ${p2[1].toFixed(1)} `;
  }
  return s + 'Z';
}
const ISL = [[640, 360, 190, 80, 1, -14], [380, 330, 80, 48, 2, 10], [900, 270, 90, 50, 3, -20], [640, 175, 100, 42, 4, -8], [420, 180, 62, 36, 5, 15]];

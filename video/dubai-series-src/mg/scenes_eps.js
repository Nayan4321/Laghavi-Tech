
// Hook text that sits over his full-frame opening shot
const hookText = (parts) => `<div class="hook">${parts.join('')}</div>`;
const H1 = (txt, t) => `<div class="l1" data-a="up" data-t="${t}" data-d=".5">${txt}</div>`;
const H2 = (txt, t) => `<div class="l2" data-a="pop" data-t="${t}" data-d=".5">${txt}</div>`;
const stamp = (txt, t) => `<div style="margin-top:30px" data-a="pop" data-t="${t}" data-d=".45"><span class="pill" style="transform:rotate(-5deg);display:inline-flex;font-size:40px;background:rgba(10,16,14,.75);color:#f3dca7">${txt}</span></div>`;
const chips = (arr, t, top = 1040) => `<div style="position:absolute;left:40px;right:40px;top:${top}px;display:flex;flex-wrap:wrap;gap:12px;justify-content:center">${arr.map((c, i) =>
  `<span class="pill" style="font-size:20px;padding:12px 20px" data-a="up" data-t="${t + i * .18}" data-d=".5">${c}</span>`).join('')}</div>`;
const rank = (n, tRing, tNum, tPill, top = 520) => `<svg style="position:absolute;left:340px;top:${top}px;width:400px;height:400px" viewBox="0 0 400 400">
    <circle data-draw data-t="${tRing}" data-d="1.1" cx="200" cy="200" r="180" fill="none" stroke="#c9a46a" stroke-width="3" transform="rotate(-90 200 200)"/>
    <circle cx="200" cy="200" r="160" fill="rgba(10,16,14,.55)" stroke="rgba(201,164,106,.25)" data-a="fade" data-t="${tRing}"/></svg>
  <div class="big" style="top:${top + 100}px" data-a="pop" data-t="${tNum}">#${n}</div>
  <div style="position:absolute;left:0;right:0;top:${top + 440}px;text-align:center" data-a="up" data-t="${tPill}"><span class="pill" style="background:rgba(10,16,14,.7)">In Dubai for sales</span></div>`;
const shadowTxt = 'text-shadow:0 8px 40px rgba(0,0,0,.65)';

function rise(W, rules) {           // assign each building its moment to break ground
  const r = (() => { let s = 7; return () => (s = (s * 1664525 + 1013904223) >>> 0) / 4294967296; })();
  for (const b of W.buildings) { const f = rules(b, r); if (f) Object.assign(b, f); else b.at = 1e9; }
  for (const I of W.islands || []) Object.assign(I, rules({ isIsland: true, k: I.k }, r) || {});
}

window.EPISODES = {
  // ============================================================ EP2 BUSINESS BAY (clip 0.00 – 42.33 s)
  '2': {
    chrome: 'Dubai Series · EP2', tag: 'Comment BAY', world: 'bb', total: 42.33,
    setup(W) {
      rise(W, (b, r) => {
        const c = b.c || [0, 0];
        if (b.group === 'fabric') return { at: 6.5 + Math.hypot(c[0] + 100, c[1] - 100) / 900 * 2.2 + r() * .5, dur: .8 };
        if (b.group === 'dt') return { at: 7.0 + r() * 1.8, dur: 1.0 };
        if (b.group === 'bb') return { at: 20.0 + (c[0] + 260) / 730 * 1.6 + r() * .35, dur: 1.0 };
        if (b.group === 'burj') return { at: 23.15, dur: .55 };
      });
    },
    scenes: [
      { at: 0, id: 'hook', hook: true, hard: true, cue: '',
        html: hookText([H1("You can't buy<br>Burj Khalifa.", .25), `<div style="margin-top:44px;font:700 150px/1 var(--sans);color:#fff;${shadowTxt}" data-a="up" data-t="2.9"><span data-count="11000" data-f="comma" data-t="2.9" data-d="1.3">0</span>+</div>`,
          `<div style="font:600 44px/1.2 var(--sans);color:#fff;letter-spacing:.06em;text-transform:uppercase;${shadowTxt}" data-a="up" data-t="3.3">people just bought</div>`, H2('the next best thing', 4.3)]) },
      { at: 6.33, id: 'bb', cue: "it's <em>Business Bay</em>",
        map: { alpha: .55, cam0: { x: -60, y: 120, dist: 1650, pitch: 40, yaw: -24, f: 1100, cy: .62 }, cam1: { x: -40, y: 110, dist: 1450, pitch: 44, yaw: -36, f: 1100, cy: .62 } },
        html: `<div class="kick" style="top:250px" data-a="up" data-t=".05">Dubai Series · Episode 2</div>
          <div class="title" style="top:310px;${shadowTxt}" data-a="blur" data-t=".15" data-d=".8">Business <i>Bay</i></div>${rank(2, 1.3, 1.5, 1.9)}` },
      { at: 9.93, id: 'deals', cue: '<em>11,400+</em> deals in a year',
        html: `<div class="kick" style="top:290px" data-a="up" data-t=".05">Sales · last 12 months</div>
          <div class="big" style="top:360px;font-size:210px" data-a="up" data-t=".1"><span data-count="11400" data-f="comma" data-t=".1" data-d="2">0</span><i>+</i></div>
          <div class="sub" style="top:610px" data-a="up" data-t=".5">deals in <b>twelve months</b></div>
          <div style="position:absolute;left:80px;right:80px;top:740px;display:grid;grid-template-columns:repeat(12,1fr);gap:12px">
            ${'JFMAMJJASOND'.split('').map((m, i) => `<div style="text-align:center"><div data-a="up" data-t="${.5 + i * .16}" data-d=".45" style="height:200px;display:flex;align-items:flex-end"><div style="width:100%;height:${100 - i * 2}%;border-radius:10px;background:${i < 12 ? 'linear-gradient(180deg,var(--gold2),rgba(201,164,106,.18))' : ''}"></div></div><div style="margin-top:10px;font:500 20px var(--sans);color:var(--muted)">${m}</div></div>`).join('')}
          </div>
          <div class="sub" style="top:1030px;font-size:22px;letter-spacing:.24em;text-transform:uppercase" data-a="fade" data-t="2.6">12 months · 11,400+ transactions</div>` },
      { at: 14.6, id: 'price', cue: 'just under <em>AED 2M</em>',
        html: `<div class="kick" style="top:330px" data-a="up" data-t=".05">Typical price</div>
          <div class="big" style="top:420px" data-a="up" data-t=".1">AED <span data-count="1.9" data-f="d1" data-t=".2" data-d="1.3">0</span>M</div>
          ${ellipse(110, 370, 860, 300, 1.0)}
          <div class="sub" style="top:740px" data-a="up" data-t=".8">Just under <b>two million dirhams</b></div>` },
      { at: 16.9, id: 'yield', cue: 'yields <em>5.5–7.5%</em>',
        html: `<div class="kick" style="top:330px" data-a="up" data-t=".05">Rental yield · roughly</div>
          <div class="big" style="top:410px;font-size:170px" data-a="up" data-t=".1"><span data-count="5.5" data-f="d1" data-t=".2" data-d="1">0</span>–<span data-count="7.5" data-f="d1" data-t=".5" data-d="1">0</span><i>%</i></div>
          <div style="position:absolute;left:110px;right:110px;top:720px">
            <div style="height:18px;border-radius:9px;background:rgba(239,232,216,.12);position:relative">
              <div data-grow data-t=".9" data-d="1" style="position:absolute;left:55%;width:20%;top:-6px;height:30px;border-radius:15px;background:linear-gradient(90deg,var(--gold),var(--gold2));transform-origin:left"></div></div>
            <div style="display:flex;justify-content:space-between;margin-top:18px;font:500 24px var(--sans);color:var(--muted)"><span>0%</span><span>5%</span><span>10%</span></div>
          </div>` },
      { at: 19.82, id: 'map', cue: 'canal · offices · <em>Downtown</em>',
        map: { cam0: { x: 160, y: 40, dist: 980, pitch: 52, yaw: -58, f: 1100, cy: .6 }, cam1: { x: 20, y: 90, dist: 900, pitch: 46, yaw: -28, f: 1100, cy: .6 },
          hl: (b, t) => (b.office && t > 21.55 && t < 23.4) || (b.group === 'burj' && t > 23.1),
          labels: [{ x: 300, y: 250, text: 'Dubai Canal', at: 20.0 }, { x: 120, y: 30, z: 30, text: 'Business Bay', big: true, at: 20.6, lift: 110 },
            { x: 240, y: 120, z: 35, text: 'Offices next door', gold: true, at: 21.6, lift: 90, pulse: true }, { x: -120, y: 160, z: 104, text: 'Downtown · Burj Khalifa', at: 23.4, lift: 40 }] },
        html: `<div class="kick" style="top:230px" data-a="up" data-t=".05">Business Bay · location</div>${chips(['Canal homes', 'Offices next door', 'Downtown · short walk'].map((c, i) => c), 0)}`,
        update(el, lt) { el.querySelectorAll('.pill').forEach((p, i) => { const on = lt >= [0, 1.73, 3.26][i]; p.style.opacity = on ? 1 : 0; p.style.background = on && lt < [1.73, 3.26, 99][i] ? 'rgba(201,164,106,.32)' : 'rgba(10,16,14,.7)'; }); } },
      { at: 24.5, id: 'who', cue: 'who is it <em>for?</em>',
        html: `<div class="kick" style="top:250px" data-a="up" data-t=".05">Who is it for?</div>
          <div class="row3" style="top:330px;gap:30px">
            ${tile('walk', 'Professionals', 'Who want to walk to work', 1.27)}
            ${tile('chart', 'Investors', 'A central address — easy to resell and rent', 3.77)}
          </div>`,
        update(el, lt) { el.querySelectorAll('.tile').forEach((t, i) => t.classList.toggle('hl', lt >= [1.27, 3.77][i] && lt < [3.77, 99][i])); } },
      { at: 33.58, id: 'close', cue: 'live here · <em>rent here</em>',
        map: { cam0: { x: 160, y: 60, dist: 760, pitch: 56, yaw: 15, f: 1100, cy: .62 }, cam1: { x: 170, y: 70, dist: 680, pitch: 50, yaw: 40, f: 1100, cy: .62 },
          hl: (b, t) => b.office,
          routes: [{ pts: [[40, -70], [100, -20], [160, 40], [210, 100], [240, 120]], at: 34.9, dur: 1.2 }],
          labels: [{ x: 40, y: -70, z: 20, text: 'Live on the canal', at: 33.7, lift: 60, pulse: true }, { x: 240, y: 120, z: 35, text: 'Tenants work here', gold: true, at: 35.0, lift: 80 }] },
        html: `<div class="title" style="top:240px;font-size:84px;${shadowTxt}" data-a="up" data-t=".1">Live on the <i>canal.</i></div>
          <div class="title" style="top:340px;font-size:54px;color:var(--cream);${shadowTxt}" data-a="up" data-t="1.28">Rent to people who work <i>next door.</i></div>` },
      { at: 37.99, id: 'cta', cue: 'comment <em>BAY</em>',
        html: `<div class="kick" style="top:290px" data-a="up" data-t=".05">Want the shortlist?</div>
          <div style="position:absolute;left:0;right:0;top:380px;text-align:center" data-a="up" data-t=".15"><span class="bubble">💬&nbsp; Comment</span></div>
          <div class="cta" style="top:530px" data-a="pop" data-t=".4"><span class="btn">BAY</span></div>
          <div class="sub" style="top:780px" data-a="up" data-t="1.2">Best <b>canal-view options</b><br>sent to you this week</div>
          ${chips(['#2 in Dubai', '11,400+ sales', '~AED 1.9M', 'Canal living'], 2.6, 1000)}` },
    ],
  },
  // ============================================================ EP5 DUBAI ISLANDS (clip 50.57 – 94.73 s of the master)
  '5': {
    chrome: 'Dubai Series · EP5', tag: 'Comment ISLANDS', world: 'di', total: 44.16,
    setup(W) {
      rise(W, (b, r) => {
        if (b.isIsland) return { at: 12.95 + b.k * .32, dur: 1.1 };
        const c = b.c || [0, 0];
        if (b.group === 'deira') return { at: 8.6 + Math.hypot(c[0], c[1] + 300) / 1000 * 2.4 + r() * .4, dur: .8 };
        if (b.group === 'isl') return { at: 13.8 + b.island * .32 + r() * 1.3, dur: .8 };
        if (b.group === 'apt') return { at: 19.3 + r() * .4, dur: .8 };
      });
    },
    scenes: [
      { at: 0, id: 'hook', hook: true, hard: true, cue: '',
        html: hookText([H1('Abu Dhabi has<br>5 islands.', .55), stamp('Plot twist', 2.55), H2('So does Dubai.', 3.9)]) },
      { at: 5.70, id: 'buyers', cue: '<em>5,000+</em> already bought',
        html: `<div class="kick" style="top:330px" data-a="up" data-t=".05">Already bought there</div>
          <div class="big" style="top:410px;font-size:220px" data-a="up" data-t=".1"><span data-count="5000" data-f="comma" data-t=".1" data-d="1.5">0</span><i>+</i></div>
          <div class="sub" style="top:680px" data-a="up" data-t=".6">people on <b>Dubai Islands</b></div>
          <div style="position:absolute;left:150px;right:150px;top:800px;display:grid;grid-template-columns:repeat(10,1fr);gap:16px">
            ${Array.from({ length: 30 }, (_, i) => `<svg viewBox="0 0 40 50" data-a="pop" data-t="${.6 + i * .045}" data-d=".4"><circle cx="20" cy="13" r="9" fill="${i % 7 === 3 ? '#e6c891' : 'rgba(239,232,216,.55)'}"/><path d="M4 50c0-12 7-20 16-20s16 8 16 20z" fill="${i % 7 === 3 ? '#e6c891' : 'rgba(239,232,216,.55)'}"/></svg>`).join('')}
          </div>` },
      { at: 8.42, id: 'title', cue: '<em>#5</em> in the city for sales',
        map: { alpha: .55, cam0: { x: 0, y: 60, dist: 1900, pitch: 40, yaw: -12, f: 1100, cy: .6 }, cam1: { x: 0, y: 80, dist: 1700, pitch: 44, yaw: 4, f: 1100, cy: .6 } },
        html: `<div class="kick" style="top:250px" data-a="up" data-t=".05">Dubai Series · Episode 5</div>
          <div class="title" style="top:310px;${shadowTxt}" data-a="blur" data-t=".1" data-d=".8">Dubai <i>Islands</i></div>
          <div style="position:absolute;left:0;right:0;top:450px;text-align:center;font:500 26px var(--sans);letter-spacing:.34em;color:var(--gold2)" data-a="up" data-t=".7">BY NAKHEEL</div>
          ${rank(5, 2.0, 2.17, 2.6, 540)}` },
      { at: 12.80, id: 'size', cue: '<em>17 km²</em> · five islands',
        map: { cam0: { x: 30, y: 280, dist: 1250, pitch: 50, yaw: -18, f: 1100, cy: .58 }, cam1: { x: 30, y: 320, dist: 1050, pitch: 54, yaw: 6, f: 1100, cy: .58 },
          labels: [[40, 300], [-330, 200], [360, 430], [-80, 520], [380, 190]].map((p, i) => ({ x: p[0], y: p[1], z: 4, text: String(i + 1), gold: true, at: 15.2 + i * .14, lift: 50 })) },
        html: `<div style="position:absolute;left:56px;right:56px;top:1060px;display:flex;gap:16px;justify-content:center">
            <span class="pill" style="background:rgba(10,16,14,.75)" data-a="up" data-t=".3"><span data-count="17" data-t=".3" data-d="1.2">0</span>&nbsp;km²</span>
            <span class="pill" style="background:rgba(10,16,14,.75)" data-a="up" data-t="2.45">5 islands</span></div>` },
      { at: 16.77, id: 'beach', cue: '<em>20+ km</em> of beaches',
        map: { beach: 16.9, cam0: { x: 60, y: 330, dist: 900, pitch: 58, yaw: 8, f: 1100, cy: .6 }, cam1: { x: 80, y: 340, dist: 820, pitch: 56, yaw: 26, f: 1100, cy: .6 } },
        html: `<div class="big" style="top:240px;font-size:150px;${shadowTxt}" data-a="up" data-t=".1"><span data-count="20" data-t=".2" data-d="1.4">0</span><i>+</i> km</div>
          <div style="position:absolute;left:0;right:0;top:1060px;text-align:center"><span class="pill" style="background:rgba(10,16,14,.75)" data-a="up" data-t=".5">of beaches</span></div>` },
      { at: 19.10, id: 'dxb', cue: '<em>10 min</em> to DXB',
        map: { cam0: { x: 220, y: -60, dist: 1500, pitch: 52, yaw: 18, f: 1100, cy: .56 }, cam1: { x: 240, y: -120, dist: 1380, pitch: 56, yaw: 32, f: 1100, cy: .56 },
          routes: [{ pts: null, at: 19.5, dur: 1.8, plane: true }],
          labels: [{ x: 450, y: -520, z: 4, text: 'DXB · Dubai International', at: 20.6, lift: 70, pulse: true }, { x: 160, y: -170, z: 2, text: '10 min', gold: true, at: 20.2, lift: 40 }] },
        html: `<div class="kick" style="top:240px" data-a="up" data-t=".05">Just ten minutes from</div>
          <div class="title" style="top:290px;font-size:84px;${shadowTxt}" data-a="up" data-t=".3">Dubai <i>International</i></div>` },
      { at: 22.75, id: 'price', cue: 'entry around <em>AED 2.8M</em>',
        html: `<div class="kick" style="top:330px" data-a="up" data-t=".05">Typical entry price</div>
          <div class="big" style="top:420px" data-a="up" data-t=".1">AED <span data-count="2.8" data-f="d1" data-t=".2" data-d="1.3">0</span>M</div>
          ${ellipse(110, 370, 860, 300, 1.0)}
          <div class="sub" style="top:740px" data-a="up" data-t=".8">Around <b>2.8 million dirhams</b></div>` },
      { at: 25.40, id: 'who', cue: 'who is it <em>for?</em>',
        html: `<div class="kick" style="top:250px" data-a="up" data-t=".05">Who is it for?</div>
          <div class="row3" style="top:330px;gap:30px">
            ${tile('beach', 'Lifestyle buyers', 'The beach at their doorstep', 1.42)}
            ${tile('key', 'Holiday-home investors', 'Island living with short-stay appeal', 4.7)}
          </div>`,
        update(el, lt) { el.querySelectorAll('.tile').forEach((t, i) => t.classList.toggle('hl', lt >= [1.42, 4.7][i] && lt < [4.7, 99][i])); } },
      { at: 31.10, id: 'why', cue: 'old location · <em>new beaches</em>',
        html: `<div class="kick" style="top:250px" data-a="up" data-t=".05">Why buy here?</div>
          <div style="position:absolute;left:56px;right:56px;top:330px;display:grid;grid-template-columns:1fr 1fr;gap:22px">
            <div class="tile" style="flex-direction:column;align-items:flex-start;min-height:430px" data-a="left" data-t="1.38">${icon('office')}<div><p style="letter-spacing:.24em;text-transform:uppercase;color:var(--gold)">Old Dubai's</p><h3 style="font-size:64px;margin-top:10px">Location</h3><p>Deira · the Creek · DXB</p></div></div>
            <div class="tile hl" style="flex-direction:column;align-items:flex-start;min-height:430px" data-a="right" data-t="3.04">${icon('beach')}<div><p style="letter-spacing:.24em;text-transform:uppercase;color:var(--gold)">New Dubai's</p><h3 style="font-size:64px;margin-top:10px">Beaches</h3><p>Island living, resort-style</p></div></div>
          </div>` },
      { at: 35.65, id: 'rare', cue: "won't stay <em>quiet</em> for long",
        html: `<div class="title" style="top:320px;font-size:100px" data-a="blur" data-t=".9">That combination<br>is <i>rare.</i></div>
          <div class="sub" style="top:610px" data-a="up" data-t="2.9">And it will <b>not stay quiet</b> for long.</div>
          <div style="position:absolute;left:150px;right:150px;top:770px;height:260px;display:flex;align-items:flex-end;gap:10px">
            ${Array.from({ length: 24 }, (_, i) => `<div data-a="up" data-t="${3.0 + i * .05}" data-d=".4" style="flex:1;border-radius:6px;height:${18 + Math.pow(i / 23, 2.2) * 240}px;background:linear-gradient(180deg,var(--gold2),rgba(201,164,106,.2))"></div>`).join('')}
          </div>` },
      { at: 40.90, id: 'cta', cue: 'comment <em>ISLANDS</em>',
        html: `<div class="kick" style="top:290px" data-a="up" data-t=".05">See what's left</div>
          <div style="position:absolute;left:0;right:0;top:380px;text-align:center" data-a="up" data-t=".15"><span class="bubble">💬&nbsp; Comment</span></div>
          <div class="cta" style="top:530px" data-a="pop" data-t=".35"><span class="btn" style="font-size:100px">ISLANDS</span></div>
          <div class="sub" style="top:770px" data-a="up" data-t="1.1">I'll send you <b>what's still available</b></div>
          ${chips(['#5 in Dubai', '5,000+ sales', '5 islands', '10 min to DXB'], 2.0, 1000)}` },
    ],
  },
};

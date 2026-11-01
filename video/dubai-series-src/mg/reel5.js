// EP5 Dubai Islands in the layout of the Palm Springs reel the client liked: full-frame satellite map with a
// gliding 3D camera, presenter window top-right, word-by-word caption pill, white callout cards, glowing zones.
// World units = metres (blender/real/ep5r: origin 25.2860 N, 55.3080 E; +x east, +y north). Times = seconds in his clip.
(function (root) {
  const ISL = { A: [84, 1270], B: [1612, 2788], C: [-361, 5623], D: [-2029, 2172], E: [2895, 3461] };
  const DXB = [5808, -3627], DEIRA = [1700, -900];
  const cam = (x, y, dist, pitch, yaw) => ({ x, y, dist, pitch, yaw, f: 1300, cy: .44 });
  // one continuous camera path (eased between keys), map from the end of his opening (5.70 s)
  const KEYS = [
    [5.70, cam(300, 2600, 11500, 52, -8)],     // 5,000 already bought: all five islands
    [8.40, cam(300, 2900, 9800, 54, 0)],
    [12.70, cam(250, 3000, 9000, 58, 10)],    // Dubai Islands by Nakheel, #5 for sales
    [15.20, cam(200, 3100, 8600, 62, 4)],     // 17 km2 . five islands
    [16.80, cam(-150, 4300, 5200, 48, 22)],   // 20+ km of beaches (Oasis / Shore coast)
    [19.00, cam(300, 3900, 5600, 46, 30)],
    [20.20, cam(2600, -400, 10500, 50, 150)], // 10 min to DXB
    [22.60, cam(3000, -900, 10000, 52, 158)],
    [23.40, cam(150, 1250, 3600, 50, 18)],    // entry ~AED 2.8M (Central Island)
    [25.60, cam(250, 1350, 3200, 47, 28)],
    [26.80, cam(1500, 2700, 3900, 48, -18)],  // who is it for: Shore Island villas on the beach
    [31.00, cam(1650, 2900, 3500, 46, -4)],
    [32.20, cam(900, 700, 8800, 48, 20)],     // old Dubai's location, new Dubai's beaches
    [35.60, cam(700, 1400, 8000, 50, 12)],
    [40.80, cam(400, 2500, 7000, 54, -6)],    // rare, won't stay quiet
    [44.16, cam(400, 2600, 6600, 56, -12)],   // comment ISLANDS
  ];
  const ease = t => t < .5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2;
  function camAt(t) {
    if (t <= KEYS[0][0]) return KEYS[0][1];
    for (let i = 0; i < KEYS.length - 1; i++) {
      const [t0, a] = KEYS[i], [t1, b] = KEYS[i + 1];
      if (t <= t1) { const k = ease((t - t0) / (t1 - t0)), o = {}; for (const key in a) o[key] = a[key] + (b[key] - a[key]) * k; return o; }
    }
    return KEYS[KEYS.length - 1][1];
  }
  const COL = { A: '#f2b33d', B: '#4f7dff', C: '#3fd28a', D: '#2fc6d6', E: '#d65cf0', white: '#ffffff', gold: '#f2b33d' };
  // highlighted zones (polygons filled in by the page from islands_real.json): which island, colour, on / off
  const ZONES = [
    { k: 'ALL', col: '#ffffff', at: 6.2, until: 12.9 },
    { k: 'A', col: COL.A, at: 15.32, until: 22.5 }, { k: 'B', col: COL.B, at: 15.47, until: 22.5 }, { k: 'C', col: COL.C, at: 15.62, until: 22.5 },
    { k: 'D', col: COL.D, at: 15.77, until: 22.5 }, { k: 'E', col: COL.E, at: 15.92, until: 22.5 },
    { k: 'A', col: COL.A, at: 23.0, until: 26.2 },
    { k: 'B', col: COL.B, at: 26.4, until: 31.3 },
    { k: 'ALL', col: '#ffffff', at: 34.1, until: 44.3 },
  ];
  const BEACH = { at: 17.3, dur: 1.6, until: 19.3 };          // glowing shoreline drawn around the islands
  const ROUTE = { pts: [ISL.A, [700, 400], [1500, -400], [2600, -1300], [3800, -2300], [4900, -3100], DXB], at: 19.6, dur: 1.6, until: 22.7 };
  // white callout cards pinned to the map: title (small caps), value (italic serif), tag
  const CARDS = [
    { x: 300, y: 2900, title: 'Dubai Islands', value: '#5 for sales', tag: 'by Nakheel', col: '#2f5bea', at: 8.66, until: 12.9, valueAt: 10.76 },
    { x: ISL.A[0], y: ISL.A[1], title: 'Central Island', small: true, col: COL.A, at: 15.32, until: 16.9 },
    { x: ISL.B[0], y: ISL.B[1], title: 'Shore Island', small: true, col: COL.B, at: 15.47, until: 16.9 },
    { x: ISL.C[0], y: ISL.C[1], title: 'Oasis Island', small: true, col: COL.C, at: 15.62, until: 16.9 },
    { x: ISL.D[0], y: ISL.D[1], title: 'Golf Island', small: true, col: COL.D, at: 15.77, until: 16.9 },
    { x: ISL.E[0], y: ISL.E[1], title: 'Elite Island', small: true, col: COL.E, at: 15.92, until: 16.9 },
    { x: DXB[0], y: DXB[1], title: 'DXB · Dubai International', value: '10 min', col: '#2f5bea', at: 20.6, until: 22.7, valueAt: 19.88 },
    { x: ISL.A[0], y: ISL.A[1], title: 'Typical entry', value: 'AED 2.8M', tag: 'approx.', col: '#c98a12', at: 23.1, until: 25.8, valueAt: 24.26 },
    { x: DEIRA[0], y: DEIRA[1], title: "Old Dubai's", value: 'location', col: '#2f5bea', at: 32.5, until: 35.7 },
    { x: ISL.C[0], y: ISL.C[1] - 600, title: "New Dubai's", value: 'beaches', col: '#1f9e63', at: 34.1, until: 35.7 },
  ];
  // big white numbers over the map (like "+132%")
  const STATS = [
    { text: '5,000+', sub: 'already bought', at: 6.22, until: 8.4, y: 820 },
    { text: '17 km²', sub: '', at: 13.0, until: 15.2, y: 820 },
    { text: '20+ km', sub: 'of beaches', at: 17.32, until: 19.1, y: 820 },
  ];
  // white checklist card (like "Want to know...")
  const LISTS = [
    { title: 'Who is it for?', at: 25.84, until: 31.2, items: [['Lifestyle buyers', 'the beach at their doorstep', 26.42], ['Holiday-home investors', '', 29.94]] },
    { title: 'Comment “ISLANDS”', at: 41.14, until: 99, items: [["What's still available", '', 42.8], ['Exact prices', '', 43.3]] },
  ];
  // dark summary panel (like the price table at the end of the reel)
  const SUMMARY = { at: 36.0, until: 41.0, rows: [['5,000+', 'already bought', COL.B], ['17 km²', 'five islands', COL.C], ['20+ km', 'of beaches', COL.D], ['10 min', 'to DXB', COL.E], ['AED 2.8M', 'typical entry', COL.A]] };
  // word-timed captions (transcribed from his clip, spelling corrected); key words in italic serif
  const WORDS = [['and', 5.48], ['5,000', 6.22], ['people', 6.6], ['have', 7.04], ['already', 7.42], ['bought', 7.68], ['there.', 7.9], ["It's", 8.46], ['Dubai', 8.66], ['Islands', 8.92], ['by', 9.3], ['Nakheel.', 9.68],
    ['Number', 10.76], ['5', 10.84], ['in', 11.28], ['the city', 11.52], ['for', 11.7], ['sales,', 12.0], ['17', 13.0], ['square', 13.44], ['kilometres,', 13.84], ['5', 15.32], ['islands,', 15.56],
    ['more', 16.96], ['than', 17.04], ['20', 17.32], ['kilometres', 17.9], ['of', 18.24], ['beaches', 18.66], ['and', 19.02], ['just', 19.6], ['10', 19.88], ['minutes', 20.28], ['from', 20.62], ['Dubai', 20.92],
    ['International', 21.3], ['Airport.', 21.72], ['Typical', 22.78], ['entry', 23.08], ['price', 23.42], ['around', 23.88], ['2.8', 24.26], ['million', 25.0], ['dirhams.', 25.3], ['Who', 25.84], ['is', 25.94], ['it', 26.04],
    ['for?', 26.18], ['Lifestyle', 26.42], ['buyers', 27.34], ['who', 27.74], ['want', 28.0], ['the', 28.28], ['beach', 28.48], ['at', 28.7], ['their', 28.96], ['doorstep.', 29.16], ['Holiday', 29.94], ['home', 30.06],
    ['investors.', 30.4], ['Why', 31.56], ['buy', 31.58], ['here?', 31.78], ['Old', 32.48], ["Dubai's", 32.76], ['location,', 33.2], ['new', 34.1], ["Dubai's", 34.38], ['beaches.', 34.7], ['And', 35.08], ['the', 36.0],
    ['combination', 36.64], ['is', 37.06], ['rare', 37.54], ['and', 37.96], ['it', 38.68], ['will', 38.82], ['not', 39.0], ['stay', 39.36], ['quiet', 39.78], ['for', 40.14], ['long.', 40.4], ['Comment', 41.14], ['Islands', 41.16],
    ['and', 41.6], ["I'll", 42.24], ['send', 42.46], ['you', 42.62], ["what's", 42.8], ['still', 43.12], ['available.', 43.3]];
  const KEY = /^(5,000|Nakheel\.|5|17|20|10|2\.8|million|dirhams\.|rare|beaches|beach|Islands|kilometres,|Airport\.|investors\.|location,|available\.)$/;
  // group words into short caption chunks (max 4 words, break after punctuation or a pause)
  const CHUNKS = [];
  { let cur = [];
    WORDS.forEach(([w, s], i) => {
      cur.push({ w, s, key: KEY.test(w) });
      const next = WORDS[i + 1], end = !next || /[.,?]$/.test(w) || cur.length >= 4 || (next && next[1] - s > .9);
      if (end) { CHUNKS.push({ words: cur, start: cur[0].s - .05, end: next ? next[1] - .05 : 44.16 }); cur = []; }
    }); }
  const out = { MAP_FROM: 5.70, TOTAL: 44.16, KEYS, camAt, ZONES, BEACH, ROUTE, CARDS, STATS, LISTS, SUMMARY, CHUNKS, ISL, COL, HANDLE: 'H&S Real Estate' };
  if (typeof module !== 'undefined') module.exports = out; else root.REEL5 = out;
})(this);

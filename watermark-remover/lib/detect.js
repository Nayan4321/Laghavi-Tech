// Finds the watermark itself inside the area the user painted, so only its
// strokes are touched and objects behind / between them are kept.
//
// Watermarks are thin strokes (letters, logo lines) that are lighter (or
// darker) than what's around them, in an even grey/white/black. A grey-level
// "opening" (min then max filter) erases strokes thinner than the filter, so
// it gives the picture around each stroke. Comparing each pixel with that
// tells us how strongly a watermark of colour W covers it:
//   pixel = (1 - a) * picture + a * W
// Pixels where R, G and B all agree on the same `a` are watermark; coloured
// objects don't fit and are left alone.
//
// See-through watermarks are then undone exactly, which brings back the
// object under them: picture = (pixel - a * W) / (1 - a). Only pixels that
// can't be recovered (solid marks, or the picture blown out to white under
// the mark) are left for the AI fill.

const { blobs, otsu, boxBlur } = require('./imgutil');

const OPAQUE = 0.75;

// Min or max over a (2r+1)-wide window along rows then columns
// (van Herk / Gil-Werman, so the cost doesn't grow with r).
function minMaxFilter(src, w, h, r, isMax) {
  const k = 2 * r + 1;
  const pick = isMax ? Math.max : Math.min;
  const pass = (input, len, lines, at) => {
    const out = new Float32Array(input.length);
    const n = len + 2 * r;
    const padded = new Float32Array(n);
    const g = new Float32Array(n);
    const hh = new Float32Array(n);
    for (let line = 0; line < lines; line++) {
      for (let i = 0; i < n; i++) padded[i] = input[at(line, Math.min(len - 1, Math.max(0, i - r)))];
      for (let i = 0; i < n; i++) g[i] = i % k === 0 ? padded[i] : pick(g[i - 1], padded[i]);
      for (let i = n - 1; i >= 0; i--) hh[i] = i % k === k - 1 || i === n - 1 ? padded[i] : pick(hh[i + 1], padded[i]);
      for (let i = 0; i < len; i++) out[at(line, i)] = pick(hh[i], g[i + k - 1]);
    }
    return out;
  };
  const rows = pass(src, w, h, (y, x) => y * w + x);
  return pass(rows, h, w, (x, y) => y * w + x);
}

const opening = (c, w, h, r) => minMaxFilter(minMaxFilter(c, w, h, r, false), w, h, r, true);
const closing = (c, w, h, r) => minMaxFilter(minMaxFilter(c, w, h, r, true), w, h, r, false);

function bounds(mask, width, height) {
  let x0 = width, y0 = height, x1 = -1, y1 = -1;
  for (let y = 0; y < height; y++) {
    for (let x = 0; x < width; x++) {
      if (!mask[y * width + x]) continue;
      if (x < x0) x0 = x;
      if (x > x1) x1 = x;
      if (y < y0) y0 = y;
      if (y > y1) y1 = y;
    }
  }
  return x1 < 0 ? null : { x0, y0, x1, y1 };
}

// How strongly an even mark of colour W would have to cover pixel i, given
// the picture behind it (bg): per channel a = (pixel - bg) / (W - bg).
// `spread` says how much R, G and B disagree (a grey mark makes them agree).
const coverageFrom = (chan, bg) => (i, W) => {
  let sum = 0, lo = Infinity, hi = -Infinity;
  for (let c = 0; c < 3; c++) {
    const denom = W[c] - bg[c][i];
    if (Math.abs(denom) < 12) return null;
    const a = (chan[c][i] - bg[c][i]) / denom;
    sum += a;
    if (a < lo) lo = a;
    if (a > hi) hi = a;
  }
  return { a: sum / 3, spread: hi - lo };
};

// Average of the nearby pixels that are not watermark (`skip` > 0), per
// channel: an unbiased estimate of the picture behind each stroke.
function averageAround(chan, skip, w, h, r) {
  const n = w * h;
  const weight = new Float32Array(n);
  for (let i = 0; i < n; i++) weight[i] = skip[i] > 0 ? 0 : 1;
  const den = boxBlur(weight, w, h, r);
  return chan.map((c, k) => {
    const v = new Float32Array(n);
    for (let i = 0; i < n; i++) v[i] = c[i] * weight[i];
    const num = boxBlur(v, w, h, r);
    const out = new Float32Array(n);
    for (let i = 0; i < n; i++) out[i] = den[i] > 0 ? num[i] / den[i] : chan[k][i];
    return out;
  });
}

const median = (arr) => {
  arr.sort((p, q) => p - q);
  return arr[arr.length >> 1];
};

// Look for watermark strokes of one polarity (light or dark) in the painted
// pixels. Returns the per-pixel opacity map and a score for how well it fits.
function findStrokes(chan, inside, w, h, r, light, sensitivity) {
  const n = w * h;
  // The picture around each stroke, with thin light (or dark) strokes erased.
  const around = chan.map((c) => (light ? opening(c, w, h, r) : closing(c, w, h, r)));

  const coverage = coverageFrom(chan, around);

  const W = light ? [255, 255, 255] : [0, 0, 0];
  const alphaRaw = new Float32Array(n);
  const values = [];
  for (let i = 0; i < n; i++) {
    if (!inside[i]) continue;
    const cv = coverage(i, W);
    if (!cv || cv.spread > 0.25 || cv.a <= 0.03) continue;
    alphaRaw[i] = Math.min(1, cv.a);
    values.push(alphaRaw[i]);
  }
  // The painted area can hold more than one light layer, e.g. a faint
  // watermark and a solid white window frame. Try each opacity group as
  // "the watermark" and keep the one that fits best.
  const first = otsu(values);
  if (!first) return null;
  const ranges = [[first.cut, Infinity]];
  const second = otsu(values.filter((v) => v < first.cut));
  if (second) ranges.push([second.cut, first.cut]);
  let best = null;
  for (const [lo, hi] of ranges) {
    const found = strokesInRange(chan, inside, w, h, r, alphaRaw, lo / sensitivity, hi, light);
    if (found && (!best || found.score > best.score)) best = found;
  }
  return best;
}

function strokesInRange(chan, inside, w, h, r, alphaRaw, lo, hi, light) {
  const n = w * h;
  const core = new Float32Array(n);
  for (let i = 0; i < n; i++) core[i] = alphaRaw[i] >= lo && alphaRaw[i] < hi ? 1 : 0;
  const keepBlobs = () => {
    // Specks much smaller than a stroke are texture, not watermark.
    const minBlob = Math.max(8, Math.round((r / 5) ** 2));
    for (const blob of blobs(core, w, h)) {
      if (blob.length < minBlob) for (const i of blob) core[i] = 0;
    }
  };
  keepBlobs();
  let coreIdx = [];
  for (let i = 0; i < n; i++) if (core[i]) coreIdx.push(i);
  if (coreIdx.length < 20) return null;
  const rough = Math.min(0.97, median(coreIdx.map((i) => alphaRaw[i])));

  // A watermark has one even opacity. Much stronger pixels are real objects
  // (a white window frame, a lamp) that just happen to be light: keep them.
  const tolerance = Math.max(0.2, 0.35 * rough);
  for (const i of coreIdx) if (alphaRaw[i] > rough + tolerance) core[i] = 0;
  keepBlobs();
  coreIdx = [];
  for (let i = 0; i < n; i++) if (core[i]) coreIdx.push(i);
  if (coreIdx.length < 20) return null;

  // The opening used so far sits at the darkest (or lightest) pixel nearby,
  // so photo grain biases it. For the final numbers use the plain average of
  // nearby non-watermark pixels instead, and measure the opacity where the
  // mark contrasts most with the picture (where the estimate is precise).
  // Leave out anything that even faintly looks like watermark, or its
  // shading would leak into the "picture behind" estimate.
  const skip = boxBlur(core, w, h, 3);
  for (let i = 0; i < n; i++) if (alphaRaw[i] >= 0.4 * rough) skip[i] = 1;
  const bg = averageAround(chan, skip, w, h, r + 3);
  const exact = coverageFrom(chan, bg);
  const W0 = light ? [255, 255, 255] : [0, 0, 0];
  const contrast = (i) => Math.abs(W0[0] - (bg[0][i] + bg[1][i] + bg[2][i]) / 3);
  // Stroke interiors only: edges are partly covered and would skew it.
  const filled = boxBlur(core, w, h, 1);
  const interior = coreIdx.filter((i) => filled[i] >= 9);
  const base = interior.length >= 50 ? interior : coreIdx;
  let strong = base.filter((i) => contrast(i) >= 60);
  if (strong.length < 50) strong = base;
  // Least-squares fit of (pixel - bg) = a0 * (W - bg) over those pixels,
  // ignoring the worst-fitting tenth (stray object pixels).
  const fit = (idx) => {
    let num = 0, den = 0;
    for (const i of idx) {
      for (let c = 0; c < 3; c++) {
        const d = W0[c] - bg[c][i];
        num += (chan[c][i] - bg[c][i]) * d;
        den += d * d;
      }
    }
    return den ? num / den : 0;
  };
  let a0 = fit(strong);
  const misfit = (i) => {
    let e = 0;
    for (let c = 0; c < 3; c++) e += Math.abs(chan[c][i] - bg[c][i] - a0 * (W0[c] - bg[c][i]));
    return e;
  };
  const errs = strong.map(misfit).sort((p, q) => p - q);
  const limit = errs[Math.floor(errs.length * 0.9)];
  strong = strong.filter((i) => misfit(i) <= limit);
  a0 = Math.min(0.97, Math.max(0.02, fit(strong)));

  // The watermark's actual colour (often light grey rather than pure white).
  const W = [0, 1, 2].map((c) => Math.min(255, Math.max(0, median(strong.map((i) => (chan[c][i] - (1 - a0) * bg[c][i]) / a0)))));

  // Final opacity per pixel: the even opacity inside strokes. Stroke edges
  // are anti-aliased (partly covered), so measure those pixels one by one.
  const alpha = new Float32Array(n);
  const reach = boxBlur(core, w, h, 2);
  let even = 0;
  for (let i = 0; i < n; i++) {
    if (!inside[i] || reach[i] <= 0) continue;
    const cv = exact(i, W);
    if (core[i] && cv && Math.abs(cv.a - a0) < 0.12) even++;
    if (core[i] && filled[i] >= 9) alpha[i] = a0;
    // Enough contrast between mark and picture to measure this pixel.
    else if (cv && contrast(i) >= 40) alpha[i] = cv.spread < 0.35 ? Math.min(a0, Math.max(0, cv.a)) : 0;
    // Too little contrast to measure (e.g. white mark on a white wall): only
    // correct the stroke pixels themselves.
    else alpha[i] = core[i] ? a0 * 0.5 : 0;
    if (alpha[i] < 0.08 * a0) alpha[i] = 0;
  }
  // Fit: many stroke pixels that agree on one opacity.
  const score = even * (even / coreIdx.length);
  return { light, alpha, a0, W, score };
}

// rgb: full photo (3 bytes per pixel). painted: full-size 0/1 mask.
// sensitivity: 1 = normal, higher finds fainter marks (and more false ones).
// Returns null when no watermark shape could be found in the painted area.
function detect(rgb, width, height, painted, sensitivity = 1) {
  const b = bounds(painted, width, height);
  if (!b) return null;
  const pw = b.x1 - b.x0 + 1;
  const ph = b.y1 - b.y0 + 1;
  // Wider than a stroke's half-width: a brush stroke over text is a bit
  // taller than the text, and letter strokes are ~1/7 of the text height.
  const r = Math.min(30, Math.max(3, Math.round(Math.min(pw, ph) / 10)));
  const pad = 2 * r + 8;
  const box = {
    left: Math.max(0, b.x0 - pad),
    top: Math.max(0, b.y0 - pad),
  };
  box.width = Math.min(width, b.x1 + pad + 1) - box.left;
  box.height = Math.min(height, b.y1 + pad + 1) - box.top;
  const w = box.width;
  const h = box.height;
  const n = w * h;

  const chan = [0, 1, 2].map(() => new Float32Array(n));
  const inside = new Uint8Array(n);
  for (let y = 0; y < h; y++) {
    for (let x = 0; x < w; x++) {
      const src = (box.top + y) * width + box.left + x;
      const i = y * w + x;
      inside[i] = painted[src];
      for (let c = 0; c < 3; c++) chan[c][i] = rgb[src * 3 + c];
    }
  }

  // Try a light (usually white) and a dark watermark and keep whichever
  // explains the painted area better.
  const tries = [true, false].map((light) => findStrokes(chan, inside, w, h, r, light, sensitivity)).filter(Boolean);
  if (!tries.length) return null;
  tries.sort((p, q) => q.score - p.score);
  const { light, alpha, a0, W } = tries[0];

  // Which marked pixels can be restored exactly, and which need the AI fill.
  const needFill = new Uint8Array(n);
  for (let i = 0; i < n; i++) {
    if (!alpha[i]) continue;
    if (a0 > OPAQUE) { needFill[i] = 1; continue; }
    for (let c = 0; c < 3; c++) {
      const v = chan[c][i];
      // Picture under the mark was blown out (or crushed): nothing to recover.
      if ((light && v >= 252) || (!light && v <= 3)) { needFill[i] = 1; break; }
      const restored = (v - alpha[i] * W[c]) / (1 - alpha[i]);
      if (restored < -25 || restored > 280) { needFill[i] = 1; break; }
    }
  }

  let paintedCount = 0, marked = 0, fill = 0;
  for (let i = 0; i < n; i++) {
    if (inside[i]) paintedCount++;
    if (alpha[i]) marked++;
    if (needFill[i]) fill++;
  }
  return {
    box, alpha, needFill, color: W.map(Math.round), opacity: a0, light,
    stats: { painted: paintedCount, marked, fill },
  };
}

// Undo the watermark in place on the full photo for every restorable pixel.
function restore(rgb, width, found) {
  const { box, alpha, needFill, color } = found;
  for (let y = 0; y < box.height; y++) {
    for (let x = 0; x < box.width; x++) {
      const i = y * box.width + x;
      const a = alpha[i];
      if (!a || needFill[i]) continue;
      const dst = ((box.top + y) * width + box.left + x) * 3;
      for (let c = 0; c < 3; c++) {
        const v = (rgb[dst + c] - a * color[c]) / (1 - a);
        rgb[dst + c] = v < 0 ? 0 : v > 255 ? 255 : Math.round(v);
      }
    }
  }
}

module.exports = { detect, restore };

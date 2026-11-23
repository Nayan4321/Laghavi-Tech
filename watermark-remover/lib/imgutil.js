// Small image helpers shared by the photo and video code. Images are flat
// arrays, one value per pixel, row by row.

// Connected groups of non-zero pixels, as lists of pixel indexes.
function blobs(map, w, h) {
  const seen = new Uint8Array(w * h);
  const out = [];
  for (let start = 0; start < map.length; start++) {
    if (!map[start] || seen[start]) continue;
    const blob = [start];
    seen[start] = 1;
    for (let k = 0; k < blob.length; k++) {
      const i = blob[k];
      const x = i % w;
      const next = [x > 0 ? i - 1 : -1, x < w - 1 ? i + 1 : -1, i - w, i + w];
      for (const j of next) {
        if (j < 0 || j >= map.length || seen[j] || !map[j]) continue;
        seen[j] = 1;
        blob.push(j);
      }
    }
    out.push(blob);
  }
  return out;
}

// Best two-group split of values in 0..1 (Otsu). Returns each group's median
// and the value that splits them.
function otsu(values) {
  if (values.length < 50) return null;
  const bins = 100;
  const hist = new Float64Array(bins);
  for (const v of values) hist[Math.min(bins - 1, Math.floor(v * bins))]++;
  let total = 0;
  for (let b = 0; b < bins; b++) total += b * hist[b];
  let best = -1, cut = 0, wB = 0, sB = 0;
  for (let b = 0; b < bins - 1; b++) {
    wB += hist[b];
    sB += b * hist[b];
    const wF = values.length - wB;
    if (!wB || !wF) continue;
    const between = wB * wF * (sB / wB - (total - sB) / wF) ** 2;
    if (between > best) { best = between; cut = (b + 1) / bins; }
  }
  const lo = values.filter((v) => v < cut).sort((a, b) => a - b);
  const hi = values.filter((v) => v >= cut).sort((a, b) => a - b);
  // Watermark strokes are usually a fair share of the brushed area.
  if (hi.length < values.length * 0.03 || !lo.length) return null;
  return { low: lo[lo.length >> 1], high: hi[hi.length >> 1], cut };
}

function boxBlur(src, w, h, r) {
  const tmp = new Float32Array(src.length);
  const out = new Float32Array(src.length);
  for (let y = 0; y < h; y++) {
    let acc = 0;
    const row = y * w;
    for (let x = -r; x <= r; x++) acc += src[row + Math.min(w - 1, Math.max(0, x))];
    for (let x = 0; x < w; x++) {
      tmp[row + x] = acc;
      acc += src[row + Math.min(w - 1, x + r + 1)] - src[row + Math.max(0, x - r)];
    }
  }
  for (let x = 0; x < w; x++) {
    let acc = 0;
    for (let y = -r; y <= r; y++) acc += tmp[Math.min(h - 1, Math.max(0, y)) * w + x];
    for (let y = 0; y < h; y++) {
      out[y * w + x] = acc;
      acc += tmp[Math.min(h - 1, y + r + 1) * w + x] - tmp[Math.max(0, y - r) * w + x];
    }
  }
  return out;
}

module.exports = { blobs, otsu, boxBlur };
